<?php
/**
 * Bangladesh phone number normalisation.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * Normalises and validates Bangladesh mobile numbers.
 *
 * Accepted inputs include "01700000000", "+8801700000000", "8801700000000",
 * "008801700000000", "1700000000", numbers with spaces / dashes / brackets and
 * numbers typed with Bangla digits.
 */
final class PhoneNormalizer
{
    /**
     * Operator prefixes (first three digits of the national number).
     *
     * @var array<string, string>
     */
    private const OPERATORS = [
        '013' => 'Grameenphone',
        '017' => 'Grameenphone',
        '014' => 'Banglalink',
        '019' => 'Banglalink',
        '015' => 'Teletalk',
        '016' => 'Robi',
        '018' => 'Robi',
    ];

    /**
     * Analyse a raw phone number.
     */
    public function analyze(string $raw): PhoneAnalysis
    {
        $ascii         = trim(BanglaDigits::toAscii($raw));
        $international = 1 === preg_match('/^\s*(\+|00)/', $ascii);
        $digits        = preg_replace('/\D+/', '', $ascii);
        $digits        = null === $digits ? '' : $digits;

        if ('' === $digits) {
            return new PhoneAnalysis($raw, '', false, null, null, null, $international, [PhoneAnalysis::ISSUE_EMPTY]);
        }

        $candidate = $this->toNationalCandidate($digits, $international);
        $issues    = [];
        $valid     = 1 === preg_match('/^01[3-9]\d{8}$/', $candidate);

        if (! $valid) {
            $issues = $this->diagnose($digits, $candidate, $international);

            return new PhoneAnalysis($raw, $digits, false, null, null, null, $international, $issues);
        }

        if ($this->looksLikePlaceholder($candidate)) {
            $issues[] = PhoneAnalysis::ISSUE_PLACEHOLDER;
        }

        $prefix   = substr($candidate, 0, 3);
        $operator = self::OPERATORS[$prefix] ?? null;

        return new PhoneAnalysis(
            $raw,
            $digits,
            true,
            $candidate,
            '+88' . $candidate,
            $operator,
            $international,
            $issues
        );
    }

    /**
     * Return the national format (01XXXXXXXXX) or null when invalid.
     */
    public function normalize(string $raw): ?string
    {
        return $this->analyze($raw)->national;
    }

    /**
     * Return the E.164 format (+8801XXXXXXXXX) or null when invalid.
     */
    public function toE164(string $raw): ?string
    {
        return $this->analyze($raw)->e164;
    }

    /**
     * Whether the number is a valid Bangladesh mobile number.
     */
    public function isValidBangladeshMobile(string $raw): bool
    {
        return $this->analyze($raw)->validBangladeshMobile;
    }

    /**
     * Canonical form used for hashing and comparisons.
     *
     * Valid BD mobiles collapse to the national format so that every spelling
     * of the same number hashes identically. Anything else falls back to the
     * bare digits (without leading international zeros).
     */
    public function canonical(string $raw): string
    {
        $analysis = $this->analyze($raw);
        if (null !== $analysis->national) {
            return $analysis->national;
        }

        if ($analysis->international) {
            return ltrim($analysis->digits, '0');
        }

        return $analysis->digits;
    }

    /**
     * Operator name for a valid BD mobile number.
     */
    public function operator(string $raw): ?string
    {
        return $this->analyze($raw)->operator;
    }

    /**
     * Whether two raw inputs refer to the same phone number.
     */
    public function same(string $a, string $b): bool
    {
        $ca = $this->canonical($a);
        $cb = $this->canonical($b);

        return '' !== $ca && $ca === $cb;
    }

    /**
     * Reduce the many spellings of a BD number to an 11 digit candidate.
     */
    private function toNationalCandidate(string $digits, bool $international): string
    {
        // 00880 1XXXXXXXXX (international dialling prefix).
        if (0 === strpos($digits, '00880')) {
            $digits = substr($digits, 2);
        }

        // 880 1XXXXXXXXX.
        if (13 === strlen($digits) && 0 === strpos($digits, '8801')) {
            return '0' . substr($digits, 3);
        }

        // 1XXXXXXXXX (leading zero dropped). Not applied to numbers typed
        // with an explicit international prefix such as "+1...".
        if (! $international && 10 === strlen($digits) && '1' === $digits[0]) {
            return '0' . $digits;
        }

        return $digits;
    }

    /**
     * Work out why a number is not a valid BD mobile number.
     *
     * @return string[]
     */
    private function diagnose(string $digits, string $candidate, bool $international): array
    {
        $issues = [];

        if ($international && 0 !== strpos(ltrim($digits, '0'), '880')) {
            $issues[] = PhoneAnalysis::ISSUE_FOREIGN;

            return $issues;
        }

        $length = strlen($candidate);
        if ($length < 11) {
            $issues[] = PhoneAnalysis::ISSUE_TOO_SHORT;
        } elseif ($length > 11) {
            $issues[] = PhoneAnalysis::ISSUE_TOO_LONG;
        }

        if (1 !== preg_match('/^01[3-9]/', $candidate)) {
            $issues[] = PhoneAnalysis::ISSUE_BAD_PREFIX;
        }

        return $issues;
    }

    /**
     * Detect placeholder style numbers such as 01711111111 or 01712345678.
     */
    private function looksLikePlaceholder(string $national): bool
    {
        $subscriber = substr($national, 3);

        // Same digit repeated across the whole subscriber part.
        if (1 === preg_match('/^(\d)\1{7}$/', $subscriber)) {
            return true;
        }

        // Fully ascending or descending sequences.
        if (in_array($subscriber, ['12345678', '23456789', '01234567', '87654321', '98765432'], true)) {
            return true;
        }

        // A two digit pattern repeated four times (e.g. 12121212).
        if (1 === preg_match('/^(\d\d)\1{3}$/', $subscriber)) {
            return true;
        }

        return false;
    }
}
