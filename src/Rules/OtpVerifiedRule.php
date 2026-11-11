<?php
/**
 * OTP verified rule.
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
 * Lowers the score when an OTP integration has verified the phone number.
 */
final class OtpVerifiedRule extends AbstractRule
{
    public function id(): string
    {
        return 'otp_verified';
    }

    public function label(): string
    {
        return 'Phone verified by OTP';
    }

    public function description(): string
    {
        return 'An OTP integration confirmed the phone number. Subtracts the weight from the score.';
    }

    public function defaultWeight(): float
    {
        return 20.0;
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        if (! $order->phoneVerified) {
            return $this->pass($config);
        }

        return $this->hit($config, -1.0, 'verified', 'Phone number was verified by OTP.');
    }
}
