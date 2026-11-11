<?php
/**
 * Blocklist / allowlist admin tab.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Admin;

use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\WP\Data\ListRepository;
use Saizul\CodRiskGuard\WP\Labels;
use Saizul\CodRiskGuard\WP\Plugin;

/**
 * Lets the merchant add and remove blocklist / allowlist entries.
 *
 * Both handlers run through admin-post.php and verify the capability and a
 * nonce before touching the database.
 */
final class ListsTab
{
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Attach hooks.
     */
    public function register(): void
    {
        add_action('admin_post_codrg_list_add', [$this, 'handleAdd']);
        add_action('admin_post_codrg_list_remove', [$this, 'handleRemove']);
    }

    /**
     * Translated list names.
     *
     * @return array<string, string>
     */
    public static function listLabels(): array
    {
        return [
            ListStoreInterface::BLOCK => __('Blocklist', 'woo-cod-risk-guard'),
            ListStoreInterface::ALLOW => __('Allowlist', 'woo-cod-risk-guard'),
        ];
    }

    /**
     * Handle the "add entry" form.
     */
    public function handleAdd(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'woo-cod-risk-guard'), '', ['response' => 403]);
        }

        check_admin_referer('codrg_list_add');

        $list  = isset($_POST['codrg_list']) ? sanitize_key(wp_unslash($_POST['codrg_list'])) : '';
        $type  = isset($_POST['codrg_type']) ? sanitize_key(wp_unslash($_POST['codrg_type'])) : '';
        $value = isset($_POST['codrg_value']) ? sanitize_text_field(wp_unslash($_POST['codrg_value'])) : '';
        $note  = isset($_POST['codrg_note']) ? sanitize_text_field(wp_unslash($_POST['codrg_note'])) : '';

        $notice = 'invalid';
        if (ListRepository::isValidList($list) && IdentifierHasher::isValidType($type) && '' !== $value) {
            $notice = $this->plugin->lists()->addValue($list, $type, $value, $note) ? 'added' : 'exists';
        }

        $this->redirect($notice);
    }

    /**
     * Handle a "remove" link.
     */
    public function handleRemove(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'woo-cod-risk-guard'), '', ['response' => 403]);
        }

        $id = isset($_GET['entry']) ? absint(wp_unslash($_GET['entry'])) : 0;

        check_admin_referer('codrg_list_remove_' . $id);

        $this->redirect($id > 0 && $this->plugin->lists()->removeById($id) ? 'removed' : 'invalid');
    }

    /**
     * Back to the tab with a notice code.
     *
     * @return never
     */
    private function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg('codrg_notice', $notice, SettingsPage::url('lists')));
        exit;
    }

    /**
     * Render the tab.
     */
    public function render(): void
    {
        $this->renderNotice();

        $typeLabels = [];
        foreach (IdentifierHasher::types() as $type) {
            $typeLabels[$type] = Labels::fragment($type);
        }

        echo '<h2>' . esc_html__('Add an entry', 'woo-cod-risk-guard') . '</h2>';
        echo '<p>' . esc_html__('Entries are stored as keyed hashes. Only a masked label is kept so you can recognise them.', 'woo-cod-risk-guard') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="codrg_list_add">';
        wp_nonce_field('codrg_list_add');

        echo '<select name="codrg_list" aria-label="' . esc_attr__('List', 'woo-cod-risk-guard') . '">';
        foreach (self::listLabels() as $list => $label) {
            echo '<option value="' . esc_attr($list) . '">' . esc_html($label) . '</option>';
        }
        echo '</select> ';

        echo '<select name="codrg_type" aria-label="' . esc_attr__('Type', 'woo-cod-risk-guard') . '">';
        foreach ($typeLabels as $type => $label) {
            echo '<option value="' . esc_attr($type) . '">' . esc_html($label) . '</option>';
        }
        echo '</select> ';

        echo '<input type="text" name="codrg_value" class="regular-text" required placeholder="01700000000" aria-label="' . esc_attr__('Value', 'woo-cod-risk-guard') . '"> ';
        echo '<input type="text" name="codrg_note" class="regular-text" maxlength="255" placeholder="' . esc_attr__('Note (optional)', 'woo-cod-risk-guard') . '" aria-label="' . esc_attr__('Note', 'woo-cod-risk-guard') . '"> ';
        submit_button(__('Add', 'woo-cod-risk-guard'), 'primary', 'submit', false);
        echo '</form>';

        foreach (self::listLabels() as $list => $label) {
            $entries = $this->plugin->lists()->entries($list, 200);
            $total   = $this->plugin->lists()->count($list);

            echo '<h2>' . esc_html($label) . ' <span class="count">(' . esc_html(number_format_i18n($total)) . ')</span></h2>';

            if ([] === $entries) {
                echo '<p>' . esc_html__('No entries yet.', 'woo-cod-risk-guard') . '</p>';
                continue;
            }

            echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
            echo '<th scope="col">' . esc_html__('Type', 'woo-cod-risk-guard') . '</th>';
            echo '<th scope="col">' . esc_html__('Entry (masked)', 'woo-cod-risk-guard') . '</th>';
            echo '<th scope="col">' . esc_html__('Note', 'woo-cod-risk-guard') . '</th>';
            echo '<th scope="col">' . esc_html__('Added (UTC)', 'woo-cod-risk-guard') . '</th>';
            echo '<th scope="col"><span class="screen-reader-text">' . esc_html__('Actions', 'woo-cod-risk-guard') . '</span></th>';
            echo '</tr></thead><tbody>';

            foreach ($entries as $entry) {
                $remove = wp_nonce_url(
                    add_query_arg(
                        [
                            'action' => 'codrg_list_remove',
                            'entry'  => $entry['id'],
                        ],
                        admin_url('admin-post.php')
                    ),
                    'codrg_list_remove_' . $entry['id']
                );

                echo '<tr>';
                echo '<td>' . esc_html($typeLabels[$entry['type']] ?? $entry['type']) . '</td>';
                echo '<td><code>' . esc_html($entry['label']) . '</code></td>';
                echo '<td>' . esc_html($entry['note']) . '</td>';
                echo '<td>' . esc_html($entry['created_at']) . '</td>';
                echo '<td><a class="button-link-delete" href="' . esc_url($remove) . '">' . esc_html__('Remove', 'woo-cod-risk-guard') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            if ($total > count($entries)) {
                echo '<p class="description">' . esc_html__('Only the newest 200 entries are shown. Use WP-CLI to list all of them.', 'woo-cod-risk-guard') . '</p>';
            }
        }
    }

    /**
     * Notice after an add / remove action.
     */
    private function renderNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a fixed message.
        $code = isset($_GET['codrg_notice']) ? sanitize_key(wp_unslash($_GET['codrg_notice'])) : '';

        $messages = [
            'added'   => ['success', __('Entry added.', 'woo-cod-risk-guard')],
            'removed' => ['success', __('Entry removed.', 'woo-cod-risk-guard')],
            'exists'  => ['warning', __('That entry is already on the list, or the value is not valid for the selected type.', 'woo-cod-risk-guard')],
            'invalid' => ['error', __('The entry could not be processed.', 'woo-cod-risk-guard')],
        ];

        if (! isset($messages[$code])) {
            return;
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($messages[$code][0]),
            esc_html($messages[$code][1])
        );
    }
}
