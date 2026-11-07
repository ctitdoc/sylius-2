<?php

declare(strict_types=1);

namespace Tests\Alma\Sylius\Unit\Api;

use Alma\Sylius\Api\AlmaJsonValue;
use PHPUnit\Framework\TestCase;

/**
 * The conversions the plugin applies to Alma's JSON values are PHP's own, kept as they were and
 * made explicit; this test pins them, the unexpected cases included (question 1 to Alma's
 * engineering team, workspace README).
 *
 * @implementation [[sylius-plugin-commons.md#fee-plans]]
 */
final class AlmaJsonValueTest extends TestCase
{
    public function testNumbersAndNumericStringsReadAsPhpConvertsThem(): void
    {
        self::assertSame(3, AlmaJsonValue::int(3));
        self::assertSame(3, AlmaJsonValue::int('3'));
        self::assertSame(12, AlmaJsonValue::int('12abc'), 'a numeric prefix, as PHP reads it');
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
        self::assertTrue(AlmaJsonValue::isUnexpected(new \stdClass()));
        self::assertFalse(AlmaJsonValue::isUnexpected('5000'));
        self::assertFalse(AlmaJsonValue::isUnexpected(null));
    }

    public function testBooleansAndStrings(): void
    {
        self::assertTrue(AlmaJsonValue::bool(true));
        self::assertTrue(AlmaJsonValue::bool('1'));
        self::assertFalse(AlmaJsonValue::bool('0'));
        self::assertFalse(AlmaJsonValue::bool(null));
        self::assertTrue(AlmaJsonValue::bool(['yes']), 'a non-empty array was true');
        self::assertFalse(AlmaJsonValue::bool([]));
        self::assertSame('general', AlmaJsonValue::string('general'));
        self::assertSame('3', AlmaJsonValue::string(3));
        self::assertSame('Array', AlmaJsonValue::string(['x']), "as PHP's conversion of an array");
    }
}
