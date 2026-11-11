<?php
/**
 * CSV generation.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Export;

/**
 * Minimal RFC 4180 CSV writer with spreadsheet formula-injection protection.
 */
final class CsvWriter
{
    private string $delimiter;

    private bool $bom;

    /**
     * @param string $delimiter Field delimiter.
     * @param bool   $bom       Prefix output with a UTF-8 byte order mark (helps Excel with Bangla text).
     */
    public function __construct(string $delimiter = ',', bool $bom = true)
    {
        $this->delimiter = '' === $delimiter ? ',' : $delimiter[0];
        $this->bom       = $bom;
    }

    /**
     * Render a header and rows as a CSV document.
     *
     * @param string[]                         $header Column headings.
     * @param iterable<array<array-key,mixed>> $rows   Data rows.
     */
    public function toString(array $header, iterable $rows): string
    {
        $out = $this->bom ? "\xEF\xBB\xBF" : '';
        $out .= $this->line($header);

        foreach ($rows as $row) {
            $out .= $this->line($row);
        }

        return $out;
    }

    /**
     * Render a single CSV line (including the trailing line break).
     *
     * @param array<array-key, mixed> $cells Cell values.
     */
    public function line(array $cells): string
    {
        $escaped = [];
        foreach ($cells as $cell) {
            $escaped[] = $this->escapeCell($cell);
        }

        return implode($this->delimiter, $escaped) . "\r\n";
    }

    /**
     * Escape one cell.
     *
     * @param mixed $value Cell value.
     */
    public function escapeCell($value): string
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = implode(' | ', array_map('strval', array_filter($value, 'is_scalar')));
        } elseif (null === $value || ! is_scalar($value)) {
            $value = '';
        }

        $text = self::neutraliseFormula((string) $value);

        if (false !== strpbrk($text, "\"\r\n" . $this->delimiter) || $text !== trim($text)) {
            return '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }

    /**
     * Prefix values that a spreadsheet would execute as a formula.
     */
    public static function neutraliseFormula(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        // Plain numbers (including negatives) are safe.
        if (is_numeric($value)) {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
