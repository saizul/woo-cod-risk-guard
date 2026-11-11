<?php
/**
 * Order list column.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Admin;

use Saizul\CodRiskGuard\WP\OrderMeta;
use Saizul\CodRiskGuard\WP\Plugin;
use Saizul\CodRiskGuard\WP\Presenter;

/**
 * Adds a "COD risk" column to the orders list, for both the HPOS orders
 * screen and the legacy posts-based screen.
 */
final class OrderColumn
{
    public const COLUMN = 'codrg_risk';

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
        // HPOS orders screen.
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addColumn'], 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'renderColumn'], 10, 2);

        // Legacy (posts) orders screen.
        add_filter('manage_edit-shop_order_columns', [$this, 'addColumn'], 20);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderColumn'], 10, 2);

        add_action('admin_head', [$this, 'printCss']);
    }

    /**
     * Insert the column after the order status.
     *
     * @param mixed $columns Existing columns.
     *
     * @return mixed
     */
    public function addColumn($columns)
    {
        if (! is_array($columns)) {
            return $columns;
        }

        $label  = __('COD risk', 'woo-cod-risk-guard');
        $result = [];

        foreach ($columns as $key => $title) {
            $result[$key] = $title;
            if ('order_status' === $key) {
                $result[self::COLUMN] = $label;
            }
        }

        if (! isset($result[self::COLUMN])) {
            $result[self::COLUMN] = $label;
        }

        return $result;
    }

    /**
     * Print the cell.
     *
     * @param string         $column Column id.
     * @param \WC_Order|int  $order  Order object (HPOS) or post id (legacy).
     */
    public function renderColumn($column, $order): void
    {
        if (self::COLUMN !== $column) {
            return;
        }

        if (! $order instanceof \WC_Order) {
            $order = wc_get_order((int) $order);
        }

        if (! $order instanceof \WC_Order) {
            return;
        }

        $level = (string) $order->get_meta(OrderMeta::LEVEL, true);
        if ('' === $level) {
            echo '<span aria-hidden="true">&ndash;</span><span class="screen-reader-text">' . esc_html__('Not scored', 'woo-cod-risk-guard') . '</span>';

            return;
        }

        // Presenter::badge() escapes its own output.
        echo Presenter::badge((int) $order->get_meta(OrderMeta::SCORE, true), $level); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Badge styles on the order screens.
     */
    public function printCss(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id     = null !== $screen ? (string) $screen->id : '';

        if (false === strpos($id, 'shop_order') && false === strpos($id, 'wc-orders')) {
            return;
        }

        echo '<style>' . Presenter::badgeCss() . '.column-' . self::COLUMN . '{width:7ch}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS.
    }
}
