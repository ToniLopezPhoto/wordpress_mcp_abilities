<?php
/**
 * PHPUnit bootstrap for WordPress MCP Abilities.
 *
 * Loads the WordPress PHPUnit test scaffold, then — on `muplugins_loaded` —
 * optionally boots a checked-out copy of WordPress/mcp-adapter and always
 * fakes it "active" via an `option_active_plugins` filter before loading this
 * plugin. The domain suites never call into mcp-adapter's own code: this
 * plugin's runtime guard (`is_plugin_active( 'mcp-adapter/mcp-adapter.php' )`)
 * only needs the option to report the plugin as active, and everything this
 * plugin registers hangs off `wp_abilities_api_init`, not an mcp-adapter API.
 * Faking the option instead of defining a bypass constant in the production
 * guard keeps that guard identical to what runs on a real site.
 *
 * Only the MCP end-to-end suite (tests/e2e-mcp-adapter.php, run by
 * phpunit.e2e.xml.dist) drives the adapter itself, and only when
 * MCP_ADAPTER_DIR points at a checkout whose Composer dependencies are
 * installed — see wp_mcp_tests_maybe_load_mcp_adapter().
 *
 * @package WP_MCP_Agent_Abilities
 */

$wp_mcp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_mcp_tests_dir ) {
	$wp_mcp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $wp_mcp_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find {$wp_mcp_tests_dir}/includes/functions.php — set WP_TESTS_DIR or run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

require_once $wp_mcp_tests_dir . '/includes/functions.php';

/**
 * Boot a checked-out mcp-adapter if CI (or a local dev) pointed us at one.
 *
 * A bare checkout boots nothing: the adapter's release tags ship no vendor/,
 * and mcp-adapter.php returns early when Autoloader::autoload() cannot find
 * vendor/autoload_packages.php. Its `WP\MCP\*` classes and its
 * `wordpress/php-mcp-schema` dependency are only reachable through Composer,
 * so `composer install --no-dev` must have run inside the checkout (the CI
 * `e2e-adapter` job does). When it has, Composer's autoloader is loaded
 * before mcp-adapter.php, exactly as the adapter's own
 * tests/phpunit/bootstrap.php does.
 *
 * Outside a REST request the adapter only initialises on `rest_api_init`,
 * while its WP-CLI branch initialises on `init` at priority 20 instead
 * (McpAdapter::instance()). The same `init` hook is used here, so the
 * adapter's default abilities and default server are registered before any
 * test first instantiates the abilities registry: once
 * `wp_abilities_api_init` has fired, the adapter could never register its
 * three tools.
 */
function wp_mcp_tests_maybe_load_mcp_adapter() {
	$mcp_adapter_dir = getenv( 'MCP_ADAPTER_DIR' );
	if ( ! $mcp_adapter_dir ) {
		return;
	}
	$mcp_adapter_dir = rtrim( $mcp_adapter_dir, '/\\' );

	if ( file_exists( $mcp_adapter_dir . '/vendor/autoload.php' ) ) {
		require_once $mcp_adapter_dir . '/vendor/autoload.php';
	}
	if ( file_exists( $mcp_adapter_dir . '/mcp-adapter.php' ) ) {
		require_once $mcp_adapter_dir . '/mcp-adapter.php';
	}

	if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
		return;
	}
	add_action( 'init', array( \WP\MCP\Core\McpAdapter::instance(), 'init' ), 20 );
}

/**
 * Fake mcp-adapter as active for is_plugin_active(), and load this plugin.
 */
function wp_mcp_tests_manually_load_plugin() {
	wp_mcp_tests_maybe_load_mcp_adapter();

	add_filter( 'option_active_plugins', function ( $active_plugins ) {
		$active_plugins[] = 'mcp-adapter/mcp-adapter.php';
		return array_unique( $active_plugins );
	} );

	require dirname( __DIR__ ) . '/wordpress-mcp-abilities.php';

	if ( getenv( 'WP_MCP_REQUIRE_ABILITIES_API' ) && ! function_exists( 'wp_register_ability' ) ) {
		fwrite( STDERR, "WP_MCP_REQUIRE_ABILITIES_API=1 but wp_register_ability() is not defined — the WordPress Abilities API is missing from this WordPress version. Failing hard instead of letting the suite report a silent, meaningless green.\n" );
		exit( 1 );
	}

	if ( getenv( 'WP_MCP_REQUIRE_MCP_ADAPTER' ) && ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
		fwrite( STDERR, "WP_MCP_REQUIRE_MCP_ADAPTER=1 but WordPress/mcp-adapter did not boot from MCP_ADAPTER_DIR. Its release tags ship no vendor/ and mcp-adapter.php returns early without vendor/autoload_packages.php: run `composer install --no-dev` inside that checkout. Failing hard instead of skipping the MCP end-to-end suite.\n" );
		exit( 1 );
	}
}
tests_add_filter( 'muplugins_loaded', 'wp_mcp_tests_manually_load_plugin' );

require $wp_mcp_tests_dir . '/includes/bootstrap.php';
