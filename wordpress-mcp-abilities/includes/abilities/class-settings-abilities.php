<?php
/**
 * WordPress MCP Abilities — Settings domain registration.
 *
 * One read and one write ability per wp-admin settings screen. Every input
 * and output schema is generated from the allowlist in WP_MCP_Settings so
 * the declared surface and the enforced surface cannot drift apart, and
 * every schema is closed (`additionalProperties: false`) so an unknown
 * field is rejected by schema validation as well as by the runtime
 * allowlist gate.
 *
 * There is deliberately no `get-option` / `update-option` ability: clients
 * address named fields, never WordPress option names (see epic #1's hard
 * prohibitions and issue #10).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.10.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Settings_Abilities
 */
class WP_MCP_Settings_Abilities {

	/**
	 * Read/write ability pairs: group slug => array( label, read slug, write slug ).
	 *
	 * @return array<string,array{label:string,read:string,write:string}>
	 */
	private static function pairs() {
		return array(
			'general'    => array( 'label' => 'General', 'read' => 'get-general-settings', 'write' => 'update-general-settings' ),
			'writing'    => array( 'label' => 'Writing', 'read' => 'get-writing-settings', 'write' => 'update-writing-settings' ),
			'reading'    => array( 'label' => 'Reading', 'read' => 'get-reading-settings', 'write' => 'update-reading-settings' ),
			'discussion' => array( 'label' => 'Discussion', 'read' => 'get-discussion-settings', 'write' => 'update-discussion-settings' ),
			'media'      => array( 'label' => 'Media', 'read' => 'get-media-settings', 'write' => 'update-media-settings' ),
			'permalinks' => array( 'label' => 'Permalink', 'read' => 'get-permalink-settings', 'write' => 'update-permalink-settings' ),
			'privacy'    => array( 'label' => 'Privacy', 'read' => 'get-privacy-settings', 'write' => 'update-privacy-settings' ),
		);
	}

	/**
	 * Execute callbacks per group, kept explicit rather than derived from a
	 * string so no ability dispatches on caller-controlled data.
	 *
	 * @return array<string,array{read:string,write:string}>
	 */
	private static function callbacks() {
		return array(
			'general'    => array( 'read' => 'get_general_settings', 'write' => 'update_general_settings' ),
			'writing'    => array( 'read' => 'get_writing_settings', 'write' => 'update_writing_settings' ),
			'reading'    => array( 'read' => 'get_reading_settings', 'write' => 'update_reading_settings' ),
			'discussion' => array( 'read' => 'get_discussion_settings', 'write' => 'update_discussion_settings' ),
			'media'      => array( 'read' => 'get_media_settings', 'write' => 'update_media_settings' ),
			'permalinks' => array( 'read' => 'get_permalink_settings', 'write' => 'update_permalink_settings' ),
			'privacy'    => array( 'read' => 'get_privacy_settings', 'write' => 'update_privacy_settings' ),
		);
	}

	/** Register every settings ability. */
	public static function register() {
		$groups    = WP_MCP_Settings::groups();
		$pairs     = self::pairs();
		$callbacks = self::callbacks();

		foreach ( $pairs as $group => $names ) {
			$capability = $groups[ $group ]['capability'];
			$output     = self::object_schema( WP_MCP_Settings::output_schema_properties( $group ), array() );

			wp_register_ability( 'wp-mcp/' . $names['read'], array(
				'label'       => sprintf(
					/* translators: %s: settings screen name, e.g. "General" */
					__( 'Get %s Settings', 'wordpress-mcp-abilities' ),
					$names['label']
				),
				'description' => sprintf(
					/* translators: %s: settings screen name, e.g. "General" */
					__( 'Read every allowlisted field of the %s settings screen. Accepts no option name: the field set is fixed by the plugin.', 'wordpress-mcp-abilities' ),
					$names['label']
				),
				'category'            => 'wp-mcp-settings',
				'input_schema'        => self::object_schema( array(), array() ),
				'output_schema'       => $output,
				'execute_callback'    => array( 'WP_MCP_Settings', $callbacks[ $group ]['read'] ),
				'permission_callback' => function () use ( $capability ) { return current_user_can( $capability ); },
				'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
			) );

			wp_register_ability( 'wp-mcp/' . $names['write'], array(
				'label'       => sprintf(
					/* translators: %s: settings screen name, e.g. "General" */
					__( 'Update %s Settings', 'wordpress-mcp-abilities' ),
					$names['label']
				),
				'description' => sprintf(
					/* translators: %s: settings screen name, e.g. "General" */
					__( 'Update one or more allowlisted fields of the %s settings screen. Any field outside the allowlist, and any read-only field, is rejected without writing anything.', 'wordpress-mcp-abilities' ),
					$names['label']
				),
				'category'            => 'wp-mcp-settings',
				'input_schema'        => self::object_schema( WP_MCP_Settings::input_schema_properties( $group ), array() ),
				'output_schema'       => self::object_schema( array(
					'updated'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'settings' => $output,
				), array() ),
				'execute_callback'    => array( 'WP_MCP_Settings', $callbacks[ $group ]['write'] ),
				'permission_callback' => function () use ( $capability ) { return current_user_can( $capability ); },
				'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
			) );
		}

		self::register_rewrite_flush();
		self::register_field_discovery();
	}

	/** Register the explicit rewrite-rule flush. */
	private static function register_rewrite_flush() {
		wp_register_ability( 'wp-mcp/flush-rewrite-rules', array(
			'label'       => __( 'Flush Rewrite Rules', 'wordpress-mcp-abilities' ),
			'description' => __( 'Regenerate the site\'s rewrite rules. Kept separate from the permalink settings write so that changing the structure and paying for a full flush stay two explicit decisions.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-settings',
			'input_schema' => self::object_schema( array(
				'hard' => array( 'type' => 'boolean', 'description' => 'Whether to also refresh the server rewrite configuration (.htaccess / web.config) when WordPress is able to. Defaults to true.' ),
			), array() ),
			'output_schema' => self::object_schema( array(
				'flushed'             => array( 'type' => 'boolean' ),
				'hard'                => array( 'type' => 'boolean' ),
				'permalink_structure' => array( 'type' => 'string' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Settings', 'flush_rewrite_rules' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/** Register the allowlist-discovery ability. */
	private static function register_field_discovery() {
		wp_register_ability( 'wp-mcp/list-settings-fields', array(
			'label'       => __( 'List Settings Fields', 'wordpress-mcp-abilities' ),
			'description' => __( 'Describe the settings allowlist itself — which fields exist per group, their types, accepted values and whether they are writable — without reading any stored value.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-settings',
			'input_schema' => self::object_schema( array(
				'group' => array(
					'type'        => 'string',
					'description' => 'Optional settings group to restrict the listing to.',
					'enum'        => WP_MCP_Settings::group_slugs(),
				),
			), array() ),
			'output_schema' => self::object_schema( array(
				'fields' => array( 'type' => 'array', 'items' => self::field_descriptor_schema() ),
				'total'  => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Settings', 'list_settings_fields' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function field_descriptor_schema() {
		return self::object_schema( array(
			'group'          => array( 'type' => 'string' ),
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
