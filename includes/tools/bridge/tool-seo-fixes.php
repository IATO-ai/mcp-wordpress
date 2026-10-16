<?php
/**
 * Bridge Tool: get_iato_seo_fixes
 *
 * Calls IATO get_seo_issues and enriches each issue with the WordPress
 * post ID and slug so Claude can chain into update_seo_data or
 * update_alt_text immediately.
 *
 * Issue types and fix routing:
 *   title            → auto-fix via update_seo_data (fix_type: auto)
 *   meta_description → auto-fix via update_seo_data (fix_type: auto)
 *   alt_text         → auto-fix via update_alt_text  (fix_type: auto)
 *   h1_missing       → manual review                 (fix_type: manual)
 *   h1_duplicate     → manual review                 (fix_type: manual)
 *   canonical        → manual review                 (fix_type: manual)
 *
 * IATO tools used: get_seo_issues
 * WP resolution:   url_to_postid() per affected URL
 *
 * Issues these tools cannot act on (1.14.0) are listed under `skipped`
 * instead of the fix list: a URL on another domain (`other_domain`; a crawl
 * can follow links off the site) or one that is not a WordPress post or page
 * on this site (`not_a_wordpress_post`: archives, search pages, files, no URL).
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
		'description' => 'Returns SEO issues from IATO with WordPress post IDs and slugs attached. Auto-fixable issues (title, meta, alt text) include current and suggested values ready to pass to update_seo_data or update_alt_text. Manual issues include step-by-step instructions. Issues on another domain or on URLs that are not WordPress posts of this site are listed under skipped, with the reason, instead of the fix list.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id' => [ 'type' => 'string',  'description' => 'IATO crawl ID. Falls back to default crawl ID from settings.' ],
				'severity' => [ 'type' => 'string',  'description' => 'Filter: error|warning|info (optional, returns all if omitted)' ],
				'limit'    => [ 'type' => 'integer', 'description' => 'Max issues to return (default: 50)' ],
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

		$severity = isset( $args['severity'] ) ? sanitize_text_field( $args['severity'] ) : null;
		$limit    = absint( $args['limit'] ?? 50 );

		$response = IATO_MCP_IATO_Client::get_seo_issues( $crawl_id, $severity, $limit );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Canonical shape: { success, data: { issues: [...] }, ... }.
		$issues_data = $response['data']['issues'] ?? [];
		if ( ! is_array( $issues_data ) ) {
			$issues_data = [];
		}

		$issues     = [];
		$skipped    = [];
		$auto_count = 0;

		foreach ( $issues_data as $issue ) {
			$type    = $issue['type'] ?? $issue['issue_type'] ?? $issue['rule'] ?? $issue['rule_type'] ?? $issue['category'] ?? '';
			$url     = $issue['url'] ?? $issue['page_url'] ?? $issue['affected_url'] ?? $issue['link'] ?? '';
			$url     = is_string( $url ) ? $url : '';
			$host    = '' !== $url ? wp_parse_url( $url, PHP_URL_HOST ) : null;
			$on_site = '' !== $url && iato_mcp_url_on_this_site( $url );
			$wp_id   = $on_site ? url_to_postid( $url ) : 0;
			if ( ! $wp_id ) {
				$skipped[] = [
					'issue_type' => $type,
					'url'        => $url,
					'title'      => $issue['title'] ?? $issue['name'] ?? $issue['summary'] ?? $issue['message'] ?? '',
					'reason'     => ( is_string( $host ) && '' !== $host && ! $on_site ) ? 'other_domain' : 'not_a_wordpress_post',
				];
				continue;
			}
			$wp_slug = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;
			$is_auto = in_array( $type, IATO_MCP_AUTO_FIX_TYPES, true );

			if ( $is_auto ) {
				$auto_count++;
			}

			// Resolve description — IATO may return a structured object instead of a string.
			$description = $issue['description'] ?? $issue['details'] ?? $issue['explanation'] ?? '';
			if ( is_array( $description ) || is_object( $description ) ) {
				$description = (array) $description;
				$suggestion  = $description['suggestion'] ?? '';
				$headings    = $description['existing_headings'] ?? [];
				$parts       = [];
				if ( ! empty( $headings ) && is_array( $headings ) ) {
					$heading_list = array_map(
						fn( $h ) => strtoupper( $h['tag'] ?? '' ) . ' "' . ( $h['content'] ?? '' ) . '"',
						$headings
					);
					$parts[] = 'Existing headings: ' . implode( ', ', $heading_list );
				}
				if ( ! empty( $suggestion ) ) {
					$parts[] = 'Suggestion: ' . $suggestion;
				}
				$description = implode( '. ', $parts ) ?: wp_json_encode( $description );
			}

			$issues[] = [
				'issue_type'           => $type,
				'severity'             => $issue['severity'] ?? $issue['level'] ?? $issue['priority'] ?? 'warning',
				'title'                => $issue['title'] ?? $issue['name'] ?? $issue['summary'] ?? $issue['message'] ?? '',
				'description'          => $description,
				'url'                  => $url,
				'current'              => $issue['current_value'] ?? $issue['current'] ?? null,
				'suggested'            => $issue['suggested_value'] ?? $issue['suggested'] ?? $issue['recommendation'] ?? null,
				'fix_type'             => $is_auto ? 'auto' : 'manual',
				'wp_post_id'           => $wp_id ?: null,
				'wp_slug'              => $wp_slug ?: null,
				'manual_instructions'  => $is_auto ? null : ( IATO_MCP_MANUAL_INSTRUCTIONS[ $type ] ?? null ),
			];
		}

		return IATO_MCP_Server::ok( [
			'crawl_id'     => $crawl_id,
			'total'        => count( $issues ),
			'auto_fixable' => $auto_count,
			'manual'       => count( $issues ) - $auto_count,
			'issues'       => $issues,
			'skipped'      => $skipped,
		] );
	}
);

/**
 * Is a crawled URL on this site? Hosts compare without case and without a
 * leading "www."; a URL without a host (site-relative) is on the site.
 */
if ( ! function_exists( 'iato_mcp_url_on_this_site' ) ) :
function iato_mcp_url_on_this_site( string $url ): bool {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ); // mailto:, tel:, … are not pages
	}
	$site = wp_parse_url( home_url(), PHP_URL_HOST );
	$norm = static fn( string $h ): string => preg_replace( '/^www\./', '', strtolower( $h ) );
	return is_string( $site ) && $norm( $host ) === $norm( $site );
}
endif;
