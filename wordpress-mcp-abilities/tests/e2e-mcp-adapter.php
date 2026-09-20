<?php
/**
 * WordPress MCP Abilities — MCP Adapter end-to-end suite (issue #15).
 *
 * Drives the real WordPress/mcp-adapter default server through its REST
 * route (`POST /wp-json/mcp/mcp-adapter-default-server`) inside the WordPress
 * test install, the way an MCP client does: JSON-RPC `initialize`, then
 * `notifications/initialized`, `tools/list`, and `tools/call` of the
 * adapter's discover / get-ability-info / execute-ability tools. Results are
 * read after a JSON round trip, which is what a client receives, and checked
 * against WordPress directly.
 *
 * The domain suites call `wp_get_ability()->execute()` in process and never
 * reach the adapter; this file is the only one that does. It is deliberately
 * outside the `test-*.php` scan of phpunit.xml.dist: it needs a booted
 * adapter (MCP_ADAPTER_DIR with the adapter's Composer dependencies
 * installed), which only the CI `e2e-adapter` job provides. That job runs it
 * through phpunit.e2e.xml.dist with WP_MCP_REQUIRE_MCP_ADAPTER=1, so a
 * missing adapter fails the bootstrap instead of skipping this suite.
 *
 * Adapter contract exercised (WordPress/mcp-adapter v0.6.1):
 * - route and tools: Servers/DefaultServerFactory.php (`mcp` namespace,
 *   `mcp-adapter-default-server` route, three `mcp-adapter/*` tools, whose
 *   MCP names replace `/` with `-` in Domain/Utils/McpNameSanitizer.php);
 * - transport gate: Transport/HttpTransport.php::check_permission()
 *   (`read` by default);
 * - sessions: Transport/Infrastructure/HttpRequestHandler.php (every method
 *   but `initialize` needs `Mcp-Session-Id`, attached to the response on
 *   `rest_post_dispatch`);
 * - execution: Abilities/ExecuteAbilityAbility.php re-enters the target
 *   ability's own permission_callback and WP_Ability::execute(), so its
 *   closed input schema still applies.
 *
 * The mutating workflows at the end run every step through the adapter's
 * execute-ability tool and check each resulting state in WordPress itself,
 * never in the tool's own output. The plugin workflow is offline: no
 * outbound HTTP leaves the process (core's post-upgrade update checks get a
 * local HTTP 503, everything else is refused), `plugins_api` answers for a
 * fixture slug, and `upgrader_pre_download` hands core's Plugin_Upgrader a
 * locally built package.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.15.0
 */

class WP_MCP_Test_MCP_Adapter_E2E extends WP_UnitTestCase {

	const ROUTE            = '/mcp/mcp-adapter-default-server';
	const SERVER_ID        = 'mcp-adapter-default-server';
	const SERVER_NAME      = 'MCP Adapter Default Server';
	const PROTOCOL_VERSION = '2025-11-25';
	const TOOL_DISCOVER    = 'mcp-adapter-discover-abilities';
	const TOOL_EXECUTE     = 'mcp-adapter-execute-ability';
	const TOOL_GET_INFO    = 'mcp-adapter-get-ability-info';

	const E2E_ROLE     = 'wp_mcp_e2e_role';
	const CPT          = 'wp_mcp_e2e_book';
	const CPT_META_KEY = 'wp_mcp_e2e_isbn';
	const FIXTURE_SLUG = 'wp-mcp-e2e-fixture';
	const FIXTURE_FILE = 'wp-mcp-e2e-fixture/wp-mcp-e2e-fixture.php';
	const FIXTURE_URL  = 'https://downloads.wordpress.org/plugin/wp-mcp-e2e-fixture.%s.zip';
	const BLOCK_MARKUP = "<!-- wp:paragraph -->\n<p>WordPress MCP E2E pattern</p>\n<!-- /wp:paragraph -->";

	/** Core's WordPress.org update checks: wp_version_check(), wp_update_plugins(), wp_update_themes(). */
	const CORE_UPDATE_CHECK = '#^https?://api\.wordpress\.org/(?:core/version-check|plugins/update-check|themes/update-check)/#';

	/** A valid 1x1 RGBA PNG (70 bytes). */
	const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/**
	 * Last JSON-RPC request ID sent.
	 *
	 * @var int
	 */
	private $request_id = 0;

	/**
	 * Captured `wp_mcp_audit_log` events for the current test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $captured_audit_events = array();

	/**
	 * URLs of outbound HTTP requests refused during the current test.
	 *
	 * @var string[]
	 */
	private $http_requests = array();

	public function set_up() {
		parent::set_up();
		$this->captured_audit_events = array();
		$this->http_requests         = array();

		if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
			$this->markTestSkipped( 'WordPress/mcp-adapter is not booted: point MCP_ADAPTER_DIR at a checkout with `composer install --no-dev` run inside it (the CI e2e-adapter job does this).' );
		}

