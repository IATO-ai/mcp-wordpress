<?php
/**
 * Bridge Tool: get_iato_nav_audit
 *
 * Combines get_menus + get_menu_items + find_orphan_pages from IATO into
 * a single navigation audit output with WP slugs, ready for Claude to
 * chain into update_menu_item calls.
 *
 * IATO tools used: get_menus, get_menu_items, find_orphan_pages
 * WP resolution:   url_to_postid() per orphan URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_nav_audit',
	[
		'description' => 'Audits site navigation: lists menus with their items, identifies pages not in any menu (orphans), and returns WordPress slugs for all pages so Claude can add them to menus directly.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'sitemap_id' => [ 'type' => 'integer', 'description' => 'IATO sitemap ID (required)' ],
			],
			'required' => [ 'sitemap_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$sitemap_id = absint( $args['sitemap_id'] ?? 0 );
		if ( ! $sitemap_id ) return new WP_Error( 'missing_sitemap_id', 'sitemap_id required' );

		// TODO: implement
		// 1. IATO_MCP_IATO_Client::get_menus($sitemap_id)
		// 2. For each menu: IATO_MCP_IATO_Client::get_menu_items($sitemap_id, $menu['id'])
		// 3. IATO_MCP_IATO_Client::get_orphan_pages($sitemap_id, ['section', 'planned'])
		// 4. Resolve orphan URLs to WP post IDs and slugs via url_to_postid()
		// 5. Return {menus: [...], orphans: [{url, title, wp_post_id, wp_slug}]}
		return new WP_Error( 'not_implemented', 'get_iato_nav_audit not yet implemented' );
	}
);
