<?php
/**
 * WordPress MCP Abilities — Plugin orchestrator.
 *
 * Singleton that wires up all hooks, loads dependencies, and
 * delegates to the specialised classes.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Plugin
 */
class WP_MCP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance() instead.
	 */
	private function __construct() {}

	/**
	 * Wire up all plugin hooks.
	 *
	 * Called once from the main plugin file after dependency checks pass.
	 */
	public function init() {
		// Register ability categories (must fire before abilities).
		add_action( 'wp_abilities_api_categories_init', array( 'WP_MCP_Ability_Categories', 'register_all' ) );

		// Register abilities.
		add_action( 'wp_abilities_api_init', array( 'WP_MCP_Ability_Registry', 'register_all_abilities' ) );

		// Role reconciliation on admin pages (handles upgrades).
		add_action( 'admin_init', array( 'WP_MCP_Role', 'maybe_reconcile' ) );
	}

	/**
	 * Plugin activation callback.
	 *
	 * Creates/reconciles the wp_mcp_agent role.
	 */
	public static function activate() {
		WP_MCP_Role::create_or_reconcile();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * Intentionally a no-op: the role and all data are preserved
	 * so that re-activation is seamless.
	 */
	public static function deactivate() {
		// No-op by design.  Role is kept for safety.
	}
}
