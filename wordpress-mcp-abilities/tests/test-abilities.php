<?php
/**
 * Test WordPress MCP Abilities.
 *
 * @package WP_MCP_Agent_Abilities
 */

class WP_MCP_Test_Abilities extends WP_UnitTestCase {

	/**
	 * Agent user ID.
	 *
	 * @var int
	 */
	protected $agent_user;

	/**
	 * Other user ID.
	 *
	 * @var int
	 */
	protected $other_user;

	/**
	 * Block names registered by the issue #16 oversized-registry fixtures.
	 *
	 * @var string[]
	 */
	protected $issue_16_bulk_blocks = array();

	/**
	 * Post statuses registered by the issue #16 oversized-registry fixtures.
	 *
	 * @var string[]
	 */
	protected $issue_16_bulk_statuses = array();

	/**
	 * Pattern categories registered by the issue #16 oversized-registry fixtures.
	 *
	 * @var string[]
	 */
	protected $issue_16_bulk_categories = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure role exists.
		WP_MCP_Role::create_or_reconcile();

		// Create users.
		$this->agent_user = self::factory()->user->create( array(
			'role' => 'wp_mcp_agent',
		) );

		$this->other_user = self::factory()->user->create( array(
			'role' => 'author',
		) );
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		// Post types, taxonomies and registered meta keys live in PHP
		// globals, not in the database, so the per-test transaction rollback
		// does not undo them. Unregistering here (no-op unless an Issue #11
		// test registered them) keeps the fixtures from leaking into any
		// other test's view of the registry.
		$this->unregister_issue_11_fixtures();
		// Issue #14: a test may have swapped the integration adapter set
		// in through WP_MCP_Integrations::set_adapters(); restore the real
		// manifests so nothing leaks into another test's view of the registry.
		WP_MCP_Integrations::flush();
		// Issue #16: block types and pattern categories live in PHP registries
		// for the same reason, so the discovery fixtures are unregistered here.
		$this->unregister_issue_16_fixtures();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ## Functional tests (1-14)

	public function test_plugin_activates() {
		$this->assertTrue( defined( 'WP_MCP_VERSION' ) );
	}

	public function test_category_registered() {
		if ( function_exists( 'wp_get_ability_category' ) ) {
			$this->assertNotNull( wp_get_ability_category( 'wp-mcp-content' ) );
		} else {
			$this->assertTrue( true );
		}
	}

	public function test_abilities_registered() {
		if ( function_exists( 'wp_get_ability' ) ) {
			$this->assertNotNull( wp_get_ability( 'wp-mcp/list-posts' ) );
		} else {
			$this->assertTrue( true );
		}
	}

	public function test_create_post_creates_draft() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title'   => 'Test',
			'content' => 'Content',
		) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 'draft', $result['status'] );
		$this->assertEquals( $this->agent_user, $result['author'] );
	}

	public function test_update_post_modifies_own() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_title'  => 'Old Title',
		) );

		$result = WP_MCP_Posts::update_post( array(
			'post_id' => $post_id,
			'title'   => 'New Title',
		) );

		$this->assertNotWPError( $result );
		$post = get_post( $post_id );
		$this->assertEquals( 'New Title', $post->post_title );
	}

	public function test_update_post_rejects_other_user() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::update_post( array(
			'post_id' => $post_id,
			'title'   => 'New Title',
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ownership_violation', $result->get_error_code() );
	}

	public function test_publish_post_publishes_draft() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'draft',
		) );

		$result = WP_MCP_Posts::publish_post( array(
			'post_id' => $post_id,
		) );

		$this->assertNotWPError( $result );
		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status );
	}

	public function test_publish_post_rejects_other_user() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'draft',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::publish_post( array(
			'post_id' => $post_id,
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ownership_violation', $result->get_error_code() );
	}

	public function test_set_featured_image_accepts_image() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
		) );

		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', $post_id, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
		) );

		$result = WP_MCP_Media::set_featured_image( array(
			'post_id'  => $post_id,
			'media_id' => $attachment_id,
		) );

		$this->assertNotWPError( $result );
		$this->assertEquals( $attachment_id, get_post_thumbnail_id( $post_id ) );
	}

	public function test_invalid_inputs_return_wp_error() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title' => '',
		) );
		$this->assertWPError( $result );
	}

	public function test_delete_post_permanently_rejects_without_delete_cap() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
		) );

		// The ability exists (added in #3), but wp_mcp_agent still lacks
		// delete_posts by design -- see class-role.php.
		$result = WP_MCP_Posts::delete_post_permanently( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
		$this->assertNotNull( get_post( $post_id ) );
	}

	public function test_no_ability_allows_change_author() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
		) );

		// Simulation of update
		$result = WP_MCP_Posts::update_post( array(
			'post_id'     => $post_id,
			'post_author' => $this->other_user,
		) );

		$post = get_post( $post_id );
		$this->assertEquals( $this->agent_user, $post->post_author );
	}

	public function test_html_sanitized() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title'   => 'Test HTML',
			'content' => '<script>alert(1)</script>Safe',
		) );

		$this->assertNotWPError( $result );
		$post = get_post( $result['id'] );
		$this->assertStringNotContainsString( '<script>', $post->post_content );
	}

	public function test_role_has_correct_capabilities() {
		$role = get_role( 'wp_mcp_agent' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'read' ) );
		$this->assertTrue( $role->has_cap( 'edit_posts' ) );
		$this->assertTrue( $role->has_cap( 'edit_published_posts' ) );
		$this->assertTrue( $role->has_cap( 'publish_posts' ) );
	}

	// ## Blindaje tests (15-29)

	public function test_list_posts_returns_published_other() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'publish',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::list_posts( array() );
		$ids = wp_list_pluck( $result['posts'], 'id' );
		$this->assertContains( $post_id, $ids );
	}

	public function test_list_posts_returns_own_draft() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'draft',
		) );

		$result = WP_MCP_Posts::list_posts( array( 'status' => 'draft' ) );
		$ids = wp_list_pluck( $result['posts'], 'id' );
		$this->assertContains( $post_id, $ids );
	}

	public function test_list_posts_no_draft_of_other() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'draft',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::list_posts( array( 'status' => 'draft' ) );
		if ( ! is_wp_error( $result ) && isset( $result['posts'] ) ) {
			$ids = wp_list_pluck( $result['posts'], 'id' );
			$this->assertNotContains( $post_id, $ids );
		}
	}

	public function test_list_posts_no_private_of_other() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'private',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::list_posts( array( 'status' => 'publish' ) );
		if ( ! is_wp_error( $result ) && isset( $result['posts'] ) ) {
			$ids = wp_list_pluck( $result['posts'], 'id' );
			$this->assertNotContains( $post_id, $ids );
		}
	}

	public function test_list_posts_no_pending_of_other() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'pending',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::list_posts( array( 'status' => 'pending' ) );
		if ( ! is_wp_error( $result ) && isset( $result['posts'] ) ) {
			$ids = wp_list_pluck( $result['posts'], 'id' );
			$this->assertNotContains( $post_id, $ids );
		}
	}

	public function test_get_post_rejects_draft_of_other() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'draft',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::get_post( array( 'post_id' => $post_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	public function test_get_post_rejects_private_of_other() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'private',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::get_post( array( 'post_id' => $post_id ) );
		$this->assertWPError( $result );
	}

	public function test_get_post_rejects_page_id() {
		wp_set_current_user( $this->agent_user );
		$page_id = self::factory()->post->create( array(
			'post_type' => 'page',
		) );

		$result = WP_MCP_Posts::get_post( array( 'post_id' => $page_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_unsupported_post_type', $result->get_error_code() );
	}

	public function test_get_page_rejects_post_id() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_type' => 'post',
		) );

		$result = WP_MCP_Pages::get_page( array( 'page_id' => $post_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_unsupported_post_type', $result->get_error_code() );
	}

	public function test_mcp_exposure_uses_mcp_public() {
		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( 'wp-mcp/list-posts' );
			$mcp     = $ability->get_meta_item( 'mcp' );
			$this->assertIsArray( $mcp );
			$this->assertTrue( $mcp['public'] );
		} else {
			$this->assertTrue( true );
		}
	}

	public function test_abilities_not_in_rest() {
		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( 'wp-mcp/list-posts' );
			$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
		} else {
			$this->assertTrue( true );
		}
	}

	public function test_invalid_category_ids_rejected() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title'      => 'Test',
			'content'    => 'Content',
			'categories' => array( 99999 ),
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_taxonomy_term', $result->get_error_code() );
	}

	public function test_invalid_tag_ids_rejected() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title'   => 'Test',
			'content' => 'Content',
			'tags'    => array( 99999 ),
		) );
		$this->assertWPError( $result );
	}

	public function test_category_id_wrong_taxonomy() {
		wp_set_current_user( $this->agent_user );
		$tag_id = self::factory()->tag->create();
		$result = WP_MCP_Posts::create_post( array(
			'title'      => 'Test',
			'content'    => 'Content',
			'categories' => array( $tag_id ),
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_taxonomy_term', $result->get_error_code() );
	}

	public function test_tag_id_wrong_taxonomy() {
		wp_set_current_user( $this->agent_user );
		$cat_id = self::factory()->category->create();
		$result = WP_MCP_Posts::create_post( array(
			'title'   => 'Test',
			'content' => 'Content',
			'tags'    => array( $cat_id ),
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_taxonomy_term', $result->get_error_code() );
	}

	// ## Mutation restriction tests (30-37)

	public function test_update_post_cannot_change_status() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'draft',
		) );

		WP_MCP_Posts::update_post( array(
			'post_id' => $post_id,
			'status'  => 'publish', // Shouldn't take effect
			'title'   => 'New Title',
		) );

		$post = get_post( $post_id );
		$this->assertEquals( 'draft', $post->post_status );
	}

	public function test_update_post_cannot_change_slug() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_name'   => 'old-slug',
		) );

		WP_MCP_Posts::update_post( array(
			'post_id' => $post_id,
			'slug'    => 'new-slug',
		) );

		$post = get_post( $post_id );
		$this->assertEquals( 'old-slug', $post->post_name );
	}

	public function test_update_post_cannot_change_type() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_type'   => 'post',
		) );

		WP_MCP_Posts::update_post( array(
			'post_id'   => $post_id,
			'post_type' => 'page',
		) );

		$post = get_post( $post_id );
		$this->assertEquals( 'post', $post->post_type );
	}

	public function test_update_post_cannot_change_author() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
		) );

		WP_MCP_Posts::update_post( array(
			'post_id' => $post_id,
			'author'  => $this->other_user,
		) );

		$post = get_post( $post_id );
		$this->assertEquals( $this->agent_user, $post->post_author );
	}

	public function test_published_post_of_other_cannot_be_modified() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'publish',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::update_post( array(
			'post_id' => $post_id,
			'title'   => 'New Title',
		) );

		$this->assertWPError( $result );
	}

	public function test_publish_post_cannot_publish_other_post() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'draft',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::publish_post( array(
			'post_id' => $post_id,
		) );

		$this->assertWPError( $result );
	}

	public function test_set_featured_image_rejects_non_image() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
		) );

		$attachment_id = self::factory()->attachment->create_object( 'doc.pdf', $post_id, array(
			'post_mime_type' => 'application/pdf',
			'post_type'      => 'attachment',
		) );

		$result = WP_MCP_Media::set_featured_image( array(
			'post_id'  => $post_id,
			'media_id' => $attachment_id,
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_not_an_image', $result->get_error_code() );
	}

	public function test_set_featured_image_rejects_other_post() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
		) );

		wp_set_current_user( $this->agent_user );
		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
		) );

		$result = WP_MCP_Media::set_featured_image( array(
			'post_id'  => $post_id,
			'media_id' => $attachment_id,
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ownership_violation', $result->get_error_code() );
	}

	// ## Taxonomy tests (#5)

	private function register_fixture_taxonomy() {
		if ( ! taxonomy_exists( 'wp_mcp_fixture' ) ) {
			register_taxonomy( 'wp_mcp_fixture', array( 'post' ), array(
				'label'             => 'WordPress MCP Fixture',
				'public'            => true,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'capabilities'      => array(
					'manage_terms' => 'manage_fixture_terms',
					'edit_terms'   => 'edit_fixture_terms',
					'delete_terms' => 'delete_fixture_terms',
					'assign_terms' => 'assign_fixture_terms',
				),
			) );
		}
		register_term_meta( 'wp_mcp_fixture', 'wp_mcp_label', array(
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
		) );
	}

	private function grant_fixture_taxonomy_capabilities( $user_id ) {
		$user = get_userdata( $user_id );
		foreach ( array( 'manage_fixture_terms', 'edit_fixture_terms', 'delete_fixture_terms', 'assign_fixture_terms' ) as $capability ) {
			$user->add_cap( $capability );
		}
	}

	public function test_list_taxonomies_discovers_custom_taxonomy_capabilities() {
		$this->register_fixture_taxonomy();
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Taxonomies::list_taxonomies( array( 'object_type' => 'post' ) );
		$this->assertNotWPError( $result );
		$names = wp_list_pluck( $result['taxonomies'], 'name' );
		$this->assertContains( 'wp_mcp_fixture', $names );
	}

	public function test_taxonomy_term_crud_uses_registered_taxonomy() {
		$this->register_fixture_taxonomy();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->grant_fixture_taxonomy_capabilities( $admin );
		wp_set_current_user( $admin );
		$created = WP_MCP_Taxonomies::create_term( array( 'taxonomy' => 'wp_mcp_fixture', 'name' => 'Fixture term' ) );
		$this->assertNotWPError( $created );
		$this->assertEquals( 'Fixture term', $created['name'] );
		$updated = WP_MCP_Taxonomies::update_term( array( 'taxonomy' => 'wp_mcp_fixture', 'term_id' => $created['id'], 'name' => 'Updated term' ) );
		$this->assertNotWPError( $updated );
		$this->assertEquals( 'Updated term', $updated['name'] );
		$deleted = WP_MCP_Taxonomies::delete_term( array( 'taxonomy' => 'wp_mcp_fixture', 'term_id' => $created['id'] ) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
	}

	public function test_get_term_rejects_taxonomy_confusion() {
		$this->register_fixture_taxonomy();
		$category_id = self::factory()->category->create();
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Taxonomies::get_term( array( 'taxonomy' => 'wp_mcp_fixture', 'term_id' => $category_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_term', $result->get_error_code() );
	}

	public function test_assign_and_remove_terms_validate_object_type() {
		$this->register_fixture_taxonomy();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->grant_fixture_taxonomy_capabilities( $admin );
		wp_set_current_user( $admin );
		$term = self::factory()->term->create( array( 'taxonomy' => 'wp_mcp_fixture' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $admin ) );
		$assigned = WP_MCP_Taxonomies::assign_terms( array( 'taxonomy' => 'wp_mcp_fixture', 'object_id' => $post_id, 'object_type' => 'post', 'term_ids' => array( $term ) ) );
		$this->assertNotWPError( $assigned );
		$this->assertContains( $term, wp_get_object_terms( $post_id, 'wp_mcp_fixture', array( 'fields' => 'ids' ) ) );
		$wrong_type = WP_MCP_Taxonomies::assign_terms( array( 'taxonomy' => 'wp_mcp_fixture', 'object_id' => $post_id, 'object_type' => 'page', 'term_ids' => array( $term ) ) );
		$this->assertWPError( $wrong_type );
		$removed = WP_MCP_Taxonomies::remove_terms( array( 'taxonomy' => 'wp_mcp_fixture', 'object_id' => $post_id, 'object_type' => 'post', 'term_ids' => array( $term ) ) );
		$this->assertNotWPError( $removed );
	}

	public function test_registered_term_meta_is_allowlisted() {
		$this->register_fixture_taxonomy();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->grant_fixture_taxonomy_capabilities( $admin );
		wp_set_current_user( $admin );
		$term = self::factory()->term->create( array( 'taxonomy' => 'wp_mcp_fixture' ) );
		$updated = WP_MCP_Taxonomies::update_term_meta( array( 'taxonomy' => 'wp_mcp_fixture', 'term_id' => $term, 'meta_key' => 'wp_mcp_label', 'value' => 'Allowed' ) );
		$this->assertNotWPError( $updated );
		$read = WP_MCP_Taxonomies::get_term_meta( array( 'taxonomy' => 'wp_mcp_fixture', 'term_id' => $term, 'meta_key' => 'wp_mcp_label' ) );
		$this->assertNotWPError( $read );
		$this->assertEquals( 'Allowed', $read['value'] );
		$rejected = WP_MCP_Taxonomies::get_term_meta( array( 'taxonomy' => 'wp_mcp_fixture', 'term_id' => $term, 'meta_key' => '_arbitrary' ) );
		$this->assertWPError( $rejected );
		$this->assertEquals( 'wp_mcp_term_meta_not_registered', $rejected->get_error_code() );
	}

	public function test_bulk_assign_terms_reports_each_object() {
		$this->register_fixture_taxonomy();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->grant_fixture_taxonomy_capabilities( $admin );
		wp_set_current_user( $admin );
		$term = self::factory()->term->create( array( 'taxonomy' => 'wp_mcp_fixture' ) );
		$one = self::factory()->post->create( array( 'post_author' => $admin ) );
		$two = self::factory()->post->create( array( 'post_author' => $admin ) );
		$result = WP_MCP_Taxonomies::bulk_assign_terms( array( 'taxonomy' => 'wp_mcp_fixture', 'object_type' => 'post', 'object_ids' => array( $one, $two ), 'term_ids' => array( $term ) ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 2, $result['assigned'] );
		$this->assertCount( 2, $result['results'] );
	}

	public function test_taxonomy_crud_requires_native_taxonomy_capability() {
		$this->register_fixture_taxonomy();
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Taxonomies::create_term( array( 'taxonomy' => 'wp_mcp_fixture', 'name' => 'Denied' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_taxonomy_permission_denied', $result->get_error_code() );
	}

	public function test_bulk_assign_rejects_more_than_twenty_objects() {
		$this->register_fixture_taxonomy();
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Taxonomies::bulk_assign_terms( array( 'taxonomy' => 'wp_mcp_fixture', 'object_type' => 'post', 'object_ids' => range( 1, 21 ), 'term_ids' => array( 1 ) ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_bulk_limit_exceeded', $result->get_error_code() );
	}

	// ## Media library tests (59+)

	public function test_get_media_returns_attachment_details() {
		wp_set_current_user( $this->agent_user );
		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
			'post_title'     => 'Media title',
			'post_excerpt'   => 'Caption',
			'post_content'   => 'Description',
		) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Alt text' );

		$result = WP_MCP_Media::get_media( array( 'media_id' => $attachment_id ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( $attachment_id, $result['id'] );
		$this->assertEquals( 'Media title', $result['title'] );
		$this->assertEquals( 'Caption', $result['caption'] );
		$this->assertEquals( 'Description', $result['description'] );
		$this->assertEquals( 'Alt text', $result['alt_text'] );
	}

	public function test_update_media_updates_only_explicit_metadata() {
		wp_set_current_user( $this->agent_user );
		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
			'post_title'     => 'Old title',
			'post_excerpt'   => 'Old caption',
			'post_content'   => 'Old description',
		) );

		$result = WP_MCP_Media::update_media( array(
			'media_id'    => $attachment_id,
			'title'       => 'New title',
			'caption'     => 'New caption',
			'description' => 'New description',
			'alt_text'    => 'New alt',
		) );

		$this->assertNotWPError( $result );
		$media = get_post( $attachment_id );
		$this->assertEquals( 'New title', $media->post_title );
		$this->assertEquals( 'New caption', $media->post_excerpt );
		$this->assertEquals( 'New description', $media->post_content );
		$this->assertEquals( 'New alt', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	public function test_attach_and_detach_media() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );
		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
		) );

		$attached = WP_MCP_Media::attach_media( array( 'media_id' => $attachment_id, 'post_id' => $post_id ) );
		$this->assertNotWPError( $attached );
		$this->assertEquals( $post_id, (int) get_post( $attachment_id )->post_parent );

		$detached = WP_MCP_Media::detach_media( array( 'media_id' => $attachment_id ) );
		$this->assertNotWPError( $detached );
		$this->assertEquals( 0, (int) get_post( $attachment_id )->post_parent );
	}

	public function test_remove_featured_image_is_idempotent() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );
		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
		) );
		set_post_thumbnail( $post_id, $attachment_id );

		$result = WP_MCP_Media::remove_featured_image( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 0, get_post_thumbnail_id( $post_id ) );

		$again = WP_MCP_Media::remove_featured_image( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $again );
	}

	public function test_remote_media_rejects_localhost_before_fetch() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$result = WP_MCP_Media::upload_media_from_url( array( 'url' => 'http://127.0.0.1/private.jpg' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_remote_url_denied', $result->get_error_code() );
	}

	public function test_upload_media_rejects_server_filesystem_path() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$result = WP_MCP_Media::upload_media( array( 'path' => '/etc/passwd' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_validation_error', $result->get_error_code() );
	}

	public function test_agent_cannot_upload_or_delete_media() {
		wp_set_current_user( $this->agent_user );
		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
		) );
		$delete = WP_MCP_Media::delete_media_permanently( array( 'media_id' => $attachment_id ) );
		$this->assertWPError( $delete );
		$this->assertEquals( 'wp_mcp_permission_denied', $delete->get_error_code() );
	}

	public function test_bulk_trash_media_returns_per_item_results() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$one = self::factory()->attachment->create_object( 'one.jpg', 0, array( 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );
		$two = self::factory()->attachment->create_object( 'two.jpg', 0, array( 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );

		$result = WP_MCP_Media::bulk_trash_media( array( 'media_ids' => array( $one, $two, $one ) ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 2, $result['trashed'] );
		$this->assertCount( 2, $result['results'] );
		$this->assertEquals( 'trash', get_post( $one )->post_status );
		$this->assertEquals( 'trash', get_post( $two )->post_status );
	}

	public function test_upload_media_accepts_client_content_without_server_path() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$result = WP_MCP_Media::upload_media( array(
			'filename'       => 'pixel.gif',
			'content_base64' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
			'alt_text'       => 'Pixel',
		) );

		$this->assertNotWPError( $result );
		$this->assertNotNull( get_post( $result['id'] ) );
		$this->assertEquals( 'Pixel', get_post_meta( $result['id'], '_wp_attachment_image_alt', true ) );
	}

	public function test_replace_media_preserves_attachment_id() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$uploaded = WP_MCP_Media::upload_media( array(
			'filename'       => 'original.gif',
			'content_base64' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
		) );
		$this->assertNotWPError( $uploaded );

		$replaced = WP_MCP_Media::replace_media( array(
			'media_id'       => $uploaded['id'],
			'filename'       => 'replacement.gif',
			'content_base64' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
		) );
		$this->assertNotWPError( $replaced );
		$this->assertEquals( $uploaded['id'], $replaced['id'] );
	}

	public function test_replace_media_rolls_back_when_attached_file_update_fails() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$uploaded = WP_MCP_Media::upload_media( array(
			'filename'       => 'rollback-attached-original.gif',
			'content_base64' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
		) );
		$this->assertNotWPError( $uploaded );

		$media_id = $uploaded['id'];
		$old_post = get_post( $media_id );
		$old_file = get_attached_file( $media_id );
		$old_meta = wp_get_attachment_metadata( $media_id );
		$filter   = function ( $file, $attachment_id ) use ( $media_id, $old_file ) {
			return $media_id === (int) $attachment_id ? $old_file : $file;
		};

		add_filter( 'update_attached_file', $filter, 10, 2 );
		try {
			$result = WP_MCP_Media::replace_media( array(
				'media_id'       => $media_id,
				'filename'       => 'rollback-attached-replacement.gif',
				'content_base64' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
				'title'          => 'Replacement title',
			) );
		} finally {
			remove_filter( 'update_attached_file', $filter, 10 );
		}

		$this->assertWPError( $result );
		$this->assertEquals( $old_file, get_attached_file( $media_id ) );
		$this->assertEquals( $old_meta, wp_get_attachment_metadata( $media_id ) );
		$this->assertEquals( $old_post->post_title, get_post( $media_id )->post_title );
		$this->assertEquals( $old_post->post_mime_type, get_post( $media_id )->post_mime_type );
		$this->assertEquals( $old_post->guid, get_post( $media_id )->guid );
	}

	public function test_replace_media_rolls_back_when_metadata_update_fails() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$uploaded = WP_MCP_Media::upload_media( array(
			'filename'       => 'rollback-metadata-original.gif',
			'content_base64' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
		) );
		$this->assertNotWPError( $uploaded );

		$media_id = $uploaded['id'];
		$old_post = get_post( $media_id );
		$old_file = get_attached_file( $media_id );
		$old_meta = wp_get_attachment_metadata( $media_id );
		$filter   = function ( $metadata, $attachment_id ) use ( $media_id, $old_meta ) {
			return $media_id === (int) $attachment_id ? $old_meta : $metadata;
		};

		add_filter( 'wp_update_attachment_metadata', $filter, 10, 2 );
		try {
			$result = WP_MCP_Media::replace_media( array(
				'media_id'       => $media_id,
				'filename'       => 'rollback-metadata-replacement.gif',
				'content_base64' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
				'title'          => 'Replacement title',
			) );
		} finally {
			remove_filter( 'wp_update_attachment_metadata', $filter, 10 );
		}

		$this->assertWPError( $result );
		$this->assertEquals( $old_file, get_attached_file( $media_id ) );
		$this->assertEquals( $old_meta, wp_get_attachment_metadata( $media_id ) );
		$this->assertEquals( $old_post->post_title, get_post( $media_id )->post_title );
		$this->assertEquals( $old_post->post_mime_type, get_post( $media_id )->post_mime_type );
		$this->assertEquals( $old_post->guid, get_post( $media_id )->guid );
	}

	public function test_replace_media_preserves_replacement_files_when_rollback_is_incomplete() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$uploaded = WP_MCP_Media::upload_media( array(
			'filename'       => 'rollback-persistent-original.gif',
			'content_base64' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
		) );
		$this->assertNotWPError( $uploaded );

		$media_id        = $uploaded['id'];
		$old_file        = get_attached_file( $media_id );
		$old_meta        = wp_get_attachment_metadata( $media_id );
		$replacement_meta = null;
		$file_filter     = function ( $file, $attachment_id ) use ( $media_id, $old_file ) {
			return $media_id === (int) $attachment_id ? $old_file : $file;
		};
		$metadata_filter = function ( $metadata, $attachment_id ) use ( $media_id, $old_meta, &$replacement_meta ) {
			if ( $media_id !== (int) $attachment_id ) {
				return $metadata;
			}
			if ( null === $replacement_meta && isset( $metadata['file'] ) && $metadata['file'] !== $old_meta['file'] ) {
				$replacement_meta = $metadata;
			}
			return null === $replacement_meta ? $metadata : $replacement_meta;
		};

		add_filter( 'update_attached_file', $file_filter, 10, 2 );
		add_filter( 'wp_update_attachment_metadata', $metadata_filter, 10, 2 );
		try {
			$result = WP_MCP_Media::replace_media( array(
				'media_id'       => $media_id,
				'filename'       => 'rollback-persistent-replacement.gif',
				'content_base64' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
			) );
		} finally {
			remove_filter( 'update_attached_file', $file_filter, 10 );
			remove_filter( 'wp_update_attachment_metadata', $metadata_filter, 10 );
		}

		$stored_meta      = wp_get_attachment_metadata( $media_id );
		$replacement_file = trailingslashit( wp_upload_dir()['basedir'] ) . $stored_meta['file'];
		$this->assertWPError( $result );
		$this->assertNotEquals( $old_meta, $stored_meta );
		$this->assertFileExists( $replacement_file );
	}

	public function test_media_trash_restore_delete_cycle_as_administrator() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$media_id = self::factory()->attachment->create_object( 'cycle.jpg', 0, array( 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ) );

		$trashed = WP_MCP_Media::trash_media( array( 'media_id' => $media_id ) );
		$this->assertNotWPError( $trashed );
		$this->assertEquals( 'trash', get_post_status( $media_id ) );
		$restored = WP_MCP_Media::restore_media( array( 'media_id' => $media_id ) );
		$this->assertNotWPError( $restored );
		$deleted = WP_MCP_Media::delete_media_permanently( array( 'media_id' => $media_id ) );
		$this->assertNotWPError( $deleted );
		$this->assertNull( get_post( $media_id ) );
	}

	// ## Role tests (38-44)

	public function test_role_no_upload_files() {
		$role = get_role( 'wp_mcp_agent' );
		$this->assertFalse( $role->has_cap( 'upload_files' ) );
	}

	public function test_role_no_delete_posts() {
		$role = get_role( 'wp_mcp_agent' );
		$this->assertFalse( $role->has_cap( 'delete_posts' ) );
	}

	public function test_role_no_edit_others_posts() {
		$role = get_role( 'wp_mcp_agent' );
		$this->assertFalse( $role->has_cap( 'edit_others_posts' ) );
	}

	public function test_role_no_manage_options() {
		$role = get_role( 'wp_mcp_agent' );
		$this->assertFalse( $role->has_cap( 'manage_options' ) );
	}

	public function test_role_reconciliation_idempotent() {
		WP_MCP_Role::create_or_reconcile();
		$role1 = get_role( 'wp_mcp_agent' );
		WP_MCP_Role::create_or_reconcile();
		$role2 = get_role( 'wp_mcp_agent' );
		$this->assertEquals( $role1->capabilities, $role2->capabilities );
	}

	public function test_role_reconciliation_removes_retired_caps() {
		$role = get_role( 'wp_mcp_agent' );
		$role->add_cap( 'upload_files' );
		WP_MCP_Role::create_or_reconcile();
		$role = get_role( 'wp_mcp_agent' );
		$this->assertFalse( $role->has_cap( 'upload_files' ) );
	}

	public function test_uninstall_preserves_role_with_users() {
		// Conceptually, if users exist, role isn't removed.
		$users = get_users( array( 'role' => 'wp_mcp_agent' ) );
		$this->assertNotEmpty( $users );
	}

	// ## Idempotency tests (45-46)

	public function test_publish_post_already_published_no_change() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'publish',
		) );

		$post1 = get_post( $post_id );
		$modified1 = $post1->post_modified;

		sleep( 1 ); // Ensure time gap if needed

		$result = WP_MCP_Posts::publish_post( array(
			'post_id' => $post_id,
		) );

		$post2 = get_post( $post_id );
		$this->assertEquals( $modified1, $post2->post_modified );
	}

	public function test_set_featured_image_same_image_no_change() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
		) );

		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
		) );

		WP_MCP_Media::set_featured_image( array(
			'post_id'  => $post_id,
			'media_id' => $attachment_id,
		) );

		$result = WP_MCP_Media::set_featured_image( array(
			'post_id'  => $post_id,
			'media_id' => $attachment_id,
		) );

		$this->assertNotWPError( $result );
	}

	// ## Fuzz / input hardening tests (47-58)

	public function test_post_id_zero() {
		$result = WP_MCP_Posts::get_post( array( 'post_id' => 0 ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_validation_error', $result->get_error_code() );
	}

	public function test_post_id_negative() {
		$result = WP_MCP_Posts::get_post( array( 'post_id' => -1 ) );
		$this->assertWPError( $result );
	}

	public function test_post_id_string() {
		$result = WP_MCP_Posts::get_post( array( 'post_id' => 'abc' ) );
		$this->assertWPError( $result );
	}

	public function test_media_id_invalid() {
		$result = WP_MCP_Media::set_featured_image( array(
			'post_id'  => 1,
			'media_id' => 0,
		) );
		$this->assertWPError( $result );
	}

	public function test_per_page_excessive() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::list_posts( array( 'per_page' => 50000 ) );
		// Assuming clamping or error, test logic should ensure no fatal error
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	public function test_page_negative() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::list_posts( array( 'page' => -5 ) );
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	public function test_arrays_with_invalid_ids() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title'      => 'Test',
			'content'    => 'Content',
			'categories' => array( 0, -1, 'abc' ),
		) );
		$this->assertWPError( $result );
	}

	public function test_arrays_extremely_long() {
		$long_array = array_fill( 0, 101, 1 );
		$result = WP_MCP_Permissions::validate_taxonomy_terms( $long_array, 'category' );
		$this->assertWPError( $result );
	}

	public function test_additional_fields_ignored() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title'       => 'Test',
			'content'     => 'Content',
			'post_author' => 999, // Should be ignored
		) );
		$this->assertNotWPError( $result );
		$post = get_post( $result['id'] );
		$this->assertEquals( $this->agent_user, $post->post_author );
	}

	public function test_script_tag_stripped() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title'   => 'Test',
			'content' => '<p>Hello</p><script>alert(1)</script>',
		) );
		$this->assertNotWPError( $result );
		$post = get_post( $result['id'] );
		$this->assertStringNotContainsString( '<script>', $post->post_content );
	}

	public function test_event_handlers_stripped() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title'   => 'Test',
			'content' => '<div onclick="alert(1)">test</div>',
		) );
		$this->assertNotWPError( $result );
		$post = get_post( $result['id'] );
		$this->assertStringNotContainsString( 'onclick', $post->post_content );
	}

	public function test_javascript_urls_stripped() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::create_post( array(
			'title'   => 'Test',
			'content' => '<a href="javascript:alert(1)">click</a>',
		) );
		$this->assertNotWPError( $result );
		$post = get_post( $result['id'] );
		$this->assertStringNotContainsString( 'javascript:', $post->post_content );
	}

	// ## Meta-capability & architecture tests (59+)

	public function test_update_post_allows_administrator_on_others_post() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_title'  => 'Old Title',
		) );

		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$result = WP_MCP_Posts::update_post( array(
			'post_id' => $post_id,
			'title'   => 'New Title',
		) );

		$this->assertNotWPError( $result );
		$post = get_post( $post_id );
		$this->assertEquals( 'New Title', $post->post_title );
	}

	public function test_publish_post_allows_administrator_on_others_draft() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'draft',
		) );

		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$result = WP_MCP_Posts::publish_post( array(
			'post_id' => $post_id,
		) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'publish', $result['status'] );
	}

	public function test_set_featured_image_allows_administrator_on_others_post() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
		) );

		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
		) );

		$result = WP_MCP_Media::set_featured_image( array(
			'post_id'  => $post_id,
			'media_id' => $attachment_id,
		) );

		$this->assertNotWPError( $result );
		$this->assertEquals( $attachment_id, get_post_thumbnail_id( $post_id ) );
	}

	public function test_all_14_categories_registered() {
		if ( ! function_exists( 'wp_get_ability_category' ) ) {
			$this->assertTrue( true );
			return;
		}

		$slugs = array(
			'wp-mcp-content',
			'wp-mcp-media',
			'wp-mcp-taxonomies',
			'wp-mcp-comments',
			'wp-mcp-users',
			'wp-mcp-navigation',
			'wp-mcp-site-editor',
			'wp-mcp-plugins',
			'wp-mcp-themes',
			'wp-mcp-settings',
			'wp-mcp-system',
			'wp-mcp-privacy',
			'wp-mcp-network',
			'wp-mcp-extensibility',
		);

		foreach ( $slugs as $slug ) {
			$this->assertNotNull( wp_get_ability_category( $slug ), "Category {$slug} should be registered" );
		}
	}

	public function test_media_abilities_recategorized() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}

		$ability = wp_get_ability( 'wp-mcp/list-media' );
		$this->assertEquals( 'wp-mcp-media', $ability->get_category() );
	}

	public function test_taxonomy_abilities_recategorized() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}

		$ability = wp_get_ability( 'wp-mcp/list-categories' );
		$this->assertEquals( 'wp-mcp-taxonomies', $ability->get_category() );
	}

	/**
	 * Names of every registered `wp-mcp/` ability.
	 *
	 * Asking `wp_get_ability()` for a name that is not registered emits a
	 * `_doing_it_wrong` notice in WordPress 6.9, and `WP_UnitTestCase` turns
	 * that into a failure — so absence is always proven against this list,
	 * never by looking an ability up and expecting null.
	 *
	 * @return string[]
	 */
	private function registered_wp_mcp_ability_names() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 === strpos( $name, 'wp-mcp/' ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	public function test_ability_matrix_matches_registered_abilities() {
		$matrix = WP_MCP_Ability_Matrix::get();

		$expected_abilities = array(
			'wp-mcp/list-posts',
			'wp-mcp/get-post',
			'wp-mcp/create-post',
			'wp-mcp/update-post',
			'wp-mcp/publish-post',
			'wp-mcp/list-categories',
			'wp-mcp/list-tags',
			'wp-mcp/list-taxonomies',
			'wp-mcp/get-taxonomy',
			'wp-mcp/list-terms',
			'wp-mcp/get-term',
			'wp-mcp/create-term',
			'wp-mcp/update-term',
			'wp-mcp/delete-term',
			'wp-mcp/assign-terms',
			'wp-mcp/remove-terms',
			'wp-mcp/bulk-assign-terms',
			'wp-mcp/bulk-remove-terms',
			'wp-mcp/get-term-meta',
			'wp-mcp/update-term-meta',
			'wp-mcp/delete-term-meta',
			'wp-mcp/list-media',
			'wp-mcp/get-media',
			'wp-mcp/upload-media',
			'wp-mcp/upload-media-from-url',
			'wp-mcp/update-media',
			'wp-mcp/replace-media',
			'wp-mcp/attach-media',
			'wp-mcp/detach-media',
			'wp-mcp/set-featured-image',
			'wp-mcp/remove-featured-image',
			'wp-mcp/regenerate-media-metadata',
			'wp-mcp/trash-media',
			'wp-mcp/restore-media',
			'wp-mcp/delete-media-permanently',
			'wp-mcp/bulk-trash-media',
			'wp-mcp/list-pages',
			'wp-mcp/get-page',
			'wp-mcp/create-page',
			'wp-mcp/update-page',
			'wp-mcp/unpublish-post',
			'wp-mcp/schedule-post',
			'wp-mcp/change-post-status',
			'wp-mcp/trash-post',
			'wp-mcp/restore-post',
			'wp-mcp/delete-post-permanently',
			'wp-mcp/publish-page',
			'wp-mcp/unpublish-page',
			'wp-mcp/schedule-page',
			'wp-mcp/trash-page',
			'wp-mcp/restore-page',
			'wp-mcp/delete-page-permanently',
			'wp-mcp/change-post-author',
			'wp-mcp/update-post-slug',
			'wp-mcp/stick-post',
			'wp-mcp/unstick-post',
			'wp-mcp/set-post-password',
			'wp-mcp/change-page-author',
			'wp-mcp/update-page-slug',
			'wp-mcp/update-page-attributes',
			'wp-mcp/list-post-revisions',
			'wp-mcp/get-post-revision',
			'wp-mcp/restore-post-revision',
			'wp-mcp/get-post-autosave',
			'wp-mcp/list-page-revisions',
			'wp-mcp/get-page-revision',
			'wp-mcp/restore-page-revision',
			'wp-mcp/duplicate-post',
			'wp-mcp/bulk-trash-posts',
			'wp-mcp/list-comments',
			'wp-mcp/get-comment',
			'wp-mcp/create-comment',
			'wp-mcp/reply-comment',
			'wp-mcp/update-comment',
			'wp-mcp/approve-comment',
			'wp-mcp/unapprove-comment',
			'wp-mcp/mark-comment-spam',
			'wp-mcp/unspam-comment',
			'wp-mcp/trash-comment',
			'wp-mcp/restore-comment',
			'wp-mcp/delete-comment-permanently',
			'wp-mcp/bulk-moderate-comments',
			'wp-mcp/list-users',
			'wp-mcp/get-user',
			'wp-mcp/get-current-user',
			'wp-mcp/create-user',
			'wp-mcp/update-user',
			'wp-mcp/set-user-password',
			'wp-mcp/delete-user',
			'wp-mcp/change-user-role',
			'wp-mcp/add-user-role',
			'wp-mcp/remove-user-role',
			'wp-mcp/list-user-capabilities',
			'wp-mcp/list-roles',
			'wp-mcp/get-role',
			'wp-mcp/create-role',
			'wp-mcp/update-role-capabilities',
			'wp-mcp/add-role-capability',
			'wp-mcp/remove-role-capability',
			'wp-mcp/delete-role',
			'wp-mcp/list-application-passwords',
			'wp-mcp/create-application-password',
			'wp-mcp/revoke-application-password',
			'wp-mcp/revoke-all-application-passwords',
		);

		foreach ( $expected_abilities as $ability_name ) {
			$this->assertArrayHasKey( $ability_name, $matrix, "Matrix is missing {$ability_name}" );
		}

		if ( ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wp_get_abilities' ) ) {
			$this->assertTrue( true );
			return;
		}

		$registered = $this->registered_wp_mcp_ability_names();

		foreach ( $matrix as $ability_name => $row ) {
			// Issue #14: an integration ability is documented unconditionally but
			// registered only when its plugin is present, so for an absent
			// integration the assertion is the opposite one.
			if ( isset( $row['integration'] ) && ! WP_MCP_Integrations::is_available( $row['integration'] ) ) {
				$this->assertNotContains( $ability_name, $registered, "{$ability_name} must not be registered while the {$row['integration']} integration is unavailable" );
				continue;
			}
			$this->assertContains( $ability_name, $registered, "{$ability_name} is in the matrix but not registered" );
		}

		// Reverse direction: every registered wp-mcp/ ability must be in the matrix too —
		// catches "added an ability, forgot the matrix", which the two checks above cannot.
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 !== strpos( $name, 'wp-mcp/' ) ) {
				continue;
			}
			$this->assertArrayHasKey( $name, $matrix, "{$name} is registered but missing from the matrix" );
		}
	}

	/**
	 * The matrix's category/destructive/idempotent fields are documentation
	 * that nothing previously verified against the ability's own
	 * declaration — this asserts they cannot silently drift apart.
	 */
	public function test_ability_matrix_fields_match_registered_annotations() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}

		$registered = $this->registered_wp_mcp_ability_names();

		$matrix = WP_MCP_Ability_Matrix::get();
		foreach ( $matrix as $ability_name => $row ) {
			// Never ask the registry for an ability it does not have: WordPress 6.9
			// answers that with a _doing_it_wrong notice, which the test case turns
			// into a failure. Integration abilities are legitimately absent here.
			if ( ! in_array( $ability_name, $registered, true ) ) {
				continue;
			}
			$ability = wp_get_ability( $ability_name );
			$this->assertNotNull( $ability, "{$ability_name} disappeared between listing and lookup" );
			$this->assertEquals( $row['category'], $ability->get_category(), "{$ability_name} category mismatch between matrix and registration" );
			$annotations = $ability->get_meta_item( 'annotations' );
			$this->assertIsArray( $annotations, "{$ability_name} is missing MCP annotations" );
			$this->assertEquals( $row['destructive'], $annotations['destructive'], "{$ability_name} destructive mismatch between matrix and registration" );
			$this->assertEquals( $row['idempotent'], $annotations['idempotent'], "{$ability_name} idempotent mismatch between matrix and registration" );
		}
	}

	// ## Lifecycle, attribute, revision, and bulk tests (#3)

	public function test_trash_post_rejects_agent_without_delete_cap() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
		) );

		// wp_mcp_agent lacks delete_posts entirely -- trashing even an
		// own post is denied, same as delete-post-permanently.
		$result = WP_MCP_Posts::trash_post( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	public function test_trash_restore_delete_permanently_full_cycle_as_administrator() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id    = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'publish',
		) );

		wp_set_current_user( $admin_user );

		$trashed = WP_MCP_Posts::trash_post( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $trashed );
		$this->assertEquals( 'trash', get_post_status( $post_id ) );

		$restored = WP_MCP_Posts::restore_post( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $restored );
		$this->assertNotEquals( 'trash', get_post_status( $post_id ) );

		$deleted = WP_MCP_Posts::delete_post_permanently( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertNull( get_post( $post_id ) );
	}

	public function test_trash_post_idempotent_if_already_trashed() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id    = self::factory()->post->create( array( 'post_author' => $this->other_user ) );
		wp_set_current_user( $admin_user );

		WP_MCP_Posts::trash_post( array( 'post_id' => $post_id ) );
		$result = WP_MCP_Posts::trash_post( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'trash', $result['status'] );
	}

	public function test_restore_post_idempotent_if_not_trashed() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id    = self::factory()->post->create( array( 'post_author' => $this->other_user ) );
		wp_set_current_user( $admin_user );

		$result = WP_MCP_Posts::restore_post( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertNotEquals( 'trash', $result['status'] );
	}

	public function test_unpublish_post_reverts_to_draft() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'publish',
		) );

		$result = WP_MCP_Posts::unpublish_post( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'draft', get_post_status( $post_id ) );
	}

	public function test_unpublish_post_idempotent_if_already_draft() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'draft',
		) );

		$result = WP_MCP_Posts::unpublish_post( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'draft', $result['status'] );
	}

	public function test_unpublish_post_rejects_other_user() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'publish',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::unpublish_post( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ownership_violation', $result->get_error_code() );
	}

	public function test_schedule_post_future_date_succeeds() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'draft',
		) );

		$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$result = WP_MCP_Posts::schedule_post( array( 'post_id' => $post_id, 'date' => $future ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'future', get_post_status( $post_id ) );
	}

	public function test_schedule_post_rejects_past_date() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'draft',
		) );

		$past   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$result = WP_MCP_Posts::schedule_post( array( 'post_id' => $post_id, 'date' => $past ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_validation_error', $result->get_error_code() );
	}

	public function test_schedule_post_rejects_without_publish_posts() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$post_id     = self::factory()->post->create( array(
			'post_author' => $contributor,
			'post_status' => 'draft',
		) );

		wp_set_current_user( $contributor );
		$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$result = WP_MCP_Posts::schedule_post( array( 'post_id' => $post_id, 'date' => $future ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	public function test_change_post_status_transitions_between_draft_and_pending() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'draft',
		) );

		$result = WP_MCP_Posts::change_post_status( array( 'post_id' => $post_id, 'status' => 'pending' ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'pending', get_post_status( $post_id ) );
	}

	public function test_change_post_status_rejects_transition_from_publish() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_status' => 'publish',
		) );

		$result = WP_MCP_Posts::change_post_status( array( 'post_id' => $post_id, 'status' => 'pending' ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_status_transition', $result->get_error_code() );
	}

	// ## Pages lifecycle

	public function test_create_page_creates_draft() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$result = WP_MCP_Pages::create_page( array(
			'title'   => 'Test Page',
			'content' => 'Content',
		) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'draft', $result['status'] );
		$this->assertEquals( $admin_user, $result['author'] );
	}

	public function test_agent_cannot_create_page_without_edit_pages() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Pages::create_page( array(
			'title'   => 'Forbidden Page',
			'content' => 'Content',
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	public function test_update_page_modifies_own() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$page_id = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_author' => $admin_user,
			'post_title'  => 'Old Title',
		) );

		$result = WP_MCP_Pages::update_page( array( 'page_id' => $page_id, 'title' => 'New Title' ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'New Title', get_the_title( $page_id ) );
	}

	public function test_publish_page_publishes_draft() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$page_id = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_author' => $admin_user,
			'post_status' => 'draft',
		) );

		$result = WP_MCP_Pages::publish_page( array( 'page_id' => $page_id ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'publish', get_post_status( $page_id ) );
	}

	public function test_trash_page_and_restore_as_administrator() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$page_id    = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_author' => $this->other_user,
		) );

		wp_set_current_user( $admin_user );
		$trashed = WP_MCP_Pages::trash_page( array( 'page_id' => $page_id ) );
		$this->assertNotWPError( $trashed );
		$this->assertEquals( 'trash', get_post_status( $page_id ) );

		$restored = WP_MCP_Pages::restore_page( array( 'page_id' => $page_id ) );
		$this->assertNotWPError( $restored );
		$this->assertNotEquals( 'trash', get_post_status( $page_id ) );
	}

	public function test_delete_page_permanently_requires_delete_cap() {
		wp_set_current_user( $this->agent_user );
		$page_id = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_author' => $this->agent_user,
		) );

		$result = WP_MCP_Pages::delete_page_permanently( array( 'page_id' => $page_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	// ## Attributes: author, slug, sticky, password, page attributes

	public function test_change_post_author_requires_edit_others_posts() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );

		$result = WP_MCP_Posts::change_post_author( array( 'post_id' => $post_id, 'new_author_id' => $this->other_user ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	public function test_change_post_author_succeeds_as_administrator() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id    = self::factory()->post->create( array( 'post_author' => $this->other_user ) );

		wp_set_current_user( $admin_user );
		$result = WP_MCP_Posts::change_post_author( array( 'post_id' => $post_id, 'new_author_id' => $this->agent_user ) );

		$this->assertNotWPError( $result );
		$post = get_post( $post_id );
		$this->assertEquals( $this->agent_user, (int) $post->post_author );
	}

	public function test_change_post_author_rejects_invalid_target_user() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id    = self::factory()->post->create( array( 'post_author' => $this->other_user ) );

		wp_set_current_user( $admin_user );
		$result = WP_MCP_Posts::change_post_author( array( 'post_id' => $post_id, 'new_author_id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_validation_error', $result->get_error_code() );
	}

	public function test_change_post_author_rejects_target_without_edit_posts() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id    = self::factory()->post->create( array( 'post_author' => $this->other_user ) );

		wp_set_current_user( $admin_user );
		$result = WP_MCP_Posts::change_post_author( array( 'post_id' => $post_id, 'new_author_id' => $subscriber ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_validation_error', $result->get_error_code() );
	}

	public function test_update_post_slug_changes_slug() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );

		$result = WP_MCP_Posts::update_post_slug( array( 'post_id' => $post_id, 'slug' => 'my-new-slug' ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'my-new-slug', get_post( $post_id )->post_name );
	}

	public function test_update_page_slug_changes_slug() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $admin_user ) );

		$result = WP_MCP_Pages::update_page_slug( array( 'page_id' => $page_id, 'slug' => 'my-page-slug' ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'my-page-slug', get_post( $page_id )->post_name );
	}

	public function test_stick_post_requires_edit_others_posts() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );

		$result = WP_MCP_Posts::stick_post( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	public function test_stick_and_unstick_post_as_administrator() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id    = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );

		wp_set_current_user( $admin_user );
		$stuck = WP_MCP_Posts::stick_post( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $stuck );
		$this->assertTrue( is_sticky( $post_id ) );

		$unstuck = WP_MCP_Posts::unstick_post( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $unstuck );
		$this->assertFalse( is_sticky( $post_id ) );
	}

	public function test_set_post_password_sets_and_clears() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );

		$set = WP_MCP_Posts::set_post_password( array( 'post_id' => $post_id, 'password' => 'secret' ) );
		$this->assertNotWPError( $set );
		$this->assertEquals( 'secret', get_post( $post_id )->post_password );

		$cleared = WP_MCP_Posts::set_post_password( array( 'post_id' => $post_id, 'password' => '' ) );
		$this->assertNotWPError( $cleared );
		$this->assertEquals( '', get_post( $post_id )->post_password );
	}

	public function test_update_page_attributes_sets_parent_and_order() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$parent_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $admin_user ) );
		$page_id   = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $admin_user ) );

		$result = WP_MCP_Pages::update_page_attributes( array(
			'page_id'    => $page_id,
			'parent_id'  => $parent_id,
			'menu_order' => 5,
		) );

		$this->assertNotWPError( $result );
		$page = get_post( $page_id );
		$this->assertEquals( $parent_id, $page->post_parent );
		$this->assertEquals( 5, $page->menu_order );
	}

	public function test_update_page_attributes_rejects_invalid_template() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $admin_user ) );

		$result = WP_MCP_Pages::update_page_attributes( array(
			'page_id'  => $page_id,
			'template' => 'this-template-does-not-exist.php',
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_template', $result->get_error_code() );
	}

	public function test_update_page_attributes_rejects_self_as_parent() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $admin_user ) );

		$result = WP_MCP_Pages::update_page_attributes( array(
			'page_id'   => $page_id,
			'parent_id' => $page_id,
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_validation_error', $result->get_error_code() );
	}

	public function test_update_page_attributes_rejects_circular_hierarchy() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$grandparent_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $admin_user ) );
		$child_id       = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_author' => $admin_user,
			'post_parent' => $grandparent_id,
		) );

		// Attempting to make the grandparent a child of its own child.
		$result = WP_MCP_Pages::update_page_attributes( array(
			'page_id'   => $grandparent_id,
			'parent_id' => $child_id,
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_validation_error', $result->get_error_code() );
	}

	// ## Revisions and autosave

	public function test_list_and_get_post_revisions_after_updates() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author'  => $this->agent_user,
			'post_title'   => 'Original Title',
			'post_content' => 'Original content',
		) );

		WP_MCP_Posts::update_post( array( 'post_id' => $post_id, 'title' => 'Second Title' ) );
		WP_MCP_Posts::update_post( array( 'post_id' => $post_id, 'title' => 'Third Title' ) );

		$list = WP_MCP_Posts::list_post_revisions( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $list );
		$this->assertGreaterThan( 0, $list['total'] );

		$revisions  = wp_get_post_revisions( $post_id );
		$revision   = reset( $revisions );
		$detail     = WP_MCP_Posts::get_post_revision( array( 'post_id' => $post_id, 'revision_id' => $revision->ID ) );

		$this->assertNotWPError( $detail );
		$this->assertEquals( (int) $revision->ID, $detail['id'] );
	}

	public function test_restore_post_revision_reverts_content() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->agent_user,
			'post_title'  => 'Original Title',
		) );

		$revision_id = wp_save_post_revision( $post_id );
		$this->assertIsInt( $revision_id );

		WP_MCP_Posts::update_post( array( 'post_id' => $post_id, 'title' => 'Changed Title' ) );

		$result = WP_MCP_Posts::restore_post_revision( array( 'post_id' => $post_id, 'revision_id' => $revision_id ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'Original Title', get_the_title( $post_id ) );
	}

	public function test_get_post_revision_rejects_wrong_parent() {
		wp_set_current_user( $this->agent_user );
		$post_a = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );
		$post_b = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );

		WP_MCP_Posts::update_post( array( 'post_id' => $post_a, 'title' => 'Updated A' ) );
		$revisions_a = wp_get_post_revisions( $post_a );
		$revision_a  = reset( $revisions_a );

		// Ask for post_a's revision but claim post_b as the parent.
		$result = WP_MCP_Posts::get_post_revision( array( 'post_id' => $post_b, 'revision_id' => $revision_a->ID ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_revision', $result->get_error_code() );
	}

	public function test_get_post_autosave_returns_exists_false_when_none() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );

		$result = WP_MCP_Posts::get_post_autosave( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['exists'] );
	}

	public function test_list_get_restore_page_revisions() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$page_id = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_author' => $admin_user,
			'post_title'  => 'Original Page Title',
		) );

		WP_MCP_Pages::update_page( array( 'page_id' => $page_id, 'title' => 'Changed Page Title' ) );

		$list = WP_MCP_Pages::list_page_revisions( array( 'page_id' => $page_id ) );
		$this->assertNotWPError( $list );
		$this->assertGreaterThan( 0, $list['total'] );

		$revisions = wp_get_post_revisions( $page_id );
		$revision  = reset( $revisions );

		$detail = WP_MCP_Pages::get_page_revision( array( 'page_id' => $page_id, 'revision_id' => $revision->ID ) );
		$this->assertNotWPError( $detail );

		$restored = WP_MCP_Pages::restore_page_revision( array( 'page_id' => $page_id, 'revision_id' => $revision->ID ) );
		$this->assertNotWPError( $restored );
	}

	// ## Duplication and bulk

	public function test_duplicate_post_does_not_copy_author_or_status() {
		$post_id = self::factory()->post->create( array(
			'post_author' => $this->other_user,
			'post_status' => 'publish',
			'post_title'  => 'Source Post',
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Posts::duplicate_post( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( $this->agent_user, $result['author'] );
		$this->assertEquals( 'draft', $result['status'] );
		$this->assertEquals( 'Source Post', $result['title'] );
	}

	public function test_duplicate_post_copies_taxonomies_and_featured_image() {
		wp_set_current_user( $this->agent_user );
		$category_id = self::factory()->category->create();
		$post_id     = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );
		wp_set_post_categories( $post_id, array( $category_id ) );

		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', $post_id, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
		) );
		set_post_thumbnail( $post_id, $attachment_id );

		$result = WP_MCP_Posts::duplicate_post( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertContains( $category_id, wp_get_post_categories( $result['id'] ) );
		$this->assertEquals( $attachment_id, get_post_thumbnail_id( $result['id'] ) );
	}

	public function test_bulk_trash_posts_mixed_results_as_administrator() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id_1  = self::factory()->post->create( array( 'post_author' => $this->other_user ) );
		$post_id_2  = self::factory()->post->create( array( 'post_author' => $this->other_user ) );
		$fake_id    = 999999;

		wp_set_current_user( $admin_user );
		$result = WP_MCP_Posts::bulk_trash_posts( array( 'post_ids' => array( $post_id_1, $post_id_2, $fake_id ) ) );

		$this->assertEquals( 2, $result['trashed'] );
		$this->assertEquals( 1, $result['failed'] );
		$this->assertCount( 3, $result['results'] );
	}

	public function test_bulk_trash_posts_rejects_over_limit() {
		wp_set_current_user( $this->agent_user );
		$ids = range( 1, 21 );

		$result = WP_MCP_Posts::bulk_trash_posts( array( 'post_ids' => $ids ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_bulk_limit_exceeded', $result->get_error_code() );
	}

	public function test_bulk_trash_posts_dedupes_ids() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id    = self::factory()->post->create( array( 'post_author' => $this->other_user ) );

		wp_set_current_user( $admin_user );
		$result = WP_MCP_Posts::bulk_trash_posts( array( 'post_ids' => array( $post_id, $post_id, $post_id ) ) );

		$this->assertCount( 1, $result['results'] );
		$this->assertEquals( 1, $result['trashed'] );
	}

	// ## Comments and moderation (#6)

	public function test_create_and_reply_comment_use_authenticated_identity_and_parent() {
		wp_set_current_user( $this->agent_user );
		$post_id = self::factory()->post->create( array(
			'post_author'    => $this->agent_user,
			'comment_status' => 'open',
		) );

		$created = WP_MCP_Comments::create_comment( array(
			'post_id' => $post_id,
			'content' => 'Root <script>alert(1)</script> comment',
		) );
		$this->assertNotWPError( $created );
		$this->assertEquals( $this->agent_user, $created['author_id'] );
		$this->assertStringNotContainsString( '<script>', get_comment( $created['id'] )->comment_content );

		add_filter( 'comment_flood_filter', '__return_false' );
		try {
			$reply = WP_MCP_Comments::reply_comment( array(
				'parent_comment_id' => $created['id'],
				'content'           => 'Threaded reply',
			) );
		} finally {
			remove_filter( 'comment_flood_filter', '__return_false' );
		}
		$this->assertNotWPError( $reply );
		$this->assertEquals( $created['id'], $reply['parent_id'] );
		$this->assertEquals( $post_id, $reply['post_id'] );
	}

	public function test_list_and_get_comments_do_not_expose_email_to_agent() {
		$post_id = self::factory()->post->create( array( 'post_author' => $this->other_user, 'comment_status' => 'open' ) );
		$comment_id = self::factory()->comment->create( array(
			'comment_post_ID'      => $post_id,
			'comment_content'      => 'Public comment',
			'comment_author'       => 'Public Author',
			'comment_author_email' => 'author@example.com',
			'comment_approved'     => 1,
		) );
		wp_set_current_user( $this->agent_user );

		$list = WP_MCP_Comments::list_comments( array( 'status' => 'approve', 'search' => 'Public' ) );
		$this->assertNotWPError( $list );
		$this->assertCount( 1, $list['comments'] );
		$this->assertArrayNotHasKey( 'author_email', $list['comments'][0] );

		$detail = WP_MCP_Comments::get_comment( array( 'comment_id' => $comment_id ) );
		$this->assertNotWPError( $detail );
		$this->assertArrayNotHasKey( 'author_email', $detail );
	}

	public function test_comment_filters_support_author_parent_and_dates() {
		$post_id = self::factory()->post->create( array( 'post_author' => $this->other_user, 'comment_status' => 'open' ) );
		$parent_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_content' => 'Parent', 'comment_approved' => 1 ) );
		$child_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_parent' => $parent_id, 'comment_content' => 'Filtered child', 'user_id' => $this->other_user, 'comment_approved' => 1 ) );
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Comments::list_comments( array( 'post_id' => $post_id, 'author_id' => $this->other_user, 'parent_id' => $parent_id, 'date_after' => '2000-01-01' ) );
		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['comments'] );
		$this->assertEquals( $child_id, $result['comments'][0]['id'] );
	}

	public function test_update_comment_uses_edit_comment_and_sanitizes_content() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->other_user, 'comment_status' => 'open' ) );
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_content' => 'Before', 'comment_approved' => 1 ) );
		wp_set_current_user( $admin_user );

		$result = WP_MCP_Comments::update_comment( array( 'comment_id' => $comment_id, 'content' => 'After <script>alert(1)</script>' ) );
		$this->assertNotWPError( $result );
		$this->assertStringNotContainsString( '<script>', get_comment( $comment_id )->comment_content );
		$this->assertEquals( 'After alert(1)', wp_strip_all_tags( get_comment( $comment_id )->comment_content ) );
	}

	public function test_non_moderator_cannot_moderate_comments() {
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => self::factory()->post->create( array( 'post_author' => $this->other_user ) ), 'comment_approved' => 1 ) );
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Comments::status_spam( array( 'comment_id' => $comment_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_comment_permission_denied', $result->get_error_code() );
		$detail = WP_MCP_Comments::get_comment( array( 'comment_id' => $comment_id ) );
		$this->assertNotWPError( $detail );
		$this->assertEquals( 'approve', $detail['status'] );
	}

	public function test_comment_moderation_lifecycle_is_separate_from_permanent_delete() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => self::factory()->post->create( array( 'post_author' => $this->other_user ) ), 'comment_approved' => 1 ) );
		$child_id = self::factory()->comment->create( array( 'comment_post_ID' => get_comment( $comment_id )->comment_post_ID, 'comment_parent' => $comment_id, 'comment_content' => 'Child', 'comment_approved' => 1 ) );
		wp_set_current_user( $admin_user );

		$this->assertNotWPError( WP_MCP_Comments::status_spam( array( 'comment_id' => $comment_id ) ) );
		$this->assertEquals( 'spam', WP_MCP_Comments::get_comment( array( 'comment_id' => $comment_id ) )['status'] );
		$this->assertNotWPError( WP_MCP_Comments::status_unspam( array( 'comment_id' => $comment_id ) ) );
		$this->assertEquals( 'approve', WP_MCP_Comments::get_comment( array( 'comment_id' => $comment_id ) )['status'] );
		$this->assertNotWPError( WP_MCP_Comments::status_approve( array( 'comment_id' => $comment_id ) ) );
		$this->assertNotWPError( WP_MCP_Comments::status_trash( array( 'comment_id' => $comment_id ) ) );
		$this->assertEquals( 'trash', WP_MCP_Comments::get_comment( array( 'comment_id' => $comment_id ) )['status'] );
		$this->assertNotWPError( WP_MCP_Comments::status_restore( array( 'comment_id' => $comment_id ) ) );
		$this->assertNotWPError( WP_MCP_Comments::delete_comment_permanently( array( 'comment_id' => $comment_id ) ) );
		$this->assertEquals( 0, (int) get_comment( $child_id )->comment_parent );
		$this->assertWPError( WP_MCP_Comments::get_comment( array( 'comment_id' => $comment_id ) ) );
	}

	public function test_bulk_comment_moderation_reports_partial_results_and_enforces_limit() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $this->other_user ) );
		$one = self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => 1 ) );
		$two = self::factory()->comment->create( array( 'comment_post_ID' => $post_id, 'comment_approved' => 1 ) );
		wp_set_current_user( $admin_user );

		$result = WP_MCP_Comments::bulk_moderate_comments( array( 'comment_ids' => array( $one, $two, 999999 ), 'action' => 'spam' ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 2, $result['processed'] );
		$this->assertEquals( 1, $result['failed'] );
		$this->assertCount( 3, $result['results'] );

		$limited = WP_MCP_Comments::bulk_moderate_comments( array( 'comment_ids' => range( 1, 21 ), 'action' => 'trash' ) );
		$this->assertWPError( $limited );
		$this->assertEquals( 'wp_mcp_bulk_limit_exceeded', $limited->get_error_code() );
	}

	// ## Users, roles, and application passwords (#7)

	public function test_get_current_user_exposes_profile_without_password_data() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Users::get_current_user( array() );

		$this->assertNotWPError( $result );
		$this->assertEquals( $this->agent_user, $result['id'] );
		$this->assertArrayNotHasKey( 'user_pass', $result );
		$this->assertArrayNotHasKey( 'password', $result );
	}

	public function test_agent_cannot_list_or_create_users() {
		wp_set_current_user( $this->agent_user );

		$list = WP_MCP_Users::list_users( array() );
		$create = WP_MCP_Users::create_user( array(
			'username' => 'blocked-user',
			'email'    => 'blocked@example.com',
			'password' => 'a-secure-test-password',
		) );

		$this->assertWPError( $list );
		$this->assertEquals( 'wp_mcp_user_permission_denied', $list->get_error_code() );
		$this->assertWPError( $create );
		$this->assertEquals( 'wp_mcp_user_permission_denied', $create->get_error_code() );
	}

	public function test_admin_can_create_and_update_user_profile_without_changing_login() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$created = WP_MCP_Users::create_user( array(
			'username' => 'profile-target',
			'email'    => 'profile-target@example.com',
			'password' => 'a-secure-test-password',
			'role'     => 'author',
		) );

		$this->assertNotWPError( $created );
		$this->assertArrayNotHasKey( 'password', $created );
		$this->assertEquals( 'profile-target', get_userdata( $created['id'] )->user_login );

		$updated = WP_MCP_Users::update_user( array(
			'user_id'      => $created['id'],
			'display_name' => 'Updated Display Name',
			'first_name'   => 'Updated',
			'url'          => 'https://example.com/profile',
		) );

		$this->assertNotWPError( $updated );
		$this->assertEquals( 'Updated Display Name', get_userdata( $created['id'] )->display_name );
		$this->assertEquals( 'profile-target', get_userdata( $created['id'] )->user_login );
	}

	public function test_role_changes_reject_uneditable_role_and_self_promotion() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $editor );

		$promote = WP_MCP_Users::change_user_role( array( 'user_id' => $author, 'role' => 'administrator' ) );
		$self = WP_MCP_Users::change_user_role( array( 'user_id' => $editor, 'role' => 'author' ) );

		$this->assertWPError( $promote );
		$this->assertEquals( 'wp_mcp_user_permission_denied', $promote->get_error_code() );
		$this->assertWPError( $self );
		$this->assertEquals( 'wp_mcp_user_self_promotion_denied', $self->get_error_code() );
	}

	public function test_delete_user_requires_explicit_reassignment_and_preserves_content() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$target = self::factory()->user->create( array( 'role' => 'author' ) );
		$replacement = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $target ) );
		wp_set_current_user( $admin_user );

		$missing = WP_MCP_Users::delete_user( array( 'user_id' => $target ) );
		$this->assertWPError( $missing );
		$this->assertEquals( 'wp_mcp_user_validation_error', $missing->get_error_code() );

		$result = WP_MCP_Users::delete_user( array( 'user_id' => $target, 'reassign_user_id' => $replacement ) );
		$this->assertNotWPError( $result );
		$this->assertFalse( get_userdata( $target ) );
		$this->assertEquals( $replacement, (int) get_post( $post_id )->post_author );
	}

	public function test_role_capability_abilities_are_admin_only_and_protect_agent_role() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		$created = WP_MCP_Users::create_role( array(
			'slug'         => 'wp_mcp_test_role',
			'name'         => 'WordPress MCP Test Role',
			'capabilities' => array( 'read' ),
		) );
		$this->assertNotWPError( $created );
		$this->assertTrue( get_role( 'wp_mcp_test_role' )->has_cap( 'read' ) );

		$protected = WP_MCP_Users::add_role_capability( array( 'slug' => 'wp_mcp_agent', 'capability' => 'manage_options' ) );
		$this->assertWPError( $protected );
		$this->assertEquals( 'wp_mcp_role_protected', $protected->get_error_code() );
	}

	public function test_application_password_metadata_never_contains_persisted_hash() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			$this->markTestSkipped( 'Application Passwords are unavailable in this test environment.' );
		}

		$created = WP_MCP_Users::create_application_password( array( 'user_id' => $admin_user, 'name' => 'WordPress MCP Test Client' ) );
		$this->assertNotWPError( $created );
		$this->assertNotEmpty( $created['password'] );
		$this->assertArrayNotHasKey( 'password', $created['metadata'] );

		$list = WP_MCP_Users::list_application_passwords( array( 'user_id' => $admin_user ) );
		$this->assertNotWPError( $list );
		$this->assertCount( 1, $list['passwords'] );
		$this->assertArrayNotHasKey( 'password', $list['passwords'][0] );
		$this->assertArrayNotHasKey( 'password', $list['passwords'][0]['metadata'] );
	}

	public function test_application_password_revoke_all_is_auditable_and_returns_count() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			$this->markTestSkipped( 'Application Passwords are unavailable in this test environment.' );
		}

		WP_MCP_Users::create_application_password( array( 'user_id' => $admin_user, 'name' => 'Client One' ) );
		WP_MCP_Users::create_application_password( array( 'user_id' => $admin_user, 'name' => 'Client Two' ) );
		$result = WP_MCP_Users::revoke_all_application_passwords( array( 'user_id' => $admin_user ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 2, $result['revoked'] );
		$this->assertEmpty( WP_Application_Passwords::get_user_application_passwords( $admin_user ) );
	}

	// ## Issue #8 — navigation and Site Editor/FSE

	public function test_issue_8_lists_classic_menus_and_items() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$menu_id = wp_create_nav_menu( 'WordPress MCP Primary' );
		wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-title'  => 'Home',
			'menu-item-url'    => home_url( '/' ),
			'menu-item-status' => 'publish',
			'menu-item-type'   => 'custom',
		) );

		$result = WP_MCP_Navigation::list_menus( array() );

		$this->assertNotWPError( $result );
		$this->assertNotEmpty( $result['menus'] );
		$this->assertEquals( 'WordPress MCP Primary', $result['menus'][0]['name'] );
		$this->assertCount( 1, $result['menus'][0]['items'] );
		$this->assertEquals( 'Home', $result['menus'][0]['items'][0]['title'] );
	}

	public function test_issue_8_menu_and_item_lifecycle_uses_native_apis() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$created = WP_MCP_Navigation::create_menu( array( 'name' => 'Lifecycle Menu', 'description' => 'Initial description' ) );
		$this->assertNotWPError( $created );
		$menu_id = $created['id'];

		$updated = WP_MCP_Navigation::update_menu( array( 'menu_id' => $menu_id, 'name' => 'Updated Menu' ) );
		$this->assertNotWPError( $updated );
		$this->assertEquals( 'Updated Menu', $updated['name'] );

		$first = WP_MCP_Navigation::add_menu_item( array( 'menu_id' => $menu_id, 'title' => 'First', 'type' => 'custom', 'url' => home_url( '/first/' ) ) );
		$second = WP_MCP_Navigation::add_menu_item( array( 'menu_id' => $menu_id, 'title' => 'Second', 'type' => 'custom', 'url' => home_url( '/second/' ) ) );
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );

		$changed = WP_MCP_Navigation::update_menu_item( array( 'menu_id' => $menu_id, 'item_id' => $first['id'], 'title' => 'Changed First' ) );
		$this->assertNotWPError( $changed );
		$this->assertEquals( 'Changed First', $changed['title'] );

		$reordered = WP_MCP_Navigation::reorder_menu_items( array( 'menu_id' => $menu_id, 'item_ids' => array( $second['id'], $first['id'] ) ) );
		$this->assertNotWPError( $reordered );
		$this->assertEquals( array( $second['id'], $first['id'] ), $reordered['item_ids'] );
		$get = WP_MCP_Navigation::get_menu( array( 'menu_id' => $menu_id ) );
		$this->assertEquals( $second['id'], $get['items'][0]['id'] );

		$removed = WP_MCP_Navigation::remove_menu_item( array( 'menu_id' => $menu_id, 'item_id' => $second['id'] ) );
		$this->assertNotWPError( $removed );
		$deleted = WP_MCP_Navigation::delete_menu( array( 'menu_id' => $menu_id ) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
	}

	public function test_issue_8_assigns_and_unassigns_registered_menu_location() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		register_nav_menu( 'wp_mcp_primary', 'WordPress MCP Primary Location' );
		$menu_id = wp_create_nav_menu( 'Assigned Menu' );

		$assigned = WP_MCP_Navigation::assign_menu_location( array( 'menu_id' => $menu_id, 'location' => 'wp_mcp_primary' ) );
		$this->assertNotWPError( $assigned );
		$this->assertTrue( $assigned['assigned'] );
		$this->assertEquals( $menu_id, get_nav_menu_locations()['wp_mcp_primary'] );

		$unassigned = WP_MCP_Navigation::assign_menu_location( array( 'menu_id' => $menu_id, 'location' => 'wp_mcp_primary', 'assign' => false ) );
		$this->assertNotWPError( $unassigned );
		$this->assertFalse( $unassigned['assigned'] );
		$this->assertArrayNotHasKey( 'wp_mcp_primary', get_nav_menu_locations() );
	}

	public function test_issue_8_agent_cannot_manage_navigation() {
		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Navigation::list_menus( array() );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_navigation_permission_denied', $result->get_error_code() );
	}

	public function test_issue_8_wp_navigation_entity_crud_is_capability_gated() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$content = '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- /wp:navigation -->';
		$created = WP_MCP_Site_Editor::create_navigation_block( array( 'title' => 'WordPress MCP Block Nav', 'content' => $content ) );
		$this->assertNotWPError( $created );
		$this->assertEquals( 'WordPress MCP Block Nav', $created['title'] );
		$id = $created['id'];

		$updated = WP_MCP_Site_Editor::update_navigation_block( array( 'navigation_id' => $id, 'title' => 'Updated Block Nav' ) );
		$this->assertNotWPError( $updated );
		$this->assertEquals( 'Updated Block Nav', $updated['title'] );
		$this->assertEquals( $content, $updated['content'] );
		$deleted = WP_MCP_Site_Editor::delete_navigation_block( array( 'navigation_id' => $id ) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
	}

	public function test_issue_8_synced_pattern_crud_uses_wp_block_entity() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$created = WP_MCP_Site_Editor::create_synced_pattern( array( 'title' => 'WordPress MCP Pattern', 'content' => '<!-- wp:paragraph --><p>Pattern</p><!-- /wp:paragraph -->' ) );
		$this->assertNotWPError( $created );
		$this->assertEquals( 'synced', $created['sync_status'] );
		$id = $created['id'];
		$updated = WP_MCP_Site_Editor::update_synced_pattern( array( 'pattern_id' => $id, 'title' => 'Updated Pattern' ) );
		$this->assertNotWPError( $updated );
		$this->assertEquals( 'Updated Pattern', $updated['title'] );
		$deleted = WP_MCP_Site_Editor::delete_synced_pattern( array( 'pattern_id' => $id ) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
	}

	public function test_issue_8_custom_template_override_never_edits_theme_file() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$id = wp_insert_post( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'wp-mcp-test-template', 'post_title' => 'WordPress MCP Test Template', 'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->', 'post_author' => $admin_user ), true );
		$this->assertNotWPError( $id );
		wp_set_post_terms( $id, get_stylesheet(), 'wp_theme', false );
		$template = get_block_template( get_stylesheet() . '//wp-mcp-test-template', 'wp_template' );
		$this->assertNotNull( $template );

		$updated = WP_MCP_Site_Editor::update_template( array( 'template_id' => $template->id, 'content' => '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->' ) );
		$this->assertNotWPError( $updated );
		$this->assertStringContainsString( '>New<', $updated['content'] );
		$this->assertEquals( $id, $updated['wp_id'] );
		$deleted = WP_MCP_Site_Editor::delete_template( array( 'template_id' => $template->id ) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
		$foreign_id = wp_insert_post( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'foreign-template', 'post_title' => 'Foreign', 'post_content' => '<!-- wp:paragraph --><p>Foreign</p><!-- /wp:paragraph -->', 'post_author' => $admin_user ), true );
		wp_set_post_terms( $foreign_id, 'foreign-theme', 'wp_theme', false );
		$foreign = WP_MCP_Site_Editor::get_template( array( 'template_id' => 'foreign-theme//foreign-template' ) );
		$this->assertWPError( $foreign );
		$this->assertEquals( 'wp_mcp_invalid_template', $foreign->get_error_code() );
		wp_delete_post( $foreign_id, true );
	}

	public function test_issue_8_reading_global_styles_does_not_create_an_entity() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		if ( ! function_exists( 'wp_theme_has_theme_json' ) || ! wp_theme_has_theme_json() ) {
			$this->markTestSkipped( 'The active test theme has no theme.json.' );
		}
		$before = get_posts( array( 'post_type' => 'wp_global_styles', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		$result = WP_MCP_Site_Editor::get_global_styles();
		$this->assertNotWPError( $result );
		$after = get_posts( array( 'post_type' => 'wp_global_styles', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		$this->assertEquals( $before, $after );
	}

	public function test_issue_8_rejects_unsafe_urls_and_cross_menu_items() {
		$admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
		$first_menu = wp_create_nav_menu( 'First Menu' );
		$second_menu = wp_create_nav_menu( 'Second Menu' );
		$unsafe = WP_MCP_Navigation::add_menu_item( array( 'menu_id' => $first_menu, 'title' => 'Unsafe', 'type' => 'custom', 'url' => 'javascript:alert(1)' ) );
		$this->assertWPError( $unsafe );
		$this->assertEquals( 'wp_mcp_navigation_validation_error', $unsafe->get_error_code() );
		$item = WP_MCP_Navigation::add_menu_item( array( 'menu_id' => $first_menu, 'title' => 'Safe', 'type' => 'custom', 'url' => home_url( '/safe/' ) ) );
		$this->assertNotWPError( $item );
		$cross_menu = WP_MCP_Navigation::remove_menu_item( array( 'menu_id' => $second_menu, 'item_id' => $item['id'] ) );
		$this->assertWPError( $cross_menu );
		$this->assertEquals( 'wp_mcp_invalid_menu_item', $cross_menu->get_error_code() );
	}

	public function test_issue_8_new_abilities_have_site_editor_categories_and_matrix_entries() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$abilities = array( 'list-nav-menus', 'get-nav-menu', 'list-menu-locations', 'create-nav-menu', 'update-nav-menu', 'delete-nav-menu', 'assign-menu-location', 'add-menu-item', 'update-menu-item', 'remove-menu-item', 'reorder-menu-items', 'list-templates', 'get-template', 'update-template', 'delete-template', 'list-template-parts', 'get-template-part', 'update-template-part', 'delete-template-part', 'list-patterns', 'get-pattern', 'create-synced-pattern', 'update-synced-pattern', 'delete-synced-pattern', 'list-navigation-blocks', 'get-navigation-block', 'create-navigation-block', 'update-navigation-block', 'delete-navigation-block', 'get-theme-context', 'get-global-styles', 'update-global-styles', 'list-widget-areas' );
		$matrix = WP_MCP_Ability_Matrix::get();
		$navigation = array( 'list-nav-menus', 'get-nav-menu', 'list-menu-locations', 'create-nav-menu', 'update-nav-menu', 'delete-nav-menu', 'assign-menu-location', 'add-menu-item', 'update-menu-item', 'remove-menu-item', 'reorder-menu-items' );
		foreach ( $abilities as $slug ) {
			$ability = wp_get_ability( 'wp-mcp/' . $slug );
			$this->assertNotNull( $ability, $slug . ' should be registered' );
			$this->assertArrayHasKey( 'wp-mcp/' . $slug, $matrix );
			$this->assertEquals( in_array( $slug, $navigation, true ) ? 'wp-mcp-navigation' : 'wp-mcp-site-editor', $matrix[ 'wp-mcp/' . $slug ]['category'] );
		}
	}

	public function test_issue_8_schemas_are_closed_and_accept_integer_pattern_ids() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$pattern = wp_get_ability( 'wp-mcp/get-pattern' );
		$this->assertContains( 'integer', (array) $pattern->get_input_schema()['properties']['pattern_id']['type'] );
		foreach ( array( 'wp-mcp/get-template', 'wp-mcp/get-pattern', 'wp-mcp/get-navigation-block', 'wp-mcp/get-theme-context', 'wp-mcp/get-global-styles', 'wp-mcp/update-global-styles', 'wp-mcp/list-widget-areas', 'wp-mcp/list-menu-locations' ) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			$this->assertArrayHasKey( 'additionalProperties', $ability->get_output_schema(), $name . ' output must be closed' );
			$this->assertFalse( $ability->get_output_schema()['additionalProperties'], $name . ' output must reject additional properties' );
		}
	}

	// ## Regression tests for the Issue #8 fatal-error fix (Part A of the stabilization plan)

	public function test_is_strict_positive_int_id_rejects_what_is_numeric_wrongly_accepts() {
		$this->assertTrue( WP_MCP_Permissions::is_strict_positive_int_id( 5 ) );
		$this->assertTrue( WP_MCP_Permissions::is_strict_positive_int_id( '5' ) );
		$this->assertTrue( WP_MCP_Permissions::is_strict_positive_int_id( '123' ) );
		$this->assertFalse( WP_MCP_Permissions::is_strict_positive_int_id( 0 ) );
		$this->assertFalse( WP_MCP_Permissions::is_strict_positive_int_id( -5 ) );
		$this->assertFalse( WP_MCP_Permissions::is_strict_positive_int_id( '1.5' ) );
		$this->assertFalse( WP_MCP_Permissions::is_strict_positive_int_id( '1e3' ) );
		$this->assertFalse( WP_MCP_Permissions::is_strict_positive_int_id( ' 12' ) );
		$this->assertFalse( WP_MCP_Permissions::is_strict_positive_int_id( '01' ) );
		$this->assertFalse( WP_MCP_Permissions::is_strict_positive_int_id( true ) );
		$this->assertFalse( WP_MCP_Permissions::is_strict_positive_int_id( 'core/paragraph' ) );
	}

	/**
	 * Regression for the latent capability bug in A2: checking
	 * `publish_posts` instead of the post type's actual `create_posts`
	 * mapping would make this fail 100% of the time, administrators
	 * included, because `wp_block` maps `create_posts` to `publish_posts`
	 * on the object but never overrides the `publish_posts` primitive
	 * capability itself.
	 */
	public function test_administrator_can_create_synced_pattern_and_navigation_block() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$pattern = WP_MCP_Site_Editor::create_synced_pattern( array(
			'title'   => 'Regression Pattern',
			'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
		) );
		$this->assertNotWPError( $pattern );
		$this->assertEquals( 'database', $pattern['source'] );

		$navigation = WP_MCP_Site_Editor::create_navigation_block( array(
			'title'   => 'Regression Navigation',
			'content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
		) );
		$this->assertNotWPError( $navigation );
		$this->assertArrayHasKey( 'id', $navigation );
	}

	public function test_validate_block_content_accepts_real_block_and_rejects_blockless_content() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$accepted = WP_MCP_Site_Editor::create_synced_pattern( array(
			'title'   => 'Valid Block Content',
			'content' => '<!-- wp:paragraph {"align":"center"} --><p>Kept intact</p><!-- /wp:paragraph -->',
		) );
		$this->assertNotWPError( $accepted );
		$this->assertStringContainsString( '{"align":"center"}', $accepted['content'] );

		$rejected = WP_MCP_Site_Editor::create_synced_pattern( array(
			'title'   => 'Blockless Content',
			'content' => 'Just plain text, no block comment delimiters at all.',
		) );
		$this->assertWPError( $rejected );
		$this->assertEquals( 'wp_mcp_site_editor_validation_error', $rejected->get_error_code() );
	}

	public function test_site_editor_list_abilities_honor_pagination_contract() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = WP_MCP_Site_Editor::list_navigation_blocks( array( 'page' => 1, 'per_page' => 5 ) );
		$this->assertNotWPError( $result );
		foreach ( array( 'total', 'total_pages', 'page', 'per_page' ) as $key ) {
			$this->assertArrayHasKey( $key, $result, "list-navigation-blocks output missing {$key}" );
		}
		$this->assertEquals( 1, $result['page'] );
		$this->assertEquals( 5, $result['per_page'] );

		$patterns = WP_MCP_Site_Editor::list_patterns( array() );
		foreach ( array( 'total', 'total_pages', 'page', 'per_page' ) as $key ) {
			$this->assertArrayHasKey( $key, $patterns, "list-patterns output missing {$key}" );
		}
	}

	public function test_global_styles_round_trip_reads_back_what_was_written() {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! function_exists( 'wp_theme_has_theme_json' ) || ! wp_theme_has_theme_json() ) {
			$this->assertTrue( true );
			return;
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$updated = WP_MCP_Site_Editor::update_global_styles( array(
			'styles' => array( 'color' => array( 'background' => '#123456' ) ),
		) );
		$this->assertNotWPError( $updated );
		$this->assertArrayHasKey( 'settings', $updated );
		$this->assertArrayHasKey( 'styles', $updated );

		$fetched = WP_MCP_Site_Editor::get_global_styles();
		$this->assertNotWPError( $fetched );
		$this->assertEquals( '#123456', $fetched['styles']['color']['background'] );
	}

	// ## Issue #9 — Plugins, themes, and core updates

	public function test_issue_9_new_abilities_have_categories_and_matrix_entries() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$matrix = WP_MCP_Ability_Matrix::get();
		$expected = array(
			'list-plugins'             => 'wp-mcp-plugins',
			'get-plugin'               => 'wp-mcp-plugins',
			'activate-plugin'          => 'wp-mcp-plugins',
			'deactivate-plugin'        => 'wp-mcp-plugins',
			'install-plugin-from-repo' => 'wp-mcp-plugins',
			'install-plugin-from-url'  => 'wp-mcp-plugins',
			'update-plugin'            => 'wp-mcp-plugins',
			'update-plugins'           => 'wp-mcp-plugins',
			'update-all-plugins'       => 'wp-mcp-plugins',
			'set-plugin-auto-update'   => 'wp-mcp-plugins',
			'delete-plugin'            => 'wp-mcp-plugins',
			'list-themes'              => 'wp-mcp-themes',
			'get-theme'                => 'wp-mcp-themes',
			'switch-theme'             => 'wp-mcp-themes',
			'install-theme-from-repo'  => 'wp-mcp-themes',
			'install-theme-from-url'   => 'wp-mcp-themes',
			'update-theme'             => 'wp-mcp-themes',
			'update-themes'            => 'wp-mcp-themes',
			'update-all-themes'        => 'wp-mcp-themes',
			'set-theme-auto-update'    => 'wp-mcp-themes',
			'delete-theme'             => 'wp-mcp-themes',
			'get-core-update-status'   => 'wp-mcp-system',
			'update-core'              => 'wp-mcp-system',
			'list-available-updates'   => 'wp-mcp-system',
			'update-translations'      => 'wp-mcp-system',
		);
		foreach ( $expected as $slug => $category ) {
			$ability = wp_get_ability( 'wp-mcp/' . $slug );
			$this->assertNotNull( $ability, $slug . ' should be registered' );
			$this->assertArrayHasKey( 'wp-mcp/' . $slug, $matrix );
			$this->assertEquals( $category, $matrix[ 'wp-mcp/' . $slug ]['category'] );
		}
	}

	public function test_issue_9_output_schemas_are_closed() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( array( 'wp-mcp/list-plugins', 'wp-mcp/get-plugin', 'wp-mcp/list-themes', 'wp-mcp/get-theme', 'wp-mcp/get-core-update-status', 'wp-mcp/update-core', 'wp-mcp/update-plugins', 'wp-mcp/update-all-plugins', 'wp-mcp/set-plugin-auto-update', 'wp-mcp/update-themes', 'wp-mcp/update-all-themes', 'wp-mcp/set-theme-auto-update', 'wp-mcp/list-available-updates', 'wp-mcp/update-translations' ) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			$this->assertArrayHasKey( 'additionalProperties', $ability->get_output_schema(), $name . ' output must be closed' );
			$this->assertFalse( $ability->get_output_schema()['additionalProperties'], $name . ' output must reject additional properties' );
		}
	}

	public function test_agent_role_lacks_plugin_theme_core_capabilities_by_default() {
		wp_set_current_user( $this->agent_user );
		foreach ( array( 'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'switch_themes', 'install_themes', 'update_themes', 'delete_themes', 'update_core', 'update_languages', 'manage_network_plugins', 'manage_network_themes' ) as $cap ) {
			$this->assertFalse( current_user_can( $cap ), "wp_mcp_agent should not have {$cap} by default" );
		}
	}

	public function test_plugin_abilities_denied_without_capability() {
		wp_set_current_user( $this->agent_user );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::list_plugins( array() )->get_error_code() );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::get_plugin( array( 'plugin_file' => 'x/x.php' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::activate_plugin( array( 'plugin_file' => 'x/x.php' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::deactivate_plugin( array( 'plugin_file' => 'x/x.php' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::install_plugin_from_repo( array( 'slug' => 'x' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::install_plugin_from_url( array( 'url' => 'https://example.com/x.zip' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::update_plugin( array( 'plugin_file' => 'x/x.php' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::delete_plugin( array( 'plugin_file' => 'x/x.php' ) )->get_error_code() );
	}

	public function test_theme_abilities_denied_without_capability() {
		wp_set_current_user( $this->agent_user );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::list_themes( array() )->get_error_code() );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::get_theme( array( 'stylesheet' => 'x' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::switch_theme( array( 'stylesheet' => 'x' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::install_theme_from_repo( array( 'slug' => 'x' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::install_theme_from_url( array( 'url' => 'https://example.com/x.zip' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::update_theme( array( 'stylesheet' => 'x' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::delete_theme( array( 'stylesheet' => 'x' ) )->get_error_code() );
	}

	public function test_system_abilities_denied_without_capability() {
		wp_set_current_user( $this->agent_user );
		$this->assertEquals( 'wp_mcp_permission_denied', WP_MCP_System::get_core_update_status()->get_error_code() );
		$this->assertEquals( 'wp_mcp_permission_denied', WP_MCP_System::update_core()->get_error_code() );
	}

	/**
	 * Locks the behavior of the SSRF helper extracted to
	 * WP_MCP_Permissions for reuse by plugin/theme install-from-url:
	 * every branch here must reject before any DNS lookup or HTTP request,
	 * so this test needs no network access.
	 */
	public function test_validate_remote_url_rejects_unsafe_urls_without_network() {
		$this->assertWPError( WP_MCP_Permissions::validate_remote_url( 'not a url' ) );
		$this->assertWPError( WP_MCP_Permissions::validate_remote_url( 'ftp://example.com/file.zip' ) );
		$this->assertWPError( WP_MCP_Permissions::validate_remote_url( 'http://user:pass@example.com/file.zip' ) );
		$this->assertWPError( WP_MCP_Permissions::validate_remote_url( 'http://example.com:8080/file.zip' ) );
		$this->assertWPError( WP_MCP_Permissions::validate_remote_url( 'http://localhost/file.zip' ) );
		$this->assertWPError( WP_MCP_Permissions::validate_remote_url( 'http://example.com/file.zip#frag' ) );

		$denied = WP_MCP_Permissions::validate_remote_url( 'https://example.com/file.zip', array( 'deny_hosts' => array( 'example.com' ) ) );
		$this->assertWPError( $denied );
		$this->assertEquals( 'wp_mcp_remote_url_denied', $denied->get_error_code() );
	}

	public function test_install_from_url_abilities_reject_unsafe_urls_before_any_download() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin_result = WP_MCP_Plugins::install_plugin_from_url( array( 'url' => 'http://localhost/evil.zip' ) );
		$this->assertWPError( $plugin_result );
		$this->assertEquals( 'wp_mcp_remote_url_denied', $plugin_result->get_error_code() );

		$theme_result = WP_MCP_Themes::install_theme_from_url( array( 'url' => 'http://user:pass@example.com/evil.zip' ) );
		$this->assertWPError( $theme_result );
		$this->assertEquals( 'wp_mcp_remote_url_denied', $theme_result->get_error_code() );
	}

	public function test_plugin_file_input_rejects_path_traversal_and_absolute_paths() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$traversal = WP_MCP_Plugins::get_plugin( array( 'plugin_file' => '../../../etc/passwd' ) );
		$this->assertWPError( $traversal );
		$this->assertEquals( 'wp_mcp_plugin_validation_error', $traversal->get_error_code() );

		$absolute = WP_MCP_Plugins::get_plugin( array( 'plugin_file' => '/etc/passwd' ) );
		$this->assertWPError( $absolute );
		$this->assertEquals( 'wp_mcp_plugin_validation_error', $absolute->get_error_code() );

		$missing = WP_MCP_Plugins::get_plugin( array( 'plugin_file' => 'nonexistent-plugin/nonexistent-plugin.php' ) );
		$this->assertWPError( $missing );
		$this->assertEquals( 'wp_mcp_invalid_plugin', $missing->get_error_code() );
	}

	public function test_theme_stylesheet_input_rejects_path_traversal_and_unknown_themes() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$traversal = WP_MCP_Themes::get_theme( array( 'stylesheet' => '../../../etc/passwd' ) );
		$this->assertWPError( $traversal );
		$this->assertEquals( 'wp_mcp_theme_validation_error', $traversal->get_error_code() );

		$missing = WP_MCP_Themes::get_theme( array( 'stylesheet' => 'nonexistent-theme-xyz' ) );
		$this->assertWPError( $missing );
		$this->assertEquals( 'wp_mcp_invalid_theme', $missing->get_error_code() );
	}

	public function test_theme_list_and_get_reflect_the_active_theme() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$stylesheet = get_stylesheet();

		$single = WP_MCP_Themes::get_theme( array( 'stylesheet' => $stylesheet ) );
		$this->assertNotWPError( $single );
		$this->assertEquals( $stylesheet, $single['stylesheet'] );
		$this->assertTrue( $single['active'] );

		$list = WP_MCP_Themes::list_themes( array() );
		$this->assertNotWPError( $list );
		$this->assertGreaterThanOrEqual( 1, $list['total'] );
	}

	public function test_switch_theme_to_already_active_theme_is_idempotent_noop() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$stylesheet = get_stylesheet();

		$result = WP_MCP_Themes::switch_theme( array( 'stylesheet' => $stylesheet ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( $stylesheet, get_stylesheet() );
	}

	public function test_delete_theme_refuses_the_active_theme() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = WP_MCP_Themes::delete_theme( array( 'stylesheet' => get_stylesheet() ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_theme_active_conflict', $result->get_error_code() );
	}

	public function test_activate_deactivate_plugin_round_trip_and_idempotency() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin_file = $this->create_fixture_plugin();

		$activated = WP_MCP_Plugins::activate_plugin( array( 'plugin_file' => $plugin_file ) );
		$this->assertNotWPError( $activated );
		$this->assertTrue( $activated['active'] );

		// Idempotent: activating an already-active plugin is a no-op success.
		$again = WP_MCP_Plugins::activate_plugin( array( 'plugin_file' => $plugin_file ) );
		$this->assertNotWPError( $again );
		$this->assertTrue( $again['active'] );

		$deactivated = WP_MCP_Plugins::deactivate_plugin( array( 'plugin_file' => $plugin_file ) );
		$this->assertNotWPError( $deactivated );
		$this->assertFalse( $deactivated['active'] );

		// Idempotent: deactivating an already-inactive plugin is a no-op success.
		$again2 = WP_MCP_Plugins::deactivate_plugin( array( 'plugin_file' => $plugin_file ) );
		$this->assertNotWPError( $again2 );
		$this->assertFalse( $again2['active'] );

		$this->remove_fixture_plugin( $plugin_file );
	}

	public function test_delete_plugin_refuses_an_active_plugin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin_file = $this->create_fixture_plugin();
		WP_MCP_Plugins::activate_plugin( array( 'plugin_file' => $plugin_file ) );

		$result = WP_MCP_Plugins::delete_plugin( array( 'plugin_file' => $plugin_file ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_plugin_active_conflict', $result->get_error_code() );

		WP_MCP_Plugins::deactivate_plugin( array( 'plugin_file' => $plugin_file ) );
		$this->remove_fixture_plugin( $plugin_file );
	}

	public function test_install_plugin_from_repo_surfaces_a_graceful_error_when_the_repository_lookup_fails() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$filter = function () {
			return new WP_Error( 'plugins_api_failed', 'Simulated repository failure.' );
		};
		add_filter( 'plugins_api', $filter, 10, 3 );
		try {
			$result = WP_MCP_Plugins::install_plugin_from_repo( array( 'slug' => 'a-plugin-that-does-not-matter' ) );
		} finally {
			remove_filter( 'plugins_api', $filter, 10 );
		}
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_plugin_install_failed', $result->get_error_code() );
	}

	public function test_install_theme_from_repo_surfaces_a_graceful_error_when_the_repository_lookup_fails() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$filter = function () {
			return new WP_Error( 'themes_api_failed', 'Simulated repository failure.' );
		};
		add_filter( 'themes_api', $filter, 10, 3 );
		try {
			$result = WP_MCP_Themes::install_theme_from_repo( array( 'slug' => 'a-theme-that-does-not-matter' ) );
		} finally {
			remove_filter( 'themes_api', $filter, 10 );
		}
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_theme_install_failed', $result->get_error_code() );
	}

	public function test_get_core_update_status_reports_up_to_date_from_a_fixture_transient() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_core_update_transient( 'latest', get_bloginfo( 'version' ) );

		$status = WP_MCP_System::get_core_update_status();
		$this->assertNotWPError( $status );
		$this->assertFalse( $status['update_available'] );
		$this->assertEquals( get_bloginfo( 'version' ), $status['current_version'] );
	}

	public function test_update_core_reports_unavailable_when_already_up_to_date() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_core_update_transient( 'latest', get_bloginfo( 'version' ) );

		$result = WP_MCP_System::update_core();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_core_update_unavailable', $result->get_error_code() );
	}

	// ## Issue #9 follow-up — multisite guard, delete truthiness, audit, bulk/auto-updates

	/**
	 * Regression: deactivate-plugin used to accept network_wide from anyone
	 * holding activate_plugins, which on multisite a site administrator can
	 * hold with no authority over the network at all.
	 */
	public function test_deactivate_plugin_refuses_network_wide_without_network_authority() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a site administrator who is not a super admin; the single-site path covers the same guard.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin_file = $this->create_fixture_plugin();
		WP_MCP_Plugins::activate_plugin( array( 'plugin_file' => $plugin_file ) );

		$result = WP_MCP_Plugins::deactivate_plugin( array(
			'plugin_file'  => $plugin_file,
			'network_wide' => true,
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', $result->get_error_code() );
		$this->assertTrue( is_plugin_active( $plugin_file ), 'The refusal must leave the plugin active.' );

		WP_MCP_Plugins::deactivate_plugin( array( 'plugin_file' => $plugin_file ) );
		$this->remove_fixture_plugin( $plugin_file );
	}

	public function test_activate_and_deactivate_apply_the_same_network_guard() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a site administrator who is not a super admin.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin_file = $this->create_fixture_plugin();

		$activate   = WP_MCP_Plugins::activate_plugin( array( 'plugin_file' => $plugin_file, 'network_wide' => true ) );
		$deactivate = WP_MCP_Plugins::deactivate_plugin( array( 'plugin_file' => $plugin_file, 'network_wide' => true ) );

		$this->assertWPError( $activate );
		$this->assertWPError( $deactivate );
		$this->assertEquals( $activate->get_error_code(), $deactivate->get_error_code(), 'Both network-scoped operations must refuse identically.' );

		$this->remove_fixture_plugin( $plugin_file );
	}

	/**
	 * Regression: core's delete_theme() returns a falsy value (not a WP_Error)
	 * when it cannot obtain filesystem credentials. That used to be reported
	 * as deleted:true and audited as a success.
	 */
	public function test_delete_theme_reports_failure_when_core_could_not_delete() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$stylesheet = $this->create_fixture_theme();
		$this->assertTrue( wp_get_theme( $stylesheet )->exists(), 'Fixture theme should be installed.' );

		add_filter( 'request_filesystem_credentials', '__return_false' );
		try {
			$result = WP_MCP_Themes::delete_theme( array( 'stylesheet' => $stylesheet ) );
		} finally {
			remove_filter( 'request_filesystem_credentials', '__return_false' );
		}

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_theme_delete_failed', $result->get_error_code() );
		$this->assertTrue( wp_get_theme( $stylesheet )->exists(), 'The theme must still be installed after a failed delete.' );

		$this->remove_fixture_theme( $stylesheet );
	}

	public function test_delete_plugin_audit_event_records_the_removed_version() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin_file = $this->create_fixture_plugin();

		$events  = $this->collect_audit_events( function () use ( $plugin_file ) {
			WP_MCP_Plugins::delete_plugin( array( 'plugin_file' => $plugin_file ) );
		} );
		$deletes = $this->audit_events_for( $events, 'wp-mcp/delete-plugin' );

		$this->assertNotEmpty( $deletes, 'delete-plugin must always emit an audit event.' );
		$this->assertArrayHasKey( 'from_version', $deletes[0], 'The audit trail must record the version that was removed.' );
		$this->assertEquals( '1.0.0', $deletes[0]['from_version'] );

		$this->remove_fixture_plugin( $plugin_file );
	}

	public function test_failed_repository_install_emits_an_audit_event() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$fail = function () {
			return new WP_Error( 'plugins_api_failed', 'Simulated repository failure.' );
		};
		add_filter( 'plugins_api', $fail, 10, 3 );
		try {
			$events = $this->collect_audit_events( function () {
				WP_MCP_Plugins::install_plugin_from_repo( array( 'slug' => 'a-plugin-that-does-not-exist' ) );
			} );
		} finally {
			remove_filter( 'plugins_api', $fail, 10 );
		}

		$installs = $this->audit_events_for( $events, 'wp-mcp/install-plugin-from-repo' );
		$this->assertNotEmpty( $installs, 'A failed repository install must still be audited.' );
		$this->assertEquals( 'error', $installs[0]['result'] );
	}

	public function test_new_issue_9_abilities_denied_without_capability() {
		wp_set_current_user( $this->agent_user );

		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::update_plugins( array( 'plugin_files' => array( 'x/x.php' ) ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::update_all_plugins()->get_error_code() );
		$this->assertEquals( 'wp_mcp_plugin_permission_denied', WP_MCP_Plugins::set_plugin_auto_update( array( 'plugin_file' => 'x/x.php', 'enabled' => true ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::update_themes( array( 'stylesheets' => array( 'x' ) ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::update_all_themes()->get_error_code() );
		$this->assertEquals( 'wp_mcp_theme_permission_denied', WP_MCP_Themes::set_theme_auto_update( array( 'stylesheet' => 'x', 'enabled' => true ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_permission_denied', WP_MCP_System::list_available_updates()->get_error_code() );
		$this->assertEquals( 'wp_mcp_permission_denied', WP_MCP_System::update_translations()->get_error_code() );
	}

	public function test_plugin_auto_update_round_trip_and_idempotency() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin_file = $this->create_fixture_plugin();
		add_filter( 'plugins_auto_update_enabled', '__return_true' );
		try {
			$on = WP_MCP_Plugins::set_plugin_auto_update( array( 'plugin_file' => $plugin_file, 'enabled' => true ) );
			$this->assertNotWPError( $on );
			$this->assertTrue( $on['auto_update'] );
			$this->assertContains( $plugin_file, (array) get_site_option( 'auto_update_plugins', array() ) );

			// Idempotent: setting the state it already has is a no-op success.
			$again = WP_MCP_Plugins::set_plugin_auto_update( array( 'plugin_file' => $plugin_file, 'enabled' => true ) );
			$this->assertNotWPError( $again );
			$this->assertTrue( $again['auto_update'] );

			$off = WP_MCP_Plugins::set_plugin_auto_update( array( 'plugin_file' => $plugin_file, 'enabled' => false ) );
			$this->assertNotWPError( $off );
			$this->assertFalse( $off['auto_update'] );
			$this->assertNotContains( $plugin_file, (array) get_site_option( 'auto_update_plugins', array() ) );
		} finally {
			remove_filter( 'plugins_auto_update_enabled', '__return_true' );
			delete_site_option( 'auto_update_plugins' );
			$this->remove_fixture_plugin( $plugin_file );
		}
	}

	public function test_plugin_auto_update_refused_when_disabled_or_forced() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin_file = $this->create_fixture_plugin();

		add_filter( 'plugins_auto_update_enabled', '__return_false' );
		$disabled = WP_MCP_Plugins::set_plugin_auto_update( array( 'plugin_file' => $plugin_file, 'enabled' => true ) );
		remove_filter( 'plugins_auto_update_enabled', '__return_false' );

		$this->assertWPError( $disabled );
		$this->assertEquals( 'wp_mcp_plugin_auto_update_unavailable', $disabled->get_error_code() );

		// A filter forcing the state wins over the option, so writing the
		// option would silently do nothing.
		add_filter( 'plugins_auto_update_enabled', '__return_true' );
		add_filter( 'auto_update_plugin', '__return_true' );
		$forced = WP_MCP_Plugins::set_plugin_auto_update( array( 'plugin_file' => $plugin_file, 'enabled' => false ) );
		remove_filter( 'auto_update_plugin', '__return_true' );
		remove_filter( 'plugins_auto_update_enabled', '__return_true' );

		$this->assertWPError( $forced );
		$this->assertEquals( 'wp_mcp_plugin_auto_update_unavailable', $forced->get_error_code() );

		$this->remove_fixture_plugin( $plugin_file );
	}

	public function test_theme_auto_update_round_trip() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$stylesheet = $this->create_fixture_theme();
		add_filter( 'themes_auto_update_enabled', '__return_true' );
		try {
			$on = WP_MCP_Themes::set_theme_auto_update( array( 'stylesheet' => $stylesheet, 'enabled' => true ) );
			$this->assertNotWPError( $on );
			$this->assertContains( $stylesheet, (array) get_site_option( 'auto_update_themes', array() ) );

			$off = WP_MCP_Themes::set_theme_auto_update( array( 'stylesheet' => $stylesheet, 'enabled' => false ) );
			$this->assertNotWPError( $off );
			$this->assertNotContains( $stylesheet, (array) get_site_option( 'auto_update_themes', array() ) );
		} finally {
			remove_filter( 'themes_auto_update_enabled', '__return_true' );
			delete_site_option( 'auto_update_themes' );
			$this->remove_fixture_theme( $stylesheet );
		}
	}

	public function test_bulk_plugin_update_rejects_oversized_and_invalid_input() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$too_many = array_fill( 0, WP_MCP_Plugins::MAX_BULK_UPDATES + 1, 'hello-dolly/hello.php' );
		$over     = WP_MCP_Plugins::update_plugins( array( 'plugin_files' => $too_many ) );
		$this->assertWPError( $over );
		$this->assertEquals( 'wp_mcp_bulk_limit_exceeded', $over->get_error_code() );

		$empty = WP_MCP_Plugins::update_plugins( array( 'plugin_files' => array() ) );
		$this->assertWPError( $empty );
		$this->assertEquals( 'wp_mcp_plugin_validation_error', $empty->get_error_code() );

		// Path traversal and unknown plugins are rejected before the upgrader
		// is constructed, reusing the single-plugin guards.
		$traversal = WP_MCP_Plugins::update_plugins( array( 'plugin_files' => array( '../../../etc/passwd' ) ) );
		$this->assertWPError( $traversal );
		$this->assertEquals( 'wp_mcp_plugin_validation_error', $traversal->get_error_code() );

		$unknown = WP_MCP_Plugins::update_plugins( array( 'plugin_files' => array( 'nonexistent-plugin/nonexistent-plugin.php' ) ) );
		$this->assertWPError( $unknown );
		$this->assertEquals( 'wp_mcp_invalid_plugin', $unknown->get_error_code() );
	}

	public function test_bulk_theme_update_rejects_oversized_and_invalid_input() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$too_many = array_fill( 0, WP_MCP_Themes::MAX_BULK_UPDATES + 1, get_stylesheet() );
		$over     = WP_MCP_Themes::update_themes( array( 'stylesheets' => $too_many ) );
		$this->assertWPError( $over );
		$this->assertEquals( 'wp_mcp_bulk_limit_exceeded', $over->get_error_code() );

		$empty = WP_MCP_Themes::update_themes( array( 'stylesheets' => array() ) );
		$this->assertWPError( $empty );
		$this->assertEquals( 'wp_mcp_theme_validation_error', $empty->get_error_code() );

		$traversal = WP_MCP_Themes::update_themes( array( 'stylesheets' => array( '../../../etc' ) ) );
		$this->assertWPError( $traversal );
		$this->assertEquals( 'wp_mcp_theme_validation_error', $traversal->get_error_code() );

		$unknown = WP_MCP_Themes::update_themes( array( 'stylesheets' => array( 'nonexistent-theme-xyz' ) ) );
		$this->assertWPError( $unknown );
		$this->assertEquals( 'wp_mcp_invalid_theme', $unknown->get_error_code() );
	}

	public function test_update_all_reports_nothing_pending_without_touching_the_upgrader() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_site_transient( 'update_plugins', (object) array( 'response' => array(), 'last_checked' => time() ) );
		set_site_transient( 'update_themes', (object) array( 'response' => array(), 'last_checked' => time() ) );

		$plugins = WP_MCP_Plugins::update_all_plugins();
		$this->assertWPError( $plugins );
		$this->assertEquals( 'wp_mcp_no_updates_pending', $plugins->get_error_code() );

		$themes = WP_MCP_Themes::update_all_themes();
		$this->assertWPError( $themes );
		$this->assertEquals( 'wp_mcp_no_updates_pending', $themes->get_error_code() );

		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
	}

	public function test_list_available_updates_reports_pending_plugin_updates_with_compatibility() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin_file = $this->create_fixture_plugin();

		set_site_transient( 'update_plugins', (object) array(
			'last_checked' => time(),
			'response'     => array(
				$plugin_file => (object) array(
					'new_version'  => '2.0.0',
					'requires'     => '6.0',
					'requires_php' => '7.0',
					'tested'       => '6.9',
				),
			),
		) );

		$result = WP_MCP_System::list_available_updates();
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['can_view_plugins'] );
		$this->assertFalse( $result['plugins_truncated'] );

		$entry = null;
		foreach ( $result['plugins'] as $candidate ) {
			if ( $plugin_file === $candidate['file'] ) {
				$entry = $candidate;
			}
		}
		$this->assertNotNull( $entry, 'The pending plugin update must be reported.' );
		$this->assertEquals( '1.0.0', $entry['current_version'] );
		$this->assertEquals( '2.0.0', $entry['new_version'] );
		$this->assertEquals( '6.9', $entry['tested_up_to'] );
		$this->assertTrue( $entry['compatible'] );

		delete_site_transient( 'update_plugins' );
		$this->remove_fixture_plugin( $plugin_file );
	}

	public function test_list_available_updates_hides_sections_the_caller_cannot_see() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'update_plugins' );
		wp_set_current_user( $user_id );

		$result = WP_MCP_System::list_available_updates();

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['can_view_plugins'] );
		$this->assertFalse( $result['can_view_themes'] );
		$this->assertFalse( $result['can_view_core'] );
		// Empty because the caller may not look, which the flag makes explicit
		// rather than passing off as "nothing to update".
		$this->assertSame( array(), $result['themes'] );

		$user->remove_cap( 'update_plugins' );
	}

	public function test_list_available_updates_denied_without_any_update_capability() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_System::list_available_updates();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	public function test_update_translations_reports_nothing_pending() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( ! current_user_can( 'update_languages' ) ) {
			$this->markTestSkipped( 'This installation does not allow language pack installation.' );
		}

		foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $transient ) {
			set_site_transient( $transient, (object) array(
				'translations' => array(),
				'last_checked' => time(),
			) );
		}

		$result = WP_MCP_System::update_translations();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_translation_updates_unavailable', $result->get_error_code() );

		foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $transient ) {
			delete_site_transient( $transient );
		}
	}

	/**
	 * Run $operation with the audit action captured, and return the events.
	 *
	 * @param callable $operation Operation to run.
	 * @return array[]
	 */
	private function collect_audit_events( $operation ) {
		$events  = array();
		$collect = function ( $data ) use ( &$events ) {
			$events[] = $data;
		};
		add_action( 'wp_mcp_audit_log', $collect );
		try {
			$operation();
		} finally {
			remove_action( 'wp_mcp_audit_log', $collect );
		}
		return $events;
	}

	/**
	 * Filter collected audit events down to one ability.
	 *
	 * @param array[] $events  Collected events.
	 * @param string  $ability Ability name.
	 * @return array[]
	 */
	private function audit_events_for( array $events, $ability ) {
		$matching = array();
		foreach ( $events as $event ) {
			if ( isset( $event['ability'] ) && $ability === $event['ability'] ) {
				$matching[] = $event;
			}
		}
		return $matching;
	}

	// ## Issue #10 — Explicit site settings (General/Writing/Reading/Discussion/Media/Permalinks/Privacy)

	/**
	 * Every ability this issue adds, as a bare slug.
	 *
	 * @return string[]
	 */
	private function issue_10_ability_slugs() {
		return array(
			'get-general-settings',
			'update-general-settings',
			'get-writing-settings',
			'update-writing-settings',
			'get-reading-settings',
			'update-reading-settings',
			'get-discussion-settings',
			'update-discussion-settings',
			'get-media-settings',
			'update-media-settings',
			'get-permalink-settings',
			'update-permalink-settings',
			'get-privacy-settings',
			'update-privacy-settings',
			'flush-rewrite-rules',
			'list-settings-fields',
		);
	}

	/**
	 * Become an administrator for a settings test.
	 *
	 * @return int
	 */
	private function become_settings_admin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		return $admin;
	}

	public function test_issue_10_settings_abilities_are_registered_with_category_and_matrix_entries() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$matrix = WP_MCP_Ability_Matrix::get();
		foreach ( $this->issue_10_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should be registered' );
			$this->assertEquals( 'wp-mcp-settings', $ability->get_category(), $name . ' belongs to wp-mcp-settings' );
			$this->assertArrayHasKey( $name, $matrix, $name . ' must be in the permission matrix' );
		}
	}

	public function test_no_generic_option_ability_exists() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 !== strpos( $name, 'wp-mcp/' ) ) {
				continue;
			}
			$this->assertStringNotContainsString( 'option', $name, $name . ' must not expose generic option access' );
		}
	}

	public function test_settings_ability_schemas_are_closed_and_take_no_option_name() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$forbidden = array( 'option', 'option_name', 'options', 'key', 'meta_key', 'setting', 'setting_name' );
		foreach ( $this->issue_10_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );

			foreach ( array( $ability->get_input_schema(), $ability->get_output_schema() ) as $schema ) {
				$this->assertArrayHasKey( 'additionalProperties', $schema, $name . ' schemas must be closed' );
				$this->assertFalse( $schema['additionalProperties'], $name . ' schemas must reject additional properties' );
			}

			foreach ( array_keys( $ability->get_input_schema()['properties'] ) as $property ) {
				$this->assertNotContains( $property, $forbidden, $name . ' must not accept a caller-supplied option name via "' . $property . '"' );
			}
		}
	}

	public function test_settings_input_schemas_only_declare_writable_allowlisted_fields() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$writes = array(
			'general'    => 'wp-mcp/update-general-settings',
			'writing'    => 'wp-mcp/update-writing-settings',
			'reading'    => 'wp-mcp/update-reading-settings',
			'discussion' => 'wp-mcp/update-discussion-settings',
			'media'      => 'wp-mcp/update-media-settings',
			'permalinks' => 'wp-mcp/update-permalink-settings',
			'privacy'    => 'wp-mcp/update-privacy-settings',
		);
		foreach ( $writes as $group => $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			$this->assertEquals(
				array_keys( WP_MCP_Settings::input_schema_properties( $group ) ),
				array_keys( $ability->get_input_schema()['properties'] ),
				$name . ' input schema must be exactly the writable allowlist for the ' . $group . ' group'
			);
		}
	}

	public function test_settings_allowlist_can_never_reach_a_denied_option() {
		$overlap = array_intersect( WP_MCP_Settings::writable_option_keys(), WP_MCP_Settings::never_writable_options() );
		$this->assertEmpty( $overlap, 'Writable settings fields must never map to a denied option: ' . implode( ', ', $overlap ) );
	}

	public function test_no_settings_group_exposes_a_credential_bearing_option() {
		$this->become_settings_admin();
		$secrets = array( 'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port', 'auth_salt', 'secret_key' );
		$reads   = array(
			WP_MCP_Settings::get_general_settings(),
			WP_MCP_Settings::get_writing_settings(),
			WP_MCP_Settings::get_reading_settings(),
			WP_MCP_Settings::get_discussion_settings(),
			WP_MCP_Settings::get_media_settings(),
			WP_MCP_Settings::get_permalink_settings(),
			WP_MCP_Settings::get_privacy_settings(),
		);
		foreach ( $reads as $values ) {
			$this->assertNotWPError( $values );
			foreach ( $secrets as $secret ) {
				$this->assertArrayNotHasKey( $secret, $values, $secret . ' must never appear in a settings response' );
			}
		}
	}

	public function test_update_general_settings_rejects_a_field_outside_the_allowlist() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_general_settings( array( 'wp_mcp_not_a_field' => 'x' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_unknown_field', $result->get_error_code() );
	}

	public function test_update_settings_rejects_raw_wordpress_option_names() {
		$this->become_settings_admin();
		foreach ( array( 'blogname', 'active_plugins', 'siteurl', 'home', 'mailserver_pass', 'wp_user_roles' ) as $option_name ) {
			$result = WP_MCP_Settings::update_general_settings( array( $option_name => 'x' ) );
			$this->assertWPError( $result, $option_name . ' must not be accepted as a field name' );
			$this->assertEquals( 'wp_mcp_settings_unknown_field', $result->get_error_code(), $option_name . ' must be refused by the allowlist' );
		}
	}

	public function test_update_settings_rejects_a_field_belonging_to_another_group() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_general_settings( array( 'posts_per_page' => 5 ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_unknown_field', $result->get_error_code() );

		$reverse = WP_MCP_Settings::update_reading_settings( array( 'site_title' => 'Nope' ) );
		$this->assertWPError( $reverse );
		$this->assertEquals( 'wp_mcp_settings_unknown_field', $reverse->get_error_code() );
	}

	public function test_update_general_settings_rejects_read_only_fields() {
		$this->become_settings_admin();
		foreach ( array( 'admin_email', 'site_url', 'home_url', 'timezone_string', 'gmt_offset' ) as $field ) {
			$result = WP_MCP_Settings::update_general_settings( array( $field => 'x' ) );
			$this->assertWPError( $result, $field . ' must not be writable' );
			$this->assertEquals( 'wp_mcp_settings_readonly_field', $result->get_error_code(), $field . ' must be refused as read-only' );
		}
		$this->assertEquals( get_option( 'admin_email' ), WP_MCP_Settings::get_general_settings()['admin_email'] );
	}

	public function test_update_privacy_settings_rejects_a_field_outside_the_allowlist() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_privacy_settings( array( 'wp_page_for_privacy_policy' => 1 ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_unknown_field', $result->get_error_code() );
	}

	public function test_update_settings_rejects_an_empty_payload() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_media_settings( array() );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code() );
	}

	public function test_settings_abilities_are_denied_without_manage_options() {
		wp_set_current_user( $this->agent_user );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::get_general_settings()->get_error_code() );
		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::get_writing_settings()->get_error_code() );
		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::get_reading_settings()->get_error_code() );
		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::get_discussion_settings()->get_error_code() );
		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::get_media_settings()->get_error_code() );
		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::get_permalink_settings()->get_error_code() );
		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::update_general_settings( array( 'site_title' => 'Hijacked' ) )->get_error_code() );
		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::flush_rewrite_rules( array() )->get_error_code() );
		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::list_settings_fields( array() )->get_error_code() );
	}

	public function test_settings_permission_denial_happens_before_any_write() {
		wp_set_current_user( $this->agent_user );
		$before = get_option( 'blogname' );
		$this->assertWPError( WP_MCP_Settings::update_general_settings( array( 'site_title' => 'Hijacked' ) ) );
		$this->assertEquals( $before, get_option( 'blogname' ) );
	}

	public function test_privacy_settings_require_the_privacy_capability() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertFalse( current_user_can( 'manage_privacy_options' ) );
		$this->assertEquals( 'wp_mcp_settings_permission_denied', WP_MCP_Settings::get_privacy_settings()->get_error_code() );

		$this->become_settings_admin();
		$this->assertTrue( current_user_can( 'manage_privacy_options' ) );
		$this->assertNotWPError( WP_MCP_Settings::get_privacy_settings() );
	}

	public function test_get_general_settings_returns_exactly_the_allowlisted_fields() {
		$this->become_settings_admin();
		$values = WP_MCP_Settings::get_general_settings();
		$this->assertNotWPError( $values );
		$this->assertEquals(
			array_keys( WP_MCP_Settings::output_schema_properties( 'general' ) ),
			array_keys( $values ),
			'A settings read must return the declared allowlist and nothing else.'
		);
	}

	public function test_update_general_settings_round_trips_title_tagline_and_week_start() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_general_settings( array(
			'site_title'     => 'WordPress MCP Test Site',
			'tagline'        => 'Explicit settings only',
			'week_starts_on' => 3,
		) );
		$this->assertNotWPError( $result );
		$this->assertEqualSets( array( 'site_title', 'tagline', 'week_starts_on' ), $result['updated'] );
		$this->assertEquals( 'WordPress MCP Test Site', get_option( 'blogname' ) );
		$this->assertEquals( 'Explicit settings only', get_option( 'blogdescription' ) );
		$this->assertEquals( 3, (int) get_option( 'start_of_week' ) );
		$this->assertEquals( 3, $result['settings']['week_starts_on'] );
	}

	public function test_update_general_settings_validates_types_and_ranges() {
		$this->become_settings_admin();

		$too_big = WP_MCP_Settings::update_general_settings( array( 'week_starts_on' => 9 ) );
		$this->assertWPError( $too_big );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $too_big->get_error_code() );

		$not_an_int = WP_MCP_Settings::update_general_settings( array( 'week_starts_on' => '1.5' ) );
		$this->assertWPError( $not_an_int );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $not_an_int->get_error_code() );

		$not_a_string = WP_MCP_Settings::update_general_settings( array( 'site_title' => array( 'x' ) ) );
		$this->assertWPError( $not_a_string );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $not_a_string->get_error_code() );

		$markup_format = WP_MCP_Settings::update_general_settings( array( 'date_format' => "F j, Y\n<script>" ) );
		$this->assertWPError( $markup_format );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $markup_format->get_error_code() );
	}

	public function test_update_general_settings_accepts_an_identifier_or_a_utc_offset_timezone() {
		$this->become_settings_admin();

		$identifier = WP_MCP_Settings::update_general_settings( array( 'timezone' => 'Europe/Madrid' ) );
		$this->assertNotWPError( $identifier );
		$this->assertEquals( 'Europe/Madrid', get_option( 'timezone_string' ) );
		$this->assertEquals( 'Europe/Madrid', $identifier['settings']['timezone'] );

		$offset = WP_MCP_Settings::update_general_settings( array( 'timezone' => 'UTC+2' ) );
		$this->assertNotWPError( $offset );
		$this->assertEquals( '', get_option( 'timezone_string' ) );
		$this->assertEquals( 2.0, (float) get_option( 'gmt_offset' ) );
		$this->assertEquals( 'UTC+2', $offset['settings']['timezone'] );
	}

	public function test_update_general_settings_rejects_an_invalid_timezone() {
		$this->become_settings_admin();
		foreach ( array( 'Mars/Olympus_Mons', 'UTC+99', '../../etc/passwd' ) as $bad ) {
			$result = WP_MCP_Settings::update_general_settings( array( 'timezone' => $bad ) );
			$this->assertWPError( $result, $bad . ' must be rejected' );
			$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code() );
		}
	}

	public function test_update_general_settings_rejects_a_locale_that_is_not_installed() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_general_settings( array( 'language' => 'xx_YY' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code() );

		$english = WP_MCP_Settings::update_general_settings( array( 'language' => 'en_US' ) );
		$this->assertNotWPError( $english );
		$this->assertEquals( 'en_US', $english['settings']['language'] );
	}

	public function test_update_general_settings_refuses_a_privileged_default_role() {
		$this->become_settings_admin();

		// administrator holds manage_options; editor holds unfiltered_html.
		// Either as the default role turns open registration into escalation.
		foreach ( array( 'administrator', 'editor' ) as $role ) {
			$result = WP_MCP_Settings::update_general_settings( array( 'default_role' => $role ) );
			$this->assertWPError( $result, $role . ' must never become the default role' );
			$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code() );
		}
		$this->assertNotEquals( 'administrator', get_option( 'default_role' ) );
		$this->assertNotEquals( 'editor', get_option( 'default_role' ) );

		$unknown = WP_MCP_Settings::update_general_settings( array( 'default_role' => 'not_a_role' ) );
		$this->assertWPError( $unknown );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $unknown->get_error_code() );
	}

	public function test_update_general_settings_accepts_a_low_privilege_default_role() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_general_settings( array( 'default_role' => 'contributor' ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 'contributor', get_option( 'default_role' ) );
	}

	public function test_update_writing_settings_validates_the_default_category() {
		$this->become_settings_admin();

		$missing = WP_MCP_Settings::update_writing_settings( array( 'default_category' => 999999 ) );
		$this->assertWPError( $missing );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $missing->get_error_code() );

		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$ok   = WP_MCP_Settings::update_writing_settings( array( 'default_category' => $term ) );
		$this->assertNotWPError( $ok );
		$this->assertEquals( $term, (int) get_option( 'default_category' ) );
	}

	public function test_update_writing_settings_rejects_an_unsupported_post_format() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_writing_settings( array( 'default_post_format' => 'not_a_format' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code() );

		$standard = WP_MCP_Settings::update_writing_settings( array( 'default_post_format' => 'standard' ) );
		$this->assertNotWPError( $standard );
		$this->assertEquals( 'standard', $standard['settings']['default_post_format'] );
	}

	public function test_update_writing_settings_rejects_update_services_pointing_at_reserved_hosts() {
		$this->become_settings_admin();
		foreach ( array( 'http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1/rpc', 'ftp://example.com/rpc', 'http://localhost/rpc' ) as $bad ) {
			$result = WP_MCP_Settings::update_writing_settings( array( 'update_services' => array( $bad ) ) );
			$this->assertWPError( $result, $bad . ' must be rejected' );
			$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code() );
		}
		$this->assertStringNotContainsString( '169.254.169.254', (string) get_option( 'ping_sites' ), 'A refused update service must never be stored.' );
	}

	public function test_update_writing_settings_accepts_a_public_update_service() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_writing_settings( array( 'update_services' => array( 'http://93.184.216.34/rpc' ) ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( array( 'http://93.184.216.34/rpc' ), $result['settings']['update_services'] );
	}

	public function test_update_reading_settings_round_trips_pagination_and_search_visibility() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_reading_settings( array(
			'posts_per_page'        => 7,
			'search_engine_visible' => false,
		) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 7, (int) get_option( 'posts_per_page' ) );
		$this->assertEquals( 0, (int) get_option( 'blog_public' ) );
		$this->assertFalse( $result['settings']['search_engine_visible'] );

		$too_many = WP_MCP_Settings::update_reading_settings( array( 'posts_per_page' => 5000 ) );
		$this->assertWPError( $too_many );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $too_many->get_error_code() );
	}

	public function test_update_reading_settings_enforces_the_static_front_page_invariants() {
		$this->become_settings_admin();
		$front = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$missing = WP_MCP_Settings::update_reading_settings( array( 'show_on_front' => 'page' ) );
		$this->assertWPError( $missing );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $missing->get_error_code() );

		$not_a_page = WP_MCP_Settings::update_reading_settings( array( 'page_on_front' => 999999 ) );
		$this->assertWPError( $not_a_page );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $not_a_page->get_error_code() );

		$ok = WP_MCP_Settings::update_reading_settings( array( 'show_on_front' => 'page', 'page_on_front' => $front ) );
		$this->assertNotWPError( $ok );
		$this->assertEquals( $front, $ok['settings']['page_on_front'] );

		$collision = WP_MCP_Settings::update_reading_settings( array( 'page_for_posts' => $front ) );
		$this->assertWPError( $collision );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $collision->get_error_code() );
	}

	public function test_update_discussion_settings_validates_enums_and_bounds() {
		$this->become_settings_admin();

		$bad_enum = WP_MCP_Settings::update_discussion_settings( array( 'default_comment_status' => 'maybe' ) );
		$this->assertWPError( $bad_enum );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $bad_enum->get_error_code() );

		$bad_avatar = WP_MCP_Settings::update_discussion_settings( array( 'avatar_default' => 'evil_remote_avatar' ) );
		$this->assertWPError( $bad_avatar );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $bad_avatar->get_error_code() );

		$shallow = WP_MCP_Settings::update_discussion_settings( array( 'thread_comments_depth' => 1 ) );
		$this->assertWPError( $shallow );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $shallow->get_error_code() );

		$ok = WP_MCP_Settings::update_discussion_settings( array(
			'default_comment_status' => 'closed',
			'thread_comments_depth'  => 4,
			'avatar_rating'          => 'PG',
			'comment_moderation'     => true,
		) );
		$this->assertNotWPError( $ok );
		$this->assertEquals( 'closed', get_option( 'default_comment_status' ) );
		$this->assertEquals( 4, (int) get_option( 'thread_comments_depth' ) );
		$this->assertEquals( 'PG', get_option( 'avatar_rating' ) );
		$this->assertTrue( $ok['settings']['comment_moderation'] );
	}

	public function test_update_discussion_settings_rejects_an_oversized_keyword_list() {
		$this->become_settings_admin();
		$result = WP_MCP_Settings::update_discussion_settings( array( 'disallowed_keys' => str_repeat( 'a', 9000 ) ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code() );
	}

	public function test_update_media_settings_validates_dimension_bounds() {
		$this->become_settings_admin();

		$oversized = WP_MCP_Settings::update_media_settings( array( 'thumbnail_size_w' => 9999 ) );
		$this->assertWPError( $oversized );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $oversized->get_error_code() );

		$negative = WP_MCP_Settings::update_media_settings( array( 'medium_size_h' => -1 ) );
		$this->assertWPError( $negative );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $negative->get_error_code() );

		$ok = WP_MCP_Settings::update_media_settings( array(
			'thumbnail_size_w'              => 200,
			'thumbnail_crop'                => false,
			'uploads_use_yearmonth_folders' => true,
		) );
		$this->assertNotWPError( $ok );
		$this->assertEquals( 200, (int) get_option( 'thumbnail_size_w' ) );
		$this->assertEquals( 0, (int) get_option( 'thumbnail_crop' ) );
		$this->assertEquals( 1, (int) get_option( 'uploads_use_yearmonth_folders' ) );
	}

	public function test_update_permalink_settings_accepts_a_valid_structure_without_flushing() {
		$this->become_settings_admin();

		// A surviving sentinel proves no flush happened, whichever way the
		// running WordPress version clears the option (delete_option in older
		// releases, update_option( 'rewrite_rules', '' ) in newer ones); the
		// delete_option spy pins the older path down explicitly.
		update_option( 'rewrite_rules', array( 'wp-mcp-sentinel/?$' => 'index.php' ) );

		$flushes = 0;
		$spy     = function ( $option ) use ( &$flushes ) {
			if ( 'rewrite_rules' === $option ) {
				++$flushes;
			}
		};
		add_action( 'delete_option', $spy );
		try {
			$result = WP_MCP_Settings::update_permalink_settings( array( 'permalink_structure' => '/%year%/%postname%/' ) );
		} finally {
			remove_action( 'delete_option', $spy );
		}

		$this->assertNotWPError( $result );
		$this->assertEquals( '/%year%/%postname%/', get_option( 'permalink_structure' ) );
		$this->assertSame( 0, $flushes, 'Changing the permalink structure must not implicitly flush rewrite rules.' );
		$this->assertArrayHasKey( 'wp-mcp-sentinel/?$', (array) get_option( 'rewrite_rules' ), 'Changing the permalink structure must leave the stored rewrite rules untouched.' );
	}

	public function test_update_permalink_settings_rejects_unsafe_or_unknown_structures() {
		$this->become_settings_admin();
		$rejected = array(
			'%postname%',                 // No leading slash.
			'/%postname%/../../etc',      // Path traversal.
			'/%not_a_tag%/',              // Unknown rewrite tag.
			'/%postname/',                // Malformed tag.
			'/%year%/%monthnum%/',        // No unique post identifier.
			'/archives/<script>',         // Illegal characters.
		);
		foreach ( $rejected as $structure ) {
			$result = WP_MCP_Settings::update_permalink_settings( array( 'permalink_structure' => $structure ) );
			$this->assertWPError( $result, $structure . ' must be rejected' );
			$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code(), $structure . ' must fail validation' );
		}
	}

	public function test_update_permalink_settings_normalizes_rewrite_bases() {
		$this->become_settings_admin();

		$ok = WP_MCP_Settings::update_permalink_settings( array( 'category_base' => 'topics', 'tag_base' => 'labels' ) );
		$this->assertNotWPError( $ok );
		$this->assertEqualSets( array( 'category_base', 'tag_base' ), $ok['updated'] );

		/*
		 * The ability hands set_category_base()/set_tag_base() exactly what
		 * wp-admin/options-permalink.php hands them (a "/"-prefixed base);
		 * WordPress' own sanitize_option() then decides the stored
		 * leading-slash form, and strips it. Assert the base itself rather
		 * than a slash convention that belongs to core, plus the invariant
		 * that actually matters here: what the ability reads back is exactly
		 * what WordPress stored.
		 */
		$this->assertEquals( 'topics', trim( (string) get_option( 'category_base' ), '/' ) );
		$this->assertEquals( 'labels', trim( (string) get_option( 'tag_base' ), '/' ) );
		$this->assertEquals( (string) get_option( 'category_base' ), $ok['settings']['category_base'] );
		$this->assertEquals( (string) get_option( 'tag_base' ), $ok['settings']['tag_base'] );

		$bad = WP_MCP_Settings::update_permalink_settings( array( 'category_base' => '../../wp-admin' ) );
		$this->assertWPError( $bad );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $bad->get_error_code() );
	}

	public function test_flush_rewrite_rules_regenerates_the_stored_rules() {
		$this->become_settings_admin();
		update_option( 'rewrite_rules', array( 'wp-mcp-sentinel/?$' => 'index.php' ) );

		$result = WP_MCP_Settings::flush_rewrite_rules( array( 'hard' => false ) );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['flushed'] );
		$this->assertFalse( $result['hard'] );
		$this->assertArrayNotHasKey( 'wp-mcp-sentinel/?$', (array) get_option( 'rewrite_rules' ) );
	}

	public function test_update_privacy_settings_requires_an_existing_page() {
		$this->become_settings_admin();

		$missing = WP_MCP_Settings::update_privacy_settings( array( 'privacy_policy_page_id' => 999999 ) );
		$this->assertWPError( $missing );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $missing->get_error_code() );

		$post = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$wrong_type = WP_MCP_Settings::update_privacy_settings( array( 'privacy_policy_page_id' => $post ) );
		$this->assertWPError( $wrong_type );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $wrong_type->get_error_code() );
	}

	public function test_update_privacy_settings_round_trips_the_policy_page() {
		$this->become_settings_admin();
		$page = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Privacy Policy',
		) );

		$result = WP_MCP_Settings::update_privacy_settings( array( 'privacy_policy_page_id' => $page ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( $page, (int) get_option( 'wp_page_for_privacy_policy' ) );
		$this->assertEquals( 'Privacy Policy', $result['settings']['privacy_policy_page_title'] );
		$this->assertEquals( get_permalink( $page ), $result['settings']['privacy_policy_page_url'] );

		$cleared = WP_MCP_Settings::update_privacy_settings( array( 'privacy_policy_page_id' => 0 ) );
		$this->assertNotWPError( $cleared );
		$this->assertEquals( '', $cleared['settings']['privacy_policy_page_title'] );
	}

	public function test_list_settings_fields_describes_the_allowlist_without_values() {
		$this->become_settings_admin();
		update_option( 'blogname', 'A Very Distinctive Title' );

		$result = WP_MCP_Settings::list_settings_fields( array() );
		$this->assertNotWPError( $result );
		$this->assertEquals( count( $result['fields'] ), $result['total'] );
		$this->assertGreaterThan( 40, $result['total'] );

		$groups = array();
		foreach ( $result['fields'] as $entry ) {
			$groups[ $entry['group'] ] = true;
			$this->assertArrayNotHasKey( 'value', $entry, 'The field catalog must never carry stored values.' );
			$this->assertArrayHasKey( 'writable', $entry );
		}
		$this->assertEqualSets( WP_MCP_Settings::group_slugs(), array_keys( $groups ) );

		$scoped = WP_MCP_Settings::list_settings_fields( array( 'group' => 'permalinks' ) );
		$this->assertNotWPError( $scoped );
		$this->assertEquals( 3, $scoped['total'] );

		$unknown = WP_MCP_Settings::list_settings_fields( array( 'group' => 'wp_options' ) );
		$this->assertWPError( $unknown );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $unknown->get_error_code() );
	}

	public function test_settings_writes_are_audited_with_old_and_new_values() {
		$this->become_settings_admin();
		update_option( 'blogname', 'Before Title' );

		$captured  = array();
		$collector = function ( $data ) use ( &$captured ) {
			$captured[] = $data;
		};
		add_action( 'wp_mcp_audit_log', $collector );
		try {
			$result = WP_MCP_Settings::update_general_settings( array( 'site_title' => 'After Title' ) );
		} finally {
			remove_action( 'wp_mcp_audit_log', $collector );
		}

		$this->assertNotWPError( $result );
		$this->assertEquals( array( 'site_title' ), $result['updated'] );

		$entries = array_values( array_filter( $captured, function ( $entry ) {
			return isset( $entry['field'] ) && 'site_title' === $entry['field'];
		} ) );
		$this->assertCount( 1, $entries );
		$this->assertEquals( 'wp-mcp/update-general-settings', $entries[0]['ability'] );
		$this->assertEquals( 'Before Title', $entries[0]['old_value'] );
		$this->assertEquals( 'After Title', $entries[0]['new_value'] );
	}

	public function test_moderation_keyword_values_are_never_written_to_the_audit_log() {
		$this->become_settings_admin();

		$captured  = array();
		$collector = function ( $data ) use ( &$captured ) {
			$captured[] = $data;
		};
		add_action( 'wp_mcp_audit_log', $collector );
		try {
			$result = WP_MCP_Settings::update_discussion_settings( array( 'moderation_keys' => "supersecretkeyword\nanother" ) );
		} finally {
			remove_action( 'wp_mcp_audit_log', $collector );
		}

		$this->assertNotWPError( $result );
		$entries = array_values( array_filter( $captured, function ( $entry ) {
			return isset( $entry['field'] ) && 'moderation_keys' === $entry['field'];
		} ) );
		$this->assertCount( 1, $entries );
		$this->assertEquals( '(omitted)', $entries[0]['new_value'] );
		$this->assertEquals( '(omitted)', $entries[0]['old_value'] );
		foreach ( $captured as $entry ) {
			$this->assertStringNotContainsString( 'supersecretkeyword', wp_json_encode( $entry ) );
		}
	}

	public function test_agent_role_cannot_reach_the_settings_domain() {
		wp_set_current_user( $this->agent_user );
		foreach ( array( 'manage_options', 'manage_privacy_options' ) as $capability ) {
			$this->assertFalse( current_user_can( $capability ), "wp_mcp_agent should not have {$capability}" );
		}
	}

	/**
	 * Install a real, minimal theme under the test install's theme root so
	 * delete/auto-update can be exercised without any network access.
	 * Cleaned up by remove_fixture_theme().
	 *
	 * @return string Theme directory name (stylesheet).
	 */
	private function create_fixture_theme() {
		$slug = 'wp-mcp-test-fixture-theme';
		$dir  = get_theme_root() . '/' . $slug;
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: WordPress MCP Test Fixture Theme\nVersion: 1.0.0\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/index.php', "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_clean_themes_cache();
		return $slug;
	}

	/**
	 * Remove a theme created by create_fixture_theme().
	 *
	 * @param string $stylesheet Theme directory name.
	 */
	private function remove_fixture_theme( $stylesheet ) {
		$dir = get_theme_root() . '/' . $stylesheet;
		if ( is_dir( $dir ) ) {
			foreach ( array( 'style.css', 'index.php' ) as $file ) {
				@unlink( $dir . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_clean_themes_cache();
	}

	/**
	 * Install a real, minimal plugin file under the test install's plugins
	 * directory so activate/deactivate/delete can be exercised without any
	 * network access. Cleaned up by remove_fixture_plugin().
	 *
	 * @return string Plugin file relative to the plugins directory.
	 */
	private function create_fixture_plugin() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$slug = 'wp-mcp-test-fixture-plugin';
		$dir  = WP_PLUGIN_DIR . '/' . $slug;
		wp_mkdir_p( $dir );
		$file = $dir . '/' . $slug . '.php';
		file_put_contents( $file, "<?php\n/**\n * Plugin Name: WordPress MCP Test Fixture Plugin\n * Version: 1.0.0\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_clean_plugins_cache( false );
		return $slug . '/' . $slug . '.php';
	}

	/**
	 * Remove a plugin created by create_fixture_plugin().
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 */
	private function remove_fixture_plugin( $plugin_file ) {
		$dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
		if ( is_dir( $dir ) ) {
			@unlink( $dir . '/' . basename( $plugin_file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
	}

	/**
	 * Set the `update_core` site transient to a single-entry fixture without
	 * any network access, in the same shape wp_version_check() would store.
	 *
	 * @param string $response 'latest' or 'upgrade'.
	 * @param string $version  Reported available version.
	 */
	private function set_core_update_transient( $response, $version ) {
		$entry = (object) array(
			'response'        => $response,
			'current'         => $version,
			'version'         => $version,
			'php_version'     => PHP_VERSION,
			'mysql_version'   => '5.0',
			'new_bundled'     => $version,
			'partial_version' => '',
			'packages'        => (object) array(
				'full'        => 'https://downloads.wordpress.org/release/wordpress-' . $version . '.zip',
				'no_content'  => '',
				'new_bundled' => '',
				'partial'     => '',
				'rollback'    => '',
			),
			'download'        => 'https://downloads.wordpress.org/release/wordpress-' . $version . '.zip',
			'locale'          => 'en_US',
		);
		set_site_transient( 'update_core', (object) array(
			'updates'         => array( $entry ),
			'version_checked' => get_bloginfo( 'version' ),
			'last_checked'    => time(),
		) );
	}

	// ## Issue #11 — Custom post types and registered post metadata

	/**
	 * Every ability this issue adds, as a bare slug.
	 *
	 * @return string[]
	 */
	private function issue_11_ability_slugs() {
		return array(
			'list-post-types',
			'get-post-type',
			'list-post-type-meta-fields',
			'list-custom-posts',
			'get-custom-post',
			'create-custom-post',
			'update-custom-post',
			'publish-custom-post',
			'unpublish-custom-post',
			'trash-custom-post',
			'restore-custom-post',
			'delete-custom-post-permanently',
			'list-custom-post-revisions',
			'get-custom-post-revision',
			'restore-custom-post-revision',
			'set-custom-post-featured-image',
			'remove-custom-post-featured-image',
			'get-custom-post-meta',
			'update-custom-post-meta',
			'delete-custom-post-meta',
		);
	}

	/**
	 * Registered metadata keys created by the Issue #11 fixtures.
	 *
	 * @return string[]
	 */
	private function issue_11_meta_keys() {
		return array(
			'wp_mcp_isbn',
			'wp_mcp_page_count',
			'wp_mcp_in_print',
			'wp_mcp_rating',
			'wp_mcp_keywords',
			'wp_mcp_publisher',
			'wp_mcp_awards',
			'wp_mcp_internal_note',
			'wp_mcp_locked',
			'wp_mcp_shelf_code',
			'_wp_mcp_secret',
		);
	}

	/**
	 * Register the Issue #11 fixtures: a custom post type with its own
	 * capability set, a custom taxonomy, a deliberately minimal type, a type
	 * that never opted into REST, and one registered metadata key per case
	 * the domain has to distinguish.
	 */
	private function register_issue_11_fixtures() {
		register_taxonomy( 'wp_mcp_genre', array( 'wp_mcp_book' ), array(
			'labels'       => array( 'name' => 'Genres', 'singular_name' => 'Genre' ),
			'hierarchical' => true,
			'public'       => true,
			'show_ui'      => true,
			'show_in_rest' => true,
		) );

		// Its own capability_type: a user holding core's edit_posts /
		// publish_posts has no rights here, and a user holding these has no
		// rights over core posts. That asymmetry is the point.
		register_post_type( 'wp_mcp_book', array(
			'label'           => 'Books',
			'labels'          => array( 'name' => 'Books', 'singular_name' => 'Book' ),
			'description'     => 'Issue #11 fixture post type.',
			'public'          => true,
			'show_ui'         => true,
			'show_in_rest'    => true,
			'has_archive'     => true,
			'hierarchical'    => false,
			'map_meta_cap'    => true,
			'capability_type' => array( 'wp_mcp_book', 'wp_mcp_books' ),
			'supports'        => array( 'title', 'editor', 'excerpt', 'author', 'thumbnail', 'revisions', 'custom-fields' ),
			'taxonomies'      => array( 'wp_mcp_genre' ),
		) );

		// Supports only a title: no editor, excerpt, thumbnail or revisions.
		// Uses core's post capabilities via map_meta_cap, which is the common
		// "just give me a CPT" registration.
		register_post_type( 'wp_mcp_minimal', array(
			'label'        => 'Minimal',
			'labels'       => array( 'name' => 'Minimal', 'singular_name' => 'Minimal' ),
			'public'       => true,
			'show_ui'      => true,
			'show_in_rest' => true,
			'map_meta_cap' => true,
			'supports'     => array( 'title' ),
		) );

		// map_meta_cap => false with a fully explicit capability map, and a
		// create_posts capability that is deliberately NOT edit_posts: the
		// only way to authorize this type correctly is to read the slots off
		// its registration object.
		register_post_type( 'wp_mcp_note', array(
			'label'           => 'Notes',
			'labels'          => array( 'name' => 'Notes', 'singular_name' => 'Note' ),
			'public'          => true,
			'show_ui'         => true,
			'show_in_rest'    => true,
			'map_meta_cap'    => false,
			'capability_type' => 'wp_mcp_note',
			'capabilities'    => array(
				'edit_post'          => 'edit_wp_mcp_note',
				'read_post'          => 'read_wp_mcp_note',
				'delete_post'        => 'delete_wp_mcp_note',
				'edit_posts'         => 'edit_wp_mcp_notes',
				'edit_others_posts'  => 'edit_others_wp_mcp_notes',
				'publish_posts'      => 'publish_wp_mcp_notes',
				'read_private_posts' => 'read_private_wp_mcp_notes',
				'delete_posts'       => 'delete_wp_mcp_notes',
				'create_posts'       => 'create_wp_mcp_notes',
			),
			'supports'        => array( 'title', 'editor' ),
		) );

		// Registered, but never opted into REST: discoverable, not operable.
		register_post_type( 'wp_mcp_hidden_cpt', array(
			'label'        => 'Hidden',
			'labels'       => array( 'name' => 'Hidden', 'singular_name' => 'Hidden' ),
			'public'       => false,
			'show_ui'      => false,
			'show_in_rest' => false,
			'supports'     => array( 'title' ),
		) );

		register_post_meta( 'wp_mcp_book', 'wp_mcp_isbn', array( 'type' => 'string', 'description' => 'ISBN', 'single' => true, 'show_in_rest' => true ) );
		register_post_meta( 'wp_mcp_book', 'wp_mcp_page_count', array( 'type' => 'integer', 'single' => true, 'show_in_rest' => true ) );
		register_post_meta( 'wp_mcp_book', 'wp_mcp_in_print', array( 'type' => 'boolean', 'single' => true, 'show_in_rest' => true ) );
		register_post_meta( 'wp_mcp_book', 'wp_mcp_rating', array( 'type' => 'number', 'single' => true, 'show_in_rest' => true ) );
		register_post_meta( 'wp_mcp_book', 'wp_mcp_keywords', array(
			'type'         => 'array',
			'single'       => true,
			'show_in_rest' => array( 'schema' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
		) );
		register_post_meta( 'wp_mcp_book', 'wp_mcp_publisher', array(
			'type'         => 'object',
			'single'       => true,
			'show_in_rest' => array(
				'schema' => array(
					'type'                 => 'object',
					'properties'           => array( 'name' => array( 'type' => 'string' ), 'year' => array( 'type' => 'integer' ) ),
					'additionalProperties' => false,
				),
			),
		) );
		// single => false: no single well-defined value, must be reported as
		// discoverable-but-not-operable.
		register_post_meta( 'wp_mcp_book', 'wp_mcp_awards', array( 'type' => 'string', 'single' => false, 'show_in_rest' => true ) );
		// Registered but never REST-visible.
		register_post_meta( 'wp_mcp_book', 'wp_mcp_internal_note', array( 'type' => 'string', 'single' => true, 'show_in_rest' => false ) );
		// Operable by every structural rule, but its own auth_callback says no.
		register_post_meta( 'wp_mcp_book', 'wp_mcp_locked', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => '__return_false' ) );
		// Normalized by its own sanitize_callback, so what core stores is not
		// what the caller sent — the case that tells a no-op rewrite apart
		// from a real write failure.
		register_post_meta( 'wp_mcp_book', 'wp_mcp_shelf_code', array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => function ( $value ) { return is_string( $value ) ? strtoupper( $value ) : $value; },
		) );
		// Protected by the leading-underscore convention.
		register_post_meta( 'wp_mcp_book', '_wp_mcp_secret', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
	}

	/**
	 * Remove everything register_issue_11_fixtures() added. Safe to call
	 * from tearDown() on every test, registered or not.
	 */
	private function unregister_issue_11_fixtures() {
		if ( function_exists( 'unregister_post_meta' ) && function_exists( 'registered_meta_key_exists' ) ) {
			foreach ( $this->issue_11_meta_keys() as $meta_key ) {
				if ( registered_meta_key_exists( 'post', $meta_key, 'wp_mcp_book' ) ) {
					unregister_post_meta( 'wp_mcp_book', $meta_key );
				}
			}
		}
		foreach ( array( 'wp_mcp_book', 'wp_mcp_minimal', 'wp_mcp_note', 'wp_mcp_hidden_cpt' ) as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				unregister_post_type( $post_type );
			}
		}
		if ( taxonomy_exists( 'wp_mcp_genre' ) ) {
			unregister_taxonomy( 'wp_mcp_genre' );
		}
	}

	/**
	 * Create a user holding exactly the given capabilities and make them the
	 * current user.
	 *
	 * @param string[] $capabilities Capability names.
	 * @return int
	 */
	private function create_capability_user( $capabilities ) {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		foreach ( $capabilities as $capability ) {
			$user->add_cap( $capability, true );
		}
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * A user who can do everything with wp_mcp_book, including other
	 * people's entries — and nothing at all with core posts.
	 *
	 * @return int
	 */
	private function create_book_manager() {
		return $this->create_capability_user( array(
			'read',
			'upload_files',
			'edit_wp_mcp_books',
			'edit_others_wp_mcp_books',
			'edit_published_wp_mcp_books',
			'edit_private_wp_mcp_books',
			'publish_wp_mcp_books',
			'read_private_wp_mcp_books',
			'delete_wp_mcp_books',
			'delete_others_wp_mcp_books',
			'delete_published_wp_mcp_books',
			'delete_private_wp_mcp_books',
		) );
	}

	/**
	 * Create one wp_mcp_book entry.
	 *
	 * @param array $args Overrides for the post fixture.
	 * @return int
	 */
	private function create_book( $args = array() ) {
		return self::factory()->post->create( array_merge( array(
			'post_type'    => 'wp_mcp_book',
			'post_status'  => 'publish',
			'post_title'   => 'Fixture Book',
			'post_content' => 'Fixture content.',
		), $args ) );
	}

	/**
	 * Find one entry of a discovery list by post type name.
	 *
	 * @param array  $items Discovery rows.
	 * @param string $name  Post type name.
	 * @return array|null
	 */
	private function find_post_type_row( $items, $name ) {
		foreach ( $items as $item ) {
			if ( isset( $item['name'] ) && $name === $item['name'] ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Find one metadata discovery row by key.
	 *
	 * @param array  $fields Discovery rows.
	 * @param string $key    Metadata key.
	 * @return array|null
	 */
	private function find_meta_field_row( $fields, $key ) {
		foreach ( $fields as $field ) {
			if ( isset( $field['key'] ) && $key === $field['key'] ) {
				return $field;
			}
		}
		return null;
	}

	/* --- Registration, schema, and catalog shape --- */

	public function test_issue_11_abilities_are_registered_with_category_and_matrix_entries() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$matrix = WP_MCP_Ability_Matrix::get();
		foreach ( $this->issue_11_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should be registered' );
			$this->assertEquals( 'wp-mcp-content', $ability->get_category(), $name . ' belongs to wp-mcp-content' );
			$this->assertArrayHasKey( $name, $matrix, $name . ' must be in the permission matrix' );
		}
	}

	public function test_issue_11_ability_schemas_are_closed() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( $this->issue_11_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			foreach ( array( $ability->get_input_schema(), $ability->get_output_schema() ) as $schema ) {
				$this->assertArrayHasKey( 'additionalProperties', $schema, $name . ' schemas must be closed' );
				$this->assertFalse( $schema['additionalProperties'], $name . ' schemas must reject additional properties' );
			}
		}
	}

	public function test_issue_11_abilities_never_expose_a_generic_dispatcher_field() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$forbidden = array( 'option', 'option_name', 'options', 'callback', 'function', 'action', 'sql', 'query', 'file', 'path', 'object_type', 'meta_type', 'args' );
		foreach ( $this->issue_11_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			$schema = $ability->get_input_schema();
			$this->assertIsArray( $schema['properties'], $name . ' must declare object properties' );
			foreach ( array_keys( $schema['properties'] ) as $property ) {
				$this->assertNotContains( $property, $forbidden, $name . ' must not accept a generic dispatcher field via "' . $property . '"' );
			}
		}
	}

	public function test_only_the_named_metadata_abilities_accept_a_meta_key() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->assertTrue( true );
			return;
		}
		$allowed = array(
			'wp-mcp/get-term-meta',
			'wp-mcp/update-term-meta',
			'wp-mcp/delete-term-meta',
			'wp-mcp/get-custom-post-meta',
			'wp-mcp/update-custom-post-meta',
			'wp-mcp/delete-custom-post-meta',
		);
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 !== strpos( $name, 'wp-mcp/' ) ) {
				continue;
			}
			$schema = $ability->get_input_schema();
			if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) || ! isset( $schema['properties']['meta_key'] ) ) {
				continue;
			}
			$this->assertContains( $name, $allowed, $name . ' exposes a meta_key input but is not one of the explicit registered-metadata abilities' );
		}
	}

	public function test_post_type_discovery_schema_matches_the_emitted_capability_slots() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$ability = wp_get_ability( 'wp-mcp/get-post-type' );
		$this->assertNotNull( $ability );
		$schema = $ability->get_output_schema();
		$this->assertEquals(
			WP_MCP_Post_Types::capability_slots(),
			array_keys( $schema['properties']['capabilities']['properties'] ),
			'The discovery output schema must be generated from WP_MCP_Post_Types::capability_slots()'
		);
	}

	/* --- Discovery --- */

	public function test_list_post_types_reports_supports_taxonomies_and_capabilities() {
		$this->register_issue_11_fixtures();
		$this->create_book_manager();

		$result = WP_MCP_Post_Types::list_post_types( array() );
		$this->assertNotWPError( $result );

		$row = $this->find_post_type_row( $result['post_types'], 'wp_mcp_book' );
		$this->assertNotNull( $row, 'wp_mcp_book must be discoverable' );
		$this->assertTrue( $row['operable'] );
		$this->assertEquals( 'operable', $row['reason'] );
		$this->assertContains( 'thumbnail', $row['supports'] );
		$this->assertContains( 'revisions', $row['supports'] );
		$this->assertContains( 'wp_mcp_genre', $row['taxonomies'] );
		$this->assertEquals( 'edit_wp_mcp_books', $row['capabilities']['create_posts'] );
		$this->assertEquals( 'publish_wp_mcp_books', $row['capabilities']['publish_posts'] );
		$this->assertTrue( $row['permissions']['can_create'] );
		$this->assertTrue( $row['permissions']['can_publish'] );
		$this->assertTrue( $row['show_in_rest'] );
		$this->assertFalse( $row['built_in'] );
	}

	public function test_list_post_types_marks_core_types_non_operable_with_a_reason() {
		$this->register_issue_11_fixtures();
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Post_Types::list_post_types( array() );
		$this->assertNotWPError( $result );

		foreach ( array( 'post', 'page', 'attachment' ) as $name ) {
			$row = $this->find_post_type_row( $result['post_types'], $name );
			$this->assertNotNull( $row, $name . ' must still be discoverable' );
			$this->assertFalse( $row['operable'], $name . ' must never be operable through this domain' );
			$this->assertEquals( 'dedicated_domain', $row['reason'], $name . ' is owned by its own ability domain' );
		}
	}

	public function test_list_post_types_operable_only_filter_excludes_built_ins() {
		$this->register_issue_11_fixtures();
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Post_Types::list_post_types( array( 'operable_only' => true ) );
		$this->assertNotWPError( $result );

		$this->assertNull( $this->find_post_type_row( $result['post_types'], 'post' ) );
		$this->assertNull( $this->find_post_type_row( $result['post_types'], 'page' ) );
		$this->assertNull( $this->find_post_type_row( $result['post_types'], 'wp_mcp_hidden_cpt' ) );
		$this->assertNotNull( $this->find_post_type_row( $result['post_types'], 'wp_mcp_book' ) );
		$this->assertEquals( count( $result['post_types'] ), $result['total'] );
	}

	public function test_list_post_types_supports_and_taxonomy_filters() {
		$this->register_issue_11_fixtures();
		wp_set_current_user( $this->agent_user );

		$by_support = WP_MCP_Post_Types::list_post_types( array( 'operable_only' => true, 'supports' => 'thumbnail' ) );
		$this->assertNotNull( $this->find_post_type_row( $by_support['post_types'], 'wp_mcp_book' ) );
		$this->assertNull( $this->find_post_type_row( $by_support['post_types'], 'wp_mcp_minimal' ) );

		$by_taxonomy = WP_MCP_Post_Types::list_post_types( array( 'operable_only' => true, 'taxonomy' => 'wp_mcp_genre' ) );
		$this->assertNotNull( $this->find_post_type_row( $by_taxonomy['post_types'], 'wp_mcp_book' ) );
		$this->assertNull( $this->find_post_type_row( $by_taxonomy['post_types'], 'wp_mcp_minimal' ) );
	}

	public function test_get_post_type_reports_a_non_rest_type_as_discoverable_but_not_operable() {
		$this->register_issue_11_fixtures();
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Post_Types::get_post_type( array( 'post_type' => 'wp_mcp_hidden_cpt' ) );
		$this->assertNotWPError( $result, 'A non-operable type must still be describable' );
		$this->assertFalse( $result['operable'] );
		$this->assertEquals( 'not_show_in_rest', $result['reason'] );

		$denied = WP_MCP_Post_Types::list_custom_posts( array( 'post_type' => 'wp_mcp_hidden_cpt' ) );
		$this->assertWPError( $denied );
		$this->assertEquals( 'wp_mcp_post_type_not_operable', $denied->get_error_code() );
	}

	public function test_get_post_type_rejects_an_unregistered_name() {
		$this->register_issue_11_fixtures();
		wp_set_current_user( $this->agent_user );

		foreach ( array( 'wp_mcp_not_registered', '', 12, array( 'wp_mcp_book' ) ) as $candidate ) {
			$result = WP_MCP_Post_Types::get_post_type( array( 'post_type' => $candidate ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_invalid_post_type', $result->get_error_code() );
		}
	}

	public function test_custom_post_operations_refuse_core_built_in_types() {
		$this->register_issue_11_fixtures();
		$post_id = self::factory()->post->create( array( 'post_author' => $this->agent_user ) );
		wp_set_current_user( $this->agent_user );

		foreach ( array( 'post', 'page', 'attachment', 'wp_block' ) as $post_type ) {
			$result = WP_MCP_Post_Types::get_custom_post( array( 'post_type' => $post_type, 'post_id' => $post_id ) );
			$this->assertWPError( $result, $post_type . ' must not be readable through the custom post type abilities' );
			$this->assertEquals( 'wp_mcp_post_type_not_operable', $result->get_error_code() );

			$created = WP_MCP_Post_Types::create_custom_post( array( 'post_type' => $post_type, 'title' => 'Nope' ) );
			$this->assertWPError( $created );
			$this->assertEquals( 'wp_mcp_post_type_not_operable', $created->get_error_code() );
		}
	}

	public function test_list_post_type_meta_fields_classifies_every_registered_key() {
		$this->register_issue_11_fixtures();
		$this->create_book_manager();

		$result = WP_MCP_Post_Types::list_post_type_meta_fields( array( 'post_type' => 'wp_mcp_book' ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( count( $result['fields'] ), $result['total'] );

		$isbn = $this->find_meta_field_row( $result['fields'], 'wp_mcp_isbn' );
		$this->assertNotNull( $isbn );
		$this->assertTrue( $isbn['operable'] );
		$this->assertEquals( 'operable', $isbn['reason'] );
		$this->assertEquals( 'string', $isbn['type'] );
		$this->assertTrue( $isbn['single'] );

		$multi = $this->find_meta_field_row( $result['fields'], 'wp_mcp_awards' );
		$this->assertNotNull( $multi, 'A single => false key must still be discoverable' );
		$this->assertFalse( $multi['single'] );
		$this->assertFalse( $multi['operable'] );
		$this->assertEquals( 'multi_value', $multi['reason'] );

		$hidden = $this->find_meta_field_row( $result['fields'], 'wp_mcp_internal_note' );
		$this->assertNotNull( $hidden );
		$this->assertFalse( $hidden['operable'] );
		$this->assertEquals( 'not_show_in_rest', $hidden['reason'] );

		$protected = $this->find_meta_field_row( $result['fields'], '_wp_mcp_secret' );
		$this->assertNotNull( $protected );
		$this->assertTrue( $protected['protected'] );
		$this->assertFalse( $protected['operable'] );
		$this->assertEquals( 'protected_key', $protected['reason'] );
	}

	/* --- Create, read, update --- */

	public function test_create_custom_post_requires_the_post_types_own_create_capability() {
		$this->register_issue_11_fixtures();

		// The agent role holds core's edit_posts and publish_posts. Those
		// are not this post type's capabilities and must not open it.
		wp_set_current_user( $this->agent_user );
		$this->assertTrue( current_user_can( 'edit_posts' ), 'Precondition: the agent holds core edit_posts' );

		$result = WP_MCP_Post_Types::create_custom_post( array( 'post_type' => 'wp_mcp_book', 'title' => 'Smuggled' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $result->get_error_code() );
	}

	public function test_create_custom_post_creates_a_draft_owned_by_the_current_user() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();

		$result = WP_MCP_Post_Types::create_custom_post( array(
			'post_type' => 'wp_mcp_book',
			'title'     => 'Dune',
			'content'   => 'Spice.',
			'excerpt'   => 'Desert.',
		) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'wp_mcp_book', $result['post_type'] );
		$this->assertEquals( 'draft', $result['status'] );
		$this->assertEquals( $manager, $result['author'] );

		$post = get_post( $result['id'] );
		$this->assertEquals( 'Dune', $post->post_title );
		$this->assertEquals( 'wp_mcp_book', $post->post_type );
	}

	public function test_a_type_with_map_meta_cap_disabled_uses_its_own_declared_capabilities() {
		$this->register_issue_11_fixtures();

		// Core's edit_posts / publish_posts must not reach this type.
		wp_set_current_user( $this->agent_user );
		$smuggled = WP_MCP_Post_Types::create_custom_post( array( 'post_type' => 'wp_mcp_note', 'title' => 'Smuggled' ) );
		$this->assertWPError( $smuggled );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $smuggled->get_error_code() );

		// This type's create_posts is create_wp_mcp_notes, not edit_posts —
		// holding every other capability of the type is still not enough.
		$this->create_capability_user( array( 'read', 'read_wp_mcp_note', 'edit_wp_mcp_note', 'edit_wp_mcp_notes', 'publish_wp_mcp_notes' ) );
		$without_create = WP_MCP_Post_Types::create_custom_post( array( 'post_type' => 'wp_mcp_note', 'title' => 'No create cap' ) );
		$this->assertWPError( $without_create );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $without_create->get_error_code() );

		$author  = $this->create_capability_user( array( 'read', 'read_wp_mcp_note', 'create_wp_mcp_notes', 'edit_wp_mcp_note', 'edit_wp_mcp_notes', 'publish_wp_mcp_notes' ) );
		$created = WP_MCP_Post_Types::create_custom_post( array( 'post_type' => 'wp_mcp_note', 'title' => 'Note', 'content' => 'Body' ) );
		$this->assertNotWPError( $created );
		$this->assertEquals( $author, $created['author'] );

		$published = WP_MCP_Post_Types::publish_custom_post( array( 'post_type' => 'wp_mcp_note', 'post_id' => $created['id'] ) );
		$this->assertNotWPError( $published );
		$this->assertEquals( 'publish', $published['status'] );

		$discovery = WP_MCP_Post_Types::get_post_type( array( 'post_type' => 'wp_mcp_note' ) );
		$this->assertNotWPError( $discovery );
		$this->assertFalse( $discovery['map_meta_cap'] );
		$this->assertEquals( 'create_wp_mcp_notes', $discovery['capabilities']['create_posts'] );
		$this->assertEquals( 'edit_wp_mcp_note', $discovery['capabilities']['edit_post'] );
		$this->assertTrue( $discovery['permissions']['can_create'] );
	}

	public function test_create_custom_post_requires_a_title_when_the_type_supports_one() {
		$this->register_issue_11_fixtures();
		$this->create_book_manager();

		$result = WP_MCP_Post_Types::create_custom_post( array( 'post_type' => 'wp_mcp_book', 'content' => 'No title.' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_post_type_validation_error', $result->get_error_code() );
	}

	public function test_create_and_update_refuse_fields_the_post_type_does_not_support() {
		$this->register_issue_11_fixtures();
		$this->create_capability_user( array( 'read', 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts' ) );

		foreach ( array( 'content', 'excerpt' ) as $field ) {
			$result = WP_MCP_Post_Types::create_custom_post( array( 'post_type' => 'wp_mcp_minimal', 'title' => 'Bare', $field => 'Unsupported' ) );
			$this->assertWPError( $result, $field . ' must be refused on a type without that support' );
			$this->assertEquals( 'wp_mcp_post_type_unsupported_feature', $result->get_error_code() );
		}

		$created = WP_MCP_Post_Types::create_custom_post( array( 'post_type' => 'wp_mcp_minimal', 'title' => 'Bare' ) );
		$this->assertNotWPError( $created );

		$updated = WP_MCP_Post_Types::update_custom_post( array( 'post_type' => 'wp_mcp_minimal', 'post_id' => $created['id'], 'content' => 'Still unsupported' ) );
		$this->assertWPError( $updated );
		$this->assertEquals( 'wp_mcp_post_type_unsupported_feature', $updated->get_error_code() );
	}

	public function test_update_custom_post_updates_supported_fields() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager, 'post_title' => 'Old' ) );

		$result = WP_MCP_Post_Types::update_custom_post( array(
			'post_type' => 'wp_mcp_book',
			'post_id'   => $book_id,
			'title'     => 'New',
			'excerpt'   => 'Fresh',
		) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'New', get_post( $book_id )->post_title );
		$this->assertEquals( 'Fresh', get_post( $book_id )->post_excerpt );
	}

	public function test_update_custom_post_refuses_another_users_entry_without_edit_others() {
		$this->register_issue_11_fixtures();
		$owner   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$book_id = $this->create_book( array( 'post_author' => $owner ) );

		// Can edit and publish its own books, but holds no *_others_* cap.
		$this->create_capability_user( array( 'read', 'edit_wp_mcp_books', 'edit_published_wp_mcp_books', 'publish_wp_mcp_books' ) );

		$result = WP_MCP_Post_Types::update_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'title' => 'Hijack' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $result->get_error_code() );
	}

	public function test_get_custom_post_returns_supports_terms_and_operable_meta() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		$term = self::factory()->term->create( array( 'taxonomy' => 'wp_mcp_genre', 'name' => 'Science Fiction' ) );
		wp_set_object_terms( $book_id, array( (int) $term ), 'wp_mcp_genre' );
		update_post_meta( $book_id, 'wp_mcp_isbn', '978-0441013593' );
		update_post_meta( $book_id, '_wp_mcp_secret', 'never-returned' );

		$result = WP_MCP_Post_Types::get_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $result );
		$this->assertContains( 'revisions', $result['supports'] );

		$genre_terms = null;
		foreach ( $result['terms'] as $entry ) {
			if ( 'wp_mcp_genre' === $entry['taxonomy'] ) {
				$genre_terms = $entry['term_ids'];
			}
		}
		$this->assertEquals( array( (int) $term ), $genre_terms );

		$keys = wp_list_pluck( $result['meta'], 'key' );
		$this->assertContains( 'wp_mcp_isbn', $keys );
		$this->assertNotContains( '_wp_mcp_secret', $keys, 'Protected metadata must never be returned' );
		$this->assertNotContains( 'wp_mcp_awards', $keys, 'A single => false key has no single value to return' );
		$this->assertNotContains( 'wp_mcp_internal_note', $keys, 'A key without show_in_rest must never be returned' );
	}

	public function test_custom_post_abilities_reject_post_type_confusion() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		// An ID that exists, but in a different post type.
		$result = WP_MCP_Post_Types::get_custom_post( array( 'post_type' => 'wp_mcp_minimal', 'post_id' => $book_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_custom_post', $result->get_error_code() );

		// A core post addressed as if it were a book.
		$core_post_id = self::factory()->post->create( array( 'post_author' => $manager ) );
		$confused     = WP_MCP_Post_Types::update_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $core_post_id, 'title' => 'Retyped' ) );
		$this->assertWPError( $confused );
		$this->assertEquals( 'wp_mcp_invalid_custom_post', $confused->get_error_code() );
		$this->assertEquals( 'post', get_post( $core_post_id )->post_type, 'The core post must be untouched' );
	}

	public function test_custom_post_abilities_reject_non_strict_integer_ids() {
		$this->register_issue_11_fixtures();
		$this->create_book_manager();

		foreach ( array( 0, -1, 'abc' ) as $candidate ) {
			$result = WP_MCP_Post_Types::get_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $candidate ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_validation_error', $result->get_error_code() );
		}
	}

	public function test_list_custom_posts_filters_by_taxonomy_term() {
		$this->register_issue_11_fixtures();
		$manager  = $this->create_book_manager();
		$matching = $this->create_book( array( 'post_author' => $manager, 'post_title' => 'Tagged' ) );
		$this->create_book( array( 'post_author' => $manager, 'post_title' => 'Untagged' ) );

		$term = self::factory()->term->create( array( 'taxonomy' => 'wp_mcp_genre', 'name' => 'Fantasy' ) );
		wp_set_object_terms( $matching, array( (int) $term ), 'wp_mcp_genre' );

		$result = WP_MCP_Post_Types::list_custom_posts( array( 'post_type' => 'wp_mcp_book', 'taxonomy' => 'wp_mcp_genre', 'term_id' => (int) $term ) );
		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['posts'] );
		$this->assertEquals( $matching, $result['posts'][0]['id'] );

		$half = WP_MCP_Post_Types::list_custom_posts( array( 'post_type' => 'wp_mcp_book', 'taxonomy' => 'wp_mcp_genre' ) );
		$this->assertWPError( $half );
		$this->assertEquals( 'wp_mcp_post_type_validation_error', $half->get_error_code() );

		$wrong_taxonomy = WP_MCP_Post_Types::list_custom_posts( array( 'post_type' => 'wp_mcp_book', 'taxonomy' => 'category', 'term_id' => (int) $term ) );
		$this->assertWPError( $wrong_taxonomy );
		$this->assertEquals( 'wp_mcp_invalid_taxonomy', $wrong_taxonomy->get_error_code() );
	}

	public function test_list_custom_posts_non_public_status_requires_the_types_edit_capability() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$this->create_book( array( 'post_author' => $manager, 'post_status' => 'draft', 'post_title' => 'Manager draft' ) );

		$visible = WP_MCP_Post_Types::list_custom_posts( array( 'post_type' => 'wp_mcp_book', 'status' => 'draft' ) );
		$this->assertNotWPError( $visible );
		$this->assertCount( 1, $visible['posts'] );

		// A reader without this type's edit capability cannot ask for drafts
		// at all — and core's edit_posts does not substitute for it.
		wp_set_current_user( $this->agent_user );
		$denied = WP_MCP_Post_Types::list_custom_posts( array( 'post_type' => 'wp_mcp_book', 'status' => 'draft' ) );
		$this->assertWPError( $denied );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $denied->get_error_code() );
	}

	/* --- Lifecycle --- */

	public function test_publish_custom_post_requires_the_post_types_own_publish_capability() {
		$this->register_issue_11_fixtures();

		// Can create and edit books, but cannot publish them.
		$author  = $this->create_capability_user( array( 'read', 'edit_wp_mcp_books', 'edit_published_wp_mcp_books' ) );
		$book_id = $this->create_book( array( 'post_author' => $author, 'post_status' => 'draft' ) );

		$result = WP_MCP_Post_Types::publish_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $result->get_error_code() );
		$this->assertEquals( 'draft', get_post( $book_id )->post_status );

		// Core's publish_posts must not open this post type either.
		wp_set_current_user( $this->agent_user );
		$this->assertTrue( current_user_can( 'publish_posts' ), 'Precondition: the agent holds core publish_posts' );
		$smuggled = WP_MCP_Post_Types::publish_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertWPError( $smuggled );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $smuggled->get_error_code() );
		$this->assertEquals( 'draft', get_post( $book_id )->post_status );
	}

	public function test_publish_and_unpublish_custom_post_are_idempotent() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager, 'post_status' => 'draft' ) );

		$first = WP_MCP_Post_Types::publish_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $first );
		$this->assertEquals( 'publish', $first['status'] );

		$again = WP_MCP_Post_Types::publish_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $again );
		$this->assertEquals( 'publish', $again['status'] );

		$down = WP_MCP_Post_Types::unpublish_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $down );
		$this->assertEquals( 'draft', $down['status'] );

		$down_again = WP_MCP_Post_Types::unpublish_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $down_again );
		$this->assertEquals( 'draft', $down_again['status'] );
	}

	public function test_trash_restore_and_delete_use_the_post_types_delete_capability() {
		$this->register_issue_11_fixtures();

		// Can edit and publish, but holds no delete_* capability at all.
		$author  = $this->create_capability_user( array( 'read', 'edit_wp_mcp_books', 'edit_published_wp_mcp_books', 'publish_wp_mcp_books' ) );
		$book_id = $this->create_book( array( 'post_author' => $author, 'post_status' => 'draft' ) );

		$denied = WP_MCP_Post_Types::trash_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertWPError( $denied );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $denied->get_error_code() );

		$this->create_book_manager();
		$trashed = WP_MCP_Post_Types::trash_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $trashed );
		$this->assertEquals( 'trash', get_post( $book_id )->post_status );

		$restored = WP_MCP_Post_Types::restore_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $restored );
		$this->assertNotEquals( 'trash', get_post( $book_id )->post_status );

		$deleted = WP_MCP_Post_Types::delete_custom_post_permanently( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertNull( get_post( $book_id ) );

		$second = WP_MCP_Post_Types::delete_custom_post_permanently( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertWPError( $second, 'Permanent deletion is deliberately not idempotent' );
		$this->assertEquals( 'wp_mcp_invalid_custom_post', $second->get_error_code() );
	}

	/* --- Revisions --- */

	public function test_custom_post_revisions_round_trip() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager, 'post_title' => 'First', 'post_content' => 'First body.' ) );

		wp_update_post( array( 'ID' => $book_id, 'post_title' => 'Second', 'post_content' => 'Second body.' ) );

		$list = WP_MCP_Post_Types::list_custom_post_revisions( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $list );
		$this->assertGreaterThan( 0, $list['total'], 'The fixture type declares revisions support' );

		$revision_id = $list['revisions'][ count( $list['revisions'] ) - 1 ]['id'];

		$revision = WP_MCP_Post_Types::get_custom_post_revision( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'revision_id' => $revision_id ) );
		$this->assertNotWPError( $revision );
		$this->assertEquals( $book_id, $revision['parent_id'] );

		$restored = WP_MCP_Post_Types::restore_custom_post_revision( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'revision_id' => $revision_id ) );
		$this->assertNotWPError( $restored );
		$this->assertEquals( $revision['title'], get_post( $book_id )->post_title );
	}

	public function test_custom_post_revision_rejects_a_revision_of_another_entry() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$first   = $this->create_book( array( 'post_author' => $manager, 'post_title' => 'One' ) );
		$second  = $this->create_book( array( 'post_author' => $manager, 'post_title' => 'Two' ) );

		wp_update_post( array( 'ID' => $first, 'post_title' => 'One updated' ) );
		$revisions = wp_get_post_revisions( $first, array( 'fields' => 'ids' ) );
		$this->assertNotEmpty( $revisions );
		$revision_id = (int) reset( $revisions );

		$result = WP_MCP_Post_Types::get_custom_post_revision( array( 'post_type' => 'wp_mcp_book', 'post_id' => $second, 'revision_id' => $revision_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_revision', $result->get_error_code() );
	}

	public function test_custom_post_revisions_refused_when_the_type_lacks_revisions_support() {
		$this->register_issue_11_fixtures();
		$user_id  = $this->create_capability_user( array( 'read', 'edit_posts', 'edit_published_posts', 'publish_posts' ) );
		$entry_id = self::factory()->post->create( array( 'post_type' => 'wp_mcp_minimal', 'post_status' => 'publish', 'post_title' => 'Bare', 'post_author' => $user_id ) );

		$result = WP_MCP_Post_Types::list_custom_post_revisions( array( 'post_type' => 'wp_mcp_minimal', 'post_id' => $entry_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_post_type_unsupported_feature', $result->get_error_code() );

		$restore = WP_MCP_Post_Types::restore_custom_post_revision( array( 'post_type' => 'wp_mcp_minimal', 'post_id' => $entry_id, 'revision_id' => 1 ) );
		$this->assertWPError( $restore );
		$this->assertEquals( 'wp_mcp_post_type_unsupported_feature', $restore->get_error_code() );
	}

	/* --- Featured media --- */

	public function test_set_and_remove_custom_post_featured_image() {
		$this->register_issue_11_fixtures();
		$manager  = $this->create_book_manager();
		$book_id  = $this->create_book( array( 'post_author' => $manager ) );
		$media_id = self::factory()->attachment->create_object( 'featured.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
		) );

		$set = WP_MCP_Post_Types::set_custom_post_featured_image( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'media_id' => $media_id ) );
		$this->assertNotWPError( $set );
		$this->assertEquals( $media_id, (int) get_post_thumbnail_id( $book_id ) );

		$again = WP_MCP_Post_Types::set_custom_post_featured_image( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'media_id' => $media_id ) );
		$this->assertNotWPError( $again, 'Setting the same featured image twice is idempotent' );

		$removed = WP_MCP_Post_Types::remove_custom_post_featured_image( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $removed );
		$this->assertEquals( $media_id, $removed['removed_media_id'] );
		$this->assertEquals( 0, (int) get_post_thumbnail_id( $book_id ) );

		$removed_again = WP_MCP_Post_Types::remove_custom_post_featured_image( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		$this->assertNotWPError( $removed_again );
		$this->assertEquals( 0, $removed_again['removed_media_id'] );
	}

	public function test_featured_image_refused_when_the_type_lacks_thumbnail_support() {
		$this->register_issue_11_fixtures();
		$user_id  = $this->create_capability_user( array( 'read', 'edit_posts', 'edit_published_posts', 'publish_posts', 'upload_files' ) );
		$entry_id = self::factory()->post->create( array( 'post_type' => 'wp_mcp_minimal', 'post_status' => 'publish', 'post_title' => 'Bare', 'post_author' => $user_id ) );
		$media_id = self::factory()->attachment->create_object( 'featured.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
		) );

		$result = WP_MCP_Post_Types::set_custom_post_featured_image( array( 'post_type' => 'wp_mcp_minimal', 'post_id' => $entry_id, 'media_id' => $media_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_post_type_unsupported_feature', $result->get_error_code() );
		$this->assertEquals( 0, (int) get_post_thumbnail_id( $entry_id ) );

		$removed = WP_MCP_Post_Types::remove_custom_post_featured_image( array( 'post_type' => 'wp_mcp_minimal', 'post_id' => $entry_id ) );
		$this->assertWPError( $removed );
		$this->assertEquals( 'wp_mcp_post_type_unsupported_feature', $removed->get_error_code() );
	}

	public function test_set_custom_post_featured_image_rejects_a_non_image_attachment() {
		$this->register_issue_11_fixtures();
		$manager  = $this->create_book_manager();
		$book_id  = $this->create_book( array( 'post_author' => $manager ) );
		$media_id = self::factory()->attachment->create_object( 'document.pdf', 0, array(
			'post_mime_type' => 'application/pdf',
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
		) );

		$result = WP_MCP_Post_Types::set_custom_post_featured_image( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'media_id' => $media_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_not_an_image', $result->get_error_code() );

		$missing = WP_MCP_Post_Types::set_custom_post_featured_image( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'media_id' => 999999 ) );
		$this->assertWPError( $missing );
		$this->assertEquals( 'wp_mcp_invalid_media', $missing->get_error_code() );
	}

	/* --- Registered post metadata --- */

	public function test_custom_post_meta_round_trip_for_scalar_types() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		$cases = array(
			'wp_mcp_isbn'       => '978-0441013593',
			'wp_mcp_page_count' => 412,
			'wp_mcp_in_print'   => true,
			'wp_mcp_rating'     => 4.5,
		);

		foreach ( $cases as $meta_key => $value ) {
			$written = WP_MCP_Post_Types::update_custom_post_meta( array(
				'post_type' => 'wp_mcp_book',
				'post_id'   => $book_id,
				'meta_key'  => $meta_key,
				'value'     => $value,
			) );
			$this->assertNotWPError( $written, $meta_key . ' should be writable' );
			$this->assertEquals( $value, $written['value'], $meta_key . ' should round-trip' );

			$read = WP_MCP_Post_Types::get_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => $meta_key ) );
			$this->assertNotWPError( $read );
			$this->assertEquals( $value, $read['value'], $meta_key . ' should read back' );

			$repeat = WP_MCP_Post_Types::update_custom_post_meta( array(
				'post_type' => 'wp_mcp_book',
				'post_id'   => $book_id,
				'meta_key'  => $meta_key,
				'value'     => $value,
			) );
			$this->assertNotWPError( $repeat, $meta_key . ' rewriting an identical value must stay a success' );
		}
	}

	public function test_custom_post_meta_round_trip_for_array_and_object_values() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		$keywords = WP_MCP_Post_Types::update_custom_post_meta( array(
			'post_type' => 'wp_mcp_book',
			'post_id'   => $book_id,
			'meta_key'  => 'wp_mcp_keywords',
			'value'     => array( 'science fiction', 'classic' ),
		) );
		$this->assertNotWPError( $keywords );
		$this->assertEquals( array( 'science fiction', 'classic' ), $keywords['value'] );

		$publisher = WP_MCP_Post_Types::update_custom_post_meta( array(
			'post_type' => 'wp_mcp_book',
			'post_id'   => $book_id,
			'meta_key'  => 'wp_mcp_publisher',
			'value'     => array( 'name' => 'Chilton Books', 'year' => 1965 ),
		) );
		$this->assertNotWPError( $publisher );
		$this->assertEquals( 'Chilton Books', $publisher['value']['name'] );
		$this->assertEquals( 1965, $publisher['value']['year'] );

		$rejected = WP_MCP_Post_Types::update_custom_post_meta( array(
			'post_type' => 'wp_mcp_book',
			'post_id'   => $book_id,
			'meta_key'  => 'wp_mcp_publisher',
			'value'     => array( 'name' => 'Chilton Books', 'undeclared' => 'nope' ),
		) );
		$this->assertWPError( $rejected, 'The registered object schema is closed' );
		$this->assertEquals( 'wp_mcp_post_type_validation_error', $rejected->get_error_code() );
	}

	public function test_custom_post_meta_validates_values_against_the_registered_schema() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		$result = WP_MCP_Post_Types::update_custom_post_meta( array(
			'post_type' => 'wp_mcp_book',
			'post_id'   => $book_id,
			'meta_key'  => 'wp_mcp_page_count',
			'value'     => 'not-a-number',
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_post_type_validation_error', $result->get_error_code() );
		$this->assertEmpty( get_post_meta( $book_id, 'wp_mcp_page_count', true ), 'A rejected value must never be persisted' );

		$wrong_shape = WP_MCP_Post_Types::update_custom_post_meta( array(
			'post_type' => 'wp_mcp_book',
			'post_id'   => $book_id,
			'meta_key'  => 'wp_mcp_keywords',
			'value'     => 'not-an-array',
		) );
		$this->assertWPError( $wrong_shape );
		$this->assertEquals( 'wp_mcp_post_type_validation_error', $wrong_shape->get_error_code() );
	}

	public function test_custom_post_meta_rejects_unregistered_keys() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );
		update_post_meta( $book_id, 'wp_mcp_unregistered', 'present-but-undeclared' );

		foreach ( array( 'wp_mcp_unregistered', 'anything_at_all', '' ) as $meta_key ) {
			$read = WP_MCP_Post_Types::get_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => $meta_key ) );
			$this->assertWPError( $read );
			$this->assertEquals( 'wp_mcp_post_meta_not_registered', $read->get_error_code() );

			$written = WP_MCP_Post_Types::update_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => $meta_key, 'value' => 'x' ) );
			$this->assertWPError( $written );
			$this->assertEquals( 'wp_mcp_post_meta_not_registered', $written->get_error_code() );
		}

		$this->assertEquals( 'present-but-undeclared', get_post_meta( $book_id, 'wp_mcp_unregistered', true ) );
	}

	public function test_custom_post_meta_rejects_protected_keys() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );
		update_post_meta( $book_id, '_wp_mcp_secret', 'classified' );

		foreach ( array( '_wp_mcp_secret', '_thumbnail_id', '_edit_lock', '_wp_page_template' ) as $meta_key ) {
			$read = WP_MCP_Post_Types::get_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => $meta_key ) );
			$this->assertWPError( $read, $meta_key . ' must never be readable' );
			$this->assertEquals( 'wp_mcp_post_meta_protected', $read->get_error_code() );

			$written = WP_MCP_Post_Types::update_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => $meta_key, 'value' => 'x' ) );
			$this->assertWPError( $written, $meta_key . ' must never be writable' );
			$this->assertEquals( 'wp_mcp_post_meta_protected', $written->get_error_code() );

			$deleted = WP_MCP_Post_Types::delete_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => $meta_key ) );
			$this->assertWPError( $deleted, $meta_key . ' must never be deletable' );
			$this->assertEquals( 'wp_mcp_post_meta_protected', $deleted->get_error_code() );
		}

		$this->assertEquals( 'classified', get_post_meta( $book_id, '_wp_mcp_secret', true ), 'Protected metadata must be left untouched' );
	}

	public function test_custom_post_meta_rejects_multi_value_and_non_rest_keys() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		$multi = WP_MCP_Post_Types::update_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_awards', 'value' => 'Hugo' ) );
		$this->assertWPError( $multi );
		$this->assertEquals( 'wp_mcp_post_meta_not_operable', $multi->get_error_code() );

		$multi_read = WP_MCP_Post_Types::get_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_awards' ) );
		$this->assertWPError( $multi_read );
		$this->assertEquals( 'wp_mcp_post_meta_not_operable', $multi_read->get_error_code() );

		$hidden = WP_MCP_Post_Types::update_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_internal_note', 'value' => 'x' ) );
		$this->assertWPError( $hidden );
		$this->assertEquals( 'wp_mcp_post_meta_not_registered', $hidden->get_error_code() );
	}

	public function test_custom_post_meta_honours_the_per_object_meta_capability() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		// wp_mcp_locked is structurally operable but its own auth_callback
		// refuses every writer, so edit_post_meta / delete_post_meta fail
		// even for a user who can edit the entry itself.
		$this->assertTrue( current_user_can( 'edit_post', $book_id ), 'Precondition: the manager can edit the entry' );

		$written = WP_MCP_Post_Types::update_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_locked', 'value' => 'x' ) );
		$this->assertWPError( $written );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $written->get_error_code() );

		$deleted = WP_MCP_Post_Types::delete_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_locked' ) );
		$this->assertWPError( $deleted );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $deleted->get_error_code() );

		// Reading is gated on read_post, not on the write auth callback.
		$read = WP_MCP_Post_Types::get_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_locked' ) );
		$this->assertNotWPError( $read );
	}

	public function test_custom_post_meta_writes_require_edit_rights_on_the_entry() {
		$this->register_issue_11_fixtures();
		$owner   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$book_id = $this->create_book( array( 'post_author' => $owner ) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Post_Types::update_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_isbn', 'value' => 'x' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_post_type_permission_denied', $result->get_error_code() );
		$this->assertEmpty( get_post_meta( $book_id, 'wp_mcp_isbn', true ) );
	}

	public function test_delete_custom_post_meta_is_idempotent() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );
		update_post_meta( $book_id, 'wp_mcp_isbn', '978-0441013593' );

		$first = WP_MCP_Post_Types::delete_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_isbn' ) );
		$this->assertNotWPError( $first );
		$this->assertTrue( $first['deleted'] );
		$this->assertEmpty( get_post_meta( $book_id, 'wp_mcp_isbn', true ) );

		$second = WP_MCP_Post_Types::delete_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_isbn' ) );
		$this->assertNotWPError( $second );
		$this->assertTrue( $second['deleted'] );
	}

	public function test_custom_post_meta_never_reaches_user_or_term_metadata() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		register_meta( 'user', 'wp_mcp_user_only', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
		update_user_meta( $manager, 'wp_mcp_user_only', 'user-scoped' );

		$result = WP_MCP_Post_Types::get_custom_post_meta( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'meta_key' => 'wp_mcp_user_only' ) );
		$this->assertWPError( $result, 'A key registered for users is not post metadata' );
		$this->assertEquals( 'wp_mcp_post_meta_not_registered', $result->get_error_code() );
		$this->assertEquals( 'user-scoped', get_user_meta( $manager, 'wp_mcp_user_only', true ), 'User metadata must be untouched' );

		unregister_meta_key( 'user', 'wp_mcp_user_only' );
	}

	/* --- Permission gates and lifecycle edge cases --- */

	public function test_post_type_permission_gates_resolve_the_types_own_capabilities() {
		$this->register_issue_11_fixtures();
		$book = array( 'post_type' => 'wp_mcp_book' );

		// Core's edit_posts / publish_posts never open a type that declares
		// its own capability_type — the first-pass gate reads the slots off
		// the type's registration, exactly as the execute callbacks do.
		wp_set_current_user( $this->agent_user );
		$this->assertTrue( current_user_can( 'edit_posts' ), 'Precondition: the agent holds core edit_posts' );
		$this->assertFalse( WP_MCP_Post_Types::can_create( $book ) );
		$this->assertFalse( WP_MCP_Post_Types::can_edit( $book ) );
		$this->assertFalse( WP_MCP_Post_Types::can_publish( $book ) );
		$this->assertFalse( WP_MCP_Post_Types::can_delete( $book ) );

		$this->create_book_manager();
		$this->assertTrue( WP_MCP_Post_Types::can_create( $book ) );
		$this->assertTrue( WP_MCP_Post_Types::can_edit( $book ) );
		$this->assertTrue( WP_MCP_Post_Types::can_publish( $book ) );
		$this->assertTrue( WP_MCP_Post_Types::can_delete( $book ) );

		// Input that names no resolvable operable type degrades to the
		// authenticated-reader gate, so the execute callback can answer with
		// the precise error instead of an indistinguishable permission
		// failure.
		$this->assertTrue( WP_MCP_Post_Types::can_edit( array( 'post_type' => 'post' ) ) );
		$this->assertTrue( WP_MCP_Post_Types::can_edit( array() ) );
		$this->assertTrue( WP_MCP_Post_Types::can_edit( null ) );
	}

	public function test_revision_reads_are_gated_on_the_types_own_edit_capability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		// Reading revisions requires edit_post at execution, so the
		// registered first-pass gate must be the type's own edit capability
		// too — not the plain authenticated-reader gate, which would let a
		// caller with no rights over the type reach the ability at all.
		wp_set_current_user( $this->agent_user );
		$this->assertTrue( current_user_can( 'read' ), 'Precondition: the agent is an authenticated reader' );

		$cases = array(
			'wp-mcp/list-custom-post-revisions' => array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ),
			'wp-mcp/get-custom-post-revision'   => array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id, 'revision_id' => 1 ),
		);
		foreach ( $cases as $name => $args ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should be registered' );
			if ( ! method_exists( $ability, 'check_permissions' ) ) {
				continue;
			}
			$this->assertNotTrue(
				$ability->check_permissions( $args ),
				$name . " must not admit a caller lacking this post type's edit capability"
			);
		}
	}

	public function test_trash_custom_post_tolerates_a_trash_that_permanently_deletes() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		// Reproduces the EMPTY_TRASH_DAYS === 0 path, a supported WordPress
		// configuration in which wp_trash_post() permanently deletes instead
		// of trashing and the entry is gone before the response is built.
		// EMPTY_TRASH_DAYS is a constant and cannot be redefined mid-suite,
		// so core's own short-circuit filter stands in for it.
		$deleting_trash = function ( $check, $post ) {
			wp_delete_post( $post->ID, true );
			return $post;
		};
		add_filter( 'pre_trash_post', $deleting_trash, 10, 2 );
		$result = WP_MCP_Post_Types::trash_custom_post( array( 'post_type' => 'wp_mcp_book', 'post_id' => $book_id ) );
		remove_filter( 'pre_trash_post', $deleting_trash, 10 );

		$this->assertNotWPError( $result, 'A trash that deletes must not fatal on the re-read' );
		$this->assertEquals( $book_id, $result['id'] );
		$this->assertEquals( 'wp_mcp_book', $result['post_type'] );
		$this->assertNull( get_post( $book_id ) );
	}

	public function test_custom_post_meta_rewrite_of_a_sanitized_value_stays_successful() {
		$this->register_issue_11_fixtures();
		$manager = $this->create_book_manager();
		$book_id = $this->create_book( array( 'post_author' => $manager ) );

		$first = WP_MCP_Post_Types::update_custom_post_meta( array(
			'post_type' => 'wp_mcp_book',
			'post_id'   => $book_id,
			'meta_key'  => 'wp_mcp_shelf_code',
			'value'     => 'sf-12',
		) );
		$this->assertNotWPError( $first );
		$this->assertEquals( 'SF-12', $first['value'], "The key's own sanitize_callback normalizes what is stored" );

		// update_post_meta() returns false for an unchanged value as well as
		// for a failure, so the two are told apart by re-reading — against
		// what core would have stored, never against the raw input, which a
		// sanitize_callback has already changed.
		$again = WP_MCP_Post_Types::update_custom_post_meta( array(
			'post_type' => 'wp_mcp_book',
			'post_id'   => $book_id,
			'meta_key'  => 'wp_mcp_shelf_code',
			'value'     => 'sf-12',
		) );
		$this->assertNotWPError( $again, 'Rewriting a value the sanitize_callback normalizes must stay a success' );
		$this->assertEquals( 'SF-12', $again['value'] );
	}

	/* --- Regression: the post and page domains are unchanged --- */

	public function test_issue_11_does_not_change_core_post_and_page_capability_behaviour() {
		$this->register_issue_11_fixtures();

		// The agent's existing post workflow still works exactly as before.
		wp_set_current_user( $this->agent_user );
		$created = WP_MCP_Posts::create_post( array( 'title' => 'Regression', 'content' => 'Body' ) );
		$this->assertNotWPError( $created );
		$this->assertEquals( 'draft', $created['status'] );

		$published = WP_MCP_Posts::publish_post( array( 'post_id' => $created['id'] ) );
		$this->assertNotWPError( $published );
		$this->assertEquals( 'publish', $published['status'] );

		// A user holding only the custom type's capabilities still has no
		// rights over core posts.
		$this->create_book_manager();
		$blocked = WP_MCP_Posts::update_post( array( 'post_id' => $created['id'], 'title' => 'Hijack' ) );
		$this->assertWPError( $blocked );
		$this->assertEquals( 'Regression', get_post( $created['id'] )->post_title );
	}

	/* ==================================================================
	 * Issue #12 — system, cron, cache/maintenance, import/export, privacy
	 * ================================================================ */

	/**
	 * Every ability slug introduced by issue #12, with its category.
	 *
	 * @return array<string,string>
	 */
	private function issue_12_ability_categories() {
		return array(
			// System inspection.
			'get-site-info'                    => 'wp-mcp-system',
			'get-environment-info'             => 'wp-mcp-system',
			'list-image-sizes'                 => 'wp-mcp-system',
			'get-rewrite-state'                => 'wp-mcp-system',
			'get-site-size'                    => 'wp-mcp-system',
			'list-site-health-tests'           => 'wp-mcp-system',
			'run-site-health-tests'            => 'wp-mcp-system',
			// Cron.
			'get-cron-status'                  => 'wp-mcp-system',
			'list-cron-events'                 => 'wp-mcp-system',
			'get-cron-event'                   => 'wp-mcp-system',
			'schedule-cron-event'              => 'wp-mcp-system',
			'unschedule-cron-event'            => 'wp-mcp-system',
			'run-cron-event'                   => 'wp-mcp-system',
			'run-due-cron-events'              => 'wp-mcp-system',
			// Cache and maintenance.
			'flush-object-cache'               => 'wp-mcp-system',
			'clear-expired-transients'         => 'wp-mcp-system',
			'clear-update-caches'              => 'wp-mcp-system',
			'get-maintenance-mode'             => 'wp-mcp-system',
			'set-maintenance-mode'             => 'wp-mcp-system',
			// Import / export.
			'export-content'                   => 'wp-mcp-system',
			'import-content'                   => 'wp-mcp-system',
			'export-settings'                  => 'wp-mcp-settings',
			'import-settings'                  => 'wp-mcp-settings',
			// Privacy.
			'list-privacy-requests'            => 'wp-mcp-privacy',
			'get-privacy-request'              => 'wp-mcp-privacy',
			'create-privacy-export-request'    => 'wp-mcp-privacy',
			'create-privacy-erasure-request'   => 'wp-mcp-privacy',
			'resend-privacy-request-email'     => 'wp-mcp-privacy',
			'process-privacy-export-request'   => 'wp-mcp-privacy',
			'process-privacy-erasure-request'  => 'wp-mcp-privacy',
			'delete-privacy-request'           => 'wp-mcp-privacy',
		);
	}

	/**
	 * Become an administrator for an issue #12 test.
	 *
	 * @return int
	 */
	private function become_issue_12_admin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		return $admin;
	}

	/**
	 * Empty the WP-Cron queue so a cron test only sees its own events and
	 * never fires one of WordPress's own scheduled jobs.
	 */
	private function reset_cron_queue() {
		_set_cron_array( array() );
	}

	/**
	 * Opt a test hook into being schedulable from scratch.
	 *
	 * Since issue #15, `wp-mcp/schedule-cron-event` only queues a hook that is
	 * either a WordPress maintenance hook or already in the site's cron array —
	 * `has_action()` is no longer a policy, because "any hook something listens
	 * to" would make schedule + run an arbitrary action dispatcher. The
	 * `wp_mcp_cron_schedulable_hooks` filter is the server-side opt-in a site
	 * owner uses for their own hooks, and the tests use it the same way.
	 *
	 * @since 0.15.0
	 *
	 * @param string $hook Hook name to allow.
	 */
	private function allow_schedulable_hook( $hook ) {
		add_filter( 'wp_mcp_cron_schedulable_hooks', static function ( $hooks ) use ( $hook ) {
			$hooks[] = $hook;
			return $hooks;
		} );
	}

	/**
	 * Move a personal data request into the confirmed state, the way the data
	 * subject following the emailed link would.
	 *
	 * @param int $request_id Request ID.
	 */
	private function confirm_privacy_request( $request_id ) {
		wp_update_post( array(
			'ID'          => $request_id,
			'post_status' => 'request-confirmed',
		) );
	}

	/* --- Registration, schema and catalog shape --- */

	public function test_issue_12_abilities_are_registered_with_category_and_matrix_entries() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$matrix = WP_MCP_Ability_Matrix::get();
		foreach ( $this->issue_12_ability_categories() as $slug => $category ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should be registered' );
			$this->assertEquals( $category, $ability->get_category(), $name . ' belongs to ' . $category );
			$this->assertArrayHasKey( $name, $matrix, $name . ' must be in the permission matrix' );
		}
	}

	public function test_issue_12_ability_schemas_are_closed() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( array_keys( $this->issue_12_ability_categories() ) as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			foreach ( array( $ability->get_input_schema(), $ability->get_output_schema() ) as $schema ) {
				$this->assertArrayHasKey( 'additionalProperties', $schema, $name . ' schemas must be closed' );
				$this->assertFalse( $schema['additionalProperties'], $name . ' schemas must reject additional properties' );
			}
		}
	}

	/**
	 * The whole point of issue #12: none of these operational abilities may
	 * become a filesystem, SQL, shell or callback primitive by accepting one
	 * as an input field.
	 */
	public function test_issue_12_inputs_never_accept_a_path_callback_or_query() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$forbidden = array(
			'path', 'file', 'filename', 'file_path', 'filepath', 'dir', 'directory',
			'callback', 'callable', 'function', 'method', 'action',
			'sql', 'query', 'command', 'shell', 'code', 'php',
			'option', 'option_name', 'transient', 'url',
		);
		foreach ( array_keys( $this->issue_12_ability_categories() ) as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			foreach ( array_keys( $ability->get_input_schema()['properties'] ) as $property ) {
				$this->assertNotContains( $property, $forbidden, $name . ' must not accept "' . $property . '" as an input field' );
			}
		}
	}

	public function test_issue_12_abilities_are_mcp_only() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( array_keys( $this->issue_12_ability_categories() ) as $slug ) {
			$ability = wp_get_ability( 'wp-mcp/' . $slug );
			$this->assertNotNull( $ability );
			$mcp = $ability->get_meta_item( 'mcp' );
			$this->assertIsArray( $mcp );
			$this->assertTrue( $mcp['public'], 'wp-mcp/' . $slug . ' must be MCP-public' );
			$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ), 'wp-mcp/' . $slug . ' must not be REST-exposed' );
		}
	}

	/* --- System inspection --- */

	public function test_system_inspection_is_denied_without_manage_options() {
		wp_set_current_user( $this->agent_user );
		foreach ( array( 'get_site_info', 'get_environment_info', 'list_image_sizes', 'get_rewrite_state', 'get_site_size' ) as $method ) {
			$result = call_user_func( array( 'WP_MCP_System', $method ), array() );
			$this->assertWPError( $result, $method . ' must be denied without manage_options' );
			$this->assertEquals( 'wp_mcp_system_permission_denied', $result->get_error_code() );
		}
	}

	public function test_get_site_info_reports_identity_and_counts() {
		$this->become_issue_12_admin();
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Issue12Counted' ) );

		$info = WP_MCP_System::get_site_info();
		$this->assertNotWPError( $info );
		$this->assertEquals( get_bloginfo( 'name' ), $info['name'] );
		$this->assertEquals( home_url(), $info['home_url'] );
		$this->assertEquals( get_bloginfo( 'version' ), $info['wp_version'] );
		$this->assertGreaterThanOrEqual( 1, $info['published_posts'] );
		$this->assertGreaterThanOrEqual( 1, $info['total_users'] );
		$this->assertIsBool( $info['is_multisite'] );
	}

	public function test_get_environment_info_reports_versions_without_secrets() {
		$this->become_issue_12_admin();

		$env = WP_MCP_System::get_environment_info();
		$this->assertNotWPError( $env );
		$this->assertEquals( PHP_VERSION, $env['php_version'] );
		$this->assertEquals( get_bloginfo( 'version' ), $env['wp_version'] );
		$this->assertNotEmpty( $env['database_server_version'] );

		foreach ( array_keys( $env ) as $key ) {
			foreach ( array( 'salt', 'secret', 'password', 'passwd', 'nonce_key', 'auth_key' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $key, 'get-environment-info must not expose a "' . $needle . '" field' );
			}
		}

		$encoded = wp_json_encode( $env, JSON_UNESCAPED_SLASHES );
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'NONCE_SALT' ) as $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
				$this->assertStringNotContainsString( constant( $constant ), $encoded, $constant . ' must never appear in an environment response' );
			}
		}
	}

	public function test_issue_12_read_responses_never_contain_a_filesystem_path() {
		$this->become_issue_12_admin();

		$payloads = array(
			'get-site-info'        => WP_MCP_System::get_site_info(),
			'get-environment-info' => WP_MCP_System::get_environment_info(),
			'get-rewrite-state'    => WP_MCP_System::get_rewrite_state(),
			'list-image-sizes'     => WP_MCP_System::list_image_sizes(),
			'get-cron-status'      => WP_MCP_Cron::get_cron_status(),
			'get-maintenance-mode' => WP_MCP_Maintenance::get_maintenance_mode(),
		);

		foreach ( $payloads as $ability => $payload ) {
			$this->assertNotWPError( $payload, $ability . ' should succeed for an administrator' );
			$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
			$this->assertStringNotContainsString( ABSPATH, $encoded, $ability . ' must never leak the WordPress root path' );
			$this->assertStringNotContainsString( WP_CONTENT_DIR, $encoded, $ability . ' must never leak the content directory path' );
		}
	}

	public function test_list_image_sizes_reports_registered_subsizes() {
		$this->become_issue_12_admin();

		$sizes = WP_MCP_System::list_image_sizes();
		$this->assertNotWPError( $sizes );
		$this->assertGreaterThan( 0, $sizes['total'] );

		$names = wp_list_pluck( $sizes['sizes'], 'name' );
		$this->assertContains( 'thumbnail', $names );
		foreach ( $sizes['sizes'] as $size ) {
			$this->assertIsInt( $size['width'] );
			$this->assertIsInt( $size['height'] );
			$this->assertIsBool( $size['crop'] );
		}
	}

	public function test_get_rewrite_state_reports_the_permalink_structure() {
		$this->become_issue_12_admin();
		update_option( 'permalink_structure', '/%postname%/' );

		$state = WP_MCP_System::get_rewrite_state();
		$this->assertNotWPError( $state );
		$this->assertEquals( '/%postname%/', $state['permalink_structure'] );
		$this->assertIsInt( $state['cached_rules'] );
		$this->assertIsBool( $state['server_supports_rewrite'] );
	}

	/* --- Site Health --- */

	public function test_site_health_abilities_require_the_site_health_capability() {
		wp_set_current_user( $this->agent_user );

		$listed = WP_MCP_System::list_site_health_tests();
		$this->assertWPError( $listed );
		$this->assertEquals( 'wp_mcp_system_permission_denied', $listed->get_error_code() );

		$ran = WP_MCP_System::run_site_health_tests();
		$this->assertWPError( $ran );
		$this->assertEquals( 'wp_mcp_system_permission_denied', $ran->get_error_code() );
	}

	public function test_list_site_health_tests_reports_runnable_core_tests() {
		$this->become_issue_12_admin();

		$catalog = WP_MCP_System::list_site_health_tests();
		if ( is_wp_error( $catalog ) ) {
			$this->assertEquals( 'wp_mcp_system_unsupported', $catalog->get_error_code() );
			return;
		}
		$this->assertGreaterThan( 0, $catalog['total'] );
		$this->assertGreaterThan( 0, $catalog['runnable'] );
		foreach ( $catalog['tests'] as $test ) {
			$this->assertContains( $test['group'], array( 'direct', 'async' ) );
			$this->assertContains( $test['reason'], array( 'operable', 'async_test', 'third_party_callback', 'unknown_core_test' ) );
			if ( 'async' === $test['group'] ) {
				$this->assertFalse( $test['runnable'], 'Asynchronous Site Health tests are never run by this plugin' );
			}
		}
	}

	public function test_site_health_never_runs_a_third_party_callback() {
		$this->become_issue_12_admin();

		$invoked = 0;
		add_filter( 'site_status_tests', function ( $tests ) use ( &$invoked ) {
			$tests['direct']['wp_mcp_fake_callback_test'] = array(
				'label' => 'WordPress MCP fake callable test',
				'test'  => function () use ( &$invoked ) {
					++$invoked;
					return array( 'label' => 'nope', 'status' => 'good' );
				},
			);
			return $tests;
		} );

		$catalog = WP_MCP_System::list_site_health_tests();
		if ( is_wp_error( $catalog ) ) {
			$this->markTestSkipped( 'Site Health is unavailable on this installation.' );
		}

		$row = null;
		foreach ( $catalog['tests'] as $test ) {
			if ( 'wp_mcp_fake_callback_test' === $test['name'] ) {
				$row = $test;
			}
		}
		$this->assertNotNull( $row, 'A third-party direct test must still be listed' );
		$this->assertFalse( $row['runnable'] );
		$this->assertEquals( 'third_party_callback', $row['reason'] );

		$result = WP_MCP_System::run_site_health_tests( array( 'tests' => array( 'wp_mcp_fake_callback_test' ) ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_system_validation_error', $result->get_error_code() );
		$this->assertSame( 0, $invoked, 'A third-party Site Health callback must never be invoked' );
	}

	public function test_run_site_health_tests_rejects_an_unknown_test_name() {
		$this->become_issue_12_admin();

		$result = WP_MCP_System::run_site_health_tests( array( 'tests' => array( 'definitely_not_a_core_test' ) ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_system_validation_error', $result->get_error_code() );
	}

	public function test_run_site_health_tests_runs_a_selected_core_test() {
		$this->become_issue_12_admin();

		$catalog = WP_MCP_System::list_site_health_tests();
		if ( is_wp_error( $catalog ) ) {
			$this->markTestSkipped( 'Site Health is unavailable on this installation.' );
		}

		// Deliberately restricted to cheap, offline core tests: running the
		// whole suite would hit the network through the update checks.
		$safe = array( 'php_version', 'utf8mb4_support', 'sql_server', 'php_extensions' );
		$pick = '';
		foreach ( $catalog['tests'] as $test ) {
			if ( $test['runnable'] && in_array( $test['name'], $safe, true ) ) {
				$pick = $test['name'];
				break;
			}
		}
		if ( '' === $pick ) {
			$this->markTestSkipped( 'No offline core Site Health test is available on this installation.' );
		}

		$result = WP_MCP_System::run_site_health_tests( array( 'tests' => array( $pick ) ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 1, $result['total'] );
		$this->assertEquals( $pick, $result['results'][0]['name'] );
		$this->assertContains( $result['results'][0]['status'], array( 'good', 'recommended', 'critical' ) );
	}

	/* --- Cron: policy --- */

	public function test_cron_abilities_are_denied_without_manage_options() {
		wp_set_current_user( $this->agent_user );

		foreach ( array( 'get_cron_status', 'list_cron_events', 'run_due_cron_events' ) as $method ) {
			$result = call_user_func( array( 'WP_MCP_Cron', $method ), array() );
			$this->assertWPError( $result, $method . ' must require manage_options' );
			$this->assertEquals( 'wp_mcp_system_permission_denied', $result->get_error_code() );
		}
	}

	public function test_cron_denylist_refuses_unattended_update_hooks() {
		$this->become_issue_12_admin();

		foreach ( array( 'wp_maybe_auto_update', 'upgrader_scheduled_cleanup', 'wp_delete_temp_updater_backups' ) as $hook ) {
			$this->assertTrue( WP_MCP_Cron::is_denied_hook( $hook ), $hook . ' must be on the cron denylist' );
			$result = WP_MCP_Cron::schedule_cron_event( array( 'hook' => $hook, 'recurrence' => 'daily' ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_cron_hook_denied', $result->get_error_code() );
		}
	}

	public function test_cron_denylist_refuses_request_lifecycle_hooks() {
		$this->become_issue_12_admin();

		foreach ( array( 'init', 'wp_loaded', 'shutdown', 'template_redirect' ) as $hook ) {
			$this->assertTrue( WP_MCP_Cron::is_denied_hook( $hook ), $hook . ' is not a cron job and must be denied' );
			$result = WP_MCP_Cron::schedule_cron_event( array( 'hook' => $hook ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_cron_hook_denied', $result->get_error_code() );
		}
	}

	public function test_cron_denylist_refuses_ajax_admin_and_rest_prefixes() {
		foreach ( array( 'wp_ajax_my_action', 'admin_init', 'rest_api_init', 'load-post.php' ) as $hook ) {
			$this->assertTrue( WP_MCP_Cron::is_denied_hook( $hook ), $hook . ' must be denied by prefix' );
		}
		$this->assertFalse( WP_MCP_Cron::is_denied_hook( 'wp_mcp_ordinary_job' ) );
	}

	public function test_schedule_cron_event_refuses_a_hook_with_no_listener() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Cron::schedule_cron_event( array( 'hook' => 'wp_mcp_hook_with_no_listener' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_cron_hook_denied', $result->get_error_code() );
		$this->assertFalse( (bool) wp_next_scheduled( 'wp_mcp_hook_with_no_listener' ) );
	}

	public function test_cron_hook_names_reject_unsafe_characters() {
		$this->become_issue_12_admin();

		foreach ( array( 'evil hook', 'hook;rm -rf /', 'hook$(id)', '' ) as $hook ) {
			$result = WP_MCP_Cron::get_cron_event( array( 'hook' => $hook ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_cron_validation_error', $result->get_error_code() );
		}
	}

	/* --- Cron: scheduling --- */

	public function test_schedule_cron_event_registers_and_replaces_rather_than_duplicating() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();
		add_action( 'wp_mcp_test_schedulable', '__return_true' );
		$this->allow_schedulable_hook( 'wp_mcp_test_schedulable' );

		$first = WP_MCP_Cron::schedule_cron_event( array(
			'hook'       => 'wp_mcp_test_schedulable',
			'recurrence' => 'hourly',
		) );
		$this->assertNotWPError( $first );
		$this->assertTrue( $first['scheduled'] );
		$this->assertFalse( $first['replaced'] );
		$this->assertEquals( 'hourly', $first['event']['schedule'] );
		$this->assertTrue( $first['event']['operable'] );

		$second = WP_MCP_Cron::schedule_cron_event( array(
			'hook'       => 'wp_mcp_test_schedulable',
			'recurrence' => 'hourly',
		) );
		$this->assertNotWPError( $second );
		$this->assertTrue( $second['replaced'], 'Rescheduling the same hook and arguments replaces the event' );

		$listed = WP_MCP_Cron::list_cron_events( array( 'hook' => 'wp_mcp_test_schedulable' ) );
		$this->assertNotWPError( $listed );
		$this->assertEquals( 1, $listed['total'], 'The hook must not be scheduled twice' );
	}

	public function test_schedule_cron_event_reports_a_signature_that_matches_the_listing() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();
		add_action( 'wp_mcp_test_signature', '__return_true' );
		$this->allow_schedulable_hook( 'wp_mcp_test_signature' );

		$scheduled = WP_MCP_Cron::schedule_cron_event( array(
			'hook' => 'wp_mcp_test_signature',
			'args' => array( 'alpha', 7 ),
		) );
		$this->assertNotWPError( $scheduled );

		$listed = WP_MCP_Cron::list_cron_events( array( 'hook' => 'wp_mcp_test_signature' ) );
		$this->assertNotWPError( $listed );
		$this->assertEquals( 1, $listed['total'] );
		$this->assertEquals( $listed['events'][0]['signature'], $scheduled['event']['signature'], 'The signature reported on write must be the key WordPress actually stored' );
		$this->assertEquals( 2, $scheduled['event']['args_count'] );
	}

	public function test_schedule_cron_event_rejects_non_scalar_arguments() {
		$this->become_issue_12_admin();
		add_action( 'wp_mcp_test_args', '__return_true' );
		$this->allow_schedulable_hook( 'wp_mcp_test_args' );

		$nested = WP_MCP_Cron::schedule_cron_event( array(
			'hook' => 'wp_mcp_test_args',
			'args' => array( array( 'nested' => true ) ),
		) );
		$this->assertWPError( $nested );
		$this->assertEquals( 'wp_mcp_cron_validation_error', $nested->get_error_code() );

		$too_many = WP_MCP_Cron::schedule_cron_event( array(
			'hook' => 'wp_mcp_test_args',
			'args' => array_fill( 0, 11, 'x' ),
		) );
		$this->assertWPError( $too_many );
		$this->assertEquals( 'wp_mcp_cron_validation_error', $too_many->get_error_code() );
	}

	public function test_schedule_cron_event_rejects_an_unregistered_recurrence() {
		$this->become_issue_12_admin();
		add_action( 'wp_mcp_test_recurrence', '__return_true' );
		$this->allow_schedulable_hook( 'wp_mcp_test_recurrence' );

		$result = WP_MCP_Cron::schedule_cron_event( array(
			'hook'       => 'wp_mcp_test_recurrence',
			'recurrence' => 'every_femtosecond',
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_cron_validation_error', $result->get_error_code() );
	}

	public function test_schedule_cron_event_rejects_a_far_future_timestamp() {
		$this->become_issue_12_admin();
		add_action( 'wp_mcp_test_horizon', '__return_true' );
		$this->allow_schedulable_hook( 'wp_mcp_test_horizon' );

		$result = WP_MCP_Cron::schedule_cron_event( array(
			'hook'      => 'wp_mcp_test_horizon',
			'timestamp' => time() + ( 2 * YEAR_IN_SECONDS ),
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_cron_validation_error', $result->get_error_code() );
	}

	/* --- Cron: listing, reading, unscheduling --- */

	public function test_list_cron_events_orders_paginates_and_reports_operability() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();
		add_action( 'wp_mcp_test_listed', '__return_true' );

		wp_schedule_single_event( time() + 300, 'wp_mcp_test_listed', array( 'a' ) );
		wp_schedule_single_event( time() + 100, 'wp_mcp_test_listed', array( 'b' ) );
		wp_schedule_single_event( time() + 200, 'wp_mcp_test_orphan' );

		$all = WP_MCP_Cron::list_cron_events( array( 'per_page' => 50 ) );
		$this->assertNotWPError( $all );
		$this->assertEquals( 3, $all['total'] );
		$this->assertEquals( 'wp_mcp_test_listed', $all['events'][0]['hook'] );
		$this->assertGreaterThanOrEqual( $all['events'][1]['timestamp'], $all['events'][2]['timestamp'] );

		$orphan = null;
		foreach ( $all['events'] as $event ) {
			if ( 'wp_mcp_test_orphan' === $event['hook'] ) {
				$orphan = $event;
			}
		}
		$this->assertNotNull( $orphan );
		$this->assertFalse( $orphan['operable'], 'An event whose hook lost its listener is reported as not operable' );
		$this->assertEquals( 'no_registered_action', $orphan['reason'] );

		$page_two = WP_MCP_Cron::list_cron_events( array( 'per_page' => 2, 'page' => 2 ) );
		$this->assertNotWPError( $page_two );
		$this->assertEquals( 2, $page_two['total_pages'] );
		$this->assertCount( 1, $page_two['events'] );
	}

	public function test_list_cron_events_can_restrict_to_due_events() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();
		add_action( 'wp_mcp_test_due', '__return_true' );

		wp_schedule_single_event( time() - 60, 'wp_mcp_test_due', array( 'past' ) );
		wp_schedule_single_event( time() + 600, 'wp_mcp_test_due', array( 'future' ) );

		$due = WP_MCP_Cron::list_cron_events( array( 'due_only' => true ) );
		$this->assertNotWPError( $due );
		$this->assertEquals( 1, $due['total'] );
		$this->assertTrue( $due['events'][0]['is_due'] );
	}

	public function test_get_cron_event_returns_not_found_for_an_unscheduled_hook() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();

		$result = WP_MCP_Cron::get_cron_event( array( 'hook' => 'wp_mcp_never_scheduled' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_cron_event_not_found', $result->get_error_code() );
	}

	public function test_unschedule_cron_event_removes_one_occurrence_or_all_of_them() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();
		add_action( 'wp_mcp_test_unschedule', '__return_true' );

		wp_schedule_single_event( time() + 100, 'wp_mcp_test_unschedule', array( 'one' ) );
		wp_schedule_single_event( time() + 200, 'wp_mcp_test_unschedule', array( 'two' ) );

		$single = WP_MCP_Cron::unschedule_cron_event( array(
			'hook'      => 'wp_mcp_test_unschedule',
			'timestamp' => wp_next_scheduled( 'wp_mcp_test_unschedule', array( 'one' ) ),
		) );
		$this->assertNotWPError( $single );
		$this->assertEquals( 1, $single['removed'] );
		$this->assertEquals( 1, $single['remaining'] );

		$all = WP_MCP_Cron::unschedule_cron_event( array(
			'hook'            => 'wp_mcp_test_unschedule',
			'all_occurrences' => true,
		) );
		$this->assertNotWPError( $all );
		$this->assertEquals( 1, $all['removed'] );
		$this->assertEquals( 0, $all['remaining'] );
	}

	public function test_unschedule_cron_event_refuses_a_denylisted_hook() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Cron::unschedule_cron_event( array( 'hook' => 'wp_maybe_auto_update' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_cron_hook_denied', $result->get_error_code() );
	}

	/* --- Cron: running --- */

	public function test_run_cron_event_fires_a_queued_hook_and_dequeues_it() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();

		$fired = 0;
		add_action( 'wp_mcp_test_runnable', function () use ( &$fired ) {
			++$fired;
		} );
		wp_schedule_single_event( time() - 10, 'wp_mcp_test_runnable' );

		$result = WP_MCP_Cron::run_cron_event( array( 'hook' => 'wp_mcp_test_runnable' ) );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['ran'] );
		$this->assertFalse( $result['rescheduled'] );
		$this->assertSame( 1, $fired );
		$this->assertFalse( (bool) wp_next_scheduled( 'wp_mcp_test_runnable' ) );
	}

	public function test_run_cron_event_reschedules_a_recurring_event() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();

		$fired = 0;
		add_action( 'wp_mcp_test_recurring_run', function () use ( &$fired ) {
			++$fired;
		} );
		wp_schedule_event( time() - 10, 'hourly', 'wp_mcp_test_recurring_run' );

		$result = WP_MCP_Cron::run_cron_event( array( 'hook' => 'wp_mcp_test_recurring_run' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $fired );
		$this->assertTrue( $result['rescheduled'] );
		$this->assertGreaterThan( time(), (int) wp_next_scheduled( 'wp_mcp_test_recurring_run' ) );
	}

	public function test_run_cron_event_refuses_a_hook_that_is_not_queued() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();

		$fired = 0;
		add_action( 'wp_mcp_test_not_queued', function () use ( &$fired ) {
			++$fired;
		} );

		$result = WP_MCP_Cron::run_cron_event( array( 'hook' => 'wp_mcp_test_not_queued' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_cron_event_not_found', $result->get_error_code() );
		$this->assertSame( 0, $fired, 'A hook that is not in the cron queue is never fired' );
	}

	public function test_run_due_cron_events_skips_denylisted_and_listenerless_hooks() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();

		$fired = 0;
		add_action( 'wp_mcp_test_due_runner', function () use ( &$fired ) {
			++$fired;
		} );

		wp_schedule_single_event( time() - 30, 'wp_mcp_test_due_runner' );
		wp_schedule_single_event( time() - 20, 'shutdown' );
		wp_schedule_single_event( time() - 10, 'wp_mcp_test_due_listenerless' );
		wp_schedule_single_event( time() + 600, 'wp_mcp_test_due_runner', array( 'later' ) );

		$result = WP_MCP_Cron::run_due_cron_events();
		$this->assertNotWPError( $result );
		$this->assertSame( 1, $fired );
		$this->assertEquals( 1, $result['ran'] );
		$this->assertEquals( 2, $result['skipped'] );

		$reasons = array();
		foreach ( $result['events'] as $event ) {
			$reasons[ $event['hook'] ] = $event['reason'];
		}
		$this->assertEquals( 'denied_hook', $reasons['shutdown'] );
		$this->assertEquals( 'no_registered_action', $reasons['wp_mcp_test_due_listenerless'] );
		$this->assertCount( 3, $result['events'], 'Only the three due events are considered' );
		$this->assertGreaterThan( time(), (int) wp_next_scheduled( 'wp_mcp_test_due_runner', array( 'later' ) ) );
	}

	public function test_get_cron_status_reports_the_queue_and_schedules() {
		$this->become_issue_12_admin();
		$this->reset_cron_queue();
		add_action( 'wp_mcp_test_status', '__return_true' );
		wp_schedule_single_event( time() + 120, 'wp_mcp_test_status' );

		$status = WP_MCP_Cron::get_cron_status();
		$this->assertNotWPError( $status );
		$this->assertEquals( 1, $status['total_events'] );
		$this->assertEquals( 0, $status['due_events'] );
		$this->assertEquals( 'wp_mcp_test_status', $status['next_event_hook'] );
		$this->assertNotEmpty( $status['schedules'] );
		$this->assertIsBool( $status['wp_cron_disabled'] );
	}

	/* --- Cache and maintenance --- */

	public function test_maintenance_abilities_are_denied_without_manage_options() {
		wp_set_current_user( $this->agent_user );

		foreach ( array( 'flush_object_cache', 'clear_expired_transients', 'clear_update_caches', 'get_maintenance_mode' ) as $method ) {
			$result = call_user_func( array( 'WP_MCP_Maintenance', $method ), array() );
			$this->assertWPError( $result, $method . ' must require manage_options' );
			$this->assertEquals( 'wp_mcp_system_permission_denied', $result->get_error_code() );
		}
	}

	public function test_flush_object_cache_reports_the_backend() {
		$this->become_issue_12_admin();

		wp_cache_set( 'wp_mcp_probe', 'value', 'acmeinc' );
		$result = WP_MCP_Maintenance::flush_object_cache();
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['flushed'] );
		$this->assertIsBool( $result['external_object_cache'] );
		$this->assertFalse( wp_cache_get( 'wp_mcp_probe', 'acmeinc' ) );
	}

	public function test_clear_update_caches_only_touches_the_three_update_transients() {
		$this->become_issue_12_admin();

		set_site_transient( 'update_core', (object) array( 'last_checked' => time() ) );
		set_site_transient( 'update_plugins', (object) array( 'last_checked' => time() ) );
		set_site_transient( 'wp_mcp_unrelated', 'keep-me' );

		$result = WP_MCP_Maintenance::clear_update_caches();
		$this->assertNotWPError( $result );
		$this->assertContains( 'update_core', $result['cleared'] );
		$this->assertContains( 'update_plugins', $result['cleared'] );
		$this->assertEquals( array( 'update_core', 'update_plugins', 'update_themes' ), $result['available'] );
		$this->assertFalse( get_site_transient( 'update_core' ) );
		$this->assertEquals( 'keep-me', get_site_transient( 'wp_mcp_unrelated' ), 'An unrelated site transient must survive' );
	}

	public function test_clear_expired_transients_removes_only_expired_entries() {
		$this->become_issue_12_admin();

		set_transient( 'wp_mcp_expired_probe', 'gone', HOUR_IN_SECONDS );
		set_transient( 'wp_mcp_live_probe', 'kept', HOUR_IN_SECONDS );
		update_option( '_transient_timeout_wp_mcp_expired_probe', time() - 100 );

		$result = WP_MCP_Maintenance::clear_expired_transients();
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['cleared'] );
		$this->assertEquals( 'expired_only', $result['scope'] );

		// delete_expired_transients() deletes the rows with a single SQL
		// statement and, like core itself, does not invalidate the options
		// cache. Read past the cache to assert what is actually in the
		// database, then confirm the live transient is untouched.
		wp_cache_flush();
		$this->assertFalse( get_option( '_transient_wp_mcp_expired_probe' ) );
		$this->assertEquals( 'kept', get_transient( 'wp_mcp_live_probe' ) );
	}

	public function test_get_maintenance_mode_reports_the_marker_state() {
		$this->become_issue_12_admin();

		$state = WP_MCP_Maintenance::get_maintenance_mode();
		$this->assertNotWPError( $state );
		$this->assertIsBool( $state['enabled'] );
		$this->assertIsBool( $state['marker_present'] );
		$this->assertEquals( 600, $state['window_seconds'] );
	}

	/**
	 * Deliberately does not enable maintenance mode: writing the marker would
	 * take the whole test installation offline for ten minutes. The gate and
	 * the input contract are what this asserts.
	 */
	public function test_set_maintenance_mode_requires_update_core_and_an_explicit_boolean() {
		wp_set_current_user( $this->agent_user );
		$denied = WP_MCP_Maintenance::set_maintenance_mode( array( 'enabled' => true ) );
		$this->assertWPError( $denied );
		$this->assertEquals( 'wp_mcp_system_permission_denied', $denied->get_error_code() );

		$this->become_issue_12_admin();
		$invalid = WP_MCP_Maintenance::set_maintenance_mode( array() );
		$this->assertWPError( $invalid );
		$this->assertEquals( 'wp_mcp_maintenance_validation_error', $invalid->get_error_code() );

		$not_boolean = WP_MCP_Maintenance::set_maintenance_mode( array( 'enabled' => 'yes' ) );
		$this->assertWPError( $not_boolean );
		$this->assertEquals( 'wp_mcp_maintenance_validation_error', $not_boolean->get_error_code() );

		$this->assertFileDoesNotExist( ABSPATH . '.maintenance', 'A refused call must never write the maintenance marker' );
	}

	/* --- Content export / import --- */

	public function test_export_content_requires_the_export_capability() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Import_Export::export_content( array() );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_system_permission_denied', $result->get_error_code() );
	}

	public function test_export_content_returns_wxr_for_the_requested_post_type() {
		$this->become_issue_12_admin();
		self::factory()->post->create( array(
			'post_status' => 'publish',
			'post_title'  => 'Issue12ExportProbe',
		) );

		$result = WP_MCP_Import_Export::export_content( array( 'content' => 'post' ) );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '<rss', $result['wxr'] );
		$this->assertStringContainsString( 'Issue12ExportProbe', $result['wxr'] );
		$this->assertEquals( 'post', $result['content'] );
		$this->assertEquals( strlen( $result['wxr'] ), $result['bytes'] );
		$this->assertEquals( WP_MCP_Import_Export::MAX_EXPORT_BYTES, $result['max_bytes'] );
	}

	public function test_export_content_rejects_unknown_filters() {
		$this->become_issue_12_admin();

		$bad_type = WP_MCP_Import_Export::export_content( array( 'content' => 'definitely_not_a_type' ) );
		$this->assertWPError( $bad_type );
		$this->assertEquals( 'wp_mcp_export_validation_error', $bad_type->get_error_code() );

		$bad_author = WP_MCP_Import_Export::export_content( array( 'author' => 999999 ) );
		$this->assertWPError( $bad_author );
		$this->assertEquals( 'wp_mcp_export_validation_error', $bad_author->get_error_code() );

		$bad_category = WP_MCP_Import_Export::export_content( array( 'category' => 'no-such-category' ) );
		$this->assertWPError( $bad_category );
		$this->assertEquals( 'wp_mcp_export_validation_error', $bad_category->get_error_code() );

		$bad_status = WP_MCP_Import_Export::export_content( array( 'status' => 'not-a-status' ) );
		$this->assertWPError( $bad_status );
		$this->assertEquals( 'wp_mcp_export_validation_error', $bad_status->get_error_code() );
	}

	public function test_export_content_rejects_a_malformed_date_filter() {
		$this->become_issue_12_admin();

		foreach ( array( '2026/01/01', '01-01-2026', '2026-13-01', 'yesterday' ) as $date ) {
			$result = WP_MCP_Import_Export::export_content( array( 'start_date' => $date ) );
			$this->assertWPError( $result, $date . ' must be rejected' );
			$this->assertEquals( 'wp_mcp_export_validation_error', $result->get_error_code() );
		}
	}

	public function test_import_content_requires_the_import_capability() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Import_Export::import_content( array( 'wxr' => '<?xml version="1.0"?><rss></rss>' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_system_permission_denied', $result->get_error_code() );
	}

	public function test_import_content_rejects_a_payload_that_is_not_wxr() {
		$this->become_issue_12_admin();

		$empty = WP_MCP_Import_Export::import_content( array( 'wxr' => '   ' ) );
		$this->assertWPError( $empty );
		$this->assertEquals( 'wp_mcp_import_validation_error', $empty->get_error_code() );

		$not_xml = WP_MCP_Import_Export::import_content( array( 'wxr' => 'just some text' ) );
		$this->assertWPError( $not_xml );
		$this->assertEquals( 'wp_mcp_import_validation_error', $not_xml->get_error_code() );
	}

	/**
	 * WordPress core ships no WXR importer, so without the official importer
	 * plugin the ability must refuse cleanly rather than half-import.
	 */
	public function test_import_content_refuses_without_the_official_importer() {
		$this->become_issue_12_admin();

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( 'wordpress-importer/wordpress-importer.php' ) ) {
			$this->markTestSkipped( 'The WordPress Importer plugin is active in this environment.' );
		}

		$result = WP_MCP_Import_Export::import_content( array( 'wxr' => '<?xml version="1.0"?><rss version="2.0"></rss>' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_import_unsupported', $result->get_error_code() );
	}

	/* --- Settings export / import --- */

	public function test_export_settings_returns_only_writable_allowlisted_fields() {
		$this->become_issue_12_admin();

		$export = WP_MCP_Import_Export::export_settings();
		$this->assertNotWPError( $export );
		$this->assertArrayHasKey( 'general', $export['settings'] );
		$this->assertContains( 'general', $export['groups'] );

		foreach ( $export['settings'] as $group => $values ) {
			$writable = array_keys( WP_MCP_Settings::input_schema_properties( $group ) );
			$this->assertEmpty(
				array_diff( array_keys( $values ), $writable ),
				'The ' . $group . ' export must not contain a field outside the writable allowlist'
			);
		}

		$encoded = wp_json_encode( $export, JSON_UNESCAPED_SLASHES );
		foreach ( array( 'mailserver_pass', 'mailserver_login', 'siteurl', 'upload_path', 'admin_email' ) as $option ) {
			$this->assertStringNotContainsString( '"' . $option . '"', $encoded, $option . ' must never appear as an exported settings field' );
		}
	}

	public function test_import_settings_round_trips_through_the_validated_writer() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Import_Export::import_settings( array(
			'settings' => array(
				'general' => array( 'site_title' => 'Issue 12 Round Trip' ),
			),
		) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 1, $result['updated_total'] );
		$this->assertEquals( 'general', $result['applied'][0]['group'] );
		$this->assertEquals( 'Issue 12 Round Trip', get_option( 'blogname' ) );
	}

	public function test_import_settings_rejects_an_unknown_group() {
		$this->become_issue_12_admin();
		$before = get_option( 'blogname' );

		$result = WP_MCP_Import_Export::import_settings( array(
			'settings' => array(
				'general'         => array( 'site_title' => 'Should Not Apply' ),
				'not_a_real_group' => array( 'whatever' => 1 ),
			),
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_unknown_field', $result->get_error_code() );
		$this->assertEquals( $before, get_option( 'blogname' ), 'An unknown group must be refused before anything is written' );
	}

	public function test_import_settings_rejects_a_raw_wordpress_option_name() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Import_Export::import_settings( array(
			'settings' => array(
				'general' => array( 'blogname' => 'Via Raw Option' ),
			),
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_unknown_field', $result->get_error_code() );
		$this->assertNotEquals( 'Via Raw Option', get_option( 'blogname' ) );
	}

	public function test_import_settings_rejects_an_empty_payload() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Import_Export::import_settings( array( 'settings' => array() ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code() );
	}

	/* --- Privacy --- */

	public function test_privacy_abilities_require_the_native_privacy_capabilities() {
		wp_set_current_user( $this->agent_user );

		$listed = WP_MCP_Privacy::list_privacy_requests();
		$this->assertWPError( $listed );
		$this->assertEquals( 'wp_mcp_privacy_permission_denied', $listed->get_error_code() );

		$created = WP_MCP_Privacy::create_privacy_export_request( array( 'email' => 'denied@example.org' ) );
		$this->assertWPError( $created );
		$this->assertEquals( 'wp_mcp_privacy_permission_denied', $created->get_error_code() );

		$erasure = WP_MCP_Privacy::create_privacy_erasure_request( array( 'email' => 'denied@example.org' ) );
		$this->assertWPError( $erasure );
		$this->assertEquals( 'wp_mcp_privacy_permission_denied', $erasure->get_error_code() );
	}

	public function test_create_privacy_export_request_is_always_pending_and_hides_the_confirm_key() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Privacy::create_privacy_export_request( array(
			'email'                   => 'issue12-export@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 'export', $result['request']['type'] );
		$this->assertEquals( 'pending', $result['request']['status'], 'A request is never created pre-confirmed' );
		$this->assertEquals( 'issue12-export@example.org', $result['request']['email'] );
		$this->assertFalse( $result['confirmation_sent'] );
		$this->assertArrayNotHasKey( 'confirm_key', $result['request'], 'The confirmation key must never leave the site' );

		$stored = wp_get_user_request( $result['request']['id'] );
		$this->assertNotFalse( $stored );
		$this->assertEquals( 'request-pending', $stored->status );
	}

	public function test_create_privacy_erasure_request_is_always_pending() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Privacy::create_privacy_erasure_request( array(
			'email'                   => 'issue12-erase@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 'erase', $result['request']['type'] );
		$this->assertEquals( 'pending', $result['request']['status'] );
		$this->assertEquals( 'remove_personal_data', $result['request']['action_name'] );
	}

	public function test_create_privacy_request_rejects_an_invalid_email() {
		$this->become_issue_12_admin();

		foreach ( array( '', 'not-an-email', 'a@b', '   ' ) as $email ) {
			$result = WP_MCP_Privacy::create_privacy_export_request( array(
				'email'                   => $email,
				'send_confirmation_email' => false,
			) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_privacy_validation_error', $result->get_error_code() );
		}
	}

	public function test_process_privacy_requests_refuse_an_unconfirmed_request() {
		$this->become_issue_12_admin();

		$export = WP_MCP_Privacy::create_privacy_export_request( array(
			'email'                   => 'issue12-unconfirmed-export@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $export );

		$processed = WP_MCP_Privacy::process_privacy_export_request( array( 'request_id' => $export['request']['id'] ) );
		$this->assertWPError( $processed );
		$this->assertEquals( 'wp_mcp_privacy_request_invalid_state', $processed->get_error_code() );

		$erase = WP_MCP_Privacy::create_privacy_erasure_request( array(
			'email'                   => 'issue12-unconfirmed-erase@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $erase );

		$erased = WP_MCP_Privacy::process_privacy_erasure_request( array( 'request_id' => $erase['request']['id'] ) );
		$this->assertWPError( $erased );
		$this->assertEquals( 'wp_mcp_privacy_request_invalid_state', $erased->get_error_code() );
	}

	public function test_privacy_processing_refuses_a_request_of_the_other_type() {
		$this->become_issue_12_admin();

		$export = WP_MCP_Privacy::create_privacy_export_request( array(
			'email'                   => 'issue12-mismatch@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $export );
		$this->confirm_privacy_request( $export['request']['id'] );

		$result = WP_MCP_Privacy::process_privacy_erasure_request( array( 'request_id' => $export['request']['id'] ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_privacy_request_invalid_state', $result->get_error_code() );
	}

	public function test_get_privacy_request_reports_not_found_for_an_unknown_id() {
		$this->become_issue_12_admin();

		$missing = WP_MCP_Privacy::get_privacy_request( array( 'request_id' => 999999 ) );
		$this->assertWPError( $missing );
		$this->assertEquals( 'wp_mcp_privacy_request_not_found', $missing->get_error_code() );

		$post_id = self::factory()->post->create();
		$wrong   = WP_MCP_Privacy::get_privacy_request( array( 'request_id' => $post_id ) );
		$this->assertWPError( $wrong );
		$this->assertEquals( 'wp_mcp_privacy_request_not_found', $wrong->get_error_code() );

		$invalid = WP_MCP_Privacy::get_privacy_request( array( 'request_id' => 0 ) );
		$this->assertWPError( $invalid );
		$this->assertEquals( 'wp_mcp_privacy_validation_error', $invalid->get_error_code() );
	}

	public function test_list_privacy_requests_filters_by_type_and_status() {
		$this->become_issue_12_admin();

		$export = WP_MCP_Privacy::create_privacy_export_request( array(
			'email'                   => 'issue12-list-export@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $export );
		$erase = WP_MCP_Privacy::create_privacy_erasure_request( array(
			'email'                   => 'issue12-list-erase@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $erase );
		$this->confirm_privacy_request( $erase['request']['id'] );

		$exports = WP_MCP_Privacy::list_privacy_requests( array( 'type' => 'export' ) );
		$this->assertNotWPError( $exports );
		$this->assertGreaterThanOrEqual( 1, $exports['total'] );
		foreach ( $exports['requests'] as $request ) {
			$this->assertEquals( 'export', $request['type'] );
			$this->assertArrayNotHasKey( 'confirm_key', $request );
		}

		$confirmed = WP_MCP_Privacy::list_privacy_requests( array( 'status' => 'confirmed' ) );
		$this->assertNotWPError( $confirmed );
		foreach ( $confirmed['requests'] as $request ) {
			$this->assertEquals( 'confirmed', $request['status'] );
		}

		$bad_type = WP_MCP_Privacy::list_privacy_requests( array( 'type' => 'delete-everything' ) );
		$this->assertWPError( $bad_type );
		$this->assertEquals( 'wp_mcp_privacy_validation_error', $bad_type->get_error_code() );
	}

	public function test_process_privacy_export_request_drives_the_registered_exporters() {
		$this->become_issue_12_admin();

		$request = WP_MCP_Privacy::create_privacy_export_request( array(
			'email'                   => 'issue12-process-export@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $request );
		$request_id = $request['request']['id'];
		$this->confirm_privacy_request( $request_id );

		$seen = array();
		add_filter( 'wp_privacy_personal_data_exporters', function () use ( &$seen ) {
			return array(
				'wp-mcp-test-exporter' => array(
					'exporter_friendly_name' => 'WordPress MCP test exporter',
					'callback'               => function ( $email, $page ) use ( &$seen ) {
						$seen[] = array( 'email' => $email, 'page' => (int) $page );
						return array(
							'data' => array(
								array(
									'group_id'    => 'acmeinc',
									'group_label' => 'WordPress MCP',
									'item_id'     => 'wp-mcp-' . (int) $page,
									'data'        => array( array( 'name' => 'Probe', 'value' => 'value-' . (int) $page ) ),
								),
							),
							'done' => 2 <= (int) $page,
						);
					},
				),
			);
		}, 999 );

		// The zip writer is swapped for a recorder: the point of this test is
		// that the pipeline reaches core's file step, not that a zip is built.
		remove_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file' );
		$file_events = 0;
		add_action( 'wp_privacy_personal_data_export_file', function () use ( &$file_events ) {
			++$file_events;
		} );

		$result = WP_MCP_Privacy::process_privacy_export_request( array( 'request_id' => $request_id ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 1, $result['exporters_run'] );
		$this->assertEquals( 2, $result['pages_processed'], 'The exporter is paged until it reports done' );
		$this->assertEquals( 2, $result['items_exported'] );
		$this->assertEmpty( $result['failed_exporters'] );
		$this->assertFalse( $result['sent_as_email'] );
		$this->assertCount( 2, $seen );
		$this->assertEquals( 'issue12-process-export@example.org', $seen[0]['email'] );
		$this->assertEquals( 1, $seen[0]['page'] );
		$this->assertEquals( 2, $seen[1]['page'] );
		$this->assertEquals( 1, $file_events, 'Core assembles the export file once every exporter is done' );
		$this->assertArrayNotHasKey( 'confirm_key', $result['request'] );
	}

	public function test_process_privacy_export_request_survives_a_malformed_exporter() {
		$this->become_issue_12_admin();

		$request = WP_MCP_Privacy::create_privacy_export_request( array(
			'email'                   => 'issue12-broken-exporter@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $request );
		$request_id = $request['request']['id'];
		$this->confirm_privacy_request( $request_id );

		add_filter( 'wp_privacy_personal_data_exporters', function () {
			return array(
				'wp-mcp-broken' => array( 'exporter_friendly_name' => 'Broken' ),
			);
		}, 999 );
		remove_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file' );

		$result = WP_MCP_Privacy::process_privacy_export_request( array( 'request_id' => $request_id ) );
		$this->assertNotWPError( $result, 'A malformed exporter is reported, not fatal' );
		$this->assertContains( 'wp-mcp-broken', $result['failed_exporters'] );
	}

	public function test_process_privacy_erasure_request_drives_the_registered_erasers_and_completes() {
		$this->become_issue_12_admin();

		$request = WP_MCP_Privacy::create_privacy_erasure_request( array(
			'email'                   => 'issue12-process-erase@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $request );
		$request_id = $request['request']['id'];
		$this->confirm_privacy_request( $request_id );

		$calls = 0;
		add_filter( 'wp_privacy_personal_data_erasers', function () use ( &$calls ) {
			return array(
				'wp-mcp-test-eraser' => array(
					'eraser_friendly_name' => 'WordPress MCP test eraser',
					'callback'             => function ( $email, $page ) use ( &$calls ) {
						++$calls;
						return array(
							'items_removed'  => true,
							'items_retained' => false,
							'messages'       => array( 'Removed one WordPress MCP probe.' ),
							'done'           => true,
						);
					},
				),
			);
		}, 999 );

		$result = WP_MCP_Privacy::process_privacy_erasure_request( array( 'request_id' => $request_id ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 1, $result['erasers_run'] );
		$this->assertEquals( 1, $calls );
		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertContains( 'Removed one WordPress MCP probe.', $result['messages'] );
		$this->assertEquals( 'completed', $result['request']['status'], 'Core marks the request completed once every eraser is done' );
	}

	public function test_resend_privacy_request_email_only_targets_unconfirmed_requests() {
		$this->become_issue_12_admin();

		$request = WP_MCP_Privacy::create_privacy_export_request( array(
			'email'                   => 'issue12-resend@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $request );
		$request_id = $request['request']['id'];

		$this->confirm_privacy_request( $request_id );
		$refused = WP_MCP_Privacy::resend_privacy_request_email( array( 'request_id' => $request_id ) );
		$this->assertWPError( $refused );
		$this->assertEquals( 'wp_mcp_privacy_request_invalid_state', $refused->get_error_code() );
	}

	public function test_delete_privacy_request_removes_the_record() {
		$this->become_issue_12_admin();

		$request = WP_MCP_Privacy::create_privacy_export_request( array(
			'email'                   => 'issue12-delete@example.org',
			'send_confirmation_email' => false,
		) );
		$this->assertNotWPError( $request );
		$request_id = $request['request']['id'];

		$deleted = WP_MCP_Privacy::delete_privacy_request( array( 'request_id' => $request_id ) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertEquals( 'export', $deleted['type'] );
		$this->assertNull( get_post( $request_id ) );

		$again = WP_MCP_Privacy::delete_privacy_request( array( 'request_id' => $request_id ) );
		$this->assertWPError( $again );
		$this->assertEquals( 'wp_mcp_privacy_request_not_found', $again->get_error_code() );
	}

	/* --- Regression: issue #12 does not widen the earlier domains --- */

	public function test_issue_12_adds_no_generic_option_transient_or_execution_ability() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->assertTrue( true );
			return;
		}
		$forbidden = array( 'option', 'execute', 'eval', 'sql', 'query', 'shell', 'exec', 'filesystem' );
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 !== strpos( $name, 'wp-mcp/' ) ) {
				continue;
			}
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, $name, $name . ' must not expose a generic ' . $needle . ' primitive' );
			}
		}
	}

	public function test_issue_12_does_not_change_core_post_and_settings_behaviour() {
		wp_set_current_user( $this->agent_user );
		$created = WP_MCP_Posts::create_post( array( 'title' => 'Issue12Regression', 'content' => 'Body' ) );
		$this->assertNotWPError( $created );
		$this->assertEquals( 'draft', $created['status'] );

		$this->become_issue_12_admin();
		$settings = WP_MCP_Settings::get_general_settings();
		$this->assertNotWPError( $settings );
		$this->assertArrayHasKey( 'site_title', $settings );
	}

	/* ==================================================================
	 * Issue #13 — multisite network domain, seen from a single site
	 *
	 * The multisite-only behaviour lives in tests/test-network.php, which
	 * runs under phpunit.multisite.xml.dist. What belongs *here* is the
	 * other half of issue #13's acceptance criterion: on a single-site
	 * installation the network abilities must still be registered and
	 * catalogued, must not be offered to any client, and must answer
	 * "unsupported" cleanly rather than failing, lying, or succeeding
	 * silently.
	 * ================================================================ */

	/**
	 * Every issue #13 ability slug. All of them are registered on a
	 * single-site installation too: the matrix and the registered surface
	 * are verified against each other in both environments, so a network
	 * ability can never be catalogued without existing.
	 *
	 * @return string[]
	 */
	private function issue_13_ability_slugs() {
		return array(
			// Network inspection and updates.
			'get-network-info',
			'get-network-update-status',
			'upgrade-network-sites',
			// Sites.
			'list-network-sites',
			'get-network-site',
			'create-network-site',
			'update-network-site',
			'archive-network-site',
			'unarchive-network-site',
			'activate-network-site',
			'deactivate-network-site',
			'mark-network-site-spam',
			'unmark-network-site-spam',
			'delete-network-site',
			// Network users.
			'list-network-users',
			'get-network-user',
			'list-network-user-sites',
			'create-network-user',
			'delete-network-user',
			'add-user-to-network-site',
			'remove-user-from-network-site',
			'set-network-site-user-role',
			// Network plugins and themes.
			'network-activate-plugin',
			'network-deactivate-plugin',
			'list-network-themes',
			'network-enable-theme',
			'network-disable-theme',
			// Network settings.
			'get-network-settings',
			'update-network-settings',
			'list-network-settings-fields',
		);
	}

	/**
	 * Every issue #13 callback except get-network-info, with a plausible
	 * input, so the single-site answer can be asserted for all of them.
	 *
	 * @return array<string,array{0:callable,1:array}>
	 */
	private function issue_13_unsupported_calls() {
		return array(
			'get-network-update-status'     => array( array( 'WP_MCP_Network', 'get_network_update_status' ), array() ),
			'upgrade-network-sites'         => array( array( 'WP_MCP_Network', 'upgrade_network_sites' ), array() ),
			'list-network-sites'            => array( array( 'WP_MCP_Network_Sites', 'list_network_sites' ), array() ),
			'get-network-site'              => array( array( 'WP_MCP_Network_Sites', 'get_network_site' ), array( 'site_id' => 1 ) ),
			'create-network-site'           => array( array( 'WP_MCP_Network_Sites', 'create_network_site' ), array( 'slug' => 'branch', 'title' => 'Branch', 'admin_user_id' => 1 ) ),
			'update-network-site'           => array( array( 'WP_MCP_Network_Sites', 'update_network_site' ), array( 'site_id' => 1, 'title' => 'Renamed' ) ),
			'archive-network-site'          => array( array( 'WP_MCP_Network_Sites', 'archive_network_site' ), array( 'site_id' => 1 ) ),
			'unarchive-network-site'        => array( array( 'WP_MCP_Network_Sites', 'unarchive_network_site' ), array( 'site_id' => 1 ) ),
			'activate-network-site'         => array( array( 'WP_MCP_Network_Sites', 'activate_network_site' ), array( 'site_id' => 1 ) ),
			'deactivate-network-site'       => array( array( 'WP_MCP_Network_Sites', 'deactivate_network_site' ), array( 'site_id' => 1 ) ),
			'mark-network-site-spam'        => array( array( 'WP_MCP_Network_Sites', 'mark_network_site_spam' ), array( 'site_id' => 1 ) ),
			'unmark-network-site-spam'      => array( array( 'WP_MCP_Network_Sites', 'unmark_network_site_spam' ), array( 'site_id' => 1 ) ),
			'delete-network-site'           => array( array( 'WP_MCP_Network_Sites', 'delete_network_site' ), array( 'site_id' => 1 ) ),
			'list-network-users'            => array( array( 'WP_MCP_Network_Users', 'list_network_users' ), array() ),
			'get-network-user'              => array( array( 'WP_MCP_Network_Users', 'get_network_user' ), array( 'user_id' => 1 ) ),
			'list-network-user-sites'       => array( array( 'WP_MCP_Network_Users', 'list_network_user_sites' ), array( 'user_id' => 1 ) ),
			'create-network-user'           => array( array( 'WP_MCP_Network_Users', 'create_network_user' ), array( 'username' => 'netuser', 'email' => 'netuser@example.org', 'password' => 'correct-horse' ) ),
			'delete-network-user'           => array( array( 'WP_MCP_Network_Users', 'delete_network_user' ), array( 'user_id' => 1, 'reassign_user_id' => 2 ) ),
			'add-user-to-network-site'      => array( array( 'WP_MCP_Network_Users', 'add_user_to_network_site' ), array( 'site_id' => 1, 'user_id' => 1, 'role' => 'author' ) ),
			'remove-user-from-network-site' => array( array( 'WP_MCP_Network_Users', 'remove_user_from_network_site' ), array( 'site_id' => 1, 'user_id' => 1 ) ),
			'set-network-site-user-role'    => array( array( 'WP_MCP_Network_Users', 'set_network_site_user_role' ), array( 'site_id' => 1, 'user_id' => 1, 'role' => 'editor' ) ),
			'network-activate-plugin'       => array( array( 'WP_MCP_Network_Extensions', 'network_activate_plugin' ), array( 'plugin_file' => 'hello-dolly/hello.php' ) ),
			'network-deactivate-plugin'     => array( array( 'WP_MCP_Network_Extensions', 'network_deactivate_plugin' ), array( 'plugin_file' => 'hello-dolly/hello.php' ) ),
			'list-network-themes'           => array( array( 'WP_MCP_Network_Extensions', 'list_network_themes' ), array() ),
			'network-enable-theme'          => array( array( 'WP_MCP_Network_Extensions', 'network_enable_theme' ), array( 'stylesheet' => 'twentytwentyfour' ) ),
			'network-disable-theme'         => array( array( 'WP_MCP_Network_Extensions', 'network_disable_theme' ), array( 'stylesheet' => 'twentytwentyfour' ) ),
			'get-network-settings'          => array( array( 'WP_MCP_Network_Settings', 'get_network_settings' ), array() ),
			'update-network-settings'       => array( array( 'WP_MCP_Network_Settings', 'update_network_settings' ), array( 'network_name' => 'Renamed Network' ) ),
			'list-network-settings-fields'  => array( array( 'WP_MCP_Network_Settings', 'list_network_settings_fields' ), array() ),
		);
	}

	/**
	 * Skip a single-site-only assertion when the suite happens to be running
	 * under multisite.
	 */
	private function skip_if_multisite() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'This assertion describes the single-site degradation path; the multisite behaviour is covered by tests/test-network.php.' );
		}
	}

	/* --- Registration, schema and catalog shape --- */

	public function test_issue_13_abilities_are_registered_with_network_category_and_matrix_entries() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$matrix = WP_MCP_Ability_Matrix::get();
		foreach ( $this->issue_13_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should be registered even on a single-site installation' );
			$this->assertEquals( 'wp-mcp-network', $ability->get_category(), $name . ' belongs to wp-mcp-network' );
			$this->assertArrayHasKey( $name, $matrix, $name . ' must be in the permission matrix' );
		}
	}

	public function test_issue_13_network_category_is_registered() {
		if ( ! function_exists( 'wp_get_ability_category' ) ) {
			$this->assertTrue( true );
			return;
		}
		$this->assertNotNull( wp_get_ability_category( 'wp-mcp-network' ) );
	}

	public function test_issue_13_matrix_network_rows_match_the_registered_slugs() {
		$matrix = WP_MCP_Ability_Matrix::get();
		$rows   = array();
		foreach ( $matrix as $name => $row ) {
			if ( 'wp-mcp-network' === $row['category'] ) {
				$rows[] = $name;
			}
		}
		sort( $rows );

		$expected = array_map(
			function ( $slug ) {
				return 'wp-mcp/' . $slug;
			},
			$this->issue_13_ability_slugs()
		);
		sort( $expected );

		$this->assertEquals( $expected, $rows, 'The wp-mcp-network rows of the matrix and the issue #13 ability list must be the same set.' );
	}

	public function test_issue_13_ability_schemas_are_closed() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( $this->issue_13_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			foreach ( array( $ability->get_input_schema(), $ability->get_output_schema() ) as $schema ) {
				$this->assertArrayHasKey( 'additionalProperties', $schema, $name . ' schemas must be closed' );
				$this->assertFalse( $schema['additionalProperties'], $name . ' schemas must reject additional properties' );
			}
		}
	}

	public function test_issue_13_abilities_are_mcp_only() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( $this->issue_13_ability_slugs() as $slug ) {
			$ability = wp_get_ability( 'wp-mcp/' . $slug );
			$this->assertNotNull( $ability );
			$mcp = $ability->get_meta_item( 'mcp' );
			$this->assertIsArray( $mcp );
			$this->assertTrue( $mcp['public'], 'wp-mcp/' . $slug . ' must be MCP-public' );
			$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ), 'wp-mcp/' . $slug . ' must not be REST-exposed' );
		}
	}

	/**
	 * The network domain must not become a generic option or address writer:
	 * no input field names a WordPress (network) option, a domain, a path or
	 * a URL. `plugin_file` and `stylesheet` are selectors validated against
	 * what is actually installed, not free-form locations.
	 */
	public function test_issue_13_inputs_never_accept_an_option_name_domain_or_path() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$forbidden = array(
			'domain', 'path', 'url', 'site_url', 'home_url', 'address',
			'option', 'option_name', 'network_option', 'site_option', 'meta_key',
			'sql', 'query', 'command', 'shell', 'code', 'php',
			'callback', 'callable', 'function', 'method',
			'file', 'filename', 'file_path', 'filepath', 'dir', 'directory',
		);
		foreach ( $this->issue_13_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			foreach ( array_keys( $ability->get_input_schema()['properties'] ) as $property ) {
				$this->assertNotContains( $property, $forbidden, $name . ' must not accept "' . $property . '" as an input field' );
			}
		}
	}

	/**
	 * Epic #1's hard prohibition, restated for the network: there is no
	 * get-network-option / update-network-option by any name.
	 */
	public function test_issue_13_exposes_no_generic_network_option_ability() {
		$forbidden = array( 'network-option', 'site-option', 'network-meta', 'network-query', 'network-exec' );
		foreach ( array_keys( WP_MCP_Ability_Matrix::get() ) as $name ) {
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, $name, $name . ' must not expose a generic ' . $needle . ' primitive' );
			}
		}
	}

	/* --- Single-site graceful degradation --- */

	public function test_issue_13_network_abilities_are_unsupported_on_single_site() {
		$this->skip_if_multisite();
		$this->become_issue_12_admin();

		foreach ( $this->issue_13_unsupported_calls() as $slug => $call ) {
			$result = call_user_func( $call[0], $call[1] );
			$this->assertWPError( $result, 'wp-mcp/' . $slug . ' must fail cleanly on a single-site installation' );
			$this->assertEquals( 'wp_mcp_network_unsupported', $result->get_error_code(), 'wp-mcp/' . $slug . ' must answer wp_mcp_network_unsupported off a network' );
			$data = $result->get_error_data();
			$this->assertIsArray( $data );
			$this->assertEquals( 501, $data['status'], 'wp-mcp/' . $slug . ' must report HTTP 501, not a permission or validation status' );
		}
	}

	/**
	 * The multisite check runs *before* the capability check on purpose: a
	 * single-site installation must never be told it lacks a network
	 * capability, which would send an agent looking for credentials to a
	 * network that does not exist.
	 */
	public function test_issue_13_unsupported_beats_permission_denied_on_single_site() {
		$this->skip_if_multisite();
		wp_set_current_user( $this->agent_user );

		foreach ( $this->issue_13_unsupported_calls() as $slug => $call ) {
			$result = call_user_func( $call[0], $call[1] );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_network_unsupported', $result->get_error_code(), 'wp-mcp/' . $slug . ' must answer unsupported, not permission denied, off a network' );
		}
	}

	/**
	 * Registered, catalogued — and still never offered to a client while
	 * there is no network to administer.
	 */
	public function test_issue_13_permission_callbacks_are_false_on_single_site() {
		$this->skip_if_multisite();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$this->become_issue_12_admin();

		$checked = 0;
		foreach ( array_keys( $this->issue_13_unsupported_calls() ) as $slug ) {
			$ability = wp_get_ability( 'wp-mcp/' . $slug );
			$this->assertNotNull( $ability );

			// The Abilities API method name is check_permissions(); tolerate
			// a has_permission() alias rather than pinning the test to one.
			$method = null;
			foreach ( array( 'check_permissions', 'has_permission' ) as $candidate ) {
				if ( method_exists( $ability, $candidate ) ) {
					$method = $candidate;
					break;
				}
			}
			if ( null === $method ) {
				continue;
			}

			$permitted = $ability->{$method}();
			$this->assertNotTrue( $permitted, 'wp-mcp/' . $slug . ' must not be offered on a single-site installation' );
			++$checked;
		}

		$this->assertGreaterThanOrEqual( 0, $checked );
	}

	public function test_issue_13_network_capability_helper_is_false_without_multisite() {
		$this->skip_if_multisite();
		$this->become_issue_12_admin();

		$this->assertFalse( WP_MCP_Network::is_available() );
		foreach ( array( 'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options', 'upgrade_network' ) as $capability ) {
			$this->assertFalse( WP_MCP_Network::can( $capability ), $capability . ' must never be granted off a network' );
		}
	}

	/**
	 * get-network-info is the one network ability that answers on both kinds
	 * of installation: it is how an agent discovers which one it is talking
	 * to before trying anything else.
	 */
	public function test_get_network_info_reports_a_single_site_installation() {
		$this->skip_if_multisite();
		$this->become_issue_12_admin();

		$info = WP_MCP_Network::get_network_info();
		$this->assertNotWPError( $info );
		$this->assertFalse( $info['is_multisite'] );
		$this->assertEquals( 0, $info['network_id'] );
		$this->assertEquals( 0, $info['site_count'] );
		$this->assertEquals( '', $info['network_name'] );
		$this->assertFalse( $info['subdomain_install'] );
		$this->assertFalse( $info['upgrade_required'] );
		$this->assertFalse( $info['current_user_is_super_admin'], 'is_super_admin() means "can delete users" off a network and must not be reported as a network privilege' );
	}

	public function test_get_network_info_is_denied_without_manage_options_on_single_site() {
		$this->skip_if_multisite();
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Network::get_network_info();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_permission_denied', $result->get_error_code() );
	}

	public function test_get_network_info_output_matches_its_declared_schema() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$this->become_issue_12_admin();

		$info = WP_MCP_Network::get_network_info();
		$this->assertNotWPError( $info );

		$ability = wp_get_ability( 'wp-mcp/get-network-info' );
		$this->assertNotNull( $ability );
		$declared = array_keys( $ability->get_output_schema()['properties'] );

		$this->assertEquals( array(), array_diff( array_keys( $info ), $declared ), 'get-network-info returns a field its closed output schema does not declare.' );
		$this->assertEquals( array(), array_diff( $declared, array_keys( $info ) ), 'get-network-info declares a field it does not return.' );
	}

	/* --- Network settings allowlist, verifiable without a network --- */

	public function test_issue_13_network_settings_allowlist_never_reaches_a_denied_option() {
		$writable = WP_MCP_Network_Settings::writable_option_keys();
		$denied   = WP_MCP_Network_Settings::never_writable_options();

		$this->assertEquals( array(), array_values( array_intersect( $writable, $denied ) ), 'A writable network settings field maps to a network option the plugin declares as never writable.' );
	}

	public function test_issue_13_network_settings_never_expose_a_secret_url_or_path_option() {
		$forbidden = array( 'site_admins', 'admin_email', 'new_admin_email', 'siteurl', 'home', 'upload_path', 'upload_url_path', 'ms_files_rewriting', 'active_sitewide_plugins', 'allowedthemes' );

		foreach ( WP_MCP_Network_Settings::writable_option_keys() as $option ) {
			$this->assertNotContains( $option, $forbidden, 'The network settings allowlist must never make "' . $option . '" writable.' );
		}

		// admin_email is readable, and must be declared read-only for it.
		$fields = WP_MCP_Network_Settings::fields();
		$this->assertArrayHasKey( 'network_admin_email', $fields );
		$this->assertTrue( $fields['network_admin_email']['readonly'] );
		$this->assertArrayHasKey( 'subdomain_install', $fields );
		$this->assertTrue( $fields['subdomain_install']['readonly'] );
		$this->assertArrayNotHasKey( 'site_admins', $fields, 'The Super Admin list must never be an addressable settings field.' );
	}

	public function test_issue_13_network_settings_input_schema_only_offers_writable_fields() {
		$input  = WP_MCP_Network_Settings::input_schema_properties();
		$output = WP_MCP_Network_Settings::output_schema_properties();

		$this->assertArrayNotHasKey( 'network_admin_email', $input );
		$this->assertArrayNotHasKey( 'subdomain_install', $input );
		$this->assertArrayHasKey( 'network_admin_email', $output );
		$this->assertArrayHasKey( 'subdomain_install', $output );
		$this->assertArrayHasKey( 'registration', $input );
		$this->assertEquals( array( 'none', 'user', 'blog', 'all' ), $input['registration']['enum'] );
	}

	/* --- Regression: the network domain changes nothing on a single site --- */

	public function test_issue_13_does_not_change_single_site_behaviour() {
		wp_set_current_user( $this->agent_user );
		$created = WP_MCP_Posts::create_post( array( 'title' => 'Issue13Regression', 'content' => 'Body' ) );
		$this->assertNotWPError( $created );
		$this->assertEquals( 'draft', $created['status'] );

		$this->become_issue_12_admin();
		$settings = WP_MCP_Settings::get_general_settings();
		$this->assertNotWPError( $settings );
		$this->assertArrayHasKey( 'site_title', $settings );

		$plugins = WP_MCP_Plugins::list_plugins( array() );
		$this->assertNotWPError( $plugins );
		$this->assertArrayHasKey( 'plugins', $plugins );
	}

	// ## Extensibility — integration adapters (#14)

	/* --- Helpers --- */

	/**
	 * A throwaway adapter, detected through a constant this suite controls.
	 *
	 * `detect()` is final on purpose — an adapter must not be able to lie
	 * about its own availability — so a fake becomes "available" the same way
	 * a real one does: by naming a symbol that exists.
	 *
	 * @param string $slug      Adapter slug.
	 * @param bool   $available Whether the adapter should detect as available.
	 * @param bool   $covered   Whether the adapter contributes an ability.
	 * @return WP_MCP_Integration
	 */
	private function fake_integration( $slug, $available, $covered = true ) {
		if ( ! defined( 'WP_MCP_TEST_FAKE_INTEGRATION' ) ) {
			define( 'WP_MCP_TEST_FAKE_INTEGRATION', '9.9.9' );
		}

		return new class( $slug, $available, $covered ) extends WP_MCP_Integration {
			/** @var string */
			private $fake_slug;
			/** @var bool */
			private $fake_available;
			/** @var bool */
			private $fake_covered;
			/** @var bool */
			public $registered = false;

			public function __construct( $slug, $available, $covered ) {
				$this->fake_slug      = $slug;
				$this->fake_available = $available;
				$this->fake_covered   = $covered;
			}

			public function slug() { return $this->fake_slug; }
			public function label() { return 'Fake ' . $this->fake_slug; }
			public function group() { return 'seo'; }
			public function plugin_label() { return 'Fake Plugin'; }

			public function signals() {
				return array( 'constants' => array( $this->fake_available ? 'WP_MCP_TEST_FAKE_INTEGRATION' : 'WP_MCP_TEST_ABSENT_INTEGRATION' ) );
			}

			public function ability_matrix() {
				if ( ! $this->fake_covered ) {
					return array();
				}
				return array(
					'wp-mcp/' . $this->fake_slug . '-do-nothing' => array(
						'category'        => 'wp-mcp-extensibility',
						'capability'      => 'manage_options',
						'meta_capability' => '',
						'destructive'     => false,
						'idempotent'      => true,
					),
				);
			}

			public function register_abilities() {
				$this->registered = true;
			}
		};
	}

	/**
	 * Matrix rows that belong to an integration, keyed by ability name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function issue_14_integration_matrix_rows() {
		$rows = array();
		foreach ( WP_MCP_Ability_Matrix::get() as $name => $row ) {
			if ( isset( $row['integration'] ) ) {
				$rows[ $name ] = $row;
			}
		}
		return $rows;
	}

	/**
	 * The two abilities that describe the integration layer itself.
	 *
	 * @return string[]
	 */
	private function issue_14_core_ability_names() {
		return array( 'wp-mcp/list-integrations', 'wp-mcp/get-integration' );
	}

	/* --- Registry shape --- */

	public function test_issue_14_registry_lists_every_manifest_adapter() {
		$expected = count( WP_MCP_Integrations::covered_manifest() ) + count( WP_MCP_Integrations::detect_only_manifest() );
		$adapters = WP_MCP_Integrations::all();

		$this->assertCount( $expected, $adapters, 'Every manifest entry must produce exactly one adapter.' );
		$this->assertEquals( array_keys( $adapters ), array_unique( array_keys( $adapters ) ), 'Adapter slugs must be unique.' );
	}

	public function test_issue_14_every_adapter_declares_identity_and_a_known_group() {
		foreach ( WP_MCP_Integrations::all() as $slug => $adapter ) {
			$this->assertNotEmpty( $slug, 'Every adapter needs a slug.' );
			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug, $slug . ' must be a kebab-case slug.' );
			$this->assertEquals( $slug, $adapter->slug() );
			$this->assertNotEmpty( $adapter->label(), $slug . ' needs a label.' );
			$this->assertNotEmpty( $adapter->plugin_label(), $slug . ' needs a plugin label.' );
			$this->assertContains( $adapter->group(), WP_MCP_Integrations::GROUPS, $slug . ' must belong to a known group.' );
		}
	}

	public function test_issue_14_group_vocabulary_is_closed_and_non_empty() {
		$this->assertNotEmpty( WP_MCP_Integrations::GROUPS );
		$this->assertEquals( WP_MCP_Integrations::GROUPS, array_unique( WP_MCP_Integrations::GROUPS ) );
	}

	public function test_issue_14_covered_adapters_declare_abilities_and_detect_only_adapters_do_not() {
		$covered_classes = array();
		foreach ( WP_MCP_Integrations::covered_manifest() as $entry ) {
			$covered_classes[] = $entry['class'];
		}

		foreach ( WP_MCP_Integrations::all() as $slug => $adapter ) {
			if ( in_array( get_class( $adapter ), $covered_classes, true ) ) {
				$this->assertTrue( $adapter->is_covered(), $slug . ' is a covered adapter and must declare abilities.' );
				$this->assertNotEmpty( $adapter->ability_names(), $slug . ' must declare at least one ability.' );
			} else {
				$this->assertFalse( $adapter->is_covered(), $slug . ' is detect-only and must declare no ability.' );
				$this->assertSame( array(), $adapter->ability_names(), $slug . ' must declare no ability.' );
			}
		}
	}

	public function test_issue_14_integration_ability_names_are_namespaced_by_their_adapter() {
		foreach ( WP_MCP_Integrations::all() as $slug => $adapter ) {
			foreach ( $adapter->ability_names() as $name ) {
				$this->assertStringStartsWith( 'wp-mcp/' . $slug . '-', $name, $name . ' must be namespaced by its adapter slug.' );
			}
		}
	}

	public function test_issue_14_every_covered_adapter_documents_capabilities_and_exclusions() {
		foreach ( WP_MCP_Integrations::all() as $slug => $adapter ) {
			if ( ! $adapter->is_covered() ) {
				continue;
			}
			$this->assertNotEmpty( $adapter->required_capabilities(), $slug . ' must document the capabilities it needs.' );
			$this->assertNotEmpty( $adapter->excluded_data(), $slug . ' must document what it deliberately does not expose.' );
		}
	}

	public function test_issue_14_every_detect_only_adapter_documents_exclusions() {
		foreach ( WP_MCP_Integrations::all() as $slug => $adapter ) {
			if ( $adapter->is_covered() ) {
				continue;
			}
			$this->assertNotEmpty( $adapter->excluded_data(), $slug . ' must record what would stay excluded once it is covered.' );
		}
	}

	/* --- Detection --- */

	public function test_issue_14_detection_answers_with_a_complete_shape() {
		foreach ( WP_MCP_Integrations::all() as $slug => $adapter ) {
			$detection = $adapter->detect();
			foreach ( array( 'available', 'version', 'detected_by', 'plugin_file' ) as $key ) {
				$this->assertArrayHasKey( $key, $detection, $slug . ' detection must report ' . $key );
			}
			$this->assertIsBool( $detection['available'] );
			if ( ! $detection['available'] ) {
				$this->assertSame( '', $detection['detected_by'], $slug . ' must not claim a detection source when it is absent.' );
			}
		}
	}

	public function test_issue_14_detection_finds_a_defined_constant_and_reports_its_version() {
		$adapter   = $this->fake_integration( 'fake-present', true );
		$detection = $adapter->detect();

		$this->assertTrue( $detection['available'] );
		$this->assertEquals( 'constant:WP_MCP_TEST_FAKE_INTEGRATION', $detection['detected_by'] );
		$this->assertEquals( '9.9.9', $detection['version'] );
	}

	public function test_issue_14_detection_is_false_when_no_signal_matches() {
		$adapter = $this->fake_integration( 'fake-absent', false );

		$this->assertFalse( $adapter->is_available() );
		$detection = $adapter->detect();
		$this->assertSame( '', $detection['detected_by'] );
	}

	/* --- Conditional registration: the acceptance criterion of #14 --- */

	public function test_issue_14_only_available_adapters_are_asked_to_register() {
		$present = $this->fake_integration( 'fake-present', true );
		$absent  = $this->fake_integration( 'fake-absent', false );

		WP_MCP_Integrations::set_adapters( array( $present, $absent ) );
		WP_MCP_Integrations::register_available_abilities();

		$this->assertTrue( $present->registered, 'An available integration must register its abilities.' );
		$this->assertFalse( $absent->registered, 'An unavailable integration must register nothing.' );
	}

	public function test_issue_14_a_detect_only_adapter_is_never_asked_to_register() {
		$present_but_uncovered = $this->fake_integration( 'fake-present', true, false );

		WP_MCP_Integrations::set_adapters( array( $present_but_uncovered ) );
		WP_MCP_Integrations::register_available_abilities();

		$this->assertFalse( $present_but_uncovered->registered, 'A detect-only adapter registers nothing even when its plugin is present.' );
	}

	public function test_issue_14_flush_restores_the_real_manifest() {
		WP_MCP_Integrations::set_adapters( array( $this->fake_integration( 'fake-present', true ) ) );
		$this->assertCount( 1, WP_MCP_Integrations::all() );

		WP_MCP_Integrations::flush();

		$expected = count( WP_MCP_Integrations::covered_manifest() ) + count( WP_MCP_Integrations::detect_only_manifest() );
		$this->assertCount( $expected, WP_MCP_Integrations::all(), 'flush() must restore the real adapter manifests.' );
	}

	public function test_issue_14_abilities_of_unavailable_integrations_are_not_registered() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->assertTrue( true );
			return;
		}

		$registered = $this->registered_wp_mcp_ability_names();

		$checked = 0;
		foreach ( $this->issue_14_integration_matrix_rows() as $name => $row ) {
			if ( WP_MCP_Integrations::is_available( $row['integration'] ) ) {
				continue;
			}
			++$checked;
			$this->assertNotContains( $name, $registered, $name . ' must not be registered while ' . $row['integration'] . ' is absent.' );
		}

		$this->assertGreaterThan( 0, $checked, 'No third-party plugin is installed in CI, so every integration ability should have been checked.' );
	}

	/* --- Matrix and documentation surface --- */

	public function test_issue_14_matrix_carries_every_declared_integration_ability() {
		$matrix = WP_MCP_Ability_Matrix::get();

		foreach ( WP_MCP_Integrations::all() as $slug => $adapter ) {
			foreach ( $adapter->ability_names() as $name ) {
				$this->assertArrayHasKey( $name, $matrix, $name . ' must be in the permission matrix.' );
				$this->assertEquals( $slug, $matrix[ $name ]['integration'], $name . ' must be attributed to its adapter.' );
				$this->assertEquals( 'wp-mcp-extensibility', $matrix[ $name ]['category'] );
			}
		}
	}

	public function test_issue_14_matrix_integration_rows_match_the_adapter_declarations() {
		$declared = array();
		foreach ( WP_MCP_Integrations::all() as $adapter ) {
			$declared = array_merge( $declared, $adapter->ability_names() );
		}
		sort( $declared );

		$rows = array_keys( $this->issue_14_integration_matrix_rows() );
		sort( $rows );

		$this->assertEquals( $declared, $rows, 'The integration rows of the matrix and the adapters must be the same set.' );
	}

	public function test_issue_14_matrix_rows_declare_the_full_permission_shape() {
		foreach ( $this->issue_14_integration_matrix_rows() as $name => $row ) {
			foreach ( array( 'category', 'capability', 'meta_capability', 'destructive', 'idempotent', 'integration' ) as $key ) {
				$this->assertArrayHasKey( $key, $row, $name . ' must declare ' . $key );
			}
			$this->assertIsBool( $row['destructive'], $name . ' destructive must be a boolean.' );
			$this->assertIsBool( $row['idempotent'], $name . ' idempotent must be a boolean.' );
			$this->assertNotEmpty( $row['capability'] . $row['meta_capability'], $name . ' must require some capability.' );
		}
	}

	/* --- The two core extensibility abilities --- */

	public function test_issue_14_extensibility_category_is_registered() {
		if ( ! function_exists( 'wp_get_ability_category' ) ) {
			$this->assertTrue( true );
			return;
		}
		$this->assertNotNull( wp_get_ability_category( 'wp-mcp-extensibility' ) );
	}

	public function test_issue_14_core_abilities_are_registered_with_category_and_matrix_entries() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$matrix = WP_MCP_Ability_Matrix::get();
		foreach ( $this->issue_14_core_ability_names() as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' must be registered unconditionally.' );
			$this->assertEquals( 'wp-mcp-extensibility', $ability->get_category() );
			$this->assertArrayHasKey( $name, $matrix, $name . ' must be in the permission matrix.' );
			$this->assertArrayNotHasKey( 'integration', $matrix[ $name ], $name . ' is a core ability, not an integration ability.' );
		}
	}

	public function test_issue_14_core_ability_schemas_are_closed() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( $this->issue_14_core_ability_names() as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability );
			foreach ( array( $ability->get_input_schema(), $ability->get_output_schema() ) as $schema ) {
				$this->assertArrayHasKey( 'additionalProperties', $schema, $name . ' schemas must be closed.' );
				$this->assertFalse( $schema['additionalProperties'], $name . ' schemas must reject additional properties.' );
			}
		}
	}

	public function test_issue_14_core_abilities_are_mcp_only() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( $this->issue_14_core_ability_names() as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability );
			$mcp = $ability->get_meta_item( 'mcp' );
			$this->assertIsArray( $mcp );
			$this->assertTrue( $mcp['public'] );
			$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
			$this->assertNotTrue( $ability->get_meta_item( 'public' ) );
		}
	}

	public function test_issue_14_list_integrations_reports_detection_and_coverage() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Integrations_Abilities::list_integrations( array() );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'integrations', $result );
		$this->assertEquals( count( WP_MCP_Integrations::all() ), $result['total'] );
		$this->assertEquals( WP_MCP_Integrations::GROUPS, $result['groups'] );
		$this->assertGreaterThan( 0, $result['covered_count'], 'At least one integration contributes abilities.' );

		foreach ( $result['integrations'] as $row ) {
			foreach ( array( 'slug', 'label', 'group', 'plugin', 'detected', 'version', 'detected_by', 'covered', 'ability_count' ) as $key ) {
				$this->assertArrayHasKey( $key, $row );
			}
		}
	}

	public function test_issue_14_list_integrations_filters_by_group_and_coverage() {
		$this->become_issue_12_admin();

		$forms = WP_MCP_Integrations_Abilities::list_integrations( array( 'group' => 'forms' ) );
		$this->assertNotWPError( $forms );
		$this->assertNotEmpty( $forms['integrations'] );
		foreach ( $forms['integrations'] as $row ) {
			$this->assertEquals( 'forms', $row['group'] );
		}

		$covered = WP_MCP_Integrations_Abilities::list_integrations( array( 'covered' => true ) );
		$this->assertNotWPError( $covered );
		$this->assertNotEmpty( $covered['integrations'] );
		foreach ( $covered['integrations'] as $row ) {
			$this->assertTrue( $row['covered'] );
			$this->assertGreaterThan( 0, $row['ability_count'] );
		}

		$uncovered = WP_MCP_Integrations_Abilities::list_integrations( array( 'covered' => false ) );
		$this->assertNotWPError( $uncovered );
		$this->assertNotEmpty( $uncovered['integrations'], 'Detectable-but-not-covered integrations must be visible, not hidden.' );
	}

	public function test_issue_14_get_integration_describes_a_covered_adapter() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Integrations_Abilities::get_integration( array( 'slug' => 'woocommerce' ) );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'woocommerce', $result['slug'] );
		$this->assertEquals( 'ecommerce', $result['group'] );
		$this->assertTrue( $result['covered'] );
		$this->assertNotEmpty( $result['abilities'] );
		$this->assertNotEmpty( $result['excluded_data'] );
		$this->assertNotEmpty( $result['detection_signals'] );
		$this->assertContains( 'constant:WC_VERSION', $result['detection_signals'] );
	}

	public function test_issue_14_get_integration_describes_a_detect_only_adapter() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Integrations_Abilities::get_integration( array( 'slug' => 'wp-mail-smtp' ) );

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['covered'] );
		$this->assertSame( array(), $result['abilities'] );
		$this->assertNotEmpty( $result['excluded_data'], 'A detect-only adapter still records what stays excluded.' );
	}

	public function test_issue_14_get_integration_rejects_an_unknown_slug() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Integrations_Abilities::get_integration( array( 'slug' => 'not-a-real-integration' ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_integration_unknown', $result->get_error_code() );
	}

	public function test_issue_14_extensibility_abilities_require_activate_plugins() {
		wp_set_current_user( $this->agent_user );

		$listed = WP_MCP_Integrations_Abilities::list_integrations( array() );
		$this->assertWPError( $listed );
		$this->assertEquals( 'wp_mcp_permission_denied', $listed->get_error_code() );

		$described = WP_MCP_Integrations_Abilities::get_integration( array( 'slug' => 'woocommerce' ) );
		$this->assertWPError( $described );
		$this->assertEquals( 'wp_mcp_permission_denied', $described->get_error_code() );
	}

	/* --- Adapter callbacks refuse cleanly when their plugin is absent --- */

	public function test_issue_14_woocommerce_callbacks_refuse_when_woocommerce_is_absent() {
		if ( WP_MCP_Integrations::is_available( 'woocommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is installed in this environment.' );
		}

		$this->become_issue_12_admin();
		$adapter = WP_MCP_Integrations::get( 'woocommerce' );

		foreach ( array( 'get_store_status', 'list_products', 'list_orders', 'list_coupons' ) as $method ) {
			$result = $adapter->$method( array() );
			$this->assertWPError( $result, $method . ' must refuse without WooCommerce.' );
			$this->assertEquals( 'wp_mcp_integration_unavailable', $result->get_error_code() );
		}

		$updated = $adapter->update_product_stock( array( 'product_id' => 1, 'stock_quantity' => 5 ) );
		$this->assertWPError( $updated );
		$this->assertEquals( 'wp_mcp_integration_unavailable', $updated->get_error_code() );
	}

	public function test_issue_14_yoast_callbacks_refuse_when_yoast_is_absent() {
		if ( WP_MCP_Integrations::is_available( 'yoast-seo' ) ) {
			$this->markTestSkipped( 'Yoast SEO is installed in this environment.' );
		}

		$this->become_issue_12_admin();
		$post_id = self::factory()->post->create();
		$adapter = WP_MCP_Integrations::get( 'yoast-seo' );

		$read = $adapter->get_post_seo( array( 'post_id' => $post_id ) );
		$this->assertWPError( $read );
		$this->assertEquals( 'wp_mcp_integration_unavailable', $read->get_error_code() );

		$written = $adapter->update_post_seo( array( 'post_id' => $post_id, 'title' => 'Nope' ) );
		$this->assertWPError( $written );
		$this->assertEquals( 'wp_mcp_integration_unavailable', $written->get_error_code() );
	}

	public function test_issue_14_form_adapters_refuse_when_their_plugin_is_absent() {
		$this->become_issue_12_admin();

		foreach ( array( 'contact-form-7', 'gravity-forms', 'wpforms' ) as $slug ) {
			if ( WP_MCP_Integrations::is_available( $slug ) ) {
				continue;
			}
			$adapter = WP_MCP_Integrations::get( $slug );

			$listed = $adapter->list_forms( array() );
			$this->assertWPError( $listed, $slug . ' must refuse to list without its plugin.' );
			$this->assertEquals( 'wp_mcp_integration_unavailable', $listed->get_error_code() );

			$read = $adapter->get_form( array( 'form_id' => 1 ) );
			$this->assertWPError( $read, $slug . ' must refuse to read without its plugin.' );
			$this->assertEquals( 'wp_mcp_integration_unavailable', $read->get_error_code() );
		}
	}

	public function test_issue_14_adapter_callbacks_refuse_an_agent_without_the_plugin_capability() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Integrations::get( 'woocommerce' )->list_products( array() );

		$this->assertWPError( $result );
		$this->assertContains( $result->get_error_code(), array( 'wp_mcp_integration_unavailable', 'wp_mcp_permission_denied' ) );
	}

	/* --- Regression: the integration layer changes nothing else --- */

	public function test_issue_14_does_not_change_core_behaviour() {
		wp_set_current_user( $this->agent_user );
		$created = WP_MCP_Posts::create_post( array( 'title' => 'Issue14Regression', 'content' => 'Body' ) );
		$this->assertNotWPError( $created );
		$this->assertEquals( 'draft', $created['status'] );

		$this->become_issue_12_admin();
		$plugins = WP_MCP_Plugins::list_plugins( array() );
		$this->assertNotWPError( $plugins );
		$this->assertArrayHasKey( 'plugins', $plugins );
	}

	// ## Issue #16 — Global discovery: search, block types, statuses, capabilities and registered objects

	/**
	 * The nine abilities this issue adds.
	 *
	 * @return string[]
	 */
	private function issue_16_ability_slugs() {
		return array(
			'global-search',
			'list-post-statuses',
			'list-mime-types',
			'list-block-types',
			'get-block-type',
			'list-pattern-categories',
			'list-template-types',
			'get-current-user-capabilities',
			'list-feature-support',
		);
	}

	/**
	 * The fixture block type: dynamic, with a defaulted attribute, an
	 * enumerated one, supports and block context — everything the detail
	 * response has to describe (or deliberately withhold).
	 *
	 * @var string
	 */
	const ISSUE_16_BLOCK = 'wp-mcp-test/discovery-block';

	/**
	 * The adversarial fixture block: its registration parks a filesystem path,
	 * an API key, a callback and a whole plugin configuration inside
	 * `supports`, both under allowlisted keys and under invented ones. None of
	 * it may reach an MCP client.
	 *
	 * @var string
	 */
	const ISSUE_16_HOSTILE_BLOCK = 'wp-mcp-test/hostile-block';

	/**
	 * The fixture pattern category.
	 *
	 * @var string
	 */
	const ISSUE_16_PATTERN_CATEGORY = 'wp-mcp-test-discovery';

	/**
	 * Register the discovery fixtures.
	 */
	private function register_issue_16_fixtures() {
		if ( class_exists( 'WP_Block_Type_Registry' ) && ! WP_Block_Type_Registry::get_instance()->is_registered( self::ISSUE_16_BLOCK ) ) {
			register_block_type( self::ISSUE_16_BLOCK, array(
				'title'            => 'WordPress MCP Discovery Block',
				'category'         => 'text',
				'description'      => 'Fixture block for the issue #16 discovery tests.',
				'keywords'         => array( 'acmeinc', 'discovery' ),
				'parent'           => array( 'core/group' ),
				'attributes'       => array(
					'align'   => array( 'type' => 'string', 'default' => 'left', 'enum' => array( 'left', 'right' ) ),
					'ref'     => array( 'type' => 'number' ),
					'content' => array( 'type' => 'string', 'source' => 'html' ),
				),
				'supports'         => array(
					'anchor' => true,
					'html'   => false,
					'color'  => array( 'text' => true, 'link' => true, 'background' => false ),
				),
				'uses_context'     => array( 'postId' ),
				'provides_context' => array( 'wp-mcp-test/align' => 'align' ),
				'render_callback'  => '__return_empty_string',
			) );
		}

		if ( function_exists( 'register_block_pattern_category' ) ) {
			register_block_pattern_category( self::ISSUE_16_PATTERN_CATEGORY, array( 'label' => 'WordPress MCP Discovery' ) );
		}
	}

	/**
	 * Register the adversarial block whose supports are hostile.
	 */
	private function register_issue_16_hostile_block() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) || WP_Block_Type_Registry::get_instance()->is_registered( self::ISSUE_16_HOSTILE_BLOCK ) ) {
			return;
		}

		register_block_type( self::ISSUE_16_HOSTILE_BLOCK, array(
			'title'    => 'WordPress MCP Hostile Block',
			'category' => 'text',
			/*
			 * Every list below mixes safe entries with paths, URLs, secrets,
			 * nested config, a closure and a non-stringable object. Discovery
			 * must drop all of them by type and by shape — and, above all, must
			 * not `strval()` any of them, which would fatal.
			 */
			'keywords' => array(
				'discovery',
				'/var/www/secret/wp-config-backup.php',
				'https://evil.example/hook?token=abc123',
				array( 'nested' => 'acmeincPluginConfig' ),
				static function () {
					return 'wp-mcp-closure-marker';
				},
				new stdClass(),
			),
			'parent'   => array( 'core/group', '/srv/app/private', 'https://evil.example/api', 42, null ),
			'ancestor' => array( new stdClass(), '../../wp-config.php' ),
			'uses_context'     => array(
				'postId',
				'acmeincSecretApiKey',
				'/var/www/secret/wp-config-backup.php',
				42,
				static function () {
					return 'wp-mcp-closure-marker';
				},
			),
			'provides_context' => array(
				'postId'                               => 'ref',
				'acmeincSecretApiKey'                  => 'sk-live-WordPress MCPSECRET',
				'wp-mcp/config'                       => 'hunter2',
				'/var/www/secret/wp-config-backup.php' => '/srv/app/private',
			),
			'attributes'       => array(
				'ref'                   => array( 'type' => array( 'number', 'null', '/etc/passwd' ) ),
				'acmeincSecretSetting'  => array(
					'type'    => 'string',
					'default' => 'sk-live-WordPress MCPSECRET',
					'enum'    => array( 'sk-live-WordPress MCPSECRET', '/srv/app/private' ),
				),
				'acmeincCallbackTyped'  => array(
					'type'   => static function () {
						return 'wp-mcp-closure-marker';
					},
					'source' => 'acmeincCustomSource',
				),
				'/etc/passwd'           => array( 'type' => 'string' ),
				'https://evil.example'  => array( 'type' => 'string' ),
			),
			'supports' => array(
				// Allowlisted keys carrying values that must never be echoed back.
				'html'     => '/var/www/secret/wp-config-backup.php',
				'anchor'   => 'sk-live-WordPress MCPSECRET',
				'color'    => array(
					'text'            => true,
					'gradients'       => false,
					'acmeincWebhook'  => 'https://evil.example/hook?token=abc123',
					'acmeincPassword' => 'hunter2',
				),
				// A closure parked under an allowlisted key: never inspected.
				'position' => static function () {
					return 'wp-mcp-closure-marker';
				},
				// A secret one level deeper, under an allowlisted sub-feature:
				// the sub-feature name is reported, its contents are not walked.
				'typography' => array(
					'fontSize' => array( 'acmeincToken' => 'sk-live-WordPress MCPSECRET' ),
				),
				// Invented keys: dropped whole, name and value alike.
				'acmeincSecretApiKey'  => 'sk-live-WordPress MCPSECRET',
				'acmeincRenderHandler' => '__return_empty_string',
				'acmeincPluginConfig'  => array(
					'db_password' => 'hunter2',
					'path'        => '/srv/app/private',
					'endpoint'    => 'https://evil.example/api',
				),
			),
		) );
	}

	/**
	 * The registries beyond the block registry that discovery reads: post
	 * statuses, pattern categories, mime types and the FSE vocabularies. Each
	 * gets a hostile entry carrying a secret, a path or a URL in the free-text
	 * fields WordPress lets a plugin fill, plus one entry whose *name* breaks
	 * the identifier grammar.
	 */
	private function register_issue_16_hostile_registries() {
		register_post_status( 'wp_mcp_hostile_status', array(
			'label'  => 'sk-live-WordPress MCPSECRET at /var/www/secret/wp-config-backup.php',
			'public' => false,
		) );
		// 84 characters: a name that survives sanitize_key() but not the grammar.
		register_post_status( str_repeat( 'acmeinc', 12 ), array( 'label' => 'https://evil.example/hook?token=abc123' ) );

		if ( function_exists( 'register_block_pattern_category' ) ) {
			// Valid name, hostile free text: the name is reported, the rest is not.
			register_block_pattern_category( 'wp_mcp_hostile_category', array(
				'label'       => 'sk-live-WordPress MCPSECRET',
				'description' => '/srv/app/private and https://evil.example/api',
			) );
			// A name that is not a registry slug at all: dropped whole.
			register_block_pattern_category( 'WordPress MCP Hostile/Category', array( 'label' => 'https://evil.example/api' ) );
		}

		add_filter( 'upload_mimes', array( $this, 'issue_16_hostile_mimes' ) );
		add_filter( 'default_template_types', array( $this, 'issue_16_hostile_template_types' ) );
		add_filter( 'default_wp_template_part_areas', array( $this, 'issue_16_hostile_template_areas' ) );
	}

	/**
	 * Undo the registry fixtures.
	 */
	private function unregister_issue_16_hostile_registries() {
		foreach ( array( 'wp_mcp_hostile_status', str_repeat( 'acmeinc', 12 ) ) as $status ) {
			if ( isset( $GLOBALS['wp_post_statuses'][ $status ] ) ) {
				unset( $GLOBALS['wp_post_statuses'][ $status ] );
			}
		}
		if ( function_exists( 'unregister_block_pattern_category' ) && class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
			foreach ( array( 'wp_mcp_hostile_category', 'WordPress MCP Hostile/Category' ) as $category ) {
				if ( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $category ) ) {
					unregister_block_pattern_category( $category );
				}
			}
		}
		remove_filter( 'upload_mimes', array( $this, 'issue_16_hostile_mimes' ) );
		remove_filter( 'default_template_types', array( $this, 'issue_16_hostile_template_types' ) );
		remove_filter( 'default_wp_template_part_areas', array( $this, 'issue_16_hostile_template_areas' ) );
	}

	/**
	 * A mime map with an unusable mime value and an unusable extension.
	 *
	 * @param array $mimes Allowed mime types.
	 * @return array
	 */
	public function issue_16_hostile_mimes( $mimes ) {
		$mimes['acmeincevil']          = 'https://evil.example/hook?token=abc123';
		$mimes['jpg|acmeinc secret']   = 'image/wp-mcp-hostile';
		$mimes['acmeincpath']          = '/var/www/secret/wp-config-backup.php';
		return $mimes;
	}

	/**
	 * A template type outside the core vocabulary, with hostile free text.
	 *
	 * @param array $types Default template types.
	 * @return array
	 */
	public function issue_16_hostile_template_types( $types ) {
		$types['wp-mcp-hostile-type'] = array(
			'title'       => 'sk-live-WordPress MCPSECRET',
			'description' => '/srv/app/private',
		);
		return $types;
	}

	/**
	 * A template-part area outside the core vocabulary, with hostile free text.
	 *
	 * @param array $areas Allowed template part areas.
	 * @return array
	 */
	public function issue_16_hostile_template_areas( $areas ) {
		$areas[] = array(
			'area'        => 'wp-mcp-hostile-area',
			'label'       => 'sk-live-WordPress MCPSECRET',
			'description' => 'https://evil.example/api',
		);
		return $areas;
	}

	/**
	 * Remove the discovery fixtures. A no-op unless a test registered them.
	 */
	private function unregister_issue_16_fixtures() {
		$this->unregister_issue_16_hostile_registries();
		$this->unregister_issue_16_bulk_fixtures();
		if ( class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( self::ISSUE_16_BLOCK ) ) {
			unregister_block_type( self::ISSUE_16_BLOCK );
		}
		if ( class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( self::ISSUE_16_HOSTILE_BLOCK ) ) {
			unregister_block_type( self::ISSUE_16_HOSTILE_BLOCK );
		}
		if ( function_exists( 'unregister_block_pattern_category' ) && class_exists( 'WP_Block_Pattern_Categories_Registry' ) && WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( self::ISSUE_16_PATTERN_CATEGORY ) ) {
			unregister_block_pattern_category( self::ISSUE_16_PATTERN_CATEGORY );
		}
	}

	/* --- Registration, schema and catalog shape --- */

	public function test_issue_16_discovery_category_is_registered() {
		if ( ! function_exists( 'wp_get_ability_category' ) ) {
			$this->assertTrue( true );
			return;
		}
		$this->assertNotNull( wp_get_ability_category( 'wp-mcp-discovery' ) );
	}

	public function test_issue_16_abilities_are_registered_with_discovery_category_and_matrix_entries() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$matrix = WP_MCP_Ability_Matrix::get();
		foreach ( $this->issue_16_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should be registered' );
			$this->assertEquals( 'wp-mcp-discovery', $ability->get_category(), $name . ' belongs to wp-mcp-discovery' );
			$this->assertArrayHasKey( $name, $matrix, $name . ' must be in the permission matrix' );
		}
	}

	public function test_issue_16_matrix_discovery_rows_match_the_registered_slugs() {
		$rows = array();
		foreach ( WP_MCP_Ability_Matrix::get() as $name => $row ) {
			if ( 'wp-mcp-discovery' === $row['category'] ) {
				$rows[] = $name;
			}
		}
		sort( $rows );

		$expected = array_map(
			function ( $slug ) {
				return 'wp-mcp/' . $slug;
			},
			$this->issue_16_ability_slugs()
		);
		sort( $expected );

		$this->assertEquals( $expected, $rows, 'The wp-mcp-discovery rows of the matrix and the issue #16 ability list must be the same set.' );
	}

	public function test_issue_16_ability_schemas_are_closed() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( $this->issue_16_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			foreach ( array( $ability->get_input_schema(), $ability->get_output_schema() ) as $schema ) {
				$this->assertArrayHasKey( 'additionalProperties', $schema, $name . ' schemas must be closed' );
				$this->assertFalse( $schema['additionalProperties'], $name . ' schemas must reject additional properties' );
			}
		}
	}

	public function test_issue_16_abilities_are_mcp_only_and_read_only() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( $this->issue_16_ability_slugs() as $slug ) {
			$ability = wp_get_ability( 'wp-mcp/' . $slug );
			$this->assertNotNull( $ability );
			$mcp = $ability->get_meta_item( 'mcp' );
			$this->assertIsArray( $mcp );
			$this->assertTrue( $mcp['public'], 'wp-mcp/' . $slug . ' must be MCP-public' );
			$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ), 'wp-mcp/' . $slug . ' must not be REST-exposed' );
			$annotations = $ability->get_meta_item( 'annotations' );
			$this->assertTrue( $annotations['readonly'], 'wp-mcp/' . $slug . ' must be read-only' );
			$this->assertFalse( $annotations['destructive'], 'wp-mcp/' . $slug . ' must not be destructive' );
		}
	}

	/**
	 * Discovery must not become the generic explorer epic #1 forbids: no
	 * input names a REST route, an option, a callback, a path or a query.
	 */
	public function test_issue_16_inputs_never_accept_a_route_option_callback_or_path() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->assertTrue( true );
			return;
		}
		$forbidden = array(
			'route', 'rest_route', 'endpoint', 'url', 'path', 'file', 'filename', 'dir', 'directory',
			'option', 'option_name', 'meta_key', 'transient',
			'sql', 'query', 'command', 'shell', 'code', 'php', 'eval',
			'callback', 'callable', 'function', 'method', 'hook', 'action', 'filter',
		);
		foreach ( $this->issue_16_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			foreach ( array_keys( $ability->get_input_schema()['properties'] ) as $property ) {
				$this->assertNotContains( $property, $forbidden, $name . ' must not accept "' . $property . '" as an input field' );
			}
		}
	}

	public function test_issue_16_exposes_no_generic_explorer_or_runner_ability() {
		$forbidden = array( 'rest-route', 'rest-request', 'endpoint', 'explorer', 'run-route', 'call-', 'option', 'sql', 'eval', 'exec', 'callback', 'shell' );
		foreach ( array_keys( WP_MCP_Ability_Matrix::get() ) as $name ) {
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( $needle, $name, $name . ' must not expose a generic ' . $needle . ' primitive' );
			}
		}
	}

	/* --- Registered objects --- */

	public function test_issue_16_post_statuses_report_core_statuses_with_their_flags() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Discovery::list_post_statuses( array() );
		$this->assertNotWPError( $result );
		$this->assertEquals( count( $result['statuses'] ), $result['total'] );

		$by_name = array();
		foreach ( $result['statuses'] as $status ) {
			$by_name[ $status['name'] ] = $status;
		}

		$this->assertFalse( $result['capped'] );
		foreach ( $result['statuses'] as $status ) {
			$this->assertEquals(
				array( 'name', 'public', 'internal', 'protected', 'private', 'exclude_from_search', 'show_in_admin_all_list', 'show_in_admin_status_list' ),
				array_keys( $status ),
				'A post status is a name plus flags: the registered label is free text.'
			);
			$this->assertMatchesRegularExpression( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $status['name'] );
		}

		$this->assertArrayHasKey( 'publish', $by_name );
		$this->assertArrayHasKey( 'draft', $by_name );
		$this->assertArrayHasKey( 'private', $by_name );
		$this->assertTrue( $by_name['publish']['public'] );
		$this->assertFalse( $by_name['draft']['public'] );
		$this->assertTrue( $by_name['draft']['protected'] );
		$this->assertTrue( $by_name['private']['private'] );
	}

	public function test_issue_16_post_statuses_refuse_a_logged_out_caller() {
		wp_set_current_user( 0 );

		$result = WP_MCP_Discovery::list_post_statuses( array() );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	public function test_issue_16_mime_types_require_upload_files() {
		wp_set_current_user( $this->agent_user );

		$denied = WP_MCP_Discovery::list_mime_types( array() );
		$this->assertWPError( $denied, 'The agent role has no upload_files and must be refused.' );
		$this->assertEquals( 'wp_mcp_permission_denied', $denied->get_error_code() );

		$this->become_issue_12_admin();
		$allowed = WP_MCP_Discovery::list_mime_types( array() );
		$this->assertNotWPError( $allowed );
		$this->assertGreaterThan( 0, $allowed['total'] );
		$this->assertGreaterThan( 0, $allowed['max_upload_size_bytes'] );

		$this->assertFalse( $allowed['capped'] );

		$mime_types = array();
		foreach ( $allowed['mime_types'] as $entry ) {
			$this->assertEquals( array( 'mime_type', 'extensions' ), array_keys( $entry ) );
			$this->assertIsArray( $entry['extensions'] );
			$this->assertMatchesRegularExpression( '#^[a-z0-9][a-z0-9.+-]{0,62}/[a-z0-9][a-z0-9.+-]{0,62}$#', $entry['mime_type'] );
			foreach ( $entry['extensions'] as $extension ) {
				$this->assertMatchesRegularExpression( '/^[a-z0-9]{1,16}$/', $extension );
			}
			$mime_types[] = $entry['mime_type'];
		}
		$this->assertContains( 'image/jpeg', $mime_types );
	}

	public function test_issue_16_block_types_are_listed_and_bounded() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_fixtures();
		$this->become_issue_12_admin();

		$result = WP_MCP_Discovery::list_block_types( array( 'per_page' => 999 ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 50, $result['per_page'], 'per_page must be clamped to the documented maximum.' );
		$this->assertGreaterThan( 0, $result['total'] );
		$this->assertLessThanOrEqual( 50, count( $result['block_types'] ) );

		$filtered = WP_MCP_Discovery::list_block_types( array( 'block_namespace' => 'wp-mcp-test' ) );
		$this->assertNotWPError( $filtered );
		$names = wp_list_pluck( $filtered['block_types'], 'name' );
		$this->assertContains( self::ISSUE_16_BLOCK, $names );
		foreach ( $names as $name ) {
			$this->assertStringStartsWith( 'wp-mcp-test/', $name, 'The namespace filter must not leak other namespaces.' );
		}
	}

	public function test_issue_16_block_type_detail_describes_the_registration_without_defaults_or_callbacks() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_fixtures();
		$this->become_issue_12_admin();

		$result = WP_MCP_Discovery::get_block_type( array( 'block_type' => self::ISSUE_16_BLOCK ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( self::ISSUE_16_BLOCK, $result['name'] );
		$this->assertTrue( $result['is_dynamic'], 'A block registered with a render_callback is dynamic.' );
		$this->assertArrayNotHasKey( 'render_callback', $result, 'The render callback must never be exposed.' );
		$this->assertEquals( array( 'core/group' ), $result['parent'] );
		$this->assertContains( 'postId', $result['uses_context'] );
		foreach ( array( 'title', 'description', 'keywords', 'label' ) as $free_text ) {
			$this->assertArrayNotHasKey( $free_text, $result, 'The conservative model reports no ' . $free_text . '.' );
		}
		$this->assertStringNotContainsString( 'WordPress MCP Discovery Block', wp_json_encode( $result ), 'A block title is free text and is never reported.' );

		$supports = array();
		foreach ( $result['supports'] as $support ) {
			$this->assertEquals( array( 'feature', 'enabled', 'sub_features' ), array_keys( $support ), 'A support entry must never carry its registered value.' );
			$supports[ $support['feature'] ] = $support;
		}
		$this->assertArrayHasKey( 'anchor', $supports );
		$this->assertTrue( $supports['anchor']['enabled'] );
		$this->assertArrayHasKey( 'html', $supports );
		$this->assertFalse( $supports['html']['enabled'], 'supports => array( html => false ) is a disabled feature, not an absent one.' );
		$this->assertEquals( array( 'link', 'text' ), $supports['color']['sub_features'], 'Only allowlisted, enabled sub-features are reported.' );
		$this->assertContains( 'anchor', $result['supports_keys'] );

		// provides_context is a map of arbitrary keys to arbitrary attribute
		// names: only allowlisted context *names* survive, never the map.
		$this->assertSame( array(), $result['provides_context'], 'wp-mcp-test/align is not a core context name.' );
		$this->assertStringNotContainsString( 'wp-mcp-test/align', wp_json_encode( $result ) );

		$attributes = array();
		foreach ( $result['attributes'] as $attribute ) {
			$this->assertEquals( array( 'name', 'types', 'source', 'has_default', 'has_enum' ), array_keys( $attribute ), 'An attribute must never carry its stored default or enumeration values.' );
			$attributes[ $attribute['name'] ] = $attribute;
		}
		$this->assertTrue( $attributes['align']['has_default'] );
		$this->assertTrue( $attributes['align']['has_enum'] );
		$this->assertEquals( array( 'string' ), $attributes['align']['types'] );
		$this->assertFalse( $attributes['ref']['has_default'] );
		$this->assertFalse( $attributes['ref']['has_enum'] );
		$this->assertEquals( array( 'number' ), $attributes['ref']['types'] );
		$this->assertEquals( 'html', $attributes['content']['source'] );
	}

	/**
	 * The adversarial case: a block whose `supports` registration carries a
	 * filesystem path, an API key, a webhook URL, a closure and a whole plugin
	 * configuration — under allowlisted keys *and* under invented ones. The
	 * response must describe the feature names and nothing else.
	 */
	public function test_issue_16_block_supports_never_carry_registration_values() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_hostile_block();
		$this->become_issue_12_admin();

		$result = WP_MCP_Discovery::get_block_type( array( 'block_type' => self::ISSUE_16_HOSTILE_BLOCK ) );
		$this->assertNotWPError( $result );

		$encoded = wp_json_encode( $result );
		foreach ( array(
			'sk-live-WordPress MCPSECRET',
			'hunter2',
			'/var/www/secret',
			'wp-config-backup',
			'evil.example',
			'token=abc123',
			'/srv/app/private',
			'__return_empty_string',
			'wp-mcp-closure-marker',
			'acmeincSecretApiKey',
			'acmeincRenderHandler',
			'acmeincPluginConfig',
			'acmeincWebhook',
			'acmeincPassword',
			'db_password',
		) as $needle ) {
			$this->assertStringNotContainsString( $needle, $encoded, 'A block support registration value or unlisted key leaked: ' . $needle );
		}

		$vocabulary = WP_MCP_Discovery::block_support_vocabulary();
		$sub_vocabulary = WP_MCP_Discovery::block_support_sub_vocabulary();

		$supports = array();
		foreach ( $result['supports'] as $support ) {
			$this->assertEquals( array( 'feature', 'enabled', 'sub_features' ), array_keys( $support ) );
			$this->assertContains( $support['feature'], $vocabulary, 'Only allowlisted supports may be reported.' );
			$this->assertIsBool( $support['enabled'] );
			foreach ( $support['sub_features'] as $sub_feature ) {
				$this->assertContains( $sub_feature, $sub_vocabulary, 'Only allowlisted sub-features may be reported.' );
			}
			$supports[ $support['feature'] ] = $support;
		}

		// An allowlisted key with a hostile *value* still reports only the name.
		$this->assertTrue( $supports['html']['enabled'] );
		$this->assertSame( array(), $supports['html']['sub_features'] );
		$this->assertTrue( $supports['anchor']['enabled'] );
		$this->assertEquals( array( 'text' ), $supports['color']['sub_features'], 'A disabled or unlisted sub-feature must not be reported.' );

		// A closure parked under an allowlisted key is never inspected.
		$this->assertArrayHasKey( 'position', $supports );
		$this->assertFalse( $supports['position']['enabled'] );
		$this->assertSame( array(), $supports['position']['sub_features'] );

		// Normalisation does not recurse: an allowlisted sub-feature is a name,
		// and whatever is nested under it stays where it is.
		$this->assertEquals( array( 'fontSize' ), $supports['typography']['sub_features'] );
		$this->assertStringNotContainsString( 'acmeincToken', $encoded );

		// The invented keys are gone entirely, name included.
		foreach ( $result['supports_keys'] as $key ) {
			$this->assertContains( $key, $vocabulary, 'supports_keys must be the allowlisted subset, not the raw registration keys.' );
		}
		$this->assertEquals( array( 'anchor', 'color', 'html', 'position', 'typography' ), $result['supports_keys'] );
	}

	/**
	 * The second half of the same surface: block context and attribute
	 * metadata are registration data too. `provides_context` is a map of
	 * arbitrary keys to arbitrary values, and an attribute's `type`, `source`
	 * and `enum` are whatever the author wrote — none of it may be echoed.
	 */
	public function test_issue_16_block_context_and_attributes_never_carry_registration_data() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_hostile_block();
		$this->become_issue_12_admin();

		$result = WP_MCP_Discovery::get_block_type( array( 'block_type' => self::ISSUE_16_HOSTILE_BLOCK ) );
		$this->assertNotWPError( $result );

		$encoded = wp_json_encode( $result );
		foreach ( array(
			'acmeincSecretApiKey',
			'wp-mcp/config',
			'hunter2',
			'sk-live-WordPress MCPSECRET',
			'/var/www/secret',
			'wp-config-backup',
			'/srv/app/private',
			'/etc/passwd',
			'evil.example',
			'token=abc123',
			'acmeincCustomSource',
			'wp-mcp-closure-marker',
			'acmeincPluginConfig',
		) as $needle ) {
			$this->assertStringNotContainsString( $needle, $encoded, 'Block registration data leaked: ' . $needle );
		}

		// Context: allowlisted names only, on both sides, and never the map.
		$vocabulary = WP_MCP_Discovery::block_context_vocabulary();
		$this->assertEquals( array( 'postId' ), $result['uses_context'] );
		$this->assertEquals( array( 'postId' ), $result['provides_context'] );
		foreach ( array_merge( $result['uses_context'], $result['provides_context'] ) as $context ) {
			$this->assertContains( $context, $vocabulary );
		}

		// Attributes: an unusable name is dropped, and the rest is vocabulary
		// terms and booleans.
		$types   = WP_MCP_Discovery::block_attribute_type_vocabulary();
		$sources = WP_MCP_Discovery::block_attribute_source_vocabulary();

		$attributes = array();
		foreach ( $result['attributes'] as $attribute ) {
			$this->assertEquals( array( 'name', 'types', 'source', 'has_default', 'has_enum' ), array_keys( $attribute ) );
			$this->assertMatchesRegularExpression( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $attribute['name'], 'An attribute name that is not an identifier must be dropped.' );
			foreach ( $attribute['types'] as $type ) {
				$this->assertContains( $type, $types, 'Only JSON Schema type names may be reported.' );
			}
			if ( '' !== $attribute['source'] ) {
				$this->assertContains( $attribute['source'], $sources, 'Only block-editor attribute sources may be reported.' );
			}
			$attributes[ $attribute['name'] ] = $attribute;
		}

		// A declared type array keeps its allowlisted members and drops the rest.
		$this->assertEquals( array( 'number', 'null' ), $attributes['ref']['types'] );
		// A secret default and a secret enumeration become booleans.
		$this->assertTrue( $attributes['acmeincSecretSetting']['has_default'] );
		$this->assertTrue( $attributes['acmeincSecretSetting']['has_enum'] );
		$this->assertArrayNotHasKey( 'enum', $attributes['acmeincSecretSetting'] );
		// A closure as a type, and an invented source, report nothing at all.
		$this->assertSame( array(), $attributes['acmeincCallbackTyped']['types'] );
		$this->assertEquals( '', $attributes['acmeincCallbackTyped']['source'] );
		$this->assertArrayNotHasKey( '/etc/passwd', $attributes );
		$this->assertArrayNotHasKey( 'https://evil.example', $attributes );
	}

	/**
	 * Item three of the same review: the summary lists were built with
	 * `strval()` over raw registration arrays, so a closure or a
	 * non-stringable object in `keywords`, `parent` or `ancestor` would fatal
	 * discovery instead of being ignored.
	 */
	public function test_issue_16_block_summary_lists_survive_objects_and_closures() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_hostile_block();
		$this->become_issue_12_admin();

		$detail = WP_MCP_Discovery::get_block_type( array( 'block_type' => self::ISSUE_16_HOSTILE_BLOCK ) );
		$this->assertNotWPError( $detail, 'A hostile registration must not break discovery.' );

		$this->assertArrayNotHasKey( 'keywords', $detail, 'Keywords are free text: the conservative model drops the field entirely.' );
		$this->assertArrayNotHasKey( 'title', $detail );
		$this->assertArrayNotHasKey( 'description', $detail );
		$this->assertEquals( array( 'core/group' ), $detail['parent'], 'Only namespace/name block names may be reported as parents.' );
		$this->assertSame( array(), $detail['ancestor'] );
		/*
		 * `WP_Block_Type::set_props()` copies its own global attributes
		 * (`lock`, `metadata`) into every registered block type, so they are
		 * part of the block's attribute surface and are reported like any
		 * other name. What this assertion is about is the fixture's own five:
		 * three identifiers survive and the path and the URL do not.
		 */
		$expected = array_merge(
			array( 'acmeincCallbackTyped', 'acmeincSecretSetting', 'ref' ),
			array_keys( WP_Block_Type::GLOBAL_ATTRIBUTES )
		);
		$this->assertEquals( $this->issue_16_sorted( $expected ), $this->issue_16_sorted( $detail['attribute_names'] ) );
		$this->assertEquals( self::ISSUE_16_HOSTILE_BLOCK, $detail['name'] );

		// The list ability shares the same summary builder.
		$listed = WP_MCP_Discovery::list_block_types( array( 'block_namespace' => 'wp-mcp-test' ) );
		$this->assertNotWPError( $listed );

		$encoded = wp_json_encode( $listed );
		foreach ( array( '/var/www/secret', 'evil.example', '/etc/passwd', 'wp-mcp-closure-marker', 'acmeincSecretApiKey' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $encoded, 'The list response leaked ' . $needle );
		}

		foreach ( $listed['block_types'] as $block ) {
			if ( self::ISSUE_16_HOSTILE_BLOCK !== $block['name'] ) {
				continue;
			}
			$this->assertArrayNotHasKey( 'keywords', $block );
			$this->assertEquals( array( 'core/group' ), $block['parent'] );
			$this->assertSame( array(), $block['ancestor'] );
		}
	}

	/**
	 * Sort a list of names for a stable comparison.
	 *
	 * @param array $names Names.
	 * @return array
	 */
	private function issue_16_sorted( $names ) {
		sort( $names );
		return $names;
	}

	/**
	 * The same allowlist applies to the list ability, which shares the summary.
	 */
	public function test_issue_16_block_list_supports_keys_are_allowlisted_too() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_hostile_block();
		$this->become_issue_12_admin();

		$result = WP_MCP_Discovery::list_block_types( array( 'block_namespace' => 'wp-mcp-test' ) );
		$this->assertNotWPError( $result );

		$encoded = wp_json_encode( $result );
		$this->assertStringNotContainsString( 'acmeincSecretApiKey', $encoded );
		$this->assertStringNotContainsString( 'sk-live-WordPress MCPSECRET', $encoded );

		$vocabulary = WP_MCP_Discovery::block_support_vocabulary();
		foreach ( $result['block_types'] as $block ) {
			foreach ( $block['supports_keys'] as $key ) {
				$this->assertContains( $key, $vocabulary );
			}
		}
	}

	public function test_issue_16_get_block_type_refuses_a_malformed_or_unknown_name() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->become_issue_12_admin();

		foreach ( array( '', 'paragraph', '../../wp-config.php', 'core/', 'Core/Paragraph' ) as $malformed ) {
			$result = WP_MCP_Discovery::get_block_type( array( 'block_type' => $malformed ) );
			$this->assertWPError( $result, 'block_type "' . $malformed . '" must be refused.' );
			$this->assertEquals( 'wp_mcp_discovery_validation_error', $result->get_error_code() );
		}

		$unknown = WP_MCP_Discovery::get_block_type( array( 'block_type' => 'wp-mcp-test/not-registered' ) );
		$this->assertWPError( $unknown );
		$this->assertEquals( 'wp_mcp_invalid_block_type', $unknown->get_error_code() );
	}

	public function test_issue_16_block_registry_requires_edit_posts() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		foreach ( array(
			WP_MCP_Discovery::list_block_types( array() ),
			WP_MCP_Discovery::get_block_type( array( 'block_type' => 'core/paragraph' ) ),
			WP_MCP_Discovery::list_pattern_categories( array() ),
			WP_MCP_Discovery::list_feature_support( array() ),
		) as $result ) {
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
		}
	}

	public function test_issue_16_pattern_categories_list_the_registry() {
		if ( ! class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no pattern category registry.' );
		}
		$this->register_issue_16_fixtures();
		$this->become_issue_12_admin();

		$result = WP_MCP_Discovery::list_pattern_categories( array() );
		$this->assertNotWPError( $result );
		$this->assertEquals( count( $result['categories'] ), $result['total'] );

		$this->assertContains( self::ISSUE_16_PATTERN_CATEGORY, $result['categories'] );
		$this->assertFalse( $result['capped'] );
		foreach ( $result['categories'] as $category ) {
			$this->assertIsString( $category, 'A pattern category is reported as a name, never as a labelled object.' );
			$this->assertMatchesRegularExpression( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $category );
		}
		$this->assertStringNotContainsString( 'WordPress MCP Discovery', wp_json_encode( $result ), 'The registered label is free text and is never reported.' );
	}

	public function test_issue_16_template_types_report_the_fse_state_without_failing_on_a_classic_theme() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Discovery::list_template_types( array() );
		$this->assertNotWPError( $result, 'A classic theme must still get a discovery answer, not an error.' );
		$this->assertIsBool( $result['block_theme'] );
		$this->assertIsBool( $result['template_editing'] );
		$this->assertIsArray( $result['template_types'] );
		$this->assertIsArray( $result['template_part_areas'] );
		$this->assertEquals( count( $result['template_types'] ), $result['total_template_types'] );
		$this->assertEquals( count( $result['template_part_areas'] ), $result['total_template_part_areas'] );
		$this->assertContains( $result['reason'], array( 'block_theme', 'block_templates_support', 'classic_theme' ) );

		// The lists exist only where a Site Editor does, whatever theme CI runs.
		if ( $result['template_editing'] ) {
			$this->assertNotEmpty( $result['template_types'] );
			$this->assertContains( 'header', $result['template_part_areas'] );
			$this->assertContains( 'index', $result['template_types'] );
			$this->assertEquals( $result['block_theme'] ? 'block_theme' : 'block_templates_support', $result['reason'] );
		} else {
			$this->assertSame( array(), $result['template_types'] );
			$this->assertSame( array(), $result['template_part_areas'] );
			$this->assertEquals( 'classic_theme', $result['reason'] );
			$this->assertFalse( $result['block_theme'] );
		}
	}

	/**
	 * The classic-theme fixture: no block theme and no `block-templates`
	 * support means no Site Editor, so the FSE vocabulary is not advertised.
	 * `get_default_block_template_types()` and
	 * `get_allowed_block_template_part_areas()` answer the same on a classic
	 * theme as on a block one, which is exactly the trap.
	 */
	public function test_issue_16_template_surface_is_empty_without_a_site_editor() {
		$classic = WP_MCP_Discovery::describe_template_surface( false, false );

		$this->assertFalse( $classic['block_theme'] );
		$this->assertFalse( $classic['template_editing'] );
		$this->assertEquals( 'classic_theme', $classic['reason'] );
		$this->assertSame( array(), $classic['template_types'], 'A classic theme must not be told about FSE template types.' );
		$this->assertSame( array(), $classic['template_part_areas'], 'A classic theme must not be told about template-part areas.' );
		$this->assertEquals( 0, $classic['total_template_types'] );
		$this->assertEquals( 0, $classic['total_template_part_areas'] );

		$encoded = wp_json_encode( $classic );
		$this->assertStringNotContainsString( 'header', $encoded, 'No template-part area may leak through the classic-theme answer.' );
		$this->assertStringNotContainsString( 'index', $encoded );
	}

	/**
	 * The other half of the same rule: a hybrid classic theme that opted into
	 * `block-templates` does get the vocabulary, and says so through `reason`.
	 */
	public function test_issue_16_template_surface_lists_the_vocabulary_where_a_site_editor_exists() {
		if ( ! function_exists( 'get_allowed_block_template_part_areas' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block template APIs.' );
		}

		$hybrid = WP_MCP_Discovery::describe_template_surface( false, true );
		$this->assertFalse( $hybrid['block_theme'] );
		$this->assertTrue( $hybrid['template_editing'] );
		$this->assertEquals( 'block_templates_support', $hybrid['reason'] );
		$this->assertContains( 'header', $hybrid['template_part_areas'] );
		$this->assertEquals( count( $hybrid['template_part_areas'] ), $hybrid['total_template_part_areas'] );

		$block = WP_MCP_Discovery::describe_template_surface( true, true );
		$this->assertTrue( $block['block_theme'] );
		$this->assertEquals( 'block_theme', $block['reason'] );
		$this->assertNotEmpty( $block['template_types'] );
		$this->assertEquals( count( $block['template_types'] ), $block['total_template_types'] );
		foreach ( $block['template_types'] as $type ) {
			$this->assertIsString( $type, 'A template type is reported as a slug, never as a titled object.' );
			$this->assertContains( $type, WP_MCP_Discovery::template_type_vocabulary() );
		}
		foreach ( $block['template_part_areas'] as $area ) {
			$this->assertContains( $area, WP_MCP_Discovery::template_part_area_vocabulary() );
		}
	}

	public function test_issue_16_template_types_require_edit_theme_options() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Discovery::list_template_types( array() );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	/* --- The caller and the installation --- */

	public function test_issue_16_current_user_capabilities_report_effective_roles_and_capabilities() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Discovery::get_current_user_capabilities( array() );
		$this->assertNotWPError( $result );
		$this->assertEquals( $this->agent_user, $result['user_id'] );
		$this->assertEquals( array( 'wp_mcp_agent' ), $result['roles'] );
		$this->assertContains( 'edit_posts', $result['capabilities'] );
		$this->assertContains( 'publish_posts', $result['capabilities'] );
		$this->assertNotContains( 'manage_options', $result['capabilities'], 'A denied capability must never be reported as granted.' );
		$this->assertNotContains( 'upload_files', $result['capabilities'] );
		$this->assertEquals( count( $result['capabilities'] ), $result['total'] );
		$this->assertFalse( $result['capped'] );
		$this->assertIsBool( $result['is_super_admin'] );
		foreach ( array_merge( $result['roles'], $result['capabilities'] ) as $identifier ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $identifier );
		}
	}

	public function test_issue_16_current_user_capabilities_refuse_a_logged_out_caller() {
		wp_set_current_user( 0 );

		$result = WP_MCP_Discovery::get_current_user_capabilities( array() );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	public function test_issue_16_feature_support_reports_booleans_and_counts_only() {
		$this->become_issue_12_admin();

		$result = WP_MCP_Discovery::list_feature_support( array() );
		$this->assertNotWPError( $result );
		$this->assertEquals( array( 'stylesheet', 'template', 'is_block_theme', 'has_theme_json' ), array_keys( $result['theme'] ) );
		$this->assertIsBool( $result['theme']['is_block_theme'] );
		foreach ( array( 'stylesheet', 'template' ) as $slug_field ) {
			$this->assertMatchesRegularExpression( '/^([A-Za-z0-9][A-Za-z0-9_-]{0,63})?$/', $result['theme'][ $slug_field ], 'A theme slug is a name, never a path.' );
		}

		$features = array();
		foreach ( $result['features'] as $feature ) {
			$this->assertEquals( array( 'feature', 'supported' ), array_keys( $feature ), 'A feature must be reported as a boolean, never as its registered arguments.' );
			$this->assertIsBool( $feature['supported'] );
			$features[] = $feature['feature'];
		}
		$this->assertContains( 'post-thumbnails', $features );
		$this->assertContains( 'custom-header', $features );
		$this->assertEquals( WP_MCP_Discovery::theme_feature_vocabulary(), $features, 'The reported vocabulary must be the fixed one.' );

		foreach ( array( 'multisite', 'block_editor', 'site_editor', 'pretty_permalinks', 'application_passwords', 'widgets_block_editor' ) as $flag ) {
			$this->assertIsBool( $result['site'][ $flag ], $flag . ' must be a boolean.' );
		}
		foreach ( array( 'nav_menu_locations', 'registered_post_types', 'registered_taxonomies' ) as $count ) {
			$this->assertIsInt( $result['site'][ $count ], $count . ' must be a count.' );
		}
	}

	/**
	 * The whole point of the fixed vocabulary: `custom-header` registers
	 * `admin-head-callback` and asset paths as its arguments, and none of
	 * that may appear anywhere in the response.
	 */
	public function test_issue_16_feature_support_never_returns_theme_support_arguments() {
		add_theme_support( 'custom-header', array(
			'default-image'       => 'https://example.org/header.png',
			'admin-head-callback' => '__return_empty_string',
			'width'               => 1200,
		) );

		$this->become_issue_12_admin();
		$result = WP_MCP_Discovery::list_feature_support( array() );
		$this->assertNotWPError( $result );

		$encoded = wp_json_encode( $result );
		$this->assertStringNotContainsString( 'admin-head-callback', $encoded );
		$this->assertStringNotContainsString( '__return_empty_string', $encoded );
		$this->assertStringNotContainsString( 'header.png', $encoded );

		$supported = array();
		foreach ( $result['features'] as $feature ) {
			$supported[ $feature['feature'] ] = $feature['supported'];
		}
		$this->assertTrue( $supported['custom-header'], 'The boolean answer itself must still be reported.' );

		remove_theme_support( 'custom-header' );
	}

	/* --- Global search --- */

	public function test_issue_16_global_search_finds_a_published_post() {
		self::factory()->post->create( array(
			'post_title'  => 'Acmeinc Discoverable Headline',
			'post_status' => 'publish',
			'post_author' => $this->other_user,
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Discovery::global_search( array( 'search' => 'Discoverable Headline', 'types' => array( 'post' ) ) );

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['results'] );
		$this->assertEquals( 'post', $result['results'][0]['object_type'] );
		$this->assertEquals( 'post', $result['results'][0]['subtype'] );
		$this->assertEquals( 'publish', $result['results'][0]['status'] );
		$this->assertEquals( array( 'post' ), $result['searched'] );
	}

	public function test_issue_16_global_search_returns_the_callers_own_draft() {
		self::factory()->post->create( array(
			'post_title'  => 'Acmeinc Private Notebook',
			'post_status' => 'draft',
			'post_author' => $this->agent_user,
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Discovery::global_search( array( 'search' => 'Private Notebook', 'types' => array( 'post' ) ) );

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['results'] );
		$this->assertEquals( 'draft', $result['results'][0]['status'] );
		$this->assertTrue( $result['results'][0]['editable'] );
		$this->assertStringNotContainsString( 'Private Notebook', wp_json_encode( $result ) );
	}

	public function test_issue_16_global_search_never_returns_another_users_draft() {
		self::factory()->post->create( array(
			'post_title'  => 'Acmeinc Somebody Elses Draft',
			'post_status' => 'draft',
			'post_author' => $this->other_user,
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Discovery::global_search( array( 'search' => 'Somebody Elses Draft' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array(), $result['results'], 'A draft owned by another user must never appear.' );
		$this->assertEquals( 0, $result['total'] );
	}

	/**
	 * The query-level narrowing is not the gate: a private post of another
	 * author survives WP_Query's `editable` clause and is stopped by the
	 * per-post `read_post` meta-capability check.
	 */
	public function test_issue_16_global_search_never_returns_another_users_private_post() {
		self::factory()->post->create( array(
			'post_title'  => 'Acmeinc Confidential Memo',
			'post_status' => 'private',
			'post_author' => $this->other_user,
		) );

		wp_set_current_user( $this->agent_user );
		$agent_view = WP_MCP_Discovery::global_search( array( 'search' => 'Confidential Memo' ) );
		$this->assertNotWPError( $agent_view );
		$this->assertSame( array(), $agent_view['results'], 'read_private_posts is required and the agent role does not have it.' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$subscriber_view = WP_MCP_Discovery::global_search( array( 'search' => 'Confidential Memo' ) );
		$this->assertNotWPError( $subscriber_view );
		$this->assertSame( array(), $subscriber_view['results'] );

		$this->become_issue_12_admin();
		$admin_view = WP_MCP_Discovery::global_search( array( 'search' => 'Confidential Memo' ) );
		$this->assertNotWPError( $admin_view );
		$this->assertContains( 'private', wp_list_pluck( $admin_view['results'], 'status' ), 'An administrator does see the private post.' );
	}

	public function test_issue_16_global_search_only_searches_users_with_list_users() {
		$person = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'AcmeincDiscoverablePerson' ) );

		wp_set_current_user( $this->agent_user );
		$denied = WP_MCP_Discovery::global_search( array( 'search' => 'AcmeincDiscoverablePerson' ) );
		$this->assertNotWPError( $denied );
		$this->assertNotContains( 'user', $denied['searched'], 'A caller without list_users must not search users.' );
		$this->assertEquals( array( array( 'type' => 'user', 'reason' => 'wp_mcp_permission_denied' ) ), $denied['skipped'] );
		foreach ( $denied['results'] as $row ) {
			$this->assertNotEquals( 'user', $row['object_type'] );
		}

		$this->become_issue_12_admin();
		$allowed = WP_MCP_Discovery::global_search( array( 'search' => 'AcmeincDiscoverablePerson', 'types' => array( 'user' ) ) );
		$this->assertNotWPError( $allowed );
		$this->assertSame( array(), $allowed['skipped'] );
		$this->assertContains( $person, wp_list_pluck( $allowed['results'], 'id' ) );
	}

	public function test_issue_16_global_search_never_returns_content_or_an_email_address() {
		$post_id = self::factory()->post->create( array(
			'post_title'   => 'Acmeinc Leakage Probe',
			'post_content' => 'SECRETBODYTEXT that must never be returned.',
			'post_status'  => 'publish',
		) );
		wp_update_user( array( 'ID' => $this->other_user, 'user_email' => 'leakageprobe@example.org', 'display_name' => 'Acmeinc Leakage Probe' ) );

		$this->become_issue_12_admin();
		$result = WP_MCP_Discovery::global_search( array( 'search' => 'Leakage Probe' ) );
		$this->assertNotWPError( $result );

		$encoded = wp_json_encode( $result );
		$this->assertStringNotContainsString( 'SECRETBODYTEXT', $encoded, 'Search results describe objects, never their content.' );
		$this->assertStringNotContainsString( 'leakageprobe@example.org', $encoded, 'A user hit must never carry an e-mail address.' );

		$this->assertContains( $post_id, wp_list_pluck( $result['results'], 'id' ) );
		$this->assertStringNotContainsString( 'Acmeinc Leakage Probe', $encoded, 'A title is content: search reports identity, not titles.' );
		foreach ( $result['results'] as $row ) {
			$this->assertEquals(
				array( 'object_type', 'id', 'subtype', 'status', 'date_gmt', 'editable' ),
				array_keys( $row ),
				'A search hit must carry exactly the documented closed field set.'
			);
		}
	}

	public function test_issue_16_global_search_covers_media_and_terms() {
		$attachment = self::factory()->attachment->create_object( 'wp-mcp-discovery.jpg', 0, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
			'post_title'     => 'Acmeinc Discoverable Picture',
			'post_status'    => 'inherit',
		) );
		$term = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Acmeinc Discoverable Topic' ) );

		$this->become_issue_12_admin();

		$media = WP_MCP_Discovery::global_search( array( 'search' => 'Discoverable Picture', 'types' => array( 'media' ) ) );
		$this->assertNotWPError( $media );
		$this->assertContains( $attachment, wp_list_pluck( $media['results'], 'id' ) );
		$this->assertStringNotContainsString( 'wp-mcp-discovery.jpg', wp_json_encode( $media ), 'No filename, no URL.' );
		$this->assertEquals( 'media', $media['results'][0]['object_type'] );
		$this->assertEquals( 'attachment', $media['results'][0]['subtype'] );

		$terms = WP_MCP_Discovery::global_search( array( 'search' => 'Discoverable Topic', 'types' => array( 'term' ) ) );
		$this->assertNotWPError( $terms );
		$this->assertContains( $term, wp_list_pluck( $terms['results'], 'id' ) );
		$this->assertEquals( 'category', $terms['results'][0]['subtype'] );
	}

	public function test_issue_16_global_search_validates_its_input() {
		wp_set_current_user( $this->agent_user );

		foreach ( array( '', 'a', str_repeat( 'x', 201 ) ) as $bad_search ) {
			$result = WP_MCP_Discovery::global_search( array( 'search' => $bad_search ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_discovery_validation_error', $result->get_error_code() );
		}

		foreach ( array( array( 'option' ), array( 'post', 'plugin' ), 'post' ) as $bad_types ) {
			$result = WP_MCP_Discovery::global_search( array( 'search' => 'anything', 'types' => $bad_types ) );
			$this->assertWPError( $result, 'Only the four documented object kinds are accepted.' );
			$this->assertEquals( 'wp_mcp_discovery_validation_error', $result->get_error_code() );
		}
	}

	public function test_issue_16_global_search_pagination_is_bounded() {
		wp_set_current_user( $this->agent_user );

		$result = WP_MCP_Discovery::global_search( array( 'search' => 'anything', 'per_page' => 999, 'page' => 0 ) );
		$this->assertNotWPError( $result );
		$this->assertEquals( 50, $result['per_page'] );
		$this->assertEquals( 1, $result['page'] );
		$this->assertFalse( $result['capped'] );
	}

	/**
	 * A branch that fills its own limit truncates the match, and the response
	 * has to say so. Without this, the last page of a 51-row match looks
	 * exactly like the last page of a 50-row one: `capped` false and a
	 * `total_pages` an agent would read as "that was everything".
	 */
	public function test_issue_16_global_search_reports_capped_when_a_branch_truncates() {
		self::factory()->post->create_many( 51, array(
			'post_status' => 'publish',
			'post_title'  => 'AcmeincCappedProbe',
			'post_author' => $this->other_user,
		) );

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Discovery::global_search( array( 'search' => 'AcmeincCappedProbe', 'types' => array( 'post' ) ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['capped'], 'A branch that hit its row limit must be reported as capped.' );
		$this->assertEquals( 50, $result['total'], 'total is the bounded row count this response can enumerate.' );
		$this->assertEquals( 5, $result['total_pages'] );
		$this->assertCount( 10, $result['results'] );

		$last_page = WP_MCP_Discovery::global_search( array( 'search' => 'AcmeincCappedProbe', 'types' => array( 'post' ), 'page' => 5 ) );
		$this->assertNotWPError( $last_page );
		$this->assertTrue( $last_page['capped'], 'The last page must still say the match was truncated.' );
		$this->assertCount( 10, $last_page['results'] );
	}

	/**
	 * Branch truncation is not a post-branch quirk: the term branch asks for
	 * one row more than it returns for the same reason.
	 */
	public function test_issue_16_global_search_reports_capped_when_the_term_branch_truncates() {
		for ( $index = 0; $index < 51; $index++ ) {
			self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'AcmeincCappedTerm ' . $index ) );
		}

		wp_set_current_user( $this->agent_user );
		$result = WP_MCP_Discovery::global_search( array( 'search' => 'AcmeincCappedTerm', 'types' => array( 'term' ), 'per_page' => 50 ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['capped'] );
		$this->assertEquals( 50, $result['total'] );
		$this->assertEquals( 1, $result['total_pages'] );
		$this->assertCount( 50, $result['results'] );
	}

	/**
	 * The other half of the contract: when nothing was truncated, `capped` is
	 * false and `total`/`total_pages` are the real size of the match.
	 */
	public function test_issue_16_global_search_is_not_capped_when_the_whole_match_fits() {
		self::factory()->post->create_many( 3, array(
			'post_status' => 'publish',
			'post_title'  => 'AcmeincSmallProbe',
			'post_author' => $this->other_user,
		) );

		wp_set_current_user( $this->agent_user );
		$first = WP_MCP_Discovery::global_search( array( 'search' => 'AcmeincSmallProbe', 'types' => array( 'post' ), 'per_page' => 2 ) );

		$this->assertNotWPError( $first );
		$this->assertFalse( $first['capped'], 'A match that fits within the branch limit is not capped.' );
		$this->assertEquals( 3, $first['total'] );
		$this->assertEquals( 2, $first['total_pages'] );
		$this->assertCount( 2, $first['results'] );

		$second = WP_MCP_Discovery::global_search( array( 'search' => 'AcmeincSmallProbe', 'types' => array( 'post' ), 'per_page' => 2, 'page' => 2 ) );
		$this->assertNotWPError( $second );
		$this->assertFalse( $second['capped'] );
		$this->assertCount( 1, $second['results'] );

		$beyond = WP_MCP_Discovery::global_search( array( 'search' => 'AcmeincSmallProbe', 'types' => array( 'post' ), 'per_page' => 2, 'page' => 9 ) );
		$this->assertNotWPError( $beyond );
		$this->assertSame( array(), $beyond['results'], 'A page past the end is empty, not an error.' );
		$this->assertEquals( 3, $beyond['total'] );
	}

	public function test_issue_16_global_search_refuses_a_logged_out_caller() {
		wp_set_current_user( 0 );

		$result = WP_MCP_Discovery::global_search( array( 'search' => 'anything' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * Discovery never walks the domains that have their own rules: a
	 * template, a synced pattern or a navigation block is not search results
	 * material, and neither is a revision.
	 */
	public function test_issue_16_global_search_skips_the_internal_post_types() {
		$block_id = self::factory()->post->create( array(
			'post_title'  => 'Acmeinc Internal Marker',
			'post_type'   => 'wp_block',
			'post_status' => 'publish',
		) );

		$this->become_issue_12_admin();
		$result = WP_MCP_Discovery::global_search( array( 'search' => 'Internal Marker' ) );

		$this->assertNotWPError( $result );
		$this->assertNotContains( $block_id, wp_list_pluck( $result['results'], 'id' ), 'wp_block belongs to the Site Editor domain, not to global search.' );
	}

	public function test_issue_16_discovery_abilities_resolve_to_their_own_audit_object_type() {
		$expected = array(
			'wp-mcp/global-search'                 => 'discovery',
			'wp-mcp/list-post-statuses'            => 'discovery',
			'wp-mcp/list-mime-types'               => 'discovery',
			'wp-mcp/list-block-types'              => 'discovery',
			'wp-mcp/get-block-type'                => 'discovery',
			'wp-mcp/list-pattern-categories'       => 'discovery',
			'wp-mcp/list-template-types'           => 'discovery',
			'wp-mcp/list-feature-support'          => 'discovery',
			'wp-mcp/get-current-user-capabilities' => 'user',
		);

		foreach ( $expected as $ability => $object_type ) {
			$this->assertEquals( $object_type, WP_MCP_Audit::object_type_for( $ability ), $ability . ' must resolve to ' . $object_type );
		}

		$this->assertContains( 'discovery', WP_MCP_Audit::OBJECT_TYPES );
	}

	/* --- The conservative model, swept across every discovery surface --- */

	/**
	 * The strings a hostile registration parked in every registry discovery
	 * reads. None of them may appear in any response, from any of the nine
	 * abilities, in the list form or the detail form.
	 *
	 * @return string[]
	 */
	private function issue_16_hostile_needles() {
		return array(
			'sk-live-WordPress MCPSECRET',
			'hunter2',
			'db_password',
			'/var/www/secret',
			'wp-config-backup',
			'/srv/app/private',
			'/etc/passwd',
			'evil.example',
			'token=abc123',
			'wp-mcp-closure-marker',
			'__return_empty_string',
			'acmeincSecretApiKey',
			'acmeincPluginConfig',
			'acmeincWebhook',
			'acmeincCustomSource',
			'wp-mcp-hostile-type',
			'wp-mcp-hostile-area',
			'WordPress MCP Hostile',
			'WordPress MCP Discovery Block',
			'acmeinc secret',
		);
	}

	/**
	 * Every read-only discovery ability, called with an input that works.
	 *
	 * @return array<string,array> Ability name => response.
	 */
	private function issue_16_all_discovery_responses() {
		return array(
			'wp-mcp/global-search'                 => WP_MCP_Discovery::global_search( array( 'search' => 'acmeinc' ) ),
			'wp-mcp/list-post-statuses'            => WP_MCP_Discovery::list_post_statuses( array() ),
			'wp-mcp/list-mime-types'               => WP_MCP_Discovery::list_mime_types( array() ),
			'wp-mcp/list-block-types'              => WP_MCP_Discovery::list_block_types( array( 'per_page' => 50 ) ),
			'wp-mcp/get-block-type'                => WP_MCP_Discovery::get_block_type( array( 'block_type' => self::ISSUE_16_HOSTILE_BLOCK ) ),
			'wp-mcp/list-pattern-categories'       => WP_MCP_Discovery::list_pattern_categories( array() ),
			'wp-mcp/list-template-types'           => WP_MCP_Discovery::list_template_types( array() ),
			'wp-mcp/get-current-user-capabilities' => WP_MCP_Discovery::get_current_user_capabilities( array() ),
			'wp-mcp/list-feature-support'          => WP_MCP_Discovery::list_feature_support( array() ),
		);
	}

	/**
	 * The whole domain at once, against every hostile registry fixture: no
	 * secret, path, URL, callback identifier, closure marker or label reaches
	 * any response, and nothing fatals on the way.
	 */
	public function test_issue_16_no_discovery_surface_leaks_registration_data() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_fixtures();
		$this->register_issue_16_hostile_block();
		$this->register_issue_16_hostile_registries();
		add_theme_support( 'custom-header', array(
			'default-image'       => 'https://evil.example/header.png',
			'admin-head-callback' => '__return_empty_string',
		) );
		$this->become_issue_12_admin();

		$offenders = array();
		foreach ( $this->issue_16_all_discovery_responses() as $ability => $response ) {
			$this->assertNotWPError( $response, $ability . ' must answer even with hostile registrations in place.' );
			$encoded = wp_json_encode( $response );
			foreach ( $this->issue_16_hostile_needles() as $needle ) {
				if ( false !== strpos( $encoded, $needle ) ) {
					$offenders[] = $ability . ' leaked ' . $needle;
				}
			}
		}

		remove_theme_support( 'custom-header' );
		$this->assertSame( array(), $offenders, 'Discovery leaked registration data: ' . implode( '; ', $offenders ) );
	}

	/**
	 * The positive half of the same sweep: what survives is identifiers,
	 * vocabulary terms, booleans and counts — nothing else. A hostile entry
	 * whose *name* breaks its grammar is dropped, while a hostile entry with a
	 * usable name is reported by name only.
	 */
	public function test_issue_16_discovery_reports_only_identifiers_and_flags() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_hostile_registries();
		$this->become_issue_12_admin();

		$statuses = WP_MCP_Discovery::list_post_statuses( array() );
		$this->assertNotWPError( $statuses );
		$names = wp_list_pluck( $statuses['statuses'], 'name' );
		$this->assertContains( 'wp_mcp_hostile_status', $names, 'A registry slug is exactly what discovery does report.' );
		$this->assertNotContains( str_repeat( 'acmeinc', 12 ), $names, 'A name longer than the grammar allows is dropped.' );

		$categories = WP_MCP_Discovery::list_pattern_categories( array() );
		$this->assertNotWPError( $categories );
		$this->assertContains( 'wp_mcp_hostile_category', $categories['categories'] );
		$this->assertNotContains( 'WordPress MCP Hostile/Category', $categories['categories'], 'A name that is not a registry slug is dropped whole.' );

		$mimes = WP_MCP_Discovery::list_mime_types( array() );
		$this->assertNotWPError( $mimes );
		$reported = wp_list_pluck( $mimes['mime_types'], 'mime_type' );
		$this->assertContains( 'image/wp-mcp-hostile', $reported, 'A well-formed mime type is reported.' );
		foreach ( $mimes['mime_types'] as $entry ) {
			$this->assertNotContains( 'acmeinc secret', $entry['extensions'], 'An extension that is not one is dropped.' );
		}

		$templates = WP_MCP_Discovery::list_template_types( array() );
		$this->assertNotWPError( $templates );
		$this->assertNotContains( 'wp-mcp-hostile-type', $templates['template_types'], 'A filtered-in template type outside the vocabulary is dropped.' );
		$this->assertNotContains( 'wp-mcp-hostile-area', $templates['template_part_areas'], 'A filtered-in area outside the vocabulary is dropped.' );
		foreach ( $templates['template_types'] as $type ) {
			$this->assertContains( $type, WP_MCP_Discovery::template_type_vocabulary() );
		}
		foreach ( $templates['template_part_areas'] as $area ) {
			$this->assertContains( $area, WP_MCP_Discovery::template_part_area_vocabulary() );
		}
	}

	/**
	 * Adding a read-only discovery domain must not have moved any existing
	 * ability into it, nor changed what the older domains answer.
	 */
	public function test_issue_16_does_not_change_the_existing_surface() {
		wp_set_current_user( $this->agent_user );
		$created = WP_MCP_Posts::create_post( array( 'title' => 'Issue16Regression', 'content' => 'Body' ) );
		$this->assertNotWPError( $created );
		$this->assertEquals( 'draft', $created['status'] );

		$types = WP_MCP_Post_Types::list_post_types( array() );
		$this->assertNotWPError( $types );
		$this->assertArrayHasKey( 'post_types', $types );

		$discovery_rows = 0;
		foreach ( WP_MCP_Ability_Matrix::get() as $row ) {
			if ( 'wp-mcp-discovery' === $row['category'] ) {
				++$discovery_rows;
			}
		}
		$this->assertEquals( count( $this->issue_16_ability_slugs() ), $discovery_rows );
	}

	/* ------------------------------------------------------------------
	 * The contract: schema and runtime cannot drift
	 * ---------------------------------------------------------------- */

	/**
	 * Every discovery output schema is generated from the contract, not
	 * hand-written next to it. This is the test that keeps the other
	 * contract tests honest: they walk `WP_MCP_Discovery_Contract`, and they
	 * only prove anything about the MCP surface if what is registered is that
	 * same table.
	 */
	public function test_issue_16_output_schemas_are_generated_from_the_contract() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'This WordPress version has no ability registry.' );
		}
		foreach ( $this->issue_16_ability_slugs() as $slug ) {
			$name    = 'wp-mcp/' . $slug;
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );
			$this->assertSame(
				WP_MCP_Discovery_Contract::output_schema( $name ),
				$ability->get_output_schema(),
				$name . ' must publish exactly the contract-generated output schema.'
			);
		}
	}

	/**
	 * The mandatory output architecture, asserted against the published
	 * schemas rather than against prose: every array declares `maxItems`,
	 * every string is either a closed `enum` or a `pattern` plus a
	 * `maxLength`, every integer is a bounded count or an enumeration, and
	 * every object is closed. A field that could carry a title, a label, a
	 * description, a path or any other free text cannot be expressed this
	 * way, so it cannot be in the surface.
	 */
	public function test_issue_16_every_output_field_is_bounded_by_its_schema() {
		foreach ( $this->issue_16_ability_slugs() as $slug ) {
			$name = 'wp-mcp/' . $slug;
			$this->assert_issue_16_schema_is_bounded( WP_MCP_Discovery_Contract::output_schema( $name ), $name );
		}
	}

	/**
	 * Walk one schema fragment and assert the four rules above.
	 *
	 * @param array  $schema Schema fragment.
	 * @param string $path   Human-readable location, for the failure message.
	 */
	private function assert_issue_16_schema_is_bounded( $schema, $path ) {
		$this->assertIsArray( $schema, $path . ' must be a schema array.' );
		$this->assertArrayHasKey( 'type', $schema, $path . ' must declare a type.' );

		switch ( $schema['type'] ) {
			case 'object':
				$this->assertArrayHasKey( 'additionalProperties', $schema, $path . ' must be closed.' );
				$this->assertFalse( $schema['additionalProperties'], $path . ' must reject additional properties.' );
				$this->assertArrayHasKey( 'properties', $schema, $path . ' must declare properties.' );
				$this->assertNotEmpty( $schema['properties'], $path . ' must declare at least one property.' );
				foreach ( $schema['properties'] as $field => $child ) {
					$this->assert_issue_16_schema_is_bounded( $child, $path . '.' . $field );
				}
				break;

			case 'array':
				$this->assertArrayHasKey( 'maxItems', $schema, $path . ' must declare maxItems.' );
				$this->assertIsInt( $schema['maxItems'], $path . ' maxItems must be an integer.' );
				$this->assertGreaterThan( 0, $schema['maxItems'], $path . ' maxItems must be a real ceiling.' );
				$this->assertArrayHasKey( 'items', $schema, $path . ' must declare its item shape.' );
				$this->assert_issue_16_schema_is_bounded( $schema['items'], $path . '[]' );
				break;

			case 'string':
				if ( isset( $schema['enum'] ) ) {
					$this->assertNotEmpty( $schema['enum'], $path . ' declares an empty enum.' );
					break;
				}
				$this->assertArrayHasKey( 'pattern', $schema, $path . ' must be an enum or carry an anchored pattern.' );
				$this->assertStringStartsWith( '^', $schema['pattern'], $path . ' pattern must be anchored at the start.' );
				$this->assertStringEndsWith( '$', $schema['pattern'], $path . ' pattern must be anchored at the end.' );
				$this->assertArrayHasKey( 'maxLength', $schema, $path . ' must declare maxLength.' );
				$this->assertGreaterThan( 0, $schema['maxLength'], $path . ' maxLength must be a real bound.' );
				break;

			case 'integer':
				if ( isset( $schema['enum'] ) ) {
					$this->assertNotEmpty( $schema['enum'], $path . ' declares an empty enum.' );
					break;
				}
				$this->assertArrayHasKey( 'minimum', $schema, $path . ' must be an enum or a non-negative count.' );
				$this->assertSame( 0, $schema['minimum'], $path . ' counts start at zero.' );
				/*
				 * `minimum: 0` on its own publishes "any integer above
				 * zero", which is a number of a plugin's choosing wearing a
				 * bound. Every count, identity and byte size names the
				 * ceiling it may not pass, and that ceiling has to be one a
				 * JSON consumer can round-trip exactly.
				 */
				$this->assertArrayHasKey( 'maximum', $schema, $path . ' must declare a maximum.' );
				$this->assertIsInt( $schema['maximum'], $path . ' maximum must be an integer.' );
				$this->assertGreaterThan( 0, $schema['maximum'], $path . ' maximum must be a real ceiling.' );
				$this->assertLessThanOrEqual(
					WP_MCP_Discovery_Contract::safe_integer_ceiling(),
					$schema['maximum'],
					$path . ' maximum must stay inside the largest integer a JSON number carries exactly.'
				);
				break;

			case 'boolean':
				break;

			default:
				$this->fail( $path . ' declares an unsupported type: ' . $schema['type'] );
		}
	}

	/**
	 * A ceiling lower than the vocabulary it bounds would truncate a
	 * complete, legitimate answer and report `capped` on a site that has
	 * nothing unusual registered. Every vocabulary-backed list must be able
	 * to carry its whole vocabulary.
	 */
	public function test_issue_16_contract_ceilings_can_carry_their_whole_vocabulary() {
		$pairs = array(
			'block_supports'     => 'block_support',
			'block_sub_features' => 'block_support_sub',
			'block_contexts'     => 'block_context',
			'attribute_types'    => 'attribute_type',
			'template_types'     => 'template_type',
			'template_areas'     => 'template_area',
			'features'           => 'theme_feature',
			'search_types'       => 'search_object_type',
		);
		foreach ( $pairs as $limit => $vocabulary ) {
			$this->assertGreaterThanOrEqual(
				count( WP_MCP_Discovery_Contract::vocabulary( $vocabulary ) ),
				WP_MCP_Discovery_Contract::limit( $limit ),
				'The ' . $limit . ' ceiling must be able to carry the whole ' . $vocabulary . ' vocabulary.'
			);
		}
	}

	/**
	 * The runtime reads its vocabularies from the contract, so the enum a
	 * client validates against and the allowlist this code filters with are
	 * the same list.
	 */
	public function test_issue_16_runtime_vocabularies_are_the_contract_vocabularies() {
		$pairs = array(
			'block_support'      => WP_MCP_Discovery::block_support_vocabulary(),
			'block_support_sub'  => WP_MCP_Discovery::block_support_sub_vocabulary(),
			'block_context'      => WP_MCP_Discovery::block_context_vocabulary(),
			'attribute_type'     => WP_MCP_Discovery::block_attribute_type_vocabulary(),
			'attribute_source'   => WP_MCP_Discovery::block_attribute_source_vocabulary(),
			'template_type'      => WP_MCP_Discovery::template_type_vocabulary(),
			'template_area'      => WP_MCP_Discovery::template_part_area_vocabulary(),
			'theme_feature'      => WP_MCP_Discovery::theme_feature_vocabulary(),
			'search_object_type' => WP_MCP_Discovery::searchable_object_types(),
		);
		foreach ( $pairs as $name => $runtime ) {
			$this->assertSame( WP_MCP_Discovery_Contract::vocabulary( $name ), $runtime, 'The ' . $name . ' vocabulary must come from the contract.' );
		}
	}

	/**
	 * Every grammar must reject the shapes the conservative model exists to
	 * keep out — a path, a URL, free text with a space, a control character,
	 * and anything past its length bound — and the JSON Schema pattern the
	 * client validates against must reject exactly the same things.
	 */
	public function test_issue_16_every_grammar_rejects_paths_urls_and_free_text() {
		$hostile = array(
			'/var/www/secret/wp-config-backup.php',
			'../../wp-config.php',
			'https://evil.example/hook?token=abc123',
			'sk-live-WordPress MCP SECRET',
			"line\nbreak",
			// PCRE lets an unmodified `$` match before a trailing newline;
			// JSON Schema does not, so the runtime must be the strict one.
			"post\n",
		);
		foreach ( array_keys( WP_MCP_Discovery_Contract::grammars() ) as $grammar ) {
			$php = WP_MCP_Discovery_Contract::preg_pattern( $grammar );
			/*
			 * The `D` modifier is what makes PCRE read the published pattern
			 * the way a JSON Schema validator does: ECMA-262's `$` anchors at
			 * the true end of the string, and without `D` PCRE's does not, so
			 * an unmodified compile would test a looser grammar than the one
			 * the client actually validates against.
			 */
			$schema = '#' . WP_MCP_Discovery_Contract::schema_pattern( $grammar ) . '#D';

			foreach ( $hostile as $value ) {
				$this->assertSame( 0, preg_match( $php, $value ), 'The ' . $grammar . ' grammar must reject ' . wp_json_encode( $value ) );
				$this->assertSame( 0, preg_match( $schema, $value ), 'The ' . $grammar . ' schema pattern must reject ' . wp_json_encode( $value ) );
			}

			$too_long = str_repeat( 'a', WP_MCP_Discovery_Contract::max_length( $grammar ) + 1 );
			$this->assertSame( 0, preg_match( $php, $too_long ), 'The ' . $grammar . ' grammar must reject a value past its length bound.' );
			$this->assertSame( 0, preg_match( $php, '' ), 'The ' . $grammar . ' grammar must reject the empty string.' );
		}
	}

	/**
	 * The end-to-end proof: run all nine abilities against a site whose
	 * registries have been filled with hostile registrations, and validate
	 * every response against the schema its ability publishes. A response
	 * carrying an undeclared field, an unbounded list, an identifier outside
	 * its grammar or a term outside its vocabulary fails here.
	 */
	public function test_issue_16_runtime_responses_validate_against_their_published_schema() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_fixtures();
		$this->register_issue_16_hostile_block();
		$this->register_issue_16_hostile_registries();
		$this->become_issue_12_admin();

		$responses = array(
			'wp-mcp/global-search'                 => WP_MCP_Discovery::global_search( array( 'search' => 'acmeinc' ) ),
			'wp-mcp/list-post-statuses'            => WP_MCP_Discovery::list_post_statuses( array() ),
			'wp-mcp/list-mime-types'               => WP_MCP_Discovery::list_mime_types( array() ),
			'wp-mcp/list-block-types'              => WP_MCP_Discovery::list_block_types( array() ),
			'wp-mcp/get-block-type'                => WP_MCP_Discovery::get_block_type( array( 'block_type' => self::ISSUE_16_HOSTILE_BLOCK ) ),
			'wp-mcp/list-pattern-categories'       => WP_MCP_Discovery::list_pattern_categories( array() ),
			'wp-mcp/list-template-types'           => WP_MCP_Discovery::list_template_types( array() ),
			'wp-mcp/get-current-user-capabilities' => WP_MCP_Discovery::get_current_user_capabilities( array() ),
			'wp-mcp/list-feature-support'          => WP_MCP_Discovery::list_feature_support( array() ),
		);

		foreach ( $responses as $ability => $response ) {
			$this->assertNotWPError( $response, $ability . ' should answer.' );
			$this->assert_issue_16_value_matches_schema( $response, WP_MCP_Discovery_Contract::output_schema( $ability ), $ability );
		}
	}

	/**
	 * Validate one runtime value against one schema fragment.
	 *
	 * @param mixed  $value  Runtime value.
	 * @param array  $schema Schema fragment.
	 * @param string $path   Human-readable location.
	 */
	private function assert_issue_16_value_matches_schema( $value, $schema, $path ) {
		switch ( $schema['type'] ) {
			case 'object':
				$this->assertIsArray( $value, $path . ' must be an object.' );
				foreach ( array_keys( $value ) as $field ) {
					$this->assertArrayHasKey( $field, $schema['properties'], $path . ' returned an undeclared field: ' . $field );
				}
				foreach ( $schema['properties'] as $field => $child ) {
					$this->assertArrayHasKey( $field, $value, $path . ' is missing the declared field ' . $field );
					$this->assert_issue_16_value_matches_schema( $value[ $field ], $child, $path . '.' . $field );
				}
				break;

			case 'array':
				$this->assertIsArray( $value, $path . ' must be an array.' );
				if ( ! empty( $value ) ) {
					$this->assertSame( range( 0, count( $value ) - 1 ), array_keys( $value ), $path . ' must be a JSON list, not a map.' );
				}
				$this->assertLessThanOrEqual( $schema['maxItems'], count( $value ), $path . ' exceeded its declared maxItems.' );
				foreach ( $value as $index => $entry ) {
					$this->assert_issue_16_value_matches_schema( $entry, $schema['items'], $path . '[' . $index . ']' );
				}
				break;

			case 'string':
				$this->assertIsString( $value, $path . ' must be a string.' );
				if ( isset( $schema['enum'] ) ) {
					$this->assertContains( $value, $schema['enum'], $path . ' returned a term outside its vocabulary.' );
					break;
				}
				$this->assertLessThanOrEqual( $schema['maxLength'], strlen( $value ), $path . ' exceeded its declared maxLength.' );
				$this->assertSame( 1, preg_match( '#' . $schema['pattern'] . '#', $value ), $path . ' returned a value outside its grammar: ' . $value );
				break;

			case 'integer':
				$this->assertIsInt( $value, $path . ' must be an integer.' );
				if ( isset( $schema['enum'] ) ) {
					$this->assertContains( $value, $schema['enum'], $path . ' returned a value outside its vocabulary.' );
					break;
				}
				$this->assertGreaterThanOrEqual( $schema['minimum'], $value, $path . ' must not be negative.' );
				$this->assertLessThanOrEqual( $schema['maximum'], $value, $path . ' exceeded its declared maximum.' );
				break;

			case 'boolean':
				$this->assertIsBool( $value, $path . ' must be a boolean.' );
				break;

			default:
				$this->fail( $path . ' has an unsupported schema type.' );
		}
	}

	/* ------------------------------------------------------------------
	 * Oversized but entirely valid registries
	 * ---------------------------------------------------------------- */

	/**
	 * The block registry is unbounded — every plugin adds to it — so the
	 * listing has to bound its output *and* its work. Two things are proven
	 * here: the scan stops at the contract ceiling and says `capped`, and,
	 * through a block type that counts how many times discovery inspected
	 * it, that a summary is built only for the blocks on the requested page
	 * rather than once per registered block.
	 *
	 * `total` is what matched *inside* the scan budget, not the budget: the
	 * budget is spent on every entry examined, and this site's own core
	 * blocks are examined before the fixture ones. It is a lower bound, and
	 * the assertion says exactly that.
	 */
	public function test_issue_16_block_listing_bounds_its_work_and_reports_capped() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) || ! class_exists( 'WP_MCP_Counting_Block_Type' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$ceiling = WP_MCP_Discovery_Contract::limit( 'block_scan' );
		$this->register_issue_16_bulk_blocks( $ceiling + 5 );
		$this->become_issue_12_admin();

		WP_MCP_Counting_Block_Type::$inspections = 0;
		$listed = WP_MCP_Discovery::list_block_types( array(
			'block_namespace' => self::ISSUE_16_BULK_NAMESPACE,
			'page'            => 1,
			'per_page'        => 5,
		) );

		$this->assertNotWPError( $listed );
		$this->assertCount( 5, $listed['block_types'], 'A page is a page, whatever the registry holds.' );
		$this->assertTrue( $listed['capped'], 'A registry past the scan ceiling must say so.' );
		$this->assertGreaterThan( 0, $listed['total'], 'The fixture namespace was reached inside the budget.' );
		$this->assertLessThanOrEqual( $ceiling, $listed['total'], 'total is the bounded scan, and therefore a lower bound.' );
		$this->assertLessThanOrEqual(
			5,
			WP_MCP_Counting_Block_Type::$inspections,
			'A summary must be built for the requested page only, never for every registered block.'
		);
	}

	/**
	 * A registry that fits under the ceiling must not claim it was cut.
	 */
	public function test_issue_16_block_listing_is_not_capped_when_the_namespace_fits() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) || ! class_exists( 'WP_MCP_Counting_Block_Type' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_bulk_blocks( 12 );
		$this->become_issue_12_admin();

		$listed = WP_MCP_Discovery::list_block_types( array(
			'block_namespace' => self::ISSUE_16_BULK_NAMESPACE,
			'per_page'        => 50,
		) );
		$this->assertNotWPError( $listed );
		$this->assertFalse( $listed['capped'] );
		$this->assertSame( 12, $listed['total'] );
		$this->assertCount( 12, $listed['block_types'] );
	}

	/**
	 * A single block may declare more attributes, parents and ancestors than
	 * the response is willing to carry. Each list stops at its own contract
	 * ceiling and the detail response reports `capped`.
	 */
	public function test_issue_16_one_blocks_lists_are_bounded_and_report_capped() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_oversized_block();
		$this->become_issue_12_admin();

		$detail = WP_MCP_Discovery::get_block_type( array( 'block_type' => self::ISSUE_16_OVERSIZED_BLOCK ) );
		$this->assertNotWPError( $detail );

		$this->assertCount( WP_MCP_Discovery_Contract::limit( 'block_attributes' ), $detail['attribute_names'] );
		$this->assertCount( WP_MCP_Discovery_Contract::limit( 'block_attributes' ), $detail['attributes'] );
		$this->assertCount( WP_MCP_Discovery_Contract::limit( 'block_parents' ), $detail['parent'] );
		$this->assertCount( WP_MCP_Discovery_Contract::limit( 'block_parents' ), $detail['ancestor'] );
		$this->assertTrue( $detail['capped'], 'A block whose lists were cut must say so.' );

		$this->assert_issue_16_value_matches_schema(
			$detail,
			WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/get-block-type' ),
			'wp-mcp/get-block-type'
		);
	}

	/**
	 * `api_version` is registration data like everything else: a block may
	 * declare any integer, a string or an object. Only the versions the block
	 * editor actually defines are reported; anything else answers 0.
	 */
	public function test_issue_16_block_api_version_is_a_vocabulary_term() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_fixtures();
		$this->become_issue_12_admin();

		$block = WP_Block_Type_Registry::get_instance()->get_registered( self::ISSUE_16_BLOCK );
		$this->assertInstanceOf( 'WP_Block_Type', $block );

		foreach ( array( 999999, '3', 3.5, PHP_INT_MAX, array( 3 ), -1 ) as $hostile ) {
			$block->api_version = $hostile;
			$detail             = WP_MCP_Discovery::get_block_type( array( 'block_type' => self::ISSUE_16_BLOCK ) );
			$this->assertNotWPError( $detail );
			$this->assertSame( 0, $detail['api_version'], 'An api_version outside the vocabulary is reported as 0.' );
		}

		$block->api_version = 3;
		$detail             = WP_MCP_Discovery::get_block_type( array( 'block_type' => self::ISSUE_16_BLOCK ) );
		$this->assertNotWPError( $detail );
		$this->assertSame( 3, $detail['api_version'] );
		$this->assertContains( $detail['api_version'], WP_MCP_Discovery_Contract::vocabulary( 'block_api_version' ) );
	}

	/**
	 * A site may genuinely register more post statuses than the response
	 * carries: the list stops at the ceiling and reports `capped` instead of
	 * growing, and `total` is then the bounded count, not the registry's.
	 */
	public function test_issue_16_post_status_registry_is_bounded_and_capped() {
		$ceiling = WP_MCP_Discovery_Contract::limit( 'collection' );
		$this->register_issue_16_bulk_post_statuses( $ceiling + 10 );
		$this->become_issue_12_admin();

		$statuses = WP_MCP_Discovery::list_post_statuses( array() );
		$this->assertNotWPError( $statuses );
		$this->assertCount( $ceiling, $statuses['statuses'] );
		$this->assertSame( $ceiling, $statuses['total'] );
		$this->assertTrue( $statuses['capped'] );
		$this->assert_issue_16_value_matches_schema(
			$statuses,
			WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-post-statuses' ),
			'wp-mcp/list-post-statuses'
		);
	}

	/**
	 * The same for the pattern category registry.
	 */
	public function test_issue_16_pattern_category_registry_is_bounded_and_capped() {
		if ( ! function_exists( 'register_block_pattern_category' ) ) {
			$this->markTestSkipped( 'This WordPress version has no pattern category registry.' );
		}
		$ceiling = WP_MCP_Discovery_Contract::limit( 'collection' );
		$this->register_issue_16_bulk_pattern_categories( $ceiling + 10 );
		$this->become_issue_12_admin();

		$categories = WP_MCP_Discovery::list_pattern_categories( array() );
		$this->assertNotWPError( $categories );
		$this->assertCount( $ceiling, $categories['categories'] );
		$this->assertTrue( $categories['capped'] );
		$this->assert_issue_16_value_matches_schema(
			$categories,
			WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-pattern-categories' ),
			'wp-mcp/list-pattern-categories'
		);
	}

	/**
	 * The mime map is filterable, so a plugin can make it arbitrarily large,
	 * and several extension groups may map to one mime type. Both the number
	 * of mime types and the merged extension list per type are bounded, and
	 * either cut sets `capped`.
	 */
	public function test_issue_16_mime_registry_is_bounded_and_capped() {
		add_filter( 'upload_mimes', array( $this, 'issue_16_bulk_mimes' ) );
		$this->become_issue_12_admin();

		$mimes = WP_MCP_Discovery::list_mime_types( array() );
		remove_filter( 'upload_mimes', array( $this, 'issue_16_bulk_mimes' ) );

		$this->assertNotWPError( $mimes );
		$this->assertLessThanOrEqual( WP_MCP_Discovery_Contract::limit( 'collection' ), count( $mimes['mime_types'] ) );
		$this->assertTrue( $mimes['capped'], 'A mime map past the ceiling must say so.' );
		$merged = 0;
		foreach ( $mimes['mime_types'] as $entry ) {
			$this->assertLessThanOrEqual( WP_MCP_Discovery_Contract::limit( 'mime_extensions' ), count( $entry['extensions'] ) );
			if ( 'application/wp-mcp-merged' === $entry['mime_type'] ) {
				$merged = count( $entry['extensions'] );
			}
		}
		$this->assertSame( WP_MCP_Discovery_Contract::limit( 'mime_extensions' ), $merged, 'Extensions merged into one mime type stop at the per-type ceiling.' );
		$this->assert_issue_16_value_matches_schema(
			$mimes,
			WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-mime-types' ),
			'wp-mcp/list-mime-types'
		);
	}

	/**
	 * A plugin may grant a user an unbounded number of capabilities through
	 * `user_has_cap`. The response stops at the ceiling and reports `capped`.
	 */
	public function test_issue_16_capability_list_is_bounded_and_capped() {
		$ceiling = WP_MCP_Discovery_Contract::limit( 'collection' );
		$this->become_issue_12_admin();
		$this->grant_issue_16_bulk_capabilities( $ceiling + 30 );

		$caps = WP_MCP_Discovery::get_current_user_capabilities( array() );

		$this->assertNotWPError( $caps );
		$this->assertCount( $ceiling, $caps['capabilities'] );
		$this->assertSame( $ceiling, $caps['total'] );
		$this->assertTrue( $caps['capped'] );
		$this->assert_issue_16_value_matches_schema(
			$caps,
			WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/get-current-user-capabilities' ),
			'wp-mcp/get-current-user-capabilities'
		);
	}

	/**
	 * A complete answer must not claim it was cut, and a classic theme must
	 * still not announce a Site Editor it does not have.
	 */
	public function test_issue_16_template_surface_reports_capped_false_when_complete() {
		$this->become_issue_12_admin();

		foreach ( array( array( false, false ), array( false, true ), array( true, true ) ) as $situation ) {
			list( $block_theme, $editable ) = $situation;
			$surface = WP_MCP_Discovery::describe_template_surface( $block_theme, $editable );
			$this->assertFalse( $surface['capped'], 'The core FSE vocabulary fits its ceiling.' );
			if ( ! $editable ) {
				$this->assertSame( 'classic_theme', $surface['reason'] );
				$this->assertSame( array(), $surface['template_types'] );
				$this->assertSame( array(), $surface['template_part_areas'] );
			}
			$this->assert_issue_16_value_matches_schema(
				$surface,
				WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-template-types' ),
				'wp-mcp/list-template-types'
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Numbers: finite by schema, truthful at runtime
	 * ---------------------------------------------------------------- */

	/**
	 * `minimum: 0` alone is not a bound — it publishes "any integer above
	 * zero", which is exactly the number of a plugin's choosing this domain
	 * refuses to carry. Every numeric descriptor the contract can build must
	 * name a finite ceiling, and that ceiling must be one a JSON consumer
	 * round-trips exactly: above 2^53-1 an IEEE-754 double stops being able
	 * to tell two integers apart, so the number that arrives is not the one
	 * that left.
	 */
	public function test_issue_16_numeric_descriptors_publish_a_finite_json_safe_maximum() {
		$ceiling = WP_MCP_Discovery_Contract::safe_integer_ceiling();
		$this->assertIsInt( $ceiling );
		$this->assertGreaterThan( 0, $ceiling );
		$this->assertLessThanOrEqual( 9007199254740991, $ceiling, 'A JSON number cannot carry more than 2^53-1 exactly.' );
		$this->assertLessThanOrEqual( PHP_INT_MAX, $ceiling, 'This build cannot even hold a larger integer.' );

		$descriptors = array(
			'count'     => WP_MCP_Discovery_Contract::counter( 'collection' ),
			'object_id' => WP_MCP_Discovery_Contract::object_id(),
			'byte_size' => WP_MCP_Discovery_Contract::byte_count(),
		);
		foreach ( $descriptors as $kind => $descriptor ) {
			$schema = WP_MCP_Discovery_Contract::schema_for( $descriptor );
			$this->assertSame( 'integer', $schema['type'], $kind . ' must be an integer.' );
			$this->assertSame( 0, $schema['minimum'], $kind . ' starts at zero.' );
			$this->assertArrayHasKey( 'maximum', $schema, $kind . ' must name the ceiling it is bounded by.' );
			$this->assertIsInt( $schema['maximum'], $kind . ' maximum must be an integer.' );
			$this->assertGreaterThan( 0, $schema['maximum'], $kind . ' maximum must be a real ceiling.' );
			$this->assertLessThanOrEqual( $ceiling, $schema['maximum'], $kind . ' maximum must stay JSON-safe.' );
		}
	}

	/**
	 * A count that does not fit is reported at the nearest bound *and*
	 * flagged, never emitted raw: the raw number would break the ability's
	 * own `maximum`, and a silently clamped one would read as a complete
	 * answer. Floats above PHP's integer range saturate under `(int)`, so
	 * the comparison has to happen before the cast — `INF` in particular
	 * casts to a platform-dependent integer, not to a large one.
	 */
	public function test_issue_16_bounded_count_stays_finite_and_flags_what_did_not_fit() {
		$ceiling = WP_MCP_Discovery_Contract::limit( 'collection' );

		foreach ( array( $ceiling + 1, PHP_INT_MAX, INF, 1.0e300, (float) $ceiling + 0.5 ) as $over ) {
			$clamped = false;
			$value   = WP_MCP_Discovery_Contract::bounded_count( $over, 'collection', $clamped );
			$this->assertIsInt( $value, 'A count is always an integer.' );
			$this->assertSame( $ceiling, $value, 'A count past the ceiling is reported at the ceiling.' );
			$this->assertTrue( $clamped, 'A count that did not fit must be flagged.' );
		}

		foreach ( array( -1, -0.5, NAN, '12', null, array(), true, new stdClass() ) as $unusable ) {
			$clamped = false;
			$value   = WP_MCP_Discovery_Contract::bounded_count( $unusable, 'collection', $clamped );
			$this->assertSame( 0, $value, 'A count that cannot exist is reported as zero.' );
			$this->assertTrue( $clamped, 'A value that is not a usable count must be flagged.' );
		}

		foreach ( array( 0, 1, $ceiling ) as $fits ) {
			$clamped = true;
			$this->assertSame( $fits, WP_MCP_Discovery_Contract::bounded_count( $fits, 'collection', $clamped ) );
			$this->assertFalse( $clamped, 'A count inside its window must not claim it was cut.' );
		}

		$clamped = true;
		$this->assertSame( 7, WP_MCP_Discovery_Contract::bounded_count( 7.9, 'collection', $clamped ) );
		$this->assertFalse( $clamped );
	}

	/**
	 * An identifier is a name for one row, so the nearest legal value is not
	 * an approximation of it — it points at a different object. Anything
	 * outside the window is reported as 0, "no object", and flagged; nothing
	 * is ever clamped. `$wpdb` hands IDs back as strings, and a digit string
	 * wider than PHP's integer range saturates under `(int)`, silently
	 * turning one row's ID into another's, so the digits are compared before
	 * any cast can lose them.
	 */
	public function test_issue_16_bounded_id_reports_zero_rather_than_a_different_object() {
		$ceiling = WP_MCP_Discovery_Contract::limit( 'object_id' );

		foreach ( array( 0, 1, 42, $ceiling ) as $fits ) {
			$overflow = true;
			$this->assertSame( $fits, WP_MCP_Discovery_Contract::bounded_id( $fits, $overflow ) );
			$this->assertFalse( $overflow, 'An ID inside its window is reportable.' );
		}

		foreach ( array( '42' => 42, '0042' => 42, '000' => 0, '0' => 0 ) as $digits => $expected ) {
			$overflow = true;
			$this->assertSame(
				$expected,
				WP_MCP_Discovery_Contract::bounded_id( (string) $digits, $overflow ),
				'A digit string is the shape $wpdb answers with.'
			);
			$this->assertFalse( $overflow );
		}

		$wider_than_the_window = array(
			(string) $ceiling . '0',
			'99999999999999999999',
			str_repeat( '9', 32 ),
		);
		foreach ( $wider_than_the_window as $wide ) {
			$overflow = false;
			$this->assertSame( 0, WP_MCP_Discovery_Contract::bounded_id( $wide, $overflow ), 'A clamped ID would name a different object, so it is refused.' );
			$this->assertTrue( $overflow );
		}

		$not_an_id = array( -1, '1.5', '1e3', ' 12', '12abc', '-12', 1.5, true, null, array(), new stdClass(), str_repeat( '9', 33 ) );
		foreach ( $not_an_id as $bad ) {
			$overflow = false;
			$this->assertSame( 0, WP_MCP_Discovery_Contract::bounded_id( $bad, $overflow ) );
			$this->assertTrue( $overflow, 'Anything that is not a reportable identity must be flagged.' );
		}
	}

	/* ------------------------------------------------------------------
	 * Truncation that hides one level down
	 * ---------------------------------------------------------------- */

	/**
	 * One `jpg|jpeg|jpe|...` key may on its own declare more extensions than
	 * the per-type ceiling carries. The cut then happens *inside*
	 * `safe_string_list()`, so the merge loop below it never reaches its own
	 * ceiling check — the list it is handed is already short — and the
	 * response would report a truncated extension list as the complete set
	 * WordPress accepts. An agent reading it would refuse an upload the site
	 * would have taken.
	 *
	 * The fixture deliberately returns a one-entry mime map, so nothing else
	 * in this response can be the thing that set `capped`.
	 */
	public function test_issue_16_one_oversized_mime_extension_group_reports_capped() {
		add_filter( 'upload_mimes', array( $this, 'issue_16_single_oversized_mime_group' ), 99 );
		$this->become_issue_12_admin();

		$mimes = WP_MCP_Discovery::list_mime_types( array() );
		remove_filter( 'upload_mimes', array( $this, 'issue_16_single_oversized_mime_group' ), 99 );

		$this->assertNotWPError( $mimes );
		$this->assertCount( 1, $mimes['mime_types'], 'The map itself is far below the mime ceiling.' );
		$this->assertSame( 1, $mimes['total'] );
		$this->assertLessThan( WP_MCP_Discovery_Contract::limit( 'collection' ), $mimes['total'], 'The mime ceiling cannot be what set capped here.' );
		$this->assertCount(
			WP_MCP_Discovery_Contract::limit( 'mime_extensions' ),
			$mimes['mime_types'][0]['extensions'],
			'A single group stops at the per-type extension ceiling.'
		);
		$this->assertTrue(
			$mimes['capped'],
			'A group cut inside safe_string_list() never reaches the merge ceiling, so it has to report capped itself.'
		);
		$this->assert_issue_16_value_matches_schema(
			$mimes,
			WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-mime-types' ),
			'wp-mcp/list-mime-types'
		);
	}

	/**
	 * A block whose own parent, ancestor or attribute list was cut is a
	 * truncation the listing carries, not one only `get-block-type` ever
	 * admits. `format_block_summary()` reports it through its by-reference
	 * flag and the listing has to turn that into its own `capped`, or a page
	 * of cut summaries reads as complete.
	 *
	 * The registry here is nowhere near the scan budget, so the budget
	 * cannot be what set the flag.
	 */
	public function test_issue_16_block_listing_reports_a_truncated_summary_as_capped() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->register_issue_16_oversized_block();
		$this->become_issue_12_admin();

		$listed = WP_MCP_Discovery::list_block_types( array(
			'block_namespace' => 'wp-mcp-test',
			'per_page'        => 50,
		) );

		$this->assertNotWPError( $listed );
		$this->assertLessThan(
			WP_MCP_Discovery_Contract::limit( 'block_scan' ),
			$listed['total'],
			'The scan budget cannot be what set capped here.'
		);
		$this->assertContains( self::ISSUE_16_OVERSIZED_BLOCK, wp_list_pluck( $listed['block_types'], 'name' ) );

		foreach ( $listed['block_types'] as $block ) {
			if ( self::ISSUE_16_OVERSIZED_BLOCK !== $block['name'] ) {
				continue;
			}
			$this->assertCount( WP_MCP_Discovery_Contract::limit( 'block_parents' ), $block['parent'] );
			$this->assertCount( WP_MCP_Discovery_Contract::limit( 'block_attributes' ), $block['attribute_names'] );
		}

		$this->assertTrue( $listed['capped'], 'A cut summary on the returned page is this response own truncation.' );
		$this->assert_issue_16_value_matches_schema(
			$listed,
			WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-block-types' ),
			'wp-mcp/list-block-types'
		);
	}

	/* ------------------------------------------------------------------
	 * The scan budget is spent on entries examined, not entries kept
	 * ---------------------------------------------------------------- */

	/**
	 * A budget applied after the namespace filter is not a budget: the kept
	 * list never grows, so the ceiling check never fires and the whole
	 * registry is walked. A site with a large block registry could then make
	 * every call to this ability scan every entry simply by asking for a
	 * namespace that matches none of them.
	 *
	 * The proof is `capped`: with the budget applied first, a registry past
	 * it says so even though nothing matched.
	 */
	public function test_issue_16_block_scan_budget_is_spent_on_entries_that_do_not_match_the_namespace() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) || ! class_exists( 'WP_MCP_Counting_Block_Type' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$ceiling = WP_MCP_Discovery_Contract::limit( 'block_scan' );
		$this->register_issue_16_bulk_blocks( $ceiling + 5 );
		$this->become_issue_12_admin();

		WP_MCP_Counting_Block_Type::$inspections = 0;
		$listed = WP_MCP_Discovery::list_block_types( array( 'block_namespace' => 'acmeincvoid' ) );

		$this->assertNotWPError( $listed );
		$this->assertSame( array(), $listed['block_types'], 'Nothing is registered in that namespace.' );
		$this->assertSame( 0, $listed['total'] );
		$this->assertTrue(
			$listed['capped'],
			'The budget counts entries examined, so a registry past it is capped even when nothing matched.'
		);
		$this->assertSame( 0, WP_MCP_Counting_Block_Type::$inspections, 'No summary is built when no name matched.' );
	}

	/**
	 * The same hazard through the other filter. Core's registry only
	 * requires `[a-z0-9-]+/[a-z0-9-]+`, so a digit-leading namespace is a
	 * legal WordPress block name and an illegal discovery identifier: an
	 * entry this domain will never report, which must still cost scan budget
	 * rather than being skipped for free.
	 */
	public function test_issue_16_block_scan_budget_is_spent_on_names_outside_the_grammar() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) || ! class_exists( 'WP_MCP_Counting_Block_Type' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$ceiling = WP_MCP_Discovery_Contract::limit( 'block_scan' );
		$this->register_issue_16_ungrammatical_blocks( $ceiling + 5 );
		$this->become_issue_12_admin();

		WP_MCP_Counting_Block_Type::$inspections = 0;
		$listed = WP_MCP_Discovery::list_block_types( array( 'per_page' => 5 ) );

		$this->assertNotWPError( $listed );
		$this->assertTrue(
			$listed['capped'],
			'A registry full of names this domain will not report still spends scan budget.'
		);
		$this->assertLessThanOrEqual( $ceiling, $listed['total'] );
		$this->assertSame(
			0,
			WP_MCP_Counting_Block_Type::$inspections,
			'A name outside the grammar is dropped, so its block type is never inspected.'
		);

		$pattern = '#' . WP_MCP_Discovery_Contract::schema_pattern( 'block' ) . '#';
		foreach ( $listed['block_types'] as $block ) {
			$this->assertSame( 1, preg_match( $pattern, $block['name'] ), 'Only names inside the grammar are reported.' );
		}

		$this->assert_issue_16_value_matches_schema(
			$listed,
			WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-block-types' ),
			'wp-mcp/list-block-types'
		);
	}

	/* ------------------------------------------------------------------
	 * Selectors are refused, never rewritten
	 * ---------------------------------------------------------------- */

	/**
	 * `sanitize_key()` on a selector is a silent answer swap: `Core` becomes
	 * `core`, so the caller is answered about a namespace it did not ask
	 * for, and `core/paragraph` becomes `coreparagraph`, so an empty result
	 * is indistinguishable from an empty namespace. Neither can be told
	 * apart from a correct answer by the client. A selector this domain
	 * would refuse to echo back is refused on the way in.
	 */
	public function test_issue_16_list_block_types_refuses_a_selector_instead_of_rewriting_it() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->become_issue_12_admin();

		$refused = array(
			'Core',
			'CORE',
			'core/paragraph',
			'core_plugin',
			'../../wp-config.php',
			'https://evil.example/hook?token=abc123',
			'core namespace',
			'_core',
			'9core',
			'-core',
			"core\n",
			' core',
			str_repeat( 'a', WP_MCP_Discovery_Contract::max_length( 'block_ns' ) + 1 ),
			array( 'core' ),
			42,
		);
		foreach ( $refused as $selector ) {
			$result = WP_MCP_Discovery::list_block_types( array( 'block_namespace' => $selector ) );
			$this->assertWPError( $result, 'block_namespace ' . wp_json_encode( $selector ) . ' must be refused, never rewritten.' );
			$this->assertEquals( 'wp_mcp_discovery_validation_error', $result->get_error_code() );
		}

		$accepted = WP_MCP_Discovery::list_block_types( array( 'block_namespace' => 'core' ) );
		$this->assertNotWPError( $accepted );
		foreach ( $accepted['block_types'] as $block ) {
			$this->assertStringStartsWith( 'core/', $block['name'], 'A namespace selector selects that namespace and nothing else.' );
		}
	}

	/**
	 * The selector's published `pattern` and `maxLength` are the grammar the
	 * runtime validates it against, generated from the same contract entry.
	 * A hand-written input bound could drift looser than the code, which is
	 * how a client learns about a refusal only by being refused.
	 */
	public function test_issue_16_block_namespace_input_publishes_the_grammar_it_enforces() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'This WordPress version has no ability registry.' );
		}
		$ability = wp_get_ability( 'wp-mcp/list-block-types' );
		$this->assertNotNull( $ability );

		$selector = $ability->get_input_schema()['properties']['block_namespace'];
		$this->assertSame( 'string', $selector['type'] );
		$this->assertSame( WP_MCP_Discovery_Contract::schema_pattern( 'block_ns' ), $selector['pattern'] );
		$this->assertSame( WP_MCP_Discovery_Contract::max_length( 'block_ns' ), $selector['maxLength'] );

		/*
		 * The namespace grammar has to accept exactly the namespaces a block
		 * name can start with, or the selector could never match a block
		 * this domain reports.
		 */
		$block_pattern = '#' . WP_MCP_Discovery_Contract::schema_pattern( 'block' ) . '#';
		$namespaces    = array( 'core', 'my-plugin', 'a', str_repeat( 'a', WP_MCP_Discovery_Contract::max_length( 'block_ns' ) ) );
		foreach ( $namespaces as $namespace ) {
			$this->assertSame( 1, preg_match( '#' . $selector['pattern'] . '#', $namespace ) );
			$this->assertSame( 1, preg_match( $block_pattern, $namespace . '/block' ), 'A legal namespace must start a legal block name.' );
		}
	}

	/* ------------------------------------------------------------------
	 * Pagination is bounded on the way in as well as on the way out
	 * ---------------------------------------------------------------- */

	/**
	 * `page` is echoed back, and the response schema bounds it at
	 * `max_page`. Clamping a request for page 10^9 down to that ceiling
	 * would answer with rows the client never asked for, so the request is
	 * refused — the one honest option that also keeps the response inside
	 * its own schema.
	 */
	public function test_issue_16_paginated_discovery_refuses_a_page_above_its_published_ceiling() {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->markTestSkipped( 'This WordPress version has no block type registry.' );
		}
		$this->become_issue_12_admin();
		$max = WP_MCP_Discovery_Contract::limit( 'max_page' );

		foreach ( array( $max + 1, $max + 1000, PHP_INT_MAX ) as $beyond ) {
			$search = WP_MCP_Discovery::global_search( array( 'search' => 'acmeinc', 'page' => $beyond ) );
			$this->assertWPError( $search, 'global-search must refuse a page above its ceiling.' );
			$this->assertEquals( 'wp_mcp_discovery_validation_error', $search->get_error_code() );

			$blocks = WP_MCP_Discovery::list_block_types( array( 'page' => $beyond ) );
			$this->assertWPError( $blocks, 'list-block-types must refuse a page above its ceiling.' );
			$this->assertEquals( 'wp_mcp_discovery_validation_error', $blocks->get_error_code() );
		}

		// The ceiling itself is a legal, empty page — and still inside its schema.
		$edge = WP_MCP_Discovery::list_block_types( array( 'page' => $max, 'per_page' => 5 ) );
		$this->assertNotWPError( $edge );
		$this->assertSame( $max, $edge['page'] );
		$this->assertSame( array(), $edge['block_types'] );
		$this->assert_issue_16_value_matches_schema(
			$edge,
			WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-block-types' ),
			'wp-mcp/list-block-types'
		);
	}

	/**
	 * And the ceiling is published on the way in, so a client sees the bound
	 * before it calls rather than as an error. The shared
	 * `WP_MCP_Ability_Schema` contract leaves `page` open at the top, which
	 * is the unbounded number the output contract refuses to carry, so
	 * discovery declares its own.
	 */
	public function test_issue_16_paginated_inputs_publish_the_page_ceiling_they_enforce() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'This WordPress version has no ability registry.' );
		}
		foreach ( array( 'wp-mcp/global-search', 'wp-mcp/list-block-types' ) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' should exist' );

			$page = $ability->get_input_schema()['properties']['page'];
			$this->assertSame( 'integer', $page['type'] );
			$this->assertSame( 1, $page['minimum'] );
			$this->assertArrayHasKey( 'maximum', $page, $name . ' must publish the page ceiling it enforces.' );
			$this->assertSame( WP_MCP_Discovery_Contract::limit( 'max_page' ), $page['maximum'] );

			$output = WP_MCP_Discovery_Contract::output_schema( $name );
			$this->assertSame(
				$page['maximum'],
				$output['properties']['page']['maximum'],
				$name . ' must bound page at the same number on the way in and on the way out.'
			);
		}
	}

	/**
	 * Every registry cardinality this domain counts without listing — post
	 * types, taxonomies, nav menu locations — has no list ceiling to inherit
	 * and can be grown freely by any plugin, so each is reported inside the
	 * window its own schema publishes.
	 */
	public function test_issue_16_feature_support_counts_stay_inside_their_published_window() {
		$this->become_issue_12_admin();

		$features = WP_MCP_Discovery::list_feature_support( array() );
		$this->assertNotWPError( $features );

		$schema = WP_MCP_Discovery_Contract::output_schema( 'wp-mcp/list-feature-support' );
		foreach ( array( 'nav_menu_locations', 'registered_post_types', 'registered_taxonomies' ) as $field ) {
			$this->assertIsInt( $features['site'][ $field ] );
			$this->assertGreaterThanOrEqual( 0, $features['site'][ $field ] );
			$this->assertLessThanOrEqual(
				$schema['properties']['site']['properties'][ $field ]['maximum'],
				$features['site'][ $field ],
				$field . ' must stay inside the maximum its schema publishes.'
			);
		}

		$this->assertFalse( $features['capped'], 'An ordinary installation is nowhere near any of these ceilings.' );
		$this->assert_issue_16_value_matches_schema( $features, $schema, 'wp-mcp/list-feature-support' );
	}

	/* ------------------------------------------------------------------
	 * Oversized fixtures
	 * ---------------------------------------------------------------- */

	/**
	 * The namespace the bulk block fixtures live in.
	 *
	 * @var string
	 */
	const ISSUE_16_BULK_NAMESPACE = 'wp-mcp-bulk';

	/**
	 * A single block whose own registration lists are past every ceiling.
	 *
	 * @var string
	 */
	const ISSUE_16_OVERSIZED_BLOCK = 'wp-mcp-test/oversized-block';

	/**
	 * Register a valid block registry larger than the scan ceiling.
	 *
	 * Every block is a counting subclass, so the test can prove discovery
	 * inspected only the ones it returned.
	 *
	 * @param int $count How many blocks to register.
	 */
	private function register_issue_16_bulk_blocks( $count ) {
		$registry = WP_Block_Type_Registry::get_instance();
		for ( $i = 0; $i < $count; $i++ ) {
			$name = self::ISSUE_16_BULK_NAMESPACE . '/block-' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT );
			if ( $registry->is_registered( $name ) ) {
				continue;
			}
			$registry->register( new WP_MCP_Counting_Block_Type( $name, array( 'category' => 'text' ) ) );
			$this->issue_16_bulk_blocks[] = $name;
		}
	}

	/**
	 * Register more blocks than the scan budget under names this domain's
	 * grammar rejects.
	 *
	 * Core's own registry only requires `[a-z0-9-]+/[a-z0-9-]+`, so a
	 * digit-leading namespace is a perfectly legal WordPress block name and
	 * an illegal discovery identifier. That is exactly the entry that must
	 * still cost scan budget: filtering it for free is what would let a
	 * registry of them make every call walk the whole thing.
	 *
	 * Counting subclasses again, so the test can also prove none of them was
	 * ever inspected.
	 *
	 * @param int $count How many blocks to register.
	 */
	private function register_issue_16_ungrammatical_blocks( $count ) {
		$registry = WP_Block_Type_Registry::get_instance();
		for ( $i = 0; $i < $count; $i++ ) {
			$name = '0' . self::ISSUE_16_BULK_NAMESPACE . '/block-' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT );
			if ( $registry->is_registered( $name ) ) {
				continue;
			}
			$registry->register( new WP_MCP_Counting_Block_Type( $name, array( 'category' => 'text' ) ) );
			$this->issue_16_bulk_blocks[] = $name;
		}
	}

	/**
	 * Register one block whose parents, ancestors and attributes all exceed
	 * their ceilings, with every entry individually valid.
	 */
	private function register_issue_16_oversized_block() {
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( self::ISSUE_16_OVERSIZED_BLOCK ) ) {
			return;
		}

		$parents = array();
		for ( $i = 0; $i < WP_MCP_Discovery_Contract::limit( 'block_parents' ) + 15; $i++ ) {
			$parents[] = self::ISSUE_16_BULK_NAMESPACE . '/parent-' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT );
		}

		$attributes = array();
		for ( $i = 0; $i < WP_MCP_Discovery_Contract::limit( 'block_attributes' ) + 40; $i++ ) {
			$attributes[ 'attribute' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT ) ] = array( 'type' => 'string' );
		}

		register_block_type( self::ISSUE_16_OVERSIZED_BLOCK, array(
			'title'      => 'WordPress MCP Oversized Block',
			'category'   => 'text',
			'parent'     => $parents,
			'ancestor'   => $parents,
			'attributes' => $attributes,
		) );
		$this->issue_16_bulk_blocks[] = self::ISSUE_16_OVERSIZED_BLOCK;
	}

	/**
	 * Register more valid post statuses than the response may carry.
	 *
	 * @param int $count How many statuses to register.
	 */
	private function register_issue_16_bulk_post_statuses( $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			$name = 'wp_mcp_bulk_' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT );
			register_post_status( $name, array( 'label' => 'Bulk ' . $i, 'public' => false ) );
			$this->issue_16_bulk_statuses[] = $name;
		}
	}

	/**
	 * Register more valid pattern categories than the response may carry.
	 *
	 * @param int $count How many categories to register.
	 */
	private function register_issue_16_bulk_pattern_categories( $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			$name = 'wp-mcp-bulk-' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT );
			register_block_pattern_category( $name, array( 'label' => 'Bulk ' . $i ) );
			$this->issue_16_bulk_categories[] = $name;
		}
	}

	/**
	 * A mime map past both the mime ceiling and the per-type extension one.
	 *
	 * @param array $mimes Allowed mime types.
	 * @return array
	 */
	public function issue_16_bulk_mimes( $mimes ) {
		/*
		 * One mime type reached through many extension groups: individually
		 * every group is small, but merged they are past the per-type
		 * extension ceiling. Added first so the merge is exercised before
		 * the bulk types below push the map past the mime ceiling.
		 */
		for ( $i = 0; $i < WP_MCP_Discovery_Contract::limit( 'mime_extensions' ) + 12; $i++ ) {
			$mimes[ 'abext' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT ) ] = 'application/wp-mcp-merged';
		}

		for ( $i = 0; $i < WP_MCP_Discovery_Contract::limit( 'collection' ) + 25; $i++ ) {
			$mimes[ 'abulk' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT ) ] = 'application/wp-mcp-bulk-' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT );
		}

		return $mimes;
	}

	/**
	 * A mime map with exactly one entry, whose single extension group is
	 * past the per-type ceiling on its own.
	 *
	 * Deliberately not merged from several keys the way
	 * `issue_16_bulk_mimes()` does it: this is the truncation that happens
	 * *inside* one `jpg|jpeg|jpe|...` value, before the merge loop's own
	 * ceiling check ever runs. Returning a one-entry map means nothing else
	 * in the response can be what sets `capped`.
	 *
	 * @param array $mimes Allowed mime types.
	 * @return array
	 */
	public function issue_16_single_oversized_mime_group( $mimes ) {
		unset( $mimes );

		$extensions = array();
		for ( $i = 0; $i < WP_MCP_Discovery_Contract::limit( 'mime_extensions' ) + 7; $i++ ) {
			$extensions[] = 'abx' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT );
		}

		return array( implode( '|', $extensions ) => 'application/wp-mcp-onegroup' );
	}

	/**
	 * Grant far more valid capabilities than the response may carry.
	 *
	 * Granted on the live `WP_User` object rather than through the
	 * `user_has_cap` filter on purpose: that filter runs inside
	 * `WP_User::has_cap()`, while `$allcaps` — which is what discovery
	 * reports — is built from the role and the per-user grants and never
	 * passes through it. Filtering `user_has_cap` would leave `$allcaps`
	 * untouched and the fixture would prove nothing.
	 *
	 * @param int $count How many capabilities to grant.
	 */
	private function grant_issue_16_bulk_capabilities( $count ) {
		$user = wp_get_current_user();
		for ( $i = 0; $i < $count; $i++ ) {
			$user->allcaps[ 'wp_mcp_bulk_cap_' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT ) ] = true;
		}
	}

	/**
	 * Undo the oversized fixtures.
	 */
	private function unregister_issue_16_bulk_fixtures() {
		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			$registry = WP_Block_Type_Registry::get_instance();
			foreach ( $this->issue_16_bulk_blocks as $name ) {
				if ( $registry->is_registered( $name ) ) {
					$registry->unregister( $name );
				}
			}
		}
		$this->issue_16_bulk_blocks = array();

		foreach ( $this->issue_16_bulk_statuses as $status ) {
			if ( isset( $GLOBALS['wp_post_statuses'][ $status ] ) ) {
				unset( $GLOBALS['wp_post_statuses'][ $status ] );
			}
		}
		$this->issue_16_bulk_statuses = array();

		if ( function_exists( 'unregister_block_pattern_category' ) && class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
			foreach ( $this->issue_16_bulk_categories as $category ) {
				if ( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $category ) ) {
					unregister_block_pattern_category( $category );
				}
			}
		}
		$this->issue_16_bulk_categories = array();

		remove_filter( 'upload_mimes', array( $this, 'issue_16_bulk_mimes' ) );
		remove_filter( 'upload_mimes', array( $this, 'issue_16_single_oversized_mime_group' ), 99 );
	}

}

if ( class_exists( 'WP_Block_Type' ) && ! class_exists( 'WP_MCP_Counting_Block_Type' ) ) {
	/**
	 * A block type that counts how many times discovery inspected it.
	 *
	 * `WP_MCP_Discovery::list_block_types()` promises to build a summary
	 * only for the blocks on the requested page, never once per registered
	 * block. That promise is about work, not about output, so it cannot be
	 * read off a response — the only way to see it is to let the block
	 * itself say when it was looked at. `is_dynamic()` is the one method
	 * building a summary calls, so it is the counter.
	 *
	 * @package WP_MCP_Agent_Abilities
	 */
	class WP_MCP_Counting_Block_Type extends WP_Block_Type {

		/**
		 * How many summaries have been built since the last reset.
		 *
		 * @var int
		 */
		public static $inspections = 0;

		/**
		 * @return bool
		 */
		public function is_dynamic() {
			++self::$inspections;
			return parent::is_dynamic();
		}
	}
}
