<?php
/**
 * Address quality rule.
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
 * Flags addresses that a courier is unlikely to be able to deliver to.
 */
final class AddressQualityRule extends AbstractRule
{
    public function id(): string
    {
        return 'address_quality';
    }

    public function label(): string
    {
        return 'Address quality';
    }

    public function description(): string
    {
        return 'Address is too short, looks like random text, is a placeholder or has no house / road / area details.';
    }

    public function defaultWeight(): float
    {
        return 20.0;
    }

    public function fields(): array
    {
        return [
            'min_length' => [
                'type'    => 'int',
                'default' => 10,
                'min'     => 3,
                'max'     => 100,
                'label'   => 'Minimum street address length (characters)',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $analysis = $signals->addresses
            ->withMinLength($config->int('min_length', 10))
            ->analyze($order->address1, $order->address2, $order->city);

        if ($analysis->isClean()) {
            return $this->pass($config);
        }

        return $this->hit(
            $config,
            $analysis->severity,
            'issues',
            'Address quality issues: %s.',
            [implode('; ', $analysis->labels())],
            ['issues' => $analysis->issues]
        );
    }
}
