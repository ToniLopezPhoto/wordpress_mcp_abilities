<?php
/**
 * Multisite-only tests for the WordPress MCP network domain (issue #13).
 *
 * These run under `phpunit.multisite.xml.dist`, which defines
 * `WP_TESTS_MULTISITE` so the WordPress test framework installs a network.
 * Under the ordinary single-site configuration every test here skips itself,
 * and the single-site half of issue #13's acceptance criterion — abilities
 * registered, never offered, answering `wp_mcp_network_unsupported` — is
 * covered in tests/test-abilities.php instead.
 *
 * @package Apfimur_Agent_Abilities
 */

class WP_MCP_Test_Network extends WP_UnitTestCase {

	/**
	 * Sites created by a test, removed again in tearDown.
	 *
	 * @var int[]
	 */
	protected $created_sites = array();

	/**
	 * Snapshot of the $super_admins override, if the environment sets one.
	 *
	 * @var array|null
	 */
	protected $super_admins_backup = null;

	/**
	 * A Super Admin used by most tests.
	 *
	 * @var int
	 */
	protected $super_admin;

	/**
	 * An administrator of the current site who is NOT a Super Admin.
	 *
	 * @var int
	 */
	protected $site_admin;

	public function setUp(): void {
		parent::setUp();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'The WordPress MCP network domain only applies to a multisite installation. Run this file with phpunit.multisite.xml.dist.' );
		}

		WP_MCP_Role::create_or_reconcile();

		$this->super_admins_backup = isset( $GLOBALS['super_admins'] ) ? $GLOBALS['super_admins'] : null;

		$this->site_admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->super_admin = $this->make_super_admin();

		wp_set_current_user( $this->super_admin );
	}

	public function tearDown(): void {
		foreach ( array_reverse( $this->created_sites ) as $site_id ) {
			if ( get_site( $site_id ) instanceof WP_Site && ! is_main_site( $site_id ) ) {
				wp_delete_site( $site_id );
			}
		}
		$this->created_sites = array();

		if ( null === $this->super_admins_backup ) {
			unset( $GLOBALS['super_admins'] );
		} else {
			$GLOBALS['super_admins'] = $this->super_admins_backup;
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/* ------------------------------------------------------------------
	 * Fixtures
	 * ---------------------------------------------------------------- */

	/**
	 * Create a Super Admin, coping with a test environment that pins the
	 * list through the $super_admins global (where core's
	 * grant_super_admin() is a documented no-op).
	 *
	 * @return int
	 */
	private function make_super_admin() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_userdata( $user_id );
		$this->assertInstanceOf( 'WP_User', $user );

		if ( isset( $GLOBALS['super_admins'] ) ) {
			$GLOBALS['super_admins'][] = $user->user_login;
		} else {
			grant_super_admin( $user_id );
		}

		if ( ! is_super_admin( $user_id ) ) {
			$this->markTestSkipped( 'This environment does not allow granting Super Admin, so the network capability paths cannot be exercised.' );
		}

		return $user_id;
	}

	/**
	 * Create a site through the ability under test and remember it for
	 * cleanup.
	 *
	 * @param string $slug  Site slug.
	 * @param string $title Site title.
	 * @return array Formatted site.
	 */
	private function create_site( $slug, $title = 'Fixture Site' ) {
		$site = WP_MCP_Network_Sites::create_network_site( array(
			'slug'          => $slug,
			'title'         => $title,
			'admin_user_id' => $this->super_admin,
		) );
		$this->assertNotWPError( $site, 'Creating the fixture site must succeed.' );
		$this->created_sites[] = (int) $site['id'];
		return $site;
	}

	/**
	 * A stylesheet that is installed but is not the main site's theme.
	 *
	 * @return string
	 */
	private function spare_stylesheet() {
		$active = (string) get_blog_option( get_main_site_id(), 'stylesheet', '' );
		foreach ( array_keys( wp_get_themes() ) as $stylesheet ) {
			if ( $stylesheet !== $active ) {
				return $stylesheet;
			}
		}
		$this->markTestSkipped( 'This installation has no theme other than the main site\'s active theme.' );
		return '';
	}

	/**
	 * The first installed plugin file, or a skip.
	 *
	 * @return string
	 */
	private function any_installed_plugin() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( empty( $plugins ) ) {
			$this->markTestSkipped( 'This installation has no plugins to exercise network activation with.' );
		}
		$files = array_keys( $plugins );
		return $files[0];
	}

	/* ==================================================================
	 * Capability boundary
	 * ================================================================ */

	public function test_network_capabilities_are_granted_to_a_super_admin() {
		$this->assertTrue( WP_MCP_Network::is_available() );
		foreach ( array( 'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options', 'upgrade_network' ) as $capability ) {
			$this->assertTrue( WP_MCP_Network::can( $capability ), $capability . ' must be granted to a Super Admin' );
		}
	}

	public function test_site_administrator_is_not_a_network_administrator() {
		wp_set_current_user( $this->site_admin );

		foreach ( array( 'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options' ) as $capability ) {
			$this->assertFalse( WP_MCP_Network::can( $capability ), 'A site administrator must not hold ' . $capability );
		}

		$result = WP_MCP_Network_Sites::list_network_sites();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_permission_denied', $result->get_error_code() );
	}

	public function test_network_user_operations_require_manage_network_users() {
		wp_set_current_user( $this->site_admin );

		foreach ( array(
			array( array( 'WP_MCP_Network_Users', 'list_network_users' ), array() ),
			array( array( 'WP_MCP_Network_Users', 'get_network_user' ), array( 'user_id' => $this->super_admin ) ),
			array( array( 'WP_MCP_Network_Users', 'create_network_user' ), array( 'username' => 'blockeduser', 'email' => 'blocked@example.org', 'password' => 'correct-horse-battery' ) ),
		) as $call ) {
			$result = call_user_func( $call[0], $call[1] );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_network_permission_denied', $result->get_error_code() );
		}
	}

	public function test_network_settings_require_manage_network_options() {
		wp_set_current_user( $this->site_admin );

		foreach ( array( 'get_network_settings', 'list_network_settings_fields' ) as $method ) {
			$result = call_user_func( array( 'WP_MCP_Network_Settings', $method ), array() );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_network_permission_denied', $result->get_error_code() );
		}

		$written = WP_MCP_Network_Settings::update_network_settings( array( 'network_name' => 'Hijacked' ) );
		$this->assertWPError( $written );
		$this->assertEquals( 'wp_mcp_network_permission_denied', $written->get_error_code() );
	}

	public function test_network_theme_operations_require_manage_network_themes() {
		wp_set_current_user( $this->site_admin );

		$result = WP_MCP_Network_Extensions::list_network_themes();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_permission_denied', $result->get_error_code() );
	}

	/* ==================================================================
	 * Network inspection and updates
	 * ================================================================ */

	public function test_get_network_info_reports_the_network() {
		$info = WP_MCP_Network::get_network_info();
		$this->assertNotWPError( $info );

		$this->assertTrue( $info['is_multisite'] );
		$this->assertEquals( get_current_network_id(), $info['network_id'] );
		$this->assertEquals( get_main_site_id(), $info['main_site_id'] );
		$this->assertEquals( get_current_blog_id(), $info['current_site_id'] );
		$this->assertGreaterThanOrEqual( 1, $info['site_count'] );
		$this->assertTrue( $info['current_user_is_super_admin'] );
		$this->assertEquals( is_subdomain_install(), $info['subdomain_install'] );
	}

	public function test_get_network_update_status_reports_versions() {
		global $wp_db_version;

		$status = WP_MCP_Network::get_network_update_status();
		$this->assertNotWPError( $status );
		$this->assertEquals( (int) $wp_db_version, $status['wp_db_version'] );
		$this->assertIsBool( $status['upgrade_required'] );
		$this->assertIsBool( $status['core_update_available'] );
		$this->assertGreaterThanOrEqual( 1, $status['sites_total'] );
	}

	public function test_upgrade_network_sites_is_bounded_and_idempotent() {
		global $wp_db_version;

		$first = WP_MCP_Network::upgrade_network_sites( array( 'max_sites' => 1 ) );
		$this->assertNotWPError( $first );
		$this->assertLessThanOrEqual( 1, $first['upgraded_count'] );
		$this->assertEquals( 0, $first['offset'] );
		$this->assertEquals( $first['upgraded_count'], $first['next_offset'] );

		// Walk the whole network so the run completes and stamps the version.
		$offset = 0;
		do {
			$page   = WP_MCP_Network::upgrade_network_sites( array( 'max_sites' => 50, 'offset' => $offset ) );
			$this->assertNotWPError( $page );
			$offset = (int) $page['next_offset'];
		} while ( $page['remaining'] > 0 && $page['upgraded_count'] > 0 );

		$this->assertEquals( 0, $page['remaining'] );
		$this->assertEquals( (int) $wp_db_version, (int) get_site_option( 'wpmu_upgrade_site', 0 ) );

		// Running it again changes nothing.
		$again = WP_MCP_Network::upgrade_network_sites( array( 'max_sites' => 50 ) );
		$this->assertNotWPError( $again );
		$this->assertEquals( $page['sites_total'], $again['sites_total'] );
	}

	public function test_upgrade_network_sites_requires_upgrade_network() {
		wp_set_current_user( $this->site_admin );

		$result = WP_MCP_Network::upgrade_network_sites();
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_permission_denied', $result->get_error_code() );
	}

	/* ==================================================================
	 * Sites
	 * ================================================================ */

	public function test_list_network_sites_returns_the_main_site() {
		$result = WP_MCP_Network_Sites::list_network_sites();
		$this->assertNotWPError( $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertLessThanOrEqual( 50, $result['per_page'] );

		$main = null;
		foreach ( $result['sites'] as $site ) {
			if ( $site['is_main_site'] ) {
				$main = $site;
			}
		}
		$this->assertNotNull( $main, 'The main site must appear in the network site list.' );
		$this->assertEquals( get_main_site_id(), $main['id'] );
	}

	public function test_list_network_sites_rejects_an_unknown_status() {
		$result = WP_MCP_Network_Sites::list_network_sites( array( 'status' => 'haunted' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
	}

	public function test_list_network_sites_status_filter_narrows_the_result() {
		$site = $this->create_site( 'archivedsite', 'Archived Site' );
		$this->assertNotWPError( WP_MCP_Network_Sites::archive_network_site( array( 'site_id' => $site['id'] ) ) );

		$archived = WP_MCP_Network_Sites::list_network_sites( array( 'status' => 'archived', 'per_page' => 50 ) );
		$this->assertNotWPError( $archived );
		$ids = wp_list_pluck( $archived['sites'], 'id' );
		$this->assertContains( (int) $site['id'], $ids );

		$active = WP_MCP_Network_Sites::list_network_sites( array( 'status' => 'active', 'per_page' => 50 ) );
		$this->assertNotWPError( $active );
		$this->assertNotContains( (int) $site['id'], wp_list_pluck( $active['sites'], 'id' ) );
	}

	public function test_get_network_site_rejects_an_unknown_site() {
		$result = WP_MCP_Network_Sites::get_network_site( array( 'site_id' => 999999 ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_network_site', $result->get_error_code() );
	}

	public function test_get_network_site_rejects_a_non_integer_id() {
		foreach ( array( 0, -1, '1.5', '1e3', ' 12', true ) as $bad ) {
			$result = WP_MCP_Network_Sites::get_network_site( array( 'site_id' => $bad ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
		}
	}

	public function test_create_network_site_creates_from_a_slug() {
		$site = $this->create_site( 'branchoffice', 'Branch Office' );

		$this->assertGreaterThan( 0, $site['id'] );
		$this->assertFalse( $site['is_main_site'] );
		$this->assertEquals( 'Branch Office', $site['name'] );
		$this->assertEquals( get_current_network_id(), $site['network_id'] );

		if ( is_subdomain_install() ) {
			$this->assertStringStartsWith( 'branchoffice.', $site['domain'] );
		} else {
			$this->assertStringEndsWith( '/branchoffice/', $site['path'] );
		}

		$this->assertEquals( 'Branch Office', get_blog_option( (int) $site['id'], 'blogname' ) );
	}

	public function test_create_network_site_never_accepts_a_domain_as_slug() {
		foreach ( array( 'evil.example.com', '/evil/', 'http://evil.example.com', 'has space', '-leading', 'trailing-', 'under_score' ) as $bad ) {
			$result = WP_MCP_Network_Sites::create_network_site( array(
				'slug'          => $bad,
				'title'         => 'Nope',
				'admin_user_id' => $this->super_admin,
			) );
			$this->assertWPError( $result, '"' . $bad . '" must not be accepted as a site slug' );
			$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
		}
	}

	public function test_create_network_site_rejects_a_duplicate_address() {
		$this->create_site( 'twinsite', 'Twin Site' );

		$duplicate = WP_MCP_Network_Sites::create_network_site( array(
			'slug'          => 'twinsite',
			'title'         => 'Twin Site Again',
			'admin_user_id' => $this->super_admin,
		) );
		$this->assertWPError( $duplicate );
		$this->assertEquals( 'wp_mcp_network_conflict', $duplicate->get_error_code() );
	}

	public function test_create_network_site_requires_an_existing_admin_user() {
		$result = WP_MCP_Network_Sites::create_network_site( array(
			'slug'          => 'orphansite',
			'title'         => 'Orphan Site',
			'admin_user_id' => 999999,
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
	}

	public function test_create_network_site_refuses_an_illegal_name() {
		update_network_option( null, 'illegal_names', array( 'forbidden' ) );

		$result = WP_MCP_Network_Sites::create_network_site( array(
			'slug'          => 'forbidden',
			'title'         => 'Forbidden',
			'admin_user_id' => $this->super_admin,
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_conflict', $result->get_error_code() );
	}

	public function test_update_network_site_changes_title_and_public_flag() {
		$site = $this->create_site( 'editablesite', 'Editable Site' );

		$updated = WP_MCP_Network_Sites::update_network_site( array(
			'site_id' => $site['id'],
			'title'   => 'Renamed Site',
			'public'  => false,
		) );
		$this->assertNotWPError( $updated );
		$this->assertEquals( 'Renamed Site', $updated['name'] );
		$this->assertFalse( $updated['public'] );
		$this->assertEquals( 'Renamed Site', get_blog_option( (int) $site['id'], 'blogname' ) );
		$this->assertEquals( 0, (int) get_blog_option( (int) $site['id'], 'blog_public' ), 'The blog_public option must stay in step with the wp_blogs.public column.' );
	}

	public function test_update_network_site_never_moves_a_site_to_another_address() {
		$site = $this->create_site( 'immovablesite', 'Immovable Site' );

		$updated = WP_MCP_Network_Sites::update_network_site( array(
			'site_id' => $site['id'],
			'title'   => 'Still Here',
			'domain'  => 'evil.example.com',
			'path'    => '/evil/',
		) );
		$this->assertNotWPError( $updated );
		$this->assertEquals( $site['domain'], $updated['domain'] );
		$this->assertEquals( $site['path'], $updated['path'] );
	}

	public function test_update_network_site_requires_at_least_one_field() {
		$site = $this->create_site( 'untouchedsite', 'Untouched Site' );

		$result = WP_MCP_Network_Sites::update_network_site( array( 'site_id' => $site['id'] ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
	}

	public function test_archive_and_unarchive_round_trip() {
		$site = $this->create_site( 'roundtripsite', 'Round Trip Site' );

		$archived = WP_MCP_Network_Sites::archive_network_site( array( 'site_id' => $site['id'] ) );
		$this->assertNotWPError( $archived );
		$this->assertTrue( $archived['archived'] );

		$again = WP_MCP_Network_Sites::archive_network_site( array( 'site_id' => $site['id'] ) );
		$this->assertNotWPError( $again, 'Archiving an archived site must be an idempotent no-op.' );
		$this->assertTrue( $again['archived'] );

		$restored = WP_MCP_Network_Sites::unarchive_network_site( array( 'site_id' => $site['id'] ) );
		$this->assertNotWPError( $restored );
		$this->assertFalse( $restored['archived'] );
	}

	public function test_deactivate_and_activate_round_trip() {
		$site = $this->create_site( 'deactivatablesite', 'Deactivatable Site' );

		$deactivated = WP_MCP_Network_Sites::deactivate_network_site( array( 'site_id' => $site['id'] ) );
		$this->assertNotWPError( $deactivated );
		$this->assertTrue( $deactivated['deleted'] );

		// Nothing was actually removed: the site is still there.
		$this->assertInstanceOf( 'WP_Site', get_site( (int) $site['id'] ) );

		$activated = WP_MCP_Network_Sites::activate_network_site( array( 'site_id' => $site['id'] ) );
		$this->assertNotWPError( $activated );
		$this->assertFalse( $activated['deleted'] );
	}

	public function test_spam_and_unspam_round_trip() {
		$site = $this->create_site( 'spammysite', 'Spammy Site' );

		$spam = WP_MCP_Network_Sites::mark_network_site_spam( array( 'site_id' => $site['id'] ) );
		$this->assertNotWPError( $spam );
		$this->assertTrue( $spam['spam'] );

		$ham = WP_MCP_Network_Sites::unmark_network_site_spam( array( 'site_id' => $site['id'] ) );
		$this->assertNotWPError( $ham );
		$this->assertFalse( $ham['spam'] );
	}

	public function test_main_site_can_never_be_archived_deactivated_spammed_or_deleted() {
		$main = get_main_site_id();

		foreach ( array( 'archive_network_site', 'deactivate_network_site', 'mark_network_site_spam', 'delete_network_site' ) as $method ) {
			$result = call_user_func( array( 'WP_MCP_Network_Sites', $method ), array( 'site_id' => $main ) );
			$this->assertWPError( $result, $method . ' must refuse the main site' );
			$this->assertEquals( 'wp_mcp_network_conflict', $result->get_error_code() );
		}

		$this->assertInstanceOf( 'WP_Site', get_site( $main ) );
	}

	public function test_a_site_cannot_disable_itself_from_within_its_own_request() {
		$site    = $this->create_site( 'selfsite', 'Self Site' );
		$site_id = (int) $site['id'];

		switch_to_blog( $site_id );
		$result = WP_MCP_Network_Sites::deactivate_network_site( array( 'site_id' => $site_id ) );
		restore_current_blog();

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_conflict', $result->get_error_code() );
	}

	public function test_delete_network_site_removes_the_site() {
		$site    = $this->create_site( 'doomedsite', 'Doomed Site' );
		$site_id = (int) $site['id'];

		$deleted = WP_MCP_Network_Sites::delete_network_site( array( 'site_id' => $site_id ) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertEquals( $site_id, $deleted['id'] );
		$this->assertNull( get_site( $site_id ) );
	}

	/* ==================================================================
	 * Network users
	 * ================================================================ */

	public function test_create_network_user_creates_an_account_with_no_site() {
		$created = WP_MCP_Network_Users::create_network_user( array(
			'username' => 'netnewuser',
			'email'    => 'netnewuser@example.org',
			'password' => 'correct-horse-battery',
		) );
		$this->assertNotWPError( $created );
		$this->assertEquals( 'netnewuser', $created['username'] );
		$this->assertEquals( 0, $created['site_count'], 'A network user starts out belonging to no site.' );
		$this->assertFalse( $created['is_super_admin'] );
		$this->assertArrayNotHasKey( 'password', $created );
	}

	public function test_create_network_user_enforces_the_network_signup_policy() {
		update_network_option( null, 'banned_email_domains', array( 'blocked.test' ) );

		$result = WP_MCP_Network_Users::create_network_user( array(
			'username' => 'banneduser',
			'email'    => 'banneduser@blocked.test',
			'password' => 'correct-horse-battery',
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
	}

	public function test_create_network_user_requires_a_long_enough_password() {
		$result = WP_MCP_Network_Users::create_network_user( array(
			'username' => 'shortpwuser',
			'email'    => 'shortpwuser@example.org',
			'password' => 'short',
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
	}

	public function test_list_network_users_sees_users_of_every_site() {
		$site = $this->create_site( 'peoplesite', 'People Site' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertNotWPError( WP_MCP_Network_Users::add_user_to_network_site( array(
			'site_id' => $site['id'],
			'user_id' => $user,
			'role'    => 'author',
		) ) );

		$listed = WP_MCP_Network_Users::list_network_users( array( 'per_page' => 50 ) );
		$this->assertNotWPError( $listed );
		$this->assertContains( $user, wp_list_pluck( $listed['users'], 'id' ) );
		$this->assertGreaterThanOrEqual( 1, $listed['total'] );
	}

	public function test_get_network_user_reports_super_admin_and_site_count() {
		$profile = WP_MCP_Network_Users::get_network_user( array( 'user_id' => $this->super_admin ) );
		$this->assertNotWPError( $profile );
		$this->assertTrue( $profile['is_super_admin'] );
		$this->assertGreaterThanOrEqual( 1, $profile['site_count'] );
		$this->assertArrayNotHasKey( 'user_pass', $profile );
	}

	public function test_get_network_user_rejects_an_unknown_user() {
		$result = WP_MCP_Network_Users::get_network_user( array( 'user_id' => 999999 ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_user', $result->get_error_code() );
	}

	public function test_site_membership_round_trip() {
		$site    = $this->create_site( 'membershipsite', 'Membership Site' );
		$site_id = (int) $site['id'];
		$user    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$added = WP_MCP_Network_Users::add_user_to_network_site( array(
			'site_id' => $site_id,
			'user_id' => $user,
			'role'    => 'editor',
		) );
		$this->assertNotWPError( $added );
		$this->assertEquals( array( 'editor' ), $added['roles'] );
		$this->assertTrue( is_user_member_of_blog( $user, $site_id ) );

		$changed = WP_MCP_Network_Users::set_network_site_user_role( array(
			'site_id' => $site_id,
			'user_id' => $user,
			'role'    => 'author',
		) );
		$this->assertNotWPError( $changed );
		$this->assertEquals( array( 'author' ), $changed['roles'] );

		$removed = WP_MCP_Network_Users::remove_user_from_network_site( array(
			'site_id' => $site_id,
			'user_id' => $user,
		) );
		$this->assertNotWPError( $removed );
		$this->assertTrue( $removed['removed'] );
		$this->assertFalse( is_user_member_of_blog( $user, $site_id ) );

		// The account itself survives losing a membership.
		$this->assertInstanceOf( 'WP_User', get_userdata( $user ) );
	}

	public function test_add_user_to_network_site_is_idempotent_for_the_same_role() {
		$site = $this->create_site( 'idempotentsite', 'Idempotent Site' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$args = array( 'site_id' => $site['id'], 'user_id' => $user, 'role' => 'author' );

		$this->assertNotWPError( WP_MCP_Network_Users::add_user_to_network_site( $args ) );
		$second = WP_MCP_Network_Users::add_user_to_network_site( $args );
		$this->assertNotWPError( $second );
		$this->assertEquals( array( 'author' ), $second['roles'] );
	}

	public function test_add_user_to_network_site_refuses_a_conflicting_role() {
		$site = $this->create_site( 'conflictsite', 'Conflict Site' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertNotWPError( WP_MCP_Network_Users::add_user_to_network_site( array(
			'site_id' => $site['id'],
			'user_id' => $user,
			'role'    => 'author',
		) ) );

		$conflict = WP_MCP_Network_Users::add_user_to_network_site( array(
			'site_id' => $site['id'],
			'user_id' => $user,
			'role'    => 'editor',
		) );
		$this->assertWPError( $conflict );
		$this->assertEquals( 'wp_mcp_network_conflict', $conflict->get_error_code() );
	}

	public function test_site_role_must_exist_on_the_target_site() {
		$site = $this->create_site( 'rolessite', 'Roles Site' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = WP_MCP_Network_Users::add_user_to_network_site( array(
			'site_id' => $site['id'],
			'user_id' => $user,
			'role'    => 'wizard',
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
	}

	public function test_set_network_site_user_role_requires_membership() {
		$site = $this->create_site( 'strangersite', 'Stranger Site' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = WP_MCP_Network_Users::set_network_site_user_role( array(
			'site_id' => $site['id'],
			'user_id' => $user,
			'role'    => 'editor',
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_conflict', $result->get_error_code() );
	}

	public function test_remove_user_from_network_site_refuses_the_current_user() {
		$result = WP_MCP_Network_Users::remove_user_from_network_site( array(
			'site_id' => get_main_site_id(),
			'user_id' => $this->super_admin,
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
	}

	public function test_remove_user_from_network_site_reassigns_content() {
		$author    = self::factory()->user->create( array( 'role' => 'author' ) );
		$inheritor = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create( array( 'post_author' => $author ) );

		$removed = WP_MCP_Network_Users::remove_user_from_network_site( array(
			'site_id'          => get_main_site_id(),
			'user_id'          => $author,
			'reassign_user_id' => $inheritor,
		) );
		$this->assertNotWPError( $removed );
		$this->assertEquals( $inheritor, $removed['reassigned_to'] );

		clean_post_cache( $post_id );
		$this->assertEquals( $inheritor, (int) get_post( $post_id )->post_author );
	}

	public function test_delete_network_user_requires_a_reassignment_user() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = WP_MCP_Network_Users::delete_network_user( array( 'user_id' => $user ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
		$this->assertInstanceOf( 'WP_User', get_userdata( $user ), 'A refused deletion must not remove the user.' );
	}

	public function test_delete_network_user_refuses_a_super_admin() {
		$other = $this->make_super_admin();

		$result = WP_MCP_Network_Users::delete_network_user( array(
			'user_id'          => $other,
			'reassign_user_id' => $this->super_admin,
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_conflict', $result->get_error_code() );
		$this->assertInstanceOf( 'WP_User', get_userdata( $other ) );
	}

	public function test_delete_network_user_refuses_the_current_user() {
		$result = WP_MCP_Network_Users::delete_network_user( array(
			'user_id'          => $this->super_admin,
			'reassign_user_id' => $this->site_admin,
		) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_validation_error', $result->get_error_code() );
	}

	public function test_delete_network_user_reassigns_content_before_deleting() {
		$author    = self::factory()->user->create( array( 'role' => 'author' ) );
		$inheritor = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create( array( 'post_author' => $author ) );

		$deleted = WP_MCP_Network_Users::delete_network_user( array(
			'user_id'          => $author,
			'reassign_user_id' => $inheritor,
		) );
		$this->assertNotWPError( $deleted );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertEquals( $inheritor, $deleted['reassigned_to'] );
		$this->assertFalse( get_userdata( $author ) );

		clean_post_cache( $post_id );
		$survivor = get_post( $post_id );
		$this->assertInstanceOf( 'WP_Post', $survivor, 'Deleting a network user must never destroy content that was reassigned.' );
		$this->assertEquals( $inheritor, (int) $survivor->post_author );
	}

	public function test_list_network_user_sites_reports_roles_per_site() {
		$site = $this->create_site( 'multisitemember', 'Multi Site Member' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertNotWPError( WP_MCP_Network_Users::add_user_to_network_site( array(
			'site_id' => $site['id'],
			'user_id' => $user,
			'role'    => 'editor',
		) ) );

		$listed = WP_MCP_Network_Users::list_network_user_sites( array( 'user_id' => $user, 'per_page' => 50 ) );
		$this->assertNotWPError( $listed );
		$this->assertEquals( $user, $listed['user_id'] );

		$found = null;
		foreach ( $listed['sites'] as $row ) {
			if ( (int) $row['site_id'] === (int) $site['id'] ) {
				$found = $row;
			}
		}
		$this->assertNotNull( $found );
		$this->assertEquals( array( 'editor' ), $found['roles'] );
	}

	/* ==================================================================
	 * Network plugins and themes
	 * ================================================================ */

	public function test_network_activate_plugin_rejects_an_uninstalled_plugin() {
		$result = WP_MCP_Network_Extensions::network_activate_plugin( array( 'plugin_file' => 'not-installed/not-installed.php' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_plugin', $result->get_error_code() );
	}

	public function test_network_activate_plugin_rejects_a_traversal_path() {
		foreach ( array( '../../wp-config.php', '/etc/passwd', 'a\\b.php', '' ) as $bad ) {
			$result = WP_MCP_Network_Extensions::network_activate_plugin( array( 'plugin_file' => $bad ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_plugin_validation_error', $result->get_error_code() );
		}
	}

	public function test_network_deactivate_plugin_round_trip() {
		$file = $this->any_installed_plugin();

		update_site_option( 'active_sitewide_plugins', array( $file => time() ) );
		$this->assertTrue( is_plugin_active_for_network( $file ) );

		$deactivated = WP_MCP_Network_Extensions::network_deactivate_plugin( array( 'plugin_file' => $file ) );
		$this->assertNotWPError( $deactivated );
		$this->assertFalse( $deactivated['network_active'] );
		$this->assertEquals( $file, $deactivated['file'] );

		$again = WP_MCP_Network_Extensions::network_deactivate_plugin( array( 'plugin_file' => $file ) );
		$this->assertNotWPError( $again, 'Deactivating a plugin that is not network-active must be an idempotent no-op.' );
		$this->assertFalse( $again['network_active'] );
	}

	public function test_network_plugin_operations_require_manage_network_plugins() {
		$file = $this->any_installed_plugin();
		wp_set_current_user( $this->site_admin );

		foreach ( array( 'network_activate_plugin', 'network_deactivate_plugin' ) as $method ) {
			$result = call_user_func( array( 'WP_MCP_Network_Extensions', $method ), array( 'plugin_file' => $file ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_network_permission_denied', $result->get_error_code() );
		}
	}

	public function test_list_network_themes_reports_the_enabled_state() {
		$stylesheet = $this->spare_stylesheet();

		$this->assertNotWPError( WP_MCP_Network_Extensions::network_enable_theme( array( 'stylesheet' => $stylesheet ) ) );

		$listed = WP_MCP_Network_Extensions::list_network_themes( array( 'per_page' => 50 ) );
		$this->assertNotWPError( $listed );

		$found = null;
		foreach ( $listed['themes'] as $theme ) {
			if ( $theme['stylesheet'] === $stylesheet ) {
				$found = $theme;
			}
		}
		$this->assertNotNull( $found );
		$this->assertTrue( $found['network_enabled'] );
	}

	public function test_network_enable_and_disable_theme_round_trip() {
		$stylesheet = $this->spare_stylesheet();

		$enabled = WP_MCP_Network_Extensions::network_enable_theme( array( 'stylesheet' => $stylesheet ) );
		$this->assertNotWPError( $enabled );
		$this->assertTrue( $enabled['network_enabled'] );

		$again = WP_MCP_Network_Extensions::network_enable_theme( array( 'stylesheet' => $stylesheet ) );
		$this->assertNotWPError( $again, 'Enabling an enabled theme must be an idempotent no-op.' );
		$this->assertTrue( $again['network_enabled'] );

		$disabled = WP_MCP_Network_Extensions::network_disable_theme( array( 'stylesheet' => $stylesheet ) );
		$this->assertNotWPError( $disabled );
		$this->assertFalse( $disabled['network_enabled'] );
	}

	public function test_network_disable_theme_refuses_the_main_site_theme() {
		$active = (string) get_blog_option( get_main_site_id(), 'stylesheet', '' );
		if ( '' === $active || ! wp_get_theme( $active )->exists() ) {
			$this->markTestSkipped( 'The main site has no resolvable active theme in this environment.' );
		}

		$this->assertNotWPError( WP_MCP_Network_Extensions::network_enable_theme( array( 'stylesheet' => $active ) ) );

		$result = WP_MCP_Network_Extensions::network_disable_theme( array( 'stylesheet' => $active ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_network_conflict', $result->get_error_code() );
	}

	public function test_network_enable_theme_rejects_an_uninstalled_theme() {
		$result = WP_MCP_Network_Extensions::network_enable_theme( array( 'stylesheet' => 'no-such-theme-here' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_invalid_theme', $result->get_error_code() );
	}

	public function test_network_enable_theme_rejects_a_path() {
		foreach ( array( '../evil', 'nested/theme', 'a\\b' ) as $bad ) {
			$result = WP_MCP_Network_Extensions::network_enable_theme( array( 'stylesheet' => $bad ) );
			$this->assertWPError( $result );
			$this->assertEquals( 'wp_mcp_theme_validation_error', $result->get_error_code() );
		}
	}

	/* ==================================================================
	 * Network settings
	 * ================================================================ */

	public function test_get_network_settings_reads_every_allowlisted_field() {
		$settings = WP_MCP_Network_Settings::get_network_settings();
		$this->assertNotWPError( $settings );

		foreach ( array_keys( WP_MCP_Network_Settings::fields() ) as $field ) {
			$this->assertArrayHasKey( $field, $settings, $field . ' must appear in the network settings response' );
		}
		$this->assertEquals( (string) get_network_option( null, 'site_name', '' ), $settings['network_name'] );
		$this->assertEquals( is_subdomain_install(), $settings['subdomain_install'] );
	}

	public function test_update_network_settings_round_trip() {
		$result = WP_MCP_Network_Settings::update_network_settings( array(
			'network_name' => 'WordPress MCP Test Network',
			'registration' => 'user',
		) );
		$this->assertNotWPError( $result );
		$this->assertContains( 'network_name', $result['updated'] );
		$this->assertEquals( 'WordPress MCP Test Network', $result['settings']['network_name'] );
		$this->assertEquals( 'user', $result['settings']['registration'] );
		$this->assertEquals( 'WordPress MCP Test Network', get_network_option( null, 'site_name' ) );
	}

	public function test_update_network_settings_rejects_an_unknown_field() {
		$result = WP_MCP_Network_Settings::update_network_settings( array( 'nonexistent_field' => 'x' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_unknown_field', $result->get_error_code() );
	}

	/**
	 * The hard prohibition, restated at runtime: a raw network option name
	 * is not a field, so it is refused before anything is written.
	 */
	public function test_update_network_settings_rejects_a_raw_network_option_name() {
		foreach ( array( 'site_admins', 'siteurl', 'active_sitewide_plugins', 'allowedthemes', 'upload_path' ) as $option ) {
			$before = get_network_option( null, $option, null );

			$result = WP_MCP_Network_Settings::update_network_settings( array( $option => 'hijacked' ) );
			$this->assertWPError( $result, $option . ' must never be addressable as a settings field' );
			$this->assertEquals( 'wp_mcp_settings_unknown_field', $result->get_error_code() );
			$this->assertEquals( $before, get_network_option( null, $option, null ), $option . ' must be untouched by a refused write' );
		}
	}

	public function test_update_network_settings_rejects_a_readonly_field() {
		$before = get_network_option( null, 'admin_email', '' );

		$result = WP_MCP_Network_Settings::update_network_settings( array( 'network_admin_email' => 'attacker@example.org' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_readonly_field', $result->get_error_code() );
		$this->assertEquals( $before, get_network_option( null, 'admin_email', '' ) );
	}

	public function test_update_network_settings_validates_enums_integers_and_domains() {
		$cases = array(
			array( 'registration' => 'whenever' ),
			array( 'registration_notification' => 'maybe' ),
			array( 'blog_upload_space' => 0 ),
			array( 'blog_upload_space' => 'lots' ),
			array( 'banned_email_domains' => array( 'not a domain' ) ),
			array( 'banned_email_domains' => 'example.com' ),
			array( 'illegal_names' => array( 'has space' ) ),
			array( 'upload_filetypes' => 'jpg ../evil' ),
			array( 'add_new_users' => 'yes' ),
			array( 'default_language' => 'xx_NOT_INSTALLED' ),
		);

		foreach ( $cases as $payload ) {
			$result = WP_MCP_Network_Settings::update_network_settings( $payload );
			$this->assertWPError( $result, wp_json_encode( $payload ) . ' must be refused' );
			$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code(), wp_json_encode( $payload ) );
		}
	}

	public function test_update_network_settings_normalises_domain_and_filetype_lists() {
		$result = WP_MCP_Network_Settings::update_network_settings( array(
			'banned_email_domains' => array( 'Blocked.TEST', 'blocked.test', 'other.example' ),
			'upload_filetypes'     => 'JPG png  jpg',
		) );
		$this->assertNotWPError( $result );
		$this->assertEquals( array( 'blocked.test', 'other.example' ), $result['settings']['banned_email_domains'] );
		$this->assertEquals( 'jpg png', $result['settings']['upload_filetypes'] );
	}

	public function test_update_network_settings_requires_at_least_one_field() {
		$result = WP_MCP_Network_Settings::update_network_settings( array() );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_validation_error', $result->get_error_code() );
	}

	public function test_list_network_settings_fields_describes_the_allowlist() {
		$listed = WP_MCP_Network_Settings::list_network_settings_fields();
		$this->assertNotWPError( $listed );
		$this->assertEquals( count( WP_MCP_Network_Settings::fields() ), $listed['total'] );

		$by_field = array();
		foreach ( $listed['fields'] as $entry ) {
			$by_field[ $entry['field'] ] = $entry;
			$this->assertEquals( 'manage_network_options', $entry['capability'] );
			$this->assertNotEmpty( $entry['description'] );
		}

		$this->assertFalse( $by_field['network_admin_email']['writable'] );
		$this->assertFalse( $by_field['subdomain_install']['writable'] );
		$this->assertTrue( $by_field['network_name']['writable'] );
		$this->assertEquals( array( 'none', 'user', 'blog', 'all' ), $by_field['registration']['allowed_values'] );
	}

	public function test_network_settings_plugins_menu_toggle_round_trip() {
		$enabled = WP_MCP_Network_Settings::update_network_settings( array( 'site_admins_can_manage_plugins' => true ) );
		$this->assertNotWPError( $enabled );
		$this->assertTrue( $enabled['settings']['site_admins_can_manage_plugins'] );
		$menu_items = (array) get_network_option( null, 'menu_items', array() );
		$this->assertEquals( 1, (int) $menu_items['plugins'] );

		$disabled = WP_MCP_Network_Settings::update_network_settings( array( 'site_admins_can_manage_plugins' => false ) );
		$this->assertNotWPError( $disabled );
		$this->assertFalse( $disabled['settings']['site_admins_can_manage_plugins'] );
	}

	/* ==================================================================
	 * Cross-domain regressions
	 * ================================================================ */

	public function test_single_site_domains_still_work_on_a_network() {
		$settings = WP_MCP_Settings::get_general_settings();
		$this->assertNotWPError( $settings );
		$this->assertArrayHasKey( 'site_title', $settings );

		$sites = WP_MCP_Network_Sites::list_network_sites();
		$this->assertNotWPError( $sites );
	}

	/**
	 * The per-site settings domain already refuses `users_can_register` on
	 * multisite, because registration is network-level there. Issue #13 adds
	 * the network-level field; this asserts the two do not start fighting.
	 */
	public function test_registration_stays_network_level_for_the_per_site_domain() {
		$result = WP_MCP_Settings::update_general_settings( array( 'users_can_register' => true ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_settings_unsupported', $result->get_error_code() );

		$network = WP_MCP_Network_Settings::update_network_settings( array( 'registration' => 'all' ) );
		$this->assertNotWPError( $network );
		$this->assertEquals( 'all', $network['settings']['registration'] );
	}
}
