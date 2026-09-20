<?php
/**
 * WordPress MCP Abilities — Plugin management callbacks.
 *
 * Every operation delegates authorization to WordPress's native, primitive
 * (non-object) plugin capabilities (`activate_plugins`, `install_plugins`,
 * `update_plugins`, `delete_plugins`) — there is no per-plugin meta-capability
 * in WordPress core, so the capability check alone is the authorization gate,
 * matching the pattern already used for role administration.
 *
 * Install, update, and delete are always separate abilities. A remote install
 * never activates the plugin — activation is an explicit follow-up call.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Plugins
 */
class WP_MCP_Plugins {

	/** Maximum number of plugins returned by one list request. */
	const MAX_PER_PAGE = 50;

	/** Maximum size, in bytes, accepted for a downloaded plugin package. */
	const MAX_PACKAGE_BYTES = 52428800; // 50 MB.

	/** Maximum number of plugins one explicit bulk update call may target. */
	const MAX_BULK_UPDATES = 20;

	/** Maximum number of plugins update-all-plugins will process in one call. */
	const MAX_BULK_UPDATE_ALL = 50;

	/** Network option holding the plugins opted in to auto-updates. */
	const AUTO_UPDATE_OPTION = 'auto_update_plugins';

	/**
	 * List installed plugins with bounded pagination.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_plugins( $input ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		self::ensure_plugin_functions();

		$all = get_plugins();
		if ( ! empty( $input['search'] ) ) {
			$needle = strtolower( sanitize_text_field( $input['search'] ) );
			$all    = array_filter(
				$all,
				function ( $data ) use ( $needle ) {
					return false !== strpos( strtolower( $data['Name'] ), $needle );
				}
			);
		}

		$files = array_keys( $all );
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( (array) $input, 20, self::MAX_PER_PAGE );
		$total = count( $files );
		$slice = array_slice( $files, ( $page - 1 ) * $per_page, $per_page );

		$plugins = array();
		foreach ( $slice as $file ) {
			$plugins[] = self::format_plugin( $file, $all[ $file ] );
		}

		return array(
			'plugins'     => $plugins,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get one installed plugin.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_plugin( $input ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$file = self::validate_installed_plugin_file( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$plugins = get_plugins();
		return self::format_plugin( $file, $plugins[ $file ] );
	}

	/**
	 * Activate an installed plugin. Idempotent: activating an already-active
	 * plugin returns its current state rather than erroring.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function activate_plugin( $input ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$file = self::validate_installed_plugin_file( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$network_wide   = ! empty( $input['network_wide'] );
		$network_denied = self::network_authority_error(
			$network_wide,
			__( 'Network-wide activation requires a multisite network administrator.', 'wordpress-mcp-abilities' )
		);
		if ( $network_denied ) {
			WP_MCP_Audit::log( 'wp-mcp/activate-plugin', 0, false, 'wp_mcp_plugin_permission_denied', array( 'slug' => $file ) );
			return $network_denied;
		}

		// A plugin active only at site level is not active for the network, and
		// core's is_plugin_active() answers true for either scope — so the
		// no-op check has to ask the question matching the requested scope, or
		// a network-wide activation of a site-active plugin silently does
		// nothing.
		$already = $network_wide ? is_plugin_active_for_network( $file ) : is_plugin_active( $file );
		if ( $already ) {
			$plugins = get_plugins();
			return self::format_plugin( $file, $plugins[ $file ] );
		}

		// activate_plugin() here is WordPress core's global function, not a
		// recursive call to this method.
		$result = activate_plugin( $file, '', $network_wide, true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/activate-plugin', 0, false, 'wp_mcp_plugin_activate_failed', array( 'slug' => $file ) );
			return WP_MCP_Errors::plugin_activate_failed( $result->get_error_message() );
		}

		WP_MCP_Audit::log( 'wp-mcp/activate-plugin', 0, true, '', array( 'slug' => $file ) );
		$plugins = get_plugins();
		return self::format_plugin( $file, $plugins[ $file ] );
	}

	/**
	 * Deactivate an installed plugin. Idempotent: deactivating an
	 * already-inactive plugin is a no-op success.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function deactivate_plugin( $input ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$file = self::validate_installed_plugin_file( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$network_wide   = ! empty( $input['network_wide'] );
		$network_denied = self::network_authority_error(
			$network_wide,
			__( 'Network-wide deactivation requires a multisite network administrator.', 'wordpress-mcp-abilities' )
		);
		if ( $network_denied ) {
			WP_MCP_Audit::log( 'wp-mcp/deactivate-plugin', 0, false, 'wp_mcp_plugin_permission_denied', array( 'slug' => $file ) );
			return $network_denied;
		}

		deactivate_plugins( array( $file ), false, $network_wide );

		WP_MCP_Audit::log( 'wp-mcp/deactivate-plugin', 0, true, '', array( 'slug' => $file ) );
		$plugins = get_plugins();
		return self::format_plugin( $file, $plugins[ $file ] );
	}

	/**
	 * Install a plugin from the official WordPress.org repository by slug.
	 * Never activates the plugin.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function install_plugin_from_repo( $input ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$slug = self::validate_slug( isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		self::ensure_upgrader_classes();
		if ( ! self::upgrader_classes_available() ) {
			WP_MCP_Audit::log( 'wp-mcp/install-plugin-from-repo', 0, false, 'wp_mcp_plugin_install_failed', array( 'slug' => $slug ) );
			return WP_MCP_Errors::plugin_install_failed( __( 'The WordPress plugin upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections' => false,
					'reviews'  => false,
					'banners'  => false,
					'icons'    => false,
				),
			)
		);
		if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-plugin-from-repo', 0, false, 'wp_mcp_plugin_install_failed', array( 'slug' => $slug ) );
			return WP_MCP_Errors::plugin_install_failed( __( 'The requested plugin was not found in the WordPress.org repository.', 'wordpress-mcp-abilities' ) );
		}

		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$installed = self::run_download_limited(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $api ) {
				return $upgrader->install( $api->download_link );
			}
		);

		$failure = self::install_failure( $installed );
		if ( is_wp_error( $failure ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-plugin-from-repo', 0, false, 'wp_mcp_plugin_install_failed', array( 'slug' => $slug ) );
			return $failure;
		}

		$file = self::resolve_installed_plugin_file( $upgrader, $slug );
		if ( is_wp_error( $file ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-plugin-from-repo', 0, false, $file->get_error_code(), array( 'slug' => $slug ) );
			return $file;
		}

		WP_MCP_Audit::log(
			'wp-mcp/install-plugin-from-repo',
			0,
			true,
			'',
			array(
				'slug'       => $slug,
				'to_version' => isset( $api->version ) ? (string) $api->version : '',
			)
		);
		$plugins = get_plugins();
		return self::format_plugin( $file, $plugins[ $file ] );
	}

	/**
	 * Install a plugin from an explicit HTTP(S) zip URL, subject to the same
	 * SSRF/host policy as upload-media-from-url. Never activates the plugin.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function install_plugin_from_url( $input ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$url = isset( $input['url'] ) ? $input['url'] : '';
		$url_error = WP_MCP_Permissions::validate_remote_url( $url, $input );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		self::ensure_upgrader_classes();
		if ( ! self::upgrader_classes_available() ) {
			WP_MCP_Audit::log( 'wp-mcp/install-plugin-from-url', 0, false, 'wp_mcp_plugin_install_failed' );
			return WP_MCP_Errors::plugin_install_failed( __( 'The WordPress plugin upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		$upgrader  = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$installed = self::run_download_limited(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $url ) {
				return $upgrader->install( $url );
			}
		);

		$failure = self::install_failure( $installed );
		if ( is_wp_error( $failure ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-plugin-from-url', 0, false, 'wp_mcp_plugin_install_failed' );
			return $failure;
		}

		$file = self::resolve_installed_plugin_file( $upgrader );
		if ( is_wp_error( $file ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-plugin-from-url', 0, false, $file->get_error_code() );
			return $file;
		}

		$plugins = get_plugins();
		WP_MCP_Audit::log(
			'wp-mcp/install-plugin-from-url',
			0,
			true,
			'',
			array(
				'slug'       => dirname( $file ),
				'to_version' => isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '',
			)
		);
		return self::format_plugin( $file, $plugins[ $file ] );
	}

	/**
	 * Update an installed plugin to the latest available version. Relies on
	 * WordPress core's own automatic temp-backup-and-restore-on-failure
	 * mechanism (available since WP 6.3 for single-plugin upgrades); this
	 * ability does not implement its own rollback.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_plugin( $input ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$file = self::validate_installed_plugin_file( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$before       = get_plugins();
		$from_version = isset( $before[ $file ]['Version'] ) ? $before[ $file ]['Version'] : '';

		self::ensure_upgrader_classes();
		if ( ! self::upgrader_classes_available() ) {
			WP_MCP_Audit::log(
				'wp-mcp/update-plugin',
				0,
				false,
				'wp_mcp_plugin_update_failed',
				array(
					'slug'         => $file,
					'from_version' => $from_version,
				)
			);
			return WP_MCP_Errors::plugin_update_failed( __( 'The WordPress plugin upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = self::run_download_limited(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $file ) {
				return $upgrader->upgrade( $file );
			}
		);

		wp_clean_plugins_cache( false );
		$after      = get_plugins();
		$to_version = isset( $after[ $file ]['Version'] ) ? $after[ $file ]['Version'] : $from_version;
		$context    = array(
			'slug'         => $file,
			'from_version' => $from_version,
			'to_version'   => $to_version,
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-plugin', 0, false, 'wp_mcp_plugin_update_failed', $context );
			return WP_MCP_Errors::plugin_update_failed( $result->get_error_message() );
		}
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/update-plugin', 0, false, 'wp_mcp_plugin_update_failed', $context );
			return WP_MCP_Errors::plugin_update_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/update-plugin', 0, true, '', $context );
		return self::format_plugin( $file, $after[ $file ] );
	}

	/**
	 * Update an explicit list of installed plugins in one pass. Every entry is
	 * validated against the installed set before the upgrader is touched, so a
	 * single bad identifier fails the call rather than half-updating the list.
	 *
	 * @since 0.9.0
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_plugins( $input ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$files = self::validate_plugin_file_list(
			isset( $input['plugin_files'] ) ? $input['plugin_files'] : null,
			self::MAX_BULK_UPDATES
		);
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		return self::run_bulk_update( 'wp-mcp/update-plugins', $files );
	}

	/**
	 * Update every installed plugin that currently has an update available.
	 *
	 * Deliberately a separate ability rather than an optional flag on
	 * update-plugins: "update everything" has an unbounded blast radius and
	 * must never be what happens when a caller forgets a parameter.
	 *
	 * @since 0.9.0
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function update_all_plugins( $input = array() ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$files = self::plugins_with_updates();
		if ( empty( $files ) ) {
			return WP_MCP_Errors::no_updates_pending();
		}
		if ( count( $files ) > self::MAX_BULK_UPDATE_ALL ) {
			return WP_MCP_Errors::bulk_limit_exceeded( self::MAX_BULK_UPDATE_ALL );
		}
		return self::run_bulk_update( 'wp-mcp/update-all-plugins', $files );
	}

	/**
	 * Opt one installed plugin in or out of WordPress's own auto-updates.
	 * Idempotent: setting the state it already has is a no-op success.
	 *
	 * @since 0.9.0
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_plugin_auto_update( $input ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$file = self::validate_installed_plugin_file( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! isset( $input['enabled'] ) || ! is_bool( $input['enabled'] ) ) {
			return WP_MCP_Errors::plugin_validation_error( __( 'enabled must be a boolean.', 'wordpress-mcp-abilities' ) );
		}
		$enabled = (bool) $input['enabled'];

		/*
		 * auto_update_plugins is a *network* option: on multisite one write
		 * changes update behavior for every site on the network, so per-site
		 * update_plugins is not enough authority on its own.
		 */
		$network_denied = self::network_authority_error(
			is_multisite(),
			__( 'Configuring plugin auto-updates on multisite requires a network administrator.', 'wordpress-mcp-abilities' )
		);
		if ( $network_denied ) {
			return $network_denied;
		}

		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) || ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
			return WP_MCP_Errors::plugin_auto_update_unavailable();
		}

		$installed = get_plugins();
		$forced    = self::auto_update_forced( $file, $installed[ $file ] );
		if ( null !== $forced ) {
			// A filter already decides this plugin's auto-update state, so
			// writing the option would silently do nothing.
			return WP_MCP_Errors::plugin_auto_update_unavailable(
				$forced
					? __( 'Auto-updates for this plugin are force-enabled by a filter and cannot be changed.', 'wordpress-mcp-abilities' )
					: __( 'Auto-updates for this plugin are force-disabled by a filter and cannot be changed.', 'wordpress-mcp-abilities' )
			);
		}

		// Drop entries for plugins deleted since the option was last written,
		// exactly as core's own toggle does.
		$auto_updates = array_values( array_intersect( (array) get_site_option( self::AUTO_UPDATE_OPTION, array() ), array_keys( $installed ) ) );
		$was_enabled  = in_array( $file, $auto_updates, true );

		if ( $enabled !== $was_enabled ) {
			if ( $enabled ) {
				$auto_updates[] = $file;
			} else {
				$auto_updates = array_values( array_diff( $auto_updates, array( $file ) ) );
			}
			update_site_option( self::AUTO_UPDATE_OPTION, array_values( array_unique( $auto_updates ) ) );

			WP_MCP_Audit::log(
				'wp-mcp/set-plugin-auto-update',
				0,
				true,
				'',
				array(
					'slug'        => $file,
					'auto_update' => $enabled ? 'enabled' : 'disabled',
				)
			);
		}

		return array(
			'file'        => $file,
			'auto_update' => $enabled,
		);
	}

	/**
	 * Permanently delete an installed plugin. Refuses to delete an active
	 * plugin — deactivate it first with a separate call.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_plugin( $input ) {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied();
		}
		$file = self::validate_installed_plugin_file( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		// Captured before the delete: afterwards the plugin header is gone and
		// the audit trail could no longer say which version was removed.
		$installed = get_plugins();
		$context   = array(
			'slug'         => $file,
			'from_version' => isset( $installed[ $file ]['Version'] ) ? (string) $installed[ $file ]['Version'] : '',
		);

		if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-plugin', 0, false, 'wp_mcp_plugin_active_conflict', $context );
			return WP_MCP_Errors::plugin_active_conflict();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$result = delete_plugins( array( $file ) );

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-plugin', 0, false, 'wp_mcp_plugin_delete_failed', $context );
			return WP_MCP_Errors::plugin_delete_failed( $result->get_error_message() );
		}
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-plugin', 0, false, 'wp_mcp_plugin_delete_failed', $context );
			return WP_MCP_Errors::plugin_delete_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/delete-plugin', 0, true, '', $context );
		return array(
			'file'    => $file,
			'deleted' => true,
		);
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ---------------------------------------------------------------- */

	/** Load get_plugins()/is_plugin_active() et al. if not already loaded. */
	private static function ensure_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Load plugins_api() and the upgrader/skin classes if not already
	 * loaded. class-wp-upgrader.php pulls in Plugin_Upgrader itself on all
	 * supported WordPress versions; the direct require of
	 * class-plugin-upgrader.php is a defensive fallback guarded by
	 * file_exists() so an unexpected WordPress core layout degrades to a
	 * graceful error (see the class_exists() check callers must perform)
	 * instead of a fatal missing-file require.
	 */
	private static function ensure_upgrader_classes() {
		self::ensure_plugin_functions();
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) && file_exists( ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
	}

	/** Whether the classes ensure_upgrader_classes() attempts to load are actually available. */
	private static function upgrader_classes_available() {
		return class_exists( 'Plugin_Upgrader' ) && class_exists( 'WP_Ajax_Upgrader_Skin' );
	}

	/**
	 * Guard a network-scoped plugin operation.
	 *
	 * `activate_plugins` on its own is a per-site capability: on multisite a
	 * site administrator can hold it (the network's `menu_items['plugins']`
	 * setting grants it) while having no authority over the network at all.
	 * Every network-wide operation therefore needs `manage_network_plugins` on
	 * top of it, or a site administrator could deactivate a plugin the whole
	 * network depends on.
	 *
	 * @since 0.9.0
	 *
	 * @param bool   $network_wide Whether the caller asked for a network-wide operation.
	 * @param string $message      Message describing the refused operation.
	 * @return WP_Error|null WP_Error when the operation must be refused, null when it may proceed.
	 */
	private static function network_authority_error( $network_wide, $message ) {
		if ( ! $network_wide ) {
			return null;
		}
		if ( ! is_multisite() || ! current_user_can( 'manage_network_plugins' ) ) {
			return WP_MCP_Errors::plugin_permission_denied( $message );
		}
		return null;
	}

	/**
	 * Temporarily cap the size of any HTTP download performed inside
	 * $operation and force reject_unsafe_urls, then restore prior behavior.
	 *
	 * @param int      $max_bytes Maximum response size in bytes.
	 * @param callable $operation Operation to run under the limit.
	 * @return mixed Whatever $operation returns.
	 */
	private static function run_download_limited( $max_bytes, $operation ) {
		return WP_MCP_Permissions::with_download_limit( $max_bytes, $operation );
	}

	/**
	 * Normalize a Plugin_Upgrader install()/upgrade() result into a
	 * WP_Error on failure, or true (not a WP_Error) on success.
	 *
	 * @param mixed $installed Upgrader install()/upgrade() return value.
	 * @return true|WP_Error
	 */
	private static function install_failure( $installed ) {
		if ( is_wp_error( $installed ) ) {
			return WP_MCP_Errors::plugin_install_failed( $installed->get_error_message() );
		}
		if ( ! $installed ) {
			return WP_MCP_Errors::plugin_install_failed();
		}
		return true;
	}

	/**
	 * Resolve the installed plugin's main file path from the upgrader's
	 * result, falling back to a known slug when the upgrader didn't record
	 * a destination folder name.
	 *
	 * @param Plugin_Upgrader $upgrader      Upgrader instance after install().
	 * @param string          $fallback_slug Slug to use if unresolved.
	 * @return string|WP_Error
	 */
	private static function resolve_installed_plugin_file( $upgrader, $fallback_slug = '' ) {
		$folder = ( isset( $upgrader->result['destination_name'] ) && is_string( $upgrader->result['destination_name'] ) && '' !== $upgrader->result['destination_name'] )
			? $upgrader->result['destination_name']
			: $fallback_slug;

		if ( '' === $folder ) {
			return WP_MCP_Errors::plugin_install_failed( __( 'The plugin was installed but its destination folder could not be determined.', 'wordpress-mcp-abilities' ) );
		}

		$plugins = get_plugins( '/' . $folder );
		if ( empty( $plugins ) ) {
			return WP_MCP_Errors::plugin_install_failed( __( 'The plugin was installed but its main file could not be located.', 'wordpress-mcp-abilities' ) );
		}

		$files = array_keys( $plugins );
		return $folder . '/' . $files[0];
	}

	/**
	 * Run one bulk plugin upgrade and report per-plugin outcomes.
	 *
	 * @since 0.9.0
	 *
	 * @param string   $ability Ability name, for the audit trail.
	 * @param string[] $files   Validated, installed plugin files.
	 * @return array|WP_Error
	 */
	private static function run_bulk_update( $ability, array $files ) {
		self::ensure_upgrader_classes();
		if ( ! self::upgrader_classes_available() ) {
			WP_MCP_Audit::log( $ability, 0, false, 'wp_mcp_plugin_update_failed' );
			return WP_MCP_Errors::plugin_update_failed( __( 'The WordPress plugin upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		$before = get_plugins();

		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$results  = self::run_download_limited(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $files ) {
				return $upgrader->bulk_upgrade( $files );
			}
		);
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		wp_clean_plugins_cache( false );
		$after = get_plugins();

		$report  = array();
		$updated = 0;
		foreach ( $files as $file ) {
			$outcome = isset( $results[ $file ] ) ? $results[ $file ] : null;

			/*
			 * Plugin_Upgrader::bulk_upgrade() reports an array for a plugin it
			 * upgraded, true for one already at the latest version, and
			 * WP_Error/false/absent for a failure.
			 */
			$success = ( true === $outcome ) || is_array( $outcome );
			$error   = '';
			if ( is_wp_error( $outcome ) ) {
				$error = $outcome->get_error_message();
			} elseif ( ! $success ) {
				$error = __( 'The plugin was not updated.', 'wordpress-mcp-abilities' );
			}

			$from_version = isset( $before[ $file ]['Version'] ) ? (string) $before[ $file ]['Version'] : '';
			$to_version   = isset( $after[ $file ]['Version'] ) ? (string) $after[ $file ]['Version'] : $from_version;

			// Audited per item: one aggregate event could not say which plugin
			// moved between which versions.
			WP_MCP_Audit::log(
				$ability,
				0,
				$success,
				$success ? '' : 'wp_mcp_plugin_update_failed',
				array(
					'slug'         => $file,
					'from_version' => $from_version,
					'to_version'   => $to_version,
				)
			);

			$report[] = array(
				'file'         => $file,
				'from_version' => $from_version,
				'to_version'   => $to_version,
				'updated'      => $success,
				'error'        => $error,
			);
			if ( $success ) {
				++$updated;
			}
		}

		return array(
			'results'   => $report,
			'requested' => count( $files ),
			'updated'   => $updated,
		);
	}

	/**
	 * Installed plugin files that currently have an update available.
	 *
	 * @since 0.9.0
	 *
	 * @return string[]
	 */
	private static function plugins_with_updates() {
		self::ensure_plugin_functions();
		$updates = get_site_transient( 'update_plugins' );
		if ( ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
			return array();
		}
		return array_values( array_intersect( array_keys( $updates->response ), array_keys( get_plugins() ) ) );
	}

	/**
	 * Validate a caller-supplied list of plugin files, reusing the same
	 * path-traversal and installed-plugin guards as the single-plugin input.
	 *
	 * @since 0.9.0
	 *
	 * @param mixed $raw Raw plugin_files input.
	 * @param int   $max Maximum accepted list length.
	 * @return string[]|WP_Error
	 */
	private static function validate_plugin_file_list( $raw, $max ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return WP_MCP_Errors::plugin_validation_error( __( 'plugin_files must be a non-empty array of installed plugin paths.', 'wordpress-mcp-abilities' ) );
		}
		if ( count( $raw ) > $max ) {
			return WP_MCP_Errors::bulk_limit_exceeded( $max );
		}
		$files = array();
		foreach ( $raw as $candidate ) {
			$file = self::validate_installed_plugin_file( array( 'plugin_file' => $candidate ) );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$files[] = $file;
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Whether a filter forces this plugin's auto-update state, and to what.
	 *
	 * @since 0.9.0
	 *
	 * @param string $file Plugin file.
	 * @param array  $data Plugin header data.
	 * @return bool|null true/false when forced, null when the site option decides.
	 */
	private static function auto_update_forced( $file, array $data ) {
		if ( ! function_exists( 'wp_is_auto_update_forced_for_item' ) ) {
			return null;
		}
		$item = (object) array_merge( $data, array( 'plugin' => $file ) );
		/*
		 * Passing null means "nobody has decided yet": a bool coming back is a
		 * filter overriding the option, which core treats as forced.
		 *
		 * wordpress-stubs types this bool, but core returns whatever the
		 * auto_update_plugin filter returns — null when nothing filters, which
		 * is exactly why core's own plugins list table checks is_null() here.
		 * The null branch is therefore reachable and phpstan is wrong.
		 */
		$forced = wp_is_auto_update_forced_for_item( 'plugin', null, $item );
		return is_bool( $forced ) ? $forced : null; // @phpstan-ignore-line
	}

	/**
	 * Validate a WordPress.org plugin/theme slug.
	 *
	 * @param mixed $raw Raw slug.
	 * @return string|WP_Error
	 */
	private static function validate_slug( $raw ) {
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^[a-z0-9]([a-z0-9\-]{0,213}[a-z0-9])?$/', $raw ) ) {
			return WP_MCP_Errors::plugin_validation_error( __( 'slug must be a valid WordPress.org plugin slug.', 'wordpress-mcp-abilities' ) );
		}
		return $raw;
	}

	/**
	 * Validate that plugin_file identifies an installed plugin.
	 *
	 * @param array $input Ability input.
	 * @return string|WP_Error
	 */
	private static function validate_installed_plugin_file( $input ) {
		$file = isset( $input['plugin_file'] ) ? $input['plugin_file'] : '';
		if ( ! is_string( $file ) || '' === $file || false !== strpos( $file, '..' ) || false !== strpos( $file, '\\' ) || '/' === $file[0] ) {
			return WP_MCP_Errors::plugin_validation_error( __( 'plugin_file must be a relative plugin path such as "hello-dolly/hello.php".', 'wordpress-mcp-abilities' ) );
		}
		self::ensure_plugin_functions();
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $file ] ) ) {
			return WP_MCP_Errors::invalid_plugin();
		}
		return $file;
	}

	/**
	 * Format one plugin entry without exposing unrelated header fields.
	 *
	 * @param string $file Plugin file, relative to the plugins directory.
	 * @param array  $data Plugin header data from get_plugins().
	 * @return array
	 */
	private static function format_plugin( $file, array $data ) {
		$updates          = get_site_transient( 'update_plugins' );
		$update_available = isset( $updates->response ) && is_array( $updates->response ) && array_key_exists( $file, $updates->response );

		return array(
			'file'             => $file,
			'name'             => wp_strip_all_tags( (string) $data['Name'] ),
			'version'          => (string) $data['Version'],
			'description'      => wp_strip_all_tags( (string) $data['Description'] ),
			'author'           => wp_strip_all_tags( (string) $data['Author'] ),
			'plugin_uri'       => (string) $data['PluginURI'],
			'requires_wp'      => (string) $data['RequiresWP'],
			'requires_php'     => (string) $data['RequiresPHP'],
			'active'           => is_plugin_active( $file ),
			'network_active'   => is_multisite() && is_plugin_active_for_network( $file ),
			'update_available' => $update_available,
		);
	}
}
