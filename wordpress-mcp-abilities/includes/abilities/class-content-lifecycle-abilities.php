<?php
/**
 * WordPress MCP Abilities — Content lifecycle domain.
 *
 * Registers publish-state, scheduling, and trash/restore/delete
 * abilities for posts and pages, backed by WP_MCP_Content_Lifecycle
 * (shared) and WP_MCP_Posts::change_post_status() (post-only).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Content_Lifecycle_Abilities
 */
class WP_MCP_Content_Lifecycle_Abilities {

	/**
	 * Register all content-lifecycle abilities.
	 */
	public static function register() {
		self::register_unpublish_post();
		self::register_schedule_post();
		self::register_change_post_status();
		self::register_trash_post();
		self::register_restore_post();
		self::register_delete_post_permanently();
		self::register_publish_page();
		self::register_unpublish_page();
		self::register_schedule_page();
		self::register_trash_page();
		self::register_restore_page();
		self::register_delete_page_permanently();
	}

	/* ------------------------------------------------------------------
	 * Shared schema fragments (private helpers, not WP_MCP_Ability_Schema
	 * — these are specific to this file's lifecycle-result shape, not
	 * reused elsewhere).
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

	private static function delete_result_output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Posts
	 * ---------------------------------------------------------------- */

	private static function register_unpublish_post() {
		wp_register_ability( 'wp-mcp/unpublish-post', array(
			'label'       => __( 'Unpublish Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Move a published post back to draft. Idempotent: if already draft, returns success without modification.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post to unpublish.', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'unpublish_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_schedule_post() {
		wp_register_ability( 'wp-mcp/schedule-post', array(
			'label'       => __( 'Schedule Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Schedule a post to publish automatically at a future date/time.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post to schedule.', 'minimum' => 1 ),
					'date'    => array( 'type' => 'string', 'description' => 'Future publish date/time (ISO 8601 recommended).' ),
				),
				'required'             => array( 'post_id', 'date' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'schedule_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts() && WP_MCP_Permissions::can_publish_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_change_post_status() {
		wp_register_ability( 'wp-mcp/change-post-status', array(
			'label'       => __( 'Change Post Status', 'wordpress-mcp-abilities' ),
			'description' => __( 'Transition a post between draft and pending review. Does not publish, schedule, trash, or unpublish -- use the dedicated abilities for those.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post.', 'minimum' => 1 ),
					'status'  => array( 'type' => 'string', 'description' => 'Target status.', 'enum' => array( 'draft', 'pending' ) ),
				),
				'required'             => array( 'post_id', 'status' ),
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
			'execute_callback'    => array( 'WP_MCP_Posts', 'change_post_status' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_trash_post() {
		wp_register_ability( 'wp-mcp/trash-post', array(
			'label'       => __( 'Trash Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Move a post to trash. Idempotent: if already trashed, returns success without modification. Reversible via restore-post.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post to trash.', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'trash_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_restore_post() {
		wp_register_ability( 'wp-mcp/restore-post', array(
			'label'       => __( 'Restore Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Restore a trashed post. Idempotent: if not currently trashed, returns success without modification.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post to restore.', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'restore_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );
	}

	private static function register_delete_post_permanently() {
		wp_register_ability( 'wp-mcp/delete-post-permanently', array(
			'label'       => __( 'Delete Post Permanently', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete a post, bypassing trash. Irreversible. Separate from trash-post, which is recoverable.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post to permanently delete.', 'minimum' => 1 ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::delete_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Posts', 'delete_post_permanently' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_delete_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Pages
	 * ---------------------------------------------------------------- */

	private static function register_publish_page() {
		wp_register_ability( 'wp-mcp/publish-page', array(
			'label'       => __( 'Publish Page', 'wordpress-mcp-abilities' ),
			'description' => __( 'Publish a draft page. Idempotent: if already published, returns success without modification.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id' => array( 'type' => 'integer', 'description' => 'ID of the page to publish.', 'minimum' => 1 ),
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'publish_page' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages() && WP_MCP_Permissions::can_publish_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_unpublish_page() {
		wp_register_ability( 'wp-mcp/unpublish-page', array(
			'label'       => __( 'Unpublish Page', 'wordpress-mcp-abilities' ),
			'description' => __( 'Move a published page back to draft. Idempotent: if already draft, returns success without modification.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id' => array( 'type' => 'integer', 'description' => 'ID of the page to unpublish.', 'minimum' => 1 ),
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'unpublish_page' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_schedule_page() {
		wp_register_ability( 'wp-mcp/schedule-page', array(
			'label'       => __( 'Schedule Page', 'wordpress-mcp-abilities' ),
			'description' => __( 'Schedule a page to publish automatically at a future date/time.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id' => array( 'type' => 'integer', 'description' => 'ID of the page to schedule.', 'minimum' => 1 ),
					'date'    => array( 'type' => 'string', 'description' => 'Future publish date/time (ISO 8601 recommended).' ),
				),
				'required'             => array( 'page_id', 'date' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'schedule_page' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages() && WP_MCP_Permissions::can_publish_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_trash_page() {
		wp_register_ability( 'wp-mcp/trash-page', array(
			'label'       => __( 'Trash Page', 'wordpress-mcp-abilities' ),
			'description' => __( 'Move a page to trash. Idempotent: if already trashed, returns success without modification. Reversible via restore-page.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id' => array( 'type' => 'integer', 'description' => 'ID of the page to trash.', 'minimum' => 1 ),
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'trash_page' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	private static function register_restore_page() {
		wp_register_ability( 'wp-mcp/restore-page', array(
			'label'       => __( 'Restore Page', 'wordpress-mcp-abilities' ),
			'description' => __( 'Restore a trashed page. Idempotent: if not currently trashed, returns success without modification.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id' => array( 'type' => 'integer', 'description' => 'ID of the page to restore.', 'minimum' => 1 ),
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::lifecycle_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'restore_page' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( false, true ),
		) );
	}

	private static function register_delete_page_permanently() {
		wp_register_ability( 'wp-mcp/delete-page-permanently', array(
			'label'       => __( 'Delete Page Permanently', 'wordpress-mcp-abilities' ),
			'description' => __( 'Permanently delete a page, bypassing trash. Irreversible. Separate from trash-page, which is recoverable.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'page_id' => array( 'type' => 'integer', 'description' => 'ID of the page to permanently delete.', 'minimum' => 1 ),
				),
				'required'             => array( 'page_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::delete_result_output_schema(),
			'execute_callback'    => array( 'WP_MCP_Pages', 'delete_page_permanently' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_delete_pages();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}
}
