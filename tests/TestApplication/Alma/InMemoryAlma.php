<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\TestApplication\Alma;

/**
 * The one double of Alma's API for the Test Application: what Alma's backend answers, kept in
 * memory, in the JSON shapes the client's entities require (Merchant, FeePlan, Eligibility,
 * Payment) — the same data as the workspace's `mock-alma` simulator, so that a scenario behaves
 * the same here and on the demo shop. Amounts are integers in cents. Scenarios drive it through
 * the public methods below (a payment paid, a reset).
 */
final class InMemoryAlma
{
    public const MERCHANT_ID = 'merchant_demo_0001';

    public const HOSTED_PAGE = 'https://alma.test/pay/';

    /** @var list<array{installments_count: int, deferred_days: int, deferred_months: int, allowed: bool, min: int, max: int, cfee: int, mfee: int}> */
    private const FEE_PLANS = [
        ['installments_count' => 1, 'deferred_days' => 0, 'deferred_months' => 0, 'allowed' => false, 'min' => 5000, 'max' => 200000, 'cfee' => 0, 'mfee' => 150],
        ['installments_count' => 2, 'deferred_days' => 0, 'deferred_months' => 0, 'allowed' => true, 'min' => 5000, 'max' => 200000, 'cfee' => 0, 'mfee' => 380],
        ['installments_count' => 3, 'deferred_days' => 0, 'deferred_months' => 0, 'allowed' => true, 'min' => 5000, 'max' => 200000, 'cfee' => 0, 'mfee' => 380],
        ['installments_count' => 4, 'deferred_days' => 0, 'deferred_months' => 0, 'allowed' => true, 'min' => 5000, 'max' => 200000, 'cfee' => 0, 'mfee' => 420],
        ['installments_count' => 1, 'deferred_days' => 15, 'deferred_months' => 0, 'allowed' => true, 'min' => 5000, 'max' => 100000, 'cfee' => 0, 'mfee' => 250],
        ['installments_count' => 1, 'deferred_days' => 30, 'deferred_months' => 0, 'allowed' => true, 'min' => 5000, 'max' => 100000, 'cfee' => 0, 'mfee' => 250],
        ['installments_count' => 10, 'deferred_days' => 0, 'deferred_months' => 0, 'allowed' => true, 'min' => 50000, 'max' => 400000, 'cfee' => 550, 'mfee' => 0],
        ['installments_count' => 12, 'deferred_days' => 0, 'deferred_months' => 0, 'allowed' => true, 'min' => 50000, 'max' => 400000, 'cfee' => 650, 'mfee' => 0],
    ];

    /** @var array<string, array<string, mixed>> payment id → payment JSON */
    private array $payments = [];

    /** @var list<array<string, mixed>> what the plugin flagged as potential fraud */
    private array $fraudFlags = [];

    private int $now;

    public function __construct(?int $now = null)
    {
        $this->now = $now ?? time();
    }

    public function reset(): void
    {
        $this->payments = [];
        $this->fraudFlags = [];
    }

    /** @return array<string, mixed> */
    public function merchant(): array
    {
        return ['id' => self::MERCHANT_ID, 'name' => 'Boutique de démonstration', 'can_create_payments' => true];
    }

    /** @return list<array<string, mixed>> */
    public function feePlans(string $only = 'all', bool $deferred = false): array
    {
        $wanted = 'all' === $only ? null : array_map('intval', array_filter(explode(',', $only), 'is_numeric'));
        $out = [];
        foreach (self::FEE_PLANS as $plan) {
            if (!$deferred && self::isDeferred($plan)) {
                continue;
            }
            if (null !== $wanted && !\in_array($plan['installments_count'], $wanted, true)) {
                continue;
            }
            $out[] = $this->feePlanJson($plan);
        }

        return $out;
    }

    /** @param array{deferred_days: int, deferred_months: int} $plan */
    private static function isDeferred(array $plan): bool
    {
        return 0 !== $plan['deferred_days'] || 0 !== $plan['deferred_months'];
    }

