<?php
/**
 * WordPress MCP Abilities — Media callbacks.
 *
 * Execute callbacks for the explicit media-library abilities. Uploads only
 * accept content supplied by the MCP client; server filesystem paths are
 * never accepted or returned.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Media
 */
class WP_MCP_Media {

	/** Maximum decoded upload size: 10 MiB. */
	const MAX_UPLOAD_BYTES = 10485760;

	/** Maximum number of IDs accepted by a bulk request. */
	const MAX_BULK_ITEMS = 20;

	/* ------------------------------------------------------------------
	 * Read operations
	 * ---------------------------------------------------------------- */

	/**
	 * List media library items visible to the current user.
	 *
	 * @param array $input {
	 *     @type string $search    Optional.
	 *     @type string $mime_type Optional. e.g. 'image/jpeg'.
	 *     @type int    $page      Optional. Default 1.
	 *     @type int    $per_page  Optional. 1–50, default 10.
	 * }
	 * @return array
	 */
	public static function list_media( $input ) {
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'perm'           => 'readable',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = sanitize_mime_type( $input['mime_type'] );
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $attachment ) {
			// Do not expose an attachment the authenticated user cannot read.
			if ( ! current_user_can( 'read_post', $attachment->ID ) ) {
				continue;
			}
			$items[] = self::format_media( $attachment );
		}

		return array(
			'media'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get one attachment's complete public metadata.
	 *
	 * @param array $input { @type int $media_id Required. }
	 * @return array|WP_Error
	 */
	public static function get_media( $input ) {
		$media_id = isset( $input['media_id'] ) ? $input['media_id'] : 0;
		$media    = self::validate_media_for_read( $media_id );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return self::format_media( $media );
	}

	/* ------------------------------------------------------------------
	 * Upload and replacement operations
	 * ---------------------------------------------------------------- */

	/**
	 * Upload content supplied by the MCP client as a new attachment.
	 *
	 * The input deliberately contains base64 content rather than a path. The
	 * temporary file exists only during the core sideload operation and its
	 * path is never returned to the caller.
	 *
	 * @param array $input Upload input.
	 * @return array|WP_Error
	 */
	public static function upload_media( $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to upload media.', 'wordpress-mcp-abilities' ) );
		}

		$parent_id = self::validate_optional_parent( $input );
		if ( is_wp_error( $parent_id ) ) {
			return $parent_id;
		}

		$staged = self::stage_base64_file( $input );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}

