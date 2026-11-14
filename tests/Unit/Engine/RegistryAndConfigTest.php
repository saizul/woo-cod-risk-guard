<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Engine;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Engine\EngineConfig;
use Saizul\CodRiskGuard\Engine\RuleRegistry;
use Saizul\CodRiskGuard\Rules\NightOrderRule;
use Saizul\CodRiskGuard\Rules\PhoneHistoryRule;
use Saizul\CodRiskGuard\Rules\RuleInterface;
use Saizul\CodRiskGuard\Tests\Stubs\FixedRule;

final class RegistryAndConfigTest extends TestCase
{
    public function testDefaultsContainEveryBundledRule(): void
    {
        $registry = RuleRegistry::withDefaults();

        self::assertSame(16, $registry->count());
        self::assertSame(
            [
                'blocklist', 'allowlist', 'phone_validity', 'phone_mismatch', 'phone_history',
                'first_time_high_value', 'repeat_orders', 'ip_velocity', 'shared_device', 'address_quality',
                'customer_name', 'disposable_email', 'quantity_anomaly', 'night_order',
                'district_return_rate', 'otp_verified',
            ],
            $registry->ids()
        );
    }

    public function testBundledRulesHaveCompleteMetadata(): void
    {
        foreach (RuleRegistry::bundled() as $rule) {
            self::assertInstanceOf(RuleInterface::class, $rule);
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]+$/', $rule->id());
            self::assertNotSame('', $rule->label(), $rule->id());
            self::assertNotSame('', $rule->description(), $rule->id());
            self::assertGreaterThanOrEqual(0.0, $rule->defaultWeight());
            self::assertLessThanOrEqual(100.0, $rule->defaultWeight());

            foreach ($rule->fields() as $key => $field) {
                self::assertIsString($key);
                self::assertContains($field['type'], ['int', 'float', 'bool', 'text', 'lines', 'rates'], $rule->id() . '.' . $key);
                self::assertArrayHasKey('default', $field, $rule->id() . '.' . $key);
                self::assertNotSame('', (string) ($field['label'] ?? ''), $rule->id() . '.' . $key);
            }
        }
    }

    public function testRegisterAndUnregister(): void
    {
        $registry = new RuleRegistry();
        $registry->register(new FixedRule('custom_rule', 0.5));

        self::assertTrue($registry->has('custom_rule'));
        self::assertSame('custom_rule', $registry->get('custom_rule')->id());

        $registry->unregister('custom_rule');

        self::assertFalse($registry->has('custom_rule'));
        self::assertNull($registry->get('custom_rule'));
        self::assertSame(0, $registry->count());
    }

    public function testDuplicateRegistrationThrows(): void
    {
        $registry = new RuleRegistry();
        $registry->register(new FixedRule('custom_rule', 0.5));

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new FixedRule('custom_rule', 0.9));
    }

    public function testReplaceFlagAllowsOverriding(): void
    {
        $registry = RuleRegistry::withDefaults();
        $registry->register(new FixedRule('night_order', 1.0, 50.0), true);

        self::assertInstanceOf(FixedRule::class, $registry->get('night_order'));
        self::assertSame(16, $registry->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badIds(): array
    {
        return [
            'empty'       => [''],
            'upper case'  => ['MyRule'],
            'dash'        => ['my-rule'],
            'digit first' => ['1rule'],
            'space'       => ['my rule'],
            'too short'   => ['a'],
            'too long'    => [str_repeat('a', 60)],
        ];
    }

    #[DataProvider('badIds')]
    public function testInvalidRuleIdsAreRejected(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RuleRegistry())->register(new FixedRule($id, 0.5));
    }

    public function testDefaultConfigUsesRuleDefaults(): void
    {
        $registry = RuleRegistry::withDefaults();
        $config   = EngineConfig::defaults($registry);

        self::assertSame(40, $config->mediumThreshold);
        self::assertSame(70, $config->highThreshold);

        $history = $config->rule('phone_history');
        self::assertTrue($history->enabled);
        self::assertSame(35.0, $history->weight);
        self::assertSame(2, $history->int('min_orders'));
        self::assertSame(0.3, $history->float('bad_ratio'));
    }

    public function testDefaultOptionsComeFromFields(): void
    {
        self::assertSame(['start_hour' => 0, 'end_hour' => 5], EngineConfig::defaultOptions(new NightOrderRule()));
        self::assertArrayHasKey('trust_bonus', EngineConfig::defaultOptions(new PhoneHistoryRule()));
    }

    public function testFromArrayOverridesSelectedValues(): void
    {
        $registry = RuleRegistry::withDefaults();
        $config   = EngineConfig::fromArray([
            'thresholds' => ['medium' => 30, 'high' => 60],
            'rules'      => [
                'night_order'   => ['enabled' => false],
                'phone_history' => ['weight' => 50, 'options' => ['min_orders' => 4]],
            ],
        ], $registry);

        self::assertSame(30, $config->mediumThreshold);
        self::assertSame(60, $config->highThreshold);
        self::assertFalse($config->rule('night_order')->enabled);
        self::assertSame(50.0, $config->rule('phone_history')->weight);
        self::assertSame(4, $config->rule('phone_history')->int('min_orders'));
        self::assertSame(0.3, $config->rule('phone_history')->float('bad_ratio'), 'Untouched options keep defaults.');
        self::assertTrue($config->rule('ip_velocity')->enabled);
    }

    public function testFromArrayToleratesGarbage(): void
    {
        $registry = RuleRegistry::withDefaults();
        $config   = EngineConfig::fromArray(['thresholds' => 'x', 'rules' => ['phone_history' => 'x', 'ghost' => []]], $registry);

        self::assertSame(40, $config->mediumThreshold);
        self::assertSame(35.0, $config->rule('phone_history')->weight);
        self::assertFalse($config->hasRule('ghost'));
    }

    public function testThresholdsAreKeptConsistent(): void
    {
        $config = new EngineConfig(80, 50);

        self::assertSame(80, $config->mediumThreshold);
        self::assertSame(81, $config->highThreshold);

        $low = new EngineConfig(-10, 500);
        self::assertSame(1, $low->mediumThreshold);
        self::assertLessThanOrEqual(100, $low->highThreshold);
    }

    public function testUnknownRuleIsDisabled(): void
    {
        $config = EngineConfig::defaults(RuleRegistry::withDefaults());

        self::assertFalse($config->rule('does_not_exist')->enabled);
        self::assertFalse($config->hasRule('does_not_exist'));
    }

    public function testWithRuleReturnsACopy(): void
    {
        $config  = EngineConfig::defaults(RuleRegistry::withDefaults());
        $changed = $config->withRule('night_order', $config->rule('night_order')->withWeight(50.0));

        self::assertSame(5.0, $config->rule('night_order')->weight);
        self::assertSame(50.0, $changed->rule('night_order')->weight);
        self::assertCount(16, $changed->rules());
    }
}
