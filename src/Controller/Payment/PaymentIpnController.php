<?php

declare(strict_types=1);

namespace Alma\Sylius\Controller\Payment;

use Alma\Sylius\Payment\AlmaFraudChecker;
use Alma\Sylius\Payment\AlmaFraudFlagger;
use Alma\Sylius\Payment\AlmaIpnSignatureVerifier;
use Alma\Sylius\Payment\AlmaPaymentCompletionHelper;
use Alma\Sylius\Payment\AlmaPaymentResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Public IPN endpoint hit by Alma server-to-server to notify of a payment
 * state change.
 *
 * Stack of tickets that built this controller, in order:
 * - F1 (ECOM-4146) — reception + info log `alma.ipn.received`.
 * - F2 (ECOM-4147) — mandatory HMAC-SHA256 signature verification → 401 on
 *   missing/invalid signature.
 * - F3 (ECOM-4148) — delegation to `AlmaPaymentCompletionHelper` on the
 *   success path (idempotent payment.complete transition).
 * - F4 (ECOM-4149) — anti-fraud checks shared with the customer return route
 *   (E2/ECOM-4143) and HTTP code mapping (200/404).
 *
 * HTTP response codes — mapped to Alma's retry policy:
 * - 200 : signature valide + (transition appliquée, helper no-op idempotent
 *   sur replay, ou fraude détectée + flagged). L'IPN a été traité côté plugin,
 *   Alma ne doit pas retry.
 * - 401 : signature absente ou invalide (F2).
 * - 404 : resolver retourne null (« order cannot be located »). Couvre les
 *   cas pid bidon, custom_data manquante, order absente en BDD.
 * - 500 : exceptions imprévues (DB down pendant flush, etc.) — bubble jusqu'au
 *   handler par défaut Symfony, pas de try/catch explicite à ce niveau.
 *
 * Note : le resolver swallow `PaymentEndpointException` (transient Alma fetch)
 * et le mappe en null comme les vrais « not found ». Mapper null → 404 est
 * strict vs le texte de la spec (404 « when the order cannot be located ») mais
 * perd la sémantique 500/retry sur fetch_failed transient. Trade-off MVP
 * assumé — un futur ticket pourra refacto le resolver si nécessaire.
 */
#[Route(
    '/alma/payment/ipn',
    name: 'alma_sylius_payment_ipn',
    methods: ['GET'],
)]
/**
 * @implementation [[payment-notification]] — Alma's server-to-server notification: signed, then read back, checked and completed
 */
final class PaymentIpnController
{
    private const SIGNATURE_HEADER = 'X-Alma-Signature';

    private const SIGNATURE_QUERY_PARAM = 'signature';

    public function __construct(
        private readonly AlmaIpnSignatureVerifier $signatureVerifier,
        private readonly AlmaPaymentResolver $paymentResolver,
        private readonly AlmaFraudChecker $fraudChecker,
        private readonly AlmaFraudFlagger $fraudFlagger,
        private readonly AlmaPaymentCompletionHelper $completionHelper,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $signature = (string) $request->headers->get(self::SIGNATURE_HEADER, '');
        if ($signature === '') {
            $this->logger->warning('alma.ipn.signature_missing');

            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $params = [];
        foreach ($request->query->all() as $name => $value) {
            // Alma signs scalar query parameters; anything else is not part of the signature.
            if (self::SIGNATURE_QUERY_PARAM !== $name && \is_scalar($value)) {
                $params[$name] = (string) $value;
            }
        }

        if (!$this->signatureVerifier->verify($signature, $params)) {
            $this->logger->warning('alma.ipn.signature_invalid');

            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $rawPid = $request->query->get('pid');
        $pid = is_string($rawPid) && $rawPid !== '' ? $rawPid : null;

        if ($pid === null) {
            $this->logger->warning('alma.ipn.missing_pid');

            return new JsonResponse(['status' => 'ok', 'pid' => null]);
        }

        $this->logger->info('alma.ipn.received', [
            'payment_id' => $pid,
        ]);

        $resolved = $this->paymentResolver->resolve($pid);
        if ($resolved === null) {
            $this->logger->warning('alma.ipn.payment_not_resolved', [
                'payment_id' => $pid,
            ]);

            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $checkResult = $this->fraudChecker->check($resolved);
        if (!$checkResult->passed) {
            $this->fraudFlagger->flag($resolved, $checkResult);

            return new JsonResponse(['status' => 'ok', 'pid' => $pid]);
        }

        $this->completionHelper->complete($resolved);

        return new JsonResponse(['status' => 'ok', 'pid' => $pid]);
    }
}
