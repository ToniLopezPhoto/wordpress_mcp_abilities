<?php
/**
 * WordPress MCP Abilities — Comment callbacks.
 *
 * Uses WordPress comment APIs for querying, creation, moderation, trash,
 * restoration, and deletion. Sensitive fields are never returned to a
 * non-moderator and comment identity is always taken from the current user
 * for creation abilities.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Comments
 */
class WP_MCP_Comments {

	/** Maximum comments in one bulk request. */
	const MAX_BULK_COMMENTS = 20;

	/** Maximum comments returned per page. */
	const MAX_COMMENTS_PER_PAGE = 50;

	/**
	 * List readable comments with explicit filters.
	 *
	 * @param array $input Query filters.
	 * @return array|WP_Error
	 */
	public static function list_comments( $input ) {
		$status = self::requested_status( isset( $input['status'] ) ? $input['status'] : 'approve' );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		if ( 'approve' !== $status && ! current_user_can( 'moderate_comments' ) ) {
			return WP_MCP_Errors::comment_permission_denied( __( 'Moderation capability is required to list non-public comments.', 'wordpress-mcp-abilities' ) );
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 20, self::MAX_COMMENTS_PER_PAGE );
		$args = array(
			'status'                 => 'all' === $status ? 'all' : $status,
			'type'                   => 'comment',
			'number'                 => $per_page,
			'offset'                 => ( $page - 1 ) * $per_page,
			'orderby'                => 'comment_date_gmt',
			'order'                  => 'DESC',
			'update_comment_meta_cache' => false,
		);

		$filters = self::query_filters( $input );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}
		$args = array_merge( $args, $filters );

		// wordpress-stubs types get_comments() as never returning WP_Error, but core's
		// own docs don't guarantee that for every filtered query shape; kept as
		// defense-in-depth against a future core change rather than asserted dead code.
		$comments = get_comments( $args );
		if ( is_wp_error( $comments ) ) { // @phpstan-ignore-line
			return WP_MCP_Errors::comment_query_failed( $comments->get_error_message() );
		}
		$total_args          = $args;
		$total_args['count'] = true;
		$total_args['number'] = 0;
		$total_args['offset'] = 0;
		$total = get_comments( $total_args );
		if ( is_wp_error( $total ) ) { // @phpstan-ignore-line
			return WP_MCP_Errors::comment_query_failed( $total->get_error_message() );
		}

		$items = array();
		foreach ( $comments as $comment ) {
			if ( ! self::can_read_comment( $comment ) ) {
				continue;
			}
			$items[] = self::format_comment( $comment, false );
		}

		return array(
			'comments'    => $items,
			'status'      => $status,
			'total'       => (int) $total,
			'total_pages' => $per_page > 0 ? (int) ceil( (int) $total / $per_page ) : 0,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get one readable comment.
	 *
	 * @param array $input Comment ID.
	 * @return array|WP_Error
	 */
	public static function get_comment( $input ) {
		$comment = self::validate_comment( isset( $input['comment_id'] ) ? $input['comment_id'] : 0 );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}
		if ( ! self::can_read_comment( $comment ) ) {
			return WP_MCP_Errors::comment_permission_denied( __( 'You do not have permission to read this comment.', 'wordpress-mcp-abilities' ) );
		}
		return self::format_comment( $comment, true );
	}

