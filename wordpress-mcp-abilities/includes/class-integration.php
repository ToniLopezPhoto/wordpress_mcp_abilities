<?php
/**
 * WordPress MCP Abilities — Integration adapter base class.
 *
 * Every third-party plugin this plugin can talk to is represented by one
 * adapter: a class that declares how the plugin is detected, which concrete
 * abilities it contributes, which capabilities those abilities need, and —
 * explicitly — which of the plugin's data stays out of scope.
 *
 * Design constraints (epic #1, issue #14):
 *
 *  - An adapter never exposes a generic bridge. There is no
 *    `call-plugin-function( name, args )`, no REST-route executor, no
 *    action/filter executor and no option/meta reader used to walk around a
 *    plugin's own API. Each ability an adapter registers is a fixed,
 *    individually schema'd operation exactly like every core-domain ability.
 *  - An adapter only ever calls the third-party plugin's documented public
 *    API (see `stubs/integrations.php` for the complete list of third-party
 *    symbols this plugin is allowed to touch), or core APIs applied to that
 *    plugin's own registered post type.
 *  - Abilities are registered only when the integration is actually
 *    available, so an MCP client is never offered a tool that cannot run.
 *  - `excluded_data()` is part of the contract, not a comment: an adapter
 *    states what it deliberately does not expose (submission payloads,
 *    customer addresses, SMTP credentials, API keys), and the README
 *    publishes it.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Integration
 *
 * Base class for every integration adapter.
 */
abstract class WP_MCP_Integration {

	/**
	 * Memoized detection result for this request.
	 *
	 * @var array{available:bool,version:string,detected_by:string,plugin_file:string}|null
	 */
	private $detection = null;

	/**
	 * Memoized `get_plugins()` result, shared by every adapter.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $installed_plugins = null;

	/* ==================================================================
	 * Identity — every adapter declares these
	 * ================================================================ */

	/**
	 * Stable machine-readable adapter slug, e.g. `woocommerce`.
	 *
	 * Also the prefix of every ability the adapter registers.
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Human-readable adapter label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Functional group this integration belongs to.
	 *
	 * One of WP_MCP_Integrations::GROUPS.
	 *
	 * @return string
	 */
	abstract public function group();

	/**
	 * Name of the third-party plugin this adapter detects.
	 *
	 * @return string
	 */
	abstract public function plugin_label();

	/**
	 * Detection signals for the third-party plugin.
	 *
	 * Every key is optional; the first signal that matches wins, and the
	 * order below is the order they are evaluated in:
	 *
	 *  - `constants`    string[] Constant names. A string constant value is
	 *                            also used as the reported version.
	 *  - `classes`      string[] Class names.
	 *  - `functions`    string[] Function names.
	 *  - `plugin_files` string[] Plugin files, checked with `is_plugin_active()`
	 *                            (and its network variant), never merely
	 *                            "installed".
	 *
	 * @return array<string,string[]>
	 */
	abstract public function signals();

	/* ==================================================================
	 * Contract — the defaults describe a detect-only adapter
	 * ================================================================ */

	/**
	 * Permission-matrix rows contributed by this adapter, keyed by the
	 * fully-namespaced ability name.
	 *
	 * Declared whether or not the plugin is installed: this is the
	 * documented surface, and the registry copies it into
	 * `WP_MCP_Ability_Matrix::get()` with an `integration` key so the
	 * consistency test knows the row is conditional.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function ability_matrix() {
		return array();
	}

	/**
	 * Capabilities the adapter's abilities require, for documentation.
	 *
	 * @return string[]
	 */
	public function required_capabilities() {
		return array();
	}

	/**
	 * Data this adapter deliberately never exposes.
	 *
	 * @return string[]
	 */
	public function excluded_data() {
		return array();
	}

	/**
	 * Free-form note published next to the integration in the README and in
	 * `wp-mcp/get-integration`.
	 *
	 * @return string
	 */
	public function notes() {
		return '';
	}

	/**
	 * Register this adapter's abilities.
	 *
	 * Called by the registry only when `detect()` reports the integration as
	 * available, so an implementation never needs to re-check availability
	 * before registering (the callbacks still do, because a plugin can be
	 * deactivated between registration and invocation).
	 */
	public function register_abilities() {}

	/* ==================================================================
	 * Derived contract
	 * ================================================================ */

	/**
	 * Fully-namespaced names of every ability this adapter contributes.
	 *
	 * @return string[]
	 */
	final public function ability_names() {
		return array_keys( $this->ability_matrix() );
	}

	/**
	 * Whether this adapter contributes abilities, as opposed to being
	 * detect-only.
	 *
	 * @return bool
	 */
	final public function is_covered() {
		return array() !== $this->ability_matrix();
	}

	/* ==================================================================
	 * Detection
	 * ================================================================ */

