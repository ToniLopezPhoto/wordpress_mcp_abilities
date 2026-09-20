<?php
/**
 * WordPress MCP Abilities — Users domain registration.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Users_Abilities
 */
class WP_MCP_Users_Abilities {

	/** Register all user, role, and application-password abilities. */
	public static function register() {
		self::register_user_reads();
		self::register_user_mutations();
		self::register_role_abilities();
		self::register_application_password_abilities();
	}

	private static function register_user_reads() {
		wp_register_ability( 'wp-mcp/list-users', array(
			'label' => __( 'List Users', 'wordpress-mcp-abilities' ),
			'description' => __( 'List users visible to the authenticated administrator with bounded pagination.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array(
				'search' => array( 'type' => 'string' ),
				'role' => array( 'type' => 'string' ),
			) + WP_MCP_Ability_Schema::pagination_input_properties(), array() ),
			'output_schema' => self::object_schema( array(
				'users' => array( 'type' => 'array', 'items' => self::user_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties(), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'list_users' ),
			'permission_callback' => function () { return current_user_can( 'list_users' ); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-user', array(
			'label' => __( 'Get User', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one user profile, subject to the native edit/list boundary. Authentication fields are never returned.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property() ), array( 'user_id' ) ),
			'output_schema' => self::user_schema( true ),
			'execute_callback' => array( 'WP_MCP_Users', 'get_user' ),
			'permission_callback' => function () { return current_user_can( 'read' ); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-current-user', array(
			'label' => __( 'Get Current User', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get the authenticated user profile without password or authentication material.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::user_schema( true ),
			'execute_callback' => array( 'WP_MCP_Users', 'get_current_user' ),
			'permission_callback' => function () { return is_user_logged_in(); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_user_mutations() {
		wp_register_ability( 'wp-mcp/create-user', array(
			'label' => __( 'Create User', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a user with explicit credentials. The supplied password is never returned or audited.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array(
				'username' => array( 'type' => 'string', 'minLength' => 1 ),
				'email' => array( 'type' => 'string', 'format' => 'email' ),
				'password' => array( 'type' => 'string', 'minLength' => 8 ),
				'role' => array( 'type' => 'string' ),
				'display_name' => array( 'type' => 'string' ),
				'first_name' => array( 'type' => 'string' ),
				'last_name' => array( 'type' => 'string' ),
			), array( 'username', 'email', 'password' ) ),
			'output_schema' => self::user_schema( true ),
			'execute_callback' => array( 'WP_MCP_Users', 'create_user' ),
			'permission_callback' => function () { return current_user_can( 'create_users' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/update-user', array(
			'label' => __( 'Update User Profile', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update only explicit profile fields: display name, email, URL, locale, first/last name, and description.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array(
				'user_id' => self::user_id_property(),
				'display_name' => array( 'type' => 'string' ),
				'email' => array( 'type' => 'string', 'format' => 'email' ),
				'url' => array( 'type' => 'string' ),
				'locale' => array( 'type' => 'string' ),
				'first_name' => array( 'type' => 'string' ),
				'last_name' => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
			), array( 'user_id' ) ),
			'output_schema' => self::user_schema( true ),
			'execute_callback' => array( 'WP_MCP_Users', 'update_user' ),
			'permission_callback' => function () { return current_user_can( 'read' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/set-user-password', array(
			'label' => __( 'Set User Password', 'wordpress-mcp-abilities' ),
			'description' => __( 'Set a user password explicitly. The password is never returned, persisted in logs, or exposed by read abilities.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property(), 'password' => array( 'type' => 'string', 'minLength' => 8 ) ), array( 'user_id', 'password' ) ),
			'output_schema' => self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'updated' => array( 'type' => 'boolean' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'set_user_password' ),
			'permission_callback' => function () { return current_user_can( 'read' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/delete-user', array(
			'label' => __( 'Delete User', 'wordpress-mcp-abilities' ),
			'description' => __( 'Delete one user only with an explicit existing reassignment target; implicit content deletion is refused.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property(), 'reassign_user_id' => self::user_id_property() ), array( 'user_id', 'reassign_user_id' ) ),
			'output_schema' => self::object_schema( array( 'id' => array( 'type' => 'integer' ), 'deleted' => array( 'type' => 'boolean' ), 'reassigned_to' => array( 'type' => 'integer' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'delete_user' ),
			'permission_callback' => function () { return current_user_can( 'delete_users' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/change-user-role', array(
			'label' => __( 'Change User Role', 'wordpress-mcp-abilities' ),
			'description' => __( 'Replace a user role only when the requested role is editable by WordPress and the native promote boundary allows it.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property(), 'role' => array( 'type' => 'string' ) ), array( 'user_id', 'role' ) ),
			'output_schema' => self::user_schema( true ),
			'execute_callback' => array( 'WP_MCP_Users', 'change_user_role' ),
			'permission_callback' => function () { return current_user_can( 'promote_users' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		foreach ( array( 'add-user-role' => 'add_user_role', 'remove-user-role' => 'remove_user_role' ) as $slug => $callback ) {
			wp_register_ability( 'wp-mcp/' . $slug, array(
				'label' => ucwords( str_replace( '-', ' ', $slug ) ),
				'description' => __( 'Add or remove one explicit WordPress role subject to native promotion and editable-role checks.', 'wordpress-mcp-abilities' ),
				'category' => 'wp-mcp-users',
				'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property(), 'role' => array( 'type' => 'string' ) ), array( 'user_id', 'role' ) ),
				'output_schema' => self::user_schema( true ),
				'execute_callback' => array( 'WP_MCP_Users', $callback ),
				'permission_callback' => function () { return current_user_can( 'promote_users' ); },
				'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
			) );
		}

		wp_register_ability( 'wp-mcp/list-user-capabilities', array(
			'label' => __( 'List User Capabilities', 'wordpress-mcp-abilities' ),
			'description' => __( 'List effective granted capabilities and roles without exposing user metadata or password material.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property() ), array( 'user_id' ) ),
			'output_schema' => self::object_schema( array( 'user_id' => array( 'type' => 'integer' ), 'roles' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'capabilities' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'list_user_capabilities' ),
			'permission_callback' => function () { return current_user_can( 'read' ); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_role_abilities() {
		wp_register_ability( 'wp-mcp/list-roles', array(
			'label' => __( 'List Roles', 'wordpress-mcp-abilities' ),
			'description' => __( 'List registered WordPress roles and granted capability names for administrators.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array( 'roles' => array( 'type' => 'array', 'items' => self::role_schema() ), 'total' => array( 'type' => 'integer' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'list_roles' ),
			'permission_callback' => function () { return current_user_can( 'list_users' ); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-role', array(
			'label' => __( 'Get Role', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one registered WordPress role and its granted capabilities.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'slug' => array( 'type' => 'string' ) ), array( 'slug' ) ),
			'output_schema' => self::role_schema(),
			'execute_callback' => array( 'WP_MCP_Users', 'get_role' ),
			'permission_callback' => function () { return current_user_can( 'list_users' ); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/create-role', array(
			'label' => __( 'Create Role', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a role with an explicit bounded list of capabilities. The plugin-managed WordPress MCP Agent role is protected.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'slug' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ), 'capabilities' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 100 ) ), array( 'slug', 'name' ) ),
			'output_schema' => self::role_schema(),
			'execute_callback' => array( 'WP_MCP_Users', 'create_role' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/update-role-capabilities', array(
			'label' => __( 'Update Role Capabilities', 'wordpress-mcp-abilities' ),
			'description' => __( 'Apply explicit add/remove capability lists to a non-protected role.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'slug' => array( 'type' => 'string' ), 'add_capabilities' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 100 ), 'remove_capabilities' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 100 ) ), array( 'slug' ) ),
			'output_schema' => self::role_schema(),
			'execute_callback' => array( 'WP_MCP_Users', 'update_role_capabilities' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		foreach ( array( 'add-role-capability' => 'add_role_capability', 'remove-role-capability' => 'remove_role_capability' ) as $slug => $callback ) {
			wp_register_ability( 'wp-mcp/' . $slug, array(
				'label' => ucwords( str_replace( '-', ' ', $slug ) ),
				'description' => __( 'Add or remove one explicit capability from a non-protected WordPress role.', 'wordpress-mcp-abilities' ),
				'category' => 'wp-mcp-users',
				'input_schema' => self::object_schema( array( 'slug' => array( 'type' => 'string' ), 'capability' => array( 'type' => 'string' ) ), array( 'slug', 'capability' ) ),
				'output_schema' => self::role_schema(),
				'execute_callback' => array( 'WP_MCP_Users', $callback ),
				'permission_callback' => function () { return current_user_can( 'manage_options' ); },
				'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
			) );
		}

		wp_register_ability( 'wp-mcp/delete-role', array(
			'label' => __( 'Delete Role', 'wordpress-mcp-abilities' ),
			'description' => __( 'Delete an unused non-protected role; roles assigned to users are refused.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'slug' => array( 'type' => 'string' ) ), array( 'slug' ) ),
			'output_schema' => self::object_schema( array( 'slug' => array( 'type' => 'string' ), 'deleted' => array( 'type' => 'boolean' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'delete_role' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function register_application_password_abilities() {
		wp_register_ability( 'wp-mcp/list-application-passwords', array(
			'label' => __( 'List Application Passwords', 'wordpress-mcp-abilities' ),
			'description' => __( 'List application-password metadata without password hashes or plaintext secrets.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property() ), array() ),
			'output_schema' => self::object_schema( array( 'user_id' => array( 'type' => 'integer' ), 'passwords' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ), 'total' => array( 'type' => 'integer' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'list_application_passwords' ),
			'permission_callback' => function () { return current_user_can( 'read' ); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/create-application-password', array(
			'label' => __( 'Create Application Password', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create an application password using WordPress core. Plaintext is returned once in this response and never audited.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property(), 'name' => array( 'type' => 'string', 'minLength' => 1 ), 'app_id' => array( 'type' => 'string' ) ), array( 'name' ) ),
			'output_schema' => self::object_schema( array( 'user_id' => array( 'type' => 'integer' ), 'password' => array( 'type' => 'string' ), 'metadata' => array( 'type' => 'object' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'create_application_password' ),
			'permission_callback' => function () { return current_user_can( 'read' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/revoke-application-password', array(
			'label' => __( 'Revoke Application Password', 'wordpress-mcp-abilities' ),
			'description' => __( 'Revoke one application password by UUID without exposing its secret.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property(), 'uuid' => array( 'type' => 'string' ) ), array( 'uuid' ) ),
			'output_schema' => self::object_schema( array( 'user_id' => array( 'type' => 'integer' ), 'uuid' => array( 'type' => 'string' ), 'revoked' => array( 'type' => 'boolean' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'revoke_application_password' ),
			'permission_callback' => function () { return current_user_can( 'read' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/revoke-all-application-passwords', array(
			'label' => __( 'Revoke All Application Passwords', 'wordpress-mcp-abilities' ),
			'description' => __( 'Revoke every application password for one user and return only the count.', 'wordpress-mcp-abilities' ),
			'category' => 'wp-mcp-users',
			'input_schema' => self::object_schema( array( 'user_id' => self::user_id_property() ), array() ),
			'output_schema' => self::object_schema( array( 'user_id' => array( 'type' => 'integer' ), 'revoked' => array( 'type' => 'integer' ) ), array() ),
			'execute_callback' => array( 'WP_MCP_Users', 'revoke_all_application_passwords' ),
			'permission_callback' => function () { return current_user_can( 'read' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function user_id_property() {
		return array( 'type' => 'integer', 'minimum' => 1 );
	}

	private static function user_schema( $include_private = false ) {
		$properties = array(
			'id' => array( 'type' => 'integer' ),
			'username' => array( 'type' => 'string' ),
			'name' => array( 'type' => 'string' ),
			'first_name' => array( 'type' => 'string' ),
			'last_name' => array( 'type' => 'string' ),
			'url' => array( 'type' => 'string' ),
			'locale' => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'roles' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		);
		if ( $include_private ) {
			$properties['email'] = array( 'type' => 'string' );
		}
		return self::object_schema( $properties, array() );
	}

	private static function role_schema() {
		return self::object_schema( array(
			'slug' => array( 'type' => 'string' ),
			'name' => array( 'type' => 'string' ),
			'capabilities' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		), array() );
	}

	private static function object_schema( $properties, $required ) {
		return array(
			'type' => 'object',
			'properties' => $properties,
			'required' => $required,
			'additionalProperties' => false,
		);
	}
}
