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
		$sitemap_id = absint( $args['sitemap_id'] ?? 0 );

		// If no sitemap_id provided, fetch the most recent one.
		if ( ! $sitemap_id ) {
			$sitemaps = IATO_MCP_IATO_Client::list_sitemaps();
			if ( is_wp_error( $sitemaps ) ) {
				return $sitemaps;
			}
			$list = $sitemaps['sitemaps'] ?? $sitemaps['data'] ?? $sitemaps;
			if ( empty( $list ) || ! is_array( $list ) ) {
				return new WP_Error( 'no_sitemaps', 'No sitemaps found in your IATO account.' );
			}
			$sitemap_id = absint( $list[0]['id'] ?? 0 );
			if ( ! $sitemap_id ) {
				return new WP_Error( 'no_sitemaps', 'Could not determine sitemap ID from IATO response.' );
			}
		}

		$nodes_response = IATO_MCP_IATO_Client::get_sitemap_nodes( $sitemap_id );
		if ( is_wp_error( $nodes_response ) ) {
			return $nodes_response;
		}

		$nodes = $nodes_response['nodes'] ?? $nodes_response['data'] ?? $nodes_response;
		if ( ! is_array( $nodes ) ) {
			$nodes = [];
		}

		$result = [];
		foreach ( $nodes as $node ) {
			$url     = $node['url'] ?? '';
			$wp_id   = $url ? url_to_postid( $url ) : 0;
			$wp_slug = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;

			$result[] = [
				'iato_node_id' => $node['id'] ?? null,
				'title'        => $node['title'] ?? '',
				'url'          => $url,
				'parent_id'    => $node['parent_id'] ?? null,
				'depth'        => $node['depth'] ?? 0,
				'type'         => $node['type'] ?? null,
				'wp_post_id'   => $wp_id ?: null,
				'wp_slug'      => $wp_slug ?: null,
			];
		}

		return IATO_MCP_Server::ok( [
			'sitemap_id' => $sitemap_id,
			'total'      => count( $result ),
			'nodes'      => $result,
		] );
	}
);
