<?php
/**
 * Bridge Tool: get_iato_content_gaps
 *
 * Identifies thin or under-optimised pages using the crawl's per-page metadata
 * (word count, H1 presence, image count, internal link count) from the IATO
 * /crawl/jobs/{id}/pages endpoint. Enriches each flagged page with the
 * WordPress post ID and slug.
 *
 * IATO tools used: get_pages
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
				'crawl_id'       => [ 'type' => 'string',  'description' => 'IATO crawl ID. Falls back to default crawl ID from settings.' ],
				'min_word_count' => [ 'type' => 'integer', 'description' => 'Flag pages below this word count (default: 300)' ],
				'limit'          => [ 'type' => 'integer', 'description' => 'Max pages to return (default: 20)' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$crawl_id  = sanitize_text_field( $args['crawl_id'] ?? '' );
		if ( ! $crawl_id ) {
			$crawl_id = sanitize_text_field( get_option( 'iato_mcp_crawl_id', '' ) );
		}
		if ( ! $crawl_id ) {
			return new WP_Error( 'missing_crawl_id', 'crawl_id required. Set a default in Settings > IATO MCP or pass it explicitly.' );
		}

		$min_words = absint( $args['min_word_count'] ?? 300 );
		$limit     = absint( $args['limit'] ?? 20 );

		$response = IATO_MCP_IATO_Client::get_pages( $crawl_id, max( $limit, 100 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$pages_data = $response['data']['pages'] ?? [];
		if ( ! is_array( $pages_data ) ) {
			$pages_data = [];
		}

		$pages = [];
		foreach ( $pages_data as $page ) {
			$url        = $page['url'] ?? '';
			$wp_id      = $url ? url_to_postid( $url ) : 0;
			$wp_slug    = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;
			$word_count = (int) ( $page['word_count'] ?? 0 );

			$flags = [];
			if ( $word_count < $min_words ) {
				$flags[] = "word_count ({$word_count} < {$min_words})";
			}
			if ( ! empty( $page['missing_h1'] ) ) {
				$flags[] = 'missing_h1';
			}
			if ( 0 === (int) ( $page['image_count'] ?? -1 ) ) {
				$flags[] = 'no_images';
			}
			if ( (int) ( $page['internal_link_count'] ?? 999 ) < 2 ) {
				$flags[] = 'low_internal_links';
			}

			if ( empty( $flags ) ) {
				continue;
			}

			$pages[] = [
				'url'        => $url,
				'title'      => $page['title'] ?? '',
				'word_count' => $word_count,
				'flags'      => $flags,
				'wp_post_id' => $wp_id ?: null,
				'wp_slug'    => $wp_slug ?: null,
			];

			if ( count( $pages ) >= $limit ) {
				break;
			}
		}

		return IATO_MCP_Server::ok( [
			'crawl_id' => $crawl_id,
			'total'    => count( $pages ),
			'pages'    => $pages,
		] );
	}
);