		$result = self::create_from_staged_file( $staged, $parent_id, $input );
		self::remove_temp_file( $staged['tmp_name'] );
		return $result;
	}

	/**
	 * Download a remote file safely and create an attachment.
	 *
	 * `wp_safe_remote_get()` and the additional DNS/IP checks are both used:
	 * the former protects redirects through WordPress HTTP, while the latter
	 * makes the allow/deny policy explicit at the ability boundary.
	 *
	 * @param array $input URL upload input.
	 * @return array|WP_Error
	 */
	public static function upload_media_from_url( $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to upload media.', 'wordpress-mcp-abilities' ) );
		}

		$url = isset( $input['url'] ) ? $input['url'] : '';
		$url_error = WP_MCP_Permissions::validate_remote_url( $url, $input );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}

		$parent_id = self::validate_optional_parent( $input );
		if ( is_wp_error( $parent_id ) ) {
			return $parent_id;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'        => 3,
				'reject_unsafe_urls' => true,
				'limit_response_size' => self::MAX_UPLOAD_BYTES + 1,
				'headers'             => array( 'Accept' => '*/*' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return WP_MCP_Errors::download_failed( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return WP_MCP_Errors::download_failed(
				/* translators: %d: HTTP status code */
				sprintf( __( 'Remote server returned HTTP status %d.', 'wordpress-mcp-abilities' ), $status )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return WP_MCP_Errors::download_failed( __( 'The remote response contained no file data.', 'wordpress-mcp-abilities' ) );
		}
		if ( strlen( $body ) > self::MAX_UPLOAD_BYTES ) {
			return WP_MCP_Errors::upload_too_large( self::MAX_UPLOAD_BYTES );
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( is_array( $content_type ) ) {
			$content_type = reset( $content_type );
		}
		$content_type = is_string( $content_type ) ? strtolower( trim( strtok( $content_type, ';' ) ) ) : '';
		$filename     = ! empty( $input['filename'] ) ? $input['filename'] : wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$filename     = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			$filename = 'remote-media';
		}
		if ( false === strpos( $filename, '.' ) && $content_type ) {
			$extension = self::extension_for_mime( $content_type );
			if ( $extension ) {
				$filename .= '.' . $extension;
			}
		}

		$tmp_name = wp_tempnam( $filename );
		// A local wp_tempnam() staging file ahead of wp_handle_sideload() — the same
		// pattern core's own upload pipeline uses; WP_Filesystem is for the media
		// library's final destination, not this local temp step.
		if ( ! $tmp_name || false === file_put_contents( $tmp_name, $body, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			self::remove_temp_file( $tmp_name );
			return WP_MCP_Errors::upload_failed( __( 'The remote file could not be staged safely.', 'wordpress-mcp-abilities' ) );
		}

		$staged = array(
			'tmp_name' => $tmp_name,
			'name'     => $filename,
		);
		$result = self::create_from_staged_file( $staged, $parent_id, $input );
		self::remove_temp_file( $tmp_name );
		return $result;
	}

	/**
	 * Replace an attachment's underlying file while preserving its ID.
	 *
	 * WordPress core's sideload and attachment-metadata APIs perform the
	 * upload and derivative generation. Existing attachment files are removed
	 * only after the new file and metadata have been stored successfully.
	 *
	 * @param array $input Replacement input.
	 * @return array|WP_Error
	 */
	public static function replace_media( $input ) {
		$media_id = isset( $input['media_id'] ) ? $input['media_id'] : 0;
		$media    = self::validate_media_for_mutation( $media_id );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		/*
		 * Replacing sideloads a brand new binary onto the server, exactly like
		 * upload-media does, so it needs the same upload_files capability. Left
		 * on edit_post alone, a role holding edit_others_posts but explicitly
		 * denied uploads could still push new file content through this path.
		 */
		if ( ! current_user_can( 'upload_files' ) ) {
			WP_MCP_Audit::log( 'wp-mcp/replace-media', $media->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to upload files.', 'wordpress-mcp-abilities' ) );
		}

		$staged = self::stage_base64_file( $input );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$checked = self::validate_staged_file( $staged );
		if ( is_wp_error( $checked ) ) {
			self::remove_temp_file( $staged['tmp_name'] );
			return $checked;
		}
		if ( ! empty( $input['mime_type'] ) && sanitize_mime_type( $input['mime_type'] ) !== $checked['type'] ) {
			self::remove_temp_file( $staged['tmp_name'] );
			return WP_MCP_Errors::unsupported_media_type();
		}

		$old_file    = get_attached_file( $media->ID );
		$old_meta    = wp_get_attachment_metadata( $media->ID );
		$old_backups = get_post_meta( $media->ID, '_wp_attachment_backup_sizes', true );
		$sideload = array(
			'name'     => $staged['name'],
			'type'     => $checked['type'],
			'tmp_name' => $staged['tmp_name'],
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $staged['tmp_name'] ),
		);
		$upload   = wp_handle_sideload(
			$sideload,
			array( 'test_form' => false ),
			$media->post_date
		);
		// wp_handle_sideload moves the temporary file on success.
		if ( isset( $upload['error'] ) ) {
			self::remove_temp_file( $staged['tmp_name'] );
			return WP_MCP_Errors::upload_failed( $upload['error'] );
		}

		$new_file = $upload['file'];
		$new_meta = wp_generate_attachment_metadata( $media->ID, $new_file );
		if ( ! is_array( $new_meta ) ) {
			self::remove_temp_file( $new_file );
			return WP_MCP_Errors::replace_failed( __( 'WordPress could not generate metadata for the replacement file.', 'wordpress-mcp-abilities' ) );
		}

		$update = wp_update_post(
			array(
				'ID'             => $media->ID,
				'post_mime_type' => $checked['type'],
				'guid'           => $upload['url'],
				'post_title'     => ! empty( $input['title'] ) ? sanitize_text_field( $input['title'] ) : $media->post_title,
			),
			true
		);
		if ( is_wp_error( $update ) ) {
			self::remove_temp_file( $new_file );
			return WP_MCP_Errors::replace_failed( __( 'The attachment could not be updated with the replacement file.', 'wordpress-mcp-abilities' ) );
		}

		$attached_updated = update_attached_file( $media->ID, $new_file );
		$metadata_updated = wp_update_attachment_metadata( $media->ID, $new_meta );
		$attached_matches = get_attached_file( $media->ID ) === $new_file;
		$metadata_matches = wp_get_attachment_metadata( $media->ID ) === $new_meta;
		if ( ( ! $attached_updated && ! $attached_matches ) || ( ! $metadata_updated && ! $metadata_matches ) ) {
			// Restore every persisted field before removing the replacement files.
			wp_update_post(
				array(
					'ID'             => $media->ID,
					'post_mime_type' => $media->post_mime_type,
					'guid'           => $media->guid,
					'post_title'     => $media->post_title,
				),
				true
			);
			if ( $old_file ) {
				update_attached_file( $media->ID, $old_file );
			} else {
				delete_post_meta( $media->ID, '_wp_attached_file' );
			}
			if ( is_array( $old_meta ) ) {
				wp_update_attachment_metadata( $media->ID, $old_meta );
			} else {
				delete_post_meta( $media->ID, '_wp_attachment_metadata' );
			}

			$restored_post     = get_post( $media->ID );
			$rollback_complete = $restored_post
				&& get_attached_file( $media->ID ) === $old_file
				&& wp_get_attachment_metadata( $media->ID ) === $old_meta
				&& $media->post_mime_type === $restored_post->post_mime_type
				&& $media->guid === $restored_post->guid
				&& $media->post_title === $restored_post->post_title;

			// Clean up only after proving that no persisted field references the replacement.
			if ( $rollback_complete ) {
				if ( function_exists( 'wp_delete_attachment_files' ) ) {
					wp_delete_attachment_files( $media->ID, $new_meta, array(), $new_file );
				} else {
					self::remove_temp_file( $new_file );
				}
				return WP_MCP_Errors::replace_failed( __( 'The attachment could not be updated with the replacement file.', 'wordpress-mcp-abilities' ) );
			}

			return WP_MCP_Errors::replace_failed( __( 'The attachment replacement failed and rollback was incomplete; replacement files were retained to avoid broken references.', 'wordpress-mcp-abilities' ) );
		}

		// Core's deletion helper removes the original and generated derivatives
		// from the old attachment metadata without accepting caller paths.
		if ( $old_file && function_exists( 'wp_delete_attachment_files' ) ) {
			wp_delete_attachment_files( $media->ID, is_array( $old_meta ) ? $old_meta : array(), is_array( $old_backups ) ? $old_backups : array(), $old_file );
		}

		if ( isset( $input['alt_text'] ) ) {
			self::save_alt_text( $media->ID, $input['alt_text'] );
		}
		WP_MCP_Audit::log( 'wp-mcp/replace-media', $media->ID, true );
		return self::format_media( get_post( $media->ID ) );
	}

	/* ------------------------------------------------------------------
	 * Metadata and relationships
	 * ---------------------------------------------------------------- */

	/**
	 * Update the attachment's editable metadata only.
	 *
	 * @param array $input Metadata input.
	 * @return array|WP_Error
	 */
	public static function update_media( $input ) {
		$media_id = isset( $input['media_id'] ) ? $input['media_id'] : 0;
		$media    = self::validate_media_for_mutation( $media_id );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		$post_data = array( 'ID' => $media->ID );
		$has_field = false;
		if ( isset( $input['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $input['title'] );
			$has_field = true;
		}
		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = wp_kses_post( $input['caption'] );
			$has_field = true;
		}
		if ( isset( $input['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $input['description'] );
			$has_field = true;
		}
		if ( isset( $input['alt_text'] ) ) {
			$has_field = true;
		}
		if ( ! $has_field ) {
			WP_MCP_Audit::log( 'wp-mcp/update-media', $media->ID, false, 'wp_mcp_validation_error' );
			return WP_MCP_Errors::validation_error( __( 'At least one editable media field is required.', 'wordpress-mcp-abilities' ) );
		}

		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-media', $media->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to update media metadata.', 'wordpress-mcp-abilities' ) );
		}
		if ( isset( $input['alt_text'] ) ) {
			self::save_alt_text( $media->ID, $input['alt_text'] );
		}

		WP_MCP_Audit::log( 'wp-mcp/update-media', $media->ID, true );
		return self::format_media( get_post( $media->ID ) );
	}

	/**
	 * Attach an attachment to an editable post/page.
	 *
	 * @param array $input { @type int $media_id @type int $post_id }
	 * @return array|WP_Error
	 */
	public static function attach_media( $input ) {
		$media = self::validate_media_for_mutation( isset( $input['media_id'] ) ? $input['media_id'] : 0 );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		$parent = self::validate_content_parent( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		$result = wp_update_post( array( 'ID' => $media->ID, 'post_parent' => $parent->ID ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/attach-media', $media->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::attach_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/attach-media', $media->ID, true );
		return self::format_media( get_post( $media->ID ) );
	}

	/**
	 * Detach an attachment from its current parent.
	 *
	 * @param array $input { @type int $media_id }
	 * @return array|WP_Error
	 */
	public static function detach_media( $input ) {
		$media = self::validate_media_for_mutation( isset( $input['media_id'] ) ? $input['media_id'] : 0 );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		if ( 0 === (int) $media->post_parent ) {
			WP_MCP_Audit::log( 'wp-mcp/detach-media', $media->ID, true );
			return self::format_media( $media );
		}

		/*
		 * Detaching mutates the *parent's* attachment relationship, so the
		 * caller needs edit rights on that post as well — attach_media()
		 * already requires them on both sides through
		 * validate_content_parent(), and the reverse operation must not be
		 * the cheaper one.
		 */
		if ( ! current_user_can( 'edit_post', (int) $media->post_parent ) ) {
			WP_MCP_Audit::log( 'wp-mcp/detach-media', $media->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to edit the content this attachment belongs to.', 'wordpress-mcp-abilities' ) );
		}

		$result = wp_update_post( array( 'ID' => $media->ID, 'post_parent' => 0 ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/detach-media', $media->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::detach_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/detach-media', $media->ID, true );
		return self::format_media( get_post( $media->ID ) );
	}

	/**
	 * Regenerate attachment metadata and image sub-sizes.
	 *
	 * @param array $input { @type int $media_id }
	 * @return array|WP_Error
	 */
	public static function regenerate_media_metadata( $input ) {
		$media = self::validate_media_for_mutation( isset( $input['media_id'] ) ? $input['media_id'] : 0 );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		$file = get_attached_file( $media->ID );
		if ( ! $file || ! is_string( $file ) || ! is_readable( $file ) ) {
			WP_MCP_Audit::log( 'wp-mcp/regenerate-media-metadata', $media->ID, false, 'wp_mcp_media_file_missing' );
			return WP_MCP_Errors::media_file_missing();
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $media->ID, $file );
		if ( ! is_array( $metadata ) || ! wp_update_attachment_metadata( $media->ID, $metadata ) ) {
			WP_MCP_Audit::log( 'wp-mcp/regenerate-media-metadata', $media->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to regenerate media metadata.', 'wordpress-mcp-abilities' ) );
		}

		WP_MCP_Audit::log( 'wp-mcp/regenerate-media-metadata', $media->ID, true );
		return self::format_media( get_post( $media->ID ) );
	}

	/**
	 * Set an existing image as featured media on a post or page.
	 *
	 * The historical `post_id` input is preserved; the target may now be a
	 * post or page while the ability remains one explicit operation.
	 *
	 * @param array $input { @type int $post_id @type int $media_id }
	 * @return array|WP_Error
	 */
	public static function set_featured_image( $input ) {
		$parent = self::validate_content_parent( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
		if ( is_wp_error( $parent ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-featured-image', absint( isset( $input['post_id'] ) ? $input['post_id'] : 0 ), false, $parent->get_error_code() );
			return $parent;
		}

		$media_id = WP_MCP_Permissions::validate_positive_int( isset( $input['media_id'] ) ? $input['media_id'] : 0, 'media_id' );
		if ( is_wp_error( $media_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-featured-image', $parent->ID, false, $media_id->get_error_code() );
			return $media_id;
		}
		$media = get_post( $media_id );
		if ( ! $media || 'attachment' !== $media->post_type ) {
			WP_MCP_Audit::log( 'wp-mcp/set-featured-image', $parent->ID, false, 'wp_mcp_invalid_media' );
			return WP_MCP_Errors::invalid_media();
		}
		if ( ! current_user_can( 'read_post', $media_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-featured-image', $parent->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to use this media.', 'wordpress-mcp-abilities' ) );
		}
		if ( ! wp_attachment_is_image( $media_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/set-featured-image', $parent->ID, false, 'wp_mcp_not_an_image' );
			return WP_MCP_Errors::not_an_image();
		}

		$current_thumbnail = (int) get_post_thumbnail_id( $parent->ID );
		if ( $current_thumbnail !== $media_id ) {
			if ( ! set_post_thumbnail( $parent->ID, $media_id ) ) {
				WP_MCP_Audit::log( 'wp-mcp/set-featured-image', $parent->ID, false, 'wp_mcp_update_failed' );
				return WP_MCP_Errors::update_failed( __( 'Failed to set featured image.', 'wordpress-mcp-abilities' ) );
			}
		}
		WP_MCP_Audit::log( 'wp-mcp/set-featured-image', $parent->ID, true );
		return array(
			'post_id'   => (int) $parent->ID,
			'media_id'  => $media_id,
			'media_url' => (string) wp_get_attachment_url( $media_id ),
		);
	}

	/**
	 * Remove featured media from a post or page. Idempotent.
	 *
	 * @param array $input { @type int $post_id }
	 * @return array|WP_Error
	 */
	public static function remove_featured_image( $input ) {
		$parent = self::validate_content_parent( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
		if ( is_wp_error( $parent ) ) {
			WP_MCP_Audit::log( 'wp-mcp/remove-featured-image', absint( isset( $input['post_id'] ) ? $input['post_id'] : 0 ), false, $parent->get_error_code() );
			return $parent;
		}
		$previous = (int) get_post_thumbnail_id( $parent->ID );
		if ( $previous && ! delete_post_thumbnail( $parent->ID ) ) {
			WP_MCP_Audit::log( 'wp-mcp/remove-featured-image', $parent->ID, false, 'wp_mcp_update_failed' );
			return WP_MCP_Errors::update_failed( __( 'Failed to remove featured image.', 'wordpress-mcp-abilities' ) );
		}
		WP_MCP_Audit::log( 'wp-mcp/remove-featured-image', $parent->ID, true );
		return array(
			'post_id'            => (int) $parent->ID,
			'removed_media_id'   => $previous,
			'featured_media_id'  => 0,
		);
	}

	/* ------------------------------------------------------------------
	 * Lifecycle and bulk operations
	 * ---------------------------------------------------------------- */

	/**
	 * Move an attachment to trash. Idempotent.
	 *
	 * @param array $input { @type int $media_id }
	 * @return array|WP_Error
	 */
	public static function trash_media( $input ) {
		$media = self::validate_media_for_deletion( isset( $input['media_id'] ) ? $input['media_id'] : 0 );
		if ( is_wp_error( $media ) ) {
			WP_MCP_Audit::log( 'wp-mcp/trash-media', absint( isset( $input['media_id'] ) ? $input['media_id'] : 0 ), false, $media->get_error_code() );
			return $media;
		}
		if ( 'trash' !== $media->post_status ) {
			$result = wp_trash_post( $media->ID );
			if ( ! $result ) {
				WP_MCP_Audit::log( 'wp-mcp/trash-media', $media->ID, false, 'wp_mcp_trash_failed' );
				return WP_MCP_Errors::trash_failed();
			}
		}
		WP_MCP_Audit::log( 'wp-mcp/trash-media', $media->ID, true );
		return self::format_media( get_post( $media->ID ) );
	}

	/**
	 * Restore an attachment from trash. Idempotent when not trashed.
	 *
	 * @param array $input { @type int $media_id }
	 * @return array|WP_Error
	 */
	public static function restore_media( $input ) {
		$media = self::validate_media_for_deletion( isset( $input['media_id'] ) ? $input['media_id'] : 0 );
		if ( is_wp_error( $media ) ) {
			WP_MCP_Audit::log( 'wp-mcp/restore-media', absint( isset( $input['media_id'] ) ? $input['media_id'] : 0 ), false, $media->get_error_code() );
			return $media;
		}
		if ( 'trash' === $media->post_status && ! wp_untrash_post( $media->ID ) ) {
			WP_MCP_Audit::log( 'wp-mcp/restore-media', $media->ID, false, 'wp_mcp_restore_failed' );
			return WP_MCP_Errors::restore_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/restore-media', $media->ID, true );
		return self::format_media( get_post( $media->ID ) );
	}

	/**
	 * Permanently delete an attachment and its core-managed files.
	 *
	 * @param array $input { @type int $media_id }
	 * @return array|WP_Error
	 */
	public static function delete_media_permanently( $input ) {
		$media = self::validate_media_for_deletion( isset( $input['media_id'] ) ? $input['media_id'] : 0 );
		if ( is_wp_error( $media ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-media-permanently', absint( isset( $input['media_id'] ) ? $input['media_id'] : 0 ), false, $media->get_error_code() );
			return $media;
		}
		if ( ! current_user_can( 'delete_posts' ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-media-permanently', $media->ID, false, 'wp_mcp_permission_denied' );
			return WP_MCP_Errors::permission_denied( __( 'Permanently deleting media requires the delete_posts capability.', 'wordpress-mcp-abilities' ) );
		}
		$media_id = $media->ID;
		if ( ! wp_delete_attachment( $media_id, true ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-media-permanently', $media_id, false, 'wp_mcp_delete_failed' );
			return WP_MCP_Errors::delete_failed( __( 'Failed to permanently delete media.', 'wordpress-mcp-abilities' ) );
		}
		WP_MCP_Audit::log( 'wp-mcp/delete-media-permanently', $media_id, true );
		return array( 'id' => (int) $media_id, 'deleted' => true );
	}

	/**
	 * Trash up to 20 attachments and report each result independently.
	 *
	 * @param array $input { @type int[] $media_ids Required. Max 20. }
	 * @return array|WP_Error
	 */
	public static function bulk_trash_media( $input ) {
		$ids = isset( $input['media_ids'] ) && is_array( $input['media_ids'] ) ? $input['media_ids'] : array();
		if ( count( $ids ) > self::MAX_BULK_ITEMS ) {
			WP_MCP_Audit::log( 'wp-mcp/bulk-trash-media', 0, false, 'wp_mcp_bulk_limit_exceeded' );
			return WP_MCP_Errors::bulk_limit_exceeded( self::MAX_BULK_ITEMS );
		}
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return WP_MCP_Errors::validation_error( __( 'At least one media ID is required.', 'wordpress-mcp-abilities' ) );
		}

		$results = array();
		$trashed = 0;
		$failed  = 0;
		foreach ( $ids as $id ) {
			$result = self::trash_media( array( 'media_id' => $id ) );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'id' => $id, 'success' => false, 'error_code' => $result->get_error_code() );
				++$failed;
			} else {
				$results[] = array( 'id' => $id, 'success' => true );
				++$trashed;
			}
		}
		WP_MCP_Audit::log( 'wp-mcp/bulk-trash-media', 0, true );
		return array( 'results' => $results, 'trashed' => $trashed, 'failed' => $failed );
	}

	/* ------------------------------------------------------------------
	 * Validation and upload helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Validate an attachment for read access.
	 *
	 * @param mixed $media_id Raw ID.
	 * @return WP_Post|WP_Error
	 */
	private static function validate_media_for_read( $media_id ) {
		$media_id = WP_MCP_Permissions::validate_positive_int( $media_id, 'media_id' );
		if ( is_wp_error( $media_id ) ) {
			return $media_id;
		}
		$media = get_post( $media_id );
		if ( ! $media || 'attachment' !== $media->post_type ) {
			return WP_MCP_Errors::invalid_media();
		}
		if ( ! current_user_can( 'read_post', $media->ID ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to read this media.', 'wordpress-mcp-abilities' ) );
		}
		return $media;
	}

	/**
	 * Validate an attachment for editing.
	 *
	 * @param mixed $media_id Raw ID.
	 * @return WP_Post|WP_Error
	 */
	private static function validate_media_for_mutation( $media_id ) {
		$media_id = WP_MCP_Permissions::validate_positive_int( $media_id, 'media_id' );
		if ( is_wp_error( $media_id ) ) {
			return $media_id;
		}
		$media = get_post( $media_id );
		if ( ! $media || 'attachment' !== $media->post_type ) {
			return WP_MCP_Errors::invalid_media();
		}
		if ( ! current_user_can( 'edit_post', $media->ID ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to edit this media.', 'wordpress-mcp-abilities' ) );
		}
		return $media;
	}

	/**
	 * Validate an attachment for trash/restore/delete.
	 *
	 * @param mixed $media_id Raw ID.
	 * @return WP_Post|WP_Error
	 */
	private static function validate_media_for_deletion( $media_id ) {
		$media_id = WP_MCP_Permissions::validate_positive_int( $media_id, 'media_id' );
		if ( is_wp_error( $media_id ) ) {
			return $media_id;
		}
		$media = get_post( $media_id );
		if ( ! $media || 'attachment' !== $media->post_type ) {
			return WP_MCP_Errors::invalid_media();
		}
		if ( ! current_user_can( 'delete_post', $media->ID ) ) {
			return WP_MCP_Errors::permission_denied( __( 'You do not have permission to delete this media.', 'wordpress-mcp-abilities' ) );
		}
		return $media;
	}

	/**
	 * Validate a post/page used as an attachment parent or featured target.
	 *
	 * @param mixed $post_id Raw ID.
	 * @return WP_Post|WP_Error
	 */
	private static function validate_content_parent( $post_id ) {
		$post_id = WP_MCP_Permissions::validate_positive_int( $post_id, 'post_id' );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return WP_MCP_Errors::invalid_post();
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return WP_MCP_Errors::unsupported_post_type( __( 'This media operation only targets a post or page.', 'wordpress-mcp-abilities' ) );
		}
		return WP_MCP_Permissions::validate_post_for_mutation( $post->ID, $post->post_type );
	}

	/**
	 * Validate optional attachment parent.
	 *
	 * @param array $input Input.
	 * @return int|WP_Error
	 */
	private static function validate_optional_parent( $input ) {
		if ( empty( $input['parent_id'] ) ) {
			return 0;
		}
		$parent = self::validate_content_parent( $input['parent_id'] );
		return is_wp_error( $parent ) ? $parent : (int) $parent->ID;
	}

	/**
	 * Stage base64 client content into a private temporary file.
	 *
	 * @param array $input Input containing content_base64 and filename.
	 * @return array|WP_Error
	 */
	private static function stage_base64_file( $input ) {
		$encoded  = isset( $input['content_base64'] ) ? $input['content_base64'] : '';
		$filename = isset( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : '';
		if ( ! is_string( $encoded ) || '' === $encoded || '' === $filename || false !== strpos( $encoded, 'base64,' ) ) {
			return WP_MCP_Errors::validation_error( __( 'content_base64 and a safe filename are required; filesystem paths are not accepted.', 'wordpress-mcp-abilities' ) );
		}
		if ( strlen( $encoded ) > (int) ceil( self::MAX_UPLOAD_BYTES * 1.4 ) ) {
			return WP_MCP_Errors::upload_too_large( self::MAX_UPLOAD_BYTES );
		}
		// Strict mode ($3 = true): decoding a client-supplied upload payload, not obfuscation.
		$binary = base64_decode( preg_replace( '/\s+/', '', $encoded ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $binary || '' === $binary ) {
			return WP_MCP_Errors::validation_error( __( 'content_base64 is not valid base64 data.', 'wordpress-mcp-abilities' ) );
		}
		if ( strlen( $binary ) > self::MAX_UPLOAD_BYTES ) {
			return WP_MCP_Errors::upload_too_large( self::MAX_UPLOAD_BYTES );
		}
		$tmp_name = wp_tempnam( $filename );
		// Same local staging step as stage_remote_file() above — see that comment.
		if ( ! $tmp_name || false === file_put_contents( $tmp_name, $binary, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			self::remove_temp_file( $tmp_name );
			return WP_MCP_Errors::upload_failed( __( 'The supplied file could not be staged safely.', 'wordpress-mcp-abilities' ) );
		}
		return array( 'tmp_name' => $tmp_name, 'name' => $filename );
	}

	/**
	 * Validate actual file type using WordPress's allowed MIME map.
	 *
	 * @param array $staged Staged file descriptor.
	 * @return array|WP_Error
	 */
	private static function validate_staged_file( $staged ) {
		$checked = wp_check_filetype_and_ext( $staged['tmp_name'], $staged['name'], get_allowed_mime_types() );
		if ( empty( $checked['type'] ) || empty( $checked['ext'] ) ) {
			return WP_MCP_Errors::unsupported_media_type();
		}
		return $checked;
	}

	/**
	 * Create an attachment via WordPress's core sideload pipeline.
	 *
	 * @param array $staged   Staged file.
	 * @param int   $parent_id Parent post ID or 0.
	 * @param array $input    Original input.
	 * @return array|WP_Error
	 */
	private static function create_from_staged_file( $staged, $parent_id, $input ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$checked = self::validate_staged_file( $staged );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		if ( ! empty( $input['mime_type'] ) && sanitize_mime_type( $input['mime_type'] ) !== $checked['type'] ) {
			return WP_MCP_Errors::unsupported_media_type();
		}

		$post_data = array();
		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = wp_kses_post( $input['caption'] );
		}
		if ( isset( $input['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $input['description'] );
		}
		if ( isset( $input['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $input['title'] );
		}
		$file_array = array(
			'name'     => $staged['name'],
			'type'     => $checked['type'],
			'tmp_name' => $staged['tmp_name'],
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $staged['tmp_name'] ),
		);
		$attachment_id = media_handle_sideload( $file_array, $parent_id, null, $post_data );
		if ( is_wp_error( $attachment_id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/upload-media', 0, false, 'wp_mcp_upload_failed' );
			return WP_MCP_Errors::upload_failed( $attachment_id->get_error_message() );
		}
		if ( isset( $input['alt_text'] ) ) {
			self::save_alt_text( $attachment_id, $input['alt_text'] );
		}
		$ability = isset( $input['url'] ) ? 'wp-mcp/upload-media-from-url' : 'wp-mcp/upload-media';
		WP_MCP_Audit::log( $ability, $attachment_id, true );
		return self::format_media( get_post( $attachment_id ) );
	}

	/**
	 * @param string $mime MIME type.
	 * @return string
	 */
	private static function extension_for_mime( $mime ) {
		foreach ( wp_get_mime_types() as $extensions => $type ) {
			if ( $type === $mime ) {
				return strtok( $extensions, '|' );
			}
		}
		return '';
	}

	/**
	 * @param int    $media_id Attachment ID.
	 * @param string $alt      Raw alt text.
	 */
	private static function save_alt_text( $media_id, $alt ) {
		$alt = sanitize_text_field( (string) $alt );
		if ( '' === $alt ) {
			delete_post_meta( $media_id, '_wp_attachment_image_alt' );
		} else {
			update_post_meta( $media_id, '_wp_attachment_image_alt', $alt );
		}
	}

	/**
	 * @param string $path Temporary or newly staged internal path.
	 */
	private static function remove_temp_file( $path ) {
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			// Internal cleanup only; caller cannot provide this path.
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Format attachment data without exposing server paths.
	 *
	 * @param WP_Post $media Attachment.
	 * @return array
	 */
	private static function format_media( $media ) {
		$metadata = wp_get_attachment_metadata( $media->ID );
		$result   = array(
			'id'          => (int) $media->ID,
			'title'       => $media->post_title,
			'caption'     => $media->post_excerpt,
			'description' => $media->post_content,
			'alt_text'    => (string) get_post_meta( $media->ID, '_wp_attachment_image_alt', true ),
			'mime_type'   => $media->post_mime_type,
			'url'         => (string) wp_get_attachment_url( $media->ID ),
			'parent_id'   => (int) $media->post_parent,
			'date'        => $media->post_date,
			'modified'    => $media->post_modified,
		);
		if ( is_array( $metadata ) ) {
			// wordpress-stubs' wp_get_attachment_metadata() shape assumes an image
			// attachment; a non-image (PDF, video, audio, ...) genuinely lacks
			// width/height/sizes, so these isset() checks are load-bearing, not dead code.
			if ( isset( $metadata['width'] ) ) { // @phpstan-ignore-line
				$result['width'] = (int) $metadata['width'];
			}
			if ( isset( $metadata['height'] ) ) { // @phpstan-ignore-line
				$result['height'] = (int) $metadata['height'];
			}
			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) { // @phpstan-ignore-line
				$result['sizes'] = array();
				foreach ( $metadata['sizes'] as $size => $size_data ) {
					if ( is_array( $size_data ) ) {
						$result['sizes'][ sanitize_key( $size ) ] = array(
							'file'      => isset( $size_data['file'] ) ? sanitize_file_name( $size_data['file'] ) : '',
							'width'     => isset( $size_data['width'] ) ? (int) $size_data['width'] : 0,
							'height'    => isset( $size_data['height'] ) ? (int) $size_data['height'] : 0,
							'mime_type' => isset( $size_data['mime-type'] ) ? sanitize_mime_type( $size_data['mime-type'] ) : '',
						);
					}
				}
			}
		}
		return $result;
	}
}
