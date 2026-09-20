<?php
/**
 * WordPress MCP Abilities — Network user callbacks (issue #13).
 *
 * The Network Admin "Users" screen plus the per-site membership operations,
 * expressed as fixed operations.
 *
 * Security notes specific to this domain:
 *
 *  - **Super Admin is never granted or revoked here.** `grant_super_admin()`
 *    / `revoke_super_admin()` are deliberately not exposed: granting the
 *    network's highest privilege is the one operation whose blast radius is
 *    the entire network, and it stays a human decision made in Network Admin.
 *    A Super Admin account is also refused as a deletion target, so this
 *    domain can never remove the people who could undo its own mistakes.
 *  - **Deletion never destroys content implicitly.** WordPress's own
 *    `wpmu_delete_user()` deletes every post the user authored on every site
 *    of the network. `delete-network-user` therefore requires an explicit
 *    `reassign_user_id` and reassigns each site's content *before* calling
 *    core, mirroring the single-site `delete-user` contract.
 *  - **Roles are validated against the target site**, inside a
 *    `switch_to_blog()` context and through `get_editable_roles()`, because a
 *    role that exists on one site of a network need not exist on another.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network_Users
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Network_Users {

	/** Maximum number of rows returned by one list request. */
	const MAX_PER_PAGE = 50;

	/** Minimum accepted password length, matching the single-site domain. */
	const MIN_PASSWORD_LENGTH = 8;

	/* ------------------------------------------------------------------
	 * Reads
	 * ---------------------------------------------------------------- */

	/**
	 * List every user of the network, not only the users of one site.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_network_users( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( 'manage_network_users' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 10, self::MAX_PER_PAGE );

		$args = array(
			'blog_id'     => 0,
			'number'      => $per_page,
			'offset'      => ( $page - 1 ) * $per_page,
			'count_total' => true,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		);
		if ( ! empty( $input['search'] ) && is_string( $input['search'] ) ) {
			$args['search'] = '*' . sanitize_text_field( $input['search'] ) . '*';
		}

		$query = new WP_User_Query( $args );
		$users = array();
		foreach ( $query->get_results() as $user ) {
			if ( $user instanceof WP_User ) {
				$users[] = self::format_network_user( $user );
			}
		}

		$total = (int) $query->get_total();

		return array(
			'users'       => $users,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get one network user.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_network_user( $input = array() ) {
		$user = self::require_user( $input, 'user_id' );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		return self::format_network_user( $user );
	}

	/**
	 * List the sites a user belongs to, with the roles they hold on each.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_network_user_sites( $input = array() ) {
		$user = self::require_user( $input, 'user_id' );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 20, self::MAX_PER_PAGE );

		$blogs = array_values( (array) get_blogs_of_user( (int) $user->ID, true ) );
		$total = count( $blogs );
		$slice = array_slice( $blogs, ( $page - 1 ) * $per_page, $per_page );

		$sites = array();
		foreach ( $slice as $blog ) {
			if ( ! isset( $blog->userblog_id ) ) {
				continue;
			}
			$site_id = (int) $blog->userblog_id;
			$sites[] = array(
				'site_id' => $site_id,
				'domain'  => isset( $blog->domain ) ? (string) $blog->domain : '',
				'path'    => isset( $blog->path ) ? (string) $blog->path : '',
				'url'     => isset( $blog->siteurl ) ? (string) $blog->siteurl : '',
				'name'    => isset( $blog->blogname ) ? wp_strip_all_tags( (string) $blog->blogname ) : '',
				'roles'   => self::roles_on_site( (int) $user->ID, $site_id ),
			);
		}

		return array(
			'user_id'     => (int) $user->ID,
			'sites'       => $sites,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/* ------------------------------------------------------------------
	 * Writes — network-level users
	 * ---------------------------------------------------------------- */

	/**
	 * Create a user on the network without adding them to any site.
	 *
	 * Validation goes through WordPress's own `wpmu_validate_user_signup()`
	 * so the network's illegal names, limited and banned email domains are
	 * enforced by the network's own configuration rather than re-implemented.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_network_user( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( 'manage_network_users' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! current_user_can( 'create_users' ) ) {
			return WP_MCP_Errors::network_permission_denied( __( 'Creating users requires the create_users capability on this network.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$username = isset( $input['username'] ) && is_string( $input['username'] ) ? sanitize_user( $input['username'], true ) : '';
		$email    = isset( $input['email'] ) && is_string( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
		$password = isset( $input['password'] ) && is_string( $input['password'] ) ? $input['password'] : '';

		if ( '' === $username || ! validate_username( $username ) ) {
			return WP_MCP_Errors::network_validation_error( __( 'username is required and must be a valid WordPress username.', 'wordpress-mcp-abilities' ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return WP_MCP_Errors::network_validation_error( __( 'email is required and must be a valid email address.', 'wordpress-mcp-abilities' ) );
		}
		if ( strlen( $password ) < self::MIN_PASSWORD_LENGTH ) {
			return WP_MCP_Errors::network_validation_error(
				sprintf(
					/* translators: %d: minimum password length */
					__( 'password is required and must be at least %d characters.', 'wordpress-mcp-abilities' ),
					self::MIN_PASSWORD_LENGTH
				)
			);
		}

		$validation = wpmu_validate_user_signup( $username, $email );
		$errors     = $validation['errors'];
		if ( $errors->has_errors() ) {
			return WP_MCP_Errors::network_validation_error( $errors->get_error_message() );
		}

		$user_id = wpmu_create_user( $username, $password, $email );
		if ( ! $user_id ) {
			WP_MCP_Audit::log( 'wp-mcp/create-network-user', 0, false, 'wp_mcp_network_user_create_failed' );
			return WP_MCP_Errors::network_user_create_failed();
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof WP_User ) {
			return WP_MCP_Errors::network_user_create_failed( __( 'The user was created but could not be read back.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/create-network-user', (int) $user_id, true );
		return self::format_network_user( $user );
	}

	/**
	 * Delete a user from the whole network, reassigning their content on
	 * every site first.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_network_user( $input = array() ) {
		$user = self::require_user( $input, 'user_id' );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! current_user_can( 'delete_users' ) ) {
			return WP_MCP_Errors::network_permission_denied( __( 'Deleting users requires the delete_users capability on this network.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$user_id = (int) $user->ID;
		if ( get_current_user_id() === $user_id ) {
			return WP_MCP_Errors::network_validation_error( __( 'Deleting the current user is not supported by this ability.', 'wordpress-mcp-abilities' ) );
		}
		if ( is_super_admin( $user_id ) ) {
			return WP_MCP_Errors::network_conflict( __( 'A Super Admin cannot be deleted through this ability; revoke the Super Admin privilege in Network Admin first.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! array_key_exists( 'reassign_user_id', $input ) ) {
			return WP_MCP_Errors::network_validation_error( __( 'A reassignment user ID is required; implicit content deletion is not allowed. WordPress deletes every post authored by a removed network user unless its content is reassigned first.', 'wordpress-mcp-abilities' ) );
		}

		$reassign_id = absint( $input['reassign_user_id'] );
		$reassign    = $reassign_id ? get_userdata( $reassign_id ) : false;
		if ( ! $reassign instanceof WP_User || $reassign_id === $user_id ) {
			return WP_MCP_Errors::network_validation_error( __( 'The reassignment user must be an existing different user.', 'wordpress-mcp-abilities' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/ms.php';
		if ( ! function_exists( 'wpmu_delete_user' ) ) {
			return WP_MCP_Errors::network_user_delete_failed( __( 'The network user deletion routine is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		$sites = array();
		foreach ( (array) get_blogs_of_user( $user_id, true ) as $blog ) {
			if ( ! isset( $blog->userblog_id ) ) {
				continue;
			}
			$site_id = (int) $blog->userblog_id;
			remove_user_from_blog( $user_id, $site_id, $reassign_id );
			$sites[] = $site_id;
		}

		$result = wpmu_delete_user( $user_id );
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-network-user', $user_id, false, 'wp_mcp_network_user_delete_failed' );
			return WP_MCP_Errors::network_user_delete_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/delete-network-user', $user_id, true, '', array( 'sites' => count( $sites ) ) );

		return array(
			'id'             => $user_id,
			'deleted'        => true,
			'reassigned_to'  => $reassign_id,
			'sites_detached' => $sites,
		);
	}

	/* ------------------------------------------------------------------
	 * Writes — per-site membership
	 * ---------------------------------------------------------------- */

	/**
	 * Add an existing network user to a site with an explicit role.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function add_user_to_network_site( $input = array() ) {
		$context = self::require_site_and_user( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		list( $site, $user ) = $context;

		$site_id = (int) $site->id;
		$user_id = (int) $user->ID;

		$role = self::validate_site_role( $site_id, isset( $input['role'] ) ? $input['role'] : '' );
		if ( is_wp_error( $role ) ) {
			return $role;
		}

		if ( is_user_member_of_blog( $user_id, $site_id ) ) {
			$current = self::roles_on_site( $user_id, $site_id );
			if ( array( $role ) === $current ) {
				return self::format_membership( $site, $user_id );
			}
			return WP_MCP_Errors::network_conflict( __( 'The user already belongs to this site with a different role; use wp-mcp/set-network-site-user-role to change it.', 'wordpress-mcp-abilities' ) );
		}

		$added = add_user_to_blog( $site_id, $user_id, $role );
		if ( is_wp_error( $added ) ) {
			WP_MCP_Audit::log( 'wp-mcp/add-user-to-network-site', $user_id, false, 'wp_mcp_network_validation_error', array( 'site_id' => $site_id ) );
			return WP_MCP_Errors::network_validation_error( $added->get_error_message() );
		}

		WP_MCP_Audit::log( 'wp-mcp/add-user-to-network-site', $user_id, true, '', array( 'site_id' => $site_id, 'role' => $role ) );
		return self::format_membership( $site, $user_id );
	}

	/**
	 * Remove a user from one site of the network. The user keeps their
	 * account and their membership of every other site.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function remove_user_from_network_site( $input = array() ) {
		$context = self::require_site_and_user( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		list( $site, $user ) = $context;

		$site_id = (int) $site->id;
		$user_id = (int) $user->ID;

		if ( get_current_user_id() === $user_id ) {
			return WP_MCP_Errors::network_validation_error( __( 'Removing the current user from a site is not supported by this ability.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! is_user_member_of_blog( $user_id, $site_id ) ) {
			return WP_MCP_Errors::network_conflict( __( 'The user does not belong to that site.', 'wordpress-mcp-abilities' ) );
		}

		$reassign_id = 0;
		if ( isset( $input['reassign_user_id'] ) ) {
			$reassign_id = absint( $input['reassign_user_id'] );
			$reassign    = $reassign_id ? get_userdata( $reassign_id ) : false;
			if ( ! $reassign instanceof WP_User || $reassign_id === $user_id ) {
				return WP_MCP_Errors::network_validation_error( __( 'reassign_user_id must reference an existing different user.', 'wordpress-mcp-abilities' ) );
			}
		}

		$removed = remove_user_from_blog( $user_id, $site_id, $reassign_id );
		if ( is_wp_error( $removed ) ) {
			WP_MCP_Audit::log( 'wp-mcp/remove-user-from-network-site', $user_id, false, 'wp_mcp_network_validation_error', array( 'site_id' => $site_id ) );
			return WP_MCP_Errors::network_validation_error( $removed->get_error_message() );
		}

		WP_MCP_Audit::log( 'wp-mcp/remove-user-from-network-site', $user_id, true, '', array( 'site_id' => $site_id ) );

		return array(
			'site_id'       => $site_id,
			'user_id'       => $user_id,
			'removed'       => true,
			'reassigned_to' => $reassign_id,
		);
	}

	/**
	 * Set the complete role a user holds on one site of the network.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_network_site_user_role( $input = array() ) {
		$context = self::require_site_and_user( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		list( $site, $user ) = $context;

		$site_id = (int) $site->id;
		$user_id = (int) $user->ID;

		$role = self::validate_site_role( $site_id, isset( $input['role'] ) ? $input['role'] : '' );
		if ( is_wp_error( $role ) ) {
			return $role;
		}
		if ( ! is_user_member_of_blog( $user_id, $site_id ) ) {
			return WP_MCP_Errors::network_conflict( __( 'The user does not belong to that site; use wp-mcp/add-user-to-network-site first.', 'wordpress-mcp-abilities' ) );
		}

		$site_user = new WP_User( $user_id, '', $site_id );
		$site_user->set_role( $role );

		WP_MCP_Audit::log( 'wp-mcp/set-network-site-user-role', $user_id, true, '', array( 'site_id' => $site_id, 'role' => $role ) );
		return self::format_membership( $site, $user_id );
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Capability gate plus user_id validation, in that order.
	 *
	 * @param mixed  $input Ability input.
	 * @param string $field Input field holding the user ID.
	 * @return WP_User|WP_Error
	 */
	private static function require_user( $input, $field ) {
		$denied = WP_MCP_Network::require_network_cap( 'manage_network_users' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$user_id = isset( $input[ $field ] ) ? absint( $input[ $field ] ) : 0;
		if ( $user_id < 1 ) {
			return WP_MCP_Errors::network_validation_error(
				sprintf(
					/* translators: %s: input field name */
					__( '%s must be a positive integer.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		$user = get_userdata( $user_id );
		return $user instanceof WP_User ? $user : WP_MCP_Errors::invalid_user();
	}

	/**
	 * Capability gate plus site_id and user_id validation.
	 *
	 * @param mixed $input Ability input.
	 * @return array{0:WP_Site,1:WP_User}|WP_Error
	 */
	private static function require_site_and_user( $input ) {
		$user = self::require_user( $input, 'user_id' );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$site = WP_MCP_Network::validate_site_id( isset( $input['site_id'] ) ? $input['site_id'] : 0 );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		return array( $site, $user );
	}

	/**
	 * Validate a role against the roles that actually exist on the target
	 * site and are editable by the current user.
	 *
	 * @param int   $site_id  Target site ID.
	 * @param mixed $raw_role Raw role slug.
	 * @return string|WP_Error
	 */
	private static function validate_site_role( $site_id, $raw_role ) {
		if ( ! is_string( $raw_role ) || 1 !== preg_match( '/^[a-z0-9_\-]{1,60}$/', $raw_role ) ) {
			return WP_MCP_Errors::network_validation_error( __( 'role must be a role slug of lowercase letters, digits, underscores and hyphens.', 'wordpress-mcp-abilities' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		switch_to_blog( $site_id );
		$editable = get_editable_roles();
		$exists   = isset( $editable[ $raw_role ] );
		restore_current_blog();

		if ( ! $exists ) {
			return WP_MCP_Errors::network_validation_error( __( 'role must be a role that exists on the target site and is editable by the current user.', 'wordpress-mcp-abilities' ) );
		}
		return $raw_role;
	}

	/**
	 * Roles a user holds on one site, as a plain list.
	 *
	 * @param int $user_id User ID.
	 * @param int $site_id Site ID.
	 * @return string[]
	 */
	private static function roles_on_site( $user_id, $site_id ) {
		$site_user = new WP_User( $user_id, '', $site_id );
		return array_values( array_map( 'strval', (array) $site_user->roles ) );
	}

	/**
	 * Format one network user. Authentication material — hashes, session
	 * tokens, application passwords, activation keys — is never included.
	 *
	 * @param WP_User $user User object.
	 * @return array<string,mixed>
	 */
	private static function format_network_user( $user ) {
		$user_id = (int) $user->ID;

		return array(
			'id'             => $user_id,
			'username'       => (string) $user->user_login,
			'email'          => (string) $user->user_email,
			'name'           => (string) $user->display_name,
			'registered_gmt' => (string) $user->user_registered,
			'is_super_admin' => is_super_admin( $user_id ),
			'site_count'     => count( (array) get_blogs_of_user( $user_id, true ) ),
		);
	}

	/**
	 * Format one site membership.
	 *
	 * @param WP_Site $site    Site object.
	 * @param int     $user_id User ID.
	 * @return array<string,mixed>
	 */
	private static function format_membership( $site, $user_id ) {
		$site_id = (int) $site->id;

		return array(
			'site_id'   => $site_id,
			'user_id'   => $user_id,
			'site_name' => wp_strip_all_tags( (string) $site->blogname ),
			'site_url'  => (string) $site->siteurl,
			'roles'     => self::roles_on_site( $user_id, $site_id ),
		);
	}
}
