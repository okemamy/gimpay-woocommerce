<?php
/**
 * GIM Pay WooCommerce payment gateway (PayForm Plus / Lightbox).
 *
 * Flow:
 *  1. process_payment() redirects to the "Pay for order" page (order-pay).
 *  2. That page signs the request server side and opens the GIM Pay Lightbox.
 *  3. The JS completeCallback posts the data signed by GIM Pay to
 *     ?wc-api=gimpay; the server checks the nonce, the order key, the
 *     SecureHash, the reference and the amount, then (optionally) confirms
 *     with the Data Service before completing the order.
 *
 * Available hooks:
 *  - filter gimpay_wc_icon                ( string $url )
 *  - filter gimpay_wc_lightbox_config     ( array $config, WC_Order $order )
 *  - action gimpay_wc_payment_completed   ( WC_Order $order, string $reference, string $transaction_id )
 *
 * Log messages are intentionally left untranslated.
 *
 * @package GimPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class GimPay_Gateway extends WC_Payment_Gateway {

	const LIGHTBOX_UAT  = 'https://lightbox-uat.gimpay.org/js/lightbox.js';
	const LIGHTBOX_PROD = 'https://checkout.gimpay.org/js/lightbox.js';

	const META_REFERENCES = '_gimpay_references';
	const MAX_REFERENCES  = 10;
	const MAX_ERROR_NOTES = 10;
	const NONCE_ACTION    = 'gimpay_callback_';

	/**
	 * Credentials that can be defined in wp-config.php (take precedence over the database).
	 */
	const CREDENTIAL_CONSTANTS = array(
		'merchant_id' => 'GIMPAY_MERCHANT_ID',
		'terminal_id' => 'GIMPAY_TERMINAL_ID',
		'secret_key'  => 'GIMPAY_SECRET_KEY',
	);

	/** @var bool */
	public $sandbox;
	/** @var string */
	public $merchant_id;
	/** @var string */
	public $terminal_id;
	/** @var string */
	public $secret_key;
	/** @var string */
	public $acquirer_merchant_id;
	/** @var string */
	public $outlet_id;
	/** @var string */
	public $lightbox_url;
	/** @var string */
	public $data_service_url;
	/** @var int */
	public $amount_multiplier;
	/** @var bool */
	public $api_verification;
	/** @var bool */
	public $debug;

	/** @var WC_Logger_Interface|null */
	private static $logger;

	public function __construct() {
		$this->id                 = 'gimpay';
		$this->icon               = apply_filters( 'gimpay_wc_icon', GIMPAY_WC_URL . 'assets/images/gim-uemoa.png' );
		$this->has_fields         = false;
		$this->method_title       = 'GIM Pay';
		$this->method_description = __( 'Card payments (GIM-UEMOA, Visa, Mastercard) through the GIM Pay Lightbox (PayForm Plus).', 'gimpay-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title             = $this->get_option( 'title' );
		$this->description       = $this->get_option( 'description' );
		$this->sandbox           = 'yes' === $this->get_option( 'sandbox', 'yes' );
		$this->merchant_id       = $this->get_credential( 'merchant_id' );
		$this->terminal_id       = $this->get_credential( 'terminal_id' );
		$this->secret_key        = $this->get_credential( 'secret_key' );
		$this->acquirer_merchant_id = trim( (string) $this->get_option( 'acquirer_merchant_id' ) );
		$this->outlet_id            = trim( (string) $this->get_option( 'outlet_id' ) );
		$this->lightbox_url         = $this->get_url_option( $this->sandbox ? 'lightbox_url_test' : 'lightbox_url_live' );
		$this->data_service_url     = $this->get_url_option( $this->sandbox ? 'data_service_url_test' : 'data_service_url_live' );
		$this->amount_multiplier = max( 1, (int) $this->get_option( 'amount_multiplier', '1' ) );
		$this->api_verification  = 'yes' === $this->get_option( 'api_verification', 'yes' );
		$this->debug             = 'yes' === $this->get_option( 'debug', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'handle_callback' ) );
	}

	/**
	 * Fixed-height logo: some themes would otherwise display it at full size.
	 */
	public function get_icon() {
		$icon = $this->icon ? '<img src="' . esc_url( $this->icon ) . '" alt="GIM-UEMOA" width="54" height="32" style="height:32px;width:auto;" />' : '';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	private function get_credential( $key ) {
		$constant = self::CREDENTIAL_CONSTANTS[ $key ];
		return trim( (string) ( defined( $constant ) ? constant( $constant ) : $this->get_option( $key ) ) );
	}

	/**
	 * Default endpoint URLs, editable in the settings.
	 */
	const DEFAULT_URLS = array(
		'lightbox_url_test'     => self::LIGHTBOX_UAT,
		'lightbox_url_live'     => self::LIGHTBOX_PROD,
		'data_service_url_test' => GimPay_API::URL_UAT,
		'data_service_url_live' => GimPay_API::URL_PROD,
	);

	/**
	 * URL from the settings, or its default if the setting is empty.
	 */
	private function get_url_option( $key ) {
		$url = trim( (string) $this->get_option( $key ) );
		return '' !== $url ? $url : self::DEFAULT_URLS[ $key ];
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'               => array(
				'title'   => __( 'Enable/Disable', 'gimpay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable GIM Pay', 'gimpay-woocommerce' ),
				'default' => 'no',
			),
			'title'                 => array(
				'title'       => __( 'Title', 'gimpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method name shown to the customer.', 'gimpay-woocommerce' ),
				'default'     => __( 'Bank card (GIM-UEMOA, Visa, Mastercard)', 'gimpay-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'           => array(
				'title'   => __( 'Description', 'gimpay-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely by bank card with GIM Pay.', 'gimpay-woocommerce' ),
			),
			'credentials'           => array(
				'title'       => __( 'Merchant credentials', 'gimpay-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Provided by your partner bank / GIM Pay integration consultant.', 'gimpay-woocommerce' ),
			),
			'sandbox'               => array(
				'title'       => __( 'Test mode (UAT)', 'gimpay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the GIM Pay test environment (lightbox-uat.gimpay.org)', 'gimpay-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'Uncheck to go live (checkout.gimpay.org).', 'gimpay-woocommerce' ),
			),
			'merchant_id'           => array(
				'title'       => __( 'Merchant ID (MID)', 'gimpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( '11 to 18 characters.', 'gimpay-woocommerce' ),
				'default'     => '',
			),
			'terminal_id'           => array(
				'title'       => __( 'Terminal ID (TID)', 'gimpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( '6 to 8 characters.', 'gimpay-woocommerce' ),
				'default'     => '',
			),
			'acquirer_merchant_id'  => array(
				'title'       => __( 'Acquirer merchant ID', 'gimpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Merchant ID on the bank (acquirer) side. For reference only: it is not sent to GIM Pay, but it is shown in the debug log and in failed payment order notes, to help the bank support team.', 'gimpay-woocommerce' ),
				'default'     => '',
			),
			'outlet_id'             => array(
				'title'       => __( 'Outlet', 'gimpay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Outlet number provided by the bank. For reference only, like the acquirer merchant ID.', 'gimpay-woocommerce' ),
				'default'     => '',
			),
			'secret_key'            => array(
				'title'       => __( 'Merchant secret key', 'gimpay-woocommerce' ),
				'type'        => 'gimpay_secret',
				'description' => __( 'Merchant Secret Key (hexadecimal). It is never displayed again: leave empty to keep the saved key.', 'gimpay-woocommerce' ),
				'default'     => '',
			),
			'endpoints'             => array(
				'title'       => __( 'GIM Pay endpoints', 'gimpay-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Pre-filled with the official GIM Pay URLs. Only change them if GIM Pay or your bank gives you different ones. Clear a field to restore its default value.', 'gimpay-woocommerce' ),
			),
			'lightbox_url_test'     => array(
				'title'   => __( 'Lightbox script URL (test)', 'gimpay-woocommerce' ),
				'type'    => 'gimpay_url',
				'default' => self::LIGHTBOX_UAT,
			),
			'lightbox_url_live'     => array(
				'title'   => __( 'Lightbox script URL (live)', 'gimpay-woocommerce' ),
				'type'    => 'gimpay_url',
				'default' => self::LIGHTBOX_PROD,
			),
			'data_service_url_test' => array(
				'title'   => __( 'Data Service URL (test)', 'gimpay-woocommerce' ),
				'type'    => 'gimpay_url',
				'default' => GimPay_API::URL_UAT,
			),
			'data_service_url_live' => array(
				'title'   => __( 'Data Service URL (live)', 'gimpay-woocommerce' ),
				'type'    => 'gimpay_url',
				'default' => GimPay_API::URL_PROD,
			),
			'advanced'              => array(
				'title' => __( 'Advanced options', 'gimpay-woocommerce' ),
				'type'  => 'title',
			),
			'amount_multiplier'     => array(
				'title'       => __( 'Amount unit', 'gimpay-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'GIM Pay expects the amount in the smallest currency unit. XOF has no minor unit: keep "× 1" unless GIM Pay tells you otherwise.', 'gimpay-woocommerce' ),
				'default'     => '1',
				'options'     => array(
					'1'   => __( '× 1 (1,000 XOF sent as 1000)', 'gimpay-woocommerce' ),
					'100' => __( '× 100 (1,000 XOF sent as 100000)', 'gimpay-woocommerce' ),
				),
			),
			'api_verification'      => array(
				'title'       => __( 'Double verification', 'gimpay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Confirm every payment with the GIM Pay Data Service (FilterTransactions)', 'gimpay-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'Strongly recommended. If confirmation fails, the order is put on hold instead of being completed. When disabled, only the signature relayed by the browser is trusted.', 'gimpay-woocommerce' ),
			),
			'debug'                 => array(
				'title'       => __( 'Debug log', 'gimpay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log exchanges', 'gimpay-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'WooCommerce > Status > Logs, source "gimpay". Disable in production.', 'gimpay-woocommerce' ),
			),
		);

		// Credential defined in wp-config.php: the field becomes a read-only notice.
		foreach ( self::CREDENTIAL_CONSTANTS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$this->form_fields[ $key ] = array(
					'title'       => $this->form_fields[ $key ]['title'],
					'type'        => 'title',
					/* translators: %s: PHP constant name. */
					'description' => sprintf( __( 'Defined in wp-config.php (constant %s).', 'gimpay-woocommerce' ), '<code>' . esc_html( $constant ) . '</code>' ),
				);
			}
		}
	}

	/**
	 * Secret key field that never outputs the saved value in the HTML.
	 */
	public function generate_gimpay_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$saved     = '' !== (string) $this->get_option( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'desc_tip'    => false,
				'description' => '',
			)
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<input class="input-text regular-input" type="password" autocomplete="new-password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="" placeholder="<?php echo esc_attr( $saved ? __( '•••••••• (key saved)', 'gimpay-woocommerce' ) : '' ); ?>" />
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by WooCommerce. ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Validates the secret key on save; empty keeps the existing key.
	 */
	public function validate_secret_key_field( $key, $value ) {
		$value = preg_replace( '/\s+/', '', (string) $value );
		if ( '' === $value ) {
			return $this->get_option( 'secret_key' );
		}
		if ( strlen( $value ) % 2 !== 0 || ! ctype_xdigit( $value ) ) {
			WC_Admin_Settings::add_error( __( 'GIM Pay: the secret key must be a hexadecimal string.', 'gimpay-woocommerce' ) );
			return $this->get_option( 'secret_key' );
		}
		return $value;
	}

	/**
	 * Endpoint URL field: a regular text field, with its default URL shown below it.
	 */
	public function generate_gimpay_url_html( $key, $data ) {
		/* translators: %s: default URL. */
		$data['description'] = sprintf( __( 'Default: %s', 'gimpay-woocommerce' ), '<code>' . esc_html( $data['default'] ) . '</code>' );
		$data['css']         = 'width: 100%; max-width: 40em;';
		return $this->generate_text_html( $key, $data );
	}

	/**
	 * Empty restores the default URL; an invalid or non-HTTPS URL keeps the previous value.
	 */
	public function validate_gimpay_url_field( $key, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return self::DEFAULT_URLS[ $key ];
		}
		if ( 'https' !== wp_parse_url( $value, PHP_URL_SCHEME ) || ! wp_http_validate_url( $value ) ) {
			/* translators: %s: field name. */
			WC_Admin_Settings::add_error( sprintf( __( 'GIM Pay: "%s" must be a valid HTTPS URL.', 'gimpay-woocommerce' ), $this->form_fields[ $key ]['title'] ) );
			return $this->get_option( $key );
		}
		return esc_url_raw( $value );
	}

	public function is_configured() {
		return '' !== $this->merchant_id && '' !== $this->terminal_id && '' !== $this->secret_key;
	}

	public function is_available() {
		if ( ! parent::is_available() || ! $this->is_configured() ) {
			return false;
		}
		return 'XOF' === get_woocommerce_currency();
	}

	public function admin_options() {
		if ( 'XOF' !== get_woocommerce_currency() ) {
			echo '<div class="notice notice-warning inline"><p>';
			/* translators: %s: current store currency code. */
			echo wp_kses_post( sprintf( __( 'GIM Pay only works with CFA francs (XOF). Current currency: %s.', 'gimpay-woocommerce' ), '<strong>' . esc_html( get_woocommerce_currency() ) . '</strong>' ) );
			echo '</p></div>';
		}
		parent::admin_options();
	}

	/**
	 * Step 1: the order is created, send the customer to the payment page.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Amount sent to GIM Pay, as an integer.
	 */
	public function get_gimpay_amount( WC_Order $order ) {
		return (string) (int) round( (float) $order->get_total() * $this->amount_multiplier );
	}

	/**
	 * Step 2: "Pay for order" page that opens the Lightbox.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}

		// New reference on every display: a retry after a decline must not be
		// rejected by GIM Pay as a duplicate.
		$reference = 'WC' . $order->get_id() . 'T' . time() . wp_rand( 10, 99 );
		$amount    = $this->get_gimpay_amount( $order );
		$datetime  = gmdate( 'YmdHi' );

		$references               = self::get_references( $order );
		$references[ $reference ] = $amount;
		$order->update_meta_data( self::META_REFERENCES, array_slice( $references, -self::MAX_REFERENCES, null, true ) );
		$order->save();

		$config = array(
			'MID'               => $this->merchant_id,
			'TID'               => $this->terminal_id,
			'AmountTrxn'        => $amount,
			'MerchantReference' => $reference,
			'TrxDateTime'       => $datetime,
			'SecureHash'        => GimPay_Hash::lightbox_request( $amount, $datetime, $this->merchant_id, $reference, $this->terminal_id, $this->secret_key ),
		);

		/**
		 * Extra Lightbox options (colors, AdditionalCustomerData…).
		 * Signed fields (MID, TID, AmountTrxn, MerchantReference, TrxDateTime,
		 * SecureHash) are restored after the filter.
		 */
		$config = array_merge( (array) apply_filters( 'gimpay_wc_lightbox_config', $config, $order ), $config );

		$this->log( 'Opening Lightbox for order #' . $order->get_id() . ': ' . wp_json_encode( array_diff_key( $config, array( 'SecureHash' => 1 ) ) ) . $this->bank_identifiers() );

		wp_enqueue_script( 'gimpay-lightbox', $this->lightbox_url, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external script versioned by GIM Pay.
		wp_enqueue_script( 'gimpay-checkout', GIMPAY_WC_URL . 'assets/js/gimpay-checkout.js', array( 'gimpay-lightbox' ), GIMPAY_WC_VERSION, true );
		wp_localize_script(
			'gimpay-checkout',
			'gimpayParams',
			array(
				'config'      => $config,
				'callbackUrl' => WC()->api_request_url( $this->id ),
				'orderId'     => $order->get_id(),
				'orderKey'    => $order->get_order_key(),
				'nonce'       => wp_create_nonce( self::NONCE_ACTION . $order->get_id() ),
				'i18n'        => array(
					'verifying' => __( 'Payment received, verifying… Please do not close this page.', 'gimpay-woocommerce' ),
					'cancelled' => __( 'Payment cancelled. You can try again whenever you like.', 'gimpay-woocommerce' ),
					'error'     => __( 'The payment failed', 'gimpay-woocommerce' ),
					'network'   => __( 'We could not confirm the payment. If your card was charged, please contact us with your order number.', 'gimpay-woocommerce' ),
					'loadError' => __( 'The GIM Pay payment module could not be loaded. Check your connection and reload the page.', 'gimpay-woocommerce' ),
				),
			)
		);

		/* translators: %s: order total. */
		$pay_label = sprintf( __( 'Pay %s', 'gimpay-woocommerce' ), wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) );

		echo '<div id="gimpay-payment" class="gimpay-payment">';
		echo '<p class="gimpay-message" role="status" aria-live="polite">' . esc_html__( 'The secure GIM Pay payment window is opening…', 'gimpay-woocommerce' ) . '</p>';
		echo '<p><button type="button" class="button alt gimpay-pay" disabled>' . esc_html( $pay_label ) . '</button> ';
		echo '<a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . esc_html__( 'Cancel order', 'gimpay-woocommerce' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Step 3: receives the Lightbox callbacks relayed by the browser.
	 *
	 * Public endpoint (guest customers included): there is no capability to
	 * check, authorization relies on the nonce + the order key.
	 */
	public function handle_callback() {
		$payload = json_decode( (string) file_get_contents( 'php://input' ), true );
		if ( ! is_array( $payload ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'gimpay-woocommerce' ) ), 400 );
		}

		$order_id = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
		$nonce    = isset( $payload['nonce'] ) ? sanitize_text_field( (string) $payload['nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'gimpay-woocommerce' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		$key   = isset( $payload['order_key'] ) ? (string) $payload['order_key'] : '';
		if ( ! $order || $order->get_payment_method() !== $this->id || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'gimpay-woocommerce' ) ), 404 );
		}

		$data  = self::sanitize_data( isset( $payload['data'] ) ? $payload['data'] : array() );
		$event = isset( $payload['event'] ) ? sanitize_key( (string) $payload['event'] ) : '';

		// Never log the cardholder name or the card number (even masked).
		$this->log( 'Callback "' . $event . '" for order #' . $order->get_id() . ': ' . wp_json_encode( array_diff_key( $data, array_flip( array( 'PayerName', 'PayerAccount', 'SecureHash' ) ) ) ) );

		if ( 'complete' === $event ) {
			$this->handle_complete( $order, $data );
		} elseif ( 'error' === $event ) {
			$this->handle_error( $order, $data );
		}

		wp_send_json_error( array( 'message' => __( 'Unknown event.', 'gimpay-woocommerce' ) ), 400 );
	}

	private function handle_complete( WC_Order $order, array $data ) {
		$redirect = $this->get_return_url( $order );

		$reference  = isset( $data['MerchantReference'] ) ? $data['MerchantReference'] : '';
		$references = self::get_references( $order );

		if ( '' === $reference || ! isset( $references[ $reference ] ) ) {
			if ( ! $order->is_paid() ) {
				$order->add_order_note( __( 'GIM Pay: callback rejected, unknown reference.', 'gimpay-woocommerce' ) );
			}
			wp_send_json_error( array( 'message' => __( 'Invalid payment reference.', 'gimpay-woocommerce' ) ), 400 );
		}

		$hash_ok         = $this->verify_complete_hash( $data );
		$expected_amount = (int) $references[ $reference ];

		if ( $order->is_paid() ) {
			// Two tabs / two paid attempts: the customer may have been charged twice.
			if ( $hash_ok && $reference !== $order->get_meta( '_gimpay_reference' ) && ! $order->get_meta( '_gimpay_duplicate_' . $reference ) ) {
				$order->update_meta_data( '_gimpay_duplicate_' . $reference, 1 );
				$order->save();
				/* translators: %s: GIM Pay merchant reference. */
				$order->add_order_note( sprintf( __( 'GIM Pay: WARNING, a second signed payment was received (ref. %s) although the order was already paid. Check and refund if needed.', 'gimpay-woocommerce' ), $reference ) );
			}
			wp_send_json_success( array( 'redirect' => $redirect ) );
		}

		if ( $hash_ok && (int) round( (float) $data['Amount'] ) !== $expected_amount ) {
			/* translators: 1: amount paid, 2: expected amount. */
			$order->update_status( 'on-hold', sprintf( __( 'GIM Pay: amount paid (%1$d) differs from the expected amount (%2$d). Check manually.', 'gimpay-woocommerce' ), (int) round( (float) $data['Amount'] ), $expected_amount ) );
			wp_send_json_success( array( 'redirect' => $redirect ) );
		}

		if ( ! $this->api_verification ) {
			if ( ! $hash_ok ) {
				/* translators: %s: GIM Pay merchant reference. */
				$order->add_order_note( sprintf( __( 'GIM Pay: callback rejected, invalid SecureHash (ref. %s).', 'gimpay-woocommerce' ), $reference ) );
				wp_send_json_error( array( 'message' => __( 'Invalid payment signature.', 'gimpay-woocommerce' ) ), 400 );
			}
			$this->complete_order( $order, $reference, ! empty( $data['SystemReference'] ) ? $data['SystemReference'] : $reference, __( 'signature verified', 'gimpay-woocommerce' ) );
			wp_send_json_success( array( 'redirect' => $redirect ) );
		}

		// The Data Service (server to server) is authoritative: it validates the
		// payment even if the callback signature format differs from the docs.
		$result = $this->get_api()->find_by_reference( $reference );
		if ( is_wp_error( $result ) ) {
			$this->log( 'Data Service error: ' . $result->get_error_message(), 'error' );
			if ( ! $hash_ok ) {
				wp_send_json_error( array( 'message' => __( 'The payment cannot be confirmed right now.', 'gimpay-woocommerce' ) ), 503 );
			}
			/* translators: 1: GIM Pay merchant reference, 2: error message. */
			$order->update_status( 'on-hold', sprintf( __( 'GIM Pay: signed payment received (ref. %1$s) but confirmation failed: %2$s. Use the "Check GIM Pay payment" order action.', 'gimpay-woocommerce' ), $reference, $result->get_error_message() ) );
			wp_send_json_success( array( 'redirect' => $redirect ) );
		}

		$txn = GimPay_API::find_approved( $result, $reference, $expected_amount );
		if ( $txn ) {
			$how = $hash_ok ? __( 'signature verified, confirmed by the Data Service', 'gimpay-woocommerce' ) : __( 'confirmed by the Data Service', 'gimpay-woocommerce' );
			$this->complete_order( $order, $reference, self::transaction_id( $txn, $reference ), $how );
			wp_send_json_success( array( 'redirect' => $redirect ) );
		}

		if ( ! $hash_ok ) {
			/* translators: %s: GIM Pay merchant reference. */
			$order->add_order_note( sprintf( __( 'GIM Pay: callback rejected, invalid signature and no approved payment (ref. %s).', 'gimpay-woocommerce' ), $reference ) );
			wp_send_json_error( array( 'message' => __( 'Invalid payment signature.', 'gimpay-woocommerce' ) ), 400 );
		}

		/* translators: %s: GIM Pay merchant reference. */
		$order->update_status( 'on-hold', sprintf( __( 'GIM Pay: signed payment received (ref. %s) but not found as approved by the Data Service. Check manually.', 'gimpay-woocommerce' ), $reference ) );
		wp_send_json_success( array( 'redirect' => $redirect ) );
	}

	private function complete_order( WC_Order $order, $reference, $transaction_id, $how ) {
		$order->update_meta_data( '_gimpay_reference', $reference );
		/* translators: 1: GIM Pay merchant reference, 2: how the payment was verified. */
		$order->add_order_note( sprintf( __( 'GIM Pay: payment approved (ref. %1$s, %2$s).', 'gimpay-woocommerce' ), $reference, $how ) );
		$order->payment_complete( $transaction_id );

		do_action( 'gimpay_wc_payment_completed', $order, $reference, $transaction_id );
	}

	private static function transaction_id( array $txn, $reference ) {
		foreach ( array( 'TransactionId', 'RRN' ) as $field ) {
			if ( ! empty( $txn[ $field ] ) ) {
				return (string) $txn[ $field ];
			}
		}
		return $reference;
	}

	/**
	 * In practice the Lightbox only returns {"error": "..."} without a signature:
	 * it is only used as an order note and never changes the order status.
	 */
	private function handle_error( WC_Order $order, array $data ) {
		$message = isset( $data['error'] ) ? $data['error'] : ( isset( $data['ErrorMessage'] ) ? $data['ErrorMessage'] : __( 'Unknown error', 'gimpay-woocommerce' ) );
		$message = mb_substr( $message, 0, 200 );

		// Capped: anyone who knows the order key can call this endpoint.
		$count = (int) $order->get_meta( '_gimpay_error_notes' );
		if ( ! $order->is_paid() && $count < self::MAX_ERROR_NOTES ) {
			$order->update_meta_data( '_gimpay_error_notes', $count + 1 );
			$order->save();
			/* translators: %s: error message returned by GIM Pay. */
			$order->add_order_note( sprintf( __( 'GIM Pay: payment attempt failed (%s).', 'gimpay-woocommerce' ), $message ) . $this->bank_identifiers() );
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * Bank-side identifiers (for support requests), e.g. " [Acquirer MID 123, Outlet 456, TID 789]".
	 */
	private function bank_identifiers() {
		$ids = array_filter(
			array(
				'Acquirer MID' => $this->acquirer_merchant_id,
				'Outlet'       => $this->outlet_id,
				'TID'          => $this->terminal_id,
			),
			'strlen'
		);
		if ( empty( $ids ) ) {
			return '';
		}
		$parts = array();
		foreach ( $ids as $label => $value ) {
			$parts[] = $label . ' ' . $value;
		}
		return ' [' . implode( ', ', $parts ) . ']';
	}

	/**
	 * References sent to GIM Pay for this order: [reference => amount].
	 */
	private static function get_references( WC_Order $order ) {
		$references = $order->get_meta( self::META_REFERENCES );
		return is_array( $references ) ? $references : array();
	}

	/**
	 * The guide (Appendix B 5.2) signs: Amount, Currency, MerchantId,
	 * MerchantReference, PaidThrough, TerminalId, TxnDate. MerchantId et
	 * TerminalId are not returned by the Lightbox: the configured values are used.
	 *
	 * The field list is FIXED: never sign "whatever fields were received",
	 * otherwise the Lightbox request signature (visible in the page) would be accepted
	 * comme preuve de paiement.
	 */
	private function verify_complete_hash( array $data ) {
		if ( empty( $data['SecureHash'] ) ) {
			return false;
		}

		$fields = array(
			'MerchantId' => $this->merchant_id,
			'TerminalId' => $this->terminal_id,
		);
		foreach ( array( 'Amount', 'Currency', 'MerchantReference', 'PaidThrough', 'TxnDate' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || '' === $data[ $field ] ) {
				return false;
			}
			$fields[ $field ] = $data[ $field ];
		}

		return GimPay_Hash::verify( $fields, $data['SecureHash'], $this->secret_key );
	}

	/**
	 * Queries the Data Service and completes the order if an approved payment
	 * is found. Called by the admin action (capability checked by the caller)
	 * and by the scheduled reconciliation (WP-Cron).
	 *
	 * @param WC_Order $order     Order.
	 * @param bool     $automatic True when called from the scheduled task.
	 * @return string Message for the order note.
	 */
	public function check_order_payment( WC_Order $order, $automatic = false ) {
		if ( $order->is_paid() ) {
			return __( 'GIM Pay: the order is already paid.', 'gimpay-woocommerce' );
		}

		$references = array_reverse( self::get_references( $order ), true );
		if ( empty( $references ) ) {
			return __( 'GIM Pay: no payment attempt recorded for this order.', 'gimpay-woocommerce' );
		}

		$api = $this->get_api();
		foreach ( $references as $reference => $amount ) {
			$result = $api->find_by_reference( $reference );
			if ( is_wp_error( $result ) ) {
				/* translators: %s: error message. */
				return sprintf( __( 'GIM Pay: verification failed: %s', 'gimpay-woocommerce' ), $result->get_error_message() );
			}
			$txn = GimPay_API::find_approved( $result, $reference, (int) $amount );
			if ( $txn ) {
				$rrn = isset( $txn['RRN'] ) ? $txn['RRN'] : '-';
				/* translators: %s: retrieval reference number (RRN). */
				$how = $automatic ? sprintf( __( 'automatic reconciliation, RRN %s', 'gimpay-woocommerce' ), $rrn ) : sprintf( __( 'manual check, RRN %s', 'gimpay-woocommerce' ), $rrn );
				$this->complete_order( $order, $reference, self::transaction_id( $txn, $reference ), $how );
				return __( 'GIM Pay: approved payment found, order completed.', 'gimpay-woocommerce' );
			}
		}

		/* translators: %s: comma-separated list of merchant references. */
		return sprintf( __( 'GIM Pay: no approved payment found for references %s.', 'gimpay-woocommerce' ), implode( ', ', array_keys( $references ) ) );
	}

	private function get_api() {
		return new GimPay_API( $this->merchant_id, $this->terminal_id, $this->secret_key, $this->data_service_url );
	}

	/**
	 * Keeps scalar values only, cast to strings.
	 */
	private static function sanitize_data( $data ) {
		$clean = array();
		foreach ( (array) $data as $name => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$name           = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $name );
				$clean[ $name ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}

	private function log( $message, $level = 'info' ) {
		if ( ! $this->debug && 'error' !== $level ) {
			return;
		}
		if ( null === self::$logger ) {
			self::$logger = wc_get_logger();
		}
		self::$logger->log( $level, $message, array( 'source' => 'gimpay' ) );
	}
}
