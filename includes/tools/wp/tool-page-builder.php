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
		'description' => 'Detects which page builder a post or page uses: elementor, wpbakery, divi, beaver-builder, gutenberg, or classic.',
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

		$content = $post->post_content;

		// Elementor.
		if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder' ) {
			return IATO_MCP_Server::ok( [
				'post_id'  => $post_id,
				'builder'  => 'elementor',
				'has_data' => ! empty( get_post_meta( $post_id, '_elementor_data', true ) ),
			] );
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
		'description' => 'Returns Elementor data for a post. format=raw (default) returns the original stored JSON; format=compact decodes and strips default-valued settings; format=summary returns a tree of {widget_id, type, peek_fields}. Always includes the revision hash for use with v2 if_revision guards.',
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

		if ( empty( $elementor_data ) ) {
			return new WP_Error( 'missing_data', 'elementor_data is required.' );
		}

		// Validate JSON.
		$decoded = json_decode( $elementor_data, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', 'Invalid JSON: ' . json_last_error_msg() );
		}

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
				$source_value = get_post_meta( $inherit_source, $key, true );
				$target_value = get_post_meta( $post_id, $key, true );

				// No-op short-circuit: same value already present on target.
				// Distinguish by string-compare (covers serialized arrays too).
				if ( (string) $source_value === (string) $target_value ) {
					$inherit_skipped[] = [ 'key' => $key, 'reason' => 'noop' ];
					continue;
				}

				$before = ( '' === $target_value ) ? null : $target_value;
				$inherit_plan[] = [ 'key' => $key, 'before' => $before, 'after' => $source_value ];
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
			] );
		}

		// Capture old post_content for comparison after save.
		$old_content = get_post_field( 'post_content', $post_id );

		// 1. Write _elementor_data meta directly — Document->save() does NOT
		//    persist the 'elements' parameter to meta; it only uses them
		//    temporarily for rendering. Without this explicit write, meta
		//    stays unchanged and post_content regenerates from stale data.
		update_post_meta( $post_id, '_elementor_data', wp_slash( $elementor_data ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		// 2. Clear all caches so Document->save() reads the fresh meta.
		delete_post_meta( $post_id, '_elementor_css' );
		wp_cache_delete( $post_id, 'post_meta' );
		wp_cache_delete( $post_id, 'posts' );

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
				$plugin->files_manager->clear_cache();
			}
		}

		// 3. Call Document->save() for rendering post_content and rebuilding CSS.
		//    Elementor's AI module filter (remove_temporary_containers) expects
		//    array elements, not stdClass — force_arrays ensures this.
		$regenerated = false;
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );
			if ( $document ) {
				$force_arrays = function ( $data ) use ( &$force_arrays ) {
					if ( is_object( $data ) ) {
						$data = (array) $data;
					}
					if ( is_array( $data ) ) {
						return array_map( $force_arrays, $data );
					}
					return $data;
				};

				$decoded = $force_arrays( $decoded );

				$document->save( [ 'elements' => $decoded ] );
				$regenerated = true;
			}
		}

		// 4. Check if post_content actually changed.
		wp_cache_delete( $post_id, 'posts' );
		wp_cache_delete( $post_id, 'post_meta' );
		$new_content     = get_post_field( 'post_content', $post_id );
		$content_updated = ( $new_content !== $old_content );

		// 5. If Document->save() didn't update post_content, force render via Frontend.
		if ( $regenerated && ! $content_updated && class_exists( '\Elementor\Plugin' ) ) {
			$frontend = \Elementor\Plugin::instance()->frontend;
			if ( $frontend && method_exists( $frontend, 'get_builder_content' ) ) {
				$rendered = $frontend->get_builder_content( $post_id, true );
				if ( ! empty( $rendered ) && $rendered !== $old_content ) {
					wp_update_post( [
						'ID'           => $post_id,
						'post_content' => $rendered,
					] );
					$new_content     = $rendered;
					$content_updated = true;
				}
			}
		}

		// 6. Verify meta actually persisted — catch silent write failures.
		wp_cache_delete( $post_id, 'post_meta' );
		$persisted_meta = get_post_meta( $post_id, '_elementor_data', true );
		$meta_persisted = ( strlen( $persisted_meta ) === strlen( $elementor_data ) );

		// Apply inherited meta writes (if any) after the main Elementor write succeeds.
		$inherit_receipts = [];
		if ( ! empty( $inherit_plan ) ) {
			$touched_elementor_meta = false;
			foreach ( $inherit_plan as $step ) {
				$key    = $step['key'];
				$before = $step['before'];
				$after  = $step['after'];
				update_post_meta( $post_id, $key, $after );
				$inherit_receipts[] = IATO_MCP_Change_Receipt::record( $post_id, 'post_meta', $key, $before, $after );
				if ( 0 === stripos( $key, '_elementor_' ) ) {
					$touched_elementor_meta = true;
				}
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
			'regenerated'          => $regenerated,
			'content_updated'      => $content_updated,
			'post_content_length'  => strlen( $new_content ),
			'meta_persisted'       => $meta_persisted,
			'meta_length'          => strlen( $persisted_meta ),
			'input_length'         => strlen( $elementor_data ),
		];
		if ( ! empty( $inherit_receipts ) ) {
			$response['change_receipts'] = $inherit_receipts;
		}
		if ( ! empty( $inherit_skipped ) ) {
			$response['inherited_skipped'] = $inherit_skipped;
		}
		return IATO_MCP_Server::ok( $response );
	}
);
