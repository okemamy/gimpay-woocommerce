=== GIM Pay for WooCommerce ===
Contributors: gimpaywc
Tags: woocommerce, payment gateway, gim-uemoa, xof, credit card
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept GIM-UEMOA, Visa and Mastercard card payments in XOF through the GIM Pay Lightbox (PayForm Plus).

== Description ==

GIM Pay for WooCommerce adds a card payment method based on the GIM Pay PayForm Plus Lightbox.

* Works with the block-based checkout and the classic checkout.
* Compatible with WooCommerce High-Performance Order Storage (HPOS).
* Requests are signed server side (HMAC SHA-256); the merchant secret key never reaches the browser.
* Every payment is verified: SecureHash on a fixed field list, merchant reference, amount, and (by default) a server-to-server confirmation with the GIM Pay Data Service.
* Automatic reconciliation every 15 minutes for customers who close the page right after paying.
* "Check GIM Pay payment" order action for manual verification.
* Credentials can be defined in wp-config.php instead of the database.
* Translation ready, French translation included.

= Third-party service =

This plugin relies on GIM Pay, a third-party payment service operated for GIM-UEMOA with PaySky.

* The payment window script and iframe are loaded from `lightbox-uat.gimpay.org` (test) or `checkout.gimpay.org` (live) on the order payment page only.
* The order amount, a technical merchant reference, the Merchant ID and the Terminal ID are sent to GIM Pay to start a payment.
* Payment confirmations are requested from `omni-uat.gimpay.org` (test) or `omni.gimpay.org` (live).
* Card details are entered directly in the GIM Pay window and are never sent to, or stored on, the store.

Please review GIM Pay's terms and privacy policy with your acquiring bank before going live.

== Installation ==

1. Upload the `gimpay-woocommerce` folder to `/wp-content/plugins/`, or upload the zip from Plugins > Add New.
2. Activate the plugin (WooCommerce must be active).
3. Set the store currency to CFA franc (XOF).
4. Go to WooCommerce > Settings > Payments > GIM Pay and enter the Merchant ID, Terminal ID and secret key provided by your bank.
5. Keep "Test mode (UAT)" checked while testing; uncheck it to go live.

Optionally define the credentials in `wp-config.php`:

`define( 'GIMPAY_MERCHANT_ID', '...' );`
`define( 'GIMPAY_TERMINAL_ID', '...' );`
`define( 'GIMPAY_SECRET_KEY', '...' );`

== Frequently Asked Questions ==

= Which currencies are supported? =

XOF only. The payment method is hidden for any other store currency.

= The customer paid but the order is still pending =

The reconciliation task checks unpaid GIM Pay orders from the last 24 hours every 15 minutes. You can also open the order and run the "Check GIM Pay payment" action.

= Where are the logs? =

Enable "Debug log" in the settings, then go to WooCommerce > Status > Logs and pick the "gimpay" source. Disable it in production.

== Changelog ==

= 1.2.1 =
* GIM-UEMOA logo shown next to the payment method (classic and block checkout).
* Author credit: Okemamy.

= 1.2.0 =
* Automatic payment reconciliation (WP-Cron), scheduled on activation and cleared on deactivation/uninstall.
* Suggested privacy policy text.
* Plugin headers: License URI, Update URI.

= 1.1.0 =
* Coding standards (WordPress-Extra), prefixes, internationalization with French translation.
* Nonce on the payment callback endpoint, capability checks on admin actions.
* Extensibility hooks: `gimpay_wc_icon`, `gimpay_wc_lightbox_config`, `gimpay_wc_payment_completed`.

= 1.0.0 =
* First release.

== Credits ==

Made by [Okemamy](https://okemamy.com).
