<?php
/**
 * Plugin Name:       W System E-Invoice for WooCommerce
 * Plugin URI:        https://wsystem.dev/woocommerce-xrechnung
 * Description:       Creates XRechnung 3.0 and EN 16931 e-invoices (UBL) for WooCommerce orders, validates them against the official rule sets, attaches them to customer emails and can send them over Peppol.
 * Version:           1.0.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            W System
 * Author URI:        https://wsystem.dev
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       w-system-e-invoice
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package WSEI
 */

defined( 'ABSPATH' ) || exit;

define( 'WSEI_VERSION', '1.0.1' );
define( 'WSEI_FILE', __FILE__ );
define( 'WSEI_DIR', plugin_dir_path( __FILE__ ) );

require_once WSEI_DIR . 'includes/class-wsei-api.php';
require_once WSEI_DIR . 'includes/class-wsei-mapper.php';
require_once WSEI_DIR . 'includes/class-wsei-settings.php';
require_once WSEI_DIR . 'includes/class-wsei-invoices.php';
require_once WSEI_DIR . 'includes/class-wsei-admin.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		WSEI_Settings::init();
		WSEI_Invoices::init();
		WSEI_Admin::init();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=wsei' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'w-system-e-invoice' ) . '</a>' );
		return $links;
	}
);
