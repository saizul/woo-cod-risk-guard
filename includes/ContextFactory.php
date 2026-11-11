<?php
/**
 * Builds engine contexts from WooCommerce data.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Otp\OtpVerifierInterface;
use Saizul\CodRiskGuard\WP\Checkout\DeviceId;
use Throwable;

/**
 * Translates WooCommerce orders and checkout state into the framework
 * agnostic OrderContext consumed by the scoring engine.
 */
final class ContextFactory
{
    private OtpVerifierInterface $otp;

    private DeviceId $device;

    public function __construct(OtpVerifierInterface $otp, DeviceId $device)
    {
        $this->otp    = $otp;
        $this->device = $device;
    }

    /**
     * Context for a stored order.
     */
    public function fromOrder(\WC_Order $order): OrderContext
    {
        $shipToBilling = '' === trim((string) $order->get_shipping_address_1());

        $country = $shipToBilling ? (string) $order->get_billing_country() : (string) $order->get_shipping_country();
        $state   = $shipToBilling ? (string) $order->get_billing_state() : (string) $order->get_shipping_state();

        $total = 0;
        $max   = 0;
        $lines = 0;
        foreach ($order->get_items() as $item) {
            $quantity = (int) $item->get_quantity();
            $total   += $quantity;
            $max      = max($max, $quantity);
            ++$lines;
        }

        $created = $order->get_date_created();

        $data = [
            'order_id'          => (int) $order->get_id(),
            'customer_id'       => (int) $order->get_customer_id(),
            'phone'             => (string) $order->get_billing_phone(),
            'shipping_phone'    => method_exists($order, 'get_shipping_phone') ? (string) $order->get_shipping_phone() : '',
            'email'             => (string) $order->get_billing_email(),
            'first_name'        => (string) ($order->get_billing_first_name() ?: $order->get_shipping_first_name()),
            'last_name'         => (string) ($order->get_billing_last_name() ?: $order->get_shipping_last_name()),
            'ip'                => (string) $order->get_customer_ip_address(),
            'device_id'         => DeviceId::clean((string) $order->get_meta(OrderMeta::DEVICE_ID, true)),
            'total'             => (float) $order->get_total(),
            'currency'          => (string) $order->get_currency(),
            'payment_method'    => (string) $order->get_payment_method(),
            'total_quantity'    => $total,
            'max_line_quantity' => $max,
            'line_count'        => $lines,
            'address_1'         => (string) ($shipToBilling ? $order->get_billing_address_1() : $order->get_shipping_address_1()),
            'address_2'         => (string) ($shipToBilling ? $order->get_billing_address_2() : $order->get_shipping_address_2()),
            'city'              => (string) ($shipToBilling ? $order->get_billing_city() : $order->get_shipping_city()),
            'district'          => $this->stateName($country, $state),
            'postcode'          => (string) ($shipToBilling ? $order->get_billing_postcode() : $order->get_shipping_postcode()),
            'country'           => $country,
            'created_at'        => null !== $created ? $created->getTimestamp() : time(),
            'phone_verified'    => false,
            'meta'              => [],
        ];

        /**
         * Filters the raw data used to score an order.
         *
         * @param array<string, mixed> $data  Context data (see OrderContext::fromArray()).
         * @param \WC_Order|null       $order Order being scored, null during the pre-order check.
         */
        $data = apply_filters('codrg_order_context', $data, $order);

        return $this->finish(is_array($data) ? $data : []);
    }

