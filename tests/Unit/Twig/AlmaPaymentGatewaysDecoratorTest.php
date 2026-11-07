<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Twig;

use Alma\Sylius\Twig\AlmaPaymentGatewaysDecorator;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\AdminBundle\Twig\PaymentMethodExtension;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;

/**
 * Characterisation of the admin's gateway choice ([[one-alma-configuration-per-shop]],
 * [[merchant-configuration-form]]): once an Alma payment method exists, the Alma gateway leaves the
 * list the admin offers for a new payment method.
 *
 * @implementation [[one-alma-configuration-per-shop]]
 */
final class AlmaPaymentGatewaysDecoratorTest extends TestCase
{
    public function testAlmaLeavesTheGatewayListOnceAMethodExists(): void
    {
        // Sylius's extension is final: the real one, fed with the admin's gateway list.
        $inner = new PaymentMethodExtension(['offline' => 'Offline', 'alma' => 'Alma']);

        self::assertSame(['offline' => 'Offline', 'alma' => 'Alma'], (new AlmaPaymentGatewaysDecorator($inner, $this->repositoryWith()))->getPaymentGateways());
        self::assertSame(['offline' => 'Offline'], (new AlmaPaymentGatewaysDecorator($inner, $this->repositoryWith('alma')))->getPaymentGateways());
        self::assertSame(['offline' => 'Offline', 'alma' => 'Alma'], (new AlmaPaymentGatewaysDecorator($inner, $this->repositoryWith('offline')))->getPaymentGateways());
    }

    /** @return PaymentMethodRepositoryInterface<PaymentMethodInterface> */
    private function repositoryWith(string ...$factories): PaymentMethodRepositoryInterface
    {
        $methods = [];
        foreach ($factories as $factory) {
            $gatewayConfig = $this->createMock(GatewayConfigInterface::class);
            $gatewayConfig->method('getFactoryName')->willReturn($factory);
            $method = $this->createMock(PaymentMethodInterface::class);
            $method->method('getGatewayConfig')->willReturn($gatewayConfig);
            $methods[] = $method;
        }
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->method('findAll')->willReturn($methods);

        return $repository;
    }
}
