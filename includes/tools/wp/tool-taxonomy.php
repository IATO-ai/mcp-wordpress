<?php
/**
 * WP Tools: get_terms, assign_term, create_term, update_term, delete_term
 *
 * get_terms    — read only
 * assign_term  — requires edit_posts
 * create_term  — requires manage_categories
 * update_term  — requires manage_categories
 * delete_term  — requires manage_categories
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

// ── create_term ──────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'create_term',
	[
		'description' => 'Create a new category or tag. Categories support hierarchical parent. Requires manage_categories capability.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'taxonomy'    => [ 'type' => 'string',  'description' => 'category or post_tag (required).' ],
				'name'        => [ 'type' => 'string',  'description' => 'Term name (required).' ],
				'slug'        => [ 'type' => 'string',  'description' => 'URL slug (auto-generated from name if omitted).' ],
				'description' => [ 'type' => 'string',  'description' => 'Term description.' ],
				'parent'      => [ 'type' => 'integer', 'description' => 'Parent term ID (categories only, 0 for top-level).' ],
			],
			'required' => [ 'taxonomy', 'name' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_categories' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$taxonomy = sanitize_text_field( $args['taxonomy'] ?? '' );
		$name     = sanitize_text_field( $args['name'] ?? '' );

		if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
			return new WP_Error( 'invalid_taxonomy', 'taxonomy must be category or post_tag.' );
		}
		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', 'name is required.' );
		}

		$term_args = [];
		if ( isset( $args['slug'] ) ) {
			$term_args['slug'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['description'] ) ) {
			$term_args['description'] = sanitize_textarea_field( $args['description'] );
		}
		if ( isset( $args['parent'] ) && 'category' === $taxonomy ) {
			$term_args['parent'] = absint( $args['parent'] );
		}

		$result = wp_insert_term( $name, $taxonomy, $term_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], $taxonomy );

		return IATO_MCP_Server::ok( [
			'term_id'   => $result['term_id'],
			'name'      => $term->name,
			'slug'      => $term->slug,
			'taxonomy'  => $taxonomy,
			'parent_id' => (int) $term->parent,
		] );
	}
);

// ── update_term ──────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_term',
	[
		'description' => 'Update an existing category or tag. Only provided fields are changed. Requires manage_categories capability.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'taxonomy'    => [ 'type' => 'string',  'description' => 'category or post_tag (required).' ],
				'term_id'     => [ 'type' => 'integer', 'description' => 'Term ID to update (required).' ],
				'name'        => [ 'type' => 'string',  'description' => 'New term name.' ],
				'slug'        => [ 'type' => 'string',  'description' => 'New URL slug.' ],
				'description' => [ 'type' => 'string',  'description' => 'New term description.' ],
				'parent'      => [ 'type' => 'integer', 'description' => 'New parent term ID (categories only, 0 for top-level).' ],
			],
			'required' => [ 'taxonomy', 'term_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_categories' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$taxonomy = sanitize_text_field( $args['taxonomy'] ?? '' );
		$term_id  = absint( $args['term_id'] ?? 0 );

		if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
			return new WP_Error( 'invalid_taxonomy', 'taxonomy must be category or post_tag.' );
		}
		if ( ! $term_id ) {
			return new WP_Error( 'missing_term_id', 'term_id is required.' );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'not_found', 'Term not found.' );
		}

		$term_args = [];
		if ( isset( $args['name'] ) ) {
			$term_args['name'] = sanitize_text_field( $args['name'] );
		}
		if ( isset( $args['slug'] ) ) {
			$term_args['slug'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['description'] ) ) {
			$term_args['description'] = sanitize_textarea_field( $args['description'] );
		}
		if ( isset( $args['parent'] ) && 'category' === $taxonomy ) {
			$term_args['parent'] = absint( $args['parent'] );
		}

		if ( empty( $term_args ) ) {
			return new WP_Error( 'no_changes', 'No fields provided to update.' );
		}

		$result = wp_update_term( $term_id, $taxonomy, $term_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_term( $result['term_id'], $taxonomy );

		return IATO_MCP_Server::ok( [
			'term_id'   => $updated->term_id,
			'name'      => $updated->name,
			'slug'      => $updated->slug,
			'taxonomy'  => $taxonomy,
			'parent_id' => (int) $updated->parent,
		] );
	}
);

// ── delete_term ──────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'delete_term',
	[
		'description' => 'Delete a category or tag by term ID. Posts assigned to the term will be unassigned. Requires manage_categories capability.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'taxonomy' => [ 'type' => 'string',  'description' => 'category or post_tag (required).' ],
				'term_id'  => [ 'type' => 'integer', 'description' => 'Term ID to delete (required).' ],
			],
			'required' => [ 'taxonomy', 'term_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_categories' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$taxonomy = sanitize_text_field( $args['taxonomy'] ?? '' );
		$term_id  = absint( $args['term_id'] ?? 0 );

		if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
			return new WP_Error( 'invalid_taxonomy', 'taxonomy must be category or post_tag.' );
		}
		if ( ! $term_id ) {
			return new WP_Error( 'missing_term_id', 'term_id is required.' );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'not_found', 'Term not found.' );
		}

		$name   = $term->name;
		$result = wp_delete_term( $term_id, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new WP_Error( 'delete_failed', 'Cannot delete the default category.' );
		}

		return IATO_MCP_Server::ok( [
			'term_id'  => $term_id,
			'name'     => $name,
			'taxonomy' => $taxonomy,
			'deleted'  => true,
		] );
	}
);
