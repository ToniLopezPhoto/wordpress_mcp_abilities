<?php
/**
 * WordPress MCP Abilities — System inspection and WordPress core update callbacks.
 *
 * Two groups of operations share this file because they share a domain and a
 * threat model:
 *
 *  - **Core updates** (issue #9), gated entirely on the native `update_core`
 *    capability, which WordPress itself restricts to Super Admins on multisite
 *    (it is not part of the default multisite Administrator capability set), so
 *    no additional multisite branching is needed here. Unlike plugin/theme
 *    updates, core updates have no equivalent automatic temp-backup-and-restore
 *    mechanism in WordPress core for a manual, synchronous update — that
 *    mechanism (`WP_Automatic_Updater`) only applies to the background
 *    auto-update path. This is documented on the ability rather than papered
 *    over. The same half also carries the cross-cutting update surface: the
 *    capability-scoped `list-available-updates` snapshot, which reports core,
 *    plugin, theme and translation updates from the existing update transients
 *    without ever forcing a WordPress.org check, and `update-translations`,
 *    which installs language packs from WordPress's own update data and takes
 *    no source parameter from the caller.
 *  - **System inspection** (issue #12): site identity, environment, Site
 *    Health, registered image sizes, rewrite state and directory sizes.
 *
 * Design constraints for the inspection half (epic #1, issue #12):
 *
 *  - **No filesystem paths, ever.** Not `ABSPATH`, not the uploads directory,
 *    not the `.maintenance` marker, not a plugin file. Directory *sizes* are
 *    reported by copying only the `raw`/`size` members of what WordPress
 *    returns, so a future core change that adds a `path` member cannot leak one
 *    through this plugin.
 *  - **No secrets.** No salts, no keys, no database credentials, no mail-server
 *    configuration. Debug flags are reported as booleans, never as log paths.
 *  - **Site Health tests are a validated selector, never a callback.** A caller
 *    may name tests, but only names WordPress own `site_status_tests` registry
 *    reports as *direct* tests whose `test` member is a string resolving to a
 *    real `WP_Site_Health::get_test_*` method. A third-party test registered as
 *    a callable is listed with `runnable: false` and is never invoked, so no
 *    ability can be talked into calling an arbitrary function.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_System
 */
class WP_MCP_System {

	/**
	 * Maximum number of Site Health tests one call may run.
	 */
	const MAX_HEALTH_TESTS = 40;

	/**
	 * Maximum number of items reported per section of one updates snapshot.
	 * The list is bounded by what is installed, but a large network can still
	 * install hundreds of plugins, and an MCP response must stay bounded.
	 */
	const MAX_ITEMS_PER_SECTION = 100;

	/** Maximum size, in bytes, accepted for a downloaded language package. */
	const MAX_PACKAGE_BYTES = 52428800; // 50 MB.

	/* ==================================================================
	 * Core updates (issue #9)
	 * ================================================================ */

