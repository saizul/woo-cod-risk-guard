<?php
/**
 * Settings repository.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

use Saizul\CodRiskGuard\Actions\ActionResolver;
use Saizul\CodRiskGuard\Engine\EngineConfig;
use Saizul\CodRiskGuard\Engine\RuleRegistry;
use Saizul\CodRiskGuard\History\StatusClassifier;
use Saizul\CodRiskGuard\Settings\Defaults;
use Saizul\CodRiskGuard\Settings\Sanitizer;

/**
 * Reads the plugin settings from the options table.
 *
 * Stored values always pass through the sanitiser on the way out, so the
 * rest of the plugin can rely on a complete, well-typed array even if the
 * option was edited by hand or is missing keys added in a later version.
 */
final class Settings
{
    public const OPTION = 'codrg_settings';

    private RuleRegistry $registry;

    /**
     * Per-request cache.
     *
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    public function __construct(RuleRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Sanitiser bound to the registered rules.
     */
    public function sanitizer(): Sanitizer
    {
        return new Sanitizer($this->registry, 'sanitize_text_field');
    }

    /**
     * Complete settings array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (null === $this->cache) {
            $stored = get_option(self::OPTION, null);

            $this->cache = is_array($stored)
                ? $this->sanitizer()->sanitize($stored, false)
                : Defaults::all($this->registry);

            /**
             * Filters the effective plugin settings.
             *
             * @param array<string, mixed> $settings Sanitised settings.
             */
            $filtered = apply_filters('codrg_settings', $this->cache);
            if (is_array($filtered)) {
                $this->cache = $filtered;
            }
        }

        return $this->cache;
    }

    /**
     * Single top-level setting.
     *
     * @param string $key      Setting key.
     * @param mixed  $fallback Value when the key is missing.
     *
     * @return mixed
     */
    public function get(string $key, $fallback = null)
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $fallback;
    }

    /**
     * Forget the cached copy (after the option was updated).
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * Whether scoring is switched on.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->get('enabled', true);
    }

    /**
     * Payment gateway ids that are treated as cash on delivery.
     *
     * @return string[]
     */
    public function gateways(): array
    {
        $gateways = $this->get('gateways', ['cod']);

        return is_array($gateways) ? array_values(array_map('strval', $gateways)) : ['cod'];
    }

    /**
     * Whether the gateway id is one of the monitored gateways.
     */
    public function isMonitoredGateway(string $gateway): bool
    {
        return in_array($gateway, $this->gateways(), true);
    }

    /**
     * Engine configuration built from the settings.
     */
    public function engineConfig(): EngineConfig
    {
        return EngineConfig::fromArray($this->all(), $this->registry);
    }

    /**
     * Level => action resolver built from the settings.
     */
    public function actionResolver(): ActionResolver
    {
        $actions = $this->get('actions', []);

        return new ActionResolver(is_array($actions) ? $actions : []);
    }

    /**
     * Order status => outcome classifier built from the settings.
     */
    public function statusClassifier(): StatusClassifier
    {
        $statuses = $this->get('statuses', []);
        $statuses = is_array($statuses) ? $statuses : [];

        return new StatusClassifier(
            (array) ($statuses['delivered'] ?? ['completed']),
            (array) ($statuses['returned'] ?? ['refunded']),
            (array) ($statuses['cancelled'] ?? ['cancelled', 'failed'])
        );
    }

    /**
     * Customer-facing message for an action, with a translated default.
     *
     * @param string $key Either "block" or "advance".
     */
    public function message(string $key): string
    {
        $messages = $this->get('messages', []);
        $custom   = is_array($messages) && isset($messages[$key]) ? trim((string) $messages[$key]) : '';

        if ('' !== $custom) {
            return $custom;
        }

        if ('advance' === $key) {
            return __('Cash on delivery is not available for this order. Please pay the delivery charge in advance using another payment method.', 'woo-cod-risk-guard');
        }

        return __('Sorry, we cannot accept this order with cash on delivery. Please contact us or choose another payment method.', 'woo-cod-risk-guard');
    }
}
