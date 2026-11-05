<?php

declare(strict_types=1);

namespace Alma\Sylius\Payment;

use Alma\Client\Application\Exception\Endpoint\PaymentEndpointException;
use Alma\Sylius\Api\PaymentEndpointFactoryInterface;
use Alma\Sylius\Entity\AlmaConfiguration;
use Alma\Sylius\Repository\AlmaPaymentReferenceRepository;
use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Single-pid → (AlmaPayment, Sylius order, Sylius payment) resolver.
 *
 * Shared between the customer-return capability (E*) and the IPN-processing
 * capability (F*). The contract is:
 *
 * 1. Trust ONLY the `pid` from the inbound URL/payload as an opaque lookup key.
 * 2. Find the local `AlmaPaymentReference` indexed on `alma_payment_id`. This
 *    gives ORM-native access to Sylius Payment, Sylius Order and the persisted
 *    `alma_mode` — without going through the Sylius Payment's JSON details.
 * 3. Use the persisted `alma_mode` — NOT the currently active global mode —
 *    to pick the API key for the Alma fetch. This is the contract acted in
 *    `payment-creation` Requirement « Alma references are persisted on the CMS
 *    order »: a merchant switching modes between creation and downstream
 *    operations MUST NOT misroute the call.
 * 4. Re-fetch the Alma payment server-to-server with the persisted-mode key
 *    (source of truth for `processing_status`, `purchase_amount`, etc.).
 * 5. Cross-check that Alma's echoed `custom_data.order_id` matches the local
 *    order's number. Defends against tampering or stale-pid replay where the
 *    `pid` would map to a Payment for a different order in the local DB.
 *
 * Callers MUST NOT short-circuit any of the five steps. Downstream anti-
 * fraud checks (E2, F2) operate on the ResolvedPayment returned here.
 *
 * Every failure mode logs a clear `reason` for operational diagnostics.
 *
 * @implements [[sylius-plugin-commons.md#payment-resolution]]
 */
class AlmaPaymentResolver
{
    public function __construct(
        private readonly AlmaPaymentMethodResolver $configurationResolver,
        private readonly PaymentEndpointFactoryInterface $endpointFactory,
        private readonly AlmaPaymentReferenceRepository $referenceRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function resolve(string $pid): ?ResolvedPayment
    {
        if ($pid === '') {
            return null;
        }

        $reference = $this->referenceRepository->findOneByAlmaPaymentId($pid);
        if ($reference === null) {
            $this->logger->warning('alma.payment.resolve_failure', [
                'reason' => 'no_local_reference_for_pid',
            ]);

            return null;
        }

        $persistedMode = $reference->getAlmaMode();
        if (!in_array($persistedMode, [AlmaConfiguration::MODE_LIVE, AlmaConfiguration::MODE_TEST], true)) {
            $this->logger->error('alma.payment.resolve_failure', [
                'reason' => 'invalid_persisted_alma_mode',
                'reference_id' => $reference->getId(),
            ]);

            return null;
        }

        $apiKey = $this->configurationResolver->getApiKeyByMode($persistedMode);
        if ($apiKey === null) {
            $this->logger->error('alma.payment.resolve_failure', [
                'reason' => 'missing_api_key_for_persisted_mode',
                'mode' => $persistedMode,
                'reference_id' => $reference->getId(),
            ]);

            return null;
        }

        try {
            $almaPayment = $this->endpointFactory->make($apiKey, $persistedMode)->fetch($pid);
        } catch (PaymentEndpointException $e) {
            $this->logger->error('alma.payment.resolve_failure', [
                'reason' => 'fetch_failed',
                'mode' => $persistedMode,
                'reference_id' => $reference->getId(),
                'exception' => $e::class,
            ]);

            return null;
        }

        $order = $reference->getSyliusOrder();
        $echoedOrderNumber = $almaPayment->getCustomData()['order_id'] ?? null;
        $expectedOrderNumber = $order->getNumber();
        if (!is_string($echoedOrderNumber) || $echoedOrderNumber !== $expectedOrderNumber) {
            $this->logger->error('alma.payment.resolve_failure', [
                'reason' => 'custom_data_order_id_mismatch',
                'reference_id' => $reference->getId(),
                'expected_order_number' => $expectedOrderNumber,
            ]);

            return null;
        }

        return new ResolvedPayment($almaPayment, $order, $reference->getSyliusPayment(), $persistedMode);
    }
}
