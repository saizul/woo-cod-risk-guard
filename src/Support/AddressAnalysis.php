<?php
/**
 * Result of analysing a delivery address.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * Immutable address quality report.
 */
final class AddressAnalysis
{
    public const ISSUE_EMPTY         = 'empty';
    public const ISSUE_TOO_SHORT     = 'too_short';
    public const ISSUE_TOO_FEW_WORDS = 'too_few_words';
    public const ISSUE_GIBBERISH     = 'gibberish';
    public const ISSUE_NO_LETTERS    = 'no_letters';
    public const ISSUE_PLACEHOLDER   = 'placeholder';
    public const ISSUE_CITY_ONLY     = 'city_only';
    public const ISSUE_MISSING_CITY  = 'missing_city';
    public const ISSUE_MISSING_AREA  = 'missing_area';

    /**
     * Severity (0..1) contributed by each issue.
     *
     * @var array<string, float>
     */
    public const SEVERITY = [
        self::ISSUE_EMPTY         => 1.0,
        self::ISSUE_TOO_SHORT     => 0.6,
        self::ISSUE_TOO_FEW_WORDS => 0.3,
        self::ISSUE_GIBBERISH     => 0.7,
        self::ISSUE_NO_LETTERS    => 0.7,
        self::ISSUE_PLACEHOLDER   => 0.8,
        self::ISSUE_CITY_ONLY     => 0.5,
        self::ISSUE_MISSING_CITY  => 0.4,
        self::ISSUE_MISSING_AREA  => 0.3,
    ];

    /**
     * English labels for each issue.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::ISSUE_EMPTY         => 'address is empty',
        self::ISSUE_TOO_SHORT     => 'address is too short',
        self::ISSUE_TOO_FEW_WORDS => 'address has too few words',
        self::ISSUE_GIBBERISH     => 'address looks like random text',
        self::ISSUE_NO_LETTERS    => 'address contains no letters',
        self::ISSUE_PLACEHOLDER   => 'address is a placeholder value',
        self::ISSUE_CITY_ONLY     => 'address only repeats the city name',
        self::ISSUE_MISSING_CITY  => 'city / town is missing',
        self::ISSUE_MISSING_AREA  => 'no house, road or area details',
    ];

    /**
     * Issue codes found.
     *
     * @var string[]
     */
    public array $issues;

    /** Combined severity, capped at 1.0. */
    public float $severity;

    /**
     * @param string[] $issues Issue codes.
     */
    public function __construct(array $issues)
    {
        $this->issues = array_values(array_unique($issues));

        $total = 0.0;
        foreach ($this->issues as $issue) {
            $total += self::SEVERITY[$issue] ?? 0.0;
        }

        $this->severity = min(1.0, $total);
    }

    /**
     * Whether a specific issue was detected.
     */
    public function has(string $issue): bool
    {
        return in_array($issue, $this->issues, true);
    }

    /**
     * Whether the address passed every check.
     */
    public function isClean(): bool
    {
        return [] === $this->issues;
    }

    /**
     * English labels for the detected issues.
     *
     * @return string[]
     */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->issues as $issue) {
            $labels[] = self::LABELS[$issue] ?? $issue;
        }

        return $labels;
    }
}
