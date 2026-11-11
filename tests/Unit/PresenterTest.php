<?php
/**
 * Tests for the translation layer between the engine and the admin screens.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Saizul\CodRiskGuard\Actions\Action;
use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\Engine\RuleRegistry;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\Tests\EngineTestCase;
use Saizul\CodRiskGuard\WP\Labels;
use Saizul\CodRiskGuard\WP\Presenter;

/**
 * With the identity translation stub, the translated reason must be exactly
 * the English reason the engine produced. This guards the translatable
 * templates in Labels against drifting away from the rules.
 */
final class PresenterTest extends EngineTestCase
{
    /**
     * Orders that between them trigger most bundled rules.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function riskyOrders(): array
    {
        return [
            'clean order'         => [[]],
            'invalid phone'       => [['phone' => '12345']],
            'repeated digits'     => [['phone' => '01711111111']],
            'phone mismatch'      => [['shipping_phone' => '01800000002']],
            'high value'          => [['total' => 25000.0]],
            'short address'       => [['address_1' => 'Dhaka', 'city' => '']],
            'gibberish address'   => [['address_1' => 'asdfgh qwerty zxcvb']],
            'gibberish name'      => [['first_name' => 'asdf', 'last_name' => 'qwrt']],
            'single letter name'  => [['first_name' => 'a', 'last_name' => '']],
            'disposable email'    => [['email' => 'someone@mailinator.com']],
            'large quantity'      => [['total_quantity' => 40, 'max_line_quantity' => 30]],
            'night order'         => [['created_at' => new DateTimeImmutable('2025-03-10 20:30:00', new DateTimeZone('UTC'))]],
            'verified phone'      => [['phone_verified' => true]],
            'everything at once'  => [[
                'phone'             => '0171',
                'shipping_phone'    => '01800000002',
                'total'             => 90000.0,
                'address_1'         => 'x',
                'first_name'        => 'zz',
                'last_name'         => '',
                'email'             => 'x@mailinator.com',
                'total_quantity'    => 99,
                'max_line_quantity' => 99,
                'created_at'        => new DateTimeImmutable('2025-03-10 21:00:00', new DateTimeZone('UTC')),
            ]],
        ];
    }

    /**
     * @dataProvider riskyOrders
     *
     * @param array<string, mixed> $overrides Order field overrides.
     */
    public function testReasonMatchesEngineReason(array $overrides): void
    {
        $result = $this->engine()->evaluate($this->order($overrides));

        foreach ($result->triggered() as $rule) {
            self::assertNotNull(
                Labels::template($rule->reasonCode),
                'Missing translatable template for ' . $rule->reasonCode
            );
            self::assertSame($rule->reason, Presenter::reason($rule));
        }

        self::assertSame($result->reasons(), Presenter::reasons($result));
    }

    public function testReasonsForHistoryVelocityAndLists(): void
    {
        $order = $this->order();

        $this->giveHistory(1, 4, 1);
        $this->recordEvents(IdentifierHasher::TYPE_PHONE, self::PHONE, 4, $order->createdAt);
        $this->recordEvents(IdentifierHasher::TYPE_IP, '203.0.113.10', 6, $order->createdAt);
        $this->recordEvents(IdentifierHasher::TYPE_DEVICE, 'device-abc', 4, $order->createdAt);

        $result = $this->engine()->evaluate($order);
        self::assertNotSame([], $result->triggered());

        foreach ($result->triggered() as $rule) {
            self::assertNotNull(Labels::template($rule->reasonCode));

            // Identifier names inside a reason are expanded to friendlier labels.
            if ('repeat_orders.match' === $rule->reasonCode) {
                self::assertSame('4 other orders from the same phone number in the last 24 hours.', Presenter::reason($rule));
                continue;
            }

            self::assertSame($rule->reason, Presenter::reason($rule));
        }

        $this->lists->add(ListStoreInterface::BLOCK, IdentifierHasher::TYPE_PHONE, $this->hasher->hashPhone(self::PHONE));
        $this->lists->add(ListStoreInterface::BLOCK, IdentifierHasher::TYPE_IP, $this->hasher->hashIp('203.0.113.10'));

        $blocked = $this->engine()->evaluate($order);
        self::assertSame(RuleResult::OVERRIDE_BLOCK, $blocked->override);
        self::assertSame(
            'Customer is on the blocklist (matched: phone number, IP address).',
            Presenter::reasons($blocked)[0]
        );
    }

    public function testGoodHistoryReasonMatches(): void
    {
        $this->giveHistory(8);

        $result = $this->engine()->evaluate($this->order());

        self::assertSame($result->reasons(), Presenter::reasons($result));
    }

    public function testAllowlistReasonMatches(): void
    {
        $this->lists->add(ListStoreInterface::ALLOW, IdentifierHasher::TYPE_PHONE, $this->hasher->hashPhone(self::PHONE));

        $result = $this->engine()->evaluate($this->order(['phone_verified' => true]));

        self::assertSame(RuleResult::OVERRIDE_ALLOW, $result->override);
        self::assertSame(
            ['Customer is on the allowlist (matched: phone number).', 'Phone number was verified by OTP.'],
            Presenter::reasons($result)
        );
    }

    public function testUnknownReasonCodeFallsBackToEnglishReason(): void
    {
        $custom = RuleResult::hit('my_rule', 0.5, 10.0, 'custom', 'Custom %s reason', ['third-party']);

        self::assertSame('Custom third-party reason', Presenter::reason($custom));
    }

    public function testBrokenArgumentsFallBackToStoredReason(): void
    {
        $result = $this->engine()->evaluate($this->order(['total' => 25000.0]));
        $first  = null;
        foreach ($result->triggered() as $rule) {
            if ([] !== $rule->reasonArgs) {
                $first = $rule;
                break;
            }
        }
        self::assertNotNull($first);

        $data         = $first->toArray();
        $data['args'] = [];
        $broken       = RuleResult::fromArray($data);

        // Either the template has no placeholders left to fill or the stored reason is used.
        self::assertNotSame('', Presenter::reason($broken));
    }

    public function testEveryBundledRuleHasALabel(): void
    {
        foreach (RuleRegistry::withDefaults()->all() as $rule) {
            self::assertSame($rule->label(), Presenter::ruleLabel($rule));
            self::assertNotSame('', Presenter::ruleLabel($rule));
        }
    }

    public function testLevelAndActionLabels(): void
    {
        foreach (RiskLevel::all() as $level) {
            self::assertNotSame($level, Presenter::levelLabel($level));
        }
        foreach (Action::all() as $action) {
            self::assertNotSame($action, Presenter::actionLabel($action));
        }

        self::assertSame('unknown', Presenter::levelLabel('unknown'));
        self::assertSame('unknown', Presenter::actionLabel('unknown'));
    }

    public function testPointsAreSigned(): void
    {
        self::assertSame('+12.5', Presenter::points(RuleResult::hit('r', 0.5, 25.0, 'c', 'x')));
        self::assertSame('-20', Presenter::points(RuleResult::hit('r', -1.0, 20.0, 'c', 'x')));
        self::assertSame('+0', Presenter::points(RuleResult::pass('r')));
    }
}
