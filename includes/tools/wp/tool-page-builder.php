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
		'description' => 'Detects which page builder a post or page uses: elementor, wpbakery, divi, gutenberg, or classic.',
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
		'description' => 'Updates the _elementor_data JSON for a post, clears Elementor CSS cache, and regenerates rendered post_content. Supports dry_run. Requires edit_posts capability.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'             => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'elementor_data' => [ 'type' => 'string',  'description' => 'Full Elementor JSON data string (required).' ],
				'dry_run'        => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
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

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'  => true,
				'post_id'  => $post_id,
				'action'   => 'would_update',
				'json_valid' => true,
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

		return IATO_MCP_Server::ok( [
			'post_id'              => $post_id,
			'success'              => $meta_persisted,
			'regenerated'          => $regenerated,
			'content_updated'      => $content_updated,
			'post_content_length'  => strlen( $new_content ),
			'meta_persisted'       => $meta_persisted,
			'meta_length'          => strlen( $persisted_meta ),
			'input_length'         => strlen( $elementor_data ),
		] );
	}
);