	/**
	 * Create a comment using the authenticated WordPress user identity.
	 *
	 * @param array $input Post ID and content.
	 * @return array|WP_Error
	 */
	public static function create_comment( $input ) {
		$post = self::validate_comment_post( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::insert_comment( $post, isset( $input['content'] ) ? $input['content'] : '', 0, 'wp-mcp/create-comment' );
	}

	/**
	 * Create a threaded reply to a readable approved/held comment.
	 *
	 * @param array $input Parent comment ID and content.
	 * @return array|WP_Error
	 */
	public static function reply_comment( $input ) {
		$parent = self::validate_comment( isset( $input['parent_comment_id'] ) ? $input['parent_comment_id'] : 0 );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		if ( ! self::can_read_comment( $parent ) ) {
			return WP_MCP_Errors::comment_permission_denied( __( 'You do not have permission to reply to this comment.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! in_array( self::comment_status( $parent ), array( 'approve', 'hold' ), true ) ) {
			return WP_MCP_Errors::comment_validation_error( __( 'Replies may only target approved or held comments.', 'wordpress-mcp-abilities' ) );
		}
		$post = self::validate_comment_post( $parent->comment_post_ID );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::insert_comment( $post, isset( $input['content'] ) ? $input['content'] : '', $parent->comment_ID, 'wp-mcp/reply-comment' );
	}

	/**
	 * Update allowlisted comment fields through edit_comment.
	 *
	 * @param array $input Comment ID and editable fields.
	 * @return array|WP_Error
	 */
	public static function update_comment( $input ) {
		$comment = self::validate_comment( isset( $input['comment_id'] ) ? $input['comment_id'] : 0 );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}
		if ( ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
			return WP_MCP_Errors::comment_permission_denied( __( 'You do not have permission to edit this comment.', 'wordpress-mcp-abilities' ) );
		}

		$update = array(
			'comment_ID'       => $comment->comment_ID,
			'comment_approved' => $comment->comment_approved,
		);
		$has_changes = false;
		if ( isset( $input['content'] ) ) {
			$content = wp_kses_post( $input['content'] );
			if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
				return WP_MCP_Errors::comment_validation_error( __( 'Comment content cannot be empty.', 'wordpress-mcp-abilities' ) );
			}
			$update['comment_content'] = $content;
			$has_changes = true;
		}

		$moderator_fields = array( 'author_name', 'author_url', 'author_email' );
		foreach ( $moderator_fields as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}
			if ( ! current_user_can( 'moderate_comments' ) ) {
				return WP_MCP_Errors::comment_permission_denied( __( 'Only a moderator may edit comment author details.', 'wordpress-mcp-abilities' ) );
			}
			$has_changes = true;
		}
		if ( isset( $input['author_name'] ) ) {
			$update['comment_author'] = sanitize_text_field( $input['author_name'] );
		}
		if ( isset( $input['author_url'] ) ) {
			$update['comment_author_url'] = esc_url_raw( $input['author_url'] );
		}
		if ( isset( $input['author_email'] ) ) {
			$email = sanitize_email( $input['author_email'] );
			if ( '' !== $email && ! is_email( $email ) ) {
				return WP_MCP_Errors::comment_validation_error( __( 'The author email address is invalid.', 'wordpress-mcp-abilities' ) );
			}
			$update['comment_author_email'] = $email;
		}

		if ( ! $has_changes ) {
			return self::format_comment( $comment, true );
		}
		$result = wp_update_comment( $update, true );
		if ( is_wp_error( $result ) || ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/update-comment', $comment->comment_ID, false, 'wp_mcp_comment_update_failed' );
			return WP_MCP_Errors::comment_update_failed( is_wp_error( $result ) ? $result->get_error_message() : null );
		}
		WP_MCP_Audit::log( 'wp-mcp/update-comment', $comment->comment_ID, true );
		return self::format_comment( get_comment( $comment->comment_ID ), true );
	}

	/** @param array $input @return array|WP_Error */
	public static function status_approve( $input ) { return self::change_status( $input, 'approve', 'wp-mcp/approve-comment' ); }
	/** @param array $input @return array|WP_Error */
	public static function status_unapprove( $input ) { return self::change_status( $input, 'unapprove', 'wp-mcp/unapprove-comment' ); }
	/** @param array $input @return array|WP_Error */
	public static function status_spam( $input ) { return self::change_status( $input, 'spam', 'wp-mcp/mark-comment-spam' ); }
	/** @param array $input @return array|WP_Error */
	public static function status_unspam( $input ) { return self::change_status( $input, 'unspam', 'wp-mcp/unspam-comment' ); }
	/** @param array $input @return array|WP_Error */
	public static function status_trash( $input ) { return self::change_status( $input, 'trash', 'wp-mcp/trash-comment' ); }
	/** @param array $input @return array|WP_Error */
	public static function status_restore( $input ) { return self::change_status( $input, 'restore', 'wp-mcp/restore-comment' ); }

