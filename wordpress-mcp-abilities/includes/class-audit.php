<?php
/**
 * WordPress MCP Abilities — Audit logging.
 *
 * Fires the `wp_mcp_audit_log` action for every write operation and,
 * when WP_MCP_AUDIT_LOG is defined and truthy, writes a summary line
 * to the PHP error log.
 *
 * Every event carries the fixed shape issue #15 requires: timestamp,
 * user_id, ability, object type/id when the ability has one, result,
 * error_code, plus a non-sensitive summary of the relevant parameters.
 *
 * No content, excerpts, secrets, passwords, tokens, headers, cookies
 * or full MCP requests are ever recorded: scrub_context() drops any key
 * whose name reads as a credential and truncates every string value, so
 * a call site cannot leak one by accident.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Audit
 */
class WP_MCP_Audit {

	/**
	 * Fields the fixed part of the logged line already accounts for; anything
	 * else in the event payload is extra context worth appending.
	 *
	 * @since 0.9.0
	 * @var string[]
	 */
	const BASE_FIELDS = array( 'timestamp', 'user_id', 'ability', 'object_type', 'object_id', 'result', 'error_code' );

	/**
	 * Maximum length of a single context string value.
	 *
	 * Long enough for a slug, a version, a post type or a settings group;
	 * short enough that post content, an export payload or a token can never
	 * be reconstructed from the log.
	 *
	 * @since 0.15.0
	 * @var int
	 */
	const MAX_CONTEXT_VALUE_LENGTH = 120;

	/**
	 * Context key fragments that may never be logged, matched as substrings of
	 * the (already sanitised) key name.
	 *
	 * A deny list rather than an allow list because the audit context is
	 * deliberately open-ended: a new call site adding `from_version` must not
	 * have to register it here, while one adding `app_password` must still be
	 * dropped.
	 *
	 * @since 0.15.0
	 * @var string[]
	 */
	const FORBIDDEN_CONTEXT_KEY_PARTS = array(
		'password',
		'passwd',
		'pwd',
		'secret',
		'token',
		'credential',
		'cookie',
		'nonce',
		'salt',
		'private_key',
		'api_key',
		'auth_key',
		'access_key',
		'authorization',
		'content',
		'body',
	);

	/**
	 * Closed vocabulary of object types an audit event may report.
	 *
	 * Deliberately a fixed list: tests/test-security.php asserts that every
	 * registered ability resolves to a member of it, so a new domain cannot
	 * quietly introduce an unaudited object kind. The custom-post-type domain
	 * is the one caller allowed past it, because it passes the site's real
	 * post type slug as an explicit `object_type` context value.
	 *
	 * @since 0.15.0
	 * @var string[]
	 */
	const OBJECT_TYPES = array(
		'',
		'post',
		'page',
		'custom_post',
		'attachment',
		'term',
		'taxonomy',
		'post_type',
		'comment',
		'user',
		'role',
		'application_password',
		'nav_menu',
		'nav_menu_item',
		'nav_menu_location',
		'wp_template',
		'wp_template_part',
		'wp_block',
		'wp_navigation',
		'wp_global_styles',
		'plugin',
		'theme',
		'core',
		'cron_event',
		'settings_group',
		'site',
		'network',
		'network_settings',
		'user_request',
		'integration',
		'content_archive',
		'cache',
		'discovery',

		// Objects that belong to an integrated third-party plugin (issue #14).
		'form',
		'product',
		'order',
		'coupon',
	);

