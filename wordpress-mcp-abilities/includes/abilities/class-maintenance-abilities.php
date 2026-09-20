<?php
/**
 * WordPress MCP Abilities — Cache and maintenance domain registration.
 *
 * Five fixed, individually named operations. None of them accepts a transient
 * name, an option name, a cache group or a filesystem path: there is no
 * `delete-transient( key )` here, and there cannot be one (see epic #1).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Maintenance_Abilities
 */
class WP_MCP_Maintenance_Abilities {

	/** Register every cache/maintenance ability. */
	public static function register() {
		wp_register_ability( 'wp-mcp/flush-object-cache', array(
			'label'       => __( 'Flush Object Cache', 'wordpress-mcp-abilities' ),
			'description' => __( 'Flush the WordPress object cache. Takes no cache group: the whole cache is flushed or nothing is.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'flushed'                    => array( 'type' => 'boolean' ),
				'external_object_cache'      => array( 'type' => 'boolean' ),
				'persistent_cache_available' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Maintenance', 'flush_object_cache' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/clear-expired-transients', array(
			'label'       => __( 'Clear Expired Transients', 'wordpress-mcp-abilities' ),
			'description' => __( 'Delete every expired transient through WordPress own cleanup routine — the same maintenance core schedules for itself. Only expired entries are removed, and no transient name is ever accepted from the caller.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'cleared'               => array( 'type' => 'boolean' ),
				'scope'                 => array( 'type' => 'string' ),
				'external_object_cache' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Maintenance', 'clear_expired_transients' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/clear-update-caches', array(
			'label'       => __( 'Clear Update Caches', 'wordpress-mcp-abilities' ),
			'description' => __( 'Drop the cached core, plugin and theme update check results so the next status read is fresh. The three site transients are fixed in the plugin; the caller names none of them.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'cleared'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'available' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Maintenance', 'clear_update_caches' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/get-maintenance-mode', array(
			'label'       => __( 'Get Maintenance Mode', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report whether WordPress is currently serving the maintenance page, when the marker was written and when WordPress will stop honouring it.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::maintenance_state_schema(),
			'execute_callback'    => array( 'WP_MCP_Maintenance', 'get_maintenance_mode' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/set-maintenance-mode', array(
			'label'       => __( 'Set Maintenance Mode', 'wordpress-mcp-abilities' ),
			'description' => __( 'Enable or disable maintenance mode through WordPress own upgrader and the WP_Filesystem abstraction — never by writing files directly, and never with a caller-supplied path. Gated on update_core because the marker lives at the WordPress root and takes an entire multisite network offline. WordPress stops honouring the marker after ten minutes on its own.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(
				'enabled' => array(
					'type'        => 'boolean',
					'description' => 'True to take the site into maintenance mode, false to bring it back.',
				),
			), array( 'enabled' ) ),
			'output_schema' => self::maintenance_state_schema(),
			'execute_callback'    => array( 'WP_MCP_Maintenance', 'set_maintenance_mode' ),
			'permission_callback' => function () { return current_user_can( 'update_core' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/**
	 * Shared maintenance-state response shape.
	 *
	 * @return array<string,mixed>
	 */
	private static function maintenance_state_schema() {
		return self::object_schema( array(
			'enabled'           => array( 'type' => 'boolean' ),
			'marker_present'    => array( 'type' => 'boolean' ),
			'enabled_since_utc' => array( 'type' => 'string' ),
			'expires_utc'       => array( 'type' => 'string' ),
			'window_seconds'    => array( 'type' => 'integer' ),
		), array() );
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
