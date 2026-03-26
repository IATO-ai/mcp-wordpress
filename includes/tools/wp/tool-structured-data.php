<?php
/**
 * WP Tool: update_structured_data
 *
 * Stores JSON-LD structured data as post meta and outputs it in wp_head.
 * Uses a custom meta key (_iato_mcp_structured_data) to avoid SEO plugin conflicts.
 * Includes write-with-rollback support (change receipt).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── Output JSON-LD in wp_head when meta exists ──────────────────────────────

add_action( 'wp_head', function () {
	if ( ! is_singular() ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$schema = get_post_meta( $post_id, '_iato_mcp_structured_data', true );
	if ( empty( $schema ) ) {
		return;
	}

	// Validate that it's proper JSON before outputting.
	$decoded = json_decode( $schema );
	if ( null === $decoded ) {
		return;
	}

	echo '<script type="application/ld+json">' . wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
} );

// ── update_structured_data ──────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_structured_data',
	[
		'description' => 'Set or update JSON-LD structured data for a post or page. Stored as post meta and output in wp_head.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer', 'description' => 'Post ID (required).' ],
				'schema_json' => [ 'type' => 'string',  'description' => 'Valid JSON-LD string (required).' ],
				'dry_run'     => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'post_id', 'schema_json' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$post_id     = absint( $args['post_id'] ?? 0 );
		$schema_json = $args['schema_json'] ?? '';
		$dry_run     = ! empty( $args['dry_run'] );

		if ( ! $post_id ) return new WP_Error( 'missing_post_id', 'post_id is required.' );
		if ( empty( $schema_json ) ) return new WP_Error( 'missing_schema_json', 'schema_json is required.' );

		// Validate JSON.
		$decoded = json_decode( $schema_json );
		if ( null === $decoded && 'null' !== strtolower( trim( $schema_json ) ) ) {
			return new WP_Error( 'invalid_json', 'schema_json is not valid JSON.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$before = get_post_meta( $post_id, '_iato_mcp_structured_data', true );
		$before = '' !== $before ? $before : null;

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'      => true,
				'post_id'      => $post_id,
				'before_value' => $before,
				'schema_json'  => $schema_json,
			] );
		}

		// Normalize: store as compact JSON.
		$normalized = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		update_post_meta( $post_id, '_iato_mcp_structured_data', $normalized );

		$receipt = IATO_MCP_Change_Receipt::record( $post_id, 'page', 'structured_data', $before, $normalized );

		$data = [
			'post_id'     => $post_id,
			'schema_json' => $normalized,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);