	/**
	 * Log a write-operation event.
	 *
	 * @param string $ability    Ability name, e.g. 'wp-mcp/create-post'.
	 * @param int    $object_id  Post/media/term/user/comment ID affected (0 if N/A).
	 * @param bool   $success    Whether the operation succeeded.
	 * @param string $error_code WP_Error code when $success is false.
	 * @param array  $context    Optional extra scalar fields (e.g. 'slug',
	 *                           'from_version', 'to_version' for plugin/theme/
	 *                           core installs and updates). Never secrets,
	 *                           tokens, or full download URLs — string values
	 *                           are sanitized and truncated, credential-shaped
	 *                           keys are dropped, and only scalars are kept.
	 *                           An explicit `object_type` key overrides the type
	 *                           resolved from the ability name, which is how the
	 *                           custom-post-type domain records the real post
	 *                           type it operated on.
	 */
	public static function log( $ability, $object_id = 0, $success = true, $error_code = '', $context = array() ) {
		$context = (array) $context;

		$object_type = '';
		if ( isset( $context['object_type'] ) && is_string( $context['object_type'] ) ) {
			$object_type = sanitize_key( $context['object_type'] );
		}
		unset( $context['object_type'] );

		if ( '' === $object_type ) {
			$object_type = self::object_type_for( $ability );
		}

		$data = array(
			'timestamp'   => current_time( 'c' ),
			'user_id'     => get_current_user_id(),
			'ability'     => $ability,
			'object_type' => $object_type,
			'object_id'   => absint( $object_id ),
			'result'      => $success ? 'success' : 'error',
			'error_code'  => sanitize_key( $error_code ),
		);

		foreach ( self::scrub_context( $context ) as $key => $value ) {
			// Never let a context key overwrite a base field.
			if ( in_array( $key, self::BASE_FIELDS, true ) ) {
				continue;
			}
			$data[ $key ] = $value;
		}

		/**
		 * Fires after a write operation completes.
		 *
		 * Third-party plugins can hook into this action to persist
		 * audit events to a database, external service, etc.
		 *
		 * @since 0.1.0
		 * @since 0.15.0 Added the `object_type` field.
		 *
		 * @param array $data {
		 *     @type string $timestamp   ISO 8601 timestamp.
		 *     @type int    $user_id     WordPress user ID.
		 *     @type string $ability     Ability identifier.
		 *     @type string $object_type Kind of object acted on ('post', 'user',
		 *                               'plugin', ...); '' when the ability has none.
		 *     @type int    $object_id   Affected object ID (0 when not applicable).
		 *     @type string $result      'success' or 'error'.
		 *     @type string $error_code  Stable error code (empty on success).
		 * }
		 */
		do_action( 'wp_mcp_audit_log', $data );

		// Optional file-based logging.
		if ( defined( 'WP_MCP_AUDIT_LOG' ) && WP_MCP_AUDIT_LOG ) {
			$line = sprintf(
				'[WordPress MCP] %s | user=%d | ability=%s | type=%s | object=%d | result=%s | code=%s',
				$data['timestamp'],
				$data['user_id'],
				$data['ability'],
				$data['object_type'],
				$data['object_id'],
				$data['result'],
				$data['error_code']
			);

			/*
			 * The extra context (slug, from_version, to_version) is the part
			 * that makes an install/update/delete entry auditable at all, so it
			 * belongs on the logged line too — not only in the
			 * wp_mcp_audit_log action payload. Values are already sanitized,
			 * scrubbed and reduced to scalars above.
			 */
			foreach ( array_diff_key( $data, array_flip( self::BASE_FIELDS ) ) as $key => $value ) {
				if ( is_bool( $value ) ) {
					$value = $value ? 'true' : 'false';
				}
				$line .= sprintf( ' | %s=%s', $key, (string) $value );
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $line );
		}
	}

