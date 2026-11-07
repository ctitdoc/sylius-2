<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Checkout;

use Alma\Client\Domain\Entity\Eligibility;
use Alma\Sylius\Api\FeePlanAdapter;
use Alma\Sylius\Checkout\FeePlanGrouper;
use Alma\Sylius\Checkout\PlanGroup;
use Alma\Sylius\Eligibility\AlmaEligibility;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Characterisation of the plan choice at checkout ([[payment-plan-choice]], [[instalment-schedule]]):
 * the eligible plans are grouped — pay now, instalments, deferred, credit — each with its button label
 * and its schedule rows; the ineligible ones are left out.
 *
 * @implementation [[payment-plan-choice]]
 */
final class FeePlanGrouperTest extends TestCase
{
    private const TODAY = '2026-11-06';

    public function testEligiblePlansAreGroupedAndIneligibleOnesLeftOut(): void
    {
        $views = (new FeePlanGrouper(new \DateTimeImmutable(self::TODAY)))->group([
            'general_3_0_0' => $this->eligibility(3, 0, 0, eligible: true, plan: [['due_date' => $this->ts(self::TODAY), 'total_amount' => 2286], ['due_date' => $this->ts('2026-12-06'), 'total_amount' => 2285], ['due_date' => $this->ts('2027-01-06'), 'total_amount' => 2285]]),
            'general_1_15_0' => $this->eligibility(1, 15, 0, eligible: true, plan: [['due_date' => $this->ts('2026-11-21'), 'purchase_amount' => 6856, 'customer_fee' => 0]]),
            'general_10_0_0' => $this->eligibility(10, 0, 0, eligible: true, plan: []),
            'general_4_0_0' => $this->eligibility(4, 0, 0, eligible: false, plan: []),
        ]);

        self::assertSame([PlanGroup::Installments, PlanGroup::Deferred, PlanGroup::Credit], array_map(fn ($v) => $v->group, $views), 'groups in their fixed order, empty ones skipped');
        self::assertCount(1, $views[0]->plans);
        $threeTimes = $views[0]->plans[0];
        self::assertSame('general_3_0_0', $threeTimes->planKey);
        self::assertSame('alma_sylius.checkout.button.installments', $threeTimes->buttonLabelKey);
        self::assertSame(['%count%' => 3], $threeTimes->buttonLabelParams);
        self::assertSame(6856, $threeTimes->total, 'the schedule total is the sum of the rows');
        self::assertTrue($threeTimes->scheduleRows[0]['isToday'], 'the first instalment is due today');
        self::assertFalse($threeTimes->scheduleRows[1]['isToday']);
        self::assertSame(2286, $threeTimes->scheduleRows[0]['amount']);

        $deferred = $views[1]->plans[0];
        self::assertSame('alma_sylius.checkout.button.deferred_days', $deferred->buttonLabelKey);
        self::assertSame(['%count%' => 15], $deferred->buttonLabelParams);
        self::assertSame(6856, $deferred->total, 'without total_amount, purchase amount plus fees');
    }

    public function testButtonLabelsNameInstalmentsAndDeferrals(): void
    {
        $grouper = new FeePlanGrouper(new \DateTimeImmutable(self::TODAY));
        $label = fn (int $n, int $d, int $m) => $grouper->group(['plan' => $this->eligibility($n, $d, $m, true, [])])[0]->plans[0];

        self::assertSame(['alma_sylius.checkout.button.installments_deferred_days', ['%installments%' => 3, '%days%' => 30]], [$label(3, 30, 0)->buttonLabelKey, $label(3, 30, 0)->buttonLabelParams]);
        self::assertSame(['alma_sylius.checkout.button.installments_deferred_months', ['%installments%' => 3, '%count%' => 1]], [$label(3, 0, 1)->buttonLabelKey, $label(3, 0, 1)->buttonLabelParams]);
        self::assertSame(['alma_sylius.checkout.button.deferred_months', ['%count%' => 2]], [$label(1, 0, 2)->buttonLabelKey, $label(1, 0, 2)->buttonLabelParams]);
        self::assertSame(['alma_sylius.checkout.button.installments', ['%count%' => 1]], [$label(1, 0, 0)->buttonLabelKey, $label(1, 0, 0)->buttonLabelParams]);
    }

    public function testARowValueThatIsNotANumberIsReadAsItAlwaysWasAndReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('alma.eligibility.unexpected_value', ['plan' => 'general_3_0_0', 'field' => 'total_amount', 'type' => 'array']);

        $views = (new FeePlanGrouper(new \DateTimeImmutable(self::TODAY), $logger))->group([
            'general_3_0_0' => $this->eligibility(3, 0, 0, true, [['due_date' => $this->ts(self::TODAY), 'total_amount' => [2286]]]),
        ]);

        self::assertSame(1, $views[0]->plans[0]->scheduleRows[0]['amount'], "PHP's conversion of a non-empty array, as before");
    }

    /** @param list<array<string, mixed>> $plan */
    private function eligibility(int $installments, int $days, int $months, bool $eligible, array $plan): AlmaEligibility
    {
        $data = ['eligible' => $eligible, 'installments_count' => $installments, 'deferred_days' => $days, 'deferred_months' => $months];
        $data += $eligible
            ? ['customer_fee' => 0, 'customer_total_cost_amount' => 0, 'customer_total_cost_bps' => 0, 'payment_plan' => $plan, 'annual_interest_rate' => 0]
            : ['constraints' => [], 'reasons' => ['purchase_amount' => 'too_low']];
        $key = sprintf('general_%d_%d_%d', $installments, $days, $months);

        return new AlmaEligibility($key, new FeePlanAdapter(['installments_count' => $installments, 'deferred_days' => $days, 'deferred_months' => $months]), new Eligibility($data));
    }

    private function ts(string $day): int
    {
        return (new \DateTimeImmutable($day . ' 12:00:00'))->getTimestamp();
    }
}
