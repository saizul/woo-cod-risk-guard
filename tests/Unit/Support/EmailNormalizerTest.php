<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Support\EmailNormalizer;

final class EmailNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function canonicalProvider(): array
    {
        return [
            'lowercase'        => ['Customer@Example.COM', 'customer@example.com'],
            'trim'             => ['  customer@example.com ', 'customer@example.com'],
            'plus tag'         => ['customer+shop@example.com', 'customer@example.com'],
            'gmail dots'       => ['c.u.s.t.omer@gmail.com', 'customer@gmail.com'],
            'gmail plus dots'  => ['cust.omer+x@gmail.com', 'customer@gmail.com'],
            'googlemail'       => ['customer@googlemail.com', 'customer@gmail.com'],
            'dots kept'        => ['first.last@example.com', 'first.last@example.com'],
            'no at sign'       => ['not-an-email', 'not-an-email'],
            'leading plus'     => ['+tag@example.com', '+tag@example.com'],
            'trailing dot'     => ['customer@example.com.', 'customer@example.com'],
        ];
    }

    #[DataProvider('canonicalProvider')]
    public function testCanonical(string $input, string $expected): void
    {
        self::assertSame($expected, EmailNormalizer::canonical($input));
    }

    public function testDomain(): void
    {
        self::assertSame('example.com', EmailNormalizer::domain('Someone@Example.com'));
        self::assertSame('', EmailNormalizer::domain('no-domain'));
        self::assertSame('b.example', EmailNormalizer::domain('weird@a@b.example'));
    }

    public function testIsValid(): void
    {
        self::assertTrue(EmailNormalizer::isValid('customer@example.com'));
        self::assertFalse(EmailNormalizer::isValid('customer@'));
        self::assertFalse(EmailNormalizer::isValid(''));
        self::assertFalse(EmailNormalizer::isValid('customer example.com'));
    }
}
