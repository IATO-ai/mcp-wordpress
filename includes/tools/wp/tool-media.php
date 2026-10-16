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
		'description' => 'List media library items. Returns ID, URL, alt text, title, and mime type. Requires upload_files (Author and above).',
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
		// The whole image library, including images attached to drafts and
		// private posts: the same capability the Media Library needs (1.13.0).
		$cap_check = IATO_MCP_Auth::require_cap( 'upload_files' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

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
		'description' => 'Update the alt text for a media attachment (id). Since 1.14.0 it also fixes the alt text of an Elementor Atomic Editor (V4) image element: pass post_id and element_id instead of id. An element that shows an external URL stores its own alt text; one that shows a Media Library image uses the attachment\'s, so the change applies everywhere that image is used.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'         => [ 'type' => 'integer', 'description' => 'Attachment ID (required unless post_id and element_id are given)' ],
				'alt'        => [ 'type' => 'string',  'description' => 'New alt text (required)' ],
				'post_id'    => [ 'type' => 'integer', 'description' => 'Post holding an atomic e-image element (with element_id)' ],
				'element_id' => [ 'type' => 'string',  'description' => 'Elementor element id of the e-image (with post_id)' ],
				'dry_run'    => [ 'type' => 'boolean', 'description' => 'Report the change without writing it' ],
			],
			'required' => [ 'alt' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		// Accept both schema names (id/alt) and Autopilot names (attachment_id/alt_text).
		$attachment_id = absint( $args['id'] ?? $args['attachment_id'] ?? 0 );
		$alt           = sanitize_text_field( $args['alt'] ?? $args['alt_text'] ?? '' );

		// 1.14.0: an atomic image element (post_id + element_id).
		if ( ! $attachment_id && ( isset( $args['post_id'] ) || isset( $args['element_id'] ) ) ) {
			return iato_mcp_update_element_alt( absint( $args['post_id'] ?? 0 ), (string) ( $args['element_id'] ?? '' ), $alt, ! empty( $args['dry_run'] ) );
		}

		if ( ! $attachment_id ) return new WP_Error( 'missing_id', 'Attachment ID required (or post_id and element_id for an Elementor atomic image element).' );

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'not_found', 'Attachment not found.' );
		}

		$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $attachment_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}

		// 1.14.0: dry_run is in the schema (for elements); honoured here too.
		if ( ! empty( $args['dry_run'] ) ) {
			return IATO_MCP_Server::ok( [
				'id'      => $attachment_id,
				'dry_run' => true,
				'from'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'alt'     => $alt,
				'url'     => wp_get_attachment_url( $attachment_id ),
			] );
		}

		$receipt = IATO_MCP_Elementor_Atomic_Writer::write_attachment_alt( $attachment_id, $alt );

		$data = [
			'id'  => $attachment_id,
			'alt' => $alt,
			'url' => wp_get_attachment_url( $attachment_id ),
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);

/**
 * update_alt_text for an Elementor atomic image element (1.14.0): an
 * element that shows an external URL gets its own alt (image.src.alt,
 * through the atomic writer); one that shows a Media Library image routes to
 * the attachment's alt, as update_alt_text with id does.
 */
function iato_mcp_update_element_alt( int $post_id, string $element_id, string $alt, bool $dry_run ): array|WP_Error {
	if ( ! $post_id || '' === $element_id ) {
		return new WP_Error( 'missing_id', 'post_id and element_id are both required for an element (or pass the attachment id as id).' );
	}
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'not_found', "Post {$post_id} not found." );
	}
	$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
	if ( is_wp_error( $object_check ) ) {
		return $object_check;
	}
	$tree = IATO_MCP_Elementor_Adapter::stored_elements( $post_id );
	if ( null === $tree ) {
		return new WP_Error( 'no_elementor_data', "Post {$post_id} has no Elementor data." );
	}
	$found = IATO_MCP_Elementor_Adapter::find_widget( $tree, $element_id );
	if ( null === $found ) {
		return new WP_Error( 'widget_not_found', "Element {$element_id} not found in post {$post_id}." );
	}
	$node = $found['element'];
	if ( ! IATO_MCP_Elementor_Atomic_Writer::is_target( $node ) ) {
		return new WP_Error( 'invalid_widget_type', "Element {$element_id} is a classic " . IATO_MCP_Elementor_Atomic::type_of( $node ) . ' widget. post_id and element_id are for Elementor atomic image elements (e-image); for a classic image widget pass its attachment id as id.' );
	}
	$why = IATO_MCP_Elementor_Atomic_Writer::unregistered( $node ) ?? IATO_MCP_Elementor_Atomic_Writer::unavailable( $node );
	if ( null !== $why ) {
		return IATO_MCP_Elementor_Adapter::tool_error( $why );
	}
	$response = IATO_MCP_Elementor_Atomic_Writer::update_widget(
		$post_id,
		$element_id,
		$found['path'],
		[ 'image' => [ 'src' => [ 'alt' => $alt ] ] ],
		[ 'dry_run' => $dry_run ]
	);
	if ( is_wp_error( $response ) ) {
		return IATO_MCP_Elementor_Adapter::tool_error( $response );
	}
	return IATO_MCP_Server::ok( $response );
}
