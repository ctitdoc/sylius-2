<?php

declare(strict_types=1);

namespace Alma\Sylius\Checkout;

use Alma\Sylius\Api\AlmaJsonValue;
use Alma\Sylius\Checkout\View\FeePlanGroupView;
use Alma\Sylius\Checkout\View\FeePlanView;
use Alma\Sylius\Eligibility\AlmaEligibility;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @implementation [[payment-plan-choice]] — the eligible plans grouped for the checkout, with their schedule
 */
final class FeePlanGrouper
{
    public function __construct(
        private readonly ?\DateTimeImmutable $today = null,
        private readonly LoggerInterface $logger = new NullLogger(),
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
        foreach ($eligibility->getPaymentPlan() as $installment) {
            $planKey = $eligibility->getPlanKey();
            $amount = isset($installment['total_amount'])
                ? $this->int($installment['total_amount'], 'total_amount', $planKey)
                : $this->int($installment['purchase_amount'] ?? 0, 'purchase_amount', $planKey)
                    + $this->int($installment['customer_fee'] ?? 0, 'customer_fee', $planKey)
                    + $this->int($installment['customer_interest'] ?? 0, 'customer_interest', $planKey);
            $dueDate = isset($installment['due_date']) ? $this->int($installment['due_date'], 'due_date', $planKey) : null;
            $isToday = null !== $dueDate &&
                (new \DateTimeImmutable('@' . $dueDate))->setTimezone($timezone)->format('Y-m-d') === $todayDate;

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
    /**
     * A number of a schedule row, as Alma's answer gives it. The reference announces integers; the
     * plugin cannot ascertain it (question to Alma's engineering team, workspace README), so it
     * converts as PHP always did and reports what the reference never announced.
     */
    private function int(mixed $value, string $field, string $planKey): int
    {
        if (AlmaJsonValue::isUnexpected($value)) {
            $this->logger->warning('alma.eligibility.unexpected_value', [
                'plan' => $planKey,
                'field' => $field,
                'type' => get_debug_type($value),
            ]);
        }

        return AlmaJsonValue::int($value);
    }

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
