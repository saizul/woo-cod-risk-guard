<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Support\IdentifierHasher;

final class IdentifierHasherTest extends TestCase
{
    private IdentifierHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new IdentifierHasher('secret-one');
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdentifierHasher('');
    }

    public function testHashIsSha256Hex(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->hasher->hashPhone('01700000375'));
    }

    public function testPhoneSpellingsHashIdentically(): void
    {
        $expected = $this->hasher->hashPhone('01700000375');

        self::assertSame($expected, $this->hasher->hashPhone('+880 1700-000375'));
        self::assertSame($expected, $this->hasher->hashPhone('০১৭০০০০০৩৭৫'));
        self::assertSame($expected, $this->hasher->hashPhone('8801700000375'));
    }

    public function testDifferentPhonesHashDifferently(): void
    {
        self::assertNotSame($this->hasher->hashPhone('01700000375'), $this->hasher->hashPhone('01700000376'));
    }

    public function testHashDoesNotContainTheRawValue(): void
    {
        self::assertStringNotContainsString('1700000375', $this->hasher->hashPhone('01700000375'));
    }

    public function testDifferentSecretsProduceDifferentHashes(): void
    {
        $other = new IdentifierHasher('secret-two');

        self::assertNotSame($this->hasher->hashPhone('01700000375'), $other->hashPhone('01700000375'));
    }

    public function testTypeIsPartOfTheHash(): void
    {
        self::assertNotSame(
            $this->hasher->hash(IdentifierHasher::TYPE_DEVICE, 'abc'),
            $this->hasher->hash(IdentifierHasher::TYPE_EMAIL, 'abc')
        );
    }

    public function testEmptyValuesHashToEmptyString(): void
    {
        self::assertSame('', $this->hasher->hashPhone(''));
        self::assertSame('', $this->hasher->hashEmail('  '));
        self::assertSame('', $this->hasher->hashIp(''));
        self::assertSame('', $this->hasher->hashDevice(''));
    }

    public function testInvalidIpHashesToEmptyString(): void
    {
        self::assertSame('', $this->hasher->hashIp('not-an-ip'));
        self::assertSame('', $this->hasher->hashIp('999.1.1.1'));
    }

    public function testEmailVariantsHashIdentically(): void
    {
        self::assertSame(
            $this->hasher->hashEmail('customer@gmail.com'),
            $this->hasher->hashEmail('Cust.Omer+promo@GoogleMail.com')
        );
    }

    public function testIpv6FormsHashIdentically(): void
    {
        self::assertSame(
            $this->hasher->hashIp('2001:db8::1'),
            $this->hasher->hashIp('2001:0DB8:0000:0000:0000:0000:0000:0001')
        );
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->hasher->hash('fingerprint', 'x');
    }

    public function testTypes(): void
    {
        self::assertSame(['phone', 'email', 'ip', 'device'], IdentifierHasher::types());
        self::assertTrue(IdentifierHasher::isValidType('ip'));
        self::assertFalse(IdentifierHasher::isValidType('name'));
    }

    public function testMaskPhone(): void
    {
        self::assertSame('017*****375', IdentifierHasher::maskPhone('01700000375'));
        self::assertSame('880*******375', IdentifierHasher::maskPhone('+8801700000375'));
        self::assertSame('*****', IdentifierHasher::maskPhone('12345'));
        self::assertSame('', IdentifierHasher::maskPhone(''));
    }

    public function testMaskEmail(): void
    {
        self::assertSame('c*******@example.com', IdentifierHasher::maskEmail('Customer@example.com'));
        self::assertSame('a*@example.com', IdentifierHasher::maskEmail('a@example.com'));
    }

    public function testMaskIp(): void
    {
        self::assertSame('203.0.113.*', IdentifierHasher::maskIp('203.0.113.10'));
        self::assertSame('2001:db8:*', IdentifierHasher::maskIp('2001:db8::1'));
        self::assertSame('', IdentifierHasher::maskIp('nonsense'));
    }

    public function testMaskGeneric(): void
    {
        self::assertSame('de******bc', IdentifierHasher::maskGeneric('device-abc'));
        self::assertSame('***', IdentifierHasher::maskGeneric('abc'));
    }

    public function testMaskByTypeNormalisesPhonesFirst(): void
    {
        self::assertSame('017*****375', $this->hasher->mask(IdentifierHasher::TYPE_PHONE, '+880 1700 000375'));
        self::assertSame('203.0.113.*', $this->hasher->mask(IdentifierHasher::TYPE_IP, '203.0.113.10'));
        self::assertSame('c*******@example.com', $this->hasher->mask(IdentifierHasher::TYPE_EMAIL, 'customer@example.com'));
        self::assertSame('de******bc', $this->hasher->mask(IdentifierHasher::TYPE_DEVICE, 'device-abc'));
    }
}
