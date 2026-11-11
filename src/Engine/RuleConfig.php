<?php
/**
 * Per-rule configuration.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Engine;

/**
 * Immutable configuration of a single rule: on/off, weight and thresholds.
 */
final class RuleConfig
{
    public bool $enabled;

    public float $weight;

    /**
     * Rule specific options (thresholds etc.).
     *
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * @param array<string, mixed> $options Rule specific options.
     */
    public function __construct(bool $enabled = true, float $weight = 0.0, array $options = [])
    {
        $this->enabled = $enabled;
        $this->weight  = max(0.0, min(100.0, $weight));
        $this->options = $options;
    }

    /**
     * All options.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Whether an option is set.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->options);
    }

    /**
     * Integer option.
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->options[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Float option.
     */
    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->options[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Boolean option.
     */
    public function bool(string $key, bool $default = false): bool
    {
        if (! array_key_exists($key, $this->options)) {
            return $default;
        }

        $value = $this->options[$key];
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * String option.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->options[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Array option.
     *
     * @param array<array-key, mixed> $default Default value.
     *
     * @return array<array-key, mixed>
     */
    public function arr(string $key, array $default = []): array
    {
        $value = $this->options[$key] ?? null;

        return is_array($value) ? $value : $default;
    }

    /**
     * Return a copy with a different weight.
     */
    public function withWeight(float $weight): self
    {
        return new self($this->enabled, $weight, $this->options);
    }

    /**
     * Return a copy with extra / replaced options.
     *
     * @param array<string, mixed> $options Options to merge in.
     */
    public function withOptions(array $options): self
    {
        return new self($this->enabled, $this->weight, array_merge($this->options, $options));
    }

    /**
     * Export as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'weight'  => $this->weight,
            'options' => $this->options,
        ];
    }
}
