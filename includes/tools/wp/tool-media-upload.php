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
		'description' => 'Upload a new image to the media library. Two source modes: base64 inline bytes (default) or URL ingestion. Use base64 ONLY for tiny assets — favicons, sprite icons, ~4 KB or under of decoded image. The MCP transport truncates or drops larger JSON-RPC payloads before they reach the plugin, which presents as the call hanging; this is a hard transport constraint, not a server-side limit. For anything bigger (photos, screenshots, full-size images) use URL ingestion. URL ingestion requires the admin to enable it once under Settings > IATO MCP > Media Uploads. The site\'s own host is implicitly trusted; external hosts must be added to the allowlist. The URL must point to the raw image bytes — viewer/share pages do NOT work (Google Drive share links return HTML, not the file; Dropbox links need ?dl=1, not ?dl=0). Most reliable workflow for non-trivial images: upload to the site\'s WordPress media library first (or any host serving the raw bytes), then pass that URL here. source.type is inferred from the presence of data vs url when omitted. SVG is not supported. Returns the new attachment_id, URL, intermediate sizes, and a change_receipt that fully deletes the attachment on rollback.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'filename'       => [ 'type' => 'string',  'description' => 'Intended filename. Will be sanitised and uniquified.' ],
				'mime_type'      => [ 'type' => 'string',  'description' => 'Claimed MIME — verified against actual bytes, not trusted.' ],
				'source'         => [
					'type'        => 'object',
					'description' => 'Discriminated union: { type: "base64", data: <b64> } or { type: "url", url: <https-url> }. type is inferred when omitted — supply data for base64 or url for url ingestion and the plugin will pick the right mode.',
					'properties'  => [
						'type' => [ 'type' => 'string', 'enum' => [ 'base64', 'url' ], 'description' => 'Optional. Inferred from data/url presence if omitted.' ],
						'data' => [ 'type' => 'string', 'description' => 'Base64-encoded bytes (for type=base64). data: URI prefix is stripped if present. Practical ceiling ~4 KB of decoded image — larger payloads are truncated by the MCP transport. Use url for anything bigger.' ],
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
			$edit_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
			if ( is_wp_error( $edit_check ) ) {
				return $edit_check;
			}
		}

		$user_id = get_current_user_id() ?: 0;
		$result  = IATO_MCP_Media_Uploader::ingest( $args, $user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// dry_run returns the validation summary directly — no attachment, no receipt.
		if ( ! empty( $result['dry_run'] ) ) {
			return IATO_MCP_Server::ok( $result );
		}

		$attachment_id = (int) $result['attachment_id'];
		$receipt = IATO_MCP_Change_Receipt::record(
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
		return IATO_MCP_Server::ok( $result );
	}
);
