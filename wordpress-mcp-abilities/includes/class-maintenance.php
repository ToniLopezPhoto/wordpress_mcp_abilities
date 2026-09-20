<?php
/**
 * WordPress MCP Abilities — Cache and maintenance callbacks.
 *
 * Design constraints (epic #1, issue #12):
 *
 *  - Every operation is a fixed, individually named action. There is no
 *    `delete-transient( key )` and no `clear-cache( group )`: a caller never
 *    supplies a transient name, an option name or a cache group, so none of
 *    these abilities can be turned into generic option or cache access.
 *  - Maintenance mode goes through WordPress own `WP_Upgrader::maintenance_mode()`
 *    and the WP_Filesystem abstraction — the same code path core uses during an
 *    update — never through direct file writes, and the caller never sees or
 *    supplies a filesystem path.
 *  - Database repair/optimize is deliberately **not** exposed. WordPress ships
 *    no capability-safe API for it: `wp-admin/maint/repair.php` is a standalone
 *    screen gated on the `WP_ALLOW_REPAIR` constant, not a function, and every
 *    other route would mean running SQL supplied to or composed by an ability,
 *    which epic #1 forbids outright.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Maintenance
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Maintenance {

	/**
	 * Site transients holding the core/plugin/theme update check results.
	 *
	 * A fixed list, never a caller-supplied transient name.
	 *
	 * @var string[]
	 */
	const UPDATE_TRANSIENTS = array( 'update_core', 'update_plugins', 'update_themes' );

	/**
	 * How long WordPress itself considers a `.maintenance` marker to be live,
	 * in seconds (see `wp_maintenance()` in wp-includes/load.php).
	 */
	const MAINTENANCE_WINDOW = 600;

	/* ==================================================================
	 * Object cache
	 * ================================================================ */

	/**
	 * Flush the WordPress object cache.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function flush_object_cache( $input = array() ) {
		$denied = self::require_manage_options();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$external = function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false;
		$flushed  = (bool) wp_cache_flush();

		WP_MCP_Audit::log( 'wp-mcp/flush-object-cache', 0, $flushed, $flushed ? '' : 'wp_mcp_maintenance_failed', array( 'external' => $external ) );

		if ( ! $flushed ) {
			return WP_MCP_Errors::maintenance_failed( __( 'The object cache backend refused the flush.', 'wordpress-mcp-abilities' ) );
		}

		return array(
			'flushed'                    => true,
			'external_object_cache'      => $external,
			'persistent_cache_available' => $external,
		);
	}

	/* ==================================================================
	 * Transients
	 * ================================================================ */

	/**
	 * Delete every *expired* transient through WordPress own cleanup routine.
	 *
	 * Only expired entries are removed — this is the same maintenance core runs
	 * on its own `delete_expired_transients` cron hook, not a cache purge and
	 * not an arbitrary option delete.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function clear_expired_transients( $input = array() ) {
		$denied = self::require_manage_options();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		if ( ! function_exists( 'delete_expired_transients' ) ) {
			return WP_MCP_Errors::maintenance_unsupported( __( 'This WordPress installation does not expose the expired-transient cleanup routine.', 'wordpress-mcp-abilities' ) );
		}

		// The force_db flag also cleans the options table when an external
		// object cache is in use, which is where expired rows would otherwise
		// accumulate unnoticed.
		delete_expired_transients( true );

		WP_MCP_Audit::log( 'wp-mcp/clear-expired-transients', 0, true, '', array() );

		return array(
			'cleared'            => true,
			'scope'              => 'expired_only',
			'external_object_cache' => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false,
		);
	}

	/**
	 * Drop the cached core/plugin/theme update check results so the next
	 * status read is fresh.
	 *
	 * The three transient names are a fixed constant in this file; the caller
	 * never names a transient.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function clear_update_caches( $input = array() ) {
		$denied = self::require_manage_options();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$cleared = array();
		foreach ( self::UPDATE_TRANSIENTS as $transient ) {
			if ( delete_site_transient( $transient ) ) {
				$cleared[] = $transient;
			}
		}

		WP_MCP_Audit::log( 'wp-mcp/clear-update-caches', 0, true, '', array( 'cleared' => implode( ',', $cleared ) ) );

		return array(
			'cleared'   => $cleared,
			'available' => array_values( self::UPDATE_TRANSIENTS ),
		);
	}

	/* ==================================================================
	 * Maintenance mode
	 * ================================================================ */

	/**
	 * Report whether WordPress is currently serving the maintenance page.
	 *
	 * The marker's age is read from the file's modification time rather than by
	 * including it: the `.maintenance` file is PHP, and this plugin does not
	 * execute PHP from disk to answer a read.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_maintenance_mode( $input = array() ) {
		$denied = self::require_manage_options();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		return self::maintenance_state();
	}

	/**
	 * Read the `.maintenance` marker state, without a capability check.
	 *
	 * Shared by the read ability and by the write ability's confirmation step,
	 * so a role holding `update_core` but not `manage_options` still gets its
	 * write confirmed.
	 *
	 * @return array<string,mixed>
	 */
	private static function maintenance_state() {
		$marker  = ABSPATH . '.maintenance';
		$present = file_exists( $marker );
		$since   = $present ? (int) filemtime( $marker ) : 0;
		$expires = $since ? $since + self::MAINTENANCE_WINDOW : 0;

		return array(
			'enabled'           => $present && $expires > time(),
			'marker_present'    => $present,
			'enabled_since_utc' => $since ? gmdate( 'c', $since ) : '',
			'expires_utc'       => $expires ? gmdate( 'c', $expires ) : '',
			'window_seconds'    => self::MAINTENANCE_WINDOW,
		);
	}

	/**
	 * Enable or disable maintenance mode.
	 *
	 * Gated on `update_core` rather than `manage_options`: the `.maintenance`
	 * marker lives at the WordPress root and takes an entire multisite network
	 * offline, and WordPress restricts `update_core` to Super Admins on
	 * multisite. Writing it through `WP_Upgrader::maintenance_mode()` keeps the
	 * operation inside the WP_Filesystem abstraction that already governs the
	 * plugin/theme/core update abilities.
	 *
	 * WordPress stops honouring the marker after ten minutes on its own, so a
	 * forgotten `enabled: true` self-heals rather than leaving the site dark.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_maintenance_mode( $input = array() ) {
		if ( ! current_user_can( 'update_core' ) ) {
			return WP_MCP_Errors::system_permission_denied( __( 'You do not have permission to change maintenance mode.', 'wordpress-mcp-abilities' ) );
		}

		if ( ! isset( $input['enabled'] ) || ! is_bool( $input['enabled'] ) ) {
			return WP_MCP_Errors::maintenance_validation_error( __( 'The "enabled" field is required and must be a boolean.', 'wordpress-mcp-abilities' ) );
		}
		$enable = (bool) $input['enabled'];

		$upgrader = self::upgrader();
		if ( is_wp_error( $upgrader ) ) {
			return $upgrader;
		}

		$upgrader->maintenance_mode( $enable );

		clearstatcache();
		$state = self::maintenance_state();

		if ( $enable !== $state['marker_present'] ) {
			WP_MCP_Audit::log( 'wp-mcp/set-maintenance-mode', 0, false, 'wp_mcp_maintenance_failed', array( 'enabled' => $enable ) );
			return WP_MCP_Errors::maintenance_failed( __( 'WordPress could not write the maintenance marker. Check that the filesystem is writable.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/set-maintenance-mode', 0, true, '', array( 'enabled' => $enable ) );

		return array(
			'enabled'           => $state['enabled'],
			'marker_present'    => $state['marker_present'],
			'enabled_since_utc' => $state['enabled_since_utc'],
			'expires_utc'       => $state['expires_utc'],
			'window_seconds'    => self::MAINTENANCE_WINDOW,
		);
	}

	/* ==================================================================
	 * Internal helpers
	 * ================================================================ */

	/**
	 * Shared `manage_options` gate.
	 *
	 * @return true|WP_Error
	 */
	private static function require_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return WP_MCP_Errors::system_permission_denied( __( 'You do not have permission to run site maintenance operations.', 'wordpress-mcp-abilities' ) );
		}
		return true;
	}

	/**
	 * Build a filesystem-connected `WP_Upgrader`, or explain why not.
	 *
	 * @return WP_Upgrader|WP_Error
	 */
	private static function upgrader() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
		if ( ! class_exists( 'WP_Upgrader' ) || ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			return WP_MCP_Errors::maintenance_unsupported( __( 'The WordPress upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		$upgrader = new WP_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$upgrader->init();

		$connected = $upgrader->fs_connect( array( ABSPATH ) );
		if ( is_wp_error( $connected ) ) {
			return WP_MCP_Errors::maintenance_unsupported( $connected->get_error_message() );
		}
		if ( ! $connected ) {
			return WP_MCP_Errors::maintenance_unsupported( __( 'WordPress could not obtain filesystem access with the credentials available to this request.', 'wordpress-mcp-abilities' ) );
		}

		return $upgrader;
	}
}
