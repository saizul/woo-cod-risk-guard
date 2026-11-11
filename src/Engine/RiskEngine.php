<?php
/**
 * The scoring engine.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Engine;

use Throwable;

/**
 * Runs every enabled rule against an order and combines the results into a
 * 0-100 score, a level and a list of reasons.
 *
 * Scoring model:
 *  - each rule returns a severity between -1 and 1;
 *  - contribution = severity x rule weight;
 *  - score = sum of contributions, clamped to 0..100 and rounded;
 *  - a "block" override forces 100, otherwise an "allow" override forces 0.
 */
final class RiskEngine
{
    private RuleRegistry $registry;

    private EngineConfig $config;

    private Signals $signals;

    public function __construct(RuleRegistry $registry, EngineConfig $config, Signals $signals)
    {
        $this->registry = $registry;
        $this->config   = $config;
        $this->signals  = $signals;
    }

    /**
     * Score an order.
     */
    public function evaluate(OrderContext $order): RiskResult
    {
        $results  = [];
        $raw      = 0.0;
        $override = null;

        foreach ($this->registry->all() as $id => $rule) {
            $ruleConfig = $this->config->rule($id);
            if (! $ruleConfig->enabled) {
                continue;
            }

            try {
                $result = $rule->evaluate($order, $this->signals, $ruleConfig);
            } catch (Throwable $e) {
                // A broken (third-party) rule must never break checkout.
                $result = new RuleResult(
                    $id,
                    false,
                    0.0,
                    $ruleConfig->weight,
                    $id . '.error',
                    '',
                    [],
                    null,
                    ['error' => get_class($e)]
                );
            }

            $results[] = $result;

            if (! $result->triggered) {
                continue;
            }

            $raw += $result->points;

            if (RuleResult::OVERRIDE_BLOCK === $result->override) {
                $override = RuleResult::OVERRIDE_BLOCK;
            } elseif (RuleResult::OVERRIDE_ALLOW === $result->override && null === $override) {
                $override = RuleResult::OVERRIDE_ALLOW;
            }
        }

        $score = (int) round(max(0.0, min(100.0, $raw)));

        if (RuleResult::OVERRIDE_BLOCK === $override) {
            $score = 100;
        } elseif (RuleResult::OVERRIDE_ALLOW === $override) {
            $score = 0;
        }

        $level = RiskLevel::fromScore($score, $this->config->mediumThreshold, $this->config->highThreshold);

        return new RiskResult($score, $level, $results, round($raw, 2), $override);
    }

    /**
     * The registry in use.
     */
    public function registry(): RuleRegistry
    {
        return $this->registry;
    }

    /**
     * The configuration in use.
     */
    public function config(): EngineConfig
    {
        return $this->config;
    }

    /**
     * The signals in use.
     */
    public function signals(): Signals
    {
        return $this->signals;
    }
}
