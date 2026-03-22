<?php
/**
 * Bridge Tool: get_iato_suggestions
 *
 * Wraps IATO generate_suggestions — the highest-signal tool in the platform.
 * AI-prioritized fixes across SEO, content, links, and performance.
 * Resolves affected page URLs to WordPress post IDs and slugs.
 *
 * This should be the first tool a new user calls.
 * Prompt: "What are the most impactful things I can fix on my site right now?"
 *
 * IATO tools used: generate_suggestions
 * WP resolution:   url_to_postid() per affected URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_suggestions',
	[
		'description' => 'Returns AI-prioritized improvement suggestions across all areas (SEO, content, broken links, performance). This is the best starting point — use it when you want to know the highest-impact fixes for a site. Results include WordPress post IDs and slugs so fixes can be applied immediately.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id'    => [ 'type' => 'string',  'description' => 'IATO crawl ID. Falls back to default crawl ID from settings.' ],
				'focus_areas' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Filter by area: seo, content, links, performance (default: all)',
				],
				'limit'       => [ 'type' => 'integer', 'description' => 'Max suggestions (default: 10, max: 50)' ],
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

		$focus_areas = $args['focus_areas'] ?? [];
		if ( ! is_array( $focus_areas ) ) {
			$focus_areas = [];
		}
		$focus_areas = array_map( 'sanitize_text_field', $focus_areas );
		$limit       = min( absint( $args['limit'] ?? 10 ), 50 );

		$response = IATO_MCP_IATO_Client::generate_suggestions( $crawl_id, $focus_areas, $limit );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$suggestions_data = $response['suggestions'] ?? $response['data'] ?? $response;
		if ( ! is_array( $suggestions_data ) ) {
			$suggestions_data = [];
		}

		$auto_fix_areas = [ 'title', 'meta_description', 'alt_text' ];

		$suggestions = [];
		foreach ( $suggestions_data as $i => $s ) {
			$url     = $s['affected_url'] ?? $s['url'] ?? '';
			$wp_id   = $url ? url_to_postid( $url ) : 0;
			$wp_slug = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;

			$fix_type = 'manual';
			$s_type   = $s['type'] ?? '';
			if ( in_array( $s_type, $auto_fix_areas, true ) ) {
				$fix_type = 'auto';
			}

			$suggestions[] = [
				'priority'       => $i + 1,
				'area'           => $s['area'] ?? $s['category'] ?? 'general',
				'title'          => $s['title'] ?? '',
				'description'    => $s['description'] ?? '',
				'impact'         => $s['impact'] ?? 'medium',
				'affected_url'   => $url ?: null,
				'affected_count' => (int) ( $s['affected_count'] ?? $s['count'] ?? 1 ),
				'fix_type'       => $fix_type,
				'wp_post_id'     => $wp_id ?: null,
				'wp_slug'        => $wp_slug ?: null,
			];
		}

		return IATO_MCP_Server::ok( [
			'crawl_id'     => $crawl_id,
			'generated_at' => gmdate( 'c' ),
			'total'        => count( $suggestions ),
			'suggestions'  => $suggestions,
		] );
	}
);
