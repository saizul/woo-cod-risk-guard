<?php
/**
 * Quantity anomaly rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;

/**
 * Flags unusually large quantities, a common pattern in prank orders.
 */
final class QuantityAnomalyRule extends AbstractRule
{
    public function id(): string
    {
        return 'quantity_anomaly';
    }

    public function label(): string
    {
        return 'Quantity anomaly';
    }

    public function description(): string
    {
        return 'Quantity of a single product, or of the whole order, is above the configured limits.';
    }

    public function defaultWeight(): float
    {
        return 10.0;
    }

    public function fields(): array
    {
        return [
            'max_line_quantity' => [
                'type'    => 'int',
                'default' => 5,
                'min'     => 1,
                'max'     => 100000,
                'label'   => 'Largest normal quantity of one product',
            ],
            'max_total_quantity' => [
                'type'    => 'int',
                'default' => 10,
                'min'     => 1,
                'max'     => 100000,
                'label'   => 'Largest normal total quantity',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $maxLine  = max(1, $config->int('max_line_quantity', 5));
        $maxTotal = max(1, $config->int('max_total_quantity', 10));

        $lineExcess  = $order->maxLineQuantity > $maxLine;
        $totalExcess = $order->totalQuantity > $maxTotal;

        if (! $lineExcess && ! $totalExcess) {
            return $this->pass($config);
        }

        $severity = 0.0;
        if ($lineExcess) {
            $severity = max($severity, $this->scale((float) $order->maxLineQuantity, (float) $maxLine, (float) ($maxLine * 2), 0.5));
        }
        if ($totalExcess) {
            $severity = max($severity, $this->scale((float) $order->totalQuantity, (float) $maxTotal, (float) ($maxTotal * 2), 0.5));
        }

        $data = [
            'max_line_quantity' => $order->maxLineQuantity,
            'total_quantity'    => $order->totalQuantity,
        ];

        if ($lineExcess) {
            return $this->hit(
                $config,
                $severity,
                'line',
                'Unusually large quantity of a single product (%1$d, limit %2$d).',
                [$order->maxLineQuantity, $maxLine],
                $data
            );
        }

        return $this->hit(
            $config,
            $severity,
            'total',
            'Unusually large total quantity (%1$d items, limit %2$d).',
            [$order->totalQuantity, $maxTotal],
            $data
        );
    }
}
