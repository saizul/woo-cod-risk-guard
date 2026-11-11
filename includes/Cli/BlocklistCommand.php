<?php
/**
 * WP-CLI blocklist commands.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP\Cli;

use Saizul\CodRiskGuard\Lists\ListStoreInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\WP\Plugin;
use WP_CLI;

/**
 * Manage the COD blocklist (use --list=allow for the allowlist).
 */
final class BlocklistCommand
{
    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Add an entry.
     *
     * ## OPTIONS
     *
     * <value>
     * : Phone number, email address, IP address or device id.
     *
     * [--type=<type>]
     * : Kind of value.
     * ---
     * default: phone
     * options:
     *   - phone
     *   - email
     *   - ip
     *   - device
     * ---
     *
     * [--list=<list>]
     * : Which list to change.
     * ---
     * default: block
     * options:
     *   - block
     *   - allow
     * ---
     *
     * [--note=<note>]
     * : Optional note.
     *
     * ## EXAMPLES
     *
     *     wp cod-risk blocklist add 01700000000 --note="Refused three parcels"
     *     wp cod-risk blocklist add 192.0.2.10 --type=ip
     *
     * @param string[]              $args  Positional arguments.
     * @param array<string, string> $assoc Named arguments.
     */
    public function add(array $args, array $assoc): void
    {
        [$list, $type] = $this->target($assoc);

        $added = $this->plugin->lists()->addValue($list, $type, (string) ($args[0] ?? ''), sanitize_text_field((string) ($assoc['note'] ?? '')));

        if (! $added) {
            WP_CLI::error('The entry was not added. It is already on the list or the value is not valid for this type.');
        }

        WP_CLI::success('Entry added.');
    }

    /**
     * Remove an entry.
     *
     * ## OPTIONS
     *
     * <value>
     * : Phone number, email address, IP address or device id.
     *
     * [--type=<type>]
     * : Kind of value.
     * ---
     * default: phone
     * options:
     *   - phone
     *   - email
     *   - ip
     *   - device
     * ---
     *
     * [--list=<list>]
     * : Which list to change.
     * ---
     * default: block
     * options:
     *   - block
     *   - allow
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cod-risk blocklist remove 01700000000
     *
     * @param string[]              $args  Positional arguments.
     * @param array<string, string> $assoc Named arguments.
     */
    public function remove(array $args, array $assoc): void
    {
        [$list, $type] = $this->target($assoc);

        if (! $this->plugin->lists()->removeValue($list, $type, (string) ($args[0] ?? ''))) {
            WP_CLI::error('No matching entry was found.');
        }

        WP_CLI::success('Entry removed.');
    }

    /**
     * List entries. Values are stored hashed, so only masked labels are shown.
     *
     * ## OPTIONS
     *
     * [--list=<list>]
     * : Which list to show.
     * ---
     * default: block
     * options:
     *   - block
     *   - allow
     * ---
     *
     * [--limit=<limit>]
     * : Maximum number of entries.
     * ---
     * default: 200
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cod-risk blocklist list --format=csv
     *
     * @subcommand list
     *
     * @param string[]              $args  Positional arguments.
     * @param array<string, string> $assoc Named arguments.
     */
    public function list_(array $args, array $assoc): void
    {
        [$list] = $this->target($assoc);

        $entries = $this->plugin->lists()->entries($list, max(1, (int) ($assoc['limit'] ?? 200)));

        \WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $entries, ['id', 'type', 'label', 'note', 'created_at']);
    }

    /**
     * Validate --list and --type.
     *
     * @param array<string, string> $assoc Named arguments.
     *
     * @return array{0: string, 1: string}
     */
    private function target(array $assoc): array
    {
        $list = (string) ($assoc['list'] ?? ListStoreInterface::BLOCK);
        $type = (string) ($assoc['type'] ?? IdentifierHasher::TYPE_PHONE);

        if (! in_array($list, [ListStoreInterface::BLOCK, ListStoreInterface::ALLOW], true)) {
            WP_CLI::error('Unknown list. Use "block" or "allow".');
        }

        if (! IdentifierHasher::isValidType($type)) {
            WP_CLI::error('Unknown type. Use phone, email, ip or device.');
        }

        return [$list, $type];
    }
}
