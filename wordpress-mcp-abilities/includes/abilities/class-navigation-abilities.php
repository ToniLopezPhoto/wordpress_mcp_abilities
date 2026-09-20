<?php
/**
 * WordPress MCP Abilities — navigation ability registration.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Navigation_Abilities
 */
class WP_MCP_Navigation_Abilities {

	/** Register all classic navigation abilities. */
	public static function register() {
		self::register_read_abilities();
		self::register_menu_mutations();
		self::register_item_mutations();
	}

	private static function register_read_abilities() {
		wp_register_ability( 'wp-mcp/list-nav-menus', array(
			'label' => __( 'List Navigation Menus', 'wordpress-mcp-abilities' ),
			'description' => __( 'List classic navigation menus, registered locations, and bounded public menu-item fields.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array( 'menus' => array( 'type' => 'array', 'items' => self::menu_schema() ), 'total' => array( 'type' => 'integer' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Navigation', 'list_menus' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-nav-menu', array(
			'label' => __( 'Get Navigation Menu', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one classic navigation menu and its items.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array( 'menu_id' => self::positive_id() ), array( 'menu_id' ) ),
			'output_schema' => self::menu_schema(),
			'execute_callback' => array( 'WP_MCP_Navigation', 'get_menu' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-menu-locations', array(
			'label' => __( 'List Menu Locations', 'wordpress-mcp-abilities' ),
			'description' => __( 'List navigation locations registered by the active theme and their assignments.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array( 'locations' => array( 'type' => 'array', 'items' => self::location_schema() ), 'total' => array( 'type' => 'integer' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Navigation', 'list_locations' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_menu_mutations() {
		wp_register_ability( 'wp-mcp/create-nav-menu', array(
			'label' => __( 'Create Navigation Menu', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a classic navigation menu with an explicit name and optional description.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array( 'name' => array( 'type' => 'string', 'minLength' => 1 ), 'description' => array( 'type' => 'string' ) ), array( 'name' ) ),
			'output_schema' => self::menu_schema(),
			'execute_callback' => array( 'WP_MCP_Navigation', 'create_menu' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/update-nav-menu', array(
			'label' => __( 'Update Navigation Menu', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update the explicit name or description of a classic navigation menu.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array( 'menu_id' => self::positive_id(), 'name' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ) ), array( 'menu_id' ) ),
			'output_schema' => self::menu_schema(),
			'execute_callback' => array( 'WP_MCP_Navigation', 'update_menu' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/delete-nav-menu', array(
			'label' => __( 'Delete Navigation Menu', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete one classic navigation menu and its menu items.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array( 'menu_id' => self::positive_id() ), array( 'menu_id' ) ),
			'output_schema' => self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'deleted' => array( 'type' => 'boolean' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Navigation', 'delete_menu' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/assign-menu-location', array(
			'label' => __( 'Assign Menu Location', 'wordpress-mcp-abilities' ),
			'description' => __( 'Assign or unassign a classic menu from one location registered by the active theme.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array( 'menu_id' => self::positive_id(), 'location' => array( 'type' => 'string', 'minLength' => 1 ), 'assign' => array( 'type' => 'boolean', 'default' => true ) ), array( 'menu_id', 'location' ) ),
			'output_schema' => self::object_schema( array( 'menu_id' => array( 'type' => 'integer' ), 'location' => array( 'type' => 'string' ), 'assigned' => array( 'type' => 'boolean' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Navigation', 'assign_menu_location' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_item_mutations() {
		wp_register_ability( 'wp-mcp/add-menu-item', array(
			'label' => __( 'Add Menu Item', 'wordpress-mcp-abilities' ),
			'description' => __( 'Add a custom, post, page, or taxonomy item to a classic navigation menu.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( self::item_properties( true ), array( 'menu_id', 'title', 'type' ) ),
			'output_schema' => self::item_schema(),
			'execute_callback' => array( 'WP_MCP_Navigation', 'add_menu_item' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/update-menu-item', array(
			'label' => __( 'Update Menu Item', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update the explicit title, URL, hierarchy, or position of one item belonging to a classic menu.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array( 'menu_id' => self::positive_id(), 'item_id' => self::positive_id(), 'title' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ), 'parent_id' => array( 'type' => 'integer', 'minimum' => 0 ), 'position' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'menu_id', 'item_id' ) ),
			'output_schema' => self::item_schema(),
			'execute_callback' => array( 'WP_MCP_Navigation', 'update_menu_item' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/remove-menu-item', array(
			'label' => __( 'Remove Menu Item', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently remove one item belonging to a classic navigation menu.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array( 'menu_id' => self::positive_id(), 'item_id' => self::positive_id() ), array( 'menu_id', 'item_id' ) ),
			'output_schema' => self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'deleted' => array( 'type' => 'boolean' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Navigation', 'remove_menu_item' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/reorder-menu-items', array(
			'label' => __( 'Reorder Menu Items', 'wordpress-mcp-abilities' ),
			'description' => __( 'Persist an explicit complete ordering for every item in one classic navigation menu.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-navigation',
			'input_schema' => self::object_schema( array( 'menu_id' => self::positive_id(), 'item_ids' => array( 'type' => 'array', 'items' => self::positive_id(), 'minItems' => 1, 'maxItems' => 100 ) ), array( 'menu_id', 'item_ids' ) ),
			'output_schema' => self::object_schema( array( 'menu_id' => array( 'type' => 'integer' ), 'item_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Navigation', 'reorder_menu_items' ),
			'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function positive_id() {
		return array( 'type' => 'integer', 'minimum' => 1 );
	}

	private static function location_schema() {
		return self::object_schema( array( 'slug' => array( 'type' => 'string' ), 'label' => array( 'type' => 'string' ), 'menu_id' => array( 'type' => 'integer' ), 'menu_name' => array( 'type' => 'string' ) ), array() );
	}

	private static function item_properties( $include_menu ) {
		$properties = array(
			'title' => array( 'type' => 'string', 'minLength' => 1 ),
			'type' => array( 'type' => 'string', 'enum' => array( 'custom', 'post', 'page', 'taxonomy' ) ),
			'url' => array( 'type' => 'string' ),
			'object_id' => self::positive_id(),
			'taxonomy' => array( 'type' => 'string' ),
			'parent_id' => array( 'type' => 'integer', 'minimum' => 0 ),
			'position' => array( 'type' => 'integer', 'minimum' => 1 ),
			'target' => array( 'type' => 'string' ),
		);
		if ( $include_menu ) {
			$properties = array( 'menu_id' => self::positive_id() ) + $properties;
		}
		return $properties;
	}

	private static function item_schema() {
		return self::object_schema( array(
			'id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ),
			'type' => array( 'type' => 'string' ), 'object' => array( 'type' => 'string' ), 'object_id' => array( 'type' => 'integer' ),
			'parent_id' => array( 'type' => 'integer' ), 'position' => array( 'type' => 'integer' ), 'target' => array( 'type' => 'string' ),
			'classes' => array( 'type' => 'array' ), 'xfn' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ),
		), array() );
	}

	private static function menu_schema() {
		return self::object_schema( array(
			'id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ), 'count' => array( 'type' => 'integer' ), 'locations' => array( 'type' => 'array' ),
			'auto_add' => array( 'type' => 'boolean' ), 'items' => array( 'type' => 'array', 'items' => self::item_schema() ),
		), array() );
	}

	private static function object_schema( $properties, $required ) {
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false );
	}
}
