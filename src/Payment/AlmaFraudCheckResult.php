<?php

declare(strict_types=1);

namespace Alma\Sylius\Payment;

/**
 * Outcome of the anti-fraud checks performed by {@see AlmaFraudChecker}
 * on a {@see ResolvedPayment} coming back from Alma. Used by the return
 * route and (later) by the IPN handler to decide whether to proceed with
 * a state transition or trigger the fraud-flagging path
 * (cf. {@see AlmaFraudFlagger}).
 */
final class AlmaFraudCheckResult
{
    public const REASON_PID_MISMATCH = 'pid_mismatch';

    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';

    public const REASON_STATE_ERROR = 'state_error';

    public const REASON_EXPIRED = 'expired';

    private function __construct(
        public readonly bool $passed,
        public readonly ?string $reason,
    ) {
    }

    public static function pass(): self
    {
        return new self(true, null);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
