<?php
/**
 * Database schema.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

/**
 * Creates, upgrades and removes the plugin's custom tables.
 *
 * Three small tables are used:
 *  - customer history: per phone-hash counters of delivered / returned / cancelled orders,
 *  - order events:     one row per scored order, used for velocity checks,
 *  - lists:            merchant blocklist and allowlist entries (hashed identifiers).
 *
 * No raw phone number, email address or IP address is ever written to these tables.
 */
final class Schema
{
    public const VERSION        = '1';
    public const OPTION_VERSION = 'codrg_db_version';

    /**
     * Customer history table name.
     */
    public static function historyTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'codrg_customer_history';
    }

    /**
     * Order events table name.
     */
    public static function eventsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'codrg_order_events';
    }

    /**
     * Lists table name.
     */
    public static function listsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'codrg_lists';
    }

    /**
     * All table names.
     *
     * @return string[]
     */
    public static function tables(): array
    {
        return [self::historyTable(), self::eventsTable(), self::listsTable()];
    }

    /**
     * Whether the installed schema is older than the code expects.
     */
    public static function needsUpgrade(): bool
    {
        return self::VERSION !== (string) get_option(self::OPTION_VERSION, '');
    }

    /**
     * Create or upgrade the tables.
     */
    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();
        $history = self::historyTable();
        $events  = self::eventsTable();
        $lists   = self::listsTable();

        // dbDelta() is picky: one column per line, two spaces after PRIMARY KEY.
        dbDelta(
            "CREATE TABLE {$history} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  phone_hash char(64) NOT NULL,
  orders_total int(10) unsigned NOT NULL DEFAULT 0,
  delivered int(10) unsigned NOT NULL DEFAULT 0,
  returned int(10) unsigned NOT NULL DEFAULT 0,
  cancelled int(10) unsigned NOT NULL DEFAULT 0,
  first_order_at datetime DEFAULT NULL,
  last_order_at datetime DEFAULT NULL,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY phone_hash (phone_hash),
  KEY last_order_at (last_order_at)
) {$collate};"
        );

        dbDelta(
            "CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  phone_hash char(64) NOT NULL DEFAULT '',
  ip_hash char(64) NOT NULL DEFAULT '',
  device_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY phone_created (phone_hash, created_at),
  KEY ip_created (ip_hash, created_at),
  KEY device_created (device_hash, created_at),
  KEY created_at (created_at)
) {$collate};"
        );

        dbDelta(
            "CREATE TABLE {$lists} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  list_name varchar(10) NOT NULL DEFAULT 'block',
  id_type varchar(10) NOT NULL DEFAULT 'phone',
  id_hash char(64) NOT NULL,
  label varchar(100) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY list_entry (list_name, id_type, id_hash),
  KEY id_hash (id_hash)
) {$collate};"
        );

        update_option(self::OPTION_VERSION, self::VERSION, false);
    }

    /**
     * Drop every plugin table. Used by uninstall.php only.
     */
    public static function drop(): void
    {
        global $wpdb;

        foreach (self::tables() as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from $wpdb->prefix and constants.
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        delete_option(self::OPTION_VERSION);
    }
}
