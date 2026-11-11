<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit\History;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\History\CustomerHistory;
use Saizul\CodRiskGuard\History\HistoryLedger;
use Saizul\CodRiskGuard\History\InMemoryHistoryProvider;
use Saizul\CodRiskGuard\History\InMemoryVelocityProvider;
use Saizul\CodRiskGuard\History\Outcome;
use Saizul\CodRiskGuard\History\StatusClassifier;

final class HistoryTest extends TestCase
{
    public function testEmptyHistory(): void
    {
        $history = CustomerHistory::none();

        self::assertTrue($history->isEmpty());
        self::assertTrue($history->isFirstTime());
        self::assertSame(0.0, $history->failureRatio());
        self::assertSame(0, $history->resolved());
    }

    public function testCounters(): void
    {
        $history = new CustomerHistory(10, 5, 2, 1);

        self::assertSame(3, $history->failed());
        self::assertSame(8, $history->resolved());
        self::assertSame(2, $history->pending());
        self::assertEqualsWithDelta(0.375, $history->failureRatio(), 0.0001);
        self::assertFalse($history->isEmpty());
        self::assertFalse($history->isFirstTime());
    }

    public function testTotalIsNeverBelowTheSumOfOutcomes(): void
    {
        $history = new CustomerHistory(1, 3, 2, 1);

        self::assertSame(6, $history->total);
        self::assertSame(0, $history->pending());
    }

    public function testNegativeCountersAreClamped(): void
    {
        $history = new CustomerHistory(-4, -1, -2, -3);

        self::assertSame(['total' => 0, 'delivered' => 0, 'returned' => 0, 'cancelled' => 0], $history->toArray());
    }

    public function testFirstTimeMeansNoResolvedOrders(): void
    {
        self::assertTrue((new CustomerHistory(2, 0, 0, 0))->isFirstTime(), 'Only pending orders so far.');
        self::assertFalse((new CustomerHistory(2, 0, 1, 1))->isFirstTime());
        self::assertSame(1.0, (new CustomerHistory(2, 0, 1, 1))->failureRatio());
    }

    public function testFromArrayAcceptsDatabaseColumnNames(): void
    {
        $history = CustomerHistory::fromArray(['orders_total' => '9', 'delivered' => '4', 'returned' => '1', 'cancelled' => '2']);

        self::assertSame(9, $history->total);
        self::assertSame(4, $history->delivered);
        self::assertEquals($history, CustomerHistory::fromArray($history->toArray()));
    }

    /**
     * @return array<string, array{?string, ?string, array<string, int>}>
     */
    public static function deltas(): array
    {
        return [
            'open to delivered'      => [null, 'delivered', ['delivered' => 1, 'returned' => 0, 'cancelled' => 0]],
            'open to cancelled'      => [null, 'cancelled', ['delivered' => 0, 'returned' => 0, 'cancelled' => 1]],
            'delivered to returned'  => ['delivered', 'returned', ['delivered' => -1, 'returned' => 1, 'cancelled' => 0]],
            'cancelled reopened'     => ['cancelled', null, ['delivered' => 0, 'returned' => 0, 'cancelled' => -1]],
            'unchanged'              => ['returned', 'returned', ['delivered' => 0, 'returned' => 0, 'cancelled' => 0]],
            'both open'              => [null, null, ['delivered' => 0, 'returned' => 0, 'cancelled' => 0]],
            'unknown previous'       => ['shipped', 'delivered', ['delivered' => 1, 'returned' => 0, 'cancelled' => 0]],
            'unknown current'        => ['delivered', 'lost', ['delivered' => -1, 'returned' => 0, 'cancelled' => 0]],
        ];
    }

    /**
     * @param array<string, int> $expected
     */
    #[DataProvider('deltas')]
    public function testLedgerDelta(?string $previous, ?string $current, array $expected): void
    {
        self::assertSame($expected, HistoryLedger::delta($previous, $current));
    }

    public function testLedgerIsNoop(): void
    {
        self::assertTrue(HistoryLedger::isNoop(HistoryLedger::delta('delivered', 'delivered')));
        self::assertFalse(HistoryLedger::isNoop(HistoryLedger::delta(null, 'delivered')));
        self::assertTrue(HistoryLedger::isNoop([]));
    }

    public function testLedgerApply(): void
    {
        $history = new CustomerHistory(3, 2, 0, 0);
        $updated = HistoryLedger::apply($history, HistoryLedger::delta('delivered', 'returned'));

        self::assertSame(['total' => 3, 'delivered' => 1, 'returned' => 1, 'cancelled' => 0], $updated->toArray());
        self::assertSame(2, $history->delivered, 'Original is untouched.');
    }

    public function testLedgerApplyNeverGoesNegative(): void
    {
        $updated = HistoryLedger::apply(CustomerHistory::none(), ['delivered' => -1, 'returned' => 1]);

        self::assertSame(0, $updated->delivered);
        self::assertSame(1, $updated->returned);
    }

    public function testLedgerApplyTotal(): void
    {
        self::assertSame(1, HistoryLedger::apply(CustomerHistory::none(), ['total' => 1])->total);
    }

