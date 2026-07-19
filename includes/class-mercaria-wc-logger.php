<?php
/**
 * Lightweight persistent log for sync activity and errors.
 *
 * Stores the most recent entries in a WordPress option so the admin settings
 * page can surface what happened during background (cron) pushes.
 *
 * @package Mercaria_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mercaria_WC_Logger
 */
class Mercaria_WC_Logger {

	/**
	 * Maximum number of entries retained.
	 */
	const MAX_ENTRIES = 100;

	/**
	 * Append a log entry (newest first), capped at MAX_ENTRIES.
	 *
	 * @param string $level   One of info|warning|error.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	public static function log( $level, $message ) {
		$entries = get_option( Mercaria_WC_Plugin::LOG_OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'level'   => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'message' => is_string( $message ) ? $message : wp_json_encode( $message ),
			)
		);

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		}

		update_option( Mercaria_WC_Plugin::LOG_OPTION, $entries, false );
	}

	/**
	 * Retrieve all stored log entries (newest first).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries() {
		$entries = get_option( Mercaria_WC_Plugin::LOG_OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Delete all stored log entries.
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( Mercaria_WC_Plugin::LOG_OPTION, array(), false );
	}
}
