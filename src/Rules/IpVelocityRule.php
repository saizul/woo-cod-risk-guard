<?php
/**
 * IP velocity rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use DateInterval;
use Saizul\CodRiskGuard\Support\IdentifierHasher;

/**
 * Flags bursts of orders from one IP address.
 */
final class IpVelocityRule extends AbstractRule
{
    public function id(): string
    {
        return 'ip_velocity';
    }

    public function label(): string
    {
        return 'Order velocity per IP';
    }

    public function description(): string
    {
        return 'Many orders from the same IP address within a short window.';
    }

    public function defaultWeight(): float
    {
        return 20.0;
    }

    public function fields(): array
    {
        return [
            'window_minutes' => [
                'type'    => 'int',
                'default' => 60,
                'min'     => 1,
                'max'     => 10080,
                'label'   => 'Window in minutes',
            ],
            'max_orders' => [
                'type'    => 'int',
                'default' => 3,
                'min'     => 1,
                'max'     => 1000,
                'label'   => 'Earlier orders in the window that trigger the rule',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $hash = $signals->hasher->hashIp($order->ip);
        if ('' === $hash) {
            return $this->pass($config);
        }

        $minutes = max(1, $config->int('window_minutes', 60));
        $max     = max(1, $config->int('max_orders', 3));
        $since   = $order->createdAt->sub(new DateInterval('PT' . $minutes . 'M'));
        $count   = $signals->velocity->countSince(IdentifierHasher::TYPE_IP, $hash, $since, $order->orderId);

        if ($count < $max) {
            return $this->pass($config);
        }

        return $this->hit(
            $config,
            $this->scale((float) $count, (float) $max, (float) ($max * 2), 0.5),
            'match',
            '%1$d other orders from the same IP address in the last %2$d minutes.',
            [$count, $minutes],
            ['count' => $count, 'window_minutes' => $minutes]
        );
    }
}
