<?php
/**
 * District return rate rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\Support\DistrictNormalizer;

/**
 * Adds risk for districts where the merchant sees a high return rate.
 *
 * The plugin ships no rates: they come from the merchant's own courier data.
 */
final class DistrictReturnRateRule extends AbstractRule
{
    public function id(): string
    {
        return 'district_return_rate';
    }

    public function label(): string
    {
        return 'District return rate';
    }

    public function description(): string
    {
        return 'Delivery district has a merchant-configured return rate above the threshold.';
    }

    public function defaultWeight(): float
    {
        return 15.0;
    }

    public function fields(): array
    {
        return [
            'rates' => [
                'type'    => 'rates',
                'default' => [],
                'label'   => 'Return rate per district (one "District=percent" per line)',
            ],
            'threshold' => [
                'type'    => 'float',
                'default' => 20.0,
                'min'     => 0.0,
                'max'     => 100.0,
                'label'   => 'Return rate (%) that triggers the rule',
            ],
            'cap' => [
                'type'    => 'float',
                'default' => 50.0,
                'min'     => 1.0,
                'max'     => 100.0,
                'label'   => 'Return rate (%) treated as full severity',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $rates = DistrictNormalizer::parseRates($config->arr('rates'));
        if ([] === $rates) {
            return $this->pass($config);
        }

        $district = '';
        $rate     = null;
        foreach ([$order->district, $order->city] as $candidate) {
            $key = DistrictNormalizer::normalize($candidate);
            if ('' !== $key && isset($rates[$key])) {
                $district = $candidate;
                $rate     = $rates[$key];
                break;
            }
        }

        $threshold = $config->float('threshold', 20.0);
        if (null === $rate || $rate < $threshold || $rate <= 0.0) {
            return $this->pass($config);
        }

        $cap = max($threshold, $config->float('cap', 50.0));

        return $this->hit(
            $config,
            $this->scale($rate, $threshold, $cap, 0.4),
            'match',
            'District %1$s has a configured return rate of %2$s%%.',
            [$district, rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.')],
            ['district' => DistrictNormalizer::normalize($district), 'rate' => $rate]
        );
    }
}
