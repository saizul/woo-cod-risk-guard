<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\WP;

use Saizul\CodRiskGuard\Engine\RiskEngine;
use Saizul\CodRiskGuard\Engine\RuleRegistry;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\Otp\NullOtpVerifier;
use Saizul\CodRiskGuard\Otp\OtpVerifierInterface;
use Saizul\CodRiskGuard\Support\IdentifierHasher;
use Saizul\CodRiskGuard\WP\Admin\CsvExport;
use Saizul\CodRiskGuard\WP\Admin\MetaBox;
use Saizul\CodRiskGuard\WP\Admin\OrderColumn;
use Saizul\CodRiskGuard\WP\Admin\SettingsPage;
use Saizul\CodRiskGuard\WP\Checkout\CheckoutHooks;
use Saizul\CodRiskGuard\WP\Checkout\DeviceId;
use Saizul\CodRiskGuard\WP\Cli\BlocklistCommand;
use Saizul\CodRiskGuard\WP\Cli\Commands;
use Saizul\CodRiskGuard\WP\Data\EventRepository;
use Saizul\CodRiskGuard\WP\Data\HistoryRepository;
use Saizul\CodRiskGuard\WP\Data\ListRepository;
use Saizul\CodRiskGuard\WP\Rest\StatsController;

/**
 * Wires the scoring engine into WordPress and WooCommerce.
 *
 * Services are created lazily and shared for the duration of the request.
 */
final class Plugin
{
    public const VERSION       = '1.0.0';
    public const SECRET_OPTION = 'codrg_hash_secret';
    public const CAPABILITY    = 'manage_woocommerce';

    private static ?Plugin $instance = null;

    /** Absolute path of the main plugin file. */
    private string $file;

    private ?RuleRegistry $registry        = null;
    private ?Settings $settings            = null;
    private ?IdentifierHasher $hasher      = null;
    private ?HistoryRepository $history    = null;
    private ?EventRepository $events       = null;
    private ?ListRepository $lists         = null;
    private ?RiskEngine $engine            = null;
    private ?ContextFactory $contexts      = null;
    private ?OrderScorer $scorer           = null;
    private ?HistorySync $historySync      = null;
    private ?StatsService $stats           = null;
    private ?OtpVerifierInterface $otp     = null;

    private function __construct(string $file)
    {
        $this->file = $file;
    }

    /**
     * Create the singleton and attach the bootstrap hooks.
     */
    public static function boot(string $file): self
    {
        if (null === self::$instance) {
            self::$instance = new self($file);

            add_action('plugins_loaded', [self::$instance, 'init'], 20);
            add_action('init', [self::$instance, 'loadTextDomain']);
        }

        return self::$instance;
    }

    /**
     * The running plugin instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self(dirname(__DIR__) . '/woo-cod-risk-guard.php');
        }

        return self::$instance;
    }

    /**
     * Activation: create tables, the hashing secret and the maintenance schedule.
     */
    public static function activate(): void
    {
        Schema::install();
        self::instance()->hasher();
    }

    /**
     * Deactivation: remove scheduled jobs. Data is kept.
     */
    public static function deactivate(): void
    {
        HistorySync::unschedule();
    }

    /**
     * Main plugin file.
     */
    public function file(): string
    {
        return $this->file;
    }

    /**
     * Load translations.
     */
    public function loadTextDomain(): void
    {
        load_plugin_textdomain('woo-cod-risk-guard', false, dirname(plugin_basename($this->file)) . '/languages');
    }

