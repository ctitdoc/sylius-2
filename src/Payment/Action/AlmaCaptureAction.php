<?php

declare(strict_types=1);

namespace Alma\Sylius\Payment\Action;

use Alma\Client\Application\Exception\Endpoint\PaymentEndpointException;
use Alma\Sylius\Api\PaymentEndpointFactoryInterface;
use Alma\Sylius\Entity\AlmaPaymentReference;
use Alma\Sylius\Payment\AlmaPaymentDetailsKeys;
use Alma\Sylius\Payment\AlmaPaymentRequestBuilder;
use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface as SyliusPaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Calls Alma's PaymentEndpoint::create() at place-order time, persists the
 * 4 references (alma_payment_id, alma_payment_url, alma_fee_plan_key, alma_mode)
 * on the Sylius Payment's details JSON column, then throws a Payum HttpRedirect
 * reply pointing to the Alma hosted payment page (302 Location). The Payum
 * gateway catches the reply and Sylius converts it to a Symfony RedirectResponse.
 *
 * On PaymentEndpointException (timeout, server error, etc.) the action
 * transitions the Sylius Payment to `failed` via the state machine, adds a
 * generic flash message and redirects the customer to the order summary page
 * where they can retry the payment (no rollback of the order, no technical
 * detail surfaced).
 *
 * @implements [[payment-creation]] — the whole of it: creates the Alma payment and redirects
 * @implements [[payment-return]] — a second capture of an already created payment redirects again
 */
final class AlmaCaptureAction implements ActionInterface
{
    public function __construct(
        private readonly AlmaPaymentMethodResolver $configurationResolver,
        private readonly AlmaPaymentRequestBuilder $requestBuilder,
        private readonly PaymentEndpointFactoryInterface $endpointFactory,
        private readonly StateMachineInterface $stateMachine,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $payment = $request->getModel();
        if (!$payment instanceof SyliusPaymentInterface) {
            return;
        }

        $details = $payment->getDetails();
        if (isset($details[AlmaPaymentDetailsKeys::PAYMENT_ID], $details[AlmaPaymentDetailsKeys::PAYMENT_URL])) {
            // Already created — likely a retry of the same place-order. Redirect
            // to the existing Alma hosted page without re-calling the API.
            throw new HttpRedirect($details[AlmaPaymentDetailsKeys::PAYMENT_URL]);
        }

        $planKey = $details[AlmaPaymentDetailsKeys::FEE_PLAN_KEY] ?? null;
        if (!is_string($planKey) || $planKey === '') {
            throw new \LogicException('Cannot create Alma payment: missing alma_fee_plan_key in payment details.');
        }

        $mode = $this->configurationResolver->getConfiguration()->getApiMode();
        $apiKey = $this->configurationResolver->getApiKeyByMode($mode);
        if ($apiKey === null) {
            throw new \LogicException(sprintf('Cannot create Alma payment: active mode "%s" has no API key.', $mode));
        }

        $almaRequest = $this->requestBuilder->build($payment, $planKey);

        try {
            $almaPayment = $this->endpointFactory->make($apiKey, $mode)->create(
                $almaRequest->payment,
                $almaRequest->order,
                $almaRequest->customer,
            );
        } catch (PaymentEndpointException $e) {
            $this->logger->error('alma.payment.create_failure', [
                'mode' => $mode,
                'order' => $payment->getOrder()?->getId(),
                'amount' => $payment->getAmount(),
                'exception' => $e::class,
            ]);

            $this->handleCreateFailure($payment);

            throw $e;
        }

        $details[AlmaPaymentDetailsKeys::PAYMENT_ID] = $almaPayment->getId();
        $details[AlmaPaymentDetailsKeys::PAYMENT_URL] = $almaPayment->getUrl();
        $details[AlmaPaymentDetailsKeys::MODE] = $mode;
        // FEE_PLAN_KEY is already present from the C6 form submission, keep as-is
        $payment->setDetails($details);

        // Double persistence : indexed AlmaPaymentReference for ORM-native lookup
        // (pid → Payment + Order + persisted mode), in addition to the JSON
        // payload above. Consumed by AlmaPaymentResolver for return/IPN/refund.
        $reference = new AlmaPaymentReference(
            $almaPayment->getId(),
            $mode,
            $payment,
            $payment->getOrder(),
        );
        $this->entityManager->persist($reference);
        $this->entityManager->flush();

        throw new HttpRedirect($almaPayment->getUrl());
    }

    public function supports($request): bool
    {
        return $request instanceof Capture && $request->getModel() instanceof SyliusPaymentInterface;
    }

    /**
     * On Alma API failure: transition the Sylius Payment to `failed` (so the
     * Sylius UI can offer a retry on the same order), add a generic flash
     * message and redirect the customer to the order summary page. The order
     * itself stays in its post-cart state — no rollback (cf. spec
     * "Failure leaves the order in pending state").
     */
    private function handleCreateFailure(SyliusPaymentInterface $payment): void
    {
        if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL)) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_FAIL);
        }

        $request = $this->requestStack->getMainRequest();
        if ($request !== null && $request->hasSession()) {
            $session = $request->getSession();
            if ($session instanceof Session) {
                $session->getFlashBag()->add('error', 'alma_sylius.flash.payment_creation_failed');
            }
        }

        $order = $payment->getOrder();
        $tokenValue = $order?->getTokenValue();
        if ($tokenValue === null) {
            return;
        }

        $url = $this->urlGenerator->generate(
            'sylius_shop_order_show',
            ['tokenValue' => $tokenValue],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        throw new HttpRedirect($url);
    }
}
