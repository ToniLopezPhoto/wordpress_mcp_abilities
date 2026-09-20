<?php
/**
 * WordPress MCP Abilities — Site Editor and block navigation callbacks.
 *
 * All persistence goes through WordPress core post/entity APIs. Theme files
 * are never read or written by this class; a theme-sourced template is
 * customized by creating the corresponding database entity.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Site_Editor
 */
class WP_MCP_Site_Editor {

	/** List templates from the active theme and database. */
	public static function list_templates( $input = array() ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		return self::list_block_templates( 'wp_template', $input );
	}

	/** Get one template by its core template identifier. */
	public static function get_template( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$template = self::validate_template( isset( $input['template_id'] ) ? $input['template_id'] : '', 'wp_template' );
		return is_wp_error( $template ) ? $template : self::format_template( $template );
	}

	/** List templates parts registered by the active theme or stored in DB. */
	public static function list_template_parts( $input = array() ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		return self::list_block_templates( 'wp_template_part', $input );
	}

	/** Get one template part by its core template identifier. */
	public static function get_template_part( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$template = self::validate_template( isset( $input['template_id'] ) ? $input['template_id'] : '', 'wp_template_part' );
		return is_wp_error( $template ) ? $template : self::format_template( $template );
	}

	/** Create or customize a template through wp_template persistence. */
	public static function update_template( $input ) {
		return self::save_template( $input, 'wp_template', 'wp-mcp/update-template' );
	}

	/** Create or customize a template part through wp_template_part persistence. */
	public static function update_template_part( $input ) {
		return self::save_template( $input, 'wp_template_part', 'wp-mcp/update-template-part' );
	}

	/** Delete only a database customization; theme files remain untouched. */
	public static function delete_template( $input ) {
		return self::delete_template_entity( $input, 'wp_template', 'wp-mcp/delete-template' );
	}

	/** Delete only a database template-part customization. */
	public static function delete_template_part( $input ) {
		return self::delete_template_entity( $input, 'wp_template_part', 'wp-mcp/delete-template-part' );
	}

