<?php
/**
 * WP Tools: get_page_builder, get_elementor_data, update_elementor_data
 *
 * get_page_builder       — read: detects which page builder a post uses
 * get_elementor_data     — read: returns raw Elementor JSON data
 * update_elementor_data  — edit_posts: updates Elementor data and clears cache
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_page_builder ─────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_page_builder',
	[
		'description' => 'Detects which page builder a post or page uses: elementor, wpbakery, divi, beaver-builder, gutenberg, or classic. For Elementor posts also returns elementor_schema: "classic" (V3 widgets only), "atomic" (V4 Atomic Editor elements only), "mixed", or "empty".',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] ?? 0 );
		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$read_check = IATO_MCP_Auth::require_read_post( $post_id );
		if ( is_wp_error( $read_check ) ) {
			return $read_check;
		}

		$content = $post->post_content;

		// Elementor.
		if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder' ) {
			$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
			$response       = [
				'post_id'  => $post_id,
				'builder'  => 'elementor',
				'has_data' => ! empty( $elementor_data ),
			];
			// classic | atomic | mixed | empty — lets clients know whether the
			// document contains Elementor V4 (Atomic Editor) elements before they
			// pick a write path. Decode failures leave the key out rather than
			// failing the builder detection.
			if ( ! empty( $elementor_data ) ) {
				$decoded = json_decode( (string) $elementor_data, true );
				if ( is_array( $decoded ) ) {
					$response['elementor_schema'] = IATO_MCP_Elementor_Atomic::document_schema(
						IATO_MCP_Elementor_Adapter::force_arrays( $decoded )
					);
				}
			}
			return IATO_MCP_Server::ok( $response );
		}

		// WPBakery — shortcodes in post_content.
		if ( str_contains( $content, '[vc_row]' ) || str_contains( $content, '[vc_column]' ) ) {
			return IATO_MCP_Server::ok( [
				'post_id' => $post_id,
				'builder' => 'wpbakery',
			] );
		}

		// Divi.
		if ( get_post_meta( $post_id, '_et_pb_use_builder', true ) === 'on' ) {
			return IATO_MCP_Server::ok( [
				'post_id' => $post_id,
				'builder' => 'divi',
			] );
		}

		// Beaver Builder.
		if ( get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
			return IATO_MCP_Server::ok( [
				'post_id' => $post_id,
				'builder' => 'beaver-builder',
			] );
		}

		// Gutenberg blocks.
		if ( has_blocks( $content ) ) {
			return IATO_MCP_Server::ok( [
				'post_id' => $post_id,
				'builder' => 'gutenberg',
			] );
		}

		// Classic editor / no builder.
		return IATO_MCP_Server::ok( [
			'post_id' => $post_id,
			'builder' => 'classic',
		] );
	}
);

// ── get_elementor_data ───────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_elementor_data',
	[
		'description' => 'Returns Elementor data for a post. format=raw (default) returns the original stored JSON; format=compact decodes and strips default-valued settings (classic widgets only; atomic nodes pass through unchanged); format=summary returns a tree of {widget_id, type, schema, peek_fields} where schema is "classic" or "atomic" (Elementor V4) and atomic nodes are normalised to the classic peek keys plus tag / image / link fields. Always includes the revision hash for use with v2 if_revision guards.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'     => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'format' => [ 'type' => 'string',  'enum' => [ 'raw', 'compact', 'summary' ], 'description' => 'Output shape (default: raw).' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] ?? 0 );
		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$read_check = IATO_MCP_Auth::require_read_post( $post_id );
		if ( is_wp_error( $read_check ) ) {
			return $read_check;
		}

		$data      = get_post_meta( $post_id, '_elementor_data', true );
		$edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );

		if ( empty( $data ) ) {
			return new WP_Error( 'no_elementor_data', 'No Elementor data found for this post. Check with get_page_builder first.' );
		}

		$format = isset( $args['format'] ) ? (string) $args['format'] : 'raw';
		if ( ! in_array( $format, [ 'raw', 'compact', 'summary' ], true ) ) {
			$format = 'raw';
		}

		$revision = IATO_MCP_Elementor_Adapter::compute_revision( (string) $data );

		if ( 'raw' === $format ) {
			return IATO_MCP_Server::ok( [
				'post_id'        => $post_id,
				'revision'       => $revision,
				'format'         => 'raw',
				'elementor_data' => $data,
				'edit_mode'      => $edit_mode,
			] );
		}

		// Compact / summary need a decoded tree.
		$decoded = IATO_MCP_Elementor_Adapter::decode_data( $post_id );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		[ $elements, ] = $decoded;

		if ( 'summary' === $format ) {
			return IATO_MCP_Server::ok( [
				'post_id'   => $post_id,
				'revision'  => $revision,
				'format'    => 'summary',
				'edit_mode' => $edit_mode,
				'tree'      => IATO_MCP_Elementor_Adapter::flatten_widgets( $elements, 'tree' ),
			] );
		}

		// Compact: walk the decoded tree, strip defaults per widget.
		$compact_tree = iato_mcp_apply_compact_recursive( $elements );
		return IATO_MCP_Server::ok( [
			'post_id'        => $post_id,
			'revision'       => $revision,
			'format'         => 'compact',
			'edit_mode'      => $edit_mode,
			'elementor_data' => wp_json_encode( $compact_tree ),
		] );
	}
);

/**
 * Walk the decoded element tree applying IATO_MCP_Elementor_Adapter::compact()
 * to every widget node. Container types (sections/columns) pass through.
 */
