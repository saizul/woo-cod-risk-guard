# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/).

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

[1.0.0]: https://github.com/saizul/woo-cod-risk-guard/releases/tag/v1.0.0
