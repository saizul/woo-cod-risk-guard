<?php
/**
 * Tiny WordPress function stubs.
 *
 * Only what the handful of framework-light integration classes under test
 * need. This is NOT a WordPress test environment.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

if (! function_exists('__')) {
    /**
     * @param string $text   Text.
     * @param string $domain Text domain.
     */
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (! function_exists('_n')) {
    /**
     * @param string $single Singular.
     * @param string $plural Plural.
     * @param int    $number Count.
     * @param string $domain Text domain.
     */
    function _n($single, $plural, $number, $domain = 'default')
    {
        return 1 === (int) $number ? $single : $plural;
    }
}
