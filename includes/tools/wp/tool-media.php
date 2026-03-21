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
		// TODO: implement — WP_Query with post_type='attachment'
		// If missing_alt: filter where get_post_meta($id, '_wp_attachment_image_alt', true) === ''
		return new WP_Error( 'not_implemented', 'get_media not yet implemented' );
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

		// TODO: implement — update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt)
		return new WP_Error( 'not_implemented', 'update_alt_text not yet implemented' );
	}
);
