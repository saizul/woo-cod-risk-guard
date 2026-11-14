<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Engine;

use DateTimeImmutable;
use DateTimeZone;
use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\Engine\RuleRegistry;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\Tests\EngineTestCase;
use Saizul\CodRiskGuard\Tests\Stubs\FixedRule;

final class RiskEngineTest extends EngineTestCase
{
    private function registryOf(FixedRule ...$rules): RuleRegistry
    {
        $registry = new RuleRegistry();
        foreach ($rules as $rule) {
            $registry->register($rule);
        }

        return $registry;
    }

    public function testCleanOrderScoresZero(): void
    {
        $result = $this->engine()->evaluate($this->order());

        self::assertSame(0, $result->score);
        self::assertSame(RiskLevel::LOW, $result->level);
        self::assertSame([], $result->reasons());
        self::assertNull($result->override);
        self::assertCount(16, $result->results);
    }

    public function testPointsAreSummed(): void
    {
        $registry = $this->registryOf(new FixedRule('rule_a', 1.0, 20.0), new FixedRule('rule_b', 0.5, 30.0));

        $result = $this->engine([], $registry)->evaluate($this->order());

        self::assertSame(35, $result->score);
        self::assertSame(35.0, $result->rawScore);
        self::assertSame(RiskLevel::LOW, $result->level);
    }

    public function testScoreIsClampedAtHundred(): void
    {
        $registry = $this->registryOf(new FixedRule('rule_a', 1.0, 80.0), new FixedRule('rule_b', 1.0, 70.0));

        $result = $this->engine([], $registry)->evaluate($this->order());

        self::assertSame(100, $result->score);
        self::assertSame(150.0, $result->rawScore);
        self::assertTrue($result->isHigh());
    }

    public function testScoreIsClampedAtZero(): void
    {
        $registry = $this->registryOf(new FixedRule('rule_a', -1.0, 30.0));

        $result = $this->engine([], $registry)->evaluate($this->order());

        self::assertSame(0, $result->score);
        self::assertSame(-30.0, $result->rawScore);
    }

    public function testTrustSignalsOffsetRisk(): void
    {
        $registry = $this->registryOf(new FixedRule('rule_a', 1.0, 60.0), new FixedRule('rule_b', -1.0, 25.0));

        self::assertSame(35, $this->engine([], $registry)->evaluate($this->order())->score);
    }

    public function testDisabledRulesAreSkipped(): void
    {
        $registry = $this->registryOf(new FixedRule('rule_a', 1.0, 60.0), new FixedRule('rule_b', 1.0, 25.0));
        $engine   = $this->engine(['rules' => ['rule_a' => ['enabled' => false]]], $registry);

        $result = $engine->evaluate($this->order());

        self::assertSame(25, $result->score);
        self::assertNull($result->get('rule_a'));
    }

    public function testConfiguredWeightOverridesDefault(): void
    {
        $registry = $this->registryOf(new FixedRule('rule_a', 1.0, 60.0));
        $engine   = $this->engine(['rules' => ['rule_a' => ['weight' => 10]]], $registry);

        self::assertSame(10, $engine->evaluate($this->order())->score);
    }

    public function testThresholdsDecideTheLevel(): void
    {
        $registry = $this->registryOf(new FixedRule('rule_a', 1.0, 45.0));

        self::assertSame(RiskLevel::MEDIUM, $this->engine([], $registry)->evaluate($this->order())->level);
        self::assertSame(
            RiskLevel::HIGH,
            $this->engine(['thresholds' => ['medium' => 20, 'high' => 45]], $registry)->evaluate($this->order())->level
        );
        self::assertSame(
            RiskLevel::LOW,
            $this->engine(['thresholds' => ['medium' => 50, 'high' => 90]], $registry)->evaluate($this->order())->level
        );
    }

    public function testBrokenRuleDoesNotBreakScoring(): void
    {
        $registry = $this->registryOf(new FixedRule('rule_a', 1.0, 20.0), new FixedRule('rule_bad', 1.0, 50.0, true));

        $result = $this->engine([], $registry)->evaluate($this->order());

        self::assertSame(20, $result->score);
        self::assertFalse($result->hasTriggered('rule_bad'));
        self::assertSame('rule_bad.error', $result->get('rule_bad')->reasonCode);
    }

