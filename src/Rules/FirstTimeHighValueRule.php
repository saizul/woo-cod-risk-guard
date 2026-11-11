<?php
/**
 * First-time high value rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;

/**
 * Flags expensive orders from customers with no completed order history.
 */
final class FirstTimeHighValueRule extends AbstractRule
{
    public function id(): string
    {
        return 'first_time_high_value';
    }

    public function label(): string
    {
        return 'First-time buyer, high value';
    }

    public function description(): string
    {
        return 'Customer has no delivered order yet and the order total is above the configured amount.';
    }

    public function defaultWeight(): float
    {
        return 15.0;
    }

    public function fields(): array
    {
        return [
            'amount' => [
                'type'    => 'float',
                'default' => 5000.0,
                'min'     => 0.0,
                'max'     => 100000000.0,
                'label'   => 'Order total considered high (store currency)',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $amount = $config->float('amount', 5000.0);
        if ($amount <= 0.0 || $order->total < $amount) {
            return $this->pass($config);
        }

        $hash = $signals->hasher->hashPhone($order->phone);
        if ('' !== $hash && $signals->history->getByPhoneHash($hash)->delivered > 0) {
            return $this->pass($config);
        }

        return $this->hit(
            $config,
            $this->scale($order->total, $amount, $amount * 2, 0.5),
            'match',
            'First-time customer with a high order value (%1$s %2$s).',
            [number_format($order->total, 2, '.', ','), $order->currency],
            ['total' => $order->total, 'threshold' => $amount]
        );
    }
}
