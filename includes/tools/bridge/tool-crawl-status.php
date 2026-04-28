<?php
/**
 * Bridge Tool: get_iato_crawl_status
 *
 * Returns the status of a specific IATO crawl job — useful after start_iato_crawl
 * to poll until the crawl is complete before invoking the read tools.
 *
 * IATO endpoint used: GET /crawl/jobs/{job_id}
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_crawl_status',
	[
		'description' => 'Returns status, progress, and metadata for a specific IATO crawl job. Use after start_iato_crawl to poll until status is "completed", then call the other get_iato_* tools with this crawl_id.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id' => [ 'type' => 'string', 'description' => 'IATO crawl job ID. Falls back to default crawl ID from settings.' ],
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
			return new WP_Error( 'missing_crawl_id', 'crawl_id required. Pass it explicitly or set a default in Settings > IATO MCP.' );
		}

		$response = IATO_MCP_IATO_Client::get_crawl_job( $crawl_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data   = $response['data'] ?? $response;
		$status = (string) ( $data['status'] ?? $data['state'] ?? 'unknown' );

		return IATO_MCP_Server::ok( [
			'crawl_id'    => $crawl_id,
			'status'      => $status,
			'is_complete' => in_array( $status, [ 'completed', 'complete', 'done', 'finished' ], true ),
			'url'         => $data['url'] ?? null,
			'pages_crawled' => $data['pages_crawled'] ?? $data['page_count'] ?? null,
			'started_at'  => $data['started_at'] ?? $data['created_at'] ?? null,
			'completed_at' => $data['completed_at'] ?? $data['finished_at'] ?? null,
			'raw'         => $data,
		] );
	}
);
