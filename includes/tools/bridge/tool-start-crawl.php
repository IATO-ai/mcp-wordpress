<?php
/**
 * Bridge Tool: start_iato_crawl
 *
 * Kicks off an IATO crawl of a URL (defaults to this site) and returns the new
 * crawl_id. Requires manage_options because the call consumes IATO platform
 * quota and starts background work.
 *
 * IATO endpoint used: POST /crawl/start
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'start_iato_crawl',
	[
		'description' => 'Starts a new IATO crawl of the specified URL (defaults to this site) and returns the new crawl_id. Use the returned crawl_id with get_iato_crawl_status to poll until complete, then with the other get_iato_* tools to read results. Consumes IATO platform quota; admin only.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'url'       => [ 'type' => 'string',  'description' => 'URL to crawl. Defaults to the current WordPress site URL.' ],
				'max_pages' => [ 'type' => 'integer', 'description' => 'Maximum pages to crawl (default: 1000).' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$url       = isset( $args['url'] ) ? esc_url_raw( (string) $args['url'] ) : site_url();
		$max_pages = isset( $args['max_pages'] ) ? max( 1, absint( $args['max_pages'] ) ) : 1000;

		if ( '' === $url ) {
			return new WP_Error( 'missing_url', 'url is required and must be a valid URL.' );
		}

		$response = IATO_MCP_IATO_Client::start_crawl( $url, $max_pages );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data     = $response['data'] ?? $response;
		$crawl_id = (string) ( $data['crawl_id'] ?? $data['job_id'] ?? $data['id'] ?? '' );
		$status   = (string) ( $data['status'] ?? $data['state'] ?? 'pending' );

		return IATO_MCP_Server::ok( [
			'crawl_id'  => $crawl_id,
			'status'    => $status,
			'url'       => $url,
			'max_pages' => $max_pages,
			'message'   => $crawl_id !== ''
				? 'Crawl queued. Poll get_iato_crawl_status with the returned crawl_id to track progress.'
				: 'IATO accepted the request but did not return a crawl_id; check the IATO dashboard.',
		] );
	}
);
