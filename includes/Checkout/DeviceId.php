<?php
/**
 * Device identifier cookie.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Checkout;

/**
 * A random first-party cookie used to link orders placed from the same
 * browser. It contains no personal data and only its keyed hash is stored
 * in the plugin's tables.
 */
final class DeviceId
{
    public const COOKIE = 'codrg_did';

    /**
     * Whether the device cookie is enabled.
     */
    public function isEnabled(): bool
    {
        /**
         * Filters whether the device cookie is used.
         *
         * Return false if your cookie policy does not allow it; the
         * "repeated orders" rule then works on phone numbers only.
         *
         * @param bool $enabled Whether to set and read the cookie.
         */
        return (bool) apply_filters('codrg_use_device_cookie', true);
    }

    /**
     * Current device id, or an empty string.
     */
    public function current(): string
    {
        $value = '';

        if ($this->isEnabled() && isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE])) {
            $value = self::clean(sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])));
        }

        /**
         * Filters the device identifier of the current visitor.
         *
         * Lets a fingerprinting or app integration supply its own identifier.
         *
         * @param string $deviceId Device identifier ('' when unknown).
         */
        return (string) apply_filters('codrg_device_id', $value);
    }

    /**
     * Set the cookie when it is missing. Must run before output starts.
     */
    public function ensure(): void
    {
        if (! $this->isEnabled() || headers_sent()) {
            return;
        }

        if (isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE])
            && '' !== self::clean(sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])))) {
            return;
        }

        $value = strtolower(wp_generate_password(32, false, false));
        $value = self::clean(md5($value . wp_rand()) . substr(md5((string) microtime(true)), 0, 8));

        setcookie(
            self::COOKIE,
            $value,
            [
                'expires'  => time() + YEAR_IN_SECONDS,
                'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $_COOKIE[self::COOKIE] = $value;
    }

    /**
     * Accept only identifiers in the format this class generates.
     */
    public static function clean(string $value): string
    {
        $value = strtolower(trim($value));

        return 1 === preg_match('/^[a-f0-9]{16,64}$/', $value) ? $value : '';
    }
}
