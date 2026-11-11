<?php
/**
 * History lookup contract.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\History;

/**
 * Looks up the order history of a (hashed) phone number.
 */
interface HistoryProviderInterface
{
    /**
     * History for a hashed phone number. Must return an empty history when unknown.
     */
    public function getByPhoneHash(string $phoneHash): CustomerHistory;
}
