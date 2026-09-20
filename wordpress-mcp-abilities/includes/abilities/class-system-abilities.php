<?php
/**
 * WordPress MCP Abilities — System domain registration.
 *
 * WordPress core updates (issue #9) plus system inspection: site identity,
 * environment, Site Health, registered image sizes, rewrite state and
 * directory sizes (issue #12).
 *
 * Every schema here is closed (`additionalProperties: false`). No ability in
 * this domain accepts a path, a callback name, an option name or a SQL
 * fragment, and no response carries a filesystem path or a secret.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_System_Abilities
 */
class WP_MCP_System_Abilities {

	/** Register all system abilities. */
	public static function register() {
		self::register_core_updates();
		self::register_inspection();
		self::register_site_health();
	}

	/* ------------------------------------------------------------------
	 * Core updates (issue #9)
	 * ---------------------------------------------------------------- */

	/** Register the WordPress core update abilities. */
	private static function register_core_updates() {
		wp_register_ability( 'wp-mcp/get-core-update-status', array(
			'label'       => __( 'Get Core Update Status', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report the current WordPress version and whether a core update is available, without forcing a fresh version check.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'current_version'  => array( 'type' => 'string' ),
				'latest_version'   => array( 'type' => 'string' ),
				'update_available' => array( 'type' => 'boolean' ),
				'locale'           => array( 'type' => 'string' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'get_core_update_status' ),
			'permission_callback' => function () { return current_user_can( 'update_core' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/update-core', array(
			'label'       => __( 'Update WordPress Core', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update WordPress core to the preferred available version. Unlike plugin/theme updates, WordPress has no automatic backup-and-restore mechanism for a manual core update; this ability does not implement its own rollback.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'from_version' => array( 'type' => 'string' ),
				'to_version'   => array( 'type' => 'string' ),
				'updated'      => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'update_core' ),
			'permission_callback' => function () { return current_user_can( 'update_core' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/list-available-updates', array(
			'label'       => __( 'List Available Updates', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report every pending core, plugin, theme and translation update, with each item\'s new version and its WordPress/PHP compatibility. Mirrors the wp-admin Updates screen and never forces a fresh WordPress.org check. Sections the caller lacks the capability for come back empty, with the matching can_view_* flag false.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'core'                   => self::object_schema( array(
					'current_version'  => array( 'type' => 'string' ),
					'latest_version'   => array( 'type' => 'string' ),
					'update_available' => array( 'type' => 'boolean' ),
					'locale'           => array( 'type' => 'string' ),
				), array() ),
				'plugins'                => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'file'            => array( 'type' => 'string' ),
						'name'            => array( 'type' => 'string' ),
						'current_version' => array( 'type' => 'string' ),
						'new_version'     => array( 'type' => 'string' ),
						'requires_wp'     => array( 'type' => 'string' ),
						'requires_php'    => array( 'type' => 'string' ),
						'tested_up_to'    => array( 'type' => 'string' ),
						'compatible'      => array( 'type' => 'boolean' ),
					), array() ),
				),
				'themes'                 => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'stylesheet'      => array( 'type' => 'string' ),
						'name'            => array( 'type' => 'string' ),
						'current_version' => array( 'type' => 'string' ),
						'new_version'     => array( 'type' => 'string' ),
						'requires_wp'     => array( 'type' => 'string' ),
						'requires_php'    => array( 'type' => 'string' ),
						'compatible'      => array( 'type' => 'boolean' ),
					), array() ),
				),
				'translations'           => array(
					'type'  => 'array',
					'items' => self::translation_schema(),
				),
				'can_view_core'          => array( 'type' => 'boolean' ),
				'can_view_plugins'       => array( 'type' => 'boolean' ),
				'can_view_themes'        => array( 'type' => 'boolean' ),
				'can_view_translations'  => array( 'type' => 'boolean' ),
				'plugins_truncated'      => array( 'type' => 'boolean' ),
				'themes_truncated'       => array( 'type' => 'boolean' ),
				'translations_truncated' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'list_available_updates' ),
			'permission_callback' => function () {
				return current_user_can( 'update_core' )
					|| current_user_can( 'update_plugins' )
					|| current_user_can( 'update_themes' )
					|| current_user_can( 'update_languages' );
			},
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/update-translations', array(
			'label'       => __( 'Update Translations', 'wordpress-mcp-abilities' ),
			'description' => __( 'Install every pending translation (language pack) update. Takes no source parameters: the package list comes from WordPress\'s own update data, never from the caller.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'results'   => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'type'     => array( 'type' => 'string' ),
						'slug'     => array( 'type' => 'string' ),
						'language' => array( 'type' => 'string' ),
						'version'  => array( 'type' => 'string' ),
						'updated'  => array( 'type' => 'boolean' ),
						'error'    => array( 'type' => 'string' ),
					), array() ),
				),
				'requested' => array( 'type' => 'integer' ),
				'updated'   => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'update_translations' ),
			'permission_callback' => function () { return current_user_can( 'update_languages' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function translation_schema() {
		return self::object_schema( array(
			'type'     => array( 'type' => 'string' ),
			'slug'     => array( 'type' => 'string' ),
			'language' => array( 'type' => 'string' ),
			'version'  => array( 'type' => 'string' ),
		), array() );
	}

	/* ------------------------------------------------------------------
	 * Inspection (issue #12)
	 * ---------------------------------------------------------------- */

	/** Register the site/environment inspection abilities. */
	private static function register_inspection() {
		wp_register_ability( 'wp-mcp/get-site-info', array(
			'label'       => __( 'Get Site Info', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report the site identity, active theme and content counts. Contains no filesystem paths and no credentials.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'name'                  => array( 'type' => 'string' ),
				'description'           => array( 'type' => 'string' ),
				'home_url'              => array( 'type' => 'string' ),
				'site_url'              => array( 'type' => 'string' ),
				'wp_version'            => array( 'type' => 'string' ),
				'locale'                => array( 'type' => 'string' ),
				'timezone_string'       => array( 'type' => 'string' ),
				'gmt_offset'            => array( 'type' => 'number' ),
				'charset'               => array( 'type' => 'string' ),
				'is_multisite'          => array( 'type' => 'boolean' ),
				'search_engine_visible' => array( 'type' => 'boolean' ),
				'active_theme'          => array( 'type' => 'string' ),
				'active_theme_slug'     => array( 'type' => 'string' ),
				'active_theme_version'  => array( 'type' => 'string' ),
				'is_block_theme'        => array( 'type' => 'boolean' ),
				'published_posts'       => array( 'type' => 'integer' ),
				'published_pages'       => array( 'type' => 'integer' ),
				'media_items'           => array( 'type' => 'integer' ),
				'approved_comments'     => array( 'type' => 'integer' ),
				'pending_comments'      => array( 'type' => 'integer' ),
				'total_users'           => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'get_site_info' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-environment-info', array(
			'label'       => __( 'Get Environment Info', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report the PHP, WordPress and database environment: versions, limits and capability flags. Never returns credentials, salts, log locations or filesystem paths.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'wp_version'              => array( 'type' => 'string' ),
				'environment_type'        => array( 'type' => 'string' ),
				'is_multisite'            => array( 'type' => 'boolean' ),
				'php_version'             => array( 'type' => 'string' ),
				'php_sapi'                => array( 'type' => 'string' ),
				'php_memory_limit'        => array( 'type' => 'string' ),
				'php_max_execution_time'  => array( 'type' => 'string' ),
				'php_upload_max_filesize' => array( 'type' => 'string' ),
				'php_post_max_size'       => array( 'type' => 'string' ),
				'php_max_input_vars'      => array( 'type' => 'string' ),
				'wp_memory_limit'         => array( 'type' => 'string' ),
				'wp_max_memory_limit'     => array( 'type' => 'string' ),
				'wp_debug'                => array( 'type' => 'boolean' ),
				'wp_debug_log_enabled'    => array( 'type' => 'boolean' ),
				'script_debug'            => array( 'type' => 'boolean' ),
				'database_server_version' => array( 'type' => 'string' ),
				'database_charset'        => array( 'type' => 'string' ),
				'database_collate'        => array( 'type' => 'string' ),
				'https_home_url'          => array( 'type' => 'boolean' ),
				'external_object_cache'   => array( 'type' => 'boolean' ),
				'curl_available'          => array( 'type' => 'boolean' ),
				'openssl_version'         => array( 'type' => 'string' ),
				'imagick_available'       => array( 'type' => 'boolean' ),
				'gd_available'            => array( 'type' => 'boolean' ),
				'server_time_utc'         => array( 'type' => 'string' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'get_environment_info' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-image-sizes', array(
			'label'       => __( 'List Image Sizes', 'wordpress-mcp-abilities' ),
			'description' => __( 'List the image sub-sizes WordPress generates on upload, with their dimensions and crop mode.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'sizes' => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'name'   => array( 'type' => 'string' ),
						'width'  => array( 'type' => 'integer' ),
						'height' => array( 'type' => 'integer' ),
						'crop'   => array( 'type' => 'boolean' ),
					), array() ),
				),
				'total' => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'list_image_sizes' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-rewrite-state', array(
			'label'       => __( 'Get Rewrite State', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report the permalink structure, rewrite bases and whether the server supports URL rewriting. Never returns the location of an .htaccess or web.config file.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'permalink_structure'     => array( 'type' => 'string' ),
				'using_permalinks'        => array( 'type' => 'boolean' ),
				'using_index_permalinks'  => array( 'type' => 'boolean' ),
				'front'                   => array( 'type' => 'string' ),
				'root'                    => array( 'type' => 'string' ),
				'category_base'           => array( 'type' => 'string' ),
				'tag_base'                => array( 'type' => 'string' ),
				'author_base'             => array( 'type' => 'string' ),
				'search_base'             => array( 'type' => 'string' ),
				'pagination_base'         => array( 'type' => 'string' ),
				'comments_base'           => array( 'type' => 'string' ),
				'feed_base'               => array( 'type' => 'string' ),
				'cached_rules'            => array( 'type' => 'integer' ),
				'server_supports_rewrite' => array( 'type' => 'boolean' ),
				'url_rewrite_available'   => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'get_rewrite_state' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-site-size', array(
			'label'       => __( 'Get Site Size', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report the size of the WordPress directories and the database. Sizes only: the filesystem paths WordPress measures are never included. Walks the whole installation and can be slow on large sites.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'entries' => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'name'      => array( 'type' => 'string' ),
						'raw_bytes' => array( 'type' => 'integer' ),
						'size'      => array( 'type' => 'string' ),
					), array() ),
				),
				'total'   => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'get_site_size' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Site Health (issue #12)
	 * ---------------------------------------------------------------- */

	/** Register the Site Health discovery and run abilities. */
	private static function register_site_health() {
		wp_register_ability( 'wp-mcp/list-site-health-tests', array(
			'label'       => __( 'List Site Health Tests', 'wordpress-mcp-abilities' ),
			'description' => __( 'Describe the Site Health tests WordPress has registered and which of them this plugin will run. Asynchronous tests and third-party tests registered as a callable are listed as not runnable, with the reason.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'tests' => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'name'     => array( 'type' => 'string' ),
						'label'    => array( 'type' => 'string' ),
						'group'    => array( 'type' => 'string', 'enum' => array( 'direct', 'async' ) ),
						'runnable' => array( 'type' => 'boolean' ),
						'reason'   => array( 'type' => 'string', 'enum' => array( 'operable', 'async_test', 'third_party_callback', 'unknown_core_test' ) ),
					), array() ),
				),
				'total'    => array( 'type' => 'integer' ),
				'runnable' => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'list_site_health_tests' ),
			'permission_callback' => function () { return current_user_can( 'view_site_health_checks' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/run-site-health-tests', array(
			'label'       => __( 'Run Site Health Tests', 'wordpress-mcp-abilities' ),
			'description' => __( 'Run WordPress own direct Site Health tests and report their results. The optional test list is validated against the registry; a name that is not a runnable core test is refused, and no caller-supplied callback is ever invoked.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(
				'tests' => array(
					'type'        => 'array',
					'description' => 'Optional subset of runnable direct test names, as reported by list-site-health-tests. Omit to run all of them.',
					'maxItems'    => 40,
					'items'       => array( 'type' => 'string' ),
				),
			), array() ),
			'output_schema' => self::object_schema( array(
				'results' => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'name'        => array( 'type' => 'string' ),
						'label'       => array( 'type' => 'string' ),
						'status'      => array( 'type' => 'string' ),
						'badge_label' => array( 'type' => 'string' ),
						'badge_color' => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					), array() ),
				),
				'total'       => array( 'type' => 'integer' ),
				'good'        => array( 'type' => 'integer' ),
				'recommended' => array( 'type' => 'integer' ),
				'critical'    => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_System', 'run_site_health_tests' ),
			'permission_callback' => function () { return current_user_can( 'view_site_health_checks' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
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
