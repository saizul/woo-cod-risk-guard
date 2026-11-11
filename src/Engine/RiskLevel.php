<?php
/**
 * Risk levels.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Engine;

/**
 * Risk level constants and helpers.
 */
final class RiskLevel
{
    public const LOW    = 'low';
    public const MEDIUM = 'medium';
    public const HIGH   = 'high';

    /**
     * All levels ordered from least to most risky.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [self::LOW, self::MEDIUM, self::HIGH];
    }

    /**
     * Whether the value is a known level.
     */
    public static function isValid(string $level): bool
    {
        return in_array($level, self::all(), true);
    }

    /**
     * Map a 0-100 score to a level.
     *
     * @param int $score           Score between 0 and 100.
     * @param int $mediumThreshold Minimum score for "medium".
     * @param int $highThreshold   Minimum score for "high".
     */
    public static function fromScore(int $score, int $mediumThreshold = 40, int $highThreshold = 70): string
    {
        if ($score >= $highThreshold) {
            return self::HIGH;
        }

        if ($score >= $mediumThreshold) {
            return self::MEDIUM;
        }

        return self::LOW;
    }

    /**
     * Numeric rank (0 = low, 2 = high). Unknown levels rank as low.
     */
    public static function rank(string $level): int
    {
        $index = array_search($level, self::all(), true);

        return false === $index ? 0 : (int) $index;
    }

    /**
     * Whether $level is at least as risky as $minimum.
     */
    public static function atLeast(string $level, string $minimum): bool
    {
        return self::rank($level) >= self::rank($minimum);
    }
}
