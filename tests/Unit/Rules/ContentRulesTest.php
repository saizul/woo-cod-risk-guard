<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Rules;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Saizul\CodRiskGuard\Rules\AddressQualityRule;
use Saizul\CodRiskGuard\Rules\CustomerNameRule;
use Saizul\CodRiskGuard\Rules\DisposableEmailRule;
use Saizul\CodRiskGuard\Rules\DistrictReturnRateRule;
use Saizul\CodRiskGuard\Rules\NightOrderRule;
use Saizul\CodRiskGuard\Rules\QuantityAnomalyRule;
use Saizul\CodRiskGuard\Tests\EngineTestCase;

final class ContentRulesTest extends EngineTestCase
{
    // --- Address quality -------------------------------------------------

    public function testGoodAddressPasses(): void
    {
        self::assertFalse($this->runRule(new AddressQualityRule(), $this->order())->triggered);
    }

    public function testEmptyAddressGetsFullWeight(): void
    {
        $result = $this->runRule(new AddressQualityRule(), $this->order(['address_1' => '']));

        self::assertSame('address_quality.issues', $result->reasonCode);
        self::assertSame(20.0, $result->points);
        self::assertContains('empty', $result->data['issues']);
    }

    public function testShortAddressIsPartiallyPenalised(): void
    {
        $result = $this->runRule(new AddressQualityRule(), $this->order(['address_1' => 'Road 5']));

        self::assertTrue($result->triggered);
        self::assertGreaterThan(0.0, $result->points);
        self::assertLessThan(20.0, $result->points);
        self::assertStringContainsString('too short', $result->reason);
    }

    public function testMinLengthOptionIsUsed(): void
    {
        $order = $this->order(['address_1' => 'Road 5']);

        self::assertFalse($this->runRule(new AddressQualityRule(), $order, ['min_length' => 5])->triggered);
    }

    public function testGibberishAddress(): void
    {
        $result = $this->runRule(new AddressQualityRule(), $this->order(['address_1' => 'asdfgh qwerty zxcvb']));

        self::assertContains('gibberish', $result->data['issues']);
    }

    public function testSecondAddressLineIsConsidered(): void
    {
        $order = $this->order(['address_1' => 'Road 5', 'address_2' => 'Sector 7, Uttara']);

        self::assertFalse($this->runRule(new AddressQualityRule(), $order)->triggered);
    }

    // --- Customer name ---------------------------------------------------

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function names(): array
    {
        return [
            'normal'         => ['Rahim', 'Uddin', null],
            'bengali'        => ['রহিম', 'উদ্দিন', null],
            'single name'    => ['Shahidul', '', null],
            'md prefix'      => ['Md.', 'Karim', null],
            'missing'        => ['', '', 'customer_name.missing'],
            'only spaces'    => ['  ', ' ', 'customer_name.missing'],
            'too short'      => ['A', 'B', 'customer_name.too_short'],
            'punctuation'    => ['..', '--', 'customer_name.too_short'],
            'gibberish'      => ['asdfgh', 'qwerty', 'customer_name.gibberish'],
            'repeated'       => ['aaaaaa', 'bbbbbb', 'customer_name.gibberish'],
            'digits'         => ['Rahim', '12345', 'customer_name.digits'],
            'bangla digits'  => ['রহিম', '১২৩', 'customer_name.digits'],
        ];
    }

    #[DataProvider('names')]
    public function testCustomerName(string $first, string $last, ?string $code): void
    {
        $result = $this->runRule(new CustomerNameRule(), $this->order(['first_name' => $first, 'last_name' => $last]));

        if (null === $code) {
            self::assertFalse($result->triggered, 'Unexpected: ' . $result->reasonCode);

            return;
        }

        self::assertSame($code, $result->reasonCode);
        self::assertGreaterThan(0.0, $result->points);
    }

    // --- Disposable email ------------------------------------------------

    public function testOrdinaryEmailPasses(): void
    {
        self::assertFalse($this->runRule(new DisposableEmailRule(), $this->order())->triggered);
    }

    public function testEmptyEmailPasses(): void
    {
        // Many Bangladeshi stores make the email field optional.
        self::assertFalse($this->runRule(new DisposableEmailRule(), $this->order(['email' => '']))->triggered);
    }

