<?php
/**
 * Gibberish text detection.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * Cheap heuristics that flag keyboard-mash style text such as "asdfgh",
 * "xxxxxx" or "kjhgfd". The heuristics are deliberately conservative for
 * Bengali script, where vowel-ratio checks do not apply.
 */
final class GibberishDetector
{
    public const REASON_REPEATED_CHARS  = 'repeated_chars';
    public const REASON_KEYBOARD_RUN    = 'keyboard_run';
    public const REASON_NO_VOWELS       = 'no_vowels';
    public const REASON_CONSONANT_RUN   = 'consonant_run';
    public const REASON_LOW_VARIETY     = 'low_variety';

    /**
     * Common keyboard-mash fragments.
     *
     * @var string[]
     */
    private const KEYBOARD_RUNS = [
        'asdf', 'sdfg', 'dfgh', 'fghj', 'ghjk', 'hjkl',
        'qwer', 'wert', 'erty', 'rtyu', 'tyui', 'yuio', 'uiop',
        'zxcv', 'xcvb', 'cvbn', 'vbnm',
        'lkjh', 'kjhg', 'jhgf', 'hgfd', 'gfds', 'fdsa',
        'poiu', 'oiuy', 'iuyt', 'uytr', 'ytre', 'trew', 'rewq',
        'mnbv', 'nbvc', 'bvcx', 'vcxz',
    ];

    /**
     * Reasons why the text looks like gibberish (empty when it looks fine).
     *
     * @return string[]
     */
    public function reasons(string $text): array
    {
        $text = Str::lower(Str::squish($text));
        if ('' === $text) {
            return [];
        }

        $reasons = [];
        $letters = Str::lettersOnly($text);

        // The same letter four or more times in a row ("aaaa", "কককক").
        if (1 === preg_match('/(\p{L})\1{3,}/u', $text)) {
            $reasons[] = self::REASON_REPEATED_CHARS;
        }

        $latin = preg_replace('/[^a-z]+/', '', $text);
        $latin = null === $latin ? '' : $latin;

        if ('' !== $latin) {
            foreach (self::KEYBOARD_RUNS as $run) {
                if (false !== strpos($latin, $run)) {
                    $reasons[] = self::REASON_KEYBOARD_RUN;
                    break;
                }
            }

            $latinLength = strlen($latin);
            if ($latinLength >= 5) {
                $vowels = preg_match_all('/[aeiouy]/', $latin);
                $ratio  = ((int) $vowels) / $latinLength;
                if ($ratio < 0.12) {
                    $reasons[] = self::REASON_NO_VOWELS;
                }
            }

            if (1 === preg_match('/[bcdfghjklmnpqrstvwxz]{6,}/', $latin)) {
                $reasons[] = self::REASON_CONSONANT_RUN;
            }
        }

        // Very few distinct characters in a reasonably long string ("abababab").
        $length = Str::length($letters);
        if ($length >= 8) {
            $chars = preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY);
            if (false !== $chars && count(array_unique($chars)) <= 2) {
                $reasons[] = self::REASON_LOW_VARIETY;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Whether the text looks like gibberish.
     */
    public function isGibberish(string $text): bool
    {
        return [] !== $this->reasons($text);
    }
}
