<?php
/**
 * Plugin Name:       Mercaria for WooCommerce
 * Plugin URI:        https://mercaria.co
 * Description:       Push your WooCommerce catalog and stock into your Mercaria marketplace store. Products, variations and inventory are synced automatically to Mercaria's channel ingestion API.
 * Version:           1.0.2
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Oxy
 * Author URI:        https://oxy.so
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mercaria-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 *
 * @package Mercaria_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'MERCARIA_WC_VERSION', '1.0.2' );
define( 'MERCARIA_WC_PLUGIN_FILE', __FILE__ );
define( 'MERCARIA_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'MERCARIA_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'MERCARIA_WC_BASENAME', plugin_basename( __FILE__ ) );
define( 'MERCARIA_WC_BATCH_SIZE', 100 );
define( 'MERCARIA_WC_MIN_WC_VERSION', '8.0' );

/**
 * PSR-ish autoloader for the plugin's classes.
 *
 * Maps `Mercaria_WC_Product_Mapper` -> `includes/class-mercaria-wc-product-mapper.php`.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Mercaria_WC_' ) ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$path = MERCARIA_WC_PATH . 'includes/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 * This plugin never touches order storage, so it is compatible.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MERCARIA_WC_PLUGIN_FILE,
				true
			);
		}
	}
);

register_activation_hook( __FILE__, array( 'Mercaria_WC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mercaria_WC_Plugin', 'deactivate' ) );

/**
 * Bootstrap the plugin once all plugins (including WooCommerce) are loaded.
 */
add_action(
	'plugins_loaded',
	static function () {
		Mercaria_WC_Plugin::instance()->init();
	}
);
