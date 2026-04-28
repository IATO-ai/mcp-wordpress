<?php
/**
 * Bridge Tool: list_iato_crawls
 *
 * Lists recent IATO crawl jobs in the user's workspace. Useful for finding the
 * most recent completed crawl_id without bouncing to the IATO dashboard.
 *
 * IATO endpoint used: GET /crawl/jobs (returns data.jobs per the canonical envelope)
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'list_iato_crawls',
	[
		'description' => 'Lists recent IATO crawl jobs with their status and IDs. Use this to find the most recent completed crawl_id before reading SEO fixes, sitemap, suggestions, etc.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'limit' => [ 'type' => 'integer', 'description' => 'Max results to return (default: 20).' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$limit = isset( $args['limit'] ) ? max( 1, min( 100, absint( $args['limit'] ) ) ) : 20;

		$response = IATO_MCP_IATO_Client::list_crawls();
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$jobs_data = $response['data']['jobs'] ?? [];
		if ( ! is_array( $jobs_data ) ) {
			$jobs_data = [];
		}

		$jobs_data = array_slice( $jobs_data, 0, $limit );

		$jobs = [];
		foreach ( $jobs_data as $job ) {
			$status = (string) ( $job['status'] ?? $job['state'] ?? 'unknown' );
			$jobs[] = [
				'crawl_id'      => $job['id'] ?? $job['crawl_id'] ?? $job['job_id'] ?? null,
				'url'           => $job['url'] ?? null,
				'status'        => $status,
				'is_complete'   => in_array( $status, [ 'completed', 'complete', 'done', 'finished' ], true ),
				'pages_crawled' => $job['pages_crawled'] ?? $job['page_count'] ?? null,
				'started_at'    => $job['started_at'] ?? $job['created_at'] ?? null,
				'completed_at'  => $job['completed_at'] ?? $job['finished_at'] ?? null,
			];
		}

		return IATO_MCP_Server::ok( [
			'total' => count( $jobs ),
			'jobs'  => $jobs,
		] );
	}
);
