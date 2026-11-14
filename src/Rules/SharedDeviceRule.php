<?php
/**
 * Shared device rule.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Rules;

use DateInterval;
use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\History\LinkedIdentifierProviderInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;

/**
 * Flags a device (and optionally an IP address) that was used to order with
 * several different phone numbers.
 *
 * A customer who keeps ordering with a new phone number from the same browser
 * is a typical pattern of fake orders and of people dodging a phone
 * blocklist or a bad delivery history. Repeat orders from the same phone are
 * covered by repeat_orders; this rule looks at the number of distinct phones.
 *
 * The IP check is off by default because mobile carriers in Bangladesh put
 * many customers behind one shared IP address.
 */
final class SharedDeviceRule extends AbstractRule
{
    public function id(): string
    {
        return 'shared_device';
    }

    public function label(): string
    {
        return 'Device used with several phones';
    }

    public function description(): string
    {
        return 'The same device (or, optionally, IP address) placed orders with several different phone numbers.';
    }

    public function defaultWeight(): float
    {
        return 25.0;
    }

    public function fields(): array
    {
        return [
            'window_days' => [
                'type'    => 'int',
                'default' => 30,
                'min'     => 1,
                'max'     => 365,
                'label'   => 'Window in days',
            ],
            'max_phones' => [
                'type'    => 'int',
                'default' => 2,
                'min'     => 1,
                'max'     => 50,
                'label'   => 'Other phone numbers in the window that trigger the rule',
            ],
            'include_ip' => [
                'type'    => 'bool',
                'default' => false,
                'label'   => 'Also check the IP address',
            ],
        ];
    }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        $provider = $signals->velocity;
        if (! $provider instanceof LinkedIdentifierProviderInterface) {
            return $this->pass($config);
        }

        $days   = max(1, $config->int('window_days', 30));
        $max    = max(1, $config->int('max_phones', 2));
        $since  = $order->createdAt->sub(new DateInterval('P' . $days . 'D'));
        $hashes = $signals->identifierHashes($order);
        $phone  = $hashes[IdentifierHasher::TYPE_PHONE] ?? '';

        $types = [IdentifierHasher::TYPE_DEVICE];
        if ($config->bool('include_ip', false)) {
            $types[] = IdentifierHasher::TYPE_IP;
        }

        $worstType  = '';
        $worstCount = 0;

        foreach ($types as $type) {
            if (! isset($hashes[$type])) {
                continue;
            }

            $count = $provider->countDistinctLinked(
                $type,
                $hashes[$type],
                IdentifierHasher::TYPE_PHONE,
                $since,
                $phone,
                $order->orderId
            );

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
            '%1$d other phone numbers ordered from the same %2$s in the last %3$d days.',
            [$worstCount, $worstType, $days],
            ['count' => $worstCount, 'type' => $worstType, 'window_days' => $days]
        );
    }
}
