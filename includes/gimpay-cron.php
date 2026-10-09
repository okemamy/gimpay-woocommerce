<?php
/**
 * Scheduled reconciliation (WP-Cron).
 *
 * If the customer closes the page after paying but before confirmation, the
 * order stays unpaid. Every 15 minutes, the Data Service is queried for
 * unpaid GIM Pay orders from the last 24 hours.
 *
 * WP-Cron only runs on page loads, so timing is not guaranteed and the task
 * is designed to tolerate delays.
 *
 * @package GimPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

const GIMPAY_CRON_HOOK  = 'gimpay_wc_reconcile';
const GIMPAY_CRON_BATCH = 20;

add_filter(
	'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 15 minutes, voulu.
	function ( $schedules ) {
		$schedules['gimpay_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (GIM Pay)', 'gimpay-woocommerce' ),
		);
		return $schedules;
	}
);

/**
 * Schedules the task if needed (on activation, or after an update without reactivation).
 */
function gimpay_schedule_reconciliation() {
	if ( ! wp_next_scheduled( GIMPAY_CRON_HOOK ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'gimpay_15min', GIMPAY_CRON_HOOK );
	}
}
add_action( 'init', 'gimpay_schedule_reconciliation' );

add_action(
	GIMPAY_CRON_HOOK,
	function () {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = isset( $gateways['gimpay'] ) ? $gateways['gimpay'] : null;
		if ( ! $gateway || 'yes' !== $gateway->enabled || ! $gateway->is_configured() ) {
			return;
		}

		// "cancelled": WooCommerce cancels unpaid orders once the hold stock
		// duration expires, even if the customer paid in the meantime.
		$orders = wc_get_orders(
			array(
				'payment_method' => 'gimpay',
				'status'         => array( 'pending', 'on-hold', 'failed', 'cancelled' ),
				'date_created'   => '>' . ( time() - DAY_IN_SECONDS ),
				'limit'          => GIMPAY_CRON_BATCH,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $orders as $order ) {
			if ( ! $order->get_meta( GimPay_Gateway::META_REFERENCES ) ) {
				continue; // The Lightbox was never opened.
			}
			$message = $gateway->check_order_payment( $order, true );
			// Add a note on success only, to avoid noise every 15 minutes.
			if ( $order->is_paid() ) {
				$order->add_order_note( $message );
			}
		}
	}
);
