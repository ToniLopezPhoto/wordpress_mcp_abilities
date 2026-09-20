<?php
/**
 * WordPress MCP Abilities — Gravity Forms integration adapter.
 *
 * Uses `GFAPI` — the officially documented Gravity Forms API — for every
 * read: `GFAPI::get_forms()`, `GFAPI::get_form()` and `GFAPI::count_entries()`.
 * Entry *counts* are aggregates and are returned; entries themselves are not.
 *
 * What this adapter deliberately does not expose:
 *
 *  - Entries. A Gravity Forms entry is a submission by a real person and
 *    routinely holds names, e-mail addresses, phone numbers, uploads and the
 *    submitter's IP.
 *  - Notifications and confirmations, which carry recipient addresses and
 *    routing.
 *  - Feeds and add-on settings (payment gateways, CRMs, mail providers) and
 *    the API keys they hold.
 *
 * Gravity Forms returns every form in one call and offers no server-side
 * paging, so the bounded pagination of this domain is applied in PHP after
 * the call. Form counts are small by nature; entry volume, which is not,
 * never leaves the plugin.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Gravity_Forms_Integration
 */
class WP_MCP_Gravity_Forms_Integration extends WP_MCP_Integration {

	/**
	 * @return string
	 */
	public function slug() {
		return 'gravity-forms';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Gravity Forms', 'wordpress-mcp-abilities' );
	}

	/**
	 * @return string
	 */
	public function group() {
		return 'forms';
	}

	/**
	 * @return string
	 */
	public function plugin_label() {
		return 'Gravity Forms';
	}

