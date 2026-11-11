<?php
/**
 * Disposable email rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\Support\DisposableEmailDomains;
use Saizul\CodRiskGuard\Support\EmailNormalizer;

/**
 * Flags invalid email addresses and throw-away mailbox providers.
 *
 * A missing email is not penalised: many Bangladesh stores make it optional.
 */
final class DisposableEmailRule extends AbstractRule
{
    public function id(): string
    {
        return 'disposable_email';
    }

    public function label(): string
    {
        return 'Disposable email';
    }

    public function description(): string
    {
        return 'Email address is malformed or uses a disposable mailbox provider.';
    }

    public function defaultWeight(): float
    {
        return 10.0;
    }

    public function fields(): array
    {
        return [
            'extra_domains' => [
                'type'    => 'lines',
                'default' => [],
                'label'   => 'Extra disposable domains (one per line)',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        if ('' === $order->email) {
            return $this->pass($config);
        }

        if (! EmailNormalizer::isValid($order->email)) {
            return $this->hit($config, 0.5, 'invalid', 'Email address is not valid.');
        }

        $domain = EmailNormalizer::domain($order->email);
        $extra  = new DisposableEmailDomains(array_map('strval', $config->arr('extra_domains')), false);

        if ($signals->disposable->isDisposableDomain($domain) || $extra->isDisposableDomain($domain)) {
            return $this->hit(
                $config,
                1.0,
                'disposable',
                'Email uses a disposable mailbox provider (%s).',
                [$domain],
                ['domain' => $domain]
            );
        }

        return $this->pass($config);
    }
}
