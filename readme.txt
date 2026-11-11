=== COD Risk Guard for WooCommerce ===
Contributors: saizul
Tags: woocommerce, cash on delivery, cod, fraud, fake orders
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scores cash-on-delivery orders for fake-order and return risk, and lets you hold, restrict or block the risky ones.

== Description ==

In Bangladesh and much of South Asia most online orders are paid on delivery. Every fake order and every refused parcel costs the store the courier fee both ways.

COD Risk Guard gives each cash-on-delivery order a risk score from 0 to 100 together with the reasons for it, and applies the action you choose for each risk level.

**What it checks**

* Phone number validity (Bangladesh mobile formats, Bangla digits, placeholder numbers)
* Delivery history of the phone number: delivered, returned and cancelled orders
* Repeated orders from the same phone, device or IP address
* Address and customer name quality
* Disposable email addresses
* Unusual quantities and night-time orders
* Your own return rate per district
* Your blocklist and allowlist

**What it can do**

* Allow the order and show the score to your staff
* Put the order on hold for review
* Require advance payment by hiding cash on delivery at checkout
* Block the order with a message

**Also included**

* "COD risk" column and meta box on the order screens
* Report with failure rate per risk level, CSV export, REST endpoint
* WP-CLI commands
* Personal data exporter and eraser
* Hooks for custom rules and for an OTP provider (no SMS provider is bundled)
* Compatible with High-Performance Order Storage (HPOS)

No external service is used and no data leaves your site.

== Installation ==

1. Upload the `woo-cod-risk-guard` folder to `/wp-content/plugins/`, or install the zip file from the Plugins screen.
2. Activate the plugin. WooCommerce 8.0 or newer must be active.
3. Go to WooCommerce > COD Risk Guard and review the thresholds, the action for each level and the rule weights.

== Frequently Asked Questions ==

= Does it send customer data anywhere? =

No. Everything is calculated and stored on your own site.

= Does it store phone numbers? =

Not in plain text. Phone numbers, IP addresses and device ids are stored as keyed hashes. WooCommerce itself still stores the phone number on the order as usual.

= Does it send OTP codes? =

No. It provides a hook so that an OTP plugin can report a phone number as verified, which lowers the score.

= Will it block real customers? =

It can. The score is a heuristic. Start with "put on hold" for medium risk, check the report after a few weeks and adjust the weights before you use "block".

= Does it work with the checkout block? =

The checkout block is supported through the Store API checkout hook. Please test on a staging site first: the WordPress integration is not covered by automated tests yet.

= Does it work outside Bangladesh? =

The rules that do not depend on the phone format work everywhere. Detailed phone validation is implemented for Bangladesh mobile numbers only.

== Screenshots ==

Screenshots have not been added yet.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
