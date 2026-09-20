<?php
/**
 * WordPress MCP Abilities — cross-cutting security suite (issue #15).
 *
 * tests/test-abilities.php covers each domain's own behaviour. This file
 * covers the invariants that must hold across *every* ability at once, plus
 * the specific bypasses issue #15's threat model names: privilege escalation
 * through roles, object confusion, indirect arbitrary execution, SSRF, the
 * option and metadata allowlists, and the audit contract.
 *
 * The registry-wide sweeps are deliberately written as "collect every
 * offender, then assert the list is empty": a failure names the abilities
 * that broke the invariant instead of stopping at the first one.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.15.0
 */

class WP_MCP_Test_Security extends WP_UnitTestCase {

	/**
	 * Input property names an MCP client may never supply, because the server
	 * decides them (epic #1) or because accepting them would be one of the
	 * five forbidden primitives.
	 *
	 * `post_type` is not here: it is a *selector* in the custom-post-type
	 * domain, and test_post_type_input_is_only_the_custom_post_type_selector
	 * checks that separately.
	 *
	 * @var string[]
	 */
	const FORBIDDEN_INPUT_PROPERTIES = array(
		// Set server-side, always (epic #1).
		'post_author',
		'post_status',
		'post_name',
		// Generic option read/write.
		'option',
		'option_name',
		'option_value',
		// Arbitrary callbacks, code and queries.
		'callback',
		'function',
		'function_name',
		'class_name',
		'php',
		'code',
		'eval',
		'sql',
		'shell',
		'command',
		// Generic filesystem access.
		'file_path',
		'absolute_path',
		'directory',
	);

	/**
	 * Captured `wp_mcp_audit_log` events for the current test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $captured_audit_events = array();

	public function tear_down() {
		remove_all_filters( 'wp_mcp_cron_schedulable_hooks' );
		remove_all_actions( 'wp_mcp_audit_log' );
		$this->captured_audit_events = array();
		parent::tear_down();
	}

	/* ==================================================================
	 * Helpers
	 * ================================================================ */

