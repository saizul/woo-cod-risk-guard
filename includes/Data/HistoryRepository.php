<?php
/**
 * Customer history storage.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Data;

use DateTimeImmutable;
use DateTimeZone;
use Saizul\CodRiskGuard\History\CustomerHistory;
use Saizul\CodRiskGuard\History\HistoryProviderInterface;
use Saizul\CodRiskGuard\History\Outcome;
use Saizul\CodRiskGuard\WP\Schema;

/**
 * Per-phone order counters stored in a custom table.
 *
 * Rows are keyed by the keyed hash of the normalised phone number; the
 * number itself is never stored here.
 */
final class HistoryRepository implements HistoryProviderInterface
{
    /**
     * Per-request cache of looked-up rows.
     *
     * @var array<string, CustomerHistory>
     */
    private array $cache = [];

    /**
     * {@inheritDoc}
     */
    public function getByPhoneHash(string $phoneHash): CustomerHistory
    {
        if ('' === $phoneHash) {
            return CustomerHistory::none();
        }

        if (isset($this->cache[$phoneHash])) {
            return $this->cache[$phoneHash];
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table, cached per request above.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT orders_total, delivered, returned, cancelled FROM %i WHERE phone_hash = %s',
                Schema::historyTable(),
                $phoneHash
            ),
            ARRAY_A
        );

        $history = is_array($row) ? CustomerHistory::fromArray($row) : CustomerHistory::none();

        $this->cache[$phoneHash] = $history;

        return $history;
    }

    /**
     * Count a newly placed order for the phone hash.
     */
    public function recordOrder(string $phoneHash, ?DateTimeImmutable $at = null): bool
    {
        if ('' === $phoneHash) {
            return false;
        }

        global $wpdb;

        $when = self::format($at);
        $now  = self::format(null);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table write.
        $done = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (phone_hash, orders_total, first_order_at, last_order_at, updated_at)
                 VALUES (%s, 1, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE orders_total = orders_total + 1, last_order_at = GREATEST(COALESCE(last_order_at, VALUES(last_order_at)), VALUES(last_order_at)), updated_at = VALUES(updated_at)',
                Schema::historyTable(),
                $phoneHash,
                $when,
                $when,
                $now
            )
        );

        unset($this->cache[$phoneHash]);

        return false !== $done;
    }

    /**
     * Apply outcome counter changes (see HistoryLedger::delta()).
     *
     * Counters never drop below zero.
     *
     * @param string             $phoneHash Phone hash.
     * @param array<string, int> $delta     Changes keyed by outcome.
     */
    public function applyDelta(string $phoneHash, array $delta): bool
    {
        if ('' === $phoneHash) {
            return false;
        }

        global $wpdb;

        $delivered = (int) ($delta[Outcome::DELIVERED] ?? 0);
        $returned  = (int) ($delta[Outcome::RETURNED] ?? 0);
        $cancelled = (int) ($delta[Outcome::CANCELLED] ?? 0);

        if (0 === $delivered && 0 === $returned && 0 === $cancelled) {
            return true;
        }

        $now = self::format(null);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table write.
        $done = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (phone_hash, orders_total, delivered, returned, cancelled, updated_at)
                 VALUES (%s, 0, %d, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    delivered = GREATEST(0, CAST(delivered AS SIGNED) + %d),
                    returned = GREATEST(0, CAST(returned AS SIGNED) + %d),
                    cancelled = GREATEST(0, CAST(cancelled AS SIGNED) + %d),
                    updated_at = VALUES(updated_at)',
                Schema::historyTable(),
                $phoneHash,
                max(0, $delivered),
                max(0, $returned),
                max(0, $cancelled),
                $now,
                $delivered,
                $returned,
                $cancelled
            )
        );

        unset($this->cache[$phoneHash]);

        return false !== $done;
    }

    /**
     * Delete the counters of one phone hash (privacy erasure).
     */
    public function deleteByPhoneHash(string $phoneHash): int
    {
        if ('' === $phoneHash) {
            return 0;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table write.
        $deleted = $wpdb->delete(Schema::historyTable(), ['phone_hash' => $phoneHash], ['%s']);

        unset($this->cache[$phoneHash]);

        return (int) $deleted;
    }

    /**
     * Number of phone hashes with history.
     */
    public function count(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, used by CLI / stats only.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', Schema::historyTable()));
    }

    /**
     * Format a date for a DATETIME column (UTC).
     */
    public static function format(?DateTimeImmutable $at): string
    {
        $utc = new DateTimeZone('UTC');
        $at  = null === $at ? new DateTimeImmutable('now', $utc) : $at->setTimezone($utc);

        return $at->format('Y-m-d H:i:s');
    }
}
