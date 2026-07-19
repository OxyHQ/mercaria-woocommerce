<?php
/**
 * Admin settings page (Settings -> Mercaria).
 *
 * Provides the configuration form (API base URL, store id, access token), the
 * Connect / Test / Disconnect actions, the "Sync all products now" backfill
 * trigger, and the activity log. Every state-changing action is guarded by a
 * nonce and the `manage_woocommerce` capability.
 *
 * @package Mercaria_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mercaria_WC_Settings
 */
class Mercaria_WC_Settings {

	const MENU_SLUG       = 'mercaria-woocommerce';
	const SETTINGS_GROUP  = 'mercaria_wc_settings_group';
	const CAPABILITY      = 'manage_woocommerce';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		add_action( 'admin_post_mercaria_wc_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_mercaria_wc_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_mercaria_wc_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_mercaria_wc_sync_all', array( $this, 'handle_sync_all' ) );
		add_action( 'admin_post_mercaria_wc_clear_log', array( $this, 'handle_clear_log' ) );

		add_filter( 'plugin_action_links_' . MERCARIA_WC_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add the settings page under the Settings menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Mercaria', 'mercaria-woocommerce' ),
			__( 'Mercaria', 'mercaria-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings, section and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			Mercaria_WC_Plugin::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'mercaria_wc_connection_section',
			__( 'Mercaria connection', 'mercaria-woocommerce' ),
			array( $this, 'render_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			'api_base_url',
			__( 'API base URL', 'mercaria-woocommerce' ),
			array( $this, 'render_field_api_base_url' ),
			self::MENU_SLUG,
			'mercaria_wc_connection_section'
		);

		add_settings_field(
			'store_id',
			__( 'Store id', 'mercaria-woocommerce' ),
			array( $this, 'render_field_store_id' ),
			self::MENU_SLUG,
			'mercaria_wc_connection_section'
		);

		add_settings_field(
			'access_token',
			__( 'Access token', 'mercaria-woocommerce' ),
			array( $this, 'render_field_access_token' ),
			self::MENU_SLUG,
			'mercaria_wc_connection_section'
		);
	}

	/**
	 * Sanitize the settings, preserving the stored token when left blank.
	 *
	 * @param array<string, mixed> $input Raw submitted values.
	 * @return array{api_base_url:string, store_id:string, access_token:string}
	 */
	public function sanitize_settings( $input ) {
		$existing = Mercaria_WC_Plugin::instance()->get_settings();
		$clean    = array();

		$base                  = isset( $input['api_base_url'] ) ? trim( (string) $input['api_base_url'] ) : '';
		$clean['api_base_url'] = '' === $base ? '' : esc_url_raw( $base, array( 'http', 'https' ) );

		$clean['store_id'] = isset( $input['store_id'] ) ? sanitize_text_field( $input['store_id'] ) : '';

		$token = isset( $input['access_token'] ) ? trim( (string) $input['access_token'] ) : '';
		if ( '' === $token ) {
			$clean['access_token'] = isset( $existing['access_token'] ) ? $existing['access_token'] : '';
		} else {
			$clean['access_token'] = sanitize_text_field( $token );
		}

		return $clean;
	}

	/**
	 * Section description.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>';
		echo esc_html__( 'Connect this WooCommerce store to your Mercaria marketplace store. Paste a store-scoped Mercaria access token, then click Connect.', 'mercaria-woocommerce' );
		echo '</p>';
	}

	/**
	 * Render the API base URL field.
	 *
	 * @return void
	 */
	public function render_field_api_base_url() {
		$settings = Mercaria_WC_Plugin::instance()->get_settings();
		printf(
			'<input type="url" class="regular-text code" name="%1$s[api_base_url]" value="%2$s" placeholder="https://api.mercaria.co" />',
			esc_attr( Mercaria_WC_Plugin::SETTINGS_OPTION ),
			esc_attr( $settings['api_base_url'] )
		);
		echo '<p class="description">' . esc_html__( 'The Mercaria API base URL, e.g. https://api.mercaria.co', 'mercaria-woocommerce' ) . '</p>';
	}

	/**
	 * Render the store id field.
	 *
	 * @return void
	 */
	public function render_field_store_id() {
		$settings = Mercaria_WC_Plugin::instance()->get_settings();
		printf(
			'<input type="text" class="regular-text code" name="%1$s[store_id]" value="%2$s" />',
			esc_attr( Mercaria_WC_Plugin::SETTINGS_OPTION ),
			esc_attr( $settings['store_id'] )
		);
		echo '<p class="description">' . esc_html__( 'Your Mercaria store id.', 'mercaria-woocommerce' ) . '</p>';
	}

	/**
	 * Render the access token field (masked; blank keeps the stored value).
	 *
	 * @return void
	 */
	public function render_field_access_token() {
		$settings  = Mercaria_WC_Plugin::instance()->get_settings();
		$has_token = '' !== $settings['access_token'];
		printf(
			'<input type="password" class="regular-text code" name="%1$s[access_token]" value="" autocomplete="off" placeholder="%2$s" />',
			esc_attr( Mercaria_WC_Plugin::SETTINGS_OPTION ),
			esc_attr(
				$has_token
					? __( 'A token is saved — leave blank to keep it', 'mercaria-woocommerce' )
					: __( 'Paste your store-scoped token', 'mercaria-woocommerce' )
			)
		);
		echo '<p class="description">' . esc_html__( 'A store-scoped Mercaria access token with the channels:write scope. Stored securely; never displayed after saving.', 'mercaria-woocommerce' ) . '</p>';
	}

	/**
	 * Render the full settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mercaria-woocommerce' ) );
		}

		$plugin     = Mercaria_WC_Plugin::instance();
		$connection = $plugin->get_connection();
		$connected  = $plugin->is_connected();
		$configured = null !== $plugin->get_client();
		$backfill   = get_option( Mercaria_WC_Plugin::BACKFILL_OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mercaria for WooCommerce', 'mercaria-woocommerce' ); ?></h1>

			<?php $this->render_notice(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Save settings', 'mercaria-woocommerce' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Connection status', 'mercaria-woocommerce' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'mercaria-woocommerce' ); ?></th>
						<td>
							<?php if ( $connected ) : ?>
								<span style="color:#008a20;font-weight:600">&#10003; <?php esc_html_e( 'Connected', 'mercaria-woocommerce' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;font-weight:600"><?php esc_html_e( 'Not connected', 'mercaria-woocommerce' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( ! empty( $connection['connection_id'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Connection id', 'mercaria-woocommerce' ); ?></th>
							<td><code><?php echo esc_html( $connection['connection_id'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $connection['shop_domain'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Shop domain', 'mercaria-woocommerce' ); ?></th>
							<td><code><?php echo esc_html( $connection['shop_domain'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $connection['connected_at'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Connected at', 'mercaria-woocommerce' ); ?></th>
							<td><?php echo esc_html( $this->format_time( (int) $connection['connected_at'] ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shop currency', 'mercaria-woocommerce' ); ?></th>
						<td><code><?php echo esc_html( get_woocommerce_currency() ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<p>
				<?php $this->render_action_button( 'mercaria_wc_connect', $connected ? __( 'Reconnect', 'mercaria-woocommerce' ) : __( 'Connect', 'mercaria-woocommerce' ), 'button button-primary', ! $configured ); ?>
				<?php $this->render_action_button( 'mercaria_wc_test', __( 'Test connection', 'mercaria-woocommerce' ), 'button', ! $configured ); ?>
				<?php if ( $connected ) : ?>
					<?php $this->render_action_button( 'mercaria_wc_disconnect', __( 'Disconnect', 'mercaria-woocommerce' ), 'button button-link-delete', false ); ?>
				<?php endif; ?>
			</p>

			<hr />

			<h2><?php esc_html_e( 'Sync', 'mercaria-woocommerce' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Products and stock sync automatically as you edit them. Use the button below to push your entire catalog now (a full backfill).', 'mercaria-woocommerce' ); ?>
			</p>

			<?php if ( ! empty( $backfill['running'] ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Backfill in progress…', 'mercaria-woocommerce' ); ?></strong>
					<?php
					printf(
						/* translators: 1: processed count, 2: total count. */
						esc_html__( '%1$d of ~%2$d products processed.', 'mercaria-woocommerce' ),
						isset( $backfill['processed'] ) ? (int) $backfill['processed'] : 0,
						isset( $backfill['total'] ) ? (int) $backfill['total'] : 0
					);
					?>
				</p>
			<?php elseif ( ! empty( $backfill['last_error'] ) ) : ?>
				<p style="color:#b32d2e">
					<?php
					printf(
						/* translators: %s: error message. */
						esc_html__( 'Last backfill error: %s', 'mercaria-woocommerce' ),
						esc_html( $backfill['last_error'] )
					);
					?>
				</p>
			<?php elseif ( isset( $backfill['processed'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: processed count. */
						esc_html__( 'Last backfill processed %d products.', 'mercaria-woocommerce' ),
						(int) $backfill['processed']
					);
					?>
				</p>
			<?php endif; ?>

			<p>
				<?php $this->render_action_button( 'mercaria_wc_sync_all', __( 'Sync all products now', 'mercaria-woocommerce' ), 'button button-secondary', ! $connected ); ?>
			</p>

			<hr />

			<h2><?php esc_html_e( 'Activity log', 'mercaria-woocommerce' ); ?></h2>
			<?php $this->render_log(); ?>
			<p>
				<?php $this->render_action_button( 'mercaria_wc_clear_log', __( 'Clear log', 'mercaria-woocommerce' ), 'button', false ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render an admin-post action button as a self-contained nonce'd form.
	 *
	 * @param string $action   admin-post action name (also the nonce action).
	 * @param string $label    Button label.
	 * @param string $classes  CSS classes.
	 * @param bool   $disabled Whether the button is disabled.
	 * @return void
	 */
	private function render_action_button( $action, $label, $classes, $disabled ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 0 0">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		printf(
			'<button type="submit" class="%1$s"%2$s>%3$s</button>',
			esc_attr( $classes ),
			$disabled ? ' disabled="disabled"' : '',
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * Render the activity log table.
	 *
	 * @return void
	 */
	private function render_log() {
		$entries = Mercaria_WC_Logger::entries();

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No activity yet.', 'mercaria-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'mercaria-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Level', 'mercaria-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'mercaria-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $entries, 0, 30 ) as $entry ) {
			$level = isset( $entry['level'] ) ? $entry['level'] : 'info';
			$color = 'error' === $level ? '#b32d2e' : ( 'warning' === $level ? '#b26200' : '#1d2327' );
			echo '<tr>';
			echo '<td>' . esc_html( $this->format_time( isset( $entry['time'] ) ? (int) $entry['time'] : 0 ) ) . '</td>';
			echo '<td style="color:' . esc_attr( $color ) . ';text-transform:uppercase;font-size:11px;font-weight:600">' . esc_html( $level ) . '</td>';
			echo '<td>' . esc_html( isset( $entry['message'] ) ? $entry['message'] : '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Format a Unix timestamp in the site's timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_time( $timestamp ) {
		if ( $timestamp <= 0 ) {
			return '—';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Handle the Connect action.
	 *
	 * @return void
	 */
	public function handle_connect() {
		$this->authorize( 'mercaria_wc_connect' );
		$this->connect_and_store( 'connected' );
	}

	/**
	 * Handle the Test connection action.
	 *
	 * @return void
	 */
	public function handle_test() {
		$this->authorize( 'mercaria_wc_test' );
		$this->connect_and_store( 'test_ok' );
	}

	/**
	 * Shared connect implementation used by Connect and Test.
	 *
	 * The caller is responsible for capability + nonce verification.
	 *
	 * @param string $success_code Notice code on success.
	 * @return void
	 */
	private function connect_and_store( $success_code ) {
		$client = Mercaria_WC_Plugin::instance()->get_client();
		if ( null === $client ) {
			$this->redirect( 'not_configured' );
		}

		$shop_domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$result      = $client->connect_push( (string) $shop_domain );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'connect_failed', $result->get_error_message() );
		}

		update_option(
			Mercaria_WC_Plugin::CONNECTION_OPTION,
			array(
				'connection_id' => (string) $result['connectionId'],
				'store_id'      => isset( $result['storeId'] ) ? (string) $result['storeId'] : Mercaria_WC_Plugin::instance()->get_settings()['store_id'],
				'shop_domain'   => (string) $shop_domain,
				'status'        => 'connected',
				'connected_at'  => time(),
			),
			false
		);

		Mercaria_WC_Logger::log( 'info', sprintf( 'Connected to Mercaria (connection %s).', (string) $result['connectionId'] ) );
		$this->redirect( $success_code );
	}

	/**
	 * Handle the Disconnect action.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		$this->authorize( 'mercaria_wc_disconnect' );
		delete_option( Mercaria_WC_Plugin::CONNECTION_OPTION );
		Mercaria_WC_Logger::log( 'info', 'Disconnected from Mercaria.' );
		$this->redirect( 'disconnected' );
	}

	/**
	 * Handle the "Sync all products now" action.
	 *
	 * @return void
	 */
	public function handle_sync_all() {
		$this->authorize( 'mercaria_wc_sync_all' );

		if ( ! Mercaria_WC_Plugin::instance()->is_connected() ) {
			$this->redirect( 'not_connected' );
		}

		$sync = Mercaria_WC_Plugin::instance()->sync();
		if ( $sync instanceof Mercaria_WC_Sync ) {
			$sync->start_backfill();
		}

		$this->redirect( 'sync_started' );
	}

	/**
	 * Handle the Clear log action.
	 *
	 * @return void
	 */
	public function handle_clear_log() {
		$this->authorize( 'mercaria_wc_clear_log' );
		Mercaria_WC_Logger::clear();
		$this->redirect( 'log_cleared' );
	}

	/**
	 * Verify capability and nonce for an admin-post action.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mercaria-woocommerce' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the settings page with a notice code.
	 *
	 * @param string $code    Notice code.
	 * @param string $message Optional detail message.
	 * @return void
	 */
	private function redirect( $code, $message = '' ) {
		$args = array(
			'page'            => self::MENU_SLUG,
			'mercaria_notice' => $code,
		);

		if ( '' !== $message ) {
			$args['mercaria_msg'] = rawurlencode( $message );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Render a one-shot notice from the redirect query args.
	 *
	 * @return void
	 */
	private function render_notice() {
		$code = isset( $_GET['mercaria_notice'] ) ? sanitize_key( wp_unslash( $_GET['mercaria_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect status code.
		if ( '' === $code ) {
			return;
		}

		$map = array(
			'connected'      => array( 'success', __( 'Connected to Mercaria.', 'mercaria-woocommerce' ) ),
			'test_ok'        => array( 'success', __( 'Connection test succeeded.', 'mercaria-woocommerce' ) ),
			'disconnected'   => array( 'success', __( 'Disconnected from Mercaria.', 'mercaria-woocommerce' ) ),
			'sync_started'   => array( 'success', __( 'Full catalog sync started. Progress appears above.', 'mercaria-woocommerce' ) ),
			'log_cleared'    => array( 'success', __( 'Activity log cleared.', 'mercaria-woocommerce' ) ),
			'not_configured' => array( 'error', __( 'Enter the API base URL, store id and access token first.', 'mercaria-woocommerce' ) ),
			'not_connected'  => array( 'error', __( 'Connect to Mercaria before syncing.', 'mercaria-woocommerce' ) ),
			'connect_failed' => array( 'error', __( 'Could not connect to Mercaria.', 'mercaria-woocommerce' ) ),
		);

		if ( ! isset( $map[ $code ] ) ) {
			return;
		}

		list( $type, $text ) = $map[ $code ];

		$detail = isset( $_GET['mercaria_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['mercaria_msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect status message.
		if ( '' !== $detail ) {
			$text .= ' ' . $detail;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'success' === $type ? 'success' : 'error' ),
			esc_html( $text )
		);
	}

	/**
	 * Add a "Settings" link on the plugins list row.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function plugin_action_links( $links ) {
		$url  = admin_url( 'options-general.php?page=' . self::MENU_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'mercaria-woocommerce' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}
}
