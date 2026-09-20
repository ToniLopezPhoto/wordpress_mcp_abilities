<?php
/**
 * WordPress MCP Abilities — Taxonomies domain.
 *
 * Registers discovery, term CRUD, registered term metadata, and object-term
 * relationship abilities. Taxonomy names are runtime-validated against the
 * WordPress registry; no taxonomy is inferred from an ID.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Taxonomies_Abilities
 */
class WP_MCP_Taxonomies_Abilities {

	/** Register every taxonomy-domain ability. */
	public static function register() {
		self::register_list_categories();
		self::register_list_tags();
		self::register_list_taxonomies();
		self::register_get_taxonomy();
		self::register_list_terms();
		self::register_get_term();
		self::register_create_term();
		self::register_update_term();
		self::register_delete_term();
		self::register_assign_terms();
		self::register_remove_terms();
		self::register_bulk_assign_terms();
		self::register_bulk_remove_terms();
		self::register_get_term_meta();
		self::register_update_term_meta();
		self::register_delete_term_meta();
	}

	/* ------------------------------------------------------------------
	 * Existing core convenience abilities
	 * ---------------------------------------------------------------- */

	private static function register_list_categories() {
		self::register_legacy_term_list( 'wp-mcp/list-categories', __( 'List Categories', 'wordpress-mcp-abilities' ), __( 'List all post categories. Returns id, name, slug, and count.', 'wordpress-mcp-abilities' ), array( 'WP_MCP_Taxonomies', 'list_categories' ) );
	}

	private static function register_list_tags() {
		self::register_legacy_term_list( 'wp-mcp/list-tags', __( 'List Tags', 'wordpress-mcp-abilities' ), __( 'List all post tags. Returns id, name, slug, and count.', 'wordpress-mcp-abilities' ), array( 'WP_MCP_Taxonomies', 'list_tags' ) );
	}

