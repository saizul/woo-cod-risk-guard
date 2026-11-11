<?php
/**
 * Statistics over scored orders.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\History\Outcome;
use Saizul\CodRiskGuard\Stats\StatsAggregator;

/**
 * Collects scored orders through the WooCommerce order query API and
 * summarises them with the pure StatsAggregator.
 */
final class StatsService
{
    /** Orders fetched per query. */
    private const BATCH = 200;

    /** Hard upper bound of orders inspected per request. */
    public const MAX_ORDERS = 5000;

    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Scored orders created in the last $days days, newest first.
     *
     * @param int    $days     Look-back window in days.
     * @param string $minLevel Lowest risk level to include.
     * @param int    $max      Maximum number of orders to inspect.
     *
     * @return \Generator<int, \WC_Order>
     */
    public function scoredOrders(int $days = 30, string $minLevel = RiskLevel::LOW, int $max = self::MAX_ORDERS): \Generator
    {
        $days     = max(1, min(3650, $days));
        $max      = max(1, min(self::MAX_ORDERS, $max));
        $since    = time() - ($days * DAY_IN_SECONDS);
        $minLevel = RiskLevel::isValid($minLevel) ? $minLevel : RiskLevel::LOW;
        $seen     = 0;
        $pages    = (int) ceil($max / self::BATCH);

        for ($page = 1; $page <= $pages; $page++) {
            $orders = wc_get_orders(
                [
                    'type'         => 'shop_order',
                    'limit'        => self::BATCH,
                    'paged'        => $page,
                    'orderby'      => 'date',
                    'order'        => 'DESC',
                    'date_created' => '>' . $since,
                    'meta_key'     => OrderMeta::LEVEL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded admin / CLI report.
                    'meta_compare' => 'EXISTS',
                ]
            );

            if (! is_array($orders) || [] === $orders) {
                return;
            }

            foreach ($orders as $order) {
                if (! $order instanceof \WC_Order) {
                    continue;
                }

                if (++$seen > $max) {
                    return;
                }

                $level = (string) $order->get_meta(OrderMeta::LEVEL, true);
                if (RiskLevel::isValid($level) && RiskLevel::atLeast($level, $minLevel)) {
                    yield $order;
                }
            }

            if (count($orders) < self::BATCH) {
                return;
            }
        }
    }

    /**
     * Aggregator row for one order.
     *
     * @return array{level:string, score:int, rules:string[], outcome:?string}
     */
    public function row(\WC_Order $order): array
    {
        $result  = $this->plugin->scorer()->stored($order);
        $outcome = (string) $order->get_meta(OrderMeta::COUNTED_OUTCOME, true);

        return [
            'level'   => (string) $order->get_meta(OrderMeta::LEVEL, true),
            'score'   => (int) $order->get_meta(OrderMeta::SCORE, true),
            'rules'   => null !== $result ? $result->triggeredRuleIds() : [],
            'outcome' => Outcome::isValid($outcome) ? $outcome : null,
        ];
    }

    /**
     * Summary of scored orders in the last $days days.
     *
     * @return array<string, mixed>
     */
    public function summary(int $days = 30): array
    {
        $days = max(1, min(3650, $days));
        $rows = [];

        foreach ($this->scoredOrders($days) as $order) {
            $rows[] = $this->row($order);
        }

        $summary = StatsAggregator::summarize($rows);

        $summary['days']      = $days;
        $summary['truncated'] = count($rows) >= self::MAX_ORDERS;
        $summary['lists']     = [
            'block' => $this->plugin->lists()->count('block'),
            'allow' => $this->plugin->lists()->count('allow'),
        ];

        return $summary;
    }
}