	/**
	 * Reduce a raw context array to loggable, non-sensitive scalars.
	 *
	 * Drops non-string keys, non-scalar values and credential-shaped keys;
	 * sanitises and truncates every string value.
	 *
	 * @since 0.15.0
	 *
	 * @param array $context Raw context.
	 * @return array Scrubbed context, string keys to scalar values.
	 */
	public static function scrub_context( $context ) {
		$clean = array();

		foreach ( (array) $context as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			$key = sanitize_key( $key );
			if ( '' === $key || self::is_forbidden_context_key( $key ) ) {
				continue;
			}

			if ( is_string( $value ) ) {
				$value = sanitize_text_field( $value );
				if ( strlen( $value ) > self::MAX_CONTEXT_VALUE_LENGTH ) {
					$value = substr( $value, 0, self::MAX_CONTEXT_VALUE_LENGTH ) . '...';
				}
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Whether a context key reads as a credential and must never be logged.
	 *
	 * @since 0.15.0
	 *
	 * @param string $key Sanitised context key.
	 * @return bool
	 */
	private static function is_forbidden_context_key( $key ) {
		foreach ( self::FORBIDDEN_CONTEXT_KEY_PARTS as $part ) {
			if ( false !== strpos( $key, $part ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the kind of object an ability acts on, from its name.
	 *
	 * Ordered most-specific-first: 'wp-mcp/update-page-attributes' must
	 * resolve to `page`, not to `post`, and 'wp-mcp/list-post-type-meta-fields'
	 * to `post_type`, not to `post`. Call sites that know better — the
	 * custom-post-type domain, which knows the real post type slug — pass an
	 * explicit `object_type` in the audit context instead.
	 *
	 * @since 0.15.0
	 *
	 * @param string $ability Ability name, with or without the `wp-mcp/` prefix.
	 * @return string One of self::OBJECT_TYPES.
	 */
	public static function object_type_for( $ability ) {
		if ( ! is_string( $ability ) || '' === $ability ) {
			return '';
		}

		$name = $ability;
		if ( 0 === strpos( $name, 'wp-mcp/' ) ) {
			$name = substr( $name, strlen( 'wp-mcp/' ) );
		}

		foreach ( self::object_type_rules() as $needle => $type ) {
			if ( false !== strpos( $name, $needle ) ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * Ordered ability-name fragment to object-type rules.
	 *
	 * Order is load-bearing: the first matching fragment wins, so every rule
	 * must appear before any less specific fragment it contains.
	 *
	 * @since 0.15.0
	 *
	 * @return array Fragment to object type.
	 */
	private static function object_type_rules() {
		return array(
			/*
			 * Discovery (issue #16) — first, because `pattern-categories`,
			 * `template-types`, `block-type` and `post-statuses` would
			 * otherwise be swallowed by the site-editor and content
			 * fragments below. These abilities describe the installation and
			 * never act on an object, so they resolve to their own type
			 * rather than borrowing the type of what they describe.
			 * `get-current-user-capabilities` is deliberately absent: it is
			 * about the caller, and the existing `user-capabilities` rule
			 * already resolves it to `user`.
			 */
			'global-search'             => 'discovery',
			'post-statuses'             => 'discovery',
			'mime-types'                => 'discovery',
			'block-type'                => 'discovery',
			'pattern-categories'        => 'discovery',
			'template-types'            => 'discovery',
			'feature-support'           => 'discovery',

			// Site editor — before the generic template/pattern/theme fragments.
			'template-part'             => 'wp_template_part',
			'template'                  => 'wp_template',
			'synced-pattern'            => 'wp_block',
			'pattern'                   => 'wp_block',
			'navigation-block'          => 'wp_navigation',
			'global-styles'             => 'wp_global_styles',
			'widget-areas'              => 'theme',
			'theme-context'             => 'theme',

			// Network — before site/user/plugin/theme.
			'network-settings'          => 'network_settings',
			'network-site'              => 'site',
			'network-user'              => 'user',
			'network-themes'            => 'theme',
			'network-activate-plugin'   => 'plugin',
			'network-deactivate-plugin' => 'plugin',
			'network-enable-theme'      => 'theme',
			'network-disable-theme'     => 'theme',
			'network-info'              => 'network',
			'network-update-status'     => 'network',

			// Users, roles, application passwords.
			'application-password'      => 'application_password',
			'role-capabilit'            => 'role',
			'user-role'                 => 'user',
			'user-capabilities'         => 'user',
			'user-password'             => 'user',

			// Privacy requests.
			'privacy-request'           => 'user_request',
			'privacy-export-request'    => 'user_request',
			'privacy-erasure-request'   => 'user_request',

			// Settings groups.
			'settings'                  => 'settings_group',
			'rewrite'                   => 'settings_group',

			// Navigation.
			'nav-menu'                  => 'nav_menu',
			'menu-item'                 => 'nav_menu_item',
			'menu-location'             => 'nav_menu_location',

			/*
			 * Integration adapters (issue #14). Placed after the navigation
			 * rules so `reorder-menu-items` cannot be caught by `order`, and
			 * before the generic domains because an adapter's object belongs to
			 * the integrated plugin, not to WordPress core.
			 */
			'store-status'              => 'integration',
			'contact-form-7'            => 'form',
			'gravity-forms'             => 'form',
			'wpforms'                   => 'form',
			'product'                   => 'product',
			'coupon'                    => 'coupon',
			'order'                     => 'order',

			// System.
			'cron-event'                => 'cron_event',
			'cron-status'               => 'cron_event',
			'core-update-status'        => 'core',
			'update-core'               => 'core',
			'update-translations'       => 'core',
			'available-updates'         => 'core',
			'maintenance-mode'          => 'site',
			'site-health'               => 'site',
			'site-info'                 => 'site',
			'site-size'                 => 'site',
			'environment-info'          => 'site',
			'image-sizes'               => 'site',
			'export-content'            => 'content_archive',
			'import-content'            => 'content_archive',
			'transients'                => 'cache',
			'object-cache'              => 'cache',
			'update-caches'             => 'cache',

			// Extensibility.
			'integration'               => 'integration',

			// Custom post types — before the generic post fragment.
			'post-type'                 => 'post_type',
			'custom-post'               => 'custom_post',

			/*
			 * Media. `set-featured-image` / `remove-featured-image` audit the
			 * parent post's ID, not the attachment's, so the pair
			 * (object_type, object_id) has to say `post`.
			 */
			'featured-image'            => 'post',
			'media'                     => 'attachment',

			/*
			 * Taxonomies. Same reasoning: the assign/remove abilities audit the
			 * object the terms were attached to, which is a post.
			 */
			'assign-terms'              => 'post',
			'remove-terms'              => 'post',
			'taxonom'                   => 'taxonomy',
			'term'                      => 'term',
			'categories'                => 'term',
			'tags'                      => 'term',

			// Comments.
			'comment'                   => 'comment',

			// Plugins and themes.
			'plugin'                    => 'plugin',
			'theme'                     => 'theme',

			// Content.
			'page'                      => 'page',
			'post'                      => 'post',

			// Remaining users and roles, after every more specific match above.
			'user'                      => 'user',
			'role'                      => 'role',
		);
	}
}
