<?php
/**
 * Plugin deletion: removes the settings, including the secret key.
 *
 * @package GimPay_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_gimpay_settings' );
wp_clear_scheduled_hook( 'gimpay_wc_reconcile' );

// Order metadata (_gimpay_*) is intentionally kept: it is part of the orders'
// accounting history and holds no personal data (technical references and
// amounts only).
