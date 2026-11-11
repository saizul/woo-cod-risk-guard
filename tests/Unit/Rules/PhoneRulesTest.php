<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\Rules;

use PHPUnit\Framework\Attributes\DataProvider;
use Saizul\CodRiskGuard\Rules\OtpVerifiedRule;
use Saizul\CodRiskGuard\Rules\PhoneMismatchRule;
use Saizul\CodRiskGuard\Rules\PhoneValidityRule;
use Saizul\CodRiskGuard\Tests\EngineTestCase;

final class PhoneRulesTest extends EngineTestCase
{
    public function testValidPhonePasses(): void
    {
        self::assertFalse($this->runRule(new PhoneValidityRule(), $this->order())->triggered);
    }

    public function testMissingPhone(): void
    {
        $result = $this->runRule(new PhoneValidityRule(), $this->order(['phone' => '']));

        self::assertSame('phone_validity.missing', $result->reasonCode);
        self::assertSame(25.0, $result->points);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidBdPhones(): array
    {
        return [
            'too short'    => ['0170000000'],
            'too long'     => ['017000000011'],
            'bad operator' => ['01200000001'],
            'landline'     => ['029000001'],
            'foreign'      => ['+1 202 555 0100'],
        ];
    }

    #[DataProvider('invalidBdPhones')]
    public function testInvalidBangladeshPhone(string $phone): void
    {
        $result = $this->runRule(new PhoneValidityRule(), $this->order(['phone' => $phone]));

        self::assertSame('phone_validity.invalid_bd', $result->reasonCode);
        self::assertSame(1.0, $result->severity);
        self::assertNotEmpty($result->data['issues']);
    }

    public function testPlaceholderPhoneUsesConfiguredSeverity(): void
    {
        $order = $this->order(['phone' => '01711111111']);

        $default = $this->runRule(new PhoneValidityRule(), $order);
        self::assertSame('phone_validity.placeholder', $default->reasonCode);
        self::assertEqualsWithDelta(15.0, $default->points, 0.001);

        $strict = $this->runRule(new PhoneValidityRule(), $order, ['placeholder_severity' => 1.0]);
        self::assertSame(25.0, $strict->points);
    }

    public function testBanglaDigitPhoneIsValid(): void
    {
        self::assertFalse($this->runRule(new PhoneValidityRule(), $this->order(['phone' => '০১৭০০০০০০০১']))->triggered);
    }

    public function testEmptyCountryIsTreatedAsBangladesh(): void
    {
        $result = $this->runRule(new PhoneValidityRule(), $this->order(['phone' => '12345', 'country' => '']));

        self::assertSame('phone_validity.invalid_bd', $result->reasonCode);
    }

    public function testForeignOrderAcceptsForeignNumber(): void
    {
        $order = $this->order(['phone' => '+1 202 555 0100', 'country' => 'US']);

        self::assertFalse($this->runRule(new PhoneValidityRule(), $order)->triggered);
    }

    public function testForeignOrderRejectsBadLength(): void
    {
        $short = $this->runRule(new PhoneValidityRule(), $this->order(['phone' => '12345', 'country' => 'US']));
        $long  = $this->runRule(new PhoneValidityRule(), $this->order(['phone' => '1234567890123456', 'country' => 'US']));

        self::assertSame('phone_validity.invalid_length', $short->reasonCode);
        self::assertSame('phone_validity.invalid_length', $long->reasonCode);
    }

    public function testForeignOrderFlagsRepeatedDigits(): void
    {
        $result = $this->runRule(new PhoneValidityRule(), $this->order(['phone' => '9999999999', 'country' => 'IN']));

        self::assertSame('phone_validity.placeholder', $result->reasonCode);
    }

    public function testWeightIsApplied(): void
    {
        $result = $this->runRule(new PhoneValidityRule(), $this->order(['phone' => '']), [], 40.0);

        self::assertSame(40.0, $result->points);
    }

    public function testMismatchPassesWithoutShippingPhone(): void
    {
        self::assertFalse($this->runRule(new PhoneMismatchRule(), $this->order())->triggered);
    }

    public function testMismatchPassesWhenShippingPhoneIsSameNumber(): void
    {
        $order = $this->order(['shipping_phone' => '+8801700000001']);

        self::assertFalse($this->runRule(new PhoneMismatchRule(), $order)->triggered);
    }

    public function testMismatchWhenShippingPhoneDiffers(): void
    {
        $result = $this->runRule(new PhoneMismatchRule(), $this->order(['shipping_phone' => '01800000002']));

        self::assertSame('phone_mismatch.shipping_differs', $result->reasonCode);
        self::assertSame(8.0, $result->points);
    }

    public function testMismatchIgnoresShippingPhoneWithoutDigits(): void
    {
        self::assertFalse($this->runRule(new PhoneMismatchRule(), $this->order(['shipping_phone' => 'same']))->triggered);
    }

    public function testMismatchPassesWithoutBillingPhone(): void
    {
        $order = $this->order(['phone' => '', 'shipping_phone' => '01800000002']);

        self::assertFalse($this->runRule(new PhoneMismatchRule(), $order)->triggered);
    }

    public function testBangladeshPhoneWithForeignAddress(): void
    {
        $result = $this->runRule(new PhoneMismatchRule(), $this->order(['country' => 'IN']));

        self::assertSame('phone_mismatch.country_mismatch', $result->reasonCode);
        self::assertSame(4.0, $result->points);
        self::assertStringContainsString('IN', $result->reason);
    }

    public function testForeignPhoneWithForeignAddressPasses(): void
    {
        $order = $this->order(['phone' => '+91 9000000001', 'country' => 'IN']);

        self::assertFalse($this->runRule(new PhoneMismatchRule(), $order)->triggered);
    }

    public function testOtpRulePassesWhenNotVerified(): void
    {
        self::assertFalse($this->runRule(new OtpVerifiedRule(), $this->order())->triggered);
    }

    public function testOtpRuleLowersScoreWhenVerified(): void
    {
        $result = $this->runRule(new OtpVerifiedRule(), $this->order(['phone_verified' => true]));

        self::assertSame('otp_verified.verified', $result->reasonCode);
        self::assertSame(-20.0, $result->points);
        self::assertTrue($result->isTrustSignal());
    }
}
