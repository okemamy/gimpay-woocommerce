<?php
/**
 * Client for the GIM Pay "Data Service" (FilterTransactions), used to confirm
 * server side that a transaction was approved.
 *
 * @package GimPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class GimPay_API {

	// Technical Flows & API Manual v1.6, API 3: {{baseUrl}}/api/Vpos/FilterTransactions.
	const URL_PREPROD = 'https://omni-preprod.gimpay.org/Cube/api/Vpos/FilterTransactions';
	const URL_UAT     = 'https://omni-uat.gimpay.org/Cube/api/Vpos/FilterTransactions';
	const URL_PROD    = 'https://omni.gimpay.org/api/Vpos/FilterTransactions';

	/** @var string */
	private $merchant_id;
	/** @var string */
	private $terminal_id;
	/** @var string */
	private $secret;
	/** @var string */
	private $url;

	/**
	 * @param string $url FilterTransactions URL (URL_PREPROD, URL_UAT or URL_PROD).
	 */
	public function __construct( $merchant_id, $terminal_id, $secret, $url ) {
		$this->merchant_id = (string) $merchant_id;
		$this->terminal_id = (string) $terminal_id;
		$this->secret      = (string) $secret;
		$this->url         = (string) $url;
	}

	/**
	 * Finds the transactions matching a merchant reference.
	 *
	 * @param string $merchant_reference Reference sent to the Lightbox.
	 * @return array|WP_Error Flat list of transactions, with lowercase field names.
	 */
	public function find_by_reference( $merchant_reference ) {
		// "YYMMDDHHMMSS" format, must be unique per request and per terminal.
		$datetime = gmdate( 'ymdHis' );

		// Field names of the v1.6 manual; dateFrom and dateTo are optional since v1.5.
		$body = array(
			'merchantId'        => $this->merchant_id,
			'merchantReference' => (string) $merchant_reference,
			'terminalId'        => $this->terminal_id,
			'displayLength'     => '20',
			'displayStart'      => '0',
			'DateTimeLocalTrxn' => $datetime,
			'SecureHash'        => GimPay_Hash::data_service_request( $datetime, $this->merchant_id, $this->terminal_id, $this->secret ),
		);

		$response = wp_remote_post(
			$this->url,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'gimpay_http', sprintf( __( 'Unexpected response from the GIM Pay Data Service (HTTP %d).', 'gimpay-woocommerce' ), $code ) );
		}

		// The v1.6 manual shows camelCase names ("success", "transactions"…),
		// older responses used PascalCase: compare names case-insensitively.
		$data = self::lowercase_keys( $data );

		if ( empty( $data['success'] ) ) {
			/* translators: %s: message returned by GIM Pay. */
			return new WP_Error( 'gimpay_api', sprintf( __( 'GIM Pay Data Service: %s', 'gimpay-woocommerce' ), isset( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : __( 'failure', 'gimpay-woocommerce' ) ) );
		}

		return self::flatten_transactions( isset( $data['transactions'] ) ? $data['transactions'] : array() );
	}

	/**
	 * Lowercases array keys, recursively.
	 *
	 * @param array $data Decoded JSON.
	 * @return array
	 */
	private static function lowercase_keys( array $data ) {
		$result = array();
		foreach ( $data as $key => $value ) {
			$result[ is_string( $key ) ? strtolower( $key ) : $key ] = is_array( $value ) ? self::lowercase_keys( $value ) : $value;
		}
		return $result;
	}

	/**
	 * Transactions are grouped by date ("dateTransactions"): flatten them.
	 *
	 * @param array $groups Value of "transactions" (lowercase keys).
	 * @return array
	 */
	private static function flatten_transactions( $groups ) {
		$list = array();
		foreach ( (array) $groups as $group ) {
			if ( isset( $group['datetransactions'] ) && is_array( $group['datetransactions'] ) ) {
				foreach ( $group['datetransactions'] as $txn ) {
					$list[] = $txn;
				}
			} elseif ( is_array( $group ) && isset( $group['status'] ) ) {
				$list[] = $group;
			}
		}
		return $list;
	}

	/**
	 * Returns the first "Approved" sale matching the reference and the amount.
	 *
	 * @param array  $transactions       Result of find_by_reference().
	 * @param string $merchant_reference Expected reference.
	 * @param int    $expected_amount    Expected amount (unit sent to GIM Pay).
	 * @return array|null
	 */
	public static function find_approved( array $transactions, $merchant_reference, $expected_amount ) {
		foreach ( $transactions as $txn ) {
			$status = isset( $txn['status'] ) ? strtolower( (string) $txn['status'] ) : '';
			$ref    = isset( $txn['merchantreference'] ) ? (string) $txn['merchantreference'] : '';
			$type   = isset( $txn['transtype'] ) ? strtolower( (string) $txn['transtype'] ) : '';
			$amount = isset( $txn['amounttrxn'] ) ? $txn['amounttrxn'] : ( isset( $txn['amnt'] ) ? $txn['amnt'] : null );

			if ( 'approved' === $status && $ref === (string) $merchant_reference && 'sale' === $type
				&& null !== $amount && (int) round( (float) $amount ) === (int) $expected_amount ) {
				return $txn;
			}
		}
		return null;
	}
}
