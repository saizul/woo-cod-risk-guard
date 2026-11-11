<?php
/**
 * Rule contract.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;

/**
 * A single risk rule.
 *
 * Rules must be stateless and free of side effects: they receive the order,
 * the shared signals and their own configuration, and return a RuleResult.
 */
interface RuleInterface
{
    /**
     * Unique identifier (lower-case letters, digits and underscores).
     */
    public function id(): string;

    /**
     * Short English label.
     */
    public function label(): string;

    /**
     * One sentence English description.
     */
    public function description(): string;

    /**
     * Default weight: the number of points added at full severity.
     */
    public function defaultWeight(): float;

    /**
     * Whether the rule is enabled by default.
     */
    public function defaultEnabled(): bool;

    /**
     * Schema of the rule's options (thresholds).
     *
     * Each entry is keyed by option name and has:
     *  - type: int | float | bool | text | lines | rates
     *  - default: default value
     *  - label: short English label
     *  - min / max: optional numeric bounds
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array;

    /**
     * Evaluate the rule.
     */
    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult;
}
