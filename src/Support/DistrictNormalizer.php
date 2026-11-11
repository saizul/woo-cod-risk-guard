<?php
/**
 * District name normalisation.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * Normalises Bangladesh district names so that old and new official
 * spellings (and a few Bengali names) map to the same key.
 */
final class DistrictNormalizer
{
    /**
     * Alias => canonical key.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'chittagong'   => 'chattogram',
        'ctg'          => 'chattogram',
        'barisal'      => 'barishal',
        'comilla'      => 'cumilla',
        'jessore'      => 'jashore',
        'bogra'        => 'bogura',
        'dacca'        => 'dhaka',
        'coxs bazar'   => 'cox\'s bazar',
        'coxsbazar'    => 'cox\'s bazar',
        'cox bazar'    => 'cox\'s bazar',
        'chapainawabganj' => 'chapai nawabganj',
        'nawabganj'    => 'chapai nawabganj',
        'brahmanbaria' => 'brahmanbaria',
        'b.baria'      => 'brahmanbaria',
        'moulvibazar'  => 'moulvibazar',
        'maulvibazar'  => 'moulvibazar',
        'netrakona'    => 'netrokona',
        'jhalakathi'   => 'jhalokati',
        'jhalakati'    => 'jhalokati',
        'ঢাকা'          => 'dhaka',
        'চট্টগ্রাম'       => 'chattogram',
        'খুলনা'         => 'khulna',
        'রাজশাহী'       => 'rajshahi',
        'সিলেট'         => 'sylhet',
        'বরিশাল'        => 'barishal',
        'রংপুর'         => 'rangpur',
        'ময়মনসিংহ'      => 'mymensingh',
        'কুমিল্লা'        => 'cumilla',
        'গাজীপুর'       => 'gazipur',
        'নারায়ণগঞ্জ'     => 'narayanganj',
        'বগুড়া'         => 'bogura',
        'যশোর'         => 'jashore',
    ];

    /**
     * Normalise a district name to a comparison key.
     */
    public static function normalize(string $district): string
    {
        // Keep only letters, combining marks, digits, spaces, apostrophes and hyphens.
        $safe = preg_replace('/[^\p{L}\p{M}\p{N}\s\'\-]+/u', ' ', $district);
        $key  = Str::lower(Str::squish(null === $safe ? '' : $safe));
        $key  = trim($key, " \t-'");

        if ('' === $key) {
            return '';
        }

        // Drop trailing administrative words: "Dhaka District", "Khulna Zila".
        $stripped = preg_replace('/\s+(district|zila|zilla|jela|division|sadar)$/u', '', $key);
        $key      = null === $stripped ? $key : $stripped;

        return self::ALIASES[$key] ?? $key;
    }

    /**
     * Parse merchant supplied "district = rate" lines into a normalised map.
     *
     * Accepts either a multi-line string ("Dhaka=12\nKhulna: 35%") or an
     * associative array. Rates are percentages clamped to 0..100.
     *
     * @param string|array<array-key, mixed> $input Raw configuration.
     *
     * @return array<string, float>
     */
    public static function parseRates($input): array
    {
        $pairs = [];

        if (is_array($input)) {
            foreach ($input as $name => $rate) {
                if (is_string($rate)) {
                    $rate = rtrim(trim(BanglaDigits::toAscii($rate)), "% \t");
                }
                $pairs[] = [(string) $name, $rate];
            }
        } else {
            $lines = preg_split('/[\r\n;]+/', (string) $input, -1, PREG_SPLIT_NO_EMPTY);
            foreach (false === $lines ? [] : $lines as $line) {
                if (1 === preg_match('/^(.+?)\s*[=:|,]\s*([0-9]+(?:\.[0-9]+)?)\s*%?\s*$/u', trim(BanglaDigits::toAscii($line)), $m)) {
                    $pairs[] = [$m[1], $m[2]];
                }
            }
        }

        $rates = [];
        foreach ($pairs as [$name, $rate]) {
            $key = self::normalize((string) $name);
            if ('' === $key || ! is_numeric($rate)) {
                continue;
            }

            $rates[$key] = max(0.0, min(100.0, round((float) $rate, 2)));
        }

        ksort($rates);

        return $rates;
    }

    /**
     * Turn a rate map back into editable "district=rate" lines.
     *
     * @param array<string, float|int> $rates Rate map.
     */
    public static function formatRates(array $rates): string
    {
        $lines = [];
        foreach ($rates as $name => $rate) {
            $lines[] = $name . '=' . rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
        }

        return implode("\n", $lines);
    }
}
