<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Api;

use Alma\Sylius\Api\AlmaJsonValue;
use PHPUnit\Framework\TestCase;

/**
 * The conversion the plugin applies to the numbers of an eligibility schedule row is PHP's own,
 * kept as it was and made explicit; this test pins it, the unexpected cases included.
 *
 * @implementation [[payment-plan-choice]]
 */
final class AlmaJsonValueTest extends TestCase
{
    public function testNumbersAndNumericStringsReadAsPhpConvertsThem(): void
    {
        self::assertSame(3, AlmaJsonValue::int(3));
        self::assertSame(3, AlmaJsonValue::int('3'));
        self::assertSame(0, AlmaJsonValue::int('abc'));
        self::assertSame(1, AlmaJsonValue::int(true));
        self::assertSame(0, AlmaJsonValue::int(null));
        self::assertSame(5000, AlmaJsonValue::int(5000.9), 'a float truncates');
    }

    public function testWhatTheReferenceNeverAnnouncesConvertsAsItAlwaysDidAndIsFlagged(): void
    {
        self::assertSame(0, AlmaJsonValue::int([]), 'an empty array counted for 0');
        self::assertSame(1, AlmaJsonValue::int([5000]), 'a non-empty array counted for 1');
        self::assertSame(1, AlmaJsonValue::int(new \stdClass()), 'an object counted for 1');
        self::assertTrue(AlmaJsonValue::isUnexpected([5000]));
        self::assertFalse(AlmaJsonValue::isUnexpected('5000'));
        self::assertFalse(AlmaJsonValue::isUnexpected(null));
    }
}
