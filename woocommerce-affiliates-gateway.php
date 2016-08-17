<?php
/*
Plugin Name: WooCommerce Affiliates Gateway
Plugin URI: http://www.eggemplo.com
Description: Extends WooCommerce with an Affiliates gateway. Pay with your commissions
Version: 1.0.beta
Author: eggemplo
Author URI: http://www.eggemplo.com/
Copyright: © 2016 eggemplo.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

define( 'WOO_AFFILIATES_GATEWAY_DOMAIN', 'woo-affiliates-gateway' );

define( 'WOO_AFFILIATES_GATEWAY_ID', 'wc-affiliates-gateway' );

define( 'WOO_AFFILIATES_GATEWAY_FILE', __FILE__ );

define( 'WOO_AFFILIATES_GATEWAY_PLUGIN_URL', plugin_dir_url( WOO_AFFILIATES_GATEWAY_FILE ) );

class WoocommerceAffiliatesGateway_Plugin {


	public static function init() {

		add_action('plugins_loaded', array( __CLASS__, 'plugins_loaded' ), 0);
		
		add_action( 'init', array( __CLASS__, 'wp_init' ) );
	}
	
	public static function wp_init () {
		add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'woocommerce_gateway_description' ), 10, 2 );
		
		// Refund ?
		add_action ( 'woocommerce_order_status_refunded', array (
				'WoocommerceAffiliatesGateway',
				'woocommerce_order_status_refunded'
		) );

		// only available for affiliates
		add_filter( 'woocommerce_available_payment_gateways',  array( __CLASS__, 'woocommerce_available_payment_gateways' ) );
	}

	public static function woocommerce_gateway_description ( $description, $id ) {
		if ( $id == WOO_AFFILIATES_GATEWAY_ID ) {
			$aff_id = Affiliates_Affiliate_WordPress::get_user_affiliate_id();
			if ( $aff_id !== false ) {
				$totals = Affiliates_Shortcodes::get_total( $aff_id, null, null, AFFILIATES_REFERRAL_STATUS_ACCEPTED );
				$total = $totals[get_woocommerce_currency()];

				$description = sprintf( $description, $total);
			}
		}
		return $description;
	}

	public static function plugins_loaded() {
		if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
		/**
		 * Localisation
		 */
		load_plugin_textdomain( WOO_AFFILIATES_GATEWAY_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
	
		include_once( 'class-woocommerce-affiliates-gateway.php' );
	
		/**
		 * Add the Gateway to WooCommerce
		**/
		function woocommerce_add_affiliates_gateway($methods) {
			$methods[] = 'WoocommerceAffiliatesGateway';
			return $methods;
		}
	
		add_filter('woocommerce_payment_gateways', 'woocommerce_add_affiliates_gateway' );
	}
	
	public static function woocommerce_available_payment_gateways ($available_gateways ) {

		$aff_id = Affiliates_Affiliate_WordPress::get_user_affiliate_id();
		if ( $aff_id == false ) {
			unset( $available_gateways[WOO_AFFILIATES_GATEWAY_ID] );
		}

		return $available_gateways;
	}
}

WoocommerceAffiliatesGateway_Plugin::init();
