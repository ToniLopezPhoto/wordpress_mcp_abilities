<?php
/**
 * WordPress MCP Abilities — Global discovery domain (issue #16).
 *
 * The read-only layer an agent consults *before* it mutates anything: what
 * content exists and is reachable for this caller, which post types,
 * statuses, mime types, block types, pattern categories and template areas
 * are registered, what the current user may actually do, and which features
 * this installation supports.
 *
 * The conservative model, which is the architecture decision this domain is
 * built on: every response is made of normalised identifiers, fixed
 * vocabularies, booleans and counts. Nothing else. A WordPress registry is an
 * open surface — any plugin may register anything under any key — so a title,
 * a label, a description, a keyword, a registration map, a default or enum
 * value, a callback, a path, a URL, an option name or any other free text that
 * reached a registry is *not* reported, whatever it says. An identifier is
 * emitted only when it matches an anchored, length-bounded grammar, a
 * vocabulary field only when it is in its allowlist, and a collection only up
 * to an explicit ceiling that the response declares. No registry value is ever
 * cast, so an object or a closure parked in one cannot reach `strval()` and
 * take discovery down with it.
 *
 * Grammars, vocabularies and ceilings all live in
 * `WP_MCP_Discovery_Contract`, which `WP_MCP_Discovery_Abilities` also
 * generates the MCP output schemas from, so what this file enforces and what
 * the published schema promises cannot drift apart.
 *
 * Three lines this domain never crosses (epic #1):
 *
 *  - It is not a REST explorer/runner. Every ability is a fixed operation
 *    with its own schema; none of them accepts a route, an endpoint, a
 *    callback, an option name, a filesystem path or a query to execute.
 *  - It never reports a value it was not asked to describe. Block types are
 *    described by allowlisted supports and attribute *names*, never by their
 *    `render_callback`; theme feature support is reported as booleans, never
 *    as the registered arguments, which for `custom-header` and
 *    `custom-background` contain PHP callbacks and asset paths.
 *  - Global search resolves visibility per object, through the caller's own
 *    capabilities: the query is narrowed to what this user may see and every
 *    surviving row is re-checked with the native `read_post` meta-capability
 *    before it is returned.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.16.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Discovery
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Discovery {

	/* ------------------------------------------------------------------
	 * The contract
	 *
	 * The conservative model: this domain answers with normalised
	 * identifiers, fixed vocabularies, booleans and counts — never with a
	 * label, a title, a description, a registration map, a default or enum
	 * value, a callback, a path, a URL, an option name or any other free
	 * text a plugin was able to put into a WordPress registry. An identifier
	 * is only reported when it matches an anchored, length-bounded grammar;
	 * anything else is dropped whole, and nothing is ever cast, so an object
	 * or a closure in a registry cannot reach `strval()`.
	 *
	 * Every grammar, vocabulary and ceiling below is read from
	 * `WP_MCP_Discovery_Contract`, which is also what
	 * `WP_MCP_Discovery_Abilities` generates the MCP output schemas from.
	 * One definition, two consumers: the runtime cannot bound a list at a
	 * number the published schema disagrees with, and a name this code emits
	 * cannot fail the `pattern` the client validates against.
	 * ---------------------------------------------------------------- */

	/**
	 * One ceiling from the contract.
	 *
	 * @since 0.16.0
	 *
	 * @param string $name Limit name.
	 * @return int
	 */
	private static function limit( $name ) {
		return WP_MCP_Discovery_Contract::limit( $name );
	}

	/**
	 * One PHP grammar pattern from the contract.
	 *
	 * @since 0.16.0
	 *
	 * @param string $name Grammar name.
	 * @return string
	 */
	private static function grammar( $name ) {
		return WP_MCP_Discovery_Contract::preg_pattern( $name );
	}

	/**
	 * The length bound of one grammar.
	 *
	 * @since 0.16.0
	 *
	 * @param string $name Grammar name.
	 * @return int
	 */
	private static function grammar_length( $name ) {
		return WP_MCP_Discovery_Contract::max_length( $name );
	}

	/**
	 * A cardinality, reported inside the `maximum` its own schema publishes.
	 *
	 * Most of the counts below are `count()` of a list this domain already
	 * bounded, so they fit by construction. The ones that do not — the
	 * cardinality of a WordPress registry a plugin may grow freely — are the
	 * reason this exists: a raw `count( get_post_types() )` is a number of
	 * somebody else's choosing, and publishing it would break the very
	 * schema the ability ships with. Routing every count through here means
	 * the honest answer, `capped`, is what a client gets instead.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed  $value   Raw number.
	 * @param string $bound   Limit name this count may not exceed.
	 * @param bool   $clamped Set to true when the value did not fit.
	 * @return int
	 */
	private static function bounded_count( $value, $bound, &$clamped = null ) {
		return WP_MCP_Discovery_Contract::bounded_count( $value, $bound, $clamped );
	}

	/**
	 * A database identity, reported only when it fits its published window.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $value    Raw identifier.
	 * @param bool  $overflow Set to true when the value is not reportable.
	 * @return int
	 */
	private static function bounded_id( $value, &$overflow = null ) {
		return WP_MCP_Discovery_Contract::bounded_id( $value, $overflow );
	}

	/**
	 * Clamp pagination input, refusing a page above the published ceiling.
	 *
	 * `page` is echoed back in the response, and the response schema bounds
	 * it at `max_page`. Clamping a request for page 10^9 down to that
	 * ceiling would answer with rows the client never asked for, so the
	 * request is refused instead — the one honest option that also keeps the
	 * response inside its own schema. Nothing above `max_page` can address a
	 * row anyway: no discovery collection reports more than `block_scan`
	 * rows and `per_page` is at least one.
	 *
	 * @since 0.16.0
	 *
	 * @param array $input            Raw ability input.
	 * @param int   $default_per_page Default page size.
	 * @return array{0:int,1:int}|WP_Error
	 */
	private static function paginate( $input, $default_per_page ) {
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, $default_per_page, self::limit( 'page_size' ) );

		if ( $page > self::limit( 'max_page' ) ) {
			return WP_MCP_Errors::discovery_validation_error(
				sprintf(
					/* translators: %d: the highest page number this domain answers. */
					__( 'page must not be greater than %d.', 'wordpress-mcp-abilities' ),
					self::limit( 'max_page' )
				)
			);
		}

		return array( $page, $per_page );
	}

	/**
	 * The object kinds global search understands.
	 *
	 * @since 0.16.0
	 *
	 * @return string[]
	 */
	public static function searchable_object_types() {
		return WP_MCP_Discovery_Contract::vocabulary( 'search_object_type' );
	}

	/* ==================================================================
	 * Permission callbacks
	 * ================================================================ */

	/**
	 * Whether the caller may read this site at all.
	 *
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'read' );
	}

	/**
	 * Whether the caller holds the editor-facing capability.
	 *
	 * @return bool
	 */
	public static function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Whether the caller may upload, which is what decides the mime types
	 * WordPress will accept from them.
	 *
	 * @return bool
	 */
	public static function can_upload_files() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Whether the caller administers the active theme.
	 *
	 * @return bool
	 */
	public static function can_edit_theme_options() {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Whether there is an authenticated user at all.
	 *
	 * @return bool
	 */
	public static function is_authenticated() {
		return is_user_logged_in();
	}

	/* ==================================================================
	 * Global search
	 * ================================================================ */

	/**
	 * Search posts, media, terms and users, filtered by what this caller may
	 * actually see.
	 *
	 * When `capped` is true the result set was truncated — either a branch hit
	 * its own limit or the merge hit the overall ceiling — and `total` and
	 * `total_pages` are therefore lower bounds, not the size of the match. A
	 * client that needs completeness narrows the search or the `types` filter;
	 * it must not read the last page as "there is nothing else".
	 *
	 * @param array $input {
	 *     @type string   $search   Required. 2-200 characters.
	 *     @type string[] $types    Optional. Subset of post/media/term/user.
	 *     @type int      $page     Optional. Page number.
	 *     @type int      $per_page Optional. Results per page.
	 * }
	 * @return array|WP_Error
	 */
	public static function global_search( $input ) {
		if ( ! self::can_read() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to search this site.', 'wordpress-mcp-abilities' ) );
		}

		$search = isset( $input['search'] ) && is_string( $input['search'] ) ? trim( sanitize_text_field( $input['search'] ) ) : '';
		if ( strlen( $search ) < 2 || strlen( $search ) > 200 ) {
			return WP_MCP_Errors::discovery_validation_error( __( 'search must be between 2 and 200 characters.', 'wordpress-mcp-abilities' ) );
		}

		$requested = self::requested_object_types( $input );
		if ( is_wp_error( $requested ) ) {
			return $requested;
		}

		$pagination = self::paginate( $input, 10 );
		if ( is_wp_error( $pagination ) ) {
			return $pagination;
		}
		list( $page, $per_page ) = $pagination;

		$results   = array();
		$searched  = array();
		$skipped   = array();
		$truncated = false;

		foreach ( $requested as $object_type ) {
			$branch = self::search_branch( $object_type, $search );
			if ( is_wp_error( $branch ) ) {
				$skipped[] = array( 'type' => $object_type, 'reason' => $branch->get_error_code() );
				continue;
			}
			$searched[] = $object_type;
			$results    = array_merge( $results, $branch['rows'] );
			$truncated  = $truncated || $branch['truncated'];
		}

		/*
		 * `capped` is the honest answer to "did you see everything?", and it
		 * has two independent causes: a single branch hit its own limit, or
		 * the merged set hit the overall ceiling. Reporting only the second
		 * would let a branch silently drop its 51st row while the response
		 * still claimed, through `total` and `total_pages`, that the client
		 * had reached the end.
		 */
		$capped = $truncated || count( $results ) > self::limit( 'search_results' );
		if ( count( $results ) > self::limit( 'search_results' ) ) {
			$results = array_slice( $results, 0, self::limit( 'search_results' ) );
		}

		$total       = self::bounded_count( count( $results ), 'search_results', $total_cut );
		$total_pages = self::bounded_count( (int) ceil( $total / $per_page ), 'search_results', $pages_cut );
		$page        = self::bounded_count( $page, 'max_page', $page_cut );
		$per_page    = self::bounded_count( $per_page, 'page_size', $per_page_cut );

		return array(
			'results'     => array_slice( $results, ( $page - 1 ) * $per_page, $per_page ),
			'searched'    => $searched,
			'skipped'     => $skipped,
			'capped'      => $capped || $total_cut || $pages_cut || $page_cut || $per_page_cut,
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Validate the requested object kinds, defaulting to all of them.
	 *
	 * @param array $input Ability input.
	 * @return string[]|WP_Error
	 */
	private static function requested_object_types( $input ) {
		if ( ! isset( $input['types'] ) ) {
			return self::searchable_object_types();
		}
		if ( ! is_array( $input['types'] ) ) {
			return WP_MCP_Errors::discovery_validation_error( __( 'types must be an array of object kinds.', 'wordpress-mcp-abilities' ) );
		}

		if ( count( $input['types'] ) > count( self::searchable_object_types() ) ) {
			return WP_MCP_Errors::discovery_validation_error( __( 'types accepts at most four object kinds.', 'wordpress-mcp-abilities' ) );
		}

		$requested = array();
		foreach ( $input['types'] as $type ) {
			if ( ! is_string( $type ) || ! in_array( $type, self::searchable_object_types(), true ) ) {
				return WP_MCP_Errors::discovery_validation_error( __( 'types accepts only post, media, term and user.', 'wordpress-mcp-abilities' ) );
			}
			$requested[] = $type;
		}

		if ( empty( $requested ) ) {
			return self::searchable_object_types();
		}

		return array_values( array_unique( $requested ) );
	}

	/**
	 * Run one search branch.
	 *
	 * @param string $object_type One of the search_object_type vocabulary.
	 * @param string $search      Sanitised search string.
	 * @return array{rows:array,truncated:bool}|WP_Error Rows and whether the
	 *                                                   branch hit its limit,
	 *                                                   or why it was skipped.
	 */
	private static function search_branch( $object_type, $search ) {
		switch ( $object_type ) {
			case 'post':
				return self::search_posts( $search );
			case 'media':
				return self::search_media( $search );
			case 'term':
				return self::search_terms( $search );
			case 'user':
				return self::search_users( $search );
		}

		return WP_MCP_Errors::discovery_validation_error( __( 'Unknown object kind.', 'wordpress-mcp-abilities' ) );
	}

	/**
	 * Post types global search never walks, because each already has its own
	 * domain with its own rules, or is internal core bookkeeping.
	 *
	 * @return string[]
	 */
	private static function never_searchable_types() {
		return array(
			'attachment', // Has its own branch.
			'revision',
			'nav_menu_item',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
			'oembed_cache',
			'user_request',
			'custom_css',
			'customize_changeset',
		);
	}

	/**
	 * Search posts, pages and custom post types.
	 *
	 * Three queries, not one: the public statuses are searched across every
	 * searchable type, and the non-public ones (draft, pending, future,
	 * private) only across the types this caller may edit — split in two, by
	 * whether the caller holds that type's own `edit_others_posts`, so the
	 * types they only own content in are narrowed to their own posts and the
	 * rest are not. All three are then re-checked per post with `read_post`,
	 * which is the actual authorization gate.
	 *
	 * The author narrowing is computed here rather than handed to WP_Query's
	 * `perm => 'editable'`, which cannot do it for this query: asked for more
	 * than one post type, WP_Query sets its capability prefix to
	 * `multiple_post_type` and therefore tests `edit_others_multiple_post_types`,
	 * a capability no role holds. Every caller, administrators included, would
	 * be narrowed to their own content and no non-public post of another
	 * author would ever reach the `read_post` gate.
	 *
	 * @param string $search Search string.
	 * @return array{rows:array,truncated:bool}
	 */
	private static function search_posts( $search ) {
		$public_types = array();
		$others_types = array();
		$own_types    = array();

		foreach ( get_post_types( array( 'exclude_from_search' => false ), 'objects' ) as $post_type ) {
			if ( ! $post_type instanceof WP_Post_Type || in_array( $post_type->name, self::never_searchable_types(), true ) ) {
				continue;
			}
			$public_types[] = $post_type->name;
			if ( empty( $post_type->cap->edit_posts ) || ! current_user_can( $post_type->cap->edit_posts ) ) {
				continue;
			}
			if ( ! empty( $post_type->cap->edit_others_posts ) && current_user_can( $post_type->cap->edit_others_posts ) ) {
				$others_types[] = $post_type->name;
			} else {
				$own_types[] = $post_type->name;
			}
		}

		$rows      = array();
		$truncated = false;
		if ( ! empty( $public_types ) ) {
			$branch    = self::query_posts( $search, $public_types, self::public_statuses(), 0 );
			$rows      = $branch['rows'];
			$truncated = $branch['truncated'];
		}
		/*
		 * `+=` and not `array_merge()`: every branch keys its rows by post ID,
		 * and union-by-key is what keeps one post from being reported twice
		 * should two status sets ever overlap. `array_merge()` would renumber
		 * the keys and silently lose that.
		 */
		if ( ! empty( $others_types ) ) {
			$branch    = self::query_posts( $search, $others_types, self::non_public_statuses(), 0 );
			$rows     += $branch['rows'];
			$truncated = $truncated || $branch['truncated'];
		}
		/*
		 * The author id is required, never optional: a branch that asked for
		 * the non-public statuses of these types without it would return every
		 * author's drafts to a caller who may only edit their own.
		 */
		$user_id = get_current_user_id();
		if ( ! empty( $own_types ) && $user_id > 0 ) {
			$branch    = self::query_posts( $search, $own_types, self::non_public_statuses(), $user_id );
			$rows     += $branch['rows'];
			$truncated = $truncated || $branch['truncated'];
		}

		return array( 'rows' => array_values( $rows ), 'truncated' => $truncated );
	}

	/**
	 * Search the media library.
	 *
	 * @param string $search Search string.
	 * @return array{rows:array,truncated:bool}
	 */
	private static function search_media( $search ) {
		$branch = self::query_posts( $search, array( 'attachment' ), array( 'inherit' ), 0 );

		return array( 'rows' => array_values( $branch['rows'] ), 'truncated' => $branch['truncated'] );
	}

	/**
	 * Run one bounded post query and keep only what `read_post` allows.
	 *
	 * Asks for one row more than it will return: that extra row is how the
	 * branch knows it truncated the match, which is what `capped` reports. The
	 * surplus row is discarded before any formatting or capability check, so
	 * the limit the caller sees is still the search_branch ceiling.
	 *
	 * @param string   $search     Search string.
	 * @param string[] $post_types Post types to search.
	 * @param string[] $statuses   Post statuses to search.
	 * @param int      $author     Narrow to this author's posts (0 for none).
	 * @return array{rows:array<int,array<string,mixed>>,truncated:bool} Rows keyed by post ID.
	 */
	private static function query_posts( $search, $post_types, $statuses, $author ) {
		if ( empty( $statuses ) ) {
			return array( 'rows' => array(), 'truncated' => false );
		}

		$args = array(
			's'                      => $search,
			'post_type'              => $post_types,
			'post_status'            => $statuses,
			'posts_per_page'         => self::limit( 'search_branch' ) + 1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( $author > 0 ) {
			$args['author'] = $author;
		}

		$query = new WP_Query( $args );

		$posts     = (array) $query->posts;
		$truncated = count( $posts ) > self::limit( 'search_branch' );
		$posts     = array_slice( $posts, 0, self::limit( 'search_branch' ) );

		$rows = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$row = self::format_post_result( $post );
			if ( null === $row ) {
				$truncated = true;
				continue;
			}
			$rows[ $post->ID ] = $row;
		}

		return array( 'rows' => $rows, 'truncated' => $truncated );
	}

	/**
	 * The registered statuses that are public by declaration.
	 *
	 * @return string[]
	 */
	private static function public_statuses() {
		return array_values( array_map( 'strval', array_keys( get_post_stati( array( 'public' => true ) ) ) ) );
	}

	/**
	 * The registered statuses that are neither public nor internal — the ones
	 * a back-end user legitimately searches (draft, pending, future, private).
	 *
	 * @return string[]
	 */
	private static function non_public_statuses() {
		$public   = self::public_statuses();
		$statuses = array();
		foreach ( array_keys( get_post_stati( array( 'internal' => false ) ) ) as $status ) {
			$status = (string) $status;
			if ( ! in_array( $status, $public, true ) ) {
				$statuses[] = $status;
			}
		}
		return $statuses;
	}

	/**
	 * Search taxonomy terms.
	 *
	 * Public taxonomies are searched for everybody; a non-public one only for
	 * a caller who may actually assign its terms.
	 *
	 * @param string $search Search string.
	 * @return array{rows:array,truncated:bool}
	 */
	private static function search_terms( $search ) {
		$taxonomies = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy instanceof WP_Taxonomy ) {
				continue;
			}
			$assignable = ! empty( $taxonomy->cap->assign_terms ) && current_user_can( $taxonomy->cap->assign_terms );
			if ( ! empty( $taxonomy->public ) || $assignable ) {
				$taxonomies[] = $taxonomy->name;
			}
		}

		if ( empty( $taxonomies ) ) {
			return array( 'rows' => array(), 'truncated' => false );
		}

		$terms = get_terms( array(
			'taxonomy'   => $taxonomies,
			'search'     => $search,
			'number'     => self::limit( 'search_branch' ) + 1,
			'hide_empty' => false,
		) );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array( 'rows' => array(), 'truncated' => false );
		}

		$truncated = count( $terms ) > self::limit( 'search_branch' );
		$terms     = array_slice( $terms, 0, self::limit( 'search_branch' ) );

		$rows = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$row = self::format_term_result( $term );
			if ( null === $row ) {
				$truncated = true;
				continue;
			}
			$rows[] = $row;
		}

		return array( 'rows' => $rows, 'truncated' => $truncated );
	}

	/**
	 * Search users — only for a caller who may list them.
	 *
	 * Never searched and never returned: the e-mail address. `list_users` is
	 * the same gate the issue #7 user abilities use.
	 *
	 * @param string $search Search string.
	 * @return array{rows:array,truncated:bool}|WP_Error
	 */
	private static function search_users( $search ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to search users.', 'wordpress-mcp-abilities' ) );
		}

		$users = get_users( array(
			'search'         => '*' . $search . '*',
			'search_columns' => array( 'user_login', 'user_nicename', 'display_name' ),
			'number'         => self::limit( 'search_branch' ) + 1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$truncated = count( $users ) > self::limit( 'search_branch' );
		$users     = array_slice( $users, 0, self::limit( 'search_branch' ) );

		$rows = array();
		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			$id = self::bounded_id( $user->ID, $overflow );
			if ( $overflow ) {
				$truncated = true;
				continue;
			}
			$rows[] = array(
				'object_type' => 'user',
				'id'          => $id,
				'subtype'     => 'user',
				'status'      => '',
				'date_gmt'    => '',
				'editable'    => current_user_can( 'edit_user', $user->ID ),
			);
		}

		return array( 'rows' => $rows, 'truncated' => $truncated );
	}

	/**
	 * Shape one post/media search hit.
	 *
	 * Identity and reachability only. No title, no slug, no permalink and no
	 * excerpt: a title is content, a slug is derived from one, and a permalink
	 * is a URL — all three are exactly the free text the conservative model
	 * keeps out of discovery. What comes back is what an agent needs to *act*:
	 * the object kind, its ID, the registered type and status it belongs to,
	 * when it was written and whether this caller may edit it. `get-post`,
	 * `get-media` and `get-custom-post` remain the capability-checked route to
	 * everything else.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string,mixed>|null Null when the ID is outside the window
	 *                                  the response schema publishes, because
	 *                                  a clamped ID names a different post.
	 */
	private static function format_post_result( $post ) {
		$id = self::bounded_id( $post->ID, $overflow );
		if ( $overflow ) {
			return null;
		}

		return array(
			'object_type' => 'attachment' === $post->post_type ? 'media' : 'post',
			'id'          => $id,
			'subtype'     => self::safe_identifier( $post->post_type, 'key' ),
			'status'      => self::safe_identifier( $post->post_status, 'key' ),
			'date_gmt'    => self::safe_identifier( $post->post_date_gmt, 'datetime' ),
			'editable'    => current_user_can( 'edit_post', $post->ID ),
		);
	}

	/**
	 * Shape one term search hit.
	 *
	 * @param WP_Term $term Term object.
	 * @return array<string,mixed>|null Null when the ID is outside the window
	 *                                  the response schema publishes.
	 */
	private static function format_term_result( $term ) {
		$id = self::bounded_id( $term->term_id, $overflow );
		if ( $overflow ) {
			return null;
		}

		$taxonomy = get_taxonomy( $term->taxonomy );

		return array(
			'object_type' => 'term',
			'id'          => $id,
			'subtype'     => self::safe_identifier( $term->taxonomy, 'key' ),
			'status'      => '',
			'date_gmt'    => '',
			'editable'    => $taxonomy instanceof WP_Taxonomy && ! empty( $taxonomy->cap->edit_terms ) && current_user_can( $taxonomy->cap->edit_terms ),
		);
	}

	/* ==================================================================
	 * Registered objects
	 * ================================================================ */

	/**
	 * List every registered post status as a name and its visibility flags.
	 *
	 * The registered `label` is translated free text a plugin chose, so it is
	 * not reported; a status whose name is not a plain registry slug is not
	 * reported either.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_post_statuses( $input = array() ) {
		if ( ! self::can_read() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read this site.', 'wordpress-mcp-abilities' ) );
		}

		$statuses = array();
		$capped   = false;
		foreach ( get_post_stati( array(), 'objects' ) as $status ) {
			if ( ! is_object( $status ) ) {
				continue;
			}
			if ( count( $statuses ) >= self::limit( 'collection' ) ) {
				$capped = true;
				break;
			}
			$name = self::safe_identifier( isset( $status->name ) ? $status->name : '', 'key' );
			if ( '' === $name ) {
				continue;
			}
			$statuses[] = array(
				'name'                      => $name,
				'public'                    => ! empty( $status->public ),
				'internal'                  => ! empty( $status->internal ),
				'protected'                 => ! empty( $status->protected ),
				'private'                   => ! empty( $status->private ),
				'exclude_from_search'       => ! empty( $status->exclude_from_search ),
				'show_in_admin_all_list'    => ! empty( $status->show_in_admin_all_list ),
				'show_in_admin_status_list' => ! empty( $status->show_in_admin_status_list ),
			);
		}

		$total = self::bounded_count( count( $statuses ), 'collection', $total_cut );

		return array( 'statuses' => $statuses, 'total' => $total, 'capped' => $capped || $total_cut );
	}

	/**
	 * List the mime types WordPress will accept from this caller.
	 *
	 * `get_allowed_mime_types()` is user-aware — a caller without
	 * `unfiltered_upload` genuinely sees a different list — so this reports
	 * what *this* agent may upload, not a site-wide theoretical list.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_mime_types( $input = array() ) {
		if ( ! self::can_upload_files() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to upload files.', 'wordpress-mcp-abilities' ) );
		}

		$extension_ceiling = self::limit( 'mime_extensions' );
		$mime_ceiling      = self::limit( 'collection' );

		/*
		 * Several extension groups may map to the same mime type, so the
		 * extensions are merged per mime and the ceiling applies to the merged
		 * list, not to one `jpg|jpeg|jpe` key. A group cut short sets `capped`
		 * as loudly as a truncated mime list does: an agent that reads a short
		 * extension list as "this is everything WordPress accepts" would then
		 * refuse an upload the site would have taken.
		 */
		$grouped = array();
		$capped  = false;
		foreach ( get_allowed_mime_types() as $extensions => $mime_type ) {
			$mime_type = self::safe_identifier( $mime_type, 'mime' );
			if ( '' === $mime_type ) {
				continue;
			}
			if ( ! isset( $grouped[ $mime_type ] ) ) {
				if ( count( $grouped ) >= $mime_ceiling ) {
					$capped = true;
					continue;
				}
				$grouped[ $mime_type ] = array();
			}
			if ( ! is_string( $extensions ) ) {
				continue;
			}
			/*
			 * `$group_cut` is the truncation a single key can hide. One
			 * `jpg|jpeg|jpe|...` group may on its own declare more extensions
			 * than the per-type ceiling carries, in which case
			 * `safe_string_list()` stops inside that group and the merge loop
			 * below never reaches its own ceiling check — the list it is
			 * handed is already short. Dropping that flag would report a cut
			 * extension list as the complete one WordPress accepts, and an
			 * agent would then refuse an upload the site would have taken.
			 */
			$safe   = self::safe_string_list( explode( '|', $extensions ), 'extension', $extension_ceiling, $group_cut );
			$capped = $capped || $group_cut;

			foreach ( $safe as $extension ) {
				if ( in_array( $extension, $grouped[ $mime_type ], true ) ) {
					continue;
				}
				if ( count( $grouped[ $mime_type ] ) >= $extension_ceiling ) {
					$capped = true;
					break;
				}
				$grouped[ $mime_type ][] = $extension;
			}
		}

		$types = array();
		foreach ( $grouped as $mime_type => $extensions ) {
			$types[] = array(
				'mime_type'  => (string) $mime_type,
				'extensions' => $extensions,
			);
		}

		$total       = self::bounded_count( count( $types ), 'collection', $total_cut );
		$upload_size = self::bounded_count( wp_max_upload_size(), 'byte_size', $size_cut );

		return array(
			'mime_types'            => $types,
			'total'                 => $total,
			'capped'                => $capped || $total_cut || $size_cut,
			'max_upload_size_bytes' => $upload_size,
		);
	}

	/**
	 * List registered block types.
	 *
	 * Two passes, and the expensive one is the short one. The block registry
	 * is unbounded — every plugin on the site adds to it — so the first pass
	 * collects nothing but validated *names* and stops after `block_scan`
	 * registry entries. The budget counts entries **examined**, not entries
	 * kept, and that ordering is the whole point: a ceiling applied after the
	 * namespace and grammar filters is no ceiling at all, because a registry
	 * full of entries matching neither would be walked end to end while the
	 * kept list never grows enough to stop it. Only the names that survive
	 * pagination are then turned into summaries, so the work of walking a
	 * block's supports, parents and attributes is paid for one page at a time
	 * instead of once per registered block on every call.
	 *
	 * `total` is therefore "matching names found inside the scan budget" — a
	 * lower bound, like every other total in this domain — and `capped` is
	 * true as soon as the budget stopped the scan, even when nothing matched,
	 * or when a summary on the returned page had a list of its own cut short.
	 *
	 * @param array $input {
	 *     @type string $block_namespace Optional. Only blocks in this namespace.
	 *     @type int    $page            Optional. Page number.
	 *     @type int    $per_page        Optional. Results per page.
	 * }
	 * @return array|WP_Error
	 */
	public static function list_block_types( $input = array() ) {
		if ( ! self::can_edit_posts() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read the block registry.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return WP_MCP_Errors::discovery_unsupported( __( 'This WordPress installation does not expose a block type registry.', 'wordpress-mcp-abilities' ) );
		}

		/*
		 * Validated against the contract's own `block_ns` grammar — the
		 * namespace half of the `block` grammar this domain reports a block
		 * name with — instead of being run through `sanitize_key()`.
		 * Sanitising a selector rewrites it: `Core` would silently become
		 * `core` and `core/paragraph` would become `coreparagraph`, so the
		 * caller would be answered about a namespace it never asked for and
		 * would have no way to tell. A selector this domain would refuse to
		 * echo back is refused on the way in instead.
		 */
		$namespace = '';
		if ( isset( $input['block_namespace'] ) && '' !== $input['block_namespace'] ) {
			$requested = $input['block_namespace'];
			$namespace = self::safe_identifier( $requested, 'block_ns' );
			/*
			 * Byte-identical, not merely "normalises to something legal".
			 * `safe_identifier()` trims, and the published `pattern` anchors
			 * at the true end of the string, so accepting a selector with a
			 * trailing newline as if it were the bare name would make the
			 * runtime looser than the schema it ships with — the one
			 * direction this domain never allows.
			 */
			if ( '' === $namespace || ! is_string( $requested ) || $requested !== $namespace ) {
				return WP_MCP_Errors::discovery_validation_error(
					__( 'block_namespace must be a block namespace such as core, not a block name or a path.', 'wordpress-mcp-abilities' )
				);
			}
		}

		$pagination = self::paginate( $input, 20 );
		if ( is_wp_error( $pagination ) ) {
			return $pagination;
		}
		list( $page, $per_page ) = $pagination;

		$registered = WP_Block_Type_Registry::get_instance()->get_all_registered();
		$ceiling    = self::limit( 'block_scan' );

		$names   = array();
		$capped  = false;
		$scanned = 0;
		foreach ( $registered as $name => $block_type ) {
			if ( $scanned >= $ceiling ) {
				$capped = true;
				break;
			}
			++$scanned;

			$name = self::safe_identifier( $name, 'block' );
			if ( '' === $name ) {
				continue;
			}
			if ( '' !== $namespace && 0 !== strpos( $name, $namespace . '/' ) ) {
				continue;
			}
			$names[] = $name;
		}

		sort( $names );

		$matched = count( $names );
		$slice   = array_slice( $names, ( $page - 1 ) * $per_page, $per_page );

		$blocks = array();
		foreach ( $slice as $name ) {
			$summary_cut = false;
			$blocks[]    = self::format_block_summary(
				$name,
				isset( $registered[ $name ] ) ? $registered[ $name ] : null,
				$summary_cut
			);
			/*
			 * A block whose own parent, ancestor or attribute list hit its
			 * ceiling is a truncation *this* response carries, so it is this
			 * response's `capped` too. Without this the listing would report
			 * a cut summary as a complete one and only `get-block-type`
			 * would ever admit the difference.
			 */
			$capped = $capped || $summary_cut;
		}

		$total       = self::bounded_count( $matched, 'block_scan', $total_cut );
		$total_pages = self::bounded_count( (int) ceil( $total / $per_page ), 'block_scan', $pages_cut );
		$page        = self::bounded_count( $page, 'max_page', $page_cut );
		$per_page    = self::bounded_count( $per_page, 'page_size', $per_page_cut );

		return array(
			'block_types' => $blocks,
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
			'per_page'    => $per_page,
			'capped'      => $capped || $total_cut || $pages_cut || $page_cut || $per_page_cut,
		);
	}

	/**
	 * Describe one registered block type in full.
	 *
	 * @param array $input { @type string $block_type Required. }
	 * @return array|WP_Error
	 */
	public static function get_block_type( $input ) {
		if ( ! self::can_edit_posts() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read the block registry.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return WP_MCP_Errors::discovery_unsupported( __( 'This WordPress installation does not expose a block type registry.', 'wordpress-mcp-abilities' ) );
		}

		/*
		 * Validated against the same contract grammar the response reports a
		 * block name with, not a second hand-written pattern: a selector this
		 * domain would refuse to echo back is a selector it has no business
		 * looking up either, and one grammar cannot drift from the other.
		 */
		$name = self::safe_identifier( isset( $input['block_type'] ) ? $input['block_type'] : '', 'block' );
		if ( '' === $name ) {
			return WP_MCP_Errors::discovery_validation_error( __( 'block_type must be a registered block name such as core/paragraph.', 'wordpress-mcp-abilities' ) );
		}

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( ! $block_type instanceof WP_Block_Type ) {
			return WP_MCP_Errors::invalid_block_type();
		}

		$summary_cut    = false;
		$attributes_cut = false;

		$detail = self::format_block_summary( $name, $block_type, $summary_cut );

		$detail['api_version'] = self::normalise_api_version( $block_type->api_version );
		$detail['supports']    = self::normalise_block_supports( $block_type->supports );
		$detail['attributes']  = self::format_block_attributes( $block_type, $attributes_cut );

		/*
		 * Block context is reported as two lists of allowlisted *names*, never
		 * as the registered map. `provides_context` is a
		 * context-name => attribute-name array in which both halves are
		 * arbitrary registration data, so returning it would hand a client
		 * whatever a block chose to put there — a path, an option name, a
		 * credential. Only the context names in the vocabulary survive, and the
		 * attribute names they map to are dropped entirely.
		 *
		 * `uses_context` is read through its accessor, not as a property:
		 * `WP_Block_Type::$uses_context` is private and reachable only through
		 * the class's `__get()` magic, so `get_uses_context()` is the declared
		 * public surface — and the one core itself reads.
		 */
		$detail['uses_context']     = self::normalise_context_names( $block_type->get_uses_context(), false );
		$detail['provides_context'] = self::normalise_context_names( $block_type->provides_context, true );

		$detail['capped'] = $summary_cut || $attributes_cut;

		return $detail;
	}

	/**
	 * The block API version, as a term from a fixed vocabulary.
	 *
	 * `api_version` is registration data like everything else here: a block
	 * may declare `'api_version' => PHP_INT_MAX`, a version string, or an
	 * object. Casting it would put an unbounded integer of a plugin's
	 * choosing into the response, so it is matched against the versions the
	 * block editor actually defines and reported as 0 — "not a version this
	 * site understands" — when it is anything else.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $value Raw `WP_Block_Type::$api_version`.
	 * @return int
	 */
	private static function normalise_api_version( $value ) {
		if ( ! is_int( $value ) ) {
			return 0;
		}

		return in_array( $value, WP_MCP_Discovery_Contract::vocabulary( 'block_api_version' ), true ) ? $value : 0;
	}

	/**
	 * Shape the summary every block-type response shares.
	 *
	 * Identifiers, an allowlist and a boolean — nothing else. The registered
	 * `title`, `description` and `keywords` are free text a block author wrote
	 * and are not reported at all; `is_dynamic` is a boolean because the
	 * `render_callback` behind it is a PHP callable that never leaves the
	 * server; `supports_keys` is the allowlisted subset of what the block
	 * declares (see `block_support_vocabulary()`), never the raw registration
	 * keys; and `name`, `category`, `parent`, `ancestor` and the attribute
	 * names must each match their grammar or they are dropped.
	 *
	 * @param string $name       Block name.
	 * @param mixed  $block_type Registered block type.
	 * @param bool   $truncated  Set to true when one of the lists hit its ceiling.
	 * @return array<string,mixed>
	 */
	private static function format_block_summary( $name, $block_type, &$truncated = null ) {
		$truncated = false;
		$name      = self::safe_identifier( $name, 'block' );

		if ( ! $block_type instanceof WP_Block_Type ) {
			return array(
				'name'            => $name,
				'category'        => '',
				'is_dynamic'      => false,
				'parent'          => array(),
				'ancestor'        => array(),
				'supports_keys'   => array(),
				'attribute_names' => array(),
			);
		}

		$parent_cut     = false;
		$ancestor_cut   = false;
		$attributes_cut = false;

		$summary = array(
			'name'            => $name,
			'category'        => self::safe_identifier( $block_type->category, 'key' ),
			'is_dynamic'      => (bool) $block_type->is_dynamic(),
			'parent'          => self::normalise_block_names( $block_type->parent, $parent_cut ),
			'ancestor'        => self::normalise_block_names( $block_type->ancestor, $ancestor_cut ),
			'supports_keys'   => self::declared_block_supports( $block_type->supports ),
			'attribute_names' => self::normalise_attribute_names( $block_type->attributes, $attributes_cut ),
		);

		$truncated = $parent_cut || $ancestor_cut || $attributes_cut;

		return $summary;
	}

	/**
	 * Keep only the entries of a registration list that are safe to echo back.
	 *
	 * Never casts: a value that is not already a string — an object, a closure,
	 * an array, null — is dropped rather than run through `strval()`, which
	 * would either fatal on a non-stringable object or invoke somebody else's
	 * `__toString()`. The pattern is what turns "a string" into "a name": a
	 * path, a URL, a serialised config or a control character never matches
	 * one, so it never reaches the response.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed  $value   Raw registration value.
	 * @param string $grammar Contract grammar name the value must match.
	 * @return string The identifier, or '' when it is not one.
	 */
	private static function safe_identifier( $value, $grammar ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > self::grammar_length( $grammar ) ) {
			return '';
		}
		if ( 1 !== preg_match( self::grammar( $grammar ), $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * The list form of `safe_identifier()`: every entry filtered by type first
	 * and by grammar second, with an explicit ceiling on how many are kept.
	 *
	 * @since 0.16.0
	 *
	 * Deduplicates as it goes, so the ceiling counts distinct identifiers
	 * rather than raw registry entries, and reports through `$truncated`
	 * whether the ceiling — not the end of the input — stopped it. That flag
	 * is what the surrounding response turns into `capped`: a list silently
	 * cut at its ceiling would otherwise read as the whole registry.
	 *
	 * @param mixed  $value     Raw registration value.
	 * @param string $grammar   Contract grammar name every entry must match.
	 * @param int    $max_items Maximum entries to report.
	 * @param bool   $truncated Set to true when the ceiling was reached.
	 * @return string[]
	 */
	private static function safe_string_list( $value, $grammar, $max_items, &$truncated = null ) {
		$truncated = false;
		if ( ! is_array( $value ) ) {
			return array();
		}

		$pattern    = self::grammar( $grammar );
		$max_length = self::grammar_length( $grammar );

		/*
		 * `$seen` is keyed by a prefixed copy of the entry on purpose: a PHP
		 * array key that looks like an integer becomes one, and `123` is a
		 * legal file extension and a legal registry slug, so keying on the
		 * raw value would hand the response an integer where its schema
		 * promises a string.
		 */
		$safe = array();
		$seen = array();
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$entry = trim( $entry );
			if ( '' === $entry || strlen( $entry ) > $max_length ) {
				continue;
			}
			if ( 1 !== preg_match( $pattern, $entry ) ) {
				continue;
			}
			if ( isset( $seen[ '#' . $entry ] ) ) {
				continue;
			}
			if ( count( $safe ) >= $max_items ) {
				$truncated = true;
				break;
			}
			$seen[ '#' . $entry ] = true;
			$safe[]               = $entry;
		}

		return $safe;
	}

	/**
	 * The block names a block declares as parent or ancestor.
	 *
	 * Validated against the same `namespace/name` shape this domain requires of
	 * its own `block_type` input, so a path or a URL parked in `parent` is not
	 * a block name and is not reported.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $value     Raw registration value.
	 * @param bool  $truncated Set to true when the ceiling was reached.
	 * @return string[]
	 */
	private static function normalise_block_names( $value, &$truncated = null ) {
		return self::safe_string_list( $value, 'block', self::limit( 'block_parents' ), $truncated );
	}

	/**
	 * The attribute names a block declares.
	 *
	 * Array keys, so they may be integers as well as strings, and they are
	 * author-chosen: only a plain identifier is reported.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $attributes Raw `WP_Block_Type::$attributes`.
	 * @param bool  $truncated  Set to true when the ceiling was reached.
	 * @return string[]
	 */
	private static function normalise_attribute_names( $attributes, &$truncated = null ) {
		$truncated = false;
		if ( ! is_array( $attributes ) ) {
			return array();
		}

		return self::safe_string_list( array_keys( $attributes ), 'attribute', self::limit( 'block_attributes' ), $truncated );
	}

	/**
	 * The block context keys this domain is willing to name.
	 *
	 * Block context is a free-form registration surface on both sides:
	 * `uses_context` is a list of arbitrary strings and `provides_context` is a
	 * map of arbitrary keys to arbitrary attribute names. Only the core
	 * vocabulary is reported; anything else is dropped, name and value alike.
	 *
	 * @since 0.16.0
	 *
	 * @return string[]
	 */
	public static function block_context_vocabulary() {
		return WP_MCP_Discovery_Contract::vocabulary( 'block_context' );
	}

	/**
	 * Report the allowlisted context names a block declares.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $value  Raw `uses_context` list or `provides_context` map.
	 * @param bool  $is_map Whether the names are the array's keys.
	 * @return string[]
	 */
	private static function normalise_context_names( $value, $is_map ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$names      = $is_map ? array_keys( $value ) : $value;
		$vocabulary = self::block_context_vocabulary();
		$ceiling    = self::limit( 'block_contexts' );

		$found = array();
		foreach ( $names as $name ) {
			if ( count( $found ) >= $ceiling ) {
				break;
			}
			if ( ! is_string( $name ) || ! in_array( $name, $vocabulary, true ) || in_array( $name, $found, true ) ) {
				continue;
			}
			$found[] = $name;
		}

		return $found;
	}

	/**
	 * The block supports this domain is willing to describe.
	 *
	 * `WP_Block_Type::$supports` is an arbitrary registration array: a block —
	 * core, third-party, or hostile — may put anything under any key, including
	 * a callable, a filesystem path, an API key or a whole plugin
	 * configuration. So the raw array is never returned. Only these feature
	 * names are described, and only ever as booleans and allowlisted
	 * sub-feature *names*; an unlisted key is dropped entirely and no
	 * registered value is ever emitted.
	 *
	 * @since 0.16.0
	 *
	 * @return string[]
	 */
	public static function block_support_vocabulary() {
		return WP_MCP_Discovery_Contract::vocabulary( 'block_support' );
	}

	/**
	 * The sub-feature names a support entry may report.
	 *
	 * One flat allowlist across every feature: a name that is not in it is not
	 * reported, whatever it is worth. Alignment names are here too, because
	 * `align` declares its options as a list of values rather than as keys.
	 *
	 * @since 0.16.0
	 *
	 * @return string[]
	 */
	public static function block_support_sub_vocabulary() {
		return WP_MCP_Discovery_Contract::vocabulary( 'block_support_sub' );
	}

	/**
	 * The allowlisted supports a block declares, as plain names.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $supports Raw `WP_Block_Type::$supports`.
	 * @return string[]
	 */
	private static function declared_block_supports( $supports ) {
		if ( ! is_array( $supports ) ) {
			return array();
		}

		$declared = array();
		foreach ( self::block_support_vocabulary() as $feature ) {
			if ( array_key_exists( $feature, $supports ) ) {
				$declared[] = $feature;
			}
		}

		return $declared;
	}

	/**
	 * Normalise a block's supports into an allowlisted, value-free shape.
	 *
	 * Each entry is a feature name from the vocabulary, whether the block
	 * enables it, and the allowlisted sub-feature names it turns on. A
	 * registered *value* — a string, a path, a callable, a nested plugin
	 * configuration — never appears in the result.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $supports Raw `WP_Block_Type::$supports`.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalise_block_supports( $supports ) {
		if ( ! is_array( $supports ) ) {
			return array();
		}

		$ceiling = self::limit( 'block_supports' );

		$normalised = array();
		foreach ( self::block_support_vocabulary() as $feature ) {
			if ( ! array_key_exists( $feature, $supports ) ) {
				continue;
			}
			if ( count( $normalised ) >= $ceiling ) {
				break;
			}
			$normalised[] = array(
				'feature'      => $feature,
				'enabled'      => self::block_support_is_enabled( $supports[ $feature ] ),
				'sub_features' => self::block_support_sub_features( $supports[ $feature ] ),
			);
		}

		return $normalised;
	}

	/**
	 * Whether a support entry counts as enabled.
	 *
	 * Anything that is not a boolean, a scalar or an array — an object or a
	 * closure, which is exactly what a `render`-style callback registered under
	 * a support key would be — is reported as disabled and never inspected
	 * further.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $value Raw support value.
	 * @return bool
	 */
	private static function block_support_is_enabled( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		if ( is_scalar( $value ) ) {
			return (bool) $value;
		}
		return false;
	}

	/**
	 * The allowlisted sub-feature names a support entry turns on.
	 *
	 * Handles both shapes core uses: a map of sub-feature => flag, and a list
	 * of values (`align`). Names outside the sub-vocabulary are dropped, and an
	 * explicitly disabled sub-feature is not reported.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $value Raw support value.
	 * @return string[]
	 */
	private static function block_support_sub_features( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$allowed = self::block_support_sub_vocabulary();
		$ceiling = self::limit( 'block_sub_features' );
		$found   = array();
		foreach ( $value as $key => $sub_value ) {
			if ( count( $found ) >= $ceiling ) {
				break;
			}
			if ( is_int( $key ) ) {
				$name = is_string( $sub_value ) ? $sub_value : '';
			} else {
				$name = (string) $key;
				if ( false === $sub_value || null === $sub_value ) {
					continue;
				}
			}
			if ( '' === $name || ! in_array( $name, $allowed, true ) || in_array( $name, $found, true ) ) {
				continue;
			}
			$found[] = $name;
		}

		sort( $found );

		return $found;
	}

	/**
	 * The JSON Schema types a block attribute may declare.
	 *
	 * `type` is registration data like everything else here, so it is matched
	 * against the closed JSON Schema vocabulary rather than echoed: a block
	 * that declares `'type' => '/srv/app/config.php'` reports no type at all.
	 *
	 * @since 0.16.0
	 *
	 * @return string[]
	 */
	public static function block_attribute_type_vocabulary() {
		return WP_MCP_Discovery_Contract::vocabulary( 'attribute_type' );
	}

	/**
	 * The attribute sources the block editor defines.
	 *
	 * @since 0.16.0
	 *
	 * @return string[]
	 */
	public static function block_attribute_source_vocabulary() {
		return WP_MCP_Discovery_Contract::vocabulary( 'attribute_source' );
	}

	/**
	 * Describe a block type's attributes as metadata, never as values.
	 *
	 * Every field here is either an allowlisted vocabulary term or a boolean.
	 * `default` and `enum` are registration *values* of unbounded shape — a
	 * path, a credential, a whole configuration — so they are reported only as
	 * `has_default` and `has_enum`; an attribute whose name is not a plain
	 * identifier is not reported at all.
	 *
	 * @param WP_Block_Type $block_type Block type.
	 * @param bool          $truncated  Set to true when the ceiling was reached.
	 * @return array<int,array<string,mixed>>
	 */
	private static function format_block_attributes( $block_type, &$truncated = null ) {
		$truncated = false;
		if ( ! is_array( $block_type->attributes ) ) {
			return array();
		}

		$sources = self::block_attribute_source_vocabulary();
		$ceiling = self::limit( 'block_attributes' );

		$attributes = array();
		foreach ( $block_type->attributes as $name => $definition ) {
			$safe_name = self::safe_identifier( $name, 'attribute' );
			if ( '' === $safe_name ) {
				continue;
			}
			if ( count( $attributes ) >= $ceiling ) {
				$truncated = true;
				break;
			}
			$definition = is_array( $definition ) ? $definition : array();
			$source     = isset( $definition['source'] ) && is_string( $definition['source'] ) && in_array( $definition['source'], $sources, true )
				? $definition['source']
				: '';

			$attributes[] = array(
				'name'        => $safe_name,
				'types'       => self::normalise_attribute_types( isset( $definition['type'] ) ? $definition['type'] : null ),
				'source'      => $source,
				'has_default' => array_key_exists( 'default', $definition ),
				'has_enum'    => isset( $definition['enum'] ) && is_array( $definition['enum'] ) && ! empty( $definition['enum'] ),
			);
		}

		return $attributes;
	}

	/**
	 * The allowlisted JSON Schema types one attribute declares.
	 *
	 * Handles both shapes JSON Schema allows — a single name and a list — and
	 * casts neither: a non-string entry is dropped, so a closure or an object
	 * parked in `type` cannot reach `strval()`.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $type Raw attribute type.
	 * @return string[]
	 */
	private static function normalise_attribute_types( $type ) {
		$candidates = is_array( $type ) ? $type : array( $type );
		$vocabulary = self::block_attribute_type_vocabulary();
		$ceiling    = self::limit( 'attribute_types' );

		$types = array();
		foreach ( $candidates as $candidate ) {
			if ( count( $types ) >= $ceiling ) {
				break;
			}
			if ( ! is_string( $candidate ) || ! in_array( $candidate, $vocabulary, true ) || in_array( $candidate, $types, true ) ) {
				continue;
			}
			$types[] = $candidate;
		}

		return $types;
	}

	/**
	 * List the names of the registered block pattern categories.
	 *
	 * A category's `label` and `description` are translated free text, so only
	 * the registry slug is reported, and only when it is one.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_pattern_categories( $input = array() ) {
		if ( ! self::can_edit_posts() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read the pattern registry.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
			return WP_MCP_Errors::discovery_unsupported( __( 'This WordPress installation does not expose a pattern category registry.', 'wordpress-mcp-abilities' ) );
		}

		$categories = array();
		$capped     = false;
		foreach ( WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered() as $category ) {
			if ( ! is_array( $category ) || ! isset( $category['name'] ) ) {
				continue;
			}
			if ( count( $categories ) >= self::limit( 'collection' ) ) {
				$capped = true;
				break;
			}
			$name = self::safe_identifier( $category['name'], 'key' );
			if ( '' === $name ) {
				continue;
			}
			$categories[] = $name;
		}

		$categories = array_values( array_unique( $categories ) );

		$total = self::bounded_count( count( $categories ), 'collection', $total_cut );

		return array( 'categories' => $categories, 'total' => $total, 'capped' => $capped || $total_cut );
	}

	/**
	 * List the block template types and template-part areas this
	 * installation offers, and say plainly whether FSE is available at all.
	 *
	 * A classic theme answers with `block_theme: false` and empty lists
	 * rather than an error: "there is no Site Editor here" is a discovery
	 * answer, not a failure.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_template_types( $input = array() ) {
		if ( ! self::can_edit_theme_options() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read the template registry.', 'wordpress-mcp-abilities' ) );
		}

		$block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$editable    = $block_theme || (bool) current_theme_supports( 'block-templates' );

		return self::describe_template_surface( $block_theme, $editable );
	}

	/**
	 * Build the template-surface response for a given theme situation.
	 *
	 * The documented safe behaviour: the FSE vocabulary is reported **only**
	 * when this installation actually has a Site Editor to use it in — a block
	 * theme, or a classic theme that opted in with `block-templates` support.
	 * Otherwise both lists are empty and `reason` says `classic_theme`.
	 * `get_default_block_template_types()` and
	 * `get_allowed_block_template_part_areas()` answer the same thing on a
	 * classic theme as on a block one, so reporting them unconditionally would
	 * advertise headers, footers and template hierarchies that no ability on
	 * this site can create or edit — an agent would then plan against a Site
	 * Editor that is not there.
	 *
	 * Split out from the ability so both branches are testable without
	 * installing a theme: the classic case is this method with $editable false.
	 *
	 * Both lists are plain slugs from a fixed vocabulary. The titles and
	 * descriptions core carries alongside them are translated free text that a
	 * filter can rewrite, so they are not reported.
	 *
	 * @since 0.16.0
	 *
	 * @param bool $block_theme Whether the active theme is a block theme.
	 * @param bool $editable    Whether block templates are available at all.
	 * @return array<string,mixed>
	 */
	public static function describe_template_surface( $block_theme, $editable ) {
		$block_theme = (bool) $block_theme;
		$editable    = (bool) $editable;

		$types      = array();
		$areas      = array();
		$capped     = false;
		$type_limit = self::limit( 'template_types' );
		$area_limit = self::limit( 'template_areas' );

		if ( $editable ) {
			/*
			 * Both core lists run through a filter (`default_template_types`,
			 * `default_wp_template_part_areas`), so a plugin can add a slug, a
			 * title and a description of its own choosing. Only slugs from the
			 * fixed vocabularies below are reported, and the titles and
			 * descriptions are dropped whatever they say.
			 */
			if ( function_exists( 'get_default_block_template_types' ) ) {
				$vocabulary = self::template_type_vocabulary();
				foreach ( array_keys( (array) get_default_block_template_types() ) as $slug ) {
					$slug = self::safe_identifier( $slug, 'key' );
					if ( '' === $slug || ! in_array( $slug, $vocabulary, true ) || in_array( $slug, $types, true ) ) {
						continue;
					}
					if ( count( $types ) >= $type_limit ) {
						$capped = true;
						break;
					}
					$types[] = $slug;
				}
			}

			if ( function_exists( 'get_allowed_block_template_part_areas' ) ) {
				$vocabulary = self::template_part_area_vocabulary();
				foreach ( (array) get_allowed_block_template_part_areas() as $definition ) {
					/*
					 * Core's PHPDoc promises a fixed shape per entry, which is
					 * why static analysis reads this guard as dead code. The
					 * value is whatever the `default_wp_template_part_areas`
					 * filter last returned, so the shape is a promise this
					 * domain checks rather than one it trusts.
					 */
					if ( ! is_array( $definition ) || ! isset( $definition['area'] ) ) { // @phpstan-ignore-line
						continue;
					}
					$area = self::safe_identifier( $definition['area'], 'key' );
					if ( '' === $area || ! in_array( $area, $vocabulary, true ) || in_array( $area, $areas, true ) ) {
						continue;
					}
					if ( count( $areas ) >= $area_limit ) {
						$capped = true;
						break;
					}
					$areas[] = $area;
				}
			}
		}

		if ( $block_theme ) {
			$reason = 'block_theme';
		} elseif ( $editable ) {
			$reason = 'block_templates_support';
		} else {
			$reason = 'classic_theme';
		}

		$total_types = self::bounded_count( count( $types ), 'template_types', $types_cut );
		$total_areas = self::bounded_count( count( $areas ), 'template_areas', $areas_cut );

		return array(
			'block_theme'               => $block_theme,
			'template_editing'          => $editable,
			'reason'                    => $reason,
			'template_types'            => $types,
			'template_part_areas'       => $areas,
			'total_template_types'      => $total_types,
			'total_template_part_areas' => $total_areas,
			'capped'                    => $capped || $types_cut || $areas_cut,
		);
	}

	/**
	 * The block template types core defines.
	 *
	 * @since 0.16.0
	 *
	 * @return string[]
	 */
	public static function template_type_vocabulary() {
		return WP_MCP_Discovery_Contract::vocabulary( 'template_type' );
	}

	/**
	 * The template-part areas core defines.
	 *
	 * @since 0.16.0
	 *
	 * @return string[]
	 */
	public static function template_part_area_vocabulary() {
		return WP_MCP_Discovery_Contract::vocabulary( 'template_area' );
	}

	/* ==================================================================
	 * The caller and the installation
	 * ================================================================ */

	/**
	 * Report the authenticated user's effective roles and capabilities.
	 *
	 * Effective, not declared: `WP_User::$allcaps` is what `current_user_can()`
	 * actually consults, including per-user grants and anything a plugin added
	 * through `user_has_cap`. Denied capabilities are omitted rather than
	 * returned as false, so the list reads as "what you may do".
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_current_user_capabilities( $input = array() ) {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return WP_MCP_Errors::permission_denied( __( 'You must be authenticated to read your own capabilities.', 'wordpress-mcp-abilities' ) );
		}

		$capabilities = array();
		foreach ( (array) $user->allcaps as $capability => $granted ) {
			if ( ! $granted || ! is_string( $capability ) ) {
				continue;
			}
			$capability = self::safe_identifier( sanitize_key( $capability ), 'capability' );
			if ( '' === $capability ) {
				continue;
			}
			$capabilities[] = $capability;
		}
		$capabilities = array_values( array_unique( $capabilities ) );
		sort( $capabilities );

		$capped       = count( $capabilities ) > self::limit( 'collection' );
		$capabilities = array_slice( $capabilities, 0, self::limit( 'collection' ) );

		$roles_cut = false;
		$roles     = self::safe_string_list( $user->roles, 'key', self::limit( 'roles' ), $roles_cut );
		$capped    = $capped || $roles_cut;

		/*
		 * An ID outside the window the schema publishes is reported as 0 —
		 * "no object" — rather than clamped, because a clamped identifier
		 * names a different user. `capped` is then how this single-object
		 * response says that something it should have reported did not fit.
		 */
		$user_id = self::bounded_id( $user->ID, $id_overflow );
		$total   = self::bounded_count( count( $capabilities ), 'collection', $total_cut );

		return array(
			'user_id'        => $user_id,
			'roles'          => $roles,
			'capabilities'   => $capabilities,
			'total'          => $total,
			'capped'         => $capped || $id_overflow || $total_cut,
			'is_super_admin' => is_multisite() && is_super_admin( $user->ID ),
			'multisite'      => is_multisite(),
		);
	}

	/**
	 * The theme features this domain reports on.
	 *
	 * A fixed vocabulary on purpose: `get_theme_support()` returns the
	 * registered *arguments*, and for `custom-header` and `custom-background`
	 * those contain PHP callbacks and asset paths. Only the boolean answer
	 * ever leaves the site.
	 *
	 * @return string[]
	 */
	public static function theme_feature_vocabulary() {
		return WP_MCP_Discovery_Contract::vocabulary( 'theme_feature' );
	}

	/**
	 * Report which features the active theme and this installation support.
	 *
	 * Booleans and counts only — never a path, a URL, a callback or a
	 * credential.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_feature_support( $input = array() ) {
		if ( ! self::can_edit_posts() ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read this installation feature support.', 'wordpress-mcp-abilities' ) );
		}

		$ceiling  = self::limit( 'features' );
		$features = array();
		$capped   = false;
		foreach ( self::theme_feature_vocabulary() as $feature ) {
			if ( count( $features ) >= $ceiling ) {
				$capped = true;
				break;
			}
			$features[] = array(
				'feature'   => $feature,
				'supported' => (bool) current_theme_supports( $feature ),
			);
		}

		$block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

		/*
		 * The three registry cardinalities below have no list ceiling to
		 * inherit — this ability counts those registries without listing them
		 * — and any plugin may grow them freely, so each one is reported
		 * inside the `registry_total` window its schema publishes and a value
		 * that did not fit is admitted through `capped` rather than shipped as
		 * a number that breaks the ability's own `maximum`.
		 */
		$nav_menus  = self::bounded_count( count( (array) get_registered_nav_menus() ), 'registry_total', $menus_cut );
		$post_types = self::bounded_count( count( (array) get_post_types( array(), 'names' ) ), 'registry_total', $types_cut );
		$taxonomies = self::bounded_count( count( (array) get_taxonomies( array(), 'names' ) ), 'registry_total', $taxonomies_cut );
		$total      = self::bounded_count( count( $features ), 'features', $total_cut );

		return array(
			'theme'    => array(
				'stylesheet'     => self::safe_identifier( get_stylesheet(), 'theme' ),
				'template'       => self::safe_identifier( get_template(), 'theme' ),
				'is_block_theme' => $block_theme,
				'has_theme_json' => function_exists( 'wp_theme_has_theme_json' ) ? (bool) wp_theme_has_theme_json() : false,
			),
			'features' => $features,
			'site'     => array(
				'multisite'             => is_multisite(),
				'block_editor'          => self::block_editor_available(),
				'site_editor'           => $block_theme,
				'pretty_permalinks'     => '' !== (string) get_option( 'permalink_structure' ),
				'application_passwords' => function_exists( 'wp_is_application_passwords_available' ) ? (bool) wp_is_application_passwords_available() : false,
				'widgets_block_editor'  => function_exists( 'wp_use_widgets_block_editor' ) ? (bool) wp_use_widgets_block_editor() : false,
				'nav_menu_locations'    => $nav_menus,
				'registered_post_types' => $post_types,
				'registered_taxonomies' => $taxonomies,
			),
			'total'    => $total,
			'capped'   => $capped || $menus_cut || $types_cut || $taxonomies_cut || $total_cut,
		);
	}

	/**
	 * Whether the block editor handles the default post type here.
	 *
	 * `use_block_editor_for_post_type()` lives in wp-admin, which is not
	 * loaded during an MCP request; reporting false rather than fataling is
	 * the honest answer when it cannot be asked.
	 *
	 * @return bool
	 */
	private static function block_editor_available() {
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			return false;
		}
		return (bool) use_block_editor_for_post_type( 'post' );
	}
}
