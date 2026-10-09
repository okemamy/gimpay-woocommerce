<?php
/**
 * GIM Pay SecureHash computation (Appendix B of the PayForm Plus integration guide).
 *
 * HMAC SHA-256 over the string "Key1=Val1&Key2=Val2..." (keys sorted in
 * ascending order), keyed with the hex-decoded "Merchant Secret Key".
 * The result is encoded as uppercase hexadecimal.
 *
 * @package GimPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class GimPay_Hash {

	/**
	 * Builds the string to sign from a key => value array.
	 *
	 * @param array $fields Fields to sign.
	 * @return string
	 */
	public static function build_message( array $fields ) {
		ksort( $fields, SORT_STRING );

		$pairs = array();
		foreach ( $fields as $name => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$pairs[] = $name . '=' . ( null === $value ? '' : (string) $value );
		}

		return implode( '&', $pairs );
	}

	/**
	 * Computes the SecureHash (uppercase hex).
	 *
	 * @param array  $fields     Fields to sign.
	 * @param string $secret_hex Merchant secret key (hexadecimal).
	 * @return string Empty string if the key is invalid.
	 */
	public static function sign( array $fields, $secret_hex ) {
		$key = self::decode_key( $secret_hex );
		if ( '' === $key ) {
			return '';
		}

		return strtoupper( hash_hmac( 'sha256', self::build_message( $fields ), $key ) );
	}

	/**
	 * Verifies a received SecureHash (case-insensitive, constant-time comparison).
	 *
	 * @param array  $fields     Signed fields (without SecureHash).
	 * @param string $received   Received hash.
	 * @param string $secret_hex Merchant secret key.
	 * @return bool
	 */
	public static function verify( array $fields, $received, $secret_hex ) {
		$expected = self::sign( $fields, $secret_hex );
		if ( '' === $expected || ! is_string( $received ) || '' === $received ) {
			return false;
		}

		return hash_equals( $expected, strtoupper( trim( $received ) ) );
	}

	/**
	 * Lightbox request hash (Appendix B 5.1).
	 */
	public static function lightbox_request( $amount, $datetime, $merchant_id, $merchant_reference, $terminal_id, $secret_hex ) {
		return self::sign(
			array(
				'Amount'            => $amount,
				'DateTimeLocalTrxn' => $datetime,
				'MerchantId'        => $merchant_id,
				'MerchantReference' => $merchant_reference,
				'TerminalId'        => $terminal_id,
			),
			$secret_hex
		);
	}

	/**
	 * Data Service / FilterTransactions request hash (Appendix B 5.4).
	 */
	public static function data_service_request( $datetime, $merchant_id, $terminal_id, $secret_hex ) {
		return self::sign(
			array(
				'DateTimeLocalTrxn' => $datetime,
				'MerchantId'        => $merchant_id,
				'TerminalId'        => $terminal_id,
			),
			$secret_hex
		);
	}

	/**
	 * Decodes the hexadecimal key. Returns '' if invalid.
	 *
	 * @param string $secret_hex Key.
	 * @return string
	 */
	private static function decode_key( $secret_hex ) {
		$secret_hex = preg_replace( '/\s+/', '', (string) $secret_hex );
		if ( '' === $secret_hex || strlen( $secret_hex ) % 2 !== 0 || ! ctype_xdigit( $secret_hex ) ) {
			return '';
		}

		return (string) hex2bin( $secret_hex );
	}
}
