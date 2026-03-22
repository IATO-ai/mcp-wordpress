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
				'crawl_id' => [ 'type' => 'string',  'description' => 'IATO crawl ID. Falls back to default crawl ID from settings.' ],
				'limit'    => [ 'type' => 'integer', 'description' => 'Max broken links to return (default: 50)' ],
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

		$limit = absint( $args['limit'] ?? 50 );

		$response = IATO_MCP_IATO_Client::get_crawl_analytics( $crawl_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$links_data = $response['broken_links'] ?? $response['data']['broken_links'] ?? [];
		if ( ! is_array( $links_data ) ) {
			$links_data = [];
		}

		$links_data = array_slice( $links_data, 0, $limit );

		$broken_links = [];
		foreach ( $links_data as $link ) {
			$source_url = $link['source_url'] ?? '';
			$wp_id      = $source_url ? url_to_postid( $source_url ) : 0;
			$wp_slug    = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;
			$status     = (int) ( $link['status_code'] ?? 0 );

			$suggestion = 'Remove or replace this broken link.';
			if ( $status >= 300 && $status < 400 ) {
				$suggestion = 'Update to the final destination URL.';
			}

			$broken_links[] = [
				'broken_url'   => $link['url'] ?? '',
				'status_code'  => $status,
				'anchor_text'  => $link['anchor_text'] ?? '',
				'source_url'   => $source_url,
				'source_title' => $link['source_title'] ?? '',
				'wp_post_id'   => $wp_id ?: null,
				'wp_slug'      => $wp_slug ?: null,
				'suggestion'   => $suggestion,
			];
		}

		return IATO_MCP_Server::ok( [
			'crawl_id'     => $crawl_id,
			'total'        => count( $broken_links ),
			'broken_links' => $broken_links,
		] );
	}
);
