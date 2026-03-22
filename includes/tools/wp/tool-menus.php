<?php
/**
 * WP Tools: get_menus, get_menu_items, update_menu_item
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
