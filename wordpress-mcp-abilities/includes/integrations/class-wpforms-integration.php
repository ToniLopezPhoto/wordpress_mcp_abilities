<?php
/**
 * WordPress MCP Abilities — WPForms integration adapter.
 *
 * WPForms stores each form as one post of its own registered `wpforms` post
 * type, whose `post_content` is the form's JSON definition. This adapter
 * reads exactly that, through core's `WP_Query` and `json_decode()`, and
 * exposes a fixed subset of it. That is not a generic post-type or meta
 * reader dressed up as an integration: the post type is WPForms' own, the
 * shape of the JSON is WPForms' own published form schema, and the fields
 * this adapter returns are enumerated below and nowhere else. WPForms Lite
 * ships no PHP form-reading API that is stable across Lite and Pro, so this
 * is the honest route rather than reaching into `wpforms()` internals.
 *
 * What this adapter deliberately does not expose:
 *
 *  - Entries (WPForms Pro). Submissions are personal data.
 *  - The `settings` block of a form: notification and confirmation
 *    configuration, recipient e-mail addresses, and integration
 *    (Mailchimp, Stripe, ...) connection data.
 *  - Field choices and default values, which routinely carry pre-filled
 *    personal data on customer-facing forms.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_WPForms_Integration
 */
class WP_MCP_WPForms_Integration extends WP_MCP_Integration {

	/**
	 * WPForms' own post type.
	 */
	const POST_TYPE = 'wpforms';

	/**
	 * @return string
	 */
	public function slug() {
		return 'wpforms';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'WPForms', 'wordpress-mcp-abilities' );
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
		return 'WPForms (Lite or Pro)';
	}

	/**
	 * @return array<string,string[]>
	 */
	public function signals() {
		return array(
			'constants'    => array( 'WPFORMS_VERSION' ),
			'functions'    => array( 'wpforms' ),
			'plugin_files' => array( 'wpforms-lite/wpforms.php', 'wpforms/wpforms.php' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function required_capabilities() {
		return array( 'wpforms_view_forms' );
	}

	/**
	 * @return string[]
	 */
	public function excluded_data() {
		return array(
			'Entries and everything in them (WPForms Pro).',
			'Notification and confirmation settings, including recipient addresses.',
			'Marketing and payment integration connections and their credentials.',
			'Field choices and default values.',
		);
	}

	/**
	 * @return string
	 */
	public function notes() {
		return 'Read-only. Form identity and field structure, read from the wpforms post type WPForms itself registers.';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function ability_matrix() {
		return array(
			'wp-mcp/wpforms-list-forms' => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'wpforms_view_forms (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/wpforms-get-form'   => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'wpforms_view_forms (or manage_options)',
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
	 * Register every WPForms ability.
	 */
	public function register_abilities() {
		$can = function () { return self::can_any( array( 'wpforms_view_forms' ) ); };

		wp_register_ability( 'wp-mcp/wpforms-list-forms', array(
			'label'               => __( 'WPForms: List Forms', 'wordpress-mcp-abilities' ),
			'description'         => __( 'List WPForms forms with bounded pagination: id, title, status, creation date and field count. No entries and no notification settings.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array(
				'search' => array( 'type' => 'string', 'description' => 'Match against the form title.' ),
			) + WP_MCP_Ability_Schema::pagination_input_properties() ),
			'output_schema'       => self::object_schema( array(
				'forms' => array( 'type' => 'array', 'items' => self::form_summary_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties() ),
			'execute_callback'    => array( $this, 'list_forms' ),
			'permission_callback' => $can,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/wpforms-get-form', array(
			'label'               => __( 'WPForms: Get Form', 'wordpress-mcp-abilities' ),
			'description'         => __( 'Read one WPForms form: identity, status and the structure of its fields (id, label, type, required). Never entries, notification settings or integration credentials.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array( 'form_id' => array( 'type' => 'integer', 'description' => 'WPForms form ID.', 'minimum' => 1 ) ), array( 'form_id' ) ),
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

		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		$query = new WP_Query( $args );

		$forms = array();
		foreach ( $query->posts as $form_post ) {
			if ( $form_post instanceof WP_Post ) {
				$forms[] = $this->form_summary( $form_post );
			}
		}

		return array(
			'forms'       => $forms,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
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

		$post = get_post( (int) $form_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return WP_MCP_Errors::integration_object_not_found( __( 'The specified WPForms form does not exist.', 'wordpress-mcp-abilities' ) );
		}

		$detail           = $this->form_summary( $post );
		$detail['fields'] = $this->form_fields( $post );

		return $detail;
	}

	/* ==================================================================
	 * Internals
	 * ================================================================ */

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

		if ( ! self::can_any( array( 'wpforms_view_forms' ) ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read WPForms forms.', 'wordpress-mcp-abilities' ) );
		}

		return null;
	}

	/**
	 * Decode a form's JSON definition.
	 *
	 * @param WP_Post $post Form post.
	 * @return array<string,mixed>
	 */
	private function definition( WP_Post $post ) {
		$decoded = json_decode( $post->post_content, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param WP_Post $post Form post.
	 * @return array<string,mixed>
	 */
	private function form_summary( WP_Post $post ) {
		$definition = $this->definition( $post );
		$fields     = isset( $definition['fields'] ) && is_array( $definition['fields'] ) ? $definition['fields'] : array();

		return array(
			'id'           => (int) $post->ID,
			'title'        => (string) $post->post_title,
			'status'       => (string) $post->post_status,
			'date_created' => self::format_date( $post->post_date_gmt ),
			'field_count'  => count( $fields ),
		);
	}

	/**
	 * Field structure of a form.
	 *
	 * @param WP_Post $post Form post.
	 * @return array<int,array<string,mixed>>
	 */
	private function form_fields( WP_Post $post ) {
		$definition = $this->definition( $post );
		$fields     = array();

		if ( ! isset( $definition['fields'] ) || ! is_array( $definition['fields'] ) ) {
			return $fields;
		}

		foreach ( $definition['fields'] as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$fields[] = array(
				'id'       => isset( $field['id'] ) ? (string) $field['id'] : '',
				'label'    => isset( $field['label'] ) ? (string) $field['label'] : '',
				'type'     => isset( $field['type'] ) ? (string) $field['type'] : '',
				'required' => ! empty( $field['required'] ),
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
			'status'       => array( 'type' => 'string' ),
			'date_created' => array( 'type' => 'string' ),
			'field_count'  => array( 'type' => 'integer' ),
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
