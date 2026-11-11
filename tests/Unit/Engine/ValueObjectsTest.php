<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Engine;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\Engine\RiskResult;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;

final class ValueObjectsTest extends TestCase
{
    // --- OrderContext ----------------------------------------------------

    public function testContextDefaults(): void
    {
        $order = OrderContext::fromArray([]);

        self::assertSame(0, $order->orderId);
        self::assertSame('', $order->phone);
        self::assertSame('BDT', $order->currency);
        self::assertSame('BD', $order->country);
        self::assertSame('cod', $order->paymentMethod);
        self::assertFalse($order->phoneVerified);
        self::assertSame([], $order->meta);
    }

    public function testContextCoercesAndTrimsValues(): void
    {
        $order = OrderContext::fromArray([
            'order_id'       => '42',
            'phone'          => ' 01700000001 ',
            'total'          => '1500.50',
            'total_quantity' => '-3',
            'country'        => ' bd ',
            'currency'       => 'bdt',
            'phone_verified' => 1,
            'unknown_key'    => 'ignored',
        ]);

        self::assertSame(42, $order->orderId);
        self::assertSame('01700000001', $order->phone);
        self::assertSame(1500.5, $order->total);
        self::assertSame(0, $order->totalQuantity);
        self::assertSame('BD', $order->country);
        self::assertSame('BDT', $order->currency);
        self::assertTrue($order->phoneVerified);
        self::assertArrayNotHasKey('unknown_key', $order->toArray());
    }

    public function testContextAcceptsDifferentDateInputs(): void
    {
        $utc = new DateTimeZone('UTC');

        $fromTimestamp = OrderContext::fromArray(['created_at' => 1741595400]);
        $fromString    = OrderContext::fromArray(['created_at' => '2025-03-10 08:30:00']);
        $fromMutable   = OrderContext::fromArray(['created_at' => new DateTime('2025-03-10 08:30:00', $utc)]);
        $fromImmutable = OrderContext::fromArray(['created_at' => new DateTimeImmutable('2025-03-10 08:30:00', $utc)]);

        foreach ([$fromTimestamp, $fromString, $fromMutable, $fromImmutable] as $order) {
            self::assertSame('2025-03-10 08:30', $order->createdAt->format('Y-m-d H:i'));
        }
    }

    public function testContextFallsBackToNowForBadDates(): void
    {
        $order = OrderContext::fromArray(['created_at' => 'not a date at all']);

        self::assertLessThan(60, abs(time() - $order->createdAt->getTimestamp()));
    }

    public function testContextWithReturnsModifiedCopy(): void
    {
        $order = OrderContext::fromArray(['phone' => '01700000001', 'total' => 100]);
        $copy  = $order->with(['total' => 250, 'phone_verified' => true]);

        self::assertSame(100.0, $order->total);
        self::assertSame(250.0, $copy->total);
        self::assertTrue($copy->phoneVerified);
        self::assertSame('01700000001', $copy->phone);
        self::assertEquals($order->createdAt, $copy->createdAt);
    }

    public function testContextFullName(): void
    {
        self::assertSame('Rahim Uddin', OrderContext::fromArray(['first_name' => ' Rahim', 'last_name' => 'Uddin '])->fullName());
        self::assertSame('Rahim', OrderContext::fromArray(['first_name' => 'Rahim'])->fullName());
        self::assertSame('', OrderContext::fromArray([])->fullName());
    }

    public function testContextRoundTrip(): void
    {
        $order = OrderContext::fromArray(['order_id' => 7, 'meta' => ['source' => 'app'], 'city' => 'Dhaka']);

        self::assertEquals($order, OrderContext::fromArray($order->toArray()));
    }

    // --- RiskLevel -------------------------------------------------------

    /**
     * @return array<string, array{int, string}>
     */
    public static function scores(): array
    {
        return [
            'zero'          => [0, RiskLevel::LOW],
            'below medium'  => [39, RiskLevel::LOW],
            'medium edge'   => [40, RiskLevel::MEDIUM],
            'below high'    => [69, RiskLevel::MEDIUM],
            'high edge'     => [70, RiskLevel::HIGH],
            'max'           => [100, RiskLevel::HIGH],
        ];
    }

    #[DataProvider('scores')]
    public function testLevelFromScore(int $score, string $level): void
    {
        self::assertSame($level, RiskLevel::fromScore($score));
    }

    public function testLevelFromScoreWithCustomThresholds(): void
    {
        self::assertSame(RiskLevel::HIGH, RiskLevel::fromScore(50, 20, 50));
        self::assertSame(RiskLevel::MEDIUM, RiskLevel::fromScore(20, 20, 50));
        self::assertSame(RiskLevel::LOW, RiskLevel::fromScore(19, 20, 50));
    }

