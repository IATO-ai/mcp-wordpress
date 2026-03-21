<?php
/**
 * Bridge Tool: get_iato_broken_links
 *
 * Extracts broken link data from IATO get_crawl_analytics and maps each
 * broken link to its source WordPress post ID and slug so Claude can
 * call update_post to fix or remove the link.
 *
 * IATO tools used: get_crawl_analytics
 * WP resolution:   url_to_postid() per source page URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_broken_links',
	[
		'description' => 'Returns broken links found during the crawl. Each result includes the broken URL, HTTP status code, anchor text, and the WordPress post ID and slug of the page containing the link.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id' => [ 'type' => 'string',  'description' => 'IATO crawl ID (required)' ],
				'limit'    => [ 'type' => 'integer', 'description' => 'Max broken links to return (default: 50)' ],
			],
			'required' => [ 'crawl_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$crawl_id = sanitize_text_field( $args['crawl_id'] ?? '' );
		$limit    = absint( $args['limit'] ?? 50 );

		if ( ! $crawl_id ) return new WP_Error( 'missing_crawl_id', 'crawl_id required' );

		// TODO: implement
		// 1. IATO_MCP_IATO_Client::get_crawl_analytics($crawl_id)
		//    Extract broken_links array from analytics response
		// 2. For each broken link:
		//      $wp_id   = url_to_postid($link['source_url'])
		//      $wp_slug = $wp_id ? get_post_field('post_name', $wp_id) : null
		//      Result item: {
		//        broken_url:      $link['url'],
		//        status_code:     $link['status_code'],
		//        anchor_text:     $link['anchor_text'],
		//        source_url:      $link['source_url'],
		//        source_title:    $link['source_title'],
		//        wp_post_id:      $wp_id ?: null,
		//        wp_slug:         $wp_slug ?: null,
		//        suggestion:      '4xx: remove or replace link. 3xx: update to final destination URL.',
		//      }
		// 3. Return {crawl_id, total, broken_links: [...]}
		return new WP_Error( 'not_implemented', 'get_iato_broken_links not yet implemented' );
	}
);
