<?php
/**
 * WordPress MCP Abilities — Network plugins and themes registration.
 *
 * Installing, updating and deleting plugins and themes are deliberately not
 * duplicated here: on a multisite network WordPress already restricts
 * `install_plugins` / `update_plugins` / `delete_plugins` and their theme
 * counterparts to Super Admins, so the issue #9 abilities already are the
 * network-level operations.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network_Extensions_Abilities
 */
class WP_MCP_Network_Extensions_Abilities {

	/** Register every network plugin and theme ability. */
	public static function register() {
		self::register_plugins();
		self::register_themes();
	}

	/** Network plugin activation. */
	private static function register_plugins() {
		wp_register_ability( 'wp-mcp/network-activate-plugin', array(
			'label'       => __( 'Network Activate Plugin', 'wordpress-mcp-abilities' ),
			'description' => __( 'Activate an installed plugin across every site of the network. Idempotent: a plugin that is already network-active is reported as-is.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'        => self::object_schema( array( 'plugin_file' => self::plugin_file_property() ), array( 'plugin_file' ) ),
			'output_schema'       => self::plugin_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Extensions', 'network_activate_plugin' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );

		wp_register_ability( 'wp-mcp/network-deactivate-plugin', array(
			'label'       => __( 'Network Deactivate Plugin', 'wordpress-mcp-abilities' ),
			'description' => __( 'Deactivate a network-activated plugin across the network. Idempotent: a plugin that is not network-active is a no-op success. Per-site activations are untouched.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'        => self::object_schema( array( 'plugin_file' => self::plugin_file_property() ), array( 'plugin_file' ) ),
			'output_schema'       => self::plugin_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Extensions', 'network_deactivate_plugin' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/** Network theme availability. */
	private static function register_themes() {
		wp_register_ability( 'wp-mcp/list-network-themes', array(
			'label'       => __( 'List Network Themes', 'wordpress-mcp-abilities' ),
			'description' => __( 'List installed themes with their network-enabled state, with bounded pagination and optional name search and enabled filter.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'search'          => array( 'type' => 'string', 'description' => 'Match against the theme name.' ),
				'network_enabled' => array( 'type' => 'boolean', 'description' => 'Restrict to themes that are, or are not, enabled for the network.' ),
			) + WP_MCP_Ability_Schema::pagination_input_properties(), array() ),
			'output_schema' => self::object_schema( array(
				'themes' => array( 'type' => 'array', 'items' => self::theme_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties(), array() ),
			'execute_callback'    => array( 'WP_MCP_Network_Extensions', 'list_network_themes' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/network-enable-theme', array(
			'label'       => __( 'Network Enable Theme', 'wordpress-mcp-abilities' ),
			'description' => __( 'Make an installed theme selectable by every site of the network. Idempotent. Enabling a theme never switches any site to it.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'        => self::object_schema( array( 'stylesheet' => self::stylesheet_property() ), array( 'stylesheet' ) ),
			'output_schema'       => self::theme_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Extensions', 'network_enable_theme' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );

		wp_register_ability( 'wp-mcp/network-disable-theme', array(
			'label'       => __( 'Network Disable Theme', 'wordpress-mcp-abilities' ),
			'description' => __( 'Stop offering a theme to the sites of the network. Idempotent. Refused for the theme currently active on the main site.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'        => self::object_schema( array( 'stylesheet' => self::stylesheet_property() ), array( 'stylesheet' ) ),
			'output_schema'       => self::theme_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Extensions', 'network_disable_theme' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_themes' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function plugin_file_property() {
		return array( 'type' => 'string', 'description' => 'Plugin file relative to the plugins directory, e.g. "hello-dolly/hello.php".', 'minLength' => 1 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function stylesheet_property() {
		return array( 'type' => 'string', 'description' => 'Installed theme directory name.', 'minLength' => 1, 'maxLength' => 100 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function plugin_schema() {
		return self::object_schema( array(
			'file'                   => array( 'type' => 'string' ),
			'name'                   => array( 'type' => 'string' ),
			'version'                => array( 'type' => 'string' ),
			'network_active'         => array( 'type' => 'boolean' ),
			'active_on_current_site' => array( 'type' => 'boolean' ),
		), array() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function theme_schema() {
		return self::object_schema( array(
			'stylesheet'          => array( 'type' => 'string' ),
			'name'                => array( 'type' => 'string' ),
			'version'             => array( 'type' => 'string' ),
			'network_enabled'     => array( 'type' => 'boolean' ),
			'active_on_main_site' => array( 'type' => 'boolean' ),
		), array() );
	}

	/**
	 * @param array $properties Schema properties.
	 * @param array $required   Required property names.
	 * @return array<string,mixed>
	 */
	private static function object_schema( $properties, $required ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}
}