    public function testBlockOverrideForcesMaximumScore(): void
    {
        $registry = $this->registryOf(new FixedRule('rule_block', 0.0, 0.0, false, RuleResult::OVERRIDE_BLOCK));

        $result = $this->engine([], $registry)->evaluate($this->order());

        self::assertSame(100, $result->score);
        self::assertSame(RiskLevel::HIGH, $result->level);
        self::assertSame(RuleResult::OVERRIDE_BLOCK, $result->override);
    }

    public function testAllowOverrideForcesZero(): void
    {
        $registry = $this->registryOf(
            new FixedRule('rule_a', 1.0, 90.0),
            new FixedRule('rule_allow', 0.0, 0.0, false, RuleResult::OVERRIDE_ALLOW)
        );

        $result = $this->engine([], $registry)->evaluate($this->order());

        self::assertSame(0, $result->score);
        self::assertSame(RiskLevel::LOW, $result->level);
        self::assertSame(RuleResult::OVERRIDE_ALLOW, $result->override);
        self::assertSame(90.0, $result->rawScore);
    }

    public function testBlockWinsOverAllow(): void
    {
        $this->lists->add(ListStoreInterface::BLOCK, IdentifierHasher::TYPE_IP, $this->hasher->hashIp('203.0.113.10'));
        $this->lists->add(ListStoreInterface::ALLOW, IdentifierHasher::TYPE_PHONE, $this->hasher->hashPhone(self::PHONE));

        $result = $this->engine()->evaluate($this->order());

        self::assertSame(100, $result->score);
        self::assertSame(RuleResult::OVERRIDE_BLOCK, $result->override);
    }

    public function testBlocklistedCustomerIsHighRisk(): void
    {
        $this->lists->add(ListStoreInterface::BLOCK, IdentifierHasher::TYPE_PHONE, $this->hasher->hashPhone(self::PHONE));

        $result = $this->engine()->evaluate($this->order());

        self::assertTrue($result->isHigh());
        self::assertSame('blocklist', $result->triggeredRuleIds()[0]);
        self::assertStringContainsString('blocklist', $result->reasons()[0]);
    }

    public function testAllowlistedCustomerIsLowRiskDespiteSignals(): void
    {
        $this->lists->add(ListStoreInterface::ALLOW, IdentifierHasher::TYPE_PHONE, $this->hasher->hashPhone(self::PHONE));
        $this->giveHistory(0, 5);

        $result = $this->engine()->evaluate($this->order(['address_1' => 'test', 'total' => 50000]));

        self::assertSame(0, $result->score);
        self::assertTrue($result->hasTriggered('phone_history'));
        self::assertGreaterThan(0.0, $result->rawScore);
    }

    public function testDisablingBlocklistRuleDisablesTheOverride(): void
    {
        $this->lists->add(ListStoreInterface::BLOCK, IdentifierHasher::TYPE_PHONE, $this->hasher->hashPhone(self::PHONE));

        $result = $this->engine(['rules' => ['blocklist' => ['enabled' => false]]])->evaluate($this->order());

        self::assertSame(0, $result->score);
    }

    // --- End-to-end scenarios with the bundled rules ---------------------

    public function testScenarioSerialReturner(): void
    {
        $this->giveHistory(1, 4);

        $result = $this->engine()->evaluate($this->order());

        // 4/5 failed => severity scale(0.8, 0.3, 1.0, 0.5) ~= 0.857 x 35 = 30.
        self::assertSame(30, $result->score);
        self::assertSame(RiskLevel::LOW, $result->level);
        self::assertSame(['phone_history'], $result->triggeredRuleIds());
    }

