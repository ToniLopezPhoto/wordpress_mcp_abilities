<?php
/**
 * WordPress MCP Abilities — Yoast SEO integration adapter.
 *
 * Reads and writes the per-post SEO fields through `WPSEO_Meta::get_value()`
 * and `WPSEO_Meta::set_value()` — Yoast's own public accessors, which own the
 * `_yoast_wpseo_*` key naming, the defaults and the sanitisation. This adapter
 * never touches those meta keys directly: a generic meta reader pointed at a
 * plugin's storage is exactly what epic #1 forbids, and it would also silently
 * drift the day Yoast changes a key.
 *
 * The field vocabulary is fixed and closed: `title`, `metadesc`, `focuskw`,
 * `canonical`, the two robots switches and the four social overrides. A caller
 * never supplies a Yoast meta key.
 *
 * What this adapter deliberately does not expose:
 *
 *  - Yoast's site-wide options and its Search Console / Semrush / wincher API
 *    tokens and connected-account data.
 *  - The indexables tables and reindexation, which are Yoast's internal cache.
 *  - Redirects (Yoast Premium): a redirect is a site-routing change and gets
 *    its own explicit issue if it is ever wanted.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Yoast_SEO_Integration
 */
class WP_MCP_Yoast_SEO_Integration extends WP_MCP_Integration {

	/**
	 * Robots index values, as this plugin names them, mapped to the values
	 * Yoast itself stores.
	 */
	const NOINDEX_VALUES = array(
		'default' => '0',
		'index'   => '1',
		'noindex' => '2',
	);

	/**
	 * Text fields: ability field name => Yoast meta key.
	 */
	const TEXT_FIELDS = array(
		'title'                  => 'title',
		'meta_description'       => 'metadesc',
		'focus_keyphrase'        => 'focuskw',
		'opengraph_title'        => 'opengraph-title',
		'opengraph_description'  => 'opengraph-description',
		'twitter_title'          => 'twitter-title',
		'twitter_description'    => 'twitter-description',
		'breadcrumbs_title'      => 'bctitle',
	);

	/**
	 * @return string
	 */
	public function slug() {
		return 'yoast-seo';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Yoast SEO', 'wordpress-mcp-abilities' );
	}

	/**
	 * @return string
	 */
	public function group() {
		return 'seo';
	}

	/**
	 * @return string
	 */
	public function plugin_label() {
		return 'Yoast SEO';
	}

