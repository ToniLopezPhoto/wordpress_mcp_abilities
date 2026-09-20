<?php
/**
 * WordPress MCP Abilities — Custom post types domain registration.
 *
 * Twenty fixed abilities: post type and registered-metadata discovery, then
 * list/read/create/update/lifecycle/revision/featured-media/metadata
 * operations that all take the post type as an explicitly validated
 * selector, never as a free-form dispatcher.
 *
 * The ability set is static and does not grow with the site's registered
 * post types: a per-type ability would make the catalog, the permission
 * matrix, and the MCP tool list depend on installed plugins. One ability per
 * operation, with `post_type` as a validated input, keeps the surface
 * enumerable and auditable.
 *
 * Every gate above "authenticated reader" is post-type aware — the Abilities
 * API hands the raw input to the permission callback, so the first-pass gate
 * checks the *target type's own* capability (`cap->create_posts`,
 * `cap->publish_posts`, ...) instead of a literal `edit_posts`. Only the
 * abilities whose execute path needs nothing beyond `read` plus a per-object
 * `read_post` use the plain reader gate; the revision reads take the type's
 * own `cap->edit_posts`, matching what `edit_post` resolves to at execution
 * and what the post/page revision abilities already require.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.11.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Post_Types_Abilities
 */
class WP_MCP_Post_Types_Abilities {

	/** Register every custom post type ability. */
	public static function register() {
		self::register_discovery();
		self::register_reads();
		self::register_writes();
		self::register_lifecycle();
		self::register_revisions();
		self::register_featured_media();
		self::register_meta();
	}

	/* ------------------------------------------------------------------
	 * Discovery
	 * ---------------------------------------------------------------- */