    public function testScenarioFakeOrder(): void
    {
        $now = new DateTimeImmutable('2025-03-09 20:15:00', new DateTimeZone('UTC'));
        $this->recordEvents(IdentifierHasher::TYPE_IP, '203.0.113.10', 4, $now);

        $result = $this->engine()->evaluate($this->order([
            'phone'             => '01711111111',
            'email'             => 'x@mailinator.com',
            'first_name'        => 'asdfgh',
            'last_name'         => '',
            'address_1'         => 'test',
            'total'             => 12000,
            'max_line_quantity' => 12,
            'total_quantity'    => 12,
            'created_at'        => $now,
        ]));

        // 16 + 15 + 15 + 13.33 + 10 + 10 + 8 + 5 = 92.33.
        self::assertSame(92, $result->score);
        self::assertTrue($result->isHigh());
        foreach (['phone_validity', 'disposable_email', 'customer_name', 'address_quality', 'first_time_high_value', 'quantity_anomaly', 'night_order', 'ip_velocity'] as $rule) {
            self::assertTrue($result->hasTriggered($rule), $rule . ' should have triggered');
        }
        self::assertGreaterThanOrEqual(8, count($result->reasons()));
    }

    public function testScenarioFirstTimeHighValueNightOrderIsMedium(): void
    {
        $result = $this->engine()->evaluate($this->order([
            'total'      => 15000,
            'address_1'  => 'Mirpur',
            'email'      => 'x@yopmail.com',
            'created_at' => new DateTimeImmutable('2025-03-09 20:15:00', new DateTimeZone('UTC')),
        ]));

        // 15 (first time) + 12 (too short) + 10 (email) + 5 (night) = 42.
        self::assertSame(42, $result->score);
        self::assertSame(RiskLevel::MEDIUM, $result->level);
    }

    public function testScenarioLoyalCustomerOffsetsMinorSignals(): void
    {
        $this->giveHistory(12);

        $result = $this->engine()->evaluate($this->order([
            'total'      => 15000,
            'created_at' => new DateTimeImmutable('2025-03-09 20:15:00', new DateTimeZone('UTC')),
        ]));

        // +5 night, -14 trusted history => clamped to 0.
        self::assertSame(0, $result->score);
        self::assertSame(-9.0, $result->rawScore);
        self::assertFalse($result->hasTriggered('first_time_high_value'));
    }

    public function testScenarioOtpVerificationLowersScore(): void
    {
        $order = $this->order(['total' => 12000, 'address_1' => 'test', 'email' => 'x@mailinator.com']);

        $before = $this->engine()->evaluate($order);
        $after  = $this->engine()->evaluate($order->with(['phone_verified' => true]));

        self::assertSame($before->score - 20, $after->score);
        self::assertTrue($after->hasTriggered('otp_verified'));
    }

    public function testScenarioInvalidPhoneAndBadAddress(): void
    {
        $result = $this->engine()->evaluate($this->order(['phone' => '12345', 'address_1' => '']));

        // 25 (invalid phone) + 20 (empty address).
        self::assertSame(45, $result->score);
        self::assertSame(RiskLevel::MEDIUM, $result->level);
    }

    public function testEvaluationIsDeterministic(): void
    {
        $this->giveHistory(2, 3);
        $order = $this->order(['total' => 9000, 'address_1' => 'Road 5']);

        $first  = $this->engine()->evaluate($order);
        $second = $this->engine()->evaluate($order);

        self::assertSame($first->score, $second->score);
        self::assertSame($first->triggeredRuleIds(), $second->triggeredRuleIds());
    }

    public function testEngineExposesItsCollaborators(): void
    {
        $engine = $this->engine();

        self::assertSame(16, $engine->registry()->count());
        self::assertSame(40, $engine->config()->mediumThreshold);
        self::assertSame($this->signals, $engine->signals());
    }

    public function testSignalsIdentifierHashes(): void
    {
        $hashes = $this->signals->identifierHashes($this->order(['email' => '', 'ip' => 'bad']));

        self::assertSame(['phone', 'device'], array_keys($hashes));
        self::assertSame($this->hasher->hashPhone(self::PHONE), $hashes['phone']);
    }

    public function testSignalsWorkWithoutProviders(): void
    {
        $signals = new \Saizul\CodRiskGuard\Engine\Signals($this->hasher);
        $engine  = new \Saizul\CodRiskGuard\Engine\RiskEngine(
            RuleRegistry::withDefaults(),
            \Saizul\CodRiskGuard\Engine\EngineConfig::defaults(RuleRegistry::withDefaults()),
            $signals
        );

        self::assertSame(0, $engine->evaluate($this->order())->score);
        self::assertSame('Asia/Dhaka', $signals->timezone->getName());
    }
}
