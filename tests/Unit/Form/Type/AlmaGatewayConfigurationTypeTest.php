<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Form\Type;

use Alma\Sylius\Api\ApiKeyValidationResult;
use Alma\Sylius\Api\ApiKeyValidator;
use Alma\Sylius\Api\FeePlansFetcher;
use Alma\Sylius\Checkout\GroupDisplayTextsResolver;
use Alma\Sylius\Form\Type\AlmaGatewayConfigurationType;
use PHPUnit\Framework\MockObject\MockObject;
use Sylius\Component\Locale\Provider\LocaleProviderInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Characterisation of the Merchant configuration form ([[merchant-configuration-form]],
 * [[merchant-configuration]]): the keys are verified against Alma when they change, the plans Alma
 * allows are read and offered with the Merchant's overrides clamped to Alma's bounds, the display
 * texts are collected per locale and group. Alma is doubled: the validator and the fetcher are mocks.
 *
 * @implementation [[merchant-configuration-form]]
 */
final class AlmaGatewayConfigurationTypeTest extends TypeTestCase
{
    /** @var array<string, array<string, mixed>> */
    private const PLANS = [
        'general_3_0_0' => ['kind' => 'general', 'installments_count' => 3, 'deferred_days' => 0, 'deferred_months' => 0, 'allowed' => true, 'min_purchase_amount' => 5000, 'max_purchase_amount' => 200000],
        'general_1_15_0' => ['kind' => 'general', 'installments_count' => 1, 'deferred_days' => 15, 'deferred_months' => 0, 'allowed' => true, 'min_purchase_amount' => 5000, 'max_purchase_amount' => 100000],
    ];

    private ApiKeyValidator&MockObject $apiKeyValidator;

    private FeePlansFetcher&MockObject $feePlansFetcher;

    private Session $session;

    protected function setUp(): void
    {
        $this->apiKeyValidator = $this->createMock(ApiKeyValidator::class);
        $this->feePlansFetcher = $this->createMock(FeePlansFetcher::class);
        $this->session = new Session(new MockArraySessionStorage());
        parent::setUp();
    }

    public function testThePlansAlmaAllowsAreOfferedWithTheMerchantsOverrides(): void
    {
        $this->feePlansFetcher->expects(self::once())->method('fetch')->with('sk_test_x', 'test')->willReturn(self::PLANS);

        $form = $this->factory->create(AlmaGatewayConfigurationType::class, [
            'api_mode' => 'test',
            'api_key_test' => 'sk_test_x',
            'fee_plan_overrides' => ['general_3_0_0' => ['enabled' => true, 'override_min_purchase_amount' => 10000, 'override_max_purchase_amount' => null]],
        ]);

        self::assertTrue($form->has('fee_plan_enabled__general_3_0_0'));
        self::assertTrue($form->get('fee_plan_enabled__general_3_0_0')->getData());
        self::assertSame(10000, $form->get('fee_plan_override_min__general_3_0_0')->getData());
        self::assertSame(['min' => 5000, 'max' => 200000], $form->get('fee_plan_override_min__general_3_0_0')->getConfig()->getOption('attr'), "Alma's bounds on the field");
        self::assertTrue($form->has('fee_plan_enabled__general_1_15_0'));
        self::assertFalse($form->get('fee_plan_enabled__general_1_15_0')->getData(), 'a plan without override is disabled');
        self::assertTrue($form->has('display_title__installments__fr_FR'), 'a title field per group and locale');
        self::assertTrue($form->has('display_description__pay_now__en_US'));
        $data = $this->data($form);
        self::assertTrue($data['product_widget_enabled'], 'the widget is on unless turned off');
        self::assertSame(self::PLANS, $data['fee_plans']);
    }

    public function testWithoutKeyNothingIsAskedToAlma(): void
    {
        $this->feePlansFetcher->expects(self::never())->method('fetch');

        $form = $this->factory->create(AlmaGatewayConfigurationType::class, ['api_mode' => 'test']);

        self::assertFalse($form->has('fee_plan_enabled__general_3_0_0'));
        self::assertSame('test', $this->data($form)['api_mode']);
    }

    public function testANewKeyIsVerifiedAndGivesTheMerchantIdAndTheDefaultPlan(): void
    {
        $this->feePlansFetcher->method('fetch')->willReturn(self::PLANS);
        $this->apiKeyValidator->expects(self::once())->method('validate')->with('sk_test_new', 'test')->willReturn(ApiKeyValidationResult::success('merchant_42'));

        $form = $this->factory->create(AlmaGatewayConfigurationType::class, ['api_mode' => 'test']);
        $form->submit($this->payload(['api_key_test' => 'sk_test_new', 'api_mode' => 'test']));

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $this->data($form);
        self::assertSame('merchant_42', $data['merchant_id']);
        self::assertSame('sk_test_new', $data['api_key_test']);
        self::assertSame(self::PLANS, $data['fee_plans']);
        self::assertSame(['general_3_0_0' => ['enabled' => true, 'override_min_purchase_amount' => null, 'override_max_purchase_amount' => null]], $data['fee_plan_overrides'], 'first valid key: the 3x plan is enabled for the Merchant');
    }