	/**
	 * List registered patterns and persisted synced patterns.
	 *
	 * Two heterogeneous sources (a registry array and wp_block posts) are
	 * merged before paginating: neither backend query API supports a
	 * combined offset, so the full merged set is paginated in memory, same
	 * as list_block_templates().
	 */
	public static function list_patterns( $input = array() ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 10, 50 );
		$patterns = array();
		if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
			foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
				$patterns[] = self::format_registered_pattern( $pattern );
			}
		}
		$synced_posts = get_posts( array( 'post_type' => 'wp_block', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( $synced_posts as $post ) {
			if ( current_user_can( 'read_post', $post->ID ) ) {
				$patterns[] = self::format_synced_pattern( $post );
			}
		}
		$total = count( $patterns );
		return array(
			'patterns'    => array_slice( $patterns, ( $page - 1 ) * $per_page, $per_page ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/** Get one registered pattern by name or one synced pattern by post ID. */
	public static function get_pattern( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$id = isset( $input['pattern_id'] ) ? $input['pattern_id'] : '';
		if ( WP_MCP_Permissions::is_strict_positive_int_id( $id ) ) {
			$post = get_post( (int) $id );
			if ( ! $post || 'wp_block' !== $post->post_type || ! current_user_can( 'read_post', $post->ID ) ) { return WP_MCP_Errors::invalid_pattern(); }
			return self::format_synced_pattern( $post );
		}
		if ( ! is_string( $id ) || '' === trim( $id ) ) { return WP_MCP_Errors::invalid_pattern(); }
		if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
			$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $id );
			if ( $pattern ) { return self::format_registered_pattern( $pattern ); }
		}
		return WP_MCP_Errors::invalid_pattern();
	}

	/** Create a database-backed synced pattern. */
	public static function create_synced_pattern( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		// The Site Editor gate above is not the native creation capability for wp_block: check it explicitly.
		$capability = self::require_post_type_create( 'wp_block' );
		if ( is_wp_error( $capability ) ) { return $capability; }
		if ( ! self::valid_pattern_input( $input ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'Pattern title and non-empty block content are required.', 'wordpress-mcp-abilities' ) ); }
		$content = self::validate_block_content( $input['content'] );
		if ( is_wp_error( $content ) ) { return $content; }
		$id = wp_insert_post( wp_slash( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => sanitize_text_field( $input['title'] ), 'post_content' => $content, 'post_author' => get_current_user_id(), 'meta_input' => array( 'wp_pattern_sync_status' => 'synced' ) ) ), true );
		if ( is_wp_error( $id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-synced-pattern', 0, false, 'wp_mcp_site_editor_create_failed' );
			return WP_MCP_Errors::site_editor_create_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/create-synced-pattern', $id, true );
		return self::format_synced_pattern( get_post( $id ) );
	}

	/** Update an explicit set of fields on one synced pattern. */
	public static function update_synced_pattern( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$post = self::validate_pattern_post( isset( $input['pattern_id'] ) ? $input['pattern_id'] : 0, 'edit_post' );
		if ( is_wp_error( $post ) ) { return $post; }
		$data = array( 'ID' => $post->ID );
		if ( isset( $input['title'] ) ) {
			if ( ! is_string( $input['title'] ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'Pattern title must be a string.', 'wordpress-mcp-abilities' ) ); }
			$data['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			if ( ! is_string( $input['content'] ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'Pattern content must be a string.', 'wordpress-mcp-abilities' ) ); }
			$content = self::validate_block_content( $input['content'] );
			if ( is_wp_error( $content ) ) { return $content; }
			$data['post_content'] = $content;
		}
		if ( isset( $input['sync_status'] ) ) {
			if ( ! is_string( $input['sync_status'] ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'Pattern synchronization status must be a string.', 'wordpress-mcp-abilities' ) ); }
			$sync_status = sanitize_key( $input['sync_status'] );
			if ( ! in_array( $sync_status, array( 'synced', 'content-only', 'unsynced' ), true ) ) {
				return WP_MCP_Errors::site_editor_validation_error( __( 'The pattern synchronization status is invalid.', 'wordpress-mcp-abilities' ) );
			}
			$data['meta_input'] = array( 'wp_pattern_sync_status' => $sync_status );
		}
		if ( 1 === count( $data ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'At least one pattern field is required.', 'wordpress-mcp-abilities' ) ); }
		$result = wp_update_post( wp_slash( $data ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-synced-pattern', $post->ID, false, 'wp_mcp_site_editor_update_failed' );
			return WP_MCP_Errors::site_editor_update_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/update-synced-pattern', $post->ID, true );
		return self::format_synced_pattern( get_post( $post->ID ) );
	}

	/** Delete one synced pattern through the native post API. */
	public static function delete_synced_pattern( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$post = self::validate_pattern_post( isset( $input['pattern_id'] ) ? $input['pattern_id'] : 0, 'delete_post' );
		if ( is_wp_error( $post ) ) { return $post; }
		if ( ! wp_delete_post( $post->ID, true ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-synced-pattern', $post->ID, false, 'wp_mcp_site_editor_delete_failed' );
			return WP_MCP_Errors::site_editor_delete_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/delete-synced-pattern', $post->ID, true );
		return array( 'id' => (int) $post->ID, 'deleted' => true );
	}

	/** List persisted wp_navigation entities used by block themes. */
	public static function list_navigation_blocks( $input = array() ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 10, 50 );
		$query = new WP_Query( array( 'post_type' => 'wp_navigation', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => $per_page, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC' ) );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'read_post', $post->ID ) ) { $items[] = self::format_navigation( $post ); }
		}
		return array(
			'navigations' => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/** Get one persisted wp_navigation entity. */
	public static function get_navigation_block( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$post = self::validate_navigation_post( isset( $input['navigation_id'] ) ? $input['navigation_id'] : 0, 'read_post' );
		return is_wp_error( $post ) ? $post : self::format_navigation( $post );
	}

	/** Create a persisted wp_navigation entity. */
	public static function create_navigation_block( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$capability = self::require_post_type_create( 'wp_navigation' );
		if ( is_wp_error( $capability ) ) { return $capability; }
		if ( ! self::valid_navigation_input( $input ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'Navigation title and block content are required.', 'wordpress-mcp-abilities' ) ); }
		$content = self::validate_block_content( $input['content'] );
		if ( is_wp_error( $content ) ) { return $content; }
		$id = wp_insert_post( wp_slash( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => sanitize_text_field( $input['title'] ), 'post_content' => $content, 'post_author' => get_current_user_id() ) ), true );
		if ( is_wp_error( $id ) ) {
			WP_MCP_Audit::log( 'wp-mcp/create-navigation-block', 0, false, 'wp_mcp_site_editor_create_failed' );
			return WP_MCP_Errors::site_editor_create_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/create-navigation-block', $id, true );
		return self::format_navigation( get_post( $id ) );
	}

	/** Update title/content of a persisted wp_navigation entity. */
	public static function update_navigation_block( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$post = self::validate_navigation_post( isset( $input['navigation_id'] ) ? $input['navigation_id'] : 0, 'edit_post' );
		if ( is_wp_error( $post ) ) { return $post; }
		$data = array( 'ID' => $post->ID );
		if ( isset( $input['title'] ) ) {
			if ( ! is_string( $input['title'] ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'Navigation title must be a string.', 'wordpress-mcp-abilities' ) ); }
			$data['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			if ( ! is_string( $input['content'] ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'Navigation content must be a string.', 'wordpress-mcp-abilities' ) ); }
			$content = self::validate_block_content( $input['content'] );
			if ( is_wp_error( $content ) ) { return $content; }
			$data['post_content'] = $content;
		}
		if ( 1 === count( $data ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'At least one navigation field is required.', 'wordpress-mcp-abilities' ) ); }
		$result = wp_update_post( wp_slash( $data ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-navigation-block', $post->ID, false, 'wp_mcp_site_editor_update_failed' );
			return WP_MCP_Errors::site_editor_update_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/update-navigation-block', $post->ID, true );
		return self::format_navigation( get_post( $post->ID ) );
	}

	/** Delete a persisted wp_navigation entity. */
	public static function delete_navigation_block( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$post = self::validate_navigation_post( isset( $input['navigation_id'] ) ? $input['navigation_id'] : 0, 'delete_post' );
		if ( is_wp_error( $post ) ) { return $post; }
		if ( ! wp_delete_post( $post->ID, true ) ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-navigation-block', $post->ID, false, 'wp_mcp_site_editor_delete_failed' );
			return WP_MCP_Errors::site_editor_delete_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/delete-navigation-block', $post->ID, true );
		return array( 'id' => (int) $post->ID, 'deleted' => true );
	}

	/** Read theme capabilities and registered entities without touching files. */
	public static function get_theme_context( $input = array() ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$theme = wp_get_theme();
		return array(
			'name' => $theme->get( 'Name' ),
			'stylesheet' => get_stylesheet(),
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'template_editing' => current_theme_supports( 'block-templates' ),
			'menu_locations' => array_keys( get_registered_nav_menus() ),
			'global_styles_available' => class_exists( 'WP_Theme_JSON_Resolver' ) && function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json(),
		);
	}

	/**
	 * Read the user's persisted global styles JSON when core supports it.
	 *
	 * Output splits the theme.json document into its `settings` and
	 * `styles` branches — mirroring update_global_styles()'s input shape —
	 * so a caller can read-modify-write a single branch without having to
	 * know about the rest of the document.
	 */
	public static function get_global_styles( $input = array() ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		if ( ! self::global_styles_available() ) { return WP_MCP_Errors::site_editor_unsupported(); }
		$data = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$post_id = isset( $data['ID'] ) ? (int) $data['ID'] : 0;
		$document = self::decode_global_styles_settings( isset( $data['post_content'] ) ? $data['post_content'] : '' );
		return array(
			'id'       => $post_id,
			'theme'    => get_stylesheet(),
			'settings' => isset( $document['settings'] ) && is_array( $document['settings'] ) ? $document['settings'] : array(),
			'styles'   => isset( $document['styles'] ) && is_array( $document['styles'] ) ? $document['styles'] : array(),
		);
	}

	/** Update only the explicit settings/styles branches of user global styles. */
	public static function update_global_styles( $input ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		if ( ! self::global_styles_available() ) { return WP_MCP_Errors::site_editor_unsupported(); }
		$allowed = array( 'settings', 'styles' );
		$data = array();
		foreach ( $allowed as $key ) {
			if ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) { $data[ $key ] = $input[ $key ]; }
		}
		if ( empty( $data ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'At least one global styles branch is required.', 'wordpress-mcp-abilities' ) ); }
		$sanitized = new WP_Theme_JSON_Data( array( 'version' => WP_Theme_JSON::LATEST_SCHEMA ), 'custom' );
		$sanitized->update_with( $data );
		$sanitized_data = $sanitized->get_data();
		$data = array();
		foreach ( array( 'settings', 'styles' ) as $key ) {
			if ( isset( $sanitized_data[ $key ] ) ) { $data[ $key ] = $sanitized_data[ $key ]; }
		}
		$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( ! $post_id || ! $post ) { return WP_MCP_Errors::site_editor_update_failed(); }
		$current = self::decode_global_styles_settings( isset( $post['post_content'] ) ? $post['post_content'] : '' );
		foreach ( $data as $key => $value ) { $current[ $key ] = $value; }
		$current['version'] = isset( $current['version'] ) ? $current['version'] : WP_Theme_JSON::LATEST_SCHEMA;
		$current['isGlobalStylesUserThemeJSON'] = true;
		$result = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => wp_json_encode( $current ) ) ), true );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-global-styles', $post_id, false, 'wp_mcp_site_editor_update_failed' );
			return WP_MCP_Errors::site_editor_update_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/update-global-styles', $post_id, true );
		return self::get_global_styles();
	}

	/** List registered widget areas; widget CRUD is not exposed without a stable core entity API. */
	public static function list_widget_areas( $input = array() ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$areas = array();
		foreach ( (array) get_registered_sidebars() as $sidebar ) {
			$areas[] = array( 'id' => sanitize_key( $sidebar['id'] ), 'name' => wp_strip_all_tags( $sidebar['name'] ), 'description' => wp_strip_all_tags( $sidebar['description'] ), 'class' => sanitize_html_class( $sidebar['class'] ) );
		}
		return array( 'areas' => $areas, 'total' => count( $areas ), 'write_support' => false );
	}

	private static function require_manage_site() {
		return current_user_can( 'edit_theme_options' ) ? true : WP_MCP_Errors::navigation_permission_denied( __( 'You do not have permission to manage the Site Editor.', 'wordpress-mcp-abilities' ) );
	}

	private static function list_block_templates( $type, $input = array() ) {
		list( $page, $per_page ) = WP_MCP_Ability_Schema::paginate( $input, 10, 50 );
		$all = get_block_templates( array(), $type );
		$items = array();
		foreach ( array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) as $template ) { $items[] = self::format_template( $template ); }
		$total = count( $all );
		return array(
			'templates'   => $items,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/** @return WP_Block_Template|WP_Error */
	private static function validate_template( $id, $type ) {
		if ( ! is_string( $id ) || '' === trim( $id ) || false !== strpos( $id, '..' ) ) { return WP_MCP_Errors::invalid_template(); }
		$template = get_block_template( $id, $type );
		if ( ! $template || get_stylesheet() !== (string) $template->theme ) { return WP_MCP_Errors::invalid_template(); }
		return $template;
	}

	private static function save_template( $input, $type, $ability ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$template = self::validate_template( isset( $input['template_id'] ) ? $input['template_id'] : '', $type );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( ! isset( $input['content'] ) || ! is_string( $input['content'] ) ) { return WP_MCP_Errors::site_editor_validation_error( __( 'Template block content is required.', 'wordpress-mcp-abilities' ) ); }
		$content = self::validate_block_content( $input['content'] );
		if ( is_wp_error( $content ) ) { return $content; }
		$data = array( 'post_content' => $content, 'post_status' => 'publish' );
		if ( isset( $input['title'] ) ) { $data['post_title'] = sanitize_text_field( $input['title'] ); }
		if ( isset( $input['description'] ) ) { $data['post_excerpt'] = sanitize_textarea_field( $input['description'] ); }
		if ( ! empty( $template->wp_id ) && 'custom' === $template->source ) {
			$data['ID'] = (int) $template->wp_id;
			$id = wp_update_post( wp_slash( $data ), true );
		} else {
			$data['post_type'] = $type;
			$data['post_name'] = $template->slug;
			$data['post_title'] = isset( $data['post_title'] ) ? $data['post_title'] : $template->title;
			$data['post_author'] = get_current_user_id();
			$id = wp_insert_post( wp_slash( $data ), true );
			if ( ! is_wp_error( $id ) ) { wp_set_post_terms( $id, get_stylesheet(), 'wp_theme', false ); }
		}
		if ( is_wp_error( $id ) ) {
			WP_MCP_Audit::log( $ability, 0, false, 'wp_mcp_site_editor_update_failed' );
			return WP_MCP_Errors::site_editor_update_failed();
		}
		WP_MCP_Audit::log( $ability, $id, true );
		$updated = get_block_template( $template->theme . '//' . $template->slug, $type );
		return $updated ? self::format_template( $updated ) : self::format_template( $template );
	}

	private static function delete_template_entity( $input, $type, $ability ) {
		$permission = self::require_manage_site();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$template = self::validate_template( isset( $input['template_id'] ) ? $input['template_id'] : '', $type );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( empty( $template->wp_id ) || 'custom' !== $template->source ) { return WP_MCP_Errors::site_editor_validation_error( __( 'Theme files are immutable; only database customizations may be deleted.', 'wordpress-mcp-abilities' ) ); }
		if ( ! wp_delete_post( (int) $template->wp_id, true ) ) { WP_MCP_Audit::log( $ability, $template->wp_id, false, 'wp_mcp_site_editor_delete_failed' ); return WP_MCP_Errors::site_editor_delete_failed(); }
		WP_MCP_Audit::log( $ability, $template->wp_id, true );
		return array( 'id' => (int) $template->wp_id, 'deleted' => true );
	}

	private static function format_template( $template ) {
		$data = array( 'id' => (string) $template->id, 'slug' => (string) $template->slug, 'theme' => (string) $template->theme, 'type' => (string) $template->type, 'source' => (string) $template->source, 'origin' => (string) $template->origin, 'title' => (string) $template->title, 'description' => (string) $template->description, 'status' => (string) $template->status, 'wp_id' => (int) $template->wp_id, 'has_theme_file' => (bool) $template->has_theme_file, 'content' => (string) $template->content, 'modified' => (string) $template->modified );
		if ( 'wp_template_part' === $template->type ) { $data['area'] = (string) $template->area; }
		return $data;
	}

	private static function valid_pattern_input( $input ) { return isset( $input['title'], $input['content'] ) && is_string( $input['title'] ) && '' !== trim( $input['title'] ) && is_string( $input['content'] ) && '' !== trim( $input['content'] ); }
	private static function valid_navigation_input( $input ) { return self::valid_pattern_input( $input ); }

	private static function validate_pattern_post( $id, $capability ) {
		$id = WP_MCP_Permissions::validate_positive_int( $id, 'pattern_id' );
		if ( is_wp_error( $id ) ) { return $id; }
		$post = get_post( $id );
		if ( ! $post || 'wp_block' !== $post->post_type ) { return WP_MCP_Errors::invalid_pattern(); }
		if ( ! current_user_can( $capability, $post->ID ) ) { return WP_MCP_Errors::navigation_permission_denied(); }
		return $post;
	}

	private static function validate_navigation_post( $id, $capability ) {
		$id = WP_MCP_Permissions::validate_positive_int( $id, 'navigation_id' );
		if ( is_wp_error( $id ) ) { return $id; }
		$post = get_post( $id );
		if ( ! $post || 'wp_navigation' !== $post->post_type ) { return WP_MCP_Errors::invalid_navigation(); }
		if ( ! current_user_can( $capability, $post->ID ) ) { return WP_MCP_Errors::navigation_permission_denied(); }
		return $post;
	}

	private static function format_registered_pattern( $pattern ) {
		return array( 'id' => isset( $pattern['name'] ) ? (string) $pattern['name'] : '', 'source' => 'registered', 'title' => isset( $pattern['title'] ) ? (string) $pattern['title'] : '', 'description' => isset( $pattern['description'] ) ? (string) $pattern['description'] : '', 'content' => isset( $pattern['content'] ) ? (string) $pattern['content'] : '', 'sync_status' => 'registered' );
	}

	private static function format_synced_pattern( $post ) {
		$status = get_post_meta( $post->ID, 'wp_pattern_sync_status', true );
		return array( 'id' => (int) $post->ID, 'source' => 'database', 'title' => (string) $post->post_title, 'description' => (string) $post->post_excerpt, 'content' => (string) $post->post_content, 'status' => (string) $post->post_status, 'sync_status' => $status ? (string) $status : 'synced', 'modified' => (string) $post->post_modified );
	}

	private static function format_navigation( $post ) {
		return array( 'id' => (int) $post->ID, 'title' => (string) $post->post_title, 'content' => (string) $post->post_content, 'status' => (string) $post->post_status, 'author' => (int) $post->post_author, 'modified' => (string) $post->post_modified );
	}

	private static function global_styles_available() { return class_exists( 'WP_Theme_JSON_Resolver' ) && class_exists( 'WP_Theme_JSON' ) && function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json(); }

	/** Maximum accepted size, in bytes, of a block-content payload. */
	const MAX_CONTENT_BYTES = 262144;

	/**
	 * Check the native creation capability for a post type, independent of
	 * the Site Editor `edit_theme_options` gate already checked by the
	 * caller.
	 *
	 * Core resolves `create_posts` for `wp_block` to the literal
	 * `publish_posts` (via its `capability_type => 'block'` /
	 * `capabilities` map) and for `wp_navigation` to `edit_theme_options`.
	 * Checking `publish_posts` directly here would be wrong for
	 * `wp_navigation`; checking the object's `create_posts` mapping is
	 * what WP_REST_Posts_Controller::create_item_permissions_check() does,
	 * and is correct for both.
	 *
	 * @param string $post_type 'wp_block' or 'wp_navigation'.
	 * @return true|WP_Error
	 */
	private static function require_post_type_create( $post_type ) {
		$object = get_post_type_object( $post_type );
		if ( ! $object || empty( $object->cap->create_posts ) ) { return WP_MCP_Errors::site_editor_unsupported(); }
		if ( ! current_user_can( $object->cap->create_posts ) ) { return WP_MCP_Errors::navigation_permission_denied(); }
		return true;
	}

	/**
	 * Structurally validate block markup without sanitizing it.
	 *
	 * Deliberately not wp_kses_post(): kses's block-comment stripping
	 * (wp_kses_split2()) corrupts JSON block attributes containing "--" or
	 * "&", and the Site Editor's `edit_theme_options` gate already implies
	 * `unfiltered_html` for the caller, so core's own REST template/pattern
	 * controllers pass content through with no sanitize_callback. Applying
	 * kses here would be stricter than core's own Site Editor and would
	 * silently corrupt legitimate markup (e.g. a Custom HTML block).
	 * wp_insert_post()/wp_update_post() still apply kses via
	 * content_save_pre for any caller who actually lacks
	 * unfiltered_html (relevant on multisite) — this validator is not
	 * the security boundary, the `edit_theme_options` capability is.
	 *
	 * @param string $content Raw block markup.
	 * @return string|WP_Error
	 */
	private static function validate_block_content( $content ) {
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return WP_MCP_Errors::site_editor_validation_error( __( 'Block content must be a non-empty string.', 'wordpress-mcp-abilities' ) );
		}
		if ( strlen( $content ) > self::MAX_CONTENT_BYTES ) {
			return WP_MCP_Errors::site_editor_validation_error( __( 'Block content exceeds the maximum allowed size.', 'wordpress-mcp-abilities' ) );
		}
		if ( false !== strpos( $content, "\0" ) || ! wp_is_valid_utf8( $content ) ) {
			return WP_MCP_Errors::site_editor_validation_error( __( 'Block content must be valid UTF-8 text.', 'wordpress-mcp-abilities' ) );
		}
		$has_block = false;
		foreach ( parse_blocks( $content ) as $block ) {
			if ( isset( $block['blockName'] ) && null !== $block['blockName'] ) { $has_block = true; break; }
		}
		if ( ! $has_block ) {
			return WP_MCP_Errors::site_editor_validation_error( __( 'Block content must contain at least one block.', 'wordpress-mcp-abilities' ) );
		}
		return $content;
	}

	/**
	 * Decode a theme.json-shaped post_content, tolerating malformed JSON.
	 *
	 * @param string $json Raw post_content.
	 * @return array
	 */
	private static function decode_global_styles_settings( $json ) {
		if ( ! is_string( $json ) || '' === $json ) { return array(); }
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
