<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Support\AddressAnalysis;
use Saizul\CodRiskGuard\Support\AddressAnalyzer;

final class AddressAnalyzerTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function cleanAddresses(): array
    {
        return [
            'house and road'     => ['House 12, Road 5, Block C, Banani', '', 'Dhaka'],
            'two lines'          => ['Flat 4B, Green Tower', 'Mirpur 10', 'Dhaka'],
            'village style'      => ['Vill: Char Kalibari, Post: Sadar', '', 'Mymensingh'],
            'bengali'            => ['বাড়ি ১২, রোড ৫, বনানী', '', 'ঢাকা'],
            'bengali village'    => ['গ্রাম: চর কালিবাড়ি, ডাকঘর: সদর', '', 'ময়মনসিংহ'],
            'landmark'           => ['Beside Central Mosque, College Road', '', 'Bogura'],
            'suffix locality'    => ['Shantinagar Bazar', '', 'Dhaka'],
            'four plain words'   => ['Near big banyan tree corner', '', 'Sylhet'],
        ];
    }

    #[DataProvider('cleanAddresses')]
    public function testCleanAddressesPass(string $address1, string $address2, string $city): void
    {
        $analysis = (new AddressAnalyzer())->analyze($address1, $address2, $city);

        self::assertTrue($analysis->isClean(), 'Unexpected issues: ' . implode(',', $analysis->issues));
        self::assertSame(0.0, $analysis->severity);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function badAddresses(): array
    {
        return [
            'empty'             => ['', 'Dhaka', AddressAnalysis::ISSUE_EMPTY],
            'whitespace'        => ['   ', 'Dhaka', AddressAnalysis::ISSUE_EMPTY],
            'too short'         => ['Mirpur', 'Dhaka', AddressAnalysis::ISSUE_TOO_SHORT],
            'placeholder test'  => ['test', 'Dhaka', AddressAnalysis::ISSUE_PLACEHOLDER],
            'placeholder n/a'   => ['N/A', 'Dhaka', AddressAnalysis::ISSUE_PLACEHOLDER],
            'placeholder basa'  => ['Basa', 'Dhaka', AddressAnalysis::ISSUE_PLACEHOLDER],
            'placeholder bn'    => ['বাসা', 'ঢাকা', AddressAnalysis::ISSUE_PLACEHOLDER],
            'digits only'       => ['123456', 'Dhaka', AddressAnalysis::ISSUE_NO_LETTERS],
            'punctuation only'  => ['....,,,,', 'Dhaka', AddressAnalysis::ISSUE_NO_LETTERS],
            'city repeated'     => ['Dhaka', 'Dhaka', AddressAnalysis::ISSUE_CITY_ONLY],
            'city repeated ci'  => ['  dhaka. ', 'Dhaka', AddressAnalysis::ISSUE_CITY_ONLY],
            'gibberish'         => ['asdfgh jklasdf', 'Dhaka', AddressAnalysis::ISSUE_GIBBERISH],
            'repeated chars'    => ['aaaaaaaaaaaaa', 'Dhaka', AddressAnalysis::ISSUE_GIBBERISH],
            'missing city'      => ['House 12, Road 5, Banani', '', AddressAnalysis::ISSUE_MISSING_CITY],
            'no area detail'    => ['Amader elakay', 'Dhaka', AddressAnalysis::ISSUE_MISSING_AREA],
            'one long word'     => ['Bashundharaaa', 'Dhaka', AddressAnalysis::ISSUE_TOO_FEW_WORDS],
        ];
    }

    #[DataProvider('badAddresses')]
    public function testBadAddressesAreFlagged(string $address, string $city, string $issue): void
    {
        $analysis = (new AddressAnalyzer())->analyze($address, '', $city);

        self::assertTrue($analysis->has($issue), 'Expected ' . $issue . ', got ' . implode(',', $analysis->issues));
        self::assertGreaterThan(0.0, $analysis->severity);
    }

    public function testSeverityIsCappedAtOne(): void
    {
        $analysis = (new AddressAnalyzer())->analyze('', '', '');

        self::assertSame(1.0, $analysis->severity);
        self::assertTrue($analysis->has(AddressAnalysis::ISSUE_EMPTY));
        self::assertTrue($analysis->has(AddressAnalysis::ISSUE_MISSING_CITY));
    }

    public function testSeveritiesAddUp(): void
    {
        // Too short (0.6) + missing area (0.3).
        $analysis = (new AddressAnalyzer())->analyze('Amar', '', 'Dhaka');

        self::assertEqualsWithDelta(0.9, $analysis->severity, 0.0001);
    }

    public function testMinLengthIsConfigurable(): void
    {
        $analyzer = new AddressAnalyzer(10);

        self::assertTrue($analyzer->analyze('Road 5', '', 'Dhaka')->has(AddressAnalysis::ISSUE_TOO_SHORT));
        self::assertFalse($analyzer->withMinLength(5)->analyze('Road 5', '', 'Dhaka')->has(AddressAnalysis::ISSUE_TOO_SHORT));
    }

    public function testSecondLineCountsTowardsLength(): void
    {
        $analysis = (new AddressAnalyzer())->analyze('Road 5', 'Sector 7, Uttara', 'Dhaka');

        self::assertTrue($analysis->isClean());
    }

    public function testLabelsAreHumanReadable(): void
    {
        $analysis = (new AddressAnalyzer())->analyze('test', '', 'Dhaka');

        self::assertSame(['address is a placeholder value'], $analysis->labels());
    }

    public function testEveryIssueHasSeverityAndLabel(): void
    {
        self::assertSame(array_keys(AddressAnalysis::SEVERITY), array_keys(AddressAnalysis::LABELS));
    }
}
