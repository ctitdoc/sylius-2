<?php

declare(strict_types=1);

namespace Alma\Sylius\Eligibility;

use Alma\Client\Application\DTO\AddressDto;
use Alma\Client\Application\DTO\EligibilityDto;
use Alma\Client\Application\DTO\EligibilityQueryDto;
use Alma\Client\Application\DTO\PaymentDto;
use Alma\Client\Application\Exception\Endpoint\EligibilityEndpointException;
use Alma\Sylius\Api\EligibilityEndpointFactoryInterface;
use Alma\Sylius\Api\FeePlanAdapter;
use Alma\Sylius\Entity\AlmaConfiguration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds an eligibility request from a cart total + addresses, calls
 * `EligibilityEndpoint::getEligibilityList()` with the active-mode credentials,
 * and exposes the response via plugin-side {@see AlmaEligibility} adapters.
 *
 * Honours the spec contract:
 *   - filters fee plans through the local pre-filter (cf. checkout spec,
 *     "Local pre-filtering of fee plans"), so the candidates source of truth is
 *     the merged view API + overrides;
 *   - skips the HTTP call entirely when zero local candidate exists;
 *   - uses the active-mode API key (cf. Module Configuration);
 *   - returns the eligibility result as plugin-side adapters, never as raw
 *     PHP client objects.
 */
class EligibilityFetcher
{
    public function __construct(
        private readonly FeePlanCandidateProvider $candidateProvider,
        private readonly EligibilityEndpointFactoryInterface $endpointFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array<string, AlmaEligibility> keyed by functional plan key
     *
     * @throws EligibilityFetchFailedException
     */
    public function fetch(
        AlmaConfiguration $configuration,
        int $purchaseAmountInCents,
        ?AddressDto $billingAddress = null,
        ?AddressDto $shippingAddress = null,
    ): array {
        $candidates = $this->candidateProvider->findCandidates($configuration, $purchaseAmountInCents);
        if ($candidates === []) {
            return [];
        }

        $activeMode = $configuration->getApiMode();
        $activeKey = $activeMode === AlmaConfiguration::MODE_LIVE
            ? $configuration->getApiKeyLive()
            : $configuration->getApiKeyTest();
        if ($activeKey === null || $activeKey === '') {
            return [];
        }

        $dto = new EligibilityDto($purchaseAmountInCents);
        $dto->setOrigin(PaymentDto::ORIGIN_ONLINE);
        foreach ($candidates as $candidate) {
            $dto->addQuery($this->buildQuery($candidate));
        }
        if ($billingAddress !== null) {
            $dto->setBillingAddress($billingAddress);
        }
        if ($shippingAddress !== null) {
            $dto->setShippingAddress($shippingAddress);
        }

        try {
            $remoteList = $this->endpointFactory->make($activeKey, $activeMode)->getEligibilityList($dto);
        } catch (EligibilityEndpointException $e) {
            $this->logger->error('alma.eligibility.failure', [
                'mode' => $activeMode,
                'amount' => $purchaseAmountInCents,
                'exception' => $e::class,
            ]);

            throw new EligibilityFetchFailedException($e->getMessage(), 0, $e);
        }

        $result = [];
        foreach ($remoteList as $remote) {
            $planKey = sprintf(
                '%s_%d_%d_%d',
                $remote->getKind(),
                $remote->getInstallmentsCount(),
                $remote->getDeferredDays(),
                $remote->getDeferredMonths(),
            );
            $local = $candidates[$planKey] ?? null;
            if ($local === null) {
                continue;
            }
            $result[$planKey] = new AlmaEligibility($planKey, $local, $remote);
        }

        return $result;
    }

    private function buildQuery(FeePlanAdapter $adapter): EligibilityQueryDto
    {
        $query = new EligibilityQueryDto($adapter->getInstallmentsCount());
        if ($adapter->getDeferredDays() > 0) {
            $query->setDeferredDays($adapter->getDeferredDays());
        }
        if ($adapter->getDeferredMonths() > 0) {
            $query->setDeferredMonths($adapter->getDeferredMonths());
        }

        return $query;
    }
}
