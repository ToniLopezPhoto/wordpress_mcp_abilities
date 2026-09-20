<?php
/**
 * WordPress MCP Abilities — Global discovery domain registration (issue #16).
 *
 * Nine fixed, individually schema'd read-only operations. None of them
 * accepts a REST route, an endpoint, an option name, a callback, a
 * filesystem path or a query: this domain describes what is registered and
 * what this caller may reach, and there is deliberately no
 * `describe( object, args )` dispatcher behind it (see epic #1).
 *
 * Every output schema here is *generated* from
 * `WP_MCP_Discovery_Contract::output_schema()`, not hand-written. That is
 * what makes the conservative contract machine-checkable rather than a
 * promise in prose: the same table that tells `WP_MCP_Discovery` which
 * grammar to validate a name against, which vocabulary to allowlist a term
 * against and which ceiling to bound a list at is the table these `pattern`,
 * `maxLength`, `enum` and `maxItems` keywords come from. A response carrying
 * a title, a label, a description, a registration map or any other free text
 * would fail its own published schema, and a runtime ceiling cannot drift
 * away from the `maxItems` a client validates against, because there is only
 * one number.
 *
 * Input schemas are hand-assembled — they are the request vocabulary, not the
 * response contract, and each one is deliberately narrow — but every bound
 * inside them still comes from that same table. A selector is published with
 * the grammar the runtime validates it against (`block_type` with `block`,
 * `block_namespace` with `block_ns`) and pagination is published through
 * `WP_MCP_Discovery_Contract::pagination_input_properties()`, which declares
 * the `page` ceiling the *response* schema already bounds `page` at. A client
 * therefore sees a refusal as a documented bound rather than as a surprise,
 * and an input this domain would reject cannot be one its own schema accepts.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.16.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Discovery_Abilities
 */
class WP_MCP_Discovery_Abilities {

