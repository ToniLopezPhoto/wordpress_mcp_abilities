<?php
/**
 * WordPress MCP Abilities — WP-Cron inspection and operation callbacks.
 *
 * Design constraints (epic #1, issue #12):
 *
 *  - A cron hook is a *selector validated against WordPress own registries*,
 *    never a dispatcher. `run-cron-event` only fires hooks that are already
 *    present in the site's cron array — jobs WordPress itself has queued and
 *    would run unattended anyway — and `schedule-cron-event` only accepts
 *    hooks that already have a registered listener (`has_action()`). A hook
 *    name that reaches neither gate is refused with
 *    `wp_mcp_cron_hook_denied` before anything is scheduled or fired.
 *  - On top of that, a hard denylist refuses the hooks whose side effects are
 *    site-wide and unattended (unattended core/plugin/theme auto-updates, the
 *    upgrader working-directory cleanup, and the deletion of the temporary
 *    update backups that make a failed update recoverable), plus the core
 *    request-lifecycle actions that are not cron jobs at all and must never be
 *    schedulable (`init`, `wp_loaded`, `shutdown`, ...) together with the
 *    `wp_ajax_`/`admin_`/`rest_` prefixes.
 *  - Event arguments are bounded scalars only. No callable, no array, no
 *    object, no serialized payload: nothing that could carry code.
 *  - Nothing here executes shell, PHP or SQL, and no ability accepts a
 *    callback or function name.
 *
 * Every ability in this file is gated on `manage_options`: the caller is an
 * administrator, so firing a queued job grants no capability the caller does
 * not already hold through wp-admin.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Cron
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Cron {

	/**
	 * Maximum number of arguments accepted for a scheduled event.
	 */
	const MAX_ARGS = 10;

	/**
	 * Maximum length, in characters, of a single string event argument.
	 */
	const MAX_ARG_LENGTH = 255;

	/**
	 * Maximum length, in characters, of a hook name.
	 */
	const MAX_HOOK_LENGTH = 191;

	/**
	 * Maximum number of events a single `run-due-cron-events` call may fire.
	 */
	const MAX_DUE_EVENTS = 50;

	/**
	 * How far into the future (in seconds) a one-off event may be scheduled.
	 */
	const MAX_SCHEDULE_HORIZON = YEAR_IN_SECONDS;

	/* ==================================================================
	 * Hook policy
	 * ================================================================ */

	/**
	 * Hooks this plugin never schedules, reschedules or fires.
	 *
	 * Two families, for two different reasons:
	 *
	 *  - Unattended-mutation cron hooks. `wp_maybe_auto_update` performs real
	 *    core/plugin/theme updates with no review step;
	 *    `upgrader_scheduled_cleanup` and `wp_delete_temp_updater_backups`
	 *    delete the upgrader working directories and the temporary backups
	 *    that make a failed update recoverable. The plugin/theme/core update
	 *    abilities from issue #9 are the explicit, reviewable way to update.
	 *  - Core request-lifecycle actions. These are not cron jobs; scheduling
	 *    one would run an entire request phase inside cron.
	 *
	 * @return string[]
	 */
	public static function denied_hooks() {
		return array(
			// Unattended mutation.
			'wp_maybe_auto_update',
			'upgrader_scheduled_cleanup',
			'wp_delete_temp_updater_backups',
			// Request lifecycle — never cron jobs.
			'muplugins_loaded',
			'plugins_loaded',
			'setup_theme',
			'after_setup_theme',
			'init',
			'wp_loaded',
			'parse_request',
			'send_headers',
			'wp',
			'template_redirect',
			'wp_head',
			'wp_footer',
			'shutdown',
		);
	}

	/**
	 * Hook name prefixes that are refused wholesale.
	 *
	 * @return string[]
	 */
	public static function denied_hook_prefixes() {
		return array( 'wp_ajax_', 'wp_ajax_nopriv_', 'admin_', 'rest_', 'load-' );
	}

	/**
	 * Whether a hook name is on the denylist.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public static function is_denied_hook( $hook ) {
		if ( in_array( $hook, self::denied_hooks(), true ) ) {
			return true;
		}
		foreach ( self::denied_hook_prefixes() as $prefix ) {
			if ( 0 === strpos( $hook, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the operability of a hook, with a stable machine-readable reason.
	 *
	 * @param string $hook Hook name.
	 * @return array{operable:bool,reason:string}
	 */
	public static function hook_policy( $hook ) {
		if ( self::is_denied_hook( $hook ) ) {
			return array(
				'operable' => false,
				'reason'   => 'denied_hook',
			);
		}
		if ( ! has_action( $hook ) ) {
			return array(
				'operable' => false,
				'reason'   => 'no_registered_action',
			);
		}
		return array(
			'operable' => true,
			'reason'   => 'operable',
		);
	}

	/**
	 * WordPress core's own maintenance hooks, which this plugin will schedule
	 * from scratch because they take no arguments and only ever perform the
	 * housekeeping WordPress already performs on its own timetable.
	 *
	 * Kept separate from `schedulable_core_hooks()` — which is this list *after*
	 * the site owner's filter — because membership of this fixed list is what
	 * triggers the no-arguments rule in `schedule_policy()`. "Core's own
	 * callbacks take no arguments" is only true of these names.
	 *
	 * @since 0.15.0
	 *
	 * @return string[]
	 */
	public static function core_maintenance_hooks() {
		return array(
			'wp_version_check',
			'wp_update_plugins',
			'wp_update_themes',
			'wp_scheduled_delete',
			'wp_scheduled_auto_draft_delete',
			'delete_expired_transients',
			'wp_privacy_delete_old_export_files',
			'recovery_mode_clean_expired_keys',
			'wp_site_health_scheduled_check',
			'wp_https_detection',
			'wp_update_user_counts',
		);
	}

	/**
	 * Hooks `wp-mcp/schedule-cron-event` may queue from scratch: core's
	 * maintenance hooks, plus whatever the site owner opted in server-side.
	 *
	 * @since 0.15.0
	 *
	 * @return string[]
	 */
	public static function schedulable_core_hooks() {
		$hooks = self::core_maintenance_hooks();

		/**
		 * Filters the hooks `wp-mcp/schedule-cron-event` may queue from scratch.
		 *
		 * Server-side only: this is how a site owner opts one of their own
		 * plugin's cron hooks into being schedulable by an agent. It is never
		 * reachable from MCP input, and the permanent denylist still wins.
		 *
		 * @since 0.15.0
		 *
		 * @param string[] $hooks Hook names schedulable without an existing event.
		 */
		$filtered = apply_filters( 'wp_mcp_cron_schedulable_hooks', $hooks );
		if ( ! is_array( $filtered ) ) {
			return $hooks;
		}

		return array_values( array_unique( array_filter( $filtered, 'is_string' ) ) );
	}

	/**
	 * Resolve whether a (hook, args) pair may be *scheduled*, which is a
	 * stricter question than whether an already-queued event may be listed,
	 * inspected, unscheduled or run.
	 *
	 * `has_action()` alone is not a policy: "any hook something listens to"
	 * covers most of WordPress and every installed plugin, which would make
	 * schedule-cron-event plus run-cron-event an `execute(action, args)`
	 * dispatcher — precisely the shape this plugin refuses to expose (epic #1,
	 * issue #15's "no arbitrary callbacks/functions/hooks"). Scheduling is
	 * therefore restricted to two cases:
	 *
	 *  - a hook from `schedulable_core_hooks()` — WordPress's own maintenance
	 *    hooks, plus whatever a site owner has opted in server-side through the
	 *    `wp_mcp_cron_schedulable_hooks` filter — because re-arming those is
	 *    exactly the housekeeping an agent should be able to do;
	 *  - a (hook, args) pair that WordPress *already has* in its cron array,
	 *    which only changes when an existing job runs, never what it runs.
	 *
	 * A hook from the fixed `core_maintenance_hooks()` list additionally has to
	 * come with **no arguments at all**. Core's own callbacks for those names
	 * take none, so any argument a caller supplies can only ever be delivered
	 * to a *third-party* listener that hooked the same name — and `wp_version_check`
	 * carrying caller-controlled scalars is a dispatch primitive wearing a core
	 * hook's name, which is exactly what this policy exists to prevent. Passing
	 * arguments therefore does not turn the fixed core list into a general
	 * allowlist: such a call still has to be a pair the site already queued.
	 *
	 * Everything else is refused with a stable `not_schedulable` reason.
	 *
	 * @since 0.15.0
	 *
	 * @param string $hook Hook name, already sanitised.
	 * @param array  $args Sanitised event arguments.
	 * @return array{operable:bool,reason:string}
	 */
	public static function schedule_policy( $hook, $args = array() ) {
		if ( self::is_denied_hook( $hook ) ) {
			return array(
				'operable' => false,
				'reason'   => 'denied_hook',
			);
		}

		$opted_in = in_array( $hook, self::schedulable_core_hooks(), true );
		$is_core  = in_array( $hook, self::core_maintenance_hooks(), true );

		if ( $opted_in && ( ! $is_core || array() === $args ) ) {
			return array(
				'operable' => true,
				'reason'   => 'operable',
			);
		}

		if ( false !== wp_get_scheduled_event( $hook, $args ) ) {
			return array(
				'operable' => true,
				'reason'   => 'operable',
			);
		}

		if ( $opted_in ) {
			return array(
				'operable' => false,
				'reason'   => 'core_hook_requires_no_args',
			);
		}

		return array(
			'operable' => false,
			'reason'   => 'not_schedulable',
		);
	}

	/* ==================================================================
	 * Read abilities
	 * ================================================================ */

	/**
	 * Report the site's WP-Cron configuration and queue summary.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_cron_status( $input = array() ) {
		$denied = self::require_cron_capability();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$events = self::flatten_events();
		$now    = time();
		$due    = 0;
		foreach ( $events as $event ) {
			if ( $event['timestamp'] <= $now ) {
				++$due;
			}
		}

		$schedules = array();
		foreach ( wp_get_schedules() as $name => $schedule ) {
			$schedules[] = array(
				'name'     => (string) $name,
				'interval' => (int) $schedule['interval'],
				'display'  => (string) $schedule['display'],
			);
		}

		$next = isset( $events[0] ) ? $events[0] : null;

		return array(
			'wp_cron_disabled'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_wp_cron'    => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'cron_lock_active'     => (bool) get_transient( 'doing_cron' ),
			'total_events'         => count( $events ),
			'due_events'           => $due,
			'next_event_hook'      => $next ? $next['hook'] : '',
			'next_event_timestamp' => $next ? $next['timestamp'] : 0,
			'next_event_utc'       => $next ? gmdate( 'c', $next['timestamp'] ) : '',
			'server_time_utc'      => gmdate( 'c', $now ),
			'schedules'            => $schedules,
		);
	}

	/**
	 * List scheduled cron events, oldest first.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_cron_events( $input = array() ) {
		$denied = self::require_cron_capability();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$events = self::flatten_events();

		if ( ! empty( $input['hook'] ) ) {
			$hook = self::sanitize_hook( $input['hook'] );
			if ( is_wp_error( $hook ) ) {
				return $hook;
			}
			$events = array_values( array_filter( $events, static function ( $event ) use ( $hook ) {
				return $event['hook'] === $hook;
			} ) );
		}

		if ( ! empty( $input['due_only'] ) ) {
			$now    = time();
			$events = array_values( array_filter( $events, static function ( $event ) use ( $now ) {
				return $event['timestamp'] <= $now;
			} ) );
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 20, 50 );

		$total  = count( $events );
		$offset = ( $page - 1 ) * $per_page;
		$slice  = array_slice( $events, $offset, $per_page );

		return array(
			'events'      => array_map( array( __CLASS__, 'format_event' ), $slice ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Read one scheduled cron event.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_cron_event( $input = array() ) {
		$denied = self::require_cron_capability();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$event = self::locate_event( $input );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		return self::format_event( $event );
	}

	/* ==================================================================
	 * Write abilities
	 * ================================================================ */

	/**
	 * Schedule (or reschedule) a cron event for an allowlisted hook.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function schedule_cron_event( $input = array() ) {
		$denied = self::require_cron_capability();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$hook = self::sanitize_hook( isset( $input['hook'] ) ? $input['hook'] : '' );
		if ( is_wp_error( $hook ) ) {
			return $hook;
		}

		if ( self::is_denied_hook( $hook ) ) {
			return self::denied_hook_error( $hook, 'denied_hook' );
		}

		$args = self::sanitize_args( isset( $input['args'] ) ? $input['args'] : array() );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		// Scheduling is gated by schedule_policy(), not hook_policy(): see the
		// method's docblock for why "any hook with a listener" is not a policy.
		$policy = self::schedule_policy( $hook, $args );
		if ( ! $policy['operable'] ) {
			return self::denied_hook_error( $hook, $policy['reason'] );
		}

		$timestamp = self::sanitize_timestamp( isset( $input['timestamp'] ) ? $input['timestamp'] : 0 );
		if ( is_wp_error( $timestamp ) ) {
			return $timestamp;
		}

		$recurrence = isset( $input['recurrence'] ) ? (string) $input['recurrence'] : '';
		$schedules  = wp_get_schedules();
		if ( '' !== $recurrence && ! isset( $schedules[ $recurrence ] ) ) {
			return WP_MCP_Errors::cron_validation_error(
				sprintf(
					/* translators: %s: recurrence name supplied by the caller */
					__( '"%s" is not a registered cron schedule on this site.', 'wordpress-mcp-abilities' ),
					$recurrence
				)
			);
		}

		// An existing event for the same hook and arguments is replaced rather
		// than duplicated, so the ability is idempotent on (hook, args).
		$existing = wp_get_scheduled_event( $hook, $args );
		if ( $existing ) {
			wp_unschedule_event( $existing->timestamp, $hook, $args );
		}

		$result = '' === $recurrence
			? wp_schedule_single_event( $timestamp, $hook, $args, true )
			: wp_schedule_event( $timestamp, $recurrence, $hook, $args, true );

		// The $wp_error argument above turns every refusal into a WP_Error, so
		// there is no separate boolean-false failure path to handle here.
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/schedule-cron-event', 0, false, 'wp_mcp_cron_schedule_failed', array( 'hook' => $hook ) );
			return WP_MCP_Errors::cron_schedule_failed( $result->get_error_message() );
		}

		$scheduled = wp_get_scheduled_event( $hook, $args );
		if ( ! $scheduled ) {
			return WP_MCP_Errors::cron_schedule_failed();
		}

		// Re-read the event from the cron array rather than rebuilding it, so
		// the reported signature is the exact key WordPress stored it under.
		$stored = self::locate_event( array(
			'hook'      => $hook,
			'timestamp' => (int) $scheduled->timestamp,
		) );
		if ( is_wp_error( $stored ) ) {
			return WP_MCP_Errors::cron_schedule_failed();
		}

		/*
		 * `event_timestamp`, not `timestamp`: `timestamp` is a base audit field
		 * (the moment the event was logged) and WP_MCP_Audit::log() drops any
		 * context key that would overwrite one, so a context key called
		 * `timestamp` records nothing at all. The time the job was queued for
		 * is the part worth auditing here.
		 */
		WP_MCP_Audit::log( 'wp-mcp/schedule-cron-event', 0, true, '', array(
			'hook'            => $hook,
			'recurrence'      => $recurrence,
			'event_timestamp' => $timestamp,
		) );

		return array(
			'event'     => self::format_event( $stored ),
			'replaced'  => (bool) $existing,
			'scheduled' => true,
		);
	}

	/**
	 * Unschedule one cron event, or every event for a hook.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function unschedule_cron_event( $input = array() ) {
		$denied = self::require_cron_capability();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$hook = self::sanitize_hook( isset( $input['hook'] ) ? $input['hook'] : '' );
		if ( is_wp_error( $hook ) ) {
			return $hook;
		}

		if ( self::is_denied_hook( $hook ) ) {
			return self::denied_hook_error( $hook, 'denied_hook' );
		}

		if ( ! empty( $input['all_occurrences'] ) ) {
			$removed = wp_unschedule_hook( $hook, true );
			if ( is_wp_error( $removed ) ) {
				WP_MCP_Audit::log( 'wp-mcp/unschedule-cron-event', 0, false, 'wp_mcp_cron_unschedule_failed', array( 'hook' => $hook ) );
				return WP_MCP_Errors::cron_unschedule_failed( $removed->get_error_message() );
			}

			WP_MCP_Audit::log( 'wp-mcp/unschedule-cron-event', 0, true, '', array(
				'hook'    => $hook,
				'removed' => (int) $removed,
			) );

			return array(
				'hook'      => $hook,
				'removed'   => (int) $removed,
				'remaining' => count( self::events_for_hook( $hook ) ),
			);
		}

		$event = self::locate_event( $input );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$result = wp_unschedule_event( $event['timestamp'], $event['hook'], $event['args'], true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/unschedule-cron-event', 0, false, 'wp_mcp_cron_unschedule_failed', array( 'hook' => $hook ) );
			return WP_MCP_Errors::cron_unschedule_failed( $result->get_error_message() );
		}

		WP_MCP_Audit::log( 'wp-mcp/unschedule-cron-event', 0, true, '', array(
			'hook'            => $hook,
			'event_timestamp' => $event['timestamp'],
		) );

		return array(
			'hook'      => $hook,
			'removed'   => 1,
			'remaining' => count( self::events_for_hook( $hook ) ),
		);
	}

	/**
	 * Run one scheduled cron event now.
	 *
	 * Only fires hooks that are *already queued* in the site's cron array —
	 * work WordPress would have run unattended — and never a hook name the
	 * caller invented. Recurring events are rescheduled and the fired
	 * occurrence removed first, exactly as WordPress own cron runner does, so
	 * the queue is left in the state a normal cron run would have produced.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function run_cron_event( $input = array() ) {
		$denied = self::require_cron_capability();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$event = self::locate_event( $input );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$policy = self::hook_policy( $event['hook'] );
		if ( ! $policy['operable'] ) {
			return self::denied_hook_error( $event['hook'], $policy['reason'] );
		}

		$fired = self::fire_event( $event );

		WP_MCP_Audit::log( 'wp-mcp/run-cron-event', 0, true, '', array(
			'hook'            => $event['hook'],
			'event_timestamp' => $event['timestamp'],
		) );

		return array(
			'hook'        => $event['hook'],
			'timestamp'   => $event['timestamp'],
			'rescheduled' => $fired['rescheduled'],
			'schedule'    => $event['schedule'],
			'ran'         => true,
		);
	}

	/**
	 * Run every cron event that is currently due.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function run_due_cron_events( $input = array() ) {
		$denied = self::require_cron_capability();
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : self::MAX_DUE_EVENTS;
		$limit = max( 1, min( self::MAX_DUE_EVENTS, $limit ) );

		$now     = time();
		$results = array();
		$ran     = 0;
		$skipped = 0;

		foreach ( self::flatten_events() as $event ) {
			if ( $event['timestamp'] > $now ) {
				break; // Events are ordered by timestamp; nothing later is due.
			}
			if ( count( $results ) >= $limit ) {
				break;
			}

			$policy = self::hook_policy( $event['hook'] );
			if ( ! $policy['operable'] ) {
				++$skipped;
				$results[] = array(
					'hook'      => $event['hook'],
					'timestamp' => $event['timestamp'],
					'ran'       => false,
					'reason'    => $policy['reason'],
				);
				continue;
			}

			self::fire_event( $event );
			++$ran;
			$results[] = array(
				'hook'      => $event['hook'],
				'timestamp' => $event['timestamp'],
				'ran'       => true,
				'reason'    => 'operable',
			);
		}

		WP_MCP_Audit::log( 'wp-mcp/run-due-cron-events', 0, true, '', array(
			'ran'     => $ran,
			'skipped' => $skipped,
		) );

		return array(
			'events'  => $results,
			'ran'     => $ran,
			'skipped' => $skipped,
			'limit'   => $limit,
		);
	}

	/* ==================================================================
	 * Internal helpers
	 * ================================================================ */

	/**
	 * Capability gate shared by every cron ability.
	 *
	 * @return true|WP_Error
	 */
	private static function require_cron_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return WP_MCP_Errors::system_permission_denied( __( 'You do not have permission to manage scheduled events.', 'wordpress-mcp-abilities' ) );
		}
		return true;
	}

	/**
	 * Turn a policy reason into the matching error object.
	 *
	 * @param string $hook   Hook name.
	 * @param string $reason Machine-readable reason.
	 * @return WP_Error
	 */
	private static function denied_hook_error( $hook, $reason ) {
		if ( 'core_hook_requires_no_args' === $reason ) {
			return WP_MCP_Errors::cron_hook_denied(
				$hook,
				sprintf(
					/* translators: %s: cron hook name supplied by the caller */
					__( 'The WordPress maintenance hook "%s" can only be scheduled with no arguments. Core\'s own callback takes none, so arguments supplied here could only reach a third-party listener on the same hook, which would turn this ability into a generic action dispatcher.', 'wordpress-mcp-abilities' ),
					$hook
				)
			);
		}
		if ( 'not_schedulable' === $reason ) {
			return WP_MCP_Errors::cron_hook_denied(
				$hook,
				sprintf(
					/* translators: %s: cron hook name supplied by the caller */
					__( 'The "%s" hook cannot be scheduled from scratch. Only WordPress core maintenance hooks, and events this site already has queued for that exact hook and arguments, may be scheduled — queuing an arbitrary hook would turn this ability into a generic action dispatcher.', 'wordpress-mcp-abilities' ),
					$hook
				)
			);
		}
		if ( 'no_registered_action' === $reason ) {
			return WP_MCP_Errors::cron_hook_denied(
				$hook,
				sprintf(
					/* translators: %s: cron hook name supplied by the caller */
					__( 'No listener is registered for the "%s" hook on this site, so scheduling or running it would do nothing. Only hooks a plugin or WordPress itself has registered are accepted.', 'wordpress-mcp-abilities' ),
					$hook
				)
			);
		}
		return WP_MCP_Errors::cron_hook_denied(
			$hook,
			sprintf(
				/* translators: %s: cron hook name supplied by the caller */
				__( 'The "%s" hook is on the permanent cron denylist: it either performs unattended site-wide updates or is a request-lifecycle action that is not a cron job.', 'wordpress-mcp-abilities' ),
				$hook
			)
		);
	}

	/**
	 * Every scheduled event, flattened and ordered by timestamp.
	 *
	 * @return array<int,array{hook:string,timestamp:int,signature:string,schedule:string,interval:int,args:array}>
	 */
	private static function flatten_events() {
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return array();
		}

		$events = array();
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $signatures ) {
				if ( ! is_array( $signatures ) ) {
					continue;
				}
				foreach ( $signatures as $signature => $data ) {
					$events[] = array(
						'hook'      => (string) $hook,
						'timestamp' => (int) $timestamp,
						'signature' => (string) $signature,
						'schedule'  => ( isset( $data['schedule'] ) && is_string( $data['schedule'] ) ) ? $data['schedule'] : '',
						'interval'  => isset( $data['interval'] ) ? (int) $data['interval'] : 0,
						'args'      => ( isset( $data['args'] ) && is_array( $data['args'] ) ) ? $data['args'] : array(),
					);
				}
			}
		}

		usort( $events, static function ( $a, $b ) {
			if ( $a['timestamp'] === $b['timestamp'] ) {
				return strcmp( $a['hook'], $b['hook'] );
			}
			return $a['timestamp'] < $b['timestamp'] ? -1 : 1;
		} );

		return $events;
	}

	/**
	 * Every scheduled event for one hook.
	 *
	 * @param string $hook Hook name.
	 * @return array<int,array>
	 */
	private static function events_for_hook( $hook ) {
		return array_values( array_filter( self::flatten_events(), static function ( $event ) use ( $hook ) {
			return $event['hook'] === $hook;
		} ) );
	}

	/**
	 * Resolve the event a caller is addressing: by hook, optionally narrowed
	 * by the signature returned by `list-cron-events` or by an exact
	 * timestamp. Without either, the next occurrence is used.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	private static function locate_event( $input ) {
		$hook = self::sanitize_hook( isset( $input['hook'] ) ? $input['hook'] : '' );
		if ( is_wp_error( $hook ) ) {
			return $hook;
		}

		$candidates = self::events_for_hook( $hook );
		if ( empty( $candidates ) ) {
			return WP_MCP_Errors::cron_event_not_found();
		}

		if ( ! empty( $input['signature'] ) ) {
			$signature  = (string) $input['signature'];
			$candidates = array_values( array_filter( $candidates, static function ( $event ) use ( $signature ) {
				return $event['signature'] === $signature;
			} ) );
		}

		if ( ! empty( $input['timestamp'] ) ) {
			$timestamp  = absint( $input['timestamp'] );
			$candidates = array_values( array_filter( $candidates, static function ( $event ) use ( $timestamp ) {
				return $event['timestamp'] === $timestamp;
			} ) );
		}

		if ( empty( $candidates ) ) {
			return WP_MCP_Errors::cron_event_not_found();
		}

		return $candidates[0];
	}

	/**
	 * Reschedule-then-fire one event, mirroring WordPress own cron runner.
	 *
	 * @param array $event Flattened event.
	 * @return array{rescheduled:bool}
	 */
	private static function fire_event( $event ) {
		$rescheduled = false;

		if ( '' !== $event['schedule'] ) {
			$result      = wp_reschedule_event( $event['timestamp'], $event['schedule'], $event['hook'], $event['args'], true );
			$rescheduled = ! is_wp_error( $result ) && false !== $result;
		}

		wp_unschedule_event( $event['timestamp'], $event['hook'], $event['args'] );

		do_action_ref_array( $event['hook'], $event['args'] );

		return array( 'rescheduled' => $rescheduled );
	}

	/**
	 * Shape a flattened event for an ability response.
	 *
	 * Arguments are reported as a JSON string rather than as a typed array:
	 * an event's arguments are whatever the scheduling plugin put there, and a
	 * closed output schema cannot promise a type for them.
	 *
	 * @param array $event Flattened event.
	 * @return array<string,mixed>
	 */
	private static function format_event( $event ) {
		$policy = self::hook_policy( $event['hook'] );

		return array(
			'hook'          => $event['hook'],
			'signature'     => $event['signature'],
			'timestamp'     => $event['timestamp'],
			'scheduled_utc' => gmdate( 'c', $event['timestamp'] ),
			'schedule'      => $event['schedule'],
			'interval'      => $event['interval'],
			'args_json'     => (string) wp_json_encode( array_values( $event['args'] ) ),
			'args_count'    => count( $event['args'] ),
			'is_due'        => $event['timestamp'] <= time(),
			'operable'      => $policy['operable'],
			'reason'        => $policy['reason'],
		);
	}

	/**
	 * Validate a hook name: a bounded, non-empty string of the characters
	 * WordPress hook names actually use.
	 *
	 * @param mixed $hook Raw input.
	 * @return string|WP_Error
	 */
	private static function sanitize_hook( $hook ) {
		if ( ! is_string( $hook ) || '' === trim( $hook ) ) {
			return WP_MCP_Errors::cron_validation_error( __( 'A cron hook name is required.', 'wordpress-mcp-abilities' ) );
		}
		$hook = trim( $hook );
		if ( strlen( $hook ) > self::MAX_HOOK_LENGTH ) {
			return WP_MCP_Errors::cron_validation_error( __( 'The cron hook name is too long.', 'wordpress-mcp-abilities' ) );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\/\-\.]+$/', $hook ) ) {
			return WP_MCP_Errors::cron_validation_error( __( 'The cron hook name contains characters WordPress hook names do not use.', 'wordpress-mcp-abilities' ) );
		}
		return $hook;
	}

	/**
	 * Validate event arguments: a short, flat list of scalars.
	 *
	 * @param mixed $args Raw input.
	 * @return array|WP_Error
	 */
	private static function sanitize_args( $args ) {
		if ( null === $args || '' === $args ) {
			return array();
		}
		if ( ! is_array( $args ) ) {
			return WP_MCP_Errors::cron_validation_error( __( 'Cron event arguments must be provided as an array.', 'wordpress-mcp-abilities' ) );
		}
		if ( count( $args ) > self::MAX_ARGS ) {
			return WP_MCP_Errors::cron_validation_error(
				sprintf(
					/* translators: %d: maximum number of cron event arguments */
					__( 'A cron event accepts at most %d arguments.', 'wordpress-mcp-abilities' ),
					self::MAX_ARGS
				)
			);
		}

		$clean = array();
		foreach ( array_values( $args ) as $arg ) {
			if ( is_string( $arg ) ) {
				if ( strlen( $arg ) > self::MAX_ARG_LENGTH ) {
					return WP_MCP_Errors::cron_validation_error( __( 'A cron event argument is too long.', 'wordpress-mcp-abilities' ) );
				}
				$clean[] = $arg;
				continue;
			}
			if ( is_int( $arg ) || is_float( $arg ) || is_bool( $arg ) ) {
				$clean[] = $arg;
				continue;
			}
			return WP_MCP_Errors::cron_validation_error( __( 'Cron event arguments must be strings, numbers or booleans. Arrays, objects and serialized payloads are never accepted.', 'wordpress-mcp-abilities' ) );
		}

		return $clean;
	}

	/**
	 * Validate a schedule timestamp, defaulting to "now".
	 *
	 * @param mixed $timestamp Raw input.
	 * @return int|WP_Error
	 */
	private static function sanitize_timestamp( $timestamp ) {
		$now = time();
		if ( empty( $timestamp ) ) {
			return $now;
		}
		/*
		 * (int) before absint(): absint() is abs( (int) $value ), so "-5" would
		 * silently become 5 — a valid-looking 1970 timestamp — instead of being
		 * refused as the negative value the caller actually sent.
		 */
		$is_integral = is_int( $timestamp ) || ( is_string( $timestamp ) && 1 === preg_match( '/^-?[0-9]+$/', $timestamp ) );
		if ( ! $is_integral || (int) $timestamp < 1 ) {
			return WP_MCP_Errors::cron_validation_error( __( 'The cron timestamp must be a positive Unix timestamp.', 'wordpress-mcp-abilities' ) );
		}
		$value = absint( $timestamp );
		if ( $value > $now + self::MAX_SCHEDULE_HORIZON ) {
			return WP_MCP_Errors::cron_validation_error( __( 'The cron timestamp is more than a year in the future.', 'wordpress-mcp-abilities' ) );
		}
		return $value;
	}
}
