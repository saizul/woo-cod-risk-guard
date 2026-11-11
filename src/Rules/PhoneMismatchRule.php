<?php
/**
 * Phone mismatch rule.
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
 * Flags orders where the phone details do not line up: a different shipping
 * phone, or a Bangladesh number used with a foreign billing country.
 */
final class PhoneMismatchRule extends AbstractRule
{
    public function id(): string
    {
        return 'phone_mismatch';
    }

    public function label(): string
    {
        return 'Phone mismatch';
    }

    public function description(): string
    {
        return 'Shipping phone differs from billing phone, or the phone country does not match the address country.';
    }

    public function defaultWeight(): float
    {
        return 8.0;
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        if ('' === $order->phone) {
            return $this->pass($config);
        }

        $billing = $signals->phones->analyze($order->phone);

        if ('' !== $order->shippingPhone) {
            $shipping = $signals->phones->analyze($order->shippingPhone);
            if (! $shipping->isEmpty() && ! $signals->phones->same($order->phone, $order->shippingPhone)) {
                return $this->hit($config, 1.0, 'shipping_differs', 'Shipping phone differs from billing phone.');
            }
        }

        if ('' !== $order->country && 'BD' !== $order->country && $billing->validBangladeshMobile) {
            return $this->hit(
                $config,
                0.5,
                'country_mismatch',
                'Bangladesh phone number used with a non-Bangladesh address (%s).',
                [$order->country]
            );
        }

        return $this->pass($config);
    }
}
