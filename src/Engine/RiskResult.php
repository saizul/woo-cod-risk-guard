<?php
/**
 * Result of scoring an order.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Engine;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Immutable outcome of a full engine run.
 */
final class RiskResult
{
    /** Final score between 0 and 100. */
    public int $score;

    /** Risk level (see RiskLevel). */
    public string $level;

    /** Unclamped sum of rule contributions. */
    public float $rawScore;

    /** Hard override applied (allow / block), if any. */
    public ?string $override;

    /** When the order was evaluated. */
    public DateTimeImmutable $evaluatedAt;

    /**
     * Results of every rule that ran.
     *
     * @var RuleResult[]
     */
    public array $results;

    /**
     * @param RuleResult[] $results Rule results.
     */
    public function __construct(
        int $score,
        string $level,
        array $results,
        float $rawScore = 0.0,
        ?string $override = null,
        ?DateTimeImmutable $evaluatedAt = null
    ) {
        $this->score       = max(0, min(100, $score));
        $this->level       = RiskLevel::isValid($level) ? $level : RiskLevel::LOW;
        $this->results     = array_values($results);
        $this->rawScore    = $rawScore;
        $this->override    = $override;
        $this->evaluatedAt = $evaluatedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Results of rules that fired, strongest contribution first.
     *
     * @return RuleResult[]
     */
    public function triggered(): array
    {
        $triggered = array_values(array_filter(
            $this->results,
            static function (RuleResult $result): bool {
                return $result->triggered;
            }
        ));

        usort(
            $triggered,
            static function (RuleResult $a, RuleResult $b): int {
                $byOverride = (null !== $b->override) <=> (null !== $a->override);
                if (0 !== $byOverride) {
                    return $byOverride;
                }

                return abs($b->points) <=> abs($a->points);
            }
        );

        return $triggered;
    }

    /**
     * Human readable (English) reasons, strongest first.
     *
     * @return string[]
     */
    public function reasons(): array
    {
        $reasons = [];
        foreach ($this->triggered() as $result) {
            if ('' !== $result->reason) {
                $reasons[] = $result->reason;
            }
        }

        return $reasons;
    }

    /**
     * Identifiers of rules that fired.
     *
     * @return string[]
     */
    public function triggeredRuleIds(): array
    {
        $ids = [];
        foreach ($this->triggered() as $result) {
            $ids[] = $result->ruleId;
        }

        return $ids;
    }

    /**
     * Result for a specific rule, if it ran.
     */
    public function get(string $ruleId): ?RuleResult
    {
        foreach ($this->results as $result) {
            if ($result->ruleId === $ruleId) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Whether a specific rule fired.
     */
    public function hasTriggered(string $ruleId): bool
    {
        $result = $this->get($ruleId);

        return null !== $result && $result->triggered;
    }

    /**
     * Whether the level is high.
     */
    public function isHigh(): bool
    {
        return RiskLevel::HIGH === $this->level;
    }

    /**
     * Export as an array suitable for JSON / order meta storage.
     *
     * Only triggered rules are stored to keep order meta small.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $rules = [];
        foreach ($this->triggered() as $result) {
            $rules[] = $result->toArray();
        }

        return [
            'score'        => $this->score,
            'level'        => $this->level,
            'raw_score'    => $this->rawScore,
            'override'     => $this->override,
            'evaluated_at' => $this->evaluatedAt->format(DATE_ATOM),
            'rules'        => $rules,
        ];
    }

    /**
     * Rebuild from toArray() output. Tolerates missing or malformed keys.
     *
     * @param array<string, mixed> $data Stored data.
     */
    public static function fromArray(array $data): self
    {
        $results = [];
        foreach ((array) ($data['rules'] ?? []) as $row) {
            if (is_array($row)) {
                $results[] = RuleResult::fromArray($row);
            }
        }

        $evaluatedAt = null;
        if (! empty($data['evaluated_at']) && is_string($data['evaluated_at'])) {
            try {
                $evaluatedAt = new DateTimeImmutable($data['evaluated_at']);
            } catch (Exception $e) {
                $evaluatedAt = null;
            }
        }

        return new self(
            (int) ($data['score'] ?? 0),
            (string) ($data['level'] ?? RiskLevel::LOW),
            $results,
            (float) ($data['raw_score'] ?? 0.0),
            isset($data['override']) && is_string($data['override']) ? $data['override'] : null,
            $evaluatedAt
        );
    }
}
