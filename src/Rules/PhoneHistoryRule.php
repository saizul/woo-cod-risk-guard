<?php
/**
 * Phone history rule.
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
 * Scores the delivered vs returned / cancelled ratio of earlier orders from
 * the same phone number. A clean history earns a trust bonus.
 */
final class PhoneHistoryRule extends AbstractRule
{
    public function id(): string
    {
        return 'phone_history';
    }

    public function label(): string
    {
        return 'Phone order history';
    }

    public function description(): string
    {
        return 'Share of earlier orders from this phone that were returned or cancelled. A good delivery record lowers the score.';
    }

    public function defaultWeight(): float
    {
        return 35.0;
    }

    public function fields(): array
    {
        return [
            'min_orders' => [
                'type'    => 'int',
                'default' => 2,
                'min'     => 1,
                'max'     => 50,
                'label'   => 'Minimum finished orders before the ratio is used',
            ],
            'bad_ratio' => [
                'type'    => 'float',
                'default' => 0.3,
                'min'     => 0.05,
                'max'     => 1.0,
                'label'   => 'Failure ratio that triggers the rule (0-1)',
            ],
            'trust_min_delivered' => [
                'type'    => 'int',
                'default' => 3,
                'min'     => 1,
                'max'     => 100,
                'label'   => 'Delivered orders needed for the trust bonus',
            ],
            'trust_bonus' => [
                'type'    => 'float',
                'default' => 0.4,
                'min'     => 0.0,
                'max'     => 1.0,
                'label'   => 'Trust bonus as a share of the weight (0-1)',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $hash = $signals->hasher->hashPhone($order->phone);
        if ('' === $hash) {
            return $this->pass($config);
        }

        $history  = $signals->history->getByPhoneHash($hash);
        $resolved = $history->resolved();
        if (0 === $resolved) {
            return $this->pass($config);
        }

        $failed    = $history->failed();
        $ratio     = $history->failureRatio();
        $minOrders = max(1, $config->int('min_orders', 2));
        $badRatio  = max(0.01, min(1.0, $config->float('bad_ratio', 0.3)));
        $data      = $history->toArray();

        if ($resolved < $minOrders) {
            if ($failed > 0 && 0 === $history->delivered) {
                return $this->hit(
                    $config,
                    0.5,
                    'early_failure',
                    'The only earlier order(s) from this phone were returned or cancelled (%1$d of %2$d).',
                    [$failed, $resolved],
                    $data
                );
            }

            return $this->pass($config);
        }

        if ($ratio >= $badRatio) {
            return $this->hit(
                $config,
                $this->scale($ratio, $badRatio, 1.0, 0.5),
                'bad_ratio',
                '%1$d of %2$d earlier orders from this phone were returned or cancelled (%3$d%%).',
                [$failed, $resolved, (int) round($ratio * 100)],
                $data
            );
        }

        if ($history->delivered >= max(1, $config->int('trust_min_delivered', 3))) {
            $bonus = max(0.0, min(1.0, $config->float('trust_bonus', 0.4)));
            if ($bonus > 0.0) {
                return $this->hit(
                    $config,
                    -$bonus,
                    'trusted',
                    'Phone has %d successfully delivered earlier orders.',
                    [$history->delivered],
                    $data
                );
            }
        }

        return $this->pass($config);
    }
}
