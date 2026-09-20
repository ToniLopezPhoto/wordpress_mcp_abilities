<?php
/**
 * WordPress MCP Abilities — Post callbacks.
 *
 * Execute callbacks for list-posts, get-post, create-post,
 * update-post and publish-post abilities.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Posts
 */
class WP_MCP_Posts {

	/* ------------------------------------------------------------------
	 * list-posts
	 * ---------------------------------------------------------------- */

	/**
	 * List posts visible to the current user.
	 *
	 * - Published posts from any author are always returned.
	 * - Draft / pending posts are restricted to the current user.
	 * - Private / trash / other non-public statuses are never returned.
	 *
	 * @param array $input {
	 *     @type string $status   Optional. 'publish', 'draft', or 'pending'. Default 'publish'.
	 *     @type string $search   Optional. Search keyword.
	 *     @type int    $page     Optional. Page number (min 1). Default 1.
	 *     @type int    $per_page Optional. Results per page (1–50). Default 10.
	 * }
	 * @return array|WP_Error
	 */
	public static function list_posts( $input ) {
		// --- Validate / sanitise inputs --------------------------------
		$allowed_statuses = array( 'publish', 'draft', 'pending' );
		$status           = 'publish';
		if ( ! empty( $input['status'] ) && in_array( $input['status'], $allowed_statuses, true ) ) {
			$status = $input['status'];
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input );

		// --- Build WP_Query args ---------------------------------------
		$args = array(
			'post_type'      => 'post',
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'perm'           => 'readable', // Belt-and-suspenders.
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// For non-public statuses, force author to current user to
		// prevent leaking other users' drafts or pending posts.
		if ( 'publish' !== $status ) {
			$args['author'] = get_current_user_id();
		}

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		$query = new WP_Query( $args );
		$posts = array();

		foreach ( $query->posts as $post ) {
			// Defensive post-filter: skip any post the user cannot read.
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$posts[] = self::format_post_summary( $post );
		}

		return array(
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/* ------------------------------------------------------------------
	 * get-post
	 * ---------------------------------------------------------------- */

	/**
	 * Get a single post by ID.
	 *
	 * Verifies post_type === 'post' and read_post capability.
	 *
	 * @param array $input { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	public static function get_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_read( $post_id, 'post' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return self::format_post_detail( $post );
	}

	/* ------------------------------------------------------------------
	 * create-post
	 * ---------------------------------------------------------------- */

	/**
	 * Create a new draft post owned by the current user.
	 *
	 * @param array $input {
	 *     @type string $title      Required.
	 *     @type string $content    Required.
	 *     @type string $excerpt    Optional.
	 *     @type int[]  $categories Optional. Array of category term IDs.
	 *     @type int[]  $tags       Optional. Array of tag term IDs.
	 * }
	 * @return array|WP_Error
	 */
	public static function create_post( $input ) {
		// --- Required fields -------------------------------------------
		if ( empty( $input['title'] ) ) {
			return WP_MCP_Errors::validation_error( __( 'Title is required.', 'wordpress-mcp-abilities' ) );
		}
		if ( empty( $input['content'] ) ) {
			return WP_MCP_Errors::validation_error( __( 'Content is required.', 'wordpress-mcp-abilities' ) );
		}

		// --- Validate taxonomy terms -----------------------------------
		$categories = null;
		if ( isset( $input['categories'] ) ) {
			$categories = WP_MCP_Permissions::validate_taxonomy_terms( $input['categories'], 'category' );
			if ( is_wp_error( $categories ) ) {
				return $categories;
			}
		}

		$tags = null;
		if ( isset( $input['tags'] ) ) {
			$tags = WP_MCP_Permissions::validate_taxonomy_terms( $input['tags'], 'post_tag' );
			if ( is_wp_error( $tags ) ) {
				return $tags;
			}
		}

		// --- Insert post -----------------------------------------------
		$post_data = array(
			'post_title'   => sanitize_text_field( $input['title'] ),
			'post_content' => wp_kses_post( $input['content'] ),
			'post_excerpt' => ! empty( $input['excerpt'] ) ? wp_kses_post( $input['excerpt'] ) : '',
			'post_type'    => 'post',    // Always internal.
			'post_status'  => 'draft',   // Always internal.
			'post_author'  => get_current_user_id(), // Always internal.
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-post', 0, false, 'wp_mcp_create_failed' );
			return WP_MCP_Errors::create_failed();
		}

		// --- Assign taxonomy terms -------------------------------------
		if ( null !== $categories ) {
			wp_set_post_categories( $post_id, $categories );
		}
		if ( null !== $tags ) {
			wp_set_post_tags( $post_id, $tags );
		}

		WP_MCP_Audit::log( 'wp-mcp/create-post', $post_id, true );

		$post = get_post( $post_id );

		return self::format_post_summary( $post );
	}

	/* ------------------------------------------------------------------
	 * update-post
	 * ---------------------------------------------------------------- */

	/**
	 * Update a post owned by the current user.
	 *
	 * Only title, content, excerpt, categories and tags may be modified.
	 * post_author, post_type, post_status, post_name/slug are never
	 * accepted from MCP input.
	 *
	 * @param array $input {
	 *     @type int    $post_id    Required.
	 *     @type string $title      Optional.
	 *     @type string $content    Optional.
	 *     @type string $excerpt    Optional.
	 *     @type int[]  $categories Optional.
	 *     @type int[]  $tags       Optional.
	 * }
	 * @return array|WP_Error
	 */
	public static function update_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_mutation( $post_id );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-post', absint( $post_id ), false, $post->get_error_code() );
			return $post;
		}

		// --- Build update array (only allowed fields) ------------------
		$update = array( 'ID' => $post->ID );
		$has_changes = false;

		if ( isset( $input['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $input['title'] );
			$has_changes = true;
		}
		if ( isset( $input['content'] ) ) {
			$update['post_content'] = wp_kses_post( $input['content'] );
			$has_changes = true;
		}
		if ( isset( $input['excerpt'] ) ) {
			$update['post_excerpt'] = wp_kses_post( $input['excerpt'] );
			$has_changes = true;
		}

		// --- Validate and assign taxonomy terms ------------------------
		if ( isset( $input['categories'] ) ) {
			$categories = WP_MCP_Permissions::validate_taxonomy_terms( $input['categories'], 'category' );
			if ( is_wp_error( $categories ) ) {
				WP_MCP_Audit::log( 'wp-mcp/update-post', $post->ID, false, $categories->get_error_code() );
				return $categories;
			}
			$has_changes = true;
		}

		if ( isset( $input['tags'] ) ) {
			$tags = WP_MCP_Permissions::validate_taxonomy_terms( $input['tags'], 'post_tag' );
			if ( is_wp_error( $tags ) ) {
				WP_MCP_Audit::log( 'wp-mcp/update-post', $post->ID, false, $tags->get_error_code() );
				return $tags;
			}
			$has_changes = true;
		}

		if ( ! $has_changes ) {
			WP_MCP_Audit::log( 'wp-mcp/update-post', $post->ID, true );
			return self::format_post_summary( $post );
		}

		// --- Apply update ----------------------------------------------
		$result = wp_update_post( $update, true );

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-post', $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed();
		}

		// Taxonomy assignments (after post update).
		if ( isset( $categories ) ) {
			wp_set_post_categories( $post->ID, $categories );
		}
		if ( isset( $tags ) ) {
			wp_set_post_tags( $post->ID, $tags );
		}

		WP_MCP_Audit::log( 'wp-mcp/update-post', $post->ID, true );

		return self::format_post_summary( get_post( $post->ID ) );
	}

	/* ------------------------------------------------------------------
	 * publish-post
	 * ---------------------------------------------------------------- */

	/**
	 * Publish a draft post owned by the current user.
	 *
	 * Idempotent: if the post is already published the method returns
	 * success without modifying the post.
	 *
	 * @param array $input { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	public static function publish_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_mutation( $post_id );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( 'wp-mcp/publish-post', absint( $post_id ), false, $post->get_error_code() );
			return $post;
		}

		// Additional capability check for publishing.
		if ( ! current_user_can( 'publish_posts' ) ) {
			WP_MCP_Audit::log( 'wp-mcp/publish-post', $post->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to publish posts.', 'wordpress-mcp-abilities' ) );
		}

		// Idempotent: already published — return success without changes.
		if ( 'publish' === $post->post_status ) {
			WP_MCP_Audit::log( 'wp-mcp/publish-post', $post->ID, true );
			return self::format_publish_result( $post );
		}

		// --- Publish ---------------------------------------------------
		$result = wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/publish-post', $post->ID, false, 'wp_mcp_publish_failed' );
			return WP_MCP_Errors::publish_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/publish-post', $post->ID, true );

		return self::format_publish_result( get_post( $post->ID ) );
	}

	/* ------------------------------------------------------------------
	 * unpublish-post / schedule-post / change-post-status
	 * ---------------------------------------------------------------- */

	/**
	 * Unpublish a published post back to draft.
	 *
	 * @param array $input { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	public static function unpublish_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		return WP_MCP_Content_Lifecycle::unpublish( $post_id, 'post', 'wp-mcp/unpublish-post' );
	}

	/**
	 * Schedule a post for future publication.
	 *
	 * @param array $input { @type int $post_id Required. @type string $date Required. }
	 * @return array|WP_Error
	 */
	public static function schedule_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$date    = isset( $input['date'] ) ? $input['date'] : '';
		return WP_MCP_Content_Lifecycle::schedule( $post_id, 'post', $date, 'wp-mcp/schedule-post' );
	}

	/**
	 * Transition a post between draft and pending review.
	 *
	 * Deliberately narrow: draft/pending -> publish is publish-post,
	 * publish -> draft is unpublish-post, *-> future is schedule-post,
	 * *-> trash is trash-post. This ability only covers draft <-> pending.
	 *
	 * @param array $input { @type int $post_id Required. @type string $status Required. 'draft' or 'pending'. }
	 * @return array|WP_Error
	 */
	public static function change_post_status( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_mutation( $post_id, 'post' );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( 'wp-mcp/change-post-status', absint( $post_id ), false, $post->get_error_code() );
			return $post;
		}

		$allowed = array( 'draft', 'pending' );

		if ( ! in_array( $post->post_status, $allowed, true ) ) {
			WP_MCP_Audit::log( 'wp-mcp/change-post-status', $post->ID, false, 'wp_mcp_invalid_status_transition' );
			return WP_MCP_Errors::invalid_status_transition( __( 'This ability only transitions between draft and pending; the post is not currently in either state.', 'wordpress-mcp-abilities' ) );
		}

		$status = isset( $input['status'] ) ? $input['status'] : '';
		if ( ! in_array( $status, $allowed, true ) ) {
			WP_MCP_Audit::log( 'wp-mcp/change-post-status', $post->ID, false, 'wp_mcp_validation_error' );
			return WP_MCP_Errors::validation_error( __( 'status must be either draft or pending.', 'wordpress-mcp-abilities' ) );
		}

		if ( $status === $post->post_status ) {
			WP_MCP_Audit::log( 'wp-mcp/change-post-status', $post->ID, true );
			return self::format_post_summary( $post );
		}

		$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => $status ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/change-post-status', $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to change status.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/change-post-status', $post->ID, true );
		return self::format_post_summary( get_post( $post->ID ) );
	}

	/* ------------------------------------------------------------------
	 * trash-post / restore-post / delete-post-permanently
	 * ---------------------------------------------------------------- */

	/**
	 * Move a post to trash.
	 *
	 * @param array $input { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	public static function trash_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		return WP_MCP_Content_Lifecycle::trash( $post_id, 'post', 'wp-mcp/trash-post' );
	}

	/**
	 * Restore a post from trash.
	 *
	 * @param array $input { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	public static function restore_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		return WP_MCP_Content_Lifecycle::restore( $post_id, 'post', 'wp-mcp/restore-post' );
	}

	/**
	 * Permanently delete a post, bypassing trash.
	 *
	 * @param array $input { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	public static function delete_post_permanently( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		return WP_MCP_Content_Lifecycle::delete_permanently( $post_id, 'post', 'wp-mcp/delete-post-permanently' );
	}

	/* ------------------------------------------------------------------
	 * change-post-author / update-post-slug
	 * ---------------------------------------------------------------- */

	/**
	 * Reassign a post to a different author.
	 *
	 * @param array $input { @type int $post_id Required. @type int $new_author_id Required. }
	 * @return array|WP_Error
	 */
	public static function change_post_author( $input ) {
		$post_id       = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$new_author_id = isset( $input['new_author_id'] ) ? $input['new_author_id'] : 0;
		return WP_MCP_Content_Lifecycle::change_author( $post_id, 'post', $new_author_id, 'wp-mcp/change-post-author' );
	}

	/**
	 * Change a post's slug.
	 *
	 * @param array $input { @type int $post_id Required. @type string $slug Required. }
	 * @return array|WP_Error
	 */
	public static function update_post_slug( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$slug    = isset( $input['slug'] ) ? $input['slug'] : '';
		return WP_MCP_Content_Lifecycle::update_slug( $post_id, 'post', $slug, 'wp-mcp/update-post-slug' );
	}

	/* ------------------------------------------------------------------
	 * stick-post / unstick-post / set-post-password
	 * ---------------------------------------------------------------- */

	/**
	 * Mark a post as sticky (pinned to the front of the blog listing).
	 *
	 * Requires edit_others_posts (not just edit_post on this post) because
	 * sticking affects what every site visitor sees, not just this object
	 * -- same criterion WP-admin uses to show the "Stick this post" option.
	 *
	 * @param array $input { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	public static function stick_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_mutation( $post_id, 'post' );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( 'wp-mcp/stick-post', absint( $post_id ), false, $post->get_error_code() );
			return $post;
		}

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			WP_MCP_Audit::log( 'wp-mcp/stick-post', $post->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'Sticking a post requires the capability to edit others\' content.', 'wordpress-mcp-abilities' ) );
		}

		if ( is_sticky( $post->ID ) ) {
			WP_MCP_Audit::log( 'wp-mcp/stick-post', $post->ID, true );
			return self::format_post_summary( $post );
		}

		stick_post( $post->ID ); // WordPress core function, not a recursive call.

		WP_MCP_Audit::log( 'wp-mcp/stick-post', $post->ID, true );
		return self::format_post_summary( get_post( $post->ID ) );
	}

	/**
	 * Remove a post's sticky flag.
	 *
	 * @param array $input { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	public static function unstick_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_mutation( $post_id, 'post' );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( 'wp-mcp/unstick-post', absint( $post_id ), false, $post->get_error_code() );
			return $post;
		}

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			WP_MCP_Audit::log( 'wp-mcp/unstick-post', $post->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'Unsticking a post requires the capability to edit others\' content.', 'wordpress-mcp-abilities' ) );
		}

		if ( ! is_sticky( $post->ID ) ) {
			WP_MCP_Audit::log( 'wp-mcp/unstick-post', $post->ID, true );
			return self::format_post_summary( $post );
		}

		unstick_post( $post->ID ); // WordPress core function, not a recursive call.

		WP_MCP_Audit::log( 'wp-mcp/unstick-post', $post->ID, true );
		return self::format_post_summary( get_post( $post->ID ) );
	}

	/**
	 * Set or clear a post's access password.
	 *
	 * @param array $input { @type int $post_id Required. @type string $password Required. Empty string clears protection. }
	 * @return array|WP_Error
	 */
	public static function set_post_password( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_mutation( $post_id, 'post' );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-post-password', absint( $post_id ), false, $post->get_error_code() );
			return $post;
		}

		$password = isset( $input['password'] ) ? (string) $input['password'] : '';

		$result = wp_update_post( array( 'ID' => $post->ID, 'post_password' => $password ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-post-password', $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to set the password.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/set-post-password', $post->ID, true );
		return self::format_post_summary( get_post( $post->ID ) );
	}

	/* ------------------------------------------------------------------
	 * list-post-revisions / get-post-revision / restore-post-revision / get-post-autosave
	 * ---------------------------------------------------------------- */

	/**
	 * List revisions for a post.
	 *
	 * @param array $input { @type int $post_id Required. @type int $page Optional. @type int $per_page Optional. }
	 * @return array|WP_Error
	 */
	public static function list_post_revisions( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		return WP_MCP_Content_Lifecycle::list_revisions( $post_id, 'post', $input );
	}

	/**
	 * Get a single post revision's full content.
	 *
	 * @param array $input { @type int $post_id Required. @type int $revision_id Required. }
	 * @return array|WP_Error
	 */
	public static function get_post_revision( $input ) {
		$post_id     = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$revision_id = isset( $input['revision_id'] ) ? $input['revision_id'] : 0;
		return WP_MCP_Content_Lifecycle::get_revision( $post_id, 'post', $revision_id );
	}

	/**
	 * Restore a post to a previous revision's content.
	 *
	 * @param array $input { @type int $post_id Required. @type int $revision_id Required. }
	 * @return array|WP_Error
	 */
	public static function restore_post_revision( $input ) {
		$post_id     = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$revision_id = isset( $input['revision_id'] ) ? $input['revision_id'] : 0;
		return WP_MCP_Content_Lifecycle::restore_revision( $post_id, 'post', $revision_id, 'wp-mcp/restore-post-revision' );
	}

	/**
	 * Get the current user's most recent autosave for a post, if any.
	 *
	 * WordPress keeps at most one active autosave per user per post — this
	 * is not a list of multiple autosaves, that API doesn't exist in core.
	 *
	 * @param array $input { @type int $post_id Required. }
	 * @return array|WP_Error
	 */
	public static function get_post_autosave( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_mutation( $post_id, 'post' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		/*
		 * The $user_id argument is load-bearing, not optional: called without
		 * it, wp_get_post_autosave() drops the post_author restriction and
		 * returns the newest autosave by *any* user. Two editors who both hold
		 * edit_post on the same post would then read each other's unsaved
		 * drafts through this ability. Scope it to the caller.
		 */
		$autosave = wp_get_post_autosave( $post->ID, get_current_user_id() );
		if ( ! $autosave ) {
			return array( 'exists' => false );
		}

		return array(
			'exists'   => true,
			'id'       => (int) $autosave->ID,
			'author'   => (int) $autosave->post_author,
			'title'    => $autosave->post_title,
			'content'  => $autosave->post_content,
			'excerpt'  => $autosave->post_excerpt,
			'date'     => $autosave->post_date,
			'modified' => $autosave->post_modified,
		);
	}

	/* ------------------------------------------------------------------
	 * duplicate-post / bulk-trash-posts
	 * ---------------------------------------------------------------- */

	/**
	 * Duplicate a post as a new draft owned by the current user.
	 *
	 * Copies title, content, excerpt, categories, tags, and featured
	 * image. Never copies the source post's author -- the duplicate is
	 * always authored by whoever calls this, same as create-post.
	 *
	 * @param array $input { @type int $post_id Required. ID of the post to duplicate. }
	 * @return array|WP_Error
	 */
	public static function duplicate_post( $input ) {
		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		$source  = WP_MCP_Permissions::validate_post_for_read( $post_id, 'post' );
		if ( is_wp_error( $source ) ) {
			WP_MCP_Audit::log( 'wp-mcp/duplicate-post', absint( $post_id ), false, $source->get_error_code() );
			return $source;
		}

		$categories = wp_get_post_categories( $source->ID, array( 'fields' => 'ids' ) );
		$tags       = wp_get_post_tags( $source->ID, array( 'fields' => 'ids' ) );

		$post_data = array(
			'post_title'   => $source->post_title,
			'post_content' => $source->post_content,
			'post_excerpt' => $source->post_excerpt,
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(), // Never copies the source author.
		);

		$new_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $new_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/duplicate-post', 0, false, 'wp_mcp_create_failed' );
			return WP_MCP_Errors::create_failed();
		}

		if ( ! empty( $categories ) ) {
			wp_set_post_categories( $new_id, $categories );
		}
		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $new_id, $tags );
		}

		$thumbnail_id = get_post_thumbnail_id( $source->ID );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $new_id, $thumbnail_id );
		}

		WP_MCP_Audit::log( 'wp-mcp/duplicate-post', $new_id, true );

		return self::format_post_summary( get_post( $new_id ) );
	}

	/**
	 * Trash up to 20 posts in a single request.
	 *
	 * Reuses trash_post() per item, so validation/idempotency/auditing
	 * are identical to the singular ability. Never aborts the whole
	 * batch for one failure -- every item is attempted and reported
	 * independently.
	 *
	 * @param array $input { @type int[] $post_ids Required. Max 20 IDs. }
	 * @return array|WP_Error
	 */
	public static function bulk_trash_posts( $input ) {
		$max = 20;
		$ids = isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ? $input['post_ids'] : array();

		if ( count( $ids ) > $max ) {
			WP_MCP_Audit::log( 'wp-mcp/bulk-trash-posts', 0, false, 'wp_mcp_bulk_limit_exceeded' );
			return WP_MCP_Errors::bulk_limit_exceeded( $max );
		}

		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );

		$results = array();
		$trashed = 0;
		$failed  = 0;

		foreach ( $ids as $id ) {
			$result = self::trash_post( array( 'post_id' => $id ) );
			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'id'         => $id,
					'success'    => false,
					'error_code' => $result->get_error_code(),
				);
				++$failed;
			} else {
				$results[] = array(
					'id'      => $id,
					'success' => true,
				);
				++$trashed;
			}
		}

		WP_MCP_Audit::log( 'wp-mcp/bulk-trash-posts', 0, true );

		return array(
			'results' => $results,
			'trashed' => $trashed,
			'failed'  => $failed,
		);
	}

	/* ------------------------------------------------------------------
	 * Formatters (private)
	 * ---------------------------------------------------------------- */

	/**
	 * Format a post for list/create/update responses.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function format_post_summary( $post ) {
		return array(
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'excerpt'  => $post->post_excerpt,
			'author'   => (int) $post->post_author,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'link'     => get_permalink( $post->ID ),
		);
	}

	/**
	 * Format a post for the get-post response (includes content).
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function format_post_detail( $post ) {
		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'ids' ) );
		$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );

		return array(
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'status'         => $post->post_status,
			'author'         => (int) $post->post_author,
			'categories'     => is_array( $categories ) ? array_map( 'intval', $categories ) : array(),
			'tags'           => is_array( $tags ) ? array_map( 'intval', $tags ) : array(),
			'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'link'           => get_permalink( $post->ID ),
		);
	}

	/**
	 * Format a post for the publish-post response.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function format_publish_result( $post ) {
		return array(
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'status'         => $post->post_status,
			'published_date' => $post->post_date,
			'modified'       => $post->post_modified,
			'link'           => get_permalink( $post->ID ),
		);
	}
}
