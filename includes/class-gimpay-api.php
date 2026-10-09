<?php
/**
 * Client du "Data Service" GIM Pay (FilterTransactions), utilisé pour confirmer
 * côté serveur qu'une transaction est bien approuvée.
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
	/** @var bool */
	private $sandbox;

	public function __construct( $merchant_id, $terminal_id, $secret, $sandbox ) {
		$this->merchant_id = (string) $merchant_id;
		$this->terminal_id = (string) $terminal_id;
		$this->secret      = (string) $secret;
		$this->sandbox     = (bool) $sandbox;
	}

	/**
	 * Recherche les transactions associées à une référence marchand.
	 *
	 * @param string $merchant_reference Référence envoyée à la Lightbox.
	 * @return array|WP_Error Liste à plat des transactions.
	 */
	public function find_by_reference( $merchant_reference ) {
		// Format "YYMMDDHHMMSS", doit être unique par requête et par terminal.
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
			$this->sandbox ? self::URL_UAT : self::URL_PROD,
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
	 * Les transactions sont groupées par date ("DateTransactions") : on les met à plat.
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
	 * Retourne la première vente "Approved" correspondant à la référence et au montant.
	 *
	 * @param array  $transactions       Résultat de find_by_reference().
	 * @param string $merchant_reference Référence attendue.
	 * @param int    $expected_amount    Montant attendu (unité envoyée à GIM Pay).
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
