<?php
/**
 * WP Tool: update_canonical
 *
 * Sets or updates the canonical URL for a post via the active SEO plugin.
 * Includes write-with-rollback support (change receipt).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'update_canonical',
	[
		'description' => 'Set or update the canonical URL for a post or page. Works with Yoast, RankMath, or SEOPress.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_id'       => [ 'type' => 'integer', 'description' => 'Post ID (required).' ],
				'canonical_url' => [ 'type' => 'string',  'description' => 'Canonical URL (required).' ],
				'dry_run'       => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'post_id', 'canonical_url' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$post_id       = absint( $args['post_id'] ?? 0 );
		$canonical_url = esc_url_raw( $args['canonical_url'] ?? '' );
		$dry_run       = ! empty( $args['dry_run'] );

		if ( ! $post_id ) return new WP_Error( 'missing_post_id', 'post_id is required.' );
		if ( empty( $canonical_url ) ) return new WP_Error( 'missing_canonical_url', 'canonical_url is required.' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$before = IATO_MCP_SEO_Adapter::get_canonical( $post_id );
		$before = '' !== $before ? $before : null;

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'       => true,
				'post_id'       => $post_id,
				'before_value'  => $before,
				'canonical_url' => $canonical_url,
			] );
		}

		$result = IATO_MCP_SEO_Adapter::update_canonical( $post_id, $canonical_url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$receipt = IATO_MCP_Change_Receipt::record( $post_id, 'page', 'canonical_url', $before, $canonical_url );

		$data = [
			'post_id'       => $post_id,
			'canonical_url' => $canonical_url,
			'plugin'        => IATO_MCP_SEO_Adapter::get_meta( $post_id )['plugin'],
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);