	/**
	 * Permanently delete one comment. Bulk permanent deletion is excluded.
	 *
	 * @param array $input Comment ID.
	 * @return array|WP_Error
	 */
	public static function delete_comment_permanently( $input ) {
		$comment = self::validate_comment( isset( $input['comment_id'] ) ? $input['comment_id'] : 0 );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return WP_MCP_Errors::comment_permission_denied( __( 'You do not have permission to permanently delete this comment.', 'wordpress-mcp-abilities' ) );
		}
		$result = wp_delete_comment( $comment->comment_ID, true );
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-comment-permanently', $comment->comment_ID, false, 'wp_mcp_comment_delete_failed' );
			return WP_MCP_Errors::comment_delete_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/delete-comment-permanently', $comment->comment_ID, true );
		return array( 'id' => (int) $comment->comment_ID, 'deleted' => true );
	}

	/**
	 * Apply one fixed moderation action to at most 20 comments.
	 *
	 * @param array $input IDs and action.
	 * @return array|WP_Error
	 */
	public static function bulk_moderate_comments( $input ) {
		$ids = isset( $input['comment_ids'] ) && is_array( $input['comment_ids'] ) ? $input['comment_ids'] : array();
		$action = isset( $input['action'] ) ? $input['action'] : '';
		$allowed = array( 'approve', 'unapprove', 'spam', 'unspam', 'trash', 'restore' );
		if ( count( $ids ) > self::MAX_BULK_COMMENTS ) {
			return WP_MCP_Errors::bulk_limit_exceeded( self::MAX_BULK_COMMENTS );
		}
		if ( ! in_array( $action, $allowed, true ) ) {
			return WP_MCP_Errors::comment_validation_error( __( 'The requested bulk moderation action is not allowed.', 'wordpress-mcp-abilities' ) );
		}
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) || in_array( 0, $ids, true ) ) {
			return WP_MCP_Errors::comment_validation_error( __( 'At least one valid comment ID is required.', 'wordpress-mcp-abilities' ) );
		}

		$results = array();
		$processed = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			$result = self::change_status( array( 'comment_id' => $id ), $action, self::ability_for_status_action( $action ) );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'id' => $id, 'success' => false, 'error_code' => $result->get_error_code() );
				++$failed;
			} else {
				$results[] = array( 'id' => $id, 'success' => true );
				++$processed;
			}
		}
		WP_MCP_Audit::log( 'wp-mcp/bulk-moderate-comments', 0, 0 === $failed, 0 === $failed ? '' : 'wp_mcp_comment_moderation_failed' );
		return array( 'results' => $results, 'processed' => $processed, 'failed' => $failed );
	}

	/* ------------------------------------------------------------------
	 * Internal validation, mutation, formatting, and query helpers
	 * ---------------------------------------------------------------- */

	private static function requested_status( $status ) {
		$allowed = array( 'approve', 'hold', 'spam', 'trash', 'all' );
		if ( ! is_string( $status ) || ! in_array( $status, $allowed, true ) ) {
			return WP_MCP_Errors::comment_validation_error( __( 'The requested comment status is invalid.', 'wordpress-mcp-abilities' ) );
		}
		return $status;
	}

	private static function query_filters( $input ) {
		$filters = array();
		if ( isset( $input['post_id'] ) ) {
			$post_id = WP_MCP_Permissions::validate_positive_int( $input['post_id'], 'post_id' );
			if ( is_wp_error( $post_id ) || ! get_post( $post_id ) ) {
				return WP_MCP_Errors::invalid_post();
			}
			$filters['post_id'] = $post_id;
		}
		if ( isset( $input['author_id'] ) ) {
			$author_id = WP_MCP_Permissions::validate_positive_int( $input['author_id'], 'author_id' );
			if ( is_wp_error( $author_id ) ) {
				return $author_id;
			}
			$filters['user_id'] = $author_id;
		}
		if ( isset( $input['parent_id'] ) ) {
			$parent_id = absint( $input['parent_id'] );
			if ( $parent_id > 0 ) {
				$parent = self::validate_comment( $parent_id );
				if ( is_wp_error( $parent ) ) {
					return $parent;
				}
			}
			$filters['parent'] = $parent_id;
		}
		if ( ! empty( $input['search'] ) ) {
			$filters['search'] = sanitize_text_field( $input['search'] );
		}
		$date_query = array();
		foreach ( array( 'date_after' => 'after', 'date_before' => 'before' ) as $input_key => $query_key ) {
			if ( ! isset( $input[ $input_key ] ) || '' === trim( $input[ $input_key ] ) ) {
				continue;
			}
			if ( false === strtotime( $input[ $input_key ] ) ) {
				return WP_MCP_Errors::comment_validation_error( __( 'The comment date filter is invalid.', 'wordpress-mcp-abilities' ) );
			}
			$date_query[ $query_key ] = sanitize_text_field( $input[ $input_key ] );
		}
		if ( ! empty( $date_query ) ) {
			$date_query['inclusive'] = true;
			$filters['date_query'] = array( $date_query );
		}
		return $filters;
	}

	private static function validate_comment( $comment_id ) {
		$comment_id = WP_MCP_Permissions::validate_positive_int( $comment_id, 'comment_id' );
		if ( is_wp_error( $comment_id ) ) {
			return $comment_id;
		}
		$comment = get_comment( $comment_id );
		return $comment ? $comment : WP_MCP_Errors::invalid_comment();
	}

	private static function validate_comment_post( $post_id ) {
		$post_id = WP_MCP_Permissions::validate_positive_int( $post_id, 'post_id' );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return WP_MCP_Errors::invalid_post();
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return WP_MCP_Errors::comment_permission_denied( __( 'You do not have permission to comment on this post.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! comments_open( $post->ID ) ) {
			return WP_MCP_Errors::comment_validation_error( __( 'Comments are closed for this post.', 'wordpress-mcp-abilities' ) );
		}
		$status = get_post_status_object( $post->post_status );
		if ( ! $status || ( ! $status->public && 'private' !== $post->post_status ) ) {
			return WP_MCP_Errors::comment_validation_error( __( 'Comments may only be created on a public or readable private post.', 'wordpress-mcp-abilities' ) );
		}
		return $post;
	}

	private static function insert_comment( $post, $raw_content, $parent_id, $ability ) {
		$content = wp_kses_post( $raw_content );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			WP_MCP_Audit::log( $ability, $post->ID, false, 'wp_mcp_comment_validation_error' );
			return WP_MCP_Errors::comment_validation_error( __( 'Comment content is required.', 'wordpress-mcp-abilities' ) );
		}
		$user = wp_get_current_user();
		$data = array(
			'comment_post_ID'      => $post->ID,
			'comment_content'      => $content,
			'comment_parent'       => absint( $parent_id ),
			'user_id'              => get_current_user_id(),
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => $user->user_url,
		);
		$comment_id = wp_new_comment( $data, true );
		if ( is_wp_error( $comment_id ) || ! $comment_id ) {
			WP_MCP_Audit::log( $ability, $post->ID, false, 'wp_mcp_comment_create_failed' );
			return WP_MCP_Errors::comment_create_failed( is_wp_error( $comment_id ) ? $comment_id->get_error_message() : null );
		}
		WP_MCP_Audit::log( $ability, $comment_id, true );
		return self::format_comment( get_comment( $comment_id ), true );
	}

	private static function change_status( $input, $action, $ability ) {
		$comment = self::validate_comment( isset( $input['comment_id'] ) ? $input['comment_id'] : 0 );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}
		if ( ! current_user_can( 'moderate_comments' ) ) {
			WP_MCP_Audit::log( $ability, $comment->comment_ID, false, 'wp_mcp_comment_permission_denied' );
			return WP_MCP_Errors::comment_permission_denied( __( 'You do not have permission to moderate this comment.', 'wordpress-mcp-abilities' ) );
		}
		if ( 'trash' === $action && defined( 'EMPTY_TRASH_DAYS' ) && (int) EMPTY_TRASH_DAYS < 1 ) {
			WP_MCP_Audit::log( $ability, $comment->comment_ID, false, 'wp_mcp_comment_trash_unavailable' );
			return WP_MCP_Errors::comment_trash_unavailable();
		}
		$current = self::comment_status( $comment );
		$already = ( 'approve' === $action && 'approve' === $current ) || ( 'unapprove' === $action && 'hold' === $current ) || ( 'spam' === $action && 'spam' === $current ) || ( 'unspam' === $action && 'spam' !== $current ) || ( 'trash' === $action && 'trash' === $current ) || ( 'restore' === $action && 'trash' !== $current );
		if ( $already ) {
			WP_MCP_Audit::log( $ability, $comment->comment_ID, true );
			return self::format_comment( $comment, true );
		}

		$result = false;
		switch ( $action ) {
			case 'approve':
				$result = wp_set_comment_status( $comment->comment_ID, 'approve', true );
				break;
			case 'unapprove':
				$result = wp_set_comment_status( $comment->comment_ID, 'hold', true );
				break;
			case 'spam':
				$result = wp_spam_comment( $comment->comment_ID );
				break;
			case 'unspam':
				$result = wp_unspam_comment( $comment->comment_ID );
				break;
			case 'trash':
				$result = wp_trash_comment( $comment->comment_ID );
				break;
			case 'restore':
				$result = wp_untrash_comment( $comment->comment_ID );
				break;
		}
		if ( is_wp_error( $result ) || ! $result ) {
			WP_MCP_Audit::log( $ability, $comment->comment_ID, false, 'wp_mcp_comment_moderation_failed' );
			return WP_MCP_Errors::comment_moderation_failed( is_wp_error( $result ) ? $result->get_error_message() : null );
		}
		WP_MCP_Audit::log( $ability, $comment->comment_ID, true );
		return self::format_comment( get_comment( $comment->comment_ID ), true );
	}

	private static function ability_for_status_action( $action ) {
		return array(
			'approve'   => 'wp-mcp/approve-comment',
			'unapprove' => 'wp-mcp/unapprove-comment',
			'spam'      => 'wp-mcp/mark-comment-spam',
			'unspam'    => 'wp-mcp/unspam-comment',
			'trash'     => 'wp-mcp/trash-comment',
			'restore'   => 'wp-mcp/restore-comment',
		)[ $action ];
	}

	private static function can_read_comment( $comment ) {
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || ! current_user_can( 'read_post', $post->ID ) ) {
			return false;
		}
		$status = self::comment_status( $comment );
		return 'approve' === $status || current_user_can( 'moderate_comments' ) || current_user_can( 'edit_comment', $comment->comment_ID );
	}

	private static function comment_status( $comment ) {
		switch ( (string) $comment->comment_approved ) {
			case '1':
				return 'approve';
			case 'spam':
				return 'spam';
			case 'trash':
				return 'trash';
			default:
				return 'hold';
		}
	}

	private static function format_comment( $comment, $include_email ) {
		$data = array(
			'id'          => (int) $comment->comment_ID,
			'post_id'     => (int) $comment->comment_post_ID,
			'post_title'  => get_the_title( $comment->comment_post_ID ),
			'parent_id'   => (int) $comment->comment_parent,
			'status'      => self::comment_status( $comment ),
			'author_id'   => (int) $comment->user_id,
			'author_name' => $comment->comment_author,
			'author_url'  => esc_url_raw( $comment->comment_author_url ),
			'content'     => wp_kses_post( $comment->comment_content ),
			'date'        => $comment->comment_date,
			'date_gmt'    => $comment->comment_date_gmt,
			'link'        => get_comment_link( $comment ),
		);
		if ( $include_email && current_user_can( 'moderate_comments' ) ) {
			$data['author_email'] = $comment->comment_author_email;
		}
		return $data;
	}
}
