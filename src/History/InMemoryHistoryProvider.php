<?php
/**
 * Array backed history provider.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\History;

/**
 * History provider that keeps everything in memory (tests, dry runs).
 */
final class InMemoryHistoryProvider implements HistoryProviderInterface
{
    /**
     * @var array<string, CustomerHistory>
     */
    private array $histories = [];

    /**
     * Store the history for a hashed phone number.
     */
    public function set(string $phoneHash, CustomerHistory $history): void
    {
        if ('' !== $phoneHash) {
            $this->histories[$phoneHash] = $history;
        }
    }

    /**
     * Apply an outcome delta to the stored history.
     *
     * @param array{total?:int, delivered?:int, returned?:int, cancelled?:int} $delta Counter changes.
     */
    public function apply(string $phoneHash, array $delta): CustomerHistory
    {
        $history = HistoryLedger::apply($this->getByPhoneHash($phoneHash), $delta);
        $this->set($phoneHash, $history);

        return $history;
    }

    public function getByPhoneHash(string $phoneHash): CustomerHistory
    {
        return $this->histories[$phoneHash] ?? CustomerHistory::none();
    }
}
