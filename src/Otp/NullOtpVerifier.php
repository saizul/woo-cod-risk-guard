<?php
/**
 * No-op OTP verifier.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Otp;

use Saizul\CodRiskGuard\Engine\OrderContext;

/**
 * Default verifier used when no OTP integration is installed.
 */
final class NullOtpVerifier implements OtpVerifierInterface
{
    public function isPhoneVerified(OrderContext $order): bool
    {
        return false;
    }

    public function requestVerification(OrderContext $order): bool
    {
        return false;
    }
}
