<?php
/**
 * Bangla digit conversion.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * Converts Bangla (and other common South Asian / Arabic-Indic) digits to ASCII.
 *
 * Customers frequently type phone numbers with a Bangla keyboard layout, so
 * "০১৭০০০০০০০০" must be treated exactly like "01700000000".
 */
final class BanglaDigits
{
    /**
     * Digit map keyed by the localised digit.
     *
     * @var array<string, string>
     */
    private const MAP = [
        // Bengali.
        '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
        '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
        // Devanagari.
        '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4',
        '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
        // Arabic-Indic.
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        // Extended Arabic-Indic (Urdu / Persian).
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Replace every localised digit with its ASCII equivalent.
     */
    public static function toAscii(string $value): string
    {
        return strtr($value, self::MAP);
    }

    /**
     * Whether the value contains at least one non-ASCII digit we can convert.
     */
    public static function containsLocalisedDigits(string $value): bool
    {
        return self::toAscii($value) !== $value;
    }

    /**
     * Convert ASCII digits to Bengali digits (useful for display).
     */
    public static function toBangla(string $value): string
    {
        return strtr($value, [
            '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪',
            '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯',
        ]);
    }
}
