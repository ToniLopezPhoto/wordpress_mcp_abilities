<?php
/**
 * WordPress MCP Abilities — Custom post type callbacks.
 *
 * Safe discovery of registered custom post types plus explicit, individually
 * schema'd list/read/create/update/lifecycle/revision/featured-media
 * operations and registered post-metadata access for the discovered subtype.
 *
 * Three rules shape every method in this file:
 *
 * 1. **The post type is data, never a capability shortcut.** A post type name
 *    is always resolved through `get_post_type_object()` and every
 *    authorization decision uses that object's own `cap` mapping
 *    (`cap->create_posts`, `cap->publish_posts`, ...) or the native per-object
 *    meta-capabilities (`edit_post`, `read_post`, `delete_post`,
 *    `edit_post_meta`, `delete_post_meta`) that WordPress core's
 *    `map_meta_cap()` resolves *through* that same object. A literal
 *    `edit_posts` / `publish_posts` / `delete_posts` string is never used
 *    here: a type registered with its own `capability_type` would otherwise
 *    be gated on capabilities it does not use, letting an unrelated
 *    `edit_posts` holder reach content they have no rights to (and locking
 *    out the users who genuinely hold the type's capabilities).
 *
 * 2. **`post`, `page`, `attachment` and every other core built-in type are not
 *    addressable here.** They have their own explicit ability domains
 *    (`class-posts.php`, `class-pages.php`, `class-media.php`,
 *    `class-site-editor.php`, `class-navigation.php`) with type-specific
 *    rules; a second, generic path to the same objects would be a weaker
 *    duplicate of those rules. Discovery still *reports* them, marked
 *    non-operable with a machine-readable reason, so an agent learns the
 *    boundary instead of guessing at it.
 *
 * 3. **Nothing generic.** There is no arbitrary meta accessor: only post meta
 *    that is registered for the requested subtype (or globally), exposed via
 *    `show_in_rest`, stored as `single => true`, and not a protected `_`-
 *    prefixed key can be read, written, or deleted. Everything else is
 *    reported by discovery as non-operable and refused at execution with a
 *    stable error code. User, option, site and network metadata are out of
 *    scope entirely — this file only ever calls the `*_post_meta()` family.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.11.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Post_Types
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Post_Types {

	/** Maximum items returned by one list request. */
	const MAX_POSTS_PER_PAGE = 50;

	/* ------------------------------------------------------------------
	 * Operability policy
	 * ---------------------------------------------------------------- */

	/**
	 * Post types this domain never operates on, each because another
	 * explicit WordPress MCP domain owns it with type-specific rules.
	 *
	 * `_builtin` already covers all of these on a stock installation; the
	 * list is kept explicit so the refusal survives a plugin re-registering
	 * one of these names without the `_builtin` flag, and so the reason
	 * reported by discovery is accurate rather than generic.
	 *
	 * @return string[]
	 */
	public static function never_operable_types() {
		return array(
			'post',
			'page',
			'attachment',
			'revision',
			'nav_menu_item',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
		);
	}

	/**
	 * Whether a registered post type may be operated on by this domain, and
	 * why not when it may not.
	 *
	 * The reason is a stable machine-readable slug, not a translated string:
	 * agents branch on it, humans read the accompanying description.
	 *
	 * @param WP_Post_Type $post_type Registered post type object.
	 * @return array{operable:bool,reason:string}
	 */
	public static function operability( $post_type ) {
		if ( in_array( $post_type->name, self::never_operable_types(), true ) ) {
			return array( 'operable' => false, 'reason' => 'dedicated_domain' );
		}
		if ( ! empty( $post_type->_builtin ) ) {
			return array( 'operable' => false, 'reason' => 'built_in' );
		}
		if ( empty( $post_type->show_in_rest ) ) {
			return array( 'operable' => false, 'reason' => 'not_show_in_rest' );
		}
		return array( 'operable' => true, 'reason' => 'operable' );
	}

	/* ------------------------------------------------------------------
	 * Post type discovery
	 * ---------------------------------------------------------------- */

	/**
	 * List registered post types with labels, supports, taxonomies,
	 * visibility, capabilities, and this domain's operability verdict.
	 *
	 * @param array $input {
	 *     @type bool   $operable_only Optional. Only return operable types.
	 *     @type string $supports      Optional. Only return types supporting this feature.
	 *     @type string $taxonomy      Optional. Only return types the taxonomy is registered for.
	 * }
	 * @return array
	 */
	public static function list_post_types( $input ) {
		$operable_only = ! empty( $input['operable_only'] );
		$supports      = isset( $input['supports'] ) && is_string( $input['supports'] ) ? sanitize_key( $input['supports'] ) : '';
		$taxonomy      = isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';

		$items = array();
		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			if ( ! $post_type instanceof WP_Post_Type ) {
				continue;
			}
			$state = self::operability( $post_type );
			if ( $operable_only && ! $state['operable'] ) {
				continue;
			}
			if ( '' !== $supports && ! post_type_supports( $post_type->name, $supports ) ) {
				continue;
			}
			if ( '' !== $taxonomy && ! in_array( $taxonomy, self::taxonomy_names( $post_type->name ), true ) ) {
				continue;
			}
			$items[] = self::format_post_type( $post_type, $state );
		}

		return array( 'post_types' => $items, 'total' => count( $items ) );
	}

	/**
	 * Get one registered post type's registration detail.
	 *
	 * Deliberately works for non-operable types too: an agent must be able to
	 * find out *why* a type is off-limits without being told only "no".
	 *
	 * @param array $input { @type string $post_type Required. }
	 * @return array|WP_Error
	 */
	public static function get_post_type( $input ) {
		$post_type = self::resolve_post_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}
		return self::format_post_type( $post_type, self::operability( $post_type ) );
	}

	/**
	 * Describe the registered post metadata of one operable post type.
	 *
	 * Reports every key registered for the subtype (or globally for all post
	 * types), including the ones this domain refuses to touch, each with the
	 * reason it is not operable. Values are never returned — this describes
	 * the surface, it does not read content.
	 *
	 * @param array $input { @type string $post_type Required. }
	 * @return array|WP_Error
	 */
	public static function list_post_type_meta_fields( $input ) {
		$post_type = self::require_operable_post_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}
		$fields = self::describe_registered_meta( $post_type->name );
		return array( 'post_type' => $post_type->name, 'fields' => $fields, 'total' => count( $fields ) );
	}

	/* ------------------------------------------------------------------
	 * Read operations
	 * ---------------------------------------------------------------- */

	/**
	 * List / search entries of one operable post type.
	 *
	 * Non-public statuses require the type's own `edit_posts` capability and,
	 * without the type's own `edit_others_posts`, are narrowed to the current
	 * user's own entries. Every row is additionally filtered through the
	 * native `read_post` meta-capability, so a status filter can never widen
	 * what a caller may see.
	 *
	 * @param array $input Post type, filters, and pagination.
	 * @return array|WP_Error
	 */
	public static function list_custom_posts( $input ) {
		$post_type = self::require_operable_post_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}

		$allowed_statuses = array( 'publish', 'draft', 'pending', 'future', 'private' );
		$status           = 'publish';
		if ( ! empty( $input['status'] ) && is_string( $input['status'] ) && in_array( $input['status'], $allowed_statuses, true ) ) {
			$status = $input['status'];
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 10, self::MAX_POSTS_PER_PAGE );

		$args = array(
			'post_type'      => $post_type->name,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'perm'           => 'readable',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( 'publish' !== $status ) {
			if ( empty( $post_type->cap->edit_posts ) || ! current_user_can( $post_type->cap->edit_posts ) ) {
				return WP_MCP_Errors::post_type_permission_denied( __( 'Listing non-public entries requires this post type\'s own edit capability.', 'wordpress-mcp-abilities' ) );
			}
			if ( empty( $post_type->cap->edit_others_posts ) || ! current_user_can( $post_type->cap->edit_others_posts ) ) {
				$args['author'] = get_current_user_id();
			}
		}

		$orderby = isset( $input['orderby'] ) && is_string( $input['orderby'] ) ? $input['orderby'] : 'date';
		if ( in_array( $orderby, array( 'date', 'modified', 'title', 'ID' ), true ) ) {
			$args['orderby'] = $orderby;
		}
		if ( isset( $input['order'] ) && is_string( $input['order'] ) && in_array( strtoupper( $input['order'] ), array( 'ASC', 'DESC' ), true ) ) {
			$args['order'] = strtoupper( $input['order'] );
		}
		if ( ! empty( $input['search'] ) && is_string( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		$term_filter = self::validate_term_filter( $post_type, $input );
		if ( is_wp_error( $term_filter ) ) {
			return $term_filter;
		}
		if ( null !== $term_filter ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- A single-term filter over an explicitly validated taxonomy is the point of this ability.
			$args['tax_query'] = array( $term_filter );
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$items[] = self::format_custom_post_summary( $post );
		}

		return array(
			'post_type'   => $post_type->name,
			'posts'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get one entry of an operable post type, including its supported
	 * features, taxonomy terms, featured media, and operable registered meta.
	 *
	 * @param array $input { @type string $post_type @type int $post_id }
	 * @return array|WP_Error
	 */
	public static function get_custom_post( $input ) {
		$context = self::validate_context( $input, 'read' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		return self::format_custom_post_detail( $context['post'], $context['post_type'] );
	}

	/* ------------------------------------------------------------------
	 * Create / update
	 * ---------------------------------------------------------------- */

	/**
	 * Create a draft entry of an operable post type, owned by the current
	 * user.
	 *
	 * `post_status` and `post_author` are always set server-side; the only
	 * writable fields are the ones the type declares support for. Publishing
	 * is the separate `wp-mcp/publish-custom-post` ability.
	 *
	 * @param array $input { @type string $post_type @type string $title @type string $content @type string $excerpt }
	 * @return array|WP_Error
	 */
	public static function create_custom_post( $input ) {
		$post_type = self::require_operable_post_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
		if ( is_wp_error( $post_type ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-custom-post', 0, false, $post_type->get_error_code() );
			return $post_type;
		}

		if ( empty( $post_type->cap->create_posts ) || ! current_user_can( $post_type->cap->create_posts ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-custom-post', 0, false, 'wp_mcp_post_type_permission_denied' );
			return WP_MCP_Errors::post_type_permission_denied( __( 'You do not have permission to create entries of this post type.', 'wordpress-mcp-abilities' ) );
		}

		$fields = self::content_fields( $post_type, $input, true );
		if ( is_wp_error( $fields ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-custom-post', 0, false, $fields->get_error_code() );
			return $fields;
		}

		$post_data = array_merge(
			$fields,
			array(
				'post_type'   => $post_type->name,
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			)
		);

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-custom-post', 0, false, 'wp_mcp_create_failed' );
			return WP_MCP_Errors::create_failed();
		}

		$created = get_post( $post_id );
		if ( ! $created instanceof WP_Post ) {
			WP_MCP_Audit::log( 'wp-mcp/create-custom-post', $post_id, false, 'wp_mcp_create_failed' );
			return WP_MCP_Errors::create_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/create-custom-post', $post_id, true, '', array( 'post_type' => $post_type->name ) );
		return self::format_custom_post_summary( $created );
	}

	/**
	 * Update the supported content fields of one entry.
	 *
	 * @param array $input { @type string $post_type @type int $post_id @type string $title @type string $content @type string $excerpt }
	 * @return array|WP_Error
	 */
	public static function update_custom_post( $input ) {
		$context = self::validate_context( $input, 'edit', 'wp-mcp/update-custom-post' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$fields = self::content_fields( $context['post_type'], $input, false );
		if ( is_wp_error( $fields ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-custom-post', $context['post']->ID, false, $fields->get_error_code() );
			return $fields;
		}

		if ( empty( $fields ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-custom-post', $context['post']->ID, true );
			return self::format_custom_post_summary( $context['post'] );
		}

		$result = wp_update_post( array_merge( $fields, array( 'ID' => $context['post']->ID ) ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-custom-post', $context['post']->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to update this entry.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/update-custom-post', $context['post']->ID, true );
		return self::refreshed_summary( $context['post'] );
	}

	/* ------------------------------------------------------------------
	 * Lifecycle
	 * ---------------------------------------------------------------- */

	/**
	 * Publish an entry. Idempotent when already published.
	 *
	 * Gated on the post type's own `publish_posts` capability rather than
	 * the literal `publish_posts` string, so a type with a custom
	 * `capability_type` is published by the users who actually hold its
	 * publish capability.
	 *
	 * @param array $input { @type string $post_type @type int $post_id }
	 * @return array|WP_Error
	 */
	public static function publish_custom_post( $input ) {
		$context = self::validate_context( $input, 'edit', 'wp-mcp/publish-custom-post' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$post      = $context['post'];
		$post_type = $context['post_type'];

		if ( empty( $post_type->cap->publish_posts ) || ! current_user_can( $post_type->cap->publish_posts ) ) {
			WP_MCP_Audit::log( 'wp-mcp/publish-custom-post', $post->ID, false, 'wp_mcp_post_type_permission_denied' );
			return WP_MCP_Errors::post_type_permission_denied( __( 'You do not have permission to publish entries of this post type.', 'wordpress-mcp-abilities' ) );
		}

		if ( 'publish' === $post->post_status ) {
			WP_MCP_Audit::log( 'wp-mcp/publish-custom-post', $post->ID, true );
			return self::format_custom_post_summary( $post );
		}

		$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/publish-custom-post', $post->ID, false, 'wp_mcp_publish_failed' );
			return WP_MCP_Errors::publish_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/publish-custom-post', $post->ID, true );
		return self::refreshed_summary( $post );
	}

	/**
	 * Return a published entry to draft. Idempotent when already a draft.
	 *
	 * @param array $input { @type string $post_type @type int $post_id }
	 * @return array|WP_Error
	 */
	public static function unpublish_custom_post( $input ) {
		$context = self::validate_context( $input, 'edit', 'wp-mcp/unpublish-custom-post' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$post = $context['post'];

		if ( 'draft' === $post->post_status ) {
			WP_MCP_Audit::log( 'wp-mcp/unpublish-custom-post', $post->ID, true );
			return self::format_custom_post_summary( $post );
		}

		$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/unpublish-custom-post', $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to unpublish this entry.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/unpublish-custom-post', $post->ID, true );
		return self::refreshed_summary( $post );
	}

	/**
	 * Move an entry to trash. Idempotent when already trashed.
	 *
	 * @param array $input { @type string $post_type @type int $post_id }
	 * @return array|WP_Error
	 */
	public static function trash_custom_post( $input ) {
		$context = self::validate_context( $input, 'delete', 'wp-mcp/trash-custom-post' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$post = $context['post'];

		if ( 'trash' === $post->post_status ) {
			WP_MCP_Audit::log( 'wp-mcp/trash-custom-post', $post->ID, true );
			return self::format_custom_post_summary( $post );
		}

		$result = wp_trash_post( $post->ID );
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/trash-custom-post', $post->ID, false, 'wp_mcp_trash_failed' );
			return WP_MCP_Errors::trash_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/trash-custom-post', $post->ID, true );

		// `wp_trash_post()` delegates to `wp_delete_post( $id, true )` when
		// EMPTY_TRASH_DAYS is 0 — a supported WordPress configuration that
		// disables the trash entirely — so the entry can be gone by the time
		// the response is built. Re-reading is still the right default (it is
		// the only way to report the new `trash` status), but it has to
		// tolerate a null read instead of dereferencing it.
		return self::refreshed_summary( $post );
	}

	/**
	 * Restore an entry from trash. Idempotent when not trashed.
	 *
	 * @param array $input { @type string $post_type @type int $post_id }
	 * @return array|WP_Error
	 */
	public static function restore_custom_post( $input ) {
		$context = self::validate_context( $input, 'delete', 'wp-mcp/restore-custom-post' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$post = $context['post'];

		if ( 'trash' !== $post->post_status ) {
			WP_MCP_Audit::log( 'wp-mcp/restore-custom-post', $post->ID, true );
			return self::format_custom_post_summary( $post );
		}

		if ( ! wp_untrash_post( $post->ID ) ) {
			WP_MCP_Audit::log( 'wp-mcp/restore-custom-post', $post->ID, false, 'wp_mcp_restore_failed' );
			return WP_MCP_Errors::restore_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/restore-custom-post', $post->ID, true );
		return self::refreshed_summary( $post );
	}

	/**
	 * Permanently delete an entry, bypassing trash. Not idempotent.
	 *
	 * @param array $input { @type string $post_type @type int $post_id }
	 * @return array|WP_Error
	 */
	public static function delete_custom_post_permanently( $input ) {
		$context = self::validate_context( $input, 'delete', 'wp-mcp/delete-custom-post-permanently' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$post_id   = $context['post']->ID;
		$post_type = $context['post_type']->name;

		if ( ! wp_delete_post( $post_id, true ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-custom-post-permanently', $post_id, false, 'wp_mcp_delete_failed' );
			return WP_MCP_Errors::delete_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/delete-custom-post-permanently', $post_id, true, '', array( 'post_type' => $post_type ) );
		return array( 'post_type' => $post_type, 'id' => (int) $post_id, 'deleted' => true );
	}

	/* ------------------------------------------------------------------
	 * Revisions
	 * ---------------------------------------------------------------- */

	/**
	 * List revisions of one entry. Requires the type to support revisions.
	 *
	 * @param array $input { @type string $post_type @type int $post_id @type int $page @type int $per_page }
	 * @return array|WP_Error
	 */
	public static function list_custom_post_revisions( $input ) {
		$context = self::validate_feature_context( $input, 'revisions' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input );

		$revisions = wp_get_post_revisions( $context['post']->ID, array( 'posts_per_page' => $per_page, 'paged' => $page ) );
		$items     = array();
		foreach ( $revisions as $revision ) {
			$items[] = array(
				'id'       => (int) $revision->ID,
				'author'   => (int) $revision->post_author,
				'date'     => $revision->post_date,
				'modified' => $revision->post_modified,
			);
		}

		$total = count( wp_get_post_revisions( $context['post']->ID, array( 'fields' => 'ids' ) ) );

		return array(
			'post_type'   => $context['post_type']->name,
			'post_id'     => (int) $context['post']->ID,
			'revisions'   => $items,
			'total'       => $total,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get one revision's stored content.
	 *
	 * @param array $input { @type string $post_type @type int $post_id @type int $revision_id }
	 * @return array|WP_Error
	 */
	public static function get_custom_post_revision( $input ) {
		$context = self::validate_feature_context( $input, 'revisions' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$revision = WP_MCP_Permissions::validate_revision_for_post( isset( $input['revision_id'] ) ? $input['revision_id'] : 0, $context['post']->ID );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		return array(
			'post_type' => $context['post_type']->name,
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
	 * Restore an entry to a previous revision. Not idempotent.
	 *
	 * @param array $input { @type string $post_type @type int $post_id @type int $revision_id }
	 * @return array|WP_Error
	 */
	public static function restore_custom_post_revision( $input ) {
		$context = self::validate_feature_context( $input, 'revisions', 'wp-mcp/restore-custom-post-revision' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$revision = WP_MCP_Permissions::validate_revision_for_post( isset( $input['revision_id'] ) ? $input['revision_id'] : 0, $context['post']->ID );
		if ( is_wp_error( $revision ) ) {
			WP_MCP_Audit::log( 'wp-mcp/restore-custom-post-revision', $context['post']->ID, false, $revision->get_error_code() );
			return $revision;
		}

		if ( ! wp_restore_post_revision( $revision->ID ) ) {
			WP_MCP_Audit::log( 'wp-mcp/restore-custom-post-revision', $context['post']->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to restore the revision.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/restore-custom-post-revision', $context['post']->ID, true );
		return self::refreshed_summary( $context['post'] );
	}

	/* ------------------------------------------------------------------
	 * Featured media
	 * ---------------------------------------------------------------- */

	/**
	 * Set an existing image as the entry's featured media. Idempotent.
	 *
	 * Requires the type to support `thumbnail`; a type without that support
	 * has no featured-media UI in WordPress and is refused here rather than
	 * silently given a `_thumbnail_id` nothing will ever render.
	 *
	 * @param array $input { @type string $post_type @type int $post_id @type int $media_id }
	 * @return array|WP_Error
	 */
	public static function set_custom_post_featured_image( $input ) {
		$context = self::validate_feature_context( $input, 'thumbnail', 'wp-mcp/set-custom-post-featured-image' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$post = $context['post'];

		$media_id = self::strict_positive_int( isset( $input['media_id'] ) ? $input['media_id'] : 0, 'media_id' );
		if ( is_wp_error( $media_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-custom-post-featured-image', $post->ID, false, $media_id->get_error_code() );
			return $media_id;
		}

		$media = get_post( $media_id );
		if ( ! $media || 'attachment' !== $media->post_type ) {
			WP_MCP_Audit::log( 'wp-mcp/set-custom-post-featured-image', $post->ID, false, 'wp_mcp_invalid_media' );
			return WP_MCP_Errors::invalid_media();
		}
		if ( ! current_user_can( 'read_post', $media_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-custom-post-featured-image', $post->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to use this media.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! wp_attachment_is_image( $media_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-custom-post-featured-image', $post->ID, false, 'wp_mcp_not_an_image' );
			return WP_MCP_Errors::not_an_image();
		}

		if ( (int) get_post_thumbnail_id( $post->ID ) !== $media_id && ! set_post_thumbnail( $post->ID, $media_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-custom-post-featured-image', $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to set featured media.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/set-custom-post-featured-image', $post->ID, true );
		return array(
			'post_type' => $context['post_type']->name,
			'post_id'   => (int) $post->ID,
			'media_id'  => $media_id,
			'media_url' => (string) wp_get_attachment_url( $media_id ),
		);
	}

	/**
	 * Remove the entry's featured media. Idempotent.
	 *
	 * @param array $input { @type string $post_type @type int $post_id }
	 * @return array|WP_Error
	 */
	public static function remove_custom_post_featured_image( $input ) {
		$context = self::validate_feature_context( $input, 'thumbnail', 'wp-mcp/remove-custom-post-featured-image' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$post     = $context['post'];
		$previous = (int) get_post_thumbnail_id( $post->ID );

		if ( $previous && ! delete_post_thumbnail( $post->ID ) ) {
			WP_MCP_Audit::log( 'wp-mcp/remove-custom-post-featured-image', $post->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to remove featured media.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/remove-custom-post-featured-image', $post->ID, true );
		return array(
			'post_type'         => $context['post_type']->name,
			'post_id'           => (int) $post->ID,
			'removed_media_id'  => $previous,
			'featured_media_id' => 0,
		);
	}

	/* ------------------------------------------------------------------
	 * Registered post metadata
	 * ---------------------------------------------------------------- */

	/**
	 * Read one operable registered metadata key of one entry.
	 *
	 * @param array $input { @type string $post_type @type int $post_id @type string $meta_key }
	 * @return array|WP_Error
	 */
	public static function get_custom_post_meta( $input ) {
		$context = self::validate_context( $input, 'read' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$key = self::validate_operable_meta_key( $context['post_type']->name, isset( $input['meta_key'] ) ? $input['meta_key'] : '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return array(
			'post_type' => $context['post_type']->name,
			'post_id'   => (int) $context['post']->ID,
			'meta_key'  => $key['key'],
			'value'     => get_post_meta( $context['post']->ID, $key['key'], true ),
		);
	}

	/**
	 * Write one operable registered metadata key after validating the value
	 * against the key's own registered REST schema.
	 *
	 * @param array $input { @type string $post_type @type int $post_id @type string $meta_key @type mixed $value }
	 * @return array|WP_Error
	 */
	public static function update_custom_post_meta( $input ) {
		$context = self::validate_context( $input, 'edit', 'wp-mcp/update-custom-post-meta' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$post = $context['post'];

		$key = self::validate_operable_meta_key( $context['post_type']->name, isset( $input['meta_key'] ) ? $input['meta_key'] : '' );
		if ( is_wp_error( $key ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-custom-post-meta', $post->ID, false, $key->get_error_code() );
			return $key;
		}

		// Per-object, per-key write gate: core resolves this through
		// map_meta_cap() into edit_post plus the key's registered
		// auth_callback, so a key whose registration refuses this user is
		// refused here even though the key is otherwise operable.
		if ( ! current_user_can( 'edit_post_meta', $post->ID, $key['key'] ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-custom-post-meta', $post->ID, false, 'wp_mcp_post_type_permission_denied' );
			return WP_MCP_Errors::post_type_permission_denied( __( 'You do not have permission to write this metadata key.', 'wordpress-mcp-abilities' ) );
		}

		$value = self::sanitize_registered_meta_value( array_key_exists( 'value', $input ) ? $input['value'] : null, $key['args'] );
		if ( is_wp_error( $value ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-custom-post-meta', $post->ID, false, $value->get_error_code() );
			return $value;
		}

		// A `false` return means either "failed" or "identical value, nothing
		// written", so the two have to be told apart by re-reading. The
		// comparison target is the value core would have *stored*, not the
		// value we handed it: a key registered with its own sanitize_callback
		// is normalized on the way in, and comparing against the raw input
		// would report a successful idempotent rewrite as a failure.
		$expected = sanitize_meta( $key['key'], $value, 'post', $context['post_type']->name );

		$result = update_post_meta( $post->ID, $key['key'], $value );
		if ( false === $result && ! self::meta_value_matches( get_post_meta( $post->ID, $key['key'], true ), $expected ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-custom-post-meta', $post->ID, false, 'wp_mcp_post_meta_update_failed' );
			return WP_MCP_Errors::post_meta_update_failed();
		}

		WP_MCP_Audit::log( 'wp-mcp/update-custom-post-meta', $post->ID, true, '', array( 'meta_key' => $key['key'] ) );
		return array(
			'post_type' => $context['post_type']->name,
			'post_id'   => (int) $post->ID,
			'meta_key'  => $key['key'],
			'value'     => get_post_meta( $post->ID, $key['key'], true ),
		);
	}

	/**
	 * Delete one operable registered metadata key. Idempotent when absent.
	 *
	 * @param array $input { @type string $post_type @type int $post_id @type string $meta_key }
	 * @return array|WP_Error
	 */
	public static function delete_custom_post_meta( $input ) {
		$context = self::validate_context( $input, 'edit', 'wp-mcp/delete-custom-post-meta' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$post = $context['post'];

		$key = self::validate_operable_meta_key( $context['post_type']->name, isset( $input['meta_key'] ) ? $input['meta_key'] : '' );
		if ( is_wp_error( $key ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-custom-post-meta', $post->ID, false, $key->get_error_code() );
			return $key;
		}

		if ( ! current_user_can( 'delete_post_meta', $post->ID, $key['key'] ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-custom-post-meta', $post->ID, false, 'wp_mcp_post_type_permission_denied' );
			return WP_MCP_Errors::post_type_permission_denied( __( 'You do not have permission to delete this metadata key.', 'wordpress-mcp-abilities' ) );
		}

		delete_post_meta( $post->ID, $key['key'] );

		WP_MCP_Audit::log( 'wp-mcp/delete-custom-post-meta', $post->ID, true, '', array( 'meta_key' => $key['key'] ) );
		return array(
			'post_type' => $context['post_type']->name,
			'post_id'   => (int) $post->ID,
			'meta_key'  => $key['key'],
			'deleted'   => true,
		);
	}

	/* ------------------------------------------------------------------
	 * Permission callbacks (first-pass gate)
	 *
	 * The Abilities API hands the raw input to permission_callback, so this
	 * domain's first-pass gate can already be post-type aware instead of
	 * falling back to a hardcoded `edit_posts`. When the input does not name
	 * a resolvable operable type, the gate degrades to "authenticated
	 * reader" and lets the execute callback return the precise, stable
	 * error (invalid_post_type / post_type_not_operable) rather than an
	 * indistinguishable permission failure.
	 * ---------------------------------------------------------------- */

	/**
	 * Read gate: an authenticated reader. Per-object `read_post` is enforced
	 * in the execute callback.
	 *
	 * @param mixed $input Raw ability input.
	 * @return bool
	 */
	public static function can_read( $input = null ) {
		return WP_MCP_Permissions::can_read();
	}

	/**
	 * Create gate: the target type's own `create_posts` capability.
	 *
	 * @param mixed $input Raw ability input.
	 * @return bool
	 */
	public static function can_create( $input = null ) {
		return self::has_type_capability( $input, 'create_posts' );
	}

	/**
	 * Edit gate: the target type's own `edit_posts` capability.
	 *
	 * @param mixed $input Raw ability input.
	 * @return bool
	 */
	public static function can_edit( $input = null ) {
		return self::has_type_capability( $input, 'edit_posts' );
	}

	/**
	 * Publish gate: the target type's own `publish_posts` capability.
	 *
	 * @param mixed $input Raw ability input.
	 * @return bool
	 */
	public static function can_publish( $input = null ) {
		return self::has_type_capability( $input, 'publish_posts' );
	}

	/**
	 * Delete gate: the target type's own `delete_posts` capability.
	 *
	 * @param mixed $input Raw ability input.
	 * @return bool
	 */
	public static function can_delete( $input = null ) {
		return self::has_type_capability( $input, 'delete_posts' );
	}

	/**
	 * Whether the current user holds one named capability slot of the post
	 * type the input names.
	 *
	 * @param mixed  $input Raw ability input.
	 * @param string $slot  Capability slot on the post type's cap object.
	 * @return bool
	 */
	private static function has_type_capability( $input, $slot ) {
		if ( ! is_array( $input ) || ! isset( $input['post_type'] ) ) {
			return WP_MCP_Permissions::can_read();
		}
		$post_type = self::require_operable_post_type( $input['post_type'] );
		if ( is_wp_error( $post_type ) ) {
			return WP_MCP_Permissions::can_read();
		}
		if ( empty( $post_type->cap->$slot ) ) {
			return false;
		}
		return current_user_can( $post_type->cap->$slot );
	}

	/* ------------------------------------------------------------------
	 * Validation helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Validate a caller-supplied database ID strictly.
	 *
	 * `WP_MCP_Permissions::validate_positive_int()` is `absint()`-based, so
	 * it silently rewrites `-1` to `1`, `"1.5"` to `1` and `"1e3"` to `1000`
	 * — a caller-supplied ID would then address a *different* object than the
	 * one it named. Every ID this domain parses itself goes through the
	 * strict parser instead, per the repository-wide rule for telling a real
	 * database ID from something that merely looks numeric.
	 *
	 * @param mixed  $value      Raw ID from MCP input.
	 * @param string $field_name Field name for the error message.
	 * @return int|WP_Error
	 */
	private static function strict_positive_int( $value, $field_name ) {
		if ( ! WP_MCP_Permissions::is_strict_positive_int_id( $value ) ) {
			return WP_MCP_Errors::validation_error(
				sprintf(
					/* translators: %s: field name */
					__( '%s must be a positive integer.', 'wordpress-mcp-abilities' ),
					$field_name
				)
			);
		}
		return (int) $value;
	}

	/**
	 * Resolve a caller-supplied post type name to its registration object.
	 *
	 * @param mixed $name Raw post type name.
	 * @return WP_Post_Type|WP_Error
	 */
	private static function resolve_post_type( $name ) {
		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			return WP_MCP_Errors::invalid_post_type();
		}
		$name = sanitize_key( $name );
		if ( '' === $name || ! post_type_exists( $name ) ) {
			return WP_MCP_Errors::invalid_post_type();
		}
		$object = get_post_type_object( $name );
		return $object instanceof WP_Post_Type ? $object : WP_MCP_Errors::invalid_post_type();
	}

	/**
	 * Resolve a post type name and require that this domain may operate on it.
	 *
	 * @param mixed $name Raw post type name.
	 * @return WP_Post_Type|WP_Error
	 */
	private static function require_operable_post_type( $name ) {
		$post_type = self::resolve_post_type( $name );
		if ( is_wp_error( $post_type ) ) {
			return $post_type;
		}
		$state = self::operability( $post_type );
		if ( ! $state['operable'] ) {
			return WP_MCP_Errors::post_type_not_operable(
				sprintf(
					/* translators: 1: post type name, 2: machine-readable reason slug */
					__( 'The "%1$s" post type is not addressable through the custom post type abilities (reason: %2$s).', 'wordpress-mcp-abilities' ),
					$post_type->name,
					$state['reason']
				)
			);
		}
		return $post_type;
	}

	/**
	 * Resolve post type + entry together and apply the native per-object
	 * meta-capability for the requested access mode.
	 *
	 * The entry is looked up by ID and then required to belong to the
	 * explicitly requested post type: an ID from a different type is
	 * reported as "does not exist in this post type", never operated on and
	 * never confirmed to exist elsewhere.
	 *
	 * @param array       $input        Raw ability input.
	 * @param string      $mode         'read', 'edit', or 'delete'.
	 * @param string|null $ability_name Ability name for audit logging, or null for read-only paths.
	 * @return array{post_type:WP_Post_Type,post:WP_Post}|WP_Error
	 */
	private static function validate_context( $input, $mode, $ability_name = null ) {
		$raw_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;

		$post_type = self::require_operable_post_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
		if ( is_wp_error( $post_type ) ) {
			if ( $ability_name ) {
				WP_MCP_Audit::log( $ability_name, absint( $raw_id ), false, $post_type->get_error_code() );
			}
			return $post_type;
		}

		$post_id = self::strict_positive_int( $raw_id, 'post_id' );
		if ( is_wp_error( $post_id ) ) {
			if ( $ability_name ) {
				WP_MCP_Audit::log( $ability_name, 0, false, $post_id->get_error_code() );
			}
			return $post_id;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== $post_type->name ) {
			if ( $ability_name ) {
				WP_MCP_Audit::log( $ability_name, $post_id, false, 'wp_mcp_invalid_custom_post' );
			}
			return WP_MCP_Errors::invalid_custom_post();
		}

		if ( 'read' === $mode ) {
			$capability = 'read_post';
		} elseif ( 'delete' === $mode ) {
			$capability = 'delete_post';
		} else {
			$capability = 'edit_post';
		}

		// Sole authorization gate: the native per-object meta-capability,
		// which core's map_meta_cap() resolves through this very post type
		// object (including types registered with map_meta_cap => false,
		// for which core falls back to the type's own singular capability).
		if ( ! current_user_can( $capability, $post->ID ) ) {
			if ( $ability_name ) {
				WP_MCP_Audit::log( $ability_name, $post->ID, false, 'wp_mcp_post_type_permission_denied' );
			}
			return WP_MCP_Errors::post_type_permission_denied(
				sprintf(
					/* translators: %s: post type name */
					__( 'You do not have the required capability for this "%s" entry.', 'wordpress-mcp-abilities' ),
					$post_type->name
				)
			);
		}

		return array( 'post_type' => $post_type, 'post' => $post );
	}

	/**
	 * validate_context() plus a required `supports` feature on the type.
	 *
	 * @param array       $input        Raw ability input.
	 * @param string      $feature      Required post type feature.
	 * @param string|null $ability_name Ability name for audit logging.
	 * @return array{post_type:WP_Post_Type,post:WP_Post}|WP_Error
	 */
	private static function validate_feature_context( $input, $feature, $ability_name = null ) {
		$context = self::validate_context( $input, 'edit', $ability_name );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( ! post_type_supports( $context['post_type']->name, $feature ) ) {
			if ( $ability_name ) {
				WP_MCP_Audit::log( $ability_name, $context['post']->ID, false, 'wp_mcp_post_type_unsupported_feature' );
			}
			return WP_MCP_Errors::post_type_unsupported_feature(
				sprintf(
					/* translators: 1: post type name, 2: post type feature */
					__( 'The "%1$s" post type does not support "%2$s".', 'wordpress-mcp-abilities' ),
					$context['post_type']->name,
					$feature
				)
			);
		}
		return $context;
	}

	/**
	 * Build the writable content fields, refusing any field the post type
	 * does not declare support for.
	 *
	 * @param WP_Post_Type $post_type Post type object.
	 * @param array        $input     Raw ability input.
	 * @param bool         $creating  Whether this is a creation.
	 * @return array|WP_Error
	 */
	private static function content_fields( $post_type, $input, $creating ) {
		$map = array(
			'title'   => array( 'feature' => 'title', 'column' => 'post_title' ),
			'content' => array( 'feature' => 'editor', 'column' => 'post_content' ),
			'excerpt' => array( 'feature' => 'excerpt', 'column' => 'post_excerpt' ),
		);

		$fields = array();
		foreach ( $map as $field => $spec ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}
			if ( ! post_type_supports( $post_type->name, $spec['feature'] ) ) {
				return WP_MCP_Errors::post_type_unsupported_feature(
					sprintf(
						/* translators: 1: post type name, 2: post type feature, 3: input field name */
						__( 'The "%1$s" post type does not support "%2$s", so "%3$s" cannot be written.', 'wordpress-mcp-abilities' ),
						$post_type->name,
						$spec['feature'],
						$field
					)
				);
			}
			if ( ! is_string( $input[ $field ] ) ) {
				return WP_MCP_Errors::post_type_validation_error(
					sprintf(
						/* translators: %s: input field name */
						__( '"%s" must be a string.', 'wordpress-mcp-abilities' ),
						$field
					)
				);
			}
			$fields[ $spec['column'] ] = 'title' === $field
				? sanitize_text_field( $input[ $field ] )
				: wp_kses_post( $input[ $field ] );
		}

		if ( $creating && post_type_supports( $post_type->name, 'title' ) && empty( $fields['post_title'] ) ) {
			return WP_MCP_Errors::post_type_validation_error( __( 'title is required for a post type that supports it.', 'wordpress-mcp-abilities' ) );
		}

		return $fields;
	}

	/**
	 * Validate the optional taxonomy/term filter of list_custom_posts().
	 *
	 * @param WP_Post_Type $post_type Post type object.
	 * @param array        $input     Raw ability input.
	 * @return array|null|WP_Error Tax-query clause, null when no filter, WP_Error when invalid.
	 */
	private static function validate_term_filter( $post_type, $input ) {
		$has_taxonomy = ! empty( $input['taxonomy'] );
		$has_term     = ! empty( $input['term_id'] );
		if ( ! $has_taxonomy && ! $has_term ) {
			return null;
		}
		if ( ! $has_taxonomy || ! $has_term ) {
			return WP_MCP_Errors::post_type_validation_error( __( 'taxonomy and term_id must be provided together.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! is_string( $input['taxonomy'] ) ) {
			return WP_MCP_Errors::invalid_taxonomy();
		}

		$taxonomy = sanitize_key( $input['taxonomy'] );
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || ! in_array( $taxonomy, self::taxonomy_names( $post_type->name ), true ) ) {
			return WP_MCP_Errors::invalid_taxonomy();
		}

		$term_id = self::strict_positive_int( $input['term_id'], 'term_id' );
		if ( is_wp_error( $term_id ) ) {
			return $term_id;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== $taxonomy ) {
			return WP_MCP_Errors::invalid_term();
		}

		return array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => array( (int) $term->term_id ) );
	}

	/* ------------------------------------------------------------------
	 * Registered metadata helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Every metadata key registered for a post type subtype, merged with the
	 * keys registered globally for all post types.
	 *
	 * Subtype registrations win over global ones on a key collision, matching
	 * how `get_registered_meta_keys()` is consumed by core's own REST
	 * controllers.
	 *
	 * @param string $post_type Post type name.
	 * @return array<string,array<string,mixed>>
	 */
	private static function registered_meta_keys( $post_type ) {
		return array_merge( get_registered_meta_keys( 'post', '' ), get_registered_meta_keys( 'post', $post_type ) );
	}

	/**
	 * Whether a registered metadata key may be read/written/deleted by this
	 * domain, and why not when it may not.
	 *
	 * @param string $key  Metadata key.
	 * @param mixed  $args Registration arguments.
	 * @return array{operable:bool,reason:string}
	 */
	private static function meta_operability( $key, $args ) {
		if ( self::is_protected_key( $key ) ) {
			return array( 'operable' => false, 'reason' => 'protected_key' );
		}
		if ( ! is_array( $args ) || empty( $args['show_in_rest'] ) ) {
			return array( 'operable' => false, 'reason' => 'not_show_in_rest' );
		}
		if ( empty( $args['single'] ) ) {
			return array( 'operable' => false, 'reason' => 'multi_value' );
		}
		return array( 'operable' => true, 'reason' => 'operable' );
	}

	/**
	 * Whether a metadata key is protected and therefore never addressable.
	 *
	 * Both halves matter: the leading-underscore convention is checked
	 * directly so the refusal does not depend on the `is_protected_meta`
	 * filter chain, and `is_protected_meta()` is checked so a key a plugin
	 * marks protected without an underscore is refused too.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private static function is_protected_key( $key ) {
		return 0 === strpos( $key, '_' ) || is_protected_meta( $key, 'post' );
	}

	/**
	 * Describe every registered metadata key of a post type, operable or not.
	 *
	 * @param string $post_type Post type name.
	 * @return array<int,array<string,mixed>>
	 */
	private static function describe_registered_meta( $post_type ) {
		$fields = array();
		foreach ( self::registered_meta_keys( $post_type ) as $key => $args ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			// No is_array() cast here: meta_operability() already treats a
			// non-array registration as non-operable, and every read below is
			// isset()/empty()-guarded, so a defensive cast would only be dead
			// code sitting on top of the checks that actually do the work.
			$state    = self::meta_operability( $key, $args );
			$fields[] = array(
				'key'          => $key,
				'type'         => isset( $args['type'] ) && is_string( $args['type'] ) ? $args['type'] : 'string',
				'description'  => isset( $args['description'] ) && is_string( $args['description'] ) ? $args['description'] : '',
				'single'       => ! empty( $args['single'] ),
				'show_in_rest' => ! empty( $args['show_in_rest'] ),
				'protected'    => self::is_protected_key( $key ),
				'operable'     => $state['operable'],
				'reason'       => $state['reason'],
			);
		}
		return $fields;
	}

	/**
	 * Validate a caller-supplied metadata key against the operable surface.
	 *
	 * @param string $post_type Post type name.
	 * @param mixed  $key       Raw metadata key.
	 * @return array{key:string,args:array}|WP_Error
	 */
	private static function validate_operable_meta_key( $post_type, $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return WP_MCP_Errors::post_meta_not_registered();
		}

		// Refused before the registry is even consulted: a protected key is
		// never addressable, registered or not.
		if ( self::is_protected_key( $key ) ) {
			return WP_MCP_Errors::post_meta_protected();
		}

		$registered = self::registered_meta_keys( $post_type );
		if ( ! isset( $registered[ $key ] ) ) {
			return WP_MCP_Errors::post_meta_not_registered();
		}

		$args = $registered[ $key ];
		if ( ! is_array( $args ) ) {
			return WP_MCP_Errors::post_meta_not_registered();
		}

		$state = self::meta_operability( $key, $args );
		if ( ! $state['operable'] ) {
			if ( 'not_show_in_rest' === $state['reason'] ) {
				return WP_MCP_Errors::post_meta_not_registered();
			}
			return WP_MCP_Errors::post_meta_not_operable(
				sprintf(
					/* translators: 1: metadata key, 2: machine-readable reason slug */
					__( 'The "%1$s" metadata key is not operable (reason: %2$s).', 'wordpress-mcp-abilities' ),
					$key,
					$state['reason']
				)
			);
		}

		return array( 'key' => $key, 'args' => $args );
	}

	/**
	 * Whether the value WordPress stored is the value we asked it to store.
	 *
	 * `update_post_meta()` returns false both for a failed write and for a
	 * no-op write of an identical value, so the two have to be told apart by
	 * re-reading. The comparison cannot be a plain `===`: WordPress persists
	 * scalars as strings, so a boolean `true` comes back as `'1'` and an
	 * integer `42` as `'42'` — a strict re-read comparison would report a
	 * successful idempotent write as a failure. Non-scalars (arrays and
	 * objects) round-trip through serialization intact and are compared
	 * strictly.
	 *
	 * @param mixed $stored Value read back from the database.
	 * @param mixed $value  Value core would have stored, after sanitize_meta().
	 * @return bool
	 */
	private static function meta_value_matches( $stored, $value ) {
		if ( is_scalar( $stored ) && is_scalar( $value ) ) {
			return (string) $stored === (string) $value;
		}
		return $stored === $value;
	}

	/**
	 * Validate and sanitize a metadata value against its registered REST
	 * schema. Strings, integers, numbers, booleans, arrays and objects are
	 * all supported — whatever the key's own registration declares.
	 *
	 * @param mixed $value Raw value from MCP input.
	 * @param array $args  Registration arguments.
	 * @return mixed|WP_Error
	 */
	private static function sanitize_registered_meta_value( $value, $args ) {
		$show   = isset( $args['show_in_rest'] ) && is_array( $args['show_in_rest'] ) ? $args['show_in_rest'] : array();
		$schema = isset( $show['schema'] ) && is_array( $show['schema'] )
			? $show['schema']
			: array( 'type' => isset( $args['type'] ) ? $args['type'] : 'string' );

		// `rest_is_array()` and `rest_is_object()` deliberately coerce a
		// scalar — `'a,b'` becomes `array( 'a', 'b' )` — because the REST API
		// also has to accept query-string parameters. MCP input is always
		// structured JSON, so a scalar where the registered schema declares
		// an array or an object is a client mistake, not a shorthand: refuse
		// it instead of silently storing a value the caller never sent.
		$declared = isset( $schema['type'] ) && is_string( $schema['type'] ) ? $schema['type'] : '';
		if ( in_array( $declared, array( 'array', 'object' ), true ) && ! is_array( $value ) && ! is_object( $value ) ) {
			return WP_MCP_Errors::post_type_validation_error(
				sprintf(
					/* translators: %s: metadata type declared by the key's own registration */
					__( 'This metadata key declares "%s", so a scalar value is not accepted.', 'wordpress-mcp-abilities' ),
					$declared
				)
			);
		}

		$valid = rest_validate_value_from_schema( $value, $schema, 'value' );
		if ( is_wp_error( $valid ) ) {
			return WP_MCP_Errors::post_type_validation_error( $valid->get_error_message() );
		}

		$sanitized = rest_sanitize_value_from_schema( $value, $schema );
		if ( is_wp_error( $sanitized ) ) {
			return WP_MCP_Errors::post_type_validation_error( $sanitized->get_error_message() );
		}

		return $sanitized;
	}

	/* ------------------------------------------------------------------
	 * Formatters
	 * ---------------------------------------------------------------- */

	/**
	 * The fixed capability slots reported by discovery.
	 *
	 * A closed list rather than `(array) $post_type->cap`: a type may add
	 * arbitrary custom slots, and the output schema stays closed only if the
	 * key set does not depend on caller-visible registration data.
	 *
	 * Public so the discovery output schema can be generated from this exact
	 * list instead of restating it — the declared surface and the emitted
	 * surface cannot drift apart.
	 *
	 * @return string[]
	 */
	public static function capability_slots() {
		return array(
			'create_posts',
			'edit_post',
			'edit_posts',
			'edit_others_posts',
			'publish_posts',
			'read_post',
			'read_private_posts',
			'delete_post',
			'delete_posts',
		);
	}

	/**
	 * Format a post type's registration detail.
	 *
	 * @param WP_Post_Type $post_type Post type object.
	 * @param array        $state     Operability verdict.
	 * @return array
	 */
	private static function format_post_type( $post_type, $state ) {
		$capabilities = array();
		foreach ( self::capability_slots() as $slot ) {
			$capabilities[ $slot ] = ! empty( $post_type->cap->$slot ) && is_string( $post_type->cap->$slot ) ? $post_type->cap->$slot : '';
		}

		return array(
			'name'            => $post_type->name,
			'label'           => (string) $post_type->label,
			'singular_label'  => isset( $post_type->labels->singular_name ) ? $post_type->labels->singular_name : $post_type->name,
			'description'     => (string) $post_type->description,
			'hierarchical'    => (bool) $post_type->hierarchical,
			'public'          => (bool) $post_type->public,
			'show_ui'         => (bool) $post_type->show_ui,
			'show_in_rest'    => (bool) $post_type->show_in_rest,
			'has_archive'     => ! empty( $post_type->has_archive ),
			'built_in'        => ! empty( $post_type->_builtin ),
			'map_meta_cap'    => ! empty( $post_type->map_meta_cap ),
			'capability_type' => implode( ',', array_map( 'strval', (array) $post_type->capability_type ) ),
			'supports'        => self::supported_features( $post_type->name ),
			'taxonomies'      => self::taxonomy_names( $post_type->name ),
			'capabilities'    => $capabilities,
			'permissions'     => array(
				'can_create'  => ! empty( $post_type->cap->create_posts ) && current_user_can( $post_type->cap->create_posts ),
				'can_edit'    => ! empty( $post_type->cap->edit_posts ) && current_user_can( $post_type->cap->edit_posts ),
				'can_publish' => ! empty( $post_type->cap->publish_posts ) && current_user_can( $post_type->cap->publish_posts ),
				'can_delete'  => ! empty( $post_type->cap->delete_posts ) && current_user_can( $post_type->cap->delete_posts ),
			),
			'operable'        => (bool) $state['operable'],
			'reason'          => $state['reason'],
		);
	}

	/**
	 * The features a post type declares support for, as plain strings.
	 *
	 * `WP_Post_Type::$supports` is unset by core once registration has run
	 * (`WP_Post_Type::add_supports()`), so the registry API is the only
	 * correct source here.
	 *
	 * @param string $post_type Post type name.
	 * @return string[]
	 */
	private static function supported_features( $post_type ) {
		$features = array();
		foreach ( array_keys( get_all_post_type_supports( $post_type ) ) as $feature ) {
			$features[] = (string) $feature;
		}
		return $features;
	}

	/**
	 * The taxonomy names registered for a post type, as plain strings.
	 *
	 * @param string $post_type Post type name.
	 * @return string[]
	 */
	private static function taxonomy_names( $post_type ) {
		$names = array();
		foreach ( get_object_taxonomies( $post_type, 'names' ) as $name ) {
			if ( is_string( $name ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Re-read one entry and format it, falling back to the pre-operation
	 * object when it no longer exists.
	 *
	 * Every mutating method re-reads before formatting so the response
	 * reports the *new* status rather than the stale object it started from.
	 * `wp_trash_post()` is the one case where the entry can legitimately be
	 * gone afterwards — with `EMPTY_TRASH_DAYS` set to 0 it permanently
	 * deletes instead of trashing — so the re-read has to be nullable and
	 * the caller must never dereference it blindly.
	 *
	 * @param WP_Post $post Entry as it was before the operation.
	 * @return array
	 */
	private static function refreshed_summary( $post ) {
		$refreshed = get_post( $post->ID );
		return self::format_custom_post_summary( $refreshed instanceof WP_Post ? $refreshed : $post );
	}

	/**
	 * Format one entry for list/create/update/lifecycle responses.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function format_custom_post_summary( $post ) {
		return array(
			'id'        => (int) $post->ID,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'author'    => (int) $post->post_author,
			'slug'      => $post->post_name,
			'date'      => $post->post_date,
			'modified'  => $post->post_modified,
			'link'      => (string) get_permalink( $post->ID ),
		);
	}

	/**
	 * Format one entry for the read response.
	 *
	 * @param WP_Post      $post      Post object.
	 * @param WP_Post_Type $post_type Post type object.
	 * @return array
	 */
	private static function format_custom_post_detail( $post, $post_type ) {
		$terms = array();
		foreach ( self::taxonomy_names( $post_type->name ) as $taxonomy ) {
			$term_ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			$terms[]  = array(
				'taxonomy' => $taxonomy,
				'term_ids' => is_array( $term_ids ) ? array_values( array_map( 'intval', $term_ids ) ) : array(),
			);
		}

		$meta = array();
		foreach ( self::registered_meta_keys( $post_type->name ) as $key => $args ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$state = self::meta_operability( $key, $args );
			if ( ! $state['operable'] ) {
				continue;
			}
			$meta[] = array( 'key' => $key, 'value' => get_post_meta( $post->ID, $key, true ) );
		}

		return array_merge(
			self::format_custom_post_summary( $post ),
			array(
				'content'        => $post->post_content,
				'excerpt'        => $post->post_excerpt,
				'parent'         => (int) $post->post_parent,
				'menu_order'     => (int) $post->menu_order,
				'featured_media' => post_type_supports( $post_type->name, 'thumbnail' ) ? (int) get_post_thumbnail_id( $post->ID ) : 0,
				'supports'       => self::supported_features( $post_type->name ),
				'terms'          => $terms,
				'meta'           => $meta,
			)
		);
	}
}
