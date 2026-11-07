<?php

declare(strict_types=1);

namespace Alma\Sylius\Api;

/**
 * Reads a number of an eligibility schedule row the way the plugin always has — PHP's own `(int)`
 * conversion, made explicit: a scalar converts as `(int)` would; an array counts for 0 when empty
 * and 1 otherwise; an object counts for 1. The rows are the one place where the plugin reads
 * numbers of Alma's answer that nothing has typed (the client types the rows of a Payment, not of
 * an Eligibility — see phpstan/stubs/AlmaClientEligibility.stub); the behaviour is kept as it was
 * and the unexpected case is reported by the caller. Question to Alma's team in the workspace README.
 *
 * @implementation [[payment-plan-choice]]
 */
final class AlmaJsonValue
{
    public static function int(mixed $value): int
    {
        if (\is_scalar($value) || null === $value) {
            return (int) $value;
        }
        if (\is_array($value)) {
            return [] === $value ? 0 : 1;
        }

        return 1;
    }

    /** True for what the reference never announces where a number is expected. */
    public static function isUnexpected(mixed $value): bool
    {
        return !\is_scalar($value) && null !== $value;
    }

    private function __construct()
    {
    }
}
