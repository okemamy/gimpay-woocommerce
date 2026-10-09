<?php
/**
 * WooCommerce block-based checkout integration.
 *
 * @package GimPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class GimPay_Blocks extends AbstractPaymentMethodType {

	protected $name = 'gimpay';

	/** @var GimPay_Gateway|null */
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_gimpay_settings', array() );
		$gateways       = WC()->payment_gateways()->payment_gateways();
		$this->gateway  = isset( $gateways['gimpay'] ) ? $gateways['gimpay'] : null;
	}

	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'gimpay-blocks',
			GIMPAY_WC_URL . 'assets/js/gimpay-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			GIMPAY_WC_VERSION,
			true
		);
		return array( 'gimpay-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'icon'        => $this->gateway ? $this->gateway->icon : '',
			'supports'    => $this->gateway ? array_values( array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) ) : array( 'products' ),
		);
	}
}