    public function testLevelHelpers(): void
    {
        self::assertSame(['low', 'medium', 'high'], RiskLevel::all());
        self::assertTrue(RiskLevel::isValid('medium'));
        self::assertFalse(RiskLevel::isValid('extreme'));
        self::assertSame(2, RiskLevel::rank('high'));
        self::assertSame(0, RiskLevel::rank('bogus'));
        self::assertTrue(RiskLevel::atLeast('high', 'medium'));
        self::assertTrue(RiskLevel::atLeast('medium', 'medium'));
        self::assertFalse(RiskLevel::atLeast('low', 'medium'));
    }

    // --- RuleResult ------------------------------------------------------

    public function testPassResult(): void
    {
        $result = RuleResult::pass('demo', 12.0);

        self::assertFalse($result->triggered);
        self::assertSame(0.0, $result->points);
        self::assertSame(12.0, $result->weight);
        self::assertFalse($result->isTrustSignal());
    }

    public function testHitComputesPointsAndReason(): void
    {
        $result = RuleResult::hit('demo', 0.5, 30.0, 'demo.code', '%1$d of %2$d orders', [3, 4]);

        self::assertTrue($result->triggered);
        self::assertSame(15.0, $result->points);
        self::assertSame('3 of 4 orders', $result->reason);
        self::assertSame([3, 4], $result->reasonArgs);
    }

    public function testHitWithoutArgsKeepsPercentSigns(): void
    {
        $result = RuleResult::hit('demo', 1.0, 10.0, 'demo.code', '100% sure');

        self::assertSame('100% sure', $result->reason);
    }

    public function testSeverityIsClamped(): void
    {
        self::assertSame(1.0, RuleResult::hit('demo', 7.0, 10.0, 'c', 't')->severity);
        self::assertSame(-1.0, RuleResult::hit('demo', -7.0, 10.0, 'c', 't')->severity);
    }

    public function testNonTriggeredResultHasNoSeverity(): void
    {
        $result = new RuleResult('demo', false, 0.9, 10.0);

        self::assertSame(0.0, $result->severity);
        self::assertSame(0.0, $result->points);
    }

    public function testNegativeWeightIsTreatedAsZero(): void
    {
        self::assertSame(0.0, RuleResult::hit('demo', 1.0, -5.0, 'c', 't')->points);
    }

    public function testUnknownOverrideIsDropped(): void
    {
        self::assertNull(RuleResult::hit('demo', 1.0, 1.0, 'c', 't', [], 'explode')->override);
        self::assertSame('block', RuleResult::hit('demo', 1.0, 1.0, 'c', 't', [], 'block')->override);
    }

    public function testTrustSignal(): void
    {
        self::assertTrue(RuleResult::hit('demo', -0.5, 10.0, 'c', 't')->isTrustSignal());
        self::assertFalse(RuleResult::hit('demo', 0.5, 10.0, 'c', 't')->isTrustSignal());
        self::assertTrue(RuleResult::hit('demo', 0.0, 0.0, 'c', 't', [], RuleResult::OVERRIDE_ALLOW)->isTrustSignal());
    }

    public function testRuleResultRoundTrip(): void
    {
        $result = RuleResult::hit('demo', 0.75, 20.0, 'demo.code', 'Reason %s', ['x'], 'block', ['k' => 'v']);

        self::assertEquals($result, RuleResult::fromArray($result->toArray()));
    }

    public function testRuleResultFromMalformedArray(): void
    {
        $result = RuleResult::fromArray(['rule' => 'demo', 'args' => [[1], 'ok'], 'data' => 'nope', 'override' => 5]);

        self::assertSame('demo', $result->ruleId);
        self::assertFalse($result->triggered);
        self::assertSame(['ok'], $result->reasonArgs);
        self::assertSame([], $result->data);
        self::assertNull($result->override);
    }

    // --- RiskResult ------------------------------------------------------

    private function sampleResult(): RiskResult
    {
        return new RiskResult(
            55,
            RiskLevel::MEDIUM,
            [
                RuleResult::pass('quiet', 10.0),
                RuleResult::hit('small', 0.5, 10.0, 'small.code', 'Small reason'),
                RuleResult::hit('big', 1.0, 40.0, 'big.code', 'Big reason'),
                RuleResult::hit('trust', -1.0, 20.0, 'trust.code', 'Trust reason'),
            ],
            25.0,
            null,
            new DateTimeImmutable('2025-03-10 08:30:00', new DateTimeZone('UTC'))
        );
    }

