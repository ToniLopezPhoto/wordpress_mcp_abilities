<?php
/**
 * WordPress MCP Abilities — Network inspection and updates registration.
 *
 * Every network ability is registered on single-site installations too, so
 * the permission matrix and the registered surface can be verified against
 * each other in either environment. What changes off a network is the
 * permission callback (false, so the tool is never offered) and the callback
 * result (`wp_mcp_network_unsupported`).
 *
 * `wp-mcp/get-network-info` is the deliberate exception: it answers on both,
 * reporting `is_multisite: false`, so an agent can discover the shape of the
 * installation before trying anything else.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network_Abilities
 */
class WP_MCP_Network_Abilities {

	/** Register the network inspection and update abilities. */
	public static function register() {
		wp_register_ability( 'wp-mcp/get-network-info', array(
			'label'       => __( 'Get Network Info', 'wordpress-mcp-abilities' ),
			'description' => __( 'Describe the multisite network: identity, addressing mode, site and user counts, and whether the current user holds Super Admin. Answers on single-site installations too, reporting is_multisite = false.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'is_multisite'                => array( 'type' => 'boolean' ),
				'network_id'                  => array( 'type' => 'integer' ),
				'network_name'                => array( 'type' => 'string' ),
				'network_home_url'            => array( 'type' => 'string' ),
				'network_site_url'            => array( 'type' => 'string' ),
				'subdomain_install'           => array( 'type' => 'boolean' ),
				'main_site_id'                => array( 'type' => 'integer' ),
				'current_site_id'             => array( 'type' => 'integer' ),
				'site_count'                  => array( 'type' => 'integer' ),
				'user_count'                  => array( 'type' => 'integer' ),
				'current_user_is_super_admin' => array( 'type' => 'boolean' ),
				'upgrade_required'            => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Network', 'get_network_info' ),
			'permission_callback' => function () {
				return is_multisite() ? current_user_can( 'manage_network' ) : current_user_can( 'manage_options' );
			},
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-network-update-status', array(
			'label'       => __( 'Get Network Update Status', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report whether the network still needs the per-site database upgrade that follows a core update, plus the network-wide core, plugin and theme update counts.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'upgrade_required'        => array( 'type' => 'boolean' ),
				'wp_db_version'           => array( 'type' => 'integer' ),
				'network_db_version'      => array( 'type' => 'integer' ),
				'sites_total'             => array( 'type' => 'integer' ),
				'core_update_available'   => array( 'type' => 'boolean' ),
				'plugin_updates'          => array( 'type' => 'integer' ),
				'theme_updates'           => array( 'type' => 'integer' ),
				'network_activated_count' => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Network', 'get_network_update_status' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/upgrade-network-sites', array(
			'label'       => __( 'Upgrade Network Sites', 'wordpress-mcp-abilities' ),
			'description' => __( 'Run WordPress\'s own per-site database upgrade across a bounded batch of network sites. Idempotent: a site already at the current database version is skipped by core itself. Page through the network with offset until remaining is zero.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'max_sites' => array( 'type' => 'integer', 'description' => 'Sites to upgrade in this call (1-50, default 25).', 'minimum' => 1, 'maximum' => 50, 'default' => 25 ),
				'offset'    => array( 'type' => 'integer', 'description' => 'Number of sites to skip, for paging through a large network.', 'minimum' => 0, 'default' => 0 ),
			), array() ),
			'output_schema' => self::object_schema( array(
				'upgraded'           => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'upgraded_count'     => array( 'type' => 'integer' ),
				'offset'             => array( 'type' => 'integer' ),
				'next_offset'        => array( 'type' => 'integer' ),
				'remaining'          => array( 'type' => 'integer' ),
				'sites_total'        => array( 'type' => 'integer' ),
				'network_db_version' => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Network', 'upgrade_network_sites' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'upgrade_network' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );
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
