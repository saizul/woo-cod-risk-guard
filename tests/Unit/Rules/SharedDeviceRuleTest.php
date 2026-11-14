<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Rules;

use DateTimeImmutable;
use DateTimeZone;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\History\InMemoryVelocityProvider;
use Saizul\CodRiskGuard\History\VelocityProviderInterface;
use Saizul\CodRiskGuard\Rules\SharedDeviceRule;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\Tests\EngineTestCase;
use Saizul\CodRiskGuard\WP\Labels;
use Saizul\CodRiskGuard\WP\Presenter;

final class SharedDeviceRuleTest extends EngineTestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-03-10 08:30:00', new DateTimeZone('UTC'));
    }

    /**
     * Record one earlier order with the given phone, device and IP.
     */
    private function earlierOrder(int $orderId, string $phone, string $device = 'device-abc', string $ip = '203.0.113.10', string $ago = '-1 day'): void
    {
        $this->velocity->recordOrder(
            $orderId,
            [
                IdentifierHasher::TYPE_PHONE  => $this->hasher->hashPhone($phone),
                IdentifierHasher::TYPE_DEVICE => $this->hasher->hashDevice($device),
                IdentifierHasher::TYPE_IP     => $this->hasher->hashIp($ip),
            ],
            $this->now()->modify($ago)
        );
    }

    public function testPassesWithoutEarlierOrders(): void
    {
        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order())->triggered);
    }

    public function testSamePhoneOnSameDeviceIsNotCounted(): void
    {
        $this->earlierOrder(901, self::PHONE);
        $this->earlierOrder(902, self::PHONE);
        $this->earlierOrder(903, '017 0000 0001');

        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order())->triggered);
    }

    public function testBelowLimitPasses(): void
    {
        $this->earlierOrder(901, '01800000002');

        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order())->triggered);
    }

    public function testTriggersAtLimit(): void
    {
        $this->earlierOrder(901, '01800000002');
        $this->earlierOrder(902, '01900000003');

        $result = $this->runRule(new SharedDeviceRule(), $this->order());

        self::assertTrue($result->triggered);
        self::assertSame('shared_device.match', $result->reasonCode);
        self::assertSame(0.5, $result->severity);
        self::assertSame(12.5, $result->points);
        self::assertSame('device', $result->data['type']);
        self::assertSame(2, $result->data['count']);
        self::assertSame('2 other phone numbers ordered from the same device in the last 30 days.', $result->reason);
    }

    public function testCountsDistinctPhonesOnly(): void
    {
        $this->earlierOrder(901, '01800000002');
        $this->earlierOrder(902, '01800000002');
        $this->earlierOrder(903, '+8801800000002');

        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order())->triggered);
    }

    public function testReachesFullSeverityAtDoubleLimit(): void
    {
        foreach (['01800000002', '01900000003', '01500000004', '01600000005'] as $i => $phone) {
            $this->earlierOrder(901 + $i, $phone);
        }

        $result = $this->runRule(new SharedDeviceRule(), $this->order());

        self::assertSame(1.0, $result->severity);
        self::assertSame(25.0, $result->points);
    }

    public function testIgnoresOrdersOutsideWindow(): void
    {
        $this->earlierOrder(901, '01800000002', 'device-abc', '203.0.113.10', '-31 days');
        $this->earlierOrder(902, '01900000003', 'device-abc', '203.0.113.10', '-40 days');

        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order())->triggered);
        self::assertTrue($this->runRule(new SharedDeviceRule(), $this->order(), ['window_days' => 60])->triggered);
    }

    public function testIgnoresOtherDevices(): void
    {
        $this->earlierOrder(901, '01800000002', 'device-other');
        $this->earlierOrder(902, '01900000003', 'device-other');

        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order())->triggered);
    }

    public function testDoesNotCountTheOrderItself(): void
    {
        // A rescore of order 1001 must not count the phone stored for 1001 itself.
        $this->earlierOrder(1001, '01800000002', 'device-abc', '203.0.113.10', '-1 minute');
        $this->earlierOrder(902, '01900000003');

        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order())->triggered);
    }

    public function testLimitIsConfigurable(): void
    {
        $this->earlierOrder(901, '01800000002');

        self::assertTrue($this->runRule(new SharedDeviceRule(), $this->order(), ['max_phones' => 1])->triggered);
    }

    public function testIpIsIgnoredByDefault(): void
    {
        $this->earlierOrder(901, '01800000002', 'device-x');
        $this->earlierOrder(902, '01900000003', 'device-y');

        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order())->triggered);
    }

    public function testIpCheckCanBeEnabled(): void
    {
        $this->earlierOrder(901, '01800000002', 'device-x');
        $this->earlierOrder(902, '01900000003', 'device-y');
        $this->earlierOrder(903, '01500000004', 'device-z');

        $result = $this->runRule(new SharedDeviceRule(), $this->order(), ['include_ip' => true]);

        self::assertTrue($result->triggered);
        self::assertSame('ip', $result->data['type']);
        self::assertSame(3, $result->data['count']);
        self::assertSame('3 other phone numbers ordered from the same IP address in the last 30 days.', Presenter::reason($result));
    }

    public function testUsesTheWorstIdentifier(): void
    {
        $this->earlierOrder(901, '01800000002');
        $this->earlierOrder(902, '01900000003');
        $this->earlierOrder(903, '01500000004', 'device-y');

        $result = $this->runRule(new SharedDeviceRule(), $this->order(), ['include_ip' => '1']);

        self::assertSame('ip', $result->data['type']);
        self::assertSame(3, $result->data['count']);
    }

    public function testOrderWithoutPhoneCountsEveryPhone(): void
    {
        $this->earlierOrder(901, '01800000002');
        $this->earlierOrder(902, self::PHONE);

        $result = $this->runRule(new SharedDeviceRule(), $this->order(['phone' => '']));

        self::assertTrue($result->triggered);
        self::assertSame(2, $result->data['count']);
    }

    public function testOrderWithoutDevicePasses(): void
    {
        $this->earlierOrder(901, '01800000002');
        $this->earlierOrder(902, '01900000003');

        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order(['device_id' => '']))->triggered);
    }

    public function testEventsWithoutOrderIdAreNotLinked(): void
    {
        foreach (['01800000002', '01900000003'] as $phone) {
            $this->velocity->recordOrder(
                0,
                [
                    IdentifierHasher::TYPE_PHONE  => $this->hasher->hashPhone($phone),
                    IdentifierHasher::TYPE_DEVICE => $this->hasher->hashDevice('device-abc'),
                ],
                $this->now()->modify('-1 hour')
            );
        }

        self::assertFalse($this->runRule(new SharedDeviceRule(), $this->order())->triggered);
    }

    public function testPassesWhenProviderCannotLinkIdentifiers(): void
    {
        $provider = new class () implements VelocityProviderInterface {
            public function countSince(string $type, string $hash, DateTimeImmutable $since, int $excludeOrderId = 0): int
            {
                return 99;
            }
        };

        $signals = new Signals($this->hasher, $this->history, $provider, $this->lists);
        $rule    = new SharedDeviceRule();

        self::assertFalse($rule->evaluate($this->order(), $signals, $this->configFor($rule))->triggered);
    }

    public function testEngineIncludesTheRuleAndReasonIsTranslatable(): void
    {
        $this->earlierOrder(901, '01800000002');
        $this->earlierOrder(902, '01900000003');
        $this->earlierOrder(903, '01500000004');

        $result = $this->engine()->evaluate($this->order());
        $codes  = array_map(
            static function ($rule): string {
                return $rule->reasonCode;
            },
            $result->triggered()
        );

        self::assertContains('shared_device.match', $codes);
        self::assertNotNull(Labels::template('shared_device.match'));
        self::assertSame($result->reasons(), Presenter::reasons($result));
    }

    public function testInMemoryProviderLinksByOrderId(): void
    {
        $provider = new InMemoryVelocityProvider();
        $since    = $this->now()->modify('-1 day');

        $provider->record(IdentifierHasher::TYPE_DEVICE, 'd1', $this->now(), 1);
        $provider->record(IdentifierHasher::TYPE_PHONE, 'p1', $this->now(), 1);
        $provider->record(IdentifierHasher::TYPE_DEVICE, 'd1', $this->now(), 2);
        $provider->record(IdentifierHasher::TYPE_PHONE, 'p2', $this->now(), 2);
        $provider->record(IdentifierHasher::TYPE_PHONE, 'p3', $this->now(), 3);
        $provider->record(IdentifierHasher::TYPE_PHONE, '', $this->now(), 2);

        self::assertSame(2, $provider->countDistinctLinked(IdentifierHasher::TYPE_DEVICE, 'd1', IdentifierHasher::TYPE_PHONE, $since));
        self::assertSame(1, $provider->countDistinctLinked(IdentifierHasher::TYPE_DEVICE, 'd1', IdentifierHasher::TYPE_PHONE, $since, 'p1'));
        self::assertSame(1, $provider->countDistinctLinked(IdentifierHasher::TYPE_DEVICE, 'd1', IdentifierHasher::TYPE_PHONE, $since, '', 2));
        self::assertSame(0, $provider->countDistinctLinked(IdentifierHasher::TYPE_DEVICE, '', IdentifierHasher::TYPE_PHONE, $since));
        self::assertSame(0, $provider->countDistinctLinked(IdentifierHasher::TYPE_DEVICE, 'd1', IdentifierHasher::TYPE_PHONE, $this->now()->modify('+1 minute')));
        self::assertSame(5, $provider->count(), 'Empty hashes are not recorded.');
    }
}
