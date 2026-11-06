<?php

declare(strict_types=1);

namespace Alma\Sylius\Entity;

/**
 * In-memory representation of the Alma configuration values exposed to the
 * rest of the plugin via {@see \Alma\Sylius\Resolver\AlmaPaymentMethodResolver::getConfiguration()}.
 *
 * Historically a Doctrine entity backed by a singleton `alma_configuration`
 * table; since the move-config-to-payment-method change the data lives in the
 * `config` JSON of the unique Alma {@see \Sylius\Component\Payment\Model\PaymentMethodInterface}'s
 * gatewayConfig (Sylius-native pattern, see specs-sylius/module-configuration).
 *
 * This class is no longer persisted by Doctrine. The resolver builds it from
 * the JSON config on demand, downstream consumers keep the same getter API.
 */
class AlmaConfiguration
{
    public const MODE_LIVE = 'live';

    public const MODE_TEST = 'test';

    public const MODES = [self::MODE_LIVE, self::MODE_TEST];

    private ?string $apiKeyLive = null;

    private ?string $apiKeyTest = null;

    private string $apiMode = self::MODE_TEST;

    private ?string $merchantId = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $feePlans = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $feePlanOverrides = null;

    private bool $productWidgetEnabled = true;

    /** @var array<string, array<string, array<string, mixed>>>|null */
    private ?array $displayTexts = null;

    public function getApiKeyLive(): ?string
    {
        return $this->apiKeyLive;
    }

    public function setApiKeyLive(?string $apiKeyLive): self
    {
        $this->apiKeyLive = $apiKeyLive;

        return $this;
    }

    public function getApiKeyTest(): ?string
    {
        return $this->apiKeyTest;
    }

    public function setApiKeyTest(?string $apiKeyTest): self
    {
        $this->apiKeyTest = $apiKeyTest;

        return $this;
    }

    public function getApiMode(): string
    {
        return $this->apiMode;
    }

    public function setApiMode(string $apiMode): self
    {
        $this->apiMode = $apiMode;

        return $this;
    }

    public function getMerchantId(): ?string
    {
        return $this->merchantId;
    }

    public function setMerchantId(?string $merchantId): self
    {
        $this->merchantId = $merchantId;

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public function getFeePlans(): ?array
    {
        return $this->feePlans;
    }

    /**
     * @param array<string, array<string, mixed>>|null $feePlans
     */
    public function setFeePlans(?array $feePlans): self
    {
        $this->feePlans = $feePlans;

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public function getFeePlanOverrides(): ?array
    {
        return $this->feePlanOverrides;
    }

    /**
     * @param array<string, array<string, mixed>>|null $feePlanOverrides
     */
    public function setFeePlanOverrides(?array $feePlanOverrides): self
    {
        $this->feePlanOverrides = $feePlanOverrides;

        return $this;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>|null
     */
    public function getDisplayTexts(): ?array
    {
        return $this->displayTexts;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>>|null $displayTexts
     */
    public function setDisplayTexts(?array $displayTexts): self
    {
        $this->displayTexts = $displayTexts;

        return $this;
    }

    public function isProductWidgetEnabled(): bool
    {
        return $this->productWidgetEnabled;
    }

    public function setProductWidgetEnabled(bool $productWidgetEnabled): self
    {
        $this->productWidgetEnabled = $productWidgetEnabled;

        return $this;
    }
}
