<?php
/**
 * Documentation-drift gate for WordPress MCP Abilities.
 *
 * Deliberately does NOT extend WP_UnitTestCase and does not need a
 * WordPress install or database: `WP_MCP_Ability_Matrix::get()` is a pure
 * PHP array with no WordPress function calls, so this only needs the class
 * itself loaded. Runs standalone in the CI `docs` job
 * (`vendor/bin/phpunit --configuration phpunit.docs.xml.dist` — a minimal,
 * WP-free config; PHPUnit's bare "--no-configuration <file>" CLI mode
 * requires the class name to match the filename, which a WordPress
 * `test-*.php` file never does), and, redundantly, inside the full `unit`
 * job's normal WP-bootstrapped run via the main phpunit.xml.dist directory
 * scan (which has no such filename/classname restriction).
 *
 * The point: an ability/test count, a matrix key, or a version number that
 * drifts out of sync with what the READMEs claim is a merge-blocking test
 * failure, not something a human has to notice.
 *
 * @package WP_MCP_Agent_Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_MCP_Ability_Matrix' ) ) {
	require_once __DIR__ . '/../includes/class-ability-matrix.php';
}

class WP_MCP_Test_Documentation extends \PHPUnit\Framework\TestCase {

	private static $plugin_header;
	private static $root_readme;
	private static $inner_readme;
	private static $test_abilities_source;
	private static $test_security_source;
	private static $coverage_report;

	public static function setUpBeforeClass(): void {
		self::$plugin_header        = file_get_contents( __DIR__ . '/../wordpress-mcp-abilities.php' );
		self::$root_readme          = file_get_contents( __DIR__ . '/../../README.md' );
		self::$inner_readme         = file_get_contents( __DIR__ . '/../README.md' );
		self::$test_abilities_source = file_get_contents( __DIR__ . '/test-abilities.php' );
		self::$test_security_source  = file_get_contents( __DIR__ . '/test-security.php' );
		self::$coverage_report       = file_get_contents( __DIR__ . '/../../docs/issue-15-coverage-report.md' );
	}

	private function matrix_count() {
		return count( WP_MCP_Ability_Matrix::get() );
	}

	private function actual_test_method_count() {
		return preg_match_all( '/public function test_[A-Za-z0-9_]+\s*\(/', self::$test_abilities_source );
	}

	private function actual_security_test_method_count() {
		return preg_match_all( '/public function test_[A-Za-z0-9_]+\s*\(/', self::$test_security_source );
	}

	public function test_root_readme_ability_count_matches_matrix() {
		$this->assertMatchesRegularExpression( '/Cat.logo de Abilities \(' . $this->matrix_count() . '\)/u', self::$root_readme, 'Root README ability-count header must match count( WP_MCP_Ability_Matrix::get() ).' );
	}

	public function test_inner_readme_ability_count_matches_matrix() {
		$this->assertMatchesRegularExpression( '/Abilities Available \(' . $this->matrix_count() . ' total\)/', self::$inner_readme, 'Inner plugin README ability-count header must match count( WP_MCP_Ability_Matrix::get() ).' );
	}

	public function test_readme_test_count_matches_reflection() {
		$actual = $this->actual_test_method_count();
		$this->assertGreaterThan( 0, $actual, 'Could not find any test_* methods in test-abilities.php — regex or path is broken.' );
		$this->assertMatchesRegularExpression( '/' . $actual . ' (pruebas|test cases)/', self::$root_readme . self::$inner_readme, "Declared test count in the READMEs must match the actual number of test_* methods ({$actual}) in test-abilities.php." );
	}

	/**
	 * The issue #15 cross-cutting suite gets the same drift gate as the main
	 * one. It is counted separately, and deliberately not folded into the
	 * `test-abilities.php` total: that total is the per-domain suite's, and
	 * the READMEs describe the two as distinct.
	 */
	public function test_readme_security_test_count_matches_reflection() {
		$actual = $this->actual_security_test_method_count();
		$this->assertGreaterThan( 0, $actual, 'Could not find any test_* methods in test-security.php — regex or path is broken.' );
		$this->assertMatchesRegularExpression( '/' . $actual . ' (tests|test cases|pruebas)/', self::$root_readme . self::$inner_readme, "Declared security-suite test count in the READMEs must match the actual number of test_* methods ({$actual}) in test-security.php." );
	}

	/**
	 * The check that matters most: every ability the matrix knows about
	 * must appear, fully namespaced, at least once in the inner plugin
	 * README's catalog — not just as a bare, unprefixed name in prose.
	 */
	public function test_every_matrix_ability_is_documented_in_inner_readme() {
		$missing = array();
		foreach ( array_keys( WP_MCP_Ability_Matrix::get() ) as $ability_name ) {
			if ( false === strpos( self::$inner_readme, $ability_name ) ) {
				$missing[] = $ability_name;
			}
		}
		$this->assertEmpty( $missing, 'Abilities missing from wordpress-mcp-abilities/README.md: ' . implode( ', ', $missing ) );
	}

	/**
	 * The issue #15 functional-coverage report (docs/issue-15-coverage-report.md)
	 * states how many abilities each matrix category holds. Those numbers are
	 * the report's claim of coverage against wp-admin, so they must be the
	 * matrix's, category by category, not a hand count that drifts.
	 */
	public function test_coverage_report_category_counts_match_matrix() {
		$expected         = array();
		$integration_rows = 0;
		foreach ( WP_MCP_Ability_Matrix::get() as $row ) {
			if ( isset( $row['integration'] ) ) {
				++$integration_rows;
				continue;
			}
			$expected[ $row['category'] ] = isset( $expected[ $row['category'] ] ) ? $expected[ $row['category'] ] + 1 : 1;
		}

		preg_match_all( '/^\| `(wp-mcp-[a-z-]+)` \| (\d+) \|\r?$/m', self::$coverage_report, $matches, PREG_SET_ORDER );
		$reported = array();
		foreach ( $matches as $match ) {
			$reported[ $match[1] ] = (int) $match[2];
		}
		ksort( $expected );
		ksort( $reported );

		$this->assertSame( $expected, $reported, 'Per-category counts in docs/issue-15-coverage-report.md must match WP_MCP_Ability_Matrix::get().' );
		$this->assertMatchesRegularExpression( '/^\| integration adapters \(rows with an `integration` key\) \| ' . $integration_rows . ' \|\r?$/m', self::$coverage_report, 'Integration-row count in the coverage report must match the matrix.' );
		$this->assertMatchesRegularExpression( '/^\| \*\*Total\*\* \| \*\*' . $this->matrix_count() . '\*\* \|\r?$/m', self::$coverage_report, 'Total in the coverage report must match count( WP_MCP_Ability_Matrix::get() ).' );
	}

	/**
	 * Every ability the coverage report names exists: the report cannot
	 * claim or exclude an ability the plugin does not have.
	 */
	public function test_coverage_report_names_only_real_abilities() {
		preg_match_all( '#wp-mcp/[a-z0-9-]+#', self::$coverage_report, $matches );
		$this->assertNotEmpty( $matches[0], 'The coverage report names no ability — regex or path is broken.' );

		$unknown = array_values( array_diff( array_unique( $matches[0] ), array_keys( WP_MCP_Ability_Matrix::get() ) ) );
		$this->assertSame( array(), $unknown, 'docs/issue-15-coverage-report.md names abilities that are not in the matrix: ' . implode( ', ', $unknown ) );
	}

	public function test_version_is_coherent_across_header_constant_and_readmes() {
		$this->assertMatchesRegularExpression( '/\* Version:\s+(\d+\.\d+\.\d+)/', self::$plugin_header, 'Plugin header must declare a Version.' );
		preg_match( '/\* Version:\s+(\d+\.\d+\.\d+)/', self::$plugin_header, $header_match );
		preg_match( '/define\(\s*\'WP_MCP_VERSION\',\s*\'(\d+\.\d+\.\d+)\'\s*\)/', self::$plugin_header, $constant_match );

		$this->assertNotEmpty( $constant_match, 'WP_MCP_VERSION constant definition not found.' );
		$this->assertEquals( $header_match[1], $constant_match[1], 'Plugin header Version and WP_MCP_VERSION constant must match.' );

		$this->assertStringContainsString( '**Version:** ' . $header_match[1], self::$inner_readme, 'Inner plugin README "Version:" line must match the plugin header.' );
	}
}
