# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `shared_device` rule ("Device used with several phones", weight 25): flags
  a device that placed orders with several different phone numbers within a
  window (defaults: 2 other phones in 30 days). Checking the IP address as
  well is available as an option and is off by default, because mobile
  carriers share one IP between many customers.
- `LinkedIdentifierProviderInterface` with `countDistinctLinked()`,
  implemented by the database event repository and by
  `InMemoryVelocityProvider` (which also gains `recordOrder()`). Custom
  velocity providers that do not implement it keep working; the new rule
  just does not fire for them.

### Changed

- Existing installations get the new rule enabled with its default weight.
  Orders with a reused device can score up to 25 points higher than before;
  disable the rule or lower its weight on the settings screen if needed.

## [1.0.0] - 2026-11-11

### Added

- Rule based scoring engine with 15 bundled rules, configurable weights,
  thresholds and per-rule options.
- Actions per risk level: allow, put on hold, require advance payment, block.
- Blocklist and allowlist for phones, emails, IP addresses and devices.
- Checkout integration for the classic checkout and the checkout block.
- Order list column, order meta box and private order note.
- Per-phone customer history updated from order status changes, with a daily
  maintenance job.
- Report tab, CSV export of risky orders and a REST endpoint for statistics.
- WP-CLI commands: `score`, `rescan`, `stats`, `blocklist add|remove|list`.
- Personal data exporter and eraser, suggested privacy policy text.
- OTP verification hook point (`OtpVerifierInterface` and the
  `codrg_otp_verifier` filter).
- HPOS compatibility declaration.
- Translation template.

[Unreleased]: https://github.com/saizul/woo-cod-risk-guard/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/saizul/woo-cod-risk-guard/releases/tag/v1.0.0
