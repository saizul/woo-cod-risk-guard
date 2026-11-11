<?php
/**
 * Keeps customer history in sync with order statuses.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

use DateTimeImmutable;
use DateTimeZone;
use Saizul\CodRiskGuard\History\HistoryLedger;
use Saizul\CodRiskGuard\History\Outcome;

/**
 * Updates the per-phone delivered / returned / cancelled counters.
 *
 * Counters are updated immediately when an order changes status and are
 * reconciled once a day, which also catches status changes made by code
 * that bypasses the WooCommerce status hooks (imports, direct SQL, courier
 * integrations). The outcome already counted for an order is remembered in
 * order meta, so repeated runs never count an order twice.
 */
final class HistorySync
{
    public const HOOK  = 'codrg_daily_maintenance';
    public const GROUP = 'woo-cod-risk-guard';

    /** Orders examined per batch by the daily job. */
    private const BATCH = 100;

    /** Maximum batches per run, to bound the runtime on large stores. */
    private const MAX_BATCHES = 50;

    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Attach hooks.
     */
    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 20, 4);
        add_action(self::HOOK, [$this, 'runDaily']);
        add_action('init', [$this, 'schedule'], 20);
    }

    /**
     * Make sure the daily job is scheduled (Action Scheduler when available, WP-Cron otherwise).
     */
    public function schedule(): void
    {
        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            if (! as_has_scheduled_action(self::HOOK, [], self::GROUP)) {
                as_schedule_recurring_action(time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, [], self::GROUP);
            }

            return;
        }

        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    /**
     * Remove the scheduled job from both schedulers.
     */
    public static function unschedule(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }

        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Status change handler.
     *
     * @param int|string     $orderId Order id.
     * @param string         $from    Old status.
     * @param string         $to      New status.
     * @param \WC_Order|null $order   Order object.
     */
    public function onStatusChanged($orderId, $from = '', $to = '', $order = null): void
    {
        if (! $order instanceof \WC_Order) {
            $order = wc_get_order((int) $orderId);
        }

        if ($order instanceof \WC_Order) {
            $this->sync($order);
        }
    }

    /**
     * Bring the counters in line with the order's current status.
     *
     * @return bool True when counters were changed.
     */
    public function sync(\WC_Order $order): bool
    {
        $phoneHash = (string) $order->get_meta(OrderMeta::PHONE_HASH, true);
        if ('' === $phoneHash) {
            return false; // Never scored, or scored without a phone number.
        }

        $previous = (string) $order->get_meta(OrderMeta::COUNTED_OUTCOME, true);
        $previous = Outcome::isValid($previous) ? $previous : null;
        $current  = $this->plugin->settings()->statusClassifier()->classify((string) $order->get_status());

        $delta = HistoryLedger::delta($previous, $current);
        if (HistoryLedger::isNoop($delta)) {
            return false;
        }

        if (! $this->plugin->history()->applyDelta($phoneHash, $delta)) {
            return false;
        }

        if (null === $current) {
            $order->delete_meta_data(OrderMeta::COUNTED_OUTCOME);
        } else {
            $order->update_meta_data(OrderMeta::COUNTED_OUTCOME, $current);
        }
        $order->save_meta_data();

        /**
         * Fires after an order outcome was counted in the customer history.
         *
         * @param \WC_Order   $order    Order.
         * @param string|null $current  Outcome now counted (delivered, returned, cancelled) or null.
         * @param string|null $previous Outcome counted before, or null.
         */
        do_action('codrg_history_synced', $order, $current, $previous);

        return true;
    }

    /**
     * Daily maintenance: reconcile recently modified orders and prune old events.
     *
     * @return array{checked:int, updated:int, pruned:int}
     */
    public function runDaily(): array
    {
        $checked = 0;
        $updated = 0;

        /**
         * Filters how many days back the daily job looks for modified orders.
         *
         * @param int $days Number of days.
         */
        $days  = max(1, (int) apply_filters('codrg_reconcile_days', 3));
        $since = time() - ($days * DAY_IN_SECONDS);

        for ($page = 1; $page <= self::MAX_BATCHES; $page++) {
            $orders = wc_get_orders(
                [
                    'type'          => 'shop_order',
                    'limit'         => self::BATCH,
                    'paged'         => $page,
                    'orderby'       => 'ID',
                    'order'         => 'ASC',
                    'date_modified' => '>' . $since,
                    'meta_key'      => OrderMeta::PHONE_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded, runs in the background.
                    'meta_compare'  => 'EXISTS',
                ]
            );

            if (! is_array($orders) || [] === $orders) {
                break;
            }

            foreach ($orders as $order) {
                if (! $order instanceof \WC_Order) {
                    continue;
                }

                ++$checked;
                if ($this->sync($order)) {
                    ++$updated;
                }
            }

            if (count($orders) < self::BATCH) {
                break;
            }
        }

        $retention = max(1, (int) $this->plugin->settings()->get('retention_days', 90));
        $before    = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $retention . ' days');
        $pruned    = $this->plugin->events()->prune($before);

        return [
            'checked' => $checked,
            'updated' => $updated,
            'pruned'  => $pruned,
        ];
    }
}
