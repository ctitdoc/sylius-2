<?php

declare(strict_types=1);

namespace Alma\Sylius\Api;

use Alma\Plugin\Infrastructure\Adapter\FeePlanAdapterInterface;
use Alma\Plugin\Infrastructure\Adapter\FeePlanAdapterLocalConfigurationAwareInterface;
use Alma\Plugin\Infrastructure\Adapter\FeePlanInterface;

/**
 * A plan as the plugin sees it: Alma's data for the plan plus the Merchant's override. Alma's data
 * is scalar by construction — FeePlansFetcher writes it from the typed getters of the client's
 * FeePlan entity, whose typed properties reject anything else — and the override is written by the
 * configuration form; the shapes below say so, and the reads need no conversion.
 *
 * @phpstan-type StoredPlan array{kind?: string, installments_count?: int, deferred_days?: int, deferred_months?: int, allowed?: bool, min_purchase_amount?: int, max_purchase_amount?: int, merchant_fee_fixed?: int|null, merchant_fee_variable?: int|null, customer_fee_variable?: int|null}
 * @phpstan-type PlanOverride array{enabled?: bool, override_min_purchase_amount?: int|null, override_max_purchase_amount?: int|null}
 *
 * @implementation [[sylius-plugin-commons.md#fee-plans]] — a plan as the plugin sees it: Alma's data plus the Merchant's override
 */
final class FeePlanAdapter implements FeePlanInterface, FeePlanAdapterInterface, FeePlanAdapterLocalConfigurationAwareInterface
{
    /**
     * @param array<string, mixed> $apiData    the fee plan as returned by Alma (one entry from AlmaConfiguration::feePlans)
     * @param array<string, mixed> $override   the merchant override entry (one entry from AlmaConfiguration::feePlanOverrides),
     *                                         may be empty when no override is set
     */
    /**
     * @param StoredPlan $apiData
     * @param PlanOverride $override
     */
    public function __construct(
        private readonly array $apiData,
        private array $override = [],
    ) {
    }

    public function getPlanKey(): string
    {
        return sprintf(
            '%s_%d_%d_%d',
            $this->getKind(),
            $this->getInstallmentsCount(),
            $this->getDeferredDays(),
            $this->getDeferredMonths(),
        );
    }

    public function isAllowed(): bool
    {
        return $this->apiData['allowed'] ?? false;
    }

    public function isEligible(int $purchaseAmount): bool
    {
        return $purchaseAmount >= $this->getMinPurchaseAmount() &&
            $purchaseAmount <= $this->getMaxPurchaseAmount();
    }

    public function isAvailable(): bool
    {
        return $this->isAllowed() && $this->isEnabled();
    }

    public function isAvailableOnline(): bool
    {
        return true;
    }

    public function getMinPurchaseAmount(): int
    {
        return $this->effectiveMin();
    }

    public function getMaxPurchaseAmount(): int
    {
        return $this->effectiveMax();
    }

    public function getOverrideMinPurchaseAmount(): int
    {
        return $this->effectiveMin();
    }

    public function getOverrideMaxPurchaseAmount(): int
    {
        return $this->effectiveMax();
    }

    public function setOverrideMinPurchaseAmount(int $overrideMinPurchaseAmount): void
    {
        $this->override['override_min_purchase_amount'] = $overrideMinPurchaseAmount;
    }

    public function setOverrideMaxPurchaseAmount(int $overrideMaxPurchaseAmount): void
    {
        $this->override['override_max_purchase_amount'] = $overrideMaxPurchaseAmount;
    }

    public function resetOverrideMinPurchaseAmount(): void
    {
        unset($this->override['override_min_purchase_amount']);
    }

    public function resetOverrideMaxPurchaseAmount(): void
    {
        unset($this->override['override_max_purchase_amount']);
    }

    public function enable(): void
    {
        $this->override['enabled'] = true;
    }

    public function disable(): void
    {
        $this->override['enabled'] = false;
    }

    public function isEnabled(): bool
    {
        return $this->override['enabled'] ?? false;
    }

    public function getDeferredDays(): int
    {
        return $this->apiData['deferred_days'] ?? 0;
    }

    public function getDeferredMonths(): int
    {
        return $this->apiData['deferred_months'] ?? 0;
    }

    public function getInstallmentsCount(): int
    {
        return $this->apiData['installments_count'] ?? 1;
    }

    public function getMerchantFeeFixed(): ?int
    {
        return $this->apiData['merchant_fee_fixed'] ?? null;
    }

    public function getMerchantFeeVariable(): ?int
    {
        return $this->apiData['merchant_fee_variable'] ?? null;
    }

    public function getCustomerFeeVariable(): ?int
    {
        return $this->apiData['customer_fee_variable'] ?? null;
    }

    public function getKind(): string
    {
        return $this->apiData['kind'] ?? 'general';
    }

    public function getLabel(): string
    {
        if ($this->getDeferredDays() > 0) {
            return sprintf('Paiement à J+%d', $this->getDeferredDays());
        }
        if ($this->getDeferredMonths() > 0) {
            return sprintf('Paiement à M+%d', $this->getDeferredMonths());
        }

        return sprintf('Paiement en %d×', $this->getInstallmentsCount());
    }

    public function getApiMinPurchaseAmount(): int
    {
        return $this->apiData['min_purchase_amount'] ?? 0;
    }

    public function getApiMaxPurchaseAmount(): int
    {
        return $this->apiData['max_purchase_amount'] ?? 0;
    }

    /**
     * Returns the persistable override entry for this plan (or an empty array if
     * the plan is at default state). Callers use this to write back to
     * AlmaConfiguration::feePlanOverrides.
     *
     * @return array<string, mixed>
     */
    /** @return PlanOverride */
    public function getOverrideData(): array
    {
        return $this->override;
    }

    private function effectiveMin(): int
    {
        return $this->override['override_min_purchase_amount'] ?? $this->getApiMinPurchaseAmount();
    }

    private function effectiveMax(): int
    {
        return $this->override['override_max_purchase_amount'] ?? $this->getApiMaxPurchaseAmount();
    }
}
