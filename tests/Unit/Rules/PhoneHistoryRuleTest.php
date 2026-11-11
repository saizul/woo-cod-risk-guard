<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Rules;

use Saizul\CodRiskGuard\Rules\FirstTimeHighValueRule;
use Saizul\CodRiskGuard\Rules\PhoneHistoryRule;
use Saizul\CodRiskGuard\Tests\EngineTestCase;

final class PhoneHistoryRuleTest extends EngineTestCase
{
    public function testNoHistoryPasses(): void
    {
        self::assertFalse($this->runRule(new PhoneHistoryRule(), $this->order())->triggered);
    }

    public function testMissingPhonePasses(): void
    {
        self::assertFalse($this->runRule(new PhoneHistoryRule(), $this->order(['phone' => '']))->triggered);
    }

    public function testPendingOnlyHistoryPasses(): void
    {
        $this->history->set(
            $this->hasher->hashPhone(self::PHONE),
            new \Saizul\CodRiskGuard\History\CustomerHistory(3, 0, 0, 0)
        );

        self::assertFalse($this->runRule(new PhoneHistoryRule(), $this->order())->triggered);
    }

    public function testSingleFailedOrderIsAnEarlyFailure(): void
    {
        $this->giveHistory(0, 1);

        $result = $this->runRule(new PhoneHistoryRule(), $this->order());

        self::assertSame('phone_history.early_failure', $result->reasonCode);
        self::assertSame(0.5, $result->severity);
        self::assertSame(17.5, $result->points);
    }

    public function testSingleDeliveredOrderIsNeutral(): void
    {
        $this->giveHistory(1);

        self::assertFalse($this->runRule(new PhoneHistoryRule(), $this->order())->triggered);
    }

    public function testAllFailedGivesFullSeverity(): void
    {
        $this->giveHistory(0, 3, 1);

        $result = $this->runRule(new PhoneHistoryRule(), $this->order());

        self::assertSame('phone_history.bad_ratio', $result->reasonCode);
        self::assertSame(1.0, $result->severity);
        self::assertSame(35.0, $result->points);
        self::assertSame('4 of 4 earlier orders from this phone were returned or cancelled (100%).', $result->reason);
    }

    public function testRatioAtThresholdGivesMinimumSeverity(): void
    {
        $this->giveHistory(7, 3);

        $result = $this->runRule(new PhoneHistoryRule(), $this->order());

        self::assertSame('phone_history.bad_ratio', $result->reasonCode);
        self::assertEqualsWithDelta(0.5, $result->severity, 0.0001);
    }

    public function testSeverityScalesWithRatio(): void
    {
        $this->giveHistory(1, 1);
        $half = $this->runRule(new PhoneHistoryRule(), $this->order());

        $this->giveHistory(1, 3);
        $threeQuarters = $this->runRule(new PhoneHistoryRule(), $this->order());

        self::assertGreaterThan($half->severity, $threeQuarters->severity);
        self::assertLessThan(1.0, $threeQuarters->severity);
    }

    public function testRatioBelowThresholdPasses(): void
    {
        $this->giveHistory(2, 0, 0);
        self::assertFalse($this->runRule(new PhoneHistoryRule(), $this->order())->triggered);
    }

    public function testCancelledOrdersCountAsFailures(): void
    {
        $this->giveHistory(1, 0, 2);

        $result = $this->runRule(new PhoneHistoryRule(), $this->order());

        self::assertSame('phone_history.bad_ratio', $result->reasonCode);
        self::assertSame(2, $result->data['cancelled']);
    }

    public function testTrustedCustomerGetsABonus(): void
    {
        $this->giveHistory(5);

        $result = $this->runRule(new PhoneHistoryRule(), $this->order());

        self::assertSame('phone_history.trusted', $result->reasonCode);
        self::assertEqualsWithDelta(-14.0, $result->points, 0.001);
        self::assertTrue($result->isTrustSignal());
    }

