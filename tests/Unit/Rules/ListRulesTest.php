<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Rules;

use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Rules\AllowlistRule;
use Saizul\CodRiskGuard\Rules\BlocklistRule;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\Tests\EngineTestCase;

final class ListRulesTest extends EngineTestCase
{
    private function block(string $type, string $value): void
    {
        $this->lists->add(ListStoreInterface::BLOCK, $type, $this->hasher->hash($type, $value));
    }

    private function allow(string $type, string $value): void
    {
        $this->lists->add(ListStoreInterface::ALLOW, $type, $this->hasher->hash($type, $value));
    }

    public function testBlocklistPassesWhenNothingIsListed(): void
    {
        $result = $this->runRule(new BlocklistRule(), $this->order());

        self::assertFalse($result->triggered);
        self::assertNull($result->override);
    }

    public function testBlocklistMatchesPhoneInAnySpelling(): void
    {
        $this->block(IdentifierHasher::TYPE_PHONE, '+880 1700-000001');

        $result = $this->runRule(new BlocklistRule(), $this->order());

        self::assertTrue($result->triggered);
        self::assertSame(RuleResult::OVERRIDE_BLOCK, $result->override);
        self::assertSame('blocklist.match', $result->reasonCode);
        self::assertSame(['phone'], $result->data['matched']);
        self::assertSame('Customer is on the blocklist (matched: phone).', $result->reason);
    }

    public function testBlocklistMatchesIp(): void
    {
        $this->block(IdentifierHasher::TYPE_IP, '203.0.113.10');

        self::assertSame(['ip'], $this->runRule(new BlocklistRule(), $this->order())->data['matched']);
    }

    public function testBlocklistMatchesEmailVariant(): void
    {
        $this->block(IdentifierHasher::TYPE_EMAIL, 'customer@example.com');

        $result = $this->runRule(new BlocklistRule(), $this->order(['email' => 'Customer+promo@Example.com']));

        self::assertSame(['email'], $result->data['matched']);
    }

    public function testBlocklistMatchesDevice(): void
    {
        $this->block(IdentifierHasher::TYPE_DEVICE, 'device-abc');

        self::assertSame(['device'], $this->runRule(new BlocklistRule(), $this->order())->data['matched']);
    }

    public function testBlocklistReportsEveryMatchedIdentifier(): void
    {
        $this->block(IdentifierHasher::TYPE_PHONE, self::PHONE);
        $this->block(IdentifierHasher::TYPE_IP, '203.0.113.10');

        $result = $this->runRule(new BlocklistRule(), $this->order());

        self::assertSame(['phone', 'ip'], $result->data['matched']);
        self::assertSame(100.0, $result->points);
    }

    public function testBlocklistIgnoresOtherCustomers(): void
    {
        $this->block(IdentifierHasher::TYPE_PHONE, '01800000002');
        $this->block(IdentifierHasher::TYPE_IP, '203.0.113.99');

        self::assertFalse($this->runRule(new BlocklistRule(), $this->order())->triggered);
    }

    public function testAllowlistEntryDoesNotTriggerBlocklist(): void
    {
        $this->allow(IdentifierHasher::TYPE_PHONE, self::PHONE);

        self::assertFalse($this->runRule(new BlocklistRule(), $this->order())->triggered);
    }

    public function testAllowlistMatchesPhone(): void
    {
        $this->allow(IdentifierHasher::TYPE_PHONE, self::PHONE);

        $result = $this->runRule(new AllowlistRule(), $this->order());

        self::assertTrue($result->triggered);
        self::assertSame(RuleResult::OVERRIDE_ALLOW, $result->override);
        self::assertSame('allowlist.match', $result->reasonCode);
        self::assertSame(0.0, $result->points);
        self::assertTrue($result->isTrustSignal());
    }

    public function testAllowlistMatchesEmail(): void
    {
        $this->allow(IdentifierHasher::TYPE_EMAIL, 'customer@example.com');

        self::assertSame(['email'], $this->runRule(new AllowlistRule(), $this->order())->data['matched']);
    }

    public function testAllowlistIgnoresIpAndDeviceEntries(): void
    {
        // Shared IPs and devices must never whitelist a whole household or office.
        $this->allow(IdentifierHasher::TYPE_IP, '203.0.113.10');
        $this->allow(IdentifierHasher::TYPE_DEVICE, 'device-abc');

        self::assertFalse($this->runRule(new AllowlistRule(), $this->order())->triggered);
    }

    public function testAllowlistPassesByDefault(): void
    {
        self::assertFalse($this->runRule(new AllowlistRule(), $this->order())->triggered);
    }
}
