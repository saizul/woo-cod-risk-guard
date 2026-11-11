<?php
/**
 * Report admin tab.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Admin;

use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\WP\Plugin;
use Saizul\CodRiskGuard\WP\Presenter;
use Saizul\CodRiskGuard\WP\StatsService;

/**
 * Shows a summary of scored orders and the CSV export form.
 */
final class ReportTab
{
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Render the tab.
     */
    public function render(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter.
        $days = isset($_GET['days']) ? absint(wp_unslash($_GET['days'])) : 30;
        $days = max(1, min(365, $days));

        $summary = $this->plugin->stats()->summary($days);

        echo '<h2>' . esc_html(
            sprintf(
                /* translators: %d: number of days. */
                _n('Scored orders in the last %d day', 'Scored orders in the last %d days', $days, 'woo-cod-risk-guard'),
                $days
            )
        ) . '</h2>';

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr(SettingsPage::SLUG) . '">';
        echo '<input type="hidden" name="tab" value="report">';
        echo '<label>' . esc_html__('Days', 'woo-cod-risk-guard') . ' <input type="number" class="small-text" name="days" min="1" max="365" value="' . esc_attr((string) $days) . '"></label> ';
        submit_button(__('Update', 'woo-cod-risk-guard'), 'secondary', '', false);
        echo '</form>';

        if (! empty($summary['truncated'])) {
            echo '<p class="description">' . esc_html(
                sprintf(
                    /* translators: %s: number of orders. */
                    __('Only the newest %s orders were analysed.', 'woo-cod-risk-guard'),
                    number_format_i18n(StatsService::MAX_ORDERS)
                )
            ) . '</p>';
        }

        echo '<table class="widefat striped" style="max-width:900px;margin-top:1em"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Risk level', 'woo-cod-risk-guard') . '</th>';
        echo '<th scope="col">' . esc_html__('Orders', 'woo-cod-risk-guard') . '</th>';
        echo '<th scope="col">' . esc_html__('Delivered', 'woo-cod-risk-guard') . '</th>';
        echo '<th scope="col">' . esc_html__('Returned', 'woo-cod-risk-guard') . '</th>';
        echo '<th scope="col">' . esc_html__('Cancelled', 'woo-cod-risk-guard') . '</th>';
        echo '<th scope="col">' . esc_html__('Failure rate', 'woo-cod-risk-guard') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (RiskLevel::all() as $level) {
            $outcomes = (array) ($summary['outcomes'][$level] ?? []);
            $rate     = $summary['failure_rate_by_level'][$level] ?? null;

            echo '<tr>';
            echo '<td>' . esc_html(Presenter::levelLabel($level)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($summary['by_level'][$level] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($outcomes['delivered'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($outcomes['returned'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($outcomes['cancelled'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(null === $rate ? '—' : number_format_i18n(100 * (float) $rate, 1) . '%') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p>' . esc_html(
            sprintf(
                /* translators: 1: number of orders, 2: average score. */
                __('Total scored orders: %1$s. Average score: %2$s.', 'woo-cod-risk-guard'),
                number_format_i18n((int) $summary['total']),
                number_format_i18n((float) $summary['average_score'], 1)
            )
        ) . '</p>';

        $this->renderTopRules((array) ($summary['top_rules'] ?? []));
        $this->renderExportForm($days);
    }

    /**
     * Most frequently triggered rules.
     *
     * @param array<string, int> $rules Trigger counts keyed by rule id.
     */
    private function renderTopRules(array $rules): void
    {
        if ([] === $rules) {
            return;
        }

        echo '<h2>' . esc_html__('Most triggered rules', 'woo-cod-risk-guard') . '</h2>';
        echo '<table class="widefat striped" style="max-width:500px"><tbody>';

        foreach ($rules as $id => $count) {
            $rule  = $this->plugin->registry()->get((string) $id);
            $label = null !== $rule ? Presenter::ruleLabel($rule) : (string) $id;

            echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html(number_format_i18n((int) $count)) . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * CSV export form (handled by CsvExport through admin-post.php).
     */
    private function renderExportForm(int $days): void
    {
        echo '<h2>' . esc_html__('Export risky orders (CSV)', 'woo-cod-risk-guard') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(CsvExport::ACTION) . '">';
        wp_nonce_field(CsvExport::ACTION);

        echo '<label>' . esc_html__('Minimum level', 'woo-cod-risk-guard') . ' <select name="level">';
        foreach ([RiskLevel::MEDIUM, RiskLevel::HIGH, RiskLevel::LOW] as $level) {
            echo '<option value="' . esc_attr($level) . '">' . esc_html(Presenter::levelLabel($level)) . '</option>';
        }
        echo '</select></label> ';

        echo '<label>' . esc_html__('Days', 'woo-cod-risk-guard') . ' <input type="number" class="small-text" name="days" min="1" max="365" value="' . esc_attr((string) $days) . '"></label> ';
        submit_button(__('Download CSV', 'woo-cod-risk-guard'), 'secondary', 'submit', false);
        echo '<p class="description">' . esc_html__('The file contains order numbers, scores and reasons, with phone numbers masked.', 'woo-cod-risk-guard') . '</p>';
        echo '</form>';
    }
}
