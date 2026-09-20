<?php
/**
 * WordPress MCP Abilities — Contact Form 7 integration adapter.
 *
 * Lists and describes forms through `WPCF7_ContactForm::find()` and
 * `WPCF7_ContactForm::get_instance()`, and reads a form's field structure
 * through `scan_form_tags()` — Contact Form 7's own public API. The form
 * template, the mail templates and the messages are never returned.
 *
 * What this adapter deliberately does not expose:
 *
 *  - Mail templates and their recipient, sender, CC/BCC and reply-to
 *    addresses. Those are e-mail addresses of real people and the routing of
 *    every submission; they are not form structure.
 *  - Submissions. Contact Form 7 stores none by itself, and the add-ons that
 *    do (Flamingo) are a separate integration with a separate decision.
 *  - reCAPTCHA, Turnstile, Akismet and constant-contact keys held in the
 *    plugin's integration settings.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Contact_Form_7_Integration
 */
class WP_MCP_Contact_Form_7_Integration extends WP_MCP_Integration {

	/**
	 * @return string
	 */
	public function slug() {
		return 'contact-form-7';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Contact Form 7', 'wordpress-mcp-abilities' );
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
		return 'Contact Form 7';
	}

	/**
	 * @return array<string,string[]>
	 */
	public function signals() {
		return array(
			'constants'    => array( 'WPCF7_VERSION' ),
			'classes'      => array( 'WPCF7_ContactForm' ),
			'plugin_files' => array( 'contact-form-7/wp-contact-form-7.php' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function required_capabilities() {
		return array( 'wpcf7_read_contact_forms' );
	}

	/**
	 * @return string[]
	 */
	public function excluded_data() {
		return array(
			'Mail templates and their recipient, sender, CC/BCC and reply-to addresses.',
			'Submissions and submission add-on data.',
			'reCAPTCHA, Turnstile, Akismet and other integration keys.',
		);
	}

	/**
	 * @return string
	 */
	public function notes() {
		return 'Read-only. Form identity, shortcode and field structure only.';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function ability_matrix() {
		return array(
			'wp-mcp/contact-form-7-list-forms' => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'wpcf7_read_contact_forms (or manage_options)',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/contact-form-7-get-form'   => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'wpcf7_read_contact_forms (or manage_options)',
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
	 * Register every Contact Form 7 ability.
	 */
	public function register_abilities() {
		$can = function () { return self::can_any( array( 'wpcf7_read_contact_forms' ) ); };

		wp_register_ability( 'wp-mcp/contact-form-7-list-forms', array(
			'label'               => __( 'Contact Form 7: List Forms', 'wordpress-mcp-abilities' ),
			'description'         => __( 'List Contact Form 7 forms with bounded pagination: id, title, shortcode and locale. No mail templates and no recipient addresses.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( WP_MCP_Ability_Schema::pagination_input_properties() ),
			'output_schema'       => self::object_schema( array(
				'forms' => array( 'type' => 'array', 'items' => self::form_summary_schema() ),
			) + WP_MCP_Ability_Schema::pagination_output_properties() ),
			'execute_callback'    => array( $this, 'list_forms' ),
			'permission_callback' => $can,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/contact-form-7-get-form', array(
			'label'               => __( 'Contact Form 7: Get Form', 'wordpress-mcp-abilities' ),
			'description'         => __( 'Read one Contact Form 7 form: id, title, shortcode, locale and the structure of its fields (name, type, required).', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array( 'form_id' => array( 'type' => 'integer', 'description' => 'Contact Form 7 form ID.', 'minimum' => 1 ) ), array( 'form_id' ) ),
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

		$found = WPCF7_ContactForm::find( array(
			'posts_per_page' => $per_page,
			'offset'         => ( $page - 1 ) * $per_page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$forms = array();
		foreach ( (array) $found as $form ) {
			if ( $form instanceof WPCF7_ContactForm ) {
				$forms[] = $this->form_summary( $form );
			}
		}

		$counts = wp_count_posts( 'wpcf7_contact_form' );
		$total  = isset( $counts->publish ) ? (int) $counts->publish : count( $forms );

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

		$form = WPCF7_ContactForm::get_instance( (int) $form_id );
		if ( ! $form instanceof WPCF7_ContactForm ) {
			return WP_MCP_Errors::integration_object_not_found( __( 'The specified Contact Form 7 form does not exist.', 'wordpress-mcp-abilities' ) );
		}

		$detail           = $this->form_summary( $form );
		$detail['fields'] = $this->form_fields( $form );

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

		if ( ! self::can_any( array( 'wpcf7_read_contact_forms' ) ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read contact forms.', 'wordpress-mcp-abilities' ) );
		}

		return null;
	}

	/**
	 * @param WPCF7_ContactForm $form Form.
	 * @return array<string,mixed>
	 */
	private function form_summary( WPCF7_ContactForm $form ) {
		return array(
			'id'        => (int) $form->id(),
			'title'     => (string) $form->title(),
			'shortcode' => (string) $form->shortcode(),
			'locale'    => (string) $form->locale(),
		);
	}

	/**
	 * Field structure of a form.
	 *
	 * @param WPCF7_ContactForm $form Form.
	 * @return array<int,array<string,mixed>>
	 */
	private function form_fields( WPCF7_ContactForm $form ) {
		$fields = array();

		foreach ( (array) $form->scan_form_tags() as $tag ) {
			if ( ! $tag instanceof WPCF7_FormTag || '' === (string) $tag->name ) {
				continue;
			}
			$fields[] = array(
				'name'     => (string) $tag->name,
				'type'     => '' !== (string) $tag->basetype ? (string) $tag->basetype : (string) $tag->type,
				'required' => (bool) $tag->is_required(),
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
			'id'        => array( 'type' => 'integer' ),
			'title'     => array( 'type' => 'string' ),
			'shortcode' => array( 'type' => 'string' ),
			'locale'    => array( 'type' => 'string' ),
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
			'name'     => array( 'type' => 'string' ),
			'type'     => array( 'type' => 'string' ),
			'required' => array( 'type' => 'boolean' ),
		) );
	}
}
