<?php
/**
 * Small multibyte-safe string helpers.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * String helpers that degrade gracefully when mbstring is unavailable.
 */
final class Str
{
    /**
     * Lower-case a string (multibyte aware when possible).
     */
    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * Character length of a string.
     */
    public static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $matched = preg_match_all('/./us', $value);

        return false === $matched ? strlen($value) : (int) $matched;
    }

    /**
     * Collapse all whitespace runs into single spaces and trim.
     */
    public static function squish(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return trim(null === $collapsed ? $value : $collapsed);
    }

    /**
     * Split a string into words (letters and digits in any script).
     *
     * @return string[]
     */
    public static function words(string $value): array
    {
        $parts = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return false === $parts ? [] : array_values($parts);
    }

    /**
     * Keep only letters (any script, including combining marks).
     */
    public static function lettersOnly(string $value): string
    {
        $letters = preg_replace('/[^\p{L}\p{M}]+/u', '', $value);

        return null === $letters ? '' : $letters;
    }

    /**
     * Whether the string contains at least one Bengali script character.
     */
    public static function hasBengali(string $value): bool
    {
        return 1 === preg_match('/[\x{0980}-\x{09FF}]/u', $value);
    }

    /**
     * Split a multi-line or comma separated string into a clean list.
     *
     * @return string[]
     */
    public static function lines(string $value): array
    {
        $parts = preg_split('/[\r\n,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $parts) {
            return [];
        }

        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' !== $part) {
                $clean[] = $part;
            }
        }

        return array_values(array_unique($clean));
    }
}
