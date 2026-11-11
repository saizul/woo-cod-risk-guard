<?php
/**
 * OTP verification hook point.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Otp;

use Saizul\CodRiskGuard\Engine\OrderContext;

/**
 * Contract for an OTP / phone verification integration.
 *
 * No SMS provider is bundled. An integration plugin implements this interface
 * and returns its instance from the "codrg_otp_verifier" filter. When the
 * verifier reports a phone as verified, the "otp_verified" rule lowers the
 * risk score.
 */
interface OtpVerifierInterface
{
    /**
     * Whether the phone number on the order has been verified by OTP.
     */
    public function isPhoneVerified(OrderContext $order): bool;

    /**
     * Ask the integration to start a verification for the order's phone.
     *
     * Called when a risky order is placed and verification would help.
     * Return true when a verification request was sent.
     */
    public function requestVerification(OrderContext $order): bool;
}
