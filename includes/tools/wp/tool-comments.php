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
		$query_args = [
			'status' => sanitize_text_field( $args['status'] ?? 'approve' ),
			'number' => min( absint( $args['per_page'] ?? 20 ), 100 ),
		];

		if ( ! empty( $args['post_id'] ) ) {
			$query_args['post_id'] = absint( $args['post_id'] );
		}

		$comments = get_comments( $query_args );

		$result = [];
		foreach ( $comments as $comment ) {
			$result[] = [
				'id'      => (int) $comment->comment_ID,
				'post_id' => (int) $comment->comment_post_ID,
				'author'  => $comment->comment_author,
				'date'    => $comment->comment_date_gmt,
				'content' => $comment->comment_content,
				'status'  => wp_get_comment_status( $comment ),
			];
		}

		return IATO_MCP_Server::ok( [ 'comments' => $result ] );
	}
);
