<?php
/**
 * Blocklist / allowlist storage contract.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Lists;

/**
 * Stores hashed identifiers on the blocklist and allowlist.
 */
interface ListStoreInterface
{
    public const BLOCK = 'block';
    public const ALLOW = 'allow';

    /**
     * Whether the hashed identifier is on the given list.
     *
     * @param string $list List name (block / allow).
     * @param string $type Identifier type (phone, email, ip, device).
     * @param string $hash Hashed identifier.
     */
    public function contains(string $list, string $type, string $hash): bool;
}
