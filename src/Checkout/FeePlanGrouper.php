<?php

declare(strict_types=1);

namespace Alma\Sylius\Checkout;

use Alma\Sylius\Checkout\View\FeePlanGroupView;
use Alma\Sylius\Checkout\View\FeePlanView;
use Alma\Sylius\Eligibility\AlmaEligibility;

/**
 * @implementation [[payment-plan-choice]] — the eligible plans grouped for the checkout, with their schedule
 */
final class FeePlanGrouper
{
    public function __construct(
        private readonly ?\DateTimeImmutable $today = null,
    ) {
    }

    /**
     * @param array<string, AlmaEligibility> $eligibilities
     *
     * @return list<FeePlanGroupView>
     */
    public function group(array $eligibilities): array
    {
        $buckets = [];
        foreach (PlanGroup::cases() as $group) {
            $buckets[$group->value] = [];
        }

        foreach ($eligibilities as $eligibility) {
            if (!$eligibility->isEligible()) {
                continue;
            }

            $buckets[PlanGroup::classify($eligibility)->value][] = $this->buildPlanView($eligibility);
        }

        $views = [];
        foreach (PlanGroup::cases() as $group) {
            if ([] === $buckets[$group->value]) {
                continue;
            }

            $views[] = new FeePlanGroupView($group, $buckets[$group->value]);
        }

        return $views;
    }

    private function buildPlanView(AlmaEligibility $eligibility): FeePlanView
    {
        [$labelKey, $labelParams] = $this->buttonLabel($eligibility);

        $todayDate = ($this->today ?? new \DateTimeImmutable('today'))->format('Y-m-d');
        $timezone = new \DateTimeZone(date_default_timezone_get());

        $rows = [];
        $total = 0;
        foreach ($eligibility->getInstallments() as $installment) {
            // Alma's total_amount for the instalment, checked by the fetcher against share plus fees plus interest.
            $amount = $installment->getTotalAmount();
            $dueDate = $installment->getDueDate();
            $isToday = (new \DateTimeImmutable('@' . $dueDate))->setTimezone($timezone)->format('Y-m-d') === $todayDate;
            $rows[] = ['isToday' => $isToday, 'dueDate' => $dueDate, 'amount' => $amount];
            $total += $amount;
        }

        return new FeePlanView(
            $eligibility->getPlanKey(),
            $labelKey,
            $labelParams,
            $rows,
            $total,
            $eligibility->getCustomerFee(),
        );
    }

    /**
     * @return array{string, array<string, int>}
     */
    private function buttonLabel(AlmaEligibility $eligibility): array
    {
        $installments = $eligibility->getInstallmentsCount();
        $days = $eligibility->getDeferredDays();
        $months = $eligibility->getDeferredMonths();

        if ($installments >= 2) {
            if ($days > 0) {
                return ['alma_sylius.checkout.button.installments_deferred_days', ['%installments%' => $installments, '%days%' => $days]];
            }

            if ($months > 0) {
                return ['alma_sylius.checkout.button.installments_deferred_months', ['%installments%' => $installments, '%count%' => $months]];
            }

            return ['alma_sylius.checkout.button.installments', ['%count%' => $installments]];
        }

        if ($days > 0) {
            return ['alma_sylius.checkout.button.deferred_days', ['%count%' => $days]];
        }

        if ($months > 0) {
            return ['alma_sylius.checkout.button.deferred_months', ['%count%' => $months]];
        }

        return ['alma_sylius.checkout.button.installments', ['%count%' => $installments]];
    }
}
