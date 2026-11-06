<?php

declare(strict_types=1);

namespace Alma\Sylius\Payment;

use Alma\Client\Application\Exception\Endpoint\PaymentEndpointException;
use Alma\Sylius\Api\PaymentEndpointFactoryInterface;
use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\OrderTransitions;

/**
 * Side-effects on fraud detection (cf. customer-return spec) :
 *
 *  1. Server-to-server `PaymentEndpoint::flagAsPotentialFraud($pid, $reason)`.
 *     Best-effort : an HTTP failure is logged but does not block the rest.
 *  2. Persist `alma_fraud_reason` in Payment.details for back-office visibility.
 *  3. Cancel the order via the `sylius_order` state machine — **only for
 *     tampering reasons** (amount/pid/expired). For `state_error` (return URL
 *     hit while the Alma payment never reached authorized/captured) we flag
 *     the fraud attempt but leave the order intact, so a confused customer
 *     can retry through another payment method.
 */
class AlmaFraudFlagger
{
    public function __construct(
        private readonly AlmaPaymentMethodResolver $configurationResolver,
        private readonly PaymentEndpointFactoryInterface $endpointFactory,
        private readonly StateMachineInterface $stateMachine,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function flag(ResolvedPayment $resolved, AlmaFraudCheckResult $result): void
    {
        if ($result->passed) {
            return;
        }

        $reason = $result->reason ?? AlmaFraudCheckResult::REASON_STATE_ERROR;

        $this->logger->error('alma.payment.fraud_detected', [
            'reason' => $reason,
            'order' => $resolved->order->getId(),
            'alma_payment_id' => $resolved->almaPayment->getId(),
        ]);

        // 1. Notify Alma server-to-server (best-effort).
        $this->flagAlma($resolved->almaPayment->getId(), $resolved->almaMode, $reason);

        // 2. Persist the fraud reason on the Sylius Payment details for BO visibility.
        $details = $resolved->syliusPayment->getDetails();
        $details[AlmaPaymentDetailsKeys::FRAUD_REASON] = $reason;
        $resolved->syliusPayment->setDetails($details);

        // 3. Cancel the order — only when the mismatch points to tampering.
        // A bare state_error (return URL hit while the Alma payment never
        // got authorized/captured) is flagged as a fraud attempt but the
        // order itself stays alive so the customer can retry.
        if ($reason !== AlmaFraudCheckResult::REASON_STATE_ERROR &&
            $this->stateMachine->can(
                $resolved->order,
                OrderTransitions::GRAPH,
                OrderTransitions::TRANSITION_CANCEL,
            )
        ) {
            $this->stateMachine->apply(
                $resolved->order,
                OrderTransitions::GRAPH,
                OrderTransitions::TRANSITION_CANCEL,
            );
        }

        // 4. Flush — the return route is not a Sylius CRUD action and has no
        // auto-flush middleware. We commit the cancel + details patch explicitly.
        $this->entityManager->flush();
    }

    private function flagAlma(string $pid, string $mode, string $reason): void
    {
        $apiKey = $this->configurationResolver->getApiKeyByMode($mode);
        if ($apiKey === null) {
            $this->logger->error('alma.payment.fraud_flag_skipped', [
                'reason' => 'missing_api_key',
                'mode' => $mode,
            ]);

            return;
        }

        try {
            $this->endpointFactory->make($apiKey, $mode)->flagAsPotentialFraud($pid, $reason);
        } catch (PaymentEndpointException $e) {
            $this->logger->error('alma.payment.fraud_flag_failure', [
                'pid' => $pid,
                'reason' => $reason,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'status' => $e->response?->getStatusCode(),
            ]);
        }
    }
}
