<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Support\BanglaDigits;

final class BanglaDigitsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function digitProvider(): array
    {
        return [
            'bengali phone'      => ['০১৭০০০০০০০০', '01700000000'],
            'bengali all digits' => ['০১২৩৪৫৬৭৮৯', '0123456789'],
            'devanagari'         => ['०१२३४५६७८९', '0123456789'],
            'arabic indic'       => ['٠١٢٣٤٥٦٧٨٩', '0123456789'],
            'persian'            => ['۰۱۲۳۴۵۶۷۸۹', '0123456789'],
            'mixed'              => ['+৮৮০ 17০০-০০০০০০', '+880 1700-000000'],
            'ascii untouched'    => ['01700000000', '01700000000'],
            'text preserved'     => ['বাড়ি ১২, রোড ৫', 'বাড়ি 12, রোড 5'],
            'empty'              => ['', ''],
        ];
    }

    #[DataProvider('digitProvider')]
    public function testToAscii(string $input, string $expected): void
    {
        self::assertSame($expected, BanglaDigits::toAscii($input));
    }

    public function testContainsLocalisedDigits(): void
    {
        self::assertTrue(BanglaDigits::containsLocalisedDigits('০১৭'));
        self::assertFalse(BanglaDigits::containsLocalisedDigits('017'));
        self::assertFalse(BanglaDigits::containsLocalisedDigits('ঢাকা'));
    }

    public function testToBanglaRoundTrip(): void
    {
        self::assertSame('০১৭০০০০০০০০', BanglaDigits::toBangla('01700000000'));
        self::assertSame('01700000000', BanglaDigits::toAscii(BanglaDigits::toBangla('01700000000')));
    }
}
