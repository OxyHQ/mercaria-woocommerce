<?php
/**
 * Sync engine: WooCommerce hooks -> debounced queue -> batched pushes,
 * plus a chunked backfill and a WP-Cron reconciliation job.
 *
 * Changes are collected into WordPress options and flushed by a single
 * debounced cron event, so a burst of product saves results in one batched
 * push rather than one request per save.
 *
 * @package Mercaria_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mercaria_WC_Sync
 */
class Mercaria_WC_Sync {

	/**
	 * Product mapper.
	 *
	 * @var Mercaria_WC_Product_Mapper
	 */
	private $mapper;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->mapper = new Mercaria_WC_Product_Mapper();
	}

	/**
	 * Register WooCommerce and cron hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'on_variation_saved' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_changed' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_changed' ), 10, 1 );

		add_action( Mercaria_WC_Plugin::CRON_PROCESS_QUEUE, array( $this, 'process_queue' ) );
		add_action( Mercaria_WC_Plugin::CRON_BACKFILL_CHUNK, array( $this, 'run_backfill_chunk' ) );
		add_action( Mercaria_WC_Plugin::CRON_RECONCILE, array( $this, 'reconcile' ) );
	}

	/**
	 * Enqueue a product push when a product is created or updated.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function on_product_saved( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! in_array( $product->get_type(), Mercaria_WC_Product_Mapper::SUPPORTED_TYPES, true ) ) {
			return;
		}

		$this->enqueue_product( $product_id );
	}

	/**
	 * Enqueue the parent product when a variation is saved.
	 *
	 * @param int $variation_id Variation id.
	 * @return void
	 */
	public function on_variation_saved( $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof WC_Product ) {
			return;
		}

		$parent_id = $variation->get_parent_id();
		if ( $parent_id ) {
			$this->enqueue_product( $parent_id );
		}
	}

	/**
	 * Enqueue an inventory push when stock changes.
	 *
	 * @param WC_Product $product Product or variation whose stock changed.
	 * @return void
	 */
	public function on_stock_changed( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$this->enqueue_inventory( $product_id );
	}

	/**
	 * Flush the product and inventory queues in batches.
	 *
	 * @return void
	 */
	public function process_queue() {
		$client = Mercaria_WC_Plugin::instance()->get_client();
		if ( null === $client ) {
			return;
		}

		$this->flush_product_queue( $client );
		$this->flush_inventory_queue( $client );

		if ( $this->queue_has_items( Mercaria_WC_Plugin::QUEUE_OPTION ) || $this->queue_has_items( Mercaria_WC_Plugin::INVENTORY_QUEUE_OPTION ) ) {
			wp_schedule_single_event( time() + 15, Mercaria_WC_Plugin::CRON_PROCESS_QUEUE );
		}
	}

	/**
	 * Start a full backfill of every published product.
	 *
	 * @return void
	 */
	public function start_backfill() {
		$counts = wp_count_posts( 'product' );
		$total  = isset( $counts->publish ) ? (int) $counts->publish : 0;

		update_option(
			Mercaria_WC_Plugin::BACKFILL_OPTION,
			array(
				'running'    => true,
				'page'       => 1,
				'processed'  => 0,
				'total'      => $total,
				'started_at' => time(),
				'last_error' => '',
			),
			false
		);

		if ( ! wp_next_scheduled( Mercaria_WC_Plugin::CRON_BACKFILL_CHUNK ) ) {
			wp_schedule_single_event( time() + 5, Mercaria_WC_Plugin::CRON_BACKFILL_CHUNK );
		}
	}

	/**
	 * Process one page of the backfill and reschedule if more remain.
	 *
	 * @return void
	 */
	public function run_backfill_chunk() {
		$state = get_option( Mercaria_WC_Plugin::BACKFILL_OPTION, array() );
		if ( empty( $state['running'] ) ) {
			return;
		}

		$client = Mercaria_WC_Plugin::instance()->get_client();
		if ( null === $client ) {
			$state['running']    = false;
			$state['last_error'] = __( 'Not connected to Mercaria.', 'mercaria-woocommerce' );
			update_option( Mercaria_WC_Plugin::BACKFILL_OPTION, $state, false );
			return;
		}

		$page = isset( $state['page'] ) ? max( 1, (int) $state['page'] ) : 1;

		$ids = wc_get_products(
			array(
				'status'  => 'publish',
				'type'    => Mercaria_WC_Product_Mapper::SUPPORTED_TYPES,
				'limit'   => MERCARIA_WC_BATCH_SIZE,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		$products = $this->map_products( $ids );

		if ( ! empty( $products ) ) {
			$result = $client->ingest_products( $products );
			if ( is_wp_error( $result ) ) {
				$state['running']    = false;
				$state['last_error'] = $result->get_error_message();
				update_option( Mercaria_WC_Plugin::BACKFILL_OPTION, $state, false );
				Mercaria_WC_Logger::log( 'error', sprintf( 'Backfill stopped on page %d: %s', $page, $result->get_error_message() ) );
				return;
			}
		}

		$state['processed'] = ( isset( $state['processed'] ) ? (int) $state['processed'] : 0 ) + count( $ids );
		$state['page']      = $page + 1;

		if ( count( $ids ) < MERCARIA_WC_BATCH_SIZE ) {
			$state['running'] = false;
			update_option( Mercaria_WC_Plugin::BACKFILL_OPTION, $state, false );
			Mercaria_WC_Logger::log( 'info', sprintf( 'Backfill complete: %d products processed.', (int) $state['processed'] ) );
			return;
		}

		update_option( Mercaria_WC_Plugin::BACKFILL_OPTION, $state, false );
		wp_schedule_single_event( time() + 5, Mercaria_WC_Plugin::CRON_BACKFILL_CHUNK );
	}

	/**
	 * Scheduled reconciliation: re-push the full catalog (idempotent upsert).
	 *
	 * @return void
	 */
	public function reconcile() {
		if ( ! Mercaria_WC_Plugin::instance()->is_connected() ) {
			return;
		}

		$state = get_option( Mercaria_WC_Plugin::BACKFILL_OPTION, array() );
		if ( ! empty( $state['running'] ) ) {
			return;
		}

		$this->start_backfill();
	}

	/**
	 * Push up to one batch of queued products.
	 *
	 * @param Mercaria_WC_Client $client API client.
	 * @return void
	 */
	private function flush_product_queue( $client ) {
		$queue = $this->read_queue( Mercaria_WC_Plugin::QUEUE_OPTION );
		if ( empty( $queue ) ) {
			return;
		}

		$batch     = array_splice( $queue, 0, MERCARIA_WC_BATCH_SIZE );
		update_option( Mercaria_WC_Plugin::QUEUE_OPTION, array_values( $queue ), false );

		$products = $this->map_products( $batch );
		if ( empty( $products ) ) {
			return;
		}

		$result = $client->ingest_products( $products );
		if ( is_wp_error( $result ) ) {
			// The batch is dropped from the queue; the daily reconciliation
			// job re-pushes the full catalog, so nothing is permanently lost.
			Mercaria_WC_Logger::log( 'error', sprintf( 'Product push failed: %s', $result->get_error_message() ) );
			return;
		}

		Mercaria_WC_Logger::log( 'info', sprintf( 'Pushed %d product(s) to Mercaria.', count( $products ) ) );
	}

	/**
	 * Push up to one batch of queued inventory levels.
	 *
	 * @param Mercaria_WC_Client $client API client.
	 * @return void
	 */
	private function flush_inventory_queue( $client ) {
		$queue = $this->read_queue( Mercaria_WC_Plugin::INVENTORY_QUEUE_OPTION );
		if ( empty( $queue ) ) {
			return;
		}

		$batch = array_splice( $queue, 0, MERCARIA_WC_BATCH_SIZE );
		update_option( Mercaria_WC_Plugin::INVENTORY_QUEUE_OPTION, array_values( $queue ), false );

		$items = array();
		foreach ( $batch as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$items = array_merge( $items, $this->mapper->map_inventory_items( $product ) );
		}

		if ( empty( $items ) ) {
			return;
		}

		$result = $client->ingest_inventory( $items );
		if ( is_wp_error( $result ) ) {
			Mercaria_WC_Logger::log( 'error', sprintf( 'Inventory push failed: %s', $result->get_error_message() ) );
			return;
		}

		Mercaria_WC_Logger::log( 'info', sprintf( 'Pushed %d inventory level(s) to Mercaria.', count( $items ) ) );
	}

	/**
	 * Map a list of product ids to IngestProduct arrays, skipping unmappable ones.
	 *
	 * @param array<int, int> $ids Product ids.
	 * @return array<int, array<string, mixed>>
	 */
	private function map_products( $ids ) {
		$products = array();

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$mapped = $this->mapper->map( $product );
			if ( null !== $mapped ) {
				$products[] = $mapped;
			}
		}

		return $products;
	}

	/**
	 * Add a product id to the product push queue (deduplicated) and debounce.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	private function enqueue_product( $product_id ) {
		$this->enqueue( Mercaria_WC_Plugin::QUEUE_OPTION, $product_id );
	}

	/**
	 * Add a product id to the inventory push queue (deduplicated) and debounce.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	private function enqueue_inventory( $product_id ) {
		$this->enqueue( Mercaria_WC_Plugin::INVENTORY_QUEUE_OPTION, $product_id );
	}

	/**
	 * Shared enqueue implementation.
	 *
	 * @param string $option_key Option storing the queue.
	 * @param int    $product_id Product id.
	 * @return void
	 */
	private function enqueue( $option_key, $product_id ) {
		if ( ! Mercaria_WC_Plugin::instance()->is_connected() ) {
			return;
		}

		$product_id = (int) $product_id;
		$queue      = $this->read_queue( $option_key );

		if ( ! in_array( $product_id, $queue, true ) ) {
			$queue[] = $product_id;
			update_option( $option_key, $queue, false );
		}

		if ( ! wp_next_scheduled( Mercaria_WC_Plugin::CRON_PROCESS_QUEUE ) ) {
			wp_schedule_single_event( time() + 10, Mercaria_WC_Plugin::CRON_PROCESS_QUEUE );
		}
	}

	/**
	 * Read a queue option as an array of ints.
	 *
	 * @param string $option_key Option key.
	 * @return array<int, int>
	 */
	private function read_queue( $option_key ) {
		$queue = get_option( $option_key, array() );
		return is_array( $queue ) ? array_map( 'intval', $queue ) : array();
	}

	/**
	 * Whether a queue option currently has items.
	 *
	 * @param string $option_key Option key.
	 * @return bool
	 */
	private function queue_has_items( $option_key ) {
		return ! empty( $this->read_queue( $option_key ) );
	}
}
