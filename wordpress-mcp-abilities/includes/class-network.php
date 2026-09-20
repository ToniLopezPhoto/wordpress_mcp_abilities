<?php
/**
 * WordPress MCP Abilities — Multisite network guards, inspection and updates.
 *
 * Shared entry point for every network domain (issue #13). Three rules hold
 * across all of them:
 *
 *  - **Nothing here assumes Super Admin.** Being an administrator of a site
 *    never implies a network capability: every operation checks the concrete
 *    network capability WordPress itself uses for that screen
 *    (`manage_sites`, `manage_network_users`, `manage_network_plugins`,
 *    `manage_network_themes`, `manage_network_options`, `upgrade_network`),
 *    never a blanket `manage_options`.
 *  - **Single-site installations degrade cleanly.** The abilities are always
 *    registered so the permission matrix and the registered surface cannot
 *    drift apart, but their permission callbacks are false off a network and
 *    their callbacks answer `wp_mcp_network_unsupported` (501) — never a
 *    fatal, never a misleading permission failure, and never a silently empty
 *    success. `wp-mcp/get-network-info` is the single deliberate exception:
 *    it answers on both, reporting `is_multisite: false`, so an agent can
 *    discover the shape of the installation before trying anything else.
 *  - **No generic network option access.** Network settings are reachable
 *    only through the declarative allowlist in `WP_MCP_Network_Settings`;
 *    no ability accepts a network option name.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Network {

	/** Maximum number of rows returned by one network list request. */
	const MAX_PER_PAGE = 50;

	/** Maximum number of sites upgraded by one upgrade-network-sites call. */
	const MAX_UPGRADE_BATCH = 50;

	/* ------------------------------------------------------------------
	 * Guards shared by every network domain
	 * ---------------------------------------------------------------- */

	/**
	 * Whether this installation is a multisite network at all.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return is_multisite();
	}

	/**
	 * Permission-callback helper: a network capability is only ever granted
	 * on a network. On a single site this is false, so the ability is not
	 * offered rather than being offered and then refused.
	 *
	 * @param string $capability Network capability.
	 * @return bool
	 */
	public static function can( $capability ) {
		return is_multisite() && current_user_can( $capability );
	}

	/**
	 * Execute-callback guard: multisite first, then the network capability.
	 *
	 * The order matters. A single-site installation must always answer
	 * "unsupported", never "permission denied": the second would tell an
	 * agent to go find credentials for a network that does not exist.
	 *
	 * @param string $capability Network capability.
	 * @return true|WP_Error
	 */
	public static function require_network_cap( $capability ) {
		if ( ! is_multisite() ) {
			return WP_MCP_Errors::network_unsupported();
		}
		if ( ! current_user_can( $capability ) ) {
			return WP_MCP_Errors::network_permission_denied(
				sprintf(
					/* translators: %s: WordPress network capability name */
					__( 'This operation requires the %s capability on this network.', 'wordpress-mcp-abilities' ),
					$capability
				)
			);
		}
		return true;
	}

	/**
	 * Count the sites matching a WP_Site_Query argument set.
	 *
	 * `get_sites()` returns either rows or a count depending on its
	 * arguments; this normalises both shapes into an int.
	 *
	 * @param array $args WP_Site_Query arguments (number/offset are ignored).
	 * @return int
	 */
	public static function count_sites( array $args = array() ) {
		unset( $args['number'], $args['offset'] );
		$args['count'] = true;
		$total         = get_sites( $args );
		return is_array( $total ) ? count( $total ) : (int) $total;
	}

	/**
	 * Fetch WP_Site rows for a query, discarding anything that is not a site.
	 *
	 * @param array $args WP_Site_Query arguments.
	 * @return WP_Site[]
	 */
	public static function query_sites( array $args ) {
		$rows = get_sites( $args );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$sites = array();
		foreach ( $rows as $row ) {
			if ( $row instanceof WP_Site ) {
				$sites[] = $row;
			}
		}
		return $sites;
	}

	/**
	 * Validate a site_id input against the sites of the current network.
	 *
	 * @param mixed $raw Raw site_id from ability input.
	 * @return WP_Site|WP_Error
	 */
	public static function validate_site_id( $raw ) {
		if ( ! WP_MCP_Permissions::is_strict_positive_int_id( $raw ) ) {
			return WP_MCP_Errors::network_validation_error( __( 'site_id must be a positive integer.', 'wordpress-mcp-abilities' ) );
		}
		$site = get_site( (int) $raw );
		if ( ! $site instanceof WP_Site ) {
			return WP_MCP_Errors::invalid_network_site();
		}
		if ( get_current_network_id() !== (int) $site->network_id ) {
			return WP_MCP_Errors::invalid_network_site();
		}
		return $site;
	}

	/* ------------------------------------------------------------------
	 * Network inspection
	 * ---------------------------------------------------------------- */

	/**
	 * Describe the network, or report that there is none.
	 *
	 * The one network ability that answers on a single-site installation:
	 * it is how an agent discovers whether the rest of this domain applies.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_network_info( $input = array() ) {
		if ( ! is_multisite() ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return WP_MCP_Errors::network_permission_denied( __( 'You do not have permission to inspect the network configuration of this installation.', 'wordpress-mcp-abilities' ) );
			}
			return self::single_site_info();
		}

		$denied = self::require_network_cap( 'manage_network' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		global $wp_db_version;

		return array(
			'is_multisite'                => true,
			'network_id'                  => get_current_network_id(),
			'network_name'                => (string) get_network_option( null, 'site_name', '' ),
			'network_home_url'            => (string) network_home_url(),
			'network_site_url'            => (string) network_site_url(),
			'subdomain_install'           => (bool) is_subdomain_install(),
			'main_site_id'                => get_main_site_id(),
			'current_site_id'             => get_current_blog_id(),
			'site_count'                  => self::network_site_count(),
			'user_count'                  => (int) get_user_count(),
			'current_user_is_super_admin' => is_super_admin(),
			'upgrade_required'            => (int) get_site_option( 'wpmu_upgrade_site', 0 ) !== (int) $wp_db_version,
		);
	}

	/**
	 * Count the sites on the current network.
	 *
	 * `get_blog_count()` reads the cached `blog_count` network option, which
	 * WordPress refreshes only when a site is created or deleted, so a network
	 * that has never written it answers 0 while sites plainly exist. Count the
	 * rows instead — except on a large network, where core itself skips the
	 * query and the cached counter is the number the network admin screens
	 * show.
	 *
	 * `user_count` above is deliberately left on `get_user_count()`: its live
	 * equivalent is a per-site count, not a network one, so the cached counter
	 * is the only figure that answers the question the field asks.
	 *
	 * @since 0.13.0
	 *
	 * @return int
	 */
	private static function network_site_count() {
		if ( wp_is_large_network( 'sites' ) ) {
			return (int) get_blog_count();
		}

		return (int) get_sites(
			array(
				'network_id' => get_current_network_id(),
				'count'      => true,
			)
		);
	}

	/**
	 * The single-site answer: every network field neutral, `is_multisite`
	 * false. `current_user_is_super_admin` is reported as false rather than
	 * as `is_super_admin()`, which off a network merely means "can delete
	 * users" and would be read as a network privilege.
	 *
	 * @return array<string,mixed>
	 */
	private static function single_site_info() {
		return array(
			'is_multisite'                => false,
			'network_id'                  => 0,
			'network_name'                => '',
			'network_home_url'            => '',
			'network_site_url'            => '',
			'subdomain_install'           => false,
			'main_site_id'                => 0,
			'current_site_id'             => 0,
			'site_count'                  => 0,
			'user_count'                  => 0,
			'current_user_is_super_admin' => false,
			'upgrade_required'            => false,
		);
	}

	/* ------------------------------------------------------------------
	 * Network updates
	 * ---------------------------------------------------------------- */

	/**
	 * Report whether the network needs the per-site database upgrade that
	 * follows a core update, plus the network-wide update counts.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_network_update_status( $input = array() ) {
		$denied = self::require_network_cap( 'manage_network' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		global $wp_db_version;

		$network_db_version = (int) get_site_option( 'wpmu_upgrade_site', 0 );
		$core               = get_site_transient( 'update_core' );
		$plugins            = get_site_transient( 'update_plugins' );
		$themes             = get_site_transient( 'update_themes' );

		$core_update_available = false;
		if ( isset( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $update ) {
				if ( isset( $update->response ) && 'upgrade' === $update->response ) {
					$core_update_available = true;
					break;
				}
			}
		}

		return array(
			'upgrade_required'        => $network_db_version !== (int) $wp_db_version,
			'wp_db_version'           => (int) $wp_db_version,
			'network_db_version'      => $network_db_version,
			'sites_total'             => self::count_sites( array( 'network_id' => get_current_network_id() ) ),
			'core_update_available'   => $core_update_available,
			'plugin_updates'          => ( isset( $plugins->response ) && is_array( $plugins->response ) ) ? count( $plugins->response ) : 0,
			'theme_updates'           => ( isset( $themes->response ) && is_array( $themes->response ) ) ? count( $themes->response ) : 0,
			'network_activated_count' => count( (array) get_site_option( 'active_sitewide_plugins', array() ) ),
		);
	}

	/**
	 * Run WordPress's own per-site database upgrade across a bounded batch
	 * of sites — the "Upgrade Network" operation of Network Admin.
	 *
	 * Deliberately not an HTTP fan-out over every site's `upgrade.php` (what
	 * the wp-admin screen does): that would mean this plugin issuing requests
	 * to caller-influenced URLs. It switches to each site and calls core's
	 * own `wp_upgrade()`, which returns immediately for a site already at the
	 * current database version — so the ability is idempotent and safe to
	 * repeat.
	 *
	 * @param array $input Ability input: optional `max_sites`, `offset`.
	 * @return array|WP_Error
	 */
	public static function upgrade_network_sites( $input = array() ) {
		$denied = self::require_network_cap( 'upgrade_network' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$max_sites = isset( $input['max_sites'] ) ? absint( $input['max_sites'] ) : 25;
		$max_sites = max( 1, min( self::MAX_UPGRADE_BATCH, $max_sites ) );
		$offset    = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! function_exists( 'wp_upgrade' ) ) {
			return WP_MCP_Errors::network_update_failed( __( 'The WordPress upgrade routines are unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		global $wp_db_version;

		$network_id = get_current_network_id();
		$sites      = self::query_sites(
			array(
				'number'     => $max_sites,
				'offset'     => $offset,
				'network_id' => $network_id,
				'orderby'    => 'id',
				'order'      => 'ASC',
			)
		);

		$upgraded = array();
		foreach ( $sites as $site ) {
			$site_id = (int) $site->id;
			switch_to_blog( $site_id );
			wp_upgrade();
			restore_current_blog();
			$upgraded[] = $site_id;
		}

		$total     = self::count_sites( array( 'network_id' => $network_id ) );
		$processed = $offset + count( $upgraded );
		$remaining = max( 0, $total - $processed );

		if ( 0 === $remaining ) {
			update_site_option( 'wpmu_upgrade_site', (int) $wp_db_version );
		}

		WP_MCP_Audit::log(
			'wp-mcp/upgrade-network-sites',
			0,
			true,
			'',
			array(
				'sites'  => count( $upgraded ),
				'offset' => $offset,
			)
		);

		return array(
			'upgraded'           => $upgraded,
			'upgraded_count'     => count( $upgraded ),
			'offset'             => $offset,
			'next_offset'        => $processed,
			'remaining'          => $remaining,
			'sites_total'        => $total,
			'network_db_version' => (int) get_site_option( 'wpmu_upgrade_site', 0 ),
		);
	}
}
