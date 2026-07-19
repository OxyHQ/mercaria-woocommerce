<?php
/**
 * Admin settings page (Settings -> Mercaria).
 *
 * Provides the configuration form (API base URL, connection id, Channel API Key),
 * a Test connection action, the "Sync all products now" backfill trigger, a
 * Disconnect action, and the activity log. Every state-changing action is guarded
 * by a nonce and the `manage_woocommerce` capability.
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
			'connection_id',
			__( 'Connection id', 'mercaria-woocommerce' ),
			array( $this, 'render_field_connection_id' ),
			self::MENU_SLUG,
			'mercaria_wc_connection_section'
		);

		add_settings_field(
			'channel_key',
			__( 'Channel API Key', 'mercaria-woocommerce' ),
			array( $this, 'render_field_channel_key' ),
			self::MENU_SLUG,
			'mercaria_wc_connection_section'
		);
	}

	/**
	 * Sanitize the settings, preserving the stored key when left blank.
	 *
	 * @param array<string, mixed> $input Raw submitted values.
	 * @return array{api_base_url:string, connection_id:string, channel_key:string}
	 */
	public function sanitize_settings( $input ) {
		$existing = Mercaria_WC_Plugin::instance()->get_settings();
		$clean    = array();

		$base                  = isset( $input['api_base_url'] ) ? trim( (string) $input['api_base_url'] ) : '';
		$clean['api_base_url'] = '' === $base ? '' : esc_url_raw( $base, array( 'http', 'https' ) );

		$clean['connection_id'] = isset( $input['connection_id'] ) ? sanitize_text_field( $input['connection_id'] ) : '';

		$key = isset( $input['channel_key'] ) ? trim( (string) $input['channel_key'] ) : '';
		if ( '' === $key ) {
			$clean['channel_key'] = isset( $existing['channel_key'] ) ? $existing['channel_key'] : '';
		} else {
			$clean['channel_key'] = sanitize_text_field( $key );
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
		echo esc_html__( 'Connect this WooCommerce store to your Mercaria marketplace store. In the Mercaria dashboard open your WooCommerce channel, copy its Connection id and generate a Channel API Key, then paste both below and click Test connection.', 'mercaria-woocommerce' );
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
	 * Render the connection id field.
	 *
	 * @return void
	 */
	public function render_field_connection_id() {
		$settings = Mercaria_WC_Plugin::instance()->get_settings();
		printf(
			'<input type="text" class="regular-text code" name="%1$s[connection_id]" value="%2$s" autocomplete="off" />',
			esc_attr( Mercaria_WC_Plugin::SETTINGS_OPTION ),
			esc_attr( $settings['connection_id'] )
		);
		echo '<p class="description">' . esc_html__( 'The channel connection id shown on your WooCommerce channel in the Mercaria dashboard.', 'mercaria-woocommerce' ) . '</p>';
	}

	/**
	 * Render the Channel API Key field (masked; blank keeps the stored value).
	 *
	 * @return void
	 */
	public function render_field_channel_key() {
		$settings = Mercaria_WC_Plugin::instance()->get_settings();
		$has_key  = '' !== $settings['channel_key'];
		printf(
			'<input type="password" class="regular-text code" name="%1$s[channel_key]" value="" autocomplete="off" placeholder="%2$s" />',
			esc_attr( Mercaria_WC_Plugin::SETTINGS_OPTION ),
			esc_attr(
				$has_key
					? __( 'A key is saved — leave blank to keep it', 'mercaria-woocommerce' )
					: __( 'Paste your Channel API Key (mck_…)', 'mercaria-woocommerce' )
			)
		);
		echo '<p class="description">' . esc_html__( 'A long-lived Channel API Key (mck_…) generated in the Mercaria dashboard. It does not expire; revoke it in the dashboard to cut off access. Stored securely; never displayed after saving.', 'mercaria-woocommerce' ) . '</p>';
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
		$settings   = $plugin->get_settings();
		$connection = $plugin->get_connection();
		$configured = null !== $plugin->get_client();
		$connected  = $plugin->is_connected();
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
							<?php if ( $configured ) : ?>
								<span style="color:#008a20;font-weight:600">&#10003; <?php esc_html_e( 'Configured', 'mercaria-woocommerce' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;font-weight:600"><?php esc_html_e( 'Not configured', 'mercaria-woocommerce' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( '' !== $settings['connection_id'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Connection id', 'mercaria-woocommerce' ); ?></th>
							<td><code><?php echo esc_html( $settings['connection_id'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $connection['tested_at'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last test', 'mercaria-woocommerce' ); ?></th>
							<td>
								<?php
								$ok = isset( $connection['status'] ) && 'connected' === $connection['status'];
								printf(
									'<span style="color:%1$s;font-weight:600">%2$s</span> — %3$s',
									esc_attr( $ok ? '#008a20' : '#b32d2e' ),
									esc_html( $ok ? __( 'Succeeded', 'mercaria-woocommerce' ) : __( 'Failed', 'mercaria-woocommerce' ) ),
									esc_html( $this->format_time( (int) $connection['tested_at'] ) )
								);
								?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shop currency', 'mercaria-woocommerce' ); ?></th>
						<td><code><?php echo esc_html( get_woocommerce_currency() ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<p>
				<?php $this->render_action_button( 'mercaria_wc_test', __( 'Test connection', 'mercaria-woocommerce' ), 'button button-primary', ! $configured ); ?>
				<?php if ( $configured ) : ?>
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
	 * Handle the Test connection action.
	 *
	 * @return void
	 */
	public function handle_test() {
		$this->authorize( 'mercaria_wc_test' );

		$client = Mercaria_WC_Plugin::instance()->get_client();
		if ( null === $client ) {
			$this->redirect( 'not_configured' );
		}

		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			update_option(
				Mercaria_WC_Plugin::CONNECTION_OPTION,
				array(
					'status'    => 'error',
					'tested_at' => time(),
					'message'   => $result->get_error_message(),
				),
				false
			);
			$this->redirect( 'test_failed', $result->get_error_message() );
		}

		update_option(
			Mercaria_WC_Plugin::CONNECTION_OPTION,
			array(
				'status'    => 'connected',
				'tested_at' => time(),
			),
			false
		);

		Mercaria_WC_Logger::log( 'info', 'Connection test succeeded.' );
		$this->redirect( 'test_ok' );
	}

	/**
	 * Handle the Disconnect action: clear the stored credential + connection id.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		$this->authorize( 'mercaria_wc_disconnect' );

		$plugin   = Mercaria_WC_Plugin::instance();
		$settings = $plugin->get_settings();
		$settings['connection_id'] = '';
		$settings['channel_key']   = '';
		update_option( Mercaria_WC_Plugin::SETTINGS_OPTION, $settings, false );
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
			'test_ok'        => array( 'success', __( 'Connection test succeeded.', 'mercaria-woocommerce' ) ),
			'disconnected'   => array( 'success', __( 'Disconnected from Mercaria.', 'mercaria-woocommerce' ) ),
			'sync_started'   => array( 'success', __( 'Full catalog sync started. Progress appears above.', 'mercaria-woocommerce' ) ),
			'log_cleared'    => array( 'success', __( 'Activity log cleared.', 'mercaria-woocommerce' ) ),
			'not_configured' => array( 'error', __( 'Enter the API base URL, connection id and Channel API Key first.', 'mercaria-woocommerce' ) ),
			'not_connected'  => array( 'error', __( 'Configure the connection before syncing.', 'mercaria-woocommerce' ) ),
			'test_failed'    => array( 'error', __( 'Connection test failed.', 'mercaria-woocommerce' ) ),
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
