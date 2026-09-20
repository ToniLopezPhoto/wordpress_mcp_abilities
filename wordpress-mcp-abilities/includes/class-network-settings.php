<?php
/**
 * WordPress MCP Abilities — Explicit network settings callbacks (issue #13).
 *
 * The Network Admin "Settings" screen through the same declarative,
 * hand-maintained allowlist model as the per-site settings of issue #10:
 *
 *  - There is no `get-network-option(key)` / `update-network-option(key)`.
 *    Clients never supply a WordPress network option name. They address
 *    *fields* from a fixed vocabulary (`network_name`, `registration`,
 *    `banned_email_domains`, ...) which this file maps server-side to
 *    network option names. An unlisted field is refused with
 *    `wp_mcp_settings_unknown_field` before anything is read or written.
 *  - `never_writable_options()` is a second, independent gate applied at
 *    write time, so a future bad allowlist entry cannot silently open one up.
 *
 * Deliberate exclusions, each for a stated reason rather than an oversight:
 *
 *  - `site_admins` — the list of Super Admins. Writing it is network takeover
 *    in a single call; Super Admin membership stays a human decision made in
 *    Network Admin (see also `WP_MCP_Network_Users`).
 *  - `admin_email` is readable but not writable, and `new_admin_email` is not
 *    exposed at all: WordPress changes the network admin email only through
 *    an emailed confirmation link, and writing the option directly would
 *    bypass that confirmation.
 *  - `siteurl` and the network's domain/path are not exposed: changing them
 *    can lock every site of the network out, and they are usually pinned in
 *    `wp-config.php` anyway.
 *  - `subdomain_install` is readable but not writable: it is decided at
 *    install time and backed by a constant, and flipping the stored option
 *    alone would leave every existing site unreachable.
 *  - `ms_files_rewriting`, `upload_path` and `upload_url_path` are not
 *    exposed: filesystem path configuration, which this plugin never accepts
 *    from an MCP client.
 *  - The "first post / first page / first comment" content templates are not
 *    exposed: they are site seeding content, not network policy, and every
 *    one of them is reachable through the content abilities once a site
 *    exists.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network_Settings
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Network_Settings {

	/** The single capability every field of this group requires. */
	const CAPABILITY = 'manage_network_options';

	/** Maximum accepted size, in bytes, of a free-text network setting. */
	const MAX_TEXT_BLOCK_BYTES = 8192;

	/** Maximum number of entries accepted in a name or domain list. */
	const MAX_LIST_ITEMS = 200;

	/* ==================================================================
	 * Allowlist
	 * ================================================================ */

	/**
	 * Every exposed network setting: field name => specification.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields() {
		return array(
			'network_name'                   => array(
				'option'      => 'site_name',
				'type'        => 'string',
				'max_length'  => 200,
				'description' => 'Network title shown in Network Admin and in the default welcome emails.',
			),
			'network_admin_email'            => array(
				'option'      => 'admin_email',
				'type'        => 'string',
				'readonly'    => true,
				'description' => 'Network administration email. Read-only: WordPress changes it only through an emailed confirmation link, and writing the option directly would bypass that confirmation.',
			),
			'subdomain_install'              => array(
				'handler'     => 'subdomain_install',
				'type'        => 'boolean',
				'readonly'    => true,
				'description' => 'Whether the network addresses sites as subdomains rather than subdirectories. Read-only: decided at install time and backed by a constant.',
			),
			'registration'                   => array(
				'option'      => 'registration',
				'type'        => 'enum',
				'enum'        => array( 'none', 'user', 'blog', 'all' ),
				'description' => 'Who may register: none, user (accounts only), blog (sites only for logged-in users), all.',
			),
			'registration_notification'      => array(
				'option'      => 'registrationnotification',
				'type'        => 'enum',
				'enum'        => array( 'yes', 'no' ),
				'description' => 'Whether the network administrator is emailed about every new user and site.',
			),
			'add_new_users'                  => array(
				'option'      => 'add_new_users',
				'type'        => 'boolean',
				'description' => 'Whether site administrators may add new users to their site.',
			),
			'site_admins_can_manage_plugins' => array(
				'handler'     => 'menu_items_plugins',
				'type'        => 'boolean',
				'description' => 'Whether the Plugins administration menu is enabled for site administrators.',
			),
			'illegal_names'                  => array(
				'option'      => 'illegal_names',
				'type'        => 'name_array',
				'description' => 'Site names and usernames nobody may register.',
			),
			'limited_email_domains'          => array(
				'option'      => 'limited_email_domains',
				'type'        => 'domain_array',
				'description' => 'If not empty, registration is limited to email addresses in these domains.',
			),
			'banned_email_domains'           => array(
				'option'      => 'banned_email_domains',
				'type'        => 'domain_array',
				'description' => 'Registration is refused for email addresses in these domains.',
			),
			'welcome_email'                  => array(
				'option'      => 'welcome_email',
				'type'        => 'text_block',
				'description' => 'Email sent to the administrator of a newly created site.',
			),
			'welcome_user_email'             => array(
				'option'      => 'welcome_user_email',
				'type'        => 'text_block',
				'description' => 'Email sent to a newly registered user.',
			),
			'blog_upload_space'              => array(
				'option'      => 'blog_upload_space',
				'type'        => 'integer',
				'min'         => 1,
				'max'         => 10000,
				'description' => 'Default upload space per site, in megabytes.',
			),
			'upload_space_check_disabled'    => array(
				'option'      => 'upload_space_check_disabled',
				'type'        => 'boolean',
				'description' => 'Whether the per-site upload space limit is ignored.',
			),
			'fileupload_maxk'                => array(
				'option'      => 'fileupload_maxk',
				'type'        => 'integer',
				'min'         => 1,
				'max'         => 1048576,
				'description' => 'Maximum size of an individual uploaded file, in kilobytes.',
			),
			'upload_filetypes'               => array(
				'option'      => 'upload_filetypes',
				'type'        => 'filetypes',
				'description' => 'Space-separated list of file extensions site administrators may upload.',
			),
			'default_language'               => array(
				'handler'     => 'locale',
				'option'      => 'WPLANG',
				'type'        => 'string',
				'max_length'  => 20,
				'description' => 'Default language for new sites: "en_US", or any locale already installed on this network. Installing new language packs is out of scope.',
			),
		);
	}

	/**
	 * Network option names this domain must never write, whatever the
	 * allowlist says. Enforced at write time as defence in depth, and
	 * asserted against the allowlist by the test suite.
	 *
	 * @return string[]
	 */
	public static function never_writable_options() {
		return array(
			'site_admins',
			'admin_email',
			'new_admin_email',
			'siteurl',
			'subdomain_install',
			'ms_files_rewriting',
			'active_sitewide_plugins',
			'allowedthemes',
			'wpmu_upgrade_site',
			'db_version',
			'initial_db_version',
			'upload_path',
			'upload_url_path',
			'blog_count',
			'user_count',
			'global_terms_enabled',
		);
	}

	/**
	 * Every network option name reachable for writing through the allowlist.
	 *
	 * @return string[]
	 */
	public static function writable_option_keys() {
		$keys = array();
		foreach ( self::fields() as $spec ) {
			if ( empty( $spec['readonly'] ) && isset( $spec['option'] ) ) {
				$keys[] = $spec['option'];
			}
		}
		// The plugins-menu field writes this option through its own handler.
		$keys[] = 'menu_items';

		return array_values( array_unique( $keys ) );
	}

	/* ==================================================================
	 * Ability callbacks
	 * ================================================================ */

	/**
	 * Read every allowlisted network setting.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function get_network_settings( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( self::CAPABILITY );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		return self::read_values();
	}

	/**
	 * Apply a partial update to the allowlisted network settings.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_network_settings( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( self::CAPABILITY );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! is_array( $input ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'Network settings input must be an object of field name to value.', 'wordpress-mcp-abilities' ) );
		}

		$fields  = self::fields();
		$payload = array();
		foreach ( $input as $field => $value ) {
			if ( ! is_string( $field ) || ! isset( $fields[ $field ] ) ) {
				return WP_MCP_Errors::settings_unknown_field( is_string( $field ) ? $field : '' );
			}
			$spec = $fields[ $field ];
			if ( ! empty( $spec['readonly'] ) ) {
				return WP_MCP_Errors::settings_readonly_field( $field );
			}
			$clean = self::sanitize_field( $field, $spec, $value );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$payload[ $field ] = $clean;
		}

		if ( empty( $payload ) ) {
			return WP_MCP_Errors::settings_validation_error( __( 'At least one network settings field is required.', 'wordpress-mcp-abilities' ) );
		}

		$changed = array();
		foreach ( $payload as $field => $clean ) {
			$spec    = $fields[ $field ];
			$old     = self::read_field( $spec );
			$written = self::write_field( $spec, $clean );
			if ( is_wp_error( $written ) ) {
				WP_MCP_Audit::log( 'wp-mcp/update-network-settings', 0, false, $written->get_error_code(), array( 'field' => $field ) );
				return $written;
			}
			$new = self::read_field( $spec );
			if ( $old === $new ) {
				continue;
			}
			$changed[] = $field;
		}

		WP_MCP_Audit::log( 'wp-mcp/update-network-settings', 0, true, '', array( 'fields' => implode( ',', $changed ) ) );

		return array(
			'updated'  => $changed,
			'settings' => self::read_values(),
		);
	}

	/**
	 * Describe the network settings allowlist itself, without reading any
	 * stored value.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function list_network_settings_fields( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( self::CAPABILITY );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$fields = array();
		foreach ( self::fields() as $field => $spec ) {
			$entry = array(
				'field'       => $field,
				'writable'    => empty( $spec['readonly'] ),
				'type'        => self::public_type( $spec ),
				'capability'  => self::CAPABILITY,
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

		return array(
			'fields' => $fields,
			'total'  => count( $fields ),
		);
	}

	/* ==================================================================
	 * Schema helpers — single source of truth shared with registration
	 * ================================================================ */

	/**
	 * JSON Schema properties for the writable fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function input_schema_properties() {
		return self::schema_properties( true );
	}

	/**
	 * JSON Schema properties for every field, writable or not.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function output_schema_properties() {
		return self::schema_properties( false );
	}

	/**
	 * @param bool $writable_only Whether to skip read-only fields.
	 * @return array<string,array<string,mixed>>
	 */
	private static function schema_properties( $writable_only ) {
		$properties = array();
		foreach ( self::fields() as $field => $spec ) {
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
			case 'name_array':
			case 'domain_array':
				return array(
					'type'        => 'array',
					'description' => $description,
					'items'       => array( 'type' => 'string', 'minLength' => 1 ),
					'maxItems'    => self::MAX_LIST_ITEMS,
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
	 * Coarse type name reported by list-network-settings-fields.
	 *
	 * @param array $spec Field spec.
	 * @return string
	 */
	private static function public_type( $spec ) {
		switch ( $spec['type'] ) {
			case 'boolean':
			case 'integer':
			case 'enum':
				return $spec['type'];
			case 'name_array':
			case 'domain_array':
				return 'array';
			default:
				return 'string';
		}
	}

	/* ==================================================================
	 * Engine
	 * ================================================================ */

	/**
	 * Read every field. Capability must already have been checked.
	 *
	 * @return array<string,mixed>
	 */
	private static function read_values() {
		$values = array();
		foreach ( self::fields() as $field => $spec ) {
			$values[ $field ] = self::read_field( $spec );
		}
		return $values;
	}

	/**
	 * Read one field's current value in its declared shape.
	 *
	 * @param array $spec Field spec.
	 * @return mixed
	 */
	private static function read_field( $spec ) {
		$handler = isset( $spec['handler'] ) ? $spec['handler'] : '';

		if ( 'subdomain_install' === $handler ) {
			return (bool) is_subdomain_install();
		}
		if ( 'menu_items_plugins' === $handler ) {
			$menu_items = (array) get_network_option( null, 'menu_items', array() );
			return ! empty( $menu_items['plugins'] );
		}
		if ( 'locale' === $handler ) {
			$stored = (string) get_network_option( null, 'WPLANG', '' );
			return '' === $stored ? 'en_US' : $stored;
		}

		$raw = get_network_option( null, $spec['option'], '' );

		switch ( $spec['type'] ) {
			case 'boolean':
				return (bool) $raw;
			case 'integer':
				return (int) $raw;
			case 'name_array':
			case 'domain_array':
				return array_values( array_map( 'strval', (array) $raw ) );
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

		if ( 'menu_items_plugins' === $handler ) {
			$menu_items            = (array) get_network_option( null, 'menu_items', array() );
			$menu_items['plugins'] = $value ? 1 : 0;
			update_network_option( null, 'menu_items', $menu_items );
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

		if ( 'boolean' === $spec['type'] ) {
			update_network_option( null, $option, $value ? 1 : 0 );
			return true;
		}

		update_network_option( null, $option, $value );
		return true;
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
		if ( isset( $spec['handler'] ) && 'locale' === $spec['handler'] ) {
			return self::sanitize_locale( $field, $value );
		}

		switch ( $spec['type'] ) {
			case 'boolean':
				return self::sanitize_boolean( $field, $value );
			case 'integer':
				return self::sanitize_integer( $field, $spec, $value );
			case 'enum':
				return self::sanitize_enum( $field, $spec, $value );
			case 'name_array':
				return self::sanitize_name_array( $field, $value );
			case 'domain_array':
				return self::sanitize_domain_array( $field, $value );
			case 'text_block':
				return self::sanitize_text_block( $field, $value );
			case 'filetypes':
				return self::sanitize_filetypes( $field, $value );
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
		if ( 1 === $value || 0 === $value ) {
			return 1 === $value;
		}
		return WP_MCP_Errors::settings_validation_error(
			sprintf(
				/* translators: %s: field name */
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
		if ( ! is_int( $value ) && ! ( is_string( $value ) && 1 === preg_match( '/^[0-9]+$/', $value ) ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: field name */
					__( '%s must be an integer.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		$int = (int) $value;
		if ( isset( $spec['min'] ) && $int < (int) $spec['min'] ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: 1: field name, 2: minimum value */
					__( '%1$s must be at least %2$d.', 'wordpress-mcp-abilities' ),
					$field,
					(int) $spec['min']
				)
			);
		}
		if ( isset( $spec['max'] ) && $int > (int) $spec['max'] ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: 1: field name, 2: maximum value */
					__( '%1$s must be at most %2$d.', 'wordpress-mcp-abilities' ),
					$field,
					(int) $spec['max']
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
		if ( ! is_string( $value ) || ! in_array( $value, $spec['enum'], true ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: 1: field name, 2: comma-separated accepted values */
					__( '%1$s must be one of: %2$s.', 'wordpress-mcp-abilities' ),
					$field,
					implode( ', ', $spec['enum'] )
				)
			);
		}
		return $value;
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
					/* translators: %s: field name */
					__( '%s must be a string.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		$clean  = sanitize_text_field( $value );
		$length = isset( $spec['max_length'] ) ? (int) $spec['max_length'] : 200;
		if ( strlen( $clean ) > $length ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: 1: field name, 2: maximum length */
					__( '%1$s must be at most %2$d characters.', 'wordpress-mcp-abilities' ),
					$field,
					$length
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
					/* translators: %s: field name */
					__( '%s must be a string.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		if ( strlen( $value ) > self::MAX_TEXT_BLOCK_BYTES ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: 1: field name, 2: maximum size in bytes */
					__( '%1$s must be at most %2$d bytes.', 'wordpress-mcp-abilities' ),
					$field,
					self::MAX_TEXT_BLOCK_BYTES
				)
			);
		}
		return sanitize_textarea_field( $value );
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string[]|WP_Error
	 */
	private static function sanitize_name_array( $field, $value ) {
		$items = self::validate_list( $field, $value );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$clean = array();
		foreach ( $items as $item ) {
			$name = strtolower( trim( $item ) );
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_\-]{0,59}$/', $name ) ) {
				return WP_MCP_Errors::settings_validation_error(
					sprintf(
						/* translators: %s: field name */
						__( 'Every entry of %s must be lowercase letters, digits, underscores and hyphens.', 'wordpress-mcp-abilities' ),
						$field
					)
				);
			}
			$clean[] = $name;
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string[]|WP_Error
	 */
	private static function sanitize_domain_array( $field, $value ) {
		$items = self::validate_list( $field, $value );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$clean = array();
		foreach ( $items as $item ) {
			$domain = strtolower( trim( $item, " \t\n\r\0\x0B." ) );
			if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9.\-]{0,251}[a-z0-9])?$/', $domain ) || false === strpos( $domain, '.' ) ) {
				return WP_MCP_Errors::settings_validation_error(
					sprintf(
						/* translators: %s: field name */
						__( 'Every entry of %s must be a bare domain name such as "example.com".', 'wordpress-mcp-abilities' ),
						$field
					)
				);
			}
			$clean[] = $domain;
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string[]|WP_Error
	 */
	private static function validate_list( $field, $value ) {
		if ( ! is_array( $value ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: field name */
					__( '%s must be an array of strings.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		if ( count( $value ) > self::MAX_LIST_ITEMS ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: 1: field name, 2: maximum number of entries */
					__( '%1$s accepts at most %2$d entries.', 'wordpress-mcp-abilities' ),
					$field,
					self::MAX_LIST_ITEMS
				)
			);
		}
		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return WP_MCP_Errors::settings_validation_error(
					sprintf(
						/* translators: %s: field name */
						__( 'Every entry of %s must be a string.', 'wordpress-mcp-abilities' ),
						$field
					)
				);
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Upload file types: a space-separated list of bare extensions, never a
	 * path, a MIME type or a wildcard.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_filetypes( $field, $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 1000 ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: field name */
					__( '%s must be a string of at most 1000 characters.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		$parts = preg_split( '/[\s,]+/', strtolower( trim( $value ) ) );
		if ( ! is_array( $parts ) ) {
			$parts = array();
		}
		$clean = array();
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[a-z0-9]{1,15}$/', $part ) ) {
				return WP_MCP_Errors::settings_validation_error(
					sprintf(
						/* translators: %s: field name */
						__( 'Every entry of %s must be a bare file extension such as "jpg".', 'wordpress-mcp-abilities' ),
						$field
					)
				);
			}
			$clean[] = $part;
		}
		if ( empty( $clean ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: field name */
					__( '%s must list at least one file extension.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		return implode( ' ', array_values( array_unique( $clean ) ) );
	}

	/**
	 * A locale already installed on this network, or en_US. Installing a new
	 * language pack is out of scope.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string|WP_Error
	 */
	private static function sanitize_locale( $field, $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Za-z0-9_\-]{2,20}$/', $value ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: field name */
					__( '%s must be a locale identifier such as "en_US".', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		if ( 'en_US' === $value ) {
			return '';
		}
		if ( ! in_array( $value, get_available_languages(), true ) ) {
			return WP_MCP_Errors::settings_validation_error(
				sprintf(
					/* translators: %s: field name */
					__( '%s must be a locale already installed on this network.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		return $value;
	}
}
