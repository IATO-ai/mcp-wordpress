<?php
/**
 * Bridge Tool: get_iato_suggestions
 *
 * Wraps IATO generate_suggestions — the highest-signal tool in the platform.
 * AI-prioritized fixes across SEO, content, links, and performance.
 * Resolves affected page URLs to WordPress post IDs and slugs.
 *
 * When suggestions are empty and the crawl is complete, auto-triggers
 * POST /suggestions/generate (once per crawl, guarded by a 1-hour transient)
 * and re-fetches.
 *
 * IATO tools used: generate_suggestions, trigger_suggestions_generate, get_crawl_job, get_seo_issues
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

		$suggestions_data = $response['data']['suggestions'] ?? [];
		if ( ! is_array( $suggestions_data ) ) {
			$suggestions_data = [];
		}

		// Empty — check crawl status, maybe auto-generate.
		if ( empty( $suggestions_data ) ) {
			$job = IATO_MCP_IATO_Client::get_crawl_job( $crawl_id );

			if ( is_wp_error( $job ) ) {
				return IATO_MCP_Server::ok( [
					'crawl_id'     => $crawl_id,
					'crawl_status' => 'not_found',
					'message'      => 'Crawl ID "' . $crawl_id . '" was not found. Verify the Default Crawl ID in Settings > IATO MCP matches a valid job ID from your IATO dashboard.',
					'total'        => 0,
					'suggestions'  => [],
				] );
			}

			$job_data     = $job['data'] ?? [];
			$crawl_status = $job_data['status'] ?? $job_data['state'] ?? 'unknown';
			$is_complete  = in_array( $crawl_status, [ 'completed', 'complete', 'done', 'finished' ], true );

			if ( ! $is_complete ) {
				return IATO_MCP_Server::ok( [
					'crawl_id'     => $crawl_id,
					'crawl_status' => $crawl_status,
					'message'      => 'Crawl is still in progress (status: ' . $crawl_status . '). Suggestions will be available after the crawl completes.',
					'total'        => 0,
					'suggestions'  => [],
				] );
			}

			// Auto-trigger generation — once per crawl, guarded by a 1-hour transient.
			$gen_transient     = 'iato_suggestions_generated_' . $crawl_id;
			$already_generated = get_transient( $gen_transient );

			if ( ! $already_generated ) {
				$gen_response = IATO_MCP_IATO_Client::trigger_suggestions_generate( $crawl_id );
				if ( ! is_wp_error( $gen_response ) ) {
					// Only set the transient on success; failures leave it unset so next call retries.
					set_transient( $gen_transient, true, HOUR_IN_SECONDS );
				}

				$response2 = IATO_MCP_IATO_Client::generate_suggestions( $crawl_id, $focus_areas, $limit );
				if ( ! is_wp_error( $response2 ) ) {
					$suggestions_data = $response2['data']['suggestions'] ?? [];
					if ( ! is_array( $suggestions_data ) ) {
						$suggestions_data = [];
					}
				}
			}

			if ( empty( $suggestions_data ) ) {
				return IATO_MCP_Server::ok( [
					'crawl_id'     => $crawl_id,
					'crawl_status' => $crawl_status,
					'message'      => 'Crawl completed but no suggestions were returned. The platform may still be processing — retry in a few minutes.',
					'total'        => 0,
					'suggestions'  => [],
				] );
			}
		}

		$auto_fix_areas = [ 'title', 'meta_description', 'alt_text' ];

		$suggestions = [];
		$priority    = 0;
		foreach ( $suggestions_data as $s ) {
			$url     = $s['affected_url'] ?? $s['url'] ?? $s['page_url'] ?? $s['link'] ?? '';
			$wp_id   = $url ? url_to_postid( $url ) : 0;
			$wp_slug = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;

			$fix_type = 'manual';
			$s_type   = $s['type'] ?? $s['issue_type'] ?? $s['rule'] ?? '';
			if ( in_array( $s_type, $auto_fix_areas, true ) ) {
				$fix_type = 'auto';
			}

			$suggestions[] = [
				'priority'       => ++$priority,
				'area'           => $s['area'] ?? $s['category'] ?? $s['section'] ?? 'general',
				'title'          => $s['title'] ?? $s['name'] ?? $s['summary'] ?? $s['message'] ?? '',
				'description'    => $s['description'] ?? $s['details'] ?? $s['explanation'] ?? $s['body'] ?? '',
				'impact'         => $s['impact'] ?? $s['severity'] ?? $s['priority'] ?? 'medium',
				'affected_url'   => $url ?: null,
				'affected_count' => (int) ( $s['affected_count'] ?? $s['count'] ?? $s['pages_affected'] ?? 1 ),
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
