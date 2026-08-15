<?php
/**
 * Maps WooCommerce products to Mercaria's `IngestProduct` shape.
 *
 * Prices are emitted as INTEGER MINOR UNITS in the shop currency
 * (WooCommerce `get_woocommerce_currency()`), e.g. EUR 12.50 -> 1250.
 *
 * @package Mercaria_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Mercaria_WC_Product_Mapper
 */
class Mercaria_WC_Product_Mapper {

	/**
	 * ISO 4217 currencies with zero minor-unit digits.
	 */
	const ZERO_DECIMAL_CURRENCIES = array(
		'BIF',
		'CLP',
		'DJF',
		'GNF',
		'ISK',
		'JPY',
		'KMF',
		'KRW',
		'PYG',
		'RWF',
		'UGX',
		'VND',
		'VUV',
		'XAF',
		'XOF',
		'XPF',
	);

	/**
	 * ISO 4217 currencies with three minor-unit digits.
	 */
	const THREE_DECIMAL_CURRENCIES = array(
		'BHD',
		'IQD',
		'JOD',
		'KWD',
		'LYD',
		'OMR',
		'TND',
	);

	/**
	 * WooCommerce product types that carry their own price and can be pushed.
	 */
	const SUPPORTED_TYPES = array( 'simple', 'variable', 'external' );

	/**
	 * Number of minor-unit digits for an ISO 4217 currency (default 2).
	 *
	 * @param string $currency ISO 4217 code.
	 * @return int
	 */
	public static function minor_unit_exponent( $currency ) {
		$currency = strtoupper( (string) $currency );

		if ( in_array( $currency, self::ZERO_DECIMAL_CURRENCIES, true ) ) {
			return 0;
		}

		if ( in_array( $currency, self::THREE_DECIMAL_CURRENCIES, true ) ) {
			return 3;
		}

		return 2;
	}

	/**
	 * Convert a decimal amount to integer minor units for the given currency.
	 *
	 * @param string|float $amount   Decimal amount (e.g. "12.50").
	 * @param string       $currency ISO 4217 code.
	 * @return int
	 */
	public static function to_minor_units( $amount, $currency ) {
		$factor = pow( 10, self::minor_unit_exponent( $currency ) );
		return (int) round( (float) $amount * $factor );
	}

	/**
	 * Build a Money object ({ amount, currency }) in minor units.
	 *
	 * @param string|float $amount   Decimal amount.
	 * @param string       $currency ISO 4217 code.
	 * @return array{amount:int, currency:string}
	 */
	public static function money( $amount, $currency ) {
		return array(
			'amount'   => self::to_minor_units( $amount, $currency ),
			'currency' => strtoupper( (string) $currency ),
		);
	}

