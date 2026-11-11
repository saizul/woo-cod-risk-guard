<?php
/**
 * Blocklist rule.
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

/**
 * Forces the maximum score when the phone, email, IP or device is blocklisted.
 */
final class BlocklistRule extends AbstractRule
{
    public function id(): string
    {
        return 'blocklist';
    }

    public function label(): string
    {
        return 'Blocklist';
    }

    public function description(): string
    {
        return 'Phone, email, IP address or device is on the merchant blocklist. Forces the score to 100.';
    }

    public function defaultWeight(): float
    {
        return 100.0;
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $matched = [];
        foreach ($signals->identifierHashes($order) as $type => $hash) {
            if ($signals->lists->contains(ListStoreInterface::BLOCK, $type, $hash)) {
                $matched[] = $type;
            }
        }

        if ([] === $matched) {
            return $this->pass($config);
        }

        return $this->hit(
            $config,
            1.0,
            'match',
            'Customer is on the blocklist (matched: %s).',
            [implode(', ', $matched)],
            ['matched' => $matched],
            RuleResult::OVERRIDE_BLOCK
        );
    }
}
