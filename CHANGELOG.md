# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.4.0] - 2026-11-20

### Added

- Webhook (Notification URL) at `?wc-api=gimpay_webhook`, as described in the GIM Pay
  Technical Flows & API Manual v1.6 and specified by GIM-UEMOA: JSON `POST`, SecureHash
  computed on MerchantId, TerminalId and DateTimeLocalTrxn with the Merchant Shared Key,
  answer HTTP 200 `{"Success": true}`. Approved purchases are checked against the order
  reference and amount, and confirmed with FilterTransactions when *Double verification* is
  enabled. Failed payments add an order note. The URL is shown on the settings screen.

### Changed

- FilterTransactions follows the v1.6 manual: `{{baseUrl}}/api/Vpos/FilterTransactions`
  URLs and camelCase request fields. Response field names are read case-insensitively. The
  former v1.1 default URLs saved in the settings are replaced automatically.
- The Lightbox and Data Service URLs are now drop-down lists of the official GIM Pay URLs
  (Lightbox: UAT, Production; FilterTransactions: Pre-production, UAT, Production) instead of
  free text fields. A saved URL that is not in the list is replaced by the default one.

## [1.3.0] - 2026-10-09

### Changed

- Endpoint URLs are now pre-filled with the official GIM Pay URLs and remain editable, with
  separate test and live fields for the Lightbox script and the Data Service. Clearing a
  field restores its default value.
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

[Unreleased]: https://github.com/okemamy/gimpay-woocommerce/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/okemamy/gimpay-woocommerce/compare/v1.2.2...v1.3.0
[1.2.2]: https://github.com/okemamy/gimpay-woocommerce/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/okemamy/gimpay-woocommerce/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/okemamy/gimpay-woocommerce/releases/tag/v1.2.0
