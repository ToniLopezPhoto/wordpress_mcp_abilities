<?php
/**
 * WordPress MCP Abilities — Extensibility registration.
 *
 * Registers the two abilities that describe the integration layer itself,
 * and then hands over to `WP_MCP_Integrations` so every *available*
 * integration registers its own abilities. An integration whose plugin is
 * not installed registers nothing: an MCP client never sees a tool it cannot
 * run, which is the whole point of issue #14's acceptance criteria.
 *
 * `wp-mcp/list-integrations` is what makes that honest rather than
 * confusing. It answers with every integration this plugin knows about and,
 * for each, two independent booleans: `detected` (is the plugin here) and
 * `covered` (does this plugin contribute abilities for it yet). An agent can
 * therefore tell "WooCommerce is not installed" from "Rank Math is installed
 * but has no abilities yet" without guessing.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Integrations_Abilities
 */
class WP_MCP_Integrations_Abilities {

	/**
	 * Register the extensibility abilities and every available integration.
	 */
	public static function register() {
		self::register_core();

		WP_MCP_Integrations::register_available_abilities();
	}

	/**
	 * Register the two abilities that describe the integration layer.
	 */
	private static function register_core() {
		wp_register_ability( 'wp-mcp/list-integrations', array(
			'label'       => __( 'List Integrations', 'wordpress-mcp-abilities' ),
			'description' => __( 'List every third-party plugin integration this plugin knows about, whether each one is detected on this site, and whether it contributes abilities yet. Detection never reveals plugin settings or credentials.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-extensibility',
			'input_schema'  => self::object_schema( array(
				'group'    => array( 'type' => 'string', 'description' => 'Restrict to one functional group.', 'enum' => WP_MCP_Integrations::GROUPS ),
				'detected' => array( 'type' => 'boolean', 'description' => 'Restrict to integrations that are, or are not, detected on this site.' ),
				'covered'  => array( 'type' => 'boolean', 'description' => 'Restrict to integrations that do, or do not, contribute abilities.' ),
			), array() ),
			'output_schema' => self::object_schema( array(
				'integrations'   => array( 'type' => 'array', 'items' => self::integration_summary_schema() ),
				'total'          => array( 'type' => 'integer' ),
				'detected_count' => array( 'type' => 'integer' ),
				'covered_count'  => array( 'type' => 'integer' ),
				'groups'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			), array() ),
			'execute_callback'    => array( __CLASS__, 'list_integrations' ),
			'permission_callback' => function () { return current_user_can( 'activate_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-integration', array(
			'label'       => __( 'Get Integration', 'wordpress-mcp-abilities' ),
			'description' => __( 'Describe one integration: how it is detected, which abilities it contributes, which capabilities those need, and which of the plugin data it deliberately never exposes.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-extensibility',
			'input_schema'  => self::object_schema( array(
				'slug' => array( 'type' => 'string', 'description' => 'Integration slug, as returned by wp-mcp/list-integrations.', 'minLength' => 1, 'maxLength' => 100 ),
			), array( 'slug' ) ),
			'output_schema' => self::integration_detail_schema(),
			'execute_callback'    => array( __CLASS__, 'get_integration' ),
			'permission_callback' => function () { return current_user_can( 'activate_plugins' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ==================================================================
	 * Callbacks
	 * ================================================================ */

	/**
	 * List every known integration.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_integrations( $input = array() ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to inspect installed plugins.', 'wordpress-mcp-abilities' ) );
		}

		$input = (array) $input;
		$all   = WP_MCP_Integrations::summary();

		$detected_count = 0;
		$covered_count  = 0;
		foreach ( $all as $row ) {
			$detected_count += $row['detected'] ? 1 : 0;
			$covered_count  += $row['covered'] ? 1 : 0;
		}

		$integrations = array();
		foreach ( $all as $row ) {
			if ( ! empty( $input['group'] ) && $row['group'] !== $input['group'] ) {
				continue;
			}
			if ( array_key_exists( 'detected', $input ) && null !== $input['detected'] && (bool) $input['detected'] !== $row['detected'] ) {
				continue;
			}
			if ( array_key_exists( 'covered', $input ) && null !== $input['covered'] && (bool) $input['covered'] !== $row['covered'] ) {
				continue;
			}
			$integrations[] = $row;
		}

		return array(
			'integrations'   => $integrations,
			'total'          => count( $all ),
			'detected_count' => $detected_count,
			'covered_count'  => $covered_count,
			'groups'         => WP_MCP_Integrations::GROUPS,
		);
	}

	/**
	 * Describe one integration.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_integration( $input = array() ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to inspect installed plugins.', 'wordpress-mcp-abilities' ) );
		}

		$slug = isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';

		if ( '' === $slug ) {
			return WP_MCP_Errors::validation_error( __( 'slug is required.', 'wordpress-mcp-abilities' ) );
		}

		$described = WP_MCP_Integrations::describe( $slug );

		if ( null === $described ) {
			return WP_MCP_Errors::integration_unknown();
		}

		return $described;
	}

	/* ==================================================================
	 * Schemas
	 * ================================================================ */

	/**
	 * @return array<string,mixed>
	 */
	private static function integration_summary_schema() {
		return self::object_schema( array(
			'slug'          => array( 'type' => 'string' ),
			'label'         => array( 'type' => 'string' ),
			'group'         => array( 'type' => 'string' ),
			'plugin'        => array( 'type' => 'string' ),
			'detected'      => array( 'type' => 'boolean' ),
			'version'       => array( 'type' => 'string' ),
			'detected_by'   => array( 'type' => 'string' ),
			'covered'       => array( 'type' => 'boolean' ),
			'ability_count' => array( 'type' => 'integer' ),
		), array() );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function integration_detail_schema() {
		$summary = self::integration_summary_schema();

		unset( $summary['properties']['ability_count'] );

		return self::object_schema( $summary['properties'] + array(
			'plugin_file'           => array( 'type' => 'string' ),
			'abilities'             => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'required_capabilities' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'excluded_data'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'detection_signals'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'notes'                 => array( 'type' => 'string' ),
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
