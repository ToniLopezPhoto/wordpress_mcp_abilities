<?php
/**
 * WordPress MCP Abilities — Content revisions domain.
 *
 * Registers revision list/get/restore abilities for posts and pages,
 * plus post-only autosave lookup, backed by WP_MCP_Content_Lifecycle.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Content_Revisions_Abilities
 */
class WP_MCP_Content_Revisions_Abilities {

	/**
	 * Register all content-revisions abilities.
	 */
	public static function register() {
		self::register_list_post_revisions();
		self::register_get_post_revision();
		self::register_restore_post_revision();
		self::register_get_post_autosave();
		self::register_list_page_revisions();
		self::register_get_page_revision();
		self::register_restore_page_revision();
	}

	/* ------------------------------------------------------------------
	 * Shared schema fragments (private, specific to this file)
	 * ---------------------------------------------------------------- */

	private static function revision_summary_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'revisions' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'author'   => array( 'type' => 'integer' ),
								'date'     => array( 'type' => 'string' ),
								'modified' => array( 'type' => 'string' ),
							),
						),
					),
				),
				WP_MCP_Ability_Schema::pagination_output_properties()
			),
		);
	}

	private static function revision_detail_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'parent_id' => array( 'type' => 'integer' ),
				'author'    => array( 'type' => 'integer' ),
				'title'     => array( 'type' => 'string' ),
				'content'   => array( 'type' => 'string' ),
				'excerpt'   => array( 'type' => 'string' ),
				'date'      => array( 'type' => 'string' ),
				'modified'  => array( 'type' => 'string' ),
			),
		);
	}

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

	/* ------------------------------------------------------------------
	 * Posts
	 * ---------------------------------------------------------------- */

	private static function register_list_post_revisions() {
		wp_register_ability( 'wp-mcp/list-post-revisions', array(
			'label'       => __( 'List Post Revisions', 'wordpress-mcp-abilities' ),
			'description' => __( 'List saved revisions for a post.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					array(
						'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post.', 'minimum' => 1 ),
					),
					WP_MCP_Ability_Schema::pagination_input_properties()
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::revision_summary_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'list_post_revisions' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_get_post_revision() {
		wp_register_ability( 'wp-mcp/get-post-revision', array(
			'label'       => __( 'Get Post Revision', 'wordpress-mcp-abilities' ),
			'description' => __( 'Retrieve the full content of a single post revision.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'     => array( 'type' => 'integer', 'description' => 'ID of the parent post.', 'minimum' => 1 ),
					'revision_id' => array( 'type' => 'integer', 'description' => 'ID of the revision.', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id', 'revision_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::revision_detail_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'get_post_revision' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_restore_post_revision() {
		wp_register_ability( 'wp-mcp/restore-post-revision', array(
			'label'       => __( 'Restore Post Revision', 'wordpress-mcp-abilities' ),
			'description' => __( 'Restore a post to a previous revision\'s content. Not idempotent -- restoring creates a new current revision each time.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'     => array( 'type' => 'integer', 'description' => 'ID of the parent post.', 'minimum' => 1 ),
					'revision_id' => array( 'type' => 'integer', 'description' => 'ID of the revision to restore.', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id', 'revision_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'restore_post_revision' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	private static function register_get_post_autosave() {
		wp_register_ability( 'wp-mcp/get-post-autosave', array(
			'label'       => __( 'Get Post Autosave', 'wordpress-mcp-abilities' ),
			'description' => __( 'Get the current user\'s most recent autosave for a post, if one exists. WordPress keeps at most one active autosave per user per post.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post.', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'exists'   => array( 'type' => 'boolean' ),
					'id'       => array( 'type' => 'integer' ),
					'author'   => array( 'type' => 'integer' ),
					'title'    => array( 'type' => 'string' ),
					'content'  => array( 'type' => 'string' ),
					'excerpt'  => array( 'type' => 'string' ),
					'date'     => array( 'type' => 'string' ),
					'modified' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( 'WP_MCP_Posts', 'get_post_autosave' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Pages
	 * ---------------------------------------------------------------- */

	private static function register_list_page_revisions() {
		wp_register_ability( 'wp-mcp/list-page-revisions', array(
			'label'       => __( 'List Page Revisions', 'wordpress-mcp-abilities' ),
			'description' => __( 'List saved revisions for a page.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					array(
						'page_id' => array( 'type' => 'integer', 'description' => 'ID of the page.', 'minimum' => 1 ),
					),
					WP_MCP_Ability_Schema::pagination_input_properties()
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::revision_summary_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'list_page_revisions' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_get_page_revision() {
		wp_register_ability( 'wp-mcp/get-page-revision', array(
			'label'       => __( 'Get Page Revision', 'wordpress-mcp-abilities' ),
			'description' => __( 'Retrieve the full content of a single page revision.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id'     => array( 'type' => 'integer', 'description' => 'ID of the parent page.', 'minimum' => 1 ),
					'revision_id' => array( 'type' => 'integer', 'description' => 'ID of the revision.', 'minimum' => 1 ),
				),
				'required'             => array( 'page_id', 'revision_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::revision_detail_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'get_page_revision' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	private static function register_restore_page_revision() {
		wp_register_ability( 'wp-mcp/restore-page-revision', array(
			'label'       => __( 'Restore Page Revision', 'wordpress-mcp-abilities' ),
			'description' => __( 'Restore a page to a previous revision\'s content. Not idempotent -- restoring creates a new current revision each time.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id'     => array( 'type' => 'integer', 'description' => 'ID of the parent page.', 'minimum' => 1 ),
					'revision_id' => array( 'type' => 'integer', 'description' => 'ID of the revision to restore.', 'minimum' => 1 ),
				),
				'required'             => array( 'page_id', 'revision_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'restore_page_revision' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}
}
