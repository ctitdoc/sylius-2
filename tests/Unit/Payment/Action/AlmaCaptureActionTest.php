<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Payment\Action;

use Alma\Sylius\Api\PaymentEndpointFactoryInterface;
use Alma\Sylius\Payment\Action\AlmaCaptureAction;
use Alma\Sylius\Payment\AlmaPaymentDetailsKeys;
use Alma\Sylius\Payment\AlmaPaymentRequestBuilder;
use Alma\Sylius\Resolver\AlmaPaymentMethodResolver;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use PHPUnit\Framework\TestCase;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The plan field present on every Alma payment ([[payment-plan-choice]], [[alma-payment]]): a
 * capture without `alma_fee_plan_key` is refused before anything is sent to Alma; a capture of a
 * payment already created at Alma redirects again without creating a new one ([[payment-return]]).
 *
 * @implementation [[payment-creation]]
 */
final class AlmaCaptureActionTest extends TestCase
{
    public function testACaptureWithoutPlanKeyIsRefusedBeforeCallingAlma(): void
    {
        $endpoints = $this->createMock(PaymentEndpointFactoryInterface::class);
        $endpoints->expects(self::never())->method('make');
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('alma_fee_plan_key');

        $this->action($endpoints)->execute(new Capture($payment));
    }

    public function testACaptureOfAnAlreadyCreatedPaymentRedirectsAgainWithoutCallingAlma(): void
    {
        $endpoints = $this->createMock(PaymentEndpointFactoryInterface::class);
        $endpoints->expects(self::never())->method('make');
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturn([
            AlmaPaymentDetailsKeys::PAYMENT_ID => 'payment_already_created',
            AlmaPaymentDetailsKeys::PAYMENT_URL => 'https://checkout.sandbox.getalma.eu/payment_already_created',
            AlmaPaymentDetailsKeys::FEE_PLAN_KEY => 'general_3_0_0',
        ]);

        try {
            $this->action($endpoints)->execute(new Capture($payment));
            self::fail('a Payum HttpRedirect reply was expected');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://checkout.sandbox.getalma.eu/payment_already_created', $redirect->getUrl());
        }
    }

    public function testACaptureOfSomethingElseThanASyliusPaymentIsNotSupported(): void
    {
        $action = $this->action($this->createMock(PaymentEndpointFactoryInterface::class));

        self::assertFalse($action->supports(new Capture(new \stdClass())));
        self::assertTrue($action->supports(new Capture($this->createMock(PaymentInterface::class))));
    }

    private function action(PaymentEndpointFactoryInterface $endpoints): AlmaCaptureAction
    {
        return new AlmaCaptureAction(
            $this->createMock(AlmaPaymentMethodResolver::class),
            $this->createMock(AlmaPaymentRequestBuilder::class),
            $endpoints,
            $this->createMock(StateMachineInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            new RequestStack(),
            $this->createMock(EntityManagerInterface::class),
        );
    }
}
