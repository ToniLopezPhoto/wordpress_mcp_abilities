<?php
/**
 * WordPress MCP Abilities — Site Editor ability registration.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Site_Editor_Abilities
 */
class WP_MCP_Site_Editor_Abilities {

	/** Register all Site Editor and block-navigation abilities. */
	public static function register() {
		self::register_template_abilities();
		self::register_pattern_abilities();
		self::register_navigation_block_abilities();
		self::register_context_abilities();
	}

	private static function register_template_abilities() {
		$kinds = array(
			'template'      => array(
				'read_slug'    => 'list-templates',
				'get_slug'     => 'get-template',
				'update_slug'  => 'update-template',
				'delete_slug'  => 'delete-template',
				'list_label'   => __( 'List Templates', 'wordpress-mcp-abilities' ),
				'list_desc'    => __( 'List active-theme and database template entities through the WordPress block-template API.', 'wordpress-mcp-abilities' ),
				'get_label'    => __( 'Get Template', 'wordpress-mcp-abilities' ),
				'get_desc'     => __( 'Get one template without exposing theme files directly.', 'wordpress-mcp-abilities' ),
				'update_label' => __( 'Update Template', 'wordpress-mcp-abilities' ),
				'update_desc'  => __( 'Create or update only the database representation of a template; theme files are never edited.', 'wordpress-mcp-abilities' ),
				'delete_label' => __( 'Delete Template', 'wordpress-mcp-abilities' ),
			),
			'template_part' => array(
				'read_slug'    => 'list-template-parts',
				'get_slug'     => 'get-template-part',
				'update_slug'  => 'update-template-part',
				'delete_slug'  => 'delete-template-part',
				'list_label'   => __( 'List Template Parts', 'wordpress-mcp-abilities' ),
				'list_desc'    => __( 'List active-theme and database template part entities through the WordPress block-template API.', 'wordpress-mcp-abilities' ),
				'get_label'    => __( 'Get Template Part', 'wordpress-mcp-abilities' ),
				'get_desc'     => __( 'Get one template part without exposing theme files directly.', 'wordpress-mcp-abilities' ),
				'update_label' => __( 'Update Template Part', 'wordpress-mcp-abilities' ),
				'update_desc'  => __( 'Create or update only the database representation of a template part; theme files are never edited.', 'wordpress-mcp-abilities' ),
				'delete_label' => __( 'Delete Template Part', 'wordpress-mcp-abilities' ),
			),
		);
		foreach ( $kinds as $kind => $strings ) {
			$read_callback = 'template' === $kind ? 'get_template' : 'get_template_part';
			$list_callback = 'template' === $kind ? 'list_templates' : 'list_template_parts';
			$update_callback = 'template' === $kind ? 'update_template' : 'update_template_part';
			$delete_callback = 'template' === $kind ? 'delete_template' : 'delete_template_part';
			wp_register_ability( 'wp-mcp/' . $strings['read_slug'], array( 'label' => $strings['list_label'], 'description' => $strings['list_desc'], 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( WP_MCP_Ability_Schema::pagination_input_properties(), array() ), 'output_schema' => self::template_list_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', $list_callback ), 'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); }, 'meta' => WP_MCP_Ability_Schema::meta_readonly() ) );
			wp_register_ability( 'wp-mcp/' . $strings['get_slug'], array( 'label' => $strings['get_label'], 'description' => $strings['get_desc'], 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'template_id' => array( 'type' => 'string', 'minLength' => 1 ) ), array( 'template_id' ) ), 'output_schema' => self::template_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', $read_callback ), 'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); }, 'meta' => WP_MCP_Ability_Schema::meta_readonly() ) );
			wp_register_ability( 'wp-mcp/' . $strings['update_slug'], array( 'label' => $strings['update_label'], 'description' => $strings['update_desc'], 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'template_id' => array( 'type' => 'string', 'minLength' => 1 ), 'content' => array( 'type' => 'string', 'minLength' => 1 ), 'title' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ) ), array( 'template_id', 'content' ) ), 'output_schema' => self::template_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', $update_callback ), 'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); }, 'meta' => WP_MCP_Ability_Schema::meta_write( true, true ) ) );
			wp_register_ability( 'wp-mcp/' . $strings['delete_slug'], array( 'label' => $strings['delete_label'], 'description' => __( 'Delete a database customization without touching the active theme file.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'template_id' => array( 'type' => 'string', 'minLength' => 1 ) ), array( 'template_id' ) ), 'output_schema' => self::deleted_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', $delete_callback ), 'permission_callback' => function () { return current_user_can( 'edit_theme_options' ); }, 'meta' => WP_MCP_Ability_Schema::meta_write( true, false ) ) );
		}
	}

	private static function register_pattern_abilities() {
		wp_register_ability( 'wp-mcp/list-patterns', array( 'label' => __( 'List Block Patterns', 'wordpress-mcp-abilities' ), 'description' => __( 'List registered patterns and database-backed synced patterns.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( WP_MCP_Ability_Schema::pagination_input_properties(), array() ), 'output_schema' => self::list_schema( 'patterns', self::pattern_schema() ), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'list_patterns' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_readonly() ) );
		wp_register_ability( 'wp-mcp/get-pattern', array( 'label' => __( 'Get Block Pattern', 'wordpress-mcp-abilities' ), 'description' => __( 'Get one registered pattern by name or one synced database pattern by ID.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'pattern_id' => array( 'type' => array( 'string', 'integer' ), 'minLength' => 1 ) ), array( 'pattern_id' ) ), 'output_schema' => self::pattern_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'get_pattern' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_readonly() ) );
		wp_register_ability( 'wp-mcp/create-synced-pattern', array( 'label' => __( 'Create Synced Pattern', 'wordpress-mcp-abilities' ), 'description' => __( 'Create a database-backed synced block pattern using the wp_block entity.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'title' => array( 'type' => 'string', 'minLength' => 1 ), 'content' => array( 'type' => 'string', 'minLength' => 1 ) ), array( 'title', 'content' ) ), 'output_schema' => self::pattern_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'create_synced_pattern' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_write( false, false ) ) );
		wp_register_ability( 'wp-mcp/update-synced-pattern', array( 'label' => __( 'Update Synced Pattern', 'wordpress-mcp-abilities' ), 'description' => __( 'Update explicit fields on a database-backed synced block pattern.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'pattern_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'title' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'sync_status' => array( 'type' => 'string', 'enum' => array( 'synced', 'content-only', 'unsynced' ) ) ), array( 'pattern_id' ) ), 'output_schema' => self::pattern_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'update_synced_pattern' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_write( true, true ) ) );
		wp_register_ability( 'wp-mcp/delete-synced-pattern', array( 'label' => __( 'Delete Synced Pattern', 'wordpress-mcp-abilities' ), 'description' => __( 'Permanently delete one database-backed synced block pattern.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'pattern_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'pattern_id' ) ), 'output_schema' => self::deleted_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'delete_synced_pattern' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_write( true, false ) ) );
	}

	private static function register_navigation_block_abilities() {
		wp_register_ability( 'wp-mcp/list-navigation-blocks', array( 'label' => __( 'List Block Navigations', 'wordpress-mcp-abilities' ), 'description' => __( 'List persisted wp_navigation entities used by block themes.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( WP_MCP_Ability_Schema::pagination_input_properties(), array() ), 'output_schema' => self::list_schema( 'navigations', self::navigation_schema() ), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'list_navigation_blocks' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_readonly() ) );
		wp_register_ability( 'wp-mcp/get-navigation-block', array( 'label' => __( 'Get Block Navigation', 'wordpress-mcp-abilities' ), 'description' => __( 'Get one persisted wp_navigation entity.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'navigation_id' => self::positive_id() ), array( 'navigation_id' ) ), 'output_schema' => self::navigation_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'get_navigation_block' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_readonly() ) );
		wp_register_ability( 'wp-mcp/create-navigation-block', array( 'label' => __( 'Create Block Navigation', 'wordpress-mcp-abilities' ), 'description' => __( 'Create a persisted wp_navigation entity for block-theme navigation.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'title' => array( 'type' => 'string', 'minLength' => 1 ), 'content' => array( 'type' => 'string', 'minLength' => 1 ) ), array( 'title', 'content' ) ), 'output_schema' => self::navigation_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'create_navigation_block' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_write( false, false ) ) );
		wp_register_ability( 'wp-mcp/update-navigation-block', array( 'label' => __( 'Update Block Navigation', 'wordpress-mcp-abilities' ), 'description' => __( 'Update the title or block content of a persisted wp_navigation entity.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'navigation_id' => self::positive_id(), 'title' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ) ), array( 'navigation_id' ) ), 'output_schema' => self::navigation_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'update_navigation_block' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_write( true, true ) ) );
		wp_register_ability( 'wp-mcp/delete-navigation-block', array( 'label' => __( 'Delete Block Navigation', 'wordpress-mcp-abilities' ), 'description' => __( 'Permanently delete one persisted wp_navigation entity.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'navigation_id' => self::positive_id() ), array( 'navigation_id' ) ), 'output_schema' => self::deleted_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'delete_navigation_block' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_write( true, false ) ) );
	}

	private static function register_context_abilities() {
		wp_register_ability( 'wp-mcp/get-theme-context', array( 'label' => __( 'Get Theme Context', 'wordpress-mcp-abilities' ), 'description' => __( 'Report active-theme support and registered locations without reading theme files.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::empty_schema(), 'output_schema' => self::theme_context_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'get_theme_context' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_readonly() ) );
		wp_register_ability( 'wp-mcp/get-global-styles', array( 'label' => __( 'Get Global Styles', 'wordpress-mcp-abilities' ), 'description' => __( 'Read persisted user global styles when the active installation exposes the core API.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::empty_schema(), 'output_schema' => self::global_styles_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'get_global_styles' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_readonly() ) );
		wp_register_ability( 'wp-mcp/update-global-styles', array( 'label' => __( 'Update Global Styles', 'wordpress-mcp-abilities' ), 'description' => __( 'Update only explicit settings or styles branches through the core global-styles entity.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::object_schema( array( 'settings' => array( 'type' => 'object' ), 'styles' => array( 'type' => 'object' ) ), array() ), 'output_schema' => self::global_styles_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'update_global_styles' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_write( true, true ) ) );
		wp_register_ability( 'wp-mcp/list-widget-areas', array( 'label' => __( 'List Widget Areas', 'wordpress-mcp-abilities' ), 'description' => __( 'List registered widget areas. Widget CRUD is withheld because classic widget options have no stable core entity API.', 'wordpress-mcp-abilities' ), 'category' => 'wp-mcp-site-editor', 'input_schema' => self::empty_schema(), 'output_schema' => self::widget_areas_schema(), 'execute_callback' => array( 'WP_MCP_Site_Editor', 'list_widget_areas' ), 'permission_callback' => self::manage_callback(), 'meta' => WP_MCP_Ability_Schema::meta_readonly() ) );
	}

	private static function manage_callback() { return function () { return current_user_can( 'edit_theme_options' ); }; }
	private static function positive_id() { return array( 'type' => 'integer', 'minimum' => 1 ); }
	private static function empty_schema() { return self::object_schema( array(), array() ); }
	private static function object_schema( $properties, $required ) { return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ); }
	private static function list_schema( $key, $schema ) { return self::object_schema( array_merge( array( $key => array( 'type' => 'array', 'items' => $schema ) ), WP_MCP_Ability_Schema::pagination_output_properties() ), array() ); }
	private static function template_list_schema() { return self::list_schema( 'templates', self::template_schema() ); }
	private static function template_schema() { return self::object_schema( array( 'id' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'theme' => array( 'type' => 'string' ), 'type' => array( 'type' => 'string' ), 'source' => array( 'type' => 'string' ), 'origin' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string' ), 'wp_id' => array( 'type' => 'integer' ), 'has_theme_file' => array( 'type' => 'boolean' ), 'content' => array( 'type' => 'string' ), 'modified' => array( 'type' => 'string' ), 'area' => array( 'type' => 'string' ) ), array() ); }
	private static function pattern_schema() { return self::object_schema( array( 'id' => array( 'type' => array( 'string', 'integer' ) ), 'source' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string' ), 'sync_status' => array( 'type' => 'string' ), 'modified' => array( 'type' => 'string' ) ), array() ); }
	private static function navigation_schema() { return self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string' ), 'author' => array( 'type' => 'integer' ), 'modified' => array( 'type' => 'string' ) ), array() ); }
	private static function theme_context_schema() { return self::object_schema( array( 'name' => array( 'type' => 'string' ), 'stylesheet' => array( 'type' => 'string' ), 'is_block_theme' => array( 'type' => 'boolean' ), 'template_editing' => array( 'type' => 'boolean' ), 'menu_locations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'global_styles_available' => array( 'type' => 'boolean' ) ), array() ); }
	private static function global_styles_schema() { return self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'theme' => array( 'type' => 'string' ), 'settings' => array( 'type' => 'object' ), 'styles' => array( 'type' => 'object' ) ), array() ); }
	private static function widget_areas_schema() { return self::object_schema( array( 'areas' => array( 'type' => 'array', 'items' => self::widget_area_schema() ), 'total' => array( 'type' => 'integer' ), 'write_support' => array( 'type' => 'boolean' ) ), array() ); }
	private static function widget_area_schema() { return self::object_schema( array( 'id' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'class' => array( 'type' => 'string' ) ), array() ); }
	private static function deleted_schema() { return self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'deleted' => array( 'type' => 'boolean' ) ), array() ); }
}
