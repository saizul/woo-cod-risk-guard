<?php
/**
 * Order outcomes.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\History;

/**
 * Final outcomes an order can have from a COD risk point of view.
 */
final class Outcome
{
    public const DELIVERED = 'delivered';
    public const RETURNED  = 'returned';
    public const CANCELLED = 'cancelled';

    /**
     * All outcomes.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [self::DELIVERED, self::RETURNED, self::CANCELLED];
    }

    /**
     * Whether the value is a known outcome.
     */
    public static function isValid(?string $outcome): bool
    {
        return null !== $outcome && in_array($outcome, self::all(), true);
    }
}
