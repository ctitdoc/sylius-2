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
use Alma\Sylius\Api\EligibilityEndpointFactoryInterface;
use Alma\Sylius\Api\MerchantEndpointFactoryInterface;
use Alma\Sylius\Api\PaymentEndpointFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Alma\Sylius\TestApplication\Alma\InMemoryAlma;
use Tests\Alma\Sylius\TestApplication\Alma\InMemoryMerchantEndpointFactory;

/**
 * The Alma double stands in the Test Application: the plugin's endpoint factories are the in-memory
 * ones, and the client's real endpoints and entities run over it — merchant, plans, eligibility,
 * payment created, paid and read back ([[alma]], the partner application).
 */
final class InMemoryAlmaTest extends KernelTestCase
{
    public function testTheTestApplicationTalksToTheInMemoryAlmaThroughTheRealClient(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $merchantFactory = $container->get(MerchantEndpointFactoryInterface::class);
        self::assertInstanceOf(InMemoryMerchantEndpointFactory::class, $merchantFactory, 'the double replaces the plugin factory in test');
        $merchant = $merchantFactory->make('sk_test_double_0001', 'test')->me();
        self::assertSame(InMemoryAlma::MERCHANT_ID, $merchant->getId());
        self::assertTrue($merchant->canCreatePayments());

        $plans = $merchantFactory->make('sk_test_double_0001', 'test')->getFeePlanList('general', 'all', true);
        $keys = [];
        foreach ($plans as $plan) {
            self::assertInstanceOf(FeePlan::class, $plan);
            $keys[] = sprintf('%s_%d_%d_%d', $plan->getKind(), $plan->getInstallmentsCount(), $plan->getDeferredDays(), $plan->getDeferredMonths());
        }
        self::assertContains('general_3_0_0', $keys);
        self::assertContains('general_1_15_0', $keys);

        $eligibilityFactory = $container->get(EligibilityEndpointFactoryInterface::class);
        self::assertInstanceOf(EligibilityEndpointFactoryInterface::class, $eligibilityFactory);
        $dto = (new EligibilityDto(6856))->addQuery(new EligibilityQueryDto(3))->addQuery(new EligibilityQueryDto(10));
        $list = $eligibilityFactory->make('sk_test_double_0001', 'test')->getEligibilityList($dto);
        $byPlan = [];
        foreach ($list as $eligibility) {
            self::assertInstanceOf(Eligibility::class, $eligibility);
            $byPlan[$eligibility->getInstallmentsCount()] = $eligibility;
        }
        self::assertTrue($byPlan[3]->isEligible(), '68,56 € is eligible to 3x');
        self::assertCount(3, $byPlan[3]->getPaymentPlan());
        self::assertSame(2286, $byPlan[3]->getPaymentPlan()[0]['purchase_amount'] ?? null);
        self::assertFalse($byPlan[10]->isEligible(), 'below the 500 € minimum of the 10x plan');

        $paymentFactory = $container->get(PaymentEndpointFactoryInterface::class);
        self::assertInstanceOf(PaymentEndpointFactoryInterface::class, $paymentFactory);
        $endpoint = $paymentFactory->make('sk_test_double_0001', 'test');
        $payment = $endpoint->create(
            (new PaymentDto(6856))->setInstallmentsCount(3)->setReturnUrl('https://shop.test/alma/return')->setCustomData(['order_id' => '000000042']),
            new OrderDto(),
            new CustomerDto(),
        );
        self::assertStringStartsWith(InMemoryAlma::HOSTED_PAGE, $payment->getUrl(), 'the Buyer is sent to the hosted page');
        self::assertSame('awaiting_authorization', $payment->getProcessingStatus());

        $alma = $container->get(InMemoryAlma::class);
        self::assertInstanceOf(InMemoryAlma::class, $alma);
        $alma->pay($payment->getId());
        $read = $endpoint->fetch($payment->getId());
        self::assertSame('captured', $read->getProcessingStatus(), 'read back as Alma reports it after the first instalment');
        self::assertSame(['order_id' => '000000042'], $read->getCustomData());
    }
}
