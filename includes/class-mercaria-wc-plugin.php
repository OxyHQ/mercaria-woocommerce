<?php
/**
 * Main plugin controller. Wires services together and exposes configuration
 * helpers (settings, connection state, API client) to the rest of the plugin.
 *
 * @package Mercaria_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mercaria_WC_Plugin
 */
final class Mercaria_WC_Plugin {

	const SETTINGS_OPTION         = 'mercaria_wc_settings';
	const CONNECTION_OPTION       = 'mercaria_wc_connection';
	const LOG_OPTION              = 'mercaria_wc_log';
	const QUEUE_OPTION            = 'mercaria_wc_push_queue';
	const INVENTORY_QUEUE_OPTION  = 'mercaria_wc_inventory_queue';
	const BACKFILL_OPTION         = 'mercaria_wc_backfill';

	const CRON_PROCESS_QUEUE  = 'mercaria_wc_process_queue';
	const CRON_BACKFILL_CHUNK = 'mercaria_wc_backfill_chunk';
	const CRON_RECONCILE      = 'mercaria_wc_reconcile';

	/**
	 * Singleton instance.
	 *
	 * @var Mercaria_WC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Sync engine.
	 *
	 * @var Mercaria_WC_Sync|null
	 */
	private $sync = null;

	/**
	 * Admin settings controller.
	 *
	 * @var Mercaria_WC_Settings|null
	 */
	private $settings = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return Mercaria_WC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Bootstrap the plugin. Requires WooCommerce.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_missing_notice' ) );
			return;
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->sync = new Mercaria_WC_Sync();
		$this->sync->register();

		if ( is_admin() ) {
			$this->settings = new Mercaria_WC_Settings();
			$this->settings->register();
		}

		// Self-heal the reconciliation schedule (e.g. after a WP-Cron reset).
		if ( ! wp_next_scheduled( self::CRON_RECONCILE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_RECONCILE );
		}
	}

	/**
	 * Load the plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'mercaria-woocommerce', false, dirname( MERCARIA_WC_BASENAME ) . '/languages' );
	}

	/**
	 * Get the merged settings with defaults.
	 *
	 * @return array{api_base_url:string, connection_id:string, channel_key:string}
	 */
	public function get_settings() {
		return wp_parse_args(
			get_option( self::SETTINGS_OPTION, array() ),
			array(
				'api_base_url'  => '',
				'connection_id' => '',
				'channel_key'   => '',
			)
		);
	}

	/**
	 * Get the stored connection state.
	 *
	 * @return array<string, mixed>
	 */
	public function get_connection() {
		$connection = get_option( self::CONNECTION_OPTION, array() );
		return is_array( $connection ) ? $connection : array();
	}

	/**
	 * Build an API client from the current settings, or null if not configured.
	 *
	 * @return Mercaria_WC_Client|null
	 */
	public function get_client() {
		$settings = $this->get_settings();

		if ( '' === $settings['api_base_url'] || '' === $settings['connection_id'] || '' === $settings['channel_key'] ) {
			return null;
		}

		return new Mercaria_WC_Client(
			$settings['api_base_url'],
			$settings['connection_id'],
			$settings['channel_key']
		);
	}

	/**
	 * Whether the plugin is fully configured to push to Mercaria.
	 *
	 * The Channel API Key is long-lived and the connection id is supplied
	 * directly, so "connected" simply means fully configured — there is no
	 * handshake to establish. A wrong or revoked key surfaces as a logged push
	 * error and via the Test connection action, not here.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return null !== $this->get_client();
	}

	/**
	 * Access the sync engine.
	 *
	 * @return Mercaria_WC_Sync|null
	 */
	public function sync() {
		return $this->sync;
	}

	/**
	 * Admin notice shown when WooCommerce is inactive.
	 *
	 * @return void
	 */
	public function render_woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Mercaria for WooCommerce requires WooCommerce to be installed and active.', 'mercaria-woocommerce' );
		echo '</p></div>';
	}

	/**
	 * Activation hook: schedule reconciliation and seed default settings.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON_RECONCILE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_RECONCILE );
		}

		add_option(
			self::SETTINGS_OPTION,
			array(
				'api_base_url'  => 'https://api.mercaria.co',
				'connection_id' => '',
				'channel_key'   => '',
			),
			'',
			'no'
		);
	}

	/**
	 * Deactivation hook: clear all scheduled events.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_RECONCILE );
		wp_clear_scheduled_hook( self::CRON_PROCESS_QUEUE );
		wp_clear_scheduled_hook( self::CRON_BACKFILL_CHUNK );
	}
}
