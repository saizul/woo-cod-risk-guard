<?php
/**
 * Blocklist / allowlist storage.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Data;

use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\WP\Schema;

/**
 * Merchant managed blocklist and allowlist.
 *
 * Only the keyed hash of each identifier is stored, together with a masked
 * label (for example "017*****001") so that entries stay recognisable in
 * the admin without keeping the full value.
 */
final class ListRepository implements ListStoreInterface
{
    private IdentifierHasher $hasher;

    /**
     * Per-request lookup cache.
     *
     * @var array<string, bool>
     */
    private array $cache = [];

    public function __construct(IdentifierHasher $hasher)
    {
        $this->hasher = $hasher;
    }

    /**
     * Whether the list name is valid.
     */
    public static function isValidList(string $list): bool
    {
        return in_array($list, [self::BLOCK, self::ALLOW], true);
    }

    /**
     * {@inheritDoc}
     */
    public function contains(string $list, string $type, string $hash): bool
    {
        if ('' === $hash || ! self::isValidList($list)) {
            return false;
        }

        $key = $list . '|' . $type . '|' . $hash;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table, cached per request above.
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE list_name = %s AND id_type = %s AND id_hash = %s LIMIT 1',
                Schema::listsTable(),
                $list,
                $type,
                $hash
            )
        );

        $this->cache[$key] = null !== $found;

        return $this->cache[$key];
    }

    /**
     * Whether a raw value (phone number, email, ...) is on a list.
     */
    public function containsValue(string $list, string $type, string $value): bool
    {
        return $this->contains($list, $type, $this->hasher->hash($type, $value));
    }

    /**
     * Add a raw value to a list.
     *
     * @return bool False when the value is empty / invalid or the entry already exists.
     */
    public function addValue(string $list, string $type, string $value, string $note = ''): bool
    {
        if (! self::isValidList($list) || ! IdentifierHasher::isValidType($type)) {
            return false;
        }

        $hash = $this->hasher->hash($type, $value);
        if ('' === $hash || $this->contains($list, $type, $hash)) {
            return false;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table write.
        $done = $wpdb->insert(
            Schema::listsTable(),
            [
                'list_name'  => $list,
                'id_type'    => $type,
                'id_hash'    => $hash,
                'label'      => self::truncate($this->hasher->mask($type, $value), 100),
                'note'       => self::truncate(sanitize_text_field($note), 255),
                'created_at' => HistoryRepository::format(null),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        $this->cache = [];

        return false !== $done && $done > 0;
    }

    /**
     * Remove a raw value from a list.
     */
    public function removeValue(string $list, string $type, string $value): bool
    {
        return $this->removeHash($list, $type, $this->hasher->hash($type, $value));
    }

    /**
     * Remove an entry by hash.
     */
    public function removeHash(string $list, string $type, string $hash): bool
    {
        if ('' === $hash || ! self::isValidList($list)) {
            return false;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table write.
        $deleted = $wpdb->delete(
            Schema::listsTable(),
            [
                'list_name' => $list,
                'id_type'   => $type,
                'id_hash'   => $hash,
            ],
            ['%s', '%s', '%s']
        );

        $this->cache = [];

        return (int) $deleted > 0;
    }

    /**
     * Remove an entry by row id.
     */
    public function removeById(int $id): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table write.
        $deleted = $wpdb->delete(Schema::listsTable(), ['id' => $id], ['%d']);

        $this->cache = [];

        return (int) $deleted > 0;
    }

    /**
     * Entries of a list, newest first.
     *
     * @return array<int, array{id:int, list:string, type:string, label:string, note:string, created_at:string}>
     */
    public function entries(string $list, int $limit = 200, int $offset = 0): array
    {
        if (! self::isValidList($list)) {
            return [];
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin / CLI listing.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, list_name, id_type, label, note, created_at FROM %i WHERE list_name = %s ORDER BY id DESC LIMIT %d OFFSET %d',
                Schema::listsTable(),
                $list,
                max(1, min(1000, $limit)),
                max(0, $offset)
            ),
            ARRAY_A
        );

        $entries = [];
        foreach ((array) $rows as $row) {
            $entries[] = [
                'id'         => (int) $row['id'],
                'list'       => (string) $row['list_name'],
                'type'       => (string) $row['id_type'],
                'label'      => (string) $row['label'],
                'note'       => (string) $row['note'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $entries;
    }

    /**
     * Number of entries on a list.
     */
    public function count(string $list): int
    {
        if (! self::isValidList($list)) {
            return 0;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin / CLI only.
        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE list_name = %s', Schema::listsTable(), $list)
        );
    }

    /**
     * Cut a string to a column length.
     */
    private static function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
