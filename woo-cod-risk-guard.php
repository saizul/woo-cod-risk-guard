<?php
/**
 * Plugin Name:       COD Risk Guard for WooCommerce
 * Plugin URI:        https://github.com/saizul/woo-cod-risk-guard
 * Description:       Scores cash-on-delivery orders for fake-order and return risk, and lets you hold, restrict or block risky orders. Built for stores in Bangladesh and South Asia.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Saizul Amin
 * Author URI:        https://saizul.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-cod-risk-guard
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (PHP_VERSION_ID < 80100) {
    add_action(
        'admin_notices',
        static function (): void {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>' . esc_html__('COD Risk Guard requires PHP 8.1 or newer.', 'woo-cod-risk-guard') . '</p></div>';
            }
        }
    );

    return;
}

define('CODRG_FILE', __FILE__);

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Fallback PSR-4 autoloader so the plugin works from a plain checkout.
    spl_autoload_register(
        static function (string $class): void {
            $prefixes = [
                'Saizul\\CodRiskGuard\\WP\\' => __DIR__ . '/includes/',
                'Saizul\\CodRiskGuard\\'     => __DIR__ . '/src/',
            ];

            foreach ($prefixes as $prefix => $dir) {
                if (0 !== strncmp($class, $prefix, strlen($prefix))) {
                    continue;
                }

                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_readable($file)) {
                    require_once $file;
                }

                return;
            }
        }
    );
}

// Declare compatibility with High-Performance Order Storage and the checkout block.
add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }
);

register_activation_hook(__FILE__, [\Saizul\CodRiskGuard\WP\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\Saizul\CodRiskGuard\WP\Plugin::class, 'deactivate']);

\Saizul\CodRiskGuard\WP\Plugin::boot(__FILE__);
