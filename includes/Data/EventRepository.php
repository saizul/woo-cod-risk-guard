<?php
/**
 * Order event storage.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Data;

use DateTimeImmutable;
use Saizul\CodRiskGuard\History\VelocityProviderInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\WP\Schema;

/**
 * One row per scored order, holding only hashed identifiers.
 *
 * Used to answer "how many other orders came from this phone / IP / device
 * recently". Old rows are pruned by the daily maintenance job.
 */
final class EventRepository implements VelocityProviderInterface
{
    /**
     * Column for each identifier type that can be counted.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        IdentifierHasher::TYPE_PHONE  => 'phone_hash',
        IdentifierHasher::TYPE_IP     => 'ip_hash',
        IdentifierHasher::TYPE_DEVICE => 'device_hash',
    ];

    /**
     * {@inheritDoc}
     */
    public function countSince(string $type, string $hash, DateTimeImmutable $since, int $excludeOrderId = 0): int
    {
        if ('' === $hash || ! isset(self::COLUMNS[$type])) {
            return 0;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must be fresh for velocity checks.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT order_id) FROM %i WHERE %i = %s AND created_at >= %s AND order_id <> %d',
                Schema::eventsTable(),
                self::COLUMNS[$type],
                $hash,
                HistoryRepository::format($since),
                $excludeOrderId
            )
        );
    }

    /**
     * Whether an event row exists for the order.
     */
    public function existsForOrder(int $orderId): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $found = $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE order_id = %d LIMIT 1', Schema::eventsTable(), $orderId)
        );

        return null !== $found;
    }

    /**
     * Store (or replace) the event of an order.
     *
     * @param int                   $orderId Order id.
     * @param array<string, string> $hashes  Identifier hashes keyed by identifier type.
     * @param DateTimeImmutable     $at      When the order was placed.
     *
     * @return bool True when the order had no event yet (a new order).
     */
    public function record(int $orderId, array $hashes, DateTimeImmutable $at): bool
    {
        global $wpdb;

        $isNew = ! $this->existsForOrder($orderId);

        if (! $isNew) {
            $this->deleteForOrder($orderId);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table write.
        $wpdb->insert(
            Schema::eventsTable(),
            [
                'order_id'    => $orderId,
                'phone_hash'  => (string) ($hashes[IdentifierHasher::TYPE_PHONE] ?? ''),
                'ip_hash'     => (string) ($hashes[IdentifierHasher::TYPE_IP] ?? ''),
                'device_hash' => (string) ($hashes[IdentifierHasher::TYPE_DEVICE] ?? ''),
                'created_at'  => HistoryRepository::format($at),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        return $isNew;
    }

    /**
     * Delete the event of one order.
     */
    public function deleteForOrder(int $orderId): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table write.
        return (int) $wpdb->delete(Schema::eventsTable(), ['order_id' => $orderId], ['%d']);
    }

    /**
     * Delete events older than the given date.
     */
    public function prune(DateTimeImmutable $before): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
        return (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE created_at < %s',
                Schema::eventsTable(),
                HistoryRepository::format($before)
            )
        );
    }

    /**
     * Number of stored events.
     */
    public function count(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, used by CLI / stats only.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', Schema::eventsTable()));
    }
}
