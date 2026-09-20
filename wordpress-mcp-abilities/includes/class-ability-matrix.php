<?php
/**
 * WordPress MCP Abilities — Ability permission matrix.
 *
 * Single source of truth for ability -> category -> capability /
 * meta-capability -> destructive -> idempotent, for documentation
 * (README "Permission Matrix") and for a consistency test that checks
 * every entry corresponds to a registered ability.
 *
 * This is documentation + verifiable data — it does not drive the
 * actual permission_callback wiring, which stays as explicit closures
 * per ability (see includes/abilities/*.php).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Ability_Matrix
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Ability_Matrix {

	/**
	 * Get the permission matrix for every ability this plugin documents.
	 *
	 * Core-domain rows are the literal table in `core_rows()`. Integration
	 * rows (issue #14) come from the adapters themselves, so adding an
	 * integration never means editing this file; each of those rows carries
	 * an extra `integration` key naming its adapter, because the ability only
	 * exists when that integration is available on the site.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get() {
		if ( ! class_exists( 'WP_MCP_Integrations' ) ) {
			require_once __DIR__ . '/class-integration-registry.php';
		}

		return array_merge( self::core_rows(), WP_MCP_Integrations::matrix_rows() );
	}

	/**
	 * The hand-maintained rows of the core ability domains.
	 *
	 * @return array<string,array{category:string,capability:string,meta_capability:string,destructive:bool,idempotent:bool}>
	 */
	private static function core_rows() {
		return array(
			'wp-mcp/list-posts'         => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/get-post'           => array(
				'category'        => 'wp-mcp-content',
				'capability'      => '',
				'meta_capability' => 'read_post (post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/create-post'        => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => false,
			),
			'wp-mcp/update-post'        => array(
				'category'        => 'wp-mcp-content',
				'capability'      => '',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/publish-post'       => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts, publish_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/list-categories'    => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/list-tags'          => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/list-taxonomies'    => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/get-taxonomy'       => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/list-terms'         => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/get-term'           => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/create-term'        => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'manage_terms',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => false,
			),
			'wp-mcp/update-term'        => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'edit_terms',
				'meta_capability' => '',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/delete-term'        => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'delete_terms',
				'meta_capability' => '',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/assign-terms'       => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'assign_terms',
				'meta_capability' => 'edit_post (object)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/remove-terms'       => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'assign_terms',
				'meta_capability' => 'edit_post (object)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/bulk-assign-terms'  => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'assign_terms',
				'meta_capability' => 'edit_post (per object)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/bulk-remove-terms'  => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'assign_terms',
				'meta_capability' => 'edit_post (per object)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/get-term-meta'      => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/update-term-meta'   => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'edit_terms',
				'meta_capability' => 'edit_term_meta',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/delete-term-meta'   => array(
				'category'        => 'wp-mcp-taxonomies',
				'capability'      => 'edit_terms',
				'meta_capability' => 'edit_term_meta',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/list-media'         => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/get-media'           => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'read',
				'meta_capability' => 'read_post (attachment)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/upload-media'        => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'upload_files',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => false,
			),
			'wp-mcp/upload-media-from-url' => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'upload_files',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => false,
			),
			'wp-mcp/update-media'        => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (attachment)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/replace-media'       => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts, upload_files',
				'meta_capability' => 'edit_post (attachment)',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/attach-media'        => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (attachment), edit_post (parent)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/detach-media'        => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (attachment), edit_post (parent)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/set-featured-image'  => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post/page)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/remove-featured-image' => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post/page)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/regenerate-media-metadata' => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (attachment)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/trash-media'         => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts',
				'meta_capability' => 'delete_post (attachment)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/restore-media'       => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts',
				'meta_capability' => 'delete_post (attachment)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/delete-media-permanently' => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'delete_posts',
				'meta_capability' => 'delete_post (attachment)',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/bulk-trash-media'    => array(
				'category'        => 'wp-mcp-media',
				'capability'      => 'edit_posts',
				'meta_capability' => 'delete_post (per item)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/list-pages'         => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'read',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/get-page'           => array(
				'category'        => 'wp-mcp-content',
				'capability'      => '',
				'meta_capability' => 'read_post (page)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/create-page'        => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages',
				'meta_capability' => '',
				'destructive'     => false,
				'idempotent'      => false,
			),
			'wp-mcp/update-page'        => array(
				'category'        => 'wp-mcp-content',
				'capability'      => '',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/unpublish-post'          => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/schedule-post'           => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts, publish_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/change-post-status'      => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/trash-post'              => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'delete_post (post)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/restore-post'            => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'delete_post (post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/delete-post-permanently' => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'delete_posts',
				'meta_capability' => 'delete_post (post)',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/publish-page'            => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages, publish_pages',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/unpublish-page'          => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/schedule-page'           => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages, publish_pages',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/trash-page'              => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages',
				'meta_capability' => 'delete_post (page)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/restore-page'            => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages',
				'meta_capability' => 'delete_post (page)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/delete-page-permanently' => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'delete_pages',
				'meta_capability' => 'delete_post (page)',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/change-post-author'      => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts, edit_others_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/update-post-slug'        => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/stick-post'              => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts, edit_others_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/unstick-post'            => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts, edit_others_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/set-post-password'       => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/change-page-author'      => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages, edit_others_pages',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/update-page-slug'        => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/update-page-attributes'  => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/list-post-revisions'     => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/get-post-revision'       => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/restore-post-revision'   => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/get-post-autosave'       => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/list-page-revisions'     => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/get-page-revision'       => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/restore-page-revision'   => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_pages',
				'meta_capability' => 'edit_post (page)',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/duplicate-post'          => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'read_post (source)',
				'destructive'     => false,
				'idempotent'      => false,
			),
			'wp-mcp/bulk-trash-posts'        => array(
				'category'        => 'wp-mcp-content',
				'capability'      => 'edit_posts',
				'meta_capability' => 'delete_post (per item)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/list-comments'           => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'read',
				'meta_capability' => 'read_post (comment post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/get-comment'             => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'read',
				'meta_capability' => 'read_post (comment post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/create-comment'          => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'edit_posts',
				'meta_capability' => 'read_post (comment post)',
				'destructive'     => false,
				'idempotent'      => false,
			),
			'wp-mcp/reply-comment'           => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'edit_posts',
				'meta_capability' => 'read_post (comment post)',
				'destructive'     => false,
				'idempotent'      => false,
			),
			'wp-mcp/update-comment'          => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_comment',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/approve-comment'         => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'moderate_comments',
				'meta_capability' => 'edit_comment',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/unapprove-comment'       => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'moderate_comments',
				'meta_capability' => 'edit_comment',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/mark-comment-spam'       => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'moderate_comments',
				'meta_capability' => 'edit_comment',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/unspam-comment'          => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'moderate_comments',
				'meta_capability' => 'edit_comment',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/trash-comment'           => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'moderate_comments',
				'meta_capability' => 'edit_comment',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/restore-comment'         => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'moderate_comments',
				'meta_capability' => 'edit_comment',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/delete-comment-permanently' => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'moderate_comments',
				'meta_capability' => 'edit_comment',
				'destructive'     => true,
				'idempotent'      => false,
			),
			'wp-mcp/bulk-moderate-comments' => array(
				'category'        => 'wp-mcp-comments',
				'capability'      => 'moderate_comments',
				'meta_capability' => 'edit_comment (per item)',
				'destructive'     => true,
				'idempotent'      => true,
			),
			'wp-mcp/list-users' => array( 'category' => 'wp-mcp-users', 'capability' => 'list_users', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-user' => array( 'category' => 'wp-mcp-users', 'capability' => 'read', 'meta_capability' => 'edit_user (target)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-current-user' => array( 'category' => 'wp-mcp-users', 'capability' => 'read', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-user' => array( 'category' => 'wp-mcp-users', 'capability' => 'create_users', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-user' => array( 'category' => 'wp-mcp-users', 'capability' => 'read', 'meta_capability' => 'edit_user (target)', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/set-user-password' => array( 'category' => 'wp-mcp-users', 'capability' => 'read', 'meta_capability' => 'edit_user (target)', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/delete-user' => array( 'category' => 'wp-mcp-users', 'capability' => 'delete_users', 'meta_capability' => 'delete_user (target)', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/change-user-role' => array( 'category' => 'wp-mcp-users', 'capability' => 'promote_users', 'meta_capability' => 'promote_user (target)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/add-user-role' => array( 'category' => 'wp-mcp-users', 'capability' => 'promote_users', 'meta_capability' => 'promote_user (target)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/remove-user-role' => array( 'category' => 'wp-mcp-users', 'capability' => 'promote_users', 'meta_capability' => 'promote_user (target)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/list-user-capabilities' => array( 'category' => 'wp-mcp-users', 'capability' => 'read', 'meta_capability' => 'edit_user (target)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-roles' => array( 'category' => 'wp-mcp-users', 'capability' => 'list_users', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-role' => array( 'category' => 'wp-mcp-users', 'capability' => 'list_users', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-role' => array( 'category' => 'wp-mcp-users', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-role-capabilities' => array( 'category' => 'wp-mcp-users', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/add-role-capability' => array( 'category' => 'wp-mcp-users', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/remove-role-capability' => array( 'category' => 'wp-mcp-users', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-role' => array( 'category' => 'wp-mcp-users', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/list-application-passwords' => array( 'category' => 'wp-mcp-users', 'capability' => 'read', 'meta_capability' => 'edit_user (target)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-application-password' => array( 'category' => 'wp-mcp-users', 'capability' => 'read', 'meta_capability' => 'edit_user (target)', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/revoke-application-password' => array( 'category' => 'wp-mcp-users', 'capability' => 'read', 'meta_capability' => 'edit_user (target)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/revoke-all-application-passwords' => array( 'category' => 'wp-mcp-users', 'capability' => 'read', 'meta_capability' => 'edit_user (target)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/list-nav-menus' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-nav-menu' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-menu-locations' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-nav-menu' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-nav-menu' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-nav-menu' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/assign-menu-location' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => 'theme_mod nav_menu_locations', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/add-menu-item' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-menu-item' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => 'nav_menu_item', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/remove-menu-item' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => 'nav_menu_item', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/reorder-menu-items' => array( 'category' => 'wp-mcp-navigation', 'capability' => 'edit_theme_options', 'meta_capability' => 'nav_menu_item', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/list-templates' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-template' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'template', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-template' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'template', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-template' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'template', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/list-template-parts' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-template-part' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'template_part', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-template-part' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'template_part', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-template-part' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'template_part', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/list-patterns' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-pattern' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_block', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-synced-pattern' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_block', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-synced-pattern' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_block', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-synced-pattern' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_block', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/list-navigation-blocks' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-navigation-block' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_navigation', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-navigation-block' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_navigation', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-navigation-block' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_navigation', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-navigation-block' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_navigation', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/get-theme-context' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-global-styles' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_global_styles', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-global-styles' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => 'wp_global_styles', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/list-widget-areas' => array( 'category' => 'wp-mcp-site-editor', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),

			'wp-mcp/list-plugins' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'activate_plugins', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-plugin' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'activate_plugins', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/activate-plugin' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'activate_plugins', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/deactivate-plugin' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'activate_plugins', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/install-plugin-from-repo' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'install_plugins', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/install-plugin-from-url' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'install_plugins', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-plugin' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'update_plugins', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/update-plugins' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'update_plugins', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/update-all-plugins' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'update_plugins', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/set-plugin-auto-update' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'update_plugins (+ manage_network_plugins on multisite)', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-plugin' => array( 'category' => 'wp-mcp-plugins', 'capability' => 'delete_plugins', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),

			'wp-mcp/list-themes' => array( 'category' => 'wp-mcp-themes', 'capability' => 'switch_themes', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-theme' => array( 'category' => 'wp-mcp-themes', 'capability' => 'switch_themes', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/switch-theme' => array( 'category' => 'wp-mcp-themes', 'capability' => 'switch_themes', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/install-theme-from-repo' => array( 'category' => 'wp-mcp-themes', 'capability' => 'install_themes', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/install-theme-from-url' => array( 'category' => 'wp-mcp-themes', 'capability' => 'install_themes', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-theme' => array( 'category' => 'wp-mcp-themes', 'capability' => 'update_themes', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/update-themes' => array( 'category' => 'wp-mcp-themes', 'capability' => 'update_themes', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/update-all-themes' => array( 'category' => 'wp-mcp-themes', 'capability' => 'update_themes', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/set-theme-auto-update' => array( 'category' => 'wp-mcp-themes', 'capability' => 'update_themes (+ manage_network_themes on multisite)', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-theme' => array( 'category' => 'wp-mcp-themes', 'capability' => 'delete_themes', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),

			'wp-mcp/get-core-update-status' => array( 'category' => 'wp-mcp-system', 'capability' => 'update_core', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-core' => array( 'category' => 'wp-mcp-system', 'capability' => 'update_core', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/list-available-updates' => array( 'category' => 'wp-mcp-system', 'capability' => 'update_core, update_plugins, update_themes, or update_languages', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-translations' => array( 'category' => 'wp-mcp-system', 'capability' => 'update_languages', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),

			'wp-mcp/get-general-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-general-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/get-writing-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-writing-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/get-reading-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-reading-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/get-discussion-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-discussion-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/get-media-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-media-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/get-permalink-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-permalink-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/get-privacy-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_privacy_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-privacy-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_privacy_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/flush-rewrite-rules' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/list-settings-fields' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),

			/*
			 * Issue #11 — custom post types and registered post metadata.
			 *
			 * The `capability` column is intentionally not a literal WordPress
			 * capability here: every gate is resolved from the *target post
			 * type's* own registration (`cap->create_posts`,
			 * `cap->publish_posts`, ...), so the concrete capability string
			 * differs per registered type. A literal `edit_posts` in this
			 * column would be documentation of a bug, not of the code.
			 */
			'wp-mcp/list-post-types' => array( 'category' => 'wp-mcp-content', 'capability' => 'read', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-post-type' => array( 'category' => 'wp-mcp-content', 'capability' => 'read', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-post-type-meta-fields' => array( 'category' => 'wp-mcp-content', 'capability' => 'read', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-custom-posts' => array( 'category' => 'wp-mcp-content', 'capability' => 'read, post type cap->edit_posts (non-public statuses)', 'meta_capability' => 'read_post (per row)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-custom-post' => array( 'category' => 'wp-mcp-content', 'capability' => 'read', 'meta_capability' => 'read_post (post type)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-custom-post' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->create_posts', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-custom-post' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->edit_posts', 'meta_capability' => 'edit_post (post type)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/publish-custom-post' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->publish_posts', 'meta_capability' => 'edit_post (post type)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/unpublish-custom-post' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->edit_posts', 'meta_capability' => 'edit_post (post type)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/trash-custom-post' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->delete_posts', 'meta_capability' => 'delete_post (post type)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/restore-custom-post' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->delete_posts', 'meta_capability' => 'delete_post (post type)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-custom-post-permanently' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->delete_posts', 'meta_capability' => 'delete_post (post type)', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/list-custom-post-revisions' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->edit_posts, revisions support', 'meta_capability' => 'edit_post (post type)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-custom-post-revision' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->edit_posts, revisions support', 'meta_capability' => 'edit_post (post type)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/restore-custom-post-revision' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->edit_posts, revisions support', 'meta_capability' => 'edit_post (post type)', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/set-custom-post-featured-image' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->edit_posts, thumbnail support', 'meta_capability' => 'edit_post (post type), read_post (attachment)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/remove-custom-post-featured-image' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->edit_posts, thumbnail support', 'meta_capability' => 'edit_post (post type)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/get-custom-post-meta' => array( 'category' => 'wp-mcp-content', 'capability' => 'read', 'meta_capability' => 'read_post (post type)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-custom-post-meta' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->edit_posts', 'meta_capability' => 'edit_post, edit_post_meta', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-custom-post-meta' => array( 'category' => 'wp-mcp-content', 'capability' => 'post type cap->edit_posts', 'meta_capability' => 'edit_post, delete_post_meta', 'destructive' => true, 'idempotent' => true ),

			/*
			 * Issue #12 — system inspection, cron, cache/maintenance,
			 * import/export and personal data privacy requests.
			 *
			 * Note the deliberately narrow capabilities: Site Health uses
			 * WordPress own `view_site_health_checks`, maintenance mode uses
			 * `update_core` (the marker is network-wide, and core restricts
			 * `update_core` to Super Admins on multisite), content export and
			 * import use the native `export` / `import` capabilities, and the
			 * privacy abilities use the native
			 * `export_others_personal_data` / `erase_others_personal_data`
			 * pair rather than a blanket `manage_options`.
			 */
			'wp-mcp/get-site-info' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-environment-info' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-image-sizes' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-rewrite-state' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-site-size' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-site-health-tests' => array( 'category' => 'wp-mcp-system', 'capability' => 'view_site_health_checks', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/run-site-health-tests' => array( 'category' => 'wp-mcp-system', 'capability' => 'view_site_health_checks', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),

			'wp-mcp/get-cron-status' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-cron-events' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-cron-event' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/schedule-cron-event' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => 'cron schedule policy (core maintenance hook with no args, or already-queued hook+args, + denylist)', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/unschedule-cron-event' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => 'cron hook policy (denylist)', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/run-cron-event' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => 'cron hook policy (queued + has_action + denylist)', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/run-due-cron-events' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => 'cron hook policy (queued + has_action + denylist)', 'destructive' => true, 'idempotent' => false ),

			'wp-mcp/flush-object-cache' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/clear-expired-transients' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/clear-update-caches' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/get-maintenance-mode' => array( 'category' => 'wp-mcp-system', 'capability' => 'manage_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/set-maintenance-mode' => array( 'category' => 'wp-mcp-system', 'capability' => 'update_core', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),

			'wp-mcp/export-content' => array( 'category' => 'wp-mcp-system', 'capability' => 'export', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/import-content' => array( 'category' => 'wp-mcp-system', 'capability' => 'import', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/export-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options, per-group capability', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/import-settings' => array( 'category' => 'wp-mcp-settings', 'capability' => 'manage_options, per-group capability', 'meta_capability' => '', 'destructive' => true, 'idempotent' => true ),

			'wp-mcp/list-privacy-requests' => array( 'category' => 'wp-mcp-privacy', 'capability' => 'export_others_personal_data or erase_others_personal_data', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-privacy-request' => array( 'category' => 'wp-mcp-privacy', 'capability' => 'export_others_personal_data or erase_others_personal_data', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-privacy-export-request' => array( 'category' => 'wp-mcp-privacy', 'capability' => 'export_others_personal_data', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/create-privacy-erasure-request' => array( 'category' => 'wp-mcp-privacy', 'capability' => 'erase_others_personal_data', 'meta_capability' => '', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/resend-privacy-request-email' => array( 'category' => 'wp-mcp-privacy', 'capability' => 'export_others_personal_data or erase_others_personal_data', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/process-privacy-export-request' => array( 'category' => 'wp-mcp-privacy', 'capability' => 'export_others_personal_data', 'meta_capability' => 'request-confirmed status', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/process-privacy-erasure-request' => array( 'category' => 'wp-mcp-privacy', 'capability' => 'erase_others_personal_data', 'meta_capability' => 'request-confirmed status', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/delete-privacy-request' => array( 'category' => 'wp-mcp-privacy', 'capability' => 'export_others_personal_data or erase_others_personal_data', 'meta_capability' => '', 'destructive' => true, 'idempotent' => false ),

			/*
			 * Issue #13 — multisite network administration.
			 *
			 * Every row of this block carries `is_multisite()` in the
			 * meta-capability column, because that is a real gate and not a
			 * remark: off a network the permission callback is false and the
			 * execute callback answers `wp_mcp_network_unsupported` (501).
			 * The one exception is `get-network-info`, which is the ability
			 * an agent uses to discover which of the two worlds it is in.
			 *
			 * The capabilities are the concrete network capabilities
			 * WordPress itself checks on each Network Admin screen —
			 * `manage_sites`, `manage_network_users`,
			 * `manage_network_plugins`, `manage_network_themes`,
			 * `manage_network_options`, `upgrade_network`, plus
			 * `create_sites` / `delete_sites` / `create_users` /
			 * `delete_users` where core requires them — never a blanket
			 * `manage_options`, and never "authenticated, therefore Super
			 * Admin".
			 */
			'wp-mcp/get-network-info' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network (multisite) or manage_options (single site)', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-network-update-status' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/upgrade-network-sites' => array( 'category' => 'wp-mcp-network', 'capability' => 'upgrade_network', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),

			'wp-mcp/list-network-sites' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites, create_sites', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/update-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites', 'meta_capability' => 'is_multisite()', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/archive-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites', 'meta_capability' => 'is_multisite(), not the main or current site', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/unarchive-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites', 'meta_capability' => 'is_multisite()', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/activate-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites', 'meta_capability' => 'is_multisite()', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/deactivate-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites', 'meta_capability' => 'is_multisite(), not the main or current site', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/mark-network-site-spam' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites', 'meta_capability' => 'is_multisite(), not the main or current site', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/unmark-network-site-spam' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites', 'meta_capability' => 'is_multisite()', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/delete-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_sites, delete_sites', 'meta_capability' => 'is_multisite(), not the main or current site', 'destructive' => true, 'idempotent' => false ),

			'wp-mcp/list-network-users' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_users', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-network-user' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_users', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-network-user-sites' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_users', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/create-network-user' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_users, create_users', 'meta_capability' => 'is_multisite(), network signup policy', 'destructive' => false, 'idempotent' => false ),
			'wp-mcp/delete-network-user' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_users, delete_users', 'meta_capability' => 'is_multisite(), not self, not a Super Admin', 'destructive' => true, 'idempotent' => false ),
			'wp-mcp/add-user-to-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_users', 'meta_capability' => 'is_multisite(), role editable on the target site', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/remove-user-from-network-site' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_users', 'meta_capability' => 'is_multisite(), not self', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/set-network-site-user-role' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_users', 'meta_capability' => 'is_multisite(), role editable on the target site', 'destructive' => true, 'idempotent' => true ),

			'wp-mcp/network-activate-plugin' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_plugins', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/network-deactivate-plugin' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_plugins', 'meta_capability' => 'is_multisite()', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/list-network-themes' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_themes', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/network-enable-theme' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_themes', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/network-disable-theme' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_themes', 'meta_capability' => 'is_multisite(), not the main site theme', 'destructive' => true, 'idempotent' => true ),

			'wp-mcp/get-network-settings' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_options', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/update-network-settings' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_options', 'meta_capability' => 'is_multisite()', 'destructive' => true, 'idempotent' => true ),
			'wp-mcp/list-network-settings-fields' => array( 'category' => 'wp-mcp-network', 'capability' => 'manage_network_options', 'meta_capability' => 'is_multisite()', 'destructive' => false, 'idempotent' => true ),

			'wp-mcp/list-integrations' => array( 'category' => 'wp-mcp-extensibility', 'capability' => 'activate_plugins', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-integration' => array( 'category' => 'wp-mcp-extensibility', 'capability' => 'activate_plugins', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),

			'wp-mcp/global-search' => array( 'category' => 'wp-mcp-discovery', 'capability' => 'read', 'meta_capability' => 'read_post (every post/media hit), list_users (user hits), assign_terms or a public taxonomy (term hits)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-post-statuses' => array( 'category' => 'wp-mcp-discovery', 'capability' => 'read', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-mime-types' => array( 'category' => 'wp-mcp-discovery', 'capability' => 'upload_files', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-block-types' => array( 'category' => 'wp-mcp-discovery', 'capability' => 'edit_posts', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-block-type' => array( 'category' => 'wp-mcp-discovery', 'capability' => 'edit_posts', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-pattern-categories' => array( 'category' => 'wp-mcp-discovery', 'capability' => 'edit_posts', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-template-types' => array( 'category' => 'wp-mcp-discovery', 'capability' => 'edit_theme_options', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/get-current-user-capabilities' => array( 'category' => 'wp-mcp-discovery', 'capability' => '', 'meta_capability' => 'authenticated user (self only)', 'destructive' => false, 'idempotent' => true ),
			'wp-mcp/list-feature-support' => array( 'category' => 'wp-mcp-discovery', 'capability' => 'edit_posts', 'meta_capability' => '', 'destructive' => false, 'idempotent' => true ),
		);
	}
}
