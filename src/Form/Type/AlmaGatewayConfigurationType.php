<?php

declare(strict_types=1);

namespace Alma\Sylius\Form\Type;

use Alma\Sylius\Api\ApiKeyValidationError;
use Alma\Sylius\Api\ApiKeyValidationResult;
use Alma\Sylius\Api\ApiKeyValidator;
use Alma\Sylius\Api\FeePlanAdapter;
use Alma\Sylius\Api\FeePlansFetcher;
use Alma\Sylius\Api\FeePlansFetchFailedException;
use Alma\Sylius\Checkout\GroupDisplayTextsResolver;
use Alma\Sylius\Checkout\PlanGroup;
use Alma\Sylius\Entity\AlmaConfiguration;
use Sylius\Component\Locale\Provider\LocaleProviderInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Configuration form embedded in the Sylius PaymentMethod admin form via the
 * `sylius.gateway_configuration_type` tag (type=alma).
 *
 * Sylius binds this form to the `config` JSON of the PaymentMethod's gatewayConfig
 * (data is an array, not an entity).
 *
 * Event lifecycle (cf. canonical specs/module-configuration + specs-sylius/module-configuration):
 *
 * - PRE_SET_DATA: fetch fresh fee plans from Alma using the persisted active-mode
 *   key; inject per-plan dynamic fields (enable + min/max override); snapshot the
 *   previous config so PRE_SUBMIT can restore failed fields.
 * - PRE_SUBMIT: validate newly-submitted API keys, run the cross-merchant check,
 *   restore failed fields to their previously persisted value in the RAW data,
 *   defer errors to POST_SUBMIT. The model data is not touched here yet.
 * - SUBMIT: model data is now bound from the raw submitted array. Compute and
 *   write the derived keys (`merchant_id`, `fee_plans`, `fee_plan_overrides`)
 *   that are NOT declared as form fields. Collect dynamic field values to
 *   build the clean overrides map (clamping out-of-range values). On the
 *   save that takes the merchant from zero valid API keys to at least one,
 *   with `fee_plan_overrides` still empty, auto-enable `general_3_0_0` if
 *   it's present and allowed — one-time, since writing that entry is what
 *   keeps this branch from running again.
 * - POST_SUBMIT: surface deferred validation errors on the right fields and
 *   push deferred clamp warnings to the flash bag.
 *
 * Encryption is handled by {@see \Alma\Sylius\EventListener\AlmaGatewayConfigCryptoListener}
 * at the Doctrine layer — the form sees and writes cleartext keys.
 *
 * @phpstan-import-type StoredPlan from FeePlanAdapter
 * @phpstan-import-type PlanOverride from FeePlanAdapter
 *
 * @implementation [[merchant-configuration-form]] — the Merchant's configuration of Alma in the Back-Office: keys verified against Alma, plans and overrides, display texts
 */
final class AlmaGatewayConfigurationType extends AbstractType
{
    private const DEFAULT_FEE_PLAN_KEY = 'general_3_0_0';

    private const DISPLAY_TITLE_MAX_LENGTH = 50;

    private const DISPLAY_DESCRIPTION_MAX_LENGTH = 150;