    public function testTriggeredIsSortedByContribution(): void
    {
        self::assertSame(['big', 'trust', 'small'], $this->sampleResult()->triggeredRuleIds());
    }

    public function testOverridesSortFirst(): void
    {
        $result = new RiskResult(100, RiskLevel::HIGH, [
            RuleResult::hit('big', 1.0, 90.0, 'big.code', 'Big'),
            RuleResult::hit('list', 0.0, 0.0, 'list.code', 'Listed', [], RuleResult::OVERRIDE_ALLOW),
        ]);

        self::assertSame(['list', 'big'], $result->triggeredRuleIds());
    }

    public function testReasons(): void
    {
        self::assertSame(['Big reason', 'Trust reason', 'Small reason'], $this->sampleResult()->reasons());
    }

    public function testGetAndHasTriggered(): void
    {
        $result = $this->sampleResult();

        self::assertNotNull($result->get('quiet'));
        self::assertNull($result->get('missing'));
        self::assertTrue($result->hasTriggered('big'));
        self::assertFalse($result->hasTriggered('quiet'));
        self::assertFalse($result->hasTriggered('missing'));
        self::assertFalse($result->isHigh());
    }

    public function testScoreAndLevelAreNormalised(): void
    {
        $result = new RiskResult(250, 'nonsense', []);

        self::assertSame(100, $result->score);
        self::assertSame(RiskLevel::LOW, $result->level);
        self::assertSame(0, (new RiskResult(-5, RiskLevel::LOW, []))->score);
    }

    public function testToArrayStoresOnlyTriggeredRules(): void
    {
        $data = $this->sampleResult()->toArray();

        self::assertSame(55, $data['score']);
        self::assertSame('medium', $data['level']);
        self::assertSame('2025-03-10T08:30:00+00:00', $data['evaluated_at']);
        self::assertCount(3, $data['rules']);
        self::assertSame('big', $data['rules'][0]['rule']);
    }

    public function testRiskResultRoundTripThroughJson(): void
    {
        $original = $this->sampleResult();
        $restored = RiskResult::fromArray(json_decode((string) json_encode($original->toArray()), true));

        self::assertSame($original->score, $restored->score);
        self::assertSame($original->level, $restored->level);
        self::assertSame($original->reasons(), $restored->reasons());
        self::assertEquals($original->evaluatedAt, $restored->evaluatedAt);
        self::assertSame(25.0, $restored->rawScore);
    }

    public function testRiskResultFromMalformedArray(): void
    {
        $result = RiskResult::fromArray(['score' => 'abc', 'rules' => ['x', ['rule' => 'ok', 'triggered' => true]], 'evaluated_at' => '???']);

        self::assertSame(0, $result->score);
        self::assertSame(RiskLevel::LOW, $result->level);
        self::assertSame(['ok'], $result->triggeredRuleIds());
    }

    // --- RuleConfig ------------------------------------------------------

    public function testRuleConfigTypedAccessors(): void
    {
        $config = new RuleConfig(true, 10.0, [
            'count'  => '7',
            'ratio'  => '0.25',
            'flag'   => 'yes',
            'off'    => '0',
            'name'   => 'abc',
            'list'   => ['a'],
            'broken' => 'x',
        ]);

        self::assertSame(7, $config->int('count'));
        self::assertSame(3, $config->int('broken', 3));
        self::assertSame(9, $config->int('missing', 9));
        self::assertSame(0.25, $config->float('ratio'));
        self::assertSame(1.5, $config->float('broken', 1.5));
        self::assertTrue($config->bool('flag'));
        self::assertFalse($config->bool('off'));
        self::assertTrue($config->bool('missing', true));
        self::assertSame('abc', $config->string('name'));
        self::assertSame('dflt', $config->string('list', 'dflt'));
        self::assertSame(['a'], $config->arr('list'));
        self::assertSame(['z'], $config->arr('name', ['z']));
        self::assertTrue($config->has('off'));
        self::assertFalse($config->has('missing'));
    }

    public function testRuleConfigWeightIsClamped(): void
    {
        self::assertSame(100.0, (new RuleConfig(true, 500.0))->weight);
        self::assertSame(0.0, (new RuleConfig(true, -3.0))->weight);
    }

    public function testRuleConfigIsImmutable(): void
    {
        $config = new RuleConfig(true, 10.0, ['a' => 1]);
        $other  = $config->withWeight(20.0)->withOptions(['b' => 2]);

        self::assertSame(10.0, $config->weight);
        self::assertSame(['a' => 1], $config->options());
        self::assertSame(['enabled' => true, 'weight' => 20.0, 'options' => ['a' => 1, 'b' => 2]], $other->toArray());
    }
}
