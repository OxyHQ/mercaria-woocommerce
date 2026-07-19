<?php
/**
 * HTTP client for the Mercaria channel ingestion API.
 *
 * All requests are authenticated with a store-scoped Oxy access token
 * (`Authorization: Bearer <token>`). Transient failures (network errors,
 * HTTP 429 and 5xx) are retried with exponential backoff.
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
	 * Mercaria store id.
	 *
	 * @var string
	 */
	private $store_id;

	/**
	 * Store-scoped Oxy access token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Channel connection id returned by connect-push (may be empty before connecting).
	 *
	 * @var string
	 */
	private $connection_id;

	/**
	 * Constructor.
	 *
	 * @param string $base_url      Mercaria API base URL.
	 * @param string $store_id      Mercaria store id.
	 * @param string $token         Store-scoped access token.
	 * @param string $connection_id Channel connection id (optional).
	 */
	public function __construct( $base_url, $store_id, $token, $connection_id = '' ) {
		$this->base_url      = untrailingslashit( $base_url );
		$this->store_id      = (string) $store_id;
		$this->token         = (string) $token;
		$this->connection_id = (string) $connection_id;
	}

	/**
	 * Establish (or refresh) the push connection for this shop.
	 *
	 * POST /admin/stores/{storeId}/channels/woocommerce/connect-push
	 *
	 * @param string $shop_domain The WordPress site host (e.g. shop.example.com).
	 * @return array<string, mixed>|WP_Error Decoded response ({ connectionId, storeId }) or error.
	 */
	public function connect_push( $shop_domain ) {
		$path     = sprintf( '/admin/stores/%s/channels/woocommerce/connect-push', rawurlencode( $this->store_id ) );
		$response = $this->request( 'POST', $path, array( 'shopDomain' => $shop_domain ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['connectionId'] ) ) {
			return new WP_Error(
				'mercaria_connect',
				__( 'Mercaria did not return a connection id. Check the store id and token.', 'mercaria-woocommerce' )
			);
		}

		return $response;
	}

	/**
	 * Push a batch of products to Mercaria.
	 *
	 * POST /admin/stores/{storeId}/channels/{connectionId}/ingest/products
	 *
	 * @param array<int, array<string, mixed>> $products Batch of IngestProduct arrays.
	 * @return array<string, mixed>|WP_Error Decoded response ({ results }) or error.
	 */
	public function ingest_products( $products ) {
		if ( '' === $this->connection_id ) {
			return new WP_Error( 'mercaria_no_connection', __( 'Not connected to Mercaria yet.', 'mercaria-woocommerce' ) );
		}

		$path = sprintf(
			'/admin/stores/%s/channels/%s/ingest/products',
			rawurlencode( $this->store_id ),
			rawurlencode( $this->connection_id )
		);

		return $this->request( 'POST', $path, array( 'products' => array_values( $products ) ) );
	}

	/**
	 * Push a batch of inventory levels to Mercaria.
	 *
	 * POST /admin/stores/{storeId}/channels/{connectionId}/ingest/inventory
	 *
	 * @param array<int, array<string, mixed>> $items Batch of inventory items.
	 * @return array<string, mixed>|WP_Error Decoded response or error.
	 */
	public function ingest_inventory( $items ) {
		if ( '' === $this->connection_id ) {
			return new WP_Error( 'mercaria_no_connection', __( 'Not connected to Mercaria yet.', 'mercaria-woocommerce' ) );
		}

		$path = sprintf(
			'/admin/stores/%s/channels/%s/ingest/inventory',
			rawurlencode( $this->store_id ),
			rawurlencode( $this->connection_id )
		);

		return $this->request( 'POST', $path, array( 'items' => array_values( $items ) ) );
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
				'Authorization' => 'Bearer ' . $this->token,
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

		if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
			return is_string( $decoded['error'] ) ? $decoded['error'] : wp_json_encode( $decoded['error'] );
		}

		if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			return $decoded['message'];
		}

		return wp_strip_all_tags( (string) $body );
	}
}
