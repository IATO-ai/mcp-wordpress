<?php
/**
 * Bridge Tool: get_iato_orphan_pages
 *
 * Wraps IATO find_orphan_pages and resolves node URLs to WordPress post IDs
 * and slugs so Claude can immediately call update_menu_item or create
 * internal links without a manual lookup step.
 *
 * IATO tools used: find_orphan_pages
 * WP resolution:   url_to_postid() per orphan URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_orphan_pages',
	[
		'description' => 'Returns pages that are not linked from any navigation menu, with WordPress post IDs and slugs. Use this to find content users cannot discover via navigation.',
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
		// 1. IATO_MCP_IATO_Client::get_orphan_pages($sitemap_id, ['section', 'planned'])
		// 2. For each orphan: url_to_postid($orphan['url']), get_post_field('post_name', $wp_id)
		// 3. Return [{iato_node_id, url, title, wp_post_id, wp_slug}]
		return new WP_Error( 'not_implemented', 'get_iato_orphan_pages not yet implemented' );
	}
);
