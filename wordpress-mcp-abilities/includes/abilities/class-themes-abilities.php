<?php
/**
 * WordPress MCP Abilities — Themes domain registration.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Themes_Abilities
 */
class WP_MCP_Themes_Abilities {

	/** Register all theme management abilities. */
	public static function register() {
		self::register_theme_reads();
		self::register_theme_switch();
		self::register_theme_install();
		self::register_theme_update();
		self::register_theme_auto_update();
		self::register_theme_delete();
	}

	private static function register_theme_reads() {
		wp_register_ability( 'wp-mcp/list-themes', array(
			'label'       => __( 'List Themes', 'wordpress-mcp-abilities' ),
			'description' => __( 'List installed themes with bounded pagination and optional name search.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema' => self::object_schema( array(
				'search' => array( 'type' => 'string' ),
			) + WP_MCP_Ability_Schema::pagination_input_properties(), array() ),
			'output_schema' => self::object_schema( array(
				'themes' => array( 'type' => 'array', 'items' => self::theme_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties(), array() ),
			'execute_callback'    => array( 'WP_MCP_Themes', 'list_themes' ),
			'permission_callback' => function () { return current_user_can( 'switch_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-theme', array(
			'label'       => __( 'Get Theme', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one installed theme by its stylesheet directory name.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema' => self::object_schema( array( 'stylesheet' => self::stylesheet_property() ), array( 'stylesheet' ) ),
			'output_schema'       => self::theme_schema(),
			'execute_callback'    => array( 'WP_MCP_Themes', 'get_theme' ),
			'permission_callback' => function () { return current_user_can( 'switch_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_theme_switch() {
		wp_register_ability( 'wp-mcp/switch-theme', array(
			'label'       => __( 'Switch Theme', 'wordpress-mcp-abilities' ),
			'description' => __( 'Activate an installed theme as the site\'s current theme. Switching to the already-active theme is a no-op.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema' => self::object_schema( array( 'stylesheet' => self::stylesheet_property() ), array( 'stylesheet' ) ),
			'output_schema'       => self::theme_schema(),
			'execute_callback'    => array( 'WP_MCP_Themes', 'switch_theme' ),
			'permission_callback' => function () { return current_user_can( 'switch_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );
	}

	private static function register_theme_install() {
		wp_register_ability( 'wp-mcp/install-theme-from-repo', array(
			'label'       => __( 'Install Theme from Repository', 'wordpress-mcp-abilities' ),
			'description' => __( 'Install a theme by slug from the official WordPress.org repository. Never switches to the installed theme.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema' => self::object_schema( array(
				'slug' => array( 'type' => 'string', 'description' => 'WordPress.org theme slug, e.g. "twentytwentyfour".' ),
			), array( 'slug' ) ),
			'output_schema'       => self::theme_schema(),
			'execute_callback'    => array( 'WP_MCP_Themes', 'install_theme_from_repo' ),
			'permission_callback' => function () { return current_user_can( 'install_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/install-theme-from-url', array(
			'label'       => __( 'Install Theme from URL', 'wordpress-mcp-abilities' ),
			'description' => __( 'Safely download a theme zip from an explicit HTTP(S) URL and install it, with explicit host policy and SSRF protections. Never switches to the installed theme.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema' => self::object_schema( self::remote_install_properties(), array( 'url' ) ),
			'output_schema'       => self::theme_schema(),
			'execute_callback'    => array( 'WP_MCP_Themes', 'install_theme_from_url' ),
			'permission_callback' => function () { return current_user_can( 'install_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );
	}

	private static function register_theme_update() {
		wp_register_ability( 'wp-mcp/update-theme', array(
			'label'       => __( 'Update Theme', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update an installed theme to the latest available version from its source. Relies on WordPress core\'s own automatic backup-and-restore-on-failure for single-theme upgrades (available since WP 6.3).', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema' => self::object_schema( array( 'stylesheet' => self::stylesheet_property() ), array( 'stylesheet' ) ),
			'output_schema'       => self::theme_schema(),
			'execute_callback'    => array( 'WP_MCP_Themes', 'update_theme' ),
			'permission_callback' => function () { return current_user_can( 'update_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/update-themes', array(
			'label'       => __( 'Update Selected Themes', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update an explicit list of installed themes (up to 20) in one call. Relies on WordPress core\'s own automatic backup-and-restore-on-failure for theme upgrades (available since WP 6.3).', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema' => self::object_schema( array(
				'stylesheets' => array(
					'type'        => 'array',
					'description' => 'Installed theme directory names to update, e.g. ["twentytwentyfour"].',
					'items'       => self::stylesheet_property(),
					'minItems'    => 1,
					'maxItems'    => 20,
				),
			), array( 'stylesheets' ) ),
			'output_schema'       => self::bulk_update_schema(),
			'execute_callback'    => array( 'WP_MCP_Themes', 'update_themes' ),
			'permission_callback' => function () { return current_user_can( 'update_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/update-all-themes', array(
			'label'       => __( 'Update All Themes', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update every installed theme that currently has an update available (up to 50 in one call). Deliberately separate from update-themes so that updating everything is always an explicit choice.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema'        => self::object_schema( array(), array() ),
			'output_schema'       => self::bulk_update_schema(),
			'execute_callback'    => array( 'WP_MCP_Themes', 'update_all_themes' ),
			'permission_callback' => function () { return current_user_can( 'update_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function register_theme_auto_update() {
		wp_register_ability( 'wp-mcp/set-theme-auto-update', array(
			'label'       => __( 'Set Theme Auto-Update', 'wordpress-mcp-abilities' ),
			'description' => __( 'Opt one installed theme in or out of WordPress auto-updates. Setting the state it already has is a no-op. On multisite this is a network-wide setting and requires a network administrator.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema' => self::object_schema( array(
				'stylesheet' => self::stylesheet_property(),
				'enabled'    => array( 'type' => 'boolean', 'description' => 'Whether WordPress should auto-update this theme.' ),
			), array( 'stylesheet', 'enabled' ) ),
			'output_schema' => self::object_schema( array(
				'stylesheet'  => array( 'type' => 'string' ),
				'auto_update' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Themes', 'set_theme_auto_update' ),
			'permission_callback' => function () { return current_user_can( 'update_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_theme_delete() {
		wp_register_ability( 'wp-mcp/delete-theme', array(
			'label'       => __( 'Delete Theme', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete an installed theme\'s files. Refuses to delete the currently active theme (or its active child theme\'s parent).', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-themes',
			'input_schema' => self::object_schema( array( 'stylesheet' => self::stylesheet_property() ), array( 'stylesheet' ) ),
			'output_schema' => self::object_schema( array(
				'stylesheet' => array( 'type' => 'string' ),
				'deleted'    => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Themes', 'delete_theme' ),
			'permission_callback' => function () { return current_user_can( 'delete_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function bulk_update_schema() {
		return self::object_schema( array(
			'results'   => array(
				'type'  => 'array',
				'items' => self::object_schema( array(
					'stylesheet'   => array( 'type' => 'string' ),
					'from_version' => array( 'type' => 'string' ),
					'to_version'   => array( 'type' => 'string' ),
					'updated'      => array( 'type' => 'boolean' ),
					'error'        => array( 'type' => 'string' ),
				), array() ),
			),
			'requested' => array( 'type' => 'integer' ),
			'updated'   => array( 'type' => 'integer' ),
		), array() );
	}

	private static function stylesheet_property() {
		return array( 'type' => 'string', 'description' => 'Theme directory name (stylesheet), e.g. "twentytwentyfour".', 'minLength' => 1 );
	}

	private static function remote_install_properties() {
		return array(
			'url'           => array( 'type' => 'string', 'description' => 'HTTP(S) URL of the theme zip to download.' ),
			'allowed_hosts' => array( 'type' => 'array', 'description' => 'Optional exact hosts (and subdomains) allowed for this request.', 'items' => array( 'type' => 'string', 'minLength' => 1 ), 'maxItems' => 20 ),
			'deny_hosts'    => array( 'type' => 'array', 'description' => 'Optional exact hosts (and subdomains) denied for this request.', 'items' => array( 'type' => 'string', 'minLength' => 1 ), 'maxItems' => 20 ),
		);
	}

	private static function theme_schema() {
		return self::object_schema( array(
			'stylesheet'       => array( 'type' => 'string' ),
			'name'             => array( 'type' => 'string' ),
			'version'          => array( 'type' => 'string' ),
			'description'      => array( 'type' => 'string' ),
			'author'           => array( 'type' => 'string' ),
			'theme_uri'        => array( 'type' => 'string' ),
			'requires_wp'      => array( 'type' => 'string' ),
			'requires_php'     => array( 'type' => 'string' ),
			'parent_theme'     => array( 'type' => 'string' ),
			'active'           => array( 'type' => 'boolean' ),
			'update_available' => array( 'type' => 'boolean' ),
		), array() );
	}

	private static function object_schema( $properties, $required ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}
}
