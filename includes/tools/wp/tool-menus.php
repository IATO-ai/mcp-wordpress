<?php
/**
 * WP Tools: get_menus, get_menu_items, update_menu_item, create_menu_item,
 *           delete_menu_item, update_menu_item_details
 *
 * get_menus / get_menu_items — read only
 * update_menu_item           — requires manage_options + supports dry_run
 *
 * Requires WordPress 6.3+ for the /wp/v2/menus REST endpoint.
 * Falls back to wp_get_nav_menus() for older versions.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_menus ─────────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_menus',
	[
		'description' => 'List all registered WordPress navigation menus with their item counts.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => new stdClass(),
			'required'   => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$menus     = wp_get_nav_menus();
		$locations = get_nav_menu_locations();
		$loc_map   = array_flip( $locations );

		$result = [];
		foreach ( $menus as $menu ) {
			$result[] = [
				'id'         => $menu->term_id,
				'name'       => $menu->name,
				'slug'       => $menu->slug,
				'item_count' => (int) $menu->count,
				'location'   => $loc_map[ $menu->term_id ] ?? null,
			];
		}

		return IATO_MCP_Server::ok( [ 'menus' => $result ] );
	}
);

// ── get_menu_items ────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_menu_items',
	[
		'description' => 'Get all items in a navigation menu by menu ID.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_id' => [ 'type' => 'integer', 'description' => 'Menu ID (required)' ],
			],
			'required' => [ 'menu_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$menu_id = absint( $args['menu_id'] ?? 0 );
		if ( ! $menu_id ) return new WP_Error( 'missing_menu_id', 'menu_id required' );

		$items = wp_get_nav_menu_items( $menu_id );
		if ( false === $items ) {
			return new WP_Error( 'not_found', 'Menu not found.' );
		}

		$result = [];
		foreach ( $items as $item ) {
			$result[] = [
				'id'         => (int) $item->ID,
				'title'      => $item->title,
				'url'        => $item->url,
				'slug'       => $item->post_name,
				'post_id'    => (int) $item->object_id,
				'parent_id'  => (int) $item->menu_item_parent,
				'menu_order' => (int) $item->menu_order,
			];
		}

		return IATO_MCP_Server::ok( [ 'items' => $result ] );
	}
);

// ── update_menu_item ──────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_menu_item',
	[
		'description' => 'Add a page to a navigation menu, or update an existing menu item. Supports dry_run to preview changes. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_id'   => [ 'type' => 'integer', 'description' => 'Menu ID (required)' ],
				'post_id'   => [ 'type' => 'integer', 'description' => 'Post/page ID to add' ],
				'parent_id' => [ 'type' => 'integer', 'description' => 'Parent menu item ID (0 for root)' ],
				'dry_run'   => [ 'type' => 'boolean', 'description' => 'If true, preview without saving (default: false)' ],
			],
			'required' => [ 'menu_id', 'post_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$dry_run = (bool) ( $args['dry_run'] ?? false );
		$menu_id = absint( $args['menu_id'] ?? 0 );
		$post_id = absint( $args['post_id'] ?? 0 );

		if ( ! $menu_id ) {
			return new WP_Error( 'missing_menu_id', 'menu_id required.' );
		}
		if ( ! $post_id ) {
			return new WP_Error( 'missing_post_id', 'post_id required.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return new WP_Error( 'not_found', 'Menu not found.' );
		}

		$parent_id = absint( $args['parent_id'] ?? 0 );

		$item_data = [
			'menu-item-object-id' => $post_id,
			'menu-item-object'    => $post->post_type,
			'menu-item-type'      => 'post_type',
			'menu-item-title'     => get_the_title( $post ),
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_id,
		];

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'   => true,
				'menu_id'   => $menu_id,
				'post_id'   => $post_id,
				'title'     => $item_data['menu-item-title'],
				'parent_id' => $parent_id,
			] );
		}

		$result = wp_update_nav_menu_item( $menu_id, 0, $item_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return IATO_MCP_Server::ok( [
			'menu_item_id' => $result,
			'menu_id'      => $menu_id,
			'post_id'      => $post_id,
			'title'        => $item_data['menu-item-title'],
			'parent_id'    => $parent_id,
		] );
	}
);

// ── create_menu_item ─────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'create_menu_item',
	[
		'description' => 'Create a new menu item. Supports arbitrary URLs (custom links) and WordPress pages/posts. Preserves parent-child hierarchy via parent_id. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_id'   => [ 'type' => 'integer', 'description' => 'Menu ID to add the item to (required).' ],
				'title'     => [ 'type' => 'string',  'description' => 'Display label for the menu item (required).' ],
				'url'       => [ 'type' => 'string',  'description' => 'URL for custom link items. Omit when using post_id.' ],
				'post_id'   => [ 'type' => 'integer', 'description' => 'WP post/page ID. When provided, type is set to post_type automatically.' ],
				'position'  => [ 'type' => 'integer', 'description' => 'Menu order / position (default: 0).' ],
				'parent_id' => [ 'type' => 'integer', 'description' => 'Parent menu item ID for nesting (0 for top-level).' ],
				'dry_run'   => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'menu_id', 'title' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$menu_id   = absint( $args['menu_id'] ?? 0 );
		$title     = sanitize_text_field( $args['title'] ?? '' );
		$url       = esc_url_raw( $args['url'] ?? '' );
		$post_id   = absint( $args['post_id'] ?? 0 );
		$position  = absint( $args['position'] ?? 0 );
		$parent_id = absint( $args['parent_id'] ?? 0 );
		$dry_run   = ! empty( $args['dry_run'] );

		if ( ! $menu_id ) {
			return new WP_Error( 'missing_menu_id', 'menu_id is required.' );
		}
		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', 'title is required.' );
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return new WP_Error( 'not_found', 'Menu not found.' );
		}

		// Build item data — post_type link or custom URL.
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'not_found', 'Post not found.' );
			}
			$item_data = [
				'menu-item-object-id' => $post_id,
				'menu-item-object'    => $post->post_type,
				'menu-item-type'      => 'post_type',
				'menu-item-title'     => $title,
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $position,
				'menu-item-parent-id' => $parent_id,
			];
		} else {
			if ( empty( $url ) ) {
				return new WP_Error( 'missing_url', 'Either url or post_id is required.' );
			}
			$item_data = [
				'menu-item-url'       => $url,
				'menu-item-type'      => 'custom',
				'menu-item-title'     => $title,
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $position,
				'menu-item-parent-id' => $parent_id,
			];
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'   => true,
				'menu_id'   => $menu_id,
				'title'     => $title,
				'url'       => $url ?: get_permalink( $post_id ),
				'post_id'   => $post_id ?: null,
				'position'  => $position,
				'parent_id' => $parent_id,
				'type'      => $post_id ? 'post_type' : 'custom',
			] );
		}

		$result = wp_update_nav_menu_item( $menu_id, 0, $item_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return IATO_MCP_Server::ok( [
			'menu_item_id' => $result,
			'menu_id'      => $menu_id,
			'title'        => $title,
			'url'          => $url ?: get_permalink( $post_id ),
			'post_id'      => $post_id ?: null,
			'position'     => $position,
			'parent_id'    => $parent_id,
			'type'         => $post_id ? 'post_type' : 'custom',
		] );
	}
);

// ── delete_menu_item ─────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'delete_menu_item',
	[
		'description' => 'Delete a menu item by its menu item ID. Supports dry_run to preview. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_item_id' => [ 'type' => 'integer', 'description' => 'The menu item ID to delete (required).' ],
				'dry_run'      => [ 'type' => 'boolean', 'description' => 'Preview without deleting (default: false).' ],
			],
			'required' => [ 'menu_item_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$menu_item_id = absint( $args['menu_item_id'] ?? 0 );
		$dry_run      = ! empty( $args['dry_run'] );

		if ( ! $menu_item_id ) {
			return new WP_Error( 'missing_menu_item_id', 'menu_item_id is required.' );
		}

		$item = get_post( $menu_item_id );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return new WP_Error( 'not_found', 'Menu item not found.' );
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'       => true,
				'menu_item_id'  => $menu_item_id,
				'title'         => $item->post_title,
				'action'        => 'would_delete',
			] );
		}

		$deleted = wp_delete_post( $menu_item_id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', 'Failed to delete menu item.' );
		}

		return IATO_MCP_Server::ok( [
			'menu_item_id' => $menu_item_id,
			'title'        => $item->post_title,
			'action'       => 'deleted',
		] );
	}
);

// ── update_menu_item_details ─────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_menu_item_details',
	[
		'description' => 'Update an existing menu item\'s title, URL, position, or parent. Only provided fields are changed. Supports dry_run. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_item_id' => [ 'type' => 'integer', 'description' => 'The menu item ID to update (required).' ],
				'title'        => [ 'type' => 'string',  'description' => 'New display label.' ],
				'url'          => [ 'type' => 'string',  'description' => 'New URL (only applies to custom link items).' ],
				'position'     => [ 'type' => 'integer', 'description' => 'New menu order / position.' ],
				'parent_id'    => [ 'type' => 'integer', 'description' => 'New parent menu item ID (0 for top-level).' ],
				'dry_run'      => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'menu_item_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$menu_item_id = absint( $args['menu_item_id'] ?? 0 );
		$dry_run      = ! empty( $args['dry_run'] );

		if ( ! $menu_item_id ) {
			return new WP_Error( 'missing_menu_item_id', 'menu_item_id is required.' );
		}

		$item = get_post( $menu_item_id );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return new WP_Error( 'not_found', 'Menu item not found.' );
		}

		// Determine which menu this item belongs to.
		$menu_terms = wp_get_object_terms( $menu_item_id, 'nav_menu' );
		if ( is_wp_error( $menu_terms ) || empty( $menu_terms ) ) {
			return new WP_Error( 'no_menu', 'Could not determine the menu for this item.' );
		}
		$menu_id = $menu_terms[0]->term_id;

		// Read current values to merge with updates.
		$current_type      = get_post_meta( $menu_item_id, '_menu_item_type', true );
		$current_object    = get_post_meta( $menu_item_id, '_menu_item_object', true );
		$current_object_id = get_post_meta( $menu_item_id, '_menu_item_object_id', true );
		$current_url       = get_post_meta( $menu_item_id, '_menu_item_url', true );
		$current_parent    = get_post_meta( $menu_item_id, '_menu_item_menu_item_parent', true );

		$item_data = [
			'menu-item-type'      => $current_type,
			'menu-item-object'    => $current_object,
			'menu-item-object-id' => $current_object_id,
			'menu-item-title'     => $item->post_title,
			'menu-item-url'       => $current_url,
			'menu-item-status'    => 'publish',
			'menu-item-position'  => $item->menu_order,
			'menu-item-parent-id' => $current_parent,
		];

		$changes = [];

		if ( isset( $args['title'] ) ) {
			$new_title = sanitize_text_field( $args['title'] );
			$changes['title'] = [ 'from' => $item->post_title, 'to' => $new_title ];
			$item_data['menu-item-title'] = $new_title;
		}
		if ( isset( $args['url'] ) ) {
			$new_url = esc_url_raw( $args['url'] );
			$changes['url'] = [ 'from' => $current_url, 'to' => $new_url ];
			$item_data['menu-item-url'] = $new_url;
		}
		if ( isset( $args['position'] ) ) {
			$new_pos = absint( $args['position'] );
			$changes['position'] = [ 'from' => $item->menu_order, 'to' => $new_pos ];
			$item_data['menu-item-position'] = $new_pos;
		}
		if ( isset( $args['parent_id'] ) ) {
			$new_parent = absint( $args['parent_id'] );
			$changes['parent_id'] = [ 'from' => (int) $current_parent, 'to' => $new_parent ];
			$item_data['menu-item-parent-id'] = $new_parent;
		}

		if ( empty( $changes ) ) {
			return new WP_Error( 'no_changes', 'No fields provided to update.' );
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'       => true,
				'menu_item_id'  => $menu_item_id,
				'menu_id'       => $menu_id,
				'changes'       => $changes,
			] );
		}

		$result = wp_update_nav_menu_item( $menu_id, $menu_item_id, $item_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return IATO_MCP_Server::ok( [
			'menu_item_id' => $result,
			'menu_id'      => $menu_id,
			'changes'      => $changes,
		] );
	}
);
