<?php
/**
 * WordPress MCP Abilities — Content domain (posts, pages).
 *
 * Registers the `wp-mcp-content` category's abilities: list-posts,
 * get-post, create-post, update-post, publish-post, list-pages, get-page.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Content_Abilities
 */
class WP_MCP_Content_Abilities {

	/**
	 * Register all content-domain abilities.
	 */
	public static function register() {
		self::register_list_posts();
		self::register_get_post();
		self::register_create_post();
		self::register_update_post();
		self::register_publish_post();
		self::register_list_pages();
		self::register_get_page();
		self::register_create_page();
		self::register_update_page();
	}

	/* ------------------------------------------------------------------
	 * 1. wp-mcp/list-posts
	 * ---------------------------------------------------------------- */

	private static function register_list_posts() {
		wp_register_ability( 'wp-mcp/list-posts', array(
			'label'       => __( 'List Posts', 'wordpress-mcp-abilities' ),
			'description' => __( 'Query published posts and own drafts/pending. Returns id, title, status, excerpt, author, date, modified, link.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					array(
						'status' => array(
							'type'        => 'string',
							'description' => 'Post status filter. Only publish, draft (own), and pending (own) are allowed.',
							'enum'        => array( 'publish', 'draft', 'pending' ),
							'default'     => 'publish',
						),
						'search' => array(
							'type'        => 'string',
							'description' => 'Search keyword.',
						),
					),
					WP_MCP_Ability_Schema::pagination_input_properties()
				),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array_merge(
					array(
						'posts' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'       => array( 'type' => 'integer' ),
									'title'    => array( 'type' => 'string' ),
									'status'   => array( 'type' => 'string' ),
									'excerpt'  => array( 'type' => 'string' ),
									'author'   => array( 'type' => 'integer' ),
									'date'     => array( 'type' => 'string' ),
									'modified' => array( 'type' => 'string' ),
									'link'     => array( 'type' => 'string' ),
								),
							),
						),
					),
					WP_MCP_Ability_Schema::pagination_output_properties()
				),
			),
			'execute_callback'    => array( 'WP_MCP_Posts', 'list_posts' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_read();
			},
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * 2. wp-mcp/get-post
	 * ---------------------------------------------------------------- */

	private static function register_get_post() {
		wp_register_ability( 'wp-mcp/get-post', array(
			'label'       => __( 'Get Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Retrieve a single post by ID. Only returns posts (not pages or other types). Checks read permission.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'The post ID to retrieve.',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'             => array( 'type' => 'integer' ),
					'title'          => array( 'type' => 'string' ),
					'content'        => array( 'type' => 'string' ),
					'excerpt'        => array( 'type' => 'string' ),
					'status'         => array( 'type' => 'string' ),
					'author'         => array( 'type' => 'integer' ),
					'categories'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					'tags'           => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					'featured_media' => array( 'type' => 'integer' ),
					'date'           => array( 'type' => 'string' ),
					'modified'       => array( 'type' => 'string' ),
					'link'           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'WP_MCP_Posts', 'get_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_read();
			},
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * 3. wp-mcp/create-post
	 * ---------------------------------------------------------------- */

	private static function register_create_post() {
		wp_register_ability( 'wp-mcp/create-post', array(
			'label'       => __( 'Create Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a new post as draft. Author is always the authenticated user. Only accepts title, content, excerpt, categories (IDs), and tags (IDs).', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'      => array(
						'type'        => 'string',
						'description' => 'Post title.',
						'minLength'   => 1,
					),
					'content'    => array(
						'type'        => 'string',
						'description' => 'Post content (safe HTML allowed via wp_kses_post).',
						'minLength'   => 1,
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => 'Post excerpt (optional).',
					),
					'categories' => array(
						'type'        => 'array',
						'description' => 'Array of existing category term IDs.',
						'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'maxItems'    => 100,
					),
					'tags'       => array(
						'type'        => 'array',
						'description' => 'Array of existing tag term IDs.',
						'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'maxItems'    => 100,
					),
				),
				'required'             => array( 'title', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string' ),
					'excerpt'  => array( 'type' => 'string' ),
					'author'   => array( 'type' => 'integer' ),
					'date'     => array( 'type' => 'string' ),
					'modified' => array( 'type' => 'string' ),
					'link'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'WP_MCP_Posts', 'create_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ), // Not destructive, not idempotent.
		) );
	}

	/* ------------------------------------------------------------------
	 * 4. wp-mcp/update-post
	 * ---------------------------------------------------------------- */

	private static function register_update_post() {
		wp_register_ability( 'wp-mcp/update-post', array(
			'label'       => __( 'Update Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update a post owned by the current user. Only title, content, excerpt, categories, and tags can be changed. Cannot change author, type, status, or slug.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'    => array(
						'type'        => 'integer',
						'description' => 'ID of the post to update.',
						'minimum'     => 1,
					),
					'title'      => array(
						'type'        => 'string',
						'description' => 'New post title.',
					),
					'content'    => array(
						'type'        => 'string',
						'description' => 'New post content (safe HTML allowed via wp_kses_post).',
					),
					'excerpt'    => array(
						'type'        => 'string',
						'description' => 'New post excerpt.',
					),
					'categories' => array(
						'type'        => 'array',
						'description' => 'Array of existing category term IDs to replace current categories.',
						'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'maxItems'    => 100,
					),
					'tags'       => array(
						'type'        => 'array',
						'description' => 'Array of existing tag term IDs to replace current tags.',
						'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'maxItems'    => 100,
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string' ),
					'excerpt'  => array( 'type' => 'string' ),
					'author'   => array( 'type' => 'integer' ),
					'date'     => array( 'type' => 'string' ),
					'modified' => array( 'type' => 'string' ),
					'link'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'WP_MCP_Posts', 'update_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ), // Destructive (overwrites), not idempotent (post_modified changes).
		) );
	}

	/* ------------------------------------------------------------------
	 * 5. wp-mcp/publish-post
	 * ---------------------------------------------------------------- */

	private static function register_publish_post() {
		wp_register_ability( 'wp-mcp/publish-post', array(
			'label'       => __( 'Publish Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Publish a draft post owned by the current user. Idempotent: if already published, returns success without modification.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID of the post to publish.',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'             => array( 'type' => 'integer' ),
					'title'          => array( 'type' => 'string' ),
					'status'         => array( 'type' => 'string' ),
					'published_date' => array( 'type' => 'string' ),
					'modified'       => array( 'type' => 'string' ),
					'link'           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'WP_MCP_Posts', 'publish_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts()
					&& WP_MCP_Permissions::can_publish_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ), // Destructive (status change), idempotent (no-op if already published).
		) );
	}

	/* ------------------------------------------------------------------
	 * 6. wp-mcp/list-pages
	 * ---------------------------------------------------------------- */

	private static function register_list_pages() {
		wp_register_ability( 'wp-mcp/list-pages', array(
			'label'       => __( 'List Pages', 'wordpress-mcp-abilities' ),
			'description' => __( 'List published WordPress pages. Returns id, title, status, modified, and link.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Search keyword.',
						),
					),
					WP_MCP_Ability_Schema::pagination_input_properties()
				),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array_merge(
					array(
						'pages' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'       => array( 'type' => 'integer' ),
									'title'    => array( 'type' => 'string' ),
									'status'   => array( 'type' => 'string' ),
									'modified' => array( 'type' => 'string' ),
									'link'     => array( 'type' => 'string' ),
								),
							),
						),
					),
					WP_MCP_Ability_Schema::pagination_output_properties()
				),
			),
			'execute_callback'    => array( 'WP_MCP_Pages', 'list_pages' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_read();
			},
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * 7. wp-mcp/get-page
	 * ---------------------------------------------------------------- */

	private static function register_get_page() {
		wp_register_ability( 'wp-mcp/get-page', array(
			'label'       => __( 'Get Page', 'wordpress-mcp-abilities' ),
			'description' => __( 'Retrieve a single page by ID. Only returns pages (not posts or other types). Checks read permission.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id' => array(
						'type'        => 'integer',
						'description' => 'The page ID to retrieve.',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'content'  => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string' ),
					'modified' => array( 'type' => 'string' ),
					'link'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'WP_MCP_Pages', 'get_page' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_read();
			},
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * 8. wp-mcp/create-page
	 * ---------------------------------------------------------------- */

	private static function register_create_page() {
		wp_register_ability( 'wp-mcp/create-page', array(
			'label'       => __( 'Create Page', 'wordpress-mcp-abilities' ),
			'description' => __( 'Create a new page as draft. Author is always the authenticated user. Only accepts title, content, and excerpt.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'   => array(
						'type'        => 'string',
						'description' => 'Page title.',
						'minLength'   => 1,
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'Page content (safe HTML allowed via wp_kses_post).',
						'minLength'   => 1,
					),
					'excerpt' => array(
						'type'        => 'string',
						'description' => 'Page excerpt (optional).',
					),
				),
				'required'             => array( 'title', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string' ),
					'excerpt'  => array( 'type' => 'string' ),
					'author'   => array( 'type' => 'integer' ),
					'date'     => array( 'type' => 'string' ),
					'modified' => array( 'type' => 'string' ),
					'link'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'WP_MCP_Pages', 'create_page' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ), // Not destructive, not idempotent.
		) );
	}

	/* ------------------------------------------------------------------
	 * 9. wp-mcp/update-page
	 * ---------------------------------------------------------------- */

	private static function register_update_page() {
		wp_register_ability( 'wp-mcp/update-page', array(
			'label'       => __( 'Update Page', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update a page the current user can edit. Only title, content, and excerpt can be changed. Cannot change author, type, status, or slug.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id' => array(
						'type'        => 'integer',
						'description' => 'ID of the page to update.',
						'minimum'     => 1,
					),
					'title'   => array(
						'type'        => 'string',
						'description' => 'New page title.',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'New page content (safe HTML allowed via wp_kses_post).',
					),
					'excerpt' => array(
						'type'        => 'string',
						'description' => 'New page excerpt.',
					),
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string' ),
					'excerpt'  => array( 'type' => 'string' ),
					'author'   => array( 'type' => 'integer' ),
					'date'     => array( 'type' => 'string' ),
					'modified' => array( 'type' => 'string' ),
					'link'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'WP_MCP_Pages', 'update_page' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ), // Destructive (overwrites), not idempotent (post_modified changes).
		) );
	}
}