		// A fresh REST server per test, as core's REST tests do. The adapter
		// registers its route on `rest_api_init` (HttpTransport::register_routes()).
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		// The database is rolled back after each test; the filesystem, the
		// in-memory roles and the meta registry are not.
		$this->remove_tree( WP_PLUGIN_DIR . '/' . self::FIXTURE_SLUG );
		$this->remove_tree( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . self::FIXTURE_SLUG );
		wp_cache_delete( 'plugins', 'plugins' );
		if ( get_role( self::E2E_ROLE ) ) {
			remove_role( self::E2E_ROLE );
		}
		if ( registered_meta_key_exists( 'post', self::CPT_META_KEY, self::CPT ) ) {
			unregister_post_meta( self::CPT, self::CPT_META_KEY );
		}

		parent::tear_down();
	}

	/* ==================================================================
	 * Helpers
	 * ================================================================ */

	/**
	 * Create a user with a core role and make them current.
	 *
	 * @param string $role Role slug.
	 * @return int User ID.
	 */
	private function become( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Every ability this plugin registers, keyed by name.
	 *
	 * @return array<string,WP_Ability>
	 */
	private function registered_wp_mcp_abilities() {
		$abilities = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 === strpos( $ability->get_name(), 'wp-mcp/' ) ) {
				$abilities[ $ability->get_name() ] = $ability;
			}
		}
		ksort( $abilities );
		$this->assertNotEmpty( $abilities, 'No wp-mcp/* abilities are registered — the end-to-end checks would pass vacuously.' );
		return $abilities;
	}

	/**
	 * A value as an MCP client sees it: after a JSON round trip.
	 *
	 * @param mixed $value Any JSON-encodable value.
	 * @return mixed
	 */
	private function wire( $value ) {
		return json_decode( wp_json_encode( $value ), true );
	}

	/**
	 * POST one JSON-RPC message to the adapter's REST route.
	 *
	 * WP_REST_Server::serve_request() applies `rest_post_dispatch` after
	 * dispatch(); the adapter attaches `Mcp-Session-Id` in that filter, so
	 * the same step is applied here.
	 *
	 * @param array       $message    JSON-RPC message.
	 * @param string|null $session_id Session to send, if any.
	 * @return WP_REST_Response
	 */
	private function post_message( array $message, $session_id = null ) {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json, text/event-stream' );
		if ( null !== $session_id ) {
			$request->set_header( 'Mcp-Session-Id', $session_id );
			$request->set_header( 'Mcp-Protocol-Version', self::PROTOCOL_VERSION );
		}
		$request->set_body( wp_json_encode( $message ) );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), $server, $request );
	}

	/**
	 * Send a JSON-RPC request inside a session and return the decoded body.
	 *
	 * @param string $method     MCP method.
	 * @param array  $params     Method params.
	 * @param string $session_id Session ID.
	 * @return array
	 */
	private function request( $method, array $params, $session_id ) {
		$response = $this->post_message(
			array(
				'jsonrpc' => '2.0',
				'id'      => ++$this->request_id,
				'method'  => $method,
				'params'  => (object) $params,
			),
			$session_id
		);
		return $this->wire( $response->get_data() );
	}

	/**
	 * Run the MCP handshake: `initialize`, then `notifications/initialized`.
	 *
	 * @return array{0:string,1:array} Session ID and the decoded initialize body.
	 */
	private function open_session() {
		$response = $this->post_message(
			array(
				'jsonrpc' => '2.0',
				'id'      => ++$this->request_id,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => self::PROTOCOL_VERSION,
					'capabilities'    => new stdClass(),
					'clientInfo'      => array(
						'name'    => 'wp-mcp-e2e',
						'version' => WP_MCP_VERSION,
					),
				),
			)
		);
		$body = $this->wire( $response->get_data() );

		$this->assertSame( 200, $response->get_status(), 'initialize failed: ' . wp_json_encode( $body ) );
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Mcp-Session-Id', $headers, 'initialize did not return an Mcp-Session-Id header.' );
		$session_id = (string) $headers['Mcp-Session-Id'];
		$this->assertNotSame( '', $session_id );

		$ack = $this->post_message(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
				'params'  => new stdClass(),
			),
			$session_id
		);
		$this->assertSame( 202, $ack->get_status(), 'notifications/initialized must be accepted with HTTP 202.' );

		return array( $session_id, $body );
	}

	/**
	 * Call one adapter tool and return the JSON-RPC `result` (a CallToolResult).
	 *
	 * @param string $session_id Session ID.
	 * @param string $tool       MCP tool name.
	 * @param array  $arguments  Tool arguments.
	 * @return array
	 */
	private function call_tool( $session_id, $tool, array $arguments ) {
		$body = $this->request(
			'tools/call',
			array(
				'name'      => $tool,
				'arguments' => (object) $arguments,
			),
			$session_id
		);
		$this->assertArrayHasKey( 'result', $body, 'tools/call ' . $tool . ' returned a JSON-RPC error: ' . wp_json_encode( $body ) );
		return $body['result'];
	}

	/**
	 * Execute an ability through the adapter's execute-ability tool.
	 *
	 * @param string $session_id Session ID.
	 * @param string $ability    Ability name.
	 * @param array  $parameters Ability input.
	 * @return array CallToolResult.
	 */
	private function execute_ability( $session_id, $ability, array $parameters ) {
		return $this->call_tool(
			$session_id,
			self::TOOL_EXECUTE,
			array(
				'ability_name' => $ability,
				'parameters'   => (object) $parameters,
			)
		);
	}

	/**
	 * The text of a CallToolResult, for failure messages.
	 *
	 * @param array $result CallToolResult.
	 * @return string
	 */
	private function result_text( array $result ) {
		return isset( $result['content'][0]['text'] ) ? (string) $result['content'][0]['text'] : wp_json_encode( $result );
	}

	/**
	 * Execute an ability through the adapter and require it to succeed.
	 *
	 * @param string $session_id Session ID.
	 * @param string $ability    Ability name.
	 * @param array  $parameters Ability input.
	 * @return mixed The ability's own output (`data`).
	 */
	private function execute_ok( $session_id, $ability, array $parameters ) {
		$result = $this->execute_ability( $session_id, $ability, $parameters );
		$this->assertTrue( empty( $result['isError'] ), $ability . ' failed through the adapter: ' . $this->result_text( $result ) );
		$this->assertTrue( $result['structuredContent']['success'], $ability . ' did not report success.' );
		return $result['structuredContent']['data'];
	}

	/**
	 * Execute an ability through the adapter and require it to be refused.
	 *
	 * @param string $session_id Session ID.
	 * @param string $ability    Ability name.
	 * @param array  $parameters Ability input.
	 */
	private function execute_refused( $session_id, $ability, array $parameters ) {
		$result = $this->execute_ability( $session_id, $ability, $parameters );
		$this->assertTrue( ! empty( $result['isError'] ), $ability . ' was expected to be refused, got: ' . wp_json_encode( $result ) );
	}

	/**
	 * The ID of the only post of a type with an exact title, looked up in
	 * WordPress rather than read from a tool's output.
	 *
	 * @param string $title     Exact post title.
	 * @param string $post_type Post type.
	 * @return int
	 */
	private function single_post_id( $title, $post_type ) {
		$ids = get_posts(
			array(
				'title'          => $title,
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 2,
			)
		);
		$this->assertCount( 1, $ids, "Expected exactly one {$post_type} titled \"{$title}\"." );
		return (int) $ids[0];
	}

	/**
	 * Start recording every audit event fired from now on.
	 */
	private function capture_audit_events() {
		add_action(
			'wp_mcp_audit_log',
			function ( $data ) {
				$this->captured_audit_events[] = $data;
			}
		);
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

	/**
	 * Delete a file or a directory tree if it exists.
	 *
	 * @param string $path Path.
	 */
	private function remove_tree( $path ) {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return;
		}
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $path );
	}

	/**
	 * Build a fresh installable package of the fixture plugin.
	 *
	 * A new file every call: core's upgrader deletes a downloaded package
	 * once it has unpacked it.
	 *
	 * @param string $version Plugin version to declare.
	 * @return string Path to the zip.
	 */
	private function fixture_package( $version ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$root = untrailingslashit( get_temp_dir() ) . '/wp-mcp-e2e-' . wp_generate_password( 12, false );
		$dir  = $root . '/' . self::FIXTURE_SLUG;
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/' . self::FIXTURE_SLUG . '.php', "<?php\n/**\n * Plugin Name: WordPress MCP E2E Fixture\n * Version: {$version}\n */\n" );

		$zip     = $root . '.zip';
		$archive = new PclZip( $zip );
		$created = $archive->create( $dir, PCLZIP_OPT_REMOVE_PATH, $root );
		$this->remove_tree( $root );
		$this->assertNotSame( 0, $created, 'Could not build the fixture plugin package: ' . $archive->errorInfo( true ) );

		return $zip;
	}

	/**
	 * Keep the plugin workflow offline and point it at the fixture.
	 *
	 * Every outbound HTTP request is recorded and none leaves the process.
	 * Core's own WordPress.org update checks — which `upgrader_process_complete`
	 * runs after every install and update — get a local HTTP 503: a refused
	 * request makes them raise E_USER_WARNING under WP_DEBUG, which the test
	 * runner turns into an exception inside the ability, whereas a 503 is the
	 * "WordPress.org unavailable" answer they skip silently. Every other
	 * request is refused. `plugins_api` answers for the fixture slug, and
	 * `upgrader_pre_download` — which core's WP_Upgrader::download_package()
	 * consults before downloading anything — returns a local package for the
	 * fixture's two package URLs.
	 */
	private function serve_plugin_fixture_offline() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->http_requests[] = $url;
				if ( preg_match( self::CORE_UPDATE_CHECK, $url ) ) {
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array(
							'code'    => 503,
							'message' => 'Service Unavailable',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return new WP_Error( 'wp_mcp_e2e_offline', 'The MCP end-to-end suite makes no outbound HTTP request.' );
			},
			10,
			3
		);
		add_filter(
			'plugins_api',
			function ( $result, $action, $args ) {
				if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::FIXTURE_SLUG !== $args->slug ) {
					return $result;
				}
				return (object) array(
					'name'          => 'WordPress MCP E2E Fixture',
					'slug'          => self::FIXTURE_SLUG,
					'version'       => '1.0.0',
					'download_link' => sprintf( self::FIXTURE_URL, '1.0.0' ),
				);
			},
			10,
			3
		);
		add_filter(
			'upgrader_pre_download',
			function ( $reply, $package ) {
				foreach ( array( '1.0.0', '2.0.0' ) as $version ) {
					if ( sprintf( self::FIXTURE_URL, $version ) === $package ) {
						return $this->fixture_package( $version );
					}
				}
				return $reply;
			},
			10,
			2
		);
	}

	/**
	 * The fixture plugin's version as WordPress reads it from disk.
	 *
	 * @return string|null Null when the plugin is not installed.
	 */
	private function fixture_version() {
		wp_cache_delete( 'plugins', 'plugins' );
		$plugins = get_plugins();
		return isset( $plugins[ self::FIXTURE_FILE ] ) ? $plugins[ self::FIXTURE_FILE ]['Version'] : null;
	}

	/* ==================================================================
	 * Transport, session and handshake
	 * ================================================================ */

	public function test_default_server_boots_with_its_rest_route() {
		$this->assertNotNull( \WP\MCP\Core\McpAdapter::instance()->get_server( self::SERVER_ID ), 'The adapter did not create its default server.' );
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes(), 'The default server route is not registered.' );
	}

	public function test_initialize_negotiates_the_protocol_and_opens_a_session() {
		$this->become( 'administrator' );

		list( , $body ) = $this->open_session();

		$this->assertSame( '2.0', $body['jsonrpc'] );
		$this->assertArrayHasKey( 'result', $body );
		$this->assertSame( self::PROTOCOL_VERSION, $body['result']['protocolVersion'] );
		$this->assertSame( self::SERVER_NAME, $body['result']['serverInfo']['name'] );
		$this->assertArrayHasKey( 'tools', $body['result']['capabilities'] );
	}

	public function test_an_anonymous_caller_never_reaches_the_transport() {
		wp_set_current_user( 0 );

		$response = $this->post_message(
			array(
				'jsonrpc' => '2.0',
				'id'      => ++$this->request_id,
				'method'  => 'initialize',
				'params'  => array( 'protocolVersion' => self::PROTOCOL_VERSION ),
			)
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response->get_headers() );
	}

	public function test_a_request_without_a_session_is_rejected() {
		$this->become( 'administrator' );

		$response = $this->post_message(
			array(
				'jsonrpc' => '2.0',
				'id'      => ++$this->request_id,
				'method'  => 'tools/list',
				'params'  => new stdClass(),
			)
		);
		$body = $this->wire( $response->get_data() );

		$this->assertArrayHasKey( 'error', $body );
		$this->assertArrayNotHasKey( 'result', $body );
	}

	/* ==================================================================
	 * tools/list, discovery and ability info
	 * ================================================================ */

	public function test_tools_list_exposes_exactly_the_default_servers_three_tools() {
		$this->become( 'administrator' );
		list( $session_id ) = $this->open_session();

		$body = $this->request( 'tools/list', array(), $session_id );

		$this->assertArrayHasKey( 'result', $body, wp_json_encode( $body ) );
		$names = array();
		foreach ( $body['result']['tools'] as $tool ) {
			$names[] = $tool['name'];
			$this->assertArrayHasKey( 'inputSchema', $tool, $tool['name'] . ' has no inputSchema.' );
		}
		sort( $names );
		$this->assertSame( array( self::TOOL_DISCOVER, self::TOOL_EXECUTE, self::TOOL_GET_INFO ), $names );
	}

	/**
	 * Discovery through the adapter returns every wp-mcp/* ability this
	 * plugin registers — no more (nothing hidden leaks) and no fewer (every
	 * ability is reachable from a client).
	 */
	public function test_discovery_returns_exactly_the_registered_wp_mcp_abilities() {
		$this->become( 'administrator' );
		list( $session_id ) = $this->open_session();

		$result = $this->call_tool( $session_id, self::TOOL_DISCOVER, array() );

		$this->assertTrue( empty( $result['isError'] ), $this->result_text( $result ) );
		$discovered = array();
		foreach ( $result['structuredContent']['abilities'] as $entry ) {
			if ( 0 === strpos( $entry['name'], 'wp-mcp/' ) ) {
				$discovered[] = $entry['name'];
			}
		}
		sort( $discovered );

		$this->assertSame( array_keys( $this->registered_wp_mcp_abilities() ), $discovered );
	}

	/**
	 * For every wp-mcp/* ability, get-ability-info publishes the registered
	 * input and output schemas unchanged — so closed schemas stay closed on
	 * the wire — and the MCP-only exposure flags and annotations.
	 */
	public function test_get_ability_info_publishes_every_registered_contract_unchanged() {
		$this->become( 'administrator' );
		list( $session_id ) = $this->open_session();

		$offenders = array();
		foreach ( $this->registered_wp_mcp_abilities() as $name => $ability ) {
			$result = $this->call_tool( $session_id, self::TOOL_GET_INFO, array( 'ability_name' => $name ) );
			if ( ! empty( $result['isError'] ) ) {
				$offenders[] = $name . ' (isError: ' . $this->result_text( $result ) . ')';
				continue;
			}

			$info = $result['structuredContent'];
			$meta = isset( $info['meta'] ) ? $info['meta'] : array();

			if ( $this->wire( $ability->get_input_schema() ) !== $info['input_schema'] ) {
				$offenders[] = $name . ' (input_schema differs)';
			}
			$output_schema = $ability->get_output_schema();
			if ( ! empty( $output_schema ) && ( ! isset( $info['output_schema'] ) || $this->wire( $output_schema ) !== $info['output_schema'] ) ) {
				$offenders[] = $name . ' (output_schema differs)';
			}
			if ( ! isset( $meta['mcp']['public'] ) || true !== $meta['mcp']['public'] ) {
				$offenders[] = $name . ' (meta.mcp.public is not true)';
			}
			if ( ! array_key_exists( 'show_in_rest', $meta ) || false !== $meta['show_in_rest'] ) {
				$offenders[] = $name . ' (show_in_rest is not false)';
			}
			$registered_meta = $ability->get_meta();
			if ( ! isset( $meta['annotations'] ) || $this->wire( $registered_meta['annotations'] ) !== $meta['annotations'] ) {
				$offenders[] = $name . ' (annotations differ)';
			}
		}

		$this->assertSame( array(), $offenders, 'Abilities whose contract differs through the adapter: ' . implode( '; ', $offenders ) );
	}

	/* ==================================================================
	 * Execution
	 * ================================================================ */

	public function test_execute_a_read_ability_returns_the_post_as_stored() {
		$admin_id = $this->become( 'administrator' );
		$post_id  = self::factory()->post->create(
			array(
				'post_title'  => 'WordPress MCP E2E read',
				'post_status' => 'publish',
				'post_author' => $admin_id,
			)
		);
		list( $session_id ) = $this->open_session();

		$result = $this->execute_ability( $session_id, 'wp-mcp/get-post', array( 'post_id' => $post_id ) );

		$this->assertTrue( empty( $result['isError'] ), $this->result_text( $result ) );
		$this->assertTrue( $result['structuredContent']['success'] );
		$data = $result['structuredContent']['data'];
		$post = get_post( $post_id );
		$this->assertSame( $post_id, $data['id'] );
		$this->assertSame( $post->post_title, $data['title'] );
		$this->assertSame( $post->post_status, $data['status'] );
		$this->assertSame( (int) $post->post_author, $data['author'] );
	}

	/**
	 * The adapter's execute-ability tool is a generic dispatcher, but it
	 * re-enters the target ability's own permission_callback: a caller who
	 * passes the transport's `read` gate still cannot create a post.
	 */
	public function test_execute_reenters_the_abilitys_own_permission_callback() {
		$this->become( 'subscriber' );
		list( $session_id ) = $this->open_session();

		$result = $this->execute_ability(
			$session_id,
			'wp-mcp/create-post',
			array(
				'title'   => 'WordPress MCP E2E subscriber attempt',
				'content' => 'Must never be written.',
			)
		);

		$this->assertTrue( ! empty( $result['isError'] ), 'A subscriber created a post through the adapter: ' . wp_json_encode( $result ) );
		$this->assertSame( array(), get_posts( array( 'title' => 'WordPress MCP E2E subscriber attempt', 'post_status' => 'any', 'fields' => 'ids' ) ) );
	}

	/**
	 * The closed input schema still applies through the adapter: a
	 * server-decided field smuggled into the parameters is refused, and
	 * nothing is written.
	 */
	public function test_execute_reenters_the_closed_input_schema() {
		$this->become( 'administrator' );
		$other_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		list( $session_id ) = $this->open_session();

		$result = $this->execute_ability(
			$session_id,
			'wp-mcp/create-post',
			array(
				'title'       => 'WordPress MCP E2E smuggled author',
				'content'     => 'Must never be written.',
				'post_author' => $other_id,
			)
		);

		$this->assertTrue( ! empty( $result['isError'] ), 'An extra post_author property was accepted through the adapter: ' . wp_json_encode( $result ) );
		$this->assertSame( array(), get_posts( array( 'title' => 'WordPress MCP E2E smuggled author', 'post_status' => 'any', 'fields' => 'ids' ) ) );
	}

	/* ==================================================================
	 * Mutating workflows (issue #15). Every step goes through the adapter's
	 * execute-ability tool; every check reads WordPress directly.
	 * ================================================================ */

	public function test_workflow_post_create_edit_publish_revision_trash_restore() {
		$admin_id = $this->become( 'administrator' );
		$this->capture_audit_events();
		list( $session_id ) = $this->open_session();

		$this->execute_ok( $session_id, 'wp-mcp/create-post', array( 'title' => 'WordPress MCP E2E post', 'content' => 'First version.' ) );
		$post_id = $this->single_post_id( 'WordPress MCP E2E post', 'post' );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( $admin_id, (int) get_post_field( 'post_author', $post_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/update-post', array( 'post_id' => $post_id, 'title' => 'WordPress MCP E2E post edited', 'content' => 'Second version.' ) );
		$this->assertSame( 'WordPress MCP E2E post edited', get_post_field( 'post_title', $post_id ) );
		$this->assertSame( 'Second version.', get_post_field( 'post_content', $post_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/publish-post', array( 'post_id' => $post_id ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/list-post-revisions', array( 'post_id' => $post_id ) );
		$this->assertNotEmpty( wp_get_post_revisions( $post_id ), 'Editing the post through the adapter left no revision.' );

		$this->execute_ok( $session_id, 'wp-mcp/trash-post', array( 'post_id' => $post_id ) );
		$this->assertSame( 'trash', get_post_status( $post_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/restore-post', array( 'post_id' => $post_id ) );
		$this->assertNotSame( 'trash', get_post_status( $post_id ) );
		$this->assertSame( 'WordPress MCP E2E post edited', get_post_field( 'post_title', $post_id ) );

		$published = $this->audit_events_for( 'wp-mcp/publish-post' );
		$this->assertNotEmpty( $published, 'Publishing through the adapter emitted no audit event.' );
		$this->assertSame( $admin_id, (int) $published[0]['user_id'] );
		$this->assertSame( $post_id, (int) $published[0]['object_id'] );
		$this->assertSame( 'success', $published[0]['result'] );
	}

	public function test_workflow_post_schedule() {
		$this->become( 'administrator' );
		list( $session_id ) = $this->open_session();

		$this->execute_ok( $session_id, 'wp-mcp/create-post', array( 'title' => 'WordPress MCP E2E scheduled post', 'content' => 'Scheduled.' ) );
		$post_id = $this->single_post_id( 'WordPress MCP E2E scheduled post', 'post' );

		$this->execute_ok( $session_id, 'wp-mcp/schedule-post', array( 'post_id' => $post_id, 'date' => gmdate( 'Y-m-d\TH:i:s', time() + WEEK_IN_SECONDS ) ) );

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertGreaterThan( time(), strtotime( get_post_field( 'post_date_gmt', $post_id ) . ' UTC' ) );
		$this->assertNotFalse( wp_next_scheduled( 'publish_future_post', array( $post_id ) ), 'Scheduling through the adapter did not queue publish_future_post.' );
	}

	public function test_workflow_page_create_edit_publish() {
		$this->become( 'administrator' );
		list( $session_id ) = $this->open_session();

		$this->execute_ok( $session_id, 'wp-mcp/create-page', array( 'title' => 'WordPress MCP E2E page', 'content' => 'Page body.' ) );
		$page_id = $this->single_post_id( 'WordPress MCP E2E page', 'page' );
		$this->assertNotSame( 'publish', get_post_status( $page_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/update-page', array( 'page_id' => $page_id, 'title' => 'WordPress MCP E2E page edited' ) );
		$this->assertSame( 'WordPress MCP E2E page edited', get_post_field( 'post_title', $page_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/publish-page', array( 'page_id' => $page_id ) );
		$this->assertSame( 'publish', get_post_status( $page_id ) );
	}

	public function test_workflow_media_upload_metadata_featured_delete() {
		$this->become( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_title' => 'WordPress MCP E2E featured target' ) );
		list( $session_id ) = $this->open_session();

		$this->execute_ok(
			$session_id,
			'wp-mcp/upload-media',
			array(
				'filename'       => 'wp-mcp-e2e.png',
				'content_base64' => self::PNG_1X1,
				'title'          => 'WordPress MCP E2E image',
			)
		);
		$media_id = $this->single_post_id( 'WordPress MCP E2E image', 'attachment' );
		$this->assertSame( 'image/png', get_post_mime_type( $media_id ) );
		$file = get_attached_file( $media_id );
		$this->assertFileExists( $file );

		$this->execute_ok( $session_id, 'wp-mcp/update-media', array( 'media_id' => $media_id, 'alt_text' => 'WordPress MCP E2E alt', 'caption' => 'WordPress MCP E2E caption' ) );
		$this->assertSame( 'WordPress MCP E2E alt', get_post_meta( $media_id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'WordPress MCP E2E caption', get_post_field( 'post_excerpt', $media_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/set-featured-image', array( 'post_id' => $post_id, 'media_id' => $media_id ) );
		$this->assertSame( $media_id, (int) get_post_thumbnail_id( $post_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/delete-media-permanently', array( 'media_id' => $media_id ) );
		$this->assertNull( get_post( $media_id ) );
		$this->assertFileDoesNotExist( $file );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_workflow_term_create_edit_assign_delete() {
		$this->become( 'administrator' );
		$post_id = self::factory()->post->create();
		list( $session_id ) = $this->open_session();

		$this->execute_ok( $session_id, 'wp-mcp/create-term', array( 'taxonomy' => 'category', 'name' => 'WordPress MCP E2E term' ) );
		$term = get_term_by( 'name', 'WordPress MCP E2E term', 'category' );
		$this->assertInstanceOf( 'WP_Term', $term );
		$term_id = (int) $term->term_id;

		$this->execute_ok( $session_id, 'wp-mcp/update-term', array( 'taxonomy' => 'category', 'term_id' => $term_id, 'name' => 'WordPress MCP E2E term edited' ) );
		$this->assertSame( 'WordPress MCP E2E term edited', get_term( $term_id, 'category' )->name );

		$this->execute_ok(
			$session_id,
			'wp-mcp/assign-terms',
			array(
				'taxonomy'    => 'category',
				'object_type' => 'post',
				'object_id'   => $post_id,
				'term_ids'    => array( $term_id ),
			)
		);
		$this->assertContains( $term_id, array_map( 'intval', wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) ) ) );

		$this->execute_ok( $session_id, 'wp-mcp/delete-term', array( 'taxonomy' => 'category', 'term_id' => $term_id ) );
		$this->assertNull( get_term( $term_id, 'category' ) );
		$this->assertNotContains( $term_id, array_map( 'intval', wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) ) ) );
	}

	public function test_workflow_comment_moderation() {
		$this->become( 'administrator' );
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '0',
			)
		);
		list( $session_id ) = $this->open_session();

		foreach ( array(
			'wp-mcp/approve-comment'   => 'approved',
			'wp-mcp/unapprove-comment' => 'unapproved',
			'wp-mcp/mark-comment-spam' => 'spam',
		) as $ability => $status ) {
			$this->execute_ok( $session_id, $ability, array( 'comment_id' => $comment_id ) );
			$this->assertSame( $status, wp_get_comment_status( $comment_id ), $ability );
		}

		$this->execute_ok( $session_id, 'wp-mcp/unspam-comment', array( 'comment_id' => $comment_id ) );
		$this->assertNotSame( 'spam', wp_get_comment_status( $comment_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/trash-comment', array( 'comment_id' => $comment_id ) );
		$this->assertSame( 'trash', wp_get_comment_status( $comment_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/restore-comment', array( 'comment_id' => $comment_id ) );
		$this->assertNotSame( 'trash', wp_get_comment_status( $comment_id ) );
	}

	public function test_workflow_user_and_role_lifecycle() {
		$admin_id = $this->become( 'administrator' );
		list( $session_id ) = $this->open_session();

		$this->execute_ok(
			$session_id,
			'wp-mcp/create-role',
			array(
				'slug'         => self::E2E_ROLE,
				'name'         => 'WordPress MCP E2E role',
				'capabilities' => array( 'read' ),
			)
		);
		$role = get_role( self::E2E_ROLE );
		$this->assertInstanceOf( 'WP_Role', $role );
		$this->assertTrue( $role->has_cap( 'read' ) );

		$this->execute_ok(
			$session_id,
			'wp-mcp/create-user',
			array(
				'username' => 'wp_mcp_e2e_user',
				'email'    => 'wp-mcp-e2e-user@example.org',
				'password' => wp_generate_password( 24 ),
				'role'     => self::E2E_ROLE,
			)
		);
		$user = get_user_by( 'login', 'wp_mcp_e2e_user' );
		$this->assertInstanceOf( 'WP_User', $user );
		$this->assertSame( array( self::E2E_ROLE ), array_values( $user->roles ) );

		$this->execute_ok( $session_id, 'wp-mcp/change-user-role', array( 'user_id' => $user->ID, 'role' => 'author' ) );
		$this->assertSame( array( 'author' ), array_values( ( new WP_User( $user->ID ) )->roles ) );

		$this->execute_ok( $session_id, 'wp-mcp/delete-user', array( 'user_id' => $user->ID, 'reassign_user_id' => $admin_id ) );
		$this->assertFalse( get_userdata( $user->ID ) );

		$this->execute_ok( $session_id, 'wp-mcp/delete-role', array( 'slug' => self::E2E_ROLE ) );
		$this->assertNull( get_role( self::E2E_ROLE ) );
	}

	public function test_workflow_navigation_menu_and_synced_pattern() {
		$this->become( 'administrator' );
		list( $session_id ) = $this->open_session();

		// Classic navigation.
		$this->execute_ok( $session_id, 'wp-mcp/create-nav-menu', array( 'name' => 'WordPress MCP E2E menu' ) );
		$menu = wp_get_nav_menu_object( 'WordPress MCP E2E menu' );
		$this->assertInstanceOf( 'WP_Term', $menu );
		$menu_id = (int) $menu->term_id;

		$this->execute_ok(
			$session_id,
			'wp-mcp/add-menu-item',
			array(
				'menu_id' => $menu_id,
				'title'   => 'WordPress MCP E2E link',
				'type'    => 'custom',
				'url'     => 'https://example.org/wp-mcp-e2e',
			)
		);
		$items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
		$this->assertCount( 1, $items );
		$this->assertSame( 'WordPress MCP E2E link', $items[0]->title );
		$this->assertSame( 'https://example.org/wp-mcp-e2e', $items[0]->url );

		$this->execute_ok( $session_id, 'wp-mcp/delete-nav-menu', array( 'menu_id' => $menu_id ) );
		$this->assertFalse( wp_get_nav_menu_object( $menu_id ) );

		// Site Editor: a synced pattern (wp_block).
		$this->execute_ok( $session_id, 'wp-mcp/create-synced-pattern', array( 'title' => 'WordPress MCP E2E pattern', 'content' => self::BLOCK_MARKUP ) );
		$pattern_id = $this->single_post_id( 'WordPress MCP E2E pattern', 'wp_block' );
		$this->assertStringContainsString( '<p>WordPress MCP E2E pattern</p>', get_post_field( 'post_content', $pattern_id ) );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', get_post_field( 'post_content', $pattern_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/update-synced-pattern', array( 'pattern_id' => $pattern_id, 'title' => 'WordPress MCP E2E pattern edited' ) );
		$this->assertSame( 'WordPress MCP E2E pattern edited', get_post_field( 'post_title', $pattern_id ) );

		$this->execute_ok( $session_id, 'wp-mcp/delete-synced-pattern', array( 'pattern_id' => $pattern_id ) );
		$this->assertNotSame( 'publish', get_post_status( $pattern_id ) );
	}

	public function test_workflow_explicit_settings() {
		$this->become( 'administrator' );
		list( $session_id ) = $this->open_session();

		$this->execute_ok( $session_id, 'wp-mcp/update-general-settings', array( 'site_title' => 'WordPress MCP E2E site' ) );
		$this->assertSame( 'WordPress MCP E2E site', get_option( 'blogname' ) );

		// The option behind the field is never addressable by name.
		$this->execute_refused( $session_id, 'wp-mcp/update-general-settings', array( 'blogname' => 'WordPress MCP E2E smuggled' ) );
		$this->assertSame( 'WordPress MCP E2E site', get_option( 'blogname' ) );
	}

	public function test_workflow_custom_post_type_with_registered_meta() {
		$this->become( 'administrator' );
		register_post_type(
			self::CPT,
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'custom-fields' ),
				'map_meta_cap' => true,
			)
		);
		register_post_meta(
			self::CPT,
			self::CPT_META_KEY,
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
		list( $session_id ) = $this->open_session();

		$this->execute_ok( $session_id, 'wp-mcp/create-custom-post', array( 'post_type' => self::CPT, 'title' => 'WordPress MCP E2E book', 'content' => 'Book body.' ) );
		$post_id = $this->single_post_id( 'WordPress MCP E2E book', self::CPT );
		$this->assertNotSame( 'publish', get_post_status( $post_id ) );

		$this->execute_ok(
			$session_id,
			'wp-mcp/update-custom-post-meta',
			array(
				'post_type' => self::CPT,
				'post_id'   => $post_id,
				'meta_key'  => self::CPT_META_KEY,
				'value'     => '978-3-16-148410-0',
			)
		);
		$this->assertSame( '978-3-16-148410-0', get_post_meta( $post_id, self::CPT_META_KEY, true ) );

		$this->execute_ok( $session_id, 'wp-mcp/publish-custom-post', array( 'post_type' => self::CPT, 'post_id' => $post_id ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		// A protected key is refused before the meta registry is consulted.
		$this->execute_refused(
			$session_id,
			'wp-mcp/update-custom-post-meta',
			array(
				'post_type' => self::CPT,
				'post_id'   => $post_id,
				'meta_key'  => '_wp_mcp_e2e_protected',
				'value'     => 'WordPress MCP E2E smuggled',
			)
		);
		$this->assertSame( '', get_post_meta( $post_id, '_wp_mcp_e2e_protected', true ) );
	}

	/**
	 * Install → activate → deactivate → update → delete a fixture plugin.
	 *
	 * The update comes after the deactivation because core's
	 * Plugin_Upgrader::upgrade() deactivates an active plugin before
	 * replacing it, so the step order would otherwise depend on upgrader
	 * internals rather than on the abilities under test.
	 */
	public function test_workflow_fixture_plugin_install_activate_deactivate_update_delete() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->become( 'administrator' );
		$this->serve_plugin_fixture_offline();
		list( $session_id ) = $this->open_session();

		$this->execute_ok( $session_id, 'wp-mcp/install-plugin-from-repo', array( 'slug' => self::FIXTURE_SLUG ) );
		$this->assertSame( '1.0.0', $this->fixture_version() );

		$this->execute_ok( $session_id, 'wp-mcp/activate-plugin', array( 'plugin_file' => self::FIXTURE_FILE ) );
		$this->assertTrue( is_plugin_active( self::FIXTURE_FILE ) );

		$this->execute_ok( $session_id, 'wp-mcp/deactivate-plugin', array( 'plugin_file' => self::FIXTURE_FILE ) );
		$this->assertFalse( is_plugin_active( self::FIXTURE_FILE ) );

		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => time(),
				'checked'      => array( self::FIXTURE_FILE => '1.0.0' ),
				'response'     => array(
					self::FIXTURE_FILE => (object) array(
						'id'          => 'w.org/plugins/' . self::FIXTURE_SLUG,
						'slug'        => self::FIXTURE_SLUG,
						'plugin'      => self::FIXTURE_FILE,
						'new_version' => '2.0.0',
						'url'         => '',
						'package'     => sprintf( self::FIXTURE_URL, '2.0.0' ),
					),
				),
				'no_update'    => array(),
				'translations' => array(),
			)
		);
		$this->execute_ok( $session_id, 'wp-mcp/update-plugin', array( 'plugin_file' => self::FIXTURE_FILE ) );
		$this->assertSame( '2.0.0', $this->fixture_version() );

		$this->execute_ok( $session_id, 'wp-mcp/delete-plugin', array( 'plugin_file' => self::FIXTURE_FILE ) );
		$this->assertNull( $this->fixture_version() );
		$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/' . self::FIXTURE_SLUG );

		// Core's own post-upgrade update checks were answered locally; no
		// package was ever requested from the network.
		foreach ( $this->http_requests as $url ) {
			$this->assertStringNotContainsString( 'downloads.wordpress.org', $url, 'A plugin package was requested over the network: ' . $url );
		}
	}
}
