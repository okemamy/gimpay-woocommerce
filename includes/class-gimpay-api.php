<?php
/**
 * Client for the GIM Pay "Data Service" (FilterTransactions), used to confirm
 * server side that a transaction was approved.
 *
 * @package GimPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class GimPay_API {

	const URL_UAT  = 'https://omni-uat.gimpay.org/Cube/PayLink.svc/api/FilterTransactions';
	const URL_PROD = 'https://omni.gimpay.org/Cube/PayLink.svc/api/FilterTransactions';

	/** @var string */
	private $merchant_id;
	/** @var string */
	private $terminal_id;
	/** @var string */
	private $secret;
	/** @var string */
	private $url;

	/**
	 * @param string $url FilterTransactions URL (URL_UAT, URL_PROD or custom).
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
	 * @return array|WP_Error Flat list of transactions.
	 */
	public function find_by_reference( $merchant_reference ) {
		// "YYMMDDHHMMSS" format, must be unique per request and per terminal.
		$datetime = gmdate( 'ymdHis' );

		$body = array(
			'MerchantReference' => (string) $merchant_reference,
			'TerminalId'        => $this->terminal_id,
			'MerchantId'        => $this->merchant_id,
			'DisplayLength'     => '20',
			'DisplayStart'      => '0',
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

		if ( empty( $data['Success'] ) ) {
			/* translators: %s: message returned by GIM Pay. */
			return new WP_Error( 'gimpay_api', sprintf( __( 'GIM Pay Data Service: %s', 'gimpay-woocommerce' ), isset( $data['Message'] ) ? sanitize_text_field( (string) $data['Message'] ) : __( 'failure', 'gimpay-woocommerce' ) ) );
		}

		return self::flatten_transactions( isset( $data['Transactions'] ) ? $data['Transactions'] : array() );
	}

	/**
	 * Transactions are grouped by date ("DateTransactions"): flatten them.
	 *
	 * @param array $groups Valeur de "Transactions".
	 * @return array
	 */
	private static function flatten_transactions( $groups ) {
		$list = array();
		foreach ( (array) $groups as $group ) {
			if ( isset( $group['DateTransactions'] ) && is_array( $group['DateTransactions'] ) ) {
				foreach ( $group['DateTransactions'] as $txn ) {
					$list[] = $txn;
				}
			} elseif ( is_array( $group ) && isset( $group['Status'] ) ) {
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
			$status = isset( $txn['Status'] ) ? strtolower( (string) $txn['Status'] ) : '';
			$ref    = isset( $txn['MerchantReference'] ) ? (string) $txn['MerchantReference'] : '';
			$type   = isset( $txn['TransType'] ) ? strtolower( (string) $txn['TransType'] ) : '';
			$amount = isset( $txn['AmountTrxn'] ) ? $txn['AmountTrxn'] : ( isset( $txn['Amnt'] ) ? $txn['Amnt'] : null );

			if ( 'approved' === $status && $ref === (string) $merchant_reference && 'sale' === $type
				&& null !== $amount && (int) round( (float) $amount ) === (int) $expected_amount ) {
				return $txn;
			}
		}
		return null;
	}
}
