<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Integration\Alma;

use Alma\Client\Application\DTO\CustomerDto;
use Alma\Client\Application\DTO\EligibilityDto;
use Alma\Client\Application\DTO\EligibilityQueryDto;
use Alma\Client\Application\DTO\OrderDto;
use Alma\Client\Application\DTO\PaymentDto;
use Alma\Client\Domain\Entity\Eligibility;
use Alma\Client\Domain\Entity\FeePlan;
use Alma\Client\Domain\Entity\Installment;
use Alma\Sylius\Api\EligibilityEndpointFactoryInterface;
use Alma\Sylius\Api\MerchantEndpointFactoryInterface;
use Alma\Sylius\Api\PaymentEndpointFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The test bench stands: the Test Application, through the plugin's real endpoint factories and the
 * client's real transport, talks over HTTP to the Alma mock server (tests/MockAlma, ALMA_API_URL) —
 * merchant, plans, eligibility with its forecast schedule, a payment created, paid without a browser
 * through the mock's control route, read back captured ([[alma]], the partner application).
 */
final class MockAlmaTest extends KernelTestCase
{
    private const KEY = 'sk_test_mock_0001';

    public function testTheTestApplicationTalksToTheAlmaMockThroughTheRealClient(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $mock = rtrim((string) ($_SERVER['ALMA_API_URL'] ?? $_ENV['ALMA_API_URL'] ?? ''), '/');
        self::assertNotSame('', $mock, 'ALMA_API_URL names the mock server (compose: alma-mock, CI: 127.0.0.1:8000)');
        $this->control($mock, 'POST', '/_test/reset');

        $merchantFactory = $container->get('test.alma.merchant_endpoint_factory');
        self::assertInstanceOf(MerchantEndpointFactoryInterface::class, $merchantFactory);
        $merchant = $merchantFactory->make(self::KEY, 'test')->me();
        self::assertSame('merchant_demo_0001', $merchant->getId());
        self::assertTrue($merchant->canCreatePayments());

        $keys = [];
        foreach ($merchantFactory->make(self::KEY, 'test')->getFeePlanList('general', 'all', true) as $plan) {
            self::assertInstanceOf(FeePlan::class, $plan);
            $keys[] = sprintf('%s_%d_%d_%d', $plan->getKind(), $plan->getInstallmentsCount(), $plan->getDeferredDays(), $plan->getDeferredMonths());
        }
        self::assertContains('general_3_0_0', $keys);
        self::assertContains('general_1_15_0', $keys);

        $eligibilityFactory = $container->get('test.alma.eligibility_endpoint_factory');
        self::assertInstanceOf(EligibilityEndpointFactoryInterface::class, $eligibilityFactory);
        $dto = (new EligibilityDto(6856))->addQuery(new EligibilityQueryDto(3))->addQuery(new EligibilityQueryDto(10));
        $byPlan = [];
        foreach ($eligibilityFactory->make(self::KEY, 'test')->getEligibilityList($dto) as $eligibility) {
            self::assertInstanceOf(Eligibility::class, $eligibility);
            $byPlan[$eligibility->getInstallmentsCount()] = $eligibility;
        }
        self::assertTrue($byPlan[3]->isEligible(), '68,56 € is eligible to 3x');
        $forecast = $byPlan[3]->getInstallments();
        self::assertSame(3, $forecast->getInstallmentCount());
        self::assertInstanceOf(Installment::class, $forecast[0]);
        self::assertSame(Installment::STATE_FORECAST, $forecast[0]->getState(), 'the mock sends no state on an eligibility row, as Alma');
        self::assertSame(2286, $forecast[0]->getTotalAmount());
        self::assertFalse($byPlan[10]->isEligible(), 'below the 500 € minimum of the 10x plan');

        $paymentFactory = $container->get('test.alma.payment_endpoint_factory');
        self::assertInstanceOf(PaymentEndpointFactoryInterface::class, $paymentFactory);
        $endpoint = $paymentFactory->make(self::KEY, 'test');
        $payment = $endpoint->create(
            (new PaymentDto(6856))->setInstallmentsCount(3)->setReturnUrl('https://shop.test/alma/return')->setCustomData(['order_id' => '000000042']),
            new OrderDto(),
            new CustomerDto(),
        );
        self::assertStringStartsWith($mock . '/pay/', $payment->getUrl(), 'the Buyer is sent to the hosted page of the mock');
        self::assertSame('awaiting_authorization', $payment->getProcessingStatus());

        $this->control($mock, 'POST', '/_test/payments/' . $payment->getId() . '/pay');
        $paid = $endpoint->fetch($payment->getId());
        self::assertSame('captured', $paid->getProcessingStatus());
        $first = $paid->getPaymentPlan()[0];
        self::assertInstanceOf(Installment::class, $first);
        self::assertSame(Installment::STATE_PAID, $first->getState(), "a payment's first instalment is paid");
    }

    /** The mock's control routes, outside the client: what a scenario does in Alma's place. */
    private function control(string $mock, string $method, string $path): void
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents($mock . $path, false, $context);
        self::assertNotFalse($body, sprintf('the mock answers %s %s', $method, $path));
    }
}
