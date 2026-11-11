<?php
/**
 * Array backed list store.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Lists;

/**
 * List store that keeps everything in memory (tests, dry runs).
 */
final class InMemoryListStore implements ListStoreInterface
{
    /**
     * @var array<string, array<string, array<string, bool>>>
     */
    private array $entries = [];

    /**
     * Add a hashed identifier to a list. Returns false when it was already there or invalid.
     */
    public function add(string $list, string $type, string $hash): bool
    {
        if ('' === $hash || ! self::isValidList($list) || isset($this->entries[$list][$type][$hash])) {
            return false;
        }

        $this->entries[$list][$type][$hash] = true;

        return true;
    }

    /**
     * Remove a hashed identifier from a list. Returns false when it was not there.
     */
    public function remove(string $list, string $type, string $hash): bool
    {
        if (! isset($this->entries[$list][$type][$hash])) {
            return false;
        }

        unset($this->entries[$list][$type][$hash]);

        return true;
    }

    public function contains(string $list, string $type, string $hash): bool
    {
        return '' !== $hash && isset($this->entries[$list][$type][$hash]);
    }

    /**
     * Number of entries on a list.
     */
    public function count(string $list): int
    {
        $count = 0;
        foreach ($this->entries[$list] ?? [] as $hashes) {
            $count += count($hashes);
        }

        return $count;
    }

    /**
     * Whether the list name is valid.
     */
    public static function isValidList(string $list): bool
    {
        return in_array($list, [self::BLOCK, self::ALLOW], true);
    }
}
