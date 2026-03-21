<?php
/**
 * WP Tools: get_seo_data, update_seo_data
 *
 * Reads and writes SEO title + description via IATO_MCP_SEO_Adapter,
 * which handles Yoast / RankMath / SEOPress / fallback transparently.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_seo_data ──────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_seo_data',
	[
		'description' => 'Get the SEO title and meta description for a post or page.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Post ID (required)' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] ?? 0 );
		if ( ! $post_id ) return new WP_Error( 'missing_id', 'Post ID required' );

		// TODO: implement — IATO_MCP_SEO_Adapter::get_meta($post_id)
		return new WP_Error( 'not_implemented', 'get_seo_data not yet implemented' );
	}
);

// ── update_seo_data ───────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_seo_data',
	[
		'description' => 'Update the SEO title and/or meta description for a post. Works with Yoast, RankMath, SEOPress, or native WP.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'integer', 'description' => 'Post ID (required)' ],
				'title'       => [ 'type' => 'string',  'description' => 'New SEO title' ],
				'description' => [ 'type' => 'string',  'description' => 'New meta description' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$post_id = absint( $args['id'] ?? 0 );
		if ( ! $post_id ) return new WP_Error( 'missing_id', 'Post ID required' );

		// TODO: implement
		// if isset($args['title'])       IATO_MCP_SEO_Adapter::update_title($post_id, $args['title'])
		// if isset($args['description']) IATO_MCP_SEO_Adapter::update_description($post_id, $args['description'])
		// Return what was updated
		return new WP_Error( 'not_implemented', 'update_seo_data not yet implemented' );
	}
);
