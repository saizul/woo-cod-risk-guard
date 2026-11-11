<?php
/**
 * Array backed velocity provider.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\History;

use DateTimeImmutable;

/**
 * Velocity provider that keeps events in memory (tests, dry runs).
 */
final class InMemoryVelocityProvider implements VelocityProviderInterface
{
    /**
     * @var array<int, array{type:string, hash:string, at:int, order:int}>
     */
    private array $events = [];

    /**
     * Record an order event for an identifier.
     */
    public function record(string $type, string $hash, DateTimeImmutable $at, int $orderId = 0): void
    {
        if ('' === $hash) {
            return;
        }

        $this->events[] = [
            'type'  => $type,
            'hash'  => $hash,
            'at'    => $at->getTimestamp(),
            'order' => $orderId,
        ];
    }

    public function countSince(string $type, string $hash, DateTimeImmutable $since, int $excludeOrderId = 0): int
    {
        if ('' === $hash) {
            return 0;
        }

        $from  = $since->getTimestamp();
        $count = 0;

        foreach ($this->events as $event) {
            if ($event['type'] !== $type || $event['hash'] !== $hash || $event['at'] < $from) {
                continue;
            }

            if ($excludeOrderId > 0 && $event['order'] === $excludeOrderId) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * Number of recorded events.
     */
    public function count(): int
    {
        return count($this->events);
    }
}
