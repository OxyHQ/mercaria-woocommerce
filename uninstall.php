<?php
/**
 * Uninstall cleanup for Mercaria for WooCommerce.
 *
 * Runs only when the user deletes the plugin from WordPress. Removes all
 * options and scheduled events created by the plugin.
 *
 * @package Mercaria_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = array(
	'mercaria_wc_settings',
	'mercaria_wc_connection',
	'mercaria_wc_log',
	'mercaria_wc_push_queue',
	'mercaria_wc_inventory_queue',
	'mercaria_wc_backfill',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

$cron_hooks = array(
	'mercaria_wc_process_queue',
	'mercaria_wc_backfill_chunk',
	'mercaria_wc_reconcile',
);

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
