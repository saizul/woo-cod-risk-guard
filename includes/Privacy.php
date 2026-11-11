<?php
/**
 * Personal data exporter, eraser and privacy policy text.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

use Saizul\CodRiskGuard\Support\IdentifierHasher;

/**
 * Integrates with the WordPress personal data tools.
 *
 * The plugin never stores a raw phone number of its own: it keeps keyed
 * hashes. The exporter reports the score stored on the person's orders and
 * the delivery history kept for the phone numbers used on those orders; the
 * eraser removes both.
 */
final class Privacy
{
    private const PER_PAGE = 20;

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
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
        add_action('admin_init', [$this, 'addPolicyContent']);
    }

    /**
     * @param mixed $exporters Registered exporters.
     *
     * @return mixed
     */
    public function registerExporter($exporters)
    {
        if (is_array($exporters)) {
            $exporters['woo-cod-risk-guard'] = [
                'exporter_friendly_name' => __('COD Risk Guard', 'woo-cod-risk-guard'),
                'callback'               => [$this, 'export'],
            ];
        }

        return $exporters;
    }

    /**
     * @param mixed $erasers Registered erasers.
     *
     * @return mixed
     */
    public function registerEraser($erasers)
    {
        if (is_array($erasers)) {
            $erasers['woo-cod-risk-guard'] = [
                'eraser_friendly_name' => __('COD Risk Guard', 'woo-cod-risk-guard'),
                'callback'             => [$this, 'erase'],
            ];
        }

        return $erasers;
    }

    /**
     * Suggested privacy policy text.
     */
    public function addPolicyContent(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p>' . esc_html__('When you place a cash-on-delivery order we calculate a risk score to protect the store against fake orders. To do this we keep a keyed hash (not the number itself) of your phone number together with counts of delivered, returned and cancelled orders, and hashes of your IP address and a first-party device cookie for a limited time. The score and the reasons for it are stored with your order.', 'woo-cod-risk-guard') . '</p>';

        wp_add_privacy_policy_content(__('COD Risk Guard', 'woo-cod-risk-guard'), wp_kses_post($content));
    }

    /**
     * Orders that belong to an email address.
     *
     * @return \WC_Order[]
     */
    private function ordersFor(string $email, int $page): array
    {
        $orders = wc_get_orders(
            [
                'type'          => 'shop_order',
                'billing_email' => $email,
                'limit'         => self::PER_PAGE,
                'page'          => max(1, $page),
                'orderby'       => 'date',
                'order'         => 'ASC',
            ]
        );

        return is_array($orders) ? array_values(array_filter($orders, static function ($order): bool {
            return $order instanceof \WC_Order;
        })) : [];
    }

    /**
     * Phone hash for an order: the stored one, or computed from the billing phone.
     */
    private function phoneHash(\WC_Order $order): string
    {
        $hash = (string) $order->get_meta(OrderMeta::PHONE_HASH, true);
        if ('' !== $hash) {
            return $hash;
        }

        $phone = (string) $order->get_billing_phone();

        return '' !== $phone ? $this->plugin->hasher()->hash(IdentifierHasher::TYPE_PHONE, $phone) : '';
    }

    /**
     * Exporter callback.
     *
     * @param string $email Email address.
     * @param int    $page  Page number.
     *
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public function export($email, $page = 1): array
    {
        $orders = $this->ordersFor((string) $email, (int) $page);
        $data   = [];

        foreach ($orders as $order) {
            $result = $this->plugin->scorer()->stored($order);
            if (null === $result) {
                continue;
            }

            $items = [
                [
                    'name'  => __('Order', 'woo-cod-risk-guard'),
                    'value' => $order->get_order_number(),
                ],
                [
                    'name'  => __('Risk score', 'woo-cod-risk-guard'),
                    'value' => $result->score,
                ],
                [
                    'name'  => __('Risk level', 'woo-cod-risk-guard'),
                    'value' => Presenter::levelLabel($result->level),
                ],
                [
                    'name'  => __('Reasons', 'woo-cod-risk-guard'),
                    'value' => implode('; ', Presenter::reasons($result)),
                ],
            ];

            $hash = $this->phoneHash($order);
            if ('' !== $hash) {
                $history = $this->plugin->history()->getByPhoneHash($hash);
                $items[] = [
                    'name'  => __('Order history for this phone number', 'woo-cod-risk-guard'),
                    'value' => sprintf(
                        /* translators: 1: total orders, 2: delivered, 3: returned, 4: cancelled. */
                        __('%1$d orders: %2$d delivered, %3$d returned, %4$d cancelled', 'woo-cod-risk-guard'),
                        $history->total,
                        $history->delivered,
                        $history->returned,
                        $history->cancelled
                    ),
                ];
            }

            $data[] = [
                'group_id'    => 'codrg_risk',
                'group_label' => __('COD risk scores', 'woo-cod-risk-guard'),
                'item_id'     => 'codrg-order-' . $order->get_id(),
                'data'        => $items,
            ];
        }

        return [
            'data' => $data,
            'done' => count($orders) < self::PER_PAGE,
        ];
    }

    /**
     * Eraser callback.
     *
     * @param string $email Email address.
     * @param int    $page  Page number.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
     */
    public function erase($email, $page = 1): array
    {
        // Erasing removes the meta the query could filter on, but the query
        // filters on the billing email only, so paging stays stable.
        $orders  = $this->ordersFor((string) $email, (int) $page);
        $removed = false;

        foreach ($orders as $order) {
            $hash = $this->phoneHash($order);
            if ('' !== $hash && $this->plugin->history()->deleteByPhoneHash($hash) > 0) {
                $removed = true;
            }

            if ($this->plugin->scorer()->isScored($order)) {
                $removed = true;
            }

            $this->plugin->scorer()->forget($order);
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => false,
            'messages'       => [],
            'done'           => count($orders) < self::PER_PAGE,
        ];
    }
}
