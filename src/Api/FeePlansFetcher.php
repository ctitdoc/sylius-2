<?php

declare(strict_types=1);

namespace Alma\Sylius\Api;

use Alma\Client\Application\Exception\Endpoint\MerchantEndpointException;
use Alma\Client\Domain\Entity\FeePlan;

/** @phpstan-import-type StoredPlan from FeePlanAdapter */
class FeePlansFetcher
{
    public function __construct(
        private readonly MerchantEndpointFactoryInterface $endpointFactory,
    ) {
    }

    /**
     * @return array<string, StoredPlan> plan key → Alma's data, from the client's typed getters
     *
     * @throws FeePlansFetchFailedException
     */
    public function fetch(string $apiKey, string $mode): array
    {
        try {
            $feePlanList = $this->endpointFactory->make($apiKey, $mode)->getFeePlanList(
                FeePlan::KIND_GENERAL,
                'all',
                true,
            );
        } catch (MerchantEndpointException $e) {
            throw new FeePlansFetchFailedException($e->getMessage(), 0, $e);
        }

        $result = [];
        foreach ($feePlanList as $feePlan) {
            /** @var FeePlan $feePlan */
            $key = sprintf(
                '%s_%d_%d_%d',
                $feePlan->getKind(),
                $feePlan->getInstallmentsCount(),
                $feePlan->getDeferredDays(),
                $feePlan->getDeferredMonths(),
            );

            $result[$key] = [
                'kind' => $feePlan->getKind(),
                'installments_count' => $feePlan->getInstallmentsCount(),
                'deferred_days' => $feePlan->getDeferredDays(),
                'deferred_months' => $feePlan->getDeferredMonths(),
                'allowed' => $feePlan->isAllowed(),
                'min_purchase_amount' => $feePlan->getMinPurchaseAmount(),
                'max_purchase_amount' => $feePlan->getMaxPurchaseAmount(),
                'merchant_fee_fixed' => $feePlan->getMerchantFeeFixed(),
                'merchant_fee_variable' => $feePlan->getMerchantFeeVariable(),
                'customer_fee_variable' => $feePlan->getCustomerFeeVariable(),
            ];
        }

        return $result;
    }
}
