<?php
/**
 * Plugin Name:       GIM Pay for WooCommerce
 * Description:       Card payments (GIM-UEMOA, Visa, Mastercard) through GIM Pay (PayForm Plus / Lightbox).
 * Version:           1.2.1
 * Author:            Okemamy
 * Author URI:        https://okemamy.com
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.6
 * Text Domain:       gimpay-woocommerce
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        false
 *
 * @package GimPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'GIMPAY_WC_VERSION', '1.2.1' );
define( 'GIMPAY_WC_FILE', __FILE__ );
define( 'GIMPAY_WC_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/gimpay-cron.php';

register_activation_hook( __FILE__, 'gimpay_schedule_reconciliation' );

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( GIMPAY_CRON_HOOK );
	}
);

add_action(
	'init',
	function () {
		// Nécessaire : traductions livrées dans le plugin, qui n'est pas hébergé sur WordPress.org.
		load_plugin_textdomain( 'gimpay-woocommerce', false, dirname( plugin_basename( GIMPAY_WC_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}
);

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', GIMPAY_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', GIMPAY_WC_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once __DIR__ . '/includes/class-gimpay-hash.php';
		require_once __DIR__ . '/includes/class-gimpay-api.php';
		require_once __DIR__ . '/includes/class-gimpay-gateway.php';

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'GimPay_Gateway';
				return $gateways;
			}
		);

		if ( is_admin() ) {
			require_once __DIR__ . '/includes/gimpay-admin.php';
		}
	}
);

add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once __DIR__ . '/includes/class-gimpay-blocks.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$registry->register( new GimPay_Blocks() );
			}
		);
	}
);