	private static function register_discovery() {
		wp_register_ability( 'wp-mcp/list-post-types', array(
			'label'       => __( 'List Post Types', 'wordpress-mcp-abilities' ),
			'description' => __( 'Discover registered post types with labels, supports, taxonomies, visibility, native capabilities, and whether the custom post type abilities can operate on each one.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => self::object_schema( array(
				'operable_only' => array( 'type' => 'boolean', 'description' => 'Only return post types these abilities can operate on.', 'default' => false ),
				'supports'      => array( 'type' => 'string', 'description' => 'Only return post types declaring support for this feature.', 'minLength' => 1 ),
				'taxonomy'      => array( 'type' => 'string', 'description' => 'Only return post types the taxonomy is registered for.', 'minLength' => 1 ),
			), array() ),
			'output_schema' => self::object_schema( array(
				'post_types' => array( 'type' => 'array', 'items' => self::post_type_schema() ),
				'total'      => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'list_post_types' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_read' ),
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-post-type', array(
			'label'       => __( 'Get Post Type', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one registered post type, including a machine-readable reason when it is not addressable through these abilities.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::object_schema( array( 'post_type' => self::post_type_property() ), array( 'post_type' ) ),
			'output_schema' => self::post_type_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'get_post_type' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_read' ),
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-post-type-meta-fields', array(
			'label'       => __( 'List Post Type Metadata Fields', 'wordpress-mcp-abilities' ),
			'description' => __( 'Describe the post metadata registered for one post type — key, type, single, REST visibility, and whether it is operable — without returning any stored value.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::object_schema( array( 'post_type' => self::post_type_property() ), array( 'post_type' ) ),
			'output_schema' => self::object_schema( array(
				'post_type' => array( 'type' => 'string' ),
				'fields'    => array( 'type' => 'array', 'items' => self::meta_field_schema() ),
				'total'     => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'list_post_type_meta_fields' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_read' ),
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Reads
	 * ---------------------------------------------------------------- */

	private static function register_reads() {
		$list_properties = array_merge(
			array(
				'post_type' => self::post_type_property(),
				'status'    => array( 'type' => 'string', 'description' => 'Entry status.', 'enum' => array( 'publish', 'draft', 'pending', 'future', 'private' ), 'default' => 'publish' ),
				'search'    => array( 'type' => 'string', 'description' => 'Search keyword.' ),
				'taxonomy'  => array( 'type' => 'string', 'description' => 'Taxonomy to filter by; requires term_id.', 'minLength' => 1 ),
				'term_id'   => array( 'type' => 'integer', 'description' => 'Term to filter by; requires taxonomy.', 'minimum' => 1 ),
				'orderby'   => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'ID' ), 'default' => 'date' ),
				'order'     => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'default' => 'DESC' ),
			),
			WP_MCP_Ability_Schema::pagination_input_properties()
		);

		wp_register_ability( 'wp-mcp/list-custom-posts', array(
			'label'       => __( 'List Custom Post Type Entries', 'wordpress-mcp-abilities' ),
			'description' => __( 'List and search entries of one registered custom post type. Non-public statuses require that post type\'s own edit capability and are narrowed to the caller\'s own entries without its edit_others capability.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::object_schema( $list_properties, array( 'post_type' ) ),
			'output_schema' => self::object_schema( array_merge(
				array(
					'post_type' => array( 'type' => 'string' ),
					'posts'     => array( 'type' => 'array', 'items' => self::custom_post_summary_schema() ),
				),
				WP_MCP_Ability_Schema::pagination_output_properties()
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'list_custom_posts' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_read' ),
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-custom-post', array(
			'label'       => __( 'Get Custom Post Type Entry', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one entry only when its ID belongs to the explicitly requested post type, including supports, taxonomy terms, featured media, and operable registered metadata.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::entry_schema(),
			'output_schema' => self::custom_post_detail_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'get_custom_post' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_read' ),
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Create / update
	 * ---------------------------------------------------------------- */

	private static function register_writes() {
		wp_register_ability( 'wp-mcp/create-custom-post', array(
			'label'       => __( 'Create Custom Post Type Entry', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a draft entry of a registered custom post type. Status and author are always set server-side; only fields the post type declares support for are accepted.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::object_schema( array_merge( array( 'post_type' => self::post_type_property() ), self::content_properties() ), array( 'post_type' ) ),
			'output_schema' => self::custom_post_summary_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'create_custom_post' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_create' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );

		wp_register_ability( 'wp-mcp/update-custom-post', array(
			'label'       => __( 'Update Custom Post Type Entry', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update the supported content fields of one entry. A field the post type does not support is refused rather than silently stored.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::object_schema( array_merge( array( 'post_type' => self::post_type_property(), 'post_id' => self::post_id_property() ), self::content_properties() ), array( 'post_type', 'post_id' ) ),
			'output_schema' => self::custom_post_summary_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'update_custom_post' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_edit' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Lifecycle
	 * ---------------------------------------------------------------- */

	private static function register_lifecycle() {
		wp_register_ability( 'wp-mcp/publish-custom-post', array(
			'label'       => __( 'Publish Custom Post Type Entry', 'wordpress-mcp-abilities' ),
			'description' => __( 'Publish one entry, gated on the post type\'s own publish capability rather than the literal publish_posts capability. Idempotent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::entry_schema(),
			'output_schema' => self::custom_post_summary_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'publish_custom_post' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_publish' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/unpublish-custom-post', array(
			'label'       => __( 'Unpublish Custom Post Type Entry', 'wordpress-mcp-abilities' ),
			'description' => __( 'Return one published entry to draft. Idempotent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::entry_schema(),
			'output_schema' => self::custom_post_summary_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'unpublish_custom_post' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_edit' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/trash-custom-post', array(
			'label'       => __( 'Trash Custom Post Type Entry', 'wordpress-mcp-abilities' ),
			'description' => __( 'Move one entry to trash, gated on the native delete_post meta-capability for that post type. Idempotent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::entry_schema(),
			'output_schema' => self::custom_post_summary_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'trash_custom_post' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_delete' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/restore-custom-post', array(
			'label'       => __( 'Restore Custom Post Type Entry', 'wordpress-mcp-abilities' ),
			'description' => __( 'Restore one entry from trash. Idempotent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::entry_schema(),
			'output_schema' => self::custom_post_summary_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'restore_custom_post' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_delete' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/delete-custom-post-permanently', array(
			'label'       => __( 'Delete Custom Post Type Entry Permanently', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete one entry, bypassing trash. Not idempotent — a second call reports the entry as missing.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::entry_schema(),
			'output_schema' => self::object_schema( array(
				'post_type' => array( 'type' => 'string' ),
				'id'        => array( 'type' => 'integer' ),
				'deleted'   => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'delete_custom_post_permanently' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_delete' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Revisions
	 * ---------------------------------------------------------------- */

	private static function register_revisions() {
		wp_register_ability( 'wp-mcp/list-custom-post-revisions', array(
			'label'       => __( 'List Custom Post Type Entry Revisions', 'wordpress-mcp-abilities' ),
			'description' => __( 'List revisions of one entry. Refused with a stable error when the post type does not declare revisions support.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::object_schema( array_merge( array( 'post_type' => self::post_type_property(), 'post_id' => self::post_id_property() ), WP_MCP_Ability_Schema::pagination_input_properties() ), array( 'post_type', 'post_id' ) ),
			'output_schema' => self::object_schema( array_merge(
				array(
					'post_type' => array( 'type' => 'string' ),
					'post_id'   => array( 'type' => 'integer' ),
					'revisions' => array( 'type' => 'array', 'items' => self::revision_summary_schema() ),
				),
				WP_MCP_Ability_Schema::pagination_output_properties()
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'list_custom_post_revisions' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_edit' ),
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-custom-post-revision', array(
			'label'       => __( 'Get Custom Post Type Entry Revision', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one revision, only when it belongs to the requested entry of the requested post type.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::revision_input_schema(),
			'output_schema' => self::revision_detail_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'get_custom_post_revision' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_edit' ),
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/restore-custom-post-revision', array(
			'label'       => __( 'Restore Custom Post Type Entry Revision', 'wordpress-mcp-abilities' ),
			'description' => __( 'Restore one entry to a previous revision. Not idempotent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::revision_input_schema(),
			'output_schema' => self::custom_post_summary_schema(),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'restore_custom_post_revision' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_edit' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Featured media
	 * ---------------------------------------------------------------- */

	private static function register_featured_media() {
		wp_register_ability( 'wp-mcp/set-custom-post-featured-image', array(
			'label'       => __( 'Set Custom Post Type Entry Featured Image', 'wordpress-mcp-abilities' ),
			'description' => __( 'Set an existing image attachment as the entry\'s featured media. Refused when the post type does not declare thumbnail support. Idempotent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::object_schema( array(
				'post_type' => self::post_type_property(),
				'post_id'   => self::post_id_property(),
				'media_id'  => array( 'type' => 'integer', 'description' => 'Existing image attachment ID.', 'minimum' => 1 ),
			), array( 'post_type', 'post_id', 'media_id' ) ),
			'output_schema' => self::object_schema( array(
				'post_type' => array( 'type' => 'string' ),
				'post_id'   => array( 'type' => 'integer' ),
				'media_id'  => array( 'type' => 'integer' ),
				'media_url' => array( 'type' => 'string' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'set_custom_post_featured_image' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_edit' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/remove-custom-post-featured-image', array(
			'label'       => __( 'Remove Custom Post Type Entry Featured Image', 'wordpress-mcp-abilities' ),
			'description' => __( 'Remove the entry\'s featured media. Refused when the post type does not declare thumbnail support. Idempotent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::entry_schema(),
			'output_schema' => self::object_schema( array(
				'post_type'         => array( 'type' => 'string' ),
				'post_id'           => array( 'type' => 'integer' ),
				'removed_media_id'  => array( 'type' => 'integer' ),
				'featured_media_id' => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'remove_custom_post_featured_image' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_edit' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Registered metadata
	 * ---------------------------------------------------------------- */

	private static function register_meta() {
		wp_register_ability( 'wp-mcp/get-custom-post-meta', array(
			'label'       => __( 'Get Custom Post Type Entry Metadata', 'wordpress-mcp-abilities' ),
			'description' => __( 'Read one registered, REST-visible, single-valued post metadata key. Unregistered, multi-valued and protected keys are rejected.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::meta_input_schema( false ),
			'output_schema' => self::meta_output_schema( false ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'get_custom_post_meta' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_read' ),
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/update-custom-post-meta', array(
			'label'       => __( 'Update Custom Post Type Entry Metadata', 'wordpress-mcp-abilities' ),
			'description' => __( 'Write one registered post metadata key after validating the value against that key\'s own registered REST schema and the native edit_post_meta capability.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::meta_input_schema( true ),
			'output_schema' => self::meta_output_schema( false ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'update_custom_post_meta' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_edit' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/delete-custom-post-meta', array(
			'label'       => __( 'Delete Custom Post Type Entry Metadata', 'wordpress-mcp-abilities' ),
			'description' => __( 'Delete one registered post metadata key, gated on the native delete_post_meta capability. Idempotent when the key is absent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema'  => self::meta_input_schema( false ),
			'output_schema' => self::meta_output_schema( true ),
			'execute_callback'    => array( 'WP_MCP_Post_Types', 'delete_custom_post_meta' ),
			'permission_callback' => array( 'WP_MCP_Post_Types', 'can_edit' ),
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Schema helpers
	 * ---------------------------------------------------------------- */

	private static function object_schema( $properties, $required ) {
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false );
	}

	private static function post_type_property() { return array( 'type' => 'string', 'description' => 'Registered post type name.', 'minLength' => 1 ); }
	private static function post_id_property() { return array( 'type' => 'integer', 'description' => 'Entry ID within the requested post type.', 'minimum' => 1 ); }

	private static function entry_schema() {
		return self::object_schema( array( 'post_type' => self::post_type_property(), 'post_id' => self::post_id_property() ), array( 'post_type', 'post_id' ) );
	}

	private static function revision_input_schema() {
		return self::object_schema( array( 'post_type' => self::post_type_property(), 'post_id' => self::post_id_property(), 'revision_id' => array( 'type' => 'integer', 'description' => 'Revision ID belonging to the entry.', 'minimum' => 1 ) ), array( 'post_type', 'post_id', 'revision_id' ) );
	}

	private static function content_properties() {
		return array(
			'title'   => array( 'type' => 'string', 'description' => 'Entry title; requires "title" support.' ),
			'content' => array( 'type' => 'string', 'description' => 'Entry content; requires "editor" support.' ),
			'excerpt' => array( 'type' => 'string', 'description' => 'Entry excerpt; requires "excerpt" support.' ),
		);
	}

	private static function meta_input_schema( $with_value ) {
		$properties = array(
			'post_type' => self::post_type_property(),
			'post_id'   => self::post_id_property(),
			'meta_key'  => array( 'type' => 'string', 'description' => 'Registered, REST-visible, single-valued metadata key.', 'minLength' => 1 ),
		);
		if ( $with_value ) {
			$properties['value'] = array( 'type' => self::meta_value_types(), 'description' => 'Value validated and sanitized against the key\'s own registered metadata schema.' );
		}
		return self::object_schema( $properties, $with_value ? array( 'post_type', 'post_id', 'meta_key', 'value' ) : array( 'post_type', 'post_id', 'meta_key' ) );
	}

	private static function meta_output_schema( $deleted ) {
		$properties = array( 'post_type' => array( 'type' => 'string' ), 'post_id' => array( 'type' => 'integer' ), 'meta_key' => array( 'type' => 'string' ) );
		if ( $deleted ) {
			$properties['deleted'] = array( 'type' => 'boolean' );
		} else {
			$properties['value'] = array( 'type' => self::meta_value_types(), 'description' => 'Stored value, shaped by the key\'s own registered metadata schema.' );
		}
		return self::object_schema( $properties, array() );
	}

	/**
	 * Every JSON type, for a metadata `value` whose shape only the key's own
	 * registration decides.
	 *
	 * WP_Ability::execute() validates input and output with
	 * rest_validate_value_from_schema(), which requires a `type` keyword: an
	 * untyped property raises a `_doing_it_wrong` notice and a PHP warning on
	 * every call. Listing every type satisfies it without narrowing anything —
	 * WP_MCP_Post_Types::sanitize_registered_meta_value() still validates the
	 * value against the key's registered schema.
	 *
	 * @since 0.15.0
	 *
	 * @return string[]
	 */
	private static function meta_value_types() {
		return array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' );
	}

	private static function meta_field_schema() {
		return self::object_schema( array(
			'key'          => array( 'type' => 'string' ),
			'type'         => array( 'type' => 'string' ),
			'description'  => array( 'type' => 'string' ),
			'single'       => array( 'type' => 'boolean' ),
			'show_in_rest' => array( 'type' => 'boolean' ),
			'protected'    => array( 'type' => 'boolean' ),
			'operable'     => array( 'type' => 'boolean' ),
			'reason'       => array( 'type' => 'string', 'enum' => array( 'operable', 'protected_key', 'not_show_in_rest', 'multi_value' ) ),
		), array() );
	}

	/**
	 * Discovery schema for one post type. The `capabilities` key set is
	 * generated from WP_MCP_Post_Types::capability_slots() so the schema
	 * and the emitted payload cannot drift apart.
	 *
	 * @return array
	 */
	private static function post_type_schema() {
		$capabilities = array();
		foreach ( WP_MCP_Post_Types::capability_slots() as $slot ) {
			$capabilities[ $slot ] = array( 'type' => 'string' );
		}

		return self::object_schema( array(
			'name'            => array( 'type' => 'string' ),
			'label'           => array( 'type' => 'string' ),
			'singular_label'  => array( 'type' => 'string' ),
			'description'     => array( 'type' => 'string' ),
			'hierarchical'    => array( 'type' => 'boolean' ),
			'public'          => array( 'type' => 'boolean' ),
			'show_ui'         => array( 'type' => 'boolean' ),
			'show_in_rest'    => array( 'type' => 'boolean' ),
			'has_archive'     => array( 'type' => 'boolean' ),
			'built_in'        => array( 'type' => 'boolean' ),
			'map_meta_cap'    => array( 'type' => 'boolean' ),
			'capability_type' => array( 'type' => 'string' ),
			'supports'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'taxonomies'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'capabilities'    => self::object_schema( $capabilities, array() ),
			'permissions'     => self::object_schema( array(
				'can_create'  => array( 'type' => 'boolean' ),
				'can_edit'    => array( 'type' => 'boolean' ),
				'can_publish' => array( 'type' => 'boolean' ),
				'can_delete'  => array( 'type' => 'boolean' ),
			), array() ),
			'operable'        => array( 'type' => 'boolean' ),
			'reason'          => array( 'type' => 'string', 'enum' => array( 'operable', 'dedicated_domain', 'built_in', 'not_show_in_rest' ) ),
		), array() );
	}

	private static function custom_post_summary_schema() {
		return self::object_schema( self::custom_post_summary_properties(), array() );
	}

	private static function custom_post_summary_properties() {
		return array(
			'id'        => array( 'type' => 'integer' ),
			'post_type' => array( 'type' => 'string' ),
			'title'     => array( 'type' => 'string' ),
			'status'    => array( 'type' => 'string' ),
			'author'    => array( 'type' => 'integer' ),
			'slug'      => array( 'type' => 'string' ),
			'date'      => array( 'type' => 'string' ),
			'modified'  => array( 'type' => 'string' ),
			'link'      => array( 'type' => 'string' ),
		);
	}

	private static function custom_post_detail_schema() {
		return self::object_schema( array_merge( self::custom_post_summary_properties(), array(
			'content'        => array( 'type' => 'string' ),
			'excerpt'        => array( 'type' => 'string' ),
			'parent'         => array( 'type' => 'integer' ),
			'menu_order'     => array( 'type' => 'integer' ),
			'featured_media' => array( 'type' => 'integer' ),
			'supports'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'terms'          => array( 'type' => 'array', 'items' => self::term_group_schema() ),
			'meta'           => array( 'type' => 'array', 'items' => self::meta_pair_schema() ),
		) ), array() );
	}

	private static function term_group_schema() {
		return self::object_schema( array(
			'taxonomy' => array( 'type' => 'string' ),
			'term_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
		), array() );
	}

	private static function meta_pair_schema() {
		return self::object_schema( array(
			'key'   => array( 'type' => 'string' ),
			'value' => array( 'description' => 'Stored value, shaped by the key\'s own registered metadata schema.' ),
		), array() );
	}

	private static function revision_summary_schema() {
		return self::object_schema( array(
			'id'       => array( 'type' => 'integer' ),
			'author'   => array( 'type' => 'integer' ),
			'date'     => array( 'type' => 'string' ),
			'modified' => array( 'type' => 'string' ),
		), array() );
	}

	private static function revision_detail_schema() {
		return self::object_schema( array(
			'post_type' => array( 'type' => 'string' ),
			'id'        => array( 'type' => 'integer' ),
			'parent_id' => array( 'type' => 'integer' ),
			'author'    => array( 'type' => 'integer' ),
			'title'     => array( 'type' => 'string' ),
			'content'   => array( 'type' => 'string' ),
			'excerpt'   => array( 'type' => 'string' ),
			'date'      => array( 'type' => 'string' ),
			'modified'  => array( 'type' => 'string' ),
		), array() );
	}
}
