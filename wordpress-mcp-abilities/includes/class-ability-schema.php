<?php
/**
 * WordPress MCP Abilities — Shared ability schema helpers.
 *
 * Reusable building blocks for ability registration: MCP meta/annotations,
 * and pagination (schema fragments plus input clamping).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Ability_Schema
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Ability_Schema {

	/* ------------------------------------------------------------------
	 * Meta / annotations
	 * ---------------------------------------------------------------- */

	/**
	 * Build the meta array for a read-only ability.
	 *
	 * @return array
	 */
	public static function meta_readonly() {
		return array(
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
			'show_in_rest' => false,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Build the meta array for a write ability.
	 *
	 * @param bool $destructive Whether the ability overwrites existing data.
	 * @param bool $idempotent  Whether repeated calls produce the same result.
	 * @return array
	 */
	public static function meta_write( $destructive, $idempotent ) {
		return array(
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
			'show_in_rest' => false,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Pagination — schema fragments
	 * ---------------------------------------------------------------- */

	/**
	 * Input schema properties shared by every paginated list ability.
	 *
	 * @return array
	 */
	public static function pagination_input_properties() {
		return array(
			'page'     => array(
				'type'        => 'integer',
				'description' => 'Page number (min 1).',
				'minimum'     => 1,
				'default'     => 1,
			),
			'per_page' => array(
				'type'        => 'integer',
				'description' => 'Results per page (1–50).',
				'minimum'     => 1,
				'maximum'     => 50,
				'default'     => 10,
			),
		);
	}

	/**
	 * Output schema properties shared by every paginated list ability.
	 *
	 * @return array
	 */
	public static function pagination_output_properties() {
		return array(
			'total'       => array( 'type' => 'integer' ),
			'total_pages' => array( 'type' => 'integer' ),
			'page'        => array( 'type' => 'integer' ),
			'per_page'    => array( 'type' => 'integer' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Pagination — input clamping
	 * ---------------------------------------------------------------- */

	/**
	 * Clamp raw pagination input into a safe (page, per_page) pair.
	 *
	 * @param array $input            Raw ability input.
	 * @param int   $default_per_page Default per_page when unset.
	 * @param int   $max_per_page     Maximum allowed per_page.
	 * @return array{0:int,1:int} array( $page, $per_page ).
	 */
	public static function paginate( array $input, $default_per_page = 10, $max_per_page = 50 ) {
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : $default_per_page;
		$per_page = max( 1, min( $max_per_page, $per_page ) );

		$page = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$page = max( 1, $page );

		return array( $page, $per_page );
	}
}
