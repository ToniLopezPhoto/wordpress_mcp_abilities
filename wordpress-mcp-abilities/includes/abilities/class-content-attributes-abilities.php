<?php
/**
 * WordPress MCP Abilities — Content attributes domain.
 *
 * Registers identity/attribute-change abilities for posts and pages:
 * author reassignment, slug, sticky flag, password protection, and
 * page-specific attributes (parent/menu_order/template).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Content_Attributes_Abilities
 */
class WP_MCP_Content_Attributes_Abilities {

	/**
	 * Register all content-attributes abilities.
	 */
	public static function register() {
		self::register_change_post_author();
		self::register_update_post_slug();
		self::register_stick_post();
		self::register_unstick_post();
		self::register_set_post_password();
		self::register_change_page_author();
		self::register_update_page_slug();
		self::register_update_page_attributes();
	}

	/* ------------------------------------------------------------------
	 * Shared schema fragment (private, specific to this file)
	 * ---------------------------------------------------------------- */

	private static function lifecycle_result_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'title'    => array( 'type' => 'string' ),
				'status'   => array( 'type' => 'string' ),
				'date'     => array( 'type' => 'string' ),
				'modified' => array( 'type' => 'string' ),
				'link'     => array( 'type' => 'string' ),
			),
		);
	}

	private static function post_summary_output_schema() {
		return array(
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
		);
	}

	/* ------------------------------------------------------------------
	 * Posts
	 * ---------------------------------------------------------------- */

	private static function register_change_post_author() {
		wp_register_ability( 'wp-mcp/change-post-author', array(
			'label'       => __( 'Change Post Author', 'wordpress-mcp-abilities' ),
			'description' => __( 'Reassign a post to a different author. Requires edit_others_posts, regardless of the target author.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'       => array( 'type' => 'integer', 'description' => 'ID of the post.', 'minimum' => 1 ),
					'new_author_id' => array( 'type' => 'integer', 'description' => 'ID of the new author (must be an existing user who can author content).', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id', 'new_author_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'change_post_author' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_update_post_slug() {
		wp_register_ability( 'wp-mcp/update-post-slug', array(
			'label'       => __( 'Update Post Slug', 'wordpress-mcp-abilities' ),
			'description' => __( 'Change a post\'s URL slug. WordPress deduplicates automatically if the slug is already taken.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post.', 'minimum' => 1 ),
					'slug'    => array( 'type' => 'string', 'description' => 'New slug.', 'minLength' => 1 ),
				),
				'required'             => array( 'post_id', 'slug' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'update_post_slug' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_stick_post() {
		wp_register_ability( 'wp-mcp/stick-post', array(
			'label'       => __( 'Stick Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Pin a post to the front of the blog listing. Requires edit_others_posts, since it affects what every visitor sees.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post to stick.', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::post_summary_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'stick_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );
	}

	private static function register_unstick_post() {
		wp_register_ability( 'wp-mcp/unstick-post', array(
			'label'       => __( 'Unstick Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Remove a post\'s sticky flag. Requires edit_others_posts.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post to unstick.', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::post_summary_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'unstick_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );
	}

	private static function register_set_post_password() {
		wp_register_ability( 'wp-mcp/set-post-password', array(
			'label'       => __( 'Set Post Password', 'wordpress-mcp-abilities' ),
			'description' => __( 'Set or clear a post\'s access password. Pass an empty string to remove protection.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'  => array( 'type' => 'integer', 'description' => 'ID of the post.', 'minimum' => 1 ),
					'password' => array( 'type' => 'string', 'description' => 'New password. Empty string clears protection.' ),
				),
				'required'             => array( 'post_id', 'password' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::post_summary_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'set_post_password' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Pages
	 * ---------------------------------------------------------------- */

	private static function register_change_page_author() {
		wp_register_ability( 'wp-mcp/change-page-author', array(
			'label'       => __( 'Change Page Author', 'wordpress-mcp-abilities' ),
			'description' => __( 'Reassign a page to a different author. Requires edit_others_posts, regardless of the target author.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id'       => array( 'type' => 'integer', 'description' => 'ID of the page.', 'minimum' => 1 ),
					'new_author_id' => array( 'type' => 'integer', 'description' => 'ID of the new author (must be an existing user who can author content).', 'minimum' => 1 ),
				),
				'required'             => array( 'page_id', 'new_author_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'change_page_author' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_update_page_slug() {
		wp_register_ability( 'wp-mcp/update-page-slug', array(
			'label'       => __( 'Update Page Slug', 'wordpress-mcp-abilities' ),
			'description' => __( 'Change a page\'s URL slug. WordPress deduplicates automatically if the slug is already taken.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id' => array( 'type' => 'integer', 'description' => 'ID of the page.', 'minimum' => 1 ),
					'slug'    => array( 'type' => 'string', 'description' => 'New slug.', 'minLength' => 1 ),
				),
				'required'             => array( 'page_id', 'slug' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'update_page_slug' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_update_page_attributes() {
		wp_register_ability( 'wp-mcp/update-page-attributes', array(
			'label'       => __( 'Update Page Attributes', 'wordpress-mcp-abilities' ),
			'description' => __( 'Update a page\'s parent, menu order, and/or template. Mirrors the WP-admin "Page Attributes" panel.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id'    => array( 'type' => 'integer', 'description' => 'ID of the page.', 'minimum' => 1 ),
					'parent_id'  => array( 'type' => 'integer', 'description' => 'ID of the new parent page. 0 clears the parent.', 'minimum' => 0 ),
					'menu_order' => array( 'type' => 'integer', 'description' => 'New menu order.' ),
					'template'   => array( 'type' => 'string', 'description' => 'Page template file path. Empty string resets to the default template.' ),
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::post_summary_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'update_page_attributes' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}
}
