<?php
/**
 * Bridge Tool: get_iato_perf_report
 *
 * Returns slow-loading and oversized pages from IATO get_crawl_analytics
 * and get_low_performing_pages, with WordPress post IDs and slugs attached.
 *
 * IATO tools used: get_crawl_analytics, get_low_performing_pages
 * WP resolution:   url_to_postid() per page URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_perf_report',
	[
		'description' => 'Returns pages with poor load performance: slow load times, large page sizes, or other Core Web Vitals outliers. Each result includes WordPress post ID and slug so Claude can identify pages needing image optimization, caching, or plugin review.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id'         => [ 'type' => 'string',  'description' => 'IATO crawl ID. Falls back to default crawl ID from settings.' ],
				'limit'            => [ 'type' => 'integer', 'description' => 'Max pages to return (default: 20)' ],
				'min_load_time_ms' => [ 'type' => 'integer', 'description' => 'Only include pages slower than this (ms, default: 2000)' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$crawl_id    = sanitize_text_field( $args['crawl_id'] ?? '' );
		if ( ! $crawl_id ) {
			$crawl_id = sanitize_text_field( get_option( 'iato_mcp_crawl_id', '' ) );
		}
		if ( ! $crawl_id ) {
			return new WP_Error( 'missing_crawl_id', 'crawl_id required. Set a default in Settings > IATO MCP or pass it explicitly.' );
		}

		$limit       = absint( $args['limit'] ?? 20 );
		$min_load_ms = absint( $args['min_load_time_ms'] ?? 2000 );

		// Site-wide performance summary.
		$analytics = IATO_MCP_IATO_Client::get_crawl_analytics( $crawl_id );
		if ( is_wp_error( $analytics ) ) {
			return $analytics;
		}

		$site_avg_load_ms = (int) ( $analytics['avg_load_time_ms']
			?? $analytics['performance']['avg_load_time_ms']
			?? $analytics['data']['avg_load_time_ms']
			?? 0 );

		// Per-page data.
		$pages_response = IATO_MCP_IATO_Client::get_low_performing_pages( $crawl_id, $limit );
		if ( is_wp_error( $pages_response ) ) {
			return $pages_response;
		}

		$pages_data = $pages_response['pages'] ?? $pages_response['data'] ?? $pages_response;
		if ( ! is_array( $pages_data ) ) {
			$pages_data = [];
		}

		$pages = [];
		foreach ( $pages_data as $page ) {
			$load_ms = (int) ( $page['load_time_ms'] ?? 0 );
			if ( $load_ms < $min_load_ms ) {
				continue;
			}

			$url        = $page['url'] ?? '';
			$wp_id      = $url ? url_to_postid( $url ) : 0;
			$wp_slug    = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;
			$size_bytes = (int) ( $page['size_bytes'] ?? 0 );

			$causes = [];
			if ( $size_bytes > 500000 ) {
				$causes[] = 'large_page_size';
			}
			if ( (int) ( $page['image_count'] ?? 0 ) > 10 ) {
				$causes[] = 'many_images';
			}
			if ( (int) ( $page['script_count'] ?? 0 ) > 15 ) {
				$causes[] = 'many_scripts';
			}

			$pages[] = [
				'url'          => $url,
				'title'        => $page['title'] ?? '',
				'load_time_ms' => $load_ms,
				'size_bytes'   => $size_bytes,
				'causes'       => $causes,
				'wp_post_id'   => $wp_id ?: null,
				'wp_slug'      => $wp_slug ?: null,
			];
		}

		return IATO_MCP_Server::ok( [
			'crawl_id'          => $crawl_id,
			'site_avg_load_ms'  => $site_avg_load_ms,
			'total_slow_pages'  => count( $pages ),
			'pages'             => $pages,
		] );
	}
);