    /**
     * @param list<array<string, mixed>> $queries
     *
     * @return list<array<string, mixed>>
     */
    public function eligibility(int $amount, array $queries): array
    {
        $out = [];
        foreach ($queries as $query) {
            $n = $this->int($query['installments_count'] ?? 1);
            $d = $this->int($query['deferred_days'] ?? 0);
            $m = $this->int($query['deferred_months'] ?? 0);
            $plan = $this->planFor($n, $d, $m);
            $common = ['installments_count' => $n, 'deferred_days' => $d, 'deferred_months' => $m];
            if (null === $plan || !$plan['allowed'] || $amount < $plan['min'] || $amount > $plan['max']) {
                $out[] = ['eligible' => false] + $common + [
                    'constraints' => ['purchase_amount' => ['minimum' => $plan['min'] ?? 0, 'maximum' => $plan['max'] ?? 0]],
                    'reasons' => ['purchase_amount' => 'invalid_value'],
                ];

                continue;
            }
            [$schedule, $customerFee] = $this->schedule($amount, $plan);
            $out[] = ['eligible' => true] + $common + [
                'customer_fee' => $customerFee,
                'customer_total_cost_amount' => $customerFee,
                'customer_total_cost_bps' => $plan['cfee'],
                'annual_interest_rate' => 0,
                'payment_plan' => $schedule,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body the `payment`, `order` and `customer` of the client's create call
     *
     * @return array<string, mixed>|null null when the plan or the amount is not eligible
     */
    public function createPayment(array $body): ?array
    {
        $pay = \is_array($body['payment'] ?? null) ? $body['payment'] : [];
        $order = \is_array($body['order'] ?? null) ? $body['order'] : [];
        $amount = $this->int($pay['purchase_amount'] ?? 0);
        $n = $this->int($pay['installments_count'] ?? 1);
        $d = $this->int($pay['deferred_days'] ?? 0);
        $m = $this->int($pay['deferred_months'] ?? 0);
        $plan = $this->planFor($n, $d, $m);
        if (null === $plan || !$plan['allowed'] || $amount < $plan['min'] || $amount > $plan['max']) {
            return null;
        }
        $id = 'payment_' . substr(bin2hex(random_bytes(10)), 0, 20);
        [$schedule, $customerFee] = $this->schedule($amount, $plan);
        $payment = [
            'id' => $id, 'state' => 'in_progress', 'processing_status' => 'awaiting_authorization',
            'url' => self::HOSTED_PAGE . $id, 'purchase_amount' => $amount, 'installments_count' => $n,
            'deferred_days' => $d, 'deferred_months' => $m, 'kind' => $this->kindOf($plan),
            'origin' => $pay['origin'] ?? 'online',
            'custom_data' => \is_array($pay['custom_data'] ?? null) ? $pay['custom_data'] : [],
            'customer_fee' => $customerFee, 'customer_interest' => 0, 'amount_already_refunded' => 0,
            'expired_at' => $this->now + 3600, 'created' => $this->now,
            'orders' => [[
                'id' => 'order_' . substr(bin2hex(random_bytes(6)), 0, 12),
                'merchant_reference' => $order['merchant_reference'] ?? null,
                'merchant_url' => $order['merchant_url'] ?? null,
                'customer_url' => $order['customer_url'] ?? null,
                'data' => \is_array($order['data'] ?? null) ? $order['data'] : [],
                'created' => $this->now,
            ]],
            'payment_plan' => $schedule,
            'return_url' => $pay['return_url'] ?? null,
            'customer_cancel_url' => $pay['customer_cancel_url'] ?? null,
            'ipn_callback_url' => $pay['ipn_callback_url'] ?? null,
            'locale' => $pay['locale'] ?? 'fr_FR',
            'customer' => \is_array($body['customer'] ?? null) ? $body['customer'] : [],
            'billing_address' => $pay['billing_address'] ?? null,
            'shipping_address' => $pay['shipping_address'] ?? null,
            'cart' => $pay['cart'] ?? null,
            'refunds' => [], 'ipn' => [],
        ];
        $this->payments[$id] = $payment;

        return $payment;
    }

    /** @return array<string, mixed>|null */
    public function payment(string $id): ?array
    {
        return $this->payments[$id] ?? null;
    }

    /** What the Buyer does on the hosted page: the first instalment is paid, Alma captures. */
    public function pay(string $id): void
    {
        if (!isset($this->payments[$id])) {
            throw new \LogicException(sprintf('No payment "%s" at Alma.', $id));
        }
        $this->payments[$id]['state'] = 'in_progress';
        $this->payments[$id]['processing_status'] = 'captured';
        /** @var list<array<string, mixed>> $plan */
        $plan = $this->payments[$id]['payment_plan'];
        $plan[0]['state'] = 'paid';
        $this->payments[$id]['payment_plan'] = $plan;
    }

    public function cancel(string $id): bool
    {
        if (!isset($this->payments[$id])) {
            return false;
        }
        $this->payments[$id]['processing_status'] = 'canceled';
        $this->payments[$id]['cancelation_reason'] = 'requested_by_merchant';

        return true;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|null null when unknown or not refundable
     */
    public function refund(string $id, array $body): ?array
    {
        if (!isset($this->payments[$id])) {
            return null;
        }
        $payment = $this->payments[$id];
        $purchase = $this->int($payment['purchase_amount']);
        $refunded = $this->int($payment['amount_already_refunded']);
        $amount = isset($body['amount']) ? $this->int($body['amount']) : $purchase - $refunded;
        if ($amount <= 0 || $refunded + $amount > $purchase) {
            return null;
        }
        $payment['amount_already_refunded'] = $refunded + $amount;
        /** @var list<array<string, mixed>> $refunds */
        $refunds = $payment['refunds'];
        $refunds[] = ['id' => 'refund_' . substr(bin2hex(random_bytes(6)), 0, 12), 'amount' => $amount, 'merchant_reference' => $body['merchant_reference'] ?? null, 'comment' => $body['comment'] ?? null, 'created' => $this->now];
        $payment['refunds'] = $refunds;
        $this->payments[$id] = $payment;

        return $payment;
    }

    /** @param array<string, mixed> $body */
    public function flagPotentialFraud(string $id, array $body): bool
    {
        if (!isset($this->payments[$id])) {
            return false;
        }
        $this->fraudFlags[] = ['id' => $id] + $body;

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function fraudFlags(): array
    {
        return $this->fraudFlags;
    }

    /** @param array{installments_count: int, deferred_days: int, deferred_months: int, allowed: bool, min: int, max: int, cfee: int, mfee: int} $plan */
    private function feePlanJson(array $plan): array
    {
        return [
            'allowed' => $plan['allowed'], 'available_online' => $plan['allowed'], 'kind' => 'general',
            'installments_count' => $plan['installments_count'], 'deferred_days' => $plan['deferred_days'],
            'deferred_months' => $plan['deferred_months'], 'min_purchase_amount' => $plan['min'],
            'max_purchase_amount' => $plan['max'], 'customer_fee_variable' => $plan['cfee'],
            'customer_fee_fixed' => 0, 'merchant_fee_variable' => $plan['mfee'], 'merchant_fee_fixed' => 0,
            'customer_lending_rate' => 0,
        ];
    }

    /** @return array{installments_count: int, deferred_days: int, deferred_months: int, allowed: bool, min: int, max: int, cfee: int, mfee: int}|null */
    private function planFor(int $n, int $d, int $m): ?array
    {
        foreach (self::FEE_PLANS as $plan) {
            if ([$plan['installments_count'], $plan['deferred_days'], $plan['deferred_months']] === [$n, $d, $m]) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * The schedule: each instalment's share, the customer fee on the first one, all pending.
     *
     * @param array{installments_count: int, deferred_days: int, deferred_months: int, allowed: bool, min: int, max: int, cfee: int, mfee: int} $plan
     *
     * @return array{0: list<array<string, int|string>>, 1: int}
     */
    private function schedule(int $amount, array $plan): array
    {
        $n = $plan['installments_count'];
        $base = intdiv($amount, $n);
        $rest = $amount % $n;
        $customerFee = intdiv($amount * $plan['cfee'], 10000);
        $firstDue = $this->now + $plan['deferred_days'] * 86400 + $plan['deferred_months'] * 30 * 86400;
        $rows = [];
        for ($i = 0; $i < $n; ++$i) {
            $part = $base + ($i < $rest ? 1 : 0);
            $fee = 0 === $i ? $customerFee : 0;
            $rows[] = [
                'due_date' => $firstDue + $i * 30 * 86400, 'purchase_amount' => $part, 'customer_fee' => $fee,
                'customer_interest' => 0, 'total_amount' => $part + $fee, 'state' => 'pending',
            ];
        }

        return [$rows, $customerFee];
    }

    /** @param array{installments_count: int, deferred_days: int, deferred_months: int, allowed: bool, min: int, max: int, cfee: int, mfee: int} $plan */
    private function kindOf(array $plan): string
    {
        $kind = sprintf('P%dX', $plan['installments_count']);
        if ($plan['deferred_days'] > 0) {
            $kind .= sprintf('_D+%d', $plan['deferred_days']);
        }
        if ($plan['deferred_months'] > 0) {
            $kind .= sprintf('_M+%d', $plan['deferred_months']);
        }

        return $kind;
    }

    private function int(mixed $value): int
    {
        return \is_scalar($value) ? (int) $value : 0;
    }
}
