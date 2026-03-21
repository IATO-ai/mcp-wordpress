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
		// TODO: implement — wp_get_nav_menus()
		// Return [{id, name, slug, item_count, location}]
		return new WP_Error( 'not_implemented', 'get_menus not yet implemented' );
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

		// TODO: implement — wp_get_nav_menu_items($menu_id)
		// Return [{id, title, url, slug, post_id, parent_id, menu_order}]
		return new WP_Error( 'not_implemented', 'get_menu_items not yet implemented' );
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

		// TODO: implement
		// If dry_run: return what would be added without calling wp_update_nav_menu_item
		// Else: wp_update_nav_menu_item($menu_id, 0, [...])
		return new WP_Error( 'not_implemented', 'update_menu_item not yet implemented' );
	}
);
