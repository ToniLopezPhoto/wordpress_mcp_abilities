<?php
/**
 * WordPress MCP Abilities — Network site callbacks (issue #13).
 *
 * The Network Admin "Sites" screen, expressed as fixed operations.
 *
 * Security notes specific to this domain:
 *
 *  - **A site is created from a slug, never from a domain and path.** The
 *    caller supplies `slug`; this file derives the domain and path from the
 *    network's own configuration exactly as `wp-admin/network/site-new.php`
 *    does. A client that could name the domain could point a new site at a
 *    host of its choosing, which is a redirect and cookie-scope problem, not
 *    a convenience.
 *  - **Domain and path are never editable.** `update-network-site` changes
 *    the title, the public flag and the mature flag only; moving a live site
 *    to another address is a migration, not a metadata edit.
 *  - **The main site is never archived, deactivated, marked as spam or
 *    deleted**, and neither is the site the request is currently running on:
 *    both would end the network administrator's own access mid-request.
 *    WordPress's own Network Admin hides those actions for the main site for
 *    the same reason.
 *  - Every operation is gated on the concrete network capability
 *    (`manage_sites`, plus `create_sites` / `delete_sites` where WordPress
 *    itself requires them), never on `manage_options`.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Network_Sites
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Network_Sites {

	/** Maximum number of sites returned by one list request. */
	const MAX_PER_PAGE = 50;

	/** Maximum accepted length of a site title. */
	const MAX_TITLE_LENGTH = 200;

	/** Maximum accepted length of a site slug. */
	const MAX_SLUG_LENGTH = 63;

	/**
	 * Status filters accepted by list-network-sites.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'all', 'active', 'public', 'archived', 'mature', 'spam', 'deleted' );

	/* ------------------------------------------------------------------
	 * Reads
	 * ---------------------------------------------------------------- */

	/**
	 * List the sites of the current network with bounded pagination.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function list_network_sites( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( 'manage_sites' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$status = isset( $input['status'] ) ? $input['status'] : 'all';
		if ( ! is_string( $status ) || ! in_array( $status, self::STATUSES, true ) ) {
			return WP_MCP_Errors::network_validation_error( __( 'status must be one of: all, active, public, archived, mature, spam, deleted.', 'wordpress-mcp-abilities' ) );
		}

		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 20, self::MAX_PER_PAGE );

		$args = array(
			'network_id' => get_current_network_id(),
			'orderby'    => 'id',
			'order'      => 'ASC',
		);
		$args = array_merge( $args, self::status_args( $status ) );

		if ( ! empty( $input['search'] ) && is_string( $input['search'] ) ) {
			$args['search'] = sanitize_text_field( $input['search'] );
		}

		$total = WP_MCP_Network::count_sites( $args );

		$args['number'] = $per_page;
		$args['offset'] = ( $page - 1 ) * $per_page;

		$sites = array();
		foreach ( WP_MCP_Network::query_sites( $args ) as $site ) {
			$sites[] = self::format_site( $site );
		}

		return array(
			'sites'       => $sites,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get one site of the current network.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_network_site( $input = array() ) {
		$site = self::require_site( $input, 'manage_sites' );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		return self::format_site( $site );
	}

	/* ------------------------------------------------------------------
	 * Writes
	 * ---------------------------------------------------------------- */

	/**
	 * Create a site on the current network from a slug.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function create_network_site( $input = array() ) {
		$denied = WP_MCP_Network::require_network_cap( 'manage_sites' );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! current_user_can( 'create_sites' ) ) {
			return WP_MCP_Errors::network_permission_denied( __( 'Creating sites requires the create_sites capability on this network.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$slug = self::validate_slug( isset( $input['slug'] ) ? $input['slug'] : '' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$title = isset( $input['title'] ) && is_string( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
		if ( '' === $title || strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			return WP_MCP_Errors::network_validation_error(
				sprintf(
					/* translators: %d: maximum site title length */
					__( 'title is required and must be at most %d characters.', 'wordpress-mcp-abilities' ),
					self::MAX_TITLE_LENGTH
				)
			);
		}

		$admin_id = isset( $input['admin_user_id'] ) ? absint( $input['admin_user_id'] ) : 0;
		if ( $admin_id < 1 || ! get_userdata( $admin_id ) ) {
			return WP_MCP_Errors::network_validation_error( __( 'admin_user_id must reference an existing user. This ability never creates a user implicitly.', 'wordpress-mcp-abilities' ) );
		}

		$address = self::derive_address( $slug );
		if ( is_wp_error( $address ) ) {
			return $address;
		}

		if ( domain_exists( $address['domain'], $address['path'], get_current_network_id() ) ) {
			return WP_MCP_Errors::network_conflict( __( 'A site already exists at that address on this network.', 'wordpress-mcp-abilities' ) );
		}

		$public  = ! isset( $input['public'] ) || (bool) $input['public'];
		$site_id = wpmu_create_blog(
			$address['domain'],
			$address['path'],
			$title,
			$admin_id,
			array( 'public' => $public ? 1 : 0 ),
			get_current_network_id()
		);

		if ( is_wp_error( $site_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-network-site', 0, false, 'wp_mcp_network_site_create_failed', array( 'slug' => $slug ) );
			return WP_MCP_Errors::network_site_create_failed( $site_id->get_error_message() );
		}

		$site = get_site( (int) $site_id );
		if ( ! $site instanceof WP_Site ) {
			WP_MCP_Audit::log( 'wp-mcp/create-network-site', 0, false, 'wp_mcp_network_site_create_failed', array( 'slug' => $slug ) );
			return WP_MCP_Errors::network_site_create_failed( __( 'The site was created but could not be read back.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/create-network-site', (int) $site_id, true, '', array( 'slug' => $slug ) );
		return self::format_site( $site );
	}

	/**
	 * Update the supported metadata of a site: title, public flag, mature
	 * flag. Domain and path are never writable.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function update_network_site( $input = array() ) {
		$site = self::require_site( $input, 'manage_sites' );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$site_id = (int) $site->id;
		$changed = array();

		if ( array_key_exists( 'title', $input ) ) {
			$title = is_string( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
			if ( '' === $title || strlen( $title ) > self::MAX_TITLE_LENGTH ) {
				return WP_MCP_Errors::network_validation_error(
					sprintf(
						/* translators: %d: maximum site title length */
						__( 'title must be a non-empty string of at most %d characters.', 'wordpress-mcp-abilities' ),
						self::MAX_TITLE_LENGTH
					)
				);
			}
			if ( (string) get_blog_option( $site_id, 'blogname', '' ) !== $title ) {
				update_blog_option( $site_id, 'blogname', $title );
				$changed[] = 'title';
			}
		}

		if ( array_key_exists( 'public', $input ) ) {
			$public = self::validate_boolean( 'public', $input['public'] );
			if ( is_wp_error( $public ) ) {
				return $public;
			}
			if ( ( $public ? 1 : 0 ) !== (int) $site->public ) {
				$updated = wp_update_site( $site_id, array( 'public' => $public ? 1 : 0 ) );
				if ( is_wp_error( $updated ) ) {
					return WP_MCP_Errors::network_site_update_failed( $updated->get_error_message() );
				}
				$changed[] = 'public';
			}
			/*
			 * The wp_blogs.public column and the site's own blog_public
			 * option are two separate stores that Network Admin keeps in
			 * step; writing only one of them leaves the site listed as
			 * public while its own Reading settings say otherwise.
			 */
			if ( (int) get_blog_option( $site_id, 'blog_public', 1 ) !== ( $public ? 1 : 0 ) ) {
				update_blog_option( $site_id, 'blog_public', $public ? 1 : 0 );
			}
		}

		if ( array_key_exists( 'mature', $input ) ) {
			$mature = self::validate_boolean( 'mature', $input['mature'] );
			if ( is_wp_error( $mature ) ) {
				return $mature;
			}
			if ( ( $mature ? 1 : 0 ) !== (int) $site->mature ) {
				$updated = wp_update_site( $site_id, array( 'mature' => $mature ? 1 : 0 ) );
				if ( is_wp_error( $updated ) ) {
					return WP_MCP_Errors::network_site_update_failed( $updated->get_error_message() );
				}
				$changed[] = 'mature';
			}
		}

		if ( ! array_key_exists( 'title', $input ) && ! array_key_exists( 'public', $input ) && ! array_key_exists( 'mature', $input ) ) {
			return WP_MCP_Errors::network_validation_error( __( 'At least one of title, public or mature is required.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/update-network-site', $site_id, true, '', array( 'fields' => implode( ',', $changed ) ) );

		$refreshed = get_site( $site_id );
		return self::format_site( $refreshed instanceof WP_Site ? $refreshed : $site );
	}

	/**
	 * Archive a site (`archived = 1`).
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function archive_network_site( $input = array() ) {
		return self::set_site_flag( $input, 'archived', 1, 'wp-mcp/archive-network-site', true );
	}

	/**
	 * Un-archive a site (`archived = 0`).
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function unarchive_network_site( $input = array() ) {
		return self::set_site_flag( $input, 'archived', 0, 'wp-mcp/unarchive-network-site', false );
	}

	/**
	 * Activate a site — Network Admin's "Activate", which clears the
	 * `deleted` flag rather than removing anything.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function activate_network_site( $input = array() ) {
		return self::set_site_flag( $input, 'deleted', 0, 'wp-mcp/activate-network-site', false );
	}

	/**
	 * Deactivate a site — Network Admin's "Deactivate", which sets the
	 * `deleted` flag. The site's data is untouched; `delete-network-site`
	 * is the separate, genuinely destructive operation.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function deactivate_network_site( $input = array() ) {
		return self::set_site_flag( $input, 'deleted', 1, 'wp-mcp/deactivate-network-site', true );
	}

	/**
	 * Mark a site as spam.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function mark_network_site_spam( $input = array() ) {
		return self::set_site_flag( $input, 'spam', 1, 'wp-mcp/mark-network-site-spam', true );
	}

	/**
	 * Clear the spam flag on a site.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function unmark_network_site_spam( $input = array() ) {
		return self::set_site_flag( $input, 'spam', 0, 'wp-mcp/unmark-network-site-spam', false );
	}

	/**
	 * Permanently delete a site, its tables and its uploads.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function delete_network_site( $input = array() ) {
		$site = self::require_site( $input, 'manage_sites' );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		if ( ! current_user_can( 'delete_sites' ) ) {
			return WP_MCP_Errors::network_permission_denied( __( 'Deleting sites requires the delete_sites capability on this network.', 'wordpress-mcp-abilities' ) );
		}

		$site_id  = (int) $site->id;
		$conflict = self::protect_site( $site_id );
		if ( is_wp_error( $conflict ) ) {
			return $conflict;
		}

		$deleted = wp_delete_site( $site_id );
		if ( is_wp_error( $deleted ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-network-site', $site_id, false, 'wp_mcp_network_site_delete_failed' );
			return WP_MCP_Errors::network_site_delete_failed( $deleted->get_error_message() );
		}

		WP_MCP_Audit::log( 'wp-mcp/delete-network-site', $site_id, true );

		return array(
			'id'      => $site_id,
			'domain'  => (string) $site->domain,
			'path'    => (string) $site->path,
			'deleted' => true,
		);
	}

	/* ------------------------------------------------------------------
	 * Internal helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Capability gate plus site_id validation, in that order.
	 *
	 * @param mixed  $input      Ability input.
	 * @param string $capability Network capability required.
	 * @return WP_Site|WP_Error
	 */
	private static function require_site( $input, $capability ) {
		$denied = WP_MCP_Network::require_network_cap( $capability );
		if ( is_wp_error( $denied ) ) {
			return $denied;
		}
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		return WP_MCP_Network::validate_site_id( isset( $input['site_id'] ) ? $input['site_id'] : 0 );
	}

	/**
	 * Refuse an operation that would disable or destroy the main site of the
	 * network, or the site this request is running on.
	 *
	 * @param int $site_id Target site ID.
	 * @return true|WP_Error
	 */
	private static function protect_site( $site_id ) {
		if ( get_main_site_id() === $site_id ) {
			return WP_MCP_Errors::network_conflict( __( 'The main site of the network cannot be archived, deactivated, marked as spam or deleted.', 'wordpress-mcp-abilities' ) );
		}
		if ( get_current_blog_id() === $site_id ) {
			return WP_MCP_Errors::network_conflict( __( 'A site cannot disable or delete itself from within its own request.', 'wordpress-mcp-abilities' ) );
		}
		return true;
	}

	/**
	 * Set one wp_blogs status flag, idempotently.
	 *
	 * @param mixed  $input          Ability input.
	 * @param string $field          One of archived, spam, deleted.
	 * @param int    $value          0 or 1.
	 * @param string $ability        Ability name for the audit log.
	 * @param bool   $protect_self   Whether this direction may lock the
	 *                               network administrator out (archive,
	 *                               deactivate, spam) and must therefore
	 *                               refuse the main and current sites.
	 * @return array|WP_Error
	 */
	private static function set_site_flag( $input, $field, $value, $ability, $protect_self ) {
		$site = self::require_site( $input, 'manage_sites' );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$site_id = (int) $site->id;
		if ( $protect_self ) {
			$conflict = self::protect_site( $site_id );
			if ( is_wp_error( $conflict ) ) {
				return $conflict;
			}
		}

		if ( self::flag_value( $site, $field ) === $value ) {
			return self::format_site( $site );
		}

		// The column is chosen by an explicit switch rather than a variable
		// array key: `wp_update_site()` declares a literal data shape, and the
		// set of writable columns belongs in code, not in a caller-reachable
		// variable. `flag_value()` above resolves the same three names.
		switch ( $field ) {
			case 'archived':
				$updated = wp_update_site( $site_id, array( 'archived' => $value ) );
				break;
			case 'spam':
				$updated = wp_update_site( $site_id, array( 'spam' => $value ) );
				break;
			default:
				$updated = wp_update_site( $site_id, array( 'deleted' => $value ) );
				break;
		}
		if ( is_wp_error( $updated ) ) {
			WP_MCP_Audit::log( $ability, $site_id, false, 'wp_mcp_network_site_update_failed' );
			return WP_MCP_Errors::network_site_update_failed( $updated->get_error_message() );
		}

		WP_MCP_Audit::log( $ability, $site_id, true, '', array( 'field' => $field, 'value' => $value ) );

		// wp_update_site() answers with the site ID, not the site: re-read it so
		// the formatted answer carries the flag that was just written.
		$refreshed = get_site( $site_id );
		return self::format_site( $refreshed instanceof WP_Site ? $refreshed : $site );
	}

	/**
	 * Read one status flag as an int. An explicit switch rather than a
	 * dynamic property lookup, so the set of addressable columns is fixed
	 * in code.
	 *
	 * @param WP_Site $site  Site object.
	 * @param string  $field Flag name.
	 * @return int
	 */
	private static function flag_value( $site, $field ) {
		switch ( $field ) {
			case 'archived':
				return (int) $site->archived;
			case 'spam':
				return (int) $site->spam;
			case 'deleted':
				return (int) $site->deleted;
			case 'mature':
				return (int) $site->mature;
			default:
				return (int) $site->public;
		}
	}

	/**
	 * Validate a site slug: the label WordPress turns into a subdomain or a
	 * subdirectory, never a full domain.
	 *
	 * @param mixed $raw Raw slug.
	 * @return string|WP_Error
	 */
	private static function validate_slug( $raw ) {
		if ( ! is_string( $raw ) ) {
			return WP_MCP_Errors::network_validation_error( __( 'slug must be a string.', 'wordpress-mcp-abilities' ) );
		}
		$slug = strtolower( trim( $raw ) );
		if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $slug ) || strlen( $slug ) > self::MAX_SLUG_LENGTH ) {
			return WP_MCP_Errors::network_validation_error( __( 'slug must be lowercase letters, digits and hyphens only, and cannot start or end with a hyphen. A full domain or path is never accepted.', 'wordpress-mcp-abilities' ) );
		}

		$illegal = get_network_option( null, 'illegal_names', array() );
		if ( is_array( $illegal ) && in_array( $slug, array_map( 'strval', $illegal ), true ) ) {
			return WP_MCP_Errors::network_conflict( __( 'That slug is on the network\'s illegal names list.', 'wordpress-mcp-abilities' ) );
		}

		if ( ! is_subdomain_install() && function_exists( 'get_subdirectory_reserved_names' ) && in_array( $slug, get_subdirectory_reserved_names(), true ) ) {
			return WP_MCP_Errors::network_conflict( __( 'That slug is reserved by WordPress on a subdirectory network.', 'wordpress-mcp-abilities' ) );
		}

		return $slug;
	}

	/**
	 * Derive (domain, path) from the slug and the network's own
	 * configuration, exactly as wp-admin/network/site-new.php does.
	 *
	 * @param string $slug Validated slug.
	 * @return array{domain:string,path:string}|WP_Error
	 */
	private static function derive_address( $slug ) {
		$network = get_network();
		if ( ! $network instanceof WP_Network ) {
			return WP_MCP_Errors::network_site_create_failed( __( 'The current network could not be resolved.', 'wordpress-mcp-abilities' ) );
		}

		$network_domain = (string) $network->domain;
		$network_path   = '' === (string) $network->path ? '/' : (string) $network->path;

		if ( is_subdomain_install() ) {
			return array(
				'domain' => $slug . '.' . preg_replace( '|^www\.|', '', $network_domain ),
				'path'   => $network_path,
			);
		}

		return array(
			'domain' => $network_domain,
			'path'   => $network_path . $slug . '/',
		);
	}

	/**
	 * Strict boolean input: JSON true/false only, never a truthy string.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return bool|WP_Error
	 */
	private static function validate_boolean( $field, $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 1 === $value || 0 === $value ) {
			return 1 === $value;
		}
		return WP_MCP_Errors::network_validation_error(
			sprintf(
				/* translators: %s: field name */
				__( '%s must be a boolean.', 'wordpress-mcp-abilities' ),
				$field
			)
		);
	}

	/**
	 * Translate a status filter into WP_Site_Query arguments.
	 *
	 * @param string $status Validated status filter.
	 * @return array<string,int>
	 */
	private static function status_args( $status ) {
		switch ( $status ) {
			case 'active':
				return array(
					'archived' => 0,
					'spam'     => 0,
					'deleted'  => 0,
				);
			case 'public':
				return array( 'public' => 1 );
			case 'archived':
				return array( 'archived' => 1 );
			case 'mature':
				return array( 'mature' => 1 );
			case 'spam':
				return array( 'spam' => 1 );
			case 'deleted':
				return array( 'deleted' => 1 );
			default:
				return array();
		}
	}

	/**
	 * Format one site. Closed shape: adding a field here means adding it to
	 * the output schema in the same change.
	 *
	 * @param WP_Site $site Site object.
	 * @return array<string,mixed>
	 */
	private static function format_site( $site ) {
		$site_id = (int) $site->id;

		return array(
			'id'               => $site_id,
			'network_id'       => (int) $site->network_id,
			'domain'           => (string) $site->domain,
			'path'             => (string) $site->path,
			'url'              => (string) $site->siteurl,
			'name'             => wp_strip_all_tags( (string) $site->blogname ),
			'registered_gmt'   => (string) $site->registered,
			'last_updated_gmt' => (string) $site->last_updated,
			'public'           => 1 === (int) $site->public,
			'archived'         => 1 === (int) $site->archived,
			'mature'           => 1 === (int) $site->mature,
			'spam'             => 1 === (int) $site->spam,
			'deleted'          => 1 === (int) $site->deleted,
			'is_main_site'     => get_main_site_id() === $site_id,
			'post_count'       => (int) $site->post_count,
		);
	}
}
