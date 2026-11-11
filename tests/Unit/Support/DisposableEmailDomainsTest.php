<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Support\DisposableEmailDomains;

final class DisposableEmailDomainsTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function emailProvider(): array
    {
        return [
            'mailinator'        => ['someone@mailinator.com', true],
            'yopmail'           => ['someone@yopmail.com', true],
            'upper case'        => ['SOMEONE@MAILINATOR.COM', true],
            'subdomain'         => ['someone@inbox.mailinator.com', true],
            'ordinary domain'   => ['someone@example.com', false],
            'gmail'             => ['someone@gmail.com', false],
            'similar name'      => ['someone@notmailinator.com', false],
            'no domain'         => ['someone', false],
        ];
    }

    #[DataProvider('emailProvider')]
    public function testIsDisposable(string $email, bool $expected): void
    {
        self::assertSame($expected, (new DisposableEmailDomains())->isDisposable($email));
    }

    public function testExtraDomainsAreHonoured(): void
    {
        $list = new DisposableEmailDomains(['Throwaway.example', '@junk.example ']);

        self::assertTrue($list->isDisposable('a@throwaway.example'));
        self::assertTrue($list->isDisposable('a@junk.example'));
    }

    public function testBundledListCanBeExcluded(): void
    {
        $list = new DisposableEmailDomains(['junk.example'], false);

        self::assertSame(1, $list->count());
        self::assertFalse($list->isDisposable('a@mailinator.com'));
        self::assertTrue($list->isDisposable('a@junk.example'));
    }

    public function testInvalidDomainsAreIgnored(): void
    {
        $list = new DisposableEmailDomains(['', 'no spaces.example', 'nodot', '<script>'], false);

        self::assertSame(0, $list->count());
    }

    public function testCleanDomain(): void
    {
        self::assertSame('example.com', DisposableEmailDomains::cleanDomain(' @Example.COM. '));
        self::assertSame('', DisposableEmailDomains::cleanDomain('localhost'));
    }

    public function testBundledListIsNotEmpty(): void
    {
        self::assertGreaterThan(40, (new DisposableEmailDomains())->count());
    }
}
