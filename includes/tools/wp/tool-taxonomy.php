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

		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$result = [];
		foreach ( $terms as $term ) {
			$result[] = [
				'id'        => $term->term_id,
				'name'      => $term->name,
				'slug'      => $term->slug,
				'count'     => (int) $term->count,
				'parent_id' => (int) $term->parent,
			];
		}

		return IATO_MCP_Server::ok( [ 'terms' => $result ] );
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

		if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
			return new WP_Error( 'invalid_taxonomy', 'taxonomy must be category or post_tag' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'not_found', 'Term not found.' );
		}

		$result = wp_set_post_terms( $post_id, [ $term_id ], $taxonomy, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return IATO_MCP_Server::ok( [
			'post_id'  => $post_id,
			'term_id'  => $term_id,
			'term_name' => $term->name,
			'taxonomy' => $taxonomy,
		] );
	}
);
