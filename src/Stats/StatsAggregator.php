<?php
/**
 * Statistics aggregation.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Stats;

use Saizul\CodRiskGuard\Engine\RiskLevel;

/**
 * Aggregates scored orders into summary statistics.
 */
final class StatsAggregator
{
    /**
     * Summarise scored orders.
     *
     * Each row may contain:
     *  - level (string), score (int), rules (string[] of triggered rule ids),
     *  - outcome (delivered / returned / cancelled / null).
     *
     * @param iterable<array<string, mixed>> $rows Scored orders.
     *
     * @return array{
     *   total:int,
     *   by_level:array<string,int>,
     *   average_score:float,
     *   top_rules:array<string,int>,
     *   outcomes:array<string,array<string,int>>,
     *   failure_rate_by_level:array<string,float|null>
     * }
     */
    public static function summarize(iterable $rows, int $topRules = 10): array
    {
        $byLevel  = array_fill_keys(RiskLevel::all(), 0);
        $outcomes = [];
        foreach (RiskLevel::all() as $level) {
            $outcomes[$level] = ['delivered' => 0, 'returned' => 0, 'cancelled' => 0];
        }

        $total    = 0;
        $scoreSum = 0;
        $rules    = [];

        foreach ($rows as $row) {
            $level = (string) ($row['level'] ?? '');
            if (! RiskLevel::isValid($level)) {
                continue;
            }

            ++$total;
            ++$byLevel[$level];
            $scoreSum += max(0, min(100, (int) ($row['score'] ?? 0)));

            foreach ((array) ($row['rules'] ?? []) as $ruleId) {
                if (is_string($ruleId) && '' !== $ruleId) {
                    $rules[$ruleId] = ($rules[$ruleId] ?? 0) + 1;
                }
            }

            $outcome = $row['outcome'] ?? null;
            if (is_string($outcome) && isset($outcomes[$level][$outcome])) {
                ++$outcomes[$level][$outcome];
            }
        }

        arsort($rules);

        $failureRates = [];
        foreach ($outcomes as $level => $counts) {
            $resolved             = array_sum($counts);
            $failureRates[$level] = 0 === $resolved
                ? null
                : round(($counts['returned'] + $counts['cancelled']) / $resolved, 4);
        }

        return [
            'total'                 => $total,
            'by_level'              => $byLevel,
            'average_score'         => 0 === $total ? 0.0 : round($scoreSum / $total, 2),
            'top_rules'             => array_slice($rules, 0, max(0, $topRules), true),
            'outcomes'              => $outcomes,
            'failure_rate_by_level' => $failureRates,
        ];
    }
}
