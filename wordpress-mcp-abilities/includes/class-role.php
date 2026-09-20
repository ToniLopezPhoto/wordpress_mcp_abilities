<?php
/**
 * WordPress MCP Abilities — Role management.
 *
 * Creates and reconciles the `wp_mcp_agent` role with only the
 * capabilities required by the content abilities. User and role
 * administration remains gated by native administrator capabilities. A small versioning
 * mechanism ensures the role is updated when the plugin is upgraded.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Role
 */
class WP_MCP_Role {

	/**
	 * Role slug.
	 *
	 * @var string
	 */
	const ROLE_SLUG = 'wp_mcp_agent';

	/**
	 * Human-readable role name.
	 *
	 * @var string
	 */
	const ROLE_NAME = 'WordPress MCP Agent';

	/**
	 * Option key that stores the current role version.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'wp_mcp_role_version';

	/**
	 * Capabilities that should be assigned to the role in this version.
	 *
	 * @return array<string,bool>
	 */
	private static function desired_capabilities() {
		return array(
			'read'                 => true,
			'edit_posts'           => true,
			'edit_published_posts' => true,
			'publish_posts'        => true,
		);
	}

	/**
	 * All capabilities that this plugin has EVER managed.
	 *
	 * Used during reconciliation so that capabilities removed between
	 * versions (e.g. `upload_files` removed in v0.1.0) are cleaned up.
	 * Capabilities added by other plugins or the admin are left untouched.
	 *
	 * @return string[]
	 */
	private static function managed_capabilities() {
		return array(
			'read',
			'edit_posts',
			'edit_published_posts',
			'publish_posts',
			'upload_files', // Removed in v0.1.0 — retained here for cleanup.
		);
	}

	/**
	 * Create the role if it does not exist, or reconcile it.
	 *
	 * Safe to call on every activation and on `admin_init`.
	 */
	public static function create_or_reconcile() {
		$role = get_role( self::ROLE_SLUG );

		if ( ! $role ) {
			add_role( self::ROLE_SLUG, self::ROLE_NAME, self::desired_capabilities() );
			update_option( self::VERSION_OPTION, WP_MCP_ROLE_VERSION );
			return;
		}

		self::reconcile( $role );
	}

	/**
	 * Reconcile only if the stored version differs from the expected one.
	 *
	 * Hooked to `admin_init` so that upgrades are applied automatically.
	 */
	public static function maybe_reconcile() {
		$stored = get_option( self::VERSION_OPTION, '0' );
		if ( WP_MCP_ROLE_VERSION === $stored ) {
			return; // Already up to date — no-op.
		}

		$role = get_role( self::ROLE_SLUG );
		if ( ! $role ) {
			self::create_or_reconcile();
			return;
		}

		self::reconcile( $role );
	}

	/**
	 * Bring the role's capabilities in line with the desired set.
	 *
	 * - Adds any missing desired capabilities.
	 * - Removes any managed capabilities that are no longer desired.
	 * - Leaves capabilities NOT in $managed_capabilities untouched.
	 *
	 * @param WP_Role $role Role object.
	 */
	private static function reconcile( $role ) {
		$desired = self::desired_capabilities();
		$managed = self::managed_capabilities();

		// Add missing capabilities.
		foreach ( $desired as $cap => $grant ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap, $grant );
			}
		}

		// Remove managed capabilities that are no longer desired.
		foreach ( $managed as $cap ) {
			if ( ! isset( $desired[ $cap ] ) && $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}

		update_option( self::VERSION_OPTION, WP_MCP_ROLE_VERSION );
	}
}
