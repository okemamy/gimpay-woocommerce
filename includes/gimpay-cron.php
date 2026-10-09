<?php
/**
 * Réconciliation planifiée (WP-Cron).
 *
 * Si le client ferme la page après avoir payé mais avant la confirmation, la
 * commande reste non payée. Toutes les 15 minutes, on interroge le Data
 * Service pour les commandes GIM Pay non payées des dernières 24 h.
 *
 * WP-Cron ne s'exécute qu'au chargement d'une page : le délai n'est pas garanti,
 * la tâche est donc conçue pour tolérer les retards.
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
 * Planifie la tâche si elle ne l'est pas (activation, ou mise à jour sans réactivation).
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

		// "cancelled" : WooCommerce annule les commandes impayées après le délai de
		// réservation du stock, même si le client a payé entre-temps.
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
				continue; // La Lightbox n'a jamais été ouverte.
			}
			$message = $gateway->check_order_payment( $order, true );
			// Note uniquement en cas de succès : pas de bruit toutes les 15 minutes.
			if ( $order->is_paid() ) {
				$order->add_order_note( $message );
			}
		}
	}
);
