<?php
/**
 * WordPress MCP Abilities — Comments domain.
 *
 * Registers explicit comment read, creation, editing, moderation, lifecycle,
 * and bounded bulk abilities. Comment actions use WordPress native comment
 * APIs and capabilities; no arbitrary status or comment fields are exposed.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Comments_Abilities
 */
class WP_MCP_Comments_Abilities {

	/** Register every comment-domain ability. */
	public static function register() {
		self::register_list_comments();
		self::register_get_comment();
		self::register_create_comment();
		self::register_reply_comment();
		self::register_update_comment();
		self::register_approve_comment();
		self::register_unapprove_comment();
		self::register_mark_comment_spam();
		self::register_unspam_comment();
		self::register_trash_comment();
		self::register_restore_comment();
		self::register_delete_comment_permanently();
		self::register_bulk_moderate_comments();
	}

	/* ------------------------------------------------------------------
	 * Read abilities
	 * ---------------------------------------------------------------- */

	private static function register_list_comments() {
		wp_register_ability( 'wp-mcp/list-comments', array(
			'label'       => __( 'List Comments', 'wordpress-mcp-abilities' ),
			'description' => __( 'List readable comments with filters for post, author, status, parent, date, and search. Non-public statuses require comment moderation capability.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-comments',
			'input_schema' => self::object_schema( array_merge( self::filter_properties(), WP_MCP_Ability_Schema::pagination_input_properties() ), array() ),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array_merge(
					array( 'comments' => array( 'type' => 'array', 'items' => self::comment_schema() ), 'status' => array( 'type' => 'string' ) ),
					WP_MCP_Ability_Schema::pagination_output_properties()
				),
			),
			'execute_callback'    => array( 'WP_MCP_Comments', 'list_comments' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_get_comment() {
		wp_register_ability( 'wp-mcp/get-comment', array(
			'label'       => __( 'Get Comment', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get one readable comment, its post and parent context, and public author data. Email is returned only to a moderator.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-comments',
			'input_schema' => self::object_schema( array( 'comment_id' => self::comment_id_property() ), array( 'comment_id' ) ),
			'output_schema' => self::comment_schema( true ),
			'execute_callback'    => array( 'WP_MCP_Comments', 'get_comment' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_read(); },
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Creation and editing
	 * ---------------------------------------------------------------- */

	private static function register_create_comment() {
		wp_register_ability( 'wp-mcp/create-comment', array(
			'label'       => __( 'Create Comment', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a comment on a readable post using the authenticated WordPress user identity. WordPress decides whether it is approved or held.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-comments',
			'input_schema' => self::object_schema( array( 'post_id' => self::post_id_property(), 'content' => self::content_property() ), array( 'post_id', 'content' ) ),
			'output_schema' => self::comment_schema( true ),
			'execute_callback'    => array( 'WP_MCP_Comments', 'create_comment' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );
	}

	private static function register_reply_comment() {
		wp_register_ability( 'wp-mcp/reply-comment', array(
			'label'       => __( 'Reply to Comment', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a threaded reply to a readable approved or held comment, using the authenticated WordPress user identity.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-comments',
			'input_schema' => self::object_schema( array( 'parent_comment_id' => self::comment_id_property(), 'content' => self::content_property() ), array( 'parent_comment_id', 'content' ) ),
			'output_schema' => self::comment_schema( true ),
			'execute_callback'    => array( 'WP_MCP_Comments', 'reply_comment' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ),
		) );
	}

	private static function register_update_comment() {
		wp_register_ability( 'wp-mcp/update-comment', array(
			'label'       => __( 'Update Comment', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update comment content through the native edit_comment capability. Author name, URL, and email are explicit moderator-only fields.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-comments',
			'input_schema' => self::object_schema( array_merge( array( 'comment_id' => self::comment_id_property() ), self::editable_properties() ), array( 'comment_id' ) ),
			'output_schema' => self::comment_schema( true ),
			'execute_callback'    => array( 'WP_MCP_Comments', 'update_comment' ),
			'permission_callback' => function () { return WP_MCP_Permissions::can_edit_posts(); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Moderation and lifecycle
	 * ---------------------------------------------------------------- */

	private static function register_approve_comment() {
		self::register_status_ability( 'approve-comment', __( 'Approve Comment', 'wordpress-mcp-abilities' ), __( 'Approve a comment using the native moderate_comments capability.', 'wordpress-mcp-abilities' ), 'approve', true );
	}

	private static function register_unapprove_comment() {
		self::register_status_ability( 'unapprove-comment', __( 'Unapprove Comment', 'wordpress-mcp-abilities' ), __( 'Move a comment back to the moderation queue using the native moderate_comments capability.', 'wordpress-mcp-abilities' ), 'unapprove', true );
	}

	private static function register_mark_comment_spam() {
		self::register_status_ability( 'mark-comment-spam', __( 'Mark Comment as Spam', 'wordpress-mcp-abilities' ), __( 'Mark a comment as spam using WordPress comment APIs and moderate_comments.', 'wordpress-mcp-abilities' ), 'spam', true );
	}

	private static function register_unspam_comment() {
		self::register_status_ability( 'unspam-comment', __( 'Unspam Comment', 'wordpress-mcp-abilities' ), __( 'Remove a comment from spam using WordPress comment APIs and moderate_comments.', 'wordpress-mcp-abilities' ), 'unspam', true );
	}

	private static function register_trash_comment() {
		self::register_status_ability( 'trash-comment', __( 'Trash Comment', 'wordpress-mcp-abilities' ), __( 'Move a comment to the WordPress trash using moderate_comments.', 'wordpress-mcp-abilities' ), 'trash', true );
	}

	private static function register_restore_comment() {
		self::register_status_ability( 'restore-comment', __( 'Restore Comment', 'wordpress-mcp-abilities' ), __( 'Restore a trashed comment using WordPress comment APIs and moderate_comments.', 'wordpress-mcp-abilities' ), 'restore', false );
	}

	private static function register_status_ability( $slug, $label, $description, $action, $destructive ) {
		wp_register_ability( 'wp-mcp/' . $slug, array(
			'label'       => $label,
			'description' => $description,
			'category'    => 'wp-mcp-comments',
			'input_schema' => self::object_schema( array( 'comment_id' => self::comment_id_property() ), array( 'comment_id' ) ),
			'output_schema' => self::comment_schema( true ),
			'execute_callback'    => array( 'WP_MCP_Comments', 'status_' . $action ),
			'permission_callback' => function () { return current_user_can( 'moderate_comments' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( $destructive, true ),
		) );
	}

	private static function register_delete_comment_permanently() {
		wp_register_ability( 'wp-mcp/delete-comment-permanently', array(
			'label'       => __( 'Delete Comment Permanently', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete one comment and its replies through the native moderate_comments capability. This is separate from trash and is never included in bulk moderation.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-comments',
			'input_schema' => self::object_schema( array( 'comment_id' => self::comment_id_property() ), array( 'comment_id' ) ),
			'output_schema' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'deleted' => array( 'type' => 'boolean' ) ) ),
			'execute_callback'    => array( 'WP_MCP_Comments', 'delete_comment_permanently' ),
			'permission_callback' => function () { return current_user_can( 'moderate_comments' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function register_bulk_moderate_comments() {
		wp_register_ability( 'wp-mcp/bulk-moderate-comments', array(
			'label'       => __( 'Bulk Moderate Comments', 'wordpress-mcp-abilities' ),
			'description' => __( 'Apply one of the fixed moderation actions approve, unapprove, spam, unspam, trash, or restore to up to 20 comments, with independent per-item results. Permanent deletion is excluded.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-comments',
			'input_schema' => self::object_schema( array( 'comment_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'minItems' => 1, 'maxItems' => 20 ), 'action' => array( 'type' => 'string', 'enum' => array( 'approve', 'unapprove', 'spam', 'unspam', 'trash', 'restore' ) ) ), array( 'comment_ids', 'action' ) ),
			'output_schema' => array( 'type' => 'object', 'properties' => array( 'results' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'success' => array( 'type' => 'boolean' ), 'error_code' => array( 'type' => 'string' ) ) ) ), 'processed' => array( 'type' => 'integer' ), 'failed' => array( 'type' => 'integer' ) ) ),
			'execute_callback'    => array( 'WP_MCP_Comments', 'bulk_moderate_comments' ),
			'permission_callback' => function () { return current_user_can( 'moderate_comments' ); },
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Schema helpers
	 * ---------------------------------------------------------------- */

	private static function object_schema( $properties, $required ) {
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false );
	}

	private static function comment_id_property() {
		return array( 'type' => 'integer', 'description' => 'Existing comment ID.', 'minimum' => 1 );
	}

	private static function post_id_property() {
		return array( 'type' => 'integer', 'description' => 'Readable post, page, or comment-enabled custom post ID.', 'minimum' => 1 );
	}

	private static function content_property() {
		return array( 'type' => 'string', 'description' => 'Comment content; WordPress sanitizes it before insertion.', 'minLength' => 1 );
	}

	private static function filter_properties() {
		return array(
			'post_id'    => self::post_id_property(),
			'author_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
			'status'     => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash', 'all' ), 'default' => 'approve' ),
			'parent_id'  => array( 'type' => 'integer', 'minimum' => 0 ),
			'search'     => array( 'type' => 'string' ),
			'date_after' => array( 'type' => 'string', 'description' => 'Inclusive date/time lower bound accepted by WordPress date_query.' ),
			'date_before' => array( 'type' => 'string', 'description' => 'Inclusive date/time upper bound accepted by WordPress date_query.' ),
		);
	}

	private static function editable_properties() {
		return array(
			'content'      => self::content_property(),
			'author_name'  => array( 'type' => 'string', 'description' => 'Moderator-only author display name.' ),
			'author_url'   => array( 'type' => 'string', 'description' => 'Moderator-only author URL.' ),
			'author_email' => array( 'type' => 'string', 'description' => 'Moderator-only author email.' ),
		);
	}

	private static function comment_schema( $include_email = false ) {
		$properties = array(
			'id'          => array( 'type' => 'integer' ),
			'post_id'     => array( 'type' => 'integer' ),
			'post_title'  => array( 'type' => 'string' ),
			'parent_id'   => array( 'type' => 'integer' ),
			'status'      => array( 'type' => 'string' ),
			'author_id'   => array( 'type' => 'integer' ),
			'author_name' => array( 'type' => 'string' ),
			'author_url'  => array( 'type' => 'string' ),
			'content'     => array( 'type' => 'string' ),
			'date'        => array( 'type' => 'string' ),
			'date_gmt'    => array( 'type' => 'string' ),
			'link'        => array( 'type' => 'string' ),
		);
		if ( $include_email ) {
			$properties['author_email'] = array( 'type' => 'string' );
		}
		return array( 'type' => 'object', 'properties' => $properties );
	}
}
