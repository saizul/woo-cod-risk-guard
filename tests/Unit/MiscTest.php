<?php

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saizul\CodRiskGuard\Actions\Action;
use Saizul\CodRiskGuard\Actions\ActionResolver;
use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\Engine\RiskResult;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Export\CsvWriter;
use Saizul\CodRiskGuard\Lists\InMemoryListStore;
use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Otp\NullOtpVerifier;
use Saizul\CodRiskGuard\Stats\StatsAggregator;

final class MiscTest extends TestCase
{
    // --- List store ------------------------------------------------------

    public function testListStoreAddContainsRemove(): void
    {
        $store = new InMemoryListStore();

        self::assertTrue($store->add(ListStoreInterface::BLOCK, 'phone', 'h1'));
        self::assertFalse($store->add(ListStoreInterface::BLOCK, 'phone', 'h1'), 'Duplicates are refused.');
        self::assertTrue($store->contains(ListStoreInterface::BLOCK, 'phone', 'h1'));
        self::assertFalse($store->contains(ListStoreInterface::ALLOW, 'phone', 'h1'));
        self::assertFalse($store->contains(ListStoreInterface::BLOCK, 'ip', 'h1'));
        self::assertSame(1, $store->count(ListStoreInterface::BLOCK));

        self::assertTrue($store->remove(ListStoreInterface::BLOCK, 'phone', 'h1'));
        self::assertFalse($store->remove(ListStoreInterface::BLOCK, 'phone', 'h1'));
        self::assertSame(0, $store->count(ListStoreInterface::BLOCK));
    }

    public function testListStoreRejectsInvalidInput(): void
    {
        $store = new InMemoryListStore();

        self::assertFalse($store->add('greylist', 'phone', 'h1'));
        self::assertFalse($store->add(ListStoreInterface::BLOCK, 'phone', ''));
        self::assertFalse($store->contains(ListStoreInterface::BLOCK, 'phone', ''));
        self::assertTrue(InMemoryListStore::isValidList('allow'));
        self::assertFalse(InMemoryListStore::isValidList('greylist'));
    }

    // --- Actions ---------------------------------------------------------

    public function testActionHelpers(): void
    {
        self::assertSame(['allow', 'hold', 'require_advance', 'block'], Action::all());
        self::assertTrue(Action::isValid('hold'));
        self::assertFalse(Action::isValid('delete'));
        self::assertSame(3, Action::rank('block'));
        self::assertSame(0, Action::rank('unknown'));
        self::assertTrue(Action::restrictsCod(Action::BLOCK));
        self::assertTrue(Action::restrictsCod(Action::REQUIRE_ADVANCE));
        self::assertFalse(Action::restrictsCod(Action::HOLD));
        self::assertFalse(Action::restrictsCod(Action::ALLOW));
    }

    public function testResolverDefaults(): void
    {
        $resolver = new ActionResolver();

        self::assertSame(Action::ALLOW, $resolver->forLevel(RiskLevel::LOW));
        self::assertSame(Action::HOLD, $resolver->forLevel(RiskLevel::MEDIUM));
        self::assertSame(Action::REQUIRE_ADVANCE, $resolver->forLevel(RiskLevel::HIGH));
        self::assertSame(Action::ALLOW, $resolver->forLevel('unknown'));
    }

    public function testResolverCustomMapIgnoresInvalidEntries(): void
    {
        $resolver = new ActionResolver(['high' => 'block', 'medium' => 'explode', 'bogus' => 'block']);

        self::assertSame(
            ['low' => 'allow', 'medium' => 'hold', 'high' => 'block'],
            $resolver->map()
        );
    }

    public function testResolverUsesResultLevel(): void
    {
        $resolver = new ActionResolver(['high' => 'block']);

        self::assertSame(Action::BLOCK, $resolver->resolve(new RiskResult(90, RiskLevel::HIGH, [])));
        self::assertSame(Action::HOLD, $resolver->resolve(new RiskResult(50, RiskLevel::MEDIUM, [])));
    }

    public function testResolverAlwaysAllowsAllowlistedCustomers(): void
    {
        $resolver = new ActionResolver(['low' => 'block', 'medium' => 'block', 'high' => 'block']);
        $result   = new RiskResult(0, RiskLevel::LOW, [], 80.0, RuleResult::OVERRIDE_ALLOW);

        self::assertSame(Action::ALLOW, $resolver->resolve($result));
    }

    // --- CSV -------------------------------------------------------------

    public function testCsvDocument(): void
    {
        $csv = (new CsvWriter(',', false))->toString(['Order', 'Score'], [[101, 80], [102, 15]]);

        self::assertSame("Order,Score\r\n101,80\r\n102,15\r\n", $csv);
    }

