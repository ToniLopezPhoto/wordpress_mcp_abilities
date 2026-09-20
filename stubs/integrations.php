<?php
/**
 * PHPStan-only fallback stubs for the third-party plugin APIs that the
 * WordPress MCP integration adapters call (issue #14).
 *
 * Loaded as a PHPStan bootstrap file (phpstan.neon.dist), never shipped in
 * the plugin package and never loaded at runtime: every call site is guarded
 * by the adapter's own detection before it runs. The file doubles as the
 * exact, auditable list of third-party symbols this plugin is allowed to
 * touch — anything not declared here is not called.
 *
 * @package WP_MCP_Agent_Abilities
 */

/* =====================================================================
 * WooCommerce
 * =================================================================== */

if ( ! class_exists( 'WooCommerce' ) ) {
	/**
	 * @see https://woocommerce.github.io/code-reference/classes/WooCommerce.html
	 */
	class WooCommerce {
		/** @var string */
		public $version = '';
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * @see https://woocommerce.github.io/code-reference/classes/WC-Product.html
	 */
	class WC_Product {
		public function get_id(): int {}
		public function get_name(): string {}
		public function get_slug(): string {}
		public function get_sku(): string {}
		public function get_type(): string {}
		public function get_status(): string {}
		public function get_price(): string {}
		public function get_regular_price(): string {}
		public function get_sale_price(): string {}
		public function get_stock_status(): string {}
		/** @return int|null */
		public function get_stock_quantity() {}
		public function get_manage_stock(): bool {}
		public function get_description(): string {}
		public function get_short_description(): string {}
		public function get_permalink(): string {}
		public function get_total_sales(): int {}
		/** @return int[] */
		public function get_category_ids(): array {}
		/** @return int[] */
		public function get_tag_ids(): array {}
		/** @return mixed */
		public function get_date_created() {}
		/** @param bool $manage @return void */
		public function set_manage_stock( $manage ) {}
		/** @param int|null $quantity @return void */
		public function set_stock_quantity( $quantity ) {}
		/** @param string $status @return void */
		public function set_stock_status( $status ) {}
		public function save(): int {}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * @see https://woocommerce.github.io/code-reference/classes/WC-Order.html
	 */
	class WC_Order {
		public function get_id(): int {}
		public function get_order_number(): string {}
		public function get_status(): string {}
		public function get_currency(): string {}
		public function get_total(): string {}
		public function get_total_tax(): string {}
		public function get_shipping_total(): string {}
		public function get_discount_total(): string {}
		public function get_payment_method_title(): string {}
		public function get_customer_id(): int {}
		public function get_item_count(): int {}
		/** @return mixed */
		public function get_date_created() {}
		/** @return mixed */
		public function get_date_paid() {}
		/** @param string $types @return array<int,mixed> */
		public function get_items( $types = 'line_item' ): array {}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	/**
	 * @see https://woocommerce.github.io/code-reference/classes/WC-Order-Item-Product.html
	 */
	class WC_Order_Item_Product {
		public function get_id(): int {}
		public function get_name(): string {}
		public function get_product_id(): int {}
		public function get_variation_id(): int {}
		public function get_quantity(): int {}
		public function get_subtotal(): string {}
		public function get_total(): string {}
	}
}

if ( ! class_exists( 'WC_Coupon' ) ) {
	/**
	 * @see https://woocommerce.github.io/code-reference/classes/WC-Coupon.html
	 */
	class WC_Coupon {
		/** @param int|string $code */
		public function __construct( $code = '' ) {}
		public function get_id(): int {}
		public function get_code(): string {}
		public function get_discount_type(): string {}
		public function get_amount(): string {}
		public function get_usage_count(): int {}
		/** @return int|string */
		public function get_usage_limit() {}
		/** @return mixed */
		public function get_date_expires() {}
		public function get_description(): string {}
	}
}

if ( ! function_exists( 'WC' ) ) {
	/** @return WooCommerce */
	function WC() {}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return \stdClass|array<int,WC_Product>
	 */
	function wc_get_products( array $args ) {}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * @param int $product_id
	 * @return WC_Product|false
	 */
	function wc_get_product( $product_id = 0 ) {}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return \stdClass|array<int,WC_Order>
	 */
	function wc_get_orders( array $args ) {}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $order_id
	 * @return WC_Order|false
	 */
	function wc_get_order( $order_id = 0 ) {}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	/** @return string */
	function get_woocommerce_currency() {}
}

if ( ! function_exists( 'wc_get_base_location' ) ) {
	/** @return array<string,string> */
	function wc_get_base_location() {}
}

/* =====================================================================
 * Yoast SEO
 * =================================================================== */

if ( ! class_exists( 'WPSEO_Meta' ) ) {
	/**
	 * @see https://github.com/Yoast/wordpress-seo/blob/trunk/inc/class-wpseo-meta.php
	 */
	class WPSEO_Meta {
		/** @param string $key @param int $post_id @return string */
		public static function get_value( $key, $post_id = 0 ) {}
		/** @param string $key @param string $meta_value @param int $post_id @return bool */
		public static function set_value( $key, $meta_value, $post_id ) {}
	}
}

/* =====================================================================
 * Contact Form 7
 * =================================================================== */

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	/**
	 * @see https://contactform7.com/
	 */
	class WPCF7_ContactForm {
		/**
		 * @param array<string,mixed> $args
		 * @return array<int,mixed>
		 */
		public static function find( $args = array() ) {}
		/** @param int|WP_Post $post @return WPCF7_ContactForm|null */
		public static function get_instance( $post ) {}
		public function id(): int {}
		public function title(): string {}
		/** @param array<string,mixed>|string $args @return string */
		public function shortcode( $args = '' ) {}
		public function locale(): string {}
		/** @param mixed $cond @return array<int,mixed> */
		public function scan_form_tags( $cond = null ) {}
	}
}

if ( ! class_exists( 'WPCF7_FormTag' ) ) {
	/**
	 * @see https://contactform7.com/
	 */
	class WPCF7_FormTag {
		/** @var string */
		public $type = '';
		/** @var string */
		public $basetype = '';
		/** @var string */
		public $name = '';
		/** @return bool */
		public function is_required() {}
	}
}

/* =====================================================================
 * Gravity Forms
 * =================================================================== */

if ( ! class_exists( 'GFAPI' ) ) {
	/**
	 * @see https://docs.gravityforms.com/api-functions/
	 */
	class GFAPI {
		/** @param bool|null $active @param bool $trash @return array<int,array<string,mixed>> */
		public static function get_forms( $active = true, $trash = false ) {}
		/** @param int $form_id @return array<string,mixed>|false */
		public static function get_form( $form_id ) {}
		/** @param int $form_id @param array<string,mixed> $search_criteria @return int */
		public static function count_entries( $form_id, $search_criteria = array() ) {}
	}
}

if ( ! class_exists( 'GFCommon' ) ) {
	/**
	 * @see https://docs.gravityforms.com/
	 */
	class GFCommon {
		/** @var string */
		public static $version = '';
		/** @param string|array<int,string> $caps @return bool */
		public static function current_user_can_any( $caps ) {}
	}
}
