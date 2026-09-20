<?php
/**
 * WordPress MCP Abilities — Personal data request callbacks.
 *
 * Covers WordPress own privacy workflows: the export and erasure requests
 * behind Tools > Export/Erase Personal Data.
 *
 * Design constraints (epic #1, issue #12):
 *
 *  - **Consent is never bypassed.** A request is always created in the
 *    `request-pending` status, exactly as wp-admin creates it, and is only
 *    processed once the data subject has confirmed it through the emailed
 *    link. There is no ability that marks a request confirmed, and
 *    `process-*` refuses a pending request with
 *    `wp_mcp_privacy_request_invalid_state`.
 *  - **The confirmation key is never returned.** `WP_User_Request::$confirm_key`
 *    is the secret behind the confirmation link; no response in this file
 *    contains it, and no ability accepts one.
 *  - **No arbitrary callbacks.** The exporters and erasers that run are
 *    whatever WordPress own `wp_privacy_personal_data_exporters` /
 *    `wp_privacy_personal_data_erasers` registries contain. A caller never
 *    names, adds or selects one — it supplies a request ID and nothing else.
 *  - The per-page accumulation, grouping, file generation and completion are
 *    core's: this file drives `wp_privacy_process_personal_data_export_page()`
 *    and `wp_privacy_process_personal_data_erasure_page()`, the same functions
 *    core's own Ajax handlers drive, rather than reimplementing them.
 *
 * The privacy *policy page* setting is not here: it is an explicit settings
 * field of the issue #10 allowlist (`get-privacy-settings` /
 * `update-privacy-settings`).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Privacy
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Privacy {

	/**
	 * Post type WordPress stores personal data requests in.
	 */
	const REQUEST_POST_TYPE = 'user_request';

	/**
	 * Maximum number of pages a single exporter or eraser may be asked for.
	 */
	const MAX_PAGES = 100;

	/**
	 * Request statuses WordPress uses, without the `request-` prefix.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'pending', 'confirmed', 'failed', 'completed' );

	/* ==================================================================
	 * Request type vocabulary
	 * ================================================================ */

	/**
	 * The two request types, each with its WordPress action name and the
	 * native capability that governs it.
	 *
	 * @return array<string,array{action:string,capability:string,label:string}>
	 */
	public static function request_types() {
		return array(
			'export' => array(
				'action'     => 'export_personal_data',
				'capability' => 'export_others_personal_data',
				'label'      => 'Export personal data',
			),
			'erase'  => array(
				'action'     => 'remove_personal_data',
				'capability' => 'erase_others_personal_data',
				'label'      => 'Erase personal data',
			),
		);
	}

	/* ==================================================================
	 * Read abilities
	 * ================================================================ */

	/**
	 * List personal data requests.
	 *
	 * Only the request types the caller holds the capability for are listed;
	 * an administrator without `erase_others_personal_data` simply does not see
	 * erasure requests.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_privacy_requests( $input = array() ) {
		$types     = self::request_types();
		$permitted = array();
		foreach ( $types as $type => $definition ) {
			if ( current_user_can( $definition['capability'] ) ) {
				$permitted[] = $type;
			}
		}
		if ( empty( $permitted ) ) {
			return WP_MCP_Errors::privacy_permission_denied();
		}

		$selected = $permitted;
		if ( ! empty( $input['type'] ) ) {
			$type = (string) $input['type'];
			if ( ! isset( $types[ $type ] ) ) {
				return WP_MCP_Errors::privacy_validation_error( __( 'The "type" filter must be "export" or "erase".', 'wordpress-mcp-abilities' ) );
			}
			if ( ! in_array( $type, $permitted, true ) ) {
				return WP_MCP_Errors::privacy_permission_denied();
			}
			$selected = array( $type );
		}

		$statuses = array();
		if ( ! empty( $input['status'] ) ) {
			$status = (string) $input['status'];
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return WP_MCP_Errors::privacy_validation_error( __( 'The "status" filter must be pending, confirmed, failed or completed.', 'wordpress-mcp-abilities' ) );
			}
			$statuses[] = 'request-' . $status;
		} else {
			foreach ( self::STATUSES as $status ) {
				$statuses[] = 'request-' . $status;
			}
		}

		$names = array();
		foreach ( $selected as $type ) {
			$names[] = $types[ $type ]['action'];
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 20, 50 );

		$query = new WP_Query( array(
			'post_type'              => self::REQUEST_POST_TYPE,
			'post_status'            => $statuses,
			'post_name__in'          => $names,
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		) );

		$requests = array();
		foreach ( $query->posts as $found ) {
			$request = wp_get_user_request( is_object( $found ) ? (int) $found->ID : (int) $found );
			if ( $request ) {
				$requests[] = self::format_request( $request );
			}
		}

		return array(
			'requests'    => $requests,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Read one personal data request.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_privacy_request( $input = array() ) {
		$request = self::resolve_request( $input );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		return self::format_request( $request );
	}

	/* ==================================================================
	 * Write abilities — creation
	 * ================================================================ */

	/**
	 * Create a personal data *export* request.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_privacy_export_request( $input = array() ) {
		return self::create_request( 'export', $input, 'wp-mcp/create-privacy-export-request' );
	}

	/**
	 * Create a personal data *erasure* request.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_privacy_erasure_request( $input = array() ) {
		return self::create_request( 'erase', $input, 'wp-mcp/create-privacy-erasure-request' );
	}

	/**
	 * Re-send the confirmation email for a request the data subject has not
	 * confirmed yet.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function resend_privacy_request_email( $input = array() ) {
		$request = self::resolve_request( $input );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		if ( ! in_array( $request->status, array( 'request-pending', 'request-failed' ), true ) ) {
			return WP_MCP_Errors::privacy_request_invalid_state( __( 'Only a pending or failed request can have its confirmation email re-sent.', 'wordpress-mcp-abilities' ) );
		}

		$result = function_exists( '_wp_privacy_resend_request' )
			? _wp_privacy_resend_request( $request->ID )
			: wp_send_user_request( $request->ID );

		// Both routes report every refusal as a WP_Error, so there is no
		// separate boolean-false failure path to handle here.
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/resend-privacy-request-email', $request->ID, false, 'wp_mcp_privacy_request_email_failed', array() );
			return WP_MCP_Errors::privacy_request_email_failed( $result->get_error_message() );
		}

		WP_MCP_Audit::log( 'wp-mcp/resend-privacy-request-email', $request->ID, true, '', array() );

		$refreshed = wp_get_user_request( $request->ID );
		return array(
			'request' => self::format_request( $refreshed ? $refreshed : $request ),
			'sent'    => true,
		);
	}

	/* ==================================================================
	 * Write abilities — processing
	 * ================================================================ */

	/**
	 * Run every registered exporter for a confirmed export request and let
	 * WordPress assemble the export file.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function process_privacy_export_request( $input = array() ) {
		$request = self::resolve_request( $input, 'export' );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$state = self::require_processable( $request );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( ! function_exists( 'wp_privacy_process_personal_data_export_page' ) ) {
			return WP_MCP_Errors::privacy_process_failed( __( 'This WordPress installation does not expose the personal data export pipeline.', 'wordpress-mcp-abilities' ) );
		}

		$send_as_email = ! empty( $input['send_as_email'] );
		$exporters     = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		if ( ! is_array( $exporters ) ) {
			$exporters = array();
		}

		$index  = 0;
		$pages  = 0;
		$items  = 0;
		$errors = array();

		foreach ( $exporters as $exporter_key => $exporter ) {
			++$index;
			$page = 1;

			do {
				$response = self::call_privacy_provider( $exporter, $request->email, $page );
				if ( is_wp_error( $response ) ) {
					$errors[]           = (string) $exporter_key;
					$response           = array(
						'data' => array(),
						'done' => true,
					);
				} elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
					$items += count( $response['data'] );
				}

				/** This filter is documented in wp-admin/includes/ajax-actions.php */
				$response = apply_filters( 'wp_privacy_personal_data_export_page', $response, $index, $request->email, $page, $request->ID, $send_as_email, (string) $exporter_key );

				++$pages;
				++$page;
			} while ( empty( $response['done'] ) && $page <= self::MAX_PAGES );
		}

		$refreshed = wp_get_user_request( $request->ID );
		$final     = $refreshed ? $refreshed : $request;

		WP_MCP_Audit::log( 'wp-mcp/process-privacy-export-request', $request->ID, true, '', array(
			'exporters' => $index,
			'items'     => $items,
		) );

		return array(
			'request'         => self::format_request( $final ),
			'exporters_run'   => $index,
			'pages_processed' => $pages,
			'items_exported'  => $items,
			'failed_exporters' => $errors,
			'sent_as_email'   => $send_as_email,
			'export_file_url' => (string) get_post_meta( $request->ID, '_export_file_url', true ),
		);
	}

	/**
	 * Run every registered eraser for a confirmed erasure request.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function process_privacy_erasure_request( $input = array() ) {
		$request = self::resolve_request( $input, 'erase' );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$state = self::require_processable( $request );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( ! function_exists( 'wp_privacy_process_personal_data_erasure_page' ) ) {
			return WP_MCP_Errors::privacy_process_failed( __( 'This WordPress installation does not expose the personal data erasure pipeline.', 'wordpress-mcp-abilities' ) );
		}

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		if ( ! is_array( $erasers ) ) {
			$erasers = array();
		}

		$index    = 0;
		$pages    = 0;
		$removed  = false;
		$retained = false;
		$messages = array();
		$errors   = array();

		foreach ( $erasers as $eraser_key => $eraser ) {
			++$index;
			$page = 1;

			do {
				$response = self::call_privacy_provider( $eraser, $request->email, $page );
				if ( is_wp_error( $response ) ) {
					$errors[] = (string) $eraser_key;
					$response = array(
						'items_removed'  => false,
						'items_retained' => false,
						'messages'       => array(),
						'done'           => true,
					);
				} else {
					$removed  = $removed || ! empty( $response['items_removed'] );
					$retained = $retained || ! empty( $response['items_retained'] );
					if ( ! empty( $response['messages'] ) && is_array( $response['messages'] ) ) {
						foreach ( $response['messages'] as $message ) {
							$messages[] = (string) $message;
						}
					}
				}

				/** This filter is documented in wp-admin/includes/ajax-actions.php */
				$response = apply_filters( 'wp_privacy_personal_data_erasure_page', $response, $index, $request->email, $page, $request->ID );

				++$pages;
				++$page;
			} while ( empty( $response['done'] ) && $page <= self::MAX_PAGES );
		}

		$refreshed = wp_get_user_request( $request->ID );
		$final     = $refreshed ? $refreshed : $request;

		WP_MCP_Audit::log( 'wp-mcp/process-privacy-erasure-request', $request->ID, true, '', array(
			'erasers' => $index,
			'removed' => $removed,
		) );

		return array(
			'request'         => self::format_request( $final ),
			'erasers_run'     => $index,
			'pages_processed' => $pages,
			'items_removed'   => $removed,
			'items_retained'  => $retained,
			'messages'        => array_slice( $messages, 0, 50 ),
			'failed_erasers'  => $errors,
		);
	}

	/**
	 * Permanently delete a personal data request record.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_privacy_request( $input = array() ) {
		$request = self::resolve_request( $input );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$id   = (int) $request->ID;
		$type = self::type_for_action( $request->action_name );

		$deleted = wp_delete_post( $id, true );
		if ( ! $deleted ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-privacy-request', $id, false, 'wp_mcp_privacy_delete_failed', array() );
			return WP_MCP_Errors::privacy_delete_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/delete-privacy-request', $id, true, '', array( 'type' => $type ) );

		return array(
			'id'      => $id,
			'type'    => $type,
			'deleted' => true,
		);
	}

	/* ==================================================================
	 * Internal helpers
	 * ================================================================ */

	/**
	 * Create a request of the given type, always in the pending status.
	 *
	 * @param string $type    'export' or 'erase'.
	 * @param array  $input   Ability input.
	 * @param string $ability Ability name for the audit log.
	 * @return array|WP_Error
	 */
	private static function create_request( $type, $input, $ability ) {
		$types = self::request_types();
		if ( ! isset( $types[ $type ] ) ) {
			return WP_MCP_Errors::privacy_validation_error();
		}
		if ( ! current_user_can( $types[ $type ]['capability'] ) ) {
			return WP_MCP_Errors::privacy_permission_denied();
		}

		$email = isset( $input['email'] ) ? $input['email'] : '';
		if ( ! is_string( $email ) || '' === trim( $email ) ) {
			return WP_MCP_Errors::privacy_validation_error( __( 'The "email" field is required.', 'wordpress-mcp-abilities' ) );
		}
		$email = sanitize_email( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return WP_MCP_Errors::privacy_validation_error( __( 'The "email" field must be a valid email address.', 'wordpress-mcp-abilities' ) );
		}

		// Always pending: confirmation belongs to the data subject, never to
		// the agent creating the request.
		$request_id = wp_create_user_request( $email, $types[ $type ]['action'], array(), 'pending' );
		if ( is_wp_error( $request_id ) ) {
			WP_MCP_Audit::log( $ability, 0, false, 'wp_mcp_privacy_request_create_failed', array( 'type' => $type ) );
			return WP_MCP_Errors::privacy_request_create_failed( $request_id->get_error_message() );
		}

		$sent       = false;
		$send_error = '';
		if ( ! isset( $input['send_confirmation_email'] ) || ! empty( $input['send_confirmation_email'] ) ) {
			$result = wp_send_user_request( $request_id );
			if ( is_wp_error( $result ) ) {
				$send_error = $result->get_error_message();
			} else {
				$sent = (bool) $result;
			}
		}

		WP_MCP_Audit::log( $ability, (int) $request_id, true, '', array(
			'type' => $type,
			'sent' => $sent,
		) );

		$request = wp_get_user_request( $request_id );
		if ( ! $request ) {
			return WP_MCP_Errors::privacy_request_create_failed();
		}

		return array(
			'request'            => self::format_request( $request ),
			'confirmation_sent'  => $sent,
			'confirmation_error' => $send_error,
		);
	}

	/**
	 * Resolve, capability-check and type-check the request a caller addresses.
	 *
	 * @param array       $input         Ability input.
	 * @param string|null $expected_type 'export', 'erase', or null for either.
	 * @return WP_User_Request|WP_Error
	 */
	private static function resolve_request( $input, $expected_type = null ) {
		$request_id = WP_MCP_Permissions::validate_positive_int( isset( $input['request_id'] ) ? $input['request_id'] : 0, 'request_id' );
		if ( is_wp_error( $request_id ) ) {
			return WP_MCP_Errors::privacy_validation_error( __( 'The "request_id" field is required and must be a positive integer.', 'wordpress-mcp-abilities' ) );
		}

		$request = wp_get_user_request( $request_id );
		if ( ! $request ) {
			return WP_MCP_Errors::privacy_request_not_found();
		}

		$type = self::type_for_action( $request->action_name );
		if ( '' === $type ) {
			return WP_MCP_Errors::privacy_request_not_found();
		}
		if ( null !== $expected_type && $type !== $expected_type ) {
			return WP_MCP_Errors::privacy_request_invalid_state(
				'export' === $expected_type
					? __( 'This request is an erasure request; use the erasure ability instead.', 'wordpress-mcp-abilities' )
					: __( 'This request is an export request; use the export ability instead.', 'wordpress-mcp-abilities' )
			);
		}

		$types = self::request_types();
		if ( ! current_user_can( $types[ $type ]['capability'] ) ) {
			return WP_MCP_Errors::privacy_permission_denied();
		}

		return $request;
	}

	/**
	 * Refuse to process a request the data subject has not confirmed.
	 *
	 * @param WP_User_Request $request Request object.
	 * @return true|WP_Error
	 */
	private static function require_processable( $request ) {
		if ( in_array( $request->status, array( 'request-confirmed', 'request-completed' ), true ) ) {
			return true;
		}
		if ( 'request-pending' === $request->status ) {
			return WP_MCP_Errors::privacy_request_invalid_state( __( 'This request has not been confirmed by the data subject yet. WordPress requires the emailed confirmation link to be followed before the request can be processed.', 'wordpress-mcp-abilities' ) );
		}
		return WP_MCP_Errors::privacy_request_invalid_state();
	}

	/**
	 * Call one exporter or eraser from WordPress own registry.
	 *
	 * The callback is never a caller-supplied name: it comes from the
	 * `wp_privacy_personal_data_exporters` / `wp_privacy_personal_data_erasers`
	 * registries, exactly as core's own Ajax handlers use them. A malformed
	 * registry entry is reported rather than invoked.
	 *
	 * @param mixed  $provider Registry entry.
	 * @param string $email    Data subject email address.
	 * @param int    $page     1-based page number.
	 * @return array|WP_Error
	 */
	private static function call_privacy_provider( $provider, $email, $page ) {
		if ( ! is_array( $provider ) || empty( $provider['callback'] ) || ! is_callable( $provider['callback'] ) ) {
			return WP_MCP_Errors::privacy_process_failed( __( 'A registered personal data provider is malformed and was skipped.', 'wordpress-mcp-abilities' ) );
		}

		$response = call_user_func( $provider['callback'], $email, $page );
		if ( ! is_array( $response ) || ! array_key_exists( 'done', $response ) ) {
			return WP_MCP_Errors::privacy_process_failed( __( 'A registered personal data provider returned a malformed response and was skipped.', 'wordpress-mcp-abilities' ) );
		}

		return $response;
	}

	/**
	 * Map a WordPress request action name onto this plugin's type vocabulary.
	 *
	 * @param string $action_name Action name.
	 * @return string 'export', 'erase', or '' when unrecognised.
	 */
	private static function type_for_action( $action_name ) {
		foreach ( self::request_types() as $type => $definition ) {
			if ( $definition['action'] === $action_name ) {
				return $type;
			}
		}
		return '';
	}

	/**
	 * Shape a request for an ability response.
	 *
	 * Deliberately omits `confirm_key`: it is the secret behind the
	 * confirmation link and must never leave the site.
	 *
	 * @param WP_User_Request $request Request object.
	 * @return array<string,mixed>
	 */
	private static function format_request( $request ) {
		$status = (string) $request->status;
		if ( 0 === strpos( $status, 'request-' ) ) {
			$status = substr( $status, strlen( 'request-' ) );
		}

		$type = self::type_for_action( $request->action_name );

		return array(
			'id'              => (int) $request->ID,
			'type'            => $type,
			'action_name'     => (string) $request->action_name,
			'email'           => (string) $request->email,
			'user_id'         => (int) $request->user_id,
			'status'          => $status,
			'created_utc'     => $request->created_timestamp ? gmdate( 'c', (int) $request->created_timestamp ) : '',
			'modified_utc'    => $request->modified_timestamp ? gmdate( 'c', (int) $request->modified_timestamp ) : '',
			'confirmed_utc'   => $request->confirmed_timestamp ? gmdate( 'c', (int) $request->confirmed_timestamp ) : '',
			'completed_utc'   => $request->completed_timestamp ? gmdate( 'c', (int) $request->completed_timestamp ) : '',
			'export_file_url' => 'export' === $type ? (string) get_post_meta( $request->ID, '_export_file_url', true ) : '',
		);
	}
}
