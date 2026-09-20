<?php
/**
 * WordPress MCP Abilities — WooCommerce integration adapter.
 *
 * Talks to WooCommerce exclusively through its documented CRUD API
 * (`wc_get_products()`, `wc_get_product()`, `wc_get_orders()`,
 * `wc_get_order()`, `WC_Coupon`) — never through its database tables, its
 * REST controllers or its post meta. Coupons are listed through core's own
 * `WP_Query` over WooCommerce's own `shop_coupon` post type and then read
 * through `WC_Coupon`, which is the only listing route WooCommerce offers
 * that is stable across both the legacy and the HPOS order stores.
 *
 * What this adapter deliberately does not expose:
 *
 *  - Customer personally identifiable information. An order answers with its
 *    money, its status and its line items; never a billing or shipping
 *    address, an e-mail address, a phone number, the customer's IP, or the
 *    payment transaction reference. `customer_id` is returned so an agent
 *    can correlate orders, and the user abilities of issue #7 remain the one
 *    capability-checked route to anything about that person.
 *  - Payment gateway configuration and keys, and every other WooCommerce
 *    setting. Store settings are secrets-adjacent and get their own explicit,
 *    per-field allowlist in a follow-up issue, exactly like issue #10 did for
 *    core settings — never a generic option reader.
 *  - Refunds, order editing and order deletion. Money movement is not a
 *    thing an agent does on a first pass.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_WooCommerce_Integration
 */
class WP_MCP_WooCommerce_Integration extends WP_MCP_Integration {

