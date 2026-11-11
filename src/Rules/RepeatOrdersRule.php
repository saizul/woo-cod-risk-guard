<?php
/**
 * Repeat orders rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use DateInterval;
use Saizul\CodRiskGuard\Support\IdentifierHasher;

/**
 * Flags several orders from the same phone or device within a short window.
 */
final class RepeatOrdersRule extends AbstractRule
{
    public function id(): string
    {
        return 'repeat_orders';
    }

    public function label(): string
    {
        return 'Repeated orders';
    }

    public function description(): string
    {
        return 'Several orders from the same phone or device within the configured window.';
    }

    public function defaultWeight(): float
    {
        return 20.0;
    }

    public function fields(): array
    {
        return [
            'window_hours' => [
                'type'    => 'int',
                'default' => 24,
                'min'     => 1,
                'max'     => 720,
                'label'   => 'Window in hours',
            ],
            'max_orders' => [
                'type'    => 'int',
                'default' => 2,
                'min'     => 1,
                'max'     => 100,
                'label'   => 'Earlier orders in the window that trigger the rule',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $hours = max(1, $config->int('window_hours', 24));
        $max   = max(1, $config->int('max_orders', 2));
        $since = $order->createdAt->sub(new DateInterval('PT' . $hours . 'H'));

        $worstType  = '';
        $worstCount = 0;
        $hashes     = $signals->identifierHashes($order);

        foreach ([IdentifierHasher::TYPE_PHONE, IdentifierHasher::TYPE_DEVICE] as $type) {
            if (! isset($hashes[$type])) {
                continue;
            }

            $count = $signals->velocity->countSince($type, $hashes[$type], $since, $order->orderId);
            if ($count > $worstCount) {
                $worstCount = $count;
                $worstType  = $type;
            }
        }

        if ($worstCount < $max) {
            return $this->pass($config);
        }

        return $this->hit(
            $config,
            $this->scale((float) $worstCount, (float) $max, (float) ($max * 2), 0.5),
            'match',
            '%1$d other orders from the same %2$s in the last %3$d hours.',
            [$worstCount, $worstType, $hours],
            ['count' => $worstCount, 'type' => $worstType, 'window_hours' => $hours]
        );
    }
}
