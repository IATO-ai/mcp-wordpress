<?php
/**
 * Bridge Tool: get_iato_sitemap
 *
 * Calls IATO list_sitemaps + get_sitemap_nodes, then resolves each node's
 * URL to a WordPress post ID and slug so Claude can chain into WP edits.
 *
 * IATO tools used: list_sitemaps, get_sitemap_nodes
 * WP resolution:   url_to_postid() per node URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_sitemap',
	[
		'description' => 'Returns the full site hierarchy from IATO with WordPress post IDs and slugs attached to each node. Use this to understand site structure before making navigation or link edits.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'sitemap_id' => [ 'type' => 'integer', 'description' => 'IATO sitemap ID. If omitted, uses the most recent sitemap.' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		// TODO: implement
		// 1. IATO_MCP_IATO_Client::list_sitemaps() — pick $args['sitemap_id'] or use first result
		// 2. IATO_MCP_IATO_Client::get_sitemap_nodes($sitemap_id)
		// 3. For each node with a URL:
		//      $wp_id  = url_to_postid($node['url'])
		//      $slug   = $wp_id ? get_post_field('post_name', $wp_id) : null
		// 4. Attach wp_post_id and wp_slug to each node, return full hierarchy
		return new WP_Error( 'not_implemented', 'get_iato_sitemap not yet implemented' );
	}
);
