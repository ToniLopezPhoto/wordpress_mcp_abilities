<?php
/**
 * WordPress MCP Abilities — Network settings domain registration.
 *
 * One read, one write and one discovery ability over the declarative
 * allowlist in `WP_MCP_Network_Settings`. Every schema is generated from
 * that allowlist so the declared surface and the enforced surface cannot
 * drift apart, and every schema is closed (`additionalProperties: false`) so
 * an unknown field is rejected by schema validation as well as by the runtime
 * allowlist gate.
 *
 * There is deliberately no `get-network-option` / `update-network-option`
 * ability: clients address named fields, never WordPress network option
 * names (see epic #1's hard prohibitions and issue #13).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network_Settings_Abilities
 */
class WP_MCP_Network_Settings_Abilities {

	/** Register every network settings ability. */
	public static function register() {
		$output = self::object_schema( WP_MCP_Network_Settings::output_schema_properties(), array() );

		wp_register_ability( 'wp-mcp/get-network-settings', array(
			'label'       => __( 'Get Network Settings', 'wordpress-mcp-abilities' ),
			'description' => __( 'Read every allowlisted field of the Network Settings screen. Accepts no option name: the field set is fixed by the plugin, and no field holding a secret, a URL or a filesystem path is exposed.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-network',
			'input_schema'        => self::object_schema( array(), array() ),
			'output_schema'       => $output,
			'execute_callback'    => array( 'WP_MCP_Network_Settings', 'get_network_settings' ),
			'permission_callback' => function () { return WP_MCP_Network::can( WP_MCP_Network_Settings::CAPABILITY ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/update-network-settings', array(
			'label'       => __( 'Update Network Settings', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update one or more allowlisted fields of the Network Settings screen. Any field outside the allowlist, and any read-only field, is rejected without writing anything.', 'wordpress-mcp-abilities' ),
			'category'     => 'wp-mcp-network',
			'input_schema' => self::object_schema( WP_MCP_Network_Settings::input_schema_properties(), array() ),
			'output_schema' => self::object_schema( array(
				'updated'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'settings' => $output,
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Network_Settings', 'update_network_settings' ),
			'permission_callback' => function () { return WP_MCP_Network::can( WP_MCP_Network_Settings::CAPABILITY ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/list-network-settings-fields', array(
			'label'       => __( 'List Network Settings Fields', 'wordpress-mcp-abilities' ),
			'description' => __( 'Describe the network settings allowlist itself — which fields exist, their types, accepted values and whether they are writable — without reading any stored value.', 'wordpress-mcp-abilities' ),
			'category'     => 'wp-mcp-network',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'fields' => array( 'type' => 'array', 'items' => self::field_descriptor_schema() ),
				'total'  => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Network_Settings', 'list_network_settings_fields' ),
			'permission_callback' => function () { return WP_MCP_Network::can( WP_MCP_Network_Settings::CAPABILITY ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function field_descriptor_schema() {
		return self::object_schema( array(
			'field'          => array( 'type' => 'string' ),
			'writable'       => array( 'type' => 'boolean' ),
			'type'           => array( 'type' => 'string' ),
			'capability'     => array( 'type' => 'string' ),
			'description'    => array( 'type' => 'string' ),
			'allowed_values' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'minimum'        => array( 'type' => 'integer' ),
			'maximum'        => array( 'type' => 'integer' ),
			'max_length'     => array( 'type' => 'integer' ),
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
