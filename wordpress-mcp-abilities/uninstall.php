<?php
/**
 * WordPress MCP Abilities — Uninstall.
 *
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * Behaviour:
 *  - If no user has the wp_mcp_agent role → remove the role.
 *  - If any user still has the role       → preserve the role.
 *  - Never deletes users.
 *  - Cleans up plugin options.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.1.0
 */

// Security: abort if not called by WordPress uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* =====================================================================
 * Clean up the role (only if no users are assigned to it)
 * =================================================================== */

$users_with_role = get_users(
	array(
		'role'   => 'wp_mcp_agent',
		'number' => 1, // We only need to know if at least one exists.
	)
);

if ( empty( $users_with_role ) ) {
	remove_role( 'wp_mcp_agent' );
}

/* =====================================================================
 * Clean up plugin options
 * =================================================================== */

delete_option( 'wp_mcp_role_version' );
