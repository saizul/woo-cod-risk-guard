<?php
/**
 * Settings sanitisation.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Settings;

use Saizul\CodRiskGuard\Actions\Action;
use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\Engine\RuleRegistry;
use Saizul\CodRiskGuard\History\Outcome;
use Saizul\CodRiskGuard\Support\DisposableEmailDomains;
use Saizul\CodRiskGuard\Support\DistrictNormalizer;
use Saizul\CodRiskGuard\Support\Str;

/**
 * Turns untrusted input (a submitted settings form, a stored option, an
 * imported array) into a complete, well-typed settings array.
 *
 * The class has no WordPress dependency; the integration layer passes in a
 * text sanitiser (sanitize_text_field) so both layers agree on the result.
 */
final class Sanitizer
{
    private RuleRegistry $registry;

    /**
     * @var callable(string):string
     */
    private $textSanitizer;

    /**
     * @param RuleRegistry  $registry      Registered rules.
     * @param callable|null $textSanitizer Function used to clean free text.
     */
    public function __construct(RuleRegistry $registry, ?callable $textSanitizer = null)
    {
        $this->registry      = $registry;
        $this->textSanitizer = $textSanitizer ?? [self::class, 'plainText'];
    }

    /**
     * Sanitise a settings array.
     *
     * @param mixed $input    Untrusted input.
     * @param bool  $fromForm True when the input comes from an HTML form, where an
     *                        unchecked checkbox is simply absent and means "off".
     *
     * @return array<string, mixed>
     */
    public function sanitize($input, bool $fromForm = false): array
    {
        $defaults = Defaults::all($this->registry);
        $input    = is_array($input) ? $input : [];

        $clean = [
            'enabled'             => $this->flag($input, 'enabled', (bool) $defaults['enabled'], $fromForm),
            'gateways'            => $this->slugList($input['gateways'] ?? $defaults['gateways'], $defaults['gateways']),
            'thresholds'          => $this->thresholds($input['thresholds'] ?? [], $defaults['thresholds']),
            'actions'             => $this->actions($input['actions'] ?? [], $defaults['actions']),
            'messages'            => $this->messages($input['messages'] ?? []),
            'statuses'            => $this->statuses($input['statuses'] ?? [], $defaults['statuses']),
            'order_note'          => $this->flag($input, 'order_note', (bool) $defaults['order_note'], $fromForm),
            'retention_days'      => $this->intRange($input['retention_days'] ?? null, 1, 3650, (int) $defaults['retention_days']),
            'delete_on_uninstall' => $this->flag($input, 'delete_on_uninstall', (bool) $defaults['delete_on_uninstall'], $fromForm),
            'rules'               => [],
        ];

        $rules = is_array($input['rules'] ?? null) ? $input['rules'] : [];
        foreach ($this->registry->all() as $id => $rule) {
            $stored      = is_array($rules[$id] ?? null) ? $rules[$id] : [];
            $ruleDefault = $defaults['rules'][$id];

            $options       = [];
            $storedOptions = is_array($stored['options'] ?? null) ? $stored['options'] : [];
            foreach ($rule->fields() as $key => $field) {
                $options[$key] = $this->field(
                    is_array($field) ? $field : [],
                    $storedOptions,
                    (string) $key,
                    $fromForm
                );
            }

            $clean['rules'][$id] = [
                'enabled' => $this->flag($stored, 'enabled', (bool) $ruleDefault['enabled'], $fromForm),
                'weight'  => $this->floatRange($stored['weight'] ?? null, 0.0, 100.0, (float) $ruleDefault['weight']),
                'options' => $options,
            ];
        }

        return $clean;
    }

    /**
     * Default text cleaner: strip tags and control characters, collapse whitespace.
     */
    public static function plainText(string $value): string
    {
        $value = strip_tags($value);
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return Str::squish(null === $clean ? $value : $clean);
    }

