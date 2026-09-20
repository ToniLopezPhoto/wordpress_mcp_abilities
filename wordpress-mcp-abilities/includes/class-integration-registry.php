<?php
/**
 * WordPress MCP Abilities — Integration adapter registry.
 *
 * The single place where an integration is declared. Adding one means
 * dropping an adapter class in `includes/integrations/` and adding one line
 * to `covered_manifest()` — or, for a plugin that is only detected so far,
 * one entry in `detect_only_manifest()`. Nothing else in the plugin changes:
 * the ability registry asks this class which adapters are available, and
 * `WP_MCP_Ability_Matrix` asks it for the matrix rows the covered adapters
 * declare.
 *
 * Two halves, deliberately kept apart:
 *
 *  - **Covered** integrations contribute abilities. They are real adapter
 *    classes with schemas, capability checks and an explicit exclusion list.
 *  - **Detect-only** integrations contribute nothing but an honest answer to
 *    "is this plugin here". Which of their operations deserve an ability is
 *    decided per plugin, against a real production inventory, in the
 *    follow-up issues of #14 — never by generalising a bridge.
 *
 * `wp-mcp/list-integrations` publishes that difference rather than hiding
 * it, so an agent is never left guessing whether a missing tool means
 * "plugin absent" or "not covered yet".
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.14.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Integrations
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Integrations {

	/**
	 * The closed vocabulary of integration groups.
	 */
	const GROUPS = array( 'seo', 'forms', 'events', 'membership', 'mail', 'security', 'cache', 'analytics', 'ecommerce' );

	/**
	 * Memoized adapter instances, keyed by slug.
	 *
	 * @var array<string,WP_MCP_Integration>|null
	 */
	private static $adapters = null;

	/* ==================================================================
	 * Manifests
	 * ================================================================ */

	/**
	 * Adapters that contribute abilities.
	 *
	 * Pure data, and deliberately free of any WordPress call: the
	 * documentation-drift test loads the permission matrix — and therefore
	 * this manifest — without a WordPress install.
	 *
	 * @return array<int,array{file:string,class:string}>
	 */
	public static function covered_manifest() {
		return array(
			array( 'file' => 'class-woocommerce-integration', 'class' => 'WP_MCP_WooCommerce_Integration' ),
			array( 'file' => 'class-yoast-seo-integration', 'class' => 'WP_MCP_Yoast_SEO_Integration' ),
			array( 'file' => 'class-contact-form-7-integration', 'class' => 'WP_MCP_Contact_Form_7_Integration' ),
			array( 'file' => 'class-gravity-forms-integration', 'class' => 'WP_MCP_Gravity_Forms_Integration' ),
			array( 'file' => 'class-wpforms-integration', 'class' => 'WP_MCP_WPForms_Integration' ),
		);
	}

	/**
	 * Plugins that are detected but not covered by abilities yet.
	 *
	 * Every entry states what would stay excluded once it *is* covered, so
	 * the decision is recorded before anybody writes the first ability for
	 * it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function detect_only_manifest() {
		return array(
			/* ---- SEO ---- */
			array(
				'slug'          => 'rank-math',
				'label'         => 'Rank Math SEO',
				'group'         => 'seo',
				'plugin_label'  => 'Rank Math SEO',
				'signals'       => array(
					'constants'    => array( 'RANK_MATH_VERSION' ),
					'classes'      => array( 'RankMath' ),
					'plugin_files' => array( 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' ),
				),
				'excluded_data' => array( 'Site-wide options, connected Google accounts and API keys.', 'Redirections and 404 logs.' ),
			),
			array(
				'slug'          => 'all-in-one-seo',
				'label'         => 'All in One SEO',
				'group'         => 'seo',
				'plugin_label'  => 'All in One SEO',
				'signals'       => array(
					'constants'    => array( 'AIOSEO_VERSION' ),
					'plugin_files' => array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ),
				),
				'excluded_data' => array( 'Site-wide options, connected accounts and API keys.', 'Redirects and link-assistant data.' ),
			),
			array(
				'slug'          => 'seopress',
				'label'         => 'SEOPress',
				'group'         => 'seo',
				'plugin_label'  => 'SEOPress',
				'signals'       => array(
					'constants'    => array( 'SEOPRESS_VERSION' ),
					'plugin_files' => array( 'wp-seopress/seopress.php', 'wp-seopress-pro/seopress-pro.php' ),
				),
				'excluded_data' => array( 'Site-wide options and API keys.' ),
			),

			/* ---- Forms ---- */
			array(
				'slug'          => 'fluent-forms',
				'label'         => 'Fluent Forms',
				'group'         => 'forms',
				'plugin_label'  => 'Fluent Forms',
				'signals'       => array(
					'constants'    => array( 'FLUENTFORM_VERSION' ),
					'plugin_files' => array( 'fluentform/fluentform.php', 'fluentformpro/fluentformpro.php' ),
				),
				'excluded_data' => array( 'Submissions.', 'Notification recipients and integration credentials.' ),
			),
			array(
				'slug'          => 'ninja-forms',
				'label'         => 'Ninja Forms',
				'group'         => 'forms',
				'plugin_label'  => 'Ninja Forms',
				'signals'       => array(
					'constants'    => array( 'NF_PLUGIN_VERSION' ),
					'classes'      => array( 'Ninja_Forms' ),
					'plugin_files' => array( 'ninja-forms/ninja-forms.php' ),
				),
				'excluded_data' => array( 'Submissions.', 'Notification recipients and integration credentials.' ),
			),

			/* ---- Events ---- */
			array(
				'slug'          => 'the-events-calendar',
				'label'         => 'The Events Calendar',
				'group'         => 'events',
				'plugin_label'  => 'The Events Calendar',
				'signals'       => array(
					'constants'    => array( 'TRIBE_EVENTS_FILE' ),
					'classes'      => array( 'Tribe__Events__Main' ),
					'plugin_files' => array( 'the-events-calendar/the-events-calendar.php' ),
				),
				'excluded_data' => array( 'Attendees, tickets and orders (Event Tickets).', 'Organizer and venue contact details.' ),
			),
			array(
				'slug'          => 'events-manager',
				'label'         => 'Events Manager',
				'group'         => 'events',
				'plugin_label'  => 'Events Manager',
				'signals'       => array(
					'constants'    => array( 'EM_VERSION' ),
					'plugin_files' => array( 'events-manager/events-manager.php' ),
				),
				'excluded_data' => array( 'Bookings and the personal data in them.' ),
			),

			/* ---- Membership ---- */
			array(
				'slug'          => 'memberpress',
				'label'         => 'MemberPress',
				'group'         => 'membership',
				'plugin_label'  => 'MemberPress',
				'signals'       => array(
					'constants'    => array( 'MEPR_VERSION' ),
					'plugin_files' => array( 'memberpress/memberpress.php' ),
				),
				'excluded_data' => array( 'Members, subscriptions, transactions and payment gateway credentials.' ),
			),
			array(
				'slug'          => 'paid-memberships-pro',
				'label'         => 'Paid Memberships Pro',
				'group'         => 'membership',
				'plugin_label'  => 'Paid Memberships Pro',
				'signals'       => array(
					'constants'    => array( 'PMPRO_VERSION' ),
					'plugin_files' => array( 'paid-memberships-pro/paid-memberships-pro.php' ),
				),
				'excluded_data' => array( 'Members, orders and payment gateway credentials.' ),
			),
			array(
				'slug'          => 'restrict-content-pro',
				'label'         => 'Restrict Content Pro',
				'group'         => 'membership',
				'plugin_label'  => 'Restrict Content Pro',
				'signals'       => array(
					'constants'    => array( 'RCP_PLUGIN_VERSION' ),
					'plugin_files' => array( 'restrict-content-pro/restrict-content-pro.php' ),
				),
				'excluded_data' => array( 'Members, payments and payment gateway credentials.' ),
			),

			/* ---- Mail / SMTP ---- */
			array(
				'slug'          => 'wp-mail-smtp',
				'label'         => 'WP Mail SMTP',
				'group'         => 'mail',
				'plugin_label'  => 'WP Mail SMTP',
				'signals'       => array(
					'constants'    => array( 'WPMS_PLUGIN_VER' ),
					'plugin_files' => array( 'wp-mail-smtp/wp_mail_smtp.php', 'wp-mail-smtp-pro/wp_mail_smtp.php' ),
				),
				'excluded_data' => array( 'SMTP hosts, usernames, passwords, API keys and OAuth tokens — under every circumstance.', 'E-mail log contents.' ),
			),
			array(
				'slug'          => 'fluent-smtp',
				'label'         => 'FluentSMTP',
				'group'         => 'mail',
				'plugin_label'  => 'FluentSMTP',
				'signals'       => array(
					'constants'    => array( 'FLUENTMAIL_PLUGIN_VERSION' ),
					'plugin_files' => array( 'fluent-smtp/fluent-smtp.php' ),
				),
				'excluded_data' => array( 'Connection credentials and API keys — under every circumstance.', 'E-mail log contents.' ),
			),
			array(
				'slug'          => 'post-smtp',
				'label'         => 'Post SMTP',
				'group'         => 'mail',
				'plugin_label'  => 'Post SMTP',
				'signals'       => array(
					'constants'    => array( 'POST_SMTP_VER' ),
					'plugin_files' => array( 'post-smtp/postman-smtp.php' ),
				),
				'excluded_data' => array( 'Connection credentials and OAuth tokens — under every circumstance.', 'E-mail log contents.' ),
			),

			/* ---- Security ---- */
			array(
				'slug'          => 'wordfence',
				'label'         => 'Wordfence Security',
				'group'         => 'security',
				'plugin_label'  => 'Wordfence Security',
				'signals'       => array(
					'constants'    => array( 'WORDFENCE_VERSION' ),
					'plugin_files' => array( 'wordfence/wordfence.php' ),
				),
				'excluded_data' => array( 'API keys and licence data.', 'Live traffic and blocked-IP logs, which are personal data.' ),
			),
			array(
				'slug'          => 'sucuri-scanner',
				'label'         => 'Sucuri Security',
				'group'         => 'security',
				'plugin_label'  => 'Sucuri Security',
				'signals'       => array(
					'constants'    => array( 'SUCURISCAN_VERSION' ),
					'plugin_files' => array( 'sucuri-scanner/sucuri.php' ),
				),
				'excluded_data' => array( 'API keys.', 'Audit logs, which are personal data.' ),
			),
			array(
				'slug'          => 'solid-security',
				'label'         => 'Solid Security (iThemes Security)',
				'group'         => 'security',
				'plugin_label'  => 'Solid Security',
				'signals'       => array(
					'classes'      => array( 'ITSEC_Core' ),
					'plugin_files' => array( 'better-wp-security/better-wp-security.php', 'ithemes-security-pro/ithemes-security-pro.php' ),
				),
				'excluded_data' => array( 'API keys and licence data.', 'Security logs, which are personal data.' ),
			),

			/* ---- Cache ---- */
			array(
				'slug'          => 'wp-rocket',
				'label'         => 'WP Rocket',
				'group'         => 'cache',
				'plugin_label'  => 'WP Rocket',
				'signals'       => array(
					'constants'    => array( 'WP_ROCKET_VERSION' ),
					'plugin_files' => array( 'wp-rocket/wp-rocket.php' ),
				),
				'excluded_data' => array( 'Licence keys.' ),
			),
			array(
				'slug'          => 'w3-total-cache',
				'label'         => 'W3 Total Cache',
				'group'         => 'cache',
				'plugin_label'  => 'W3 Total Cache',
				'signals'       => array(
					'constants'    => array( 'W3TC_VERSION' ),
					'plugin_files' => array( 'w3-total-cache/w3-total-cache.php' ),
				),
				'excluded_data' => array( 'CDN and object-cache credentials.' ),
			),
			array(
				'slug'          => 'wp-super-cache',
				'label'         => 'WP Super Cache',
				'group'         => 'cache',
				'plugin_label'  => 'WP Super Cache',
				'signals'       => array(
					'constants'    => array( 'WPCACHEHOME' ),
					'plugin_files' => array( 'wp-super-cache/wp-cache.php' ),
				),
				'excluded_data' => array( 'Filesystem paths of the cache directory.' ),
			),
			array(
				'slug'          => 'litespeed-cache',
				'label'         => 'LiteSpeed Cache',
				'group'         => 'cache',
				'plugin_label'  => 'LiteSpeed Cache',
				'signals'       => array(
					'constants'    => array( 'LSCWP_V' ),
					'plugin_files' => array( 'litespeed-cache/litespeed-cache.php' ),
				),
				'excluded_data' => array( 'QUIC.cloud credentials and API keys.' ),
			),

			/* ---- Analytics ---- */
			array(
				'slug'          => 'google-site-kit',
				'label'         => 'Site Kit by Google',
				'group'         => 'analytics',
				'plugin_label'  => 'Site Kit by Google',
				'signals'       => array(
					'constants'    => array( 'GOOGLESITEKIT_VERSION' ),
					'plugin_files' => array( 'google-site-kit/google-site-kit.php' ),
				),
				'excluded_data' => array( 'OAuth tokens and connected Google account data — under every circumstance.' ),
			),
			array(
				'slug'          => 'monsterinsights',
				'label'         => 'MonsterInsights',
				'group'         => 'analytics',
				'plugin_label'  => 'MonsterInsights',
				'signals'       => array(
					'constants'    => array( 'MONSTERINSIGHTS_VERSION' ),
					'plugin_files' => array( 'google-analytics-for-wordpress/googleanalytics.php', 'google-analytics-premium/googleanalytics-premium.php' ),
				),
				'excluded_data' => array( 'OAuth tokens, licence keys and connected account data.' ),
			),
			array(
				'slug'          => 'matomo',
				'label'         => 'Matomo Analytics',
				'group'         => 'analytics',
				'plugin_label'  => 'Matomo Analytics',
				'signals'       => array(
					'constants'    => array( 'MATOMO_ANALYTICS_FILE' ),
					'plugin_files' => array( 'matomo/matomo.php' ),
				),
				'excluded_data' => array( 'Auth tokens.', 'Visitor-level logs, which are personal data.' ),
			),
		);
	}

	/* ==================================================================
	 * Adapter access
	 * ================================================================ */

	/**
	 * Every adapter, keyed by slug.
	 *
	 * @return array<string,WP_MCP_Integration>
	 */
	public static function all() {
		if ( null !== self::$adapters ) {
			return self::$adapters;
		}

		$adapters = array();

		foreach ( self::covered_instances() as $adapter ) {
			$adapters[ $adapter->slug() ] = $adapter;
		}

		require_once __DIR__ . '/integrations/class-generic-integration.php';

		foreach ( self::detect_only_manifest() as $definition ) {
			$adapter                      = new WP_MCP_Generic_Integration( $definition );
			$adapters[ $adapter->slug() ] = $adapter;
		}

		self::$adapters = $adapters;

		return self::$adapters;
	}

	/**
	 * One adapter by slug.
	 *
	 * @param string $slug Adapter slug.
	 * @return WP_MCP_Integration|null
	 */
	public static function get( $slug ) {
		$adapters = self::all();
		$slug     = (string) $slug;

		return isset( $adapters[ $slug ] ) ? $adapters[ $slug ] : null;
	}

	/**
	 * Whether an integration is available on this site right now.
	 *
	 * @param string $slug Adapter slug.
	 * @return bool
	 */
	public static function is_available( $slug ) {
		$adapter = self::get( $slug );

		return null !== $adapter && $adapter->is_available();
	}

	/**
	 * Forget every memoized adapter and detection result.
	 *
	 * Used by the test suite, and by anything that activates or deactivates a
	 * plugin inside the same request.
	 */
	public static function flush() {
		if ( is_array( self::$adapters ) ) {
			foreach ( self::$adapters as $adapter ) {
				$adapter->flush_detection();
			}
		}

		self::$adapters = null;
	}

	/**
	 * Replace the adapter set.
	 *
	 * A test seam, and nothing else: it lets the suite exercise the framework
	 * — "an available adapter registers, an unavailable one does not" —
	 * without installing five third-party plugins in CI. Passing null
	 * restores the real manifests.
	 *
	 * @param array<int,WP_MCP_Integration>|null $adapters Adapters to use.
	 */
	public static function set_adapters( $adapters = null ) {
		if ( null === $adapters ) {
			self::flush();

			return;
		}

		$keyed = array();
		foreach ( $adapters as $adapter ) {
			if ( $adapter instanceof WP_MCP_Integration ) {
				$keyed[ $adapter->slug() ] = $adapter;
			}
		}

		self::$adapters = $keyed;
	}

	/* ==================================================================
	 * Registration
	 * ================================================================ */

	/**
	 * Register the abilities of every available integration.
	 *
	 * An unavailable integration registers nothing at all, so an MCP client
	 * is never offered a tool whose plugin is not installed.
	 */
	public static function register_available_abilities() {
		foreach ( self::all() as $adapter ) {
			if ( $adapter->is_covered() && $adapter->is_available() ) {
				$adapter->register_abilities();
			}
		}
	}

	/* ==================================================================
	 * Documentation surface
	 * ================================================================ */

	/**
	 * Permission-matrix rows contributed by the covered adapters.
	 *
	 * Every row carries an extra `integration` key naming the adapter it
	 * belongs to: the row is documented unconditionally, but the ability only
	 * exists when that integration is available.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function matrix_rows() {
		$rows = array();

		foreach ( self::covered_instances() as $adapter ) {
			foreach ( $adapter->ability_matrix() as $ability_name => $row ) {
				$row['integration']    = $adapter->slug();
				$rows[ $ability_name ] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Machine-readable status of every integration.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function summary() {
		$summary = array();

		foreach ( self::all() as $adapter ) {
			$detection = $adapter->detect();

			$summary[] = array(
				'slug'          => $adapter->slug(),
				'label'         => $adapter->label(),
				'group'         => $adapter->group(),
				'plugin'        => $adapter->plugin_label(),
				'detected'      => (bool) $detection['available'],
				'version'       => (string) $detection['version'],
				'detected_by'   => (string) $detection['detected_by'],
				'covered'       => $adapter->is_covered(),
				'ability_count' => count( $adapter->ability_names() ),
			);
		}

		return $summary;
	}

	/**
	 * Full description of one integration.
	 *
	 * @param string $slug Adapter slug.
	 * @return array<string,mixed>|null
	 */
	public static function describe( $slug ) {
		$adapter = self::get( $slug );

		if ( null === $adapter ) {
			return null;
		}

		$detection = $adapter->detect();
		$signals   = $adapter->signals();

		return array(
			'slug'                  => $adapter->slug(),
			'label'                 => $adapter->label(),
			'group'                 => $adapter->group(),
			'plugin'                => $adapter->plugin_label(),
			'detected'              => (bool) $detection['available'],
			'version'               => (string) $detection['version'],
			'detected_by'           => (string) $detection['detected_by'],
			'plugin_file'           => (string) $detection['plugin_file'],
			'covered'               => $adapter->is_covered(),
			'abilities'             => $adapter->ability_names(),
			'required_capabilities' => $adapter->required_capabilities(),
			'excluded_data'         => $adapter->excluded_data(),
			'detection_signals'     => self::flatten_signals( $signals ),
			'notes'                 => $adapter->notes(),
		);
	}

	/* ==================================================================
	 * Internals
	 * ================================================================ */

	/**
	 * Instantiate every covered adapter, loading its file first.
	 *
	 * Kept free of WordPress calls so the documentation-drift test can reach
	 * `matrix_rows()` without a WordPress install.
	 *
	 * @return array<int,WP_MCP_Integration>
	 */
	private static function covered_instances() {
		require_once __DIR__ . '/class-integration.php';

		$instances = array();

		foreach ( self::covered_manifest() as $entry ) {
			require_once __DIR__ . '/integrations/' . $entry['file'] . '.php';
			$class    = $entry['class'];
			$instance = new $class();
			if ( $instance instanceof WP_MCP_Integration ) {
				$instances[] = $instance;
			}
		}

		return $instances;
	}

	/**
	 * Flatten a `signals()` array into readable `kind:name` strings.
	 *
	 * @param array<string,mixed> $signals Signals.
	 * @return string[]
	 */
	private static function flatten_signals( $signals ) {
		$flat = array();
		$kind = array(
			'constants'    => 'constant',
			'classes'      => 'class',
			'functions'    => 'function',
			'plugin_files' => 'plugin',
		);

		foreach ( $kind as $key => $label ) {
			if ( ! isset( $signals[ $key ] ) || ! is_array( $signals[ $key ] ) ) {
				continue;
			}
			foreach ( $signals[ $key ] as $name ) {
				$flat[] = $label . ':' . $name;
			}
		}

		return $flat;
	}
}
