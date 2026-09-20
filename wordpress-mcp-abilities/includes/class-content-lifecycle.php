<?php
/**
 * WordPress MCP Abilities — Shared post/page lifecycle callbacks.
 *
 * Business logic shared between posts and pages for operations that are
 * type-agnostic in WordPress core (both are WP_Post objects): publish
 * state changes, scheduling, trash/restore/delete, author/slug changes,
 * and revisions. Every method is parameterized by $expected_type so it
 * can be reused for either post_type via a thin wrapper in
 * class-posts.php / class-pages.php.
 *
 * publish() is only consumed by publish-page — WP_MCP_Posts::publish_post()
 * (shipped in #2, already tested) is left untouched rather than refactored
 * to call this, to avoid touching working code for the sake of DRY purity.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Content_Lifecycle
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Content_Lifecycle {

	/* ------------------------------------------------------------------
	 * Type-aware capability resolution
	 *
	 * Every method here is shared by posts and pages, and WordPress maps
	 * `page` to its own primitive capabilities (`publish_pages`,
	 * `edit_others_pages`, `edit_pages`). Resolving them from
	 * $expected_type — rather than hardcoding the `*_posts` variants —
	 * is what keeps a role that may edit pages but was deliberately
	 * denied `publish_pages` from publishing one through this plugin.
	 *
	 * These are the type's *primitive* capabilities, resolved from its
	 * own registration so a filtered post type object is honoured; the
	 * per-object `edit_post` / `delete_post` meta-capabilities are still
	 * checked separately by WP_MCP_Permissions.
	 * ---------------------------------------------------------------- */

	/**
	 * Primitive capability required to publish content of $expected_type.
	 *
	 * @since 0.15.0
	 *
	 * @param string $expected_type 'post' or 'page'.
	 * @return string Capability name.
	 */
	private static function publish_capability( $expected_type ) {
		return self::type_capability( $expected_type, 'publish_posts', 'publish_posts' );
	}

	/**
	 * Primitive capability required to act on other users' content of $expected_type.
	 *
	 * @since 0.15.0
	 *
	 * @param string $expected_type 'post' or 'page'.
	 * @return string Capability name.
	 */
	private static function edit_others_capability( $expected_type ) {
		return self::type_capability( $expected_type, 'edit_others_posts', 'edit_others_posts' );
	}

	/**
	 * Primitive capability an author must hold to own content of $expected_type.
	 *
	 * @since 0.15.0
	 *
	 * @param string $expected_type 'post' or 'page'.
	 * @return string Capability name.
	 */
	private static function edit_capability( $expected_type ) {
		return self::type_capability( $expected_type, 'edit_posts', 'edit_posts' );
	}

	/**
	 * Read one capability off a post type's own registration.
	 *
	 * @since 0.15.0
	 *
	 * @param string $expected_type 'post' or 'page'.
	 * @param string $cap_key       Key on the type object's `cap` map.
	 * @param string $fallback      Capability to use when the type is unknown.
	 * @return string Capability name.
	 */
	private static function type_capability( $expected_type, $cap_key, $fallback ) {
		$type_object = get_post_type_object( (string) $expected_type );
		if ( ! $type_object || ! isset( $type_object->cap->{$cap_key} ) ) {
			return $fallback;
		}
		return (string) $type_object->cap->{$cap_key};
	}

	/* ------------------------------------------------------------------
	 * Publish state
	 * ---------------------------------------------------------------- */

	/**
	 * Publish a draft/pending post or page. Idempotent if already published.
	 *
	 * Used only by publish-page; WP_MCP_Posts::publish_post() is separate.
	 *
	 * @param int    $id            Raw post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param string $ability_name  Ability name for audit logging.
	 * @return array|WP_Error
	 */
	public static function publish( $id, $expected_type, $ability_name ) {
		$post = WP_MCP_Permissions::validate_post_for_mutation( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( $ability_name, absint( $id ), false, $post->get_error_code() );
			return $post;
		}

		if ( ! current_user_can( self::publish_capability( $expected_type ) ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to publish this content.', 'wordpress-mcp-abilities' ) );
		}

		if ( 'publish' === $post->post_status ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, true );
			return self::format_result( $post );
		}

		$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_publish_failed' );
			return WP_MCP_Errors::publish_failed();
		}

		WP_MCP_Audit::log( $ability_name, $post->ID, true );
		return self::format_result( get_post( $post->ID ) );
	}

	/**
	 * Unpublish a published post/page back to draft. Idempotent if already draft.
	 *
	 * @param int    $id            Raw post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param string $ability_name  Ability name for audit logging.
	 * @return array|WP_Error
	 */
	public static function unpublish( $id, $expected_type, $ability_name ) {
		$post = WP_MCP_Permissions::validate_post_for_mutation( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( $ability_name, absint( $id ), false, $post->get_error_code() );
			return $post;
		}

		if ( 'draft' === $post->post_status ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, true );
			return self::format_result( $post );
		}

		$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to unpublish.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( $ability_name, $post->ID, true );
		return self::format_result( get_post( $post->ID ) );
	}

	/**
	 * Schedule a post/page for future publication.
	 *
	 * @param int    $id            Raw post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param string $date          Raw date string from MCP input (any format strtotime() accepts).
	 * @param string $ability_name  Ability name for audit logging.
	 * @return array|WP_Error
	 */
	public static function schedule( $id, $expected_type, $date, $ability_name ) {
		$post = WP_MCP_Permissions::validate_post_for_mutation( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( $ability_name, absint( $id ), false, $post->get_error_code() );
			return $post;
		}

		if ( ! current_user_can( self::publish_capability( $expected_type ) ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to schedule this content.', 'wordpress-mcp-abilities' ) );
		}

		// strtotime()/time() both operate on Unix epoch seconds, so this
		// future-check is timezone-agnostic regardless of the input string's
		// offset. Only the stored post_date needs site-timezone conversion,
		// done below via wp_date().
		$timestamp = strtotime( (string) $date );
		if ( false === $timestamp ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_validation_error' );
			return WP_MCP_Errors::validation_error( __( 'The scheduled date could not be parsed. Use an ISO 8601 date/time.', 'wordpress-mcp-abilities' ) );
		}
		if ( $timestamp <= time() ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_validation_error' );
			return WP_MCP_Errors::validation_error( __( 'The scheduled date must be in the future.', 'wordpress-mcp-abilities' ) );
		}

		$result = wp_update_post( array(
			'ID'            => $post->ID,
			'post_status'   => 'future',
			'post_date'     => wp_date( 'Y-m-d H:i:s', $timestamp ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'edit_date'     => true,
		), true );

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to schedule.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( $ability_name, $post->ID, true );
		return self::format_result( get_post( $post->ID ) );
	}

	/* ------------------------------------------------------------------
	 * Trash / restore / delete
	 * ---------------------------------------------------------------- */

	/**
	 * Move a post/page to trash. Idempotent if already trashed.
	 *
	 * @param int    $id            Raw post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param string $ability_name  Ability name for audit logging.
	 * @return array|WP_Error
	 */
	public static function trash( $id, $expected_type, $ability_name ) {
		$post = WP_MCP_Permissions::validate_post_for_deletion( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( $ability_name, absint( $id ), false, $post->get_error_code() );
			return $post;
		}

		if ( 'trash' === $post->post_status ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, true );
			return self::format_result( $post );
		}

		$result = wp_trash_post( $post->ID );
		if ( ! $result ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_trash_failed' );
			return WP_MCP_Errors::trash_failed();
		}

		WP_MCP_Audit::log( $ability_name, $post->ID, true );
		return self::format_result( $result );
	}

	/**
	 * Restore a post/page from trash. Idempotent if not currently trashed.
	 *
	 * @param int    $id            Raw post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param string $ability_name  Ability name for audit logging.
	 * @return array|WP_Error
	 */
	public static function restore( $id, $expected_type, $ability_name ) {
		$post = WP_MCP_Permissions::validate_post_for_deletion( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( $ability_name, absint( $id ), false, $post->get_error_code() );
			return $post;
		}

		if ( 'trash' !== $post->post_status ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, true );
			return self::format_result( $post );
		}

		$result = wp_untrash_post( $post->ID );
		if ( ! $result ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_restore_failed' );
			return WP_MCP_Errors::restore_failed();
		}

		WP_MCP_Audit::log( $ability_name, $post->ID, true );
		return self::format_result( get_post( $post->ID ) );
	}

	/**
	 * Permanently delete a post/page, bypassing trash. Not idempotent —
	 * a second call on the same ID returns wp_mcp_invalid_post/_page.
	 *
	 * @param int    $id            Raw post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param string $ability_name  Ability name for audit logging.
	 * @return array|WP_Error
	 */
	public static function delete_permanently( $id, $expected_type, $ability_name ) {
		$post = WP_MCP_Permissions::validate_post_for_deletion( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( $ability_name, absint( $id ), false, $post->get_error_code() );
			return $post;
		}

		$post_id = $post->ID; // Capture before deletion — can't be re-read afterward.
		$result  = wp_delete_post( $post_id, true );
		if ( ! $result ) {
			WP_MCP_Audit::log( $ability_name, $post_id, false, 'wp_mcp_delete_failed' );
			return WP_MCP_Errors::delete_failed();
		}

		WP_MCP_Audit::log( $ability_name, $post_id, true );
		return array(
			'id'      => (int) $post_id,
			'deleted' => true,
		);
	}

	/* ------------------------------------------------------------------
	 * Attribute changes
	 * ---------------------------------------------------------------- */

	/**
	 * Reassign a post/page to a different author.
	 *
	 * Always requires edit_others_posts, regardless of the target author —
	 * mirrors WP-admin, which only shows the author dropdown to users with
	 * that capability.
	 *
	 * @param int    $id             Raw post/page ID.
	 * @param string $expected_type  'post' or 'page'.
	 * @param int    $new_author_id  Raw new author user ID.
	 * @param string $ability_name   Ability name for audit logging.
	 * @return array|WP_Error
	 */
	public static function change_author( $id, $expected_type, $new_author_id, $ability_name ) {
		$post = WP_MCP_Permissions::validate_post_for_mutation( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( $ability_name, absint( $id ), false, $post->get_error_code() );
			return $post;
		}

		if ( ! current_user_can( self::edit_others_capability( $expected_type ) ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'Changing the author requires the capability to edit others\' content.', 'wordpress-mcp-abilities' ) );
		}

		$new_author_id = WP_MCP_Permissions::validate_positive_int( $new_author_id, 'new_author_id' );
		if ( is_wp_error( $new_author_id ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, $new_author_id->get_error_code() );
			return $new_author_id;
		}

		$user = get_userdata( $new_author_id );
		if ( ! $user || ! user_can( $user, self::edit_capability( $expected_type ) ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_validation_error' );
			return WP_MCP_Errors::validation_error( __( 'The specified user does not exist or cannot author content.', 'wordpress-mcp-abilities' ) );
		}

		$result = wp_update_post( array( 'ID' => $post->ID, 'post_author' => $new_author_id ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to change the author.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( $ability_name, $post->ID, true );
		return self::format_result( get_post( $post->ID ) );
	}

	/**
	 * Change a post/page's slug. WordPress deduplicates automatically.
	 *
	 * @param int    $id            Raw post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param string $slug          Raw new slug.
	 * @param string $ability_name  Ability name for audit logging.
	 * @return array|WP_Error
	 */
	public static function update_slug( $id, $expected_type, $slug, $ability_name ) {
		$post = WP_MCP_Permissions::validate_post_for_mutation( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( $ability_name, absint( $id ), false, $post->get_error_code() );
			return $post;
		}

		if ( empty( $slug ) || ! is_string( $slug ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_validation_error' );
			return WP_MCP_Errors::validation_error( __( 'slug must be a non-empty string.', 'wordpress-mcp-abilities' ) );
		}

		$result = wp_update_post( array( 'ID' => $post->ID, 'post_name' => sanitize_title( $slug ) ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to update the slug.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( $ability_name, $post->ID, true );
		return self::format_result( get_post( $post->ID ) );
	}

	/* ------------------------------------------------------------------
	 * Revisions
	 * ---------------------------------------------------------------- */

	/**
	 * List revisions for a post/page. Read-only — not audited.
	 *
	 * @param int    $id            Raw post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param array  $input {
	 *     @type int $page     Optional. Page number (min 1). Default 1.
	 *     @type int $per_page Optional. Results per page (1-50). Default 10.
	 * }
	 * @return array|WP_Error
	 */
	public static function list_revisions( $id, $expected_type, $input ) {
		$post = WP_MCP_Permissions::validate_post_for_mutation( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input );

		$revisions = wp_get_post_revisions( $post->ID, array(
			'posts_per_page' => $per_page,
			'paged'          => $page,
		) );

		$items = array();
		foreach ( $revisions as $revision ) {
			$items[] = array(
				'id'       => (int) $revision->ID,
				'author'   => (int) $revision->post_author,
				'date'     => $revision->post_date,
				'modified' => $revision->post_modified,
			);
		}

		$total = count( wp_get_post_revisions( $post->ID, array( 'fields' => 'ids' ) ) );

		return array(
			'revisions'   => $items,
			'total'       => $total,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get a single revision's full content. Read-only — not audited.
	 *
	 * @param int    $id            Raw parent post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param int    $revision_id   Raw revision ID.
	 * @return array|WP_Error
	 */
	public static function get_revision( $id, $expected_type, $revision_id ) {
		$post = WP_MCP_Permissions::validate_post_for_mutation( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$revision = WP_MCP_Permissions::validate_revision_for_post( $revision_id, $post->ID );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		return array(
			'id'        => (int) $revision->ID,
			'parent_id' => (int) $revision->post_parent,
			'author'    => (int) $revision->post_author,
			'title'     => $revision->post_title,
			'content'   => $revision->post_content,
			'excerpt'   => $revision->post_excerpt,
			'date'      => $revision->post_date,
			'modified'  => $revision->post_modified,
		);
	}

	/**
	 * Restore a post/page to a previous revision's content. Not idempotent.
	 *
	 * @param int    $id            Raw parent post/page ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @param int    $revision_id   Raw revision ID.
	 * @param string $ability_name  Ability name for audit logging.
	 * @return array|WP_Error
	 */
	public static function restore_revision( $id, $expected_type, $revision_id, $ability_name ) {
		$post = WP_MCP_Permissions::validate_post_for_mutation( $id, $expected_type );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( $ability_name, absint( $id ), false, $post->get_error_code() );
			return $post;
		}

		$revision = WP_MCP_Permissions::validate_revision_for_post( $revision_id, $post->ID );
		if ( is_wp_error( $revision ) ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, $revision->get_error_code() );
			return $revision;
		}

		$result = wp_restore_post_revision( $revision->ID );
		if ( ! $result ) {
			WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to restore the revision.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( $ability_name, $post->ID, true );
		return self::format_result( get_post( $post->ID ) );
	}

	/* ------------------------------------------------------------------
	 * Formatters (private)
	 * ---------------------------------------------------------------- */

	/**
	 * Generic lifecycle-operation result shape, shared by every mutating
	 * method above except delete_permanently() (whose subject no longer
	 * exists after the call).
	 *
	 * @param WP_Post $post Post/page object.
	 * @return array
	 */
	private static function format_result( $post ) {
		return array(
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'link'     => get_permalink( $post->ID ),
		);
	}
}
