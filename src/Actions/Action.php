<?php
/**
 * Actions the plugin can take on an order.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Actions;

/**
 * Action constants.
 */
final class Action
{
    /** Let the order through untouched. */
    public const ALLOW = 'allow';

    /** Put the order on hold for manual review. */
    public const HOLD = 'hold';

    /** Hide cash on delivery so the customer must pay (the delivery charge) in advance. */
    public const REQUIRE_ADVANCE = 'require_advance';

    /** Refuse the cash on delivery order with a message. */
    public const BLOCK = 'block';

    /**
     * All actions ordered from least to most restrictive.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [self::ALLOW, self::HOLD, self::REQUIRE_ADVANCE, self::BLOCK];
    }

    /**
     * Whether the value is a known action.
     */
    public static function isValid(string $action): bool
    {
        return in_array($action, self::all(), true);
    }

    /**
     * Numeric rank (0 = allow, 3 = block). Unknown actions rank as allow.
     */
    public static function rank(string $action): int
    {
        $index = array_search($action, self::all(), true);

        return false === $index ? 0 : (int) $index;
    }

    /**
     * Whether the action removes cash on delivery as a payment option.
     */
    public static function restrictsCod(string $action): bool
    {
        return in_array($action, [self::REQUIRE_ADVANCE, self::BLOCK], true);
    }
}
