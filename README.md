# GIM Pay for WooCommerce

Accept **GIM-UEMOA, Visa and Mastercard** card payments in CFA francs (XOF) on WooCommerce,
through the **GIM Pay PayForm Plus Lightbox**.

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b.svg)
![WooCommerce 8.0+](https://img.shields.io/badge/WooCommerce-8.0%2B-96588a.svg)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)

## Features

- Block-based checkout and classic checkout.
- Compatible with High-Performance Order Storage (HPOS).
- Requests are signed server side (HMAC SHA-256); the secret key never reaches the browser.
- Every payment is verified: SecureHash on a fixed field list, merchant reference, amount and,
  by default, a server-to-server confirmation with the GIM Pay Data Service.
- Automatic reconciliation every 15 minutes, for customers who close the page right after paying.
- "Check GIM Pay payment" order action for manual verification.
- Credentials can be stored in `wp-config.php` instead of the database.
- Translation ready, French translation included.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.4 |
| WooCommerce | 8.0 |
| PHP | 7.4 |
| Store currency | XOF (the payment method is hidden for any other currency) |
| GIM Pay account | Merchant ID, Terminal ID and secret key from your acquiring bank |

## Installation

1. Download `gimpay-woocommerce.zip` from the [latest release](../../releases/latest).
2. In WordPress, go to **Plugins › Add New › Upload Plugin**, upload the zip and activate it.
3. Go to **WooCommerce › Settings › Payments › GIM Pay** and fill in the settings below.
4. Keep **Test mode (UAT)** checked while testing; uncheck it to go live.

## Configuration

| Setting | Where to find it | Used for |
|---|---|---|
| Merchant ID (MID) | GIM Pay gateway MID, provided with the secret key | Sent to the Lightbox, signed |
| Terminal ID (TID) | Terminal ID | Sent to the Lightbox, signed |
| Acquirer merchant ID | Bank-side merchant ID | Reference only: debug log and failed payment notes |
| Outlet | Outlet number | Reference only: debug log and failed payment notes |
| Merchant secret key | Merchant secret key (hexadecimal) | HMAC signatures; never displayed again |
| Lightbox script URL (test / live) | Pre-filled with the official GIM Pay URL | Script loaded on the payment page |
| Data Service URL (test / live) | Pre-filled with the official GIM Pay URL | Server-to-server payment confirmation |
| Amount unit | Keep `× 1` for XOF | Amount sent to GIM Pay |
| Double verification | Enabled by default | Confirms every payment with the Data Service |
| Debug log | Disabled by default | WooCommerce › Status › Logs, source `gimpay` |

The endpoint URLs are pre-filled with the official GIM Pay URLs and only need to be changed
if GIM Pay or your bank gives you different ones. The test or live URL is used depending on
**Test mode (UAT)**. Custom URLs must use HTTPS; clearing a field restores its default value.

Leaving the secret key field empty keeps the saved key.

To keep credentials out of the database, define them in `wp-config.php`. Constants take
precedence over the settings screen:

```php
define( 'GIMPAY_MERCHANT_ID', '...' );
define( 'GIMPAY_TERMINAL_ID', '...' );
define( 'GIMPAY_SECRET_KEY', '...' );
```

Deleting the plugin removes its settings, including the secret key.

## How it works

1. The customer picks GIM Pay and places the order. The order is created as *Pending payment*
   and the customer is sent to the *Pay for order* page.
2. The server signs the request (Appendix B 5.1 of the GIM Pay guide) with a unique reference
   `WC<order id>T<timestamp>` and opens the GIM Pay Lightbox.
3. When the payment completes, the browser relays the data signed by GIM Pay to
   `?wc-api=gimpay`. The server checks:
   - the `SecureHash` (Appendix B 5.2) with the secret key,
   - that the reference belongs to the order,
   - that the amount matches,
   - with *Double verification*, that the transaction is `Approved` in the Data Service
     (`FilterTransactions`).
4. If everything matches, the order is marked as paid. If anything is doubtful (amount mismatch,
   Data Service unreachable, transaction not found), the order is put *On hold* with an
   explanatory note.

If the customer closes the page before confirmation, the scheduled reconciliation or the
**Check GIM Pay payment** order action queries the Data Service and completes the order
once an approved payment is found.

## Troubleshooting

| Symptom | Meaning |
|---|---|
| `Issuer not found` (code 992) | The card network could not route the card to its issuing bank. This is not a plugin error. Typical causes: a UAT test card used in production, or the terminal is not enabled for that card scheme. Contact your acquiring bank and give them the identifiers shown in the order note. |
| Customer paid, order still pending | Wait for the reconciliation (every 15 minutes) or run **Check GIM Pay payment** from the order screen. |
| Payment method not shown | The store currency must be XOF, and MID, TID and secret key must all be set. |

Response codes are listed in Appendix C of the GIM Pay PayForm Plus integration guide,
available from your acquiring bank.

## Security

- HMAC signatures are computed server side; the secret key never leaves the server and is
  never written to the logs.
- The callback endpoint is protected by a nonce, the order key, a SecureHash over a fixed
  field list, reference and amount checks, and the Data Service confirmation.
- The admin action requires the `edit_shop_orders` capability.
- Inputs are sanitized, outputs are escaped, no direct SQL queries.

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## Hooks

| Hook | Type | Description |
|---|---|---|
| `gimpay_wc_icon` | filter | Payment method icon URL. Defaults to the GIM-UEMOA logo; return an empty string to hide it. |
| `gimpay_wc_lightbox_config` | filter | `( array $config, WC_Order $order )`: extra Lightbox options (colors, `AdditionalCustomerData`…). Signed fields cannot be changed. |
| `gimpay_wc_payment_completed` | action | `( WC_Order $order, string $reference, string $transaction_id )`: fired after a payment is validated. |

## Translations

Source strings are in English, text domain `gimpay-woocommerce`. The French translation lives in
`languages/` (`.po`, `.mo` and `.l10n.php`). Translations into other languages are welcome; see
[CONTRIBUTING.md](CONTRIBUTING.md).

## Development

```sh
php tests/test-hash.php     # checks the signatures against the guide's examples
git archive --format=zip --prefix=gimpay-woocommerce/ -o gimpay-woocommerce.zip HEAD
```

The zip leaves out tests and development files (see `.gitattributes`).

## License

[GPL-2.0-or-later](LICENSE).

This project is not affiliated with GIM-UEMOA or PaySky. GIM Pay, GIM-UEMOA and their logos
are trademarks of their respective owners.

## Credits

Made by [Okemamy](https://okemamy.com).
