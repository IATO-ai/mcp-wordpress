<?php
/**
 * WP Tool: set_featured_image
 *
 * Set or clear a post's featured image (`_thumbnail_id` meta). Validates that
 * the supplied attachment exists and is an image; emits a `post_meta` receipt
 * on the `_thumbnail_id` key so rollback can restore the previous value.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'set_featured_image',
	[
		'description' => 'Set or clear the featured image (_thumbnail_id) of a post. Pass attachment_id=null or 0 to clear. The attachment must be an image MIME type. Rollback-able via target_type=post_meta, field=_thumbnail_id.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'            => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'attachment_id' => [ 'type' => [ 'integer', 'null' ], 'description' => 'Attachment ID to set, or null/0 to clear.' ],
				'dry_run'       => [ 'type' => 'boolean', 'description' => 'Preview without writing (default false).' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$post_id = absint( $args['id'] ?? 0 );
		$dry_run = ! empty( $args['dry_run'] );
		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		// Distinguish "absent" (no change) from "explicitly null/0" (clear).
		if ( ! array_key_exists( 'attachment_id', $args ) ) {
			return new WP_Error( 'missing_attachment_id', 'attachment_id is required (use null or 0 to clear).' );
		}

		$raw           = $args['attachment_id'];
		$attachment_id = ( null === $raw ) ? 0 : absint( $raw );

		if ( $attachment_id > 0 ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new WP_Error( 'invalid_attachment', 'attachment_id does not reference an attachment.' );
			}
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return new WP_Error( 'invalid_attachment', 'Featured image must be an image attachment.' );
			}
		}

		$before_raw = get_post_meta( $post_id, '_thumbnail_id', true );
		$before     = ( '' === $before_raw || '0' === (string) $before_raw ) ? null : (int) $before_raw;

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'              => true,
				'id'                   => $post_id,
				'before_attachment_id' => $before,
				'after_attachment_id'  => $attachment_id > 0 ? $attachment_id : null,
			] );
		}

		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );
			$after = $attachment_id;
		} else {
			delete_post_thumbnail( $post_id );
			$after = null;
		}

		clean_post_cache( $post_id );

		$receipt = IATO_MCP_Change_Receipt::record( $post_id, 'post_meta', '_thumbnail_id', $before, $after );

		$data = [
			'id'                   => $post_id,
			'before_attachment_id' => $before,
			'after_attachment_id'  => $after,
			'attachment_url'       => $after ? wp_get_attachment_url( $after ) : null,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );
		return IATO_MCP_Server::ok( $data );
	}
);
