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
				'crawl_id'    => [ 'type' => 'string',  'description' => 'IATO crawl ID (required)' ],
				'focus_areas' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Filter by area: seo, content, links, performance (default: all)',
				],
				'limit'       => [ 'type' => 'integer', 'description' => 'Max suggestions (default: 10, max: 50)' ],
			],
			'required' => [ 'crawl_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$crawl_id    = sanitize_text_field( $args['crawl_id'] ?? '' );
		$focus_areas = $args['focus_areas'] ?? [];
		$limit       = min( absint( $args['limit'] ?? 10 ), 50 );

		if ( ! $crawl_id ) return new WP_Error( 'missing_crawl_id', 'crawl_id required' );

		// TODO: implement
		// 1. IATO_MCP_IATO_Client::generate_suggestions($crawl_id, $focus_areas, $limit)
		// 2. For each suggestion that has an affected_url:
		//      $wp_id   = url_to_postid($suggestion['affected_url'])
		//      $wp_slug = $wp_id ? get_post_field('post_name', $wp_id) : null
		// 3. Determine if auto-fixable:
		//      auto: title, meta_description, alt_text (can chain to WP plugin tools)
		//      manual: everything else
		// 4. Return {
		//      crawl_id:     $crawl_id,
		//      generated_at: current ISO timestamp,
		//      total:        count($suggestions),
		//      suggestions:  [{
		//        priority:        int (1 = highest),
		//        area:            'seo'|'content'|'links'|'performance',
		//        title:           string,
		//        description:     string,
		//        impact:          'high'|'medium'|'low',
		//        affected_url:    string|null,
		//        affected_count:  int,
		//        fix_type:        'auto'|'manual',
		//        wp_post_id:      int|null,
		//        wp_slug:         string|null,
		//      }]
		//    }
		return new WP_Error( 'not_implemented', 'get_iato_suggestions not yet implemented' );
	}
);
