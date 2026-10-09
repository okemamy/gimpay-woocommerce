<?php
/**
 * Checks GimPay_Hash against the Appendix B examples of the GIM Pay guide.
 * Usage (CLI only): php tests/test-hash.php
 *
 * @package GimPay_WooCommerce
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script.

if ( PHP_SAPI !== 'cli' ) {
	exit; // Never runnable over the web.
}

define( 'ABSPATH', __DIR__ ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- stubbed WordPress constant.
require __DIR__ . '/../includes/class-gimpay-hash.php';

/**
 * Runs the test cases and returns the number of failures.
 *
 * @return int
 */
function gimpay_run_hash_tests() {
	$cases = array(
		'5.1 Lightbox request'  => array(
			'key'      => '35333335653063302D663464372D343237652D623739362D643234666661386432323065',
			'fields'   => array(
				'Amount'            => '100',
				'DateTimeLocalTrxn' => '202009171418',
				'MerchantId'        => '43233',
				'MerchantReference' => 'Txn-1234',
				'TerminalId'        => '53532091',
			),
			'expected' => 'EAD7AB68E23BFF2E5B03F4A0CD41581722FD14C349C6743CD91B577341465A61',
		),
		'5.2 Complete callback' => array(
			'key'      => '62656164643366382D626466612D343461392D383630332D346135613363376666356264',
			'fields'   => array(
				'Amount'            => '55',
				'Currency'          => '818',
				'MerchantId'        => '14554075972',
				'MerchantReference' => '1234',
				'PaidThrough'       => 'Card',
				'TerminalId'        => '93171742',
				'TxnDate'           => '20190826111304',
			),
			'expected' => 'AFE674E81B1933DAFBCB5BB4A7A78C5F644AF104CA340AEBD5379FEE86FF1EEE',
		),
		'5.3 Error callback'    => array(
			'key'      => '62656164643366382D626466612D343461392D383630332D346135613363376666356264',
			'fields'   => array(
				'Amount'            => '55',
				'DateTimeLocalTrxn' => '20190826111304',
				'ErrorMessage'      => 'CubeEX:132123',
				'MerchantId'        => '14554075972',
				'MerchantReference' => '1234',
				'TerminalId'        => '93171742',
			),
			'expected' => '92DCAEF9F9F3FB0280F29892A1E88DBBF562F34005024CCBA21DC7D83B046C8C',
		),
		'5.4 Data service'      => array(
			'key'      => '34376635346431302D353564662D346334652D623965302D656239653030306637323161',
			'fields'   => array(
				'DateTimeLocalTrxn' => '1811101423',
				'MerchantId'        => '45374',
				'TerminalId'        => '84949616',
			),
			'expected' => 'CF0B9237DCC8D31F985B6203BDBA634019717D746BAA1B8C7F198BA3DA0B6A96',
		),
	);

	$failed = 0;
	foreach ( $cases as $name => $case ) {
		// Deliberately shuffled order to test sorting.
		$fields = array_reverse( $case['fields'], true );
		$got    = GimPay_Hash::sign( $fields, $case['key'] );
		$ok     = $got === $case['expected'] && GimPay_Hash::verify( $fields, strtolower( $case['expected'] ), $case['key'] );
		printf( "%s %s\n", $ok ? 'OK  ' : 'FAIL', $name );
		if ( ! $ok ) {
			printf( "     expected %s\n     got      %s\n", $case['expected'], $got );
			++$failed;
		}
	}

	$bad_key = GimPay_Hash::sign( array( 'a' => 1 ), 'zz' );
	printf( "%s invalid key rejected\n", '' === $bad_key ? 'OK  ' : 'FAIL' );

	return $failed + ( '' === $bad_key ? 0 : 1 );
}

exit( gimpay_run_hash_tests() ? 1 : 0 );
