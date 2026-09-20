<?php
/**
 * WordPress MCP Abilities — Plugins domain registration.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Plugins_Abilities
 */
class WP_MCP_Plugins_Abilities {

	/** Register all plugin management abilities. */
	public static function register() {
		self::register_plugin_reads();
		self::register_plugin_activation();
		self::register_plugin_install();
		self::register_plugin_update();
		self::register_plugin_auto_update();
		self::register_plugin_delete();
	}

	private static function register_plugin_reads() {
		wp_register_ability( 'wp-mcp/list-plugins', array(
			'label'       => __( 'List Plugins', 'wordpress-mcp-abilities' ),
			'description' => __( 'List installed plugins with bounded pagination and optional name search.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( array(
				'search' => array( 'type' => 'string' ),
			) + WP_MCP_Ability_Schema::pagination_input_properties(), array() ),
			'output_schema' => self::object_schema( array(
				'plugins' => array( 'type' => 'array', 'items' => self::plugin_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties(), array() ),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'list_plugins' ),
			'permission_callback' => function () { return current_user_can( 'activate_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-plugin', array(
			'label'       => __( 'Get Plugin', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one installed plugin by its file path.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( array( 'plugin_file' => self::plugin_file_property() ), array( 'plugin_file' ) ),
			'output_schema'       => self::plugin_schema(),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'get_plugin' ),
			'permission_callback' => function () { return current_user_can( 'activate_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_plugin_activation() {
		wp_register_ability( 'wp-mcp/activate-plugin', array(
			'label'       => __( 'Activate Plugin', 'wordpress-mcp-abilities' ),
			'description' => __( 'Activate an installed plugin. Activating an already-active plugin is a no-op.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( array(
				'plugin_file'  => self::plugin_file_property(),
				'network_wide' => array( 'type' => 'boolean', 'description' => 'Activate network-wide (multisite network administrators only).' ),
			), array( 'plugin_file' ) ),
			'output_schema'       => self::plugin_schema(),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'activate_plugin' ),
			'permission_callback' => function () { return current_user_can( 'activate_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );

		wp_register_ability( 'wp-mcp/deactivate-plugin', array(
			'label'       => __( 'Deactivate Plugin', 'wordpress-mcp-abilities' ),
			'description' => __( 'Deactivate an installed plugin. Deactivating an already-inactive plugin is a no-op.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( array(
				'plugin_file'  => self::plugin_file_property(),
				'network_wide' => array( 'type' => 'boolean', 'description' => 'Deactivate network-wide (multisite only).' ),
			), array( 'plugin_file' ) ),
			'output_schema'       => self::plugin_schema(),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'deactivate_plugin' ),
			'permission_callback' => function () { return current_user_can( 'activate_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );
	}

	private static function register_plugin_install() {
		wp_register_ability( 'wp-mcp/install-plugin-from-repo', array(
			'label'       => __( 'Install Plugin from Repository', 'wordpress-mcp-abilities' ),
			'description' => __( 'Install a plugin by slug from the official WordPress.org repository. Never activates the plugin.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( array(
				'slug' => array( 'type' => 'string', 'description' => 'WordPress.org plugin slug, e.g. "hello-dolly".' ),
			), array( 'slug' ) ),
			'output_schema'       => self::plugin_schema(),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'install_plugin_from_repo' ),
			'permission_callback' => function () { return current_user_can( 'install_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/install-plugin-from-url', array(
			'label'       => __( 'Install Plugin from URL', 'wordpress-mcp-abilities' ),
			'description' => __( 'Safely download a plugin zip from an explicit HTTP(S) URL and install it, with explicit host policy and SSRF protections. Never activates the plugin.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( self::remote_install_properties(), array( 'url' ) ),
			'output_schema'       => self::plugin_schema(),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'install_plugin_from_url' ),
			'permission_callback' => function () { return current_user_can( 'install_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );
	}

	private static function register_plugin_update() {
		wp_register_ability( 'wp-mcp/update-plugin', array(
			'label'       => __( 'Update Plugin', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update an installed plugin to the latest available version from its source. Relies on WordPress core\'s own automatic backup-and-restore-on-failure for single-plugin upgrades (available since WP 6.3).', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( array( 'plugin_file' => self::plugin_file_property() ), array( 'plugin_file' ) ),
			'output_schema'       => self::plugin_schema(),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'update_plugin' ),
			'permission_callback' => function () { return current_user_can( 'update_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/update-plugins', array(
			'label'       => __( 'Update Selected Plugins', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update an explicit list of installed plugins (up to 20) in one call. Relies on WordPress core\'s own automatic backup-and-restore-on-failure for plugin upgrades (available since WP 6.3).', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( array(
				'plugin_files' => array(
					'type'        => 'array',
					'description' => 'Installed plugin files to update, e.g. ["hello-dolly/hello.php"].',
					'items'       => self::plugin_file_property(),
					'minItems'    => 1,
					'maxItems'    => 20,
				),
			), array( 'plugin_files' ) ),
			'output_schema'       => self::bulk_update_schema(),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'update_plugins' ),
			'permission_callback' => function () { return current_user_can( 'update_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/update-all-plugins', array(
			'label'       => __( 'Update All Plugins', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update every installed plugin that currently has an update available (up to 50 in one call). Deliberately separate from update-plugins so that updating everything is always an explicit choice.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema'        => self::object_schema( array(), array() ),
			'output_schema'       => self::bulk_update_schema(),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'update_all_plugins' ),
			'permission_callback' => function () { return current_user_can( 'update_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function register_plugin_auto_update() {
		wp_register_ability( 'wp-mcp/set-plugin-auto-update', array(
			'label'       => __( 'Set Plugin Auto-Update', 'wordpress-mcp-abilities' ),
			'description' => __( 'Opt one installed plugin in or out of WordPress auto-updates. Setting the state it already has is a no-op. On multisite this is a network-wide setting and requires a network administrator.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( array(
				'plugin_file' => self::plugin_file_property(),
				'enabled'     => array( 'type' => 'boolean', 'description' => 'Whether WordPress should auto-update this plugin.' ),
			), array( 'plugin_file', 'enabled' ) ),
			'output_schema' => self::object_schema( array(
				'file'        => array( 'type' => 'string' ),
				'auto_update' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'set_plugin_auto_update' ),
			'permission_callback' => function () { return current_user_can( 'update_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_plugin_delete() {
		wp_register_ability( 'wp-mcp/delete-plugin', array(
			'label'       => __( 'Delete Plugin', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete an installed plugin\'s files. Refuses to delete an active plugin; deactivate it first.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-plugins',
			'input_schema' => self::object_schema( array( 'plugin_file' => self::plugin_file_property() ), array( 'plugin_file' ) ),
			'output_schema' => self::object_schema( array(
				'file'    => array( 'type' => 'string' ),
				'deleted' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Plugins', 'delete_plugin' ),
			'permission_callback' => function () { return current_user_can( 'delete_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function bulk_update_schema() {
		return self::object_schema( array(
			'results'   => array(
				'type'  => 'array',
				'items' => self::object_schema( array(
					'file'         => array( 'type' => 'string' ),
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

	private static function plugin_file_property() {
		return array( 'type' => 'string', 'description' => 'Plugin file relative to the plugins directory, e.g. "hello-dolly/hello.php".', 'minLength' => 1 );
	}

	private static function remote_install_properties() {
		return array(
			'url'           => array( 'type' => 'string', 'description' => 'HTTP(S) URL of the plugin zip to download.' ),
			'allowed_hosts' => array( 'type' => 'array', 'description' => 'Optional exact hosts (and subdomains) allowed for this request.', 'items' => array( 'type' => 'string', 'minLength' => 1 ), 'maxItems' => 20 ),
			'deny_hosts'    => array( 'type' => 'array', 'description' => 'Optional exact hosts (and subdomains) denied for this request.', 'items' => array( 'type' => 'string', 'minLength' => 1 ), 'maxItems' => 20 ),
		);
	}

	private static function plugin_schema() {
		return self::object_schema( array(
			'file'             => array( 'type' => 'string' ),
			'name'             => array( 'type' => 'string' ),
			'version'          => array( 'type' => 'string' ),
			'description'      => array( 'type' => 'string' ),
			'author'           => array( 'type' => 'string' ),
			'plugin_uri'       => array( 'type' => 'string' ),
			'requires_wp'      => array( 'type' => 'string' ),
			'requires_php'     => array( 'type' => 'string' ),
			'active'           => array( 'type' => 'boolean' ),
			'network_active'   => array( 'type' => 'boolean' ),
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
