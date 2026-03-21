<?php
/**
 * WP Tools: get_terms, assign_term
 *
 * get_terms   — read only
 * assign_term — requires edit_posts
 *
 * Used by get_iato_taxonomy bridge tool to map IATO labels → WP term IDs.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_terms ─────────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_terms',
	[
		'description' => 'List WordPress categories or tags with their IDs, slugs, and post counts.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'taxonomy' => [ 'type' => 'string', 'description' => 'category|post_tag (default: category)' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$taxonomy = sanitize_text_field( $args['taxonomy'] ?? 'category' );
		if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
			return new WP_Error( 'invalid_taxonomy', 'taxonomy must be category or post_tag' );
		}

		// TODO: implement — get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false])
		// Return [{id, name, slug, count, parent_id}]
		return new WP_Error( 'not_implemented', 'get_terms not yet implemented' );
	}
);

// ── assign_term ───────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'assign_term',
	[
		'description' => 'Assign a category or tag to a post. Adds to existing terms (does not replace).',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_id'  => [ 'type' => 'integer', 'description' => 'Post ID (required)' ],
				'term_id'  => [ 'type' => 'integer', 'description' => 'Term ID to assign (required)' ],
				'taxonomy' => [ 'type' => 'string',  'description' => 'category|post_tag (default: category)' ],
			],
			'required' => [ 'post_id', 'term_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$post_id  = absint( $args['post_id'] ?? 0 );
		$term_id  = absint( $args['term_id'] ?? 0 );
		$taxonomy = sanitize_text_field( $args['taxonomy'] ?? 'category' );

		if ( ! $post_id || ! $term_id ) return new WP_Error( 'missing_args', 'post_id and term_id required' );

		// TODO: implement — wp_set_post_terms($post_id, [$term_id], $taxonomy, $append = true)
		return new WP_Error( 'not_implemented', 'assign_term not yet implemented' );
	}
);
