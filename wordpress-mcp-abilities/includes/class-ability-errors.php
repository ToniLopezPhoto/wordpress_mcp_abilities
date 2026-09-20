<?php
/**
 * WordPress MCP Abilities — Centralized WP_Error factory.
 *
 * One constructor per documented error code (see README "Error Codes").
 * Callers supply the exact message text for their context; this class
 * guarantees a consistent `code`/`status` pairing so the catalog stays in
 * one place.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Errors
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Errors {

	/**
	 * The specified post does not exist.
	 *
	 * @return WP_Error
	 */
	public static function invalid_post() {
		return new WP_Error(
			'wp_mcp_invalid_post',
			__( 'The specified post does not exist.', 'wordpress-mcp-abilities' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * The specified page does not exist.
	 *
	 * @return WP_Error
	 */
	public static function invalid_page() {
		return new WP_Error(
			'wp_mcp_invalid_page',
			__( 'The specified page does not exist.', 'wordpress-mcp-abilities' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * The specified media attachment does not exist.
	 *
	 * @return WP_Error
	 */
	public static function invalid_media() {
		return new WP_Error(
			'wp_mcp_invalid_media',
			__( 'The specified media does not exist.', 'wordpress-mcp-abilities' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * The specified attachment is not an image.
	 *
	 * @return WP_Error
	 */
	public static function not_an_image() {
		return new WP_Error(
			'wp_mcp_not_an_image',
			__( 'The specified attachment is not an image.', 'wordpress-mcp-abilities' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Wrong post_type for the ability that was called.
	 *
	 * @param string $message Context-specific message.
	 * @return WP_Error
	 */
	public static function unsupported_post_type( $message ) {
		return new WP_Error(
			'wp_mcp_unsupported_post_type',
			$message,
			array( 'status' => 400 )
		);
	}

	/**
	 * Current user lacks the required capability.
	 *
	 * @param string $message Context-specific message.
	 * @return WP_Error
	 */
	public static function permission_denied( $message ) {
		return new WP_Error(
			'wp_mcp_permission_denied',
			$message,
			array( 'status' => 403 )
		);
	}

	/**
	 * Current user is not the object's author and lacks the capability
	 * (e.g. edit_others_posts) required to act on someone else's content.
	 *
	 * @return WP_Error
	 */
	public static function ownership_violation() {
		return new WP_Error(
			'wp_mcp_ownership_violation',
			__( 'You can only modify your own posts.', 'wordpress-mcp-abilities' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Generic input validation failure.
	 *
	 * @param string $message Context-specific message.
	 * @return WP_Error
	 */
	public static function validation_error( $message ) {
		return new WP_Error(
			'wp_mcp_validation_error',
			$message,
			array( 'status' => 400 )
		);
	}

	/**
	 * Invalid taxonomy term ID (missing, nonexistent, or wrong taxonomy).
	 *
	 * @param string $message Context-specific message.
	 * @param int    $status  HTTP-style status (400 or 404 depending on cause).
	 * @return WP_Error
	 */
	public static function invalid_taxonomy_term( $message, $status = 400 ) {
		return new WP_Error(
			'wp_mcp_invalid_taxonomy_term',
			$message,
			array( 'status' => $status )
		);
	}

	/**
	 * wp_insert_post() failed.
	 *
	 * @return WP_Error
	 */
	public static function create_failed() {
		return new WP_Error(
			'wp_mcp_create_failed',
			__( 'Failed to create post.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * A mutation (wp_update_post, set_post_thumbnail, ...) failed.
	 *
	 * @param string|null $message Context-specific message; defaults to the post-update case.
	 * @return WP_Error
	 */
	public static function update_failed( $message = null ) {
		return new WP_Error(
			'wp_mcp_update_failed',
			$message ?? __( 'Failed to update post.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Publishing a post failed.
	 *
	 * @return WP_Error
	 */
	public static function publish_failed() {
		return new WP_Error(
			'wp_mcp_publish_failed',
			__( 'Failed to publish post.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * The specified revision does not exist or does not belong to the
	 * expected parent post.
	 *
	 * @return WP_Error
	 */
	public static function invalid_revision() {
		return new WP_Error(
			'wp_mcp_invalid_revision',
			__( 'The specified revision does not exist for this post.', 'wordpress-mcp-abilities' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * The requested status transition is not allowed from the object's
	 * current status.
	 *
	 * @param string $message Context-specific message.
	 * @return WP_Error
	 */
	public static function invalid_status_transition( $message ) {
		return new WP_Error(
			'wp_mcp_invalid_status_transition',
			$message,
			array( 'status' => 400 )
		);
	}

	/**
	 * A bulk operation's item count exceeds the allowed maximum.
	 *
	 * @param int $max Maximum number of items allowed per request.
	 * @return WP_Error
	 */
	public static function bulk_limit_exceeded( $max ) {
		return new WP_Error(
			'wp_mcp_bulk_limit_exceeded',
			/* translators: %d: maximum number of items allowed */
			sprintf( __( 'No more than %d items may be processed in a single bulk request.', 'wordpress-mcp-abilities' ), $max ),
			array( 'status' => 400 )
		);
	}

	/**
	 * The specified page template does not exist in the active theme.
	 *
	 * @return WP_Error
	 */
	public static function invalid_template() {
		return new WP_Error(
			'wp_mcp_invalid_template',
			__( 'The specified page template does not exist in the active theme.', 'wordpress-mcp-abilities' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Moving a post/page to trash failed.
	 *
	 * @return WP_Error
	 */
	public static function trash_failed() {
		return new WP_Error(
			'wp_mcp_trash_failed',
			__( 'Failed to move the item to trash.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Restoring a post/page from trash failed.
	 *
	 * @return WP_Error
	 */
	public static function restore_failed() {
		return new WP_Error(
			'wp_mcp_restore_failed',
			__( 'Failed to restore the item from trash.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Permanently deleting a post/page failed.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function delete_failed( $message = null ) {
		return new WP_Error(
			'wp_mcp_delete_failed',
			$message ?? __( 'Failed to permanently delete the item.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Media upload failed.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function upload_failed( $message = null ) {
		return new WP_Error(
			'wp_mcp_upload_failed',
			$message ?? __( 'Failed to upload media.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Upload exceeds the plugin's decoded byte limit.
	 *
	 * @param int $max_bytes Maximum size.
	 * @return WP_Error
	 */
	public static function upload_too_large( $max_bytes ) {
		return new WP_Error(
			'wp_mcp_upload_too_large',
			/* translators: %d: maximum allowed upload size, in bytes */
			sprintf( __( 'The media file exceeds the maximum size of %d bytes.', 'wordpress-mcp-abilities' ), $max_bytes ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Media type is not allowed by WordPress.
	 *
	 * @return WP_Error
	 */
	public static function unsupported_media_type() {
		return new WP_Error(
			'wp_mcp_unsupported_media_type',
			__( 'The media type or extension is not allowed by WordPress.', 'wordpress-mcp-abilities' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Remote download failed.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function download_failed( $message = null ) {
		return new WP_Error(
			'wp_mcp_download_failed',
			$message ?? __( 'Failed to download remote media.', 'wordpress-mcp-abilities' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * Remote URL was rejected by the SSRF/host policy.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function remote_url_denied( $message = null ) {
		return new WP_Error(
			'wp_mcp_remote_url_denied',
			$message ?? __( 'The remote URL is not allowed.', 'wordpress-mcp-abilities' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Replacement operation failed.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function replace_failed( $message = null ) {
		return new WP_Error(
			'wp_mcp_replace_failed',
			$message ?? __( 'Failed to replace media.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Attachment relationship update failed.
	 *
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	public static function attach_failed( $message = null ) {
		return new WP_Error(
			'wp_mcp_attach_failed',
			$message ?? __( 'Failed to attach media.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Attachment relationship removal failed.
	 *
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	public static function detach_failed( $message = null ) {
		return new WP_Error(
			'wp_mcp_detach_failed',
			$message ?? __( 'Failed to detach media.', 'wordpress-mcp-abilities' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Attachment's underlying file is missing.
	 *
	 * @return WP_Error
	 */
	public static function media_file_missing() {
		return new WP_Error(
			'wp_mcp_media_file_missing',
			__( 'The attachment file is missing or is not readable.', 'wordpress-mcp-abilities' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Taxonomy does not exist or is not registered.
	 *
	 * @return WP_Error
	 */
	public static function invalid_taxonomy() {
		return new WP_Error( 'wp_mcp_invalid_taxonomy', __( 'The specified taxonomy is not registered.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/**
	 * Term does not exist in the requested taxonomy.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function invalid_term( $message = null ) {
		return new WP_Error( 'wp_mcp_invalid_term', $message ?? __( 'The specified term does not exist in this taxonomy.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/**
	 * Taxonomy input or hierarchy validation failed.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function taxonomy_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_taxonomy_validation_error', $message ?? __( 'The taxonomy input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * Taxonomy capability check failed.
	 *
	 * @param string $capability Capability name.
	 * @return WP_Error
	 */
	public static function taxonomy_permission_denied( $capability ) {
		return new WP_Error(
			'wp_mcp_taxonomy_permission_denied',
			/* translators: %s: capability name */
			sprintf( __( 'The current user lacks the taxonomy capability: %s.', 'wordpress-mcp-abilities' ), sanitize_key( $capability ) ),
			array( 'status' => 403 )
		);
	}

	/** @return WP_Error */
	public static function taxonomy_object_type_mismatch() {
		return new WP_Error( 'wp_mcp_taxonomy_object_type_mismatch', __( 'The object type or object ID is not valid for this taxonomy.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @param string $message @return WP_Error */
	public static function term_query_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_term_query_failed', $message ?? __( 'Failed to query taxonomy terms.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string $message @return WP_Error */
	public static function term_create_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_term_create_failed', $message ?? __( 'Failed to create the term.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string $message @return WP_Error */
	public static function term_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_term_update_failed', $message ?? __( 'Failed to update the term.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string $message @return WP_Error */
	public static function term_delete_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_term_delete_failed', $message ?? __( 'Failed to delete the term.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string $message @return WP_Error */
	public static function term_assignment_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_term_assignment_failed', $message ?? __( 'Failed to update object terms.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function term_meta_not_registered() {
		return new WP_Error( 'wp_mcp_term_meta_not_registered', __( 'The term metadata key is not registered and REST-visible for this taxonomy.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function term_meta_update_failed() {
		return new WP_Error( 'wp_mcp_term_meta_update_failed', __( 'Failed to update term metadata.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function invalid_comment() {
		return new WP_Error( 'wp_mcp_invalid_comment', __( 'The specified comment does not exist.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function comment_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_comment_permission_denied', $message ?? __( 'You do not have permission to access this comment.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function comment_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_comment_validation_error', $message ?? __( 'The comment input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function comment_trash_unavailable( $message = null ) {
		return new WP_Error( 'wp_mcp_comment_trash_unavailable', $message ?? __( 'Comment trash is disabled; refusing to turn trash into permanent deletion.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function comment_query_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_comment_query_failed', $message ?? __( 'Failed to query comments.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function comment_create_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_comment_create_failed', $message ?? __( 'Failed to create the comment.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function comment_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_comment_update_failed', $message ?? __( 'Failed to update the comment.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function comment_moderation_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_comment_moderation_failed', $message ?? __( 'Failed to change the comment moderation status.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function comment_delete_failed() {
		return new WP_Error( 'wp_mcp_comment_delete_failed', __( 'Failed to permanently delete the comment.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function invalid_user() {
		return new WP_Error( 'wp_mcp_invalid_user', __( 'The specified user does not exist.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function user_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_user_permission_denied', $message ?? __( 'You do not have permission to access or modify this user.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function user_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_user_validation_error', $message ?? __( 'The user input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function user_create_failed() {
		return new WP_Error( 'wp_mcp_user_create_failed', __( 'Failed to create the user.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function user_update_failed() {
		return new WP_Error( 'wp_mcp_user_update_failed', __( 'Failed to update the user.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function user_delete_failed() {
		return new WP_Error( 'wp_mcp_user_delete_failed', __( 'Failed to delete the user.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function user_delete_unsupported() {
		return new WP_Error( 'wp_mcp_user_delete_unsupported', __( 'User deletion is not exposed for multisite installations by this ability.', 'wordpress-mcp-abilities' ), array( 'status' => 501 ) );
	}

	/** @return WP_Error */
	public static function user_self_promotion_denied() {
		return new WP_Error( 'wp_mcp_user_self_promotion_denied', __( 'The requested role change would remove the current user\'s ability to manage users.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function role_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_role_permission_denied', $message ?? __( 'You do not have permission to manage roles.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function role_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_role_validation_error', $message ?? __( 'The role input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function role_not_found() {
		return new WP_Error( 'wp_mcp_role_not_found', __( 'The specified role does not exist.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @return WP_Error */
	public static function role_protected() {
		return new WP_Error( 'wp_mcp_role_protected', __( 'The WordPress MCP Agent role is managed by the plugin and cannot be changed through this ability.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @return WP_Error */
	public static function role_in_use() {
		return new WP_Error( 'wp_mcp_role_in_use', __( 'The role is assigned to one or more users and cannot be deleted.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @return WP_Error */
	public static function role_create_failed() {
		return new WP_Error( 'wp_mcp_role_create_failed', __( 'Failed to create the role.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function role_delete_failed() {
		return new WP_Error( 'wp_mcp_role_delete_failed', __( 'Failed to delete the role.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function application_password_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_application_password_validation_error', $message ?? __( 'The application password input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function application_password_unavailable() {
		return new WP_Error( 'wp_mcp_application_password_unavailable', __( 'Application Passwords are unavailable for this site or user.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @return WP_Error */
	public static function application_password_create_failed() {
		return new WP_Error( 'wp_mcp_application_password_create_failed', __( 'Failed to create the application password.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function application_password_not_found() {
		return new WP_Error( 'wp_mcp_application_password_not_found', __( 'The application password does not exist.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @return WP_Error */
	public static function application_password_revoke_failed() {
		return new WP_Error( 'wp_mcp_application_password_revoke_failed', __( 'Failed to revoke application passwords.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function navigation_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_navigation_permission_denied', $message ?? __( 'You do not have permission to manage navigation.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function navigation_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_navigation_validation_error', $message ?? __( 'The navigation input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function invalid_menu() {
		return new WP_Error( 'wp_mcp_invalid_menu', __( 'The specified navigation menu does not exist.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @return WP_Error */
	public static function invalid_menu_item() {
		return new WP_Error( 'wp_mcp_invalid_menu_item', __( 'The specified menu item does not belong to this navigation menu.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @return WP_Error */
	public static function invalid_menu_location() {
		return new WP_Error( 'wp_mcp_invalid_menu_location', __( 'The specified navigation location is not registered by the active theme.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function navigation_create_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_navigation_create_failed', $message ?? __( 'Failed to create the navigation menu.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function navigation_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_navigation_update_failed', $message ?? __( 'Failed to update the navigation menu.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function navigation_delete_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_navigation_delete_failed', $message ?? __( 'Failed to delete the navigation menu.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function site_editor_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_site_editor_validation_error', $message ?? __( 'The Site Editor input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function site_editor_unsupported() {
		return new WP_Error( 'wp_mcp_site_editor_unsupported', __( 'This Site Editor entity is not supported by the active WordPress installation or theme.', 'wordpress-mcp-abilities' ), array( 'status' => 501 ) );
	}

	/** @return WP_Error */
	public static function invalid_pattern() {
		return new WP_Error( 'wp_mcp_invalid_pattern', __( 'The specified block pattern does not exist.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @return WP_Error */
	public static function invalid_navigation() {
		return new WP_Error( 'wp_mcp_invalid_navigation', __( 'The specified block navigation does not exist.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function site_editor_create_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_site_editor_create_failed', $message ?? __( 'Failed to create the Site Editor entity.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function site_editor_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_site_editor_update_failed', $message ?? __( 'Failed to update the Site Editor entity.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function site_editor_delete_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_site_editor_delete_failed', $message ?? __( 'Failed to delete the Site Editor entity.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/* ------------------------------------------------------------------
	 * Plugins
	 * ---------------------------------------------------------------- */

	/** @return WP_Error */
	public static function invalid_plugin() {
		return new WP_Error( 'wp_mcp_invalid_plugin', __( 'The specified plugin is not installed.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function plugin_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_plugin_permission_denied', $message ?? __( 'You do not have permission to manage plugins.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function plugin_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_plugin_validation_error', $message ?? __( 'The plugin input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function plugin_active_conflict() {
		return new WP_Error( 'wp_mcp_plugin_active_conflict', __( 'The plugin is active; deactivate it before deleting.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function plugin_install_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_plugin_install_failed', $message ?? __( 'Failed to install the plugin.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function plugin_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_plugin_update_failed', $message ?? __( 'Failed to update the plugin.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function plugin_delete_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_plugin_delete_failed', $message ?? __( 'Failed to delete the plugin.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function plugin_activate_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_plugin_activate_failed', $message ?? __( 'Failed to activate the plugin.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function plugin_auto_update_unavailable( $message = null ) {
		return new WP_Error( 'wp_mcp_plugin_auto_update_unavailable', $message ?? __( 'Plugin auto-updates cannot be configured on this installation.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/* ------------------------------------------------------------------
	 * Themes
	 * ---------------------------------------------------------------- */

	/** @return WP_Error */
	public static function invalid_theme() {
		return new WP_Error( 'wp_mcp_invalid_theme', __( 'The specified theme is not installed.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function theme_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_theme_permission_denied', $message ?? __( 'You do not have permission to manage themes.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function theme_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_theme_validation_error', $message ?? __( 'The theme input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function theme_active_conflict() {
		return new WP_Error( 'wp_mcp_theme_active_conflict', __( 'The theme is the currently active theme and cannot be deleted.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function theme_install_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_theme_install_failed', $message ?? __( 'Failed to install the theme.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function theme_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_theme_update_failed', $message ?? __( 'Failed to update the theme.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function theme_delete_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_theme_delete_failed', $message ?? __( 'Failed to delete the theme.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function theme_switch_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_theme_switch_failed', $message ?? __( 'Failed to switch the active theme.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function theme_auto_update_unavailable( $message = null ) {
		return new WP_Error( 'wp_mcp_theme_auto_update_unavailable', $message ?? __( 'Theme auto-updates cannot be configured on this installation.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/* ------------------------------------------------------------------
	 * Core, translation, and bulk updates
	 * ---------------------------------------------------------------- */

	/** @return WP_Error */
	public static function core_update_unavailable() {
		return new WP_Error( 'wp_mcp_core_update_unavailable', __( 'No WordPress core update is currently available.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function core_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_core_update_failed', $message ?? __( 'Failed to update WordPress core.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function translation_updates_unavailable() {
		return new WP_Error( 'wp_mcp_translation_updates_unavailable', __( 'No translation updates are currently available.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function translation_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_translation_update_failed', $message ?? __( 'Failed to update the translation packages.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @return WP_Error */
	public static function no_updates_pending() {
		return new WP_Error( 'wp_mcp_no_updates_pending', __( 'No items currently have an update available.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	/** @param string|null $message @return WP_Error */
	public static function settings_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_settings_permission_denied', $message ?? __( 'You do not have permission to manage site settings.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function settings_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_settings_validation_error', $message ?? __( 'The settings input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * A settings field that is not in the domain's allowlist. This is the
	 * error that keeps `update-option(key)` from existing by the back door:
	 * anything the plugin does not explicitly declare is refused before a
	 * single option is read or written.
	 *
	 * @param string $field Field name supplied by the caller.
	 * @return WP_Error
	 */
	public static function settings_unknown_field( $field ) {
		return new WP_Error(
			'wp_mcp_settings_unknown_field',
			sprintf(
				/* translators: %s: field name supplied by the caller */
				__( '"%s" is not an allowlisted settings field for this ability. Arbitrary WordPress option names are never accepted.', 'wordpress-mcp-abilities' ),
				(string) $field
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * An allowlisted field that this domain exposes for reading only.
	 *
	 * @param string $field Field name supplied by the caller.
	 * @return WP_Error
	 */
	public static function settings_readonly_field( $field ) {
		return new WP_Error(
			'wp_mcp_settings_readonly_field',
			sprintf(
				/* translators: %s: field name supplied by the caller */
				__( 'The "%s" setting is exposed for reading only and cannot be written through this ability.', 'wordpress-mcp-abilities' ),
				(string) $field
			),
			array( 'status' => 400 )
		);
	}

	/** @param string|null $message @return WP_Error */
	public static function settings_unsupported( $message = null ) {
		return new WP_Error( 'wp_mcp_settings_unsupported', $message ?? __( 'This setting is not writable on the current installation.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function settings_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_settings_update_failed', $message ?? __( 'Failed to update the setting.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/* ------------------------------------------------------------------
	 * Custom post types and registered post metadata (issue #11)
	 * ---------------------------------------------------------------- */

	/**
	 * The requested post type name is not registered on this site.
	 *
	 * @return WP_Error
	 */
	public static function invalid_post_type() {
		return new WP_Error( 'wp_mcp_invalid_post_type', __( 'The specified post type is not registered.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/**
	 * The post type is registered but outside this domain's reach — a core
	 * built-in, or a type owned by another explicit WordPress MCP domain, or one
	 * that never opted into REST exposure.
	 *
	 * @param string|null $message Context-specific message naming the reason.
	 * @return WP_Error
	 */
	public static function post_type_not_operable( $message = null ) {
		return new WP_Error( 'wp_mcp_post_type_not_operable', $message ?? __( 'This post type is not addressable through the custom post type abilities.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * The post type does not declare support for the feature the ability
	 * needs (`thumbnail`, `revisions`, `editor`, `excerpt`, `title`).
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function post_type_unsupported_feature( $message = null ) {
		return new WP_Error( 'wp_mcp_post_type_unsupported_feature', $message ?? __( 'This post type does not support the requested feature.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * The current user lacks the post type's own capability, resolved from
	 * that type's registration rather than from a literal post capability.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function post_type_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_post_type_permission_denied', $message ?? __( 'You do not have the capability this post type requires.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function post_type_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_post_type_validation_error', $message ?? __( 'The custom post type input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * No entry with that ID exists *in the requested post type*. Also the
	 * answer for an ID that belongs to a different type: existence in
	 * another type is never confirmed.
	 *
	 * @return WP_Error
	 */
	public static function invalid_custom_post() {
		return new WP_Error( 'wp_mcp_invalid_custom_post', __( 'The specified entry does not exist in this post type.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/**
	 * The metadata key is not registered for this post type (or globally),
	 * or is registered without `show_in_rest`.
	 *
	 * @return WP_Error
	 */
	public static function post_meta_not_registered() {
		return new WP_Error( 'wp_mcp_post_meta_not_registered', __( 'The specified post metadata key is not registered and REST-visible for this post type.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * The key is registered and REST-visible but this domain still refuses
	 * it — currently only `single => false` multi-value keys, which have no
	 * single well-defined value to read, write, or delete.
	 *
	 * @param string|null $message Context-specific message naming the reason.
	 * @return WP_Error
	 */
	public static function post_meta_not_operable( $message = null ) {
		return new WP_Error( 'wp_mcp_post_meta_not_operable', $message ?? __( 'The specified post metadata key is registered but not operable through these abilities.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * A protected metadata key (`_`-prefixed, or marked protected by a
	 * filter). Refused before the registry is consulted.
	 *
	 * @return WP_Error
	 */
	public static function post_meta_protected() {
		return new WP_Error( 'wp_mcp_post_meta_protected', __( 'Protected post metadata keys are never addressable.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @return WP_Error */
	public static function post_meta_update_failed() {
		return new WP_Error( 'wp_mcp_post_meta_update_failed', __( 'Failed to update the post metadata key.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/* ------------------------------------------------------------------
	 * System inspection, cron, cache and maintenance (issue #12)
	 * ---------------------------------------------------------------- */

	/** @param string|null $message @return WP_Error */
	public static function system_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_system_permission_denied', $message ?? __( 'You do not have permission to inspect or operate the system tools of this site.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function system_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_system_validation_error', $message ?? __( 'The system input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * A system facility WordPress does not expose on this installation
	 * (a missing Site Health class, an unavailable directory-size API, a
	 * filesystem WordPress cannot reach).
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function system_unsupported( $message = null ) {
		return new WP_Error( 'wp_mcp_system_unsupported', $message ?? __( 'This system facility is not available on the current WordPress installation.', 'wordpress-mcp-abilities' ), array( 'status' => 501 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function cron_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_cron_validation_error', $message ?? __( 'The cron input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * The cron hook is outside the policy: on the hard denylist, or with no
	 * registered listener. This is the error that keeps the cron abilities
	 * from degenerating into a generic do_action() dispatcher.
	 *
	 * @param string      $hook    Hook name supplied by the caller.
	 * @param string|null $message Context-specific reason.
	 * @return WP_Error
	 */
	public static function cron_hook_denied( $hook, $message = null ) {
		return new WP_Error(
			'wp_mcp_cron_hook_denied',
			$message ?? sprintf(
				/* translators: %s: cron hook name supplied by the caller */
				__( 'The "%s" cron hook is not operable through these abilities.', 'wordpress-mcp-abilities' ),
				(string) $hook
			),
			array( 'status' => 403 )
		);
	}

	/** @return WP_Error */
	public static function cron_event_not_found() {
		return new WP_Error( 'wp_mcp_cron_event_not_found', __( 'No scheduled cron event matches the requested hook and arguments.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function cron_schedule_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_cron_schedule_failed', $message ?? __( 'Failed to schedule the cron event.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function cron_unschedule_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_cron_unschedule_failed', $message ?? __( 'Failed to unschedule the cron event.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function maintenance_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_maintenance_validation_error', $message ?? __( 'The maintenance input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function maintenance_unsupported( $message = null ) {
		return new WP_Error( 'wp_mcp_maintenance_unsupported', $message ?? __( 'This maintenance operation is not available on the current WordPress installation.', 'wordpress-mcp-abilities' ), array( 'status' => 501 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function maintenance_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_maintenance_failed', $message ?? __( 'The maintenance operation failed.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/* ------------------------------------------------------------------
	 * Content and settings import / export (issue #12)
	 * ---------------------------------------------------------------- */

	/** @param string|null $message @return WP_Error */
	public static function export_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_export_validation_error', $message ?? __( 'The export input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function export_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_export_failed', $message ?? __( 'Failed to generate the export.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/**
	 * The generated export is larger than the response limit. The caller is
	 * told to narrow the filters rather than handed a truncated, unusable
	 * WXR document.
	 *
	 * @param int $max_bytes Maximum accepted size.
	 * @return WP_Error
	 */
	public static function export_too_large( $max_bytes ) {
		return new WP_Error(
			'wp_mcp_export_too_large',
			sprintf(
				/* translators: %d: maximum export size in bytes */
				__( 'The export exceeds the %d byte response limit. Narrow it with the content, author, category, status or date filters.', 'wordpress-mcp-abilities' ),
				(int) $max_bytes
			),
			array( 'status' => 413 )
		);
	}

	/** @param string|null $message @return WP_Error */
	public static function import_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_import_validation_error', $message ?? __( 'The import input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * The WXR document carries a serialized PHP object in a metadata value.
	 *
	 * The WordPress Importer runs every `<wp:postmeta>`/`<wp:commentmeta>`
	 * value through `maybe_unserialize()`, so accepting one would hand an MCP
	 * caller a PHP object-injection primitive — an indirect equivalent of the
	 * arbitrary-code execution this plugin never exposes (epic #1, issue #15).
	 *
	 * @since 0.15.0
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function import_unsafe_meta( $message = null ) {
		return new WP_Error( 'wp_mcp_import_unsafe_meta', $message ?? __( 'The WXR document contains a serialized PHP object in a metadata value, which this plugin never imports.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * WXR import is unavailable because WordPress core ships no importer:
	 * the official WordPress Importer plugin is not installed and active.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function import_unsupported( $message = null ) {
		return new WP_Error( 'wp_mcp_import_unsupported', $message ?? __( 'WordPress core provides no WXR importer; the official WordPress Importer plugin must be installed and active.', 'wordpress-mcp-abilities' ), array( 'status' => 501 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function import_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_import_failed', $message ?? __( 'The import failed.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/* ------------------------------------------------------------------
	 * Personal data privacy requests (issue #12)
	 * ---------------------------------------------------------------- */

	/** @param string|null $message @return WP_Error */
	public static function privacy_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_privacy_permission_denied', $message ?? __( 'You do not have permission to manage personal data requests.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function privacy_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_privacy_validation_error', $message ?? __( 'The privacy request input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function privacy_request_not_found() {
		return new WP_Error( 'wp_mcp_privacy_request_not_found', __( 'The specified personal data request does not exist.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/**
	 * The request exists but is in a status the operation cannot act on
	 * (processing an already-completed request, erasing through an export
	 * request, and so on).
	 *
	 * @param string|null $message Context-specific reason.
	 * @return WP_Error
	 */
	public static function privacy_request_invalid_state( $message = null ) {
		return new WP_Error( 'wp_mcp_privacy_request_invalid_state', $message ?? __( 'The personal data request is not in a state that allows this operation.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function privacy_request_create_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_privacy_request_create_failed', $message ?? __( 'Failed to create the personal data request.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function privacy_request_email_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_privacy_request_email_failed', $message ?? __( 'Failed to send the personal data request confirmation email.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function privacy_process_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_privacy_process_failed', $message ?? __( 'Failed to process the personal data request.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function privacy_delete_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_privacy_delete_failed', $message ?? __( 'Failed to delete the personal data request.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/* ------------------------------------------------------------------
	 * Multisite / network administration (issue #13)
	 * ---------------------------------------------------------------- */

	/**
	 * The installation is not a WordPress multisite network.
	 *
	 * This is the single, stable answer every network ability gives on a
	 * single-site installation: the graceful degradation issue #13 requires,
	 * rather than a fatal error, a misleading permission failure, or a
	 * silently empty success.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function network_unsupported( $message = null ) {
		return new WP_Error(
			'wp_mcp_network_unsupported',
			$message ?? __( 'This WordPress installation is not a multisite network, so network administration abilities are unavailable.', 'wordpress-mcp-abilities' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * The current user lacks the network capability the operation requires.
	 *
	 * Deliberately distinct from the per-site permission errors: being an
	 * administrator of a site never implies Super Admin.
	 *
	 * @param string|null $message Context-specific message.
	 * @return WP_Error
	 */
	public static function network_permission_denied( $message = null ) {
		return new WP_Error( 'wp_mcp_network_permission_denied', $message ?? __( 'You do not have permission to administer this network.', 'wordpress-mcp-abilities' ), array( 'status' => 403 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function network_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_network_validation_error', $message ?? __( 'The network input is invalid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	public static function invalid_network_site() {
		return new WP_Error( 'wp_mcp_invalid_network_site', __( 'The specified site does not exist on this network.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/**
	 * The target exists but the operation is refused for a structural
	 * reason: the main site of the network, a Super Admin, a theme still
	 * active on the main site, a user that is not a member of the site.
	 *
	 * @param string|null $message Context-specific reason.
	 * @return WP_Error
	 */
	public static function network_conflict( $message = null ) {
		return new WP_Error( 'wp_mcp_network_conflict', $message ?? __( 'The requested network operation conflicts with the current state of the network.', 'wordpress-mcp-abilities' ), array( 'status' => 409 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function network_site_create_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_network_site_create_failed', $message ?? __( 'Failed to create the site.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function network_site_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_network_site_update_failed', $message ?? __( 'Failed to update the site.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function network_site_delete_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_network_site_delete_failed', $message ?? __( 'Failed to delete the site.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function network_user_create_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_network_user_create_failed', $message ?? __( 'Failed to create the network user.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function network_user_delete_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_network_user_delete_failed', $message ?? __( 'Failed to delete the network user.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/** @param string|null $message @return WP_Error */
	public static function network_update_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_network_update_failed', $message ?? __( 'The network update operation failed.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/* ==================================================================
	 * Integrations (issue #14)
	 * ================================================================ */

	/**
	 * The requested integration slug is not one this plugin knows about.
	 *
	 * @return WP_Error
	 */
	public static function integration_unknown() {
		return new WP_Error( 'wp_mcp_integration_unknown', __( 'The specified integration does not exist.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/**
	 * The integration exists but its plugin is not available on this site.
	 *
	 * @param string|null $message Context-specific reason.
	 * @return WP_Error
	 */
	public static function integration_unavailable( $message = null ) {
		return new WP_Error( 'wp_mcp_integration_unavailable', $message ?? __( 'The requested integration is not available on this site.', 'wordpress-mcp-abilities' ), array( 'status' => 501 ) );
	}

	/**
	 * The object addressed inside a third-party plugin does not exist.
	 *
	 * @param string|null $message Context-specific reason.
	 * @return WP_Error
	 */
	public static function integration_object_not_found( $message = null ) {
		return new WP_Error( 'wp_mcp_integration_object_not_found', $message ?? __( 'The specified object does not exist in the integrated plugin.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}

	/**
	 * The third-party plugin refused, or answered with something unusable.
	 *
	 * @param string|null $message Context-specific reason.
	 * @return WP_Error
	 */
	public static function integration_failed( $message = null ) {
		return new WP_Error( 'wp_mcp_integration_failed', $message ?? __( 'The integrated plugin could not complete the operation.', 'wordpress-mcp-abilities' ), array( 'status' => 500 ) );
	}

	/* ==================================================================
	 * Discovery (issue #16)
	 * ================================================================ */

	/**
	 * A discovery input was malformed or outside its documented vocabulary.
	 *
	 * @since 0.16.0
	 *
	 * @param string|null $message Context-specific reason.
	 * @return WP_Error
	 */
	public static function discovery_validation_error( $message = null ) {
		return new WP_Error( 'wp_mcp_discovery_validation_error', $message ?? __( 'The discovery request is not valid.', 'wordpress-mcp-abilities' ), array( 'status' => 400 ) );
	}

	/**
	 * This WordPress installation does not expose the registry the ability
	 * describes.
	 *
	 * @since 0.16.0
	 *
	 * @param string|null $message Context-specific reason.
	 * @return WP_Error
	 */
	public static function discovery_unsupported( $message = null ) {
		return new WP_Error( 'wp_mcp_discovery_unsupported', $message ?? __( 'This WordPress installation does not expose the requested registry.', 'wordpress-mcp-abilities' ), array( 'status' => 501 ) );
	}

	/**
	 * The requested block type is not registered on this site.
	 *
	 * @since 0.16.0
	 *
	 * @return WP_Error
	 */
	public static function invalid_block_type() {
		return new WP_Error( 'wp_mcp_invalid_block_type', __( 'The specified block type is not registered.', 'wordpress-mcp-abilities' ), array( 'status' => 404 ) );
	}
}
