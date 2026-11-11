<?php
/**
 * Keyed hashing of customer identifiers.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

use InvalidArgumentException;

/**
 * Produces keyed (HMAC-SHA256) hashes of phone numbers, emails, IPs and
 * device identifiers so that raw identifiers never need to be stored in the
 * plugin's own tables.
 */
final class IdentifierHasher
{
    public const TYPE_PHONE  = 'phone';
    public const TYPE_EMAIL  = 'email';
    public const TYPE_IP     = 'ip';
    public const TYPE_DEVICE = 'device';

    private string $secret;

    private PhoneNormalizer $phones;

    /**
     * @param string               $secret Site specific secret used as the HMAC key.
     * @param PhoneNormalizer|null $phones Phone normaliser.
     *
     * @throws InvalidArgumentException When the secret is empty.
     */
    public function __construct(string $secret, ?PhoneNormalizer $phones = null)
    {
        if ('' === $secret) {
            throw new InvalidArgumentException('The hashing secret must not be empty.');
        }

        $this->secret = $secret;
        $this->phones = $phones ?? new PhoneNormalizer();
    }

    /**
     * Supported identifier types.
     *
     * @return string[]
     */
    public static function types(): array
    {
        return [self::TYPE_PHONE, self::TYPE_EMAIL, self::TYPE_IP, self::TYPE_DEVICE];
    }

    /**
     * Whether the type is supported.
     */
    public static function isValidType(string $type): bool
    {
        return in_array($type, self::types(), true);
    }

    /**
     * Hash a value of the given type. Empty values hash to an empty string.
     *
     * @throws InvalidArgumentException When the type is unknown.
     */
    public function hash(string $type, string $value): string
    {
        $canonical = $this->canonical($type, $value);
        if ('' === $canonical) {
            return '';
        }

        return hash_hmac('sha256', $type . ':' . $canonical, $this->secret);
    }

    /**
     * Hash a phone number.
     */
    public function hashPhone(string $phone): string
    {
        return $this->hash(self::TYPE_PHONE, $phone);
    }

    /**
     * Hash an email address.
     */
    public function hashEmail(string $email): string
    {
        return $this->hash(self::TYPE_EMAIL, $email);
    }

    /**
     * Hash an IP address.
     */
    public function hashIp(string $ip): string
    {
        return $this->hash(self::TYPE_IP, $ip);
    }

    /**
     * Hash a device identifier.
     */
    public function hashDevice(string $device): string
    {
        return $this->hash(self::TYPE_DEVICE, $device);
    }

    /**
     * Canonical representation of a value prior to hashing.
     *
     * @throws InvalidArgumentException When the type is unknown.
     */
    public function canonical(string $type, string $value): string
    {
        $value = trim($value);

        switch ($type) {
            case self::TYPE_PHONE:
                return $this->phones->canonical($value);
            case self::TYPE_EMAIL:
                return EmailNormalizer::canonical($value);
            case self::TYPE_IP:
                return self::canonicalIp($value);
            case self::TYPE_DEVICE:
                return $value;
        }

        throw new InvalidArgumentException(sprintf('Unknown identifier type "%s".', $type));
    }

    /**
     * Canonical textual form of an IP address (empty when invalid).
     */
    public static function canonicalIp(string $ip): string
    {
        $ip = trim($ip);
        if ('' === $ip || false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }

        $packed = @inet_pton($ip);
        if (false === $packed) {
            return strtolower($ip);
        }

        $text = inet_ntop($packed);

        return false === $text ? strtolower($ip) : strtolower($text);
    }

    /**
     * Mask a value for display, keeping just enough to recognise it.
     */
    public function mask(string $type, string $value): string
    {
        switch ($type) {
            case self::TYPE_PHONE:
                return self::maskPhone($this->phones->canonical($value));
            case self::TYPE_EMAIL:
                return self::maskEmail($value);
            case self::TYPE_IP:
                return self::maskIp($value);
            default:
                return self::maskGeneric($value);
        }
    }

    /**
     * Mask a phone number: 01700000000 becomes 017*****000.
     */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', BanglaDigits::toAscii($phone));
        $digits = null === $digits ? '' : $digits;
        $length = strlen($digits);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return substr($digits, 0, 3) . str_repeat('*', $length - 6) . substr($digits, -3);
    }

    /**
     * Mask an email: someone@example.com becomes s*****@example.com.
     */
    public static function maskEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $at    = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return self::maskGeneric($email);
        }

        $local = substr($email, 0, $at);

        return substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1)) . substr($email, $at);
    }

    /**
     * Mask an IP address by hiding the host part.
     */
    public static function maskIp(string $ip): string
    {
        $ip = self::canonicalIp($ip);
        if ('' === $ip) {
            return '';
        }

        if (false !== strpos($ip, ':')) {
            $groups = explode(':', $ip);

            return implode(':', array_slice($groups, 0, 2)) . ':*';
        }

        $octets = explode('.', $ip);

        return $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.*';
    }

    /**
     * Mask any other value.
     */
    public static function maskGeneric(string $value): string
    {
        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
    }
}