	/**
	 * @return array<string,string[]>
	 */
	public function signals() {
		return array(
			'constants'    => array( 'WPSEO_VERSION' ),
			'classes'      => array( 'WPSEO_Meta' ),
			'plugin_files' => array( 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' ),
		);
	}

	/**
	 * @return string[]
	 */
	public function required_capabilities() {
		return array( 'edit_posts', 'edit_post' );
	}

	/**
	 * @return string[]
	 */
	public function excluded_data() {
		return array(
			'Yoast site-wide options, connected accounts and API tokens.',
			'Indexables and reindexation.',
			'Redirects (Yoast SEO Premium).',
		);
	}

	/**
	 * @return string
	 */
	public function notes() {
		return 'Per-post SEO fields only, through WPSEO_Meta::get_value()/set_value(), gated on edit_post for the target post.';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function ability_matrix() {
		return array(
			'wp-mcp/yoast-seo-get-post-seo'    => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => false,
				'idempotent'      => true,
			),
			'wp-mcp/yoast-seo-update-post-seo' => array(
				'category'        => 'wp-mcp-extensibility',
				'capability'      => 'edit_posts',
				'meta_capability' => 'edit_post (post)',
				'destructive'     => true,
				'idempotent'      => true,
			),
		);
	}

	/* ==================================================================
	 * Registration
	 * ================================================================ */

	/**
	 * Register every Yoast SEO ability.
	 */
	public function register_abilities() {
		$can = function () { return current_user_can( 'edit_posts' ); };

		wp_register_ability( 'wp-mcp/yoast-seo-get-post-seo', array(
			'label'               => __( 'Yoast SEO: Get Post SEO', 'wordpress-mcp-abilities' ),
			'description'         => __( 'Read the Yoast SEO fields of one post: SEO title, meta description, focus keyphrase, canonical, robots switches, social overrides and breadcrumbs title.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array( 'post_id' => array( 'type' => 'integer', 'description' => 'Post ID.', 'minimum' => 1 ) ), array( 'post_id' ) ),
			'output_schema'       => self::seo_schema(),
			'execute_callback'    => array( $this, 'get_post_seo' ),
			'permission_callback' => $can,
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/yoast-seo-update-post-seo', array(
			'label'               => __( 'Yoast SEO: Update Post SEO', 'wordpress-mcp-abilities' ),
			'description'         => __( 'Write one or more Yoast SEO fields of one post. Fields left out are untouched. Idempotent: writing the values a post already has is a no-op success.', 'wordpress-mcp-abilities' ),
			'category'            => 'wp-mcp-extensibility',
			'input_schema'        => self::object_schema( array(
				'post_id'               => array( 'type' => 'integer', 'description' => 'Post ID.', 'minimum' => 1 ),
				'title'                 => array( 'type' => 'string', 'description' => 'SEO title template or literal title.', 'maxLength' => 400 ),
				'meta_description'      => array( 'type' => 'string', 'description' => 'Meta description.', 'maxLength' => 1000 ),
				'focus_keyphrase'       => array( 'type' => 'string', 'description' => 'Focus keyphrase.', 'maxLength' => 200 ),
				'canonical'             => array( 'type' => 'string', 'description' => 'Canonical URL. An empty string clears it.', 'maxLength' => 500 ),
				'robots_noindex'        => array( 'type' => 'string', 'description' => 'Indexing directive.', 'enum' => array( 'default', 'index', 'noindex' ) ),
				'robots_nofollow'       => array( 'type' => 'boolean', 'description' => 'Whether links on the post are nofollow.' ),
				'opengraph_title'       => array( 'type' => 'string', 'description' => 'Open Graph title override.', 'maxLength' => 400 ),
				'opengraph_description' => array( 'type' => 'string', 'description' => 'Open Graph description override.', 'maxLength' => 1000 ),
				'twitter_title'         => array( 'type' => 'string', 'description' => 'Twitter title override.', 'maxLength' => 400 ),
				'twitter_description'   => array( 'type' => 'string', 'description' => 'Twitter description override.', 'maxLength' => 1000 ),
				'breadcrumbs_title'     => array( 'type' => 'string', 'description' => 'Breadcrumbs title.', 'maxLength' => 400 ),
			), array( 'post_id' ) ),
			'output_schema'       => self::seo_schema(),
			'execute_callback'    => array( $this, 'update_post_seo' ),
			'permission_callback' => $can,
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );
	}

	/* ==================================================================
	 * Callbacks
	 * ================================================================ */

	/**
	 * Read the Yoast fields of one post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function get_post_seo( $input = array() ) {
		$post_id = $this->resolve_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return $this->read_fields( $post_id );
	}

	/**
	 * Write Yoast fields on one post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function update_post_seo( $input = array() ) {
		$post_id = $this->resolve_post( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$input   = (array) $input;
		$written = array();

		foreach ( self::TEXT_FIELDS as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			WPSEO_Meta::set_value( $meta_key, sanitize_text_field( (string) $input[ $field ] ), $post_id );
			$written[] = $field;
		}

		if ( array_key_exists( 'canonical', $input ) ) {
			$canonical = trim( (string) $input['canonical'] );
			WPSEO_Meta::set_value( 'canonical', '' === $canonical ? '' : esc_url_raw( $canonical ), $post_id );
			$written[] = 'canonical';
		}

		if ( array_key_exists( 'robots_noindex', $input ) ) {
			$value = (string) $input['robots_noindex'];
			if ( ! isset( self::NOINDEX_VALUES[ $value ] ) ) {
				return WP_MCP_Errors::validation_error( __( 'robots_noindex must be one of: default, index, noindex.', 'wordpress-mcp-abilities' ) );
			}
			WPSEO_Meta::set_value( 'meta-robots-noindex', self::NOINDEX_VALUES[ $value ], $post_id );
			$written[] = 'robots_noindex';
		}

		if ( array_key_exists( 'robots_nofollow', $input ) ) {
			WPSEO_Meta::set_value( 'meta-robots-nofollow', $input['robots_nofollow'] ? '1' : '0', $post_id );
			$written[] = 'robots_nofollow';
		}

		if ( array() === $written ) {
			return WP_MCP_Errors::validation_error( __( 'Provide at least one SEO field to update.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/yoast-seo-update-post-seo', $post_id, true, '', array( 'fields' => $written ) );

		return $this->read_fields( $post_id );
	}

	/* ==================================================================
	 * Internals
	 * ================================================================ */

	/**
	 * Validate availability, the post and `edit_post` on it.
	 *
	 * @param array $input Ability input.
	 * @return int|WP_Error Post ID.
	 */
	private function resolve_post( $input ) {
		$unavailable = $this->require_available();
		if ( is_wp_error( $unavailable ) ) {
			return $unavailable;
		}

		$post_id = isset( $input['post_id'] ) ? $input['post_id'] : 0;
		if ( ! WP_MCP_Permissions::is_strict_positive_int_id( $post_id ) ) {
			return WP_MCP_Errors::validation_error( __( 'post_id must be a positive integer.', 'wordpress-mcp-abilities' ) );
		}

		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'revision' === $post->post_type ) {
			return WP_MCP_Errors::invalid_post();
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to manage the SEO of this post.', 'wordpress-mcp-abilities' ) );
		}

		return $post_id;
	}

	/**
	 * Read every exposed Yoast field of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private function read_fields( $post_id ) {
		$fields = array( 'post_id' => $post_id );

		foreach ( self::TEXT_FIELDS as $field => $meta_key ) {
			$fields[ $field ] = (string) WPSEO_Meta::get_value( $meta_key, $post_id );
		}

		$fields['canonical'] = (string) WPSEO_Meta::get_value( 'canonical', $post_id );

		$noindex               = (string) WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id );
		$named                 = array_flip( self::NOINDEX_VALUES );
		$fields['robots_noindex'] = isset( $named[ $noindex ] ) ? $named[ $noindex ] : 'default';

		$fields['robots_nofollow'] = '1' === (string) WPSEO_Meta::get_value( 'meta-robots-nofollow', $post_id );

		return $fields;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function seo_schema() {
		return self::object_schema( array(
			'post_id'               => array( 'type' => 'integer' ),
			'title'                 => array( 'type' => 'string' ),
			'meta_description'      => array( 'type' => 'string' ),
			'focus_keyphrase'       => array( 'type' => 'string' ),
			'opengraph_title'       => array( 'type' => 'string' ),
			'opengraph_description' => array( 'type' => 'string' ),
			'twitter_title'         => array( 'type' => 'string' ),
			'twitter_description'   => array( 'type' => 'string' ),
			'breadcrumbs_title'     => array( 'type' => 'string' ),
			'canonical'             => array( 'type' => 'string' ),
			'robots_noindex'        => array( 'type' => 'string' ),
			'robots_nofollow'       => array( 'type' => 'boolean' ),
		) );
	}
}
