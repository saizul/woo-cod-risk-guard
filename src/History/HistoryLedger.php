<?php
/**
 * History bookkeeping.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\History;

/**
 * Pure bookkeeping helpers that keep the per-phone counters correct when an
 * order moves between outcomes (for example completed, then refunded).
 */
final class HistoryLedger
{
    /**
     * Counter changes needed when an order's counted outcome changes.
     *
     * @param string|null $previous Outcome already counted for the order (null when none).
     * @param string|null $current  Outcome the order has now (null while open).
     *
     * @return array{delivered:int, returned:int, cancelled:int}
     */
    public static function delta(?string $previous, ?string $current): array
    {
        $delta = [
            Outcome::DELIVERED => 0,
            Outcome::RETURNED  => 0,
            Outcome::CANCELLED => 0,
        ];

        $previous = Outcome::isValid($previous) ? $previous : null;
        $current  = Outcome::isValid($current) ? $current : null;

        if ($previous === $current) {
            return $delta;
        }

        if (null !== $previous) {
            --$delta[$previous];
        }

        if (null !== $current) {
            ++$delta[$current];
        }

        return $delta;
    }

    /**
     * Whether a delta changes anything.
     *
     * @param array<string, int> $delta Counter changes.
     */
    public static function isNoop(array $delta): bool
    {
        foreach ($delta as $change) {
            if (0 !== (int) $change) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a delta to a history, never letting counters go negative.
     *
     * @param array<string, int> $delta Counter changes (delivered, returned, cancelled and optionally total).
     */
    public static function apply(CustomerHistory $history, array $delta): CustomerHistory
    {
        return new CustomerHistory(
            $history->total + (int) ($delta['total'] ?? 0),
            $history->delivered + (int) ($delta[Outcome::DELIVERED] ?? 0),
            $history->returned + (int) ($delta[Outcome::RETURNED] ?? 0),
            $history->cancelled + (int) ($delta[Outcome::CANCELLED] ?? 0)
        );
    }
}
