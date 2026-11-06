<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Controller\Payment;

use Alma\Client\Domain\Entity\Payment as AlmaPayment;
use Alma\Sylius\Controller\Payment\PaymentIpnController;
use Alma\Sylius\Payment\AlmaFraudChecker;
use Alma\Sylius\Payment\AlmaFraudCheckResult;
use Alma\Sylius\Payment\AlmaFraudFlagger;
use Alma\Sylius\Payment\AlmaIpnSignatureVerifier;
use Alma\Sylius\Payment\AlmaPaymentCompletionHelper;
use Alma\Sylius\Payment\AlmaPaymentResolver;
use Alma\Sylius\Payment\ResolvedPayment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Characterisation of the server-to-server notification ([[payment-notification]], a GET with the
 * payment id and the signature in the query, as Alma sends it): refused before
 * anything is read unless signed ([[notification-is-signed]]); then the payment is read back from
 * Alma, checked against the order ([[payment-matches-order]]) and completed.
 *
 * @implementation [[payment-notification]]
 */
final class PaymentIpnControllerTest extends TestCase
{
    private AlmaIpnSignatureVerifier&MockObject $verifier;

    private AlmaPaymentResolver&MockObject $resolver;

    private AlmaFraudChecker&MockObject $fraudChecker;

    private AlmaFraudFlagger&MockObject $fraudFlagger;

    private AlmaPaymentCompletionHelper&MockObject $completion;

    protected function setUp(): void
    {
        $this->verifier = $this->createMock(AlmaIpnSignatureVerifier::class);
        $this->resolver = $this->createMock(AlmaPaymentResolver::class);
        $this->fraudChecker = $this->createMock(AlmaFraudChecker::class);
        $this->fraudFlagger = $this->createMock(AlmaFraudFlagger::class);
        $this->completion = $this->createMock(AlmaPaymentCompletionHelper::class);
    }

    public function testANotificationWithoutSignatureIsRefusedBeforeAnythingIsRead(): void
    {
        $this->verifier->expects(self::never())->method('verify');
        $this->resolver->expects(self::never())->method('resolve');

        $response = $this->controller()(Request::create('/alma/ipn', 'GET', ['pid' => 'payment_x']));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testANotificationWithABadSignatureIsRefused(): void
    {
        $this->verifier->expects(self::once())->method('verify')->with('bad', ['pid' => 'payment_x'])->willReturn(false);
        $this->resolver->expects(self::never())->method('resolve');

        $response = $this->controller()($this->signed('bad', ['pid' => 'payment_x', 'signature' => 'bad']));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testASignedNotificationWithoutPaymentIdIsAcknowledgedAndIgnored(): void
    {
        $this->verifier->method('verify')->willReturn(true);
        $this->resolver->expects(self::never())->method('resolve');

        $response = $this->controller()($this->signed('ok', []));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok","pid":null}', $response->getContent());
    }

    public function testAnUnknownPaymentIsNotFound(): void
    {
        $this->verifier->method('verify')->willReturn(true);
        $this->resolver->expects(self::once())->method('resolve')->with('payment_unknown')->willReturn(null);
        $this->completion->expects(self::never())->method('complete');

        $response = $this->controller()($this->signed('ok', ['pid' => 'payment_unknown']));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAPaymentThatFailsTheFraudCheckIsFlaggedNotCompleted(): void
    {
        $resolved = $this->resolved();
        $this->verifier->method('verify')->willReturn(true);
        $this->resolver->method('resolve')->willReturn($resolved);
        $this->fraudChecker->method('check')->willReturn(AlmaFraudCheckResult::fail(AlmaFraudCheckResult::REASON_AMOUNT_MISMATCH));
        $this->fraudFlagger->expects(self::once())->method('flag')->with($resolved, self::isInstanceOf(AlmaFraudCheckResult::class));
        $this->completion->expects(self::never())->method('complete');

        $response = $this->controller()($this->signed('ok', ['pid' => 'payment_x']));

        self::assertSame(200, $response->getStatusCode(), 'Alma is answered OK either way: the notification is acknowledged, the order is not completed');
        self::assertSame('{"status":"ok","pid":"payment_x"}', $response->getContent());
    }

    public function testAVerifiedPaymentIsCompleted(): void
    {
        $resolved = $this->resolved();
        $this->verifier->method('verify')->willReturn(true);
        $this->resolver->method('resolve')->willReturn($resolved);
        $this->fraudChecker->method('check')->willReturn(AlmaFraudCheckResult::pass());
        $this->fraudFlagger->expects(self::never())->method('flag');
        $this->completion->expects(self::once())->method('complete')->with($resolved)->willReturn(true);

        $response = $this->controller()($this->signed('ok', ['pid' => 'payment_x']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok","pid":"payment_x"}', $response->getContent());
    }

    private function controller(): PaymentIpnController
    {
        return new PaymentIpnController($this->verifier, $this->resolver, $this->fraudChecker, $this->fraudFlagger, $this->completion);
    }

    /** @param array<string, string> $query */
    private function signed(string $signature, array $query): Request
    {
        $request = Request::create('/alma/ipn', 'GET', $query);
        $request->headers->set('X-Alma-Signature', $signature);

        return $request;
    }

    private function resolved(): ResolvedPayment
    {
        return new ResolvedPayment(
            $this->createMock(AlmaPayment::class),
            $this->createMock(OrderInterface::class),
            $this->createMock(PaymentInterface::class),
            'test',
        );
    }
}
