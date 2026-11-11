<?php
/**
 * Delivery address quality heuristics.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * Scores how deliverable an address looks.
 *
 * These are heuristics, not address validation: Bangladesh has no reliable
 * address database, so the analyser only looks for obvious signs of a
 * throw-away address (too short, placeholder text, random characters,
 * no house / road / area details).
 */
final class AddressAnalyzer
{
    /**
     * Values that are never a real address on their own.
     *
     * @var string[]
     */
    private const PLACEHOLDERS = [
        'n/a', 'na', 'none', 'nil', 'null', 'test', 'testing', 'demo', 'address',
        'abc', 'abcd', 'xyz', 'asdf', 'home', 'house', 'basa', 'bari', 'office',
        'same', 'same as above', 'no address', 'unknown', '-', '--', '.', '...',
        'ঠিকানা', 'বাসা', 'বাড়ি',
    ];

    /**
     * Tokens that indicate house / road / area level detail.
     *
     * @var string[]
     */
    private const AREA_KEYWORDS = [
        'road', 'rd', 'street', 'st', 'lane', 'avenue', 'ave', 'house', 'holding',
        'flat', 'apt', 'apartment', 'floor', 'level', 'block', 'sector', 'section',
        'plot', 'building', 'tower', 'bhaban', 'bhobon', 'villa', 'manzil', 'market',
        'bazar', 'bazaar', 'more', 'mor', 'goli', 'para', 'tola', 'nagar', 'pur',
        'village', 'vill', 'gram', 'union', 'ward', 'thana', 'upazila', 'upozila',
        'post', 'po', 'ps', 'school', 'college', 'masjid', 'mosque', 'mosjid',
        'hospital', 'station', 'stand', 'gate', 'colony', 'housing', 'society',
        'residential', 'area', 'sarani', 'sarak', 'sorok', 'chowrasta', 'chottor',
        'রোড', 'সড়ক', 'বাড়ি', 'বাসা', 'ফ্ল্যাট', 'ব্লক', 'সেক্টর', 'গ্রাম', 'বাজার',
        'থানা', 'উপজেলা', 'পাড়া', 'মোড়', 'গলি', 'ইউনিয়ন', 'ওয়ার্ড', 'ডাকঘর',
        'মসজিদ', 'স্কুল', 'কলেজ', 'নগর', 'পুর', 'তলা', 'হাউজিং', 'এলাকা',
    ];

    private GibberishDetector $gibberish;

    private int $minLength;

    /**
     * @param int                    $minLength Minimum number of characters expected in the street address.
     * @param GibberishDetector|null $gibberish Gibberish detector.
     */
    public function __construct(int $minLength = 10, ?GibberishDetector $gibberish = null)
    {
        $this->minLength = max(1, $minLength);
        $this->gibberish = $gibberish ?? new GibberishDetector();
    }

    /**
     * Return a copy that uses a different minimum length.
     */
    public function withMinLength(int $minLength): self
    {
        return new self($minLength, $this->gibberish);
    }

    /**
     * Analyse an address.
     *
     * @param string $address1 Street address line 1.
     * @param string $address2 Street address line 2.
     * @param string $city     City / town / upazila.
     */
    public function analyze(string $address1, string $address2 = '', string $city = ''): AddressAnalysis
    {
        $street = Str::squish(BanglaDigits::toAscii($address1 . ' ' . $address2));
        $city   = Str::squish($city);
        $issues = [];

        if ('' === $street) {
            $issues[] = AddressAnalysis::ISSUE_EMPTY;
            if ('' === $city) {
                $issues[] = AddressAnalysis::ISSUE_MISSING_CITY;
            }

            return new AddressAnalysis($issues);
        }

        $lower = Str::lower($street);
        $words = Str::words($lower);

        if ('' === $city) {
            $issues[] = AddressAnalysis::ISSUE_MISSING_CITY;
        }

        if (in_array(trim($lower, " \t,.;:"), self::PLACEHOLDERS, true)) {
            $issues[] = AddressAnalysis::ISSUE_PLACEHOLDER;

            return new AddressAnalysis($issues);
        }

        if ('' === Str::lettersOnly($street)) {
            $issues[] = AddressAnalysis::ISSUE_NO_LETTERS;

            return new AddressAnalysis($issues);
        }

        if ('' !== $city && trim($lower, " \t,.;:") === Str::lower($city)) {
            $issues[] = AddressAnalysis::ISSUE_CITY_ONLY;

            return new AddressAnalysis($issues);
        }

        if (Str::length($street) < $this->minLength) {
            $issues[] = AddressAnalysis::ISSUE_TOO_SHORT;
        } elseif (count($words) < 2) {
            $issues[] = AddressAnalysis::ISSUE_TOO_FEW_WORDS;
        }

        if ($this->gibberish->isGibberish($street)) {
            $issues[] = AddressAnalysis::ISSUE_GIBBERISH;
        }

        if (! $this->hasAreaDetail($lower, $words)) {
            $issues[] = AddressAnalysis::ISSUE_MISSING_AREA;
        }

        return new AddressAnalysis($issues);
    }

    /**
     * Whether the address mentions a house number, road, area or landmark.
     *
     * @param string   $lower Lower-cased street address.
     * @param string[] $words Words of the street address.
     */
    private function hasAreaDetail(string $lower, array $words): bool
    {
        // Any digit usually means a house / road / holding number.
        if (1 === preg_match('/\d/', $lower)) {
            return true;
        }

        // Four or more words is detailed enough to hand to a courier.
        if (count($words) >= 4) {
            return true;
        }

        foreach ($words as $word) {
            if (in_array($word, self::AREA_KEYWORDS, true)) {
                return true;
            }
        }

        // Bengali and Latin suffix style keywords ("Rampura", "Mirpur", "শান্তিনগর").
        foreach (['pur', 'nagar', 'para', 'ganj', 'gonj', 'bazar', 'পুর', 'নগর', 'পাড়া', 'গঞ্জ', 'বাজার'] as $suffix) {
            foreach ($words as $word) {
                if (Str::length($word) > Str::length($suffix) && self::endsWith($word, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Multibyte-safe "ends with".
     */
    private static function endsWith(string $haystack, string $needle): bool
    {
        $length = strlen($needle);

        return $length > 0 && substr($haystack, -$length) === $needle;
    }
}
