<?php
/**
 * Translatable copies of the strings produced by the scoring engine.
 *
 * The engine under src/ has no WordPress dependency and therefore emits plain
 * English. This class maps those strings to gettext calls so the admin
 * screens, order notes and exports can be translated.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

/**
 * Gettext lookup tables for engine strings.
 */
final class Labels
{
    /**
     * Reason templates keyed by reason code ("<rule id>.<code>").
     *
     * @return array<string, string>
     */
    public static function templates(): array
    {
        return [
            /* translators: placeholders are filled with order specific values. */
            'blocklist.match' => __('Customer is on the blocklist (matched: %s).', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'allowlist.match' => __('Customer is on the allowlist (matched: %s).', 'woo-cod-risk-guard'),
            'phone_validity.missing' => __('No phone number was provided.', 'woo-cod-risk-guard'),
            'phone_validity.invalid_bd' => __('Phone number is not a valid Bangladesh mobile number.', 'woo-cod-risk-guard'),
            'phone_validity.placeholder' => __('Phone number looks like a placeholder (repeated or sequential digits).', 'woo-cod-risk-guard'),
            'phone_validity.invalid_length' => __('Phone number has an invalid length.', 'woo-cod-risk-guard'),
            'phone_mismatch.shipping_differs' => __('Shipping phone differs from billing phone.', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'phone_mismatch.country_mismatch' => __('Bangladesh phone number used with a non-Bangladesh address (%s).', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'phone_history.early_failure' => __('The only earlier order(s) from this phone were returned or cancelled (%1$d of %2$d).', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'phone_history.bad_ratio' => __('%1$d of %2$d earlier orders from this phone were returned or cancelled (%3$d%%).', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'phone_history.trusted' => __('Phone has %d successfully delivered earlier orders.', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'first_time_high_value.match' => __('First-time customer with a high order value (%1$s %2$s).', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'repeat_orders.match' => __('%1$d other orders from the same %2$s in the last %3$d hours.', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'ip_velocity.match' => __('%1$d other orders from the same IP address in the last %2$d minutes.', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'address_quality.issues' => __('Address quality issues: %s.', 'woo-cod-risk-guard'),
            'customer_name.missing' => __('Customer name is missing.', 'woo-cod-risk-guard'),
            'customer_name.too_short' => __('Customer name is too short.', 'woo-cod-risk-guard'),
            'customer_name.gibberish' => __('Customer name looks like random text.', 'woo-cod-risk-guard'),
            'customer_name.digits' => __('Customer name contains digits.', 'woo-cod-risk-guard'),
            'disposable_email.invalid' => __('Email address is not valid.', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'disposable_email.disposable' => __('Email uses a disposable mailbox provider (%s).', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'quantity_anomaly.line' => __('Unusually large quantity of a single product (%1$d, limit %2$d).', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'quantity_anomaly.total' => __('Unusually large total quantity (%1$d items, limit %2$d).', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'night_order.match' => __('Order was placed at night (%s local time).', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'district_return_rate.match' => __('District %1$s has a configured return rate of %2$s%%.', 'woo-cod-risk-guard'),
            'otp_verified.verified' => __('Phone number was verified by OTP.', 'woo-cod-risk-guard'),
        ];
    }

    /**
     * Rule labels, rule descriptions and settings field labels keyed by their English text.
     *
     * @return array<string, string>
     */
    public static function texts(): array
    {
        return [
            'Blocklist' => __('Blocklist', 'woo-cod-risk-guard'),
            'Phone, email, IP address or device is on the merchant blocklist. Forces the score to 100.' => __('Phone, email, IP address or device is on the merchant blocklist. Forces the score to 100.', 'woo-cod-risk-guard'),
            'Allowlist' => __('Allowlist', 'woo-cod-risk-guard'),
            'Phone or email is on the merchant allowlist. Forces the score to 0 unless the customer is also blocklisted.' => __('Phone or email is on the merchant allowlist. Forces the score to 0 unless the customer is also blocklisted.', 'woo-cod-risk-guard'),
            'Invalid phone number' => __('Invalid phone number', 'woo-cod-risk-guard'),
            'Phone number is missing, is not a valid Bangladesh mobile number, or looks like a placeholder.' => __('Phone number is missing, is not a valid Bangladesh mobile number, or looks like a placeholder.', 'woo-cod-risk-guard'),
            'Severity for placeholder numbers (0-1)' => __('Severity for placeholder numbers (0-1)', 'woo-cod-risk-guard'),
            'Phone mismatch' => __('Phone mismatch', 'woo-cod-risk-guard'),
            'Shipping phone differs from billing phone, or the phone country does not match the address country.' => __('Shipping phone differs from billing phone, or the phone country does not match the address country.', 'woo-cod-risk-guard'),
            'Phone order history' => __('Phone order history', 'woo-cod-risk-guard'),
            'Share of earlier orders from this phone that were returned or cancelled. A good delivery record lowers the score.' => __('Share of earlier orders from this phone that were returned or cancelled. A good delivery record lowers the score.', 'woo-cod-risk-guard'),
            'Minimum finished orders before the ratio is used' => __('Minimum finished orders before the ratio is used', 'woo-cod-risk-guard'),
            'Failure ratio that triggers the rule (0-1)' => __('Failure ratio that triggers the rule (0-1)', 'woo-cod-risk-guard'),
            'Delivered orders needed for the trust bonus' => __('Delivered orders needed for the trust bonus', 'woo-cod-risk-guard'),
            'Trust bonus as a share of the weight (0-1)' => __('Trust bonus as a share of the weight (0-1)', 'woo-cod-risk-guard'),
            'First-time buyer, high value' => __('First-time buyer, high value', 'woo-cod-risk-guard'),
            'Customer has no delivered order yet and the order total is above the configured amount.' => __('Customer has no delivered order yet and the order total is above the configured amount.', 'woo-cod-risk-guard'),
            'Order total considered high (store currency)' => __('Order total considered high (store currency)', 'woo-cod-risk-guard'),
            'Repeated orders' => __('Repeated orders', 'woo-cod-risk-guard'),
            'Several orders from the same phone or device within the configured window.' => __('Several orders from the same phone or device within the configured window.', 'woo-cod-risk-guard'),
            'Window in hours' => __('Window in hours', 'woo-cod-risk-guard'),
            'Earlier orders in the window that trigger the rule' => __('Earlier orders in the window that trigger the rule', 'woo-cod-risk-guard'),
            'Order velocity per IP' => __('Order velocity per IP', 'woo-cod-risk-guard'),
            'Many orders from the same IP address within a short window.' => __('Many orders from the same IP address within a short window.', 'woo-cod-risk-guard'),
            'Window in minutes' => __('Window in minutes', 'woo-cod-risk-guard'),
            'Address quality' => __('Address quality', 'woo-cod-risk-guard'),
            'Address is too short, looks like random text, is a placeholder or has no house / road / area details.' => __('Address is too short, looks like random text, is a placeholder or has no house / road / area details.', 'woo-cod-risk-guard'),
            'Minimum street address length (characters)' => __('Minimum street address length (characters)', 'woo-cod-risk-guard'),
            'Customer name quality' => __('Customer name quality', 'woo-cod-risk-guard'),
            'Customer name is missing, too short, contains digits or looks like random text.' => __('Customer name is missing, too short, contains digits or looks like random text.', 'woo-cod-risk-guard'),
            'Disposable email' => __('Disposable email', 'woo-cod-risk-guard'),
            'Email address is malformed or uses a disposable mailbox provider.' => __('Email address is malformed or uses a disposable mailbox provider.', 'woo-cod-risk-guard'),
            'Extra disposable domains (one per line)' => __('Extra disposable domains (one per line)', 'woo-cod-risk-guard'),
            'Quantity anomaly' => __('Quantity anomaly', 'woo-cod-risk-guard'),
            'Quantity of a single product, or of the whole order, is above the configured limits.' => __('Quantity of a single product, or of the whole order, is above the configured limits.', 'woo-cod-risk-guard'),
            'Largest normal quantity of one product' => __('Largest normal quantity of one product', 'woo-cod-risk-guard'),
            'Largest normal total quantity' => __('Largest normal total quantity', 'woo-cod-risk-guard'),
            'Night-time order' => __('Night-time order', 'woo-cod-risk-guard'),
            'Order was placed during the configured night hours (store time zone).' => __('Order was placed during the configured night hours (store time zone).', 'woo-cod-risk-guard'),
            'Night starts at hour (0-23)' => __('Night starts at hour (0-23)', 'woo-cod-risk-guard'),
            'Night ends before hour (0-23)' => __('Night ends before hour (0-23)', 'woo-cod-risk-guard'),
            'District return rate' => __('District return rate', 'woo-cod-risk-guard'),
            'Delivery district has a merchant-configured return rate above the threshold.' => __('Delivery district has a merchant-configured return rate above the threshold.', 'woo-cod-risk-guard'),
            'Return rate per district (one "District=percent" per line)' => __('Return rate per district (one "District=percent" per line)', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'Return rate (%) that triggers the rule' => __('Return rate (%) that triggers the rule', 'woo-cod-risk-guard'),
            /* translators: placeholders are filled with order specific values. */
            'Return rate (%) treated as full severity' => __('Return rate (%) treated as full severity', 'woo-cod-risk-guard'),
            'Phone verified by OTP' => __('Phone verified by OTP', 'woo-cod-risk-guard'),
            'An OTP integration confirmed the phone number. Subtracts the weight from the score.' => __('An OTP integration confirmed the phone number. Subtracts the weight from the score.', 'woo-cod-risk-guard'),
        ];
    }

    /**
     * Fragments used inside reasons (address issues, identifier names) keyed by their English text.
     *
     * @return array<string, string>
     */
    public static function fragments(): array
    {
        return [
            'address is empty' => __('address is empty', 'woo-cod-risk-guard'),
            'address is too short' => __('address is too short', 'woo-cod-risk-guard'),
            'address has too few words' => __('address has too few words', 'woo-cod-risk-guard'),
            'address looks like random text' => __('address looks like random text', 'woo-cod-risk-guard'),
            'address contains no letters' => __('address contains no letters', 'woo-cod-risk-guard'),
            'address is a placeholder value' => __('address is a placeholder value', 'woo-cod-risk-guard'),
            'address only repeats the city name' => __('address only repeats the city name', 'woo-cod-risk-guard'),
            'city / town is missing' => __('city / town is missing', 'woo-cod-risk-guard'),
            'no house, road or area details' => __('no house, road or area details', 'woo-cod-risk-guard'),
            'phone' => __('phone number', 'woo-cod-risk-guard'),
            'email' => __('email address', 'woo-cod-risk-guard'),
            'ip' => __('IP address', 'woo-cod-risk-guard'),
            'device' => __('device', 'woo-cod-risk-guard'),
        ];
    }

    /**
     * Translate a label / description, falling back to the given text.
     */
    public static function text(string $english): string
    {
        $texts = self::texts();

        return $texts[$english] ?? $english;
    }

    /**
     * Translate a reason fragment, falling back to the given text.
     */
    public static function fragment(string $english): string
    {
        $fragments = self::fragments();

        return $fragments[$english] ?? $english;
    }

    /**
     * Translated template for a reason code, or null when the code is unknown
     * (for example a rule added by another plugin).
     */
    public static function template(string $code): ?string
    {
        $templates = self::templates();

        return $templates[$code] ?? null;
    }
}
