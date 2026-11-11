<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Stubs;

use RuntimeException;
use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\Rules\AbstractRule;

/**
 * Test double: a rule that always fires with a fixed severity (or throws).
 */
final class FixedRule extends AbstractRule
{
    private string $ruleId;

    private float $severity;

    private float $weight;

    private bool $throws;

    private ?string $override;

    public function __construct(string $id, float $severity, float $weight = 10.0, bool $throws = false, ?string $override = null)
    {
        $this->ruleId   = $id;
        $this->severity = $severity;
        $this->weight   = $weight;
        $this->throws   = $throws;
        $this->override = $override;
    }

    public function id(): string
    {
        return $this->ruleId;
    }

    public function label(): string
    {
        return 'Fixed rule';
    }

    public function description(): string
    {
        return 'Always fires with a fixed severity.';
    }

    public function defaultWeight(): float
    {
        return $this->weight;
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        if ($this->throws) {
            throw new RuntimeException('Rule exploded');
        }

        return $this->hit($config, $this->severity, 'fixed', 'Fixed rule fired.', [], [], $this->override);
    }
}
