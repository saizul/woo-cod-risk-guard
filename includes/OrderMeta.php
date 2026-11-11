<?php
/**
 * Order meta keys.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

/**
 * Names of the order meta fields written by the plugin.
 *
 * All access goes through WC_Order CRUD methods, so the data lives in the
 * HPOS meta table or in post meta depending on the store configuration.
 */
final class OrderMeta
{
    public const SCORE           = '_codrg_score';
    public const LEVEL           = '_codrg_level';
    public const RESULT          = '_codrg_result';
    public const ACTION          = '_codrg_action';
    public const PHONE_HASH      = '_codrg_phone_hash';
    public const DEVICE_ID       = '_codrg_device_id';
    public const COUNTED_OUTCOME = '_codrg_counted_outcome';
    public const SCORED_AT       = '_codrg_scored_at';
    public const HOLD_APPLIED    = '_codrg_hold_applied';
    public const COUNTED         = '_codrg_counted';

    /**
     * Every meta key.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::SCORE,
            self::LEVEL,
            self::RESULT,
            self::ACTION,
            self::PHONE_HASH,
            self::DEVICE_ID,
            self::COUNTED_OUTCOME,
            self::SCORED_AT,
            self::HOLD_APPLIED,
            self::COUNTED,
        ];
    }
}
