<?php
/**
 * WordPress MCP Abilities — Network plugin and theme callbacks (issue #13).
 *
 * The Network Admin "Plugins" and "Themes" screens: network activation of a
 * plugin, and network enabling of a theme so site administrators may pick it.
 *
 * Deliberately *not* duplicated here: installing, updating and deleting
 * plugins and themes. On a multisite network WordPress already restricts
 * `install_plugins` / `update_plugins` / `delete_plugins` (and their theme
 * counterparts) to Super Admins, so the issue #9 abilities are already the
 * network-level operations — a second, near-identical surface would be a
 * weaker duplicate of a gate that already works.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network_Extensions
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Network_Extensions {

	/** Maximum number of themes returned by one list request. */
	const MAX_PER_PAGE = 50;

	/* ------------------------------------------------------------------
	 * Network plugins
	 * ---------------------------------------------------------------- */

	/**
	 * Activate a plugin across the whole network. Idempotent: a plugin that
	 * is already network-active is reported as-is.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function network_activate_plugin( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( 'manage_network_plugins' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		$file = self::validate_installed_plugin_file( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( is_plugin_active_for_network( $file ) ) {
			return self::format_plugin( $file );
		}

		$result = activate_plugin( $file, '', true, true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/network-activate-plugin', 0, false, 'wp_mcp_plugin_activate_failed', array( 'slug' => $file ) );
			return WP_MCP_Errors::plugin_activate_failed( $result->get_error_message() );
		}

		WP_MCP_Audit::log( 'wp-mcp/network-activate-plugin', 0, true, '', array( 'slug' => $file ) );
		return self::format_plugin( $file );
	}

	/**
	 * Deactivate a plugin across the whole network. Idempotent: deactivating
	 * a plugin that is not network-active is a no-op success.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function network_deactivate_plugin( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( 'manage_network_plugins' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		$file = self::validate_installed_plugin_file( $input );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! is_plugin_active_for_network( $file ) ) {
			return self::format_plugin( $file );
		}

		deactivate_plugins( array( $file ), false, true );

		WP_MCP_Audit::log( 'wp-mcp/network-deactivate-plugin', 0, true, '', array( 'slug' => $file ) );
		return self::format_plugin( $file );
	}

	/* ------------------------------------------------------------------
	 * Network themes
	 * ---------------------------------------------------------------- */

	/**
	 * List installed themes with their network-enabled state.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_network_themes( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( 'manage_network_themes' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$all = wp_get_themes();
		if ( ! empty( $input['search'] ) && is_string( $input['search'] ) ) {
			$needle = strtolower( sanitize_text_field( $input['search'] ) );
			$all    = array_filter(
				$all,
				function ( $theme ) use ( $needle ) {
					return false !== strpos( strtolower( (string) $theme->get( 'Name' ) ), $needle );
				}
			);
		}

		if ( isset( $input['network_enabled'] ) ) {
			if ( ! is_bool( $input['network_enabled'] ) ) {
				return WP_MCP_Errors::network_validation_error( __( 'network_enabled must be a boolean.', 'wordpress-mcp-abilities' ) );
			}
			$allowed = self::allowed_themes();
			$wanted  = $input['network_enabled'];
			$all     = array_filter(
				$all,
				function ( $theme ) use ( $allowed, $wanted ) {
					return isset( $allowed[ $theme->get_stylesheet() ] ) === $wanted;
				}
			);
		}

		$stylesheets = array_keys( $all );
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 20, self::MAX_PER_PAGE );
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
	 * Make a theme selectable by every site of the network. Idempotent.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function network_enable_theme( $input = array() ) {
		$theme = self::require_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}

		$stylesheet = $theme->get_stylesheet();
		$allowed    = self::allowed_themes();
		if ( isset( $allowed[ $stylesheet ] ) ) {
			return self::format_theme( $theme );
		}

		WP_Theme::network_enable_theme( $stylesheet );

		WP_MCP_Audit::log( 'wp-mcp/network-enable-theme', 0, true, '', array( 'slug' => $stylesheet ) );
		return self::format_theme( $theme );
	}

	/**
	 * Stop offering a theme to the sites of the network. Idempotent.
	 *
	 * Refuses the theme currently active on the main site: disabling it would
	 * leave the network's own front page pointing at a theme the network no
	 * longer allows.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function network_disable_theme( $input = array() ) {
		$theme = self::require_theme( $input );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}

		$stylesheet = $theme->get_stylesheet();
		$allowed    = self::allowed_themes();
		if ( ! isset( $allowed[ $stylesheet ] ) ) {
			return self::format_theme( $theme );
		}
		if ( self::main_site_stylesheet() === $stylesheet ) {
			return WP_MCP_Errors::network_conflict( __( 'That theme is the active theme of the main site and cannot be disabled for the network.', 'wordpress-mcp-abilities' ) );
		}

		WP_Theme::network_disable_theme( $stylesheet );

		WP_MCP_Audit::log( 'wp-mcp/network-disable-theme', 0, true, '', array( 'slug' => $stylesheet ) );
		return self::format_theme( $theme );
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ---------------------------------------------------------------- */

	/** Load get_plugins()/is_plugin_active_for_network() if not already loaded. */
	private static function ensure_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Validate that plugin_file identifies an installed plugin.
	 *
	 * @param mixed $input Ability input.
	 * @return string|WP_Error
	 */
	private static function validate_installed_plugin_file( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
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
	 * Capability gate plus stylesheet validation.
	 *
	 * @param mixed $input Ability input.
	 * @return WP_Theme|WP_Error
	 */
	private static function require_theme( $input ) {
		$denied = WP_MCP_Network::require_network_cap( 'manage_network_themes' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$stylesheet = isset( $input['stylesheet'] ) ? $input['stylesheet'] : '';
		if ( ! is_string( $stylesheet ) || '' === $stylesheet || false !== strpos( $stylesheet, '..' ) || false !== strpos( $stylesheet, '/' ) || false !== strpos( $stylesheet, '\\' ) ) {
			return WP_MCP_Errors::theme_validation_error( __( 'stylesheet must be an installed theme directory name.', 'wordpress-mcp-abilities' ) );
		}
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return WP_MCP_Errors::invalid_theme();
		}
		return $theme;
	}

	/**
	 * Themes allowed on the network, as stylesheet => true.
	 *
	 * Reads the stored `allowedthemes` network option rather than calling
	 * `WP_Theme::get_allowed_on_network()`, for two reasons: that method
	 * memoises its result in a `static` for the whole request, so a value
	 * read back immediately after `network_enable_theme()` would still be
	 * the pre-write one; and it runs the `allowed_themes` filter, whose
	 * result is what a site may *use*, not what the network administrator
	 * has actually stored and can toggle here.
	 *
	 * @return array<string,bool>
	 */
	private static function allowed_themes() {
		return (array) get_network_option( null, 'allowedthemes', array() );
	}

	/**
	 * The stylesheet the main site of the network currently runs.
	 *
	 * @return string
	 */
	private static function main_site_stylesheet() {
		return (string) get_blog_option( get_main_site_id(), 'stylesheet', '' );
	}

	/**
	 * Format one plugin for the network views.
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 * @return array<string,mixed>
	 */
	private static function format_plugin( $file ) {
		self::ensure_plugin_functions();
		$plugins = get_plugins();
		$data    = isset( $plugins[ $file ] ) ? $plugins[ $file ] : array();

		return array(
			'file'                   => $file,
			'name'                   => isset( $data['Name'] ) ? wp_strip_all_tags( (string) $data['Name'] ) : '',
			'version'                => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			'network_active'         => is_plugin_active_for_network( $file ),
			'active_on_current_site' => is_plugin_active( $file ),
		);
	}

	/**
	 * Format one theme for the network views.
	 *
	 * @param WP_Theme $theme Theme object.
	 * @return array<string,mixed>
	 */
	private static function format_theme( $theme ) {
		$stylesheet = $theme->get_stylesheet();
		$allowed    = self::allowed_themes();

		return array(
			'stylesheet'          => $stylesheet,
			'name'                => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
			'version'             => (string) $theme->get( 'Version' ),
			'network_enabled'     => isset( $allowed[ $stylesheet ] ),
			'active_on_main_site' => self::main_site_stylesheet() === $stylesheet,
		);
	}
}
