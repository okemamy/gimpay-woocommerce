<?php
/**
 * Calcul des SecureHash GIM Pay (Annexe B du guide d'intégration PayForm Plus).
 *
 * HMAC SHA-256 sur la chaîne "Cle1=Val1&Cle2=Val2..." (clés triées par ordre
 * croissant), avec comme clé la valeur hex-décodée de la "Merchant Secret Key".
 * Le résultat est encodé en hexadécimal majuscule.
 *
 * @package GimPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class GimPay_Hash {

	/**
	 * Construit la chaîne à signer à partir d'un tableau clé => valeur.
	 *
	 * @param array $fields Champs à signer.
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
	 * Calcule le SecureHash (hex majuscule).
	 *
	 * @param array  $fields     Champs à signer.
	 * @param string $secret_hex Clé secrète marchand (hexadécimale).
	 * @return string Chaîne vide si la clé est invalide.
	 */
	public static function sign( array $fields, $secret_hex ) {
		$key = self::decode_key( $secret_hex );
		if ( '' === $key ) {
			return '';
		}

		return strtoupper( hash_hmac( 'sha256', self::build_message( $fields ), $key ) );
	}

	/**
	 * Vérifie un SecureHash reçu (comparaison insensible à la casse, à temps constant).
	 *
	 * @param array  $fields     Champs signés (sans SecureHash).
	 * @param string $received   Hash reçu.
	 * @param string $secret_hex Clé secrète marchand.
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
	 * Hash de la requête Lightbox (Annexe B 5.1).
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
	 * Hash de la requête Data Service / FilterTransactions (Annexe B 5.4).
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
	 * Décode la clé hexadécimale. Retourne '' si invalide.
	 *
	 * @param string $secret_hex Clé.
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