    public function testTrustedCustomerWithFewFailuresStillGetsBonus(): void
    {
        $this->giveHistory(9, 1);

        self::assertSame('phone_history.trusted', $this->runRule(new PhoneHistoryRule(), $this->order())->reasonCode);
    }

    public function testTrustBonusCanBeDisabled(): void
    {
        $this->giveHistory(5);

        self::assertFalse($this->runRule(new PhoneHistoryRule(), $this->order(), ['trust_bonus' => 0])->triggered);
    }

    public function testTrustThresholdIsConfigurable(): void
    {
        $this->giveHistory(2);

        self::assertFalse($this->runRule(new PhoneHistoryRule(), $this->order())->triggered);
        self::assertTrue($this->runRule(new PhoneHistoryRule(), $this->order(), ['trust_min_delivered' => 2])->triggered);
    }

    public function testBadRatioThresholdIsConfigurable(): void
    {
        $this->giveHistory(4, 1);

        self::assertSame('phone_history.trusted', $this->runRule(new PhoneHistoryRule(), $this->order())->reasonCode);
        self::assertSame(
            'phone_history.bad_ratio',
            $this->runRule(new PhoneHistoryRule(), $this->order(), ['bad_ratio' => 0.2])->reasonCode
        );
    }

    public function testMinOrdersIsConfigurable(): void
    {
        $this->giveHistory(1, 2);

        $result = $this->runRule(new PhoneHistoryRule(), $this->order(), ['min_orders' => 5]);

        self::assertFalse($result->triggered, 'A mixed history below min_orders is not judged yet.');
    }

    public function testHistoryIsLookedUpByNormalisedPhone(): void
    {
        $this->giveHistory(0, 4);

        $result = $this->runRule(new PhoneHistoryRule(), $this->order(['phone' => '+880 1700 000001']));

        self::assertTrue($result->triggered);
    }

    public function testOtherPhonesHistoryIsNotUsed(): void
    {
        $this->giveHistory(0, 4, 0, '01800000002');

        self::assertFalse($this->runRule(new PhoneHistoryRule(), $this->order())->triggered);
    }

    public function testFirstTimeBuyerBelowAmountPasses(): void
    {
        self::assertFalse($this->runRule(new FirstTimeHighValueRule(), $this->order(['total' => 4999]))->triggered);
    }

    public function testFirstTimeBuyerAtAmountTriggersWithMinimumSeverity(): void
    {
        $result = $this->runRule(new FirstTimeHighValueRule(), $this->order(['total' => 5000]));

        self::assertSame('first_time_high_value.match', $result->reasonCode);
        self::assertSame(0.5, $result->severity);
        self::assertSame(7.5, $result->points);
    }

    public function testFirstTimeBuyerSeverityScalesUpToDoubleAmount(): void
    {
        $mid = $this->runRule(new FirstTimeHighValueRule(), $this->order(['total' => 7500]));
        $top = $this->runRule(new FirstTimeHighValueRule(), $this->order(['total' => 10000]));
        $over = $this->runRule(new FirstTimeHighValueRule(), $this->order(['total' => 90000]));

        self::assertEqualsWithDelta(0.75, $mid->severity, 0.0001);
        self::assertSame(1.0, $top->severity);
        self::assertSame(1.0, $over->severity);
    }

    public function testReturningCustomerWithDeliveredOrderIsNotFirstTime(): void
    {
        $this->giveHistory(1);

        self::assertFalse($this->runRule(new FirstTimeHighValueRule(), $this->order(['total' => 20000]))->triggered);
    }

    public function testCustomerWithOnlyFailedOrdersStillCountsAsFirstTime(): void
    {
        $this->giveHistory(0, 2);

        self::assertTrue($this->runRule(new FirstTimeHighValueRule(), $this->order(['total' => 20000]))->triggered);
    }

    public function testFirstTimeAmountIsConfigurable(): void
    {
        $order = $this->order(['total' => 2500]);

        self::assertFalse($this->runRule(new FirstTimeHighValueRule(), $order)->triggered);
        self::assertTrue($this->runRule(new FirstTimeHighValueRule(), $order, ['amount' => 2000])->triggered);
    }
}
