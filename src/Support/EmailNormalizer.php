<?php
/**
 * Email normalisation.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * Normalises email addresses so that trivial variations hash identically.
 */
final class EmailNormalizer
{
    /**
     * Providers that ignore dots in the local part.
     *
     * @var string[]
     */
    private const DOT_INSENSITIVE = ['gmail.com', 'googlemail.com'];

    /**
     * Whether the value is a syntactically valid email address.
     */
    public static function isValid(string $email): bool
    {
        $email = trim($email);

        return '' !== $email && false !== filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Lower-cased domain part, or an empty string.
     */
    public static function domain(string $email): string
    {
        $email = strtolower(trim($email));
        $at    = strrpos($email, '@');
        if (false === $at) {
            return '';
        }

        return rtrim(substr($email, $at + 1), '.');
    }

    /**
     * Canonical form: lower-case, "+tag" removed, Gmail dots removed.
     */
    public static function canonical(string $email): string
    {
        $email = strtolower(trim($email));
        $at    = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return $email;
        }

        $local  = substr($email, 0, $at);
        $domain = rtrim(substr($email, $at + 1), '.');

        $plus = strpos($local, '+');
        if (false !== $plus && $plus > 0) {
            $local = substr($local, 0, $plus);
        }

        if (in_array($domain, self::DOT_INSENSITIVE, true)) {
            $local  = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }
}
