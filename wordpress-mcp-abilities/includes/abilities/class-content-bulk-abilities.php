<?php
/**
 * WordPress MCP Abilities — Content duplication and bulk domain.
 *
 * Registers duplicate-post and bulk-trash-posts. Deliberately narrow:
 * no bulk-publish/restore and no bulk abilities for pages in #3 --
 * this is the conservative starting surface, extend in a later issue
 * if needed.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Content_Bulk_Abilities
 */
class WP_MCP_Content_Bulk_Abilities {

	/**
	 * Register all duplication/bulk abilities.
	 */
	public static function register() {
		self::register_duplicate_post();
		self::register_bulk_trash_posts();
	}

	private static function register_duplicate_post() {
		wp_register_ability( 'wp-mcp/duplicate-post', array(
			'label'       => __( 'Duplicate Post', 'wordpress-mcp-abilities' ),
			'description' => __( 'Duplicate a post as a new draft. Copies title, content, excerpt, categories, tags, and featured image. The duplicate is always authored by the current user, never the source post\'s author.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post to duplicate.', 'minimum' => 1 ),
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
			'execute_callback'    => array( 'WP_MCP_Posts', 'duplicate_post' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( false, false ), // Not destructive, not idempotent.
		) );
	}

	private static function register_bulk_trash_posts() {
		wp_register_ability( 'wp-mcp/bulk-trash-posts', array(
			'label'       => __( 'Bulk Trash Posts', 'wordpress-mcp-abilities' ),
			'description' => __( 'Trash up to 20 posts in a single request. Never aborts for one failure -- every ID is attempted and reported independently in the results array.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-content',
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_ids' => array(
						'type'        => 'array',
						'description' => 'Post IDs to trash (max 20).',
						'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'minItems'    => 1,
						'maxItems'    => 20,
					),
				),
				'required'             => array( 'post_ids' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'results' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'         => array( 'type' => 'integer' ),
								'success'    => array( 'type' => 'boolean' ),
								'error_code' => array( 'type' => 'string' ),
							),
						),
					),
					'trashed' => array( 'type' => 'integer' ),
					'failed'  => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( 'WP_MCP_Posts', 'bulk_trash_posts' ),
			'permission_callback' => function () {
				return WP_MCP_Permissions::can_edit_posts();
			},
			'meta' => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}
}
