<?php
/**
 * Aggregated order history for one phone number.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\History;

/**
 * Immutable counters describing how previous orders from a phone ended.
 */
final class CustomerHistory
{
    /** Orders ever seen for this phone (including still open ones). */
    public int $total;

    /** Orders that were delivered / completed. */
    public int $delivered;

    /** Orders that were returned or refunded. */
    public int $returned;

    /** Orders that were cancelled or failed. */
    public int $cancelled;

    public function __construct(int $total = 0, int $delivered = 0, int $returned = 0, int $cancelled = 0)
    {
        $this->delivered = max(0, $delivered);
        $this->returned  = max(0, $returned);
        $this->cancelled = max(0, $cancelled);
        $this->total     = max($total, $this->delivered + $this->returned + $this->cancelled, 0);
    }

    /**
     * History with no orders.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Build from a database row / array.
     *
     * @param array<string, mixed> $row Row with total, delivered, returned and cancelled keys.
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (int) ($row['total'] ?? $row['orders_total'] ?? 0),
            (int) ($row['delivered'] ?? 0),
            (int) ($row['returned'] ?? 0),
            (int) ($row['cancelled'] ?? 0)
        );
    }

    /**
     * Orders that ended badly (returned + cancelled).
     */
    public function failed(): int
    {
        return $this->returned + $this->cancelled;
    }

    /**
     * Orders with a known final outcome.
     */
    public function resolved(): int
    {
        return $this->delivered + $this->failed();
    }

    /**
     * Orders still awaiting an outcome.
     */
    public function pending(): int
    {
        return max(0, $this->total - $this->resolved());
    }

    /**
     * Share of resolved orders that failed (0..1). Zero when nothing resolved.
     */
    public function failureRatio(): float
    {
        $resolved = $this->resolved();

        return 0 === $resolved ? 0.0 : $this->failed() / $resolved;
    }

    /**
     * Whether no order has ever been recorded.
     */
    public function isEmpty(): bool
    {
        return 0 === $this->total;
    }

    /**
     * Whether the customer has never had an order delivered or failed.
     */
    public function isFirstTime(): bool
    {
        return 0 === $this->resolved();
    }

    /**
     * Export as an array.
     *
     * @return array{total:int, delivered:int, returned:int, cancelled:int}
     */
    public function toArray(): array
    {
        return [
            'total'     => $this->total,
            'delivered' => $this->delivered,
            'returned'  => $this->returned,
            'cancelled' => $this->cancelled,
        ];
    }
}