    public function testCsvBom(): void
    {
        $csv = (new CsvWriter())->toString(['A'], []);

        self::assertSame("\xEF\xBB\xBFA\r\n", $csv);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function cells(): array
    {
        return [
            'plain'           => ['Dhaka', 'Dhaka'],
            'comma'           => ['House 1, Road 2', '"House 1, Road 2"'],
            'quote'           => ['He said "hi"', '"He said ""hi"""'],
            'newline'         => ["line1\nline2", "\"line1\nline2\""],
            'leading space'   => [' padded', '" padded"'],
            'formula equals'  => ['=SUM(A1:A2)', "'=SUM(A1:A2)"],
            'formula plus'    => ['+cmd', "'+cmd"],
            'formula at'      => ['@import', "'@import"],
            'formula minus'   => ['-danger()', "'-danger()"],
            'negative number' => ['-12.5', '-12.5'],
            'phone e164'      => ['+8801700000000', '+8801700000000'],
            'true'            => [true, '1'],
            'false'           => [false, '0'],
            'null'            => [null, ''],
            'int'             => [42, '42'],
            'array'           => [['a', 'b'], 'a | b'],
            'bengali'         => ['ঢাকা', 'ঢাকা'],
        ];
    }

    /**
     * @param mixed $value
     */
    #[DataProvider('cells')]
    public function testCsvCellEscaping($value, string $expected): void
    {
        self::assertSame($expected, (new CsvWriter())->escapeCell($value));
    }

    public function testCsvCustomDelimiter(): void
    {
        $writer = new CsvWriter(';', false);

        self::assertSame("a;\"b;c\";d,e\r\n", $writer->line(['a', 'b;c', 'd,e']));
    }

    // --- Stats -----------------------------------------------------------

    public function testStatsOnEmptyInput(): void
    {
        $stats = StatsAggregator::summarize([]);

        self::assertSame(0, $stats['total']);
        self::assertSame(0.0, $stats['average_score']);
        self::assertSame(['low' => 0, 'medium' => 0, 'high' => 0], $stats['by_level']);
        self::assertSame([], $stats['top_rules']);
        self::assertNull($stats['failure_rate_by_level']['high']);
    }

    public function testStatsSummary(): void
    {
        $stats = StatsAggregator::summarize([
            ['level' => 'low', 'score' => 10, 'rules' => ['night_order'], 'outcome' => 'delivered'],
            ['level' => 'low', 'score' => 0, 'rules' => [], 'outcome' => 'delivered'],
            ['level' => 'high', 'score' => 90, 'rules' => ['phone_history', 'night_order'], 'outcome' => 'returned'],
            ['level' => 'high', 'score' => 80, 'rules' => ['phone_history'], 'outcome' => 'delivered'],
            ['level' => 'high', 'score' => 100, 'rules' => ['blocklist', 'phone_history'], 'outcome' => 'cancelled'],
            ['level' => 'medium', 'score' => 50, 'rules' => ['address_quality'], 'outcome' => null],
            ['level' => 'unknown', 'score' => 50],
        ]);

        self::assertSame(6, $stats['total']);
        self::assertSame(['low' => 2, 'medium' => 1, 'high' => 3], $stats['by_level']);
        self::assertSame(55.0, $stats['average_score']);
        self::assertSame('phone_history', array_key_first($stats['top_rules']));
        self::assertSame(3, $stats['top_rules']['phone_history']);
        self::assertSame(2, $stats['top_rules']['night_order']);
        self::assertSame(['delivered' => 1, 'returned' => 1, 'cancelled' => 1], $stats['outcomes']['high']);
        self::assertSame(0.0, $stats['failure_rate_by_level']['low']);
        self::assertSame(0.6667, $stats['failure_rate_by_level']['high']);
        self::assertNull($stats['failure_rate_by_level']['medium']);
    }

    public function testStatsTopRulesLimit(): void
    {
        $stats = StatsAggregator::summarize([
            ['level' => 'low', 'score' => 1, 'rules' => ['a', 'b', 'c']],
            ['level' => 'low', 'score' => 1, 'rules' => ['a', 'b']],
            ['level' => 'low', 'score' => 1, 'rules' => ['a']],
        ], 2);

        self::assertSame(['a' => 3, 'b' => 2], $stats['top_rules']);
    }

    public function testStatsAcceptsGenerators(): void
    {
        $rows = (static function () {
            yield ['level' => 'medium', 'score' => 500, 'rules' => 'not-an-array'];
        })();

        $stats = StatsAggregator::summarize($rows);

        self::assertSame(1, $stats['total']);
        self::assertSame(100.0, $stats['average_score'], 'Scores are clamped.');
        self::assertSame(['not-an-array' => 1], $stats['top_rules']);
    }

    // --- OTP -------------------------------------------------------------

    public function testNullOtpVerifierNeverVerifies(): void
    {
        $verifier = new NullOtpVerifier();
        $order    = OrderContext::fromArray(['phone' => '01700000000', 'created_at' => new DateTimeImmutable('2025-01-01')]);

        self::assertFalse($verifier->isPhoneVerified($order));
        self::assertFalse($verifier->requestVerification($order));
    }
}
