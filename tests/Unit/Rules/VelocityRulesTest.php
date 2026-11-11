<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Rules;

use DateTimeImmutable;
use DateTimeZone;
use Saizul\CodRiskGuard\Rules\IpVelocityRule;
use Saizul\CodRiskGuard\Rules\RepeatOrdersRule;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\Tests\EngineTestCase;

final class VelocityRulesTest extends EngineTestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-03-10 08:30:00', new DateTimeZone('UTC'));
    }

    public function testRepeatOrdersPassesWithoutEarlierOrders(): void
    {
        self::assertFalse($this->runRule(new RepeatOrdersRule(), $this->order())->triggered);
    }

    public function testRepeatOrdersBelowLimitPasses(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_PHONE, self::PHONE, 1, $this->now());

        self::assertFalse($this->runRule(new RepeatOrdersRule(), $this->order())->triggered);
    }

    public function testRepeatOrdersFromSamePhone(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_PHONE, self::PHONE, 2, $this->now());

        $result = $this->runRule(new RepeatOrdersRule(), $this->order());

        self::assertSame('repeat_orders.match', $result->reasonCode);
        self::assertSame(0.5, $result->severity);
        self::assertSame('phone', $result->data['type']);
        self::assertSame('2 other orders from the same phone in the last 24 hours.', $result->reason);
    }

    public function testRepeatOrdersFromSameDevice(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_DEVICE, 'device-abc', 3, $this->now());

        $result = $this->runRule(new RepeatOrdersRule(), $this->order());

        self::assertSame('device', $result->data['type']);
        self::assertSame(3, $result->data['count']);
        self::assertEqualsWithDelta(0.75, $result->severity, 0.0001);
    }

    public function testRepeatOrdersUsesTheWorstIdentifier(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_PHONE, self::PHONE, 2, $this->now());
        $this->recordEvents(IdentifierHasher::TYPE_DEVICE, 'device-abc', 4, $this->now());

        $result = $this->runRule(new RepeatOrdersRule(), $this->order());

        self::assertSame('device', $result->data['type']);
        self::assertSame(1.0, $result->severity);
    }

    public function testRepeatOrdersIgnoresOrdersOutsideWindow(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_PHONE, self::PHONE, 3, $this->now()->modify('-25 hours'));

        self::assertFalse($this->runRule(new RepeatOrdersRule(), $this->order())->triggered);
    }

    public function testRepeatOrdersWindowIsConfigurable(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_PHONE, self::PHONE, 3, $this->now()->modify('-25 hours'));

        self::assertTrue($this->runRule(new RepeatOrdersRule(), $this->order(), ['window_hours' => 48])->triggered);
    }

    public function testRepeatOrdersLimitIsConfigurable(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_PHONE, self::PHONE, 1, $this->now());

        self::assertTrue($this->runRule(new RepeatOrdersRule(), $this->order(), ['max_orders' => 1])->triggered);
    }

    public function testRepeatOrdersDoesNotCountTheOrderItself(): void
    {
        $hash = $this->hasher->hashPhone(self::PHONE);
        $this->velocity->record(IdentifierHasher::TYPE_PHONE, $hash, $this->now(), 1001);
        $this->velocity->record(IdentifierHasher::TYPE_PHONE, $hash, $this->now()->modify('-1 minute'), 900);

        self::assertFalse($this->runRule(new RepeatOrdersRule(), $this->order())->triggered);
    }

    public function testRepeatOrdersIgnoresIpEvents(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_IP, '203.0.113.10', 10, $this->now());

        self::assertFalse($this->runRule(new RepeatOrdersRule(), $this->order())->triggered);
    }

    public function testRepeatOrdersWithoutIdentifiersPasses(): void
    {
        $order = $this->order(['phone' => '', 'device_id' => '']);

        self::assertFalse($this->runRule(new RepeatOrdersRule(), $order)->triggered);
    }

    public function testIpVelocityPassesBelowLimit(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_IP, '203.0.113.10', 2, $this->now());

        self::assertFalse($this->runRule(new IpVelocityRule(), $this->order())->triggered);
    }

    public function testIpVelocityTriggersAtLimit(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_IP, '203.0.113.10', 3, $this->now());

        $result = $this->runRule(new IpVelocityRule(), $this->order());

        self::assertSame('ip_velocity.match', $result->reasonCode);
        self::assertSame(0.5, $result->severity);
        self::assertSame(10.0, $result->points);
        self::assertSame('3 other orders from the same IP address in the last 60 minutes.', $result->reason);
    }

    public function testIpVelocityReachesFullSeverityAtDoubleLimit(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_IP, '203.0.113.10', 6, $this->now());

        self::assertSame(1.0, $this->runRule(new IpVelocityRule(), $this->order())->severity);
    }

    public function testIpVelocityIgnoresOldOrders(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_IP, '203.0.113.10', 5, $this->now()->modify('-2 hours'));

        self::assertFalse($this->runRule(new IpVelocityRule(), $this->order())->triggered);
    }

    public function testIpVelocityIgnoresOtherIps(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_IP, '203.0.113.11', 5, $this->now());

        self::assertFalse($this->runRule(new IpVelocityRule(), $this->order())->triggered);
    }

    public function testIpVelocityPassesWithoutOrInvalidIp(): void
    {
        self::assertFalse($this->runRule(new IpVelocityRule(), $this->order(['ip' => '']))->triggered);
        self::assertFalse($this->runRule(new IpVelocityRule(), $this->order(['ip' => 'unknown']))->triggered);
    }

    public function testIpVelocityOptionsAreConfigurable(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_IP, '203.0.113.10', 2, $this->now(), 20);

        self::assertTrue($this->runRule(new IpVelocityRule(), $this->order(), ['max_orders' => 2])->triggered);
        self::assertFalse(
            $this->runRule(new IpVelocityRule(), $this->order(), ['max_orders' => 2, 'window_minutes' => 30])->triggered
        );
    }

    public function testIpVelocityWorksWithIpv6(): void
    {
        $this->recordEvents(IdentifierHasher::TYPE_IP, '2001:db8::1', 3, $this->now());

        $order = $this->order(['ip' => '2001:0db8:0000:0000:0000:0000:0000:0001']);

        self::assertTrue($this->runRule(new IpVelocityRule(), $order)->triggered);
    }
}
