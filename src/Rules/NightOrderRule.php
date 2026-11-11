<?php
/**
 * Night-time order rule.
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
 * Flags orders placed in the middle of the night (store local time).
 */
final class NightOrderRule extends AbstractRule
{
    public function id(): string
    {
        return 'night_order';
    }

    public function label(): string
    {
        return 'Night-time order';
    }

    public function description(): string
    {
        return 'Order was placed during the configured night hours (store time zone).';
    }

    public function defaultWeight(): float
    {
        return 5.0;
    }

    public function fields(): array
    {
        return [
            'start_hour' => [
                'type'    => 'int',
                'default' => 0,
                'min'     => 0,
                'max'     => 23,
                'label'   => 'Night starts at hour (0-23)',
            ],
            'end_hour' => [
                'type'    => 'int',
                'default' => 5,
                'min'     => 0,
                'max'     => 23,
                'label'   => 'Night ends before hour (0-23)',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $start = max(0, min(23, $config->int('start_hour', 0)));
        $end   = max(0, min(23, $config->int('end_hour', 5)));

        if ($start === $end) {
            return $this->pass($config);
        }

        $local = $order->createdAt->setTimezone($signals->timezone);
        $hour  = (int) $local->format('G');

        $isNight = $start < $end
            ? ($hour >= $start && $hour < $end)
            : ($hour >= $start || $hour < $end);

        if (! $isNight) {
            return $this->pass($config);
        }

        return $this->hit(
            $config,
            1.0,
            'match',
            'Order was placed at night (%s local time).',
            [$local->format('H:i')],
            ['hour' => $hour]
        );
    }
}
