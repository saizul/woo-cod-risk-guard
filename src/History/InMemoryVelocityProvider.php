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
final class InMemoryVelocityProvider implements VelocityProviderInterface, LinkedIdentifierProviderInterface
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

    /**
     * Record every identifier hash of one order at once.
     *
     * Events that share an order id are linked to each other, which is what
     * countDistinctLinked() relies on.
     *
     * @param array<string, string> $hashes Identifier hashes keyed by identifier type.
     */
    public function recordOrder(int $orderId, array $hashes, DateTimeImmutable $at): void
    {
        foreach ($hashes as $type => $hash) {
            $this->record((string) $type, (string) $hash, $at, $orderId);
        }
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

    public function countDistinctLinked(
        string $type,
        string $hash,
        string $linkedType,
        DateTimeImmutable $since,
        string $excludeLinkedHash = '',
        int $excludeOrderId = 0
    ): int {
        if ('' === $hash) {
            return 0;
        }

        $from   = $since->getTimestamp();
        $orders = [];

        foreach ($this->events as $event) {
            // Events without an order id cannot be linked to other identifiers.
            if ($event['order'] <= 0 || $event['order'] === $excludeOrderId) {
                continue;
            }

            if ($event['type'] === $type && $event['hash'] === $hash && $event['at'] >= $from) {
                $orders[$event['order']] = true;
            }
        }

        if ([] === $orders) {
            return 0;
        }

        $linked = [];
        foreach ($this->events as $event) {
            if ($event['type'] !== $linkedType || ! isset($orders[$event['order']])) {
                continue;
            }

            if ('' === $event['hash'] || $event['hash'] === $excludeLinkedHash) {
                continue;
            }

            $linked[$event['hash']] = true;
        }

        return count($linked);
    }

    /**
     * Number of recorded events.
     */
    public function count(): int
    {
        return count($this->events);
    }
}
