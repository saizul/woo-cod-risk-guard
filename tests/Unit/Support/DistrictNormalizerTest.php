<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Support\DistrictNormalizer;

final class DistrictNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function nameProvider(): array
    {
        return [
            'lowercase'        => ['Dhaka', 'dhaka'],
            'trim'             => ['  Khulna  ', 'khulna'],
            'old spelling ctg' => ['Chittagong', 'chattogram'],
            'new spelling ctg' => ['Chattogram', 'chattogram'],
            'barisal'          => ['Barisal', 'barishal'],
            'comilla'          => ['Comilla', 'cumilla'],
            'jessore'          => ['JESSORE', 'jashore'],
            'bogra'            => ['Bogra', 'bogura'],
            'district suffix'  => ['Dhaka District', 'dhaka'],
            'zila suffix'      => ['Khulna Zila', 'khulna'],
            'bengali'          => ['ঢাকা', 'dhaka'],
            'bengali ctg'      => ['চট্টগ্রাম', 'chattogram'],
            'coxs bazar'       => ['Coxs Bazar', "cox's bazar"],
            'unknown kept'     => ['Somewhere Else', 'somewhere else'],
            'empty'            => ['', ''],
            'punctuation'      => ['Sylhet.', 'sylhet'],
        ];
    }

    #[DataProvider('nameProvider')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, DistrictNormalizer::normalize($input));
    }

    public function testParseRatesFromText(): void
    {
        $rates = DistrictNormalizer::parseRates("Dhaka=12\nKhulna: 35%\nChittagong | 22.5\n\nbroken line\nSylhet=abc");

        self::assertSame(['chattogram' => 22.5, 'dhaka' => 12.0, 'khulna' => 35.0], $rates);
    }

    public function testParseRatesFromArray(): void
    {
        $rates = DistrictNormalizer::parseRates(['Barisal' => '40', 'Dhaka' => 10, 'Bad' => 'x', '' => 5]);

        self::assertSame(['barishal' => 40.0, 'dhaka' => 10.0], $rates);
    }

    public function testRatesAreClamped(): void
    {
        $rates = DistrictNormalizer::parseRates(['Dhaka' => 250, 'Khulna' => -5]);

        self::assertSame(100.0, $rates['dhaka']);
        self::assertSame(0.0, $rates['khulna']);
    }

    public function testBanglaDigitsInRates(): void
    {
        self::assertSame(['dhaka' => 15.0], DistrictNormalizer::parseRates('ঢাকা=১৫'));
    }

    public function testLaterDuplicateWins(): void
    {
        self::assertSame(['chattogram' => 30.0], DistrictNormalizer::parseRates("Chittagong=20\nChattogram=30"));
    }

    public function testFormatRatesRoundTrips(): void
    {
        $rates = ['dhaka' => 12.0, 'khulna' => 35.5];

        self::assertSame("dhaka=12\nkhulna=35.5", DistrictNormalizer::formatRates($rates));
        self::assertSame($rates, DistrictNormalizer::parseRates(DistrictNormalizer::formatRates($rates)));
    }

    public function testEmptyInput(): void
    {
        self::assertSame([], DistrictNormalizer::parseRates(''));
        self::assertSame([], DistrictNormalizer::parseRates([]));
        self::assertSame('', DistrictNormalizer::formatRates([]));
    }
}
