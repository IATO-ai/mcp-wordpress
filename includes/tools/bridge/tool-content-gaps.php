<?php
/**
 * Bridge Tool: get_iato_content_gaps
 *
 * Identifies thin or under-optimised pages via IATO get_low_performing_pages
 * and get_content_metrics, with WordPress slugs attached for direct editing.
 *
 * IATO tools used: get_low_performing_pages, get_content_metrics
 * WP resolution:   url_to_postid() per page URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_content_gaps',
	[
		'description' => 'Returns pages with thin or low-quality content: word count below threshold, missing H1, no images, or insufficient internal links. Each result includes the WordPress post ID and slug for direct editing.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id'          => [ 'type' => 'string',  'description' => 'IATO crawl ID (required)' ],
				'min_word_count'    => [ 'type' => 'integer', 'description' => 'Flag pages below this word count (default: 300)' ],
				'limit'             => [ 'type' => 'integer', 'description' => 'Max pages to return (default: 20)' ],
			],
			'required' => [ 'crawl_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$crawl_id      = sanitize_text_field( $args['crawl_id'] ?? '' );
		$min_words     = absint( $args['min_word_count'] ?? 300 );
		$limit         = absint( $args['limit'] ?? 20 );

		if ( ! $crawl_id ) return new WP_Error( 'missing_crawl_id', 'crawl_id required' );

		// TODO: implement
		// 1. IATO_MCP_IATO_Client::get_low_performing_pages($crawl_id, $limit)
		// 2. IATO_MCP_IATO_Client::get_content_metrics($crawl_id)  — for per-page word counts
		// 3. For each page:
		//      $wp_id   = url_to_postid($page['url'])
		//      $wp_slug = $wp_id ? get_post_field('post_name', $wp_id) : null
		//      $flags   = []
		//      if $page['word_count'] < $min_words:  $flags[] = "word_count ({$page['word_count']} < {$min_words})"
		//      if $page['missing_h1']:               $flags[] = 'missing_h1'
		//      if $page['image_count'] === 0:        $flags[] = 'no_images'
		//      if $page['internal_link_count'] < 2:  $flags[] = 'low_internal_links'
		//
		//      Result item: {url, title, word_count, flags, wp_post_id, wp_slug, recommendations}
		//
		// 4. Return {crawl_id, total, pages: [...]}
		return new WP_Error( 'not_implemented', 'get_iato_content_gaps not yet implemented' );
	}
);
