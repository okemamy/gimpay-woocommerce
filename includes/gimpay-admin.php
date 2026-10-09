<?php
/**
 * Admin hooks, loaded only when is_admin().
 * is_admin() does not imply any permission: every action checks a capability.
 *
 * @package GimPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'plugin_action_links_' . plugin_basename( GIMPAY_WC_FILE ),
	function ( $links ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=gimpay' ) ) . '">' . esc_html__( 'Settings', 'gimpay-woocommerce' ) . '</a>' );
		}
		return $links;
	}
);

/*
 * Suggested privacy policy text (Settings › Privacy).
 * No personal data exporter/eraser: the plugin stores no personal data beyond
 * the WooCommerce order, which already handles export and erasure.
 */
add_action(
	'admin_init',
	function () {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for stores accepting card payments with GIM Pay.', 'gimpay-woocommerce' ) . '</p>';
		$content .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'gimpay-woocommerce' ) . ' </strong>';
		$content .= esc_html__( 'Card payments are processed by GIM Pay (GIM-UEMOA, operated with PaySky). When you pay by card, the payment window is loaded from gimpay.org and your card details are entered directly with GIM Pay: they are never sent to or stored on our website. We send GIM Pay the order amount and a technical payment reference. GIM Pay sends us back the payment result, a masked card number and the cardholder name, which we only use to confirm the payment.', 'gimpay-woocommerce' ) . '</p>';

		wp_add_privacy_policy_content( 'GIM Pay for WooCommerce', wp_kses_post( $content ) );
	}
);

// "Check GIM Pay payment" action on the order screen.
add_filter(
	'woocommerce_order_actions',
	function ( $actions, $order = null ) {
		if ( $order instanceof WC_Order && 'gimpay' === $order->get_payment_method() && ! $order->is_paid() && current_user_can( 'edit_shop_orders' ) ) {
			$actions['gimpay_check_payment'] = __( 'Check GIM Pay payment', 'gimpay-woocommerce' );
		}
		return $actions;
	},
	10,
	2
);

// WooCommerce has already verified its order save nonce before this action.
add_action(
	'woocommerce_order_action_gimpay_check_payment',
	function ( $order ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'gimpay-woocommerce' ), 403 );
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways['gimpay'] ) ) {
			$order->add_order_note( $gateways['gimpay']->check_order_payment( $order ) );
		}
	}
);
