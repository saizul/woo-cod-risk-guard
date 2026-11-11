<?php
/**
 * Result of a single rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Engine;

/**
 * Immutable outcome of evaluating one rule against one order.
 */
final class RuleResult
{
    public const OVERRIDE_ALLOW = 'allow';
    public const OVERRIDE_BLOCK = 'block';

    /** Rule identifier. */
    public string $ruleId;

    /** Whether the rule fired. */
    public bool $triggered;

    /** Severity between -1 (strong trust signal) and 1 (strong risk signal). */
    public float $severity;

    /** Configured weight of the rule. */
    public float $weight;

    /** Weighted contribution to the score (severity x weight). */
    public float $points;

    /** Machine readable reason code (used for translation and statistics). */
    public string $reasonCode;

    /** Human readable reason in English. */
    public string $reason;

    /**
     * Arguments used to build the reason (for translation).
     *
     * @var array<int, scalar>
     */
    public array $reasonArgs;

    /** Hard override requested by the rule (allow / block), if any. */
    public ?string $override;

    /**
     * Extra structured data.
     *
     * @var array<string, mixed>
     */
    public array $data;

    /**
     * @param array<int, scalar>   $reasonArgs Reason arguments.
     * @param array<string, mixed> $data       Extra data.
     */
    public function __construct(
        string $ruleId,
        bool $triggered,
        float $severity = 0.0,
        float $weight = 0.0,
        string $reasonCode = '',
        string $reason = '',
        array $reasonArgs = [],
        ?string $override = null,
        array $data = []
    ) {
        $severity = max(-1.0, min(1.0, $severity));

        $this->ruleId     = $ruleId;
        $this->triggered  = $triggered;
        $this->severity   = $triggered ? $severity : 0.0;
        $this->weight     = max(0.0, $weight);
        $this->points     = round($this->severity * $this->weight, 2);
        $this->reasonCode = $reasonCode;
        $this->reason     = $reason;
        $this->reasonArgs = array_values($reasonArgs);
        $this->override   = in_array($override, [self::OVERRIDE_ALLOW, self::OVERRIDE_BLOCK], true) ? $override : null;
        $this->data       = $data;
    }

    /**
     * A rule that did not fire.
     */
    public static function pass(string $ruleId, float $weight = 0.0): self
    {
        return new self($ruleId, false, 0.0, $weight);
    }

    /**
     * A rule that fired.
     *
     * @param string               $ruleId     Rule identifier.
     * @param float                $severity   Severity between -1 and 1.
     * @param float                $weight     Rule weight.
     * @param string               $reasonCode Machine readable reason code.
     * @param string               $template   sprintf() template in English.
     * @param array<int, scalar>   $args       Template arguments.
     * @param string|null          $override   Optional hard override.
     * @param array<string, mixed> $data       Extra data.
     */
    public static function hit(
        string $ruleId,
        float $severity,
        float $weight,
        string $reasonCode,
        string $template,
        array $args = [],
        ?string $override = null,
        array $data = []
    ): self {
        $reason = [] === $args ? $template : vsprintf($template, $args);

        return new self($ruleId, true, $severity, $weight, $reasonCode, $reason, $args, $override, $data);
    }

    /**
     * Whether this result lowers the score (a trust signal).
     */
    public function isTrustSignal(): bool
    {
        return $this->triggered && ($this->points < 0 || self::OVERRIDE_ALLOW === $this->override);
    }

    /**
     * Export as an array suitable for JSON / order meta storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule'      => $this->ruleId,
            'triggered' => $this->triggered,
            'severity'  => $this->severity,
            'weight'    => $this->weight,
            'points'    => $this->points,
            'code'      => $this->reasonCode,
            'reason'    => $this->reason,
            'args'      => $this->reasonArgs,
            'override'  => $this->override,
            'data'      => $this->data,
        ];
    }

    /**
     * Rebuild from toArray() output. Tolerates missing or malformed keys.
     *
     * @param array<string, mixed> $data Stored data.
     */
    public static function fromArray(array $data): self
    {
        $args = [];
        foreach ((array) ($data['args'] ?? []) as $arg) {
            if (is_scalar($arg)) {
                $args[] = $arg;
            }
        }

        return new self(
            (string) ($data['rule'] ?? ''),
            (bool) ($data['triggered'] ?? false),
            (float) ($data['severity'] ?? 0.0),
            (float) ($data['weight'] ?? 0.0),
            (string) ($data['code'] ?? ''),
            (string) ($data['reason'] ?? ''),
            $args,
            isset($data['override']) && is_string($data['override']) ? $data['override'] : null,
            is_array($data['data'] ?? null) ? $data['data'] : []
        );
    }
}
