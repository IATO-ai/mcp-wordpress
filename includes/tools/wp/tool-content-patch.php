<?php
/**
 * WP Tool: update_post_content_patch (1.22.0)
 *
 * Edits part of a post's content with exact-match operations (replace,
 * insert_before, insert_after, append), applied in order, all or nothing. The
 * response carries the content's hash and size before and after and the changed
 * spans with some context, never the content itself; the change receipt stores the
 * full before and after values server-side, so rollback restores the content as it
 * was. The rules are in IATO_MCP_Content_Patch.
 *
 * Refused where visitors do not see post_content: posts with Elementor on, and
 * Beaver Builder posts. Sanitised as update_post sanitises content, and refused
 * when sanitising the post as it stands would remove markup (an iframe added in
 * wp-admin) unless allow_markup_removal is true, since a one-sentence patch would
 * otherwise delete it.
 *
 * Requires edit_posts, and edit_post on the post.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

/** Divi or WPBakery: the post keeps its layout in shortcodes in post_content. Null for any other post. */
function iato_mcp_content_patch_builder( WP_Post $post ): ?string {
	if ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) ) {
		return 'Divi';
	}
	if ( str_contains( (string) $post->post_content, '[vc_row' ) || str_contains( (string) $post->post_content, '[vc_column' ) ) {
		return 'WPBakery';
	}
	return null;
}

/** The refusal for a post whose content visitors do not see, or null. */
function iato_mcp_content_patch_builder_refusal( int $post_id ): ?WP_Error {
	if ( 'active' === IATO_MCP_Elementor_Adapter::builder_state( $post_id ) ) {
		return new WP_Error(
			'elementor_active',
			"Post {$post_id} is built with Elementor, which shows visitors its own data, not post_content, so a patch would not change the page. Nothing was written. Use list_elementor_widgets to find the widget, then update_elementor_widget for one widget or update_elementor_patch for several.",
			[ 'status' => 409, 'post_id' => $post_id, 'builder' => 'elementor', 'use_tools' => [ 'list_elementor_widgets', 'update_elementor_widget', 'update_elementor_patch' ] ]
		);
	}
	if ( get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
		return new WP_Error(
			'beaver_builder_active',
			"Post {$post_id} is built with Beaver Builder, which shows visitors its own data, not post_content, so a patch would not change the page. Nothing was written. Beaver Builder content cannot be written through MCP: ask the user to edit the post in WP admin.",
			[ 'status' => 409, 'post_id' => $post_id, 'builder' => 'beaver-builder' ]
		);
	}
	return null;
}

