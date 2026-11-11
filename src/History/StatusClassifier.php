<?php
/**
 * Maps order statuses to outcomes.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\History;

/**
 * Classifies an order status slug as delivered, returned, cancelled or
 * "still open" (null). The maps are configurable because many stores add
 * custom statuses such as "returned" or "delivered".
 */
final class StatusClassifier
{
    /**
     * @var array<string, string>
     */
    private array $map = [];

    /**
     * @param string[] $delivered Status slugs meaning "delivered".
     * @param string[] $returned  Status slugs meaning "returned".
     * @param string[] $cancelled Status slugs meaning "cancelled".
     */
    public function __construct(
        array $delivered = ['completed', 'delivered'],
        array $returned = ['refunded', 'returned'],
        array $cancelled = ['cancelled', 'failed']
    ) {
        // Later assignments win, so the most "negative" outcome takes precedence
        // if a merchant lists the same status twice.
        foreach ($delivered as $status) {
            $this->assign((string) $status, Outcome::DELIVERED);
        }
        foreach ($cancelled as $status) {
            $this->assign((string) $status, Outcome::CANCELLED);
        }
        foreach ($returned as $status) {
            $this->assign((string) $status, Outcome::RETURNED);
        }
    }

    /**
     * Outcome for a status slug, or null while the order is still open.
     */
    public function classify(string $status): ?string
    {
        return $this->map[self::clean($status)] ?? null;
    }

    /**
     * Statuses mapped to the given outcome.
     *
     * @return string[]
     */
    public function statusesFor(string $outcome): array
    {
        return array_keys($this->map, $outcome, true);
    }

    /**
     * Normalise a status slug ("wc-Completed " becomes "completed").
     */
    public static function clean(string $status): string
    {
        $status = strtolower(trim($status));

        return 0 === strpos($status, 'wc-') ? substr($status, 3) : $status;
    }

    private function assign(string $status, string $outcome): void
    {
        $status = self::clean($status);
        if ('' !== $status) {
            $this->map[$status] = $outcome;
        }
    }
}
