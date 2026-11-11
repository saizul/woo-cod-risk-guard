<?php
/**
 * Settings screen.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Admin;

use Saizul\CodRiskGuard\Actions\Action;
use Saizul\CodRiskGuard\Engine\RiskLevel;
use Saizul\CodRiskGuard\History\Outcome;
use Saizul\CodRiskGuard\Rules\RuleInterface;
use Saizul\CodRiskGuard\WP\Labels;
use Saizul\CodRiskGuard\WP\Plugin;
use Saizul\CodRiskGuard\WP\Presenter;
use Saizul\CodRiskGuard\WP\Settings;

/**
 * WooCommerce > COD Risk Guard.
 *
 * The settings tab uses the WordPress Settings API (register_setting(),
 * settings_fields(), options.php), which takes care of the nonce and the
 * capability check for saving. The other tabs are rendered by ListsTab and
 * CsvExport.
 */
final class SettingsPage
{
    public const SLUG  = 'codrg-settings';
    public const GROUP = 'codrg_settings_group';

    private Plugin $plugin;

    private ListsTab $lists;

    private ReportTab $report;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->lists  = new ListsTab($plugin);
        $this->report = new ReportTab($plugin);
    }

    /**
     * Attach hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 60);
        add_action('admin_init', [$this, 'registerSetting']);
        add_filter('option_page_capability_' . self::GROUP, [$this, 'capability']);
        add_filter('plugin_action_links_' . plugin_basename($this->plugin->file()), [$this, 'actionLinks']);
        add_action('update_option_' . Settings::OPTION, [$this, 'onSaved'], 10, 0);
        add_action('add_option_' . Settings::OPTION, [$this, 'onSaved'], 10, 0);

        $this->lists->register();
    }

    /**
     * URL of a tab of the settings screen.
     */
    public static function url(string $tab = 'settings'): string
    {
        return add_query_arg(
            [
                'page' => self::SLUG,
                'tab'  => $tab,
            ],
            admin_url('admin.php')
        );
    }

    /**
     * Capability needed to save the settings through options.php.
     */
    public function capability(): string
    {
        return Plugin::CAPABILITY;
    }

    /**
     * Add the submenu page under WooCommerce.
     */
    public function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('COD Risk Guard', 'woo-cod-risk-guard'),
            __('COD Risk Guard', 'woo-cod-risk-guard'),
            Plugin::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    /**
     * "Settings" link on the plugins screen.
     *
     * @param mixed $links Existing links.
     *
     * @return mixed
     */
    public function actionLinks($links)
    {
        if (! is_array($links)) {
            return $links;
        }

        array_unshift(
            $links,
            sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('Settings', 'woo-cod-risk-guard'))
        );

        return $links;
    }

    /**
     * Register the option with its sanitisation callback.
     */
    public function registerSetting(): void
    {
        register_setting(
            self::GROUP,
            Settings::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
                'show_in_rest'      => false,
            ]
        );
    }

    /**
     * Sanitise the submitted settings.
     *
     * Every value is validated against the rule schema: unknown keys are
     * dropped, numbers are clamped to their ranges and text is cleaned with
     * sanitize_text_field().
     *
     * @param mixed $input Raw form input.
     *
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        return $this->plugin->settings()->sanitizer()->sanitize($input, true);
    }

    /**
     * Drop cached settings after a save.
     */
    public function onSaved(): void
    {
        $this->plugin->reset();
    }

    /**
     * Render the screen.
     */
    public function render(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'woo-cod-risk-guard'));
        }

        $tabs = [
            'settings' => __('Settings', 'woo-cod-risk-guard'),
            'lists'    => __('Blocklist & allowlist', 'woo-cod-risk-guard'),
            'report'   => __('Report & export', 'woo-cod-risk-guard'),
        ];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if (! isset($tabs[$tab])) {
            $tab = 'settings';
        }

        echo '<div class="wrap codrg-wrap">';
        echo '<h1>' . esc_html__('COD Risk Guard', 'woo-cod-risk-guard') . '</h1>';
        echo '<style>' . Presenter::badgeCss() . '.codrg-rules td,.codrg-rules th{vertical-align:top}.codrg-rules .codrg-option{display:block;margin:0 0 8px}.codrg-rules textarea{width:100%;max-width:28em}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS.

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $id => $label) {
            printf(
                '<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
                esc_url(self::url($id)),
                $id === $tab ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';

        if ('lists' === $tab) {
            $this->lists->render();
        } elseif ('report' === $tab) {
            $this->report->render();
        } else {
            $this->renderSettings();
        }

        echo '</div>';
    }

    /**
     * Field name inside the option array.
     *
     * @param string ...$path Path segments.
     */
    private static function name(string ...$path): string
    {
        return Settings::OPTION . '[' . implode('][', $path) . ']';
    }

    /**
     * The settings form.
     */
    private function renderSettings(): void
    {
        $settings = $this->plugin->settings()->all();

        settings_errors();

        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);

        echo '<h2>' . esc_html__('General', 'woo-cod-risk-guard') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->row(
            __('Scoring', 'woo-cod-risk-guard'),
            $this->checkbox(self::name('enabled'), (bool) $settings['enabled'], __('Score new orders at checkout', 'woo-cod-risk-guard'))
        );

        $this->row(__('Monitored payment methods', 'woo-cod-risk-guard'), $this->gatewayFields((array) $settings['gateways']));

        $thresholds  = (array) $settings['thresholds'];
        $thresholdUi = sprintf(
            '<label>%1$s <input type="number" class="small-text" min="1" max="99" name="%2$s" value="%3$d"></label> &nbsp; <label>%4$s <input type="number" class="small-text" min="2" max="100" name="%5$s" value="%6$d"></label><p class="description">%7$s</p>',
            esc_html__('Medium from', 'woo-cod-risk-guard'),
            esc_attr(self::name('thresholds', 'medium')),
            (int) ($thresholds['medium'] ?? 40),
            esc_html__('High from', 'woo-cod-risk-guard'),
            esc_attr(self::name('thresholds', 'high')),
            (int) ($thresholds['high'] ?? 70),
            esc_html__('Scores run from 0 (no risk signals) to 100.', 'woo-cod-risk-guard')
        );
        $this->row(__('Risk level thresholds', 'woo-cod-risk-guard'), $thresholdUi);

        $actions = (array) $settings['actions'];
        foreach (RiskLevel::all() as $level) {
            $this->row(
                /* translators: %s: risk level name. */
                sprintf(__('Action for %s risk', 'woo-cod-risk-guard'), strtolower(Presenter::levelLabel($level))),
                $this->actionSelect(self::name('actions', $level), (string) ($actions[$level] ?? Action::ALLOW))
            );
        }

        $messages = (array) $settings['messages'];
        $this->row(
            __('Message when an order is blocked', 'woo-cod-risk-guard'),
            $this->textarea(self::name('messages', 'block'), (string) ($messages['block'] ?? ''), $this->plugin->settings()->message('block'))
        );
        $this->row(
            __('Message when advance payment is required', 'woo-cod-risk-guard'),
            $this->textarea(self::name('messages', 'advance'), (string) ($messages['advance'] ?? ''), $this->plugin->settings()->message('advance'))
        );

        $statuses = (array) $settings['statuses'];
        $labels   = [
            Outcome::DELIVERED => __('Statuses that mean "delivered"', 'woo-cod-risk-guard'),
            Outcome::RETURNED  => __('Statuses that mean "returned"', 'woo-cod-risk-guard'),
            Outcome::CANCELLED => __('Statuses that mean "cancelled"', 'woo-cod-risk-guard'),
        ];
        foreach ($labels as $outcome => $label) {
            $this->row($label, $this->statusFields($outcome, (array) ($statuses[$outcome] ?? [])));
        }

        $this->row(
            __('Order note', 'woo-cod-risk-guard'),
            $this->checkbox(self::name('order_note'), (bool) $settings['order_note'], __('Add a private order note with the score and the reasons', 'woo-cod-risk-guard'))
        );

        $this->row(
            __('Velocity data retention', 'woo-cod-risk-guard'),
            sprintf(
                '<input type="number" class="small-text" min="1" max="3650" name="%1$s" value="%2$d"> %3$s<p class="description">%4$s</p>',
                esc_attr(self::name('retention_days')),
                (int) $settings['retention_days'],
                esc_html__('days', 'woo-cod-risk-guard'),
                esc_html__('Hashed order events used for the velocity rules are deleted after this many days.', 'woo-cod-risk-guard')
            )
        );

        $this->row(
            __('Uninstall', 'woo-cod-risk-guard'),
            $this->checkbox(self::name('delete_on_uninstall'), (bool) $settings['delete_on_uninstall'], __('Delete all plugin data (settings, lists, history tables) when the plugin is deleted', 'woo-cod-risk-guard'))
        );

        echo '</tbody></table>';

        $this->renderRules((array) $settings['rules']);

        submit_button();
        echo '</form>';
    }

    /**
     * The rules table.
     *
     * @param array<string, mixed> $stored Stored rule settings.
     */
    private function renderRules(array $stored): void
    {
        echo '<h2>' . esc_html__('Rules', 'woo-cod-risk-guard') . '</h2>';
        echo '<p>' . esc_html__('Each rule adds up to its weight to the score. The total is capped at 100.', 'woo-cod-risk-guard') . '</p>';
        echo '<table class="widefat striped codrg-rules"><thead><tr>';
        echo '<th scope="col">' . esc_html__('On', 'woo-cod-risk-guard') . '</th>';
        echo '<th scope="col">' . esc_html__('Rule', 'woo-cod-risk-guard') . '</th>';
        echo '<th scope="col">' . esc_html__('Weight', 'woo-cod-risk-guard') . '</th>';
        echo '<th scope="col">' . esc_html__('Options', 'woo-cod-risk-guard') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($this->plugin->registry()->all() as $id => $rule) {
            $id     = (string) $id;
            $config = is_array($stored[$id] ?? null) ? $stored[$id] : [];

            echo '<tr>';
            printf(
                '<td><input type="checkbox" name="%1$s" value="1" aria-label="%2$s"%3$s></td>',
                esc_attr(self::name('rules', $id, 'enabled')),
                esc_attr(Presenter::ruleLabel($rule)),
                checked(! empty($config['enabled']), true, false)
            );
            printf(
                '<td><strong>%1$s</strong><p class="description">%2$s</p></td>',
                esc_html(Presenter::ruleLabel($rule)),
                esc_html(Labels::text($rule->description()))
            );
            printf(
                '<td><input type="number" class="small-text" min="0" max="100" step="1" name="%1$s" value="%2$s"></td>',
                esc_attr(self::name('rules', $id, 'weight')),
                esc_attr((string) (float) ($config['weight'] ?? $rule->defaultWeight()))
            );
            echo '<td>';
            $this->renderRuleOptions($rule, is_array($config['options'] ?? null) ? $config['options'] : []);
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Option inputs of one rule, generated from its field schema.
     *
     * @param array<string, mixed> $options Stored option values.
     */
    private function renderRuleOptions(RuleInterface $rule, array $options): void
    {
        $fields = $rule->fields();
        if ([] === $fields) {
            echo '<span aria-hidden="true">&mdash;</span>';

            return;
        }

        foreach ($fields as $key => $field) {
            $key   = (string) $key;
            $type  = (string) ($field['type'] ?? 'text');
            $name  = self::name('rules', $rule->id(), 'options', $key);
            $label = Labels::text((string) ($field['label'] ?? $key));
            $value = $options[$key] ?? ($field['default'] ?? '');

            echo '<label class="codrg-option">';

            switch ($type) {
                case 'bool':
                    printf(
                        '<input type="checkbox" name="%1$s" value="1"%2$s> %3$s',
                        esc_attr($name),
                        checked((bool) $value, true, false),
                        esc_html($label)
                    );
                    break;

                case 'int':
                case 'float':
                    printf(
                        '%1$s<br><input type="number" class="small-text" name="%2$s" value="%3$s" step="%4$s"%5$s%6$s>',
                        esc_html($label),
                        esc_attr($name),
                        esc_attr(is_scalar($value) ? (string) $value : ''),
                        'int' === $type ? '1' : 'any',
                        isset($field['min']) ? ' min="' . esc_attr((string) $field['min']) . '"' : '',
                        isset($field['max']) ? ' max="' . esc_attr((string) $field['max']) . '"' : ''
                    );
                    break;

                case 'lines':
                    printf(
                        '%1$s<br><textarea rows="4" name="%2$s">%3$s</textarea>',
                        esc_html($label),
                        esc_attr($name),
                        esc_textarea(implode("\n", array_map('strval', is_array($value) ? $value : [])))
                    );
                    break;

                case 'rates':
                    $lines = [];
                    foreach (is_array($value) ? $value : [] as $district => $rate) {
                        $lines[] = $district . '=' . (is_scalar($rate) ? (string) $rate : '');
                    }
                    printf(
                        '%1$s<br><textarea rows="5" name="%2$s" placeholder="%3$s">%4$s</textarea>',
                        esc_html($label),
                        esc_attr($name),
                        esc_attr("Dhaka=12\nGazipur=25"),
                        esc_textarea(implode("\n", $lines))
                    );
                    break;

                default:
                    printf(
                        '%1$s<br><input type="text" class="regular-text" name="%2$s" value="%3$s">',
                        esc_html($label),
                        esc_attr($name),
                        esc_attr(is_scalar($value) ? (string) $value : '')
                    );
            }

            if (! empty($field['help']) && is_string($field['help'])) {
                echo '<span class="description"> ' . esc_html(Labels::text($field['help'])) . '</span>';
            }

            echo '</label>';
        }
    }

    /**
     * Print one form-table row. $html must already be escaped.
     */
    private function row(string $label, string $html): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments by the helpers below.
    }

    /**
     * Escaped checkbox markup.
     */
    private function checkbox(string $name, bool $checked, string $label): string
    {
        return sprintf(
            '<label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
            esc_attr($name),
            checked($checked, true, false),
            esc_html($label)
        );
    }

    /**
     * Escaped textarea markup.
     */
    private function textarea(string $name, string $value, string $placeholder): string
    {
        return sprintf(
            '<textarea class="large-text" rows="2" maxlength="500" name="%1$s" placeholder="%2$s">%3$s</textarea><p class="description">%4$s</p>',
            esc_attr($name),
            esc_attr($placeholder),
            esc_textarea($value),
            esc_html__('Leave empty to use the default message.', 'woo-cod-risk-guard')
        );
    }

    /**
     * Escaped action dropdown.
     */
    private function actionSelect(string $name, string $current): string
    {
        $html = '<select name="' . esc_attr($name) . '">';
        foreach (Action::all() as $action) {
            $html .= sprintf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($action),
                selected($current, $action, false),
                esc_html(Presenter::actionLabel($action))
            );
        }

        return $html . '</select>';
    }

    /**
     * Escaped payment gateway checkboxes.
     *
     * @param array<int, mixed> $selected Selected gateway ids.
     */
    private function gatewayFields(array $selected): string
    {
        $gateways = [];
        if (function_exists('WC') && null !== WC()->payment_gateways()) {
            foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
                $gateways[(string) $gateway->id] = wp_strip_all_tags((string) $gateway->get_method_title());
            }
        }

        foreach ($selected as $id) {
            if (is_string($id) && ! isset($gateways[$id])) {
                $gateways[$id] = $id;
            }
        }

        $html = '';
        foreach ($gateways as $id => $title) {
            $html .= sprintf(
                '<label style="display:block"><input type="checkbox" name="%1$s" value="%2$s"%3$s> %4$s <code>%2$s</code></label>',
                esc_attr(self::name('gateways') . '[]'),
                esc_attr((string) $id),
                checked(in_array($id, $selected, true), true, false),
                esc_html($title)
            );
        }

        return $html . '<p class="description">' . esc_html__('Orders paid with these methods are scored. Usually only cash on delivery.', 'woo-cod-risk-guard') . '</p>';
    }

    /**
     * Escaped order status checkboxes for one outcome.
     *
     * @param array<int, mixed> $selected Selected status slugs (without the "wc-" prefix).
     */
    private function statusFields(string $outcome, array $selected): string
    {
        $name = self::name('statuses', $outcome) . '[]';

        // The empty value keeps the key present when every box is unticked.
        $html = '<input type="hidden" name="' . esc_attr($name) . '" value="">';

        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        foreach ($statuses as $slug => $label) {
            $slug = 0 === strpos((string) $slug, 'wc-') ? substr((string) $slug, 3) : (string) $slug;

            $html .= sprintf(
                '<label style="display:inline-block;margin-right:14px"><input type="checkbox" name="%1$s" value="%2$s"%3$s> %4$s</label>',
                esc_attr($name),
                esc_attr($slug),
                checked(in_array($slug, $selected, true), true, false),
                esc_html((string) $label)
            );
        }

        return $html;
    }
}