	/**
	 * Format an instant as the UTC, `Z`-suffixed timestamp the ingest API accepts.
	 *
	 * `DateTime::format( 'c' )` renders an OFFSET (`2026-08-15T10:23:45+02:00`,
	 * and `+00:00` even on a UTC site) and Mercaria's `externalUpdatedAt` is
	 * validated with a Z-only ISO 8601 rule, so an offset is rejected with
	 * HTTP 400 and the whole batch is refused. `getTimestamp()` is the true UTC
	 * epoch — NOT `getOffsetTimestamp()`, which WC_DateTime shifts by the store's
	 * offset for display and which would report the wrong instant here.
	 *
	 * @param WC_DateTime $date Date to format.
	 * @return string
	 */
	private static function utc_timestamp( $date ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() );
	}

	/**
	 * Map a WooCommerce product to an IngestProduct array.
	 *
	 * @param WC_Product $product Product to map.
	 * @return array<string, mixed>|null IngestProduct, or null if it cannot be pushed.
	 */
	public function map( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		if ( ! in_array( $product->get_type(), self::SUPPORTED_TYPES, true ) ) {
			return null;
		}

		$currency = get_woocommerce_currency();

		$ingest = array(
			'externalId' => (string) $product->get_id(),
			'title'      => $product->get_name(),
		);

		$modified = $product->get_date_modified();
		if ( $modified instanceof WC_DateTime ) {
			$ingest['externalUpdatedAt'] = self::utc_timestamp( $modified );
		}

		$description = $product->get_description();
		if ( '' !== trim( (string) $description ) ) {
			$ingest['description'] = $description;
		}

		$images = $this->map_images( $product );
		if ( ! empty( $images ) ) {
			$ingest['images'] = $images;
		}

		$vendor = $product->get_attribute( 'brand' );
		if ( '' !== $vendor ) {
			$ingest['vendor'] = $vendor;
		}

		$product_type = $this->map_product_type( $product );
		if ( '' !== $product_type ) {
			$ingest['productType'] = $product_type;
		}

		$handle = $product->get_slug();
		if ( '' !== $handle ) {
			$ingest['handle'] = $handle;
		}

		$seo = $this->map_seo( $product->get_id() );
		if ( ! empty( $seo ) ) {
			$ingest['seo'] = $seo;
		}

		if ( $product->is_type( 'variable' ) ) {
			$ingest['options']  = $this->map_options( $product );
			$ingest['variants'] = $this->map_variable_variants( $product, $currency );
		} else {
			$variant  = $this->build_variant( $product, $currency, null );
			$ingest['variants'] = null === $variant ? array() : array( $variant );
		}

		// A product with no priced variants cannot be sold; skip it.
		if ( empty( $ingest['variants'] ) ) {
			return null;
		}

		return $ingest;
	}

	/**
	 * Build the inventory items for a product (one per stock-managed variant).
	 *
	 * @param WC_Product $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	public function map_inventory_items( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$items = array();

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation instanceof WC_Product_Variation || ! $variation->managing_stock() ) {
					continue;
				}
				$items[] = $this->inventory_item( $product->get_id(), $variation->get_sku(), $variation->get_stock_quantity() );
			}
		} elseif ( $product->managing_stock() ) {
			$items[] = $this->inventory_item( $product->get_id(), $product->get_sku(), $product->get_stock_quantity() );
		}

		return $items;
	}

	/**
	 * Assemble a single inventory item, omitting an empty SKU.
	 *
	 * @param int         $product_id Parent product id (the externalId).
	 * @param string      $sku        Variant SKU.
	 * @param int|null    $available  Stock quantity.
	 * @return array<string, mixed>
	 */
	private function inventory_item( $product_id, $sku, $available ) {
		$item = array(
			'externalId' => (string) $product_id,
			'available'  => (int) $available,
		);
		if ( '' !== (string) $sku ) {
			$item['sku'] = (string) $sku;
		}
		return $item;
	}

	/**
	 * Collect the absolute URLs of a product's featured and gallery images.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int, string>
	 */
	private function map_images( $product ) {
		$urls = array();

		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
			$url = wp_get_attachment_image_url( $gallery_id, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Derive a product type label from the first assigned product category.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function map_product_type( $product ) {
		$category_ids = $product->get_category_ids();
		if ( empty( $category_ids ) ) {
			return '';
		}

		$term = get_term( $category_ids[0], 'product_cat' );
		if ( $term instanceof WP_Term ) {
			return $term->name;
		}

		return '';
	}

	/**
	 * Read SEO title/description from Yoast or Rank Math meta, if present.
	 *
	 * @param int $product_id Product id.
	 * @return array<string, string>
	 */
	private function map_seo( $product_id ) {
		$title = get_post_meta( $product_id, '_yoast_wpseo_title', true );
		if ( '' === $title ) {
			$title = get_post_meta( $product_id, 'rank_math_title', true );
		}

		$description = get_post_meta( $product_id, '_yoast_wpseo_metadesc', true );
		if ( '' === $description ) {
			$description = get_post_meta( $product_id, 'rank_math_description', true );
		}

		$seo = array();
		if ( '' !== (string) $title ) {
			$seo['title'] = (string) $title;
		}
		if ( '' !== (string) $description ) {
			$seo['description'] = (string) $description;
		}

		return $seo;
	}

	/**
	 * Map a variable product's attributes to the `options` array.
	 *
	 * @param WC_Product_Variable $product Variable product.
	 * @return array<int, array<string, mixed>>
	 */
	private function map_options( $product ) {
		$options = array();

		foreach ( $product->get_variation_attributes() as $key => $values ) {
			$labels = array();
			foreach ( $values as $value ) {
				$labels[] = $this->attribute_value_label( $key, $value );
			}

			$options[] = array(
				'name'   => wc_attribute_label( $key ),
				'values' => $labels,
			);
		}

		return $options;
	}

	/**
	 * Map a variable product's variations to the `variants` array.
	 *
	 * @param WC_Product_Variable $product  Variable product.
	 * @param string              $currency Shop currency.
	 * @return array<int, array<string, mixed>>
	 */
	private function map_variable_variants( $product, $currency ) {
		$variants = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$variant = $this->build_variant( $variation, $currency, $this->map_variation_option_values( $variation ) );
			if ( null !== $variant ) {
				$variants[] = $variant;
			}
		}

		return $variants;
	}

	/**
	 * Map a variation's selected attributes to `optionValues`.
	 *
	 * @param WC_Product_Variation $variation Variation.
	 * @return array<int, array{name:string, value:string}>
	 */
	private function map_variation_option_values( $variation ) {
		$values = array();

		foreach ( $variation->get_attributes() as $key => $value ) {
			// An empty value means "any" for that attribute; skip it.
			if ( '' === $value ) {
				continue;
			}

			$values[] = array(
				'name'  => wc_attribute_label( $key ),
				'value' => $this->attribute_value_label( $key, $value ),
			);
		}

		return $values;
	}

	/**
	 * Resolve an attribute value to a human-readable label.
	 *
	 * For global (taxonomy) attributes the stored value is a term slug, so we
	 * resolve it to the term name. Custom attribute values are already labels.
	 *
	 * @param string $key   Attribute key (e.g. `pa_color` or `size`).
	 * @param string $value Stored value.
	 * @return string
	 */
	private function attribute_value_label( $key, $value ) {
		$taxonomy = strtolower( (string) $key );

		if ( taxonomy_exists( $taxonomy ) ) {
			$term = get_term_by( 'slug', $value, $taxonomy );
			if ( $term instanceof WP_Term ) {
				return $term->name;
			}
		}

		return (string) $value;
	}

	/**
	 * Build a single variant object from a product or variation.
	 *
	 * @param WC_Product                        $product       Simple product or variation.
	 * @param string                            $currency      Shop currency.
	 * @param array<int, array>|null            $option_values Variant option values (variations only).
	 * @return array<string, mixed>|null Variant, or null if it has no usable price.
	 */
	private function build_variant( $product, $currency, $option_values ) {
		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			return null;
		}

		$variant = array(
			'price' => self::money( $price, $currency ),
		);

		if ( is_array( $option_values ) && ! empty( $option_values ) ) {
			$variant['optionValues'] = $option_values;
		}

		$regular = $product->get_regular_price();
		if ( '' !== $regular && (float) $regular > (float) $price ) {
			$variant['compareAtPrice'] = self::money( $regular, $currency );
		}

		$sku = $product->get_sku();
		if ( '' !== $sku ) {
			$variant['sku'] = $sku;
		}

		$barcode = $this->get_barcode( $product );
		if ( '' !== $barcode ) {
			$variant['barcode'] = $barcode;
		}

		if ( $product->managing_stock() ) {
			$variant['inventory'] = array(
				'available' => (int) $product->get_stock_quantity(),
			);
		}

		return $variant;
	}

	/**
	 * Read a product's GTIN/barcode (WooCommerce 9.2+ global unique id).
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	private function get_barcode( $product ) {
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = $product->get_global_unique_id();
			if ( '' !== (string) $gtin ) {
				return (string) $gtin;
			}
		}

		return '';
	}
}
