<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Support\GibberishDetector;

final class GibberishDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function textProvider(): array
    {
        return [
            'keyboard run asdf'      => ['asdfgh', true],
            'keyboard run qwerty'    => ['qwerty', true],
            'keyboard run reversed'  => ['lkjhgf', true],
            'repeated letter'        => ['aaaaaa', true],
            'repeated inside'        => ['Dhakaaaaa', true],
            'no vowels'              => ['bcdfg', true],
            'consonant run'          => ['strngthsmnt road', true],
            'low variety'            => ['abababab', true],
            'repeated bengali'       => ['কককককক', true],
            'real name'              => ['Rahim Uddin', false],
            'real address'           => ['House 12, Road 5, Banani', false],
            'bengali address'        => ['বাড়ি ১২, রোড ৫, বনানী', false],
            'short abbreviation'     => ['Rd', false],
            'number with zeros'      => ['House 10000', false],
            'mirpur dohs'            => ['Mirpur DOHS', false],
            'empty'                  => ['', false],
        ];
    }

    #[DataProvider('textProvider')]
    public function testIsGibberish(string $text, bool $expected): void
    {
        self::assertSame($expected, (new GibberishDetector())->isGibberish($text));
    }

    public function testReasonsAreReported(): void
    {
        $detector = new GibberishDetector();

        self::assertContains(GibberishDetector::REASON_KEYBOARD_RUN, $detector->reasons('asdf asdf'));
        self::assertContains(GibberishDetector::REASON_REPEATED_CHARS, $detector->reasons('xxxxx'));
        self::assertContains(GibberishDetector::REASON_NO_VOWELS, $detector->reasons('bcdfgh'));
        self::assertContains(GibberishDetector::REASON_LOW_VARIETY, $detector->reasons('abababab'));
        self::assertSame([], $detector->reasons('Karim Ahmed'));
    }
}
