<?php
/**
 * WP-CLI commands.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Cli;

use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\WP\Plugin;
use Saizul\CodRiskGuard\WP\Presenter;
use WP_CLI;

/**
 * Score cash-on-delivery orders and inspect risk statistics.
 */
final class Commands
{
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Score a single order and store the result.
     *
     * ## OPTIONS
     *
     * <order_id>
     * : The order to score.
     *
     * [--dry-run]
     * : Show the score without storing it.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cod-risk score 1234
     *     wp cod-risk score 1234 --dry-run --format=json
     *
     * @param string[]              $args  Positional arguments.
     * @param array<string, string> $assoc Named arguments.
     */
    public function score(array $args, array $assoc): void
    {
        $order = wc_get_order((int) ($args[0] ?? 0));
        if (! $order instanceof \WC_Order) {
            WP_CLI::error('Order not found.');
        }

        $scorer = $this->plugin->scorer();

        if (isset($assoc['dry-run'])) {
            $result = $scorer->evaluate($order);
            $action = $scorer->actionFor($result, $order);
        } else {
            $scored = $scorer->rescore($order, false);
            $result = $scored['result'];
            $action = $scored['action'];
        }

        if ('json' === ($assoc['format'] ?? 'table')) {
            WP_CLI::line((string) wp_json_encode(['action' => $action] + $result->toArray()));

            return;
        }

        WP_CLI::line(sprintf('Order #%s: score %d/100, level %s, action %s', $order->get_order_number(), $result->score, $result->level, $action));

        $rows = [];
        foreach ($result->triggered() as $rule) {
            $rows[] = [
                'rule'   => $rule->ruleId,
                'points' => Presenter::points($rule),
                'reason' => Presenter::reason($rule),
            ];
        }

        if ([] === $rows) {
            WP_CLI::line('No risk signals were found.');
        } else {
            \WP_CLI\Utils\format_items('table', $rows, ['rule', 'points', 'reason']);
        }

        if (isset($assoc['dry-run'])) {
            WP_CLI::line('Dry run: nothing was stored.');
        } else {
            WP_CLI::success('Score stored.');
        }
    }

    /**
     * Score recent orders paid with a monitored gateway.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : How many days back to look.
     * ---
     * default: 30
     * ---
     *
     * [--limit=<limit>]
     * : Maximum number of orders to process.
     * ---
     * default: 500
     * ---
     *
     * [--force]
     * : Score orders again even when they already have a score.
     *
     * [--dry-run]
     * : Only report what would be done.
     *
     * ## EXAMPLES
     *
     *     wp cod-risk rescan --days=90 --limit=2000
     *
     * @param string[]              $args  Positional arguments.
     * @param array<string, string> $assoc Named arguments.
     */
    public function rescan(array $args, array $assoc): void
    {
        $days   = max(1, (int) ($assoc['days'] ?? 30));
        $limit  = max(1, (int) ($assoc['limit'] ?? 500));
        $force  = isset($assoc['force']);
        $dryRun = isset($assoc['dry-run']);

        $gateways = $this->plugin->settings()->gateways();
        if ([] === $gateways) {
            WP_CLI::error('No payment gateways are monitored. Check the plugin settings.');
        }

        $scorer  = $this->plugin->scorer();
        $counts  = ['scored' => 0, 'skipped' => 0, 'low' => 0, 'medium' => 0, 'high' => 0];
        $page    = 1;
        $perPage = 100;
        $seen    = 0;

        while ($seen < $limit) {
            $ids = wc_get_orders(
                [
                    'type'           => 'shop_order',
                    'limit'          => min($perPage, $limit - $seen),
                    'page'           => $page,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'return'         => 'ids',
                    'payment_method' => $gateways,
                    'date_created'   => '>' . (time() - $days * DAY_IN_SECONDS),
                ]
            );

            if (! is_array($ids) || [] === $ids) {
                break;
            }

            foreach ($ids as $id) {
                ++$seen;
                $order = wc_get_order($id);
                if (! $order instanceof \WC_Order) {
                    continue;
                }

                if (! $force && $scorer->isScored($order)) {
                    ++$counts['skipped'];
                    continue;
                }

                $result = $dryRun ? $scorer->evaluate($order) : $scorer->rescore($order, false)['result'];

                ++$counts['scored'];
                if (RiskLevel::isValid($result->level)) {
                    ++$counts[$result->level];
                }
            }

            ++$page;
        }

        WP_CLI::line(sprintf('Low: %d, medium: %d, high: %d', $counts['low'], $counts['medium'], $counts['high']));
        WP_CLI::success(
            sprintf(
                '%s %d order(s), skipped %d already scored.',
                $dryRun ? 'Would score' : 'Scored',
                $counts['scored'],
                $counts['skipped']
            )
        );
    }

    /**
     * Show risk statistics for recent orders.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : How many days back to look.
     * ---
     * default: 30
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cod-risk stats --days=7
     *
     * @param string[]              $args  Positional arguments.
     * @param array<string, string> $assoc Named arguments.
     */
    public function stats(array $args, array $assoc): void
    {
        $days    = max(1, (int) ($assoc['days'] ?? 30));
        $summary = $this->plugin->stats()->summary($days);

        if ('json' === ($assoc['format'] ?? 'table')) {
            WP_CLI::line((string) wp_json_encode($summary));

            return;
        }

        WP_CLI::line(sprintf('Scored orders in the last %d day(s): %d (average score %.1f)', $days, (int) $summary['total'], (float) $summary['average_score']));

        $rows = [];
        foreach (RiskLevel::all() as $level) {
            $outcomes = (array) ($summary['outcomes'][$level] ?? []);
            $rate     = $summary['failure_rate_by_level'][$level] ?? null;

            $rows[] = [
                'level'        => $level,
                'orders'       => (int) ($summary['by_level'][$level] ?? 0),
                'delivered'    => (int) ($outcomes['delivered'] ?? 0),
                'returned'     => (int) ($outcomes['returned'] ?? 0),
                'cancelled'    => (int) ($outcomes['cancelled'] ?? 0),
                'failure_rate' => null === $rate ? '-' : sprintf('%.1f%%', 100 * (float) $rate),
            ];
        }

        \WP_CLI\Utils\format_items('table', $rows, ['level', 'orders', 'delivered', 'returned', 'cancelled', 'failure_rate']);

        $top = [];
        foreach ((array) ($summary['top_rules'] ?? []) as $rule => $count) {
            $top[] = [
                'rule'  => (string) $rule,
                'count' => (int) $count,
            ];
        }

        if ([] !== $top) {
            \WP_CLI\Utils\format_items('table', $top, ['rule', 'count']);
        }

        WP_CLI::line(sprintf('Blocklist entries: %d, allowlist entries: %d', (int) $summary['lists']['block'], (int) $summary['lists']['allow']));
    }
}
