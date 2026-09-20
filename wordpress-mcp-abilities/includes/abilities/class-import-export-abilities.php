<?php
/**
 * WordPress MCP Abilities — Import/export domain registration.
 *
 * Four abilities: WXR content export and import, and a round-trip of the
 * issue #10 settings allowlist.
 *
 * No schema in this file has a path, a filename or a URL field: the export is
 * returned as a string and the import is supplied as one. The settings payload
 * schemas are generated from `WP_MCP_Settings`, so an import can only carry
 * fields the settings abilities already declare writable.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Import_Export_Abilities
 */
class WP_MCP_Import_Export_Abilities {

	/** Register every import/export ability. */
	public static function register() {
		self::register_content();
		self::register_settings();
	}

	/* ------------------------------------------------------------------
	 * Content
	 * ---------------------------------------------------------------- */

	/** Register the WXR export and import abilities. */
	private static function register_content() {
		wp_register_ability( 'wp-mcp/export-content', array(
			'label'       => __( 'Export Content', 'wordpress-mcp-abilities' ),
			'description' => __( 'Generate a WXR export with WordPress own exporter and return it as a string, filtered by content type, author, category, status and date range. Nothing is written to disk and no download URL is created.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(
				'content'    => array(
					'type'        => 'string',
					'description' => '"all", or a registered post type that is exportable. Defaults to "all".',
					'maxLength'   => 191,
				),
				'author'     => array(
					'type'        => 'integer',
					'description' => 'Restrict the export to one existing author ID.',
					'minimum'     => 1,
				),
				'category'   => array(
					'type'        => 'string',
					'description' => 'Restrict the export to one existing category slug.',
					'maxLength'   => 200,
				),
				'status'     => array(
					'type'        => 'string',
					'description' => 'Restrict the export to one registered post status.',
					'maxLength'   => 20,
				),
				'start_date' => array(
					'type'        => 'string',
					'description' => 'Earliest post date to include, as YYYY-MM-DD.',
					'maxLength'   => 10,
				),
				'end_date'   => array(
					'type'        => 'string',
					'description' => 'Latest post date to include, as YYYY-MM-DD.',
					'maxLength'   => 10,
				),
			), array() ),
			'output_schema' => self::object_schema( array(
				'wxr'           => array( 'type' => 'string' ),
				'bytes'         => array( 'type' => 'integer' ),
				'content'       => array( 'type' => 'string' ),
				'status'        => array( 'type' => 'string' ),
				'author'        => array( 'type' => 'integer' ),
				'category'      => array( 'type' => 'string' ),
				'start_date'    => array( 'type' => 'string' ),
				'end_date'      => array( 'type' => 'string' ),
				'generated_utc' => array( 'type' => 'string' ),
				'max_bytes'     => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Import_Export', 'export_content' ),
			'permission_callback' => function () { return current_user_can( 'export' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/import-content', array(
			'label'       => __( 'Import Content', 'wordpress-mcp-abilities' ),
			'description' => __( 'Import a WXR document supplied as a string. Attachment fetching is forced off, so the importer never downloads a URL found inside the document. WordPress core ships no WXR importer: this ability requires the official WordPress Importer plugin and refuses with wp_mcp_import_unsupported when it is not active.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(
				'wxr' => array(
					'type'        => 'string',
					'description' => 'The complete WXR (WordPress eXtended RSS) document.',
					'maxLength'   => WP_MCP_Import_Export::MAX_IMPORT_BYTES,
				),
			), array( 'wxr' ) ),
			'output_schema' => self::object_schema( array(
				'imported'            => array( 'type' => 'boolean' ),
				'imported_posts'      => array( 'type' => 'integer' ),
				'imported_terms'      => array( 'type' => 'integer' ),
				'imported_authors'    => array( 'type' => 'integer' ),
				'fetched_attachments' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Import_Export', 'import_content' ),
			'permission_callback' => function () { return current_user_can( 'import' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	/** Register the settings round-trip abilities. */
	private static function register_settings() {
		$payload = self::settings_payload_schema();

		wp_register_ability( 'wp-mcp/export-settings', array(
			'label'       => __( 'Export Settings', 'wordpress-mcp-abilities' ),
			'description' => __( 'Export every allowlisted, writable settings field, grouped by settings screen, in the exact shape import-settings accepts. Groups the caller lacks the capability for are reported as skipped rather than silently dropped.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-settings',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'settings'       => $payload,
				'groups'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'skipped_groups' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'generated_utc'  => array( 'type' => 'string' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Import_Export', 'export_settings' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/import-settings', array(
			'label'       => __( 'Import Settings', 'wordpress-mcp-abilities' ),
			'description' => __( 'Write back a settings payload produced by export-settings. Every group goes through the same validated writer the settings abilities use, so an unknown field or a read-only field is refused instead of written — an import payload is not a way around the allowlist, and it never carries a WordPress option name.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-settings',
			'input_schema' => self::object_schema( array(
				'settings' => $payload,
			), array( 'settings' ) ),
			'output_schema' => self::object_schema( array(
				'applied'       => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'group'   => array( 'type' => 'string' ),
						'updated' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					), array() ),
				),
				'updated_total' => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Import_Export', 'import_settings' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/**
	 * The settings payload shape: one closed object per settings group, whose
	 * fields are exactly that group's writable allowlist.
	 *
	 * @return array<string,mixed>
	 */
	private static function settings_payload_schema() {
		$groups = array();
		foreach ( WP_MCP_Settings::group_slugs() as $group ) {
			$groups[ $group ] = self::object_schema( WP_MCP_Settings::input_schema_properties( $group ), array() );
		}
		return self::object_schema( $groups, array() );
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
