<?php
/**
 * WP Tool: create_media
 *
 * Upload a new image to the WordPress media library and create the matching
 * attachment record. Source can be base64-encoded bytes (default) or, when
 * the admin has opted in, an HTTPS URL on a configured allowlist. All work
 * is delegated to IATO_MCP_Media_Uploader.
 *
 * Emits a change_receipt under target_type=attachment so rollback can fully
 * delete the attachment (including the on-disk file).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'create_media',
	[
		'description' => 'Upload a new image to the media library from base64 bytes (default) or a URL. Send base64 only for a file of about 3 KB or less: the MCP transport truncates, drops or alters larger payloads before they reach the plugin, which shows up as a call that hangs or a damaged image. For anything larger (photos, screenshots, full-size images) use media_upload_start, media_upload_chunk and media_upload_finish, which carry the file in pieces and check it as a whole. Send sha256, the hex digest of the file: if the bytes that arrive differ, the call is refused with checksum_mismatch and nothing is stored. Bytes that are not a whole image of the declared type are refused with invalid_image. SVG is not supported. A URL is for an image already online: URL ingestion must be enabled under Settings > IATO MCP > Media Uploads, the site\'s own host is trusted and other hosts must be on the allowlist, and the URL must serve the raw image bytes (share pages such as Google Drive links return HTML; Dropbox links need ?dl=1). source.type is inferred from data or url when omitted. Returns the attachment_id, URL, intermediate sizes, sha256 and decoded_bytes (compare them with your file), warnings when WordPress could not create the expected sizes, and a change_receipt whose rollback deletes the attachment.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'filename'       => [ 'type' => 'string',  'description' => 'Intended filename. Will be sanitised and uniquified.' ],
				'mime_type'      => [ 'type' => 'string',  'description' => 'The image type. With base64, bytes of another type are refused (invalid_image).' ],
				'sha256'         => [ 'type' => 'string',  'description' => 'SHA-256 hex digest of the file, 64 characters. If the bytes that arrive differ, the call is refused with checksum_mismatch and nothing is stored.' ],
				'source'         => [
					'type'        => 'object',
					'description' => 'Discriminated union: { type: "base64", data: <b64> } or { type: "url", url: <https-url> }. type is inferred when omitted — supply data for base64 or url for url ingestion and the plugin will pick the right mode.',
					'properties'  => [
						'type' => [ 'type' => 'string', 'enum' => [ 'base64', 'url' ], 'description' => 'Optional. Inferred from data/url presence if omitted.' ],
						'data' => [ 'type' => 'string', 'description' => 'Base64-encoded bytes (for type=base64). A data: URI prefix is stripped if present. Reliable up to about 3 KB of decoded image: the MCP transport alters or truncates larger payloads. Use media_upload_start, media_upload_chunk and media_upload_finish for anything bigger.' ],
						'url'  => [ 'type' => 'string', 'description' => 'Absolute http(s) URL pointing directly at the raw image bytes (not a viewer / share page). Requires URL ingestion to be enabled in Settings > IATO MCP > Media Uploads; the site\'s own host is implicitly trusted, external hosts must be allowlisted.' ],
					],
				],
				'alt_text'       => [ 'type' => 'string',  'description' => 'Stored as _wp_attachment_image_alt. Strongly encouraged.' ],
				'caption'        => [ 'type' => 'string',  'description' => 'Attachment caption (post_excerpt).' ],
				'title'          => [ 'type' => 'string',  'description' => 'Attachment title. Defaults to the filename without extension.' ],
				'description'    => [ 'type' => 'string',  'description' => 'Attachment description (post_content).' ],
				'attach_to_post' => [ 'type' => 'integer', 'description' => 'If set, links the new attachment to that post via post_parent (does NOT set it as the featured image — use set_featured_image for that).' ],
				'dry_run'        => [ 'type' => 'boolean', 'description' => 'Validate the source without persisting (default false).' ],
				'defer_subsizes' => [ 'type' => 'boolean', 'description' => 'If true, skip the synchronous wp_generate_attachment_metadata call and schedule it via WP-Cron. The response returns immediately with attachment_id and the canonical URL but `intermediate_sizes` will be empty until the cron tick runs (typically the next request to the site). Recommended when the host has a slow image-resize pipeline (ShortPixel, Imagify, etc.) that pushes synchronous uploads past the MCP gateway timeout. Default false.' ],
			],
			'required' => [ 'filename', 'mime_type', 'source' ],
		],
	],
	function ( array $args ): array|WP_Error {
		// Use IATO_MCP_Auth::require_cap (not current_user_can) so plugin-Bearer
		// authenticated requests grant the cap. current_user_can() always returns
		// false under Bearer auth because wp_get_current_user() returns 0 and
		// meta-cap checks against the empty WP_User object always fail. Same bug
		// class fixed in v1.3.1 for update_elementor_widgets_bulk / find_elementor_widgets.
		$cap_check = IATO_MCP_Auth::require_cap( 'upload_files' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$attach_to_post = isset( $args['attach_to_post'] ) ? absint( $args['attach_to_post'] ) : 0;
		if ( $attach_to_post > 0 ) {
			if ( ! get_post( $attach_to_post ) ) {
				return new WP_Error( 'not_found', 'attach_to_post references a post that does not exist.' );
			}
			$edit_check = IATO_MCP_Auth::require_cap( 'edit_post', $attach_to_post );
			if ( is_wp_error( $edit_check ) ) {
				return $edit_check;
			}
		}

		$user_id = get_current_user_id() ?: 0;
		$result  = IATO_MCP_Media_Uploader::ingest( $args, $user_id );
		if ( is_wp_error( $result ) ) {
			return IATO_MCP_Server::refusal( $result );
		}

		// dry_run returns the validation summary directly — no attachment, no receipt.
		if ( ! empty( $result['dry_run'] ) ) {
			return IATO_MCP_Server::ok( $result );
		}

		return IATO_MCP_Server::ok( iato_mcp_media_with_receipt( $result ) );
	}
);

/** What the uploader returned, with the receipt that fully deletes the attachment on rollback. */
function iato_mcp_media_with_receipt( array $result ): array {
	$attachment_id = (int) $result['attachment_id'];
	$receipt       = IATO_MCP_Change_Receipt::record(
		$attachment_id,
		'attachment',
		'create',
		null,
		[
			'attachment_id' => $attachment_id,
			'url'           => $result['url'],
			'filename'      => $result['filename'],
		]
	);
	IATO_MCP_Change_Receipt::append( $result, $receipt );
	return $result;
}

