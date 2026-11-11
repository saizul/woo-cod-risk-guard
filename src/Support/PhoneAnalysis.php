<?php
/**
 * Result of analysing a phone number.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * Immutable description of a parsed phone number.
 */
final class PhoneAnalysis
{
    public const ISSUE_EMPTY       = 'empty';
    public const ISSUE_TOO_SHORT   = 'too_short';
    public const ISSUE_TOO_LONG    = 'too_long';
    public const ISSUE_BAD_PREFIX  = 'bad_prefix';
    public const ISSUE_FOREIGN     = 'foreign';
    public const ISSUE_PLACEHOLDER = 'placeholder';

    /** Raw input as typed by the customer. */
    public string $raw;

    /** Digits only, after converting localised digits. */
    public string $digits;

    /** Whether the number is a valid Bangladesh mobile number. */
    public bool $validBangladeshMobile;

    /** National format 01XXXXXXXXX, or null when not a valid BD mobile. */
    public ?string $national;

    /** E.164 format +8801XXXXXXXXX, or null when not a valid BD mobile. */
    public ?string $e164;

    /** Operator name, or null when unknown. */
    public ?string $operator;

    /** Whether the input carried an explicit international prefix (+ or 00). */
    public bool $international;

    /**
     * Issue codes found.
     *
     * @var string[]
     */
    public array $issues;

    /**
     * @param string[] $issues Issue codes.
     */
    public function __construct(
        string $raw,
        string $digits,
        bool $validBangladeshMobile,
        ?string $national,
        ?string $e164,
        ?string $operator,
        bool $international,
        array $issues
    ) {
        $this->raw                   = $raw;
        $this->digits                = $digits;
        $this->validBangladeshMobile = $validBangladeshMobile;
        $this->national              = $national;
        $this->e164                  = $e164;
        $this->operator              = $operator;
        $this->international         = $international;
        $this->issues                = array_values($issues);
    }

    /**
     * Whether a specific issue was detected.
     */
    public function has(string $issue): bool
    {
        return in_array($issue, $this->issues, true);
    }

    /**
     * Whether the number looks like a placeholder (e.g. 01711111111).
     */
    public function isPlaceholder(): bool
    {
        return $this->has(self::ISSUE_PLACEHOLDER);
    }

    /**
     * Whether no usable digits were supplied.
     */
    public function isEmpty(): bool
    {
        return '' === $this->digits;
    }
}