    public function testAnInvalidKeyIsRefusedAndThePreviousOneKept(): void
    {
        $this->feePlansFetcher->method('fetch')->willReturn(self::PLANS);
        $this->apiKeyValidator->method('validate')->willReturn(ApiKeyValidationResult::invalid());

        $form = $this->factory->create(AlmaGatewayConfigurationType::class, ['api_mode' => 'test', 'api_key_test' => 'sk_test_old', 'merchant_id' => 'merchant_old']);
        $form->submit($this->payload(['api_key_test' => 'sk_test_wrong', 'api_mode' => 'test']));

        self::assertFalse($form->isValid());
        self::assertSame(['alma_sylius.form.error.api_key_invalid'], $this->messages($form->get('api_key_test')));
        $data = $this->data($form);
        self::assertSame('sk_test_old', $data['api_key_test'], 'the previous key is restored');
        self::assertSame('merchant_old', $data['merchant_id']);
    }

    public function testKeysOfTwoDifferentMerchantsAreRefusedTogether(): void
    {
        $this->feePlansFetcher->method('fetch')->willReturn(self::PLANS);
        $this->apiKeyValidator->method('validate')->willReturnMap([
            ['sk_live_a', 'live', ApiKeyValidationResult::success('merchant_a')],
            ['sk_test_b', 'test', ApiKeyValidationResult::success('merchant_b')],
        ]);

        $form = $this->factory->create(AlmaGatewayConfigurationType::class, ['api_mode' => 'test']);
        $form->submit($this->payload(['api_key_live' => 'sk_live_a', 'api_key_test' => 'sk_test_b', 'api_mode' => 'test']));

        self::assertFalse($form->isValid());
        self::assertSame(['alma_sylius.form.error.cross_keys_merchant_mismatch'], $this->messages($form));
    }

    public function testOverridesAreClampedToAlmasBoundsWithAWarning(): void
    {
        $this->feePlansFetcher->method('fetch')->willReturn(self::PLANS);

        $form = $this->factory->create(AlmaGatewayConfigurationType::class, ['api_mode' => 'test', 'api_key_test' => 'sk_test_x', 'merchant_id' => 'merchant_x']);
        $form->submit($this->payload([
            'api_key_test' => 'sk_test_x',
            'api_mode' => 'test',
            'fee_plan_enabled__general_3_0_0' => '1',
            'fee_plan_override_min__general_3_0_0' => '1000',
            'fee_plan_override_max__general_3_0_0' => '150000',
        ]));

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame(['general_3_0_0' => ['enabled' => true, 'override_min_purchase_amount' => 5000, 'override_max_purchase_amount' => 150000]], $this->data($form)['fee_plan_overrides'], "the minimum rises to Alma's, the maximum is kept; a plan neither enabled nor bounded has no override");
        self::assertSame(['alma_sylius.fee_plan.warning.range_clamped'], $this->session->getFlashBag()->get('warning'), 'the Merchant is warned once');
    }

    public function testDisplayTextsAreCollectedPerLocaleAndGroup(): void
    {
        $this->feePlansFetcher->method('fetch')->willReturn(self::PLANS);

        $form = $this->factory->create(AlmaGatewayConfigurationType::class, ['api_mode' => 'test', 'api_key_test' => 'sk_test_x', 'merchant_id' => 'merchant_x']);
        $form->submit($this->payload([
            'api_key_test' => 'sk_test_x',
            'api_mode' => 'test',
            'display_title__installments__fr_FR' => '  Payez en plusieurs fois  ',
            'display_description__installments__fr_FR' => '',
        ]));

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $texts = $this->data($form)['display_texts'];
        self::assertIsArray($texts);
        self::assertSame(['title' => 'Payez en plusieurs fois', 'description' => null], $texts['fr_FR']['installments'] ?? null);
        self::assertArrayHasKey('en_US', $texts);
    }

    /**
     * Every title field is required: the payload carries a title for each group and locale, overridden by what the test gives.
     *
     * @param array<string, string> $fields
     *
     * @return array<string, string>
     */
    private function payload(array $fields): array
    {
        $payload = [];
        foreach (['fr_FR', 'en_US'] as $locale) {
            foreach (['pay_now', 'installments', 'deferred', 'credit'] as $group) {
                $payload["display_title__{$group}__{$locale}"] = "Title {$group} {$locale}";
            }
        }

        return $fields + $payload;
    }

    /** @return array<string, mixed> */
    private function data(FormInterface $form): array
    {
        $data = $form->getData();
        self::assertIsArray($data);

        return $data;
    }

    /** @return list<string> */
    private function messages(FormInterface $form): array
    {
        $messages = [];
        foreach ($form->getErrors() as $error) {
            if ($error instanceof FormError) {
                $messages[] = $error->getMessage();
            }
        }

        return $messages;
    }

    protected function getExtensions(): array
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $localeProvider = $this->createMock(LocaleProviderInterface::class);
        $localeProvider->method('getAvailableLocalesCodes')->willReturn(['fr_FR', 'en_US']);
        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $type = new AlmaGatewayConfigurationType(
            $this->apiKeyValidator,
            $this->feePlansFetcher,
            $translator,
            $requestStack,
            new GroupDisplayTextsResolver($translator),
            $localeProvider,
        );

        // The form declares constraints on its fields: the validator extension, as the shop has it.
        return [new PreloadedExtension([$type], []), new ValidatorExtension(Validation::createValidator())];
    }
}
