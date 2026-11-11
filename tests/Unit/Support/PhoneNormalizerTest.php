<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Support\PhoneAnalysis;
use Saizul\CodRiskGuard\Support\PhoneNormalizer;

final class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $phones;

    protected function setUp(): void
    {
        $this->phones = new PhoneNormalizer();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validSpellings(): array
    {
        return [
            'national'              => ['01700000000'],
            'plus country code'     => ['+8801700000000'],
            'country code no plus'  => ['8801700000000'],
            'double zero prefix'    => ['008801700000000'],
            'missing leading zero'  => ['1700000000'],
            'spaces'                => ['017 0000 0000'],
            'dashes'                => ['017-0000-0000'],
            'brackets and spaces'   => ['(+880) 1700 000000'],
            'dots'                  => ['017.0000.0000'],
            'bangla digits'         => ['০১৭০০০০০০০০'],
            'bangla with plus'      => ['+৮৮০১৭০০০০০০০০'],
            'surrounding space'     => ['  01700000000  '],
            'plus 880 with zero'    => ['+880 01700000000'],
        ];
    }

    #[DataProvider('validSpellings')]
    public function testEverySpellingNormalisesToTheSameNumber(string $raw): void
    {
        if ('+880 01700000000' === $raw) {
            // "+880 0..." has one digit too many; it must be rejected, not silently fixed.
            self::assertNull($this->phones->normalize($raw));

            return;
        }

        self::assertSame('01700000000', $this->phones->normalize($raw));
        self::assertSame('+8801700000000', $this->phones->toE164($raw));
        self::assertTrue($this->phones->isValidBangladeshMobile($raw));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNumbers(): array
    {
        return [
            'empty'             => ['', PhoneAnalysis::ISSUE_EMPTY],
            'only text'         => ['call me', PhoneAnalysis::ISSUE_EMPTY],
            'too short'         => ['0170000000', PhoneAnalysis::ISSUE_TOO_SHORT],
            'far too short'     => ['12345', PhoneAnalysis::ISSUE_TOO_SHORT],
            'too long'          => ['017000000001', PhoneAnalysis::ISSUE_TOO_LONG],
            'landline prefix'   => ['02900000000', PhoneAnalysis::ISSUE_BAD_PREFIX],
            'bad operator 011'  => ['01100000000', PhoneAnalysis::ISSUE_BAD_PREFIX],
            'bad operator 012'  => ['01200000000', PhoneAnalysis::ISSUE_BAD_PREFIX],
            'foreign us'        => ['+1 202 555 0100', PhoneAnalysis::ISSUE_FOREIGN],
            'foreign india'     => ['+91 9000000000', PhoneAnalysis::ISSUE_FOREIGN],
            'foreign 10 digits' => ['+1700000000', PhoneAnalysis::ISSUE_FOREIGN],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function testInvalidNumbersAreRejectedWithAReason(string $raw, string $issue): void
    {
        $analysis = $this->phones->analyze($raw);

        self::assertFalse($analysis->validBangladeshMobile);
        self::assertNull($analysis->national);
        self::assertNull($analysis->e164);
        self::assertTrue($analysis->has($issue), 'Expected issue ' . $issue . ', got ' . implode(',', $analysis->issues));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function operators(): array
    {
        return [
            '013' => ['01300000001', 'Grameenphone'],
            '014' => ['01400000001', 'Banglalink'],
            '015' => ['01500000001', 'Teletalk'],
            '016' => ['01600000001', 'Robi'],
            '017' => ['01700000001', 'Grameenphone'],
            '018' => ['01800000001', 'Robi'],
            '019' => ['01900000001', 'Banglalink'],
            'invalid' => ['01100000001', null],
        ];
    }

    #[DataProvider('operators')]
    public function testOperatorDetection(string $raw, ?string $operator): void
    {
        self::assertSame($operator, $this->phones->operator($raw));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function placeholders(): array
    {
        return [
            'all same digit'     => ['01711111111', true],
            'all zeros'          => ['01700000000', true],
            'ascending'          => ['01712345678', true],
            'descending'         => ['01787654321', true],
            'two digit repeat'   => ['01712121212', true],
            'ordinary number'    => ['01700000375', false],
            'another ordinary'   => ['01800000172', false],
        ];
    }

    #[DataProvider('placeholders')]
    public function testPlaceholderDetection(string $raw, bool $expected): void
    {
        $analysis = $this->phones->analyze($raw);

        self::assertTrue($analysis->validBangladeshMobile);
        self::assertSame($expected, $analysis->isPlaceholder());
    }

    public function testCanonicalCollapsesSpellings(): void
    {
        self::assertSame('01700000375', $this->phones->canonical('+880 1700-000375'));
        self::assertSame('01700000375', $this->phones->canonical('০১৭০০০০০৩৭৫'));
    }

    public function testCanonicalFallsBackToDigitsForForeignNumbers(): void
    {
        self::assertSame('12025550100', $this->phones->canonical('+1 (202) 555-0100'));
        self::assertSame('12025550100', $this->phones->canonical('0012025550100'));
        self::assertSame('', $this->phones->canonical('n/a'));
    }

    public function testSameComparesCanonicalForms(): void
    {
        self::assertTrue($this->phones->same('01700000375', '+8801700000375'));
        self::assertFalse($this->phones->same('01700000375', '01700000376'));
        self::assertFalse($this->phones->same('', ''));
    }

    public function testAnalysisKeepsRawInputAndInternationalFlag(): void
    {
        $analysis = $this->phones->analyze(' +880 1700-000375 ');

        self::assertSame(' +880 1700-000375 ', $analysis->raw);
        self::assertSame('8801700000375', $analysis->digits);
        self::assertTrue($analysis->international);
        self::assertFalse($this->phones->analyze('01700000375')->international);
        self::assertSame([], $analysis->issues);
    }

    public function testEmptyAnalysis(): void
    {
        $analysis = $this->phones->analyze('   ');

        self::assertTrue($analysis->isEmpty());
        self::assertSame('', $analysis->digits);
    }
}
