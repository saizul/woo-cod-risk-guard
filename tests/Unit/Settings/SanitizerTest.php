<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Engine\EngineConfig;
use Saizul\CodRiskGuard\Engine\RuleRegistry;
use Saizul\CodRiskGuard\Settings\Defaults;
use Saizul\CodRiskGuard\Settings\Sanitizer;

final class SanitizerTest extends TestCase
{
    private RuleRegistry $registry;

    private Sanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->registry  = RuleRegistry::withDefaults();
        $this->sanitizer = new Sanitizer($this->registry);
    }

    public function testDefaultsShape(): void
    {
        $defaults = Defaults::all($this->registry);

        self::assertTrue($defaults['enabled']);
        self::assertSame(['cod'], $defaults['gateways']);
        self::assertSame(['medium' => 40, 'high' => 70], $defaults['thresholds']);
        self::assertSame(['low' => 'allow', 'medium' => 'hold', 'high' => 'require_advance'], $defaults['actions']);
        self::assertSame(90, $defaults['retention_days']);
        self::assertFalse($defaults['delete_on_uninstall']);
        self::assertCount(15, $defaults['rules']);
        self::assertSame(35.0, $defaults['rules']['phone_history']['weight']);
        self::assertSame(5000.0, $defaults['rules']['first_time_high_value']['options']['amount']);
    }

    public function testDefaultWeightsMatchDocumentation(): void
    {
        $weights = [];
        foreach (Defaults::all($this->registry)['rules'] as $id => $rule) {
            $weights[$id] = $rule['weight'];
        }

        self::assertSame(
            [
                'blocklist'             => 100.0,
                'allowlist'             => 0.0,
                'phone_validity'        => 25.0,
                'phone_mismatch'        => 8.0,
                'phone_history'         => 35.0,
                'first_time_high_value' => 15.0,
                'repeat_orders'         => 20.0,
                'ip_velocity'           => 20.0,
                'address_quality'       => 20.0,
                'customer_name'         => 8.0,
                'disposable_email'      => 10.0,
                'quantity_anomaly'      => 10.0,
                'night_order'           => 5.0,
                'district_return_rate'  => 15.0,
                'otp_verified'          => 20.0,
            ],
            $weights
        );
    }

    public function testSanitisingDefaultsIsIdempotent(): void
    {
        $defaults = Defaults::all($this->registry);

        self::assertEquals($defaults, $this->sanitizer->sanitize($defaults));
        self::assertEquals($defaults, $this->sanitizer->sanitize($this->sanitizer->sanitize($defaults)));
    }

    public function testNonArrayInputYieldsDefaults(): void
    {
        self::assertEquals(Defaults::all($this->registry), $this->sanitizer->sanitize('garbage'));
        self::assertEquals(Defaults::all($this->registry), $this->sanitizer->sanitize(null));
    }

    public function testThresholdsAreClampedAndOrdered(): void
    {
        $clean = $this->sanitizer->sanitize(['thresholds' => ['medium' => 80, 'high' => 60]]);
        self::assertSame(['medium' => 80, 'high' => 81], $clean['thresholds']);

        $clean = $this->sanitizer->sanitize(['thresholds' => ['medium' => -5, 'high' => 900]]);
        self::assertSame(['medium' => 1, 'high' => 100], $clean['thresholds']);

        $clean = $this->sanitizer->sanitize(['thresholds' => ['medium' => 'abc']]);
        self::assertSame(['medium' => 40, 'high' => 70], $clean['thresholds']);
    }

    public function testInvalidActionsFallBackToDefaults(): void
    {
        $clean = $this->sanitizer->sanitize(['actions' => ['low' => 'block', 'medium' => 'drop table', 'high' => ['x']]]);

        self::assertSame(['low' => 'block', 'medium' => 'hold', 'high' => 'require_advance'], $clean['actions']);
    }

    public function testMessagesAreStrippedAndTruncated(): void
    {
        $clean = $this->sanitizer->sanitize([
            'messages' => [
                'block'   => "<script>alert(1)</script>Sorry,\n<b>no COD</b>",
                'advance' => str_repeat('ক', 600),
            ],
        ]);

        self::assertSame('alert(1)Sorry, no COD', $clean['messages']['block']);
        self::assertSame(500, mb_strlen($clean['messages']['advance'], 'UTF-8'));
    }

    public function testCustomTextSanitizerIsUsed(): void
    {
        $sanitizer = new Sanitizer($this->registry, static function (string $text): string {
            return strtoupper($text);
        });

        self::assertSame('HELLO', $sanitizer->sanitize(['messages' => ['block' => 'hello']])['messages']['block']);
    }

    public function testGatewaysAndStatusesAreSlugLists(): void
    {
        $clean = $this->sanitizer->sanitize([
            'gateways' => "cod, Cash_On_Delivery\n<b>bad</b>",
            'statuses' => [
                'delivered' => ['wc-completed', 'wc-delivered', 'completed'],
                'returned'  => 'wc-returned, refunded',
                'cancelled' => [],
            ],
        ]);

        self::assertSame(['cod', 'cash_on_delivery', 'bbadb'], $clean['gateways']);
        self::assertSame(['completed', 'delivered'], $clean['statuses']['delivered']);
        self::assertSame(['returned', 'refunded'], $clean['statuses']['returned']);
        self::assertSame([], $clean['statuses']['cancelled']);
    }

    public function testEmptyGatewayListFallsBackToCod(): void
    {
        self::assertSame(['cod'], $this->sanitizer->sanitize(['gateways' => []])['gateways']);
    }

    public function testRetentionDaysRange(): void
    {
        self::assertSame(1, $this->sanitizer->sanitize(['retention_days' => 0])['retention_days']);
        self::assertSame(3650, $this->sanitizer->sanitize(['retention_days' => 99999])['retention_days']);
        self::assertSame(90, $this->sanitizer->sanitize(['retention_days' => 'x'])['retention_days']);
        self::assertSame(30, $this->sanitizer->sanitize(['retention_days' => '30'])['retention_days']);
    }

    public function testCheckboxesFromFormsAreFalseWhenAbsent(): void
    {
        $stored = $this->sanitizer->sanitize([]);
        $form   = $this->sanitizer->sanitize(['rules' => ['night_order' => ['enabled' => '1']]], true);

        self::assertTrue($stored['enabled']);
        self::assertTrue($stored['rules']['phone_history']['enabled']);

        self::assertFalse($form['enabled']);
        self::assertFalse($form['order_note']);
        self::assertFalse($form['rules']['phone_history']['enabled']);
        self::assertTrue($form['rules']['night_order']['enabled']);
    }

    public function testRuleWeightsAreClamped(): void
    {
        $clean = $this->sanitizer->sanitize([
            'rules' => [
                'phone_history' => ['weight' => 500],
                'night_order'   => ['weight' => -3],
                'ip_velocity'   => ['weight' => 'heavy'],
                'customer_name' => ['weight' => '12.5'],
            ],
        ]);

        self::assertSame(100.0, $clean['rules']['phone_history']['weight']);
        self::assertSame(0.0, $clean['rules']['night_order']['weight']);
        self::assertSame(20.0, $clean['rules']['ip_velocity']['weight']);
        self::assertSame(12.5, $clean['rules']['customer_name']['weight']);
    }

    public function testRuleOptionsRespectFieldBounds(): void
    {
        $clean = $this->sanitizer->sanitize([
            'rules' => [
                'night_order'   => ['options' => ['start_hour' => 99, 'end_hour' => -4]],
                'phone_history' => ['options' => ['bad_ratio' => 7, 'min_orders' => 'x', 'injected' => 'DROP']],
            ],
        ]);

        self::assertSame(23, $clean['rules']['night_order']['options']['start_hour']);
        self::assertSame(0, $clean['rules']['night_order']['options']['end_hour']);
        self::assertSame(1.0, $clean['rules']['phone_history']['options']['bad_ratio']);
        self::assertSame(2, $clean['rules']['phone_history']['options']['min_orders']);
        self::assertArrayNotHasKey('injected', $clean['rules']['phone_history']['options']);
    }

    public function testUnknownRulesAreDropped(): void
    {
        $clean = $this->sanitizer->sanitize(['rules' => ['ghost' => ['enabled' => true, 'weight' => 99]]]);

        self::assertArrayNotHasKey('ghost', $clean['rules']);
        self::assertCount(15, $clean['rules']);
    }

    public function testDomainLinesAreCleaned(): void
    {
        $clean = $this->sanitizer->sanitize([
            'rules' => ['disposable_email' => ['options' => ['extra_domains' => "Junk.Example\n@spam.example, not a domain\njunk.example"]]],
        ]);

        self::assertSame(['junk.example', 'spam.example'], $clean['rules']['disposable_email']['options']['extra_domains']);
    }

    public function testDistrictRatesAreParsed(): void
    {
        $clean = $this->sanitizer->sanitize([
            'rules' => ['district_return_rate' => ['options' => ['rates' => "Dhaka=12\nChittagong: 40%\n<script>=5"]]],
        ]);

        $rates = $clean['rules']['district_return_rate']['options']['rates'];

        self::assertSame(12.0, $rates['dhaka']);
        self::assertSame(40.0, $rates['chattogram']);
        foreach (array_keys($rates) as $key) {
            self::assertStringNotContainsString('<', (string) $key);
        }
    }

    public function testSanitisedSettingsFeedTheEngineConfig(): void
    {
        $clean  = $this->sanitizer->sanitize([
            'thresholds' => ['medium' => 25, 'high' => 55],
            'rules'      => ['night_order' => ['enabled' => '0', 'weight' => 9]],
        ]);
        $config = EngineConfig::fromArray($clean, $this->registry);

        self::assertSame(25, $config->mediumThreshold);
        self::assertSame(55, $config->highThreshold);
        self::assertFalse($config->rule('night_order')->enabled);
        self::assertSame(9.0, $config->rule('night_order')->weight);
    }

    public function testHelpers(): void
    {
        self::assertSame('a b', Sanitizer::plainText("<i>a</i>\tb\x00"));
        self::assertTrue(Sanitizer::toBool('Yes'));
        self::assertTrue(Sanitizer::toBool(1));
        self::assertFalse(Sanitizer::toBool('off'));
        self::assertFalse(Sanitizer::toBool(''));
    }
}
