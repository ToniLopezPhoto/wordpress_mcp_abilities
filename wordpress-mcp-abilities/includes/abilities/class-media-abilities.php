<?php
/**
 * WordPress MCP Abilities — Media domain.
 *
 * Registers the explicit media-library surface. No ability accepts a server
 * filesystem path; uploads use client content or a validated remote URL.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Media_Abilities
 */
class WP_MCP_Media_Abilities {

	/** Register all media-domain abilities. */
	public static function register() {
		self::register_list_media();
		self::register_get_media();
		self::register_upload_media();
		self::register_upload_media_from_url();
		self::register_update_media();
		self::register_replace_media();
		self::register_attach_media();
		self::register_detach_media();
		self::register_set_featured_image();
		self::register_remove_featured_image();
		self::register_regenerate_media_metadata();
		self::register_trash_media();
		self::register_restore_media();
		self::register_delete_media_permanently();
		self::register_bulk_trash_media();
	}

	/* ------------------------------------------------------------------
	 * Read abilities
	 * ---------------------------------------------------------------- */

	private static function register_list_media() {
		wp_register_ability( 'wp-mcp/list-media', array(
			'label'       => __( 'List Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'List visible media-library attachments with metadata and image dimensions.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array_merge( array(
					'search'    => array( 'type' => 'string', 'description' => 'Search keyword.' ),
					'mime_type' => array( 'type' => 'string', 'description' => 'Filter by MIME type.' ),
				), WP_MCP_Ability_Schema::pagination_input_properties() ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::list_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'list_media' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta'               => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_get_media() {
		wp_register_ability( 'wp-mcp/get-media', array(
			'label'       => __( 'Get Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one visible attachment and its editable metadata, relationships, URLs, and dimensions.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'media_id' => self::media_id_property() ), array( 'media_id' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'get_media' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta'               => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Upload abilities
	 * ---------------------------------------------------------------- */

	private static function register_upload_media() {
		wp_register_ability( 'wp-mcp/upload-media', array(
			'label'       => __( 'Upload Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create an attachment from base64 file content supplied by the MCP client. Server filesystem paths are not accepted.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( self::file_properties(), array( 'filename', 'content_base64' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'upload_media' ),
			'permission_callback' => function () { return current_user_can( 'upload_files' ); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );
	}

	private static function register_upload_media_from_url() {
		wp_register_ability( 'wp-mcp/upload-media-from-url', array(
			'label'       => __( 'Upload Media from URL', 'wordpress-mcp-abilities' ),
			'description' => __( 'Safely download a remote HTTP(S) file and create an attachment, with explicit host policy and SSRF protections.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( self::remote_file_properties(), array( 'url' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'upload_media_from_url' ),
			'permission_callback' => function () { return current_user_can( 'upload_files' ); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Metadata / relationship abilities
	 * ---------------------------------------------------------------- */

	private static function register_update_media() {
		wp_register_ability( 'wp-mcp/update-media', array(
			'label'       => __( 'Update Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update an attachment title, caption, description, and/or alternative text. No arbitrary metadata is accepted.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array_merge( array( 'media_id' => self::media_id_property() ), self::editable_metadata_properties() ), array( 'media_id' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'update_media' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_replace_media() {
		wp_register_ability( 'wp-mcp/replace-media', array(
			'label'       => __( 'Replace Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'Replace an attachment file while preserving its attachment ID, using WordPress core upload and metadata APIs.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array_merge( array( 'media_id' => self::media_id_property() ), self::file_properties( false, true ) ), array( 'media_id', 'filename', 'content_base64' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'replace_media' ),
			// Replacing sideloads a new file, so it needs upload_files just like upload-media.
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts() && current_user_can( 'upload_files' ); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function register_attach_media() {
		wp_register_ability( 'wp-mcp/attach-media', array(
			'label'       => __( 'Attach Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'Attach an existing media item to an editable post or page.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'media_id' => self::media_id_property(), 'post_id' => self::post_id_property() ), array( 'media_id', 'post_id' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'attach_media' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_detach_media() {
		wp_register_ability( 'wp-mcp/detach-media', array(
			'label'       => __( 'Detach Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'Remove an attachment relationship without deleting the media item.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'media_id' => self::media_id_property() ), array( 'media_id' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'detach_media' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_set_featured_image() {
		wp_register_ability( 'wp-mcp/set-featured-image', array(
			'label'       => __( 'Set Featured Image', 'wordpress-mcp-abilities' ),
			'description' => __( 'Set an existing image as the featured image of an editable post or page. Idempotent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'post_id' => self::post_id_property(), 'media_id' => self::media_id_property() ), array( 'post_id', 'media_id' ) ),
			'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'media_id' => array( 'type' => 'integer' ), 'media_url' => array( 'type' => 'string' ) ) ),
			'execute_callback'    => array( 'WP_MCP_Media', 'set_featured_image' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_remove_featured_image() {
		wp_register_ability( 'wp-mcp/remove-featured-image', array(
			'label'       => __( 'Remove Featured Image', 'wordpress-mcp-abilities' ),
			'description' => __( 'Remove featured media from an editable post or page. Idempotent and does not delete the attachment.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'post_id' => self::post_id_property() ), array( 'post_id' ) ),
			'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'removed_media_id' => array( 'type' => 'integer' ), 'featured_media_id' => array( 'type' => 'integer' ) ) ),
			'execute_callback'    => array( 'WP_MCP_Media', 'remove_featured_image' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_regenerate_media_metadata() {
		wp_register_ability( 'wp-mcp/regenerate-media-metadata', array(
			'label'       => __( 'Regenerate Media Metadata', 'wordpress-mcp-abilities' ),
			'description' => __( 'Regenerate attachment metadata and image sub-sizes with WordPress core APIs.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'media_id' => self::media_id_property() ), array( 'media_id' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'regenerate_media_metadata' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Lifecycle / bulk abilities
	 * ---------------------------------------------------------------- */

	private static function register_trash_media() {
		wp_register_ability( 'wp-mcp/trash-media', array(
			'label'       => __( 'Trash Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'Move an attachment to the WordPress trash. Idempotent.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'media_id' => self::media_id_property() ), array( 'media_id' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'trash_media' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_restore_media() {
		wp_register_ability( 'wp-mcp/restore-media', array(
			'label'       => __( 'Restore Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'Restore an attachment from the WordPress trash. Idempotent when it is not trashed.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'media_id' => self::media_id_property() ), array( 'media_id' ) ),
			'output_schema'       => self::media_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'restore_media' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );
	}

	private static function register_delete_media_permanently() {
		wp_register_ability( 'wp-mcp/delete-media-permanently', array(
			'label'       => __( 'Delete Media Permanently', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete an attachment and its core-managed files. Separate from trash.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'media_id' => self::media_id_property() ), array( 'media_id' ) ),
			'output_schema'       => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'deleted' => array( 'type' => 'boolean' ) ) ),
			'execute_callback'    => array( 'WP_MCP_Media', 'delete_media_permanently' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_delete_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function register_bulk_trash_media() {
		wp_register_ability( 'wp-mcp/bulk-trash-media', array(
			'label'       => __( 'Bulk Trash Media', 'wordpress-mcp-abilities' ),
			'description' => __( 'Trash up to 20 attachments. Every item is validated and reported independently.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-media',
			'input_schema' => self::object_schema( array( 'media_ids' => array( 'type' => 'array', 'description' => 'Attachment IDs to trash (maximum 20).', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'minItems' => 1, 'maxItems' => 20 ) ), array( 'media_ids' ) ),
			'output_schema'       => self::bulk_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Media', 'bulk_trash_media' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta'               => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Schema helpers
	 * ---------------------------------------------------------------- */

	private static function media_id_property() {
		return array( 'type' => 'integer', 'description' => 'Existing attachment ID.', 'minimum' => 1 );
	}

	private static function post_id_property() {
		return array( 'type' => 'integer', 'description' => 'Editable post or page ID.', 'minimum' => 1 );
	}

	private static function editable_metadata_properties() {
		return array(
			'title'       => array( 'type' => 'string', 'description' => 'Attachment title.' ),
			'caption'     => array( 'type' => 'string', 'description' => 'Attachment caption.' ),
			'description' => array( 'type' => 'string', 'description' => 'Attachment description.' ),
			'alt_text'    => array( 'type' => 'string', 'description' => 'Image alternative text.' ),
		);
	}

	private static function file_properties( $include_parent = true, $include_title = true ) {
		$properties = array(
			'filename'      => array( 'type' => 'string', 'description' => 'Client-provided filename, including a permitted extension.', 'minLength' => 1, 'maxLength' => 255 ),
			'content_base64' => array( 'type' => 'string', 'description' => 'Raw base64 file content; data-URI prefixes and filesystem paths are not accepted.', 'minLength' => 1, 'maxLength' => 14680064 ),
			'mime_type'     => array( 'type' => 'string', 'description' => 'Optional advisory MIME type; WordPress validates the actual file.' ),
			'alt_text'      => array( 'type' => 'string', 'description' => 'Optional image alternative text.' ),
			'caption'       => array( 'type' => 'string', 'description' => 'Optional attachment caption.' ),
			'description'   => array( 'type' => 'string', 'description' => 'Optional attachment description.' ),
		);
		if ( $include_title ) {
			$properties['title'] = array( 'type' => 'string', 'description' => 'Optional attachment title.' );
		}
		if ( $include_parent ) {
			$properties['parent_id'] = array( 'type' => 'integer', 'description' => 'Optional editable post/page parent; 0 leaves it unattached.', 'minimum' => 0 );
		}
		return $properties;
	}

	private static function remote_file_properties() {
		return array(
			'url'           => array( 'type' => 'string', 'description' => 'HTTP(S) URL to download.' ),
			'filename'     => array( 'type' => 'string', 'description' => 'Optional client-provided filename.', 'minLength' => 1, 'maxLength' => 255 ),
			'mime_type'    => array( 'type' => 'string', 'description' => 'Optional advisory MIME type; WordPress validates the actual file.' ),
			'alt_text'     => array( 'type' => 'string', 'description' => 'Optional image alternative text.' ),
			'caption'      => array( 'type' => 'string', 'description' => 'Optional attachment caption.' ),
			'description'  => array( 'type' => 'string', 'description' => 'Optional attachment description.' ),
			'title'        => array( 'type' => 'string', 'description' => 'Optional attachment title.' ),
			'parent_id'    => array( 'type' => 'integer', 'description' => 'Optional editable post/page parent; 0 leaves it unattached.', 'minimum' => 0 ),
			'allowed_hosts' => array( 'type' => 'array', 'description' => 'Optional exact hosts (and subdomains) allowed for this request.', 'items' => array( 'type' => 'string', 'minLength' => 1 ), 'maxItems' => 20 ),
			'deny_hosts'    => array( 'type' => 'array', 'description' => 'Optional exact hosts (and subdomains) denied for this request.', 'items' => array( 'type' => 'string', 'minLength' => 1 ), 'maxItems' => 20 ),
		);
	}

	private static function object_schema( $properties, $required ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	private static function media_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'caption'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'alt_text'    => array( 'type' => 'string' ),
				'mime_type'   => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'parent_id'   => array( 'type' => 'integer' ),
				'width'       => array( 'type' => 'integer' ),
				'height'      => array( 'type' => 'integer' ),
				'date'        => array( 'type' => 'string' ),
				'modified'    => array( 'type' => 'string' ),
				'sizes'       => array( 'type' => 'object' ),
			),
		);
	}

	private static function list_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array( 'media' => array( 'type' => 'array', 'items' => self::media_output_schema() ) ),
				WP_MCP_Ability_Schema::pagination_output_properties()
			),
			'additionalProperties' => false,
		);
	}

	private static function bulk_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'results' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'success' => array( 'type' => 'boolean' ), 'error_code' => array( 'type' => 'string' ) ) ) ),
				'trashed' => array( 'type' => 'integer' ),
				'failed'  => array( 'type' => 'integer' ),
			),
		);
	}
}
