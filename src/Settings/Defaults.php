<?php
/**
 * Default settings.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Settings;

use Saizul\CodRiskGuard\Actions\ActionResolver;
use Saizul\CodRiskGuard\Engine\EngineConfig;
use Saizul\CodRiskGuard\Engine\RuleRegistry;

/**
 * Builds the default settings array.
 */
final class Defaults
{
    /**
     * Complete default settings for the given rule registry.
     *
     * @return array<string, mixed>
     */
    public static function all(RuleRegistry $registry): array
    {
        $rules = [];
        foreach ($registry->all() as $id => $rule) {
            $rules[$id] = [
                'enabled' => $rule->defaultEnabled(),
                'weight'  => $rule->defaultWeight(),
                'options' => EngineConfig::defaultOptions($rule),
            ];
        }

        return [
            'enabled'             => true,
            'gateways'            => ['cod'],
            'thresholds'          => [
                'medium' => EngineConfig::DEFAULT_MEDIUM,
                'high'   => EngineConfig::DEFAULT_HIGH,
            ],
            'actions'             => ActionResolver::defaults(),
            'messages'            => [
                'block'   => '',
                'advance' => '',
            ],
            'statuses'            => [
                'delivered' => ['completed'],
                'returned'  => ['refunded'],
                'cancelled' => ['cancelled', 'failed'],
            ],
            'order_note'          => true,
            'retention_days'      => 90,
            'delete_on_uninstall' => false,
            'rules'               => $rules,
        ];
    }
}
