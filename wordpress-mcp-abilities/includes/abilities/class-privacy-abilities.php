<?php
/**
 * WordPress MCP Abilities — Privacy domain registration.
 *
 * Eight abilities over WordPress own personal data export and erasure
 * requests. No schema here carries `confirm_key`, and no ability accepts one:
 * confirmation stays with the data subject and the emailed link.
 *
 * The privacy *policy page* setting is not part of this domain — it is an
 * explicit field of the issue #10 settings allowlist
 * (`get-privacy-settings` / `update-privacy-settings`).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Privacy_Abilities
 */
class WP_MCP_Privacy_Abilities {

	/** Register every privacy ability. */
	public static function register() {
		self::register_reads();
		self::register_creation();
		self::register_processing();
	}

	/* ------------------------------------------------------------------
	 * Reads
	 * ---------------------------------------------------------------- */

	/** Register the request discovery abilities. */
	private static function register_reads() {
		wp_register_ability( 'wp-mcp/list-privacy-requests', array(
			'label'       => __( 'List Privacy Requests', 'wordpress-mcp-abilities' ),
			'description' => __( 'List personal data export and erasure requests. Only the request types the caller holds the native capability for are listed.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-privacy',
			'input_schema' => self::object_schema( array_merge(
				array(
					'type'   => array(
						'type'        => 'string',
						'description' => 'Restrict the listing to export or erasure requests.',
						'enum'        => array( 'export', 'erase' ),
					),
					'status' => array(
						'type'        => 'string',
						'description' => 'Restrict the listing to one request status.',
						'enum'        => array( 'pending', 'confirmed', 'failed', 'completed' ),
					),
				),
				WP_MCP_Ability_Schema::pagination_input_properties()
			), array() ),
			'output_schema' => self::object_schema( array_merge(
				array(
					'requests' => array(
						'type'  => 'array',
						'items' => self::request_schema(),
					),
				),
				WP_MCP_Ability_Schema::pagination_output_properties()
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Privacy', 'list_privacy_requests' ),
			'permission_callback' => function () { return current_user_can( 'export_others_personal_data' ) || current_user_can( 'erase_others_personal_data' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-privacy-request', array(
			'label'       => __( 'Get Privacy Request', 'wordpress-mcp-abilities' ),
			'description' => __( 'Read one personal data request. The confirmation key behind the emailed link is never returned.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-privacy',
			'input_schema' => self::object_schema( array(
				'request_id' => self::request_id_property(),
			), array( 'request_id' ) ),
			'output_schema' => self::request_schema(),
			'execute_callback'    => array( 'WP_MCP_Privacy', 'get_privacy_request' ),
			'permission_callback' => function () { return current_user_can( 'export_others_personal_data' ) || current_user_can( 'erase_others_personal_data' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Creation
	 * ---------------------------------------------------------------- */

	/** Register the request creation abilities. */
	private static function register_creation() {
		$create_input = self::object_schema( array(
			'email'                   => array(
				'type'        => 'string',
				'description' => 'Email address of the data subject.',
				'format'      => 'email',
				'maxLength'   => 254,
			),
			'send_confirmation_email' => array(
				'type'        => 'boolean',
				'description' => 'Send the confirmation email immediately. Defaults to true.',
			),
		), array( 'email' ) );

		$create_output = self::object_schema( array(
			'request'            => self::request_schema(),
			'confirmation_sent'  => array( 'type' => 'boolean' ),
			'confirmation_error' => array( 'type' => 'string' ),
		), array() );

		wp_register_ability( 'wp-mcp/create-privacy-export-request', array(
			'label'       => __( 'Create Privacy Export Request', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a personal data export request. The request is always created pending: only the data subject can confirm it, through the emailed link, exactly as in wp-admin.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-privacy',
			'input_schema'        => $create_input,
			'output_schema'       => $create_output,
			'execute_callback'    => array( 'WP_MCP_Privacy', 'create_privacy_export_request' ),
			'permission_callback' => function () { return current_user_can( 'export_others_personal_data' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/create-privacy-erasure-request', array(
			'label'       => __( 'Create Privacy Erasure Request', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a personal data erasure request. The request is always created pending: only the data subject can confirm it, through the emailed link, exactly as in wp-admin.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-privacy',
			'input_schema'        => $create_input,
			'output_schema'       => $create_output,
			'execute_callback'    => array( 'WP_MCP_Privacy', 'create_privacy_erasure_request' ),
			'permission_callback' => function () { return current_user_can( 'erase_others_personal_data' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/resend-privacy-request-email', array(
			'label'       => __( 'Resend Privacy Request Email', 'wordpress-mcp-abilities' ),
			'description' => __( 'Re-send the confirmation email for a request the data subject has not confirmed yet. Only pending or failed requests can be re-sent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-privacy',
			'input_schema' => self::object_schema( array(
				'request_id' => self::request_id_property(),
			), array( 'request_id' ) ),
			'output_schema' => self::object_schema( array(
				'request' => self::request_schema(),
				'sent'    => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Privacy', 'resend_privacy_request_email' ),
			'permission_callback' => function () { return current_user_can( 'export_others_personal_data' ) || current_user_can( 'erase_others_personal_data' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Processing
	 * ---------------------------------------------------------------- */

	/** Register the request processing and removal abilities. */
	private static function register_processing() {
		wp_register_ability( 'wp-mcp/process-privacy-export-request', array(
			'label'       => __( 'Process Privacy Export Request', 'wordpress-mcp-abilities' ),
			'description' => __( 'Run every registered exporter for a confirmed export request and let WordPress assemble the export file. A request the data subject has not confirmed is refused with wp_mcp_privacy_request_invalid_state. The exporters that run come from WordPress own registry; the caller never names one.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-privacy',
			'input_schema' => self::object_schema( array(
				'request_id'    => self::request_id_property(),
				'send_as_email' => array(
					'type'        => 'boolean',
					'description' => 'Email the export link to the data subject and mark the request completed, instead of only returning the URL. Defaults to false.',
				),
			), array( 'request_id' ) ),
			'output_schema' => self::object_schema( array(
				'request'          => self::request_schema(),
				'exporters_run'    => array( 'type' => 'integer' ),
				'pages_processed'  => array( 'type' => 'integer' ),
				'items_exported'   => array( 'type' => 'integer' ),
				'failed_exporters' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'sent_as_email'    => array( 'type' => 'boolean' ),
				'export_file_url'  => array( 'type' => 'string' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Privacy', 'process_privacy_export_request' ),
			'permission_callback' => function () { return current_user_can( 'export_others_personal_data' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/process-privacy-erasure-request', array(
			'label'       => __( 'Process Privacy Erasure Request', 'wordpress-mcp-abilities' ),
			'description' => __( 'Run every registered eraser for a confirmed erasure request and report what was removed and what was retained. A request the data subject has not confirmed is refused with wp_mcp_privacy_request_invalid_state.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-privacy',
			'input_schema' => self::object_schema( array(
				'request_id' => self::request_id_property(),
			), array( 'request_id' ) ),
			'output_schema' => self::object_schema( array(
				'request'         => self::request_schema(),
				'erasers_run'     => array( 'type' => 'integer' ),
				'pages_processed' => array( 'type' => 'integer' ),
				'items_removed'   => array( 'type' => 'boolean' ),
				'items_retained'  => array( 'type' => 'boolean' ),
				'messages'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'failed_erasers'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Privacy', 'process_privacy_erasure_request' ),
			'permission_callback' => function () { return current_user_can( 'erase_others_personal_data' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/delete-privacy-request', array(
			'label'       => __( 'Delete Privacy Request', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete a personal data request record, as the Remove request action does in wp-admin.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-privacy',
			'input_schema' => self::object_schema( array(
				'request_id' => self::request_id_property(),
			), array( 'request_id' ) ),
			'output_schema' => self::object_schema( array(
				'id'      => array( 'type' => 'integer' ),
				'type'    => array( 'type' => 'string' ),
				'deleted' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Privacy', 'delete_privacy_request' ),
			'permission_callback' => function () { return current_user_can( 'export_others_personal_data' ) || current_user_can( 'erase_others_personal_data' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Shared schema fragments
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<string,mixed>
	 */
	private static function request_id_property() {
		return array(
			'type'        => 'integer',
			'description' => 'ID of the personal data request.',
			'minimum'     => 1,
		);
	}

	/**
	 * The shape of one personal data request in a response. Deliberately has no
	 * `confirm_key` member.
	 *
	 * @return array<string,mixed>
	 */
	private static function request_schema() {
		return self::object_schema( array(
			'id'              => array( 'type' => 'integer' ),
			'type'            => array( 'type' => 'string', 'enum' => array( 'export', 'erase' ) ),
			'action_name'     => array( 'type' => 'string' ),
			'email'           => array( 'type' => 'string' ),
			'user_id'         => array( 'type' => 'integer' ),
			'status'          => array( 'type' => 'string', 'enum' => array( 'pending', 'confirmed', 'failed', 'completed' ) ),
			'created_utc'     => array( 'type' => 'string' ),
			'modified_utc'    => array( 'type' => 'string' ),
			'confirmed_utc'   => array( 'type' => 'string' ),
			'completed_utc'   => array( 'type' => 'string' ),
			'export_file_url' => array( 'type' => 'string' ),
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