    public function testInvalidEmail(): void
    {
        $result = $this->runRule(new DisposableEmailRule(), $this->order(['email' => 'not-an-email']));

        self::assertSame('disposable_email.invalid', $result->reasonCode);
        self::assertSame(5.0, $result->points);
    }

    public function testDisposableEmail(): void
    {
        $result = $this->runRule(new DisposableEmailRule(), $this->order(['email' => 'buyer@mailinator.com']));

        self::assertSame('disposable_email.disposable', $result->reasonCode);
        self::assertSame(10.0, $result->points);
        self::assertStringContainsString('mailinator.com', $result->reason);
    }

    public function testExtraDisposableDomains(): void
    {
        $order = $this->order(['email' => 'buyer@junk.example']);

        self::assertFalse($this->runRule(new DisposableEmailRule(), $order)->triggered);
        self::assertTrue(
            $this->runRule(new DisposableEmailRule(), $order, ['extra_domains' => ['junk.example']])->triggered
        );
    }

    // --- Quantity anomaly ------------------------------------------------

    public function testNormalQuantitiesPass(): void
    {
        self::assertFalse($this->runRule(new QuantityAnomalyRule(), $this->order())->triggered);
    }

    public function testQuantitiesAtTheLimitPass(): void
    {
        $order = $this->order(['max_line_quantity' => 5, 'total_quantity' => 10]);

        self::assertFalse($this->runRule(new QuantityAnomalyRule(), $order)->triggered);
    }

    public function testLargeLineQuantity(): void
    {
        $result = $this->runRule(new QuantityAnomalyRule(), $this->order(['max_line_quantity' => 6, 'total_quantity' => 6]));

        self::assertSame('quantity_anomaly.line', $result->reasonCode);
        self::assertEqualsWithDelta(0.6, $result->severity, 0.0001);
        self::assertSame('Unusually large quantity of a single product (6, limit 5).', $result->reason);
    }

    public function testLargeTotalQuantity(): void
    {
        $result = $this->runRule(new QuantityAnomalyRule(), $this->order(['max_line_quantity' => 3, 'total_quantity' => 15]));

        self::assertSame('quantity_anomaly.total', $result->reasonCode);
        self::assertEqualsWithDelta(0.75, $result->severity, 0.0001);
    }

    public function testHugeQuantityGetsFullSeverity(): void
    {
        $result = $this->runRule(new QuantityAnomalyRule(), $this->order(['max_line_quantity' => 50, 'total_quantity' => 50]));

        self::assertSame(1.0, $result->severity);
        self::assertSame(10.0, $result->points);
    }

    public function testQuantityLimitsAreConfigurable(): void
    {
        $order = $this->order(['max_line_quantity' => 8, 'total_quantity' => 8]);

        $result = $this->runRule(new QuantityAnomalyRule(), $order, ['max_line_quantity' => 20, 'max_total_quantity' => 40]);

        self::assertFalse($result->triggered);
    }

    // --- Night orders ----------------------------------------------------

    /**
     * @return array<string, array{string, int, int, bool}>
     */
    public static function nightHours(): array
    {
        // Times are UTC; Dhaka is UTC+6.
        return [
            'afternoon'                 => ['2025-03-10 08:30:00', 0, 5, false],
            '02:15 local'               => ['2025-03-09 20:15:00', 0, 5, true],
            'midnight local'            => ['2025-03-09 18:00:00', 0, 5, true],
            '04:59 local'               => ['2025-03-09 22:59:00', 0, 5, true],
            '05:00 local is exclusive'  => ['2025-03-09 23:00:00', 0, 5, false],
            '23:30 local default'       => ['2025-03-10 17:30:00', 0, 5, false],
            'wrap window 23:30 local'   => ['2025-03-10 17:30:00', 23, 4, true],
            'wrap window 03:00 local'   => ['2025-03-09 21:00:00', 23, 4, true],
            'wrap window 12:00 local'   => ['2025-03-10 06:00:00', 23, 4, false],
            'equal hours disables'      => ['2025-03-09 20:15:00', 3, 3, false],
        ];
    }

