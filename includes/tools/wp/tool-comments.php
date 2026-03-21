<?php
/**
 * WP Tools: get_comments
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_comments',
	[
		'description' => 'List comments with optional filters by status or post.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'status'   => [ 'type' => 'string',  'description' => 'approve|hold|spam|trash (default: approve)' ],
				'post_id'  => [ 'type' => 'integer', 'description' => 'Filter by post ID' ],
				'per_page' => [ 'type' => 'integer', 'description' => 'Max results (default: 20)' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		// TODO: implement — get_comments() with sanitized args
		// Return {id, post_id, author, date, content, status}
		return new WP_Error( 'not_implemented', 'get_comments not yet implemented' );
	}
);
