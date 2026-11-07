<?php

declare(strict_types=1);

namespace Alma\Sylius\Resolver;

use Alma\Sylius\Entity\AlmaConfiguration;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Single access point to the operational Alma configuration.
 *
 * The configuration values (API keys, active mode, fee plans, overrides) live
 * in the `config` JSON of the unique Alma PaymentMethod's gatewayConfig (see
 * specs-sylius/module-configuration). This resolver finds that PaymentMethod
 * and exposes the values as an in-memory {@see AlmaConfiguration} so downstream
 * code (eligibility, payment creation, IPN, refund, fraud, product-widget) does
 * not couple to the Sylius storage shape.
 */
/**
 * @implementation [[sylius-plugin-commons.md#configuration-resolver]] — the Alma payment method of the shop and its configuration
 */
class AlmaPaymentMethodResolver implements ResetInterface
{
    public const GATEWAY_FACTORY_NAME = 'alma';

    private ?AlmaConfiguration $configuration = null;

    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository */
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
    ) {
    }

    public function isAlma(PaymentMethodInterface $paymentMethod): bool
    {
        return $paymentMethod->getGatewayConfig()?->getFactoryName() === self::GATEWAY_FACTORY_NAME;
    }

    /**
     * Memoized for the request: a checkout render reads the configuration from
     * several consumers (eligibility, per-group display texts), each of which
     * would otherwise re-scan the payment method repository.
     */
    public function getConfiguration(): AlmaConfiguration
    {
        if ($this->configuration === null) {
            $paymentMethod = $this->findAlmaPaymentMethod();
            $config = $paymentMethod?->getGatewayConfig()?->getConfig() ?? [];
            $this->configuration = $this->buildConfiguration($config);
        }

        return $this->configuration;
    }

    public function reset(): void
    {
        $this->configuration = null;
    }

    /**
     * Returns the API key configured for the given mode (live or test), or null
     * if the matching key slot is empty / unknown mode.
     */
    public function getApiKeyByMode(string $mode): ?string
    {
        $configuration = $this->getConfiguration();
        $key = match ($mode) {
            AlmaConfiguration::MODE_LIVE => $configuration->getApiKeyLive(),
            AlmaConfiguration::MODE_TEST => $configuration->getApiKeyTest(),
            default => null,
        };

        return $key === '' ? null : $key;
    }

    /**
     * Looks up the unique Alma PaymentMethod (cf. uniqueness invariant in
     * specs-sylius/module-configuration). Returns null when none exists yet
     * (fresh install, before the admin creates one).
     */
    private function findAlmaPaymentMethod(): ?PaymentMethodInterface
    {
        foreach ($this->paymentMethodRepository->findAll() as $paymentMethod) {
            if (!$paymentMethod instanceof PaymentMethodInterface) {
                continue;
            }

            $gatewayConfig = $paymentMethod->getGatewayConfig();
            if (!$gatewayConfig instanceof GatewayConfigInterface) {
                continue;
            }

            if ($gatewayConfig->getFactoryName() === self::GATEWAY_FACTORY_NAME) {
                return $paymentMethod;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildConfiguration(array $config): AlmaConfiguration
    {
        $configuration = new AlmaConfiguration();

        $configuration->setApiKeyLive($this->nullableString($config['api_key_live'] ?? null));
        $configuration->setApiKeyTest($this->nullableString($config['api_key_test'] ?? null));
        $configuration->setApiMode(
            \is_string($config['api_mode'] ?? null) && \in_array($config['api_mode'], AlmaConfiguration::MODES, true)
                ? $config['api_mode']
                : AlmaConfiguration::MODE_TEST,
        );
        $configuration->setMerchantId($this->nullableString($config['merchant_id'] ?? null));
        // The stored JSON is trusted for its shape: the plans were written by FeePlansFetcher from the
        // client's typed getters, the overrides and the display texts by the configuration form.
        /** @var array<string, array<string, mixed>>|null $feePlans */
        $feePlans = $this->nullableArray($config['fee_plans'] ?? null);
        $configuration->setFeePlans($feePlans);
        /** @var array<string, array<string, mixed>>|null $feePlanOverrides */
        $feePlanOverrides = $this->nullableArray($config['fee_plan_overrides'] ?? null);
        $configuration->setFeePlanOverrides($feePlanOverrides);
        $configuration->setProductWidgetEnabled((bool) ($config['product_widget_enabled'] ?? true));
        /** @var array<string, array<string, array<string, mixed>>>|null $displayTexts */
        $displayTexts = $this->nullableArray($config['display_texts'] ?? null);
        $configuration->setDisplayTexts($displayTexts);

        return $configuration;
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    /** @return array<mixed>|null */
    private function nullableArray(mixed $value): ?array
    {
        return \is_array($value) && $value !== [] ? $value : null;
    }
}