    #[DataProvider('nightHours')]
    public function testNightOrder(string $utc, int $start, int $end, bool $expected): void
    {
        $order  = $this->order(['created_at' => new DateTimeImmutable($utc, new DateTimeZone('UTC'))]);
        $result = $this->runRule(new NightOrderRule(), $order, ['start_hour' => $start, 'end_hour' => $end]);

        self::assertSame($expected, $result->triggered);
    }

    public function testNightOrderReasonUsesLocalTime(): void
    {
        $order  = $this->order(['created_at' => new DateTimeImmutable('2025-03-09 20:15:00', new DateTimeZone('UTC'))]);
        $result = $this->runRule(new NightOrderRule(), $order);

        self::assertSame('Order was placed at night (02:15 local time).', $result->reason);
        self::assertSame(2, $result->data['hour']);
        self::assertSame(5.0, $result->points);
    }

    // --- District return rate -------------------------------------------

    public function testNoRatesConfiguredPasses(): void
    {
        self::assertFalse($this->runRule(new DistrictReturnRateRule(), $this->order())->triggered);
    }

    public function testDistrictBelowThresholdPasses(): void
    {
        $result = $this->runRule(new DistrictReturnRateRule(), $this->order(), ['rates' => ['dhaka' => 12]]);

        self::assertFalse($result->triggered);
    }

    public function testDistrictAtThresholdGetsMinimumSeverity(): void
    {
        $result = $this->runRule(new DistrictReturnRateRule(), $this->order(), ['rates' => ['dhaka' => 20]]);

        self::assertSame('district_return_rate.match', $result->reasonCode);
        self::assertEqualsWithDelta(0.4, $result->severity, 0.0001);
        self::assertEqualsWithDelta(6.0, $result->points, 0.001);
    }

    public function testDistrictSeverityScalesToCap(): void
    {
        $mid = $this->runRule(new DistrictReturnRateRule(), $this->order(), ['rates' => ['dhaka' => 35]]);
        $cap = $this->runRule(new DistrictReturnRateRule(), $this->order(), ['rates' => ['dhaka' => 50]]);
        $max = $this->runRule(new DistrictReturnRateRule(), $this->order(), ['rates' => ['dhaka' => 90]]);

        self::assertEqualsWithDelta(0.7, $mid->severity, 0.0001);
        self::assertSame(1.0, $cap->severity);
        self::assertSame(1.0, $max->severity);
    }

    public function testDistrictNamesAreNormalised(): void
    {
        $order  = $this->order(['district' => 'Chittagong', 'city' => 'Pahartali']);
        $result = $this->runRule(new DistrictReturnRateRule(), $order, ['rates' => ['Chattogram' => 40]]);

        self::assertTrue($result->triggered);
        self::assertSame('chattogram', $result->data['district']);
        self::assertSame('District Chittagong has a configured return rate of 40%.', $result->reason);
    }

    public function testCityIsUsedWhenDistrictHasNoRate(): void
    {
        $order  = $this->order(['district' => '', 'city' => 'Khulna']);
        $result = $this->runRule(new DistrictReturnRateRule(), $order, ['rates' => ['khulna' => 33.5]]);

        self::assertTrue($result->triggered);
        self::assertStringContainsString('33.5%', $result->reason);
    }

    public function testUnknownDistrictPasses(): void
    {
        $order = $this->order(['district' => 'Rangpur', 'city' => 'Rangpur']);

        self::assertFalse($this->runRule(new DistrictReturnRateRule(), $order, ['rates' => ['khulna' => 60]])->triggered);
    }

    public function testDistrictThresholdIsConfigurable(): void
    {
        $result = $this->runRule(new DistrictReturnRateRule(), $this->order(), ['rates' => ['dhaka' => 12], 'threshold' => 10]);

        self::assertTrue($result->triggered);
    }

    public function testRatesAcceptTextFormat(): void
    {
        // The rule tolerates an un-parsed list as stored by older settings.
        $result = $this->runRule(new DistrictReturnRateRule(), $this->order(), ['rates' => ['Dhaka District' => '45%']]);

        self::assertTrue($result->triggered);
    }
}
