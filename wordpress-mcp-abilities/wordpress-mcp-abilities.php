<?php
/**
 * Plugin Name:       WordPress MCP Abilities
 * Plugin URI:        https://github.com/ToniLopezPhoto/wordpress_mcp_abilities
 * Description:       WordPress Abilities for AI agent content management via MCP Adapter.
 * Version:           0.16.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Requires Plugins:  mcp-adapter
 * Author:            Toni López
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wordpress-mcp-abilities
 * Domain Path:       /languages
 *
 * @package WP_MCP_Agent_Abilities
 */

defined( 'ABSPATH' ) || exit;

/* =====================================================================
 * Constants
 * =================================================================== */

define( 'WP_MCP_VERSION', '0.16.0' );
define( 'WP_MCP_ROLE_VERSION', '1' );
define( 'WP_MCP_PLUGIN_FILE', __FILE__ );
define( 'WP_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/* =====================================================================
 * PHP version guard
 * =================================================================== */

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'WordPress MCP Abilities requires PHP %1$s or higher. You are running PHP %2$s.', 'wordpress-mcp-abilities' ),
					'7.4',
					PHP_VERSION
				)
			)
		);
	} );
	return;
}

/* =====================================================================
 * WordPress Abilities API guard
 * =================================================================== */

if ( ! function_exists( 'wp_register_ability' ) ) {
	add_action( 'admin_notices', function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'WordPress MCP Abilities requires WordPress 6.9 or higher (Abilities API).', 'wordpress-mcp-abilities' )
		);
	} );
	return;
}

/* =====================================================================
 * MCP Adapter guard
 *
 * The "Requires Plugins: mcp-adapter" header handles this at the
 * WordPress plugin manager level, but we add a runtime check as
 * belt-and-suspenders for manual installations.
 * =================================================================== */

add_action( 'plugins_loaded', function () {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) ) {
		add_action( 'admin_notices', function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'WordPress MCP Abilities requires the MCP Adapter plugin to be installed and active.', 'wordpress-mcp-abilities' )
			);
		} );
		return;
	}

	/* =================================================================
	 * Load includes
	 * =============================================================== */

	$includes = array(
		'class-permissions',
		'class-audit',
		'class-role',
		'class-ability-schema',
		'class-ability-errors',
		'class-posts',
		'class-pages',
		'class-media',
		'class-taxonomies',
		'class-comments',
		'class-users',
		'class-navigation',
		'class-site-editor',
		'class-plugins',
		'class-themes',
		'class-system',
		'class-settings',
		'class-cron',
		'class-maintenance',
		'class-import-export',
		'class-privacy',
		'class-post-types',
		'class-content-lifecycle',
		'class-network',
		'class-network-sites',
		'class-network-users',
		'class-network-extensions',
		'class-network-settings',
		'class-integration',
		'class-integration-registry',
		'class-discovery-contract',
		'class-discovery',
		'class-ability-categories',
		'class-ability-matrix',
		'abilities/class-content-abilities',
		'abilities/class-content-lifecycle-abilities',
		'abilities/class-content-attributes-abilities',
		'abilities/class-content-revisions-abilities',
		'abilities/class-content-bulk-abilities',
		'abilities/class-media-abilities',
		'abilities/class-taxonomies-abilities',
		'abilities/class-comments-abilities',
		'abilities/class-users-abilities',
		'abilities/class-navigation-abilities',
		'abilities/class-site-editor-abilities',
		'abilities/class-plugins-abilities',
		'abilities/class-themes-abilities',
		'abilities/class-system-abilities',
		'abilities/class-settings-abilities',
		'abilities/class-cron-abilities',
		'abilities/class-maintenance-abilities',
		'abilities/class-import-export-abilities',
		'abilities/class-privacy-abilities',
		'abilities/class-post-types-abilities',
		'abilities/class-network-abilities',
		'abilities/class-network-sites-abilities',
		'abilities/class-network-users-abilities',
		'abilities/class-network-extensions-abilities',
		'abilities/class-network-settings-abilities',
		'abilities/class-integrations-abilities',
		'abilities/class-discovery-abilities',
		'class-ability-registry',
		'class-plugin',
	);

	foreach ( $includes as $file ) {
		require_once WP_MCP_PLUGIN_DIR . 'includes/' . $file . '.php';
	}

	/* =================================================================
	 * Boot the plugin
	 * =============================================================== */

	WP_MCP_Plugin::instance()->init();
}, 20 ); // Priority 20 — after mcp-adapter has loaded.

/* =====================================================================
 * Activation / Deactivation hooks
 *
 * These must be registered in the main plugin file (not inside a hook).
 * =================================================================== */

register_activation_hook( __FILE__, function () {
	// Load dependencies needed for activation.
	require_once WP_MCP_PLUGIN_DIR . 'includes/class-role.php';

	WP_MCP_Role::create_or_reconcile();
} );

register_deactivation_hook( __FILE__, function () {
	// No-op: role and data are preserved on deactivation.
} );
