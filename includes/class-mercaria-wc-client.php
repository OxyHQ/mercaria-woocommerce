<?php
/**
 * HTTP client for the Mercaria channel ingestion API.
 *
 * All requests are authenticated with a long-lived, store-scoped Channel API Key
 * (`Authorization: Bearer mck_...`) minted in the Mercaria dashboard. Unlike an
 * Oxy access token the key never expires, so the plugin keeps working without the
 * merchant re-pasting a credential. Pushes go to the token-free ingest surface
 * `POST /channels/ingest/{connectionId}/{products|inventory}`. Transient failures
 * (network errors, HTTP 429 and 5xx) are retried with exponential backoff.
 *
 * @package Mercaria_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mercaria_WC_Client
 */
class Mercaria_WC_Client {

	/**
	 * Maximum number of attempts per request (including the first).
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Mercaria API base URL, without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Channel connection id (from the Mercaria dashboard) the pushes target.
	 *
	 * @var string
	 */
	private $connection_id;

	/**
	 * Long-lived Channel API Key (`mck_...`).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $base_url      Mercaria API base URL.
	 * @param string $connection_id Channel connection id.
	 * @param string $key           Channel API Key (`mck_...`).
	 */
	public function __construct( $base_url, $connection_id, $key ) {
		$this->base_url      = untrailingslashit( $base_url );
		$this->connection_id = (string) $connection_id;
		$this->key           = (string) $key;
	}

	/**
	 * Verify the credentials by making a harmless, side-effect-free ingest.
	 *
	 * Posts a single inventory item whose external id maps to no listing, so the
	 * API returns `skipped` (no catalog change) with HTTP 200. A 401 means the key
	 * is wrong/revoked; a 403/404/400 means the connection id doesn't belong to
	 * the key's store or isn't a push-in channel.
	 *
	 * @return true|WP_Error True on success, WP_Error otherwise.
	 */
	public function test_connection() {
		if ( '' === $this->connection_id ) {
			return new WP_Error( 'mercaria_no_connection', __( 'Enter the connection id first.', 'mercaria-woocommerce' ) );
		}

		$result = $this->request(
			'POST',
			$this->ingest_path( 'inventory' ),
			array(
				'items' => array(
					array(
						'externalId' => '__mercaria_connection_test__',
						'available'  => 0,
					),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Push a batch of products to Mercaria.
	 *
	 * POST /channels/ingest/{connectionId}/products
	 *
	 * @param array<int, array<string, mixed>> $products Batch of IngestProduct arrays.
	 * @return array<string, mixed>|WP_Error Decoded response ({ results }) or error.
	 */
	public function ingest_products( $products ) {
		if ( '' === $this->connection_id ) {
			return new WP_Error( 'mercaria_no_connection', __( 'Not connected to Mercaria yet.', 'mercaria-woocommerce' ) );
		}

		return $this->request( 'POST', $this->ingest_path( 'products' ), array( 'products' => array_values( $products ) ) );
	}

	/**
	 * Push a batch of inventory levels to Mercaria.
	 *
	 * POST /channels/ingest/{connectionId}/inventory
	 *
	 * @param array<int, array<string, mixed>> $items Batch of inventory items.
	 * @return array<string, mixed>|WP_Error Decoded response or error.
	 */
	public function ingest_inventory( $items ) {
		if ( '' === $this->connection_id ) {
			return new WP_Error( 'mercaria_no_connection', __( 'Not connected to Mercaria yet.', 'mercaria-woocommerce' ) );
		}

		return $this->request( 'POST', $this->ingest_path( 'inventory' ), array( 'items' => array_values( $items ) ) );
	}

	/**
	 * Build a token-free ingest path for the configured connection.
	 *
	 * @param string $resource `products` or `inventory`.
	 * @return string
	 */
	private function ingest_path( $resource ) {
		return sprintf(
			'/channels/ingest/%s/%s',
			rawurlencode( $this->connection_id ),
			$resource
		);
	}

	/**
	 * Perform an HTTP request with retry/backoff and JSON handling.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   API path (leading slash).
	 * @param array<string, mixed>|null $body   Request body (JSON encoded) or null.
	 * @return array<string, mixed>|WP_Error Decoded body on 2xx, WP_Error otherwise.
	 */
	private function request( $method, $path, $body = null ) {
		$url = $this->base_url . '/' . ltrim( $path, '/' );

		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'mercaria_bad_url', __( 'The Mercaria API base URL is invalid.', 'mercaria-woocommerce' ) );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'Mercaria-WooCommerce/' . MERCARIA_WC_VERSION,
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$attempt = 0;
		$last    = null;

		while ( $attempt < self::MAX_ATTEMPTS ) {
			$attempt++;
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last      = $response;
				$retryable = true;
			} else {
				$status = (int) wp_remote_retrieve_response_code( $response );

				if ( $status >= 200 && $status < 300 ) {
					$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
					return is_array( $decoded ) ? $decoded : array();
				}

				$retryable = ( 429 === $status || $status >= 500 );
				$last      = new WP_Error(
					'mercaria_http_' . $status,
					sprintf(
						/* translators: 1: HTTP status code, 2: error message from the API. */
						__( 'Mercaria API returned HTTP %1$d: %2$s', 'mercaria-woocommerce' ),
						$status,
						$this->error_body( $response )
					)
				);
			}

			if ( ! $retryable || $attempt >= self::MAX_ATTEMPTS ) {
				break;
			}

			// Exponential backoff: 250ms, then 500ms.
			usleep( (int) ( 250000 * pow( 2, $attempt - 1 ) ) );
		}

		$message = $last instanceof WP_Error ? $last->get_error_message() : __( 'Unknown Mercaria API error.', 'mercaria-woocommerce' );
		Mercaria_WC_Logger::log( 'error', $message );

		return $last instanceof WP_Error ? $last : new WP_Error( 'mercaria_unknown', $message );
	}

	/**
	 * Extract a human-readable error message from a non-2xx response body.
	 *
	 * @param array<string, mixed> $response wp_remote_* response.
	 * @return string
	 */
	private function error_body( $response ) {
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			return $decoded['message'];
		}

		if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
			return is_string( $decoded['error'] ) ? $decoded['error'] : wp_json_encode( $decoded['error'] );
		}

		return wp_strip_all_tags( (string) $body );
	}
}