    /**
     * Register every hook once all plugins are loaded.
     */
    public function init(): void
    {
        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'missingWooCommerceNotice']);

            return;
        }

        if (Schema::needsUpgrade()) {
            Schema::install();
        }

        (new CheckoutHooks($this))->register();
        $this->historySync()->register();
        (new StatsController($this))->register();
        (new Privacy($this))->register();

        if (is_admin()) {
            (new SettingsPage($this))->register();
            (new OrderColumn($this))->register();
            (new MetaBox($this))->register();
            (new CsvExport($this))->register();
        }

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('cod-risk', new Commands($this));
            \WP_CLI::add_command('cod-risk blocklist', new BlocklistCommand($this));
        }

        /**
         * Fires once COD Risk Guard has registered its hooks.
         *
         * @param Plugin $plugin Plugin instance (service container).
         */
        do_action('codrg_loaded', $this);
    }

    /**
     * Admin notice shown when WooCommerce is not active.
     */
    public function missingWooCommerceNotice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('COD Risk Guard needs WooCommerce to be installed and active.', 'woo-cod-risk-guard');
        echo '</p></div>';
    }

    /**
     * Registered rules (bundled rules plus rules added by other plugins).
     */
    public function registry(): RuleRegistry
    {
        if (null === $this->registry) {
            $this->registry = RuleRegistry::withDefaults();

            /**
             * Lets other plugins register, replace or remove scoring rules.
             *
             * @param RuleRegistry $registry Rule registry.
             */
            do_action('codrg_register_rules', $this->registry);
        }

        return $this->registry;
    }

    /**
     * Settings repository.
     */
    public function settings(): Settings
    {
        if (null === $this->settings) {
            $this->settings = new Settings($this->registry());
        }

        return $this->settings;
    }

    /**
     * Keyed hasher for phone numbers, emails, IP addresses and device ids.
     *
     * The secret is generated once per site and stored in a non-autoloaded
     * option, so hashes cannot be compared between sites.
     */
    public function hasher(): IdentifierHasher
    {
        if (null === $this->hasher) {
            $secret = (string) get_option(self::SECRET_OPTION, '');

            if ('' === $secret) {
                $secret = wp_generate_password(64, true, true);
                add_option(self::SECRET_OPTION, $secret, '', false);
            }

            /**
             * Filters the secret used to hash customer identifiers.
             *
             * Changing the secret invalidates all stored history and list entries.
             *
             * @param string $secret Hashing secret.
             */
            $secret = (string) apply_filters('codrg_hash_secret', $secret);

            $this->hasher = new IdentifierHasher('' !== $secret ? $secret : wp_salt('auth'));
        }

        return $this->hasher;
    }

    /**
     * Customer history repository.
     */
    public function history(): HistoryRepository
    {
        if (null === $this->history) {
            $this->history = new HistoryRepository();
        }

        return $this->history;
    }

    /**
     * Order event repository.
     */
    public function events(): EventRepository
    {
        if (null === $this->events) {
            $this->events = new EventRepository();
        }

        return $this->events;
    }

    /**
     * Blocklist / allowlist repository.
     */
    public function lists(): ListRepository
    {
        if (null === $this->lists) {
            $this->lists = new ListRepository($this->hasher());
        }

        return $this->lists;
    }

    /**
     * Scoring engine configured from the settings.
     */
    public function engine(): RiskEngine
    {
        if (null === $this->engine) {
            $signals = new Signals(
                $this->hasher(),
                $this->history(),
                $this->events(),
                $this->lists(),
                wp_timezone()
            );

            $this->engine = new RiskEngine($this->registry(), $this->settings()->engineConfig(), $signals);
        }

        return $this->engine;
    }

    /**
     * Drop services that depend on the settings (after the settings changed).
     */
    public function reset(): void
    {
        $this->settings()->flush();
        $this->engine = null;
    }

    /**
     * OTP verifier supplied by an integration, or a verifier that never verifies.
     */
    public function otpVerifier(): OtpVerifierInterface
    {
        if (null === $this->otp) {
            /**
             * Supplies the OTP verifier used to mark phone numbers as verified.
             *
             * Return an object implementing
             * Saizul\CodRiskGuard\Otp\OtpVerifierInterface. COD Risk Guard does
             * not send SMS messages itself.
             *
             * @param OtpVerifierInterface|null $verifier Verifier, null for none.
             */
            $verifier = apply_filters('codrg_otp_verifier', null);

            $this->otp = $verifier instanceof OtpVerifierInterface ? $verifier : new NullOtpVerifier();
        }

        return $this->otp;
    }

    /**
     * Builds engine contexts from orders and checkout data.
     */
    public function contexts(): ContextFactory
    {
        if (null === $this->contexts) {
            $this->contexts = new ContextFactory($this->otpVerifier(), new DeviceId());
        }

        return $this->contexts;
    }

    /**
     * Scores orders and stores the result.
     */
    public function scorer(): OrderScorer
    {
        if (null === $this->scorer) {
            $this->scorer = new OrderScorer($this);
        }

        return $this->scorer;
    }

    /**
     * Keeps the customer history in sync with order statuses.
     */
    public function historySync(): HistorySync
    {
        if (null === $this->historySync) {
            $this->historySync = new HistorySync($this);
        }

        return $this->historySync;
    }

    /**
     * Statistics over scored orders.
     */
    public function stats(): StatsService
    {
        if (null === $this->stats) {
            $this->stats = new StatsService($this);
        }

        return $this->stats;
    }
}
