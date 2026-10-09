# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- English documentation and code comments; added license, contributing and security guides.

## [1.2.2] - 2026-10-09

### Added

- Acquirer merchant ID and Outlet settings, shown in the debug log and in failed payment
  order notes to help the bank support team.
- Configurable Lightbox script URL and Data Service URL (empty uses the default test/live
  URL, HTTPS only).

## [1.2.1] - 2026-10-09

### Added

- GIM-UEMOA logo next to the payment method, in the classic and block-based checkout.
- Author credit.

## [1.2.0] - 2026-10-09

### Added

- Automatic payment reconciliation (WP-Cron), scheduled on activation and cleared on
  deactivation and uninstall.
- Suggested privacy policy text.
- `License URI` and `Update URI` plugin headers.

## [1.1.0]

### Added

- French translation and internationalization.
- Extensibility hooks: `gimpay_wc_icon`, `gimpay_wc_lightbox_config`,
  `gimpay_wc_payment_completed`.

### Security

- Nonce on the payment callback endpoint, capability checks on admin actions.

### Changed

- WordPress-Extra coding standards and consistent prefixes.

## [1.0.0]

- First release.

[Unreleased]: https://github.com/okemamy/gimpay-woocommerce/compare/v1.2.2...HEAD
[1.2.2]: https://github.com/okemamy/gimpay-woocommerce/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/okemamy/gimpay-woocommerce/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/okemamy/gimpay-woocommerce/releases/tag/v1.2.0
