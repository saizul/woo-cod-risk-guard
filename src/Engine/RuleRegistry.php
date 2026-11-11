<?php
/**
 * Rule registry.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Engine;

use InvalidArgumentException;
use Saizul\CodRiskGuard\Rules\AddressQualityRule;
use Saizul\CodRiskGuard\Rules\AllowlistRule;
use Saizul\CodRiskGuard\Rules\BlocklistRule;
use Saizul\CodRiskGuard\Rules\CustomerNameRule;
use Saizul\CodRiskGuard\Rules\DisposableEmailRule;
use Saizul\CodRiskGuard\Rules\DistrictReturnRateRule;
use Saizul\CodRiskGuard\Rules\FirstTimeHighValueRule;
use Saizul\CodRiskGuard\Rules\IpVelocityRule;
use Saizul\CodRiskGuard\Rules\NightOrderRule;
use Saizul\CodRiskGuard\Rules\OtpVerifiedRule;
use Saizul\CodRiskGuard\Rules\PhoneHistoryRule;
use Saizul\CodRiskGuard\Rules\PhoneMismatchRule;
use Saizul\CodRiskGuard\Rules\PhoneValidityRule;
use Saizul\CodRiskGuard\Rules\QuantityAnomalyRule;
use Saizul\CodRiskGuard\Rules\RepeatOrdersRule;
use Saizul\CodRiskGuard\Rules\RuleInterface;

/**
 * Ordered collection of rules, keyed by rule id.
 */
final class RuleRegistry
{
    /**
     * @var array<string, RuleInterface>
     */
    private array $rules = [];

    /**
     * Registry pre-loaded with every bundled rule.
     */
    public static function withDefaults(): self
    {
        $registry = new self();

        foreach (self::bundled() as $rule) {
            $registry->register($rule);
        }

        return $registry;
    }

    /**
     * Fresh instances of the bundled rules.
     *
     * @return RuleInterface[]
     */
    public static function bundled(): array
    {
        return [
            new BlocklistRule(),
            new AllowlistRule(),
            new PhoneValidityRule(),
            new PhoneMismatchRule(),
            new PhoneHistoryRule(),
            new FirstTimeHighValueRule(),
            new RepeatOrdersRule(),
            new IpVelocityRule(),
            new AddressQualityRule(),
            new CustomerNameRule(),
            new DisposableEmailRule(),
            new QuantityAnomalyRule(),
            new NightOrderRule(),
            new DistrictReturnRateRule(),
            new OtpVerifiedRule(),
        ];
    }

    /**
     * Register a rule.
     *
     * @param RuleInterface $rule    Rule to add.
     * @param bool          $replace Replace an existing rule with the same id.
     *
     * @throws InvalidArgumentException When the id is invalid or already registered.
     */
    public function register(RuleInterface $rule, bool $replace = false): self
    {
        $id = $rule->id();

        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,48}$/', $id)) {
            throw new InvalidArgumentException(sprintf('Invalid rule id "%s".', $id));
        }

        if (isset($this->rules[$id]) && ! $replace) {
            throw new InvalidArgumentException(sprintf('Rule "%s" is already registered.', $id));
        }

        $this->rules[$id] = $rule;

        return $this;
    }

    /**
     * Remove a rule. Returns false when it was not registered.
     */
    public function unregister(string $id): bool
    {
        if (! isset($this->rules[$id])) {
            return false;
        }

        unset($this->rules[$id]);

        return true;
    }

    /**
     * Whether a rule is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->rules[$id]);
    }

    /**
     * Get a rule by id.
     */
    public function get(string $id): ?RuleInterface
    {
        return $this->rules[$id] ?? null;
    }

    /**
     * All rules in registration order.
     *
     * @return array<string, RuleInterface>
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * Ids of all rules.
     *
     * @return string[]
     */
    public function ids(): array
    {
        return array_keys($this->rules);
    }

    /**
     * Number of rules.
     */
    public function count(): int
    {
        return count($this->rules);
    }
}
