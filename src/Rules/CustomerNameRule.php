<?php
/**
 * Customer name rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\Support\BanglaDigits;
use Saizul\CodRiskGuard\Support\Str;

/**
 * Flags missing, very short or random-looking customer names.
 */
final class CustomerNameRule extends AbstractRule
{
    public function id(): string
    {
        return 'customer_name';
    }

    public function label(): string
    {
        return 'Customer name quality';
    }

    public function description(): string
    {
        return 'Customer name is missing, too short, contains digits or looks like random text.';
    }

    public function defaultWeight(): float
    {
        return 8.0;
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $name = Str::squish($order->fullName());

        if ('' === $name) {
            return $this->hit($config, 0.5, 'missing', 'Customer name is missing.');
        }

        if (Str::length(Str::lettersOnly($name)) < 3) {
            return $this->hit($config, 0.7, 'too_short', 'Customer name is too short.');
        }

        if ($signals->gibberish->isGibberish($name)) {
            return $this->hit($config, 1.0, 'gibberish', 'Customer name looks like random text.');
        }

        if (1 === preg_match('/\d/', BanglaDigits::toAscii($name))) {
            return $this->hit($config, 0.6, 'digits', 'Customer name contains digits.');
        }

        return $this->pass($config);
    }
}