function iato_mcp_apply_compact_recursive( array $elements ): array {
	$out = [];
	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}
		if ( ( $element['elType'] ?? '' ) === 'widget' ) {
			$element = IATO_MCP_Elementor_Adapter::compact( $element );
		}
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$element['elements'] = iato_mcp_apply_compact_recursive( $element['elements'] );
		}
		$out[] = $element;
	}
	return $out;
}

// ── update_elementor_data ────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_elementor_data',
	[
		'description' => 'Updates the _elementor_data JSON for a post, clears Elementor CSS cache, and regenerates rendered post_content. Supports dry_run. Optional inherit_settings_from copies a curated set of theme + Elementor page-level meta keys from a source post in the same call (one change_receipt per inherited key). Requires edit_posts capability.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'                    => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'elementor_data'        => [ 'type' => 'string',  'description' => 'Full Elementor JSON data string (required).' ],
				'dry_run'               => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
				'inherit_settings_from' => [ 'type' => 'integer', 'description' => 'Optional source post ID. When set, copies a curated set of theme + Elementor page-level meta keys onto the target.' ],
				'inherit_keys'          => [ 'type' => 'array',   'description' => 'Optional override of the inherited key list. Defaults to a built-in curated list when omitted.', 'items' => [ 'type' => 'string' ] ],
			],
			'required' => [ 'id', 'elementor_data' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$post_id        = absint( $args['id'] ?? 0 );
		$elementor_data = $args['elementor_data'] ?? '';
		$dry_run        = ! empty( $args['dry_run'] );

		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}

		if ( empty( $elementor_data ) ) {
			return new WP_Error( 'missing_data', 'elementor_data is required.' );
		}

		// Validate JSON.
		$decoded = json_decode( $elementor_data, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', 'Invalid JSON: ' . json_last_error_msg() );
		}
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'invalid_json', 'elementor_data must be a JSON array of elements.' );
		}
		$decoded = IATO_MCP_Elementor_Adapter::force_arrays( $decoded );

		// What is stored now: the sanitising pass compares against it so only the
		// values this request changes are touched, and the revision guards use it.
		$stored_elements = IATO_MCP_Elementor_Adapter::stored_elements( $post_id );
		$stored_raw      = get_post_meta( $post_id, '_elementor_data', true );
		$stored_raw      = is_array( $stored_raw ) ? (string) wp_json_encode( $stored_raw ) : (string) $stored_raw;
		$previous_revision = IATO_MCP_Elementor_Adapter::compute_revision( $stored_raw );
		// What a rollback must put back when the post had no Elementor data:
		// Elementor's save replaces post_content with its rendering of the tree,
		// so the receipt keeps the original content and builder flag (1.13.0).
		$receipt_before = '' !== $stored_raw ? $stored_raw : IATO_MCP_Rollback::no_elementor_envelope(
			(string) get_post_field( 'post_content', $post_id ),
			(string) get_post_meta( $post_id, '_elementor_edit_mode', true )
		);

		// Resolve inherit_settings_from inputs — plan the meta writes now so dry_run
		// surfaces them, and apply them after the main Elementor write succeeds.
		//
		// Empty-string values are now copied through (they used to be skipped). On
		// Astra and similar themes, empty strings are stored as meaningful values
		// (e.g. ast-main-header-display="" is a real per-post override, not "no
		// override"). WordPress get_post_meta returns '' for both stored-empty and
		// absent meta, so we can't distinguish; the safer default is to mirror
		// whatever the source has, since the contract of inherit_settings_from is
		// "make this target match the source."
		//
		// Keys whose source value matches the target's existing value are recorded
		// in $inherit_skipped (reason: noop) so the caller can see what was planned
		// but produced no actual change.
		$inherit_source  = isset( $args['inherit_settings_from'] ) ? absint( $args['inherit_settings_from'] ) : 0;
		$inherit_plan    = [];
		$inherit_skipped = [];
		if ( $inherit_source > 0 ) {
			if ( ! get_post( $inherit_source ) ) {
				return new WP_Error( 'inherit_source_not_found', 'inherit_settings_from references a post that does not exist.' );
			}
			// The source is only read, but its meta may belong to a post the
			// caller cannot see (another user's draft, a private or password-
			// protected page, a template): same gate as the read tools.
			$source_check = IATO_MCP_Auth::require_read_post( $inherit_source );
			if ( is_wp_error( $source_check ) ) {
				return $source_check;
			}
			// Default curated list spans Astra per-post overrides + Elementor page
			// settings + WP page template. Wider than the original 8 because real
			// cloning workflows also need ast-banner-title-visibility, ast-featured-img,
			// site-content-style, etc. Callers who want the narrower set can pass
			// explicit inherit_keys.
			$default_keys = [
				// Astra layout overrides (per-post).
				'site-post-title',
				'site-sidebar-layout',
				'site-content-layout',
				'site-content-style',
				'site-sidebar-style',
				'ast-main-header-display',
				'ast-global-header-display',
				'ast-banner-title-visibility',
				'ast-breadcrumbs-content',
				'ast-featured-img',
				'footer-sml-layout',
				// WordPress / Elementor.
				'_wp_page_template',
				'_elementor_page_settings',
				'_elementor_template_type',
			];
			$keys = isset( $args['inherit_keys'] ) && is_array( $args['inherit_keys'] )
				? array_values( array_filter( array_map( 'strval', $args['inherit_keys'] ) ) )
				: $default_keys;
			foreach ( $keys as $key ) {
				$policy = IATO_MCP_Meta_Policy::check_write( $key, true );
				if ( is_wp_error( $policy ) ) {
					return $policy;
				}
				// Only the allowlisted _elementor_ keys may be copied; the element
				// tree and the other builder data go through the Elementor tools.
				$elementor_policy = IATO_MCP_Meta_Policy::check_elementor_meta_write( $key );
				if ( is_wp_error( $elementor_policy ) ) {
					return $elementor_policy;
				}
				// Administrator-only keys (Elementor's template type) are skipped
				// for other users rather than failing the whole call (1.13.0).
				$key_cap = IATO_MCP_Meta_Policy::capability_for_key( $key );
				if ( null !== $key_cap && is_wp_error( IATO_MCP_Auth::require_cap( $key_cap ) ) ) {
					$inherit_skipped[] = [ 'key' => $key, 'reason' => 'requires_' . $key_cap ];
					continue;
				}
				$source_value = get_post_meta( $inherit_source, $key, true );
				$target_value = get_post_meta( $post_id, $key, true );

				// No-op short-circuit: same value already present on target. Compared
				// serialised, so arrays are compared by content (a plain string cast
				// turns every array into "Array").
				if ( maybe_serialize( $source_value ) === maybe_serialize( $target_value ) ) {
					$inherit_skipped[] = [ 'key' => $key, 'reason' => 'noop' ];
					continue;
				}

				$before = ( '' === $target_value ) ? null : $target_value;
				$after  = $source_value;
				$entry  = [ 'key' => $key, 'before' => $before, 'after' => $after ];
				if ( ( is_array( $after ) || is_object( $after ) ) && ! IATO_MCP_Auth::unfiltered_html_allowed() ) {
					// Elementor keys by value shape; other keys: markup in the
					// strings that differ from the target's goes through wp_kses_post.
					$s              = IATO_MCP_Meta_Policy::is_elementor_key( $key )
						? IATO_MCP_Meta_Policy::sanitize_elementor_value( $key, $after, is_array( $target_value ) ? $target_value : null )
						: IATO_MCP_Meta_Policy::sanitize_array_value( $key, $after, is_array( $target_value ) ? $target_value : null );
					$entry['after'] = $s['value'];
					if ( ! empty( $s['changed'] ) ) {
						$entry['sanitized_paths'] = $s['changed'];
					}
				}
				$inherit_plan[] = $entry;
			}
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'           => true,
				'post_id'           => $post_id,
				'action'            => 'would_update',
				'json_valid'        => true,
				'inherit_planned'   => $inherit_plan,
				'inherited_skipped' => $inherit_skipped,
			] + IATO_MCP_Elementor_Adapter::sanitize_preview( $decoded, $stored_elements ) );
		}

		// The shared pipeline (IATO_MCP_Elementor_Adapter::write_pipeline): sanitise
		// what changed, Elementor's own save first, the plugin's write only when
		// Elementor did not store the tree, frontend fallback, persistence check.
		$pipeline = IATO_MCP_Elementor_Adapter::write_pipeline( $post_id, $decoded, $previous_revision, $stored_elements );
		if ( is_wp_error( $pipeline ) ) {
			return $pipeline;
		}
		$meta_persisted = (bool) $pipeline['meta_persisted'];

		// One receipt for the whole document, like update_elementor_patch. Before:
		// the stored tree as it was, or, when the post had none, the original
		// post content and builder flag (IATO_MCP_Rollback::no_elementor_envelope());
		// after: the revision hash of what was stored.
		$receipt = IATO_MCP_Change_Receipt::record(
			$post_id,
			'elementor_widget',
			'elementor:document:replace',
			$receipt_before,
			$pipeline['current_revision']
		);

		// Apply inherited meta writes (if any) after the main Elementor write succeeds.
		$inherit_receipts = [];
		if ( ! empty( $inherit_plan ) ) {
			$touched_elementor_meta = false;
			foreach ( $inherit_plan as $step ) {
				$key    = $step['key'];
				$before = $step['before'];
				$after  = $step['after'];
				if ( 0 === stripos( $key, '_elementor_' ) ) {
					IATO_MCP_Elementor_Adapter::write_elementor_meta( fn() => update_post_meta( $post_id, $key, wp_slash( $after ) ) );
					$touched_elementor_meta = true;
				} else {
					IATO_MCP_Auth::write_unlocked( fn() => update_post_meta( $post_id, $key, wp_slash( $after ) ) ); // slashed: update_post_meta() unslashes
				}
				$inherit_receipts[] = IATO_MCP_Change_Receipt::record( $post_id, 'post_meta', $key, $before, $after );
			}
			clean_post_cache( $post_id );
			if ( $touched_elementor_meta && class_exists( '\Elementor\Plugin' ) ) {
				$plugin = \Elementor\Plugin::$instance;
				if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
					$plugin->files_manager->clear_cache();
				}
			}
		}

		$response = [
			'post_id'              => $post_id,
			'success'              => $meta_persisted,
			'regenerated'          => null !== $pipeline['elementor_save'],
			'content_updated'      => $pipeline['content_updated'],
			'post_content_length'  => $pipeline['post_content_length'],
			'meta_persisted'       => $meta_persisted,
			'meta_length'          => $pipeline['meta_length'],
			'input_length'         => strlen( $elementor_data ),
			'current_revision'     => $pipeline['current_revision'],
		] + IATO_MCP_Elementor_Adapter::pipeline_report( $pipeline );
		$response['change_receipt'] = [
			'change_id'   => $receipt['change_id'],
			'target_type' => $receipt['target_type'],
			'field'       => $receipt['field'],
			'applied_at'  => $receipt['applied_at'],
			'actor'       => $receipt['actor'] ?? null,
		];
		$inherit_sanitized = array_merge( ...array_map( fn( $st ) => $st['sanitized_paths'] ?? [], $inherit_plan ?: [ [] ] ) );
		if ( ! empty( $inherit_sanitized ) ) {
			$response['inherited_sanitized_paths'] = $inherit_sanitized;
		}
		if ( ! empty( $inherit_receipts ) ) {
			$response['change_receipts'] = $inherit_receipts;
		}
		if ( ! empty( $inherit_skipped ) ) {
			$response['inherited_skipped'] = $inherit_skipped;
		}
		return IATO_MCP_Server::ok( $response );
	}
);