	/**
	 * Detect whether the third-party plugin is available in this request.
	 *
	 * @return array{available:bool,version:string,detected_by:string,plugin_file:string}
	 */
	final public function detect() {
		if ( null !== $this->detection ) {
			return $this->detection;
		}

		$signals = $this->signals();
		$result  = array(
			'available'   => false,
			'version'     => '',
			'detected_by' => '',
			'plugin_file' => '',
		);

		foreach ( $this->signal_list( $signals, 'constants' ) as $constant ) {
			if ( defined( $constant ) ) {
				$value                 = constant( $constant );
				$result['available']   = true;
				$result['detected_by'] = 'constant:' . $constant;
				$result['version']     = self::version_or_empty( $value );
				break;
			}
		}

		if ( ! $result['available'] ) {
			foreach ( $this->signal_list( $signals, 'classes' ) as $class_name ) {
				if ( class_exists( $class_name ) ) {
					$result['available']   = true;
					$result['detected_by'] = 'class:' . $class_name;
					break;
				}
			}
		}

		if ( ! $result['available'] ) {
			foreach ( $this->signal_list( $signals, 'functions' ) as $function_name ) {
				if ( function_exists( $function_name ) ) {
					$result['available']   = true;
					$result['detected_by'] = 'function:' . $function_name;
					break;
				}
			}
		}

		foreach ( $this->signal_list( $signals, 'plugin_files' ) as $plugin_file ) {
			if ( ! self::is_plugin_file_active( $plugin_file ) ) {
				continue;
			}
			$result['plugin_file'] = $plugin_file;
			if ( ! $result['available'] ) {
				$result['available']   = true;
				$result['detected_by'] = 'plugin:' . $plugin_file;
			}
			if ( '' === $result['version'] ) {
				$result['version'] = self::plugin_file_version( $plugin_file );
			}
			break;
		}

		$this->detection = $result;

		return $result;
	}

	/**
	 * Whether the third-party plugin is available in this request.
	 *
	 * @return bool
	 */
	final public function is_available() {
		$detection = $this->detect();

		return (bool) $detection['available'];
	}

	/**
	 * Forget the memoized detection result.
	 *
	 * Only ever needed when a plugin is activated or deactivated inside the
	 * same request, and by the test suite.
	 */
	final public function flush_detection() {
		$this->detection = null;
	}

	/* ==================================================================
	 * Helpers for adapter implementations
	 * ================================================================ */

	/**
	 * Guard an ability callback: the integration must still be available.
	 *
	 * @return WP_Error|null WP_Error when unavailable, null when fine.
	 */
	protected function require_available() {
		if ( $this->is_available() ) {
			return null;
		}

		return WP_MCP_Errors::integration_unavailable(
			sprintf(
				/* translators: %s: third-party plugin name. */
				__( 'The %s integration is not available on this site.', 'wordpress-mcp-abilities' ),
				$this->plugin_label()
			)
		);
	}

	/**
	 * Whether the current user holds at least one of the given capabilities.
	 *
	 * Third-party plugins register their own capabilities (`manage_woocommerce`,
	 * `wpcf7_read_contact_forms`, `wpforms_view_forms`, ...). When none of them
	 * is held, `$fallback` — always an administrator-level core capability —
	 * decides, so an installation that never mapped the plugin's own
	 * capabilities is still operable by an administrator and by nobody else.
	 *
	 * @param string[] $capabilities Plugin-owned capabilities.
	 * @param string   $fallback     Core capability used when none is held.
	 * @return bool
	 */
	protected static function can_any( array $capabilities, $fallback = 'manage_options' ) {
		foreach ( $capabilities as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}

		return current_user_can( $fallback );
	}

	/**
	 * Closed object schema, the shape every ability in this plugin uses.
	 *
	 * @param array<string,mixed> $properties Schema properties.
	 * @param string[]            $required   Required property names.
	 * @return array<string,mixed>
	 */
	protected static function object_schema( $properties, $required = array() ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * Format a date-ish value returned by a third-party getter as ISO 8601.
	 *
	 * WooCommerce returns `WC_DateTime` objects, Gravity Forms returns
	 * strings, and both return null for "never".
	 *
	 * @param mixed $value Raw value.
	 * @return string ISO 8601 string, or '' when there is no date.
	 */
	protected static function format_date( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		if ( $value instanceof DateTimeInterface ) {
			return $value->format( DATE_ATOM );
		}

		if ( is_string( $value ) ) {
			$timestamp = strtotime( $value );

			return false === $timestamp ? '' : gmdate( DATE_ATOM, $timestamp );
		}

		return '';
	}

	/* ==================================================================
	 * Internals
	 * ================================================================ */

	/**
	 * A constant value, but only when it actually looks like a version.
	 *
	 * Several plugins expose a path constant (`TRIBE_EVENTS_FILE`,
	 * `MATOMO_ANALYTICS_FILE`) as their most reliable detection signal.
	 * Reporting a filesystem path as a "version" would be wrong and would
	 * leak the path, so anything that is not version-shaped is dropped.
	 *
	 * @param mixed $value Constant value.
	 * @return string
	 */
	private static function version_or_empty( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return 1 === preg_match( '/^\d+(\.\d+)*([-+][A-Za-z0-9.\-]+)?$/', $value ) ? $value : '';
	}

	/**
	 * Read one signal list out of a `signals()` array.
	 *
	 * @param array<string,mixed> $signals Signals declared by the adapter.
	 * @param string              $key     Signal key.
	 * @return string[]
	 */
	private function signal_list( $signals, $key ) {
		if ( ! isset( $signals[ $key ] ) || ! is_array( $signals[ $key ] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $signals[ $key ] ) ) );
	}

	/**
	 * Whether a plugin file is active on this site or across the network.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 * @return bool
	 */
	private static function is_plugin_file_active( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return true;
		}

		return is_multisite() && is_plugin_active_for_network( $plugin_file );
	}

	/**
	 * Version declared in a plugin file's header.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 * @return string
	 */
	private static function plugin_file_version( $plugin_file ) {
		if ( null === self::$installed_plugins ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			self::$installed_plugins = get_plugins();
		}

		if ( ! isset( self::$installed_plugins[ $plugin_file ]['Version'] ) ) {
			return '';
		}

		return (string) self::$installed_plugins[ $plugin_file ]['Version'];
	}
}
