<?php
/**
 * WordPress MCP Abilities — Content and settings import/export callbacks.
 *
 * Design constraints (epic #1, issue #12):
 *
 *  - **No filesystem primitive.** No ability accepts a path, a filename or a
 *    URL. Content export is returned as a string in the response; content
 *    import receives the WXR document *as a string* and writes it to a
 *    WordPress-managed temporary file through the `WP_Filesystem` abstraction,
 *    which is deleted before the ability returns.
 *  - **No remote fetching during import.** `fetch_attachments` is forced off,
 *    so the importer never downloads a URL found inside caller-supplied XML —
 *    that would be an SSRF surface this plugin does not control.
 *  - **Settings import/export is the issue #10 allowlist, nothing more.** It
 *    round-trips exactly the fields `WP_MCP_Settings` declares writable, group
 *    by group, through the same validated writers the settings abilities use.
 *    An arbitrary option can no more be written through an import payload than
 *    through `update-general-settings`.
 *  - WordPress core ships **no WXR importer** — the parser and importer live in
 *    the official WordPress Importer plugin. Rather than reimplement a WXR
 *    parser, `import-content` refuses with `wp_mcp_import_unsupported` when
 *    that plugin is not installed and active.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Import_Export
 *
 * Static utility class — never instantiated.
 */
class WP_MCP_Import_Export {

	/**
	 * Maximum size, in bytes, of a generated WXR export returned in a response.
	 */
	const MAX_EXPORT_BYTES = 8388608;

	/**
	 * Maximum size, in bytes, of a WXR document accepted for import.
	 */
	const MAX_IMPORT_BYTES = 8388608;

	/**
	 * Plugin-relative path of the official WordPress Importer class file.
	 */
	const IMPORTER_PLUGIN = 'wordpress-importer/wordpress-importer.php';

	/* ==================================================================
	 * Content export
	 * ================================================================ */

	/**
	 * Generate a WXR export with WordPress own `export_wp()`.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function export_content( $input = array() ) {
		if ( ! current_user_can( 'export' ) ) {
			return WP_MCP_Errors::system_permission_denied( __( 'You do not have permission to export this site.', 'wordpress-mcp-abilities' ) );
		}

		$args = self::validate_export_args( $input );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		if ( ! function_exists( 'export_wp' ) ) {
			require_once ABSPATH . 'wp-admin/includes/export.php';
		}
		if ( ! function_exists( 'export_wp' ) ) {
			return WP_MCP_Errors::export_failed( __( 'The WordPress exporter is unavailable on this installation.', 'wordpress-mcp-abilities' ) );
		}

		/*
		 * export_wp() streams the document to the output buffer *and* sends
		 * download headers unconditionally. The buffer is captured here and the
		 * headers it managed to set are undone immediately, so the MCP response
		 * is still JSON. Where output has already started the header() calls
		 * cannot succeed at all, and that one warning is swallowed: the
		 * document itself is what this ability returns, and the headers were
		 * never wanted in the first place.
		 */
		$xml = '';
		ob_start();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Not debug code: scoped to this one call and restored immediately below, and it swallows only the header warning export_wp() causes. Every other warning still reaches the normal handler.
		set_error_handler( static function ( $errno, $errstr ) {
			return false !== strpos( (string) $errstr, 'Cannot modify header information' );
		}, E_WARNING );

		try {
			export_wp( $args );
		} finally {
			restore_error_handler();
			$captured = ob_get_clean();
			if ( is_string( $captured ) ) {
				$xml = $captured;
			}
		}

		self::restore_response_headers();

		if ( '' === $xml ) {
			return WP_MCP_Errors::export_failed();
		}

		$bytes = strlen( $xml );
		if ( $bytes > self::MAX_EXPORT_BYTES ) {
			return WP_MCP_Errors::export_too_large( self::MAX_EXPORT_BYTES );
		}

		/*
		 * `export_scope`, not `content` and not `content_scope`: the audit
		 * scrubber matches its forbidden key list as *substrings*, so every key
		 * containing `content` is dropped — deliberately, so a key that reads
		 * like post content can never reach the log. The value here is the
		 * export scope ('all', 'posts', ...), not a document, so it gets a name
		 * that says so and survives scrubbing.
		 */
		WP_MCP_Audit::log( 'wp-mcp/export-content', 0, true, '', array(
			'export_scope' => $args['content'],
			'bytes'        => $bytes,
		) );