	/**
	 * Product stock statuses WooCommerce itself understands.
	 */
	const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );

	/**
	 * Product statuses this adapter is willing to list.
	 */
	const PRODUCT_STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * @return string
	 */
	public function slug() {
		return 'woocommerce';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'WooCommerce', 'wordpress-mcp-abilities' );
	}

	/**
	 * @return string
	 */
	public function group() {
		return 'ecommerce';
	}

	/**
	 * @return string
	 */
	public function plugin_label() {
		return 'WooCommerce';
	}

	/**
	 * @return array<string,string[]>
	 */
	public function signals() {
		return array(
			'constants'    => array( 'WC_VERSION' ),
			'classes'      => array( 'WooCommerce' ),
			'functions'    => array( 'WC' ),
			'plugin_files' => array( 'woocommerce/woocommerce.php' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function required_capabilities() {
		return array( 'manage_woocommerce', 'edit_shop_orders' );
	}

	/**
	 * @return string[]
	 */
	public function excluded_data() {
		return array(
			'Billing and shipping addresses, customer e-mail addresses, phone numbers and IP addresses.',
			'Payment transaction references and payment gateway credentials.',
			'WooCommerce settings and API keys.',
			'Refunds, order editing and order deletion.',
		);
	}

	/**
	 * @return string
	 */
	public function notes() {
		return 'Read-oriented. The only write is a bounded stock update on a single product.';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function ability_matrix() {
		return array(
			'wp-mcp/woocommerce-get-store-status'    => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'manage_woocommerce (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/woocommerce-list-products'       => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'manage_woocommerce (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/woocommerce-get-product'         => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'manage_woocommerce (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/woocommerce-update-product-stock' => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'manage_woocommerce (or manage_options)',
				'meta_capability' => 'edit_post (product)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/woocommerce-list-orders'         => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'edit_shop_orders (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/woocommerce-get-order'           => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'edit_shop_orders (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/woocommerce-list-coupons'        => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'manage_woocommerce (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
		);
	}

	/* ==================================================================
	 * Registration
	 * ================================================================ */

	/**
	 * Register every WooCommerce ability.
	 */
	public function register_abilities() {
		$store = function () { return self::can_any( array( 'manage_woocommerce' ) ); };
		$order = function () { return self::can_any( array( 'edit_shop_orders' ) ); };

		wp_register_ability( 'wp-mcp/woocommerce-get-store-status', array(
			'label'               => __( 'WooCommerce: Get Store Status', 'wordpress-mcp-abilities' ),
			'description'         => __( 'Report the WooCommerce version, store currency, base location and the number of published products, orders and coupons. No settings and no credentials.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array() ),
			'output_schema'       => self::object_schema( array(
				'version'        => array( 'type' => 'string' ),
				'currency'       => array( 'type' => 'string' ),
				'base_country'   => array( 'type' => 'string' ),
				'base_state'     => array( 'type' => 'string' ),
				'product_count'  => array( 'type' => 'integer' ),
				'order_count'    => array( 'type' => 'integer' ),
				'coupon_count'   => array( 'type' => 'integer' ),
				'order_statuses' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			) ),
			'execute_callback'    => array( $this, 'get_store_status' ),
			'permission_callback' => $store,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/woocommerce-list-products', array(
			'label'               => __( 'WooCommerce: List Products', 'wordpress-mcp-abilities' ),
			'description'         => __( 'List products with bounded pagination and optional search, status, type and stock-status filters.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array(
				'search'       => array( 'type' => 'string', 'description' => 'Match against the product name and SKU.' ),
				'status'       => array( 'type' => 'string', 'description' => 'Product status.', 'enum' => self::PRODUCT_STATUSES ),
				'type'         => array( 'type' => 'string', 'description' => 'Product type, e.g. "simple" or "variable".', 'maxLength' => 40 ),
				'stock_status' => array( 'type' => 'string', 'description' => 'Stock status.', 'enum' => self::STOCK_STATUSES ),
			) + WP_MCP_Ability_Schema::pagination_input_properties() ),
			'output_schema'       => self::object_schema( array(
				'products' => array( 'type' => 'array', 'items' => self::product_summary_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties() ),
			'execute_callback'    => array( $this, 'list_products' ),
			'permission_callback' => $store,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/woocommerce-get-product', array(
			'label'               => __( 'WooCommerce: Get Product', 'wordpress-mcp-abilities' ),
			'description'         => __( 'Read one product: pricing, stock, type, descriptions, categories and tags.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array( 'product_id' => self::id_property( 'Product ID.' ) ), array( 'product_id' ) ),
			'output_schema'       => self::product_detail_schema(),
			'execute_callback'    => array( $this, 'get_product' ),
			'permission_callback' => $store,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/woocommerce-update-product-stock', array(
			'label'               => __( 'WooCommerce: Update Product Stock', 'wordpress-mcp-abilities' ),
			'description'         => __( 'Set the stock quantity and/or the stock status of one product. Idempotent: writing the values a product already has is a no-op success. Prices are never touched.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array(
				'product_id'     => self::id_property( 'Product ID.' ),
				'stock_quantity' => array( 'type' => 'integer', 'description' => 'New stock quantity. Setting it turns stock management on for the product.', 'minimum' => 0 ),
				'stock_status'   => array( 'type' => 'string', 'description' => 'New stock status.', 'enum' => self::STOCK_STATUSES ),
			), array( 'product_id' ) ),
			'output_schema'       => self::product_summary_schema(),
			'execute_callback'    => array( $this, 'update_product_stock' ),
			'permission_callback' => $store,
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/woocommerce-list-orders', array(
			'label'               => __( 'WooCommerce: List Orders', 'wordpress-mcp-abilities' ),
			'description'         => __( 'List orders with bounded pagination and optional status and customer filters. Answers with money, status and counts only — never with customer addresses, e-mail addresses or phone numbers.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array(
				'status'      => array( 'type' => 'string', 'description' => 'Order status, with or without the "wc-" prefix.', 'maxLength' => 40 ),
				'customer_id' => array( 'type' => 'integer', 'description' => 'Restrict to orders of one registered customer.', 'minimum' => 1 ),
			) + WP_MCP_Ability_Schema::pagination_input_properties() ),
			'output_schema'       => self::object_schema( array(
				'orders' => array( 'type' => 'array', 'items' => self::order_summary_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties() ),
			'execute_callback'    => array( $this, 'list_orders' ),
			'permission_callback' => $order,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/woocommerce-get-order', array(
			'label'               => __( 'WooCommerce: Get Order', 'wordpress-mcp-abilities' ),
			'description'         => __( 'Read one order: status, totals, payment method title and line items. Never the customer address, e-mail address, phone number, IP or transaction reference.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array( 'order_id' => self::id_property( 'Order ID.' ) ), array( 'order_id' ) ),
			'output_schema'       => self::order_detail_schema(),
			'execute_callback'    => array( $this, 'get_order' ),
			'permission_callback' => $order,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/woocommerce-list-coupons', array(
			'label'               => __( 'WooCommerce: List Coupons', 'wordpress-mcp-abilities' ),
			'description'         => __( 'List published coupons with bounded pagination and optional code search: discount type, amount, usage and expiry.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array(
				'search' => array( 'type' => 'string', 'description' => 'Match against the coupon code.' ),
			) + WP_MCP_Ability_Schema::pagination_input_properties() ),
			'output_schema'       => self::object_schema( array(
				'coupons' => array( 'type' => 'array', 'items' => self::coupon_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties() ),
			'execute_callback'    => array( $this, 'list_coupons' ),
			'permission_callback' => $store,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ==================================================================
	 * Callbacks — store
	 * ================================================================ */

	/**
	 * Describe the store.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public function get_store_status( $input = array() ) {
		$denied = $this->guard( array( 'manage_woocommerce' ) );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$version = '';
		if ( defined( 'WC_VERSION' ) ) {
			$version = (string) constant( 'WC_VERSION' );
		} elseif ( function_exists( 'WC' ) ) {
			$version = (string) WC()->version;
		}

		$base = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();

		$coupons = wp_count_posts( 'shop_coupon' );

		return array(
			'version'        => $version,
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'base_country'   => isset( $base['country'] ) ? (string) $base['country'] : '',
			'base_state'     => isset( $base['state'] ) ? (string) $base['state'] : '',
			'product_count'  => $this->count_products( array( 'status' => array( 'publish' ) ) ),
			'order_count'    => $this->count_orders( array() ),
			'coupon_count'   => isset( $coupons->publish ) ? (int) $coupons->publish : 0,
			'order_statuses' => function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array(),
		);
	}

	/* ==================================================================
	 * Callbacks — products
	 * ================================================================ */

	/**
	 * List products.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function list_products( $input = array() ) {
		$denied = $this->guard( array( 'manage_woocommerce' ) );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( (array) $input );

		$args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'return'   => 'objects',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}
		if ( ! empty( $input['status'] ) && in_array( $input['status'], self::PRODUCT_STATUSES, true ) ) {
			$args['status'] = array( (string) $input['status'] );
		}
		if ( ! empty( $input['type'] ) ) {
			$args['type'] = sanitize_key( (string) $input['type'] );
		}
		if ( ! empty( $input['stock_status'] ) && in_array( $input['stock_status'], self::STOCK_STATUSES, true ) ) {
			$args['stock_status'] = (string) $input['stock_status'];
		}

		$results = wc_get_products( $args );
		if ( ! is_object( $results ) || ! isset( $results->products ) ) {
			return WP_MCP_Errors::integration_failed( __( 'WooCommerce did not return a product list.', 'wordpress-mcp-abilities' ) );
		}

		$products = array();
		foreach ( (array) $results->products as $product ) {
			if ( $product instanceof WC_Product ) {
				$products[] = $this->product_summary( $product );
			}
		}

		$total = isset( $results->total ) ? (int) $results->total : count( $products );

		return array(
			'products'    => $products,
			'total'       => $total,
			'total_pages' => isset( $results->max_num_pages ) ? (int) $results->max_num_pages : (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Read one product.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function get_product( $input = array() ) {
		$denied = $this->guard( array( 'manage_woocommerce' ) );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$product = $this->resolve_product( $input );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$detail = $this->product_summary( $product );

		$detail['description']       = $product->get_description();
		$detail['short_description'] = $product->get_short_description();
		$detail['permalink']         = $product->get_permalink();
		$detail['total_sales']       = (int) $product->get_total_sales();
		$detail['categories']        = $this->term_names( $product->get_category_ids(), 'product_cat' );
		$detail['tags']              = $this->term_names( $product->get_tag_ids(), 'product_tag' );

		return $detail;
	}

	/**
	 * Update the stock of one product.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function update_product_stock( $input = array() ) {
		$denied = $this->guard( array( 'manage_woocommerce' ) );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$product = $this->resolve_product( $input );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$has_quantity = array_key_exists( 'stock_quantity', (array) $input ) && null !== $input['stock_quantity'];
		$has_status   = ! empty( $input['stock_status'] );

		if ( ! $has_quantity && ! $has_status ) {
			return WP_MCP_Errors::validation_error( __( 'Provide stock_quantity, stock_status, or both.', 'wordpress-mcp-abilities' ) );
		}

		$product_id = (int) $product->get_id();

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/woocommerce-update-product-stock', $product_id, false, 'wp_mcp_permission_denied', array() );

			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to edit this product.', 'wordpress-mcp-abilities' ) );
		}

		if ( $has_quantity ) {
			$quantity = absint( $input['stock_quantity'] );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $quantity );
		}

		if ( $has_status ) {
			$status = (string) $input['stock_status'];
			if ( ! in_array( $status, self::STOCK_STATUSES, true ) ) {
				return WP_MCP_Errors::validation_error( __( 'Unknown stock status.', 'wordpress-mcp-abilities' ) );
			}
			$product->set_stock_status( $status );
		}

		$saved = (int) $product->save();

		if ( $saved <= 0 ) {
			WP_MCP_Audit::log( 'wp-mcp/woocommerce-update-product-stock', $product_id, false, 'wp_mcp_integration_failed', array() );

			return WP_MCP_Errors::integration_failed( __( 'WooCommerce could not save the product.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/woocommerce-update-product-stock', $product_id, true, '', array( 'stock_quantity' => $has_quantity ? absint( $input['stock_quantity'] ) : null ) );

		$refreshed = wc_get_product( $product_id );

		return $this->product_summary( $refreshed instanceof WC_Product ? $refreshed : $product );
	}

	/* ==================================================================
	 * Callbacks — orders
	 * ================================================================ */

	/**
	 * List orders.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function list_orders( $input = array() ) {
		$denied = $this->guard( array( 'edit_shop_orders' ) );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( (array) $input );

		$args = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		if ( ! empty( $input['status'] ) ) {
			$status = $this->normalize_order_status( (string) $input['status'] );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			$args['status'] = $status;
		}

		if ( ! empty( $input['customer_id'] ) ) {
			$args['customer_id'] = absint( $input['customer_id'] );
		}

		$results = wc_get_orders( $args );
		if ( ! is_object( $results ) || ! isset( $results->orders ) ) {
			return WP_MCP_Errors::integration_failed( __( 'WooCommerce did not return an order list.', 'wordpress-mcp-abilities' ) );
		}

		$orders = array();
		foreach ( (array) $results->orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$orders[] = $this->order_summary( $order );
			}
		}

		$total = isset( $results->total ) ? (int) $results->total : count( $orders );

		return array(
			'orders'      => $orders,
			'total'       => $total,
			'total_pages' => isset( $results->max_num_pages ) ? (int) $results->max_num_pages : (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Read one order.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function get_order( $input = array() ) {
		$denied = $this->guard( array( 'edit_shop_orders' ) );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$order_id = isset( $input['order_id'] ) ? $input['order_id'] : 0;
		if ( ! WP_MCP_Permissions::is_strict_positive_int_id( $order_id ) ) {
			return WP_MCP_Errors::validation_error( __( 'order_id must be a positive integer.', 'wordpress-mcp-abilities' ) );
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return WP_MCP_Errors::integration_object_not_found( __( 'The specified WooCommerce order does not exist.', 'wordpress-mcp-abilities' ) );
		}

		$detail = $this->order_summary( $order );

		$detail['total_tax']      = (string) $order->get_total_tax();
		$detail['shipping_total'] = (string) $order->get_shipping_total();
		$detail['discount_total'] = (string) $order->get_discount_total();
		$detail['date_paid']      = self::format_date( $order->get_date_paid() );

		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$items[] = array(
				'item_id'      => (int) $item->get_id(),
				'name'         => (string) $item->get_name(),
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'quantity'     => (int) $item->get_quantity(),
				'subtotal'     => (string) $item->get_subtotal(),
				'total'        => (string) $item->get_total(),
			);
		}
		$detail['items'] = $items;

		return $detail;
	}

	/* ==================================================================
	 * Callbacks — coupons
	 * ================================================================ */

	/**
	 * List coupons.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function list_coupons( $input = array() ) {
		$denied = $this->guard( array( 'manage_woocommerce' ) );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( (array) $input );

		$args = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		$query = new WP_Query( $args );

		$coupons = array();
		foreach ( $query->posts as $coupon_post ) {
			$coupon_id = $coupon_post instanceof WP_Post ? (int) $coupon_post->ID : (int) $coupon_post;
			$coupon    = new WC_Coupon( $coupon_id );
			if ( ! $coupon->get_id() ) {
				continue;
			}
			$coupons[] = array(
				'id'            => (int) $coupon->get_id(),
				'code'          => (string) $coupon->get_code(),
				'discount_type' => (string) $coupon->get_discount_type(),
				'amount'        => (string) $coupon->get_amount(),
				'usage_count'   => (int) $coupon->get_usage_count(),
				'usage_limit'   => (int) $coupon->get_usage_limit(),
				'date_expires'  => self::format_date( $coupon->get_date_expires() ),
				'description'   => (string) $coupon->get_description(),
			);
		}

		return array(
			'coupons'     => $coupons,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/* ==================================================================
	 * Internals
	 * ================================================================ */

	/**
	 * Availability plus capability guard shared by every callback.
	 *
	 * @param string[] $capabilities WooCommerce capabilities that grant access.
	 * @return WP_Error|null
	 */
	private function guard( array $capabilities ) {
		$unavailable = $this->require_available();
		if ( is_wp_error( $unavailable ) ) {
			return $unavailable;
		}

		if ( ! self::can_any( $capabilities ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to manage this WooCommerce store.', 'wordpress-mcp-abilities' ) );
		}

		return null;
	}

	/**
	 * Resolve and validate a product from ability input.
	 *
	 * @param array $input Ability input.
	 * @return WC_Product|WP_Error
	 */
	private function resolve_product( $input ) {
		$product_id = isset( $input['product_id'] ) ? $input['product_id'] : 0;

		if ( ! WP_MCP_Permissions::is_strict_positive_int_id( $product_id ) ) {
			return WP_MCP_Errors::validation_error( __( 'product_id must be a positive integer.', 'wordpress-mcp-abilities' ) );
		}

		$product = wc_get_product( (int) $product_id );

		if ( ! $product instanceof WC_Product ) {
			return WP_MCP_Errors::integration_object_not_found( __( 'The specified WooCommerce product does not exist.', 'wordpress-mcp-abilities' ) );
		}

		return $product;
	}

	/**
	 * Count products matching the given `wc_get_products()` arguments.
	 *
	 * @param array<string,mixed> $args Extra arguments.
	 * @return int
	 */
	private function count_products( array $args ) {
		$results = wc_get_products( array_merge( array( 'limit' => 1, 'paginate' => true, 'return' => 'ids' ), $args ) );

		return is_object( $results ) && isset( $results->total ) ? (int) $results->total : 0;
	}

	/**
	 * Count orders matching the given `wc_get_orders()` arguments.
	 *
	 * @param array<string,mixed> $args Extra arguments.
	 * @return int
	 */
	private function count_orders( array $args ) {
		$results = wc_get_orders( array_merge( array( 'limit' => 1, 'paginate' => true, 'return' => 'ids' ), $args ) );

		return is_object( $results ) && isset( $results->total ) ? (int) $results->total : 0;
	}

	/**
	 * Validate an order status against WooCommerce's own registry.
	 *
	 * @param string $status Raw status, with or without the `wc-` prefix.
	 * @return string|WP_Error
	 */
	private function normalize_order_status( $status ) {
		$status = sanitize_key( $status );
		$status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		$known = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
		$known = array_map(
			function ( $key ) {
				return 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			},
			$known
		);

		if ( ! in_array( $status, $known, true ) ) {
			return WP_MCP_Errors::validation_error( __( 'Unknown WooCommerce order status.', 'wordpress-mcp-abilities' ) );
		}

		return $status;
	}

	/**
	 * Term names for a list of term IDs.
	 *
	 * @param int[]  $term_ids Term IDs.
	 * @param string $taxonomy Taxonomy name.
	 * @return string[]
	 */
	private function term_names( $term_ids, $taxonomy ) {
		$names = array();

		foreach ( (array) $term_ids as $term_id ) {
			$term = get_term( (int) $term_id, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$names[] = $term->name;
			}
		}

		return $names;
	}

	/**
	 * Public, PII-free summary of a product.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	private function product_summary( WC_Product $product ) {
		return array(
			'id'             => (int) $product->get_id(),
			'name'           => (string) $product->get_name(),
			'sku'            => (string) $product->get_sku(),
			'type'           => (string) $product->get_type(),
			'status'         => (string) $product->get_status(),
			'price'          => (string) $product->get_price(),
			'regular_price'  => (string) $product->get_regular_price(),
			'sale_price'     => (string) $product->get_sale_price(),
			'stock_status'   => (string) $product->get_stock_status(),
			'stock_quantity' => null === $product->get_stock_quantity() ? 0 : (int) $product->get_stock_quantity(),
			'manage_stock'   => (bool) $product->get_manage_stock(),
			'date_created'   => self::format_date( $product->get_date_created() ),
		);
	}

	/**
	 * Public, PII-free summary of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function order_summary( WC_Order $order ) {
		return array(
			'id'                    => (int) $order->get_id(),
			'number'                => (string) $order->get_order_number(),
			'status'                => (string) $order->get_status(),
			'currency'              => (string) $order->get_currency(),
			'total'                 => (string) $order->get_total(),
			'payment_method_title'  => (string) $order->get_payment_method_title(),
			'customer_id'           => (int) $order->get_customer_id(),
			'item_count'            => (int) $order->get_item_count(),
			'date_created'          => self::format_date( $order->get_date_created() ),
		);
	}

	/* ==================================================================
	 * Schemas
	 * ================================================================ */

	/**
	 * @param string $description Property description.
	 * @return array<string,mixed>
	 */
	private static function id_property( $description ) {
		return array( 'type' => 'integer', 'description' => $description, 'minimum' => 1 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function product_summary_schema() {
		return self::object_schema( array(
			'id'             => array( 'type' => 'integer' ),
			'name'           => array( 'type' => 'string' ),
			'sku'            => array( 'type' => 'string' ),
			'type'           => array( 'type' => 'string' ),
			'status'         => array( 'type' => 'string' ),
			'price'          => array( 'type' => 'string' ),
			'regular_price'  => array( 'type' => 'string' ),
			'sale_price'     => array( 'type' => 'string' ),
			'stock_status'   => array( 'type' => 'string' ),
			'stock_quantity' => array( 'type' => 'integer' ),
			'manage_stock'   => array( 'type' => 'boolean' ),
			'date_created'   => array( 'type' => 'string' ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function product_detail_schema() {
		$summary = self::product_summary_schema();

		return self::object_schema( $summary['properties'] + array(
			'description'       => array( 'type' => 'string' ),
			'short_description' => array( 'type' => 'string' ),
			'permalink'         => array( 'type' => 'string' ),
			'total_sales'       => array( 'type' => 'integer' ),
			'categories'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'tags'              => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function order_summary_schema() {
		return self::object_schema( array(
			'id'                   => array( 'type' => 'integer' ),
			'number'               => array( 'type' => 'string' ),
			'status'               => array( 'type' => 'string' ),
			'currency'             => array( 'type' => 'string' ),
			'total'                => array( 'type' => 'string' ),
			'payment_method_title' => array( 'type' => 'string' ),
			'customer_id'          => array( 'type' => 'integer' ),
			'item_count'           => array( 'type' => 'integer' ),
			'date_created'         => array( 'type' => 'string' ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function order_detail_schema() {
		$summary = self::order_summary_schema();

		return self::object_schema( $summary['properties'] + array(
			'total_tax'      => array( 'type' => 'string' ),
			'shipping_total' => array( 'type' => 'string' ),
			'discount_total' => array( 'type' => 'string' ),
			'date_paid'      => array( 'type' => 'string' ),
			'items'          => array( 'type' => 'array', 'items' => self::order_item_schema() ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function order_item_schema() {
		return self::object_schema( array(
			'item_id'      => array( 'type' => 'integer' ),
			'name'         => array( 'type' => 'string' ),
			'product_id'   => array( 'type' => 'integer' ),
			'variation_id' => array( 'type' => 'integer' ),
			'quantity'     => array( 'type' => 'integer' ),
			'subtotal'     => array( 'type' => 'string' ),
			'total'        => array( 'type' => 'string' ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function coupon_schema() {
		return self::object_schema( array(
			'id'            => array( 'type' => 'integer' ),
			'code'          => array( 'type' => 'string' ),
			'discount_type' => array( 'type' => 'string' ),
			'amount'        => array( 'type' => 'string' ),
			'usage_count'   => array( 'type' => 'integer' ),
			'usage_limit'   => array( 'type' => 'integer' ),
			'date_expires'  => array( 'type' => 'string' ),
			'description'   => array( 'type' => 'string' ),
		) );
	}
}
