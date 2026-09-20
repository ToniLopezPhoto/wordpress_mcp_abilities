<?php
/**
 * WordPress MCP Abilities — Permission helpers.
 *
 * Centralised helpers for capability checks, ownership verification,
 * input validation, and taxonomy-term validation.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Permissions
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Permissions {

	/* ------------------------------------------------------------------
	 * Capability helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the current user can read content.
	 *
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'read' );
	}

	/**
	 * Whether the current user can create / edit own posts.
	 *
	 * @return bool
	 */
	public static function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Whether the current user can create / edit pages.
	 *
	 * @return bool
	 */
	public static function can_edit_pages() {
		return current_user_can( 'edit_pages' );
	}

	/**
	 * Whether the current user can publish posts.
	 *
	 * @return bool
	 */
	public static function can_publish_posts() {
		return current_user_can( 'publish_posts' );
	}

	/**
	 * Whether the current user can publish pages.
	 *
	 * WordPress maps the `page` post type to its own primitive capabilities,
	 * so a page ability gated on `publish_posts` would let a role that was
	 * deliberately denied `publish_pages` publish one anyway.
	 *
	 * @since 0.15.0
	 *
	 * @return bool
	 */
	public static function can_publish_pages() {
		return current_user_can( 'publish_pages' );
	}

	/**
	 * Whether the current user can delete posts (general capability).
	 *
	 * @return bool
	 */
	public static function can_delete_posts() {
		return current_user_can( 'delete_posts' );
	}

	/**
	 * Whether the current user can delete pages (general capability).
	 *
	 * @since 0.15.0
	 *
	 * @return bool
	 */
	public static function can_delete_pages() {
		return current_user_can( 'delete_pages' );
	}

	/* ------------------------------------------------------------------
	 * Ownership helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Whether a post belongs to the currently authenticated user.
	 *
	 * NOT an authorization gate. The native `edit_post`/`read_post`
	 * meta-capabilities (resolved by WordPress core's map_meta_cap) are
	 * the sole authority on whether an action is allowed — they already
	 * restrict users without `edit_others_posts` to their own content
	 * and allow Editors/Admins to act on others' content. This method
	 * is only used, after that check has already denied access, to pick
	 * a more specific error code/message.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public static function is_own_post( $post ) {
		return get_current_user_id() === (int) $post->post_author;
	}

	/* ------------------------------------------------------------------
	 * Composite validators — return WP_Post|WP_Error
	 * ---------------------------------------------------------------- */

	/**
	 * Validate a post or page for mutation (update / publish / attribute changes).
	 *
	 * Checks: exists, post_type === $expected_type, edit_post meta-capability.
	 *
	 * Authorization is delegated entirely to WordPress core's native
	 * `edit_post` meta-capability (map_meta_cap): a user without
	 * `edit_others_posts` is automatically restricted to their own
	 * posts, while a user with elevated capabilities (Editor,
	 * Administrator) may edit posts owned by others, exactly as
	 * WordPress core allows. is_own_post() is only consulted after
	 * the capability check has already denied access, to choose
	 * between a more specific "not yours" vs. "no permission" error.
	 *
	 * @param int    $post_id       Raw post ID (will be sanitised internally).
	 * @param string $expected_type 'post' or 'page'.
	 * @return WP_Post|WP_Error
	 */
	public static function validate_post_for_mutation( $post_id, $expected_type = 'post' ) {
		$post_id = self::validate_positive_int( $post_id, 'post_id' );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return 'page' === $expected_type ? WP_MCP_Errors::invalid_page() : WP_MCP_Errors::invalid_post();
		}

		if ( $expected_type !== $post->post_type ) {
			return WP_MCP_Errors::unsupported_post_type(
				/* translators: %s: expected type (post or page) */
				sprintf( __( 'This ability only operates on a %s.', 'wordpress-mcp-abilities' ), $expected_type )
			);
		}

		// Sole authorization gate: native meta-capability.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			// Classification only, not authorization — access was
			// already denied above.
			return self::is_own_post( $post )
				? WP_MCP_Errors::permission_denied(
					/* translators: %s: expected type */
					sprintf( __( 'You do not have permission to edit this %s.', 'wordpress-mcp-abilities' ), $expected_type )
				)
				: WP_MCP_Errors::ownership_violation();
		}

		return $post;
	}

	/**
	 * Validate a post or page for deletion (trash / restore / delete permanently).
	 *
	 * Checks: exists, post_type === $expected_type, delete_post meta-capability.
	 * Same authorization pattern as validate_post_for_mutation(), but gated
	 * on the `delete_post` meta-capability rather than `edit_post` — WP-admin
	 * uses the same capability for trash, restore, and permanent deletion.
	 *
	 * @param int    $post_id       Raw post ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @return WP_Post|WP_Error
	 */
	public static function validate_post_for_deletion( $post_id, $expected_type = 'post' ) {
		$post_id = self::validate_positive_int( $post_id, 'post_id' );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return 'page' === $expected_type ? WP_MCP_Errors::invalid_page() : WP_MCP_Errors::invalid_post();
		}

		if ( $expected_type !== $post->post_type ) {
			return WP_MCP_Errors::unsupported_post_type(
				/* translators: %s: expected type (post or page) */
				sprintf( __( 'This ability only operates on a %s.', 'wordpress-mcp-abilities' ), $expected_type )
			);
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return self::is_own_post( $post )
				? WP_MCP_Errors::permission_denied(
					/* translators: %s: expected type */
					sprintf( __( 'You do not have permission to delete this %s.', 'wordpress-mcp-abilities' ), $expected_type )
				)
				: WP_MCP_Errors::ownership_violation();
		}

		return $post;
	}

	/**
	 * Validate that a revision exists and belongs to the expected parent post.
	 *
	 * Called after the parent post has already been validated for mutation
	 * (the capability gate is resolved there) — this only checks structural
	 * integrity of the revision itself.
	 *
	 * @param int $revision_id    Raw revision ID.
	 * @param int $parent_post_id Expected parent post/page ID.
	 * @return WP_Post|WP_Error
	 */
	public static function validate_revision_for_post( $revision_id, $parent_post_id ) {
		$revision_id = self::validate_positive_int( $revision_id, 'revision_id' );
		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type || (int) $revision->post_parent !== (int) $parent_post_id ) {
			return WP_MCP_Errors::invalid_revision();
		}

		return $revision;
	}

	/**
	 * Validate a post or page for reading.
	 *
	 * Checks: exists, correct post_type, read_post cap.
	 *
	 * @param int    $post_id       Raw post ID.
	 * @param string $expected_type 'post' or 'page'.
	 * @return WP_Post|WP_Error
	 */
	public static function validate_post_for_read( $post_id, $expected_type ) {
		$post_id = self::validate_positive_int( $post_id, 'post_id' );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return 'page' === $expected_type ? WP_MCP_Errors::invalid_page() : WP_MCP_Errors::invalid_post();
		}

		if ( $expected_type !== $post->post_type ) {
			return WP_MCP_Errors::unsupported_post_type(
				/* translators: %s: expected type */
				sprintf( __( 'The specified ID does not correspond to a %s.', 'wordpress-mcp-abilities' ), $expected_type )
			);
		}

		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return WP_MCP_Errors::permission_denied(
				/* translators: %s: expected type */
				sprintf( __( 'You do not have permission to read this %s.', 'wordpress-mcp-abilities' ), $expected_type )
			);
		}

		return $post;
	}

	/* ------------------------------------------------------------------
	 * Input validators
	 * ---------------------------------------------------------------- */

	/**
	 * Whether $id is strictly a positive integer identifier — an int > 0,
	 * or a numeric string matching /^[1-9][0-9]*$/ (no leading zero, sign,
	 * decimal point, exponent, or surrounding whitespace).
	 *
	 * Deliberately stricter than is_numeric(), which accepts "1.5", "1e3",
	 * " 12", and bool true — all of which silently truncate to a possibly
	 * wrong ID under (int) casting. Used where an ability accepts either a
	 * numeric database ID or a non-numeric string identifier (e.g. a
	 * registered pattern name) and must tell the two apart before casting.
	 *
	 * @param mixed $id Raw value from MCP input.
	 * @return bool
	 */
	public static function is_strict_positive_int_id( $id ) {
		if ( is_int( $id ) ) {
			return $id > 0;
		}
		if ( ! is_string( $id ) ) {
			return false;
		}
		return 1 === preg_match( '/^[1-9][0-9]*$/', $id );
	}

	/**
	 * Validate and return a positive integer.
	 *
	 * @param mixed  $value      Raw value from MCP input.
	 * @param string $field_name Field name for the error message.
	 * @return int|WP_Error
	 */
	public static function validate_positive_int( $value, $field_name ) {
		$int_value = absint( $value );
		if ( $int_value < 1 ) {
			return WP_MCP_Errors::validation_error(
				/* translators: %s: field name */
				sprintf( __( '%s must be a positive integer.', 'wordpress-mcp-abilities' ), $field_name )
			);
		}
		return $int_value;
	}

	/**
	 * Validate an array of taxonomy term IDs.
	 *
	 * Every ID must:
	 *  - be a positive integer;
	 *  - exist as a term;
	 *  - belong to the expected taxonomy.
	 *
	 * @param array  $ids      Raw array of IDs.
	 * @param string $taxonomy Expected taxonomy ('category' or 'post_tag').
	 * @return int[]|WP_Error Clean array of term IDs on success.
	 */
	public static function validate_taxonomy_terms( $ids, $taxonomy ) {
		if ( ! is_array( $ids ) ) {
			return WP_MCP_Errors::validation_error( __( 'Term IDs must be provided as an array.', 'wordpress-mcp-abilities' ) );
		}

		// Guard against excessively large arrays.
		if ( count( $ids ) > 100 ) {
			return WP_MCP_Errors::validation_error( __( 'Too many term IDs provided (maximum 100).', 'wordpress-mcp-abilities' ) );
		}

		$clean = array();
		foreach ( $ids as $id ) {
			$int_id = absint( $id );
			if ( $int_id < 1 ) {
				return WP_MCP_Errors::invalid_taxonomy_term(
					/* translators: %s: raw value */
					sprintf( __( 'Invalid term ID: %s.', 'wordpress-mcp-abilities' ), $id )
				);
			}

			$term = get_term( $int_id );
			if ( ! $term || is_wp_error( $term ) ) {
				return WP_MCP_Errors::invalid_taxonomy_term(
					/* translators: %d: term ID */
					sprintf( __( 'Term ID %d does not exist.', 'wordpress-mcp-abilities' ), $int_id ),
					404
				);
			}

			if ( $term->taxonomy !== $taxonomy ) {
				return WP_MCP_Errors::invalid_taxonomy_term(
					sprintf(
						/* translators: 1: term ID, 2: expected taxonomy */
						__( 'Term ID %1$d does not belong to the %2$s taxonomy.', 'wordpress-mcp-abilities' ),
						$int_id,
						$taxonomy
					)
				);
			}

			$clean[] = $int_id;
		}

		return $clean;
	}

	/* ------------------------------------------------------------------
	 * Remote URL / SSRF policy
	 *
	 * Shared by every domain that downloads a caller-supplied URL
	 * (media-from-url, plugin/theme install-from-url): explicit host
	 * allow/deny lists, no local hosts, and DNS/IP resolution checked
	 * against private/reserved ranges before any HTTP request is made.
	 * ---------------------------------------------------------------- */

	/**
	 * Validate remote URL, allow/deny host policy, and resolved IPs.
	 *
	 * @param mixed $url   URL.
	 * @param array $input URL-policy input (optional allowed_hosts/deny_hosts).
	 * @return true|WP_Error
	 */
	public static function validate_remote_url( $url, $input = array() ) {
		if ( ! is_string( $url ) || '' === $url || ! function_exists( 'wp_http_validate_url' ) || ! wp_http_validate_url( $url ) ) {
			return WP_MCP_Errors::remote_url_denied( __( 'Only safe HTTP(S) URLs are accepted.', 'wordpress-mcp-abilities' ) );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return WP_MCP_Errors::remote_url_denied( __( 'The remote URL contains an unsafe host or URL component.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! empty( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
			return WP_MCP_Errors::remote_url_denied( __( 'Only HTTP and HTTPS default ports are allowed.', 'wordpress-mcp-abilities' ) );
		}
		$host = strtolower( trim( $parts['host'], '[]' ) );
		$allowed = self::normalise_hosts( isset( $input['allowed_hosts'] ) ? $input['allowed_hosts'] : array() );
		$denied  = self::normalise_hosts( isset( $input['deny_hosts'] ) ? $input['deny_hosts'] : array() );
		if ( ! empty( $allowed ) && ! self::host_matches_policy( $host, $allowed ) ) {
			return WP_MCP_Errors::remote_url_denied( __( 'The remote host is not in allowed_hosts.', 'wordpress-mcp-abilities' ) );
		}
		if ( self::host_matches_policy( $host, $denied ) ) {
			return WP_MCP_Errors::remote_url_denied( __( 'The remote host is denied.', 'wordpress-mcp-abilities' ) );
		}
		if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) || 'local' === $host ) {
			return WP_MCP_Errors::remote_url_denied( __( 'Local hosts are not allowed.', 'wordpress-mcp-abilities' ) );
		}

		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} elseif ( function_exists( 'dns_get_record' ) ) {
			$records = dns_get_record( $host, DNS_A | DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					}
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}
		if ( empty( $ips ) ) {
			$resolved = gethostbynamel( $host );
			$ips      = is_array( $resolved ) ? $resolved : array();
		}
		if ( empty( $ips ) ) {
			return WP_MCP_Errors::remote_url_denied( __( 'The remote host could not be resolved safely.', 'wordpress-mcp-abilities' ) );
		}
		foreach ( $ips as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return WP_MCP_Errors::remote_url_denied( __( 'The remote host resolves to a private or reserved IP address.', 'wordpress-mcp-abilities' ) );
			}
		}
		return true;
	}

	/**
	 * @param mixed $hosts Host list.
	 * @return string[]
	 */
	private static function normalise_hosts( $hosts ) {
		if ( ! is_array( $hosts ) ) {
			return array();
		}
		$result = array();
		foreach ( array_slice( $hosts, 0, 20 ) as $host ) {
			if ( is_string( $host ) && '' !== trim( $host ) ) {
				$result[] = strtolower( trim( $host, " \t\n\r\0\x0B." ) );
			}
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * @param string   $host   Host.
	 * @param string[] $policy Host policy.
	 * @return bool
	 */
	private static function host_matches_policy( $host, $policy ) {
		foreach ( $policy as $candidate ) {
			if ( $host === $candidate || ( strlen( $host ) > strlen( $candidate ) && substr( $host, -strlen( '.' . $candidate ) ) === '.' . $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Run $operation with every HTTP request it makes capped to $max_bytes and
	 * forced through WordPress's unsafe-URL rejection, then restore the prior
	 * behavior — including when $operation throws.
	 *
	 * Shared by the plugin, theme, and language-pack upgrader paths, which all
	 * download a remote archive on the caller's behalf and must not be able to
	 * pull an unbounded response into memory or reach a private address.
	 *
	 * @since 0.9.0
	 *
	 * @param int      $max_bytes Maximum response size in bytes.
	 * @param callable $operation Operation to run under the limit.
	 * @return mixed Whatever $operation returns.
	 */
	public static function with_download_limit( $max_bytes, $operation ) {
		$filter = function ( $args ) use ( $max_bytes ) {
			$args['limit_response_size'] = $max_bytes;
			$args['reject_unsafe_urls']  = true;
			return $args;
		};
		add_filter( 'http_request_args', $filter );
		try {
			return $operation();
		} finally {
			remove_filter( 'http_request_args', $filter );
		}
	}
}
