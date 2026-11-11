<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Support\Str;

final class StrTest extends TestCase
{
    public function testLowerHandlesMultibyte(): void
    {
        self::assertSame('dhaka', Str::lower('DHAKA'));
        self::assertSame('ঢাকা', Str::lower('ঢাকা'));
    }

    public function testLengthCountsCharactersNotBytes(): void
    {
        self::assertSame(5, Str::length('Dhaka'));
        self::assertSame(4, Str::length('ঢাকা'));
        self::assertSame(0, Str::length(''));
    }

    public function testSquish(): void
    {
        self::assertSame('House 12 Road 5', Str::squish("  House   12\n\tRoad 5 "));
    }

    public function testWordsSplitsOnPunctuationInAnyScript(): void
    {
        self::assertSame(['House', '12', 'Road', '5'], Str::words('House 12, Road-5'));
        self::assertSame(['বাড়ি', '১২', 'রোড'], Str::words('বাড়ি ১২, রোড'));
        self::assertSame([], Str::words(' ,, '));
    }

    public function testLettersOnly(): void
    {
        self::assertSame('HouseRoad', Str::lettersOnly('House 12, Road 5'));
        self::assertSame('', Str::lettersOnly('123 456'));
    }

    public function testHasBengali(): void
    {
        self::assertTrue(Str::hasBengali('Dhaka ঢাকা'));
        self::assertFalse(Str::hasBengali('Dhaka'));
    }

    public function testLines(): void
    {
        self::assertSame(['a', 'b', 'c'], Str::lines("a\n b ,c\r\n\na"));
        self::assertSame([], Str::lines(''));
    }
}
