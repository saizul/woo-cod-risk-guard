<?php
/**
 * Allowlist rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;

/**
 * Forces the minimum score when the phone or email is allowlisted.
 *
 * IP addresses and devices are deliberately ignored: they are shared too
 * often (mobile carrier NAT, family devices) to be a safe trust signal.
 */
final class AllowlistRule extends AbstractRule
{
    public function id(): string
    {
        return 'allowlist';
    }

    public function label(): string
    {
        return 'Allowlist';
    }

    public function description(): string
    {
        return 'Phone or email is on the merchant allowlist. Forces the score to 0 unless the customer is also blocklisted.';
    }

    public function defaultWeight(): float
    {
        return 0.0;
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $matched = [];
        foreach ($signals->identifierHashes($order) as $type => $hash) {
            if (! in_array($type, [IdentifierHasher::TYPE_PHONE, IdentifierHasher::TYPE_EMAIL], true)) {
                continue;
            }

            if ($signals->lists->contains(ListStoreInterface::ALLOW, $type, $hash)) {
                $matched[] = $type;
            }
        }

        if ([] === $matched) {
            return $this->pass($config);
        }

        return $this->hit(
            $config,
            0.0,
            'match',
            'Customer is on the allowlist (matched: %s).',
            [implode(', ', $matched)],
            ['matched' => $matched],
            RuleResult::OVERRIDE_ALLOW
        );
    }
}