    public function __construct(
        private readonly ApiKeyValidator $apiKeyValidator,
        private readonly FeePlansFetcher $feePlansFetcher,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly GroupDisplayTextsResolver $displayTextsResolver,
        private readonly LocaleProviderInterface $localeProvider,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $previousConfig = [];
        $latestFeePlans = [];
        $validation = ['live' => null, 'test' => null];
        $deferredErrors = ['fields' => [], 'form' => null];
        $deferredFlash = [];

        $builder
            ->add('api_key_live', TextType::class, [
                'required' => false,
                'label' => 'alma_sylius.form.configuration.api_key_live',
            ])
            ->add('api_key_test', TextType::class, [
                'required' => false,
                'label' => 'alma_sylius.form.configuration.api_key_test',
            ])
            ->add('api_mode', ChoiceType::class, [
                'label' => 'alma_sylius.form.configuration.api_mode',
                'choices' => [
                    'alma_sylius.form.configuration.mode.live' => AlmaConfiguration::MODE_LIVE,
                    'alma_sylius.form.configuration.mode.test' => AlmaConfiguration::MODE_TEST,
                ],
                'expanded' => false,
                'multiple' => false,
            ])
            ->add('product_widget_enabled', CheckboxType::class, [
                'required' => false,
                'label' => 'alma_sylius.form.configuration.product_widget_enabled',
            ])
        ;

        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use (&$previousConfig, &$latestFeePlans): void {
                $config = $this->normalizeConfig($event->getData());
                if (!\in_array($config['api_mode'] ?? null, AlmaConfiguration::MODES, true)) {
                    $config['api_mode'] = AlmaConfiguration::MODE_TEST;
                }
                $config['product_widget_enabled'] = (bool) ($config['product_widget_enabled'] ?? true);
                $previousConfig = $config;

                $latestFeePlans = $this->refreshFeePlans($config);
                $config['fee_plans'] = $latestFeePlans;

                $this->addFeePlanOverrideFields(
                    $event->getForm(),
                    $latestFeePlans,
                    $this->overrides($config['fee_plan_overrides'] ?? null),
                );

                $this->addDisplayTextFields(
                    $event->getForm(),
                    \is_array($config['display_texts'] ?? null) ? $config['display_texts'] : [],
                );

                $event->setData($config);
            },
        );

        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event) use (&$previousConfig, &$validation, &$deferredErrors): void {
                $submitted = \is_array($event->getData()) ? $event->getData() : [];

                $previousLive = $this->nonEmptyString($previousConfig['api_key_live'] ?? null);
                $previousTest = $this->nonEmptyString($previousConfig['api_key_test'] ?? null);
                $submittedLive = $this->nonEmptyString($submitted['api_key_live'] ?? null);
                $submittedTest = $this->nonEmptyString($submitted['api_key_test'] ?? null);

                $liveChanged = $submittedLive !== null && $submittedLive !== $previousLive;
                $testChanged = $submittedTest !== null && $submittedTest !== $previousTest;
                $bothPresent = $submittedLive !== null && $submittedTest !== null;

                $needLive = $liveChanged || ($bothPresent && $testChanged);
                $needTest = $testChanged || ($bothPresent && $liveChanged);

                $liveResult = $needLive && $submittedLive !== null
                    ? $this->apiKeyValidator->validate($submittedLive, AlmaConfiguration::MODE_LIVE)
                    : null;
                $testResult = $needTest && $submittedTest !== null
                    ? $this->apiKeyValidator->validate($submittedTest, AlmaConfiguration::MODE_TEST)
                    : null;

                $validation = ['live' => $liveResult, 'test' => $testResult];

                $fieldErrors = [];
                $formError = null;
                $fieldsToRestore = [];

                if ($liveResult !== null && !$liveResult->isSuccess()) {
                    $fieldErrors['api_key_live'] = $this->messageForError($liveResult->getError());
                    if ($liveChanged) {
                        $fieldsToRestore[] = 'api_key_live';
                    }
                }

                if ($testResult !== null && !$testResult->isSuccess()) {
                    $fieldErrors['api_key_test'] = $this->messageForError($testResult->getError());
                    if ($testChanged) {
                        $fieldsToRestore[] = 'api_key_test';
                    }
                }

                if (
                    $fieldErrors === [] &&
                    $bothPresent &&
                    $liveResult?->isSuccess() &&
                    $testResult?->isSuccess() &&
                    $liveResult->getMerchantId() !== $testResult->getMerchantId()
                ) {
                    $formError = 'alma_sylius.form.error.cross_keys_merchant_mismatch';
                    if ($liveChanged) {
                        $fieldsToRestore[] = 'api_key_live';
                    }
                    if ($testChanged) {
                        $fieldsToRestore[] = 'api_key_test';
                    }
                }

                $deferredErrors = ['fields' => $fieldErrors, 'form' => $formError];

                foreach (array_unique($fieldsToRestore) as $field) {
                    $submitted[$field] = $previousConfig[$field] ?? '';
                }

                if (!\in_array($submitted['api_mode'] ?? null, AlmaConfiguration::MODES, true)) {
                    $submitted['api_mode'] = $previousConfig['api_mode'] ?? AlmaConfiguration::MODE_TEST;
                }

                $event->setData($submitted);
            },
        );

        $builder->addEventListener(
            FormEvents::SUBMIT,
            function (FormEvent $event) use (&$previousConfig, &$latestFeePlans, &$validation, &$deferredFlash): void {
                $data = \is_array($event->getData()) ? $event->getData() : [];

                $data['merchant_id'] = $this->resolveMerchantId(
                    $data,
                    $previousConfig,
                    $validation['live'] ?? null,
                    $validation['test'] ?? null,
                );

                $feePlans = $latestFeePlans !== []
                    ? $latestFeePlans
                    : $this->storedPlans($previousConfig['fee_plans'] ?? null);
                $data['fee_plans'] = $feePlans;

                [$overrides, $warnings] = $this->collectOverrides($event->getForm(), $feePlans);

                if (
                    $data['merchant_id'] !== null &&
                    $this->hadNoValidKeyBefore($previousConfig) &&
                    ($previousConfig['fee_plan_overrides'] ?? []) === []
                ) {
                    $freshFeePlans = $this->refreshFeePlans($data);
                    $autoPlan = $freshFeePlans[self::DEFAULT_FEE_PLAN_KEY] ?? null;
                    if ($autoPlan !== null && ($autoPlan['allowed'] ?? false) === true) {
                        $data['fee_plans'] = $freshFeePlans;
                        $overrides[self::DEFAULT_FEE_PLAN_KEY] = [
                            'enabled' => true,
                            'override_min_purchase_amount' => null,
                            'override_max_purchase_amount' => null,
                        ];
                    }
                }

                $data['fee_plan_overrides'] = $overrides;
                $data['display_texts'] = $this->collectDisplayTexts($event->getForm());
                $deferredFlash = $warnings;

                $event->setData($data);
            },
        );

        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            function (FormEvent $event) use (&$deferredErrors, &$deferredFlash): void {
                $form = $event->getForm();

                foreach ($deferredErrors['fields'] as $field => $message) {
                    if ($form->has($field)) {
                        $form->get($field)->addError(new FormError($this->translator->trans($message)));
                    }
                }

                if (\is_string($deferredErrors['form'] ?? null)) {
                    $form->addError(new FormError($this->translator->trans($deferredErrors['form'])));
                }

                if ($deferredFlash !== []) {
                    $session = $this->requestStack->getSession();
                    if ($session instanceof FlashBagAwareSessionInterface) {
                        foreach ($deferredFlash as $flash) {
                            $session->getFlashBag()->add('warning', $flash);
                        }
                    }
                }
            },
        );
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['display_locales'] = $this->localeProvider->getAvailableLocalesCodes();
        $view->vars['display_groups'] = array_map(
            static fn (PlanGroup $group): string => $group->value,
            PlanGroup::cases(),
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'empty_data' => static fn (): array => [],
            'label' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeConfig(mixed $data): array
    {
        return \is_array($data) ? $data : [];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** A field's data as trimmed text; anything not scalar is empty. */
    private function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The Merchant's overrides as stored, plan key → data: written by this form (collectOverrides), read back here.
     *
     * @return array<string, PlanOverride>
     */
    private function overrides(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $overrides = [];
        foreach ($value as $key => $data) {
            if (!\is_string($key) || !\is_array($data)) {
                continue;
            }
            foreach ($data as $field => $item) {
                if (\is_string($field)) {
                    $overrides[$key][$field] = $item;
                }
            }
        }
        /** @var array<string, PlanOverride> $stored written by collectOverrides */
        $stored = $overrides;

        return $stored;
    }

    /**
     * The plans as stored, plan key → Alma's data: written by FeePlansFetcher from the client's typed getters.
     *
     * @return array<string, StoredPlan>
     */
    private function storedPlans(mixed $value): array
    {
        /** @var array<string, StoredPlan> $plans */
        $plans = \is_array($value) ? $value : [];

        return $plans;
    }

    /**
     * @param array<string, mixed> $previousConfig
     */
    private function hadNoValidKeyBefore(array $previousConfig): bool
    {
        return $this->nonEmptyString($previousConfig['api_key_live'] ?? null) === null &&
            $this->nonEmptyString($previousConfig['api_key_test'] ?? null) === null;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, StoredPlan>
     */
    private function refreshFeePlans(array $config): array
    {
        $mode = \is_string($config['api_mode'] ?? null) && \in_array($config['api_mode'], AlmaConfiguration::MODES, true)
            ? $config['api_mode']
            : AlmaConfiguration::MODE_TEST;
        $key = $mode === AlmaConfiguration::MODE_LIVE
            ? $this->nonEmptyString($config['api_key_live'] ?? null)
            : $this->nonEmptyString($config['api_key_test'] ?? null);

        if ($key === null) {
            return [];
        }

        try {
            return $this->feePlansFetcher->fetch($key, $mode);
        } catch (FeePlansFetchFailedException) {
            return $this->storedPlans($config['fee_plans'] ?? null);
        }
    }

    /**
     * @param array<string, StoredPlan> $feePlans
     * @param array<string, PlanOverride> $overrides
     */
    private function addFeePlanOverrideFields(FormInterface $form, array $feePlans, array $overrides): void
    {
        foreach ($feePlans as $planKey => $apiPlan) {
            $override = $overrides[$planKey] ?? [];
            $apiMin = $apiPlan['min_purchase_amount'] ?? 0;
            $apiMax = $apiPlan['max_purchase_amount'] ?? 0;

            $form->add('fee_plan_enabled__' . $planKey, CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'data' => $override['enabled'] ?? false,
                'label' => false,
            ]);
            $form->add('fee_plan_override_min__' . $planKey, IntegerType::class, [
                'mapped' => false,
                'required' => false,
                'data' => $override['override_min_purchase_amount'] ?? null,
                'attr' => ['min' => $apiMin, 'max' => $apiMax],
                'label' => false,
            ]);
            $form->add('fee_plan_override_max__' . $planKey, IntegerType::class, [
                'mapped' => false,
                'required' => false,
                'data' => $override['override_max_purchase_amount'] ?? null,
                'attr' => ['min' => $apiMin, 'max' => $apiMax],
                'label' => false,
            ]);
        }
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $displayTexts
     */
    private function addDisplayTextFields(FormInterface $form, array $displayTexts): void
    {
        foreach ($this->localeProvider->getAvailableLocalesCodes() as $locale) {
            foreach (PlanGroup::cases() as $group) {
                $text = $this->displayTextsResolver->resolve($displayTexts, $group, $locale);

                $form->add(sprintf('display_title__%s__%s', $group->value, $locale), TextType::class, [
                    'mapped' => false,
                    'required' => true,
                    'data' => $text->title,
                    'label' => false,
                    'constraints' => [
                        new NotBlank(message: 'alma_sylius.form.error.display_title_required'),
                        new Length(max: self::DISPLAY_TITLE_MAX_LENGTH),
                    ],
                    'attr' => ['maxlength' => self::DISPLAY_TITLE_MAX_LENGTH],
                ]);
                $form->add(sprintf('display_description__%s__%s', $group->value, $locale), TextType::class, [
                    'mapped' => false,
                    'required' => false,
                    'data' => $text->description,
                    'label' => false,
                    'constraints' => [
                        new Length(max: self::DISPLAY_DESCRIPTION_MAX_LENGTH),
                    ],
                    'attr' => [
                        'maxlength' => self::DISPLAY_DESCRIPTION_MAX_LENGTH,
                        'placeholder' => 'alma_sylius.form.configuration.display.description_placeholder',
                    ],
                ]);
            }
        }
    }

    /**
     * @return array<string, array<string, array{title: string, description: string|null}>>
     */
    private function collectDisplayTexts(FormInterface $form): array
    {
        $texts = [];

        foreach ($this->localeProvider->getAvailableLocalesCodes() as $locale) {
            foreach (PlanGroup::cases() as $group) {
                $titleField = sprintf('display_title__%s__%s', $group->value, $locale);
                if (!$form->has($titleField)) {
                    continue;
                }

                $description = $this->text($form->get(sprintf('display_description__%s__%s', $group->value, $locale))->getData());

                $texts[$locale][$group->value] = [
                    'title' => $this->text($form->get($titleField)->getData()),
                    'description' => $description === '' ? null : $description,
                ];
            }
        }

        return $texts;
    }

    private function messageForError(?ApiKeyValidationError $error): string
    {
        return $error === ApiKeyValidationError::CannotCreatePayments
            ? 'alma_sylius.form.error.merchant_cannot_create_payments'
            : 'alma_sylius.form.error.api_key_invalid';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $previous
     */
    private function resolveMerchantId(
        array $data,
        array $previous,
        ?ApiKeyValidationResult $liveResult,
        ?ApiKeyValidationResult $testResult,
    ): ?string {
        $live = $this->nonEmptyString($data['api_key_live'] ?? null);
        $test = $this->nonEmptyString($data['api_key_test'] ?? null);

        if ($live === null && $test === null) {
            return null;
        }

        $mode = $data['api_mode'] ?? AlmaConfiguration::MODE_TEST;
        $activeResult = $mode === AlmaConfiguration::MODE_LIVE ? $liveResult : $testResult;
        $otherResult = $mode === AlmaConfiguration::MODE_LIVE ? $testResult : $liveResult;

        if ($activeResult !== null && $activeResult->isSuccess()) {
            return $activeResult->getMerchantId();
        }

        if ($otherResult !== null && $otherResult->isSuccess()) {
            return $otherResult->getMerchantId();
        }

        return $this->nonEmptyString($previous['merchant_id'] ?? null);
    }

    /**
     * @param array<string, StoredPlan> $feePlans
     *
     * @return array{0: array<string, PlanOverride>, 1: list<string>}
     */
    private function collectOverrides(FormInterface $form, array $feePlans): array
    {
        $overrides = [];
        $warnings = [];

        foreach ($feePlans as $planKey => $apiPlan) {
            $enabledField = 'fee_plan_enabled__' . $planKey;
            $minField = 'fee_plan_override_min__' . $planKey;
            $maxField = 'fee_plan_override_max__' . $planKey;

            if (!$form->has($enabledField)) {
                continue;
            }

            $apiMin = $apiPlan['min_purchase_amount'] ?? 0;
            $apiMax = $apiPlan['max_purchase_amount'] ?? 0;

            $enabled = (bool) $form->get($enabledField)->getData();
            [$minClamped, $minWarning] = $this->clampOverride($form->get($minField)->getData(), $apiMin, $apiMax, $planKey);
            [$maxClamped, $maxWarning] = $this->clampOverride($form->get($maxField)->getData(), $apiMin, $apiMax, $planKey);

            if ($minWarning !== null) {
                $warnings[] = $minWarning;
            }
            if ($maxWarning !== null) {
                $warnings[] = $maxWarning;
            }

            if ($enabled === false && $minClamped === null && $maxClamped === null) {
                continue;
            }

            $overrides[$planKey] = [
                'enabled' => $enabled,
                'override_min_purchase_amount' => $minClamped,
                'override_max_purchase_amount' => $maxClamped,
            ];
        }

        return [$overrides, $warnings];
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function clampOverride(mixed $value, int $apiMin, int $apiMax, string $planKey): array
    {
        if (!\is_scalar($value) || $value === '') {
            return [null, null];
        }

        $intValue = (int) $value;
        $clamped = max($apiMin, min($apiMax, $intValue));
        if ($clamped === $intValue) {
            return [$clamped, null];
        }

        $message = $this->translator->trans('alma_sylius.fee_plan.warning.range_clamped', [
            '%plan%' => $planKey,
            '%value%' => $clamped,
        ]);

        return [$clamped, $message];
    }
}