// ── Chunked uploads ───────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'media_upload_start',
	[
		'description' => 'Start a chunked image upload, for any file over about 3 KB. The transport cannot carry more than a few KB of base64 in one call reliably. Returns an upload_id. Then send the file with media_upload_chunk, in order, every chunk the same size except the last and up to about 3 KB decoded, and call media_upload_finish. PNG, JPEG, GIF, WebP or AVIF. An upload with no activity for an hour is removed.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'filename'       => [ 'type' => 'string',  'description' => 'Intended filename. Will be sanitised and uniquified.' ],
				'mime_type'      => [ 'type' => 'string',  'description' => 'The image type. Bytes of another type are refused at the end (invalid_image).' ],
				'total_bytes'    => [ 'type' => 'integer', 'description' => 'The size of the file. At most 15 MB, or the site\'s upload limit if lower.' ],
				'sha256'         => [ 'type' => 'string',  'description' => 'Hex digest of the file.' ],
				'alt_text'       => [ 'type' => 'string',  'description' => 'Stored as _wp_attachment_image_alt. Strongly encouraged.' ],
				'title'          => [ 'type' => 'string',  'description' => 'Attachment title. Defaults to the filename without extension.' ],
				'caption'        => [ 'type' => 'string',  'description' => 'Attachment caption (post_excerpt).' ],
				'attach_to_post' => [ 'type' => 'integer', 'description' => 'If set, links the new attachment to that post via post_parent (does NOT set it as the featured image — use set_featured_image for that).' ],
				'dry_run'        => [ 'type' => 'boolean', 'description' => 'Check all this without starting an upload (default false).' ],
			],
			'required' => [ 'filename', 'mime_type', 'total_bytes', 'sha256' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'upload_files' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		$attach_to_post = isset( $args['attach_to_post'] ) ? absint( $args['attach_to_post'] ) : 0;
		if ( $attach_to_post > 0 ) {
			if ( ! get_post( $attach_to_post ) ) {
				return IATO_MCP_Server::refusal( new WP_Error( 'not_found', 'attach_to_post references a post that does not exist.' ) );
			}
			$edit_check = IATO_MCP_Auth::require_cap( 'edit_post', $attach_to_post );
			if ( is_wp_error( $edit_check ) ) {
				return $edit_check;
			}
		}
		$result = IATO_MCP_Media_Chunks::start( $args );
		return is_wp_error( $result ) ? IATO_MCP_Server::refusal( $result ) : IATO_MCP_Server::ok( $result );
	}
);

IATO_MCP_Server::register_tool(
	'media_upload_chunk',
	[
		'description' => 'Send one chunk of an upload begun with media_upload_start. Chunks go in order from index 0, each encoded in base64 on its own, up to about 3 KB decoded (4 KB at most). Every chunk must be the size of the first, except the last. Sending a chunk again with the same contents is safe. Returns bytes_received and next_index.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'upload_id'    => [ 'type' => 'string',  'description' => 'From media_upload_start.' ],
				'index'        => [ 'type' => 'integer', 'description' => 'This chunk\'s position, counting from 0.' ],
				'data'         => [ 'type' => 'string',  'description' => 'This chunk\'s bytes in base64.' ],
				'chunk_sha256' => [ 'type' => 'string',  'description' => 'Hex digest of this chunk\'s bytes. If it does not match, the chunk is refused (checksum_mismatch) and can be sent again.' ],
				'dry_run'      => [ 'type' => 'boolean', 'description' => 'Check this chunk without storing it (default false).' ],
			],
			'required' => [ 'upload_id', 'index', 'data' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'upload_files' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		$result = IATO_MCP_Media_Chunks::chunk( $args );
		return is_wp_error( $result ) ? IATO_MCP_Server::refusal( $result ) : IATO_MCP_Server::ok( $result );
	}
);

IATO_MCP_Server::register_tool(
	'media_upload_finish',
	[
		'description' => 'Finish an upload: checks the total size and sha256, checks it is a whole image of the declared type, creates the attachment and removes the upload. Returns what create_media returns, including a change_receipt. If this call times out, call it again: it returns the same result and does not upload twice. checksum_mismatch means the file arrived altered: start again.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'upload_id'      => [ 'type' => 'string',  'description' => 'From media_upload_start.' ],
				'defer_subsizes' => [ 'type' => 'boolean', 'description' => 'If true, create the image sizes through WP-Cron rather than in this call. Recommended when the host has a slow image pipeline. Default false.' ],
				'dry_run'        => [ 'type' => 'boolean', 'description' => 'Check the file without creating an attachment. The upload stays (default false).' ],
			],
			'required' => [ 'upload_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'upload_files' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		$result = IATO_MCP_Media_Chunks::finish( $args, 'iato_mcp_media_with_receipt' );
		return is_wp_error( $result ) ? IATO_MCP_Server::refusal( $result ) : IATO_MCP_Server::ok( $result );
	}
);