	private static function register_legacy_term_list( $name, $label, $description, $callback ) {
		wp_register_ability( $name, array(
			'label'       => $label,
			'description' => $description,
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => array( 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false ),
			'output_schema' => array( 'type' => 'object', 'properties' => array( 'terms' => array( 'type' => 'array', 'items' => self::term_summary_schema() ) ) ),
			'execute_callback'    => $callback,
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Discovery and reads
	 * ---------------------------------------------------------------- */

	private static function register_list_taxonomies() {
		wp_register_ability( 'wp-mcp/list-taxonomies', array(
			'label'       => __( 'List Taxonomies', 'wordpress-mcp-abilities' ),
			'description' => __( 'Discover registered WordPress taxonomies and their object types, hierarchy, visibility, and capabilities.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( array( 'object_type' => array( 'type' => 'string', 'description' => 'Optional post type filter.' ) ), array() ),
			'output_schema' => array( 'type' => 'object', 'properties' => array( 'taxonomies' => array( 'type' => 'array', 'items' => self::taxonomy_schema() ) ) ),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'list_taxonomies' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_get_taxonomy() {
		wp_register_ability( 'wp-mcp/get-taxonomy', array(
			'label'       => __( 'Get Taxonomy', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one registered taxonomy and its native WordPress capabilities.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( array( 'taxonomy' => self::taxonomy_property() ), array( 'taxonomy' ) ),
			'output_schema' => self::taxonomy_schema(),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'get_taxonomy' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_list_terms() {
		$properties = array_merge(
			array( 'taxonomy' => self::taxonomy_property(), 'search' => array( 'type' => 'string' ), 'parent' => array( 'type' => 'integer', 'minimum' => 0 ), 'hide_empty' => array( 'type' => 'boolean', 'default' => false ) ),
			WP_MCP_Ability_Schema::pagination_input_properties()
		);
		wp_register_ability( 'wp-mcp/list-terms', array(
			'label'       => __( 'List Terms', 'wordpress-mcp-abilities' ),
			'description' => __( 'List terms for any registered taxonomy with pagination, search, hierarchy, and counts.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( $properties, array( 'taxonomy' ) ),
			'output_schema' => array( 'type' => 'object', 'properties' => array_merge( array( 'taxonomy' => array( 'type' => 'string' ), 'terms' => array( 'type' => 'array', 'items' => self::term_summary_schema() ) ), WP_MCP_Ability_Schema::pagination_output_properties() ) ),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'list_terms' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_get_term() {
		wp_register_ability( 'wp-mcp/get-term', array(
			'label'       => __( 'Get Term', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get a term only when its ID belongs to the explicitly requested taxonomy, including registered REST-visible metadata.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( array( 'taxonomy' => self::taxonomy_property(), 'term_id' => self::term_id_property() ), array( 'taxonomy', 'term_id' ) ),
			'output_schema' => self::term_detail_schema(),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'get_term' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Term CRUD
	 * ---------------------------------------------------------------- */

	private static function register_create_term() {
		wp_register_ability( 'wp-mcp/create-term', array(
			'label'       => __( 'Create Term', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a term in a registered taxonomy. Creation requires that taxonomy\'s manage_terms capability.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( self::term_write_properties(), array( 'taxonomy', 'name' ) ),
			'output_schema' => self::term_detail_schema(),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'create_term' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );
	}

	private static function register_update_term() {
		$properties = array_merge( array( 'term_id' => self::term_id_property() ), self::term_write_properties() );
		wp_register_ability( 'wp-mcp/update-term', array(
			'label'       => __( 'Update Term', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update explicit name, slug, description, or parent fields on a registered term.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( $properties, array( 'taxonomy', 'term_id' ) ),
			'output_schema' => self::term_detail_schema(),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'update_term' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_delete_term() {
		wp_register_ability( 'wp-mcp/delete-term', array(
			'label'       => __( 'Delete Term', 'wordpress-mcp-abilities' ),
			'description' => __( 'Delete a term using WordPress taxonomy rules, including child reassignment and default-term protection.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( array( 'taxonomy' => self::taxonomy_property(), 'term_id' => self::term_id_property() ), array( 'taxonomy', 'term_id' ) ),
			'output_schema' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'taxonomy' => array( 'type' => 'string' ), 'deleted' => array( 'type' => 'boolean' ) ) ),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'delete_term' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Relationships and bulk
	 * ------------------------------------------------------------------ */

	private static function register_assign_terms() {
		wp_register_ability( 'wp-mcp/assign-terms', array(
			'label'       => __( 'Assign Terms', 'wordpress-mcp-abilities' ),
			'description' => __( 'Add validated terms to an editable post, page, or registered custom post type.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( self::relationship_properties(), array( 'taxonomy', 'object_type', 'object_id', 'term_ids' ) ),
			'output_schema' => self::relationship_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'assign_terms' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_remove_terms() {
		wp_register_ability( 'wp-mcp/remove-terms', array(
			'label'       => __( 'Remove Terms', 'wordpress-mcp-abilities' ),
			'description' => __( 'Remove validated terms from an editable post, page, or registered custom post type.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( self::relationship_properties(), array( 'taxonomy', 'object_type', 'object_id', 'term_ids' ) ),
			'output_schema' => self::relationship_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'remove_terms' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_bulk_assign_terms() {
		wp_register_ability( 'wp-mcp/bulk-assign-terms', array(
			'label'       => __( 'Bulk Assign Terms', 'wordpress-mcp-abilities' ),
			'description' => __( 'Assign the same validated terms to up to 20 objects with per-object results.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( array( 'taxonomy' => self::taxonomy_property(), 'object_type' => self::object_type_property(), 'object_ids' => self::object_ids_property(), 'term_ids' => self::term_ids_property() ), array( 'taxonomy', 'object_type', 'object_ids', 'term_ids' ) ),
			'output_schema' => self::bulk_output_schema( 'assigned' ),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'bulk_assign_terms' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_bulk_remove_terms() {
		wp_register_ability( 'wp-mcp/bulk-remove-terms', array(
			'label'       => __( 'Bulk Remove Terms', 'wordpress-mcp-abilities' ),
			'description' => __( 'Remove the same validated terms from up to 20 objects with per-object results.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::object_schema( array( 'taxonomy' => self::taxonomy_property(), 'object_type' => self::object_type_property(), 'object_ids' => self::object_ids_property(), 'term_ids' => self::term_ids_property() ), array( 'taxonomy', 'object_type', 'object_ids', 'term_ids' ) ),
			'output_schema' => self::bulk_output_schema( 'removed' ),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'bulk_remove_terms' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Registered metadata
	 * ------------------------------------------------------------------ */

	private static function register_get_term_meta() {
		wp_register_ability( 'wp-mcp/get-term-meta', array(
			'label'       => __( 'Get Term Metadata', 'wordpress-mcp-abilities' ),
			'description' => __( 'Read one registered and REST-visible term metadata key; arbitrary keys are rejected.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::meta_input_schema( false ),
			'output_schema' => self::meta_output_schema( false ),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'get_term_meta' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_update_term_meta() {
		wp_register_ability( 'wp-mcp/update-term-meta', array(
			'label'       => __( 'Update Term Metadata', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update one registered term metadata key after validating its registered schema and auth callback.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::meta_input_schema( true ),
			'output_schema' => self::meta_output_schema( false ),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'update_term_meta' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_delete_term_meta() {
		wp_register_ability( 'wp-mcp/delete-term-meta', array(
			'label'       => __( 'Delete Term Metadata', 'wordpress-mcp-abilities' ),
			'description' => __( 'Delete one registered term metadata key; arbitrary keys are rejected.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-taxonomies',
			'input_schema' => self::meta_input_schema( false ),
			'output_schema' => self::meta_output_schema( true ),
			'execute_callback'    => array( 'WP_MCP_Taxonomies', 'delete_term_meta' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Schema helpers
	 * ---------------------------------------------------------------- */

	private static function object_schema( $properties, $required ) {
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false );
	}

	private static function taxonomy_property() { return array( 'type' => 'string', 'description' => 'Registered taxonomy name.', 'minLength' => 1 ); }
	private static function term_id_property() { return array( 'type' => 'integer', 'description' => 'Term ID.', 'minimum' => 1 ); }
	private static function object_type_property() { return array( 'type' => 'string', 'description' => 'Registered post type associated with the taxonomy.', 'minLength' => 1 ); }
	private static function object_ids_property() { return array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'minItems' => 1, 'maxItems' => 20 ); }
	private static function term_ids_property() { return array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'minItems' => 1, 'maxItems' => 100 ); }

	private static function term_write_properties() {
		return array(
			'taxonomy'    => self::taxonomy_property(),
			'name'        => array( 'type' => 'string', 'description' => 'Term name.' ),
			'slug'        => array( 'type' => 'string', 'description' => 'Optional term slug.' ),
			'description' => array( 'type' => 'string', 'description' => 'Optional term description.' ),
			'parent'      => array( 'type' => 'integer', 'description' => 'Optional parent term ID; only hierarchical taxonomies accept it.', 'minimum' => 0 ),
		);
	}

	private static function relationship_properties() {
		return array( 'taxonomy' => self::taxonomy_property(), 'object_type' => self::object_type_property(), 'object_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'term_ids' => self::term_ids_property() );
	}

	private static function meta_input_schema( $with_value ) {
		$properties = array( 'taxonomy' => self::taxonomy_property(), 'term_id' => self::term_id_property(), 'meta_key' => array( 'type' => 'string', 'minLength' => 1 ) );
		if ( $with_value ) { $properties['value'] = array( 'description' => 'Value validated against the registered metadata schema.' ); }
		return self::object_schema( $properties, $with_value ? array( 'taxonomy', 'term_id', 'meta_key', 'value' ) : array( 'taxonomy', 'term_id', 'meta_key' ) );
	}

	private static function taxonomy_schema() {
		return array( 'type' => 'object', 'properties' => array( 'name' => array( 'type' => 'string' ), 'label' => array( 'type' => 'string' ), 'singular_label' => array( 'type' => 'string' ), 'object_types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'hierarchical' => array( 'type' => 'boolean' ), 'public' => array( 'type' => 'boolean' ), 'show_ui' => array( 'type' => 'boolean' ), 'show_in_rest' => array( 'type' => 'boolean' ), 'capabilities' => array( 'type' => 'object' ) ) );
	}

	private static function term_summary_schema() {
		return array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'taxonomy' => array( 'type' => 'string' ), 'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'parent' => array( 'type' => 'integer' ), 'count' => array( 'type' => 'integer' ) ) );
	}

	private static function term_detail_schema() {
		$schema = self::term_summary_schema();
		$schema['properties']['meta'] = array( 'type' => 'object' );
		return $schema;
	}

	private static function relationship_output_schema() {
		return array( 'type' => 'object', 'properties' => array( 'taxonomy' => array( 'type' => 'string' ), 'object_id' => array( 'type' => 'integer' ), 'object_type' => array( 'type' => 'string' ), 'term_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ) ) );
	}

	private static function bulk_output_schema( $count_key ) {
		return array( 'type' => 'object', 'properties' => array( 'results' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'object_id' => array( 'type' => 'integer' ), 'success' => array( 'type' => 'boolean' ), 'error_code' => array( 'type' => 'string' ) ) ) ), $count_key => array( 'type' => 'integer' ), 'failed' => array( 'type' => 'integer' ) ) );
	}

	private static function meta_output_schema( $deleted ) {
		$properties = array( 'taxonomy' => array( 'type' => 'string' ), 'term_id' => array( 'type' => 'integer' ), 'meta_key' => array( 'type' => 'string' ) );
		if ( $deleted ) { $properties['deleted'] = array( 'type' => 'boolean' ); } else { $properties['value'] = array(); }
		return array( 'type' => 'object', 'properties' => $properties );
	}
}
