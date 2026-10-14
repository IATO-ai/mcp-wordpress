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
		'description' => 'List comments with optional filters by status or post. Only comments on posts you may read are returned; statuses other than approve (hold, spam, trash, all) require moderate_comments.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'status'   => [ 'type' => 'string',  'description' => 'approve|hold|spam|trash|all (default: approve). Anything but approve requires moderate_comments.' ],
				'post_id'  => [ 'type' => 'integer', 'description' => 'Filter by post ID' ],
				'per_page' => [ 'type' => 'integer', 'description' => 'Max results (default: 20)' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		// Permissions (1.13.0): unmoderated comments (held, spam, trash) need
		// moderate_comments; comments are only returned for posts the caller
		// may read (password-protected posts: edit_post, as for their content).
		$status  = sanitize_key( (string) ( $args['status'] ?? 'approve' ) );
		$allowed = [ 'approve', 'hold', 'spam', 'trash', 'all' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'invalid_status', 'status must be one of: ' . implode( ', ', $allowed ) . '.' );
		}
		if ( 'approve' !== $status ) {
			$moderate = IATO_MCP_Auth::require_cap( 'moderate_comments' );
			if ( is_wp_error( $moderate ) ) {
				return $moderate;
			}
		}

		$per_page = max( 1, min( absint( $args['per_page'] ?? 20 ), 100 ) );
		$post_id  = absint( $args['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$readable = IATO_MCP_Auth::require_read_post( $post_id );
			if ( is_wp_error( $readable ) ) {
				return $readable;
			}
		}

		// Without a post filter, comments on posts the caller cannot read are
		// dropped; up to five pages are fetched to fill the requested count.
		$result  = [];
		$checked = [];
		for ( $page = 0; $page < 5 && count( $result ) < $per_page; $page++ ) {
			$query_args = [ 'status' => $status, 'number' => $per_page, 'offset' => $page * $per_page ];
			if ( $post_id > 0 ) {
				$query_args['post_id'] = $post_id;
			}
			$comments = get_comments( $query_args );
			foreach ( $comments as $comment ) {
				$parent = (int) $comment->comment_post_ID;
				if ( $post_id <= 0 ) {
					if ( ! array_key_exists( $parent, $checked ) ) {
						$checked[ $parent ] = $parent > 0 && ! is_wp_error( IATO_MCP_Auth::require_read_post( $parent ) );
					}
					if ( ! $checked[ $parent ] ) {
						continue;
					}
				}
				$result[] = [
					'id'      => (int) $comment->comment_ID,
					'post_id' => $parent,
					'author'  => $comment->comment_author,
					'date'    => $comment->comment_date_gmt,
					'content' => $comment->comment_content,
					'status'  => wp_get_comment_status( $comment ),
				];
				if ( count( $result ) >= $per_page ) {
					break;
				}
			}
			if ( count( $comments ) < $per_page || $post_id > 0 ) {
				break;
			}
		}

		return IATO_MCP_Server::ok( [ 'comments' => $result ] );
	}
);
