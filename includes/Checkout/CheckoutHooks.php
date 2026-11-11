<?php
/**
 * Checkout integration.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Checkout;

use Saizul\CodRiskGuard\Actions\Action;
use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RiskResult;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\WP\OrderMeta;
use Saizul\CodRiskGuard\WP\Plugin;

/**
 * Scores orders at checkout and enforces the configured action.
 *
 * Classic (shortcode) checkout:
 *  - woocommerce_after_checkout_validation rejects "block" and
 *    "require advance" orders before an order is created,
 *  - woocommerce_checkout_order_processed scores the created order.
 *
 * Block checkout (Store API):
 *  - woocommerce_store_api_checkout_order_processed scores the draft order
 *    and aborts the request for "block" / "require advance".
 *
 * Both:
 *  - woocommerce_available_payment_gateways hides the monitored gateways
 *    once enough is known about the customer to tell that COD is restricted,
 *  - "hold" orders are moved to on-hold instead of processing.
 */
final class CheckoutHooks
{
    private Plugin $plugin;

    /**
     * Pre-order check results cached for the request.
     *
     * @var array<string, array{result: RiskResult, action: string}>
     */
    private array $preChecks = [];

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Attach hooks.
     */
    public function register(): void
    {
        add_action('wp', [$this, 'ensureDeviceCookie']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateClassicCheckout'], 20, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'onClassicOrder'], 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'onBlockOrder'], 20, 1);
        add_filter('woocommerce_available_payment_gateways', [$this, 'filterGateways'], 20);
        add_action('woocommerce_review_order_before_payment', [$this, 'renderRestrictionNotice']);
        add_filter('woocommerce_cod_process_payment_order_status', [$this, 'filterCodStatus'], 20, 2);
        add_action('woocommerce_order_status_processing', [$this, 'maybeHold'], 20, 2);
    }

    /**
     * Whether scoring is active at all.
     */
    private function isActive(): bool
    {
        return $this->plugin->settings()->isEnabled();
    }

    /**
     * Set the device cookie on the cart and checkout pages.
     */
    public function ensureDeviceCookie(): void
    {
        if (! $this->isActive() || is_admin()) {
            return;
        }

        if ((function_exists('is_checkout') && is_checkout()) || (function_exists('is_cart') && is_cart())) {
            $this->plugin->contexts(); // Make sure the factory (and its DeviceId) exists.
            (new DeviceId())->ensure();
        }
    }

    /**
     * Evaluate the checkout in progress.
     *
     * @param array<string, mixed> $posted  Posted checkout fields.
     * @param string               $gateway Gateway being checked.
     *
     * @return array{result: RiskResult, action: string, context: OrderContext}
     */
    private function preCheck(array $posted, string $gateway): array
    {
        $context = $this->plugin->contexts()->fromCheckout($posted, $gateway);
        $data    = $context->toArray();
        unset($data['created_at']);
        $key = md5((string) wp_json_encode($data));

        if (! isset($this->preChecks[$key])) {
            $result = $this->plugin->engine()->evaluate($context);

            $this->preChecks[$key] = [
                'result' => $result,
                'action' => $this->plugin->scorer()->actionFor($result, null),
            ];
        }

        return $this->preChecks[$key] + ['context' => $context];
    }

    /**
     * Classic checkout: stop restricted orders before they are created.
     *
     * @param array<string, mixed> $data   Validated checkout fields.
     * @param \WP_Error            $errors Validation errors.
     */
    public function validateClassicCheckout($data, $errors): void
    {
        if (! $this->isActive() || ! is_array($data) || ! $errors instanceof \WP_Error) {
            return;
        }

        $gateway = isset($data['payment_method']) ? (string) $data['payment_method'] : '';
        if (! $this->plugin->settings()->isMonitoredGateway($gateway)) {
            return;
        }

        // Let WooCommerce report missing / malformed fields first.
        if ($errors->has_errors()) {
            return;
        }

        $check = $this->preCheck($data, $gateway);

        if (Action::BLOCK === $check['action']) {
            $errors->add('codrg_blocked', esc_html($this->plugin->settings()->message('block')));
        } elseif (Action::REQUIRE_ADVANCE === $check['action']) {
            $errors->add('codrg_advance', esc_html($this->plugin->settings()->message('advance')));
        }
    }

    /**
     * Classic checkout: score the created order.
     *
     * @param int|string           $orderId Order id.
     * @param array<string, mixed> $posted  Posted data.
     * @param \WC_Order|null       $order   Order object.
     *
     * @throws \Exception When the order must not go through (shown to the customer as a notice).
     */
    public function onClassicOrder($orderId, $posted = [], $order = null): void
    {
        if (! $order instanceof \WC_Order) {
            $order = wc_get_order((int) $orderId);
        }

        if (! $order instanceof \WC_Order) {
            return;
        }

        $action = $this->scoreNewOrder($order);

        // Normally already stopped by validateClassicCheckout(); this is the safety net.
        if (Action::BLOCK === $action) {
            throw new \Exception(esc_html($this->plugin->settings()->message('block')));
        }

        if (Action::REQUIRE_ADVANCE === $action) {
            throw new \Exception(esc_html($this->plugin->settings()->message('advance')));
        }
    }

    /**
     * Block checkout: score the draft order and abort restricted orders.
     *
     * @param \WC_Order $order Draft order.
     *
     * @throws \Exception When the order must not go through.
     */
    public function onBlockOrder($order): void
    {
        if (! $order instanceof \WC_Order) {
            return;
        }

        $action = $this->scoreNewOrder($order);

        if (Action::BLOCK === $action) {
            $this->abortStoreApi('codrg_blocked', $this->plugin->settings()->message('block'));
        }

        if (Action::REQUIRE_ADVANCE === $action) {
            $this->abortStoreApi('codrg_advance_required', $this->plugin->settings()->message('advance'));
        }
    }

    /**
     * Score a freshly placed order.
     *
     * @return string|null Resolved action, or null when the order is not monitored.
     */
    private function scoreNewOrder(\WC_Order $order): ?string
    {
        if (! $this->isActive()) {
            return null;
        }

        if (! $this->plugin->settings()->isMonitoredGateway((string) $order->get_payment_method())) {
            return null;
        }

        $deviceId = (new DeviceId())->current();
        if ('' !== $deviceId) {
            $order->update_meta_data(OrderMeta::DEVICE_ID, $deviceId);
        }

        $scored = $this->plugin->scorer()->score($order);

        return $scored['action'];
    }

    /**
     * Abort a Store API checkout request with a customer-facing message.
     *
     * @throws \Exception Always.
     */
    private function abortStoreApi(string $code, string $message): void
    {
        $routeException = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

        if (class_exists($routeException)) {
            throw new $routeException($code, esc_html($message), 400);
        }

        throw new \Exception(esc_html($message));
    }

    /**
     * Hide the monitored gateways when COD is restricted for this customer.
     *
     * @param mixed $gateways Available gateways keyed by id.
     *
     * @return mixed
     */
    public function filterGateways($gateways)
    {
        if (! is_array($gateways) || [] === $gateways || ! $this->isActive()) {
            return $gateways;
        }

        $monitored = array_values(array_intersect($this->plugin->settings()->gateways(), array_keys($gateways)));
        if ([] === $monitored || ! $this->isCustomerCheckoutRequest()) {
            return $gateways;
        }

        $check = $this->preCheck($this->postedReviewData(), $monitored[0]);

        if (! Action::restrictsCod($check['action']) || ! $this->knowsEnough($check['context'], $check['result'])) {
            return $gateways;
        }

        foreach ($monitored as $id) {
            unset($gateways[$id]);
        }

        return $gateways;
    }

    /**
     * Explain on the classic checkout why COD is missing.
     */
    public function renderRestrictionNotice(): void
    {
        if (! $this->isActive() || ! function_exists('WC') || null === WC()->cart) {
            return;
        }

        $gateways = $this->plugin->settings()->gateways();
        if ([] === $gateways) {
            return;
        }

        $check = $this->preCheck($this->postedReviewData(), $gateways[0]);

        if (Action::REQUIRE_ADVANCE !== $check['action'] || ! $this->knowsEnough($check['context'], $check['result'])) {
            return;
        }

        echo '<div class="woocommerce-info codrg-advance-notice">' . esc_html($this->plugin->settings()->message('advance')) . '</div>';
    }

    /**
     * Whether the gateway filter should run for this request.
     *
     * The filter must not touch the admin order screen, the "pay for order"
     * page (the order already exists) or background requests without a cart.
     */
    private function isCustomerCheckoutRequest(): bool
    {
        if (! function_exists('WC') || null === WC()->cart || null === WC()->customer) {
            return false;
        }

        if (is_admin() && ! wp_doing_ajax()) {
            return false;
        }

        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            return false;
        }

        return ! WC()->cart->is_empty();
    }