    /**
     * Interpret a truthy / falsy value.
     *
     * @param mixed $value Raw value.
     */
    public static function toBool($value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'yes', 'true', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * @param array<array-key, mixed> $source   Input array.
     * @param string                  $key      Key to read.
     * @param bool                    $default  Default when absent.
     * @param bool                    $fromForm Absent means false.
     */
    private function flag(array $source, string $key, bool $default, bool $fromForm): bool
    {
        if (! array_key_exists($key, $source)) {
            return $fromForm ? false : $default;
        }

        return self::toBool($source[$key]);
    }

    /**
     * @param mixed $value Raw value.
     */
    private function intRange($value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) round((float) $value)));
    }

    /**
     * @param mixed $value Raw value.
     */
    private function floatRange($value, float $min, float $max, float $default): float
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, round((float) $value, 4)));
    }

    /**
     * @param mixed                $value    Raw value.
     * @param array<string, mixed> $defaults Default thresholds.
     *
     * @return array{medium:int, high:int}
     */
    private function thresholds($value, array $defaults): array
    {
        $value  = is_array($value) ? $value : [];
        $medium = $this->intRange($value['medium'] ?? null, 1, 99, (int) $defaults['medium']);
        $high   = $this->intRange($value['high'] ?? null, 2, 100, (int) $defaults['high']);

        if ($high <= $medium) {
            $high = min(100, $medium + 1);
        }

        return ['medium' => $medium, 'high' => $high];
    }

    /**
     * @param mixed                 $value    Raw value.
     * @param array<string, string> $defaults Default actions.
     *
     * @return array<string, string>
     */
    private function actions($value, array $defaults): array
    {
        $value = is_array($value) ? $value : [];
        $clean = [];

        foreach (RiskLevel::all() as $level) {
            $action        = is_string($value[$level] ?? null) ? $value[$level] : '';
            $clean[$level] = Action::isValid($action) ? $action : $defaults[$level];
        }

        return $clean;
    }

    /**
     * @param mixed $value Raw value.
     *
     * @return array{block:string, advance:string}
     */
    private function messages($value): array
    {
        $value = is_array($value) ? $value : [];
        $clean = [];

        foreach (['block', 'advance'] as $key) {
            $text        = is_scalar($value[$key] ?? null) ? (string) $value[$key] : '';
            $text        = (string) call_user_func($this->textSanitizer, $text);
            $clean[$key] = function_exists('mb_substr') ? mb_substr($text, 0, 500, 'UTF-8') : substr($text, 0, 500);
        }

        return $clean;
    }

    /**
     * @param mixed                   $value    Raw value.
     * @param array<string, string[]> $defaults Default status map.
     *
     * @return array<string, string[]>
     */
    private function statuses($value, array $defaults): array
    {
        $value = is_array($value) ? $value : [];
        $clean = [];

        foreach (Outcome::all() as $outcome) {
            $clean[$outcome] = array_key_exists($outcome, $value)
                ? $this->slugList($value[$outcome], [])
                : $defaults[$outcome];
        }

        return $clean;
    }

    /**
     * Clean a list of slugs given as an array or a comma / line separated string.
     *
     * @param mixed    $value    Raw value.
     * @param string[] $fallback Used when nothing valid remains.
     *
     * @return string[]
     */
    private function slugList($value, array $fallback): array
    {
        $items = is_array($value) ? $value : Str::lines(is_scalar($value) ? (string) $value : '');
        $clean = [];

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $slug = preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim((string) $item)));
            if (null !== $slug && '' !== $slug) {
                $clean[] = 0 === strpos($slug, 'wc-') ? substr($slug, 3) : $slug;
            }
        }

        $clean = array_values(array_unique(array_filter($clean, 'strlen')));

        return [] === $clean ? $fallback : $clean;
    }

    /**
     * Sanitise one rule option according to its field schema.
     *
     * @param array<string, mixed>    $field    Field schema.
     * @param array<array-key, mixed> $stored   Submitted options.
     * @param string                  $key      Option key.
     * @param bool                    $fromForm Whether input comes from a form.
     *
     * @return mixed
     */
    private function field(array $field, array $stored, string $key, bool $fromForm)
    {
        $type    = (string) ($field['type'] ?? 'text');
        $default = $field['default'] ?? null;
        $present = array_key_exists($key, $stored);
        $value   = $present ? $stored[$key] : null;

        switch ($type) {
            case 'bool':
                return $this->flag($stored, $key, (bool) $default, $fromForm);

            case 'int':
                return $this->intRange(
                    $value,
                    (int) ($field['min'] ?? PHP_INT_MIN),
                    (int) ($field['max'] ?? PHP_INT_MAX),
                    (int) $default
                );

            case 'float':
                return $this->floatRange(
                    $value,
                    (float) ($field['min'] ?? -1.0e12),
                    (float) ($field['max'] ?? 1.0e12),
                    (float) $default
                );

            case 'lines':
                if (! $present) {
                    return is_array($default) ? $default : [];
                }

                $items = is_array($value) ? $value : Str::lines(is_scalar($value) ? (string) $value : '');
                $clean = [];
                foreach ($items as $item) {
                    $domain = is_scalar($item) ? DisposableEmailDomains::cleanDomain((string) $item) : '';
                    if ('' !== $domain) {
                        $clean[] = $domain;
                    }
                }

                return array_values(array_unique($clean));

            case 'rates':
                if (! $present) {
                    return is_array($default) ? $default : [];
                }

                return DistrictNormalizer::parseRates(is_array($value) ? $value : (is_scalar($value) ? (string) $value : ''));

            default:
                if (! $present || ! is_scalar($value)) {
                    return is_scalar($default) ? (string) $default : '';
                }

                return (string) call_user_func($this->textSanitizer, (string) $value);
        }
    }
}
