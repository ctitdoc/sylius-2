<?php

declare(strict_types=1);

namespace Alma\Sylius\Api;

/**
 * Reads a value of Alma's JSON the way the plugin always has — PHP's own conversions, made
 * explicit: a scalar converts as `(int)`, `(bool)` or `(string)` would; an array counts for 0 when
 * empty and 1 otherwise; an object counts for 1. The plugin cannot ascertain that Alma's API
 * never sends anything else than numbers where its reference says numbers (its code is not
 * readable), so the behaviour is kept as it was and the unexpected case is reported by the
 * caller — see the question to Alma's engineering team in the workspace README.
 *
 * @implementation [[sylius-plugin-commons.md#fee-plans]]
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

    public static function bool(mixed $value): bool
    {
        if (\is_scalar($value) || null === $value) {
            return (bool) $value;
        }
        if (\is_array($value)) {
            return [] !== $value;
        }

        return true;
    }

    public static function string(mixed $value): string
    {
        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return \is_array($value) ? 'Array' : 'Object';
    }

    /** True for what Alma's reference never announces where a number, a boolean or a string is expected. */
    public static function isUnexpected(mixed $value): bool
    {
        return !\is_scalar($value) && null !== $value;
    }

    private function __construct()
    {
    }
}
