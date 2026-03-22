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
		if ( ! $sitemap_id ) {
			return new WP_Error( 'missing_sitemap_id', 'sitemap_id required' );
		}

		$response = IATO_MCP_IATO_Client::get_orphan_pages( $sitemap_id, [ 'section', 'planned' ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$orphans_data = $response['orphans'] ?? $response['data'] ?? $response;
		if ( ! is_array( $orphans_data ) ) {
			$orphans_data = [];
		}

		$orphans = [];
		foreach ( $orphans_data as $orphan ) {
			$url     = $orphan['url'] ?? '';
			$wp_id   = $url ? url_to_postid( $url ) : 0;
			$wp_slug = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;

			$orphans[] = [
				'iato_node_id' => $orphan['id'] ?? null,
				'title'        => $orphan['title'] ?? '',
				'url'          => $url,
				'wp_post_id'   => $wp_id ?: null,
				'wp_slug'      => $wp_slug ?: null,
			];
		}

		return IATO_MCP_Server::ok( [
			'sitemap_id' => $sitemap_id,
			'total'      => count( $orphans ),
			'orphans'    => $orphans,
		] );
	}
);
