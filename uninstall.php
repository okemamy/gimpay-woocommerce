<?php
/**
 * Suppression de l'extension : on efface les réglages, dont la clé secrète.
 *
 * @package GimPay_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_gimpay_settings' );
wp_clear_scheduled_hook( 'gimpay_wc_reconcile' );

// Les métadonnées de commande (_gimpay_*) sont conservées volontairement : elles
// font partie de l'historique comptable des commandes et ne contiennent aucune
// donnée personnelle (références techniques et montants uniquement).
