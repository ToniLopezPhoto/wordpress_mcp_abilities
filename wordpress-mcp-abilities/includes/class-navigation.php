<?php
/**
 * WordPress MCP Abilities — classic navigation callbacks.
 *
 * Uses WordPress' native navigation-menu APIs and theme-mod storage. The
 * callbacks never inspect or edit theme files and expose only an explicit,
 * bounded set of menu fields.
 *
 * @package WP_MCP_Agent_Abilities
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WP_MCP_Navigation
 */
class WP_MCP_Navigation {

	/** Maximum menu items accepted by one reorder request. */
	const MAX_MENU_ITEMS = 100;

	/**
	 * List classic menus and their items.
	 *
	 * @param array $input Unused input.
	 * @return array|WP_Error
	 */
	public static function list_menus( $input = array() ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$menus = array();
		foreach ( wp_get_nav_menus( array( 'number' => 100 ) ) as $menu ) {
			$menus[] = self::format_menu( $menu );
		}

		return array( 'menus' => $menus, 'total' => count( $menus ) );
	}

	/**
	 * Get one classic menu and its items.
	 *
	 * @param array $input Menu ID.
	 * @return array|WP_Error
	 */
	public static function get_menu( $input ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$menu = self::validate_menu( isset( $input['menu_id'] ) ? $input['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		return self::format_menu( $menu );
	}

	/**
	 * List locations registered by the active theme.
	 *
	 * @param array $input Unused input.
	 * @return array|WP_Error
	 */
	public static function list_locations( $input = array() ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$assigned = get_nav_menu_locations();
		$locations = array();
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$menu_id = isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0;
			$menu = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;
			$locations[] = array(
				'slug'     => sanitize_key( $slug ),
				'label'    => wp_strip_all_tags( $label ),
				'menu_id'  => $menu_id,
				'menu_name' => $menu ? $menu->name : '',
			);
		}
		return array( 'locations' => $locations, 'total' => count( $locations ) );
	}

	/**
	 * Create a classic menu.
	 *
	 * @param array $input Menu name and optional description.
	 * @return array|WP_Error
	 */
	public static function create_menu( $input ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			return WP_MCP_Errors::navigation_validation_error( __( 'A menu name is required.', 'wordpress-mcp-abilities' ) );
		}

		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			WP_MCP_Audit::log( 'wp-mcp/create-nav-menu', 0, false, 'wp_mcp_navigation_create_failed' );
			return WP_MCP_Errors::navigation_create_failed( is_wp_error( $menu_id ) ? $menu_id->get_error_message() : null );
		}
		if ( isset( $input['description'] ) ) {
			$updated = wp_update_nav_menu_object( $menu_id, wp_slash( array( 'menu-name' => $name, 'description' => sanitize_textarea_field( $input['description'] ) ) ) );
			if ( is_wp_error( $updated ) ) {
				WP_MCP_Audit::log( 'wp-mcp/create-nav-menu', $menu_id, false, 'wp_mcp_navigation_update_failed' );
				return WP_MCP_Errors::navigation_update_failed( $updated->get_error_message() );
			}
		}
		WP_MCP_Audit::log( 'wp-mcp/create-nav-menu', $menu_id, true );
		return self::format_menu( wp_get_nav_menu_object( $menu_id ) );
	}

	/**
	 * Update a classic menu's explicit metadata.
	 *
	 * @param array $input Menu ID, name and/or description.
	 * @return array|WP_Error
	 */
	public static function update_menu( $input ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$menu = self::validate_menu( isset( $input['menu_id'] ) ? $input['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$data = array( 'menu-name' => $menu->name );
		$has_changes = false;
		if ( isset( $input['name'] ) ) {
			$data['menu-name'] = sanitize_text_field( $input['name'] );
			$has_changes = true;
			if ( '' === $data['menu-name'] ) {
				return WP_MCP_Errors::navigation_validation_error( __( 'The menu name cannot be empty.', 'wordpress-mcp-abilities' ) );
			}
		}
		if ( isset( $input['description'] ) ) {
			$data['description'] = sanitize_textarea_field( $input['description'] );
			$has_changes = true;
		}
		if ( ! $has_changes ) {
			return WP_MCP_Errors::navigation_validation_error( __( 'At least one menu field is required.', 'wordpress-mcp-abilities' ) );
		}
		$result = wp_update_nav_menu_object( $menu->term_id, wp_slash( $data ) );
		if ( is_wp_error( $result ) ) {
			WP_MCP_Audit::log( 'wp-mcp/update-nav-menu', $menu->term_id, false, 'wp_mcp_navigation_update_failed' );
			return WP_MCP_Errors::navigation_update_failed( $result->get_error_message() );
		}
		WP_MCP_Audit::log( 'wp-mcp/update-nav-menu', $menu->term_id, true );
		return self::format_menu( wp_get_nav_menu_object( $menu->term_id ) );
	}

	/**
	 * Delete one classic menu.
	 *
	 * @param array $input Menu ID.
	 * @return array|WP_Error
	 */
	public static function delete_menu( $input ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$menu = self::validate_menu( isset( $input['menu_id'] ) ? $input['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$result = wp_delete_nav_menu( $menu->term_id );
		if ( is_wp_error( $result ) || ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/delete-nav-menu', $menu->term_id, false, 'wp_mcp_navigation_delete_failed' );
			return WP_MCP_Errors::navigation_delete_failed( is_wp_error( $result ) ? $result->get_error_message() : null );
		}
		WP_MCP_Audit::log( 'wp-mcp/delete-nav-menu', $menu->term_id, true );
		return array( 'id' => (int) $menu->term_id, 'deleted' => true );
	}

	/**
	 * Assign or unassign a menu from one registered theme location.
	 *
	 * @param array $input Menu ID, location slug and assign flag.
	 * @return array|WP_Error
	 */
	public static function assign_menu_location( $input ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$menu = self::validate_menu( isset( $input['menu_id'] ) ? $input['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$location = isset( $input['location'] ) ? sanitize_key( $input['location'] ) : '';
		if ( '' === $location || ! array_key_exists( $location, get_registered_nav_menus() ) ) {
			return WP_MCP_Errors::invalid_menu_location();
		}
		$assign = ! isset( $input['assign'] ) || (bool) $input['assign'];
		$locations = get_nav_menu_locations();
		if ( $assign ) {
			$locations[ $location ] = (int) $menu->term_id;
		} elseif ( isset( $locations[ $location ] ) && (int) $locations[ $location ] === (int) $menu->term_id ) {
			unset( $locations[ $location ] );
		}
		set_theme_mod( 'nav_menu_locations', $locations );
		if ( get_nav_menu_locations() != $locations ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison, Universal.Operators.StrictComparisons.LooseNotEqual
			WP_MCP_Audit::log( 'wp-mcp/assign-menu-location', $menu->term_id, false, 'wp_mcp_navigation_update_failed' );
			return WP_MCP_Errors::navigation_update_failed( __( 'The menu location assignment was not persisted.', 'wordpress-mcp-abilities' ) );
		}
		WP_MCP_Audit::log( 'wp-mcp/assign-menu-location', $menu->term_id, true );
		return array( 'menu_id' => (int) $menu->term_id, 'location' => $location, 'assigned' => $assign );
	}

	/**
	 * Add an explicit item to a classic menu.
	 *
	 * @param array $input Item fields.
	 * @return array|WP_Error
	 */
	public static function add_menu_item( $input ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$menu = self::validate_menu( isset( $input['menu_id'] ) ? $input['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$data = self::build_item_data( $input, $menu );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$item_id = wp_update_nav_menu_item( $menu->term_id, 0, wp_slash( $data ) );
		if ( is_wp_error( $item_id ) || ! $item_id ) {
			WP_MCP_Audit::log( 'wp-mcp/add-menu-item', $menu->term_id, false, 'wp_mcp_navigation_update_failed' );
			return WP_MCP_Errors::navigation_update_failed( is_wp_error( $item_id ) ? $item_id->get_error_message() : null );
		}
		WP_MCP_Audit::log( 'wp-mcp/add-menu-item', $item_id, true );
		return self::find_formatted_item( $menu, $item_id );
	}

	/**
	 * Update title, URL, hierarchy or position of one menu item.
	 *
	 * @param array $input Item fields.
	 * @return array|WP_Error
	 */
	public static function update_menu_item( $input ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$menu = self::validate_menu( isset( $input['menu_id'] ) ? $input['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$item = self::validate_menu_item( isset( $input['item_id'] ) ? $input['item_id'] : 0, $menu );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$data = array(
			'menu-item-object-id'   => (int) $item->object_id,
			'menu-item-object'      => (string) $item->object,
			'menu-item-parent-id'   => (int) $item->menu_item_parent,
			'menu-item-position'    => (int) $item->menu_order,
			'menu-item-type'        => (string) $item->type,
			'menu-item-title'       => (string) $item->title,
			'menu-item-url'         => (string) $item->url,
			'menu-item-target'      => (string) $item->target,
			'menu-item-classes'     => implode( ' ', (array) $item->classes ),
			'menu-item-xfn'         => (string) $item->xfn,
			'menu-item-description' => (string) $item->description,
			'menu-item-status'      => 'publish',
		);
		$has_changes = false;
		if ( isset( $input['title'] ) ) {
			$data['menu-item-title'] = sanitize_text_field( $input['title'] );
			$has_changes = true;
		}
		if ( isset( $input['url'] ) ) {
			$url = esc_url_raw( $input['url'] );
			if ( '' === $url || preg_match( '#^\s*(javascript|data|vbscript):#i', $input['url'] ) ) {
				return WP_MCP_Errors::navigation_validation_error( __( 'The menu item URL is invalid.', 'wordpress-mcp-abilities' ) );
			}
			$data['menu-item-url'] = $url;
			$has_changes = true;
		}
		if ( isset( $input['parent_id'] ) ) {
			$parent = self::validate_parent_item( $input['parent_id'], $menu, $item->ID );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
			$data['menu-item-parent-id'] = $parent;
			$has_changes = true;
		}
		if ( isset( $input['position'] ) ) {
			$data['menu-item-position'] = self::position( $input['position'] );
			$has_changes = true;
		}
		if ( ! $has_changes ) {
			return WP_MCP_Errors::navigation_validation_error( __( 'At least one menu item field is required.', 'wordpress-mcp-abilities' ) );
		}
		$result = wp_update_nav_menu_item( $menu->term_id, $item->ID, wp_slash( $data ) );
		if ( is_wp_error( $result ) || ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/update-menu-item', $item->ID, false, 'wp_mcp_navigation_update_failed' );
			return WP_MCP_Errors::navigation_update_failed( is_wp_error( $result ) ? $result->get_error_message() : null );
		}
		WP_MCP_Audit::log( 'wp-mcp/update-menu-item', $item->ID, true );
		return self::find_formatted_item( $menu, $item->ID );
	}

	/**
	 * Remove one item from a menu.
	 *
	 * @param array $input Menu and item IDs.
	 * @return array|WP_Error
	 */
	public static function remove_menu_item( $input ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$menu = self::validate_menu( isset( $input['menu_id'] ) ? $input['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$item = self::validate_menu_item( isset( $input['item_id'] ) ? $input['item_id'] : 0, $menu );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$result = wp_delete_post( $item->ID, true );
		if ( ! $result ) {
			WP_MCP_Audit::log( 'wp-mcp/remove-menu-item', $item->ID, false, 'wp_mcp_navigation_delete_failed' );
			return WP_MCP_Errors::navigation_delete_failed();
		}
		WP_MCP_Audit::log( 'wp-mcp/remove-menu-item', $item->ID, true );
		return array( 'id' => (int) $item->ID, 'deleted' => true );
	}

	/**
	 * Reorder all items in a menu by an explicit ordered ID list.
	 *
	 * @param array $input Menu ID and item IDs.
	 * @return array|WP_Error
	 */
	public static function reorder_menu_items( $input ) {
		$permission = self::require_manage_navigation();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$menu = self::validate_menu( isset( $input['menu_id'] ) ? $input['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$ids = isset( $input['item_ids'] ) && is_array( $input['item_ids'] ) ? array_values( array_map( 'absint', $input['item_ids'] ) ) : array();
		if ( empty( $ids ) || count( $ids ) > self::MAX_MENU_ITEMS || count( $ids ) !== count( array_unique( $ids ) ) ) {
			return WP_MCP_Errors::navigation_validation_error( __( 'A unique ordered list of menu item IDs is required.', 'wordpress-mcp-abilities' ) );
		}
		$current = wp_get_nav_menu_items( $menu->term_id );
		$current_ids = wp_list_pluck( (array) $current, 'ID' );
		if ( count( $ids ) !== count( $current_ids ) || array_diff( $ids, $current_ids ) || array_diff( $current_ids, $ids ) ) {
			return WP_MCP_Errors::navigation_validation_error( __( 'The reorder list must contain every item in this menu exactly once.', 'wordpress-mcp-abilities' ) );
		}
		foreach ( $ids as $position => $item_id ) {
			$result = wp_update_post( array( 'ID' => $item_id, 'menu_order' => $position + 1 ), true );
			if ( is_wp_error( $result ) ) {
				WP_MCP_Audit::log( 'wp-mcp/reorder-menu-items', $menu->term_id, false, 'wp_mcp_navigation_update_failed' );
				return WP_MCP_Errors::navigation_update_failed( $result->get_error_message() );
			}
		}
		WP_MCP_Audit::log( 'wp-mcp/reorder-menu-items', $menu->term_id, true );
		return array( 'menu_id' => (int) $menu->term_id, 'item_ids' => $ids );
	}

	/** Check the capability shared by classic navigation operations. */
	private static function require_manage_navigation() {
		return current_user_can( 'edit_theme_options' ) ? true : WP_MCP_Errors::navigation_permission_denied();
	}

	/** Validate a menu ID and return its term object. */
	private static function validate_menu( $menu_id ) {
		$menu_id = WP_MCP_Permissions::validate_positive_int( $menu_id, 'menu_id' );
		if ( is_wp_error( $menu_id ) ) {
			return $menu_id;
		}
		$menu = wp_get_nav_menu_object( $menu_id );
		return $menu ? $menu : WP_MCP_Errors::invalid_menu();
	}

	/** Validate that an item belongs to the supplied menu. */
	private static function validate_menu_item( $item_id, $menu ) {
		$item_id = WP_MCP_Permissions::validate_positive_int( $item_id, 'item_id' );
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
			if ( (int) $item->ID === $item_id ) {
				return $item;
			}
		}
		return WP_MCP_Errors::invalid_menu_item();
	}

	/** Build native nav-menu input for a new item. */
	private static function build_item_data( $input, $menu ) {
		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
		$type = isset( $input['type'] ) ? sanitize_key( $input['type'] ) : '';
		if ( '' === $title || ! in_array( $type, array( 'custom', 'post', 'page', 'taxonomy' ), true ) ) {
			return WP_MCP_Errors::navigation_validation_error( __( 'Title and a supported item type are required.', 'wordpress-mcp-abilities' ) );
		}
		$data = array(
			'menu-item-title'     => $title,
			'menu-item-status'    => 'publish',
			'menu-item-position'  => self::position( isset( $input['position'] ) ? $input['position'] : 0 ),
		);
		if ( 'custom' === $type ) {
			$url = isset( $input['url'] ) ? esc_url_raw( $input['url'] ) : '';
			if ( '' === $url || preg_match( '#^\s*(javascript|data|vbscript):#i', isset( $input['url'] ) ? $input['url'] : '' ) ) {
				return WP_MCP_Errors::navigation_validation_error( __( 'A safe URL is required for a custom menu item.', 'wordpress-mcp-abilities' ) );
			}
			$data['menu-item-type'] = 'custom';
			$data['menu-item-url'] = $url;
		} else {
			$object_id = WP_MCP_Permissions::validate_positive_int( isset( $input['object_id'] ) ? $input['object_id'] : 0, 'object_id' );
			if ( is_wp_error( $object_id ) ) {
				return $object_id;
			}
			if ( 'taxonomy' === $type ) {
				$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
				$term = $taxonomy ? get_term( $object_id, $taxonomy ) : false;
				if ( ! $term || is_wp_error( $term ) ) {
					return WP_MCP_Errors::navigation_validation_error( __( 'The taxonomy term is invalid.', 'wordpress-mcp-abilities' ) );
				}
				$data['menu-item-type'] = 'taxonomy';
				$data['menu-item-object'] = $taxonomy;
				$data['menu-item-object-id'] = $object_id;
			} else {
				$post = get_post( $object_id );
				if ( ! $post || $post->post_type !== $type || ! current_user_can( 'read_post', $post->ID ) ) {
					return WP_MCP_Errors::navigation_validation_error( __( 'The linked post or page is invalid or unreadable.', 'wordpress-mcp-abilities' ) );
				}
				$data['menu-item-type'] = 'post_type';
				$data['menu-item-object'] = $type;
				$data['menu-item-object-id'] = $object_id;
			}
		}
		if ( isset( $input['parent_id'] ) ) {
			$parent = self::validate_parent_item( $input['parent_id'], $menu, 0 );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
			$data['menu-item-parent-id'] = $parent;
		}
		if ( isset( $input['target'] ) ) {
			$data['menu-item-target'] = sanitize_text_field( $input['target'] );
		}
		return $data;
	}

	/** Validate an optional parent item in the same menu. */
	private static function validate_parent_item( $parent_id, $menu, $item_id ) {
		$parent_id = absint( $parent_id );
		if ( 0 === $parent_id ) {
			return 0;
		}
		if ( $parent_id === (int) $item_id ) {
			return WP_MCP_Errors::navigation_validation_error( __( 'A menu item cannot be its own parent.', 'wordpress-mcp-abilities' ) );
		}
		$parent = self::validate_menu_item( $parent_id, $menu );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$seen = array( (int) $item_id );
		$current_id = $parent_id;
		while ( $current_id > 0 ) {
			if ( in_array( $current_id, $seen, true ) ) {
				return WP_MCP_Errors::navigation_validation_error( __( 'Menu item hierarchy cannot contain a cycle.', 'wordpress-mcp-abilities' ) );
			}
			$seen[] = $current_id;
			$current = self::validate_menu_item( $current_id, $menu );
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$current_id = (int) $current->menu_item_parent;
		}
		return $parent_id;
	}

	/** Clamp a menu position to a safe positive value. */
	private static function position( $position ) {
		$position = absint( $position );
		return $position > 0 ? min( self::MAX_MENU_ITEMS, $position ) : 0;
	}

	/** Format a menu without exposing arbitrary term metadata. */
	private static function format_menu( $menu ) {
		$locations = array();
		foreach ( get_nav_menu_locations() as $location => $menu_id ) {
			if ( (int) $menu_id === (int) $menu->term_id ) {
				$locations[] = sanitize_key( $location );
			}
		}
		$items = array();
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
			$items[] = self::format_item( $item );
		}
		return array(
			'id'          => (int) $menu->term_id,
			'name'        => $menu->name,
			'slug'        => $menu->slug,
			'description' => $menu->description,
			'count'       => count( $items ),
			'locations'   => $locations,
			'auto_add'    => self::menu_auto_add( $menu->term_id ),
			'items'       => $items,
		);
	}

	/** Return whether WordPress has this menu in its auto-add option. */
	private static function menu_auto_add( $menu_id ) {
		$options = get_option( 'nav_menu_options', array() );
		return ! empty( $options['auto_add'] ) && in_array( (int) $menu_id, array_map( 'absint', (array) $options['auto_add'] ), true );
	}

	/** Find and format one item after a native menu mutation. */
	private static function find_formatted_item( $menu, $item_id ) {
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
			if ( (int) $item->ID === (int) $item_id ) {
				return self::format_item( $item );
			}
		}
		return WP_MCP_Errors::invalid_menu_item();
	}

	/** Format only stable, public menu-item fields. */
	private static function format_item( $item ) {
		return array(
			'id'        => (int) $item->ID,
			'title'     => $item->title,
			'url'       => esc_url_raw( $item->url ),
			'type'      => sanitize_key( $item->type ),
			'object'    => sanitize_key( (string) $item->object ),
			'object_id' => (int) $item->object_id,
			'parent_id' => (int) $item->menu_item_parent,
			'position'  => (int) $item->menu_order,
			'target'    => sanitize_text_field( $item->target ),
			'classes'   => array_values( array_filter( array_map( 'sanitize_html_class', (array) $item->classes ) ) ),
			'xfn'       => sanitize_text_field( $item->xfn ),
			'description' => wp_strip_all_tags( $item->description ),
		);
	}
}