	/**
	 * @return array<string,string[]>
	 */
	public function signals() {
		return array(
			'classes'      => array( 'GFAPI' ),
			'plugin_files' => array( 'gravityforms/gravityforms.php' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function required_capabilities() {
		return array( 'gravityforms_edit_forms', 'gform_full_access' );
	}

	/**
	 * @return string[]
	 */
	public function excluded_data() {
		return array(
			'Entries and everything in them, including uploads and submitter IPs.',
			'Notifications and confirmations, and their recipient addresses.',
			'Feeds and add-on settings, including payment gateway and CRM credentials.',
		);
	}

	/**
	 * @return string
	 */
	public function notes() {
		return 'Read-only. Form structure and aggregate entry counts; never entry content.';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function ability_matrix() {
		return array(
			'wp-mcp/gravity-forms-list-forms' => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'gravityforms_edit_forms (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/gravity-forms-get-form'   => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'gravityforms_edit_forms (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
		);
	}

	/* ==================================================================
	 * Registration
	 * ================================================================ */

	/**
	 * Register every Gravity Forms ability.
	 */
	public function register_abilities() {
		$can = function () { return $this->current_user_can_forms(); };

		wp_register_ability( 'wp-mcp/gravity-forms-list-forms', array(
			'label'               => __( 'Gravity Forms: List Forms', 'wordpress-mcp-abilities' ),
			'description'         => __( 'List Gravity Forms forms with bounded pagination: id, title, active state, creation date and entry count. Never entry content.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array(
				'active' => array( 'type' => 'boolean', 'description' => 'Restrict to active, or to inactive, forms. Omitted: both.' ),
			) + WP_MCP_Ability_Schema::pagination_input_properties() ),
			'output_schema'       => self::object_schema( array(
				'forms' => array( 'type' => 'array', 'items' => self::form_summary_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties() ),
			'execute_callback'    => array( $this, 'list_forms' ),
			'permission_callback' => $can,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/gravity-forms-get-form', array(
			'label'               => __( 'Gravity Forms: Get Form', 'wordpress-mcp-abilities' ),
			'description'         => __( 'Read one Gravity Forms form: identity, active state, entry count and the structure of its fields (id, label, type, required). Never notifications, confirmations or feeds.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array( 'form_id' => array( 'type' => 'integer', 'description' => 'Gravity Forms form ID.', 'minimum' => 1 ) ), array( 'form_id' ) ),
			'output_schema'       => self::form_detail_schema(),
			'execute_callback'    => array( $this, 'get_form' ),
			'permission_callback' => $can,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ==================================================================
	 * Callbacks
	 * ================================================================ */

	/**
	 * List forms.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function list_forms( $input = array() ) {
		$denied = $this->guard();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( (array) $input );

		$input = (array) $input;

		if ( array_key_exists( 'active', $input ) && null !== $input['active'] ) {
			$all = GFAPI::get_forms( (bool) $input['active'], false );
		} else {
			$all = array_merge( GFAPI::get_forms( true, false ), GFAPI::get_forms( false, false ) );
		}

		$total = count( $all );
		$slice = array_slice( array_values( $all ), ( $page - 1 ) * $per_page, $per_page );

		$forms = array();
		foreach ( $slice as $form ) {
			$forms[] = $this->form_summary( (array) $form );
		}

		return array(
			'forms'       => $forms,
			'total'       => $total,
			'total_pages' => (int) ceil( max( 1, $total ) / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Read one form.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function get_form( $input = array() ) {
		$denied = $this->guard();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$form_id = isset( $input['form_id'] ) ? $input['form_id'] : 0;
		if ( ! WP_MCP_Permissions::is_strict_positive_int_id( $form_id ) ) {
			return WP_MCP_Errors::validation_error( __( 'form_id must be a positive integer.', 'wordpress-mcp-abilities' ) );
		}

		$form = GFAPI::get_form( (int) $form_id );
		if ( ! is_array( $form ) ) {
			return WP_MCP_Errors::integration_object_not_found( __( 'The specified Gravity Forms form does not exist.', 'wordpress-mcp-abilities' ) );
		}

		$detail           = $this->form_summary( $form );
		$detail['fields'] = $this->form_fields( $form );

		return $detail;
	}

	/* ==================================================================
	 * Internals
	 * ================================================================ */

	/**
	 * Whether the current user may read Gravity Forms forms.
	 *
	 * Gravity Forms owns its own capability mapping, including the
	 * `gform_full_access` super-capability, so its own checker decides when it
	 * is loaded.
	 *
	 * @return bool
	 */
	private function current_user_can_forms() {
		if ( class_exists( 'GFCommon' ) ) {
			return (bool) GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) );
		}

		return self::can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) );
	}

	/**
	 * Availability plus capability guard.
	 *
	 * @return WP_Error|null
	 */
	private function guard() {
		$unavailable = $this->require_available();
		if ( is_wp_error( $unavailable ) ) {
			return $unavailable;
		}

		if ( ! $this->current_user_can_forms() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read Gravity Forms forms.', 'wordpress-mcp-abilities' ) );
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $form Raw Gravity Forms form array.
	 * @return array<string,mixed>
	 */
	private function form_summary( array $form ) {
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;

		return array(
			'id'           => $form_id,
			'title'        => isset( $form['title'] ) ? (string) $form['title'] : '',
			'is_active'    => ! empty( $form['is_active'] ),
			'is_trash'     => ! empty( $form['is_trash'] ),
			'date_created' => isset( $form['date_created'] ) ? self::format_date( $form['date_created'] ) : '',
			'entry_count'  => $form_id > 0 ? (int) GFAPI::count_entries( $form_id ) : 0,
		);
	}

	/**
	 * Field structure of a form.
	 *
	 * Gravity Forms fields are `GF_Field` objects; reading them through
	 * `get_object_vars()` keeps this adapter off that class's internals.
	 *
	 * @param array<string,mixed> $form Raw Gravity Forms form array.
	 * @return array<int,array<string,mixed>>
	 */
	private function form_fields( array $form ) {
		$fields = array();

		if ( ! isset( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $fields;
		}

		foreach ( $form['fields'] as $field ) {
			$data = is_object( $field ) ? get_object_vars( $field ) : (array) $field;

			$fields[] = array(
				'id'       => isset( $data['id'] ) ? (string) $data['id'] : '',
				'label'    => isset( $data['label'] ) ? (string) $data['label'] : '',
				'type'     => isset( $data['type'] ) ? (string) $data['type'] : '',
				'required' => ! empty( $data['isRequired'] ),
			);
		}

		return $fields;
	}

	/* ==================================================================
	 * Schemas
	 * ================================================================ */

	/**
	 * @return array<string,mixed>
	 */
	private static function form_summary_schema() {
		return self::object_schema( array(
			'id'           => array( 'type' => 'integer' ),
			'title'        => array( 'type' => 'string' ),
			'is_active'    => array( 'type' => 'boolean' ),
			'is_trash'     => array( 'type' => 'boolean' ),
			'date_created' => array( 'type' => 'string' ),
			'entry_count'  => array( 'type' => 'integer' ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function form_detail_schema() {
		$summary = self::form_summary_schema();

		return self::object_schema( $summary['properties'] + array(
			'fields' => array( 'type' => 'array', 'items' => self::field_schema() ),
		) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function field_schema() {
		return self::object_schema( array(
			'id'       => array( 'type' => 'string' ),
			'label'    => array( 'type' => 'string' ),
			'type'     => array( 'type' => 'string' ),
			'required' => array( 'type' => 'boolean' ),
		) );
	}
}