	/** Register every discovery ability. */
	public static function register() {
		wp_register_ability( 'wp-mcp/global-search', array(
			'label'       => __( 'Global Search', 'wordpress-mcp-abilities' ),
			'description' => __( 'Search posts, pages, custom post types, media, taxonomy terms and users at once, restricted to what the current user may actually see. Non-public statuses are searched only across the post types the caller may edit, every post row is re-checked with the native read_post meta-capability, and users are searched only with list_users and never by e-mail address. Returns identity and reachability only — object kind, ID, registered type and status, timestamp and whether the caller may edit it — never a title, a slug, a permalink or any other content.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-discovery',
			'input_schema' => self::object_schema( array_merge( array(
				'search' => array(
					'type'        => 'string',
					'description' => 'Search phrase (2-200 characters).',
					'minLength'   => 2,
					'maxLength'   => 200,
				),
				'types'  => array(
					'type'        => 'array',
					'description' => 'Object kinds to search. Defaults to all four.',
					'maxItems'    => WP_MCP_Discovery_Contract::limit( 'search_types' ),
					'items'       => array(
						'type' => 'string',
						'enum' => WP_MCP_Discovery_Contract::vocabulary( 'search_object_type' ),
					),
				),
			), WP_MCP_Discovery_Contract::pagination_input_properties() ), array( 'search' ) ),
			'output_schema'       => WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/global-search' ),
			'execute_callback'    => array( 'WP_MCP_Discovery', 'global_search' ),
			'permission_callback' => array( 'WP_MCP_Discovery', 'can_read' ),
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-post-statuses', array(
			'label'       => __( 'List Post Statuses', 'wordpress-mcp-abilities' ),
			'description' => __( 'List every registered post status as a name plus its visibility flags, so an agent can tell a public status from a protected, private or internal one before it asks for content. The registered label is translated free text and is not reported.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-discovery',
			'input_schema'        => self::empty_schema(),
			'output_schema'       => WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-post-statuses' ),
			'execute_callback'    => array( 'WP_MCP_Discovery', 'list_post_statuses' ),
			'permission_callback' => array( 'WP_MCP_Discovery', 'can_read' ),
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-mime-types', array(
			'label'       => __( 'List Allowed Mime Types', 'wordpress-mcp-abilities' ),
			'description' => __( 'List the mime types and file extensions WordPress will accept from this caller, plus the maximum upload size. Reports the caller own allowance, not a theoretical site-wide list.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-discovery',
			'input_schema'        => self::empty_schema(),
			'output_schema'       => WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-mime-types' ),
			'execute_callback'    => array( 'WP_MCP_Discovery', 'list_mime_types' ),
			'permission_callback' => array( 'WP_MCP_Discovery', 'can_upload_files' ),
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-block-types', array(
			'label'       => __( 'List Block Types', 'wordpress-mcp-abilities' ),
			'description' => __( 'List registered block types as identifiers: block name, category slug, parent and ancestor block names, attribute names, the allowlisted supports they declare and whether they render dynamically. The registry scan is bounded and reports capped when it stops early, so a site with thousands of registered blocks answers in bounded work. The registered title, description and keywords are free text and are not reported; a render callback is never exposed, only the boolean; a name or a support outside its grammar or allowlist is dropped.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-discovery',
			'input_schema' => self::object_schema( array_merge( array(
				'block_namespace' => array(
					'type'        => 'string',
					'description' => 'Only blocks in this namespace, e.g. core.',
					'minLength'   => 1,
					'maxLength'   => WP_MCP_Discovery_Contract::max_length( 'block_ns' ),
					'pattern'     => WP_MCP_Discovery_Contract::schema_pattern( 'block_ns' ),
				),
			), WP_MCP_Discovery_Contract::pagination_input_properties() ), array() ),
			'output_schema'       => WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-block-types' ),
			'execute_callback'    => array( 'WP_MCP_Discovery', 'list_block_types' ),
			'permission_callback' => array( 'WP_MCP_Discovery', 'can_edit_posts' ),
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-block-type', array(
			'label'       => __( 'Get Block Type', 'wordpress-mcp-abilities' ),
			'description' => __( 'Describe one registered block type through allowlisted vocabularies only: the supports it declares as feature and sub-feature names, its attributes as name plus JSON Schema types, source and whether they have a default or an enumeration, and the block context names it uses and provides. Registration values never leave the site — not the raw supports array, not attribute defaults or enumerations, not the context map, not the render callback. The API version is reported only when it is one the block editor defines.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-discovery',
			'input_schema'  => self::object_schema( array(
				'block_type' => array(
					'type'        => 'string',
					'description' => 'Registered block name, e.g. core/paragraph.',
					'minLength'   => 3,
					'maxLength'   => WP_MCP_Discovery_Contract::max_length( 'block' ),
					'pattern'     => WP_MCP_Discovery_Contract::schema_pattern( 'block' ),
				),
			), array( 'block_type' ) ),
			'output_schema'       => WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/get-block-type' ),
			'execute_callback'    => array( 'WP_MCP_Discovery', 'get_block_type' ),
			'permission_callback' => array( 'WP_MCP_Discovery', 'can_edit_posts' ),
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-pattern-categories', array(
			'label'       => __( 'List Pattern Categories', 'wordpress-mcp-abilities' ),
			'description' => __( 'List the names of the registered block pattern categories, which are the vocabulary the pattern abilities of issue #8 are grouped by. Labels and descriptions are translated free text and are not reported.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-discovery',
			'input_schema'        => self::empty_schema(),
			'output_schema'       => WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-pattern-categories' ),
			'execute_callback'    => array( 'WP_MCP_Discovery', 'list_pattern_categories' ),
			'permission_callback' => array( 'WP_MCP_Discovery', 'can_edit_posts' ),
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-template-types', array(
			'label'       => __( 'List Template Types and Areas', 'wordpress-mcp-abilities' ),
			'description' => __( 'List the block template type and template-part area slugs this installation offers, from the fixed core vocabularies, and whether the active theme is a block theme at all. The FSE vocabulary is reported only where a Site Editor exists: a classic theme without block-templates support answers with empty lists, block_theme false and reason classic_theme, rather than advertising templates nothing here can edit. Titles and descriptions are not reported.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-discovery',
			'input_schema'        => self::empty_schema(),
			'output_schema'       => WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-template-types' ),
			'execute_callback'    => array( 'WP_MCP_Discovery', 'list_template_types' ),
			'permission_callback' => array( 'WP_MCP_Discovery', 'can_edit_theme_options' ),
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-current-user-capabilities', array(
			'label'       => __( 'Get Current User Capabilities', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report the authenticated caller effective roles and granted capabilities — what current_user_can() actually answers, including per-user grants — so an agent can check what it may do before it tries. No profile data, no e-mail address and no authentication material.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-discovery',
			'input_schema'        => self::empty_schema(),
			'output_schema'       => WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/get-current-user-capabilities' ),
			'execute_callback'    => array( 'WP_MCP_Discovery', 'get_current_user_capabilities' ),
			'permission_callback' => array( 'WP_MCP_Discovery', 'is_authenticated' ),
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-feature-support', array(
			'label'       => __( 'List Feature Support', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report which of a fixed vocabulary of theme features the active theme declares support for, plus the installation capabilities an agent has to branch on (block editor, Site Editor, pretty permalinks, application passwords, multisite). Booleans and counts only: never the registered feature arguments, which contain PHP callbacks and asset paths.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-discovery',
			'input_schema'        => self::empty_schema(),
			'output_schema'       => WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-feature-support' ),
			'execute_callback'    => array( 'WP_MCP_Discovery', 'list_feature_support' ),
			'permission_callback' => array( 'WP_MCP_Discovery', 'can_edit_posts' ),
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Input schema helpers
	 * ---------------------------------------------------------------- */

	/**
	 * An ability that takes no input.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_schema() {
		return self::object_schema( array(), array() );
	}

	/**
	 * @param array $properties Schema properties.
	 * @param array $required   Required property names.
	 * @return array<string,mixed>
	 */
	private static function object_schema( $properties, $required ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}
}
