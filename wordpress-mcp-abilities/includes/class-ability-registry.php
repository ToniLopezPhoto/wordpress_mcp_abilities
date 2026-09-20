<?php
/**
 * WordPress MCP Abilities — Ability registry.
 *
 * Orchestrates registration across all ability domains. Deliberately
 * an explicit manifest rather than directory auto-discovery: it is
 * auditable at a glance and fails loudly (missing class/typo) instead
 * of silently skipping a domain.
 *
 * To add a new domain (epic #1, issues #3-#16): create
 * includes/abilities/class-<domain>-abilities.php implementing a
 * public static register() method, add its class name to $domains
 * below, and add the file to the $includes list in
 * wordpress-mcp-abilities.php. No other file needs to change.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Ability_Registry
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Ability_Registry {

	/**
	 * Registered ability-domain classes, each exposing register().
	 *
	 * @var string[]
	 */
	private static $domains = array(
		'WP_MCP_Content_Abilities',
		'WP_MCP_Content_Lifecycle_Abilities',
		'WP_MCP_Content_Attributes_Abilities',
		'WP_MCP_Content_Revisions_Abilities',
		'WP_MCP_Content_Bulk_Abilities',
		'WP_MCP_Media_Abilities',
		'WP_MCP_Taxonomies_Abilities',
		'WP_MCP_Comments_Abilities',
		'WP_MCP_Users_Abilities',
		'WP_MCP_Navigation_Abilities',
		'WP_MCP_Site_Editor_Abilities',
		'WP_MCP_Plugins_Abilities',
		'WP_MCP_Themes_Abilities',
		'WP_MCP_System_Abilities',
		'WP_MCP_Settings_Abilities',
		'WP_MCP_Cron_Abilities',
		'WP_MCP_Maintenance_Abilities',
		'WP_MCP_Import_Export_Abilities',
		'WP_MCP_Privacy_Abilities',
		'WP_MCP_Post_Types_Abilities',
		'WP_MCP_Network_Abilities',
		'WP_MCP_Network_Sites_Abilities',
		'WP_MCP_Network_Users_Abilities',
		'WP_MCP_Network_Extensions_Abilities',
		'WP_MCP_Network_Settings_Abilities',
		'WP_MCP_Integrations_Abilities',
		'WP_MCP_Discovery_Abilities',
	);

	/**
	 * Register every ability across every domain.
	 *
	 * Hooked to `wp_abilities_api_init`.
	 */
	public static function register_all_abilities() {
		foreach ( self::$domains as $domain_class ) {
			call_user_func( array( $domain_class, 'register' ) );
		}
	}
}
