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
				'crawl_id'           => [ 'type' => 'string',  'description' => 'IATO crawl ID (required)' ],
				'limit'              => [ 'type' => 'integer', 'description' => 'Max pages to return (default: 20)' ],
				'min_load_time_ms'   => [ 'type' => 'integer', 'description' => 'Only include pages slower than this (ms, default: 2000)' ],
			],
			'required' => [ 'crawl_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$crawl_id       = sanitize_text_field( $args['crawl_id'] ?? '' );
		$limit          = absint( $args['limit'] ?? 20 );
		$min_load_ms    = absint( $args['min_load_time_ms'] ?? 2000 );

		if ( ! $crawl_id ) return new WP_Error( 'missing_crawl_id', 'crawl_id required' );

		// TODO: implement
		// 1. IATO_MCP_IATO_Client::get_crawl_analytics($crawl_id)  — site-wide perf summary
		// 2. IATO_MCP_IATO_Client::get_low_performing_pages($crawl_id, $limit)
		// 3. Filter to pages where load_time_ms >= $min_load_ms
		// 4. For each page:
		//      $wp_id   = url_to_postid($page['url'])
		//      $wp_slug = $wp_id ? get_post_field('post_name', $wp_id) : null
		//      $causes  = []  // infer from data
		//      if $page['size_bytes'] > 500000:      $causes[] = 'large_page_size'
		//      if $page['image_count'] > 10:         $causes[] = 'many_images'
		//      if $page['script_count'] > 15:        $causes[] = 'many_scripts'
		//
		//      Result item: {
		//        url:            $page['url'],
		//        title:          $page['title'],
		//        load_time_ms:   $page['load_time_ms'],
		//        size_bytes:     $page['size_bytes'],
		//        causes:         $causes,
		//        wp_post_id:     $wp_id ?: null,
		//        wp_slug:        $wp_slug ?: null,
		//      }
		//
		// 5. Return {
		//      crawl_id:            $crawl_id,
		//      site_avg_load_ms:    from analytics,
		//      total_slow_pages:    count,
		//      pages:               [...],
		//    }
		return new WP_Error( 'not_implemented', 'get_iato_perf_report not yet implemented' );
	}
);
