<?php
/**
 * WordPress MCP Abilities — Taxonomy callbacks.
 *
 * Explicit discovery, term CRUD, registered term metadata, and assignment
 * operations. Taxonomy names and term IDs are always validated together;
 * arbitrary database/meta access is not exposed.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.5.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Taxonomies
 */
class WP_MCP_Taxonomies {

	/** Maximum terms returned by one list request. */
	const MAX_TERMS_PER_PAGE = 50;

	/** Maximum objects in one bulk assignment request. */
	const MAX_BULK_OBJECTS = 20;

	/* ------------------------------------------------------------------
	 * Existing core convenience abilities
	 * ---------------------------------------------------------------- */

	/**
	 * List all categories.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public static function list_categories( $input ) {
		return self::list_legacy_terms( 'category' );
	}

	/**
	 * List all tags.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public static function list_tags( $input ) {
		return self::list_legacy_terms( 'post_tag' );
	}

	/**
	 * @param string $taxonomy Core taxonomy.
	 * @return array
	 */
	private static function list_legacy_terms( $taxonomy ) {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 200, 'orderby' => 'name', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) {
			return array( 'terms' => array() );
		}
		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => (int) $term->count );
		}
		return array( 'terms' => $items );
	}

	/* ------------------------------------------------------------------
	 * Taxonomy discovery
	 * ---------------------------------------------------------------- */

	/**
	 * List registered taxonomies, optionally filtered by post type.
	 *
	 * @param array $input { @type string $object_type Optional post type. }
	 * @return array
	 */
	public static function list_taxonomies( $input ) {
		$object_type = ! empty( $input['object_type'] ) ? sanitize_key( $input['object_type'] ) : '';
		$taxonomies  = get_taxonomies( array(), 'objects' );
		$items       = array();
		foreach ( $taxonomies as $taxonomy ) {
			if ( $object_type && ! in_array( $object_type, (array) $taxonomy->object_type, true ) ) {
				continue;
			}
			$items[] = self::format_taxonomy( $taxonomy );
		}
		return array( 'taxonomies' => $items );
	}

	/**
	 * Get one registered taxonomy's public registration details.
	 *
	 * @param array $input { @type string $taxonomy Required. }
	 * @return array|WP_Error
	 */
	public static function get_taxonomy( $input ) {
		$taxonomy = self::validate_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		return self::format_taxonomy( $taxonomy );
	}

	/* ------------------------------------------------------------------
	 * Term read operations
	 * ---------------------------------------------------------------- */

	/**
	 * List terms for a registered taxonomy.
	 *
	 * @param array $input Taxonomy and query parameters.
	 * @return array|WP_Error
	 */
	public static function list_terms( $input ) {
		$taxonomy = self::validate_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 20, self::MAX_TERMS_PER_PAGE );
		$args = array(
			'taxonomy'               => $taxonomy->name,
			'hide_empty'             => isset( $input['hide_empty'] ) ? (bool) $input['hide_empty'] : false,
			'number'                 => $per_page,
			'offset'                 => ( $page - 1 ) * $per_page,
			'orderby'                => 'name',
			'order'                  => 'ASC',
			'update_term_meta_cache' => false,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = sanitize_text_field( $input['search'] );
		}
		if ( isset( $input['parent'] ) ) {
			$parent = absint( $input['parent'] );
			if ( $parent > 0 ) {
				if ( ! $taxonomy->hierarchical ) {
					return WP_MCP_Errors::taxonomy_validation_error( __( 'A non-hierarchical taxonomy cannot filter by parent.', 'wordpress-mcp-abilities' ) );
				}
				$parent_term = self::validate_term( $taxonomy->name, $parent );
				if ( is_wp_error( $parent_term ) ) {
					return WP_MCP_Errors::invalid_term( __( 'The requested parent term does not exist in this taxonomy.', 'wordpress-mcp-abilities' ) );
				}
			}
			$args['parent'] = $parent;
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return WP_MCP_Errors::term_query_failed( $terms->get_error_message() );
		}
		$count_args             = $args;
		$count_args['number']   = 0;
		$count_args['offset']   = 0;
		$count_args['fields']   = 'count';
		$count_result            = get_terms( $count_args );
		$total                   = is_wp_error( $count_result ) ? 0 : (int) $count_result;
		$items                  = array();
		foreach ( $terms as $term ) {
			$items[] = self::format_term( $term, false );
		}
		return array(
			'taxonomy'    => $taxonomy->name,
			'terms'       => $items,
			'total'       => $total,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get one term, including registered REST-visible term metadata.
	 *
	 * @param array $input { @type string $taxonomy @type int $term_id }
	 * @return array|WP_Error
	 */
	public static function get_term( $input ) {
		$taxonomy = self::validate_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$term = self::validate_term( $taxonomy->name, isset( $input['term_id'] ) ? $input['term_id'] : 0 );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		return self::format_term( $term, true );
	}

	/* ------------------------------------------------------------------
	 * Term CRUD
	 * ---------------------------------------------------------------- */

	/**
	 * Create a term in a registered taxonomy.
	 *
	 * @param array $input Taxonomy, name, and optional slug/description/parent.
	 * @return array|WP_Error
	 */
	public static function create_term( $input ) {
		$taxonomy = self::validate_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$permission = self::require_taxonomy_capability( $taxonomy, 'manage_terms' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			return WP_MCP_Errors::taxonomy_validation_error( __( 'Term name is required.', 'wordpress-mcp-abilities' ) );
		}
		$args = self::term_update_args( $input, $taxonomy, 0 );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		unset( $args['name'] );
		$result = wp_insert_term( $name, $taxonomy->name, $args );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-term', 0, false, 'wp_mcp_term_create_failed', array( 'taxonomy' => $taxonomy->name ) );
			return WP_MCP_Errors::term_create_failed( $result->get_error_message() );
		}
		$term = self::validate_term( $taxonomy->name, $result['term_id'] );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		WP_MCP_Audit::log( 'wp-mcp/create-term', $term->term_id, true );
		return self::format_term( $term, true );
	}

	/**
	 * Update explicit fields on an existing term.
	 *
	 * @param array $input Taxonomy, term ID, and one or more fields.
	 * @return array|WP_Error
	 */
	public static function update_term( $input ) {
		$taxonomy = self::validate_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$term = self::validate_term( $taxonomy->name, isset( $input['term_id'] ) ? $input['term_id'] : 0 );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$permission = self::require_taxonomy_capability( $taxonomy, 'edit_terms' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$args = self::term_update_args( $input, $taxonomy, $term->term_id );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		if ( empty( $args ) ) {
			return WP_MCP_Errors::taxonomy_validation_error( __( 'At least one term field is required.', 'wordpress-mcp-abilities' ) );
		}
		$result = wp_update_term( $term->term_id, $taxonomy->name, $args );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-term', $term->term_id, false, 'wp_mcp_term_update_failed', array( 'taxonomy' => $taxonomy->name ) );
			return WP_MCP_Errors::term_update_failed( $result->get_error_message() );
		}
		$updated = self::validate_term( $taxonomy->name, $term->term_id );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		WP_MCP_Audit::log( 'wp-mcp/update-term', $term->term_id, true );
		return self::format_term( $updated, true );
	}

	/**
	 * Delete a term using WordPress's taxonomy API.
	 *
	 * @param array $input { @type string $taxonomy @type int $term_id }
	 * @return array|WP_Error
	 */
	public static function delete_term( $input ) {
		$taxonomy = self::validate_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$term = self::validate_term( $taxonomy->name, isset( $input['term_id'] ) ? $input['term_id'] : 0 );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$permission = self::require_taxonomy_capability( $taxonomy, 'delete_terms' );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$result = wp_delete_term( $term->term_id, $taxonomy->name );
		if ( is_wp_error( $result ) || ! $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'WordPress did not delete the term.', 'wordpress-mcp-abilities' );
			WP_MCP_Audit::log( 'wp-mcp/delete-term', $term->term_id, false, 'wp_mcp_term_delete_failed', array( 'taxonomy' => $taxonomy->name ) );
			return WP_MCP_Errors::term_delete_failed( $message );
		}
		WP_MCP_Audit::log( 'wp-mcp/delete-term', $term->term_id, true );
		return array( 'id' => (int) $term->term_id, 'taxonomy' => $taxonomy->name, 'deleted' => true );
	}

	/* ------------------------------------------------------------------
	 * Object-term relationships
	 * ---------------------------------------------------------------- */

	/**
	 * Add terms to an editable post/page/CPT without removing existing terms.
	 *
	 * @param array $input Taxonomy, object type/ID, and term IDs.
	 * @return array|WP_Error
	 */
	public static function assign_terms( $input ) {
		$context = self::validate_object_context( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$term_ids = self::validate_term_ids( $context['taxonomy']->name, isset( $input['term_ids'] ) ? $input['term_ids'] : array() );
		if ( is_wp_error( $term_ids ) ) {
			return $term_ids;
		}
		$result = wp_set_object_terms( $context['object_id'], $term_ids, $context['taxonomy']->name, true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/assign-terms', $context['object_id'], false, 'wp_mcp_term_assignment_failed', array( 'taxonomy' => $context['taxonomy']->name ) );
			return WP_MCP_Errors::term_assignment_failed( $result->get_error_message() );
		}
		WP_MCP_Audit::log( 'wp-mcp/assign-terms', $context['object_id'], true );
		return array( 'taxonomy' => $context['taxonomy']->name, 'object_id' => $context['object_id'], 'object_type' => $context['object_type'], 'term_ids' => array_map( 'intval', $term_ids ) );
	}

	/**
	 * Remove terms from an editable post/page/CPT.
	 *
	 * @param array $input Taxonomy, object type/ID, and term IDs.
	 * @return array|WP_Error
	 */
	public static function remove_terms( $input ) {
		$context = self::validate_object_context( $input );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$term_ids = self::validate_term_ids( $context['taxonomy']->name, isset( $input['term_ids'] ) ? $input['term_ids'] : array() );
		if ( is_wp_error( $term_ids ) ) {
			return $term_ids;
		}
		$result = wp_remove_object_terms( $context['object_id'], $term_ids, $context['taxonomy']->name );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/remove-terms', $context['object_id'], false, 'wp_mcp_term_assignment_failed', array( 'taxonomy' => $context['taxonomy']->name ) );
			return WP_MCP_Errors::term_assignment_failed( $result->get_error_message() );
		}
		WP_MCP_Audit::log( 'wp-mcp/remove-terms', $context['object_id'], true );
		return array( 'taxonomy' => $context['taxonomy']->name, 'object_id' => $context['object_id'], 'object_type' => $context['object_type'], 'term_ids' => array_map( 'intval', $term_ids ) );
	}

	/**
	 * Assign the same terms to up to 20 objects.
	 *
	 * @param array $input Taxonomy, object type/IDs, and term IDs.
	 * @return array|WP_Error
	 */
	public static function bulk_assign_terms( $input ) {
		$ids = isset( $input['object_ids'] ) && is_array( $input['object_ids'] ) ? $input['object_ids'] : array();
		if ( count( $ids ) > self::MAX_BULK_OBJECTS ) {
			return WP_MCP_Errors::bulk_limit_exceeded( self::MAX_BULK_OBJECTS );
		}
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return WP_MCP_Errors::taxonomy_validation_error( __( 'At least one object ID is required.', 'wordpress-mcp-abilities' ) );
		}
		$results = array();
		$assigned = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			$item = $input;
			$item['object_id'] = $id;
			$result = self::assign_terms( $item );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'object_id' => $id, 'success' => false, 'error_code' => $result->get_error_code() );
				++$failed;
			} else {
				$results[] = array( 'object_id' => $id, 'success' => true );
				++$assigned;
			}
		}
		// The bulk result is only a success when every object succeeded — a run
		// where all 20 were refused must not be logged as one.
		WP_MCP_Audit::log( 'wp-mcp/bulk-assign-terms', 0, 0 === $failed, 0 === $failed ? '' : 'wp_mcp_term_assignment_failed', array( 'assigned' => $assigned, 'failed' => $failed ) );
		return array( 'results' => $results, 'assigned' => $assigned, 'failed' => $failed );
	}

	/**
	 * Remove the same terms from up to 20 objects.
	 *
	 * @param array $input Taxonomy, object type/IDs, and term IDs.
	 * @return array|WP_Error
	 */
	public static function bulk_remove_terms( $input ) {
		$ids = isset( $input['object_ids'] ) && is_array( $input['object_ids'] ) ? $input['object_ids'] : array();
		if ( count( $ids ) > self::MAX_BULK_OBJECTS ) {
			return WP_MCP_Errors::bulk_limit_exceeded( self::MAX_BULK_OBJECTS );
		}
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return WP_MCP_Errors::taxonomy_validation_error( __( 'At least one object ID is required.', 'wordpress-mcp-abilities' ) );
		}
		$results = array();
		$removed = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			$item = $input;
			$item['object_id'] = $id;
			$result = self::remove_terms( $item );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'object_id' => $id, 'success' => false, 'error_code' => $result->get_error_code() );
				++$failed;
			} else {
				$results[] = array( 'object_id' => $id, 'success' => true );
				++$removed;
			}
		}
		WP_MCP_Audit::log( 'wp-mcp/bulk-remove-terms', 0, 0 === $failed, 0 === $failed ? '' : 'wp_mcp_term_assignment_failed', array( 'removed' => $removed, 'failed' => $failed ) );
		return array( 'results' => $results, 'removed' => $removed, 'failed' => $failed );
	}

	/* ------------------------------------------------------------------
	 * Registered term metadata
	 * ---------------------------------------------------------------- */

	/**
	 * Read one registered, REST-visible term metadata key.
	 *
	 * @param array $input Taxonomy, term ID, meta key.
	 * @return array|WP_Error
	 */
	public static function get_term_meta( $input ) {
		$term = self::validate_term_input( $input );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$key = self::validate_registered_meta_key( $term->taxonomy, isset( $input['meta_key'] ) ? $input['meta_key'] : '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return array( 'taxonomy' => $term->taxonomy, 'term_id' => (int) $term->term_id, 'meta_key' => $key['key'], 'value' => get_term_meta( $term->term_id, $key['key'], true ) );
	}

	/**
	 * Update one registered term metadata key after schema validation.
	 *
	 * @param array $input Taxonomy, term ID, meta key, value.
	 * @return array|WP_Error
	 */
	public static function update_term_meta( $input ) {
		$term = self::validate_term_input( $input );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$key = self::validate_registered_meta_key( $term->taxonomy, isset( $input['meta_key'] ) ? $input['meta_key'] : '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( ! current_user_can( 'edit_term_meta', $term->term_id, $key['key'] ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to edit this term metadata.', 'wordpress-mcp-abilities' ) );
		}
		$value = self::sanitize_registered_meta_value( $input['value'] ?? null, $key['args'] );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$result = update_term_meta( $term->term_id, $key['key'], $value );
		if ( false === $result && get_term_meta( $term->term_id, $key['key'], true ) !== $value ) {
			return WP_MCP_Errors::term_meta_update_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/update-term-meta', $term->term_id, true );
		return array( 'taxonomy' => $term->taxonomy, 'term_id' => (int) $term->term_id, 'meta_key' => $key['key'], 'value' => get_term_meta( $term->term_id, $key['key'], true ) );
	}

	/**
	 * Delete one registered term metadata key. Idempotent when absent.
	 *
	 * @param array $input Taxonomy, term ID, meta key.
	 * @return array|WP_Error
	 */
	public static function delete_term_meta( $input ) {
		$term = self::validate_term_input( $input );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$key = self::validate_registered_meta_key( $term->taxonomy, isset( $input['meta_key'] ) ? $input['meta_key'] : '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( ! current_user_can( 'edit_term_meta', $term->term_id, $key['key'] ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to delete this term metadata.', 'wordpress-mcp-abilities' ) );
		}
		delete_term_meta( $term->term_id, $key['key'] );
		WP_MCP_Audit::log( 'wp-mcp/delete-term-meta', $term->term_id, true );
		return array( 'taxonomy' => $term->taxonomy, 'term_id' => (int) $term->term_id, 'meta_key' => $key['key'], 'deleted' => true );
	}

	/* ------------------------------------------------------------------
	 * Validation / formatting helpers
	 * ---------------------------------------------------------------- */

	/** @param mixed $name @return WP_Taxonomy|WP_Error */
	private static function validate_taxonomy( $name ) {
		if ( ! is_string( $name ) || '' === trim( $name ) || ! taxonomy_exists( $name ) ) {
			return WP_MCP_Errors::invalid_taxonomy();
		}
		$taxonomy = get_taxonomy( $name );
		return $taxonomy ? $taxonomy : WP_MCP_Errors::invalid_taxonomy();
	}

	/** @param string $taxonomy @param mixed $term_id @return WP_Term|WP_Error */
	private static function validate_term( $taxonomy, $term_id ) {
		$term_id = WP_MCP_Permissions::validate_positive_int( $term_id, 'term_id' );
		if ( is_wp_error( $term_id ) ) {
			return $term_id;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== $taxonomy ) {
			return WP_MCP_Errors::invalid_term();
		}
		return $term;
	}

	/** @param WP_Taxonomy $taxonomy @param string $capability @return true|WP_Error */
	private static function require_taxonomy_capability( $taxonomy, $capability ) {
		$capability = sanitize_key( $capability );
		if ( empty( $taxonomy->cap->$capability ) || ! current_user_can( $taxonomy->cap->$capability ) ) {
			return WP_MCP_Errors::taxonomy_permission_denied( $capability );
		}
		return true;
	}

	/** @param array $input @return array|WP_Error */
	private static function validate_object_context( $input ) {
		$taxonomy = self::validate_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$object_type = isset( $input['object_type'] ) ? sanitize_key( $input['object_type'] ) : '';
		$object_id   = WP_MCP_Permissions::validate_positive_int( isset( $input['object_id'] ) ? $input['object_id'] : 0, 'object_id' );
		if ( is_wp_error( $object_id ) || '' === $object_type ) {
			return is_wp_error( $object_id ) ? $object_id : WP_MCP_Errors::taxonomy_validation_error( __( 'object_type is required.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! in_array( $object_type, (array) $taxonomy->object_type, true ) ) {
			return WP_MCP_Errors::taxonomy_object_type_mismatch();
		}
		$post = get_post( $object_id );
		if ( ! $post || $post->post_type !== $object_type ) {
			return WP_MCP_Errors::taxonomy_object_type_mismatch();
		}
		if ( ! current_user_can( 'edit_post', $object_id ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to edit this object.', 'wordpress-mcp-abilities' ) );
		}
		$permission = self::require_taxonomy_capability( $taxonomy, 'assign_terms' );
		return is_wp_error( $permission ) ? $permission : array( 'taxonomy' => $taxonomy, 'object_id' => $object_id, 'object_type' => $object_type );
	}

	/** @param string $taxonomy @param mixed $ids @return int[]|WP_Error */
	private static function validate_term_ids( $taxonomy, $ids ) {
		if ( ! is_array( $ids ) || empty( $ids ) || count( $ids ) > 100 ) {
			return WP_MCP_Errors::taxonomy_validation_error( __( 'term_ids must contain between 1 and 100 IDs.', 'wordpress-mcp-abilities' ) );
		}
		$result = array();
		foreach ( $ids as $id ) {
			$term = self::validate_term( $taxonomy, $id );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			$result[] = (int) $term->term_id;
		}
		return array_values( array_unique( $result ) );
	}

	/** @param array $input @param WP_Taxonomy $taxonomy @param int $term_id @return array|WP_Error */
	private static function term_update_args( $input, $taxonomy, $term_id ) {
		$args = array();
		if ( isset( $input['name'] ) ) {
			$args['name'] = sanitize_text_field( $input['name'] );
		}
		if ( isset( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = wp_kses_post( $input['description'] );
		}
		if ( isset( $input['parent'] ) ) {
			if ( ! $taxonomy->hierarchical ) {
				return WP_MCP_Errors::taxonomy_validation_error( __( 'Only hierarchical taxonomies support parent.', 'wordpress-mcp-abilities' ) );
			}
			$parent = absint( $input['parent'] );
			if ( $parent === (int) $term_id ) {
				return WP_MCP_Errors::taxonomy_validation_error( __( 'A term cannot be its own parent.', 'wordpress-mcp-abilities' ) );
			}
			if ( $parent && is_wp_error( self::validate_term( $taxonomy->name, $parent ) ) ) {
				return WP_MCP_Errors::invalid_term( __( 'The parent term does not exist in this taxonomy.', 'wordpress-mcp-abilities' ) );
			}
			if ( $parent && $term_id && self::parent_would_cycle( $taxonomy->name, $parent, $term_id ) ) {
				return WP_MCP_Errors::taxonomy_validation_error( __( 'The requested parent would create a hierarchy cycle.', 'wordpress-mcp-abilities' ) );
			}
			$args['parent'] = $parent;
		}
		return $args;
	}

	/** @param string $taxonomy @param int $ancestor_id @param int $term_id @return bool */
	private static function parent_would_cycle( $taxonomy, $ancestor_id, $term_id ) {
		$seen = array();
		while ( $ancestor_id > 0 && ! isset( $seen[ $ancestor_id ] ) ) {
			if ( $ancestor_id === (int) $term_id ) {
				return true;
			}
			$seen[ $ancestor_id ] = true;
			$term = get_term( $ancestor_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				break;
			}
			$ancestor_id = (int) $term->parent;
		}
		return false;
	}

	/** @param array $input @return WP_Term|WP_Error */
	private static function validate_term_input( $input ) {
		$taxonomy = self::validate_taxonomy( isset( $input['taxonomy'] ) ? $input['taxonomy'] : '' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		return self::validate_term( $taxonomy->name, isset( $input['term_id'] ) ? $input['term_id'] : 0 );
	}

	/** @param string $taxonomy @param mixed $key @return array|WP_Error */
	private static function validate_registered_meta_key( $taxonomy, $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return WP_MCP_Errors::term_meta_not_registered();
		}
		$registered = get_registered_meta_keys( 'term', $taxonomy );
		$global     = get_registered_meta_keys( 'term', '' );
		$args       = isset( $registered[ $key ] ) ? $registered[ $key ] : ( isset( $global[ $key ] ) ? $global[ $key ] : null );
		if ( ! is_array( $args ) || empty( $args['show_in_rest'] ) ) {
			return WP_MCP_Errors::term_meta_not_registered();
		}
		return array( 'key' => $key, 'args' => $args );
	}

	/** @param mixed $value @param array $args @return mixed|WP_Error */
	private static function sanitize_registered_meta_value( $value, $args ) {
		$show = isset( $args['show_in_rest'] ) && is_array( $args['show_in_rest'] ) ? $args['show_in_rest'] : array();
		$schema = isset( $show['schema'] ) && is_array( $show['schema'] ) ? $show['schema'] : array( 'type' => isset( $args['type'] ) ? $args['type'] : 'string' );
		$valid = rest_validate_value_from_schema( $value, $schema, 'value' );
		if ( is_wp_error( $valid ) ) {
			return WP_MCP_Errors::taxonomy_validation_error( $valid->get_error_message() );
		}
		return rest_sanitize_value_from_schema( $value, $schema );
	}

	/** @param WP_Taxonomy $taxonomy @return array */
	private static function format_taxonomy( $taxonomy ) {
		return array(
			'name'          => $taxonomy->name,
			'label'         => $taxonomy->label,
			'singular_label' => isset( $taxonomy->labels->singular_name ) ? $taxonomy->labels->singular_name : $taxonomy->label,
			'object_types'  => array_values( array_map( 'sanitize_key', (array) $taxonomy->object_type ) ),
			'hierarchical'  => (bool) $taxonomy->hierarchical,
			'public'        => (bool) $taxonomy->public,
			'show_ui'       => (bool) $taxonomy->show_ui,
			'show_in_rest'  => (bool) $taxonomy->show_in_rest,
			'capabilities'  => array(
				'manage_terms' => $taxonomy->cap->manage_terms,
				'edit_terms'   => $taxonomy->cap->edit_terms,
				'delete_terms' => $taxonomy->cap->delete_terms,
				'assign_terms' => $taxonomy->cap->assign_terms,
			),
		);
	}

	/** @param WP_Term $term @param bool $include_meta @return array */
	private static function format_term( $term, $include_meta ) {
		$result = array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		);
		if ( $include_meta ) {
			$result['meta'] = self::registered_term_meta( $term );
		}
		return $result;
	}

	/** @param WP_Term $term @return array */
	private static function registered_term_meta( $term ) {
		$registered = get_registered_meta_keys( 'term', $term->taxonomy );
		$global     = get_registered_meta_keys( 'term', '' );
		$keys       = array_merge( $global, $registered );
		$result     = array();
		foreach ( $keys as $key => $args ) {
			if ( is_array( $args ) && ! empty( $args['show_in_rest'] ) ) {
				$result[ $key ] = get_term_meta( $term->term_id, $key, true );
			}
		}
		return $result;
	}
}
