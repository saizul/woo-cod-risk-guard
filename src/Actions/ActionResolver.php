<?php
/**
 * Maps risk levels to actions.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Actions;

use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\Engine\RiskResult;
use Saizul\CodRiskGuard\Engine\RuleResult;

/**
 * Decides what to do with an order based on its risk level.
 */
final class ActionResolver
{
    /**
     * @var array<string, string>
     */
    private array $map;

    /**
     * @param array<string, string> $map Level => action. Missing or invalid entries use the defaults.
     */
    public function __construct(array $map = [])
    {
        $this->map = self::defaults();

        foreach (RiskLevel::all() as $level) {
            $action = $map[$level] ?? null;
            if (is_string($action) && Action::isValid($action)) {
                $this->map[$level] = $action;
            }
        }
    }

    /**
     * Default level => action map.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            RiskLevel::LOW    => Action::ALLOW,
            RiskLevel::MEDIUM => Action::HOLD,
            RiskLevel::HIGH   => Action::REQUIRE_ADVANCE,
        ];
    }

    /**
     * Action for a risk level.
     */
    public function forLevel(string $level): string
    {
        return $this->map[$level] ?? Action::ALLOW;
    }

    /**
     * Action for a scoring result.
     *
     * Allowlisted customers are always allowed, whatever the level map says.
     */
    public function resolve(RiskResult $result): string
    {
        if (RuleResult::OVERRIDE_ALLOW === $result->override) {
            return Action::ALLOW;
        }

        return $this->forLevel($result->level);
    }

    /**
     * The effective level => action map.
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        return $this->map;
    }
}