    /**
     * Whether enough of the form is filled in to act on the pre-order check.
     *
     * While the customer is still typing, empty fields would trigger the
     * "missing phone / name / address" rules and hide COD prematurely. A
     * blocklist match is acted on immediately.
     */
    private function knowsEnough(OrderContext $context, RiskResult $result): bool
    {
        if (RuleResult::OVERRIDE_BLOCK === $result->override) {
            return true;
        }

        return '' !== $context->phone && '' !== $context->firstName && '' !== $context->address1;
    }

    /**
     * Checkout fields sent with the classic "update order review" request.
     *
     * WooCommerce refreshes the payment methods through this AJAX call and
     * posts the whole form as a query string in "post_data".
     *
     * @return array<string, string>
     */
    private function postedReviewData(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the "update-order-review" nonce before gateways are filtered; data is only read for scoring.
        if (! isset($_POST['post_data']) || ! is_string($_POST['post_data'])) {
            return [];
        }

        $parsed = [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitised below.
        wp_parse_str(wp_unslash($_POST['post_data']), $parsed);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $wanted = [
            'billing_phone',
            'billing_email',
            'billing_first_name',
            'billing_last_name',
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_country',
            'shipping_phone',
            'shipping_address_1',
            'shipping_address_2',
            'shipping_city',
            'shipping_state',
            'shipping_postcode',
            'shipping_country',
            'ship_to_different_address',
        ];

        $clean = [];
        foreach ($wanted as $field) {
            if (isset($parsed[$field]) && is_scalar($parsed[$field])) {
                $clean[$field] = sanitize_text_field((string) $parsed[$field]);
            }
        }

        return $clean;
    }

    /**
     * Send "hold" COD orders to on-hold instead of processing.
     *
     * @param mixed          $status Status chosen by the COD gateway.
     * @param \WC_Order|null $order  Order.
     *
     * @return mixed
     */
    public function filterCodStatus($status, $order = null)
    {
        if (! $order instanceof \WC_Order || ! $this->shouldHold($order)) {
            return $status;
        }

        $order->update_meta_data(OrderMeta::HOLD_APPLIED, 'yes');
        $order->save_meta_data();
        $order->add_order_note(__('COD Risk Guard: order put on hold for manual review.', 'woo-cod-risk-guard'));

        return 'on-hold';
    }

    /**
     * Fallback for gateways other than the built-in COD gateway: move a
     * "hold" order from processing to on-hold, once.
     *
     * @param int|string     $orderId Order id.
     * @param \WC_Order|null $order   Order.
     */
    public function maybeHold($orderId, $order = null): void
    {
        if (! $order instanceof \WC_Order) {
            $order = wc_get_order((int) $orderId);
        }

        if (! $order instanceof \WC_Order || ! $this->shouldHold($order)) {
            return;
        }

        // Flag first so that the merchant can later release the order to processing.
        $order->update_meta_data(OrderMeta::HOLD_APPLIED, 'yes');
        $order->save_meta_data();
        $order->update_status('on-hold', __('COD Risk Guard: order put on hold for manual review.', 'woo-cod-risk-guard'));
    }

    /**
     * Whether the order is waiting for its one-time hold.
     */
    private function shouldHold(\WC_Order $order): bool
    {
        return $this->isActive()
            && Action::HOLD === (string) $order->get_meta(OrderMeta::ACTION, true)
            && 'yes' !== (string) $order->get_meta(OrderMeta::HOLD_APPLIED, true);
    }
}
