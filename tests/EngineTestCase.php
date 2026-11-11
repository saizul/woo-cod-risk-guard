<?php
/**
 * Shared helpers for engine tests.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Engine\EngineConfig;
use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RiskEngine;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleRegistry;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\History\CustomerHistory;
use Saizul\CodRiskGuard\History\InMemoryHistoryProvider;
use Saizul\CodRiskGuard\History\InMemoryVelocityProvider;
use Saizul\CodRiskGuard\Lists\InMemoryListStore;
use Saizul\CodRiskGuard\Rules\AbstractRule;
use Saizul\CodRiskGuard\Rules\RuleInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;

/**
 * Base test case that wires an in-memory engine.
 */
abstract class EngineTestCase extends TestCase
{
    protected const SECRET = 'unit-test-secret';
    protected const PHONE  = '01700000001';

    protected IdentifierHasher $hasher;
    protected InMemoryHistoryProvider $history;
    protected InMemoryVelocityProvider $velocity;
    protected InMemoryListStore $lists;
    protected Signals $signals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hasher   = new IdentifierHasher(self::SECRET);
        $this->history  = new InMemoryHistoryProvider();
        $this->velocity = new InMemoryVelocityProvider();
        $this->lists    = new InMemoryListStore();
        $this->signals  = new Signals(
            $this->hasher,
            $this->history,
            $this->velocity,
            $this->lists,
            new DateTimeZone('Asia/Dhaka')
        );
    }

    /**
     * A clean, low-risk order; override any field.
     *
     * @param array<string, mixed> $overrides Field overrides.
     */
    protected function order(array $overrides = []): OrderContext
    {
        return OrderContext::fromArray(array_merge([
            'order_id'          => 1001,
            'phone'             => self::PHONE,
            'email'             => 'customer@example.com',
            'first_name'        => 'Rahim',
            'last_name'         => 'Uddin',
            'ip'                => '203.0.113.10',
            'device_id'         => 'device-abc',
            'total'             => 1200.0,
            'currency'          => 'BDT',
            'payment_method'    => 'cod',
            'total_quantity'    => 2,
            'max_line_quantity' => 1,
            'line_count'        => 2,
            'address_1'         => 'House 12, Road 5, Block C, Banani',
            'address_2'         => '',
            'city'              => 'Dhaka',
            'district'          => 'Dhaka',
            'postcode'          => '1213',
            'country'           => 'BD',
            // 14:30 in Dhaka (UTC+6).
            'created_at'        => new DateTimeImmutable('2025-03-10 08:30:00', new DateTimeZone('UTC')),
        ], $overrides));
    }

    /**
     * Default configuration of a rule, with optional option / weight overrides.
     *
     * @param array<string, mixed> $options Option overrides.
     */
    protected function configFor(RuleInterface $rule, array $options = [], ?float $weight = null): RuleConfig
    {
        return new RuleConfig(
            true,
            $weight ?? $rule->defaultWeight(),
            array_merge(EngineConfig::defaultOptions($rule), $options)
        );
    }

    /**
     * Evaluate a single rule against an order.
     *
     * @param array<string, mixed> $options Option overrides.
     */
    protected function runRule(AbstractRule $rule, OrderContext $order, array $options = [], ?float $weight = null): RuleResult
    {
        return $rule->evaluate($order, $this->signals, $this->configFor($rule, $options, $weight));
    }

    /**
     * Engine with every bundled rule and optional settings.
     *
     * @param array<string, mixed> $settings Settings array (thresholds / rules).
     */
    protected function engine(array $settings = [], ?RuleRegistry $registry = null): RiskEngine
    {
        $registry = $registry ?? RuleRegistry::withDefaults();

        return new RiskEngine($registry, EngineConfig::fromArray($settings, $registry), $this->signals);
    }

    /**
     * Store a phone history for the default (or given) phone.
     */
    protected function giveHistory(int $delivered, int $returned = 0, int $cancelled = 0, string $phone = self::PHONE): void
    {
        $this->history->set(
            $this->hasher->hashPhone($phone),
            new CustomerHistory($delivered + $returned + $cancelled, $delivered, $returned, $cancelled)
        );
    }

    /**
     * Record earlier orders for an identifier, spaced $stepMinutes apart before $before.
     */
    protected function recordEvents(string $type, string $value, int $count, DateTimeImmutable $before, int $stepMinutes = 5): void
    {
        $hash = $this->hasher->hash($type, $value);
        for ($i = 1; $i <= $count; $i++) {
            $this->velocity->record($type, $hash, $before->modify('-' . ($i * $stepMinutes) . ' minutes'), 5000 + $i);
        }
    }
}
