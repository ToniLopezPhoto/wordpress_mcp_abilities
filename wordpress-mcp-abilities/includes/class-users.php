<?php
/**
 * WordPress MCP Abilities — User, role, and application password callbacks.
 *
 * Every operation is explicit and delegates authorization to WordPress native
 * capabilities. Password hashes, plaintext passwords, cookies, nonces, and
 * other authentication material are never included in list/read responses.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Users
 */
class WP_MCP_Users {

	/** Maximum number of users returned by one request. */
	const MAX_PER_PAGE = 50;

	/**
	 * List users visible to a user with list_users.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_users( $input ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( (array) $input, 10, self::MAX_PER_PAGE );
		$args = array(
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'count_total' => true,
			'fields'     => 'all',
			'orderby'    => 'ID',
			'order'      => 'ASC',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['search'] = '*' . sanitize_text_field( $input['search'] ) . '*';
		}
		if ( ! empty( $input['role'] ) ) {
			$role = self::validate_role_slug( $input['role'] );
			if ( is_wp_error( $role ) ) {
				return $role;
			}
			$args['role'] = $role;
		}

		$query = new WP_User_Query( $args );
		$users = array();
		foreach ( $query->get_results() as $user ) {
			if ( ! self::can_view_user( $user ) ) {
				continue;
			}
			$users[] = self::format_user( $user, false );
		}

		return array(
			'users'      => $users,
			'total'      => (int) $query->get_total(),
			'total_pages' => (int) ceil( $query->get_total() / $per_page ),
			'page'       => $page,
			'per_page'   => $per_page,
		);
	}

	/**
	 * Get one user after checking the native edit/list boundary.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_user( $input ) {
		$user = self::get_user_from_input( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! self::can_view_user( $user ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}

		return self::format_user( $user, true );
	}

	/**
	 * Get the authenticated user's profile.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_current_user( $input = array() ) {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return WP_MCP_Errors::user_permission_denied( __( 'You must be authenticated to read the current user.', 'wordpress-mcp-abilities' ) );
		}

		return self::format_user( $user, true );
	}

	/**
	 * Create a user without returning the password.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_user( $input ) {
		if ( ! current_user_can( 'create_users' ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}

		$username = isset( $input['username'] ) ? sanitize_user( $input['username'], true ) : '';
		$email    = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
		$password = isset( $input['password'] ) && is_string( $input['password'] ) ? $input['password'] : '';
		if ( '' === $username || ! validate_username( $username ) || '' === $email || ! is_email( $email ) || strlen( $password ) < 8 ) {
			return WP_MCP_Errors::user_validation_error( __( 'username, a valid email, and a password of at least 8 characters are required.', 'wordpress-mcp-abilities' ) );
		}

		$data = array(
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => $password,
		);
		if ( isset( $input['display_name'] ) ) {
			$data['display_name'] = sanitize_text_field( $input['display_name'] );
		}
		if ( isset( $input['first_name'] ) ) {
			$data['first_name'] = sanitize_text_field( $input['first_name'] );
		}
		if ( isset( $input['last_name'] ) ) {
			$data['last_name'] = sanitize_text_field( $input['last_name'] );
		}
		if ( isset( $input['role'] ) ) {
			$role = self::validate_assignable_role( $input['role'], 0, false );
			if ( is_wp_error( $role ) ) {
				return $role;
			}
			$data['role'] = $role;
		}

		$user_id = wp_insert_user( $data );
		if ( is_wp_error( $user_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-user', 0, false, 'wp_mcp_user_create_failed' );
			return WP_MCP_Errors::user_create_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/create-user', $user_id, true );
		return self::format_user( get_userdata( $user_id ), true );
	}

	/**
	 * Update only the explicitly allowed profile fields.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_user( $input ) {
		$user = self::get_user_from_input( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}

		$allowed = array( 'user_id', 'display_name', 'email', 'url', 'locale', 'first_name', 'last_name', 'description' );
		$unknown = array_diff( array_keys( (array) $input ), $allowed );
		if ( ! empty( $unknown ) ) {
			return WP_MCP_Errors::user_validation_error( __( 'Only documented profile fields may be updated.', 'wordpress-mcp-abilities' ) );
		}

		$data = array( 'ID' => $user->ID );
		if ( array_key_exists( 'display_name', $input ) ) {
			$data['display_name'] = sanitize_text_field( $input['display_name'] );
		}
		if ( array_key_exists( 'email', $input ) ) {
			$email = sanitize_email( $input['email'] );
			if ( '' === $email || ! is_email( $email ) ) {
				return WP_MCP_Errors::user_validation_error( __( 'The email address is invalid.', 'wordpress-mcp-abilities' ) );
			}
			$data['user_email'] = $email;
		}
		if ( array_key_exists( 'url', $input ) ) {
			$data['user_url'] = esc_url_raw( $input['url'] );
		}
		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = sanitize_text_field( $input[ $field ] );
			}
		}
		if ( array_key_exists( 'locale', $input ) ) {
			$data['locale'] = sanitize_text_field( $input['locale'] );
		}
		if ( array_key_exists( 'description', $input ) ) {
			$data['description'] = sanitize_textarea_field( $input['description'] );
		}
		if ( 1 === count( $data ) ) {
			return WP_MCP_Errors::user_validation_error( __( 'At least one profile field is required.', 'wordpress-mcp-abilities' ) );
		}

		$result = wp_update_user( $data );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-user', $user->ID, false, 'wp_mcp_user_update_failed' );
			return WP_MCP_Errors::user_update_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/update-user', $user->ID, true );
		return self::format_user( get_userdata( $user->ID ), true );
	}

	/**
	 * Set a user's password. The password is accepted only for this call and is
	 * never returned or passed to the audit layer.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_user_password( $input ) {
		$user = self::get_user_from_input( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}
		$password = isset( $input['password'] ) && is_string( $input['password'] ) ? $input['password'] : '';
		if ( strlen( $password ) < 8 ) {
			return WP_MCP_Errors::user_validation_error( __( 'The password must contain at least 8 characters.', 'wordpress-mcp-abilities' ) );
		}

		$result = wp_update_user( array( 'ID' => $user->ID, 'user_pass' => $password ) );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-user-password', $user->ID, false, 'wp_mcp_user_update_failed' );
			return WP_MCP_Errors::user_update_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/set-user-password', $user->ID, true );
		return array( 'id' => $user->ID, 'updated' => true );
	}

	/**
	 * Delete a user only when content reassignment is explicit.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_user( $input ) {
		$user = self::get_user_from_input( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! current_user_can( 'delete_user', $user->ID ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}
		if ( get_current_user_id() === (int) $user->ID ) {
			return WP_MCP_Errors::user_validation_error( __( 'Deleting the current user is not supported by this ability.', 'wordpress-mcp-abilities' ) );
		}
		if ( is_multisite() ) {
			return WP_MCP_Errors::user_delete_unsupported();
		}
		if ( ! array_key_exists( 'reassign_user_id', $input ) ) {
			return WP_MCP_Errors::user_validation_error( __( 'A reassignment user ID is required; implicit content deletion is not allowed.', 'wordpress-mcp-abilities' ) );
		}

		$reassign_id = absint( $input['reassign_user_id'] );
		$reassign = $reassign_id ? get_userdata( $reassign_id ) : false;
		if ( ! $reassign || $reassign_id === (int) $user->ID ) {
			return WP_MCP_Errors::user_validation_error( __( 'The reassignment user must be an existing different user.', 'wordpress-mcp-abilities' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$result = wp_delete_user( $user->ID, $reassign_id );
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-user', $user->ID, false, 'wp_mcp_user_delete_failed' );
			return WP_MCP_Errors::user_delete_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/delete-user', $user->ID, true );
		return array( 'id' => $user->ID, 'deleted' => true, 'reassigned_to' => $reassign_id );
	}

	/**
	 * List effective capabilities for a user.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_user_capabilities( $input ) {
		$user = self::get_user_from_input( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! self::can_view_user( $user ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}

		$capabilities = array();
		foreach ( (array) $user->allcaps as $capability => $granted ) {
			if ( $granted ) {
				$capabilities[] = sanitize_key( $capability );
			}
		}
		sort( $capabilities );

		return array( 'user_id' => $user->ID, 'roles' => array_values( $user->roles ), 'capabilities' => $capabilities );
	}

	/**
	 * List all roles and their granted capabilities.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_roles( $input = array() ) {
		if ( ! self::can_manage_roles( 'list_users' ) ) {
			return WP_MCP_Errors::role_permission_denied();
		}
		$roles = array();
		foreach ( wp_roles()->roles as $slug => $definition ) {
			$roles[] = self::format_role( $slug, $definition );
		}
		return array( 'roles' => $roles, 'total' => count( $roles ) );
	}

	/**
	 * Get one role.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_role( $input ) {
		if ( ! self::can_manage_roles( 'list_users' ) ) {
			return WP_MCP_Errors::role_permission_denied();
		}
		$slug = self::validate_role_slug( isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		if ( ! isset( wp_roles()->roles[ $slug ] ) ) {
			return WP_MCP_Errors::role_not_found();
		}
		return self::format_role( $slug, wp_roles()->roles[ $slug ] );
	}

	/** Create a new role. */
	public static function create_role( $input ) {
		if ( ! self::can_manage_roles( 'create_users' ) ) {
			return WP_MCP_Errors::role_permission_denied();
		}
		$slug = self::validate_role_slug( isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		if ( WP_MCP_Role::ROLE_SLUG === $slug ) {
			return WP_MCP_Errors::role_protected();
		}
		if ( get_role( $slug ) ) {
			return WP_MCP_Errors::role_validation_error( __( 'The role already exists.', 'wordpress-mcp-abilities' ) );
		}
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		$caps = self::capability_list( isset( $input['capabilities'] ) ? $input['capabilities'] : array() );
		if ( is_wp_error( $caps ) || '' === $name ) {
			return is_wp_error( $caps ) ? $caps : WP_MCP_Errors::role_validation_error( __( 'A role name is required.', 'wordpress-mcp-abilities' ) );
		}

		$role = add_role( $slug, $name, $caps );
		if ( ! $role ) {
			WP_MCP_Audit::log( 'wp-mcp/create-role', 0, false, 'wp_mcp_role_create_failed' );
			return WP_MCP_Errors::role_create_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/create-role', 0, true );
		return self::format_role( $slug, wp_roles()->roles[ $slug ] );
	}

	/** Update a role using explicit add/remove capability lists. */
	public static function update_role_capabilities( $input ) {
		if ( ! self::can_manage_roles( 'edit_users' ) ) {
			return WP_MCP_Errors::role_permission_denied();
		}
		$role = self::get_mutable_role( $input );
		if ( is_wp_error( $role ) ) {
			return $role;
		}
		$add = self::capability_list( isset( $input['add_capabilities'] ) ? $input['add_capabilities'] : array() );
		$remove = self::capability_list( isset( $input['remove_capabilities'] ) ? $input['remove_capabilities'] : array() );
		if ( is_wp_error( $add ) ) {
			return $add;
		}
		if ( is_wp_error( $remove ) ) {
			return $remove;
		}
		if ( empty( $add ) && empty( $remove ) ) {
			return WP_MCP_Errors::role_validation_error( __( 'At least one capability change is required.', 'wordpress-mcp-abilities' ) );
		}
		foreach ( $add as $capability ) {
			$role->add_cap( $capability );
		}
		foreach ( $remove as $capability ) {
			$role->remove_cap( $capability );
		}
		WP_MCP_Audit::log( 'wp-mcp/update-role-capabilities', 0, true );
		return self::format_role( $input['slug'], wp_roles()->roles[ $input['slug'] ] );
	}

	/** Add one capability to a role. */
	public static function add_role_capability( $input ) {
		return self::mutate_single_role_capability( $input, 'add' );
	}

	/** Remove one capability from a role. */
	public static function remove_role_capability( $input ) {
		return self::mutate_single_role_capability( $input, 'remove' );
	}

	/** Delete an unused role. */
	public static function delete_role( $input ) {
		if ( ! self::can_manage_roles( 'delete_users' ) ) {
			return WP_MCP_Errors::role_permission_denied();
		}
		$slug = self::validate_role_slug( isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		if ( WP_MCP_Role::ROLE_SLUG === $slug ) {
			return WP_MCP_Errors::role_protected();
		}
		if ( ! get_role( $slug ) ) {
			return WP_MCP_Errors::role_not_found();
		}
		$assigned = get_users( array( 'role' => $slug, 'number' => 1, 'fields' => 'ID' ) );
		if ( ! empty( $assigned ) ) {
			return WP_MCP_Errors::role_in_use();
		}
		remove_role( $slug );
		// PHPStan can't see that remove_role() mutates the global WP_Roles registry
		// get_role() reads from, so it treats this repeat call as returning the same
		// (already-narrowed-truthy) result as the guard above — it doesn't, once the
		// role is actually gone, and this verifies the deletion really took effect.
		if ( get_role( $slug ) ) { // @phpstan-ignore-line
			WP_MCP_Audit::log( 'wp-mcp/delete-role', 0, false, 'wp_mcp_role_delete_failed' );
			return WP_MCP_Errors::role_delete_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/delete-role', 0, true ); // @phpstan-ignore-line
		return array( 'slug' => $slug, 'deleted' => true );
	}

	/** List metadata for a user's application passwords. */
	public static function list_application_passwords( $input ) {
		$user = self::get_application_password_user( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$available = self::check_application_passwords_available( $user );
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		$passwords = array();
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
			$passwords[] = array( 'metadata' => self::format_application_password( $item ) );
		}
		return array( 'user_id' => $user->ID, 'passwords' => $passwords, 'total' => count( $passwords ) );
	}

	/** Create one application password and return plaintext only in this response. */
	public static function create_application_password( $input ) {
		$user = self::get_application_password_user( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$available = self::check_application_passwords_available( $user );
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			return WP_MCP_Errors::application_password_validation_error( __( 'An application name is required.', 'wordpress-mcp-abilities' ) );
		}
		$args = array( 'name' => $name );
		if ( isset( $input['app_id'] ) ) {
			$app_id = self::validate_uuid( $input['app_id'] );
			if ( is_wp_error( $app_id ) ) {
				return $app_id;
			}
			$args['app_id'] = $app_id;
		}

		$result = WP_Application_Passwords::create_new_application_password( $user->ID, $args );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-application-password', $user->ID, false, 'wp_mcp_application_password_create_failed' );
			return WP_MCP_Errors::application_password_create_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/create-application-password', $user->ID, true );
		return array(
			'user_id' => $user->ID,
			'password' => $result[0],
			'metadata' => self::format_application_password( $result[1] ),
		);
	}

	/** Revoke one application password by UUID. */
	public static function revoke_application_password( $input ) {
		$user = self::get_application_password_user( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$available = self::check_application_passwords_available( $user );
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		$uuid = self::validate_uuid( isset( $input['uuid'] ) ? $input['uuid'] : '' );
		if ( is_wp_error( $uuid ) ) {
			return $uuid;
		}
		$result = WP_Application_Passwords::delete_application_password( $user->ID, $uuid );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/revoke-application-password', $user->ID, false, 'wp_mcp_application_password_not_found' );
			return WP_MCP_Errors::application_password_not_found();
		}
		WP_MCP_Audit::log( 'wp-mcp/revoke-application-password', $user->ID, true );
		return array( 'user_id' => $user->ID, 'uuid' => $uuid, 'revoked' => true );
	}

	/** Revoke all application passwords for one user. */
	public static function revoke_all_application_passwords( $input ) {
		$user = self::get_application_password_user( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$available = self::check_application_passwords_available( $user );
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		$result = WP_Application_Passwords::delete_all_application_passwords( $user->ID );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/revoke-all-application-passwords', $user->ID, false, 'wp_mcp_application_password_revoke_failed' );
			return WP_MCP_Errors::application_password_revoke_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/revoke-all-application-passwords', $user->ID, true );
		return array( 'user_id' => $user->ID, 'revoked' => (int) $result );
	}

	/** Resolve a user and enforce the native edit boundary for credentials. */
	private static function get_application_password_user( $input ) {
		$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : get_current_user_id();
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return WP_MCP_Errors::invalid_user();
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}
		return $user;
	}

	/** Check both core availability gates for application passwords. */
	private static function check_application_passwords_available( $user ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! function_exists( 'wp_is_application_passwords_available_for_user' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return WP_MCP_Errors::application_password_unavailable();
		}
		return true;
	}

	/** Return only non-secret application password metadata. */
	private static function format_application_password( $item ) {
		return array(
			'uuid'      => isset( $item['uuid'] ) ? sanitize_text_field( $item['uuid'] ) : '',
			'app_id'    => isset( $item['app_id'] ) ? sanitize_text_field( $item['app_id'] ) : '',
			'name'      => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
			'created'   => isset( $item['created'] ) ? (int) $item['created'] : 0,
			'last_used' => isset( $item['last_used'] ) && null !== $item['last_used'] ? (int) $item['last_used'] : null,
			'last_ip'   => isset( $item['last_ip'] ) && null !== $item['last_ip'] ? sanitize_text_field( $item['last_ip'] ) : null,
		);
	}

	/** Resolve and validate a user ID from an ability input. */
	private static function get_user_from_input( $input ) {
		$user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
		if ( $user_id < 1 ) {
			return WP_MCP_Errors::user_validation_error( __( 'user_id must be a positive integer.', 'wordpress-mcp-abilities' ) );
		}
		$user = get_userdata( $user_id );
		return $user ? $user : WP_MCP_Errors::invalid_user();
	}

	/** Whether the current user may view a user's profile. */
	private static function can_view_user( $user ) {
		return get_current_user_id() === (int) $user->ID || current_user_can( 'list_users' ) || current_user_can( 'edit_user', $user->ID );
	}

	/** Format a user without ever including authentication fields. */
	private static function format_user( $user, $include_private ) {
		$data = array(
			'id'           => (int) $user->ID,
			'username'     => $user->user_login,
			'name'         => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'url'          => $user->user_url,
			'locale'       => get_user_locale( $user ),
			'description'  => $user->description,
			'roles'        => array_values( $user->roles ),
		);
		if ( $include_private && ( get_current_user_id() === (int) $user->ID || current_user_can( 'edit_user', $user->ID ) ) ) {
			$data['email'] = $user->user_email;
		}
		return $data;
	}

	/** Check core's editable role list and the native promote boundary. */
	private static function validate_assignable_role( $raw_role, $user_id, $check_self_lockout = true ) {
		$role = self::validate_role_slug( $raw_role );
		if ( is_wp_error( $role ) ) {
			return $role;
		}
		if ( $check_self_lockout && $user_id && get_current_user_id() === $user_id && ! self::role_preserves_promotion( $role ) ) {
			return WP_MCP_Errors::user_self_promotion_denied();
		}
		if ( ! current_user_can( 'promote_users' ) || ( $user_id && ! current_user_can( 'promote_user', $user_id ) ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$editable = get_editable_roles();
		if ( ! isset( $editable[ $role ] ) ) {
			return WP_MCP_Errors::user_permission_denied( __( 'The requested role is not editable by the current user.', 'wordpress-mcp-abilities' ) );
		}
		$escalation = self::role_grants_capability_the_caller_lacks( $role );
		if ( '' !== $escalation ) {
			return WP_MCP_Errors::user_permission_denied(
				sprintf(
					/* translators: %s: capability the target role grants */
					__( 'You cannot grant a role that carries the "%s" capability, which you do not hold yourself.', 'wordpress-mcp-abilities' ),
					$escalation
				)
			);
		}
		return $role;
	}

	/**
	 * First capability the target role grants that the caller does not hold.
	 *
	 * `promote_users` plus core's `get_editable_roles()` is the whole gate
	 * WordPress itself applies, and on a single site that list is unfiltered —
	 * it includes `administrator`. Anyone holding `promote_users` could
	 * therefore hand out capabilities they never had, which is the privilege
	 * escalation issue #15 rules out. This is deliberately stricter than
	 * wp-admin: an Administrator holds every capability of every core role, so
	 * it never bites them, but a narrow custom role created purely to delegate
	 * user management can no longer promote anyone (itself included) to
	 * Administrator.
	 *
	 * Legacy `level_N` pseudo-capabilities are ignored: they are vestigial
	 * numeric markers WordPress keeps for backwards compatibility, not real
	 * privileges, and comparing them would reject assignments that grant
	 * nothing new.
	 *
	 * @since 0.15.0
	 *
	 * @param string $role Role slug, already validated.
	 * @return string Offending capability, or '' when the role grants nothing new.
	 */
	private static function role_grants_capability_the_caller_lacks( $role ) {
		$role_object = get_role( $role );
		if ( ! $role_object || ! is_array( $role_object->capabilities ) ) {
			return '';
		}

		foreach ( $role_object->capabilities as $capability => $granted ) {
			if ( ! $granted || ! is_string( $capability ) || preg_match( '/^level_\d+$/', $capability ) ) {
				continue;
			}
			if ( ! self::caller_holds_capability( $capability ) ) {
				return $capability;
			}
		}

		return '';
	}

	/**
	 * Whether the caller counts as holding one capability for the purposes of
	 * the escalation check above.
	 *
	 * `current_user_can()` first, because it is the real answer and it honours
	 * super admins and the `user_has_cap` filter. The caller's own raw role
	 * grant is then accepted as a fallback, and that fallback is what keeps the
	 * rule from misfiring on capabilities WordPress denies to *everybody* in
	 * this environment:
	 *
	 *  - `DISALLOW_FILE_MODS` / `DISALLOW_FILE_EDIT` make `map_meta_cap()`
	 *    answer `do_not_allow` for `install_plugins`, `update_core`,
	 *    `edit_themes` and friends, for administrators included;
	 *  - on multisite the same file-modifying capabilities are reserved to
	 *    super admins, while the `administrator` *role* still lists them.
	 *
	 * In both cases `current_user_can( 'install_plugins' )` is false for a site
	 * administrator whose own role grants it, and the grantee would be denied
	 * it just as hard — so refusing the assignment would lock administrators
	 * out of ordinary user management without preventing any escalation. A
	 * narrow custom role created only to delegate user management still fails
	 * the check, because its own role grant does not carry the capability
	 * either.
	 *
	 * @since 0.15.0
	 *
	 * @param string $capability Capability from the target role's registration.
	 * @return bool
	 */
	private static function caller_holds_capability( $capability ) {
		if ( current_user_can( $capability ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability comes from the target role's own registration.
			return true;
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() || ! is_array( $user->allcaps ) ) {
			return false;
		}

		return ! empty( $user->allcaps[ $capability ] );
	}

	/** Change the complete role set for a user. */
	public static function change_user_role( $input ) {
		$user = self::get_user_from_input( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$role = self::validate_assignable_role( isset( $input['role'] ) ? $input['role'] : '', $user->ID, true );
		if ( is_wp_error( $role ) ) {
			return $role;
		}
		$user->set_role( $role );
		WP_MCP_Audit::log( 'wp-mcp/change-user-role', $user->ID, true );
		return self::format_user( get_userdata( $user->ID ), true );
	}

	/** Add a role without removing existing roles. */
	public static function add_user_role( $input ) {
		$user = self::get_user_from_input( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$role = self::validate_assignable_role( isset( $input['role'] ) ? $input['role'] : '', $user->ID, false );
		if ( is_wp_error( $role ) ) {
			return $role;
		}
		if ( get_current_user_id() === $user->ID && ! self::roles_preserve_promotion( array_merge( $user->roles, array( $role ) ) ) ) {
			return WP_MCP_Errors::user_self_promotion_denied();
		}
		$user->add_role( $role );
		WP_MCP_Audit::log( 'wp-mcp/add-user-role', $user->ID, true );
		return self::format_user( get_userdata( $user->ID ), true );
	}

	/** Remove one role while preventing self-lockout. */
	public static function remove_user_role( $input ) {
		$user = self::get_user_from_input( $input );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'promote_user', $user->ID ) ) {
			return WP_MCP_Errors::user_permission_denied();
		}
		$role = self::validate_role_slug( isset( $input['role'] ) ? $input['role'] : '' );
		if ( is_wp_error( $role ) ) {
			return $role;
		}
		if ( ! in_array( $role, $user->roles, true ) ) {
			return WP_MCP_Errors::role_not_found();
		}
		$remaining = array_values( array_diff( $user->roles, array( $role ) ) );
		if ( get_current_user_id() === $user->ID && ! self::roles_preserve_promotion( $remaining ) ) {
			return WP_MCP_Errors::user_self_promotion_denied();
		}
		$user->remove_role( $role );
		WP_MCP_Audit::log( 'wp-mcp/remove-user-role', $user->ID, true );
		return self::format_user( get_userdata( $user->ID ), true );
	}

	/** Ensure the role is not the plugin-managed protected role. */
	private static function get_mutable_role( $input ) {
		$slug = self::validate_role_slug( isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		if ( WP_MCP_Role::ROLE_SLUG === $slug ) {
			return WP_MCP_Errors::role_protected();
		}
		$role = get_role( $slug );
		return $role ? $role : WP_MCP_Errors::role_not_found();
	}

	/** Mutate one capability in a role. */
	private static function mutate_single_role_capability( $input, $operation ) {
		if ( ! self::can_manage_roles( 'edit_users' ) ) {
			return WP_MCP_Errors::role_permission_denied();
		}
		$role = self::get_mutable_role( $input );
		if ( is_wp_error( $role ) ) {
			return $role;
		}
		$capability = self::validate_capability( isset( $input['capability'] ) ? $input['capability'] : '' );
		if ( is_wp_error( $capability ) ) {
			return $capability;
		}
		if ( 'add' === $operation ) {
			$role->add_cap( $capability );
		} else {
			$role->remove_cap( $capability );
		}
		WP_MCP_Audit::log( 'wp-mcp/' . ( 'add' === $operation ? 'add-role-capability' : 'remove-role-capability' ), 0, true );
		return self::format_role( $input['slug'], wp_roles()->roles[ $input['slug'] ] );
	}

	/** Role operations require a strong administrative capability. */
	private static function can_manage_roles( $capability ) {
		return current_user_can( $capability ) && current_user_can( 'manage_options' );
	}

	/** Validate a role slug without silently rewriting it. */
	private static function validate_role_slug( $raw_role ) {
		if ( ! is_string( $raw_role ) || '' === $raw_role || sanitize_key( $raw_role ) !== $raw_role || strlen( $raw_role ) > 32 ) {
			return WP_MCP_Errors::role_validation_error( __( 'The role slug must be a lowercase WordPress key of at most 32 characters.', 'wordpress-mcp-abilities' ) );
		}
		return $raw_role;
	}

	/** Validate and normalize a list of capability keys. */
	private static function capability_list( $raw ) {
		if ( ! is_array( $raw ) || count( $raw ) > 100 ) {
			return WP_MCP_Errors::role_validation_error( __( 'Capabilities must be an array of at most 100 keys.', 'wordpress-mcp-abilities' ) );
		}
		$result = array();
		foreach ( $raw as $capability ) {
			$validated = self::validate_capability( $capability );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$result[] = $validated;
		}
		return array_values( array_unique( $result ) );
	}

	/** Validate one capability key. */
	private static function validate_capability( $capability ) {
		if ( ! is_string( $capability ) || '' === $capability || sanitize_key( $capability ) !== $capability || strlen( $capability ) > 128 ) {
			return WP_MCP_Errors::role_validation_error( __( 'The capability must be a lowercase WordPress key.', 'wordpress-mcp-abilities' ) );
		}
		return $capability;
	}

	/** Format role data without exposing unrelated registry internals. */
	private static function format_role( $slug, $definition ) {
		$capabilities = array();
		foreach ( (array) $definition['capabilities'] as $capability => $granted ) {
			if ( $granted ) {
				$capabilities[] = sanitize_key( $capability );
			}
		}
		sort( $capabilities );
		return array(
			'slug' => $slug,
			'name' => isset( $definition['name'] ) ? translate_user_role( $definition['name'] ) : $slug,
			'capabilities' => $capabilities,
		);
	}

	/** Check whether a role retains promote_users. */
	private static function role_preserves_promotion( $role ) {
		return isset( wp_roles()->roles[ $role ]['capabilities']['promote_users'] ) && wp_roles()->roles[ $role ]['capabilities']['promote_users'];
	}

	/** Check whether a set of roles retains promote_users. */
	private static function roles_preserve_promotion( $roles ) {
		foreach ( $roles as $role ) {
			if ( self::role_preserves_promotion( $role ) ) {
				return true;
			}
		}
		return false;
	}

	/** Validate a UUID supplied for an application password. */
	private static function validate_uuid( $uuid ) {
		if ( ! is_string( $uuid ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid ) ) {
			return WP_MCP_Errors::application_password_validation_error( __( 'The identifier must be a valid UUID.', 'wordpress-mcp-abilities' ) );
		}
		return $uuid;
	}
}
