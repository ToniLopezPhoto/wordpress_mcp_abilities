<?php
/**
 * WordPress MCP Abilities — Explicit site settings callbacks.
 *
 * Covers Settings > General / Writing / Reading / Discussion / Media /
 * Permalinks / Privacy through a declarative, hand-maintained allowlist.
 *
 * Design constraints (epic #1, issue #10):
 *
 *  - There is no `get-option(key)` / `update-option(key)`. Clients never
 *    supply a WordPress option name. They address *fields* from a fixed,
 *    per-group vocabulary (`site_title`, `posts_per_page`, ...) which this
 *    file maps server-side to option names. A field that is not in the
 *    group's allowlist is rejected before anything is read or written.
 *  - Every field declares its own type, range, and — where applicable —
 *    its enum of accepted values. Validation happens before the write, not
 *    as a side effect of it.
 *  - No option that stores a secret is reachable. `never_writable_options()`
 *    is a second, independent gate applied at write time so a future bad
 *    allowlist entry cannot silently open one up.
 *  - Nothing here touches the filesystem, executes PHP/SQL, or dispatches on
 *    a caller-supplied action name.
 *
 * Deliberate exclusions, each for a stated reason rather than an oversight:
 *
 *  - `admin_email` is readable but not writable. WordPress only changes it
 *    through an emailed confirmation link (`new_admin_email` plus the
 *    wp-admin-only `update_option_new_admin_email` handler); writing the
 *    option directly would bypass that confirmation, which is a site
 *    takeover control, and writing `new_admin_email` outside wp-admin sends
 *    no email at all.
 *  - `siteurl` / `home` are readable but not writable: changing either can
 *    lock every user out of the site and is a redirect/takeover vector, and
 *    both are frequently pinned in `wp-config.php` anyway.
 *  - Post via e-mail (`mailserver_url` / `mailserver_login` /
 *    `mailserver_pass` / `mailserver_port`) is not exposed at all: the group
 *    stores a plaintext mail-server password, and issue #10 forbids
 *    surfacing secrets held in options.
 *  - `upload_path` / `upload_url_path` (Settings > Media, "Store uploads in
 *    this folder") are not exposed: they are filesystem path configuration,
 *    which this plugin never accepts from an MCP client.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.10.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Settings
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Settings {

	/**
	 * Maximum accepted size, in bytes, of a free-text settings blob
	 * (comment moderation / disallowed key lists).
	 */
	const MAX_TEXT_BLOCK_BYTES = 8192;

	/**
	 * Maximum number of Update Services (ping) URLs accepted in one write.
	 */
	const MAX_UPDATE_SERVICES = 20;

	/**
	 * Rewrite tags accepted inside a permalink structure.
	 *
	 * A fixed allowlist rather than WP_Rewrite's dynamic `$rewritecode`,
	 * so a plugin-registered rewrite tag cannot widen what an MCP client
	 * may put into the structure.
	 *
	 * @var string[]
	 */
	const PERMALINK_TAGS = array( 'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'post_id', 'postname', 'category', 'author' );

	/**
	 * Capabilities that disqualify a role from becoming the default role for
	 * new registrations. Setting any of these as the default would turn
	 * open registration into privilege escalation.
	 *
	 * @var string[]
	 */
	const DEFAULT_ROLE_FORBIDDEN_CAPS = array( 'manage_options', 'edit_users', 'create_users', 'delete_users', 'promote_users', 'install_plugins', 'activate_plugins', 'edit_plugins', 'install_themes', 'switch_themes', 'edit_themes', 'edit_files', 'unfiltered_html', 'update_core', 'manage_network' );

	/* ==================================================================
	 * Allowlist
	 * ================================================================ */

	/**
	 * Every settings group: its required capability and its field allowlist.
	 *
	 * @return array<string,array{capability:string,label:string,fields:array<string,array<string,mixed>>}>
	 */
	public static function groups() {
		return array(
			'general'    => array(
				'capability' => 'manage_options',
				'label'      => 'General',
				'fields'     => self::general_fields(),
			),
			'writing'    => array(
				'capability' => 'manage_options',
				'label'      => 'Writing',
				'fields'     => self::writing_fields(),
			),
			'reading'    => array(
				'capability' => 'manage_options',
				'label'      => 'Reading',
				'fields'     => self::reading_fields(),
			),
			'discussion' => array(
				'capability' => 'manage_options',
				'label'      => 'Discussion',
				'fields'     => self::discussion_fields(),
			),
			'media'      => array(
				'capability' => 'manage_options',
				'label'      => 'Media',
				'fields'     => self::media_fields(),
			),
			'permalinks' => array(
				'capability' => 'manage_options',
				'label'      => 'Permalinks',
				'fields'     => self::permalink_fields(),
			),
			'privacy'    => array(
				'capability' => 'manage_privacy_options',
				'label'      => 'Privacy',
				'fields'     => self::privacy_fields(),
			),
		);
	}

	/**
	 * Slugs of every settings group, in catalog order.
	 *
	 * @return string[]
	 */
	public static function group_slugs() {
		return array_keys( self::groups() );
	}

	/**
	 * Option names this domain must never write, whatever the allowlist says.
	 *
	 * Enforced at write time (see write_field()) as defence in depth, and
	 * asserted against the allowlist by the test suite.
	 *
	 * @return string[]
	 */
	public static function never_writable_options() {
		return array(
			'admin_email',
			'new_admin_email',
			'siteurl',
			'home',
			'mailserver_url',
			'mailserver_login',
			'mailserver_pass',
			'mailserver_port',
			'active_plugins',
			'template',
			'stylesheet',
			'current_theme',
			'upload_path',
			'upload_url_path',
			'user_roles',
			'wp_user_roles',
			'cron',
			'db_version',
			'initial_db_version',
			'rewrite_rules',
			'recently_edited',
			'uninstall_plugins',
			'secret_key',
			'auth_salt',
		);
	}

	/**
	 * Every option name reachable for writing through the allowlist.
	 *
	 * @return string[]
	 */
	public static function writable_option_keys() {
		$keys = array();
		foreach ( self::groups() as $definition ) {
			foreach ( $definition['fields'] as $spec ) {
				if ( empty( $spec['readonly'] ) && isset( $spec['option'] ) ) {
					$keys[] = $spec['option'];
				}
			}
		}
		// The timezone field writes this pair through its own handler.
		$keys[] = 'timezone_string';
		$keys[] = 'gmt_offset';

		return array_values( array_unique( $keys ) );
	}

	/* ------------------------------------------------------------------
	 * Field definitions — Settings > General
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function general_fields() {
		return array(
			'site_title'         => array(
				'option'      => 'blogname',
				'type'        => 'string',
				'max_length'  => 200,
				'description' => 'Site title.',
			),
			'tagline'            => array(
				'option'      => 'blogdescription',
				'type'        => 'string',
				'max_length'  => 200,
				'description' => 'Site tagline (short description).',
			),
			'admin_email'        => array(
				'option'      => 'admin_email',
				'type'        => 'string',
				'readonly'    => true,
				'description' => 'Site administration email. Read-only: WordPress changes it only through an emailed confirmation link, and writing the option directly would bypass that confirmation.',
			),
			'site_url'           => array(
				'option'      => 'siteurl',
				'type'        => 'string',
				'readonly'    => true,
				'description' => 'WordPress address (URL). Read-only: changing it can lock every user out of the site.',
			),
			'home_url'           => array(
				'option'      => 'home',
				'type'        => 'string',
				'readonly'    => true,
				'description' => 'Site address (URL). Read-only: changing it can lock every user out of the site.',
			),
			'timezone'           => array(
				'handler'     => 'timezone',
				'type'        => 'string',
				'max_length'  => 64,
				'description' => 'Timezone as a PHP timezone identifier ("Europe/Madrid") or a manual UTC offset ("UTC+2"). Writing it sets timezone_string and gmt_offset together, exactly as wp-admin does.',
			),
			'timezone_string'    => array(
				'option'      => 'timezone_string',
				'type'        => 'string',
				'readonly'    => true,
				'description' => 'Resolved PHP timezone identifier, empty when a manual UTC offset is in use. Read-only: write the timezone field instead.',
			),
			'gmt_offset'         => array(
				'option'      => 'gmt_offset',
				'type'        => 'number',
				'readonly'    => true,
				'description' => 'Resolved manual UTC offset in hours. Read-only: write the timezone field instead.',
			),
			'date_format'        => array(
				'option'      => 'date_format',
				'type'        => 'format_string',
				'max_length'  => 40,
				'description' => 'PHP date() format string used for dates, e.g. "F j, Y".',
			),
			'time_format'        => array(
				'option'      => 'time_format',
				'type'        => 'format_string',
				'max_length'  => 40,
				'description' => 'PHP date() format string used for times, e.g. "g:i a".',
			),
			'week_starts_on'     => array(
				'option'      => 'start_of_week',
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 6,
				'description' => 'First day of the week: 0 (Sunday) through 6 (Saturday).',
			),
			'language'           => array(
				'handler'     => 'locale',
				'option'      => 'WPLANG',
				'type'        => 'string',
				'max_length'  => 20,
				'description' => 'Site language: "en_US", or any locale already installed on this site (get_available_languages()). Installing new language packs is out of scope.',
			),
			'users_can_register' => array(
				'option'          => 'users_can_register',
				'type'            => 'boolean',
				'network_managed' => true,
				'description'     => 'Whether anyone can register an account. Not writable on multisite, where registration is a network-level setting.',
			),
			'default_role'       => array(
				'handler'     => 'default_role',
				'option'      => 'default_role',
				'type'        => 'string',
				'max_length'  => 60,
				'description' => 'Role assigned to newly registered users. Refused for any role holding an administrative capability.',
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Field definitions — Settings > Writing
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function writing_fields() {
		return array(
			'default_category'    => array(
				'handler'     => 'default_category',
				'option'      => 'default_category',
				'type'        => 'integer',
				'min'         => 1,
				'description' => 'Term ID of the default post category. Must be an existing term in the category taxonomy.',
			),
			'default_post_format' => array(
				'handler'     => 'default_post_format',
				'option'      => 'default_post_format',
				'type'        => 'string',
				'max_length'  => 20,
				'description' => 'Default post format: "standard", or a format the active theme declares support for.',
			),
			'update_services'     => array(
				'handler'     => 'update_services',
				'option'      => 'ping_sites',
				'type'        => 'url_array',
				'max_items'   => self::MAX_UPDATE_SERVICES,
				'description' => 'Update Services (ping) endpoints notified when a post is published. HTTP(S) URLs only; literal private or reserved IP hosts are refused.',
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Field definitions — Settings > Reading
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function reading_fields() {
		return array(
			'show_on_front'         => array(
				'option'      => 'show_on_front',
				'type'        => 'enum',
				'enum'        => array( 'posts', 'page' ),
				'description' => 'What the front page displays: latest posts, or a static page.',
			),
			'page_on_front'         => array(
				'handler'     => 'page_reference',
				'option'      => 'page_on_front',
				'type'        => 'integer',
				'min'         => 0,
				'description' => 'Page ID used as the static front page, or 0 for none. Must be an existing page.',
			),
			'page_for_posts'        => array(
				'handler'     => 'page_reference',
				'option'      => 'page_for_posts',
				'type'        => 'integer',
				'min'         => 0,
				'description' => 'Page ID used as the posts page, or 0 for none. Must be an existing page.',
			),
			'posts_per_page'        => array(
				'option'      => 'posts_per_page',
				'type'        => 'integer',
				'min'         => 1,
				'max'         => 100,
				'description' => 'Blog pages show at most this many posts.',
			),
			'posts_per_rss'         => array(
				'option'      => 'posts_per_rss',
				'type'        => 'integer',
				'min'         => 1,
				'max'         => 100,
				'description' => 'Syndication feeds show the most recent this many items.',
			),
			'rss_use_excerpt'       => array(
				'option'      => 'rss_use_excerpt',
				'type'        => 'boolean',
				'description' => 'Whether feeds show the excerpt (true) instead of the full text (false).',
			),
			'search_engine_visible' => array(
				'option'      => 'blog_public',
				'type'        => 'boolean',
				'description' => 'Whether search engines are allowed to index the site. False is wp-admin\'s "Discourage search engines from indexing this site".',
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Field definitions — Settings > Discussion
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function discussion_fields() {
		return array(
			'default_pingback_flag'       => array(
				'option'      => 'default_pingback_flag',
				'type'        => 'boolean',
				'description' => 'Attempt to notify any blogs linked to from a new post.',
			),
			'default_ping_status'         => array(
				'option'      => 'default_ping_status',
				'type'        => 'enum',
				'enum'        => array( 'open', 'closed' ),
				'description' => 'Whether new posts accept pingbacks and trackbacks by default.',
			),
			'default_comment_status'      => array(
				'option'      => 'default_comment_status',
				'type'        => 'enum',
				'enum'        => array( 'open', 'closed' ),
				'description' => 'Whether new posts accept comments by default.',
			),
			'require_name_email'          => array(
				'option'      => 'require_name_email',
				'type'        => 'boolean',
				'description' => 'Comment author must supply a name and email address.',
			),
			'comment_registration'        => array(
				'option'      => 'comment_registration',
				'type'        => 'boolean',
				'description' => 'Users must be registered and logged in to comment.',
			),
			'close_comments_for_old_posts' => array(
				'option'      => 'close_comments_for_old_posts',
				'type'        => 'boolean',
				'description' => 'Automatically close comments on old posts.',
			),
			'close_comments_days_old'     => array(
				'option'      => 'close_comments_days_old',
				'type'        => 'integer',
				'min'         => 1,
				'max'         => 3650,
				'description' => 'Age in days after which comments close, when close_comments_for_old_posts is enabled.',
			),
			'thread_comments'             => array(
				'option'      => 'thread_comments',
				'type'        => 'boolean',
				'description' => 'Enable threaded (nested) comments.',
			),
			'thread_comments_depth'       => array(
				'option'      => 'thread_comments_depth',
				'type'        => 'integer',
				'min'         => 2,
				'max'         => 10,
				'description' => 'Maximum nesting depth for threaded comments.',
			),
			'page_comments'               => array(
				'option'      => 'page_comments',
				'type'        => 'boolean',
				'description' => 'Break comments into pages.',
			),
			'comments_per_page'           => array(
				'option'      => 'comments_per_page',
				'type'        => 'integer',
				'min'         => 1,
				'max'         => 200,
				'description' => 'Top-level comments per page.',
			),
			'default_comments_page'       => array(
				'option'      => 'default_comments_page',
				'type'        => 'enum',
				'enum'        => array( 'newest', 'oldest' ),
				'description' => 'Which page of comments is displayed by default.',
			),
			'comment_order'               => array(
				'option'      => 'comment_order',
				'type'        => 'enum',
				'enum'        => array( 'asc', 'desc' ),
				'description' => 'Order comments are displayed in within a page.',
			),
			'comments_notify'             => array(
				'option'      => 'comments_notify',
				'type'        => 'boolean',
				'description' => 'Email the site owner when anyone posts a comment.',
			),
			'moderation_notify'           => array(
				'option'      => 'moderation_notify',
				'type'        => 'boolean',
				'description' => 'Email the site owner when a comment is held for moderation.',
			),
			'comment_moderation'          => array(
				'option'      => 'comment_moderation',
				'type'        => 'boolean',
				'description' => 'Hold every comment for manual approval.',
			),
			'comment_previously_approved' => array(
				'option'      => 'comment_previously_approved',
				'type'        => 'boolean',
				'description' => 'Auto-approve comments from authors with a previously approved comment.',
			),
			'comment_max_links'           => array(
				'option'      => 'comment_max_links',
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 100,
				'description' => 'Hold a comment containing more than this many links.',
			),
			'moderation_keys'             => array(
				'option'      => 'moderation_keys',
				'type'        => 'text_block',
				'audit'       => 'omit',
				'description' => 'Newline-separated words/IPs that send a comment to moderation. Never echoed into the audit log.',
			),
			'disallowed_keys'             => array(
				'option'      => 'disallowed_keys',
				'type'        => 'text_block',
				'audit'       => 'omit',
				'description' => 'Newline-separated words/IPs that send a comment to trash. Never echoed into the audit log.',
			),
			'show_avatars'                => array(
				'option'      => 'show_avatars',
				'type'        => 'boolean',
				'description' => 'Show avatars alongside comments.',
			),
			'avatar_rating'               => array(
				'option'      => 'avatar_rating',
				'type'        => 'enum',
				'enum'        => array( 'G', 'PG', 'R', 'X' ),
				'description' => 'Maximum avatar maturity rating displayed.',
			),
			'avatar_default'              => array(
				'option'      => 'avatar_default',
				'type'        => 'enum',
				'enum'        => array( 'mystery', 'blank', 'gravatar_default', 'identicon', 'wavatar', 'monsterid', 'retro' ),
				'description' => 'Default avatar shown for users without a custom one. A fixed allowlist: avatars contributed by other plugins are not selectable here.',
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Field definitions — Settings > Media
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function media_fields() {
		return array(
			'thumbnail_size_w'              => array(
				'option'      => 'thumbnail_size_w',
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 3000,
				'description' => 'Thumbnail width in pixels.',
			),
			'thumbnail_size_h'              => array(
				'option'      => 'thumbnail_size_h',
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 3000,
				'description' => 'Thumbnail height in pixels.',
			),
			'thumbnail_crop'                => array(
				'option'      => 'thumbnail_crop',
				'type'        => 'boolean',
				'description' => 'Crop thumbnails to exact dimensions instead of scaling proportionally.',
			),
			'medium_size_w'                 => array(
				'option'      => 'medium_size_w',
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 5000,
				'description' => 'Medium size maximum width in pixels.',
			),
			'medium_size_h'                 => array(
				'option'      => 'medium_size_h',
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 5000,
				'description' => 'Medium size maximum height in pixels.',
			),
			'large_size_w'                  => array(
				'option'      => 'large_size_w',
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 5000,
				'description' => 'Large size maximum width in pixels.',
			),
			'large_size_h'                  => array(
				'option'      => 'large_size_h',
				'type'        => 'integer',
				'min'         => 0,
				'max'         => 5000,
				'description' => 'Large size maximum height in pixels.',
			),
			'uploads_use_yearmonth_folders' => array(
				'option'      => 'uploads_use_yearmonth_folders',
				'type'        => 'boolean',
				'description' => 'Organize uploads into month- and year-based folders.',
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Field definitions — Settings > Permalinks
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function permalink_fields() {
		return array(
			'permalink_structure' => array(
				'handler'     => 'permalink_structure',
				'option'      => 'permalink_structure',
				'type'        => 'string',
				'max_length'  => 255,
				'description' => 'Permalink structure. Empty string for plain permalinks, otherwise a "/"-prefixed structure built only from %year%, %monthnum%, %day%, %hour%, %minute%, %second%, %post_id%, %postname%, %category% and %author%, and containing at least %postname% or %post_id%. Rewrite rules are NOT flushed — call wp-mcp/flush-rewrite-rules afterwards.',
			),
			'category_base'       => array(
				'handler'     => 'rewrite_base',
				'option'      => 'category_base',
				'type'        => 'string',
				'max_length'  => 100,
				'description' => 'Category URL base, or empty for the default. Rewrite rules are NOT flushed — call wp-mcp/flush-rewrite-rules afterwards.',
			),
			'tag_base'            => array(
				'handler'     => 'rewrite_base',
				'option'      => 'tag_base',
				'type'        => 'string',
				'max_length'  => 100,
				'description' => 'Tag URL base, or empty for the default. Rewrite rules are NOT flushed — call wp-mcp/flush-rewrite-rules afterwards.',
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Field definitions — Settings > Privacy
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function privacy_fields() {
		return array(
			'privacy_policy_page_id'    => array(
				'handler'     => 'page_reference',
				'option'      => 'wp_page_for_privacy_policy',
				'type'        => 'integer',
				'min'         => 0,
				'description' => 'Page ID of the privacy policy page, or 0 for none. Must be an existing page.',
			),
			'privacy_policy_page_title' => array(
				'handler'     => 'privacy_page_title',
				'type'        => 'string',
				'readonly'    => true,
				'description' => 'Title of the current privacy policy page. Read-only.',
			),
			'privacy_policy_page_url'   => array(
				'handler'     => 'privacy_page_url',
				'type'        => 'string',
				'readonly'    => true,
				'description' => 'Permalink of the current privacy policy page. Read-only.',
			),
		);
	}

	/* ==================================================================
	 * Ability callbacks
	 * ================================================================ */

	/**
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_general_settings( $input = array() ) {
		return self::read_group( 'general' );
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_general_settings( $input = array() ) {
		return self::write_group( 'general', $input, 'wp-mcp/update-general-settings' );
	}

	/**
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_writing_settings( $input = array() ) {
		return self::read_group( 'writing' );
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_writing_settings( $input = array() ) {
		return self::write_group( 'writing', $input, 'wp-mcp/update-writing-settings' );
	}

	/**
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_reading_settings( $input = array() ) {
		return self::read_group( 'reading' );
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_reading_settings( $input = array() ) {
		return self::write_group( 'reading', $input, 'wp-mcp/update-reading-settings' );
	}

	/**
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_discussion_settings( $input = array() ) {
		return self::read_group( 'discussion' );
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_discussion_settings( $input = array() ) {
		return self::write_group( 'discussion', $input, 'wp-mcp/update-discussion-settings' );
	}

	/**
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_media_settings( $input = array() ) {
		return self::read_group( 'media' );
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_media_settings( $input = array() ) {
		return self::write_group( 'media', $input, 'wp-mcp/update-media-settings' );
	}

	/**
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_permalink_settings( $input = array() ) {
		return self::read_group( 'permalinks' );
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_permalink_settings( $input = array() ) {
		return self::write_group( 'permalinks', $input, 'wp-mcp/update-permalink-settings' );
	}

	/**
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_privacy_settings( $input = array() ) {
		return self::read_group( 'privacy' );
	}

	/**
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_privacy_settings( $input = array() ) {
		return self::write_group( 'privacy', $input, 'wp-mcp/update-privacy-settings' );
	}

	/**
	 * Regenerate the site's rewrite rules.
	 *
	 * Kept as a separate, explicit operation so that changing the permalink
	 * structure and paying the cost of a rewrite flush are two decisions,
	 * not one hidden side effect (issue #10).
	 *
	 * @param array $input Ability input: optional `hard` boolean.
	 * @return array|WP_Error
	 */
	public static function flush_rewrite_rules( $input = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return WP_MCP_Errors::settings_permission_denied( __( 'You do not have permission to flush rewrite rules.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$hard = true;
		if ( isset( $input['hard'] ) ) {
			$hard = self::sanitize_boolean( 'hard', $input['hard'] );
			if ( is_wp_error( $hard ) ) {
				return $hard;
			}
		}

		flush_rewrite_rules( $hard );

		WP_MCP_Audit::log( 'wp-mcp/flush-rewrite-rules', 0, true, '', array( 'hard' => $hard ) );

		return array(
			'flushed'             => true,
			'hard'                => $hard,
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
		);
	}

	/**
	 * Describe the settings allowlist itself, without reading any value.
	 *
	 * Lets an agent discover exactly which fields exist, their types and
	 * their accepted values instead of guessing option names.
	 *
	 * @param array $input Ability input: optional `group` slug.
	 * @return array|WP_Error
	 */
	public static function list_settings_fields( $input = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return WP_MCP_Errors::settings_permission_denied( __( 'You do not have permission to inspect site settings.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$groups = self::groups();
		if ( isset( $input['group'] ) && '' !== $input['group'] ) {
			if ( ! is_string( $input['group'] ) || ! isset( $groups[ $input['group'] ] ) ) {
				return WP_MCP_Errors::settings_validation_error( __( 'Unknown settings group.', 'wordpress-mcp-abilities' ) );
			}
			$groups = array( $input['group'] => $groups[ $input['group'] ] );
		}

		$fields = array();
		foreach ( $groups as $group => $definition ) {
			foreach ( $definition['fields'] as $field => $spec ) {
				$entry = array(
					'group'       => $group,
					'field'       => $field,
					'writable'    => empty( $spec['readonly'] ),
					'type'        => self::public_type( $spec ),
					'capability'  => $definition['capability'],
					'description' => isset( $spec['description'] ) ? (string) $spec['description'] : '',
				);
				if ( isset( $spec['enum'] ) ) {
					$entry['allowed_values'] = array_values( $spec['enum'] );
				}
				if ( isset( $spec['min'] ) ) {
					$entry['minimum'] = (int) $spec['min'];
				}
				if ( isset( $spec['max'] ) ) {
					$entry['maximum'] = (int) $spec['max'];
				}
				if ( isset( $spec['max_length'] ) ) {
					$entry['max_length'] = (int) $spec['max_length'];
				}
				$fields[] = $entry;
			}
		}

		return array(
			'fields' => $fields,
			'total'  => count( $fields ),
		);
	}

	/* ==================================================================
	 * Schema helpers — single source of truth shared with registration
	 * ================================================================ */

	/**
	 * JSON Schema properties for the writable fields of a group.
	 *
	 * @param string $group Group slug.
	 * @return array<string,array<string,mixed>>
	 */
	public static function input_schema_properties( $group ) {
		return self::schema_properties( $group, true );
	}

	/**
	 * JSON Schema properties for every field of a group, writable or not.
	 *
	 * @param string $group Group slug.
	 * @return array<string,array<string,mixed>>
	 */
	public static function output_schema_properties( $group ) {
		return self::schema_properties( $group, false );
	}

	/**
	 * @param string $group          Group slug.
	 * @param bool   $writable_only  Whether to skip read-only fields.
	 * @return array<string,array<string,mixed>>
	 */
	private static function schema_properties( $group, $writable_only ) {
		$groups = self::groups();
		if ( ! isset( $groups[ $group ] ) ) {
			return array();
		}
		$properties = array();
		foreach ( $groups[ $group ]['fields'] as $field => $spec ) {
			if ( $writable_only && ! empty( $spec['readonly'] ) ) {
				continue;
			}
			$properties[ $field ] = self::field_schema( $spec );
		}
		return $properties;
	}

	/**
	 * @param array $spec Field spec.
	 * @return array<string,mixed>
	 */
	private static function field_schema( $spec ) {
		$description = isset( $spec['description'] ) ? (string) $spec['description'] : '';

		switch ( $spec['type'] ) {
			case 'boolean':
				return array( 'type' => 'boolean', 'description' => $description );
			case 'number':
				return array( 'type' => 'number', 'description' => $description );
			case 'integer':
				$schema = array( 'type' => 'integer', 'description' => $description );
				if ( isset( $spec['min'] ) ) {
					$schema['minimum'] = (int) $spec['min'];
				}
				if ( isset( $spec['max'] ) ) {
					$schema['maximum'] = (int) $spec['max'];
				}
				return $schema;
			case 'enum':
				return array( 'type' => 'string', 'description' => $description, 'enum' => array_values( $spec['enum'] ) );
			case 'url_array':
				return array(
					'type'        => 'array',
					'description' => $description,
					'items'       => array( 'type' => 'string', 'minLength' => 1 ),
					'maxItems'    => isset( $spec['max_items'] ) ? (int) $spec['max_items'] : self::MAX_UPDATE_SERVICES,
				);
			case 'text_block':
				return array( 'type' => 'string', 'description' => $description, 'maxLength' => self::MAX_TEXT_BLOCK_BYTES );
			default:
				$schema = array( 'type' => 'string', 'description' => $description );
				if ( isset( $spec['max_length'] ) ) {
					$schema['maxLength'] = (int) $spec['max_length'];
				}
				return $schema;
		}
	}

	/**
	 * Coarse type name reported by list-settings-fields.
	 *
	 * @param array $spec Field spec.
	 * @return string
	 */
	private static function public_type( $spec ) {
		switch ( $spec['type'] ) {
			case 'boolean':
			case 'integer':
			case 'number':
			case 'enum':
				return $spec['type'];
			case 'url_array':
				return 'array';
			default:
				return 'string';
		}
	}

	/* ==================================================================
	 * Engine
	 * ================================================================ */

	/**
	 * Read every field of a group after checking the group's capability.
	 *
	 * @param string $group Group slug.
	 * @return array|WP_Error
	 */
	private static function read_group( $group ) {
		$groups = self::groups();
		if ( ! isset( $groups[ $group ] ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'Unknown settings group.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! current_user_can( $groups[ $group ]['capability'] ) ) {
			return WP_MCP_Errors::settings_permission_denied();
		}
		return self::read_group_values( $group );
	}

	/**
	 * Read every field of a group. Capability must already have been checked.
	 *
	 * @param string $group Group slug.
	 * @return array<string,mixed>
	 */
	private static function read_group_values( $group ) {
		$groups = self::groups();
		$values = array();
		foreach ( $groups[ $group ]['fields'] as $field => $spec ) {
			$values[ $field ] = self::read_field( $spec );
		}
		return $values;
	}

	/**
	 * Validate and apply a partial update to one settings group.
	 *
	 * The allowlist gate lives here: any input key that is not a declared
	 * field of this group is refused before a single option is touched.
	 *
	 * @param string $group   Group slug.
	 * @param mixed  $input   Raw ability input.
	 * @param string $ability Ability name, for the audit log.
	 * @return array|WP_Error
	 */
	private static function write_group( $group, $input, $ability ) {
		$groups = self::groups();
		if ( ! isset( $groups[ $group ] ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'Unknown settings group.', 'wordpress-mcp-abilities' ) );
		}
		$definition = $groups[ $group ];

		if ( ! current_user_can( $definition['capability'] ) ) {
			return WP_MCP_Errors::settings_permission_denied();
		}
		if ( ! is_array( $input ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'Settings input must be an object of field name to value.', 'wordpress-mcp-abilities' ) );
		}

		$fields  = $definition['fields'];
		$payload = array();
		foreach ( $input as $field => $value ) {
			if ( ! is_string( $field ) || ! isset( $fields[ $field ] ) ) {
				return WP_MCP_Errors::settings_unknown_field( is_string( $field ) ? $field : '' );
			}
			$spec = $fields[ $field ];
			if ( ! empty( $spec['readonly'] ) ) {
				return WP_MCP_Errors::settings_readonly_field( $field );
			}
			if ( ! empty( $spec['network_managed'] ) && is_multisite() ) {
				return WP_MCP_Errors::settings_unsupported(
					sprintf(
						/* translators: %s: settings field name */
						__( 'The %s setting is managed at the network level on multisite and cannot be changed per site.', 'wordpress-mcp-abilities' ),
						$field
					)
				);
			}
			$clean = self::sanitize_field( $field, $spec, $value );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$payload[ $field ] = $clean;
		}

		if ( empty( $payload ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'At least one settings field is required.', 'wordpress-mcp-abilities' ) );
		}

		$invariants = self::validate_group_invariants( $group, $payload );
		if ( is_wp_error( $invariants ) ) {
			return $invariants;
		}

		$changed = array();
		foreach ( $payload as $field => $clean ) {
			$spec    = $fields[ $field ];
			$old     = self::read_field( $spec );
			$written = self::write_field( $spec, $clean );
			if ( is_wp_error( $written ) ) {
				WP_MCP_Audit::log( $ability, 0, false, $written->get_error_code(), array( 'field' => $field ) );
				return $written;
			}
			$new = self::read_field( $spec );
			if ( $old === $new ) {
				continue;
			}
			$changed[] = $field;
			WP_MCP_Audit::log( $ability, 0, true, '', array(
				'field'     => $field,
				'old_value' => self::audit_value( $spec, $old ),
				'new_value' => self::audit_value( $spec, $new ),
			) );
		}

		WP_MCP_Audit::log( $ability, 0, true, '', array( 'fields' => implode( ',', $changed ) ) );

		return array(
			'updated'  => $changed,
			'settings' => self::read_group_values( $group ),
		);
	}

	/**
	 * Cross-field rules that a single field cannot express on its own.
	 *
	 * @param string $group   Group slug.
	 * @param array  $payload Sanitized field => value pairs.
	 * @return true|WP_Error
	 */
	private static function validate_group_invariants( $group, $payload ) {
		if ( 'reading' !== $group ) {
			return true;
		}

		$show_on_front  = isset( $payload['show_on_front'] ) ? $payload['show_on_front'] : (string) get_option( 'show_on_front' );
		$page_on_front  = isset( $payload['page_on_front'] ) ? (int) $payload['page_on_front'] : (int) get_option( 'page_on_front' );
		$page_for_posts = isset( $payload['page_for_posts'] ) ? (int) $payload['page_for_posts'] : (int) get_option( 'page_for_posts' );

		if ( 'page' === $show_on_front && $page_on_front < 1 ) {
			return WP_MCP_Errors::settings_validation_error( __( 'show_on_front "page" requires page_on_front to reference an existing page.', 'wordpress-mcp-abilities' ) );
		}
		if ( $page_on_front > 0 && $page_on_front === $page_for_posts ) {
			return WP_MCP_Errors::settings_validation_error( __( 'page_on_front and page_for_posts cannot be the same page.', 'wordpress-mcp-abilities' ) );
		}

		return true;
	}

	/**
	 * Read one field's current value in its declared shape.
	 *
	 * @param array $spec Field spec.
	 * @return mixed
	 */
	private static function read_field( $spec ) {
		$handler = isset( $spec['handler'] ) ? $spec['handler'] : '';

		if ( 'timezone' === $handler ) {
			return self::read_timezone();
		}
		if ( 'locale' === $handler ) {
			$stored = (string) get_option( 'WPLANG' );
			return '' === $stored ? 'en_US' : $stored;
		}
		if ( 'default_post_format' === $handler ) {
			$stored = (string) get_option( 'default_post_format' );
			return ( '' === $stored || '0' === $stored ) ? 'standard' : $stored;
		}
		if ( 'update_services' === $handler ) {
			return self::read_update_services();
		}
		if ( 'privacy_page_title' === $handler ) {
			$page = self::privacy_policy_page();
			return $page ? (string) $page->post_title : '';
		}
		if ( 'privacy_page_url' === $handler ) {
			$page = self::privacy_policy_page();
			return $page ? (string) get_permalink( $page ) : '';
		}

		$raw = get_option( $spec['option'] );
		switch ( $spec['type'] ) {
			case 'boolean':
				return (bool) $raw;
			case 'integer':
				return (int) $raw;
			case 'number':
				return (float) $raw;
			default:
				return is_scalar( $raw ) ? (string) $raw : '';
		}
	}

	/**
	 * Persist one already-sanitized field value.
	 *
	 * @param array $spec  Field spec.
	 * @param mixed $value Sanitized value.
	 * @return true|WP_Error
	 */
	private static function write_field( $spec, $value ) {
		$handler = isset( $spec['handler'] ) ? $spec['handler'] : '';

		if ( 'timezone' === $handler ) {
			update_option( 'timezone_string', $value['timezone_string'] );
			update_option( 'gmt_offset', $value['gmt_offset'] );
			return true;
		}

		if ( ! isset( $spec['option'] ) ) {
			return WP_MCP_Errors::settings_update_failed();
		}

		$option = $spec['option'];
		// Defence in depth: an allowlist entry can never reach a denied option.
		if ( in_array( $option, self::never_writable_options(), true ) ) {
			return WP_MCP_Errors::settings_readonly_field( $option );
		}

		if ( 'permalink_structure' === $handler ) {
			$rewrite = self::wp_rewrite();
			if ( $rewrite ) {
				$rewrite->set_permalink_structure( $value );
			} else {
				update_option( 'permalink_structure', $value );
			}
			return true;
		}

		if ( 'rewrite_base' === $handler ) {
			$rewrite = self::wp_rewrite();
			if ( $rewrite && 'category_base' === $option ) {
				$rewrite->set_category_base( $value );
			} elseif ( $rewrite && 'tag_base' === $option ) {
				$rewrite->set_tag_base( $value );
			} else {
				update_option( $option, $value );
			}
			return true;
		}

		if ( 'update_services' === $handler ) {
			update_option( $option, implode( "\n", $value ) );
			return true;
		}

		if ( 'boolean' === $spec['type'] ) {
			update_option( $option, $value ? 1 : 0 );
			return true;
		}

		update_option( $option, $value );
		return true;
	}

	/**
	 * Value recorded in the audit log for a field, never a secret and never
	 * a free-text blob that may carry moderation keywords.
	 *
	 * @param array $spec  Field spec.
	 * @param mixed $value Read-back value.
	 * @return bool|float|int|string
	 */
	private static function audit_value( $spec, $value ) {
		if ( isset( $spec['audit'] ) && 'omit' === $spec['audit'] ) {
			return '(omitted)';
		}
		if ( is_array( $value ) ) {
			return count( $value ) . ' entries';
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/* ==================================================================
	 * Per-field validation
	 * ================================================================ */

	/**
	 * @param string $field Field name.
	 * @param array  $spec  Field spec.
	 * @param mixed  $value Raw client value.
	 * @return mixed|WP_Error
	 */
	private static function sanitize_field( $field, $spec, $value ) {
		$handler = isset( $spec['handler'] ) ? $spec['handler'] : '';

		switch ( $handler ) {
			case 'timezone':
				return self::sanitize_timezone( $field, $value );
			case 'locale':
				return self::sanitize_locale( $field, $value );
			case 'default_role':
				return self::sanitize_default_role( $field, $value );
			case 'default_category':
				return self::sanitize_default_category( $field, $value );
			case 'default_post_format':
				return self::sanitize_default_post_format( $field, $value );
			case 'update_services':
				return self::sanitize_update_services( $field, $value );
			case 'page_reference':
				return self::sanitize_page_reference( $field, $value );
			case 'permalink_structure':
				return self::sanitize_permalink_structure( $field, $value );
			case 'rewrite_base':
				return self::sanitize_rewrite_base( $field, $value );
		}

		switch ( $spec['type'] ) {
			case 'boolean':
				return self::sanitize_boolean( $field, $value );
			case 'integer':
				return self::sanitize_integer( $field, $spec, $value );
			case 'enum':
				return self::sanitize_enum( $field, $spec, $value );
			case 'text_block':
				return self::sanitize_text_block( $field, $value );
			case 'format_string':
				return self::sanitize_format_string( $field, $spec, $value );
			default:
				return self::sanitize_string( $field, $spec, $value );
		}
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return bool|WP_Error
	 */
	private static function sanitize_boolean( $field, $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) && ( 0 === $value || 1 === $value ) ) {
			return 1 === $value;
		}
		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			if ( in_array( $normalized, array( '1', 'true' ), true ) ) {
				return true;
			}
			if ( in_array( $normalized, array( '0', 'false' ), true ) ) {
				return false;
			}
		}
		return WP_MCP_Errors::settings_validation_error(
			sprintf(
				/* translators: %s: settings field name */
				__( '%s must be a boolean.', 'wordpress-mcp-abilities' ),
				$field
			)
		);
	}

	/**
	 * @param string $field Field name.
	 * @param array  $spec  Field spec.
	 * @param mixed  $value Raw value.
	 * @return int|WP_Error
	 */
	private static function sanitize_integer( $field, $spec, $value ) {
		if ( is_int( $value ) ) {
			$int = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^-?[0-9]+$/', trim( $value ) ) ) {
			$int = (int) trim( $value );
		} else {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s must be an integer.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}

		$min = isset( $spec['min'] ) ? (int) $spec['min'] : null;
		$max = isset( $spec['max'] ) ? (int) $spec['max'] : null;
		if ( ( null !== $min && $int < $min ) || ( null !== $max && $int > $max ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: 1: settings field name, 2: minimum value, 3: maximum value */
					__( '%1$s must be between %2$d and %3$d.', 'wordpress-mcp-abilities' ),
					$field,
					null === $min ? PHP_INT_MIN : $min,
					null === $max ? PHP_INT_MAX : $max
				)
			);
		}

		return $int;
	}

	/**
	 * @param string $field Field name.
	 * @param array  $spec  Field spec.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_enum( $field, $spec, $value ) {
		if ( is_string( $value ) && in_array( $value, $spec['enum'], true ) ) {
			return $value;
		}
		return WP_MCP_Errors::settings_validation_error(
			sprintf(
				/* translators: 1: settings field name, 2: comma-separated list of accepted values */
				__( '%1$s must be one of: %2$s.', 'wordpress-mcp-abilities' ),
				$field,
				implode( ', ', $spec['enum'] )
			)
		);
	}

	/**
	 * @param string $field Field name.
	 * @param array  $spec  Field spec.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_string( $field, $spec, $value ) {
		if ( ! is_string( $value ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s must be a string.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		$max = isset( $spec['max_length'] ) ? (int) $spec['max_length'] : 255;
		if ( strlen( $value ) > $max ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: 1: settings field name, 2: maximum length */
					__( '%1$s must be at most %2$d characters.', 'wordpress-mcp-abilities' ),
					$field,
					$max
				)
			);
		}
		return sanitize_text_field( $value );
	}

	/**
	 * A PHP date()/time() format string: no markup, no newlines.
	 *
	 * @param string $field Field name.
	 * @param array  $spec  Field spec.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_format_string( $field, $spec, $value ) {
		/*
		 * Checked against the raw value on purpose: sanitize_text_field()
		 * strips markup and collapses line breaks, which would turn a
		 * malformed format string into a plausible-looking one instead of
		 * an error the caller can see.
		 */
		if ( is_string( $value ) && 1 === preg_match( '/[<>\r\n\t]/', $value ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s cannot contain markup, tabs or line breaks.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		$clean = self::sanitize_string( $field, $spec, $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( '' === trim( $clean ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s cannot be empty.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		return $clean;
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_text_block( $field, $value ) {
		if ( ! is_string( $value ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s must be a string.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		if ( strlen( $value ) > self::MAX_TEXT_BLOCK_BYTES ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: 1: settings field name, 2: maximum size in bytes */
					__( '%1$s must be at most %2$d bytes.', 'wordpress-mcp-abilities' ),
					$field,
					self::MAX_TEXT_BLOCK_BYTES
				)
			);
		}
		return sanitize_textarea_field( $value );
	}

	/**
	 * Accepts a PHP timezone identifier or a manual "UTC±n" offset and
	 * returns the (timezone_string, gmt_offset) pair to store, mirroring
	 * what wp-admin/options.php does with the same input.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return array{timezone_string:string,gmt_offset:string}|WP_Error
	 */
	private static function sanitize_timezone( $field, $value ) {
		if ( ! is_string( $value ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s must be a string.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return array( 'timezone_string' => '', 'gmt_offset' => '0' );
		}

		if ( 1 === preg_match( '/^UTC([+-]?(?:[0-9]|1[0-4])(?:\.[0-9]{1,2})?)$/', $value, $matches ) ) {
			$offset = (float) $matches[1];
			if ( $offset < -12 || $offset > 14 ) {
				return WP_MCP_Errors::settings_validation_error( __( 'A manual UTC offset must be between UTC-12 and UTC+14.', 'wordpress-mcp-abilities' ) );
			}
			return array( 'timezone_string' => '', 'gmt_offset' => (string) $matches[1] );
		}

		if ( in_array( $value, timezone_identifiers_list( DateTimeZone::ALL_WITH_BC ), true ) ) {
			return array( 'timezone_string' => $value, 'gmt_offset' => '' );
		}

		return WP_MCP_Errors::settings_validation_error( __( 'timezone must be a PHP timezone identifier such as "Europe/Madrid", or a manual offset such as "UTC+2".', 'wordpress-mcp-abilities' ) );
	}

	/**
	 * @return string
	 */
	private static function read_timezone() {
		$timezone_string = (string) get_option( 'timezone_string' );
		if ( '' !== $timezone_string ) {
			return $timezone_string;
		}
		$offset    = (float) get_option( 'gmt_offset' );
		$formatted = rtrim( rtrim( number_format( $offset, 2, '.', '' ), '0' ), '.' );
		if ( '' === $formatted || '-0' === $formatted ) {
			$formatted = '0';
		}
		return 0 === strpos( $formatted, '-' ) ? 'UTC' . $formatted : 'UTC+' . $formatted;
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_locale( $field, $value ) {
		if ( ! is_string( $value ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s must be a string.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		$value = trim( $value );
		if ( '' === $value || 'en_US' === $value ) {
			return '';
		}
		if ( in_array( $value, get_available_languages(), true ) ) {
			return $value;
		}
		return WP_MCP_Errors::settings_validation_error( __( 'language must be "en_US" or a locale already installed on this site.', 'wordpress-mcp-abilities' ) );
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_default_role( $field, $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'default_role must be a role slug.', 'wordpress-mcp-abilities' ) );
		}
		$slug = sanitize_key( trim( $value ) );
		$role = get_role( $slug );
		if ( ! $role ) {
			return WP_MCP_Errors::settings_validation_error( __( 'default_role must reference an existing role.', 'wordpress-mcp-abilities' ) );
		}
		foreach ( self::DEFAULT_ROLE_FORBIDDEN_CAPS as $capability ) {
			if ( ! empty( $role->capabilities[ $capability ] ) ) {
				return WP_MCP_Errors::settings_validation_error(
					sprintf(
						/* translators: 1: role slug, 2: capability name */
						__( 'default_role cannot be "%1$s": it grants the administrative capability %2$s to every new registration.', 'wordpress-mcp-abilities' ),
						$slug,
						$capability
					)
				);
			}
		}
		return $slug;
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return int|WP_Error
	 */
	private static function sanitize_default_category( $field, $value ) {
		$term_id = self::sanitize_integer( $field, array( 'min' => 1 ), $value );
		if ( is_wp_error( $term_id ) ) {
			return $term_id;
		}
		$term = get_term( $term_id, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'default_category must reference an existing category term.', 'wordpress-mcp-abilities' ) );
		}
		return $term_id;
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_default_post_format( $field, $value ) {
		if ( ! is_string( $value ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'default_post_format must be a string.', 'wordpress-mcp-abilities' ) );
		}
		$format = strtolower( trim( $value ) );
		if ( '' === $format || 'standard' === $format || '0' === $format ) {
			return '0';
		}
		if ( ! in_array( $format, get_post_format_slugs(), true ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'default_post_format must be "standard" or a registered post format slug.', 'wordpress-mcp-abilities' ) );
		}
		$supported = get_theme_support( 'post-formats' );
		$supported = ( is_array( $supported ) && isset( $supported[0] ) && is_array( $supported[0] ) ) ? $supported[0] : array();
		if ( ! in_array( $format, $supported, true ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'default_post_format must be a format the active theme declares support for.', 'wordpress-mcp-abilities' ) );
		}
		return $format;
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string[]|WP_Error
	 */
	private static function sanitize_update_services( $field, $value ) {
		if ( ! is_array( $value ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'update_services must be an array of HTTP(S) URLs.', 'wordpress-mcp-abilities' ) );
		}
		if ( count( $value ) > self::MAX_UPDATE_SERVICES ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %d: maximum number of update service URLs */
					__( 'update_services accepts at most %d URLs.', 'wordpress-mcp-abilities' ),
					self::MAX_UPDATE_SERVICES
				)
			);
		}

		$clean = array();
		foreach ( $value as $url ) {
			if ( ! is_string( $url ) ) {
				return WP_MCP_Errors::settings_validation_error( __( 'Every update_services entry must be a string.', 'wordpress-mcp-abilities' ) );
			}
			$url = trim( $url );
			if ( '' === $url ) {
				continue;
			}
			if ( ! wp_http_validate_url( $url ) ) {
				return WP_MCP_Errors::settings_validation_error( __( 'Every update_services entry must be a valid HTTP(S) URL.', 'wordpress-mcp-abilities' ) );
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				return WP_MCP_Errors::settings_validation_error( __( 'Every update_services entry must be a valid HTTP(S) URL.', 'wordpress-mcp-abilities' ) );
			}
			$host = strtolower( trim( $parts['host'], '[]' ) );
			$is_literal_ip = (bool) filter_var( $host, FILTER_VALIDATE_IP );
			$is_public_ip  = $is_literal_ip && false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
			if ( 'localhost' === $host || ( $is_literal_ip && ! $is_public_ip ) ) {
				return WP_MCP_Errors::settings_validation_error( __( 'update_services cannot point at localhost or a private/reserved IP address.', 'wordpress-mcp-abilities' ) );
			}
			$clean[] = esc_url_raw( $url );
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * @return string[]
	 */
	private static function read_update_services() {
		$raw = get_option( 'ping_sites' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$lines = preg_split( '/[\r\n]+/', $raw );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		$urls = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$urls[] = $line;
			}
		}
		return $urls;
	}

	/**
	 * A page ID that must exist and actually be a page (or 0 to unset).
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return int|WP_Error
	 */
	private static function sanitize_page_reference( $field, $value ) {
		$page_id = self::sanitize_integer( $field, array( 'min' => 0 ), $value );
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}
		if ( 0 === $page_id ) {
			return 0;
		}
		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s must reference an existing page.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		return $page_id;
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_permalink_structure( $field, $value ) {
		if ( ! is_string( $value ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'permalink_structure must be a string.', 'wordpress-mcp-abilities' ) );
		}
		$structure = trim( $value );
		if ( '' === $structure ) {
			return '';
		}
		if ( strlen( $structure ) > 255 ) {
			return WP_MCP_Errors::settings_validation_error( __( 'permalink_structure must be at most 255 characters.', 'wordpress-mcp-abilities' ) );
		}
		if ( 0 !== strpos( $structure, '/' ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'permalink_structure must start with "/".', 'wordpress-mcp-abilities' ) );
		}
		if ( false !== strpos( $structure, '..' ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'permalink_structure cannot contain "..".', 'wordpress-mcp-abilities' ) );
		}
		if ( 1 !== preg_match( '#^[A-Za-z0-9%_\-/.]+$#', $structure ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'permalink_structure contains characters that are not allowed.', 'wordpress-mcp-abilities' ) );
		}

		preg_match_all( '/%([a-z_]+)%/', $structure, $matches );
		$tags = $matches[1];
		foreach ( $tags as $tag ) {
			if ( ! in_array( $tag, self::PERMALINK_TAGS, true ) ) {
				return WP_MCP_Errors::settings_validation_error(
					sprintf(
						/* translators: 1: rewrite tag supplied, 2: comma-separated list of accepted rewrite tags */
						__( 'Unknown permalink tag %%%1$s%%. Accepted tags: %2$s.', 'wordpress-mcp-abilities' ),
						$tag,
						implode( ', ', self::PERMALINK_TAGS )
					)
				);
			}
		}
		if ( substr_count( $structure, '%' ) !== count( $matches[0] ) * 2 ) {
			return WP_MCP_Errors::settings_validation_error( __( 'permalink_structure contains an unterminated or malformed %tag%.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! in_array( 'postname', $tags, true ) && ! in_array( 'post_id', $tags, true ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'permalink_structure must contain %postname% or %post_id% so that every post resolves to a unique URL.', 'wordpress-mcp-abilities' ) );
		}

		return $structure;
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_rewrite_base( $field, $value ) {
		if ( ! is_string( $value ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s must be a string.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		$base = trim( $value );
		if ( '' === $base ) {
			return '';
		}
		if ( strlen( $base ) > 100 ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s must be at most 100 characters.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		if ( 1 !== preg_match( '#^/?[A-Za-z0-9_\-]+(?:/[A-Za-z0-9_\-]+)*/?$#', $base ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: settings field name */
					__( '%s may only contain letters, digits, hyphens, underscores and "/" separators.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}

		/*
		 * The same normalization wp-admin/options-permalink.php applies before
		 * calling set_category_base()/set_tag_base(). What ends up in the
		 * option is then core's decision: sanitize_option() runs this through
		 * esc_url_raw() and drops the leading slash again. Reproducing
		 * wp-admin's input verbatim is the point — not predicting core's
		 * stored form.
		 */
		$base = '/' . trim( (string) preg_replace( '#/+#', '/', $base ), '/' );

		return self::blog_prefix() . $base;
	}

	/**
	 * The "/blog" prefix wp-admin prepends to rewrite bases on the main site
	 * of a subdirectory multisite whose permalink structure starts with it.
	 *
	 * @return string
	 */
	private static function blog_prefix() {
		if ( ! is_multisite() || ! function_exists( 'is_subdomain_install' ) || is_subdomain_install() || ! is_main_site() ) {
			return '';
		}
		return 0 === strpos( (string) get_option( 'permalink_structure' ), '/blog/' ) ? '/blog' : '';
	}

	/* ==================================================================
	 * Small internals
	 * ================================================================ */

	/**
	 * The global WP_Rewrite instance, when WordPress has built one.
	 *
	 * Read through $GLOBALS rather than `global` so nothing here can be
	 * mistaken for (or accidentally become) an assignment to a WordPress
	 * global.
	 *
	 * @return WP_Rewrite|null
	 */
	private static function wp_rewrite() {
		if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof WP_Rewrite ) {
			return $GLOBALS['wp_rewrite'];
		}
		return null;
	}

	/**
	 * @return WP_Post|null
	 */
	private static function privacy_policy_page() {
		$page_id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $page_id < 1 ) {
			return null;
		}
		$page = get_post( $page_id );
		return ( $page && 'page' === $page->post_type ) ? $page : null;
	}
}
