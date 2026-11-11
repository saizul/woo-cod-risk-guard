<?php
/**
 * Base class for rules.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;

/**
 * Convenience base class with sensible defaults and result helpers.
 */
abstract class AbstractRule implements RuleInterface
{
    public function defaultEnabled(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [];
    }

    /**
     * Default option values derived from fields().
     *
     * @return array<string, mixed>
     */
    public function defaultOptions(): array
    {
        $options = [];
        foreach ($this->fields() as $key => $field) {
            $options[$key] = $field['default'] ?? null;
        }

        return $options;
    }

    /**
     * The rule did not fire.
     */
    protected function pass(RuleConfig $config): RuleResult
    {
        return RuleResult::pass($this->id(), $config->weight);
    }

    /**
     * The rule fired.
     *
     * @param RuleConfig           $config   Rule configuration (provides the weight).
     * @param float                $severity Severity between -1 and 1.
     * @param string               $code     Reason code, unique within the rule.
     * @param string               $template sprintf() template in English.
     * @param array<int, scalar>   $args     Template arguments.
     * @param array<string, mixed> $data     Extra data.
     * @param string|null          $override Optional hard override.
     */
    protected function hit(
        RuleConfig $config,
        float $severity,
        string $code,
        string $template,
        array $args = [],
        array $data = [],
        ?string $override = null
    ): RuleResult {
        return RuleResult::hit(
            $this->id(),
            $severity,
            $config->weight,
            $this->id() . '.' . $code,
            $template,
            $args,
            $override,
            $data
        );
    }

    /**
     * Linear interpolation of a severity between two bounds.
     *
     * Returns $minSeverity at $start, 1.0 at $end and beyond.
     */
    protected function scale(float $value, float $start, float $end, float $minSeverity = 0.5): float
    {
        if ($end <= $start) {
            return 1.0;
        }

        $progress = ($value - $start) / ($end - $start);
        $progress = max(0.0, min(1.0, $progress));

        return $minSeverity + (1.0 - $minSeverity) * $progress;
    }
}
