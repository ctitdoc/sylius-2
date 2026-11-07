<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Api;

use Alma\Sylius\Api\FeePlanAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the fee plan brick ([[payment-plan]], [[amount-within-plan-bounds]]): a plan as
 * the plugin sees it — Alma's data plus the Merchant's override — its key, its availability and its
 * eligibility for an amount. Written on the code as it is, before its static-analysis fixes.
 *
 * @implementation [[sylius-plugin-commons.md#fee-plans]]
 */
final class FeePlanAdapterTest extends TestCase
{
    /** @var array<string, mixed> Alma's data for a 3-instalment plan, 50 € to 2 000 € */
    private const ALMA_3X = [
        'kind' => 'general',
        'installments_count' => 3,
        'deferred_days' => 0,
        'deferred_months' => 0,
        'allowed' => true,
        'min_purchase_amount' => 5000,
        'max_purchase_amount' => 200000,
        'merchant_fee_variable' => 380,
        'customer_fee_variable' => 0,
    ];

    public function testThePlanKeyIsKindInstalmentsDaysMonths(): void
    {
        self::assertSame('general_3_0_0', (new FeePlanAdapter(self::ALMA_3X))->getPlanKey());
        self::assertSame('general_1_15_0', (new FeePlanAdapter(['installments_count' => 1, 'deferred_days' => 15]))->getPlanKey());
        self::assertSame('general_1_0_0', (new FeePlanAdapter([]))->getPlanKey(), 'defaults: general, 1 instalment, no deferral');
    }

    public function testEligibilityFollowsAlmaBoundsInclusive(): void
    {
        $plan = new FeePlanAdapter(self::ALMA_3X);

        self::assertTrue($plan->isEligible(5000));
        self::assertTrue($plan->isEligible(200000));
        self::assertFalse($plan->isEligible(4999));
        self::assertFalse($plan->isEligible(200001));
    }

    public function testTheMerchantOverrideNarrowsTheBounds(): void
    {
        $plan = new FeePlanAdapter(self::ALMA_3X, ['override_min_purchase_amount' => 10000, 'override_max_purchase_amount' => 100000]);

        self::assertSame(10000, $plan->getMinPurchaseAmount());
        self::assertSame(100000, $plan->getMaxPurchaseAmount());
        self::assertFalse($plan->isEligible(9999));
        self::assertTrue($plan->isEligible(10000));
        self::assertSame(5000, $plan->getApiMinPurchaseAmount(), "Alma's own bound stays readable");

        $plan->resetOverrideMinPurchaseAmount();
        $plan->resetOverrideMaxPurchaseAmount();
        self::assertSame(5000, $plan->getMinPurchaseAmount());
        self::assertSame(200000, $plan->getMaxPurchaseAmount());
    }

    public function testAvailableMeansAllowedByAlmaAndEnabledByTheMerchant(): void
    {
        $plan = new FeePlanAdapter(self::ALMA_3X);
        self::assertTrue($plan->isAllowed());
        self::assertFalse($plan->isEnabled(), 'a plan is disabled until the Merchant enables it');
        self::assertFalse($plan->isAvailable());

        $plan->enable();
        self::assertTrue($plan->isAvailable());
        self::assertSame(['enabled' => true], $plan->getOverrideData());

        $plan->disable();
        self::assertFalse($plan->isAvailable());

        $notAllowed = new FeePlanAdapter(['allowed' => false] + self::ALMA_3X, ['enabled' => true]);
        self::assertFalse($notAllowed->isAvailable(), 'enabled but not allowed by Alma: not available');
    }

    public function testAbsentFeesAreNullAndPresentOnesIntegers(): void
    {
        $plan = new FeePlanAdapter(['installments_count' => 4, 'merchant_fee_fixed' => 150, 'customer_fee_variable' => null]);

        self::assertSame(4, $plan->getInstallmentsCount());
        self::assertSame(150, $plan->getMerchantFeeFixed());
        self::assertNull($plan->getCustomerFeeVariable());
        self::assertNull($plan->getMerchantFeeVariable(), 'absent means null');
    }

    public function testTheLabelSaysInstalmentsOrDeferral(): void
    {
        self::assertSame('Paiement en 3×', (new FeePlanAdapter(self::ALMA_3X))->getLabel());
        self::assertSame('Paiement à J+15', (new FeePlanAdapter(['deferred_days' => 15]))->getLabel());
        self::assertSame('Paiement à M+1', (new FeePlanAdapter(['deferred_months' => 1]))->getLabel());
    }
}
