<?php
/**
 * Uninstall routine.
 *
 * Data is removed only when "Delete data on uninstall" is enabled in the
 * settings, so that deleting the plugin by mistake does not destroy the
 * customer history.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$codrg_settings = get_option('codrg_settings', []);

if (! is_array($codrg_settings) || empty($codrg_settings['delete_on_uninstall'])) {
    return;
}

global $wpdb;

// Scheduled maintenance.
wp_clear_scheduled_hook('codrg_daily_maintenance');
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('codrg_daily_maintenance');
}

// Custom tables. Table names are built from the trusted prefix and fixed suffixes.
foreach (['codrg_customer_history', 'codrg_order_events', 'codrg_lists'] as $codrg_table) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $codrg_table));
}

// Options.
delete_option('codrg_settings');
delete_option('codrg_db_version');
delete_option('codrg_hash_secret');

// Order meta is left in place: it belongs to the orders and is removed with
// them, or through the personal data eraser.
