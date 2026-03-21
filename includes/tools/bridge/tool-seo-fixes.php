<?php
/**
 * Bridge Tool: get_iato_seo_fixes
 *
 * Calls IATO run_seo_audit + get_seo_issues and enriches each issue with
 * the WordPress post ID and slug so Claude can chain into update_seo_data
 * or update_alt_text immediately.
 *
 * Issue types and fix routing:
 *   title            → auto-fix via update_seo_data (fix_type: auto)
 *   meta_description → auto-fix via update_seo_data (fix_type: auto)
 *   alt_text         → auto-fix via update_alt_text  (fix_type: auto)
 *   h1_missing       → manual review                 (fix_type: manual)
 *   h1_duplicate     → manual review                 (fix_type: manual)
 *   canonical        → manual review                 (fix_type: manual)
 *
 * IATO tools used: run_seo_audit, get_seo_issues
 * WP resolution:   url_to_postid() per affected URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

/** Issue types Claude can fix automatically via WP plugin tools. */
const IATO_MCP_AUTO_FIX_TYPES = [ 'title', 'meta_description', 'alt_text' ];

/** Manual fix instructions keyed by issue type. */
const IATO_MCP_MANUAL_INSTRUCTIONS = [
	'h1_missing'   => 'Edit the post content to add an H1 heading. In WordPress block editor, add a Heading block (H1) near the top of the page content.',
	'h1_duplicate' => 'Remove one of the H1 headings in the post content, or change it to H2. The post title is usually rendered as the H1 — check if your theme outputs it and remove any H1 blocks from the body.',
	'canonical'    => 'Add or correct the canonical URL via your SEO plugin settings for this post, or check if a conflicting canonical is set in a page template or plugin.',
];

IATO_MCP_Server::register_tool(
	'get_iato_seo_fixes',
	[
		'description' => 'Returns SEO issues from IATO with WordPress post IDs and slugs attached. Auto-fixable issues (title, meta, alt text) include current and suggested values ready to pass to update_seo_data or update_alt_text. Manual issues include step-by-step instructions.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id' => [ 'type' => 'string',  'description' => 'IATO crawl ID (required)' ],
				'severity' => [ 'type' => 'string',  'description' => 'Filter: error|warning|info (optional, returns all if omitted)' ],
				'limit'    => [ 'type' => 'integer', 'description' => 'Max issues to return (default: 50)' ],
			],
			'required' => [ 'crawl_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$crawl_id = sanitize_text_field( $args['crawl_id'] ?? '' );
		if ( ! $crawl_id ) return new WP_Error( 'missing_crawl_id', 'crawl_id required' );

		$severity = isset( $args['severity'] ) ? sanitize_text_field( $args['severity'] ) : null;
		$limit    = absint( $args['limit'] ?? 50 );

		// TODO: implement
		// 1. IATO_MCP_IATO_Client::run_seo_audit($crawl_id)  — triggers/refreshes audit
		// 2. IATO_MCP_IATO_Client::get_seo_issues($crawl_id, $severity, $limit)
		// 3. For each issue:
		//      $wp_id   = url_to_postid($issue['url'])
		//      $wp_slug = $wp_id ? get_post_field('post_name', $wp_id) : null
		//      $is_auto = in_array($issue['type'], IATO_MCP_AUTO_FIX_TYPES, true)
		//
		//      Build result item:
		//      {
		//        issue_type:   $issue['type'],
		//        severity:     $issue['severity'],
		//        url:          $issue['url'],
		//        current:      $issue['current_value'],
		//        suggested:    $issue['suggested_value'],
		//        fix_type:     $is_auto ? 'auto' : 'manual',
		//        wp_post_id:   $wp_id ?: null,
		//        wp_slug:      $wp_slug ?: null,
		//        manual_instructions: $is_auto ? null : (IATO_MCP_MANUAL_INSTRUCTIONS[$issue['type']] ?? null),
		//      }
		//
		// 4. Return {
		//      crawl_id:     $crawl_id,
		//      total:        count($issues),
		//      auto_fixable: count of fix_type=auto,
		//      manual:       count of fix_type=manual,
		//      issues:       [...],
		//    }
		return new WP_Error( 'not_implemented', 'get_iato_seo_fixes not yet implemented' );
	}
);