    /**
     * Context for the checkout in progress (no order exists yet).
     *
     * @param array<string, mixed> $posted Posted checkout fields (may be empty; the
     *                                     customer session is used as a fallback).
     * @param string               $gateway Payment gateway being checked.
     */
    public function fromCheckout(array $posted, string $gateway): OrderContext
    {
        $customer = function_exists('WC') ? WC()->customer : null;
        $cart     = function_exists('WC') ? WC()->cart : null;

        $read = static function (string $field, string $getter) use ($posted, $customer): string {
            if (isset($posted[$field]) && is_scalar($posted[$field]) && '' !== trim((string) $posted[$field])) {
                return trim((string) $posted[$field]);
            }

            if (null !== $customer && is_callable([$customer, $getter])) {
                return trim((string) $customer->{$getter}());
            }

            return '';
        };

        $shipElsewhere = ! empty($posted['ship_to_different_address']) && '' !== $read('shipping_address_1', 'get_shipping_address_1');
        $prefix        = $shipElsewhere ? 'shipping' : 'billing';

        $total = 0;
        $max   = 0;
        $lines = 0;
        $sum   = 0.0;
        if (null !== $cart) {
            foreach ($cart->get_cart() as $item) {
                $quantity = (int) ($item['quantity'] ?? 0);
                $total   += $quantity;
                $max      = max($max, $quantity);
                ++$lines;
            }
            $sum = (float) $cart->get_total('edit');
        }

        $country = $read($prefix . '_country', 'get_' . $prefix . '_country');

        $data = [
            'order_id'          => 0,
            'customer_id'       => (int) get_current_user_id(),
            'phone'             => $read('billing_phone', 'get_billing_phone'),
            'shipping_phone'    => $shipElsewhere ? $read('shipping_phone', 'get_shipping_phone') : '',
            'email'             => $read('billing_email', 'get_billing_email'),
            'first_name'        => $read('billing_first_name', 'get_billing_first_name'),
            'last_name'         => $read('billing_last_name', 'get_billing_last_name'),
            'ip'                => class_exists('WC_Geolocation') ? (string) \WC_Geolocation::get_ip_address() : '',
            'device_id'         => $this->device->current(),
            'total'             => $sum,
            'currency'          => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'BDT',
            'payment_method'    => $gateway,
            'total_quantity'    => $total,
            'max_line_quantity' => $max,
            'line_count'        => $lines,
            'address_1'         => $read($prefix . '_address_1', 'get_' . $prefix . '_address_1'),
            'address_2'         => $read($prefix . '_address_2', 'get_' . $prefix . '_address_2'),
            'city'              => $read($prefix . '_city', 'get_' . $prefix . '_city'),
            'district'          => $this->stateName($country, $read($prefix . '_state', 'get_' . $prefix . '_state')),
            'postcode'          => $read($prefix . '_postcode', 'get_' . $prefix . '_postcode'),
            'country'           => $country,
            'created_at'        => time(),
            'phone_verified'    => false,
            'meta'              => [],
        ];

        /** This filter is documented in includes/ContextFactory.php */
        $data = apply_filters('codrg_order_context', $data, null);

        return $this->finish(is_array($data) ? $data : []);
    }

    /**
     * Build the context and ask the OTP integration whether the phone is verified.
     *
     * @param array<string, mixed> $data Context data.
     */
    private function finish(array $data): OrderContext
    {
        $context = OrderContext::fromArray($data);

        if ($context->phoneVerified) {
            return $context;
        }

        try {
            $verified = $this->otp->isPhoneVerified($context);
        } catch (Throwable $e) {
            $verified = false;
        }

        return $verified ? $context->with(['phone_verified' => true]) : $context;
    }

    /**
     * Human readable state / district name for a WooCommerce state code.
     *
     * Bangladesh districts are stored by WooCommerce as codes such as
     * "BD-13"; the district return rate rule works on names.
     */
    private function stateName(string $country, string $state): string
    {
        $state = trim($state);
        if ('' === $state || '' === $country || ! function_exists('WC') || ! isset(WC()->countries)) {
            return $state;
        }

        $states = WC()->countries->get_states($country);

        if (is_array($states) && isset($states[$state]) && is_string($states[$state])) {
            return html_entity_decode($states[$state], ENT_QUOTES, 'UTF-8');
        }

        return $state;
    }
}
