<?php
/**
 * Order meta box.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Admin;

use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\WP\OrderMeta;
use Saizul\CodRiskGuard\WP\Plugin;
use Saizul\CodRiskGuard\WP\Presenter;

/**
 * "COD risk" box on the order edit screen with the score, the reasons and
 * the rescore / blocklist buttons.
 */
final class MetaBox
{
    public const ACTION_RESCORE = 'codrg_rescore';
    public const ACTION_BLOCK   = 'codrg_block_phone';
    public const ACTION_UNBLOCK = 'codrg_unblock_phone';

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
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 30);
        add_action('admin_post_' . self::ACTION_RESCORE, [$this, 'handleRescore']);
        add_action('admin_post_' . self::ACTION_BLOCK, [$this, 'handleBlock']);
        add_action('admin_post_' . self::ACTION_UNBLOCK, [$this, 'handleUnblock']);
        add_action('admin_notices', [$this, 'renderNotice']);
    }

    /**
     * Register the box on the HPOS and the legacy order screens.
     */
    public function addMetaBox(): void
    {
        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        foreach (array_unique($screens) as $screen) {
            add_meta_box(
                'codrg-risk',
                __('COD risk', 'woo-cod-risk-guard'),
                [$this, 'render'],
                $screen,
                'side',
                'high'
            );
        }
    }

    /**
     * Nonce action for an order.
     */
    private static function nonce(string $action, int $orderId): string
    {
        return $action . '_' . $orderId;
    }

    /**
     * Nonce protected admin-post URL for an order action.
     */
    private static function actionUrl(string $action, int $orderId): string
    {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action'   => $action,
                    'order_id' => $orderId,
                ],
                admin_url('admin-post.php')
            ),
            self::nonce($action, $orderId)
        );
    }

    /**
     * Render the box.
     *
     * @param \WP_Post|\WC_Order|mixed $object Post (legacy) or order (HPOS).
     */
    public function render($object): void
    {
        $order = $object instanceof \WC_Order ? $object : null;
        if (null === $order && is_object($object) && isset($object->ID)) {
            $found = wc_get_order((int) $object->ID);
            $order = $found instanceof \WC_Order ? $found : null;
        }

        if (null === $order) {
            return;
        }

        $orderId = (int) $order->get_id();
        $result  = $this->plugin->scorer()->stored($order);

        echo '<style>' . Presenter::badgeCss() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS.

        if (null === $result) {
            echo '<p>' . esc_html__('This order has not been scored.', 'woo-cod-risk-guard') . '</p>';
        } else {
            $action = (string) $order->get_meta(OrderMeta::ACTION, true);

            echo '<p>';
            echo Presenter::badge($result->score, $result->level); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Presenter::badge().
            echo ' <strong>' . esc_html(Presenter::levelLabel($result->level)) . '</strong>';
            if ('' !== $action) {
                echo '<br><span class="description">' . esc_html(
                    sprintf(
                        /* translators: %s: action name. */
                        __('Action at checkout: %s', 'woo-cod-risk-guard'),
                        Presenter::actionLabel($action)
                    )
                ) . '</span>';
            }
            echo '</p>';

            $triggered = $result->triggered();
            if ([] === $triggered) {
                echo '<p>' . esc_html__('No risk signals were found.', 'woo-cod-risk-guard') . '</p>';
            } else {
                echo '<ul class="codrg-reasons">';
                foreach ($triggered as $rule) {
                    echo '<li>' . esc_html(Presenter::reason($rule)) . ' <span class="codrg-points">(' . esc_html(Presenter::points($rule)) . ')</span></li>';
                }
                echo '</ul>';
            }

            $scoredAt = (int) $order->get_meta(OrderMeta::SCORED_AT, true);
            if ($scoredAt > 0) {
                echo '<p class="description">' . esc_html(
                    sprintf(
                        /* translators: %s: date and time. */
                        __('Scored on %s', 'woo-cod-risk-guard'),
                        wp_date(get_option('date_format') . ' ' . get_option('time_format'), $scoredAt)
                    )
                ) . '</p>';
            }
        }

        if (! current_user_can('edit_shop_orders') || $orderId <= 0) {
            return;
        }

        echo '<p>';
        echo '<a class="button" href="' . esc_url(self::actionUrl(self::ACTION_RESCORE, $orderId)) . '">' . esc_html__('Score again', 'woo-cod-risk-guard') . '</a> ';

        $phone = (string) $order->get_billing_phone();
        if ('' !== $phone && current_user_can(Plugin::CAPABILITY)) {
            if ($this->plugin->lists()->containsValue(ListStoreInterface::BLOCK, IdentifierHasher::TYPE_PHONE, $phone)) {
                echo '<a class="button" href="' . esc_url(self::actionUrl(self::ACTION_UNBLOCK, $orderId)) . '">' . esc_html__('Remove phone from blocklist', 'woo-cod-risk-guard') . '</a>';
            } else {
                echo '<a class="button" href="' . esc_url(self::actionUrl(self::ACTION_BLOCK, $orderId)) . '">' . esc_html__('Blocklist this phone', 'woo-cod-risk-guard') . '</a>';
            }
        }
        echo '</p>';
    }

    /**
     * Verify the request and return the order, or stop.
     */
    private function authorise(string $action, string $capability): \WC_Order
    {
        if (! current_user_can($capability)) {
            wp_die(esc_html__('You do not have permission to do this.', 'woo-cod-risk-guard'), '', ['response' => 403]);
        }

        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;

        check_admin_referer(self::nonce($action, $orderId));

        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if (! $order instanceof \WC_Order) {
            wp_die(esc_html__('Order not found.', 'woo-cod-risk-guard'), '', ['response' => 404]);
        }

        return $order;
    }

    /**
     * Go back to the order screen with a notice.
     *
     * @return never
     */
    private function back(\WC_Order $order, string $notice): void
    {
        $url = wp_get_referer();
        if (! is_string($url) || '' === $url) {
            $url = $order->get_edit_order_url();
        }

        wp_safe_redirect(add_query_arg('codrg_notice', $notice, remove_query_arg('codrg_notice', $url)));
        exit;
    }

    /**
     * "Score again" handler.
     */
    public function handleRescore(): void
    {
        $order = $this->authorise(self::ACTION_RESCORE, 'edit_shop_orders');

        $this->plugin->scorer()->rescore($order);
        $this->back($order, 'rescored');
    }

    /**
     * "Blocklist this phone" handler.
     */
    public function handleBlock(): void
    {
        $order = $this->authorise(self::ACTION_BLOCK, Plugin::CAPABILITY);

        $added = $this->plugin->lists()->addValue(
            ListStoreInterface::BLOCK,
            IdentifierHasher::TYPE_PHONE,
            (string) $order->get_billing_phone(),
            /* translators: %s: order number. */
            sprintf(__('Added from order %s', 'woo-cod-risk-guard'), $order->get_order_number())
        );

        $this->back($order, $added ? 'blocked' : 'failed');
    }

    /**
     * "Remove phone from blocklist" handler.
     */
    public function handleUnblock(): void
    {
        $order = $this->authorise(self::ACTION_UNBLOCK, Plugin::CAPABILITY);

        $removed = $this->plugin->lists()->removeValue(
            ListStoreInterface::BLOCK,
            IdentifierHasher::TYPE_PHONE,
            (string) $order->get_billing_phone()
        );

        $this->back($order, $removed ? 'unblocked' : 'failed');
    }

    /**
     * Notice after one of the handlers.
     */
    public function renderNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a fixed message.
        $code = isset($_GET['codrg_notice']) ? sanitize_key(wp_unslash($_GET['codrg_notice'])) : '';

        $messages = [
            'rescored'  => ['success', __('The order was scored again.', 'woo-cod-risk-guard')],
            'blocked'   => ['success', __('The phone number was added to the blocklist.', 'woo-cod-risk-guard')],
            'unblocked' => ['success', __('The phone number was removed from the blocklist.', 'woo-cod-risk-guard')],
            'failed'    => ['error', __('The request could not be completed.', 'woo-cod-risk-guard')],
        ];

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id     = null !== $screen ? (string) $screen->id : '';

        if (! isset($messages[$code]) || (false === strpos($id, 'shop_order') && false === strpos($id, 'wc-orders'))) {
            return;
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($messages[$code][0]),
            esc_html($messages[$code][1])
        );
    }
}