IATO_MCP_Server::register_tool(
	'update_post_content_patch',
	[
		'description' => 'Edit part of a post\'s content without sending the whole post: replace a sentence, insert before or after a passage, or append. ops are applied in order, all or nothing: replace {find, replace}, insert_before {anchor, html}, insert_after {anchor, html}, append {html}. find and anchor are exact strings that must match exactly once in the content as get_post returns it (byte for byte: line breaks and entities such as &amp; count); no match or several matches refuse the whole patch with the match count. replace "" deletes find. append adds html as given, with no line break. On block (Gutenberg) posts, insert whole blocks with their <!-- wp: --> comments. The response has no content: content_hash_before and content_hash_after, byte sizes, and each changed span with about 80 characters of context. The change receipt keeps the full content for rollback. Content is sanitised as update_post sanitises it; if sanitising the post as it stands would remove markup (an iframe added in wp-admin) the patch is refused, unless allow_markup_removal is true. Refused on posts built with Elementor (use the Elementor tools) or Beaver Builder. Supports dry_run, if_revision (get_post\'s content_hash) and idempotency_key. Requires edit_posts and edit_post.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'                   => [ 'type' => 'integer', 'description' => 'Post ID (required).' ],
				'ops'                  => [
					'type'        => 'array',
					'description' => 'Operations, applied in order, all or nothing, at most 50: {"op":"replace","find":"…","replace":"…"}, {"op":"insert_before","anchor":"…","html":"…"}, {"op":"insert_after","anchor":"…","html":"…"}, {"op":"append","html":"…"}. find and anchor must match exactly once in the content as it is after the operations before them.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'op'      => [ 'type' => 'string', 'enum' => [ 'replace', 'insert_before', 'insert_after', 'append' ] ],
							'find'    => [ 'type' => 'string', 'description' => 'replace: the exact text to replace.' ],
							'replace' => [ 'type' => 'string', 'description' => 'replace: the new text; "" deletes find.' ],
							'anchor'  => [ 'type' => 'string', 'description' => 'insert_before, insert_after: the exact text to insert next to.' ],
							'html'    => [ 'type' => 'string', 'description' => 'insert_before, insert_after, append: the HTML to insert.' ],
						],
						'required'   => [ 'op' ],
					],
				],
				'dry_run'              => [ 'type' => 'boolean', 'description' => 'Preview the hashes, sizes, spans and sanitising without saving (default: false).' ],
				'if_revision'          => [ 'type' => 'string', 'description' => 'Optional. The content_hash from get_post (or content_hash_after from an earlier patch). Refused with revision_conflict if the content has changed since.' ],
				'idempotency_key'      => [ 'type' => 'string', 'description' => 'Optional, for writes. The same key with the same arguments within 60s returns the first response with idempotency_replay: true; with other arguments it is refused.' ],
				'allow_markup_removal' => [ 'type' => 'boolean', 'description' => 'Default false. When sanitising the post as it stands would remove markup (tags, attributes or CSS, listed in the refusal), the patch is refused unless this is true. Ask the user first: the removed markup is gone from the page, though rollback restores it.' ],
			],
			'required'   => [ 'id', 'ops' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$post_id = absint( $args['id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}
		// Also refuses a revision and the theme's Additional CSS post.
		$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}

		$ops = IATO_MCP_Content_Patch::validate_ops( $args['ops'] ?? null );
		if ( is_wp_error( $ops ) ) {
			return IATO_MCP_Server::refusal( $ops );
		}
		$dry_run     = ! empty( $args['dry_run'] );
		$allow_loss  = ! empty( $args['allow_markup_removal'] );
		$if_revision = isset( $args['if_revision'] ) ? (string) $args['if_revision'] : '';

		// Writes only: a dry run stored under the key would make the write that follows
		// it with the same key a different payload.
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$key     = ! $dry_run && isset( $args['idempotency_key'] ) ? (string) $args['idempotency_key'] : null;
		$cached  = IATO_MCP_Elementor_Concurrency::idempotency_lookup( $user_id, 'update_post_content_patch', $key, $args );
		if ( is_wp_error( $cached ) ) {
			return IATO_MCP_Server::refusal( $cached );
		}
		if ( is_array( $cached ) ) {
			return IATO_MCP_Server::ok( $cached );
		}

		$builder = iato_mcp_content_patch_builder_refusal( $post_id );
		if ( null !== $builder ) {
			return IATO_MCP_Server::refusal( $builder );
		}

		$before = (string) $post->post_content;
		$hash   = IATO_MCP_Content_Patch::hash( $before );
		if ( '' !== $if_revision && $if_revision !== $hash ) {
			return IATO_MCP_Server::refusal( new WP_Error(
				'revision_conflict',
				"Post {$post_id}'s content has changed since that content_hash. Nothing was written. Read it again with get_post and rebuild the patch.",
				[ 'status' => 409, 'current_revision' => $hash ]
			) );
		}

		// The client's own mistakes (no match, several) first.
		$applied = IATO_MCP_Content_Patch::apply( $before, $ops );
		if ( is_wp_error( $applied ) ) {
			return IATO_MCP_Server::refusal( $applied );
		}
		$patched = $applied['content'];

		$data = [ 'post_id' => $post_id ];
		if ( $dry_run ) {
			$data['dry_run'] = true;
		}
		if ( $patched === $before ) {
			$data += [ 'action' => 'no_change' ] + IATO_MCP_Content_Patch::sizes( $before, $before ) + [ 'note' => 'The patch leaves the content as it is. Nothing was written.' ];
			return iato_mcp_content_patch_done( $data, $user_id, $key, $args );
		}

		// Markup in the post as it stands that sanitising would remove: any write to
		// the content through MCP removes it, a one-sentence patch included.
		$existing = IATO_MCP_Sanitize_Report::for_value( 'content', 'html', $before, iato_mcp_post_content_as_stored( $before, $post_id ) );
		$lost     = null === $existing ? null : array_intersect_key( $existing, [ 'removed_tags' => true, 'removed_attributes' => true, 'removed_css' => true ] );
		if ( null !== $lost && ! $allow_loss ) {
			return IATO_MCP_Server::refusal( new WP_Error(
				'existing_markup_would_be_removed',
				"Post {$post_id} already holds markup that sanitising removes (listed here), so writing its content through MCP would remove it from the page, this patch included. Nothing was written. Ask the user: to keep it, edit the post in WP admin; to let it go, call again with allow_markup_removal: true. Rollback would restore it.",
				[ 'status' => 409, 'post_id' => $post_id ] + $lost
			) );
		}

		$predicted = iato_mcp_post_content_as_stored( $patched, $post_id );
		if ( $predicted === $before ) {
			$data += [ 'action' => 'no_change' ] + IATO_MCP_Content_Patch::sizes( $before, $before ) + [ 'note' => 'Sanitising removed everything this patch changed. Nothing was written.' ];
			IATO_MCP_Sanitize_Report::add_to( $data, iato_mcp_content_patch_report( $patched, $predicted ) );
			return iato_mcp_content_patch_done( $data, $user_id, $key, $args );
		}

		if ( $dry_run ) {
			$data += [ 'action' => 'would_update' ] + iato_mcp_content_patch_summary( $before, $patched, $predicted, $applied['spans'] );
			if ( null !== $lost ) {
				$data['existing_markup_removed'] = $lost;
			}
			IATO_MCP_Sanitize_Report::add_to( $data, iato_mcp_content_patch_report( $patched, $predicted ) );
			$data['not_checked'] = [
				'Filters from other plugins that run on save (wp_insert_post_data), which can change the content.',
				'Bytes the database cannot store, which it drops.',
				'Attribute values sanitising rewrites without removing the attribute, such as a data: image source.',
			];
			$notice = iato_mcp_content_patch_notice( $post );
			if ( null !== $notice ) {
				$data['notice'] = $notice;
			}
			return IATO_MCP_Server::ok( $data );
		}

		// As update_post writes content: the plugin's markup rule, then WordPress's save
		// filters. Not verbatim, which is for rollbacks.
		$result = IATO_MCP_Auth::update_post_fields( [ 'ID' => $post_id, 'post_content' => IATO_MCP_Elementor_Sanitizer::clean_markup( $patched ) ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		wp_cache_delete( $post_id, 'posts' );
		$fresh  = get_post( $post_id );
		$stored = $fresh ? (string) $fresh->post_content : $predicted;

		$data += [ 'action' => $stored === $before ? 'no_change' : 'updated', 'modified' => $fresh ? $fresh->post_modified_gmt : null ]
			+ iato_mcp_content_patch_summary( $before, $patched, $stored, $applied['spans'] );
		if ( null !== $lost ) {
			$data['existing_markup_removed'] = $lost;
		}
		if ( $stored !== $before ) {
			// The full values stay in the receipt row: rollback writes the before value
			// back, and its conflict check compares the after value with the post.
			$receipt                = IATO_MCP_Change_Receipt::record( $post_id, 'post', 'content', $before, $stored );
			$data['change_receipt'] = IATO_MCP_Change_Receipt::slim( $receipt );
		}
		IATO_MCP_Sanitize_Report::add_to( $data, iato_mcp_content_patch_report( $patched, $stored ) );
		$notice = iato_mcp_content_patch_notice( $post );
		if ( null !== $notice ) {
			$data['notice'] = $notice;
		}
		return iato_mcp_content_patch_done( $data, $user_id, $key, $args );
	}
);

/**
 * Hashes, sizes and spans, and whether what is (or would be) stored is the patched
 * text. The spans describe the patch as applied, before sanitising.
 */
function iato_mcp_content_patch_summary( string $before, string $patched, string $stored, array $spans ): array {
	$out = IATO_MCP_Content_Patch::sizes( $before, $stored ) + [
		'spans'                => $spans,
		'stored_matches_patch' => $stored === $patched,
	];
	if ( $stored !== $patched ) {
		// As the Elementor tools say it (1.21.1): the same HTML written differently, or not.
		$out['stored_difference'] = [ true === IATO_MCP_Sanitize_Report::same_markup( $patched, $stored ) ? 'markup_normalized' : 'changed' ];
	}
	return $out;
}

/** The response, stored first under the idempotency key when there is one. */
function iato_mcp_content_patch_done( array $data, int $user_id, ?string $key, array $args ): array {
	IATO_MCP_Elementor_Concurrency::idempotency_store( $user_id, 'update_post_content_patch', $key, $args, $data );
	return IATO_MCP_Server::ok( $data );
}

/** The sanitized_report entry for the patched content, or none. */
function iato_mcp_content_patch_report( string $patched, string $stored ): array {
	$entry = IATO_MCP_Sanitize_Report::for_value( 'content', 'html', $patched, $stored );
	return null === $entry ? [] : [ $entry ];
}

/** On a Divi or WPBakery post: the patch changed text, and layout is edited in WP admin. */
function iato_mcp_content_patch_notice( WP_Post $post ): ?string {
	$builder = iato_mcp_content_patch_builder( $post );
	return null === $builder ? null : "This post is built with {$builder}, which keeps its layout in shortcodes in the content. Change text inside them only; layout is edited in WP admin.";
}
