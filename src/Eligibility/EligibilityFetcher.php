<?php

declare(strict_types=1);

namespace Alma\Sylius\Eligibility;

use Alma\Client\Application\DTO\AddressDto;
use Alma\Client\Application\DTO\EligibilityDto;
use Alma\Client\Application\DTO\EligibilityQueryDto;
use Alma\Client\Application\DTO\PaymentDto;
use Alma\Client\Application\Exception\Endpoint\EligibilityEndpointException;
use Alma\Client\Application\Exception\ParametersException;
use Alma\Client\Domain\Entity\Eligibility as AlmaResponseEligibility;
use Alma\Client\Domain\Entity\Installment;
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
/**
 * @implementation [[eligibility-check]] — asks Alma for the eligibility of an amount to the active plans, with the key of the active mode
 */
class EligibilityFetcher
{
    /** The only state a forecast instalment can have; Alma's eligibility rows carry none. */
    private const FORECAST_STATE = 'pending';

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
            if (!$remote instanceof AlmaResponseEligibility) {
                continue;
            }
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
            $result[$planKey] = new AlmaEligibility($planKey, $local, $remote, $this->installments($remote, $planKey));
        }

        return $result;
    }

    /**
     * The schedule rows of an eligibility answer, which the client returns raw (unlike a Payment's),
     * wrapped in its Installment entity with `state: pending` — a forecast schedule has no other
     * state — so that its typed properties do the typing. A row the client rejects is skipped and
     * reported; a `total_amount` that differs from purchase amount plus fees plus interest is
     * reported. Question 1 to Alma's engineering team, workspace README.
     *
     * @return list<Installment>
     */
    private function installments(AlmaResponseEligibility $remote, string $planKey): array
    {
        $installments = [];
        foreach ($remote->getPaymentPlan() as $index => $row) {
            if (!\is_array($row)) {
                $this->logger->warning('alma.eligibility.row_rejected', ['plan' => $planKey, 'row' => $index, 'reason' => 'not an object']);

                continue;
            }

            try {
                $installment = new Installment($row + ['state' => self::FORECAST_STATE]);
            } catch (ParametersException|\TypeError $e) {
                $this->logger->warning('alma.eligibility.row_rejected', ['plan' => $planKey, 'row' => $index, 'reason' => $e->getMessage()]);

                continue;
            }
            $sum = $installment->getPurchaseAmount() + $installment->getCustomerFee() + $installment->getCustomerInterest();
            if (isset($row['total_amount']) && $row['total_amount'] !== $sum) {
                $this->logger->warning('alma.eligibility.total_amount_mismatch', ['plan' => $planKey, 'row' => $index, 'total_amount' => $row['total_amount'], 'sum' => $sum]);
            }
            $installments[] = $installment;
        }

        return $installments;
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
