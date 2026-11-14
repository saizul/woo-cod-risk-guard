<?php
/**
 * Linked identifier lookup contract.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\History;

use DateTimeImmutable;

/**
 * Answers "how many different X were used together with this Y", for example
 * how many different phone numbers were used from one device.
 *
 * This is a separate, optional contract so that existing third-party
 * velocity providers keep working; rules that need it check for it with
 * instanceof and pass when it is not available.
 */
interface LinkedIdentifierProviderInterface
{
    /**
     * Number of distinct $linkedType hashes found on orders that carry the
     * identifier $type / $hash and were recorded at or after $since.
     *
     * Empty linked hashes are never counted.
     *
     * @param string            $type              Identifier type to look up (device, ip, ...).
     * @param string            $hash              Hashed identifier.
     * @param string            $linkedType        Identifier type to count (usually phone).
     * @param DateTimeImmutable $since             Start of the window.
     * @param string            $excludeLinkedHash Linked hash to leave out (the order's own phone).
     * @param int               $excludeOrderId    Order to leave out (the one being scored).
     */
    public function countDistinctLinked(
        string $type,
        string $hash,
        string $linkedType,
        DateTimeImmutable $since,
        string $excludeLinkedHash = '',
        int $excludeOrderId = 0
    ): int;
}
