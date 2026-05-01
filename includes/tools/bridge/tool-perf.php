<?php
/**
 * Bridge Tool: get_iato_perf_report
 *
 * Wraps IATO /crawl/jobs/{id}/performance. The platform returns the slowest
 * pages, the largest pages, and a summary object. Each page is enriched with
 * the WordPress post ID and slug so Claude can jump straight to the content.
 *
 * IATO tools used: get_low_performing_pages (→ /performance)
 * WP resolution:   url_to_postid() per page URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_perf_report',
	[
		'description' => 'Returns pages with poor load performance: the slowest pages and the largest pages, plus a site-level summary. Each page includes WordPress post ID and slug so Claude can identify pages needing image optimization, caching, or plugin review.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id' => [ 'type' => 'string',  'description' => 'IATO crawl ID. Falls back to default crawl ID from settings.' ],
				'limit'    => [ 'type' => 'integer', 'description' => 'Max entries per list (default: 20)' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$crawl_id = sanitize_text_field( $args['crawl_id'] ?? '' );
		if ( ! $crawl_id ) {
			$crawl_id = sanitize_text_field( get_option( 'iato_mcp_crawl_id', '' ) );
		}
		if ( ! $crawl_id ) {
			return new WP_Error( 'missing_crawl_id', 'crawl_id required. Set a default in Settings > IATO MCP or pass it explicitly.' );
		}

		$limit = absint( $args['limit'] ?? 20 );

		$response = IATO_MCP_IATO_Client::get_low_performing_pages( $crawl_id, $limit );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response['data'] ?? [];

		$slowest_data = is_array( $data['slowest_pages'] ?? null ) ? $data['slowest_pages'] : [];
		$largest_data = is_array( $data['largest_pages'] ?? null ) ? $data['largest_pages'] : [];
		$summary      = is_array( $data['summary'] ?? null ) ? $data['summary'] : [];

		$enrich = function ( array $page ): array {
			$url     = $page['url'] ?? '';
			$wp_id   = $url ? url_to_postid( $url ) : 0;
			$wp_slug = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;

			return [
				'url'          => $url,
				'title'        => $page['title'] ?? '',
				'load_time_ms' => (int) ( $page['load_time_ms'] ?? $page['response_time_ms'] ?? 0 ),
				'size_bytes'   => (int) ( $page['size_bytes'] ?? $page['page_size'] ?? 0 ),
				'wp_post_id'   => $wp_id ?: null,
				'wp_slug'      => $wp_slug ?: null,
			];
		};

		$slowest_pages = array_map( $enrich, array_slice( $slowest_data, 0, $limit ) );
		$largest_pages = array_map( $enrich, array_slice( $largest_data, 0, $limit ) );

		return IATO_MCP_Server::ok( [
			'crawl_id'      => $crawl_id,
			'summary'       => $summary,
			'slowest_pages' => $slowest_pages,
			'largest_pages' => $largest_pages,
		] );
	}
);
