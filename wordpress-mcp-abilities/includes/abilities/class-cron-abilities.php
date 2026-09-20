<?php
/**
 * WordPress MCP Abilities — Cron domain registration.
 *
 * Seven fixed abilities over WP-Cron. A hook name is a *selector* validated
 * against WordPress own registries — the cron array for anything that fires,
 * `has_action()` for anything that gets scheduled — plus a permanent denylist.
 * No ability here accepts a callback, a function name, or anything but bounded
 * scalar event arguments (see `WP_MCP_Cron`).
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Cron_Abilities
 */
class WP_MCP_Cron_Abilities {

	/** Register every cron ability. */
	public static function register() {
		self::register_reads();
		self::register_writes();
	}

	/* ------------------------------------------------------------------
	 * Reads
	 * ---------------------------------------------------------------- */

	/** Register the cron inspection abilities. */
	private static function register_reads() {
		wp_register_ability( 'wp-mcp/get-cron-status', array(
			'label'       => __( 'Get Cron Status', 'wordpress-mcp-abilities' ),
			'description' => __( 'Report the WP-Cron configuration, the size of the queue, the next event and the registered recurrence schedules.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(), array() ),
			'output_schema' => self::object_schema( array(
				'wp_cron_disabled'     => array( 'type' => 'boolean' ),
				'alternate_wp_cron'    => array( 'type' => 'boolean' ),
				'cron_lock_active'     => array( 'type' => 'boolean' ),
				'total_events'         => array( 'type' => 'integer' ),
				'due_events'           => array( 'type' => 'integer' ),
				'next_event_hook'      => array( 'type' => 'string' ),
				'next_event_timestamp' => array( 'type' => 'integer' ),
				'next_event_utc'       => array( 'type' => 'string' ),
				'server_time_utc'      => array( 'type' => 'string' ),
				'schedules'            => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'name'     => array( 'type' => 'string' ),
						'interval' => array( 'type' => 'integer' ),
						'display'  => array( 'type' => 'string' ),
					), array() ),
				),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Cron', 'get_cron_status' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/list-cron-events', array(
			'label'       => __( 'List Cron Events', 'wordpress-mcp-abilities' ),
			'description' => __( 'List the scheduled cron events, oldest first, with the operability verdict this plugin applies to each hook.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array_merge(
				array(
					'hook'     => array(
						'type'        => 'string',
						'description' => 'Optional hook name to restrict the listing to.',
						'maxLength'   => 191,
					),
					'due_only' => array(
						'type'        => 'boolean',
						'description' => 'List only events whose timestamp has already passed.',
					),
				),
				WP_MCP_Ability_Schema::pagination_input_properties()
			), array() ),
			'output_schema' => self::object_schema( array_merge(
				array(
					'events' => array(
						'type'  => 'array',
						'items' => self::event_schema(),
					),
				),
				WP_MCP_Ability_Schema::pagination_output_properties()
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Cron', 'list_cron_events' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );

		wp_register_ability( 'wp-mcp/get-cron-event', array(
			'label'       => __( 'Get Cron Event', 'wordpress-mcp-abilities' ),
			'description' => __( 'Read one scheduled cron event, addressed by hook and optionally narrowed by the signature or timestamp reported by list-cron-events.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( self::selector_properties(), array( 'hook' ) ),
			'output_schema' => self::event_schema(),
			'execute_callback'    => array( 'WP_MCP_Cron', 'get_cron_event' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_readonly(),
		) );
	}

	/* ------------------------------------------------------------------
	 * Writes
	 * ---------------------------------------------------------------- */

	/** Register the cron mutation abilities. */
	private static function register_writes() {
		wp_register_ability( 'wp-mcp/schedule-cron-event', array(
			'label'       => __( 'Schedule Cron Event', 'wordpress-mcp-abilities' ),
			'description' => __( 'Schedule a one-off or recurring cron event. Only two hooks are accepted: a WordPress core maintenance hook (with no arguments), or a hook and argument set this site already has queued, whose timing is then changed. Queuing an arbitrary hook is refused, because that would make this a generic action dispatcher. Any existing event for the same hook and arguments is replaced rather than duplicated.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(
				'hook'       => array(
					'type'        => 'string',
					'description' => 'Hook name. Must be a WordPress core maintenance hook scheduled with no arguments, or a hook this site already has queued with the same arguments, and must not be on the cron denylist.',
					'maxLength'   => 191,
				),
				'timestamp'  => array(
					'type'        => 'integer',
					'description' => 'Unix timestamp of the first run. Defaults to now; at most one year in the future.',
					'minimum'     => 1,
				),
				'recurrence' => array(
					'type'        => 'string',
					'description' => 'Registered recurrence name (hourly, twicedaily, daily, weekly, ...). Omit for a one-off event.',
					'maxLength'   => 191,
				),
				'args'       => array(
					'type'        => 'array',
					'description' => 'Optional event arguments. Bounded scalars only: strings, numbers or booleans. Arrays, objects and serialized payloads are never accepted.',
					'maxItems'    => 10,
					'items'       => array( 'type' => array( 'string', 'integer', 'number', 'boolean' ) ),
				),
			), array( 'hook' ) ),
			'output_schema' => self::object_schema( array(
				'event'     => self::event_schema(),
				'replaced'  => array( 'type' => 'boolean' ),
				'scheduled' => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Cron', 'schedule_cron_event' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, true ),
		) );

		wp_register_ability( 'wp-mcp/unschedule-cron-event', array(
			'label'       => __( 'Unschedule Cron Event', 'wordpress-mcp-abilities' ),
			'description' => __( 'Remove one scheduled cron event, or every occurrence of a hook.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array_merge(
				self::selector_properties(),
				array(
					'all_occurrences' => array(
						'type'        => 'boolean',
						'description' => 'Remove every scheduled occurrence of the hook instead of a single event.',
					),
				)
			), array( 'hook' ) ),
			'output_schema' => self::object_schema( array(
				'hook'      => array( 'type' => 'string' ),
				'removed'   => array( 'type' => 'integer' ),
				'remaining' => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Cron', 'unschedule_cron_event' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/run-cron-event', array(
			'label'       => __( 'Run Cron Event', 'wordpress-mcp-abilities' ),
			'description' => __( 'Run one already-scheduled cron event now. Only hooks present in the site cron queue can be fired, never a hook name invented by the caller. A recurring event is rescheduled and the fired occurrence removed first, exactly as WordPress own cron runner does.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( self::selector_properties(), array( 'hook' ) ),
			'output_schema' => self::object_schema( array(
				'hook'        => array( 'type' => 'string' ),
				'timestamp'   => array( 'type' => 'integer' ),
				'rescheduled' => array( 'type' => 'boolean' ),
				'schedule'    => array( 'type' => 'string' ),
				'ran'         => array( 'type' => 'boolean' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Cron', 'run_cron_event' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );

		wp_register_ability( 'wp-mcp/run-due-cron-events', array(
			'label'       => __( 'Run Due Cron Events', 'wordpress-mcp-abilities' ),
			'description' => __( 'Run every cron event that is currently due, skipping denylisted hooks and hooks with no registered listener and reporting why each was skipped.', 'wordpress-mcp-abilities' ),
			'category'    => 'wp-mcp-system',
			'input_schema' => self::object_schema( array(
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Maximum number of due events to consider (1-50).',
					'minimum'     => 1,
					'maximum'     => 50,
				),
			), array() ),
			'output_schema' => self::object_schema( array(
				'events'  => array(
					'type'  => 'array',
					'items' => self::object_schema( array(
						'hook'      => array( 'type' => 'string' ),
						'timestamp' => array( 'type' => 'integer' ),
						'ran'       => array( 'type' => 'boolean' ),
						'reason'    => array( 'type' => 'string' ),
					), array() ),
				),
				'ran'     => array( 'type' => 'integer' ),
				'skipped' => array( 'type' => 'integer' ),
				'limit'   => array( 'type' => 'integer' ),
			), array() ),
			'execute_callback'    => array( 'WP_MCP_Cron', 'run_due_cron_events' ),
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'meta'                => WP_MCP_Ability_Schema::meta_write( true, false ),
		) );
	}

	/* ------------------------------------------------------------------
	 * Shared schema fragments
	 * ---------------------------------------------------------------- */

	/**
	 * The (hook, signature, timestamp) selector every single-event ability takes.
	 *
	 * @return array<string,mixed>
	 */
	private static function selector_properties() {
		return array(
			'hook'      => array(
				'type'        => 'string',
				'description' => 'Hook name of the scheduled event.',
				'maxLength'   => 191,
			),
			'signature' => array(
				'type'        => 'string',
				'description' => 'Optional argument signature reported by list-cron-events, to pick one of several events sharing a hook.',
				'maxLength'   => 32,
			),
			'timestamp' => array(
				'type'        => 'integer',
				'description' => 'Optional exact Unix timestamp of the occurrence.',
				'minimum'     => 1,
			),
		);
	}

	/**
	 * The shape of one cron event in a response.
	 *
	 * @return array<string,mixed>
	 */
	private static function event_schema() {
		return self::object_schema( array(
			'hook'          => array( 'type' => 'string' ),
			'signature'     => array( 'type' => 'string' ),
			'timestamp'     => array( 'type' => 'integer' ),
			'scheduled_utc' => array( 'type' => 'string' ),
			'schedule'      => array( 'type' => 'string' ),
			'interval'      => array( 'type' => 'integer' ),
			'args_json'     => array( 'type' => 'string' ),
			'args_count'    => array( 'type' => 'integer' ),
			'is_due'        => array( 'type' => 'boolean' ),
			'operable'      => array( 'type' => 'boolean' ),
			'reason'        => array( 'type' => 'string', 'enum' => array( 'operable', 'denied_hook', 'no_registered_action' ) ),
		), array() );
	}

	/**
	 * @param array $properties Schema properties.
	 * @param array $required   Required property names.
	 * @return array<string,mixed>
	 */
	private static function object_schema( $properties, $required ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}
}
