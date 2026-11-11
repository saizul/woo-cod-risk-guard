<?php
/**
 * CSV export of risky orders.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Admin;

use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\Export\CsvWriter;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\WP\OrderMeta;
use Saizul\CodRiskGuard\WP\Plugin;
use Saizul\CodRiskGuard\WP\Presenter;

/**
 * Streams a CSV file of scored orders.
 *
 * The handler checks the capability and the nonce, and the CSV writer
 * neutralises cells that spreadsheet software would treat as formulas.
 */
final class CsvExport
{
    public const ACTION = 'codrg_export_csv';

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
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * Column headings.
     *
     * @return string[]
     */
    public static function header(): array
    {
        return [
            __('Order', 'woo-cod-risk-guard'),
            __('Date', 'woo-cod-risk-guard'),
            __('Status', 'woo-cod-risk-guard'),
            __('Score', 'woo-cod-risk-guard'),
            __('Level', 'woo-cod-risk-guard'),
            __('Action', 'woo-cod-risk-guard'),
            __('Total', 'woo-cod-risk-guard'),
            __('Currency', 'woo-cod-risk-guard'),
            __('Payment method', 'woo-cod-risk-guard'),
            __('Phone (masked)', 'woo-cod-risk-guard'),
            __('District / state', 'woo-cod-risk-guard'),
            __('Outcome', 'woo-cod-risk-guard'),
            __('Reasons', 'woo-cod-risk-guard'),
        ];
    }

    /**
     * CSV row for one order.
     *
     * @return array<int, scalar>
     */
    public function row(\WC_Order $order): array
    {
        $result  = $this->plugin->scorer()->stored($order);
        $created = $order->get_date_created();
        $action  = (string) $order->get_meta(OrderMeta::ACTION, true);
        $level   = (string) $order->get_meta(OrderMeta::LEVEL, true);

        return [
            (string) $order->get_order_number(),
            null !== $created ? $created->date('Y-m-d H:i') : '',
            (string) $order->get_status(),
            (int) $order->get_meta(OrderMeta::SCORE, true),
            Presenter::levelLabel($level),
            '' !== $action ? Presenter::actionLabel($action) : '',
            (string) $order->get_total(),
            (string) $order->get_currency(),
            (string) $order->get_payment_method(),
            IdentifierHasher::maskPhone((string) $order->get_billing_phone()),
            (string) ($order->get_shipping_state() ?: $order->get_billing_state()),
            (string) $order->get_meta(OrderMeta::COUNTED_OUTCOME, true),
            null !== $result ? implode(' | ', Presenter::reasons($result)) : '',
        ];
    }

    /**
     * Build the CSV document.
     */
    public function build(int $days, string $minLevel): string
    {
        $rows = [];
        foreach ($this->plugin->stats()->scoredOrders($days, $minLevel) as $order) {
            $rows[] = $this->row($order);
        }

        return (new CsvWriter())->toString(self::header(), $rows);
    }

    /**
     * admin-post.php handler.
     */
    public function handle(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to export orders.', 'woo-cod-risk-guard'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION);

        $days  = isset($_POST['days']) ? absint(wp_unslash($_POST['days'])) : 30;
        $days  = max(1, min(365, $days));
        $level = isset($_POST['level']) ? sanitize_key(wp_unslash($_POST['level'])) : RiskLevel::MEDIUM;
        $level = RiskLevel::isValid($level) ? $level : RiskLevel::MEDIUM;

        $csv = $this->build($days, $level);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cod-risk-orders-' . gmdate('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');

        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells are escaped by CsvWriter.
        exit;
    }
}
