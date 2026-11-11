# COD Risk Guard for WooCommerce

[![CI](https://github.com/saizul/woo-cod-risk-guard/actions/workflows/ci.yml/badge.svg)](https://github.com/saizul/woo-cod-risk-guard/actions/workflows/ci.yml)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

Scores WooCommerce cash-on-delivery (COD) orders for fake-order and return
risk, and lets the store hold, restrict or block the risky ones.

It is built for stores in Bangladesh and the rest of South Asia, where most
orders are paid on delivery and a refused parcel costs the merchant the
courier fee both ways. The scoring is rule based and explainable: every score
comes with the list of reasons that produced it.

- Requires PHP 8.1+, WordPress 6.4+, WooCommerce 8.0+
- Compatible with High-Performance Order Storage (HPOS)
- No external service, no SMS gateway, no data leaves the site

## Features

- **Risk score (0-100) and level** (low / medium / high) for every order paid
  with a monitored gateway (COD by default).
- **15 bundled rules** covering phone validity (Bangladesh mobile formats,
  Bangla digits, placeholder numbers), per-phone delivery history, order
  velocity per phone / device / IP, address and name quality, disposable
  email, quantity anomalies, night-time orders and per-district return rates.
- **Configurable action per level**: allow, put on hold, require advance
  payment (COD is hidden at checkout) or block with a message.
- **Blocklist and allowlist** for phone numbers, emails, IP addresses and
  devices.
- **Order screen integration**: a "COD risk" column in the order list, a meta
  box with the score and reasons, a private order note, "Score again" and
  "Blocklist this phone" buttons.
- **Customer history that learns**: delivered, returned and cancelled orders
  are counted per phone number as order statuses change, with a daily
  reconciliation job (Action Scheduler, falling back to WP-Cron).
- **Report tab** with failure rate per risk level and the most triggered
  rules, a **CSV export** of risky orders and a **REST endpoint** for stats.
- **WP-CLI commands** for scoring, bulk rescans, list management and stats.
- **Privacy tools**: personal data exporter and eraser, suggested privacy
  policy text, phone numbers stored only as keyed hashes.
- **Extensible**: register your own rules, plug in an OTP provider, filter
  the order data and the resulting action.
- Translation ready (`languages/woo-cod-risk-guard.pot`).

## Screenshots

Screenshots have not been added yet. Planned:

1. Settings screen with thresholds, actions and per-rule weights.
2. "COD risk" column in the order list.
3. Meta box on the order screen with the score and reasons.
4. Report tab with outcome statistics per risk level.

## Installation

1. Download or clone this repository into `wp-content/plugins/woo-cod-risk-guard`.
2. Activate **COD Risk Guard for WooCommerce** on the Plugins screen.
3. Go to **WooCommerce > COD Risk Guard** and review the thresholds, actions
   and rule weights.

Composer is only needed for development. The plugin ships its own PSR-4
autoloader and has no runtime dependencies.

## How scoring works

1. The order is turned into a plain data object (phone, address, totals, IP,
   device cookie, time).
2. Every enabled rule looks at the order and returns a **severity** between
   -1 and 1 (0 when the rule does not fire).
3. The rule's contribution is `severity x weight`. Contributions are summed,
   clamped to 0-100 and rounded. That is the score.
4. The score is mapped to a level: **medium** from 40, **high** from 70
   (both configurable).
5. A blocklist match forces the score to 100. Otherwise an allowlist match
   forces it to 0. The blocklist wins when both match.
6. The level is mapped to an action. Defaults: low = allow, medium = put on
   hold, high = require advance payment.

### Rules and default weights

The weight is the most a rule can add to the score. Every weight, threshold
and on/off switch can be changed on the settings screen.

| Rule id | What it detects | Default weight | Options (defaults) |
| --- | --- | ---: | --- |
| `blocklist` | Phone, email, IP or device is on the blocklist. Forces the score to 100. | 100 | - |
| `allowlist` | Phone or email is on the allowlist. Forces the score to 0 unless also blocklisted. | 0 | - |
| `phone_validity` | Missing phone, not a valid Bangladesh mobile number, or a placeholder such as repeated digits. | 25 | placeholder severity 0.6 |
| `phone_mismatch` | Shipping phone differs from billing phone, or phone country does not match the address country. | 8 | - |
| `phone_history` | Share of earlier orders from this phone that were returned or cancelled. A good record lowers the score. | 35 | min. finished orders 2, failure ratio 0.3, trust bonus 0.4 after 3 deliveries |
| `first_time_high_value` | No delivered order yet and the order total is high. | 15 | amount 5000 |
| `repeat_orders` | Several orders from the same phone or device in a window. | 20 | 2 earlier orders in 24 hours |
| `ip_velocity` | Many orders from the same IP address in a short window. | 20 | 3 earlier orders in 60 minutes |
| `address_quality` | Address too short, random text, placeholder, or no house / road / area details. | 20 | min. length 10 |
| `customer_name` | Name missing, too short, containing digits or random text. | 8 | - |
| `disposable_email` | Malformed email or a disposable mailbox provider. | 10 | extra domains |
| `quantity_anomaly` | Quantity of one product, or of the whole order, above the limits. | 10 | 5 per product, 10 in total |
| `night_order` | Order placed during night hours in the store time zone. | 5 | 00:00 to 05:00 |
| `district_return_rate` | Delivery district has a merchant-configured return rate above the threshold. | 15 | threshold 20%, full severity at 50% |
| `otp_verified` | An OTP integration confirmed the phone number. Subtracts its weight. | 20 (bonus) | - |

`district_return_rate` does nothing until you enter your own per-district
return rates; the plugin does not ship courier statistics.

### Actions

| Action | What happens |
| --- | --- |
| Allow | Nothing. The score is stored and shown to staff. |
| Put on hold | The order is created and set to "on hold" for manual review. |
| Require advance payment | The monitored gateways are hidden at checkout, so the customer has to pay online. A notice explains why. |
| Block | Checkout is stopped with a message. |

On the classic checkout, "require advance payment" and "block" are applied
during validation, before an order is created. With the checkout block the
check runs on the draft order through the Store API: the checkout request is
rejected with the message, and the scored draft order stays unpaid until
WooCommerce cleans it up.

## WP-CLI

```bash
# Score one order and store the result (use --dry-run to only display it).
wp cod-risk score 1234
wp cod-risk score 1234 --dry-run --format=json

# Score recent COD orders that have no score yet.
wp cod-risk rescan --days=90 --limit=2000
wp cod-risk rescan --force --dry-run

# Manage the blocklist (add --list=allow for the allowlist).
wp cod-risk blocklist add 01700000000 --note="Refused three parcels"
wp cod-risk blocklist add 192.0.2.10 --type=ip
wp cod-risk blocklist remove 01700000000
wp cod-risk blocklist list --format=csv

# Statistics.
wp cod-risk stats --days=30
wp cod-risk stats --format=json
```

Rescoring an existing order from WP-CLI or the admin button never changes the
order status.

## REST API

```
GET /wp-json/codrg/v1/stats?days=30
```

Requires a user with the `manage_woocommerce` capability. Returns aggregated
numbers only: totals per level, average score, outcome counts and failure
rate per level, most triggered rules and list sizes. No customer data.

## Hooks reference

### Actions

| Hook | Arguments | Purpose |
| --- | --- | --- |
| `codrg_register_rules` | `RuleRegistry $registry` | Register, replace or remove rules. |
| `codrg_loaded` | `Plugin $plugin` | Fires after the plugin registered its hooks. |
| `codrg_order_scored` | `WC_Order $order, RiskResult $result, string $action, OrderContext $context` | Fires after a score was stored. |
| `codrg_history_synced` | `WC_Order $order, string $current, string $previous` | Fires after an order outcome was counted in the phone history. |

### Filters

| Hook | Value | Purpose |
| --- | --- | --- |
| `codrg_settings` | `array $settings` | Change the sanitised settings at runtime. |
| `codrg_order_context` | `array $data, ?WC_Order $order` | Change the data the engine sees. `$order` is null during the pre-order check. |
| `codrg_order_action` | `string $action, RiskResult $result, ?WC_Order $order` | Override the action (`allow`, `hold`, `require_advance`, `block`). |
| `codrg_otp_verifier` | `?OtpVerifierInterface $verifier` | Provide an OTP integration. |
| `codrg_use_device_cookie` | `bool $enabled` | Turn the first-party device cookie off. |
| `codrg_device_id` | `string $deviceId` | Supply your own device identifier. |
| `codrg_hash_secret` | `string $secret` | Supply the hashing secret (for example from a constant). |
| `codrg_reconcile_days` | `int $days` | How far back the daily job re-checks order outcomes (default 3). |

### Adding a rule

```php
use Saizul\CodRiskGuard\Engine\OrderContext;
use Saizul\CodRiskGuard\Engine\RuleConfig;
use Saizul\CodRiskGuard\Engine\RuleResult;
use Saizul\CodRiskGuard\Engine\Signals;
use Saizul\CodRiskGuard\Rules\AbstractRule;

final class FreeShippingAbuseRule extends AbstractRule
{
    public function id(): string { return 'free_shipping_abuse'; }
    public function label(): string { return 'Free shipping abuse'; }
    public function description(): string { return 'Order total is just above the free shipping limit.'; }
    public function defaultWeight(): float { return 10.0; }

    public function evaluate(OrderContext $order, Signals $signals, RuleConfig $config): RuleResult
    {
        if ($order->total < 1000 || $order->total > 1020) {
            return $this->pass($config);
        }

        return $this->hit($config, 1.0, 'match', 'Order total is just above the free shipping limit.');
    }
}

add_action('codrg_register_rules', static function ($registry): void {
    $registry->register(new FreeShippingAbuseRule());
});
```

### OTP verification

No SMS provider is bundled. The plugin only defines the hook point:
implement `Saizul\CodRiskGuard\Otp\OtpVerifierInterface` and return the
instance from the `codrg_otp_verifier` filter. `isPhoneVerified()` feeds the
`otp_verified` rule, and `requestVerification()` is called when a newly
scored order gets the "put on hold" or "require advance payment" action and
the phone is not verified yet.

## Privacy

- Phone numbers, IP addresses and device ids are **never stored in plain
  text by this plugin**. They are normalised and stored as HMAC-SHA256 hashes
  keyed with a random secret generated on activation.
- The customer history table holds a phone hash and counters (orders,
  delivered, returned, cancelled) and two dates. Nothing else.
- Velocity events (hashes of phone, IP and device per order) are deleted
  after the retention period (90 days by default).
- Blocklist and allowlist entries keep a masked label such as `017*****000`
  so staff can recognise them.
- The score and reasons are stored as order meta. Reasons can contain a
  masked or partial value (for example an email domain).
- A first-party cookie (`codrg_did`, random id, one year) recognises repeat
  orders from the same browser. Disable it with the
  `codrg_use_device_cookie` filter if your consent setup requires it.
- The WordPress personal data exporter and eraser are supported, and the
  plugin suggests text for your privacy policy.

A keyed hash of a phone number is still personal data under most privacy
laws: phone numbers have little entropy, and anyone holding the secret can
test a number against the table. Hashing limits the damage of a database
leak; it does not make the data anonymous.

## Limitations

- It is a heuristic, not a fraud oracle. Expect false positives; start with
  "put on hold" rather than "block" and review the report after a few weeks.
- Phone validation targets Bangladesh mobile numbers. Other countries get a
  generic length check only.
- Customer history starts empty. Orders placed before activation are not
  counted until they are scored and their status changes; `wp cod-risk
  rescan` gives old orders a score but does not replay their outcomes.
- Outcomes are derived from WooCommerce order statuses (completed, refunded,
  cancelled, failed by default). If your courier integration uses custom
  statuses, map them on the settings screen.
- IP based rules are only as good as the IP WooCommerce sees. Behind a proxy
  or CDN, make sure the real client IP is forwarded.
- The report analyses at most the newest 5,000 scored orders in the range.
- No courier API integration and no SMS / OTP provider are included.

## Testing status

- The scoring engine in `src/` is plain PHP without WordPress dependencies
  and is covered by PHPUnit unit tests (run them with `composer test`).
- The WordPress integration layer in `includes/` (checkout hooks, admin
  screens, database tables, WP-CLI, REST, privacy tools) is linted with
  `php -l` on PHP 8.1, 8.2 and 8.3 in CI, but it is **not executed inside
  WordPress in CI**. There are no integration or end-to-end tests yet. Test
  on a staging site before using it on a live store, in particular with your
  checkout type (classic or block) and order storage (HPOS or legacy).

## Development

```bash
composer install
composer test   # PHPUnit
composer lint   # php -l on every file
```

Layout:

- `src/` scoring engine (namespace `Saizul\CodRiskGuard`)
- `includes/` WordPress / WooCommerce integration (`Saizul\CodRiskGuard\WP`)
- `tests/` PHPUnit tests
- `languages/` translation template

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Copyright (C) 2026 [Saizul Amin](https://saizul.com)
