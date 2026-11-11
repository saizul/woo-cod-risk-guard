<?php
/**
 * Presentation helpers.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

use Saizul\CodRiskGuard\Actions\Action;
use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\Engine\RiskResult;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Rules\RuleInterface;
use Throwable;

/**
 * Turns engine output into translated, display-ready text.
 */
final class Presenter
{
    /**
     * Reason arguments that are lists of engine fragments: reason code => separator.
     *
     * @var array<string, array<int, string>>
     */
    private const FRAGMENT_ARGS = [
        'blocklist.match'        => [0 => ', '],
        'allowlist.match'        => [0 => ', '],
        'repeat_orders.match'    => [1 => ', '],
        'address_quality.issues' => [0 => '; '],
    ];

    /**
     * Translated name of a risk level.
     */
    public static function levelLabel(string $level): string
    {
        switch ($level) {
            case RiskLevel::HIGH:
                return __('High', 'woo-cod-risk-guard');
            case RiskLevel::MEDIUM:
                return __('Medium', 'woo-cod-risk-guard');
            case RiskLevel::LOW:
                return __('Low', 'woo-cod-risk-guard');
        }

        return $level;
    }

    /**
     * Translated name of an action.
     */
    public static function actionLabel(string $action): string
    {
        switch ($action) {
            case Action::ALLOW:
                return __('Allow', 'woo-cod-risk-guard');
            case Action::HOLD:
                return __('Put on hold for review', 'woo-cod-risk-guard');
            case Action::REQUIRE_ADVANCE:
                return __('Require advance payment (hide COD)', 'woo-cod-risk-guard');
            case Action::BLOCK:
                return __('Block the order', 'woo-cod-risk-guard');
        }

        return $action;
    }

    /**
     * Translated label of a rule.
     */
    public static function ruleLabel(RuleInterface $rule): string
    {
        return Labels::text($rule->label());
    }

    /**
     * Translated reason of one rule result.
     *
     * Falls back to the English reason stored with the result when the
     * reason code is unknown (third-party rules) or the template cannot be
     * filled.
     */
    public static function reason(RuleResult $result): string
    {
        // Reason codes are already namespaced by the rule, e.g. "phone_validity.missing".
        $code     = $result->reasonCode;
        $template = Labels::template($code);

        if (null === $template) {
            return $result->reason;
        }

        $args = $result->reasonArgs;
        foreach (self::FRAGMENT_ARGS[$code] ?? [] as $index => $separator) {
            if (isset($args[$index]) && is_string($args[$index])) {
                $parts        = array_map([Labels::class, 'fragment'], explode($separator, $args[$index]));
                $args[$index] = implode($separator, $parts);
            }
        }

        if ([] === $args) {
            return $template;
        }

        try {
            $text = vsprintf($template, $args);
        } catch (Throwable $e) {
            return $result->reason;
        }

        return is_string($text) && '' !== $text ? $text : $result->reason;
    }

    /**
     * Translated reasons of a result, strongest first.
     *
     * @return string[]
     */
    public static function reasons(RiskResult $result): array
    {
        $reasons = [];
        foreach ($result->triggered() as $rule) {
            $text = self::reason($rule);
            if ('' !== $text) {
                $reasons[] = $text;
            }
        }

        return $reasons;
    }

    /**
     * Signed points of a rule result, e.g. "+12" or "-8".
     */
    public static function points(RuleResult $result): string
    {
        $points = round($result->points, 1);
        $text   = rtrim(rtrim(number_format(abs($points), 1, '.', ''), '0'), '.');

        return ($points < 0 ? '-' : '+') . $text;
    }

    /**
     * Escaped HTML badge for a score.
     */
    public static function badge(int $score, string $level): string
    {
        $level = RiskLevel::isValid($level) ? $level : RiskLevel::LOW;

        return sprintf(
            '<span class="codrg-badge codrg-badge--%1$s" title="%2$s">%3$d</span>',
            esc_attr($level),
            /* translators: %s: risk level name. */
            esc_attr(sprintf(__('COD risk: %s', 'woo-cod-risk-guard'), self::levelLabel($level))),
            $score
        );
    }

    /**
     * Inline CSS for the badge (small enough not to justify a stylesheet).
     */
    public static function badgeCss(): string
    {
        return '.codrg-badge{display:inline-block;min-width:2.2em;padding:2px 6px;border-radius:10px;font-weight:600;text-align:center;color:#fff;line-height:1.5}'
            . '.codrg-badge--low{background:#2e7d32}'
            . '.codrg-badge--medium{background:#b26a00}'
            . '.codrg-badge--high{background:#c62828}'
            . '.codrg-reasons{margin:.5em 0 0 1.2em;list-style:disc}'
            . '.codrg-points{color:#646970;white-space:nowrap}';
    }
}