    public function testRepeatedStatusFlipsStayConsistent(): void
    {
        $history = new CustomerHistory(1, 0, 0, 0);
        $counted = null;

        foreach (['delivered', 'returned', null, 'cancelled', 'cancelled', 'delivered'] as $outcome) {
            $history = HistoryLedger::apply($history, HistoryLedger::delta($counted, $outcome));
            $counted = $outcome;
        }

        self::assertSame(['total' => 1, 'delivered' => 1, 'returned' => 0, 'cancelled' => 0], $history->toArray());
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function statuses(): array
    {
        return [
            'completed'        => ['completed', Outcome::DELIVERED],
            'prefixed'         => ['wc-completed', Outcome::DELIVERED],
            'upper case'       => ['COMPLETED', Outcome::DELIVERED],
            'custom delivered' => ['delivered', Outcome::DELIVERED],
            'refunded'         => ['refunded', Outcome::RETURNED],
            'custom returned'  => ['wc-returned', Outcome::RETURNED],
            'cancelled'        => ['cancelled', Outcome::CANCELLED],
            'failed'           => ['failed', Outcome::CANCELLED],
            'processing'       => ['processing', null],
            'on hold'          => ['on-hold', null],
            'pending'          => ['pending', null],
            'empty'            => ['', null],
        ];
    }

    #[DataProvider('statuses')]
    public function testDefaultStatusClassification(string $status, ?string $outcome): void
    {
        self::assertSame($outcome, (new StatusClassifier())->classify($status));
    }

    public function testCustomStatusMap(): void
    {
        $classifier = new StatusClassifier(['wc-courier-delivered'], ['courier-returned'], ['fake']);

        self::assertSame(Outcome::DELIVERED, $classifier->classify('courier-delivered'));
        self::assertSame(Outcome::RETURNED, $classifier->classify('wc-courier-returned'));
        self::assertSame(Outcome::CANCELLED, $classifier->classify('fake'));
        self::assertNull($classifier->classify('completed'));
        self::assertSame(['courier-delivered'], $classifier->statusesFor(Outcome::DELIVERED));
    }

    public function testDuplicateStatusPrefersTheWorseOutcome(): void
    {
        $classifier = new StatusClassifier(['mixed'], ['mixed'], ['mixed']);

        self::assertSame(Outcome::RETURNED, $classifier->classify('mixed'));
    }

    public function testOutcomeHelpers(): void
    {
        self::assertSame(['delivered', 'returned', 'cancelled'], Outcome::all());
        self::assertTrue(Outcome::isValid('returned'));
        self::assertFalse(Outcome::isValid('shipped'));
        self::assertFalse(Outcome::isValid(null));
    }

    public function testInMemoryHistoryProvider(): void
    {
        $provider = new InMemoryHistoryProvider();

        self::assertTrue($provider->getByPhoneHash('abc')->isEmpty());

        $provider->set('abc', new CustomerHistory(2, 1, 0, 0));
        $updated = $provider->apply('abc', ['returned' => 1]);

        self::assertSame(1, $updated->returned);
        self::assertSame(1, $provider->getByPhoneHash('abc')->returned);
        self::assertTrue($provider->getByPhoneHash('other')->isEmpty());
    }

    public function testInMemoryVelocityProvider(): void
    {
        $provider = new InMemoryVelocityProvider();
        $now      = new DateTimeImmutable('2025-03-10 08:30:00', new DateTimeZone('UTC'));

        $provider->record('ip', 'h1', $now->modify('-10 minutes'), 1);
        $provider->record('ip', 'h1', $now->modify('-50 minutes'), 2);
        $provider->record('ip', 'h1', $now->modify('-3 hours'), 3);
        $provider->record('ip', 'h2', $now->modify('-5 minutes'), 4);
        $provider->record('phone', 'h1', $now->modify('-5 minutes'), 5);
        $provider->record('ip', '', $now, 6);

        self::assertSame(5, $provider->count());
        self::assertSame(2, $provider->countSince('ip', 'h1', $now->modify('-1 hour')));
        self::assertSame(3, $provider->countSince('ip', 'h1', $now->modify('-1 day')));
        self::assertSame(1, $provider->countSince('ip', 'h1', $now->modify('-1 hour'), 1));
        self::assertSame(1, $provider->countSince('phone', 'h1', $now->modify('-1 hour')));
        self::assertSame(0, $provider->countSince('ip', '', $now->modify('-1 hour')));
        self::assertSame(0, $provider->countSince('device', 'h1', $now->modify('-1 hour')));
    }

    public function testVelocityWindowBoundaryIsInclusive(): void
    {
        $provider = new InMemoryVelocityProvider();
        $now      = new DateTimeImmutable('2025-03-10 08:30:00', new DateTimeZone('UTC'));
        $provider->record('ip', 'h1', $now->modify('-1 hour'), 1);

        self::assertSame(1, $provider->countSince('ip', 'h1', $now->modify('-1 hour')));
        self::assertSame(0, $provider->countSince('ip', 'h1', $now->modify('-59 minutes')));
    }
}
