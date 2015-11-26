<?php
/*
Plugin Name: WooCommerce EU VAT Rates for Digital Goods Sync
Plugin URI: https://github.com/mikejolley/woocommerce-eu-vat-rates-for-digital-goods-sync
Description: Syncs 2 Tax Classes (named Digital Goods and eBooks) with https://github.com/mikejolley/EU-VAT-Rates-for-Digital-Goods/ monthly.
Version: 1.0.0
Author: Mike Jolley
Author URI: http://mikejolley.com
Requires at least: 3.8
Tested up to: 4.4
Text Domain: woocommerce-eu-vat-rates-for-digital-goods-sync
Domain Path: /languages

	Copyright: 2015 Mike Jolley
	License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_EU_VAT_Rates_For_Digital_Goods_Sync class.
 */
class WC_EU_VAT_Rates_For_Digital_Goods_Sync {

 	/**
 	 * This is the JSON file where rates are retrieved
 	 * @var string
 	 */
 	const endpoint = 'https://raw.githubusercontent.com/mikejolley/EU-VAT-Rates-for-Digital-Goods/master/rates.json';

 	private static $tax_classes = array(
		'digital-goods' => 'standard_rate',
		'ebooks'        => 'ebook_rate'
	);

 	/**
 	 * Init the plugin
 	 */
 	public static function init() {
 		register_activation_hook( basename( dirname( __FILE__ ) ) . '/' . basename( __FILE__ ), array( __CLASS__, 'activate' ), 10 );
		register_deactivation_hook( basename( dirname( __FILE__ ) ) . '/' . basename( __FILE__ ), array( __CLASS__, 'deactivate' ), 10 );
		add_action( 'plugins_loaded', array( __CLASS__, 'load_plugin_textdomain' ) );
		add_action( 'admin_init', array( __CLASS__, 'force_sync' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_sync_notice' ) );
		add_action( 'wc_eu_vat_rates_sync', array( __CLASS__, 'sync_rates' ) );
 	}

	/**
	 * Localisation
	 */
	public static function load_plugin_textdomain() {
		load_plugin_textdomain( 'woocommerce-eu-vat-rates-for-digital-goods-sync', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Force a sync on demand
	 */
	public static function force_sync() {
		if ( ! empty( $_GET['update_rates'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'update_rates' ) ) {
			self::sync_rates();
			if ( wp_get_referer() ) {
   				wp_safe_redirect( wp_get_referer() );
   			}
		}
	}

	/**
	 * Show a notice on the tax rates pages.
	 */
	public static function admin_sync_notice() {
		if ( ! empty( $_GET['tab'] ) && ! empty( $_GET['section'] ) && 'tax' === $_GET['tab'] && in_array( $_GET['section'], array_keys( self::$tax_classes ) ) ) {
			echo '<div class="success updated"><p>' . sprintf( __( 'These Rates are synced with the %sEU-VAT-Rates-for-Digital-Goods%s rates periodicaly. To force an update now,  %sclick here %s.', 'woocommerce-eu-vat-rates-for-digital-goods-sync' ), '<a href="https://github.com/mikejolley/EU-VAT-Rates-for-Digital-Goods/">', '</a>', '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'update_rates', 'true' ), 'update_rates' ) ) . '">', '</a>' ) . '</p></div>';
		}
	}

 	/**
 	 * On activation, setup the cron jobs
 	 */
 	public static function activate() {
		wp_schedule_event( time(), 'monthly', 'wc_eu_vat_rates_sync' );
		self::setup_tax_classes();
		self::sync_rates();
 	}

 	/**
 	 * On deactivation, remove the cron jobs
 	 */
 	public static function deactivate() {
 		wp_clear_scheduled_hook( 'wc_eu_vat_rates_sync' );
 	}

 	/**
 	 * Add tax classes to WooCommerce for Digital Goods and eBooks. These will be synced.
 	 */
 	private static function setup_tax_classes() {
 		$woocommerce_tax_classes = array_filter( array_map( 'trim', explode( "\n", get_option( 'woocommerce_tax_classes' ) ) ) );

 		if ( ! in_array( 'Digital Goods', $woocommerce_tax_classes ) ) {
 			$woocommerce_tax_classes[] = 'Digital Goods';
 		}
 		if ( ! in_array( 'eBooks', $woocommerce_tax_classes ) ) {
 			$woocommerce_tax_classes[] = 'eBooks';
 		}

 		update_option( 'woocommerce_tax_classes', implode( "\n", $woocommerce_tax_classes ) );
 	}

 	/**
 	 * Sync rates with the json
 	 */
 	public static function sync_rates() {
 		$rates = wp_remote_get( WC_EU_VAT_Rates_For_Digital_Goods_Sync::endpoint );
 		$rates = json_decode( $rates['body'] );
 		if ( $rates && ! empty( $rates->rates ) ) {
 			global $wpdb;

 			// Update the rates
 			foreach ( $rates->rates as $cc => $rates ) {
 				foreach ( self::$tax_classes as $tax_class => $rate_key ) {
 					if ( $wpdb->get_var( $wpdb->prepare( "SELECT tax_rate_country FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = %s AND tax_rate_class = %s;", $cc, $tax_class ) ) ) {
 						$wpdb->update(
							"{$wpdb->prefix}woocommerce_tax_rates",
							array(
								'tax_rate' => number_format( $rates->$rate_key, 4, '.', '' )
							),
							array(
								'tax_rate_country' => $cc,
								'tax_rate_class'   => $tax_class
							)
						);
 					} else {
 						$wpdb->insert(
							"{$wpdb->prefix}woocommerce_tax_rates",
							array(
								'tax_rate'          => $rates->$rate_key,
								'tax_rate_country'  => $cc,
								'tax_rate_state'    => '',
								'tax_rate'          => number_format( $rates->$rate_key, 4, '.', '' ),
								'tax_rate_name'     => __( 'VAT', 'woocommerce-eu-vat-rates-for-digital-goods-sync' ),
								'tax_rate_priority' => 1,
								'tax_rate_compound' => 0,
								'tax_rate_shipping' => 1,
								'tax_rate_order'    => 1,
								'tax_rate_class'    => $tax_class
							)
						);
 					}
 				}
 			}
 		}
 	}
}

WC_EU_VAT_Rates_For_Digital_Goods_Sync::init();
