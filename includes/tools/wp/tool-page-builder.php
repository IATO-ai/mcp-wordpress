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
		'description' => 'Returns the raw _elementor_data JSON and edit mode for a post. Use get_page_builder first to confirm the post uses Elementor.',
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

		$data      = get_post_meta( $post_id, '_elementor_data', true );
		$edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );

		if ( empty( $data ) ) {
			return new WP_Error( 'no_elementor_data', 'No Elementor data found for this post. Check with get_page_builder first.' );
		}

		return IATO_MCP_Server::ok( [
			'post_id'        => $post_id,
			'elementor_data' => $data,
			'edit_mode'      => $edit_mode,
		] );
	}
);

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
		$decoded = json_decode( $elementor_data );
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

		// Update the meta.
		update_post_meta( $post_id, '_elementor_data', wp_slash( $elementor_data ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		// Clear Elementor CSS cache for this post so frontend reflects changes.
		delete_post_meta( $post_id, '_elementor_css' );

		// Regenerate rendered post_content from Elementor data.
		// This keeps post_content in sync for search / RSS / fallback.
		$regenerated = false;
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );
			if ( $document ) {
				$document->save( [ 'elements' => $decoded ] );
				$regenerated = true;
			}
		}

		return IATO_MCP_Server::ok( [
			'post_id'     => $post_id,
			'success'     => true,
			'regenerated' => $regenerated,
		] );
	}
);
