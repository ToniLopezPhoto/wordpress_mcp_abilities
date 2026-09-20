<?php
/**
 * PHPStan-only fallback stubs for the WordPress 6.9 Abilities API.
 *
 * Loaded as a PHPStan bootstrap file (phpstan.neon.dist), never shipped in
 * the plugin package (see wordpress-mcp-abilities/.gitattributes). Only
 * fills in symbols php-stubs/wordpress-stubs does not yet ship — guarded so
 * a future wordpress-stubs release that adds these does not collide.
 *
 * @package WP_MCP_Agent_Abilities
 */

if ( ! class_exists( 'WP_Ability' ) ) {
	/**
	 * @see https://developer.wordpress.org/reference/classes/wp_ability/
	 */
	class WP_Ability {
		public function get_name(): string {}
		public function get_label(): string {}
		public function get_description(): string {}
		public function get_category(): string {}

		/** @return array<string,mixed> */
		public function get_meta(): array {}

		/** @param mixed $default_value @return mixed */
		public function get_meta_item( string $key, $default_value = null ) {}

		/** @return array<string,mixed> */
		public function get_input_schema(): array {}

		/** @return array<string,mixed> */
		public function get_output_schema(): array {}

		/** @param mixed $input @return mixed */
		public function normalize_input( $input = null ) {}

		/** @param mixed $input @return true|WP_Error */
		public function validate_input( $input = null ) {}

		/** @param mixed $input @return bool|WP_Error */
		public function check_permissions( $input = null ) {}

		/** @param mixed $input @return mixed|WP_Error */
		public function execute( $input = null ) {}
	}
}

if ( ! class_exists( 'WP_Ability_Category' ) ) {
	class WP_Ability_Category {
		public function get_name(): string {}
		public function get_label(): string {}
		public function get_description(): string {}
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * @param array<string,mixed> $args
	 */
	function wp_register_ability( string $name, array $args ): ?WP_Ability {}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ): ?WP_Ability {}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/** @return WP_Ability[] */
	function wp_get_abilities(): array {}
}

if ( ! function_exists( 'wp_unregister_ability' ) ) {
	function wp_unregister_ability( string $name ): bool {}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	/**
	 * @param array<string,mixed> $args
	 */
	function wp_register_ability_category( string $slug, array $args ): ?WP_Ability_Category {}
}

if ( ! function_exists( 'wp_get_ability_category' ) ) {
	function wp_get_ability_category( string $slug ): ?WP_Ability_Category {}
}

if ( ! function_exists( 'wp_get_ability_categories' ) ) {
	/** @return WP_Ability_Category[] */
	function wp_get_ability_categories(): array {}
}

if ( ! function_exists( 'get_registered_sidebars' ) ) {
	/**
	 * Missing from php-stubs/wordpress-stubs as of this writing — a real
	 * wp-includes/widgets.php global, not part of the Abilities API, but
	 * this file is where every extra PHPStan-only stub lives.
	 *
	 * @return array<int|string,array<string,mixed>>
	 */
	function get_registered_sidebars(): array {}
}

if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
	class WP_Abilities_Registry {
		public static function get_instance(): self {}

		/** @return WP_Ability[] */
		public function get_all_registered(): array {}

		public function get_registered( string $name ): ?WP_Ability {}
	}
}
