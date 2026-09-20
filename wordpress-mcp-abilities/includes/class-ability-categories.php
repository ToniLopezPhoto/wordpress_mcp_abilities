<?php
/**
 * WordPress MCP Abilities — MCP ability category registration.
 *
 * Registers all MCP ability categories defined by the WordPress MCP roadmap
 * (see epic #1). Categories may be registered here before any domain
 * has abilities in them — this lets future domains (#3-#16) add
 * abilities without touching this file.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Ability_Categories
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Ability_Categories {

	/**
	 * Register all MCP ability categories.
	 *
	 * Hooked to `wp_abilities_api_categories_init`.
	 */
	public static function register_all() {
		foreach ( self::definitions() as $slug => $definition ) {
			wp_register_ability_category( $slug, $definition );
		}
	}

	/**
	 * Category definitions: slug => array( 'label', 'description' ).
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function definitions() {
		return array(
			'wp-mcp-content'       => array(
				'label'       => __( 'WordPress MCP Content', 'wordpress-mcp-abilities' ),
				'description' => __( 'Posts, pages, and their content lifecycle.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-media'         => array(
				'label'       => __( 'WordPress MCP Media', 'wordpress-mcp-abilities' ),
				'description' => __( 'Media library management.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-taxonomies'    => array(
				'label'       => __( 'WordPress MCP Taxonomies', 'wordpress-mcp-abilities' ),
				'description' => __( 'Categories, tags, and other taxonomy terms.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-comments'      => array(
				'label'       => __( 'WordPress MCP Comments', 'wordpress-mcp-abilities' ),
				'description' => __( 'Comment moderation and management.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-users'         => array(
				'label'       => __( 'WordPress MCP Users', 'wordpress-mcp-abilities' ),
				'description' => __( 'Users, roles, profiles, and application credentials.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-navigation'    => array(
				'label'       => __( 'WordPress MCP Navigation', 'wordpress-mcp-abilities' ),
				'description' => __( 'Navigation menus.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-site-editor'   => array(
				'label'       => __( 'WordPress MCP Site Editor', 'wordpress-mcp-abilities' ),
				'description' => __( 'Site Editor templates, template parts, and patterns.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-plugins'       => array(
				'label'       => __( 'WordPress MCP Plugins', 'wordpress-mcp-abilities' ),
				'description' => __( 'Plugin management.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-themes'        => array(
				'label'       => __( 'WordPress MCP Themes', 'wordpress-mcp-abilities' ),
				'description' => __( 'Theme management.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-settings'      => array(
				'label'       => __( 'WordPress MCP Settings', 'wordpress-mcp-abilities' ),
				'description' => __( 'Explicit, individually registered site settings.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-system'        => array(
				'label'       => __( 'WordPress MCP System', 'wordpress-mcp-abilities' ),
				'description' => __( 'Site health, cron, cache, and maintenance.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-privacy'       => array(
				'label'       => __( 'WordPress MCP Privacy', 'wordpress-mcp-abilities' ),
				'description' => __( 'Privacy tools and data import/export.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-network'       => array(
				'label'       => __( 'WordPress MCP Network', 'wordpress-mcp-abilities' ),
				'description' => __( 'Multisite network administration: sites, network users, network plugins and themes, network settings and network updates.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-extensibility' => array(
				'label'       => __( 'WordPress MCP Extensibility', 'wordpress-mcp-abilities' ),
				'description' => __( 'Adapters for installed plugins.', 'wordpress-mcp-abilities' ),
			),
			'wp-mcp-discovery'     => array(
				'label'       => __( 'WordPress MCP Discovery', 'wordpress-mcp-abilities' ),
				'description' => __( 'Capability-filtered global search and the registered objects, capabilities and features of this installation.', 'wordpress-mcp-abilities' ),
			),
		);
	}
}