	/**
	 * Report the current WordPress version and whether a core update is
	 * available, without forcing a fresh WordPress.org version check.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_core_update_status( $input = array() ) {
		if ( ! current_user_can( 'update_core' ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to view the core update status.', 'wordpress-mcp-abilities' ) );
		}
		self::ensure_update_functions();

		$update           = get_preferred_from_update_core();
		$current_version  = (string) get_bloginfo( 'version' );
		$update_available = is_object( $update ) && isset( $update->response ) && 'upgrade' === $update->response;

		return array(
			'current_version'  => $current_version,
			'latest_version'   => ( $update_available && isset( $update->version ) ) ? (string) $update->version : $current_version,
			'update_available' => $update_available,
			'locale'           => ( $update_available && isset( $update->locale ) ) ? (string) $update->locale : '',
		);
	}

	/**
	 * Update WordPress core to the preferred available version.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function update_core( $input = array() ) {
		if ( ! current_user_can( 'update_core' ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to update WordPress core.', 'wordpress-mcp-abilities' ) );
		}
		self::ensure_update_functions();

		$from_version = (string) get_bloginfo( 'version' );

		$update = get_preferred_from_update_core();
		if ( ! is_object( $update ) || ! isset( $update->response ) || 'upgrade' !== $update->response ) {
			WP_MCP_Audit::log( 'wp-mcp/update-core', 0, false, 'wp_mcp_core_update_unavailable', array( 'from_version' => $from_version ) );
			return WP_MCP_Errors::core_update_unavailable();
		}

		self::ensure_upgrader_classes();
		if ( ! self::upgrader_classes_available() ) {
			WP_MCP_Audit::log( 'wp-mcp/update-core', 0, false, 'wp_mcp_core_update_failed', array( 'from_version' => $from_version ) );
			return WP_MCP_Errors::core_update_failed( __( 'The WordPress core upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}
		/*
		 * The core package download gets the same size cap and unsafe-URL
		 * rejection as every other upgrader path in this plugin. The URL is not
		 * caller-controlled here, but an oversized or hostile response from a
		 * poisoned update endpoint must not be pulled unbounded into memory.
		 */
		$upgrader = new Core_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = WP_MCP_Permissions::with_download_limit(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $update ) {
				return $upgrader->upgrade( $update );
			}
		);

		$to_version = (string) get_bloginfo( 'version' );
		$context    = array(
			'from_version' => $from_version,
			'to_version'   => $to_version,
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-core', 0, false, 'wp_mcp_core_update_failed', $context );
			return WP_MCP_Errors::core_update_failed( $result->get_error_message() );
		}
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/update-core', 0, false, 'wp_mcp_core_update_failed', $context );
			return WP_MCP_Errors::core_update_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/update-core', 0, true, '', $context );
		return array(
			'from_version' => $from_version,
			'to_version'   => $to_version,
			'updated'      => true,
		);
	}

	/**
	 * Report every update WordPress currently knows about — core, plugins,
	 * themes and translations — in one snapshot, mirroring the wp-admin
	 * Updates screen. Reads the existing update transients; never forces a
	 * fresh WordPress.org check.
	 *
	 * Each section is filled only when the caller holds the matching
	 * capability. The can_view_* flags say why a section is empty, so an agent
	 * can tell "nothing to update" apart from "not allowed to look".
	 *
	 * @since 0.9.0
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_available_updates( $input = array() ) {
		if ( ! self::can_view_any_updates() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to view available updates.', 'wordpress-mcp-abilities' ) );
		}
		self::ensure_update_functions();

		$can_core         = current_user_can( 'update_core' );
		$can_plugins      = current_user_can( 'update_plugins' );
		$can_themes       = current_user_can( 'update_themes' );
		$can_translations = current_user_can( 'update_languages' );

		$current_version = (string) get_bloginfo( 'version' );
		$core            = array(
			'current_version'  => $current_version,
			'latest_version'   => $current_version,
			'update_available' => false,
			'locale'           => '',
		);
		if ( $can_core ) {
			$status = self::get_core_update_status();
			if ( ! is_wp_error( $status ) ) {
				$core = $status;
			}
		}

		$plugins           = array();
		$plugins_truncated = false;
		if ( $can_plugins ) {
			$installed = get_plugins();
			$pending   = array_intersect_key( self::transient_response( 'update_plugins' ), $installed );

			$plugins_truncated = count( $pending ) > self::MAX_ITEMS_PER_SECTION;
			foreach ( array_slice( $pending, 0, self::MAX_ITEMS_PER_SECTION, true ) as $file => $update ) {
				$update       = (object) $update;
				$requires_wp  = isset( $update->requires ) ? (string) $update->requires : '';
				$requires_php = isset( $update->requires_php ) ? (string) $update->requires_php : '';

				$plugins[] = array(
					'file'            => (string) $file,
					'name'            => wp_strip_all_tags( (string) $installed[ $file ]['Name'] ),
					'current_version' => (string) $installed[ $file ]['Version'],
					'new_version'     => isset( $update->new_version ) ? (string) $update->new_version : '',
					'requires_wp'     => $requires_wp,
					'requires_php'    => $requires_php,
					'tested_up_to'    => isset( $update->tested ) ? (string) $update->tested : '',
					'compatible'      => self::is_compatible( $requires_wp, $requires_php ),
				);
			}
		}

		$themes           = array();
		$themes_truncated = false;
		if ( $can_themes ) {
			$installed = wp_get_themes();
			$pending   = array_intersect_key( self::transient_response( 'update_themes' ), $installed );

			$themes_truncated = count( $pending ) > self::MAX_ITEMS_PER_SECTION;
			foreach ( array_slice( $pending, 0, self::MAX_ITEMS_PER_SECTION, true ) as $stylesheet => $update ) {
				// The themes transient stores plain arrays, the plugins one objects.
				$update       = (array) $update;
				$requires_wp  = isset( $update['requires'] ) ? (string) $update['requires'] : '';
				$requires_php = isset( $update['requires_php'] ) ? (string) $update['requires_php'] : '';

				$themes[] = array(
					'stylesheet'      => (string) $stylesheet,
					'name'            => wp_strip_all_tags( (string) $installed[ $stylesheet ]->get( 'Name' ) ),
					'current_version' => (string) $installed[ $stylesheet ]->get( 'Version' ),
					'new_version'     => isset( $update['new_version'] ) ? (string) $update['new_version'] : '',
					'requires_wp'     => $requires_wp,
					'requires_php'    => $requires_php,
					'compatible'      => self::is_compatible( $requires_wp, $requires_php ),
				);
			}
		}

		$translations           = array();
		$translations_truncated = false;
		if ( $can_translations ) {
			$pending                = self::pending_translation_updates();
			$translations_truncated = count( $pending ) > self::MAX_ITEMS_PER_SECTION;
			foreach ( array_slice( $pending, 0, self::MAX_ITEMS_PER_SECTION ) as $update ) {
				$translations[] = self::format_translation( $update );
			}
		}

		return array(
			'core'                   => $core,
			'plugins'                => $plugins,
			'themes'                 => $themes,
			'translations'           => $translations,
			'can_view_core'          => $can_core,
			'can_view_plugins'       => $can_plugins,
			'can_view_themes'        => $can_themes,
			'can_view_translations'  => $can_translations,
			'plugins_truncated'      => $plugins_truncated,
			'themes_truncated'       => $themes_truncated,
			'translations_truncated' => $translations_truncated,
		);
	}

	/**
	 * Install every translation (language pack) update WordPress currently
	 * has pending. Takes no source parameters: the package list comes from
	 * WordPress's own update transients, never from the caller.
	 *
	 * @since 0.9.0
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function update_translations( $input = array() ) {
		if ( ! current_user_can( 'update_languages' ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to update translations.', 'wordpress-mcp-abilities' ) );
		}
		self::ensure_update_functions();

		$pending = self::pending_translation_updates();
		if ( empty( $pending ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-translations', 0, false, 'wp_mcp_translation_updates_unavailable' );
			return WP_MCP_Errors::translation_updates_unavailable();
		}

		self::ensure_upgrader_classes();
		if ( ! class_exists( 'Language_Pack_Upgrader' ) || ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-translations', 0, false, 'wp_mcp_translation_update_failed' );
			return WP_MCP_Errors::translation_update_failed( __( 'The WordPress language pack upgrader is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		$upgrader = new Language_Pack_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$results  = WP_MCP_Permissions::with_download_limit(
			self::MAX_PACKAGE_BYTES,
			function () use ( $upgrader, $pending ) {
				return $upgrader->bulk_upgrade( $pending );
			}
		);
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$report  = array();
		$updated = 0;
		foreach ( array_values( $pending ) as $index => $update ) {
			$outcome = isset( $results[ $index ] ) ? $results[ $index ] : null;
			$success = ( true === $outcome ) || is_array( $outcome );

			$entry = self::format_translation( $update );

			// Audited per item: one aggregate event could not say which
			// language pack was installed at which version.
			WP_MCP_Audit::log(
				'wp-mcp/update-translations',
				0,
				$success,
				$success ? '' : 'wp_mcp_translation_update_failed',
				array(
					'slug'       => $entry['type'] . ':' . $entry['slug'] . ':' . $entry['language'],
					'to_version' => $entry['version'],
				)
			);

			$entry['updated'] = $success;
			$entry['error']   = '';
			if ( is_wp_error( $outcome ) ) {
				$entry['error'] = $outcome->get_error_message();
			} elseif ( ! $success ) {
				$entry['error'] = __( 'The translation package was not installed.', 'wordpress-mcp-abilities' );
			}

			$report[] = $entry;
			if ( $success ) {
				++$updated;
			}
		}

		return array(
			'results'   => $report,
			'requested' => count( $pending ),
			'updated'   => $updated,
		);
	}

	/* ==================================================================
	 * Site identity and environment (issue #12)
	 * ================================================================ */

	/**
	 * Report the site's identity, active theme and content counts.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_site_info( $input = array() ) {
		$denied = self::require_manage_options();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$theme    = wp_get_theme();
		$posts    = wp_count_posts( 'post' );
		$pages    = wp_count_posts( 'page' );
		$media    = wp_count_posts( 'attachment' );
		$comments = wp_count_comments();
		$users    = count_users();

		return array(
			'name'                => (string) get_bloginfo( 'name' ),
			'description'         => (string) get_bloginfo( 'description' ),
			'home_url'            => (string) home_url(),
			'site_url'            => (string) site_url(),
			'wp_version'          => (string) get_bloginfo( 'version' ),
			'locale'              => (string) get_locale(),
			'timezone_string'     => (string) get_option( 'timezone_string' ),
			'gmt_offset'          => (float) get_option( 'gmt_offset' ),
			'charset'             => (string) get_bloginfo( 'charset' ),
			'is_multisite'        => is_multisite(),
			'search_engine_visible' => (bool) get_option( 'blog_public' ),
			'active_theme'        => (string) $theme->get( 'Name' ),
			'active_theme_slug'   => (string) $theme->get_stylesheet(),
			'active_theme_version' => (string) $theme->get( 'Version' ),
			'is_block_theme'      => (bool) $theme->is_block_theme(),
			'published_posts'     => isset( $posts->publish ) ? (int) $posts->publish : 0,
			'published_pages'     => isset( $pages->publish ) ? (int) $pages->publish : 0,
			'media_items'         => isset( $media->inherit ) ? (int) $media->inherit : 0,
			'approved_comments'   => isset( $comments->approved ) ? (int) $comments->approved : 0,
			'pending_comments'    => isset( $comments->moderated ) ? (int) $comments->moderated : 0,
			'total_users'         => (int) $users['total_users'],
		);
	}

	/**
	 * Report the PHP, WordPress and database environment.
	 *
	 * Versions, limits and capability booleans only: no credentials, no salts,
	 * no filesystem paths, and no log locations.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_environment_info( $input = array() ) {
		$denied = self::require_manage_options();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		global $wpdb;

		return array(
			'wp_version'            => (string) get_bloginfo( 'version' ),
			'environment_type'      => function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : '',
			'is_multisite'          => is_multisite(),
			'php_version'           => PHP_VERSION,
			'php_sapi'              => (string) php_sapi_name(),
			'php_memory_limit'      => (string) ini_get( 'memory_limit' ),
			'php_max_execution_time' => (string) ini_get( 'max_execution_time' ),
			'php_upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'php_post_max_size'     => (string) ini_get( 'post_max_size' ),
			'php_max_input_vars'    => (string) ini_get( 'max_input_vars' ),
			'wp_memory_limit'       => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
			'wp_max_memory_limit'   => defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : '',
			'wp_debug'              => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log_enabled'  => defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG,
			'script_debug'          => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'database_server_version' => (string) $wpdb->db_version(),
			'database_charset'      => (string) $wpdb->charset,
			'database_collate'      => (string) $wpdb->collate,
			'https_home_url'        => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
			'external_object_cache' => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false,
			'curl_available'        => extension_loaded( 'curl' ),
			'openssl_version'       => defined( 'OPENSSL_VERSION_TEXT' ) ? (string) OPENSSL_VERSION_TEXT : '',
			'imagick_available'     => extension_loaded( 'imagick' ),
			'gd_available'          => extension_loaded( 'gd' ),
			'server_time_utc'       => gmdate( 'c' ),
		);
	}

	/* ==================================================================
	 * Site Health (issue #12)
	 * ================================================================ */

	/**
	 * Describe the Site Health tests WordPress has registered, and which of
	 * them this plugin will run.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_site_health_tests( $input = array() ) {
		$denied = self::require_site_health_capability();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$catalog = self::site_health_catalog();
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$runnable = 0;
		foreach ( $catalog as $entry ) {
			if ( $entry['runnable'] ) {
				++$runnable;
			}
		}

		return array(
			'tests'    => $catalog,
			'total'    => count( $catalog ),
			'runnable' => $runnable,
		);
	}

	/**
	 * Run WordPress own direct Site Health tests and report their results.
	 *
	 * The optional `tests` input is a *selector* validated against the registry
	 * that `list-site-health-tests` reports; a name that is not a runnable
	 * direct core test is refused. Nothing here calls a caller-supplied
	 * function: the method invoked is always `WP_Site_Health::get_test_{name}`
	 * on core's own singleton, checked with `method_exists()` first.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function run_site_health_tests( $input = array() ) {
		$denied = self::require_site_health_capability();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$catalog = self::site_health_catalog();
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$runnable = array();
		foreach ( $catalog as $entry ) {
			if ( $entry['runnable'] ) {
				$runnable[ $entry['name'] ] = $entry;
			}
		}

		$selected = array_keys( $runnable );
		if ( isset( $input['tests'] ) && array() !== $input['tests'] ) {
			if ( ! is_array( $input['tests'] ) ) {
				return WP_MCP_Errors::system_validation_error( __( 'The "tests" field must be an array of Site Health test names.', 'wordpress-mcp-abilities' ) );
			}
			$selected = array();
			foreach ( $input['tests'] as $name ) {
				if ( ! is_string( $name ) || ! isset( $runnable[ $name ] ) ) {
					return WP_MCP_Errors::system_validation_error(
						sprintf(
							/* translators: %s: Site Health test name supplied by the caller */
							__( '"%s" is not a runnable direct Site Health test on this site. Use list-site-health-tests to discover the available names.', 'wordpress-mcp-abilities' ),
							is_string( $name ) ? $name : gettype( $name )
						)
					);
				}
				$selected[] = $name;
			}
			$selected = array_values( array_unique( $selected ) );
		}

		if ( count( $selected ) > self::MAX_HEALTH_TESTS ) {
			$selected = array_slice( $selected, 0, self::MAX_HEALTH_TESTS );
		}

		$health = self::site_health_instance();
		if ( is_wp_error( $health ) ) {
			return $health;
		}

		$results = array();
		$counts  = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);

		foreach ( $selected as $name ) {
			$method = 'get_test_' . $name;
			if ( ! method_exists( $health, $method ) ) {
				continue; // Defensive: the catalog already checked this.
			}

			$result = call_user_func( array( $health, $method ) );
			if ( ! is_array( $result ) ) {
				continue;
			}

			$status = isset( $result['status'] ) ? (string) $result['status'] : '';
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}

			$results[] = array(
				'name'        => $name,
				'label'       => isset( $result['label'] ) ? (string) $result['label'] : $runnable[ $name ]['label'],
				'status'      => $status,
				'badge_label' => isset( $result['badge']['label'] ) ? (string) $result['badge']['label'] : '',
				'badge_color' => isset( $result['badge']['color'] ) ? (string) $result['badge']['color'] : '',
				'description' => isset( $result['description'] ) ? wp_strip_all_tags( (string) $result['description'] ) : '',
			);
		}

		return array(
			'results'     => $results,
			'total'       => count( $results ),
			'good'        => $counts['good'],
			'recommended' => $counts['recommended'],
			'critical'    => $counts['critical'],
		);
	}

	/* ==================================================================
	 * Image sizes, rewrite state and directory sizes (issue #12)
	 * ================================================================ */

	/**
	 * List the image sub-sizes WordPress will generate on upload.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_image_sizes( $input = array() ) {
		$denied = self::require_manage_options();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		if ( ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
			return WP_MCP_Errors::system_unsupported( __( 'This WordPress installation does not expose the registered image sub-sizes.', 'wordpress-mcp-abilities' ) );
		}

		$sizes = array();
		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			$sizes[] = array(
				'name'   => (string) $name,
				'width'  => isset( $size['width'] ) ? (int) $size['width'] : 0,
				'height' => isset( $size['height'] ) ? (int) $size['height'] : 0,
				'crop'   => ! empty( $size['crop'] ),
			);
		}

		return array(
			'sizes' => $sizes,
			'total' => count( $sizes ),
		);
	}

	/**
	 * Report the permalink and rewrite configuration.
	 *
	 * Reports whether the server supports rewriting and how many rules are
	 * cached — never the location of an `.htaccess` or `web.config` file.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_rewrite_state( $input = array() ) {
		$denied = self::require_manage_options();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		global $wp_rewrite;

		$rules = get_option( 'rewrite_rules' );

		if ( ! function_exists( 'got_rewrite' ) && file_exists( ABSPATH . 'wp-admin/includes/misc.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		return array(
			'permalink_structure'    => (string) get_option( 'permalink_structure' ),
			'using_permalinks'       => (bool) $wp_rewrite->using_permalinks(),
			'using_index_permalinks' => (bool) $wp_rewrite->using_index_permalinks(),
			'front'                  => (string) $wp_rewrite->front,
			'root'                   => (string) $wp_rewrite->root,
			'category_base'          => (string) get_option( 'category_base' ),
			'tag_base'               => (string) get_option( 'tag_base' ),
			'author_base'            => (string) $wp_rewrite->author_base,
			'search_base'            => (string) $wp_rewrite->search_base,
			'pagination_base'        => (string) $wp_rewrite->pagination_base,
			'comments_base'          => (string) $wp_rewrite->comments_base,
			'feed_base'              => (string) $wp_rewrite->feed_base,
			'cached_rules'           => is_array( $rules ) ? count( $rules ) : 0,
			'server_supports_rewrite' => function_exists( 'got_rewrite' ) ? (bool) got_rewrite() : false,
			'url_rewrite_available'  => function_exists( 'got_url_rewrite' ) ? (bool) got_url_rewrite() : false,
		);
	}

	/**
	 * Report the size of the WordPress directories and the database.
	 *
	 * Sizes only. Only the `raw` and `size` members of what WordPress returns
	 * are copied into the response, so a `path` member — which the deprecated
	 * `WP_Debug_Data::get_sizes()` does include — can never reach a client.
	 *
	 * This walks the whole installation and is slow on large sites; WordPress
	 * own timeout protection applies and a partial result is reported as an
	 * error by core, which is surfaced here as `wp_mcp_system_unsupported`.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_site_size( $input = array() ) {
		$denied = self::require_manage_options();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		if ( ! class_exists( 'WP_REST_Site_Health_Controller' ) ) {
			return WP_MCP_Errors::system_unsupported( __( 'This WordPress installation does not expose the directory size API.', 'wordpress-mcp-abilities' ) );
		}

		// The controller takes the Site Health singleton, which also carries
		// the timeout protection its size walk relies on.
		$health = self::site_health_instance();
		if ( is_wp_error( $health ) ) {
			return $health;
		}

		$controller = new WP_REST_Site_Health_Controller( $health );
		$sizes      = $controller->get_directory_sizes();

		if ( is_wp_error( $sizes ) ) {
			return WP_MCP_Errors::system_unsupported( $sizes->get_error_message() );
		}

		$entries = array();
		foreach ( $sizes as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$entries[] = array(
				'name'      => (string) $key,
				'raw_bytes' => isset( $entry['raw'] ) ? (int) $entry['raw'] : 0,
				'size'      => isset( $entry['size'] ) ? (string) $entry['size'] : '',
			);
		}

		return array(
			'entries' => $entries,
			'total'   => count( $entries ),
		);
	}

	/* ==================================================================
	 * Internal helpers
	 * ================================================================ */

	/**
	 * Shared `manage_options` gate for the inspection abilities.
	 *
	 * @return true|WP_Error
	 */
	private static function require_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return WP_MCP_Errors::system_permission_denied();
		}
		return true;
	}

	/**
	 * Site Health gate: WordPress own `view_site_health_checks` capability.
	 *
	 * @return true|WP_Error
	 */
	private static function require_site_health_capability() {
		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			return WP_MCP_Errors::system_permission_denied( __( 'You do not have permission to view Site Health checks.', 'wordpress-mcp-abilities' ) );
		}
		return true;
	}

	/**
	 * Describe every registered Site Health test with a stable
	 * machine-readable operability reason.
	 *
	 * @return array<int,array{name:string,label:string,group:string,runnable:bool,reason:string}>|WP_Error
	 */
	private static function site_health_catalog() {
		$health = self::site_health_instance();
		if ( is_wp_error( $health ) ) {
			return $health;
		}

		$tests   = WP_Site_Health::get_tests();
		$catalog = array();

		foreach ( array( 'direct', 'async' ) as $group ) {
			if ( empty( $tests[ $group ] ) || ! is_array( $tests[ $group ] ) ) {
				continue;
			}
			foreach ( $tests[ $group ] as $name => $test ) {
				$label = ( is_array( $test ) && isset( $test['label'] ) ) ? (string) $test['label'] : (string) $name;

				if ( 'async' === $group ) {
					$catalog[] = array(
						'name'     => (string) $name,
						'label'    => $label,
						'group'    => 'async',
						'runnable' => false,
						'reason'   => 'async_test',
					);
					continue;
				}

				$callback = ( is_array( $test ) && isset( $test['test'] ) ) ? $test['test'] : null;
				if ( ! is_string( $callback ) ) {
					$catalog[] = array(
						'name'     => (string) $name,
						'label'    => $label,
						'group'    => 'direct',
						'runnable' => false,
						'reason'   => 'third_party_callback',
					);
					continue;
				}

				$runnable  = method_exists( $health, 'get_test_' . $callback );
				$catalog[] = array(
					'name'     => (string) $callback,
					'label'    => $label,
					'group'    => 'direct',
					'runnable' => $runnable,
					'reason'   => $runnable ? 'operable' : 'unknown_core_test',
				);
			}
		}

		return $catalog;
	}

	/**
	 * Load and return WordPress own Site Health singleton.
	 *
	 * @return WP_Site_Health|WP_Error
	 */
	private static function site_health_instance() {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			foreach ( array( 'file.php', 'plugin.php', 'update.php', 'misc.php', 'class-wp-site-health.php' ) as $include ) {
				if ( file_exists( ABSPATH . 'wp-admin/includes/' . $include ) ) {
					require_once ABSPATH . 'wp-admin/includes/' . $include;
				}
			}
		}

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return WP_MCP_Errors::system_unsupported( __( 'Site Health is not available on this WordPress installation.', 'wordpress-mcp-abilities' ) );
		}

		return WP_Site_Health::get_instance();
	}

	/** Whether the caller may see any part of the updates snapshot. */
	private static function can_view_any_updates() {
		return current_user_can( 'update_core' )
			|| current_user_can( 'update_plugins' )
			|| current_user_can( 'update_themes' )
			|| current_user_can( 'update_languages' );
	}

	/**
	 * The `response` map of an update transient, or an empty array.
	 *
	 * @param string $name Site transient name.
	 * @return array
	 */
	private static function transient_response( $name ) {
		$transient = get_site_transient( $name );
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}
		return $transient->response;
	}

	/**
	 * Pending language pack updates, as WordPress itself reports them.
	 *
	 * @return object[]
	 */
	private static function pending_translation_updates() {
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			return array();
		}
		// Core always returns an array here, so there is no falsy case to guard.
		/** @var object[] $updates */
		$updates = wp_get_translation_updates();
		return $updates;
	}

	/**
	 * Reduce one translation update object to the fields the schema exposes.
	 *
	 * @param object $update Translation update object.
	 * @return array
	 */
	private static function format_translation( $update ) {
		return array(
			'type'     => isset( $update->type ) ? (string) $update->type : '',
			'slug'     => isset( $update->slug ) ? (string) $update->slug : '',
			'language' => isset( $update->language ) ? (string) $update->language : '',
			'version'  => isset( $update->version ) ? (string) $update->version : '',
		);
	}

	/**
	 * Whether this installation satisfies an item's declared requirements.
	 * An unstated requirement is treated as satisfied, exactly as core does.
	 *
	 * @param string $requires_wp  Minimum WordPress version, or ''.
	 * @param string $requires_php Minimum PHP version, or ''.
	 * @return bool
	 */
	private static function is_compatible( $requires_wp, $requires_php ) {
		$wp_ok  = ( '' === $requires_wp ) || ! function_exists( 'is_wp_version_compatible' ) || is_wp_version_compatible( $requires_wp );
		$php_ok = ( '' === $requires_php ) || ! function_exists( 'is_php_version_compatible' ) || is_php_version_compatible( $requires_php );
		return (bool) ( $wp_ok && $php_ok );
	}

	/** Load get_preferred_from_update_core() and get_plugins() if not already loaded. */
	private static function ensure_update_functions() {
		if ( ! function_exists( 'get_preferred_from_update_core' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Load the core upgrader/skin classes if not already loaded.
	 * class-wp-upgrader.php pulls in Core_Upgrader itself on all supported
	 * WordPress versions; the direct require of class-core-upgrader.php is
	 * a defensive fallback guarded by file_exists() so an unexpected
	 * WordPress core layout degrades to a graceful error (see the
	 * class_exists() check the caller must perform) instead of a fatal
	 * missing-file require.
	 */
	private static function ensure_upgrader_classes() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'Core_Upgrader' ) && file_exists( ABSPATH . 'wp-admin/includes/class-core-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		}
		if ( ! class_exists( 'Language_Pack_Upgrader' ) && file_exists( ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-language-pack-upgrader.php';
		}
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
	}

	/** Whether the classes ensure_upgrader_classes() attempts to load are actually available. */
	private static function upgrader_classes_available() {
		return class_exists( 'Core_Upgrader' ) && class_exists( 'WP_Ajax_Upgrader_Skin' );
	}
}
