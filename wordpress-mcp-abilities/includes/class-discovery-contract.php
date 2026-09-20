<?php
/**
 * WordPress MCP Abilities — Discovery response contract (issue #16).
 *
 * The single source of truth for what the discovery domain is allowed to
 * answer with. Three consumers read this file and nothing else:
 *
 *  - `WP_MCP_Discovery` normalises every value against the grammar, the
 *    vocabulary and the ceiling declared here;
 *  - `WP_MCP_Discovery_Abilities` builds each MCP output schema from
 *    `output_schema()`, so `pattern`, `maxLength`, `enum` and `maxItems`
 *    are generated, never hand-written;
 *  - the test suite walks `surfaces()` to assert both of the above agree.
 *
 * A field may only be one of four things, and the descriptor says which:
 *
 *  - `identifier` — a name matching an anchored, length-bounded grammar;
 *  - `enum` / `enum_int` — a term from a fixed vocabulary;
 *  - `boolean` — a flag;
 *  - `count` / `object_id` / `byte_size` — an integer inside an explicit
 *    `[0, maximum]` window. The three are separate kinds because they are
 *    separate things: a cardinality bounded by the ceiling of the collection
 *    it counts, a database identity bounded by the largest integer a JSON
 *    consumer can round-trip, and a byte count. `minimum: 0` alone would
 *    publish "any integer" and let a filtered registry value of a plugin's
 *    choosing through, so every numeric descriptor names the ceiling it is
 *    bounded by and the runtime enforces that same ceiling.
 *
 * Plus the two containers, `list` (always with an explicit ceiling) and
 * `object` (always closed). There is deliberately no "string" kind: a title,
 * a label, a description, a keyword, a registration map, a default or enum
 * value, a callback, a path, a URL or an option name cannot be expressed in
 * this contract, so it cannot be returned by an ability that is built from it.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.16.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Discovery_Contract
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Discovery_Contract {

	/* ==================================================================
	 * Limits
	 * ================================================================ */

	/**
	 * Every ceiling this domain enforces, in one table.
	 *
	 * Each one is applied at runtime *and* published in the schema — as
	 * `maxItems` for a list, as `maximum` for a number — so a client can tell
	 * a complete answer from a bounded one without reading the source.
	 *
	 * @return array<string,int>
	 */
	public static function limits() {
		return array(
			// Global search.
			'search_results'     => 200,
			'search_branch'      => 50,
			'search_types'       => 4,
			'page_size'          => 50,
			// Registry listings.
			'collection'         => 200,
			'block_scan'         => 1000,
			'block_parents'      => 20,
			'block_attributes'   => 100,
			'block_supports'     => 40,
			'block_sub_features' => 40,
			'block_contexts'     => 40,
			'attribute_types'    => 8,
			'mime_extensions'    => 20,
			'roles'              => 32,
			'features'           => 64,
			'template_types'     => 32,
			'template_areas'     => 16,
			/*
			 * Numeric ceilings. A count is bounded by the ceiling of the
			 * collection it counts, so those reuse the entries above; the
			 * three below bound the numbers that have no collection behind
			 * them and would otherwise be published as "any integer":
			 *
			 *  - `max_page` is the highest page number a bounded collection
			 *    can reach. `block_scan` is the largest total this domain
			 *    can report and `per_page` is at least one, so no meaningful
			 *    page is above it. A request past it is refused rather than
			 *    clamped: silently answering page 1000 to a request for page
			 *    10^9 would hand a client rows it did not ask for.
			 *  - `registry_total` bounds the cardinality of a WordPress
			 *    registry this domain counts but does not list (post types,
			 *    taxonomies, nav menu locations). Those counts have no list
			 *    ceiling to inherit and a plugin may grow them freely.
			 *  - `object_id` and `byte_size` are the same number and stay
			 *    separate names because they are separate meanings: an
			 *    identity and a quantity of bytes.
			 */
			'max_page'           => 1000,
			'registry_total'     => 10000,
			'object_id'          => self::safe_integer_ceiling(),
			'byte_size'          => self::safe_integer_ceiling(),
		);
	}

	/**
	 * The largest integer this response may carry.
	 *
	 * 2^53-1 is the largest integer an IEEE-754 double — which is what a
	 * JSON number is on the other side of the wire — represents exactly, so
	 * anything above it would reach the client as a different number than
	 * the one that left. On a 32-bit PHP build `PHP_INT_MAX` is smaller
	 * still and is the honest ceiling there, because that build cannot even
	 * hold 2^53-1 as an integer.
	 *
	 * @since 0.16.0
	 *
	 * @return int
	 */
	public static function safe_integer_ceiling() {
		$json_safe = 9007199254740991;

		return PHP_INT_MAX < $json_safe ? PHP_INT_MAX : (int) $json_safe;
	}

	/**
	 * One ceiling.
	 *
	 * @param string $name Limit name.
	 * @return int
	 */
	public static function limit( $name ) {
		$limits = self::limits();

		return isset( $limits[ $name ] ) ? $limits[ $name ] : 0;
	}

	/* ==================================================================
	 * Grammars
	 * ================================================================ */

	/**
	 * Every identifier grammar, as an un-anchored body plus its length bound.
	 *
	 * The body is stored without anchors and without delimiters so the same
	 * definition can produce a JSON Schema `pattern` and a PHP `preg_match()`
	 * pattern, optional or required, with no second copy to drift.
	 *
	 * @return array<string,array{body:string,max_length:int}>
	 */
	public static function grammars() {
		return array(
			'key'        => array( 'body' => '[a-z0-9][a-z0-9_-]{0,63}', 'max_length' => 64 ),
			'capability' => array( 'body' => '[a-z0-9][a-z0-9_-]{0,63}', 'max_length' => 64 ),
			'block'      => array( 'body' => '[a-z][a-z0-9-]{0,31}/[a-z][a-z0-9-]{0,63}', 'max_length' => 128 ),
			/*
			 * The namespace half of `block`, on its own, because
			 * `list-block-types` takes it as a selector. Same body as the
			 * segment before the slash above, so a namespace this grammar
			 * accepts is exactly a namespace a block name can start with.
			 */
			'block_ns'   => array( 'body' => '[a-z][a-z0-9-]{0,31}', 'max_length' => 32 ),
			'attribute'  => array( 'body' => '[A-Za-z_][A-Za-z0-9_-]{0,63}', 'max_length' => 64 ),
			'mime'       => array( 'body' => '[a-z0-9][a-z0-9.+-]{0,62}/[a-z0-9][a-z0-9.+-]{0,62}', 'max_length' => 128 ),
			'extension'  => array( 'body' => '[a-z0-9]{1,16}', 'max_length' => 16 ),
			'theme'      => array( 'body' => '[A-Za-z0-9][A-Za-z0-9_-]{0,63}', 'max_length' => 64 ),
			'datetime'   => array( 'body' => '\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}', 'max_length' => 19 ),
		);
	}

	/**
	 * One grammar.
	 *
	 * @param string $name Grammar name.
	 * @return array{body:string,max_length:int}
	 */
	public static function grammar( $name ) {
		$grammars = self::grammars();

		return isset( $grammars[ $name ] ) ? $grammars[ $name ] : array( 'body' => '(?!)', 'max_length' => 0 );
	}

	/**
	 * The JSON Schema pattern for a grammar.
	 *
	 * @param string $name     Grammar name.
	 * @param bool   $optional Whether the empty string is allowed.
	 * @return string
	 */
	public static function schema_pattern( $name, $optional = false ) {
		$body = self::grammar( $name )['body'];

		return $optional ? '^(' . $body . ')?$' : '^' . $body . '$';
	}

	/**
	 * The PHP pattern for a grammar. `#` is the delimiter because two of the
	 * bodies contain `/` and none contains `#`.
	 *
	 * The `D` modifier is the point of this method rather than an
	 * afterthought: without it PCRE lets `$` match before a trailing newline,
	 * so `post\n` would pass a grammar that JSON Schema's `$` — which anchors
	 * at the true end of the string — rejects. The runtime has to be at
	 * least as strict as the schema it publishes, never one newline looser.
	 *
	 * @param string $name Grammar name.
	 * @return string
	 */
	public static function preg_pattern( $name ) {
		return '#^' . self::grammar( $name )['body'] . '$#D';
	}

	/**
	 * The length bound for a grammar.
	 *
	 * @param string $name Grammar name.
	 * @return int
	 */
	public static function max_length( $name ) {
		return self::grammar( $name )['max_length'];
	}

	/* ==================================================================
	 * Vocabularies
	 * ================================================================ */

	/**
	 * Every fixed vocabulary, in one table.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function vocabularies() {
		return array(
			'search_object_type'  => array( 'post', 'media', 'term', 'user' ),
			'skip_reason'         => array( 'wp_mcp_permission_denied', 'wp_mcp_discovery_validation_error', 'wp_mcp_discovery_unsupported' ),
			'template_reason'     => array( 'block_theme', 'block_templates_support', 'classic_theme' ),
			'block_support'       => array(
				'align',
				'alignWide',
				'anchor',
				'ariaLabel',
				'background',
				'border',
				'className',
				'color',
				'customClassName',
				'dimensions',
				'filter',
				'html',
				'inserter',
				'interactivity',
				'layout',
				'lock',
				'multiple',
				'position',
				'renaming',
				'reusable',
				'shadow',
				'spacing',
				'splitting',
				'typography',
			),
			'block_support_sub'   => array(
				// align, declared as a list of values.
				'left',
				'center',
				'right',
				'wide',
				'full',
				// color.
				'background',
				'button',
				'enableContrastChecker',
				'gradients',
				'heading',
				'link',
				'text',
				// typography.
				'fontFamily',
				'fontSize',
				'fontStyle',
				'fontWeight',
				'letterSpacing',
				'lineHeight',
				'textAlign',
				'textDecoration',
				'textTransform',
				'writingMode',
				// spacing.
				'blockGap',
				'margin',
				'padding',
				// dimensions.
				'aspectRatio',
				'height',
				'minHeight',
				'width',
				// border.
				'color',
				'radius',
				'style',
				// position.
				'sticky',
				// layout.
				'allowEditing',
				'allowInheriting',
				'allowSizingOnChildren',
				'allowSwitching',
				'default',
			),
			'block_context'       => array(
				'allowedBlocks',
				'backgroundColor',
				'commentId',
				'customBackgroundColor',
				'customFontSize',
				'customOverlayBackgroundColor',
				'customOverlayTextColor',
				'customTextColor',
				'displayLayout',
				'enhancedPagination',
				'fontSize',
				'index',
				'maxNestingLevel',
				'openSubmenusOnClick',
				'overlayBackgroundColor',
				'overlayTextColor',
				'pattern/overrides',
				'postId',
				'postType',
				'previewPostType',
				'query',
				'queryId',
				'showSubmenuIcon',
				'style',
				'templateSlug',
				'textColor',
			),
			'attribute_type'      => array( 'array', 'boolean', 'integer', 'null', 'number', 'object', 'string' ),
			'attribute_source'    => array( 'attribute', 'children', 'html', 'meta', 'node', 'query', 'raw', 'rich-text', 'tag', 'text' ),
			'template_type'       => array(
				'404',
				'archive',
				'attachment',
				'author',
				'category',
				'date',
				'front-page',
				'home',
				'index',
				'page',
				'privacy-policy',
				'search',
				'single',
				'singular',
				'tag',
				'taxonomy',
			),
			'template_area'       => array( 'footer', 'header', 'sidebar', 'uncategorized' ),
			'theme_feature'       => array(
				'align-wide',
				'appearance-tools',
				'automatic-feed-links',
				'block-template-parts',
				'block-templates',
				'border',
				'custom-background',
				'custom-header',
				'custom-line-height',
				'custom-logo',
				'custom-spacing',
				'custom-units',
				'customize-selective-refresh-widgets',
				'dark-editor-style',
				'disable-custom-colors',
				'disable-custom-font-sizes',
				'editor-color-palette',
				'editor-font-sizes',
				'editor-gradient-presets',
				'editor-styles',
				'html5',
				'link-color',
				'menus',
				'post-formats',
				'post-thumbnails',
				'responsive-embeds',
				'starter-content',
				'title-tag',
				'widgets',
				'widgets-block-editor',
				'wp-block-styles',
			),
			'block_api_version'   => array( 0, 1, 2, 3 ),
		);
	}

	/**
	 * One vocabulary.
	 *
	 * @param string $name Vocabulary name.
	 * @return array<int,mixed>
	 */
	public static function vocabulary( $name ) {
		$vocabularies = self::vocabularies();

		return isset( $vocabularies[ $name ] ) ? $vocabularies[ $name ] : array();
	}

	/* ==================================================================
	 * Field descriptors
	 * ================================================================ */

	/**
	 * An identifier field.
	 *
	 * @param string $grammar  Grammar name.
	 * @param bool   $optional Whether the empty string is allowed.
	 * @return array<string,mixed>
	 */
	public static function identifier( $grammar, $optional = false ) {
		return array( 'kind' => 'identifier', 'grammar' => $grammar, 'optional' => (bool) $optional );
	}

	/**
	 * A vocabulary field.
	 *
	 * @param string $vocabulary Vocabulary name.
	 * @param bool   $optional   Whether the empty string is allowed.
	 * @return array<string,mixed>
	 */
	public static function enumerated( $vocabulary, $optional = false ) {
		return array( 'kind' => 'enum', 'vocabulary' => $vocabulary, 'optional' => (bool) $optional );
	}

	/**
	 * An integer vocabulary field.
	 *
	 * @param string $vocabulary Vocabulary name.
	 * @return array<string,mixed>
	 */
	public static function enumerated_int( $vocabulary ) {
		return array( 'kind' => 'enum_int', 'vocabulary' => $vocabulary );
	}

	/**
	 * A flag.
	 *
	 * @return array<string,mixed>
	 */
	public static function flag() {
		return array( 'kind' => 'boolean' );
	}

	/**
	 * A cardinality, bounded by the ceiling of what it counts.
	 *
	 * The ceiling is required, not defaulted: a count whose upper bound
	 * nobody named is exactly the unbounded integer this contract exists to
	 * keep out of the schema.
	 *
	 * @param string $bound Limit name this count may not exceed.
	 * @return array<string,mixed>
	 */
	public static function counter( $bound ) {
		return array( 'kind' => 'count', 'bound' => $bound );
	}

	/**
	 * A database identity.
	 *
	 * Separate from `counter()` because clamping is not a legal answer here:
	 * a clamped identifier is a *different object's* identifier, so the
	 * runtime reports 0 — "no object" — and flags the response instead.
	 *
	 * @since 0.16.0
	 *
	 * @return array<string,mixed>
	 */
	public static function object_id() {
		return array( 'kind' => 'object_id', 'bound' => 'object_id' );
	}

	/**
	 * A quantity of bytes.
	 *
	 * @since 0.16.0
	 *
	 * @return array<string,mixed>
	 */
	public static function byte_count() {
		return array( 'kind' => 'byte_size', 'bound' => 'byte_size' );
	}

	/**
	 * A bounded list.
	 *
	 * @param array  $items Item descriptor.
	 * @param string $limit Limit name.
	 * @return array<string,mixed>
	 */
	public static function bounded_list( $items, $limit ) {
		return array( 'kind' => 'list', 'items' => $items, 'limit' => $limit );
	}

	/**
	 * A closed object.
	 *
	 * @param array $fields Field descriptors.
	 * @return array<string,mixed>
	 */
	public static function shape( $fields ) {
		return array( 'kind' => 'object', 'fields' => $fields );
	}

	/* ==================================================================
	 * The surfaces
	 * ================================================================ */

	/**
	 * The response contract of every discovery ability.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function surfaces() {
		return array(
			'wp-mcp/global-search'                 => self::shape( array_merge(
				array(
					'results'  => self::bounded_list( self::search_hit(), 'page_size' ),
					'searched' => self::bounded_list( self::enumerated( 'search_object_type' ), 'search_types' ),
					'skipped'  => self::bounded_list(
						self::shape( array(
							'type'   => self::enumerated( 'search_object_type' ),
							'reason' => self::enumerated( 'skip_reason' ),
						) ),
						'search_types'
					),
					'capped'   => self::flag(),
				),
				self::pagination_fields( 'search_results' )
			) ),
			'wp-mcp/list-post-statuses'            => self::shape( array(
				'statuses' => self::bounded_list( self::post_status(), 'collection' ),
				'total'    => self::counter( 'collection' ),
				'capped'   => self::flag(),
			) ),
			'wp-mcp/list-mime-types'               => self::shape( array(
				'mime_types'            => self::bounded_list(
					self::shape( array(
						'mime_type'  => self::identifier( 'mime' ),
						'extensions' => self::bounded_list( self::identifier( 'extension' ), 'mime_extensions' ),
					) ),
					'collection'
				),
				'total'                 => self::counter( 'collection' ),
				'capped'                => self::flag(),
				'max_upload_size_bytes' => self::byte_count(),
			) ),
			'wp-mcp/list-block-types'              => self::shape( array_merge(
				array(
					'block_types' => self::bounded_list( self::shape( self::block_summary_fields() ), 'page_size' ),
					'capped'      => self::flag(),
				),
				self::pagination_fields( 'block_scan' )
			) ),
			'wp-mcp/get-block-type'                => self::shape( array_merge(
				self::block_summary_fields(),
				array(
					'api_version'      => self::enumerated_int( 'block_api_version' ),
					'supports'         => self::bounded_list(
						self::shape( array(
							'feature'      => self::enumerated( 'block_support' ),
							'enabled'      => self::flag(),
							'sub_features' => self::bounded_list( self::enumerated( 'block_support_sub' ), 'block_sub_features' ),
						) ),
						'block_supports'
					),
					'attributes'       => self::bounded_list(
						self::shape( array(
							'name'        => self::identifier( 'attribute' ),
							'types'       => self::bounded_list( self::enumerated( 'attribute_type' ), 'attribute_types' ),
							'source'      => self::enumerated( 'attribute_source', true ),
							'has_default' => self::flag(),
							'has_enum'    => self::flag(),
						) ),
						'block_attributes'
					),
					'uses_context'     => self::bounded_list( self::enumerated( 'block_context' ), 'block_contexts' ),
					'provides_context' => self::bounded_list( self::enumerated( 'block_context' ), 'block_contexts' ),
					'capped'           => self::flag(),
				)
			) ),
			'wp-mcp/list-pattern-categories'       => self::shape( array(
				'categories' => self::bounded_list( self::identifier( 'key' ), 'collection' ),
				'total'      => self::counter( 'collection' ),
				'capped'     => self::flag(),
			) ),
			'wp-mcp/list-template-types'           => self::shape( array(
				'block_theme'               => self::flag(),
				'template_editing'          => self::flag(),
				'reason'                    => self::enumerated( 'template_reason' ),
				'template_types'            => self::bounded_list( self::enumerated( 'template_type' ), 'template_types' ),
				'template_part_areas'       => self::bounded_list( self::enumerated( 'template_area' ), 'template_areas' ),
				'total_template_types'      => self::counter( 'template_types' ),
				'total_template_part_areas' => self::counter( 'template_areas' ),
				'capped'                    => self::flag(),
			) ),
			'wp-mcp/get-current-user-capabilities' => self::shape( array(
				'user_id'        => self::object_id(),
				'roles'          => self::bounded_list( self::identifier( 'key' ), 'roles' ),
				'capabilities'   => self::bounded_list( self::identifier( 'capability' ), 'collection' ),
				'total'          => self::counter( 'collection' ),
				'capped'         => self::flag(),
				'is_super_admin' => self::flag(),
				'multisite'      => self::flag(),
			) ),
			'wp-mcp/list-feature-support'          => self::shape( array(
				'theme'    => self::shape( array(
					'stylesheet'     => self::identifier( 'theme', true ),
					'template'       => self::identifier( 'theme', true ),
					'is_block_theme' => self::flag(),
					'has_theme_json' => self::flag(),
				) ),
				'features' => self::bounded_list(
					self::shape( array(
						'feature'   => self::enumerated( 'theme_feature' ),
						'supported' => self::flag(),
					) ),
					'features'
				),
				'site'     => self::shape( array(
					'multisite'             => self::flag(),
					'block_editor'          => self::flag(),
					'site_editor'           => self::flag(),
					'pretty_permalinks'     => self::flag(),
					'application_passwords' => self::flag(),
					'widgets_block_editor'  => self::flag(),
					'nav_menu_locations'    => self::counter( 'registry_total' ),
					'registered_post_types' => self::counter( 'registry_total' ),
					'registered_taxonomies' => self::counter( 'registry_total' ),
				) ),
				'total'    => self::counter( 'features' ),
				'capped'   => self::flag(),
			) ),
		);
	}

	/**
	 * One search hit.
	 *
	 * @return array<string,mixed>
	 */
	private static function search_hit() {
		return self::shape( array(
			'object_type' => self::enumerated( 'search_object_type' ),
			'id'          => self::object_id(),
			'subtype'     => self::identifier( 'key', true ),
			'status'      => self::identifier( 'key', true ),
			'date_gmt'    => self::identifier( 'datetime', true ),
			'editable'    => self::flag(),
		) );
	}

	/**
	 * One registered post status.
	 *
	 * @return array<string,mixed>
	 */
	private static function post_status() {
		return self::shape( array(
			'name'                      => self::identifier( 'key' ),
			'public'                    => self::flag(),
			'internal'                  => self::flag(),
			'protected'                 => self::flag(),
			'private'                   => self::flag(),
			'exclude_from_search'       => self::flag(),
			'show_in_admin_all_list'    => self::flag(),
			'show_in_admin_status_list' => self::flag(),
		) );
	}

	/**
	 * The block fields the list and the detail response share.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function block_summary_fields() {
		return array(
			'name'            => self::identifier( 'block', true ),
			'category'        => self::identifier( 'key', true ),
			'is_dynamic'      => self::flag(),
			'parent'          => self::bounded_list( self::identifier( 'block' ), 'block_parents' ),
			'ancestor'        => self::bounded_list( self::identifier( 'block' ), 'block_parents' ),
			'supports_keys'   => self::bounded_list( self::enumerated( 'block_support' ), 'block_supports' ),
			'attribute_names' => self::bounded_list( self::identifier( 'attribute' ), 'block_attributes' ),
		);
	}

	/**
	 * The four pagination counters.
	 *
	 * `total` is bounded by the ceiling of the collection being paginated,
	 * and `total_pages` by the same number: `per_page` is at least one, so
	 * there can never be more pages than rows.
	 *
	 * @param string $total_bound Limit name bounding `total` for this surface.
	 * @return array<string,array<string,mixed>>
	 */
	private static function pagination_fields( $total_bound ) {
		return array(
			'total'       => self::counter( $total_bound ),
			'total_pages' => self::counter( $total_bound ),
			'page'        => self::counter( 'max_page' ),
			'per_page'    => self::counter( 'page_size' ),
		);
	}

	/**
	 * The pagination input properties of a discovery list ability.
	 *
	 * The shared contract in `WP_MCP_Ability_Schema` leaves `page` open at
	 * the top, which is exactly the unbounded number the output contract
	 * refuses to publish. Discovery declares the ceiling on the way in too,
	 * so a client sees the bound before it calls rather than as an error.
	 *
	 * @since 0.16.0
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function pagination_input_properties() {
		$properties = WP_MCP_Ability_Schema::pagination_input_properties();

		$properties['page']['maximum'] = self::limit( 'max_page' );

		return $properties;
	}

	/* ==================================================================
	 * Schema generation
	 * ================================================================ */

	/**
	 * The MCP output schema of one ability, generated from its descriptor.
	 *
	 * @param string $ability Ability name.
	 * @return array<string,mixed>
	 */
	public static function output_schema( $ability ) {
		$surfaces = self::surfaces();
		if ( ! isset( $surfaces[ $ability ] ) ) {
			return array( 'type' => 'object', 'properties' => array(), 'required' => array(), 'additionalProperties' => false );
		}

		return self::schema_for( $surfaces[ $ability ] );
	}

	/**
	 * Turn one descriptor into its JSON Schema fragment.
	 *
	 * @param array $descriptor Field descriptor.
	 * @return array<string,mixed>
	 */
	public static function schema_for( $descriptor ) {
		$kind = isset( $descriptor['kind'] ) ? $descriptor['kind'] : '';

		switch ( $kind ) {
			case 'identifier':
				$optional = ! empty( $descriptor['optional'] );
				return array(
					'type'      => 'string',
					'maxLength' => self::max_length( $descriptor['grammar'] ),
					'pattern'   => self::schema_pattern( $descriptor['grammar'], $optional ),
				);

			case 'enum':
				$values = self::vocabulary( $descriptor['vocabulary'] );
				if ( ! empty( $descriptor['optional'] ) ) {
					$values = array_merge( array( '' ), $values );
				}
				return array( 'type' => 'string', 'enum' => array_values( $values ) );

			case 'enum_int':
				return array( 'type' => 'integer', 'enum' => array_values( self::vocabulary( $descriptor['vocabulary'] ) ) );

			case 'boolean':
				return array( 'type' => 'boolean' );

			case 'count':
			case 'object_id':
			case 'byte_size':
				return array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => self::limit( isset( $descriptor['bound'] ) ? $descriptor['bound'] : '' ),
				);

			case 'list':
				return array(
					'type'     => 'array',
					'maxItems' => self::limit( $descriptor['limit'] ),
					'items'    => self::schema_for( $descriptor['items'] ),
				);

			case 'object':
				$properties = array();
				foreach ( $descriptor['fields'] as $field => $child ) {
					$properties[ $field ] = self::schema_for( $child );
				}
				return array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => array(),
					'additionalProperties' => false,
				);
		}

		// Unreachable for a well-formed descriptor, and deliberately useless.
		return array( 'type' => 'boolean' );
	}

	/* ==================================================================
	 * Runtime bounds
	 *
	 * The other half of the numeric contract. `schema_for()` publishes the
	 * `maximum`; these two enforce the same number on the way out, so a
	 * response cannot violate the schema it ships with — and, when a value
	 * does not fit, the caller is told rather than handed a quietly wrong
	 * number.
	 * ================================================================ */

	/**
	 * Report a cardinality inside its published window.
	 *
	 * A value outside `[0, maximum]` is reported at the nearest bound and
	 * `$clamped` is set, which every discovery surface turns into `capped`.
	 * The alternative — emitting the raw number — would either break the
	 * ability's own `maximum` or, on the negative side, publish a count that
	 * cannot exist.
	 *
	 * @since 0.16.0
	 *
	 * @param mixed  $value   Raw number.
	 * @param string $bound   Limit name this count may not exceed.
	 * @param bool   $clamped Set to true when the value did not fit.
	 * @return int
	 */
	public static function bounded_count( $value, $bound, &$clamped = null ) {
		$clamped = false;
		$ceiling = self::limit( $bound );

		if ( is_int( $value ) ) {
			$number = $value;
		} elseif ( is_float( $value ) && ! is_nan( $value ) ) {
			// A float above PHP's integer range saturates under (int), so
			// the comparison happens before the cast, not after it.
			if ( $value >= (float) $ceiling ) {
				$clamped = true;
				return $ceiling;
			}
			$number = $value < 0 ? -1 : (int) $value;
		} else {
			$clamped = true;
			return 0;
		}

		if ( $number < 0 ) {
			$clamped = true;
			return 0;
		}
		if ( $number > $ceiling ) {
			$clamped = true;
			return $ceiling;
		}

		return $number;
	}

	/**
	 * Report a database identity inside its published window.
	 *
	 * Never clamps. An identifier is a name for one row, so the nearest
	 * legal value is not an approximation of it — it points at a different
	 * object. Anything that does not fit is reported as 0 and `$overflow` is
	 * set, and the caller decides whether that means "drop this row" or
	 * "flag this response".
	 *
	 * @since 0.16.0
	 *
	 * @param mixed $value    Raw identifier.
	 * @param bool  $overflow Set to true when the value is not a reportable ID.
	 * @return int
	 */
	public static function bounded_id( $value, &$overflow = null ) {
		$overflow = false;
		$ceiling  = self::limit( 'object_id' );

		if ( is_int( $value ) ) {
			if ( $value < 0 || $value > $ceiling ) {
				$overflow = true;
				return 0;
			}
			return $value;
		}

		/*
		 * `$wpdb` hands identifiers back as strings, and a string of digits
		 * wider than PHP's integer range saturates under `(int)` — silently
		 * turning one object's ID into another's. Compare the digits
		 * themselves, before any cast can lose them.
		 */
		if ( ! is_string( $value ) || 1 !== preg_match( '#^[0-9]{1,32}$#D', $value ) ) {
			$overflow = true;
			return 0;
		}

		$digits = ltrim( $value, '0' );
		if ( '' === $digits ) {
			return 0;
		}

		$limit = (string) $ceiling;
		if ( strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 ) ) {
			$overflow = true;
			return 0;
		}

		return (int) $digits;
	}
}
