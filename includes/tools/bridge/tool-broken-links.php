<?php
/**
 * Bridge Tool: get_iato_broken_links
 *
 * Wraps IATO /crawl/jobs/{id}/broken-links. The platform returns broken pages
 * (4xx/5xx pages on the site) and broken resources (images, scripts, etc. on
 * pages) separately, plus a summary object. Each broken page is enriched with
 * the WordPress post ID and slug when resolvable.
 *
 * IATO tools used: get_broken_links
 * WP resolution:   url_to_postid() per broken page URL (and source URL for resources)
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_broken_links',
	[
		'description' => 'Returns broken pages and broken resources found during the crawl. Each broken page includes the URL, HTTP status, and WordPress post ID/slug when resolvable; each broken resource includes the source page where it is referenced.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id' => [ 'type' => 'string',  'description' => 'IATO crawl ID. Falls back to default crawl ID from settings.' ],
				'limit'    => [ 'type' => 'integer', 'description' => 'Max entries per list (default: 50)' ],
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

		$response = IATO_MCP_IATO_Client::get_broken_links( $crawl_id, $limit );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response['data'] ?? [];

		$broken_pages_data     = is_array( $data['broken_pages'] ?? null ) ? $data['broken_pages'] : [];
		$broken_resources_data = is_array( $data['broken_resources'] ?? null ) ? $data['broken_resources'] : [];
		$summary               = is_array( $data['summary'] ?? null ) ? $data['summary'] : [];

		$broken_pages = [];
		foreach ( array_slice( $broken_pages_data, 0, $limit ) as $page ) {
			$url     = $page['url'] ?? '';
			$wp_id   = $url ? url_to_postid( $url ) : 0;
			$wp_slug = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;
			$status  = (int) ( $page['status_code'] ?? $page['status'] ?? 0 );

			$suggestion = 'Remove or replace this broken page.';
			if ( $status >= 300 && $status < 400 ) {
				$suggestion = 'Update references to point at the final destination URL.';
			}

			$broken_pages[] = [
				'url'         => $url,
				'status_code' => $status,
				'title'       => $page['title'] ?? '',
				'wp_post_id'  => $wp_id ?: null,
				'wp_slug'     => $wp_slug ?: null,
				'suggestion'  => $suggestion,
			];
		}

		$broken_resources = [];
		foreach ( array_slice( $broken_resources_data, 0, $limit ) as $resource ) {
			$source_url = $resource['source_url'] ?? $resource['page_url'] ?? '';
			$wp_id      = $source_url ? url_to_postid( $source_url ) : 0;
			$wp_slug    = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;

			$broken_resources[] = [
				'resource_url' => $resource['url'] ?? '',
				'resource_type' => $resource['type'] ?? $resource['resource_type'] ?? '',
				'status_code'  => (int) ( $resource['status_code'] ?? $resource['status'] ?? 0 ),
				'source_url'   => $source_url,
				'wp_post_id'   => $wp_id ?: null,
				'wp_slug'      => $wp_slug ?: null,
			];
		}

		return IATO_MCP_Server::ok( [
			'crawl_id'         => $crawl_id,
			'summary'          => $summary,
			'broken_pages'     => $broken_pages,
			'broken_resources' => $broken_resources,
		] );
	}
);
