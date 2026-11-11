<?php
/**
 * Phone validity rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\Support\PhoneAnalysis;

/**
 * Flags missing, malformed or placeholder phone numbers.
 */
final class PhoneValidityRule extends AbstractRule
{
    public function id(): string
    {
        return 'phone_validity';
    }

    public function label(): string
    {
        return 'Invalid phone number';
    }

    public function description(): string
    {
        return 'Phone number is missing, is not a valid Bangladesh mobile number, or looks like a placeholder.';
    }

    public function defaultWeight(): float
    {
        return 25.0;
    }

    public function fields(): array
    {
        return [
            'placeholder_severity' => [
                'type'    => 'float',
                'default' => 0.6,
                'min'     => 0.0,
                'max'     => 1.0,
                'label'   => 'Severity for placeholder numbers (0-1)',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $analysis = $signals->phones->analyze($order->phone);

        if ($analysis->isEmpty()) {
            return $this->hit($config, 1.0, 'missing', 'No phone number was provided.');
        }

        $bangladesh = '' === $order->country || 'BD' === $order->country;

        if ($bangladesh) {
            if (! $analysis->validBangladeshMobile) {
                return $this->hit(
                    $config,
                    1.0,
                    'invalid_bd',
                    'Phone number is not a valid Bangladesh mobile number.',
                    [],
                    ['issues' => $analysis->issues]
                );
            }

            if ($analysis->has(PhoneAnalysis::ISSUE_PLACEHOLDER)) {
                return $this->hit(
                    $config,
                    $config->float('placeholder_severity', 0.6),
                    'placeholder',
                    'Phone number looks like a placeholder (repeated or sequential digits).'
                );
            }

            return $this->pass($config);
        }

        // Outside Bangladesh only a basic E.164 length check is possible.
        $length = strlen(ltrim($analysis->digits, '0'));
        if ($length < 7 || $length > 15) {
            return $this->hit($config, 1.0, 'invalid_length', 'Phone number has an invalid length.');
        }

        if (1 === preg_match('/^(\d)\1+$/', $analysis->digits)) {
            return $this->hit(
                $config,
                $config->float('placeholder_severity', 0.6),
                'placeholder',
                'Phone number looks like a placeholder (repeated or sequential digits).'
            );
        }

        return $this->pass($config);
    }
}
