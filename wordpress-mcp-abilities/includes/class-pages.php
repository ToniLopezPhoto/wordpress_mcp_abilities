<?php
/**
 * WordPress MCP Abilities — Page callbacks.
 *
 * Execute callbacks for list-pages and get-page abilities.
 * Pages are read-only in v0.1.0.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Pages
 */
class WP_MCP_Pages {

	/* ------------------------------------------------------------------
	 * list-pages
	 * ---------------------------------------------------------------- */

	/**
	 * List published pages.
	 *
	 * @param array $input {
	 *     @type string $search   Optional. Search keyword.
	 *     @type int    $page     Optional. Page number (min 1). Default 1.
	 *     @type int    $per_page Optional. Results per page (1–50). Default 10.
	 * }
	 * @return array
	 */
	public static function list_pages( $input ) {
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input );

		$args = array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'perm'           => 'readable',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		$query = new WP_Query( $args );
		$pages = array();

		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$pages[] = array(
				'id'       => (int) $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'modified' => $post->post_modified,
				'link'     => get_permalink( $post->ID ),
			);
		}

		return array(
			'pages'       => $pages,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/* ------------------------------------------------------------------
	 * get-page
	 * ---------------------------------------------------------------- */

	/**
	 * Get a single page by ID.
	 *
	 * Verifies post_type === 'page' and read_post capability.
	 *
	 * @param array $input { @type int $page_id Required. }
	 * @return array|WP_Error
	 */
	public static function get_page( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_read( $page_id, 'page' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'content'  => $post->post_content,
			'status'   => $post->post_status,
			'modified' => $post->post_modified,
			'link'     => get_permalink( $post->ID ),
		);
	}

	/* ------------------------------------------------------------------
	 * create-page
	 * ---------------------------------------------------------------- */

	/**
	 * Create a new draft page owned by the current user.
	 *
	 * @param array $input {
	 *     @type string $title   Required.
	 *     @type string $content Required.
	 *     @type string $excerpt Optional.
	 * }
	 * @return array|WP_Error
	 */
	public static function create_page( $input ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to create pages.', 'wordpress-mcp-abilities' ) );
		}
		if ( empty( $input['title'] ) ) {
			return WP_MCP_Errors::validation_error( __( 'Title is required.', 'wordpress-mcp-abilities' ) );
		}
		if ( empty( $input['content'] ) ) {
			return WP_MCP_Errors::validation_error( __( 'Content is required.', 'wordpress-mcp-abilities' ) );
		}

		$post_data = array(
			'post_title'   => sanitize_text_field( $input['title'] ),
			'post_content' => wp_kses_post( $input['content'] ),
			'post_excerpt' => ! empty( $input['excerpt'] ) ? wp_kses_post( $input['excerpt'] ) : '',
			'post_type'    => 'page',    // Always internal.
			'post_status'  => 'draft',   // Always internal.
			'post_author'  => get_current_user_id(), // Always internal.
		);

		$page_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $page_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-page', 0, false, 'wp_mcp_create_failed' );
			return WP_MCP_Errors::create_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/create-page', $page_id, true );

		return self::format_page_summary( get_post( $page_id ) );
	}

	/* ------------------------------------------------------------------
	 * update-page
	 * ---------------------------------------------------------------- */

	/**
	 * Update a page the current user can edit.
	 *
	 * Only title, content and excerpt may be modified. post_author,
	 * post_type, post_status, post_name/slug are never accepted from
	 * MCP input here — use the dedicated change-author/update-page-slug
	 * abilities instead.
	 *
	 * @param array $input {
	 *     @type int    $page_id Required.
	 *     @type string $title   Optional.
	 *     @type string $content Optional.
	 *     @type string $excerpt Optional.
	 * }
	 * @return array|WP_Error
	 */
	public static function update_page( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_mutation( $page_id, 'page' );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-page', absint( $page_id ), false, $post->get_error_code() );
			return $post;
		}

		$update      = array( 'ID' => $post->ID );
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

		if ( ! $has_changes ) {
			WP_MCP_Audit::log( 'wp-mcp/update-page', $post->ID, true );
			return self::format_page_summary( $post );
		}

		$result = wp_update_post( $update, true );

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-page', $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to update page.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/update-page', $post->ID, true );

		return self::format_page_summary( get_post( $post->ID ) );
	}

	/* ------------------------------------------------------------------
	 * publish-page / unpublish-page / schedule-page
	 * ---------------------------------------------------------------- */

	/**
	 * Publish a draft/pending page. Idempotent if already published.
	 *
	 * @param array $input { @type int $page_id Required. }
	 * @return array|WP_Error
	 */
	public static function publish_page( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		return WP_MCP_Content_Lifecycle::publish( $page_id, 'page', 'wp-mcp/publish-page' );
	}

	/**
	 * Unpublish a published page back to draft.
	 *
	 * @param array $input { @type int $page_id Required. }
	 * @return array|WP_Error
	 */
	public static function unpublish_page( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		return WP_MCP_Content_Lifecycle::unpublish( $page_id, 'page', 'wp-mcp/unpublish-page' );
	}

	/**
	 * Schedule a page for future publication.
	 *
	 * @param array $input { @type int $page_id Required. @type string $date Required. }
	 * @return array|WP_Error
	 */
	public static function schedule_page( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		$date    = isset( $input['date'] ) ? $input['date'] : '';
		return WP_MCP_Content_Lifecycle::schedule( $page_id, 'page', $date, 'wp-mcp/schedule-page' );
	}

	/* ------------------------------------------------------------------
	 * trash-page / restore-page / delete-page-permanently
	 * ---------------------------------------------------------------- */

	/**
	 * Move a page to trash.
	 *
	 * @param array $input { @type int $page_id Required. }
	 * @return array|WP_Error
	 */
	public static function trash_page( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		return WP_MCP_Content_Lifecycle::trash( $page_id, 'page', 'wp-mcp/trash-page' );
	}

	/**
	 * Restore a page from trash.
	 *
	 * @param array $input { @type int $page_id Required. }
	 * @return array|WP_Error
	 */
	public static function restore_page( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		return WP_MCP_Content_Lifecycle::restore( $page_id, 'page', 'wp-mcp/restore-page' );
	}

	/**
	 * Permanently delete a page, bypassing trash.
	 *
	 * @param array $input { @type int $page_id Required. }
	 * @return array|WP_Error
	 */
	public static function delete_page_permanently( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		return WP_MCP_Content_Lifecycle::delete_permanently( $page_id, 'page', 'wp-mcp/delete-page-permanently' );
	}

	/* ------------------------------------------------------------------
	 * change-page-author / update-page-slug / update-page-attributes
	 * ---------------------------------------------------------------- */

	/**
	 * Reassign a page to a different author.
	 *
	 * @param array $input { @type int $page_id Required. @type int $new_author_id Required. }
	 * @return array|WP_Error
	 */
	public static function change_page_author( $input ) {
		$page_id       = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		$new_author_id = isset( $input['new_author_id'] ) ? $input['new_author_id'] : 0;
		return WP_MCP_Content_Lifecycle::change_author( $page_id, 'page', $new_author_id, 'wp-mcp/change-page-author' );
	}

	/**
	 * Change a page's slug.
	 *
	 * @param array $input { @type int $page_id Required. @type string $slug Required. }
	 * @return array|WP_Error
	 */
	public static function update_page_slug( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		$slug    = isset( $input['slug'] ) ? $input['slug'] : '';
		return WP_MCP_Content_Lifecycle::update_slug( $page_id, 'page', $slug, 'wp-mcp/update-page-slug' );
	}

	/**
	 * Update a page's parent, menu order, and/or template.
	 *
	 * Bundled into one ability (mirrors WP-admin's "Page Attributes" box)
	 * since all three fields share the same edit_post gate.
	 *
	 * @param array $input {
	 *     @type int    $page_id    Required.
	 *     @type int    $parent_id  Optional. 0 clears the parent.
	 *     @type int    $menu_order Optional.
	 *     @type string $template   Optional. Template file path; empty string resets to default.
	 * }
	 * @return array|WP_Error
	 */
	public static function update_page_attributes( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		$post    = WP_MCP_Permissions::validate_post_for_mutation( $page_id, 'page' );
		if ( is_wp_error( $post ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-page-attributes', absint( $page_id ), false, $post->get_error_code() );
			return $post;
		}

		$update      = array( 'ID' => $post->ID );
		$has_changes = false;

		if ( isset( $input['parent_id'] ) ) {
			$parent_id = absint( $input['parent_id'] );

			if ( $parent_id > 0 ) {
				$parent = get_post( $parent_id );
				if ( ! $parent || 'page' !== $parent->post_type ) {
					WP_MCP_Audit::log( 'wp-mcp/update-page-attributes', $post->ID, false, 'wp_mcp_validation_error' );
					return WP_MCP_Errors::validation_error( __( 'parent_id must reference an existing page.', 'wordpress-mcp-abilities' ) );
				}
				if ( $parent_id === $post->ID ) {
					WP_MCP_Audit::log( 'wp-mcp/update-page-attributes', $post->ID, false, 'wp_mcp_validation_error' );
					return WP_MCP_Errors::validation_error( __( 'A page cannot be its own parent.', 'wordpress-mcp-abilities' ) );
				}
				// Reject the new parent being a descendant of this page (would create a cycle).
				if ( in_array( $post->ID, get_post_ancestors( $parent_id ), true ) ) {
					WP_MCP_Audit::log( 'wp-mcp/update-page-attributes', $post->ID, false, 'wp_mcp_validation_error' );
					return WP_MCP_Errors::validation_error( __( 'The specified parent would create a circular hierarchy.', 'wordpress-mcp-abilities' ) );
				}
			}

			$update['post_parent'] = $parent_id;
			$has_changes            = true;
		}

		if ( isset( $input['menu_order'] ) ) {
			$update['menu_order'] = (int) $input['menu_order'];
			$has_changes           = true;
		}

		$template = null;
		if ( isset( $input['template'] ) ) {
			$template = (string) $input['template'];
			if ( '' !== $template && ! array_key_exists( $template, wp_get_theme()->get_page_templates( $post ) ) ) {
				WP_MCP_Audit::log( 'wp-mcp/update-page-attributes', $post->ID, false, 'wp_mcp_invalid_template' );
				return WP_MCP_Errors::invalid_template();
			}
			$has_changes = true;
		}

		if ( ! $has_changes ) {
			WP_MCP_Audit::log( 'wp-mcp/update-page-attributes', $post->ID, true );
			return self::format_page_summary( $post );
		}

		if ( count( $update ) > 1 ) { // More than just 'ID' — parent_id and/or menu_order changed.
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				WP_MCP_Audit::log( 'wp-mcp/update-page-attributes', $post->ID, false, 'wp_mcp_update_failed' );
				return WP_MCP_Errors::update_failed( __( 'Failed to update page attributes.', 'wordpress-mcp-abilities' ) );
			}
		}

		if ( null !== $template ) {
			update_post_meta( $post->ID, '_wp_page_template', $template );
		}

		WP_MCP_Audit::log( 'wp-mcp/update-page-attributes', $post->ID, true );
		return self::format_page_summary( get_post( $post->ID ) );
	}

	/* ------------------------------------------------------------------
	 * list-page-revisions / get-page-revision / restore-page-revision
	 * ---------------------------------------------------------------- */

	/**
	 * List revisions for a page.
	 *
	 * @param array $input { @type int $page_id Required. @type int $page Optional. @type int $per_page Optional. }
	 * @return array|WP_Error
	 */
	public static function list_page_revisions( $input ) {
		$page_id = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		return WP_MCP_Content_Lifecycle::list_revisions( $page_id, 'page', $input );
	}

	/**
	 * Get a single page revision's full content.
	 *
	 * @param array $input { @type int $page_id Required. @type int $revision_id Required. }
	 * @return array|WP_Error
	 */
	public static function get_page_revision( $input ) {
		$page_id     = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		$revision_id = isset( $input['revision_id'] ) ? $input['revision_id'] : 0;
		return WP_MCP_Content_Lifecycle::get_revision( $page_id, 'page', $revision_id );
	}

	/**
	 * Restore a page to a previous revision's content.
	 *
	 * @param array $input { @type int $page_id Required. @type int $revision_id Required. }
	 * @return array|WP_Error
	 */
	public static function restore_page_revision( $input ) {
		$page_id     = isset( $input['page_id'] ) ? $input['page_id'] : 0;
		$revision_id = isset( $input['revision_id'] ) ? $input['revision_id'] : 0;
		return WP_MCP_Content_Lifecycle::restore_revision( $page_id, 'page', $revision_id, 'wp-mcp/restore-page-revision' );
	}

	/* ------------------------------------------------------------------
	 * Formatters (private)
	 * ---------------------------------------------------------------- */

	/**
	 * Format a page for create/update responses.
	 *
	 * @param WP_Post $post Page object.
	 * @return array
	 */
	private static function format_page_summary( $post ) {
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
}
