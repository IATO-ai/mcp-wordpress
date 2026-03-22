<?php
/**
 * WP Tools: get_media, update_alt_text
 *
 * update_alt_text is used by get_iato_seo_fixes bridge tool for automated alt text fixes.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_media ─────────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_media',
	[
		'description' => 'List media library items. Returns ID, URL, alt text, title, and mime type.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'per_page'     => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default: 20)' ],
				'missing_alt'  => [ 'type' => 'boolean', 'description' => 'If true, return only items with missing alt text' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$per_page    = min( absint( $args['per_page'] ?? 20 ), 100 );
		$missing_alt = ! empty( $args['missing_alt'] );

		$query = new WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $missing_alt ? -1 : $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$items = [];
		foreach ( $query->posts as $attachment ) {
			$alt = sanitize_text_field( get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );

			if ( $missing_alt && '' !== $alt ) {
				continue;
			}

			$items[] = [
				'id'        => $attachment->ID,
				'title'     => get_the_title( $attachment ),
				'url'       => wp_get_attachment_url( $attachment->ID ),
				'alt'       => $alt,
				'mime_type' => $attachment->post_mime_type,
			];
		}

		if ( $missing_alt ) {
			$items = array_slice( $items, 0, $per_page );
		}

		return IATO_MCP_Server::ok( [
			'media' => $items,
			'total' => count( $items ),
		] );
	}
);

// ── update_alt_text ───────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_alt_text',
	[
		'description' => 'Update the alt text for a media attachment.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer', 'description' => 'Attachment ID (required)' ],
				'alt'     => [ 'type' => 'string',  'description' => 'New alt text (required)' ],
			],
			'required' => [ 'id', 'alt' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$attachment_id = absint( $args['id'] ?? 0 );
		$alt           = sanitize_text_field( $args['alt'] ?? '' );

		if ( ! $attachment_id ) return new WP_Error( 'missing_id', 'Attachment ID required' );

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'not_found', 'Attachment not found.' );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

		return IATO_MCP_Server::ok( [
			'id'  => $attachment_id,
			'alt' => $alt,
			'url' => wp_get_attachment_url( $attachment_id ),
		] );
	}
);
