<?php
/**
 * REST endpoint for statistics.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Rest;

use Saizul\CodRiskGuard\WP\Plugin;

/**
 * GET /wp-json/codrg/v1/stats?days=30
 *
 * Returns aggregated numbers only (no customer data) and is restricted to
 * users who can manage WooCommerce.
 */
final class StatsController
{
    public const REST_NAMESPACE = 'codrg/v1';
    public const ROUTE          = '/stats';

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
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register the route.
     */
    public function registerRoutes(): void
    {
        register_rest_route(
            self::REST_NAMESPACE,
            self::ROUTE,
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'getStats'],
                'permission_callback' => [$this, 'canRead'],
                'args'                => [
                    'days' => [
                        'description'       => __('Number of days to include.', 'woo-cod-risk-guard'),
                        'type'              => 'integer',
                        'default'           => 30,
                        'minimum'           => 1,
                        'maximum'           => 365,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ]
        );
    }

    /**
     * Permission check.
     *
     * @return bool|\WP_Error
     */
    public function canRead()
    {
        if (current_user_can(Plugin::CAPABILITY)) {
            return true;
        }

        return new \WP_Error(
            'codrg_forbidden',
            __('You are not allowed to view COD risk statistics.', 'woo-cod-risk-guard'),
            ['status' => rest_authorization_required_code()]
        );
    }

    /**
     * Route callback.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response
     */
    public function getStats($request)
    {
        $days = max(1, min(365, (int) $request->get_param('days')));

        return rest_ensure_response($this->plugin->stats()->summary($days));
    }
}
