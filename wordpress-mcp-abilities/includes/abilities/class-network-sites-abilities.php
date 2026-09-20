<?php
/**
 * WordPress MCP Abilities — Network sites domain registration.
 *
 * The input schemas are the first gate of this domain's security model:
 * no ability accepts a domain, a path, a URL or a filesystem location. A
 * site is addressed by `site_id` and created from a `slug` the plugin
 * expands using the network's own configuration.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network_Sites_Abilities
 */
class WP_MCP_Network_Sites_Abilities {

	/** Register every network site ability. */
	public static function register() {
		self::register_reads();
		self::register_lifecycle();
		self::register_status_flags();
		self::register_delete();
	}

	/** List and read. */
	private static function register_reads() {
		wp_register_ability( 'wp-mcp/list-network-sites', array(
			'label'       => __( 'List Network Sites', 'wordpress-mcp-abilities' ),
			'description' => __( 'List the sites of the multisite network with bounded pagination, an optional search and an optional status filter.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'search' => array( 'type' => 'string', 'description' => 'Match against the site domain and path.' ),
				'status' => array(
					'type'        => 'string',
					'description' => 'Status filter.',
					'enum'        => WP_MCP_Network_Sites::STATUSES,
					'default'     => 'all',
				),
			) + WP_MCP_Ability_Schema::pagination_input_properties(), array() ),
			'output_schema' => self::object_schema( array(
				'sites' => array( 'type' => 'array', 'items' => self::site_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties(), array() ),
			'execute_callback'    => array( 'WP_MCP_Network_Sites', 'list_network_sites' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_sites' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-network-site', array(
			'label'       => __( 'Get Network Site', 'wordpress-mcp-abilities' ),
			'description' => __( 'Read one site of the network by its ID.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'        => self::object_schema( array( 'site_id' => self::site_id_property() ), array( 'site_id' ) ),
			'output_schema'       => self::site_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Sites', 'get_network_site' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_sites' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/** Create and update. */
	private static function register_lifecycle() {
		wp_register_ability( 'wp-mcp/create-network-site', array(
			'label'       => __( 'Create Network Site', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a site on the network from a slug. The domain and path are derived from the network\'s own configuration — a full domain is never accepted — and the administrator must be an existing user; no user is created implicitly.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'slug'          => array( 'type' => 'string', 'description' => 'Subdomain label or subdirectory name: lowercase letters, digits and hyphens.', 'minLength' => 1, 'maxLength' => 63 ),
				'title'         => array( 'type' => 'string', 'description' => 'Title of the new site.', 'minLength' => 1, 'maxLength' => 200 ),
				'admin_user_id' => array( 'type' => 'integer', 'description' => 'Existing user who becomes the site administrator.', 'minimum' => 1 ),
				'public'        => array( 'type' => 'boolean', 'description' => 'Whether search engines may index the site. Defaults to true.' ),
			), array( 'slug', 'title', 'admin_user_id' ) ),
			'output_schema'       => self::site_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Sites', 'create_network_site' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'create_sites' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/update-network-site', array(
			'label'       => __( 'Update Network Site', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update the supported metadata of a site: its title, its public flag and its mature flag. The domain and path are never writable — moving a live site to another address is a migration, not a metadata edit.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'site_id' => self::site_id_property(),
				'title'   => array( 'type' => 'string', 'description' => 'New site title.', 'minLength' => 1, 'maxLength' => 200 ),
				'public'  => array( 'type' => 'boolean', 'description' => 'Whether search engines may index the site.' ),
				'mature'  => array( 'type' => 'boolean', 'description' => 'Whether the site is flagged as mature.' ),
			), array( 'site_id' ) ),
			'output_schema'       => self::site_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Sites', 'update_network_site' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_sites' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/** Archive / activate / spam pairs. */
	private static function register_status_flags() {
		$flags = array(
			'archive-network-site'      => array(
				'label'       => __( 'Archive Network Site', 'wordpress-mcp-abilities' ),
				'description' => __( 'Archive a site: it stops serving visitors but keeps all of its data. Refused for the main site of the network and for the site the request is running on.', 'wordpress-mcp-abilities' ),
				'callback'    => 'archive_network_site',
			),
			'unarchive-network-site'    => array(
				'label'       => __( 'Unarchive Network Site', 'wordpress-mcp-abilities' ),
				'description' => __( 'Clear the archived flag of a site.', 'wordpress-mcp-abilities' ),
				'callback'    => 'unarchive_network_site',
			),
			'activate-network-site'     => array(
				'label'       => __( 'Activate Network Site', 'wordpress-mcp-abilities' ),
				'description' => __( 'Activate a deactivated site, clearing its deleted flag. Nothing is restored from a backup: the site\'s data was never removed.', 'wordpress-mcp-abilities' ),
				'callback'    => 'activate_network_site',
			),
			'deactivate-network-site'   => array(
				'label'       => __( 'Deactivate Network Site', 'wordpress-mcp-abilities' ),
				'description' => __( 'Deactivate a site by setting its deleted flag, as Network Admin\'s Deactivate does. The site\'s data is untouched; wp-mcp/delete-network-site is the separate destructive operation. Refused for the main site and for the current site.', 'wordpress-mcp-abilities' ),
				'callback'    => 'deactivate_network_site',
			),
			'mark-network-site-spam'    => array(
				'label'       => __( 'Mark Network Site as Spam', 'wordpress-mcp-abilities' ),
				'description' => __( 'Flag a site as spam. Refused for the main site and for the current site.', 'wordpress-mcp-abilities' ),
				'callback'    => 'mark_network_site_spam',
			),
			'unmark-network-site-spam'  => array(
				'label'       => __( 'Unmark Network Site as Spam', 'wordpress-mcp-abilities' ),
				'description' => __( 'Clear the spam flag of a site.', 'wordpress-mcp-abilities' ),
				'callback'    => 'unmark_network_site_spam',
			),
		);

		foreach ( $flags as $slug => $definition ) {
			wp_register_ability( 'wp-mcp/' . $slug, array(
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => 'wp-mcp-network',
				'input_schema'        => self::object_schema( array( 'site_id' => self::site_id_property() ), array( 'site_id' ) ),
				'output_schema'       => self::site_schema(),
				'execute_callback'    => array( 'WP_MCP_Network_Sites', $definition['callback'] ),
				'permission_callback' => function () { return WP_MCP_Network::can( 'manage_sites' ); },
				'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
			) );
		}
	}

	/** Permanent deletion. */
	private static function register_delete() {
		wp_register_ability( 'wp-mcp/delete-network-site', array(
			'label'       => __( 'Delete Network Site', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete a site of the network, including its database tables and its uploads. Refused for the main site of the network and for the site the request is running on. Consider wp-mcp/deactivate-network-site, which is reversible.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array( 'site_id' => self::site_id_property() ), array( 'site_id' ) ),
			'output_schema' => self::object_schema( array(
				'id'      => array( 'type' => 'integer' ),
				'domain'  => array( 'type' => 'string' ),
				'path'    => array( 'type' => 'string' ),
				'deleted' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Network_Sites', 'delete_network_site' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'delete_sites' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function site_id_property() {
		return array( 'type' => 'integer', 'description' => 'Site ID within the current network.', 'minimum' => 1 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function site_schema() {
		return self::object_schema( array(
			'id'               => array( 'type' => 'integer' ),
			'network_id'       => array( 'type' => 'integer' ),
			'domain'           => array( 'type' => 'string' ),
			'path'             => array( 'type' => 'string' ),
			'url'              => array( 'type' => 'string' ),
			'name'             => array( 'type' => 'string' ),
			'registered_gmt'   => array( 'type' => 'string' ),
			'last_updated_gmt' => array( 'type' => 'string' ),
			'public'           => array( 'type' => 'boolean' ),
			'archived'         => array( 'type' => 'boolean' ),
			'mature'           => array( 'type' => 'boolean' ),
			'spam'             => array( 'type' => 'boolean' ),
			'deleted'          => array( 'type' => 'boolean' ),
			'is_main_site'     => array( 'type' => 'boolean' ),
			'post_count'       => array( 'type' => 'integer' ),
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