		return array(
			'wxr'           => $xml,
			'bytes'         => $bytes,
			'content'       => (string) $args['content'],
			'status'        => is_string( $args['status'] ) ? $args['status'] : '',
			'author'        => (int) ( $args['author'] ? $args['author'] : 0 ),
			'category'      => is_string( $args['category'] ) ? $args['category'] : '',
			'start_date'    => is_string( $args['start_date'] ) ? $args['start_date'] : '',
			'end_date'      => is_string( $args['end_date'] ) ? $args['end_date'] : '',
			'generated_utc' => gmdate( 'c' ),
			'max_bytes'     => self::MAX_EXPORT_BYTES,
		);
	}

	/* ==================================================================
	 * Content import
	 * ================================================================ */

	/**
	 * Import a WXR document supplied as a string.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function import_content( $input = array() ) {
		if ( ! current_user_can( 'import' ) ) {
			return WP_MCP_Errors::system_permission_denied( __( 'You do not have permission to import content into this site.', 'wordpress-mcp-abilities' ) );
		}

		$wxr = isset( $input['wxr'] ) ? $input['wxr'] : '';
		if ( ! is_string( $wxr ) || '' === trim( $wxr ) ) {
			return WP_MCP_Errors::import_validation_error( __( 'The "wxr" field is required and must be a WXR document.', 'wordpress-mcp-abilities' ) );
		}
		if ( strlen( $wxr ) > self::MAX_IMPORT_BYTES ) {
			return WP_MCP_Errors::import_validation_error(
				sprintf(
					/* translators: %d: maximum accepted import size in bytes */
					__( 'The WXR document exceeds the %d byte import limit.', 'wordpress-mcp-abilities' ),
					self::MAX_IMPORT_BYTES
				)
			);
		}
		if ( false === strpos( $wxr, '<rss' ) && false === strpos( $wxr, '<?xml' ) ) {
			return WP_MCP_Errors::import_validation_error( __( 'The supplied document does not look like a WXR (WordPress eXtended RSS) export.', 'wordpress-mcp-abilities' ) );
		}

		$ready = self::load_importer();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		$file = wp_tempnam( 'wp-mcp-import.xml' );
		if ( ! $file ) {
			return WP_MCP_Errors::import_failed( __( 'WordPress could not create a temporary file for the import.', 'wordpress-mcp-abilities' ) );
		}

		if ( ! $filesystem->put_contents( $file, $wxr, FS_CHMOD_FILE ) ) {
			$filesystem->delete( $file );
			return WP_MCP_Errors::import_failed( __( 'WordPress could not write the import document to its temporary file.', 'wordpress-mcp-abilities' ) );
		}

		$result = self::run_importer( $file );

		$filesystem->delete( $file );

		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/import-content', 0, false, $result->get_error_code(), array() );
			return $result;
		}

		WP_MCP_Audit::log( 'wp-mcp/import-content', 0, true, '', array(
			'posts' => $result['imported_posts'],
			'terms' => $result['imported_terms'],
		) );

		return $result;
	}

	/* ==================================================================
	 * Settings export / import
	 * ================================================================ */

	/**
	 * Export every allowlisted, writable settings field, group by group.
	 *
	 * @param array $input Ability input (unused).
	 * @return array|WP_Error
	 */
	public static function export_settings( $input = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return WP_MCP_Errors::settings_permission_denied();
		}

		$groups  = WP_MCP_Settings::groups();
		$readers = self::settings_readers();

		$settings = array();
		$exported = array();
		$skipped  = array();

		foreach ( WP_MCP_Settings::group_slugs() as $group ) {
			if ( ! isset( $readers[ $group ], $groups[ $group ] ) ) {
				continue;
			}
			if ( ! current_user_can( $groups[ $group ]['capability'] ) ) {
				$skipped[] = $group;
				continue;
			}

			$values = call_user_func( $readers[ $group ] );
			if ( is_wp_error( $values ) ) {
				$skipped[] = $group;
				continue;
			}

			$writable = array_keys( WP_MCP_Settings::input_schema_properties( $group ) );
			$payload  = array();
			foreach ( $writable as $field ) {
				if ( array_key_exists( $field, $values ) ) {
					$payload[ $field ] = $values[ $field ];
				}
			}

			$settings[ $group ] = $payload;
			$exported[]         = $group;
		}

		return array(
			'settings'       => $settings,
			'groups'         => $exported,
			'skipped_groups' => $skipped,
			'generated_utc'  => gmdate( 'c' ),
		);
	}

	/**
	 * Write back a settings payload produced by `export-settings`.
	 *
	 * Each group goes through the same validated writer the settings abilities
	 * use, so an unknown field is refused with `wp_mcp_settings_unknown_field`
	 * and a read-only field with `wp_mcp_settings_readonly_field` — an import
	 * payload is not a way around the allowlist.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function import_settings( $input = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return WP_MCP_Errors::settings_permission_denied();
		}

		$payload = isset( $input['settings'] ) ? $input['settings'] : null;
		if ( ! is_array( $payload ) || array() === $payload ) {
			return WP_MCP_Errors::settings_validation_error( __( 'The "settings" field is required and must contain at least one settings group.', 'wordpress-mcp-abilities' ) );
		}

		$groups  = WP_MCP_Settings::groups();
		$writers = self::settings_writers();

		// Validate every group name and capability before writing anything, so
		// a payload naming an unknown group cannot half-apply.
		foreach ( $payload as $group => $values ) {
			if ( ! is_string( $group ) || ! isset( $writers[ $group ], $groups[ $group ] ) ) {
				return WP_MCP_Errors::settings_unknown_field( is_string( $group ) ? $group : gettype( $group ) );
			}
			if ( ! is_array( $values ) ) {
				return WP_MCP_Errors::settings_validation_error(
					sprintf(
						/* translators: %s: settings group name */
						__( 'The "%s" group must be an object of field name to value.', 'wordpress-mcp-abilities' ),
						$group
					)
				);
			}
			if ( ! current_user_can( $groups[ $group ]['capability'] ) ) {
				return WP_MCP_Errors::settings_permission_denied(
					sprintf(
						/* translators: %s: settings group name */
						__( 'You do not have permission to write the "%s" settings group.', 'wordpress-mcp-abilities' ),
						$group
					)
				);
			}
		}

		$applied = array();
		foreach ( WP_MCP_Settings::group_slugs() as $group ) {
			if ( ! isset( $payload[ $group ] ) ) {
				continue;
			}
			if ( array() === $payload[ $group ] ) {
				continue;
			}

			$result = call_user_func( $writers[ $group ], $payload[ $group ] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$applied[] = array(
				'group'   => $group,
				'updated' => isset( $result['updated'] ) ? array_values( (array) $result['updated'] ) : array(),
			);
		}

		$total = 0;
		foreach ( $applied as $entry ) {
			$total += count( $entry['updated'] );
		}

		WP_MCP_Audit::log( 'wp-mcp/import-settings', 0, true, '', array( 'fields' => $total ) );

		return array(
			'applied'       => $applied,
			'updated_total' => $total,
		);
	}

	/* ==================================================================
	 * Internal helpers
	 * ================================================================ */

	/**
	 * Per-group settings readers. A fixed table, never a name derived from
	 * caller input.
	 *
	 * @return array<string,callable>
	 */
	private static function settings_readers() {
		return array(
			'general'    => array( 'WP_MCP_Settings', 'get_general_settings' ),
			'writing'    => array( 'WP_MCP_Settings', 'get_writing_settings' ),
			'reading'    => array( 'WP_MCP_Settings', 'get_reading_settings' ),
			'discussion' => array( 'WP_MCP_Settings', 'get_discussion_settings' ),
			'media'      => array( 'WP_MCP_Settings', 'get_media_settings' ),
			'permalinks' => array( 'WP_MCP_Settings', 'get_permalink_settings' ),
			'privacy'    => array( 'WP_MCP_Settings', 'get_privacy_settings' ),
		);
	}

	/**
	 * Per-group settings writers. A fixed table, never a name derived from
	 * caller input.
	 *
	 * @return array<string,callable>
	 */
	private static function settings_writers() {
		return array(
			'general'    => array( 'WP_MCP_Settings', 'update_general_settings' ),
			'writing'    => array( 'WP_MCP_Settings', 'update_writing_settings' ),
			'reading'    => array( 'WP_MCP_Settings', 'update_reading_settings' ),
			'discussion' => array( 'WP_MCP_Settings', 'update_discussion_settings' ),
			'media'      => array( 'WP_MCP_Settings', 'update_media_settings' ),
			'permalinks' => array( 'WP_MCP_Settings', 'update_permalink_settings' ),
			'privacy'    => array( 'WP_MCP_Settings', 'update_privacy_settings' ),
		);
	}

	/**
	 * Validate the export filters into the argument array `export_wp()` takes.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	private static function validate_export_args( $input ) {
		$content = isset( $input['content'] ) ? $input['content'] : 'all';
		if ( ! is_string( $content ) || '' === $content ) {
			return WP_MCP_Errors::export_validation_error( __( 'The "content" filter must be a string.', 'wordpress-mcp-abilities' ) );
		}
		if ( 'all' !== $content ) {
			$type = get_post_type_object( $content );
			if ( ! $type ) {
				return WP_MCP_Errors::export_validation_error(
					sprintf(
						/* translators: %s: post type name supplied by the caller */
						__( '"%s" is not a registered post type.', 'wordpress-mcp-abilities' ),
						$content
					)
				);
			}
			if ( ! post_type_exists( $content ) || empty( $type->can_export ) ) {
				return WP_MCP_Errors::export_validation_error(
					sprintf(
						/* translators: %s: post type name supplied by the caller */
						__( 'The "%s" post type is registered with can_export disabled.', 'wordpress-mcp-abilities' ),
						$content
					)
				);
			}
		}

		$author = 0;
		if ( ! empty( $input['author'] ) ) {
			$author = absint( $input['author'] );
			if ( $author < 1 || ! get_userdata( $author ) ) {
				return WP_MCP_Errors::export_validation_error( __( 'The "author" filter must be an existing user ID.', 'wordpress-mcp-abilities' ) );
			}
		}

		$category = '';
		if ( ! empty( $input['category'] ) ) {
			if ( ! is_string( $input['category'] ) ) {
				return WP_MCP_Errors::export_validation_error( __( 'The "category" filter must be a category slug.', 'wordpress-mcp-abilities' ) );
			}
			$category = sanitize_title( $input['category'] );
			if ( '' === $category || ! get_term_by( 'slug', $category, 'category' ) ) {
				return WP_MCP_Errors::export_validation_error( __( 'The "category" filter must be an existing category slug.', 'wordpress-mcp-abilities' ) );
			}
		}

		$status = '';
		if ( ! empty( $input['status'] ) ) {
			if ( ! is_string( $input['status'] ) || ! get_post_status_object( $input['status'] ) ) {
				return WP_MCP_Errors::export_validation_error( __( 'The "status" filter must be a registered post status.', 'wordpress-mcp-abilities' ) );
			}
			$status = $input['status'];
		}

		$start_date = self::validate_export_date( isset( $input['start_date'] ) ? $input['start_date'] : '', 'start_date' );
		if ( is_wp_error( $start_date ) ) {
			return $start_date;
		}
		$end_date = self::validate_export_date( isset( $input['end_date'] ) ? $input['end_date'] : '', 'end_date' );
		if ( is_wp_error( $end_date ) ) {
			return $end_date;
		}

		return array(
			'content'    => $content,
			'author'     => $author ? $author : false,
			'category'   => '' !== $category ? $category : false,
			'start_date' => '' !== $start_date ? $start_date : false,
			'end_date'   => '' !== $end_date ? $end_date : false,
			'status'     => '' !== $status ? $status : false,
		);
	}

	/**
	 * Validate an optional `Y-m-d` export boundary.
	 *
	 * @param mixed  $value Raw input.
	 * @param string $field Field name for the error message.
	 * @return string|WP_Error
	 */
	private static function validate_export_date( $value, $field ) {
		if ( empty( $value ) ) {
			return '';
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return WP_MCP_Errors::export_validation_error(
				sprintf(
					/* translators: %s: field name */
					__( 'The "%s" filter must be a date in YYYY-MM-DD format.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			return WP_MCP_Errors::export_validation_error(
				sprintf(
					/* translators: %s: field name */
					__( 'The "%s" filter is not a valid calendar date.', 'wordpress-mcp-abilities' ),
					$field
				)
			);
		}
		return $value;
	}

	/**
	 * Undo the download headers `export_wp()` sends so the MCP response stays
	 * a JSON document.
	 */
	private static function restore_response_headers() {
		if ( headers_sent() ) {
			return;
		}
		foreach ( array( 'Content-Description', 'Content-Disposition', 'Content-Type' ) as $header ) {
			header_remove( $header );
		}
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	}

	/**
	 * Load the official WordPress Importer, or explain why it is unavailable.
	 *
	 * The importer plugin only defines `WP_Import` from an `admin_init`
	 * callback, which never fires on a REST request, so the class file is
	 * required directly once the plugin has been confirmed active.
	 *
	 * @return true|WP_Error
	 */
	private static function load_importer() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( self::IMPORTER_PLUGIN ) ) {
			return WP_MCP_Errors::import_unsupported();
		}

		if ( ! class_exists( 'WP_Importer' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-importer.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
		}

		$plugin_dir = WP_PLUGIN_DIR . '/wordpress-importer/';
		foreach ( array( 'parsers.php', 'class-wp-import.php' ) as $file ) {
			if ( file_exists( $plugin_dir . $file ) ) {
				require_once $plugin_dir . $file;
			}
		}

		if ( ! class_exists( 'WP_Import' ) || ! class_exists( 'WXR_Parser' ) ) {
			return WP_MCP_Errors::import_unsupported( __( 'The WordPress Importer plugin is active but its importer classes could not be loaded.', 'wordpress-mcp-abilities' ) );
		}

		return true;
	}

	/**
	 * Parse and run an already-written WXR file through the importer.
	 *
	 * The document is parsed first with the importer's own parser: `WP_Import`
	 * calls `die()` on a malformed document, which would kill the request, so
	 * a parse failure is turned into an ordinary `WP_Error` before the importer
	 * is ever handed the file.
	 *
	 * @param string $file Absolute path of the temporary WXR file.
	 * @return array|WP_Error
	 */
	private static function run_importer( $file ) {
		$parser = self::instantiate( 'WXR_Parser' );
		$parsed = $parser->parse( $file );

		if ( is_wp_error( $parsed ) ) {
			return WP_MCP_Errors::import_validation_error( $parsed->get_error_message() );
		}
		if ( ! is_array( $parsed ) ) {
			return WP_MCP_Errors::import_validation_error( __( 'The WXR document could not be parsed.', 'wordpress-mcp-abilities' ) );
		}

		$unsafe = self::reject_serialized_objects( $parsed );
		if ( is_wp_error( $unsafe ) ) {
			return $unsafe;
		}

		$importer = self::instantiate( 'WP_Import' );

		// Never let the importer reach out to the network for attachments.
		$importer->fetch_attachments = false;

		// The importer prints progress HTML; the MCP response is JSON.
		ob_start();
		$importer->import( $file );
		ob_end_clean();

		$state = get_object_vars( $importer );

		return array(
			'imported'            => true,
			'imported_posts'      => self::count_state( $state, 'processed_posts' ),
			'imported_terms'      => self::count_state( $state, 'processed_terms' ),
			'imported_authors'    => self::count_state( $state, 'processed_authors' ),
			'fetched_attachments' => false,
		);
	}

	/**
	 * Refuse a parsed WXR document that carries a serialized PHP object.
	 *
	 * The WordPress Importer calls `maybe_unserialize()` on every post- and
	 * comment-meta value it imports. A serialized *array* is ordinary WXR
	 * (`_wp_attachment_metadata` is one) and stays allowed; a serialized
	 * *object* would be instantiated by `unserialize()` during the import, and
	 * with the right class already loaded on the site that is a PHP
	 * object-injection primitive — an indirect route to the arbitrary code
	 * execution epic #1 rules out. The check therefore looks for the `O:` and
	 * `C:` markers anywhere in the value, including nested inside an array.
	 *
	 * @since 0.15.0
	 *
	 * @param array $parsed Parsed WXR document from WXR_Parser::parse().
	 * @return true|WP_Error
	 */
	public static function reject_serialized_objects( array $parsed ) {
		foreach ( self::meta_sets_in( $parsed ) as $meta_set ) {
			foreach ( $meta_set as $meta ) {
				$value = is_array( $meta ) && isset( $meta['value'] ) ? $meta['value'] : null;
				if ( is_string( $value ) && self::contains_serialized_object( $value ) ) {
					return WP_MCP_Errors::import_unsafe_meta();
				}
			}
		}

		return true;
	}

	/**
	 * Every metadata collection a parsed WXR document can carry.
	 *
	 * All three families matter, because the importer unserializes all three:
	 * `process_posts()` runs post- and comment-meta through
	 * `maybe_unserialize()`, and `process_terms()` does the same for
	 * `wp:termmeta`. Checking only `postmeta` would leave the term route open,
	 * and a term is as good a home for an injection payload as a post is.
	 *
	 * @since 0.15.0
	 *
	 * @param array $parsed Parsed WXR document from WXR_Parser::parse().
	 * @return array<int,array> Metadata collections, each a list of key/value entries.
	 */
	private static function meta_sets_in( array $parsed ) {
		$meta_sets = array();

		$posts = isset( $parsed['posts'] ) && is_array( $parsed['posts'] ) ? $parsed['posts'] : array();
		foreach ( $posts as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}
			if ( isset( $post['postmeta'] ) && is_array( $post['postmeta'] ) ) {
				$meta_sets[] = $post['postmeta'];
			}
			if ( isset( $post['comments'] ) && is_array( $post['comments'] ) ) {
				foreach ( $post['comments'] as $comment ) {
					if ( is_array( $comment ) && isset( $comment['commentmeta'] ) && is_array( $comment['commentmeta'] ) ) {
						$meta_sets[] = $comment['commentmeta'];
					}
				}
			}
		}

		// `terms` is the WXR 1.2 shape; `categories` and `tags` are the older
		// per-taxonomy lists the parsers still emit, and carry termmeta too.
		foreach ( array( 'terms', 'categories', 'tags' ) as $collection ) {
			if ( ! isset( $parsed[ $collection ] ) || ! is_array( $parsed[ $collection ] ) ) {
				continue;
			}
			foreach ( $parsed[ $collection ] as $term ) {
				if ( is_array( $term ) && isset( $term['termmeta'] ) && is_array( $term['termmeta'] ) ) {
					$meta_sets[] = $term['termmeta'];
				}
			}
		}

		return $meta_sets;
	}

	/**
	 * Whether a string is serialized PHP containing an object.
	 *
	 * @since 0.15.0
	 *
	 * @param string $value Raw metadata value from the WXR document.
	 * @return bool
	 */
	public static function contains_serialized_object( $value ) {
		if ( ! is_serialized( $value ) ) {
			return false;
		}
		// O: is a serialized object, C: a Serializable one. Both appear either
		// at the start of the value or after a ; : or { when nested.
		return 1 === preg_match( '/(?:^|[;:{])[OC]:\d+:"/', $value );
	}

	/**
	 * Instantiate a class supplied by the importer plugin.
	 *
	 * The class name is a constant of this file, never caller input; it is
	 * passed as a variable only because the WordPress Importer's classes do
	 * not exist on an installation without that plugin, and every caller has
	 * already checked for them.
	 *
	 * @param string $class_name Class name.
	 * @return mixed
	 */
	private static function instantiate( $class_name ) {
		return new $class_name();
	}

	/**
	 * Count one of the importer's public bookkeeping arrays.
	 *
	 * @param array  $state Public properties of the importer.
	 * @param string $key   Property name.
	 * @return int
	 */
	private static function count_state( $state, $key ) {
		return ( isset( $state[ $key ] ) && is_array( $state[ $key ] ) ) ? count( $state[ $key ] ) : 0;
	}

	/**
	 * Initialise and return the WP_Filesystem abstraction.
	 *
	 * Returns the global loosely rather than a declared filesystem type: the
	 * concrete class depends on the transport WordPress negotiates, and the
	 * caller only ever uses put_contents()/delete().
	 *
	 * @return mixed WP_Filesystem_Base on success, WP_Error on failure.
	 */
	private static function filesystem() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! WP_Filesystem() ) {
			return WP_MCP_Errors::import_failed( __( 'WordPress could not obtain filesystem access with the credentials available to this request.', 'wordpress-mcp-abilities' ) );
		}

		return $wp_filesystem;
	}
}
