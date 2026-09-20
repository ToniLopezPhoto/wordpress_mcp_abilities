<?php
/**
 * WordPress MCP Abilities — Network users domain registration.
 *
 * There is deliberately no grant-super-admin / revoke-super-admin ability:
 * granting the network's highest privilege is the one operation whose blast
 * radius is the entire network, and it stays a human decision made in
 * Network Admin (see `WP_MCP_Network_Users`).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network_Users_Abilities
 */
class WP_MCP_Network_Users_Abilities {

	/** Register every network user ability. */
	public static function register() {
		self::register_reads();
		self::register_account_lifecycle();
		self::register_memberships();
	}

	/** Network-wide reads. */
	private static function register_reads() {
		wp_register_ability( 'wp-mcp/list-network-users', array(
			'label'       => __( 'List Network Users', 'wordpress-mcp-abilities' ),
			'description' => __( 'List every user of the network, not only the users of the current site, with bounded pagination and an optional search. Password hashes, session tokens and application passwords are never returned.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'search' => array( 'type' => 'string', 'description' => 'Match against username, email and display name.' ),
			) + WP_MCP_Ability_Schema::pagination_input_properties(), array() ),
			'output_schema' => self::object_schema( array(
				'users' => array( 'type' => 'array', 'items' => self::user_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties(), array() ),
			'execute_callback'    => array( 'WP_MCP_Network_Users', 'list_network_users' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_users' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-network-user', array(
			'label'       => __( 'Get Network User', 'wordpress-mcp-abilities' ),
			'description' => __( 'Read one network user, including whether they hold Super Admin and how many sites they belong to.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'        => self::object_schema( array( 'user_id' => self::user_id_property() ), array( 'user_id' ) ),
			'output_schema'       => self::user_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Users', 'get_network_user' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_users' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-network-user-sites', array(
			'label'       => __( 'List Network User Sites', 'wordpress-mcp-abilities' ),
			'description' => __( 'List the sites a user belongs to across the network, with the roles they hold on each.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'user_id' => self::user_id_property(),
			) + WP_MCP_Ability_Schema::pagination_input_properties(), array( 'user_id' ) ),
			'output_schema' => self::object_schema( array(
				'user_id' => array( 'type' => 'integer' ),
				'sites'   => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'site_id' => array( 'type' => 'integer' ),
						'domain'  => array( 'type' => 'string' ),
						'path'    => array( 'type' => 'string' ),
						'url'     => array( 'type' => 'string' ),
						'name'    => array( 'type' => 'string' ),
						'roles'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					), array() ),
				),
			) + WP_MCP_Ability_Schema::pagination_output_properties(), array() ),
			'execute_callback'    => array( 'WP_MCP_Network_Users', 'list_network_user_sites' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_users' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/** Account creation and deletion. */
	private static function register_account_lifecycle() {
		wp_register_ability( 'wp-mcp/create-network-user', array(
			'label'       => __( 'Create Network User', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a user on the network without adding them to any site. The network\'s own illegal names and limited/banned email domains are enforced by WordPress\'s own signup validation. The password is never returned.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'username' => array( 'type' => 'string', 'description' => 'New username.', 'minLength' => 1, 'maxLength' => 60 ),
				'email'    => array( 'type' => 'string', 'description' => 'New user email address.', 'minLength' => 3, 'maxLength' => 100 ),
				'password' => array( 'type' => 'string', 'description' => 'Initial password, at least 8 characters. Never returned.', 'minLength' => 8, 'maxLength' => 255 ),
			), array( 'username', 'email', 'password' ) ),
			'output_schema'       => self::user_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Users', 'create_network_user' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_users' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/delete-network-user', array(
			'label'       => __( 'Delete Network User', 'wordpress-mcp-abilities' ),
			'description' => __( 'Delete a user from the whole network. A reassignment user is required: WordPress deletes every post a removed network user authored on every site, so this ability reassigns each site\'s content first. Super Admins and the current user are refused.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'user_id'          => self::user_id_property(),
				'reassign_user_id' => array( 'type' => 'integer', 'description' => 'Existing different user who inherits the deleted user\'s content on every site.', 'minimum' => 1 ),
			), array( 'user_id', 'reassign_user_id' ) ),
			'output_schema' => self::object_schema( array(
				'id'             => array( 'type' => 'integer' ),
				'deleted'        => array( 'type' => 'boolean' ),
				'reassigned_to'  => array( 'type' => 'integer' ),
				'sites_detached' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Network_Users', 'delete_network_user' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_users' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	/** Per-site membership and roles. */
	private static function register_memberships() {
		wp_register_ability( 'wp-mcp/add-user-to-network-site', array(
			'label'       => __( 'Add User to Network Site', 'wordpress-mcp-abilities' ),
			'description' => __( 'Add an existing network user to one site with an explicit role. Idempotent when the user already holds exactly that role; a different existing role is refused so a membership is never silently downgraded.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'site_id' => self::site_id_property(),
				'user_id' => self::user_id_property(),
				'role'    => self::role_property(),
			), array( 'site_id', 'user_id', 'role' ) ),
			'output_schema'       => self::membership_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Users', 'add_user_to_network_site' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_users' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );

		wp_register_ability( 'wp-mcp/remove-user-from-network-site', array(
			'label'       => __( 'Remove User from Network Site', 'wordpress-mcp-abilities' ),
			'description' => __( 'Remove a user from one site of the network. The account and every other membership are untouched. An optional reassignment user inherits the content the removed user authored on that site.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'site_id'          => self::site_id_property(),
				'user_id'          => self::user_id_property(),
				'reassign_user_id' => array( 'type' => 'integer', 'description' => 'Optional existing different user who inherits the removed user\'s content on that site.', 'minimum' => 1 ),
			), array( 'site_id', 'user_id' ) ),
			'output_schema' => self::object_schema( array(
				'site_id'       => array( 'type' => 'integer' ),
				'user_id'       => array( 'type' => 'integer' ),
				'removed'       => array( 'type' => 'boolean' ),
				'reassigned_to' => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Network_Users', 'remove_user_from_network_site' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_users' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/set-network-site-user-role', array(
			'label'       => __( 'Set Network Site User Role', 'wordpress-mcp-abilities' ),
			'description' => __( 'Set the complete role a user holds on one site of the network. The role must exist on that site and be editable by the current user: roles are per-site data, so a role present on one site need not exist on another.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-network',
			'input_schema'  => self::object_schema( array(
				'site_id' => self::site_id_property(),
				'user_id' => self::user_id_property(),
				'role'    => self::role_property(),
			), array( 'site_id', 'user_id', 'role' ) ),
			'output_schema'       => self::membership_schema(),
			'execute_callback'    => array( 'WP_MCP_Network_Users', 'set_network_site_user_role' ),
			'permission_callback' => function () { return WP_MCP_Network::can( 'manage_network_users' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function user_id_property() {
		return array( 'type' => 'integer', 'description' => 'Network user ID.', 'minimum' => 1 );
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
	private static function role_property() {
		return array( 'type' => 'string', 'description' => 'Role slug that exists on the target site.', 'minLength' => 1, 'maxLength' => 60 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function user_schema() {
		return self::object_schema( array(
			'id'             => array( 'type' => 'integer' ),
			'username'       => array( 'type' => 'string' ),
			'email'          => array( 'type' => 'string' ),
			'name'           => array( 'type' => 'string' ),
			'registered_gmt' => array( 'type' => 'string' ),
			'is_super_admin' => array( 'type' => 'boolean' ),
			'site_count'     => array( 'type' => 'integer' ),
		), array() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function membership_schema() {
		return self::object_schema( array(
			'site_id'   => array( 'type' => 'integer' ),
			'user_id'   => array( 'type' => 'integer' ),
			'site_name' => array( 'type' => 'string' ),
			'site_url'  => array( 'type' => 'string' ),
			'roles'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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
