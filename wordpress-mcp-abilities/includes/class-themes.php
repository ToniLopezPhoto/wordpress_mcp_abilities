<?php
/**
 * WordPress MCP Abilities — Theme management callbacks.
 *
 * Mirrors class-plugins.php: authorization is the native, primitive
 * (non-object) theme capability alone (`switch_themes`, `install_themes`,
 * `update_themes`, `delete_themes`) — WordPress has no per-theme
 * meta-capability. Install/update/delete are always separate abilities; a
 * remote install never switches to the new theme.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Themes
 */
class WP_MCP_Themes {

	/** Maximum number of themes returned by one list request. */
	const MAX_PER_PAGE = 50;

	/** Maximum size, in bytes, accepted for a downloaded theme package. */
	const MAX_PACKAGE_BYTES = 52428800; // 50 MB.

	/** Maximum number of themes one explicit bulk update call may target. */
	const MAX_BULK_UPDATES = 20;

	/** Maximum number of themes update-all-themes will process in one call. */
	const MAX_BULK_UPDATE_ALL = 50;

	/** Network option holding the themes opted in to auto-updates. */
	const AUTO_UPDATE_OPTION = 'auto_update_themes';

	/**
	 * List installed themes with bounded pagination.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_themes( $input ) {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$all = wp_get_themes();
		if ( ! empty( $input['search'] ) ) {
			$needle = strtolower( sanitize_text_field( $input['search'] ) );
			$all    = array_filter(
				$all,
				function ( $theme ) use ( $needle ) {
					return false !== strpos( strtolower( (string) $theme->get( 'Name' ) ), $needle );
				}
			);
		}

		$stylesheets = array_keys( $all );
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( (array) $input, 20, self::MAX_PER_PAGE );
		$total = count( $stylesheets );
		$slice = array_slice( $stylesheets, ( $page - 1 ) * $per_page, $per_page );

		$themes = array();
		foreach ( $slice as $stylesheet ) {
			$themes[] = self::format_theme( $all[ $stylesheet ] );
		}

		return array(
			'themes'      => $themes,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get one installed theme.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_theme( $input ) {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$theme = self::validate_installed_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		return self::format_theme( $theme );
	}

	/**
	 * Switch the active theme. Idempotent: switching to the already-active
	 * theme returns its current state rather than erroring.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function switch_theme( $input ) {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$theme = self::validate_installed_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}

		if ( get_stylesheet() === $theme->get_stylesheet() ) {
			return self::format_theme( $theme );
		}

		// switch_theme() here is WordPress core's global function, not a
		// recursive call to this method.
		switch_theme( $theme->get_stylesheet() );

		if ( get_stylesheet() !== $theme->get_stylesheet() ) {
			WP_MCP_Audit::log( 'wp-mcp/switch-theme', 0, false, 'wp_mcp_theme_switch_failed', array( 'slug' => $theme->get_stylesheet() ) );
			return WP_MCP_Errors::theme_switch_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/switch-theme', 0, true, '', array( 'slug' => $theme->get_stylesheet() ) );
		return self::format_theme( wp_get_theme( $theme->get_stylesheet() ) );
	}

	/**
	 * Install a theme from the official WordPress.org repository by slug.
	 * Never switches to the installed theme.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function install_theme_from_repo( $input ) {
		if ( ! current_user_can( 'install_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$slug = self::validate_slug( isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		self::ensure_upgrader_classes();
		if ( ! self::upgrader_classes_available() ) {
			WP_MCP_Audit::log( 'wp-mcp/install-theme-from-repo', 0, false, 'wp_mcp_theme_install_failed', array( 'slug' => $slug ) );
			return WP_MCP_Errors::theme_install_failed( __( 'The WordPress theme upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		$api = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'    => false,
					'reviews'     => false,
					'screenshots' => false,
				),
			)
		);
		if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-theme-from-repo', 0, false, 'wp_mcp_theme_install_failed', array( 'slug' => $slug ) );
			return WP_MCP_Errors::theme_install_failed( __( 'The requested theme was not found in the WordPress.org repository.', 'wordpress-mcp-abilities' ) );
		}

		$upgrader  = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$installed = self::run_download_limited(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $api ) {
				return $upgrader->install( $api->download_link );
			}
		);

		$failure = self::install_failure( $installed );
		if ( is_wp_error( $failure ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-theme-from-repo', 0, false, 'wp_mcp_theme_install_failed', array( 'slug' => $slug ) );
			return $failure;
		}

		$theme = self::resolve_installed_theme( $upgrader, $slug );
		if ( is_wp_error( $theme ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-theme-from-repo', 0, false, $theme->get_error_code(), array( 'slug' => $slug ) );
			return $theme;
		}

		WP_MCP_Audit::log(
			'wp-mcp/install-theme-from-repo',
			0,
			true,
			'',
			array(
				'slug'       => $slug,
				'to_version' => isset( $api->version ) ? (string) $api->version : '',
			)
		);
		return self::format_theme( $theme );
	}

	/**
	 * Install a theme from an explicit HTTP(S) zip URL, subject to the same
	 * SSRF/host policy as upload-media-from-url. Never switches to the
	 * installed theme.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function install_theme_from_url( $input ) {
		if ( ! current_user_can( 'install_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$url = isset( $input['url'] ) ? $input['url'] : '';
		$url_error = WP_MCP_Permissions::validate_remote_url( $url, $input );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		self::ensure_upgrader_classes();
		if ( ! self::upgrader_classes_available() ) {
			WP_MCP_Audit::log( 'wp-mcp/install-theme-from-url', 0, false, 'wp_mcp_theme_install_failed' );
			return WP_MCP_Errors::theme_install_failed( __( 'The WordPress theme upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		$upgrader  = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$installed = self::run_download_limited(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $url ) {
				return $upgrader->install( $url );
			}
		);

		$failure = self::install_failure( $installed );
		if ( is_wp_error( $failure ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-theme-from-url', 0, false, 'wp_mcp_theme_install_failed' );
			return $failure;
		}

		$theme = self::resolve_installed_theme( $upgrader );
		if ( is_wp_error( $theme ) ) {
			WP_MCP_Audit::log( 'wp-mcp/install-theme-from-url', 0, false, $theme->get_error_code() );
			return $theme;
		}

		WP_MCP_Audit::log(
			'wp-mcp/install-theme-from-url',
			0,
			true,
			'',
			array(
				'slug'       => $theme->get_stylesheet(),
				'to_version' => (string) $theme->get( 'Version' ),
			)
		);
		return self::format_theme( $theme );
	}

	/**
	 * Update an installed theme to the latest available version. Relies on
	 * WordPress core's own automatic temp-backup-and-restore-on-failure
	 * mechanism (available since WP 6.3 for single-theme upgrades); this
	 * ability does not implement its own rollback.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_theme( $input ) {
		if ( ! current_user_can( 'update_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$theme = self::validate_installed_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$stylesheet   = $theme->get_stylesheet();
		$from_version = (string) $theme->get( 'Version' );

		self::ensure_upgrader_classes();
		if ( ! self::upgrader_classes_available() ) {
			WP_MCP_Audit::log(
				'wp-mcp/update-theme',
				0,
				false,
				'wp_mcp_theme_update_failed',
				array(
					'slug'         => $stylesheet,
					'from_version' => $from_version,
				)
			);
			return WP_MCP_Errors::theme_update_failed( __( 'The WordPress theme upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}
		$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = self::run_download_limited(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $stylesheet ) {
				return $upgrader->upgrade( $stylesheet );
			}
		);

		$theme->cache_delete();
		$after      = wp_get_theme( $stylesheet );
		$to_version = $after->exists() ? (string) $after->get( 'Version' ) : $from_version;
		$context    = array(
			'slug'         => $stylesheet,
			'from_version' => $from_version,
			'to_version'   => $to_version,
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-theme', 0, false, 'wp_mcp_theme_update_failed', $context );
			return WP_MCP_Errors::theme_update_failed( $result->get_error_message() );
		}
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/update-theme', 0, false, 'wp_mcp_theme_update_failed', $context );
			return WP_MCP_Errors::theme_update_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/update-theme', 0, true, '', $context );
		return self::format_theme( $after );
	}

	/**
	 * Update an explicit list of installed themes in one pass. Every entry is
	 * validated against the installed set before the upgrader is touched.
	 *
	 * @since 0.9.0
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_themes( $input ) {
		if ( ! current_user_can( 'update_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$stylesheets = self::validate_stylesheet_list(
			isset( $input['stylesheets'] ) ? $input['stylesheets'] : null,
			self::MAX_BULK_UPDATES
		);
		if ( is_wp_error( $stylesheets ) ) {
			return $stylesheets;
		}
		return self::run_bulk_update( 'wp-mcp/update-themes', $stylesheets );
	}

	/**
	 * Update every installed theme that currently has an update available.
	 *
	 * Deliberately a separate ability rather than an optional flag on
	 * update-themes, for the same reason as update-all-plugins: "update
	 * everything" must never be what a forgotten parameter does.
	 *
	 * @since 0.9.0
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function update_all_themes( $input = array() ) {
		if ( ! current_user_can( 'update_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$stylesheets = self::themes_with_updates();
		if ( empty( $stylesheets ) ) {
			return WP_MCP_Errors::no_updates_pending();
		}
		if ( count( $stylesheets ) > self::MAX_BULK_UPDATE_ALL ) {
			return WP_MCP_Errors::bulk_limit_exceeded( self::MAX_BULK_UPDATE_ALL );
		}
		return self::run_bulk_update( 'wp-mcp/update-all-themes', $stylesheets );
	}

	/**
	 * Opt one installed theme in or out of WordPress's own auto-updates.
	 * Idempotent: setting the state it already has is a no-op success.
	 *
	 * @since 0.9.0
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function set_theme_auto_update( $input ) {
		if ( ! current_user_can( 'update_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$theme = self::validate_installed_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		if ( ! isset( $input['enabled'] ) || ! is_bool( $input['enabled'] ) ) {
			return WP_MCP_Errors::theme_validation_error( __( 'enabled must be a boolean.', 'wordpress-mcp-abilities' ) );
		}
		$enabled    = (bool) $input['enabled'];
		$stylesheet = $theme->get_stylesheet();

		/*
		 * auto_update_themes is a *network* option: on multisite one write
		 * changes update behavior for every site on the network, so per-site
		 * update_themes is not enough authority on its own.
		 */
		if ( is_multisite() && ! current_user_can( 'manage_network_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied( __( 'Configuring theme auto-updates on multisite requires a network administrator.', 'wordpress-mcp-abilities' ) );
		}

		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) || ! wp_is_auto_update_enabled_for_type( 'theme' ) ) {
			return WP_MCP_Errors::theme_auto_update_unavailable();
		}

		$forced = self::auto_update_forced( $stylesheet );
		if ( null !== $forced ) {
			// A filter already decides this theme's auto-update state, so
			// writing the option would silently do nothing.
			return WP_MCP_Errors::theme_auto_update_unavailable(
				$forced
					? __( 'Auto-updates for this theme are force-enabled by a filter and cannot be changed.', 'wordpress-mcp-abilities' )
					: __( 'Auto-updates for this theme are force-disabled by a filter and cannot be changed.', 'wordpress-mcp-abilities' )
			);
		}

		// Drop entries for themes deleted since the option was last written,
		// exactly as core's own toggle does.
		$auto_updates = array_values( array_intersect( (array) get_site_option( self::AUTO_UPDATE_OPTION, array() ), array_keys( wp_get_themes() ) ) );
		$was_enabled  = in_array( $stylesheet, $auto_updates, true );

		if ( $enabled !== $was_enabled ) {
			if ( $enabled ) {
				$auto_updates[] = $stylesheet;
			} else {
				$auto_updates = array_values( array_diff( $auto_updates, array( $stylesheet ) ) );
			}
			update_site_option( self::AUTO_UPDATE_OPTION, array_values( array_unique( $auto_updates ) ) );

			WP_MCP_Audit::log(
				'wp-mcp/set-theme-auto-update',
				0,
				true,
				'',
				array(
					'slug'        => $stylesheet,
					'auto_update' => $enabled ? 'enabled' : 'disabled',
				)
			);
		}

		return array(
			'stylesheet'  => $stylesheet,
			'auto_update' => $enabled,
		);
	}

	/**
	 * Permanently delete an installed theme. Refuses to delete the currently
	 * active theme.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_theme( $input ) {
		if ( ! current_user_can( 'delete_themes' ) ) {
			return WP_MCP_Errors::theme_permission_denied();
		}
		$theme = self::validate_installed_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}
		$stylesheet = $theme->get_stylesheet();
		// Captured before the delete: afterwards the theme headers are gone and
		// the audit trail could no longer say which version was removed.
		$context = array(
			'slug'         => $stylesheet,
			'from_version' => (string) $theme->get( 'Version' ),
		);

		if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-theme', 0, false, 'wp_mcp_theme_active_conflict', $context );
			return WP_MCP_Errors::theme_active_conflict();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$result = delete_theme( $stylesheet );

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-theme', 0, false, 'wp_mcp_theme_delete_failed', $context );
			return WP_MCP_Errors::theme_delete_failed( $result->get_error_message() );
		}
		// core's delete_theme() returns true, false, null (filesystem credentials
		// unavailable) or WP_Error. Anything falsy means nothing was deleted, so
		// without this guard the ability reports deleted:true and the audit log
		// records a success for a theme that is still on disk.
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-theme', 0, false, 'wp_mcp_theme_delete_failed', $context );
			return WP_MCP_Errors::theme_delete_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/delete-theme', 0, true, '', $context );
		return array(
			'stylesheet' => $stylesheet,
			'deleted'    => true,
		);
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Load themes_api() and the upgrader/skin classes if not already
	 * loaded. class-wp-upgrader.php pulls in Theme_Upgrader itself on all
	 * supported WordPress versions; the direct require of
	 * class-theme-upgrader.php is a defensive fallback guarded by
	 * file_exists() so an unexpected WordPress core layout degrades to a
	 * graceful error (see the class_exists() check callers must perform)
	 * instead of a fatal missing-file require.
	 */
	private static function ensure_upgrader_classes() {
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'Theme_Upgrader' ) && file_exists( ABSPATH . 'wp-admin/includes/class-theme-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
	}

	/** Whether the classes ensure_upgrader_classes() attempts to load are actually available. */
	private static function upgrader_classes_available() {
		return class_exists( 'Theme_Upgrader' ) && class_exists( 'WP_Ajax_Upgrader_Skin' );
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
	 * Normalize a Theme_Upgrader install() result into a WP_Error on
	 * failure, or true (not a WP_Error) on success.
	 *
	 * @param mixed $installed Upgrader install() return value.
	 * @return true|WP_Error
	 */
	private static function install_failure( $installed ) {
		if ( is_wp_error( $installed ) ) {
			return WP_MCP_Errors::theme_install_failed( $installed->get_error_message() );
		}
		if ( ! $installed ) {
			return WP_MCP_Errors::theme_install_failed();
		}
		return true;
	}

	/**
	 * Resolve the installed theme from the upgrader's result, falling back
	 * to a known slug when the upgrader didn't record a destination folder.
	 *
	 * @param Theme_Upgrader $upgrader      Upgrader instance after install().
	 * @param string         $fallback_slug Slug to use if unresolved.
	 * @return WP_Theme|WP_Error
	 */
	private static function resolve_installed_theme( $upgrader, $fallback_slug = '' ) {
		$stylesheet = ( isset( $upgrader->result['destination_name'] ) && is_string( $upgrader->result['destination_name'] ) && '' !== $upgrader->result['destination_name'] )
			? $upgrader->result['destination_name']
			: $fallback_slug;

		if ( '' === $stylesheet ) {
			return WP_MCP_Errors::theme_install_failed( __( 'The theme was installed but its destination folder could not be determined.', 'wordpress-mcp-abilities' ) );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return WP_MCP_Errors::theme_install_failed( __( 'The theme was installed but could not be located afterward.', 'wordpress-mcp-abilities' ) );
		}
		return $theme;
	}

	/**
	 * Run one bulk theme upgrade and report per-theme outcomes.
	 *
	 * @since 0.9.0
	 *
	 * @param string   $ability     Ability name, for the audit trail.
	 * @param string[] $stylesheets Validated, installed theme directory names.
	 * @return array|WP_Error
	 */
	private static function run_bulk_update( $ability, array $stylesheets ) {
		self::ensure_upgrader_classes();
		if ( ! self::upgrader_classes_available() ) {
			WP_MCP_Audit::log( $ability, 0, false, 'wp_mcp_theme_update_failed' );
			return WP_MCP_Errors::theme_update_failed( __( 'The WordPress theme upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		$before = array();
		foreach ( $stylesheets as $stylesheet ) {
			$installed             = wp_get_theme( $stylesheet );
			$before[ $stylesheet ] = $installed->exists() ? (string) $installed->get( 'Version' ) : '';
		}

		$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$results  = self::run_download_limited(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $stylesheets ) {
				return $upgrader->bulk_upgrade( $stylesheets );
			}
		);
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		wp_clean_themes_cache();

		$report  = array();
		$updated = 0;
		foreach ( $stylesheets as $stylesheet ) {
			$outcome = isset( $results[ $stylesheet ] ) ? $results[ $stylesheet ] : null;

			/*
			 * Theme_Upgrader::bulk_upgrade() reports an array for a theme it
			 * upgraded, true for one already at the latest version, and
			 * WP_Error/false/absent for a failure.
			 */
			$success = ( true === $outcome ) || is_array( $outcome );
			$error   = '';
			if ( is_wp_error( $outcome ) ) {
				$error = $outcome->get_error_message();
			} elseif ( ! $success ) {
				$error = __( 'The theme was not updated.', 'wordpress-mcp-abilities' );
			}

			$after        = wp_get_theme( $stylesheet );
			$from_version = $before[ $stylesheet ];
			$to_version   = $after->exists() ? (string) $after->get( 'Version' ) : $from_version;

			// Audited per item: one aggregate event could not say which theme
			// moved between which versions.
			WP_MCP_Audit::log(
				$ability,
				0,
				$success,
				$success ? '' : 'wp_mcp_theme_update_failed',
				array(
					'slug'         => $stylesheet,
					'from_version' => $from_version,
					'to_version'   => $to_version,
				)
			);

			$report[] = array(
				'stylesheet'   => $stylesheet,
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
			'requested' => count( $stylesheets ),
			'updated'   => $updated,
		);
	}

	/**
	 * Installed theme stylesheets that currently have an update available.
	 *
	 * @since 0.9.0
	 *
	 * @return string[]
	 */
	private static function themes_with_updates() {
		$updates = get_site_transient( 'update_themes' );
		if ( ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
			return array();
		}
		return array_values( array_intersect( array_keys( $updates->response ), array_keys( wp_get_themes() ) ) );
	}

	/**
	 * Validate a caller-supplied list of stylesheets, reusing the same
	 * path-traversal and installed-theme guards as the single-theme input.
	 *
	 * @since 0.9.0
	 *
	 * @param mixed $raw Raw stylesheets input.
	 * @param int   $max Maximum accepted list length.
	 * @return string[]|WP_Error
	 */
	private static function validate_stylesheet_list( $raw, $max ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return WP_MCP_Errors::theme_validation_error( __( 'stylesheets must be a non-empty array of installed theme directory names.', 'wordpress-mcp-abilities' ) );
		}
		if ( count( $raw ) > $max ) {
			return WP_MCP_Errors::bulk_limit_exceeded( $max );
		}
		$stylesheets = array();
		foreach ( $raw as $candidate ) {
			$theme = self::validate_installed_theme( array( 'stylesheet' => $candidate ) );
			if ( is_wp_error( $theme ) ) {
				return $theme;
			}
			$stylesheets[] = $theme->get_stylesheet();
		}
		return array_values( array_unique( $stylesheets ) );
	}

	/**
	 * Whether a filter forces this theme's auto-update state, and to what.
	 *
	 * @since 0.9.0
	 *
	 * @param string $stylesheet Theme directory name.
	 * @return bool|null true/false when forced, null when the site option decides.
	 */
	private static function auto_update_forced( $stylesheet ) {
		if ( ! function_exists( 'wp_is_auto_update_forced_for_item' ) ) {
			return null;
		}
		/*
		 * Passing null means "nobody has decided yet": a bool coming back is a
		 * filter overriding the option, which core treats as forced.
		 *
		 * wordpress-stubs types this bool, but core returns whatever the
		 * auto_update_theme filter returns — null when nothing filters, which
		 * is exactly why core's own themes list table checks is_null() here.
		 * The null branch is therefore reachable and phpstan is wrong.
		 */
		$forced = wp_is_auto_update_forced_for_item( 'theme', null, (object) array( 'theme' => $stylesheet ) );
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
			return WP_MCP_Errors::theme_validation_error( __( 'slug must be a valid WordPress.org theme slug.', 'wordpress-mcp-abilities' ) );
		}
		return $raw;
	}

	/**
	 * Validate that stylesheet identifies an installed theme.
	 *
	 * @param array $input Ability input.
	 * @return WP_Theme|WP_Error
	 */
	private static function validate_installed_theme( $input ) {
		$stylesheet = isset( $input['stylesheet'] ) ? $input['stylesheet'] : '';
		if ( ! is_string( $stylesheet ) || '' === $stylesheet || false !== strpos( $stylesheet, '..' ) || false !== strpos( $stylesheet, '/' ) || false !== strpos( $stylesheet, '\\' ) ) {
			return WP_MCP_Errors::theme_validation_error( __( 'stylesheet must be a single theme directory name.', 'wordpress-mcp-abilities' ) );
		}
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return WP_MCP_Errors::invalid_theme();
		}
		return $theme;
	}

	/**
	 * Format one theme entry without exposing unrelated internals.
	 *
	 * @param WP_Theme $theme Theme object.
	 * @return array
	 */
	private static function format_theme( $theme ) {
		$stylesheet       = $theme->get_stylesheet();
		$updates          = get_site_transient( 'update_themes' );
		$update_available = isset( $updates->response ) && is_array( $updates->response ) && array_key_exists( $stylesheet, $updates->response );

		return array(
			'stylesheet'       => $stylesheet,
			'name'             => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
			'version'          => (string) $theme->get( 'Version' ),
			'description'      => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
			'author'           => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'theme_uri'        => (string) $theme->get( 'ThemeURI' ),
			'requires_wp'      => (string) $theme->get( 'RequiresWP' ),
			'requires_php'     => (string) $theme->get( 'RequiresPHP' ),
			'parent_theme'     => $theme->parent() ? $theme->parent()->get_stylesheet() : '',
			'active'           => get_stylesheet() === $stylesheet,
			'update_available' => $update_available,
		);
	}
}