	/**
	 * Every ability this plugin registers, keyed by name.
	 *
	 * @return array<string,WP_Ability>
	 */
	private function wp_mcp_abilities() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'The WordPress Abilities API is not available on this WordPress version.' );
		}

		$abilities = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( 0 === strpos( $name, 'wp-mcp/' ) ) {
				$abilities[ $name ] = $ability;
			}
		}

		$this->assertNotEmpty( $abilities, 'No wp-mcp/* abilities are registered — the sweep would pass vacuously.' );
		return $abilities;
	}

	/**
	 * Create a user holding exactly $capabilities and make them current.
	 *
	 * A bespoke role rather than a core one: the point of most of these tests
	 * is a caller holding one capability and deliberately lacking a
	 * neighbouring one, which no core role expresses.
	 *
	 * @param string[] $capabilities Capability names.
	 * @param string   $role_slug    Unique role slug for this fixture.
	 * @return int User ID.
	 */
	private function become_user_with_capabilities( $capabilities, $role_slug ) {
		if ( get_role( $role_slug ) ) {
			remove_role( $role_slug );
		}
		$caps = array();
		foreach ( $capabilities as $capability ) {
			$caps[ $capability ] = true;
		}
		add_role( $role_slug, $role_slug, $caps );

		$user_id = self::factory()->user->create( array( 'role' => $role_slug ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Start recording every audit event fired from now on.
	 */
	private function capture_audit_events() {
		$this->captured_audit_events = array();
		add_action( 'wp_mcp_audit_log', function ( $data ) {
			$this->captured_audit_events[] = $data;
		} );
	}

	/**
	 * The recorded audit events for one ability.
	 *
	 * @param string $ability Ability name.
	 * @return array<int,array<string,mixed>>
	 */
	private function audit_events_for( $ability ) {
		$matching = array();
		foreach ( $this->captured_audit_events as $event ) {
			if ( isset( $event['ability'] ) && $ability === $event['ability'] ) {
				$matching[] = $event;
			}
		}
		return $matching;
	}

	/* ==================================================================
	 * 1. Registry-wide invariants
	 * ================================================================ */

	/**
	 * Every ability is exposed to MCP only: never through the generic
	 * `meta.public` flag, and never through the REST API.
	 */
	public function test_every_ability_is_mcp_only_and_never_rest_exposed() {
		$offenders = array();

		foreach ( $this->wp_mcp_abilities() as $name => $ability ) {
			$meta = $ability->get_meta();

			if ( ! isset( $meta['mcp']['public'] ) || true !== $meta['mcp']['public'] ) {
				$offenders[] = $name . ' (meta.mcp.public is not true)';
			}
			if ( ! isset( $meta['show_in_rest'] ) || false !== $meta['show_in_rest'] ) {
				$offenders[] = $name . ' (show_in_rest is not false)';
			}
			if ( isset( $meta['public'] ) && true === $meta['public'] ) {
				$offenders[] = $name . ' (uses the generic meta.public flag)';
			}
			if ( ! isset( $meta['annotations']['readonly'], $meta['annotations']['destructive'], $meta['annotations']['idempotent'] ) ) {
				$offenders[] = $name . ' (incomplete MCP annotations)';
			}
		}

		$this->assertSame( array(), $offenders, 'Abilities breaking the MCP-only exposure contract: ' . implode( '; ', $offenders ) );
	}

	/**
	 * Every input schema is closed. An open input schema lets an MCP client
	 * smuggle a property the callback never validated straight into it.
	 */
	public function test_every_input_schema_is_closed() {
		$offenders = array();

		foreach ( $this->wp_mcp_abilities() as $name => $ability ) {
			$schema = $ability->get_input_schema();
			if ( empty( $schema ) ) {
				continue;
			}
			if ( ! isset( $schema['type'] ) || 'object' !== $schema['type'] ) {
				$offenders[] = $name . ' (input schema is not an object)';
				continue;
			}
			if ( ! array_key_exists( 'additionalProperties', $schema ) || false !== $schema['additionalProperties'] ) {
				$offenders[] = $name . ' (additionalProperties is not false)';
			}
		}

		$this->assertSame( array(), $offenders, 'Abilities with an open input schema: ' . implode( '; ', $offenders ) );
	}

	/**
	 * No ability accepts a field the server must decide for itself, or one
	 * that would turn it into an arbitrary option/code/path primitive.
	 */
	public function test_no_input_schema_accepts_a_server_controlled_field() {
		$offenders = array();

		foreach ( $this->wp_mcp_abilities() as $name => $ability ) {
			$schema     = $ability->get_input_schema();
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

			foreach ( array_keys( $properties ) as $property ) {
				if ( in_array( $property, self::FORBIDDEN_INPUT_PROPERTIES, true ) ) {
					$offenders[] = $name . ' accepts ' . $property;
				}
			}
		}

		$this->assertSame( array(), $offenders, 'Abilities accepting a server-controlled or forbidden input field: ' . implode( '; ', $offenders ) );
	}

	/**
	 * `post_type` is only ever the custom-post-type domain's validated
	 * selector — never a value another domain writes to a post.
	 */
	public function test_post_type_input_is_only_the_custom_post_type_selector() {
		$offenders = array();

		foreach ( $this->wp_mcp_abilities() as $name => $ability ) {
			$schema     = $ability->get_input_schema();
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

			if ( ! array_key_exists( 'post_type', $properties ) ) {
				continue;
			}
			if ( false === strpos( $name, 'custom-post' ) && false === strpos( $name, 'post-type' ) ) {
				$offenders[] = $name;
			}
		}

		$this->assertSame( array(), $offenders, 'Abilities outside the custom-post-type domain accepting post_type: ' . implode( ', ', $offenders ) );
	}

	/**
	 * Pagination is always bounded, and always complete: an ability offering
	 * one half of the contract must offer the other.
	 */
	public function test_paginated_abilities_declare_a_bounded_page_size() {
		$offenders = array();

		foreach ( $this->wp_mcp_abilities() as $name => $ability ) {
			$schema     = $ability->get_input_schema();
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

			$has_page     = array_key_exists( 'page', $properties );
			$has_per_page = array_key_exists( 'per_page', $properties );

			if ( $has_page !== $has_per_page ) {
				$offenders[] = $name . ' (declares only half of the pagination contract)';
				continue;
			}
			if ( ! $has_per_page ) {
				continue;
			}

			$per_page = $properties['per_page'];
			if ( ! isset( $per_page['maximum'] ) || $per_page['maximum'] > 50 ) {
				$offenders[] = $name . ' (per_page has no maximum, or one above 50)';
			}
			if ( ! isset( $per_page['minimum'] ) || $per_page['minimum'] < 1 ) {
				$offenders[] = $name . ' (per_page has no minimum of at least 1)';
			}
		}

		$this->assertSame( array(), $offenders, 'Abilities with an unbounded or incomplete pagination contract: ' . implode( '; ', $offenders ) );
	}

	/**
	 * The whole surface is closed to an unauthenticated caller. This is the
	 * one sweep that has to hold for every ability without exception: MCP
	 * transport authentication is the adapter's job, but an ability must
	 * never be the thing that grants access.
	 */
	public function test_every_ability_denies_an_anonymous_caller() {
		$abilities = $this->wp_mcp_abilities();
		wp_set_current_user( 0 );

		$offenders = array();
		foreach ( $abilities as $name => $ability ) {
			$allowed = $ability->check_permissions( array() );
			if ( true === $allowed ) {
				$offenders[] = $name;
			}
		}

		$this->assertSame( array(), $offenders, 'Abilities that allow a logged-out caller: ' . implode( ', ', $offenders ) );
	}

	/* ==================================================================
	 * 2. Audit contract
	 * ================================================================ */

	/**
	 * Every ability resolves to an object type from the closed vocabulary, so
	 * a new domain cannot introduce an unaudited object kind by accident.
	 */
	public function test_every_ability_resolves_to_a_known_audit_object_type() {
		$offenders = array();

		foreach ( array_keys( $this->wp_mcp_abilities() ) as $name ) {
			$type = WP_MCP_Audit::object_type_for( $name );
			if ( ! in_array( $type, WP_MCP_Audit::OBJECT_TYPES, true ) ) {
				$offenders[] = $name . ' => ' . $type;
			}
		}

		$this->assertSame( array(), $offenders, 'Abilities resolving to an object type outside the closed vocabulary: ' . implode( '; ', $offenders ) );
	}

	/**
	 * Every write ability names the kind of object it acts on. A mutation
	 * audited as "something, somewhere" is not an audit trail.
	 */
	public function test_every_write_ability_resolves_to_a_non_empty_object_type() {
		$offenders = array();

		foreach ( $this->wp_mcp_abilities() as $name => $ability ) {
			$meta = $ability->get_meta();
			if ( ! isset( $meta['annotations']['readonly'] ) || true === $meta['annotations']['readonly'] ) {
				continue;
			}
			if ( '' === WP_MCP_Audit::object_type_for( $name ) ) {
				$offenders[] = $name;
			}
		}

		$this->assertSame( array(), $offenders, 'Write abilities with no audit object type: ' . implode( ', ', $offenders ) );
	}

	/**
	 * A real mutation emits the complete issue #15 event shape.
	 */
	public function test_audit_event_carries_the_full_field_set() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->capture_audit_events();

		$created = WP_MCP_Posts::create_post( array(
			'title'   => 'Audited post',
			'content' => 'Audited content',
		) );
		$this->assertNotWPError( $created );

		$events = $this->audit_events_for( 'wp-mcp/create-post' );
		$this->assertNotEmpty( $events, 'create-post must emit an audit event' );

		$event = $events[0];
		foreach ( array( 'timestamp', 'user_id', 'ability', 'object_type', 'object_id', 'result', 'error_code' ) as $field ) {
			$this->assertArrayHasKey( $field, $event, 'The audit event must carry ' . $field );
		}
		$this->assertSame( $admin, $event['user_id'] );
		$this->assertSame( 'post', $event['object_type'] );
		$this->assertSame( (int) $created['id'], $event['object_id'] );
		$this->assertSame( 'success', $event['result'] );
		$this->assertSame( '', $event['error_code'] );
	}

	/**
	 * A failed mutation is audited too, with the stable error code.
	 */
	public function test_a_failed_mutation_is_audited_with_its_error_code() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->capture_audit_events();

		$result = WP_MCP_Pages::publish_page( array( 'page_id' => 99999999 ) );
		$this->assertWPError( $result );

		$events = $this->audit_events_for( 'wp-mcp/publish-page' );
		$this->assertNotEmpty( $events, 'A refused publish must still be audited' );
		$this->assertSame( 'error', $events[0]['result'] );
		$this->assertNotSame( '', $events[0]['error_code'] );
	}

	/**
	 * Credential-shaped context keys never reach the audit event, whatever a
	 * call site passes.
	 */
	public function test_audit_context_drops_credential_shaped_keys() {
		$scrubbed = WP_MCP_Audit::scrub_context( array(
			'slug'             => 'hello-dolly',
			'from_version'     => '1.7.2',
			'password'         => 'hunter2',
			'app_password'     => 'abcd efgh ijkl',
			'api_key'          => 'sk-live-123',
			'auth_token'       => 'tok_123',
			'session_cookie'   => 'wordpress_logged_in=...',
			'nonce'            => 'abc123',
			'auth_salt'        => 'salty',
			'post_content'     => 'the whole article body',
			'request_body'     => '{"secret":true}',
		) );

		$this->assertSame( array( 'slug' => 'hello-dolly', 'from_version' => '1.7.2' ), $scrubbed );
	}

	/**
	 * Long values are truncated, so no call site can leak a document through
	 * the audit trail one context field at a time.
	 */
	public function test_audit_context_truncates_long_values() {
		$scrubbed = WP_MCP_Audit::scrub_context( array( 'note' => str_repeat( 'a', 500 ) ) );

		$this->assertArrayHasKey( 'note', $scrubbed );
		$this->assertLessThanOrEqual(
			WP_MCP_Audit::MAX_CONTEXT_VALUE_LENGTH + 3,
			strlen( $scrubbed['note'] ),
			'A context value must be truncated to the documented maximum'
		);
	}

	/**
	 * Only scalars survive, and a context key can never overwrite a base
	 * field — an event whose user_id or result came from caller-influenced
	 * context would be worthless.
	 */
	public function test_audit_context_keeps_only_scalars_and_never_overwrites_a_base_field() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->capture_audit_events();

		WP_MCP_Audit::log( 'wp-mcp/update-post', 7, true, '', array(
			'user_id' => 999999,
			'result'  => 'forged',
			'nested'  => array( 'not' => 'scalar' ),
			'kept'    => 'yes',
		) );

		$events = $this->audit_events_for( 'wp-mcp/update-post' );
		$this->assertCount( 1, $events );
		$this->assertSame( $admin, $events[0]['user_id'] );
		$this->assertSame( 'success', $events[0]['result'] );
		$this->assertArrayNotHasKey( 'nested', $events[0] );
		$this->assertSame( 'yes', $events[0]['kept'] );
	}

	/**
	 * A call site that knows the real object type overrides the one resolved
	 * from the ability name — how the custom-post-type domain records the
	 * actual post type it operated on.
	 */
	public function test_an_explicit_object_type_overrides_the_resolved_one() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->capture_audit_events();

		WP_MCP_Audit::log( 'wp-mcp/update-custom-post', 12, true, '', array( 'object_type' => 'wp_mcp_book' ) );

		$events = $this->audit_events_for( 'wp-mcp/update-custom-post' );
		$this->assertCount( 1, $events );
		$this->assertSame( 'wp_mcp_book', $events[0]['object_type'] );
	}

	/* ==================================================================
	 * 3. Privilege escalation — users and roles
	 * ================================================================ */

	/**
	 * `promote_users` is not a licence to hand out capabilities you do not
	 * hold. The contrast in this test is the whole point: the same caller may
	 * still assign a role whose capabilities it does hold.
	 */
	public function test_promote_users_cannot_grant_a_role_carrying_capabilities_the_caller_lacks() {
		$target = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->become_user_with_capabilities(
			array( 'read', 'list_users', 'edit_users', 'promote_users' ),
			'wp_mcp_test_promoter'
		);

		$escalate = WP_MCP_Users::change_user_role( array( 'user_id' => $target, 'role' => 'administrator' ) );
		$this->assertWPError( $escalate, 'A promote_users holder must not be able to create an administrator' );
		$this->assertEquals( 'wp_mcp_user_permission_denied', $escalate->get_error_code() );
		$this->assertNotContains( 'administrator', get_userdata( $target )->roles );

		$allowed = WP_MCP_Users::change_user_role( array( 'user_id' => $target, 'role' => 'subscriber' ) );
		$this->assertNotWPError( $allowed, 'A role whose capabilities the caller already holds stays assignable' );
	}

	/**
	 * The subset rule must not break the ordinary case: an administrator
	 * holds every core role's capabilities and can still assign them.
	 */
	public function test_an_administrator_can_still_assign_core_roles() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$target = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $admin );

		foreach ( array( 'contributor', 'author', 'editor', 'administrator' ) as $role ) {
			$result = WP_MCP_Users::change_user_role( array( 'user_id' => $target, 'role' => $role ) );
			$this->assertNotWPError( $result, 'An administrator must still be able to assign the ' . $role . ' role' );
			$this->assertContains( $role, get_userdata( $target )->roles );
		}
	}

	/**
	 * Adding a role, not just replacing one, goes through the same gate.
	 */
	public function test_add_user_role_is_gated_by_the_same_escalation_rule() {
		$target = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->become_user_with_capabilities(
			array( 'read', 'list_users', 'edit_users', 'promote_users' ),
			'wp_mcp_test_adder'
		);

		$result = WP_MCP_Users::add_user_role( array( 'user_id' => $target, 'role' => 'administrator' ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_user_permission_denied', $result->get_error_code() );
		$this->assertNotContains( 'administrator', get_userdata( $target )->roles );
	}

	/**
	 * Creating a user is the same escalation path with a different door.
	 */
	public function test_create_user_cannot_mint_an_administrator_from_a_narrow_role() {
		$this->become_user_with_capabilities(
			array( 'read', 'list_users', 'create_users', 'edit_users', 'promote_users' ),
			'wp_mcp_test_creator'
		);

		$result = WP_MCP_Users::create_user( array(
			'username' => 'escalated-admin',
			'email'    => 'escalated-admin@example.com',
			'password' => 'a-secure-test-password',
			'role'     => 'administrator',
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_user_permission_denied', $result->get_error_code() );
		$this->assertFalse( get_user_by( 'login', 'escalated-admin' ) );
	}

	/**
	 * An application password is a credential: the listing describes them,
	 * it never returns one.
	 */
	public function test_application_password_listings_never_return_a_credential() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$created = WP_MCP_Users::create_application_password( array( 'user_id' => $admin, 'name' => 'agent' ) );
		if ( is_wp_error( $created ) ) {
			$this->markTestSkipped( 'Application passwords are unavailable in this environment: ' . $created->get_error_code() );
		}

		$listed = WP_MCP_Users::list_application_passwords( array( 'user_id' => $admin ) );
		$this->assertNotWPError( $listed );

		$serialised = (string) wp_json_encode( $listed );
		$this->assertStringNotContainsString( (string) $created['password'], $serialised, 'The generated credential must never be readable again' );

		foreach ( $listed['passwords'] as $entry ) {
			$this->assertArrayNotHasKey( 'password', $entry, 'A listing entry must describe a credential, never carry one' );
			$this->assertArrayNotHasKey( 'hashed_password', $entry['metadata'], 'The stored hash must never be exposed either' );
		}
	}

	/* ==================================================================
	 * 4. Post vs page capability separation
	 * ================================================================ */

	/**
	 * A role that may edit pages but was deliberately denied `publish_pages`
	 * cannot publish one, even while holding `publish_posts`.
	 */
	public function test_publishing_a_page_requires_publish_pages_not_publish_posts() {
		$user_id = $this->become_user_with_capabilities(
			array( 'read', 'edit_posts', 'publish_posts', 'edit_pages', 'edit_published_pages' ),
			'wp_mcp_test_page_editor'
		);

		$page_id = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_author' => $user_id,
			'post_status' => 'draft',
		) );

		$result = WP_MCP_Pages::publish_page( array( 'page_id' => $page_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $page_id ) );
	}

	/**
	 * The same separation for scheduling, which is publishing with a date.
	 */
	public function test_scheduling_a_page_requires_publish_pages() {
		$user_id = $this->become_user_with_capabilities(
			array( 'read', 'edit_posts', 'publish_posts', 'edit_pages', 'edit_published_pages' ),
			'wp_mcp_test_page_scheduler'
		);

		$page_id = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_author' => $user_id,
			'post_status' => 'draft',
		) );

		$result = WP_MCP_Pages::schedule_page( array(
			'page_id' => $page_id,
			'date'    => gmdate( 'c', time() + DAY_IN_SECONDS ),
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * Reassigning a page's author needs `edit_others_pages`; holding
	 * `edit_others_posts` is not the same permission.
	 */
	public function test_changing_a_page_author_requires_edit_others_pages() {
		$user_id = $this->become_user_with_capabilities(
			array( 'read', 'edit_posts', 'edit_others_posts', 'edit_pages', 'edit_published_pages' ),
			'wp_mcp_test_page_author_changer'
		);
		$new_author = self::factory()->user->create( array( 'role' => 'editor' ) );

		$page_id = self::factory()->post->create( array(
			'post_type'   => 'page',
			'post_author' => $user_id,
			'post_status' => 'draft',
		) );

		$result = WP_MCP_Pages::change_page_author( array( 'page_id' => $page_id, 'new_author_id' => $new_author ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
		$this->assertSame( $user_id, (int) get_post( $page_id )->post_author );
	}

	/**
	 * The page abilities' own permission gates are page gates, so a
	 * post-only role is refused before any object is even looked up.
	 */
	public function test_page_abilities_are_closed_to_a_post_only_role() {
		$abilities = $this->wp_mcp_abilities();
		$this->become_user_with_capabilities(
			array( 'read', 'edit_posts', 'publish_posts', 'delete_posts', 'edit_published_posts' ),
			'wp_mcp_test_post_only'
		);

		$offenders = array();
		foreach ( array( 'wp-mcp/update-page', 'wp-mcp/publish-page', 'wp-mcp/trash-page', 'wp-mcp/delete-page-permanently', 'wp-mcp/update-page-slug', 'wp-mcp/update-page-attributes' ) as $name ) {
			if ( ! isset( $abilities[ $name ] ) ) {
				continue;
			}
			if ( true === $abilities[ $name ]->check_permissions( array() ) ) {
				$offenders[] = $name;
			}
		}

		$this->assertSame( array(), $offenders, 'Page abilities reachable by a role with only post capabilities: ' . implode( ', ', $offenders ) );
	}

	/* ==================================================================
	 * 5. Object confusion and ownership
	 * ================================================================ */

	/**
	 * An ID of the wrong kind is refused, never operated on.
	 */
	public function test_object_type_confusion_is_refused_across_domains() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$post_id = self::factory()->post->create( array( 'post_author' => $admin, 'post_status' => 'publish' ) );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $admin, 'post_status' => 'publish' ) );

		$this->assertWPError( WP_MCP_Posts::get_post( array( 'post_id' => $page_id ) ), 'A page ID is not a post' );
		$this->assertWPError( WP_MCP_Pages::get_page( array( 'page_id' => $post_id ) ), 'A post ID is not a page' );
		$this->assertWPError( WP_MCP_Media::get_media( array( 'media_id' => $post_id ) ), 'A post ID is not an attachment' );
		$this->assertWPError( WP_MCP_Comments::get_comment( array( 'comment_id' => $post_id ) ), 'A post ID is not a comment' );
		$this->assertWPError( WP_MCP_Post_Types::get_custom_post( array( 'post_type' => 'post', 'post_id' => $post_id ) ), 'A core built-in type is not operable by the custom post type domain' );
	}

	/**
	 * A term of the wrong taxonomy is refused rather than silently resolved.
	 */
	public function test_a_term_of_the_wrong_taxonomy_is_refused() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tag = self::factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'security-fixture' ) );

		$result = WP_MCP_Taxonomies::get_term( array( 'taxonomy' => 'category', 'term_id' => $tag ) );

		$this->assertWPError( $result );
	}

	/**
	 * The autosave endpoint is scoped to its caller. WordPress keeps one
	 * autosave per user per post, and reading somebody else's would expose
	 * unsaved editorial drafts to any co-editor.
	 */
	public function test_get_post_autosave_never_returns_another_users_autosave() {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $owner, 'post_status' => 'draft' ) );

		$autosave_id = wp_insert_post( array(
			'post_type'    => 'revision',
			'post_status'  => 'inherit',
			'post_parent'  => $post_id,
			'post_name'    => $post_id . '-autosave-v1',
			'post_author'  => $other,
			'post_title'   => 'Another editor draft',
			'post_content' => 'Unsaved words that belong to somebody else',
		) );
		$this->assertNotWPError( $autosave_id );

		// Only assert once the fixture really is the post's autosave: the
		// naming convention is core's, and a future change to it must skip
		// this test rather than turn it into a silent pass.
		$unscoped = wp_get_post_autosave( $post_id );
		if ( ! $unscoped || (int) $unscoped->ID !== (int) $autosave_id ) {
			$this->markTestSkipped( 'This WordPress version does not resolve the fixture as an autosave.' );
		}

		wp_set_current_user( $owner );
		$result = WP_MCP_Posts::get_post_autosave( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['exists'], 'Another user\'s autosave must never be returned' );
	}

	/**
	 * Detaching mutates the parent's attachment relationship, so it needs
	 * edit rights on the parent — not only on the attachment.
	 */
	public function test_detaching_media_requires_edit_rights_on_the_parent() {
		$owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$post_id = self::factory()->post->create( array( 'post_author' => $owner, 'post_status' => 'publish' ) );
		$attachment_id = self::factory()->attachment->create_object( 'detach-me.jpg', $post_id, array(
			'post_mime_type' => 'image/jpeg',
			'post_type'      => 'attachment',
			'post_author'    => $author,
		) );

		wp_set_current_user( $author );
		$result = WP_MCP_Media::detach_media( array( 'media_id' => $attachment_id ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_permission_denied', $result->get_error_code() );
		$this->assertSame( $post_id, (int) get_post( $attachment_id )->post_parent );
	}

	/**
	 * Replacing an attachment file is an upload, and needs `upload_files`.
	 */
	public function test_replacing_media_requires_upload_files() {
		$abilities = $this->wp_mcp_abilities();
		$this->become_user_with_capabilities(
			array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts' ),
			'wp_mcp_test_no_uploads'
		);

		$this->assertArrayHasKey( 'wp-mcp/replace-media', $abilities );
		$this->assertNotTrue(
			$abilities['wp-mcp/replace-media']->check_permissions( array() ),
			'replace-media must be closed to a caller without upload_files'
		);
	}

	/* ==================================================================
	 * 6. SSRF
	 * ================================================================ */

	/**
	 * The remote-URL policy refuses every shape an SSRF payload takes. Only
	 * IP literals and obviously-unsafe URLs are used, so the table is
	 * decided without a single DNS lookup.
	 */
	public function test_validate_remote_url_refuses_the_ssrf_payload_table() {
		$payloads = array(
			'loopback IPv4'          => 'http://127.0.0.1/payload.zip',
			'loopback name'          => 'https://localhost/payload.zip',
			'loopback subdomain'     => 'https://evil.localhost/payload.zip',
			'link-local metadata'    => 'http://169.254.169.254/latest/meta-data/',
			'private 10/8'           => 'https://10.0.0.5/payload.zip',
			'private 172.16/12'      => 'https://172.16.0.9/payload.zip',
			'private 192.168/16'     => 'https://192.168.1.10/payload.zip',
			'unspecified address'    => 'http://0.0.0.0/payload.zip',
			'IPv6 loopback'          => 'http://[::1]/payload.zip',
			'credentials in URL'     => 'https://user:pass@127.0.0.1/payload.zip',
			'non-http scheme'        => 'file:///etc/passwd',
			'gopher scheme'          => 'gopher://127.0.0.1:70/',
			'non-standard port'      => 'https://93.184.216.34:8080/payload.zip',
			'fragment smuggling'     => 'https://93.184.216.34/payload.zip#@127.0.0.1',
			'empty string'           => '',
			'not a string'           => 12345,
		);

		foreach ( $payloads as $label => $url ) {
			$result = WP_MCP_Permissions::validate_remote_url( $url );
			$this->assertWPError( $result, 'SSRF payload must be refused: ' . $label );
			$this->assertEquals( 'wp_mcp_remote_url_denied', $result->get_error_code(), 'Wrong error code for: ' . $label );
		}
	}

	/**
	 * The host policy is a real allow/deny list, not decoration.
	 */
	public function test_remote_url_host_policy_allows_and_denies_by_host() {
		$public_url = 'https://93.184.216.34/package.zip';

		$this->assertTrue(
			WP_MCP_Permissions::validate_remote_url( $public_url ),
			'A public IP literal on a default port is accepted when no policy is supplied'
		);

		$denied = WP_MCP_Permissions::validate_remote_url( $public_url, array( 'deny_hosts' => array( '93.184.216.34' ) ) );
		$this->assertWPError( $denied, 'deny_hosts must refuse the host' );

		$not_allowed = WP_MCP_Permissions::validate_remote_url( $public_url, array( 'allowed_hosts' => array( 'downloads.example.com' ) ) );
		$this->assertWPError( $not_allowed, 'A host outside allowed_hosts must be refused' );
	}

	/* ==================================================================
	 * 7. Settings allowlist
	 * ================================================================ */

	/**
	 * An option name is not a field name. The vocabulary is closed, and an
	 * unlisted field is refused before anything is read or written.
	 */
	public function test_settings_refuse_a_raw_option_name() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( 'mailserver_pass', 'siteurl', 'upload_path', 'active_plugins', 'auth_salt' ) as $option ) {
			$result = WP_MCP_Settings::update_general_settings( array( $option => 'anything' ) );
			$this->assertWPError( $result, 'A raw option name must never be accepted: ' . $option );
			$this->assertEquals( 'wp_mcp_settings_unknown_field', $result->get_error_code(), 'Wrong refusal for: ' . $option );
		}
	}

	/**
	 * The declarative allowlist and the write-time deny list agree: no
	 * writable field maps to an option the plugin swore never to write.
	 */
	public function test_no_writable_settings_field_maps_to_a_never_writable_option() {
		$never_writable = WP_MCP_Settings::never_writable_options();
		$offenders      = array();

		foreach ( WP_MCP_Settings::groups() as $group => $definition ) {
			foreach ( $definition['fields'] as $field => $spec ) {
				if ( ! empty( $spec['readonly'] ) ) {
					continue;
				}
				if ( isset( $spec['option'] ) && in_array( $spec['option'], $never_writable, true ) ) {
					$offenders[] = $group . '.' . $field . ' => ' . $spec['option'];
				}
			}
		}

		$this->assertSame( array(), $offenders, 'Writable settings fields pointing at a never-writable option: ' . implode( '; ', $offenders ) );
	}

	/**
	 * A read-only field stays read-only through the write path.
	 */
	public function test_readonly_settings_fields_cannot_be_written() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$before = get_option( 'admin_email' );

		$result = WP_MCP_Settings::update_general_settings( array( 'admin_email' => 'attacker@example.com' ) );

		$this->assertWPError( $result );
		$this->assertSame( $before, get_option( 'admin_email' ) );
	}

	/* ==================================================================
	 * 8. Indirect execution primitives
	 * ================================================================ */

	/**
	 * `schedule-cron-event` plus `run-cron-event` must not add up to
	 * `execute(action, args)`. A hook with a listener is not, by itself,
	 * something an agent may queue.
	 */
	public function test_scheduling_an_arbitrary_hook_with_a_listener_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		_set_cron_array( array() );
		add_action( 'wp_mcp_arbitrary_dispatch_target', '__return_true' );

		$result = WP_MCP_Cron::schedule_cron_event( array(
			'hook' => 'wp_mcp_arbitrary_dispatch_target',
			'args' => array( 'attacker-chosen' ),
		) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_cron_hook_denied', $result->get_error_code() );
		$this->assertFalse( wp_get_scheduled_event( 'wp_mcp_arbitrary_dispatch_target', array( 'attacker-chosen' ) ) );
	}

	/**
	 * WordPress's own maintenance hooks stay schedulable — the point is to
	 * close the dispatcher, not cron management.
	 */
	public function test_a_core_maintenance_hook_is_still_schedulable() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		_set_cron_array( array() );

		$this->assertContains( 'wp_version_check', WP_MCP_Cron::schedulable_core_hooks() );

		$result = WP_MCP_Cron::schedule_cron_event( array( 'hook' => 'wp_version_check', 'recurrence' => 'twicedaily' ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['scheduled'] );
	}

	/**
	 * The core allowlist is a list of *hooks*, never a channel for arguments.
	 * Core's own `wp_version_check` callback takes none, so any argument the
	 * caller supplies could only ever be delivered to a third-party plugin
	 * that hooked the same name — the `execute(action, args)` dispatcher this
	 * policy exists to close, wearing a core hook's name.
	 */
	public function test_a_core_maintenance_hook_is_refused_with_caller_supplied_arguments() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		_set_cron_array( array() );

		// Exactly the third-party listener the attack relies on.
		$received = array();
		add_action( 'wp_version_check', function ( $payload = null ) use ( &$received ) {
			$received[] = $payload;
		} );

		$scheduled = WP_MCP_Cron::schedule_cron_event( array(
			'hook' => 'wp_version_check',
			'args' => array( 'attacker-chosen' ),
		) );

		$this->assertWPError( $scheduled, 'A core maintenance hook must not be schedulable with caller-supplied arguments' );
		$this->assertEquals( 'wp_mcp_cron_hook_denied', $scheduled->get_error_code() );
		$this->assertFalse( wp_get_scheduled_event( 'wp_version_check', array( 'attacker-chosen' ) ), 'The refused pair must never reach the cron array' );

		/*
		 * run-cron-event only ever fires what is already queued, so refusing
		 * the schedule is what closes the run path too: there is nothing to
		 * locate, and the listener is never reached.
		 */
		$ran = WP_MCP_Cron::run_cron_event( array( 'hook' => 'wp_version_check' ) );
		$this->assertWPError( $ran );
		$this->assertEquals( 'wp_mcp_cron_event_not_found', $ran->get_error_code() );
		$this->assertSame( array(), $received, 'The third-party listener must never have been called' );

		// The same hook with no arguments at all is still ordinary housekeeping.
		$clean = WP_MCP_Cron::schedule_cron_event( array( 'hook' => 'wp_version_check' ) );
		$this->assertNotWPError( $clean, 'A core maintenance hook with no arguments stays schedulable' );
		$this->assertTrue( $clean['scheduled'] );
	}

	/**
	 * Retiming a job the site already has is not dispatch, and stays allowed.
	 */
	public function test_an_already_queued_hook_can_be_rescheduled() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		_set_cron_array( array() );
		add_action( 'wp_mcp_existing_site_job', '__return_true' );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'wp_mcp_existing_site_job', array( 'alpha' ) );

		$result = WP_MCP_Cron::schedule_cron_event( array(
			'hook'      => 'wp_mcp_existing_site_job',
			'args'      => array( 'alpha' ),
			'timestamp' => time() + ( 2 * HOUR_IN_SECONDS ),
		) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['replaced'], 'Rescheduling replaces the existing event rather than duplicating it' );

		$other_args = WP_MCP_Cron::schedule_cron_event( array(
			'hook' => 'wp_mcp_existing_site_job',
			'args' => array( 'beta' ),
		) );
		$this->assertWPError( $other_args, 'A different argument set is a different event, and is not queued' );
	}

	/**
	 * A negative timestamp is refused, not silently turned into 1970 by
	 * absint().
	 */
	public function test_a_negative_cron_timestamp_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = WP_MCP_Cron::schedule_cron_event( array( 'hook' => 'wp_version_check', 'timestamp' => '-5' ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_cron_validation_error', $result->get_error_code() );
	}

	/**
	 * The WXR importer runs `maybe_unserialize()` over every metadata value,
	 * so a serialized object in the payload is a PHP object-injection
	 * primitive and is refused before the importer ever sees the document.
	 */
	public function test_wxr_import_refuses_a_serialized_object_in_metadata() {
		$parsed = array(
			'posts' => array(
				array(
					'post_title' => 'Payload',
					'postmeta'   => array(
						array( 'key' => 'harmless', 'value' => 'plain string' ),
						array( 'key' => 'payload', 'value' => 'O:8:"stdClass":1:{s:4:"evil";b:1;}' ),
					),
				),
			),
		);

		$result = WP_MCP_Import_Export::reject_serialized_objects( $parsed );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_import_unsafe_meta', $result->get_error_code() );
	}

	/**
	 * A serialized *array* is ordinary WXR (`_wp_attachment_metadata` is
	 * one) and must keep importing — the guard targets objects, not
	 * serialization.
	 */
	public function test_wxr_import_still_accepts_a_serialized_array() {
		$parsed = array(
			'posts' => array(
				array(
					'post_title' => 'Attachment',
					'postmeta'   => array(
						array( 'key' => '_wp_attachment_metadata', 'value' => 'a:2:{s:5:"width";i:640;s:6:"height";i:480;}' ),
					),
				),
			),
		);

		$this->assertTrue( WP_MCP_Import_Export::reject_serialized_objects( $parsed ) );
	}

	/**
	 * Term metadata is the other route the importer unserializes
	 * (`process_terms()` → `maybe_unserialize()`), so `wp:termmeta` gets the
	 * same refusal as `wp:postmeta`.
	 */
	public function test_wxr_import_refuses_a_serialized_object_in_term_metadata() {
		$parsed = array(
			'terms' => array(
				array(
					'term_name' => 'Payload',
					'termmeta'  => array(
						array( 'key' => 'payload', 'value' => 'O:8:"stdClass":1:{s:4:"evil";b:1;}' ),
					),
				),
			),
		);

		$result = WP_MCP_Import_Export::reject_serialized_objects( $parsed );

		$this->assertWPError( $result, 'A serialized object in wp:termmeta must be refused just like one in wp:postmeta' );
		$this->assertEquals( 'wp_mcp_import_unsafe_meta', $result->get_error_code() );
	}

	/**
	 * An object nested inside a serialized array is caught too.
	 */
	public function test_a_nested_serialized_object_is_detected() {
		$this->assertTrue( WP_MCP_Import_Export::contains_serialized_object( 'a:1:{i:0;O:8:"stdClass":0:{}}' ) );
		$this->assertFalse( WP_MCP_Import_Export::contains_serialized_object( 'a:1:{i:0;s:5:"plain";}' ) );
		$this->assertFalse( WP_MCP_Import_Export::contains_serialized_object( 'just a string' ) );
	}

	/* ==================================================================
	 * 9. Metadata allowlist
	 * ================================================================ */

	/**
	 * Protected and unregistered metadata keys are refused before the
	 * registry is even consulted, so there is no generic get/update-post-meta.
	 */
	public function test_protected_and_unregistered_post_meta_keys_are_refused() {
		register_post_type( 'wp_mcp_sec_item', array(
			'label'        => 'Security fixture',
			'public'       => true,
			'show_in_rest' => true,
			'map_meta_cap' => true,
			'supports'     => array( 'title', 'custom-fields' ),
		) );
		register_post_meta( 'wp_mcp_sec_item', 'wp_mcp_sec_public', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
		register_post_meta( 'wp_mcp_sec_item', '_wp_mcp_sec_private', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post_id = self::factory()->post->create( array(
			'post_type'   => 'wp_mcp_sec_item',
			'post_author' => $admin,
			'post_status' => 'publish',
		) );

		$private = WP_MCP_Post_Types::get_custom_post_meta( array(
			'post_type' => 'wp_mcp_sec_item',
			'post_id'   => $post_id,
			'meta_key'  => '_wp_mcp_sec_private',
		) );
		$unregistered = WP_MCP_Post_Types::get_custom_post_meta( array(
			'post_type' => 'wp_mcp_sec_item',
			'post_id'   => $post_id,
			'meta_key'  => 'wp_mcp_never_registered',
		) );

		$this->assertWPError( $private, 'An underscore-prefixed key is protected and must be refused' );
		$this->assertWPError( $unregistered, 'A key that was never registered must be refused' );

		unregister_post_meta( 'wp_mcp_sec_item', 'wp_mcp_sec_public' );
		unregister_post_meta( 'wp_mcp_sec_item', '_wp_mcp_sec_private' );
		unregister_post_type( 'wp_mcp_sec_item' );
	}

	/* ==================================================================
	 * 10. Bulk limits, malformed input, destructive separation
	 * ================================================================ */

	/**
	 * Every bulk action is capped, so one MCP call cannot become an
	 * unbounded write loop.
	 */
	public function test_bulk_actions_refuse_more_than_their_documented_maximum() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$too_many = range( 1, 200 );

		$this->assertWPError( WP_MCP_Media::bulk_trash_media( array( 'media_ids' => $too_many ) ) );
		$this->assertWPError( WP_MCP_Taxonomies::bulk_assign_terms( array(
			'taxonomy'    => 'category',
			'object_type' => 'post',
			'object_ids'  => $too_many,
			'term_ids'    => array( 1 ),
		) ) );
	}

	/**
	 * Values of the wrong PHP type never fatal and never silently truncate
	 * into a different, valid ID.
	 */
	public function test_malformed_scalar_inputs_are_refused_without_fataling() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$malformed = array( array( 'nested' => true ), null, true, false, '', '1.5', '1e3', ' 12', '0', -1, 'abc' );

		foreach ( $malformed as $index => $value ) {
			$result = WP_MCP_Posts::get_post( array( 'post_id' => $value ) );
			$this->assertWPError( $result, 'Malformed post_id at index ' . $index . ' must be refused' );
		}
	}

	/**
	 * `is_strict_positive_int_id()` is the reason those values are refused
	 * rather than truncated; is_numeric() would accept most of them.
	 */
	public function test_strict_positive_int_ids_reject_what_is_numeric_accepts() {
		foreach ( array( '1.5', '1e3', ' 12', '012', '-1', '0', true, false, null, array(), 1.5 ) as $value ) {
			$this->assertFalse( WP_MCP_Permissions::is_strict_positive_int_id( $value ), 'Must be refused: ' . wp_json_encode( $value ) );
		}
		foreach ( array( 1, 42, '1', '42' ) as $value ) {
			$this->assertTrue( WP_MCP_Permissions::is_strict_positive_int_id( $value ), 'Must be accepted: ' . wp_json_encode( $value ) );
		}
	}

	/**
	 * Trash and permanent deletion are separate abilities, separately
	 * annotated, so a client can never destroy content believing it moved it.
	 */
	public function test_trash_and_permanent_deletion_are_separate_abilities() {
		$abilities = $this->wp_mcp_abilities();

		$pairs = array(
			'wp-mcp/trash-post'   => 'wp-mcp/delete-post-permanently',
			'wp-mcp/trash-page'   => 'wp-mcp/delete-page-permanently',
			'wp-mcp/trash-media'  => 'wp-mcp/delete-media-permanently',
			'wp-mcp/trash-comment' => 'wp-mcp/delete-comment-permanently',
		);

		foreach ( $pairs as $reversible => $permanent ) {
			$this->assertArrayHasKey( $reversible, $abilities, $reversible . ' must exist' );
			$this->assertArrayHasKey( $permanent, $abilities, $permanent . ' must exist' );

			$permanent_meta = $abilities[ $permanent ]->get_meta();
			$this->assertTrue( $permanent_meta['annotations']['destructive'], $permanent . ' must be annotated destructive' );
			$this->assertFalse( $permanent_meta['annotations']['readonly'], $permanent . ' must not be annotated read-only' );
		}
	}

	/**
	 * A read-only ability is never annotated destructive, and never carries a
	 * write annotation — the annotations are what an MCP client shows a human
	 * before approving a tool call.
	 */
	public function test_readonly_annotations_are_internally_consistent() {
		$offenders = array();

		foreach ( $this->wp_mcp_abilities() as $name => $ability ) {
			$annotations = $ability->get_meta()['annotations'];
			if ( true !== $annotations['readonly'] ) {
				continue;
			}
			if ( true === $annotations['destructive'] ) {
				$offenders[] = $name . ' (read-only but destructive)';
			}
			if ( true !== $annotations['idempotent'] ) {
				$offenders[] = $name . ' (read-only but not idempotent)';
			}
		}

		$this->assertSame( array(), $offenders, 'Inconsistent read-only annotations: ' . implode( '; ', $offenders ) );
	}
}
