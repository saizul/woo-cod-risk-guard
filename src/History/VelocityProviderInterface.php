<?php
/**
 * Velocity lookup contract.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\History;

use DateTimeImmutable;

/**
 * Counts recent orders that share a (hashed) identifier.
 */
interface VelocityProviderInterface
{
    /**
     * Number of orders recorded for the identifier at or after $since.
     *
     * @param string            $type           Identifier type (phone, ip, device, email).
     * @param string            $hash           Hashed identifier.
     * @param DateTimeImmutable $since          Start of the window.
     * @param int               $excludeOrderId Order to leave out of the count (the one being scored).
     */
    public function countSince(string $type, string $hash, DateTimeImmutable $since, int $excludeOrderId = 0): int;
}
