<?php
/**
 * Engine configuration.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Engine;

use Saizul\CodRiskGuard\Rules\RuleInterface;

/**
 * Level thresholds plus the configuration of every rule.
 */
final class EngineConfig
{
    public const DEFAULT_MEDIUM = 40;
    public const DEFAULT_HIGH   = 70;

    public int $mediumThreshold;

    public int $highThreshold;

    /**
     * @var array<string, RuleConfig>
     */
    private array $rules;

    /**
     * @param array<string, RuleConfig> $rules Rule configuration keyed by rule id.
     */
    public function __construct(int $mediumThreshold = self::DEFAULT_MEDIUM, int $highThreshold = self::DEFAULT_HIGH, array $rules = [])
    {
        $mediumThreshold = max(1, min(99, $mediumThreshold));
        $highThreshold   = max($mediumThreshold + 1, min(100, $highThreshold));

        $this->mediumThreshold = $mediumThreshold;
        $this->highThreshold   = $highThreshold;
        $this->rules           = $rules;
    }

    /**
     * Default configuration for every rule in the registry.
     */
    public static function defaults(RuleRegistry $registry): self
    {
        return self::fromArray([], $registry);
    }

    /**
     * Build from a settings array, filling in rule defaults.
     *
     * Expected shape:
     *   [ 'thresholds' => [ 'medium' => 40, 'high' => 70 ],
     *     'rules'      => [ '<rule id>' => [ 'enabled' => true, 'weight' => 25, 'options' => [...] ] ] ]
     *
     * @param array<string, mixed> $settings Settings array.
     * @param RuleRegistry         $registry Registered rules.
     */
    public static function fromArray(array $settings, RuleRegistry $registry): self
    {
        $thresholds = is_array($settings['thresholds'] ?? null) ? $settings['thresholds'] : [];
        $stored     = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
        $rules      = [];

        foreach ($registry->all() as $id => $rule) {
            $rules[$id] = self::ruleConfig($rule, is_array($stored[$id] ?? null) ? $stored[$id] : []);
        }

        return new self(
            is_numeric($thresholds['medium'] ?? null) ? (int) $thresholds['medium'] : self::DEFAULT_MEDIUM,
            is_numeric($thresholds['high'] ?? null) ? (int) $thresholds['high'] : self::DEFAULT_HIGH,
            $rules
        );
    }

    /**
     * Default options of a rule, derived from its field schema.
     *
     * @return array<string, mixed>
     */
    public static function defaultOptions(RuleInterface $rule): array
    {
        $options = [];
        foreach ($rule->fields() as $key => $field) {
            $options[(string) $key] = is_array($field) ? ($field['default'] ?? null) : null;
        }

        return $options;
    }

    /**
     * Configuration for a rule (defaults when it was never configured).
     */
    public function rule(string $id): RuleConfig
    {
        return $this->rules[$id] ?? new RuleConfig(false, 0.0);
    }

    /**
     * Whether a rule has configuration.
     */
    public function hasRule(string $id): bool
    {
        return isset($this->rules[$id]);
    }

    /**
     * Return a copy with one rule's configuration replaced.
     */
    public function withRule(string $id, RuleConfig $config): self
    {
        $rules      = $this->rules;
        $rules[$id] = $config;

        return new self($this->mediumThreshold, $this->highThreshold, $rules);
    }

    /**
     * All rule configurations.
     *
     * @return array<string, RuleConfig>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Merge stored values over a rule's defaults.
     *
     * @param array<string, mixed> $stored Stored rule settings.
     */
    private static function ruleConfig(RuleInterface $rule, array $stored): RuleConfig
    {
        $options = self::defaultOptions($rule);
        if (is_array($stored['options'] ?? null)) {
            $options = array_merge($options, array_intersect_key($stored['options'], $options));
        }

        $enabled = array_key_exists('enabled', $stored) ? (bool) $stored['enabled'] : $rule->defaultEnabled();
        $weight  = is_numeric($stored['weight'] ?? null) ? (float) $stored['weight'] : $rule->defaultWeight();

        return new RuleConfig($enabled, $weight, $options);
    }
}
