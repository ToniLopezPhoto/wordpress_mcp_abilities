<?php
/**
 * WordPress MCP Abilities — Declarative detect-only integration adapter.
 *
 * Most of the priority integrations of issue #14 are *detectable* long
 * before anybody has decided which of their operations deserve an ability:
 * that decision is made per plugin, against a real production inventory, in
 * the follow-up issues. This adapter covers that half of the contract — it
 * answers "is this plugin here, which version, how did we know" and nothing
 * else — so `wp-mcp/list-integrations` can report the honest difference
 * between what is detectable and what is actually covered.
 *
 * A detect-only adapter registers no abilities by construction: it has no
 * `ability_matrix()`, so the registry has nothing to register for it. It can
 * never become a generic bridge into the plugin it detects.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Generic_Integration
 */
class WP_MCP_Generic_Integration extends WP_MCP_Integration {

	/**
	 * Declarative definition supplied by the registry manifest.
	 *
	 * @var array<string,mixed>
	 */
	private $definition;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $definition Keys: slug, label, group,
	 *                                        plugin_label, signals, and
	 *                                        optionally excluded_data, notes.
	 */
	public function __construct( array $definition ) {
		$this->definition = $definition;
	}

	/**
	 * @return string
	 */
	public function slug() {
		return isset( $this->definition['slug'] ) ? (string) $this->definition['slug'] : '';
	}

	/**
	 * @return string
	 */
	public function label() {
		return isset( $this->definition['label'] ) ? (string) $this->definition['label'] : $this->slug();
	}

	/**
	 * @return string
	 */
	public function group() {
		return isset( $this->definition['group'] ) ? (string) $this->definition['group'] : '';
	}

	/**
	 * @return string
	 */
	public function plugin_label() {
		return isset( $this->definition['plugin_label'] ) ? (string) $this->definition['plugin_label'] : $this->label();
	}

	/**
	 * @return array<string,string[]>
	 */
	public function signals() {
		return isset( $this->definition['signals'] ) && is_array( $this->definition['signals'] ) ? $this->definition['signals'] : array();
	}

	/**
	 * @return string[]
	 */
	public function excluded_data() {
		return isset( $this->definition['excluded_data'] ) && is_array( $this->definition['excluded_data'] ) ? $this->definition['excluded_data'] : array();
	}

	/**
	 * @return string
	 */
	public function notes() {
		return isset( $this->definition['notes'] ) ? (string) $this->definition['notes'] : '';
	}
}
