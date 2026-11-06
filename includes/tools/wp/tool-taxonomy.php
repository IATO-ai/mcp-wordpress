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

/**
 * A term's name or description as WordPress will store it, for a dry run: the save
 * filters turn & into &amp;, and wp_insert_term() compares that form.
 */
function iato_mcp_term_field_as_stored( string $field, string $value, string $taxonomy ): string {
	return (string) wp_unslash( sanitize_term_field( $field, wp_slash( $value ), 0, $taxonomy, 'db' ) );
}

/**
 * The term wp_insert_term() would refuse a new one over, by its own rule: a slug asked
 * for can make a repeated name acceptable, and on a hierarchical taxonomy only siblings
 * count.
 *
 * @return int|null The existing term's ID, or null when the insert would go ahead.
 */
function iato_mcp_term_insert_conflict( string $name, string $slug, bool $slug_given, int $parent, string $taxonomy ): ?int {
	$matches = get_terms( [ 'taxonomy' => $taxonomy, 'name' => $name, 'hide_empty' => false, 'parent' => $parent, 'update_term_meta_cache' => false ] );
	$match   = null;
	foreach ( is_array( $matches ) ? $matches : [] as $candidate ) {
		if ( strtolower( $name ) === strtolower( $candidate->name ) ) {
			$match = $candidate;
			break;
		}
	}
	if ( null === $match ) {
		return null;
	}
	$slug_match = get_term_by( 'slug', $slug, $taxonomy );
	if ( $slug_given && $match->slug !== $slug && ! $slug_match ) {
		return null;
	}
	if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
		return (int) $match->term_id;
	}
	$siblings = get_terms( [ 'taxonomy' => $taxonomy, 'get' => 'all', 'parent' => $parent, 'update_term_meta_cache' => false ] );
	$siblings = is_array( $siblings ) ? $siblings : [];
	if ( ( ! $slug_given || $match->slug === $slug ) && in_array( $name, wp_list_pluck( $siblings, 'name' ), true ) ) {
		return (int) $match->term_id;
	}
	return $slug_match && in_array( $slug, wp_list_pluck( $siblings, 'slug' ), true ) ? (int) $slug_match->term_id : null;
}

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
				'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
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

		$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}
		$assign_check = IATO_MCP_Auth::require_cap( IATO_MCP_Auth::taxonomy_cap( $taxonomy, 'assign_terms' ) );
		if ( is_wp_error( $assign_check ) ) return $assign_check;

		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) || ! $term ) {
			return new WP_Error( 'not_found', 'Term not found.' );
		}

		// Capture before for change receipt.
		$before_ids = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $before_ids ) ) {
			$before_ids = [];
		}

		if ( ! empty( $args['dry_run'] ) ) {
			$before_ids = array_map( 'intval', $before_ids );
			return IATO_MCP_Server::ok( [
				'dry_run'     => true,
				'action'      => in_array( $term_id, $before_ids, true ) ? 'no_change' : 'would_assign',
				'post_id'     => $post_id,
				'term_id'     => $term_id,
				'term_name'   => $term->name,
				'taxonomy'    => $taxonomy,
				'before_ids'  => $before_ids,
				'after_ids'   => array_values( array_unique( array_merge( $before_ids, [ $term_id ] ) ) ),
				'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED,
			] );
		}

		$result = wp_set_post_terms( $post_id, [ $term_id ], $taxonomy, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$after_ids = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $after_ids ) ) {
			$after_ids = [];
		}

		$receipt = IATO_MCP_Change_Receipt::record( $post_id, 'taxonomy', 'assign', $before_ids, $after_ids );

		$data = [
			'post_id'   => $post_id,
			'term_id'   => $term_id,
			'term_name' => $term->name,
			'taxonomy'  => $taxonomy,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
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
				'dry_run'     => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
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

		$manage_check = IATO_MCP_Auth::require_cap( IATO_MCP_Auth::taxonomy_cap( $taxonomy, 'manage_terms' ) );
		if ( is_wp_error( $manage_check ) ) return $manage_check;

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

		if ( ! empty( $args['dry_run'] ) ) {
			$parent = (int) ( $term_args['parent'] ?? 0 );
			if ( $parent > 0 && ! term_exists( $parent ) ) {
				return new WP_Error( 'missing_parent', 'Parent term does not exist.' );
			}
			$stored_name = iato_mcp_term_field_as_stored( 'name', $name, $taxonomy );
			$slug_given  = ! empty( $term_args['slug'] );
			$slug        = $slug_given ? $term_args['slug'] : sanitize_title( $stored_name );
			$existing    = iato_mcp_term_insert_conflict( $stored_name, $slug, $slug_given, $parent, $taxonomy );
			if ( null !== $existing ) {
				return new WP_Error( 'term_exists', 'A term with the name provided already exists with this parent.', (int) $existing );
			}
			return IATO_MCP_Server::ok( [
				'dry_run'     => true,
				'action'      => 'would_create',
				'name'        => $stored_name,
				'slug'        => $slug,
				'taxonomy'    => $taxonomy,
				'parent_id'   => $parent,
				'description' => $term_args['description'] ?? '',
				'not_checked' => array_merge( IATO_MCP_Server::DRY_RUN_NOT_CHECKED, [ 'A slug already used by another term, which WordPress would change by adding a suffix.' ] ),
			] );
		}

		// Slashed: wp_insert_term() unslashes the name and description.
		$result = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $term_args ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], $taxonomy );

		$receipt = IATO_MCP_Change_Receipt::record(
			null, 'taxonomy', 'create_term', null,
			wp_json_encode( [ 'term_id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'taxonomy' => $taxonomy, 'parent' => (int) $term->parent ] )
		);

		$data = [
			'term_id'   => $result['term_id'],
			'name'      => $term->name,
			'slug'      => $term->slug,
			'taxonomy'  => $taxonomy,
			'parent_id' => (int) $term->parent,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
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
				'dry_run'     => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
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

		$object_check = IATO_MCP_Auth::require_cap( 'edit_term', $term_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
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

		if ( ! empty( $args['dry_run'] ) ) {
			if ( isset( $term_args['name'] ) && '' === trim( $term_args['name'] ) ) {
				return new WP_Error( 'empty_term_name', 'A name is required for this term.' );
			}
			$new_parent = $term_args['parent'] ?? (int) $term->parent;
			if ( $new_parent > 0 && ! term_exists( $new_parent ) ) {
				return new WP_Error( 'missing_parent', 'Parent term does not exist.' );
			}
			// What wp_update_term() will do with each: its save filters run on the text,
			// an empty slug comes from the name, and a term cannot be its own parent.
			$planned = $term_args;
			foreach ( [ 'name', 'description' ] as $field ) {
				if ( isset( $planned[ $field ] ) ) {
					$planned[ $field ] = iato_mcp_term_field_as_stored( $field, $planned[ $field ], $taxonomy );
				}
			}
			if ( isset( $planned['slug'] ) && '' === $planned['slug'] ) {
				$planned['slug'] = sanitize_title( $planned['name'] ?? $term->name );
			} elseif ( isset( $planned['slug'] ) ) {
				$duplicate = get_term_by( 'slug', $planned['slug'], $taxonomy );
				if ( $duplicate && (int) $duplicate->term_id !== $term_id && $new_parent === (int) $duplicate->parent ) {
					return new WP_Error( 'duplicate_term_slug', sprintf( 'The slug "%s" is already in use by another term.', $planned['slug'] ) );
				}
			}
			if ( isset( $planned['parent'] ) && $planned['parent'] === $term_id ) {
				$planned['parent'] = 0;
			}
			$would = [];
			foreach ( $planned as $field => $after ) {
				$current = 'parent' === $field ? (int) $term->parent : $term->{$field};
				if ( $current !== $after ) {
					$would[ $field ] = [ 'before' => $current, 'after' => $after ];
				}
			}
			return IATO_MCP_Server::ok( [
				'dry_run'      => true,
				'action'       => [] === $would ? 'no_change' : 'would_update',
				'term_id'      => $term_id,
				'taxonomy'     => $taxonomy,
				'would_change' => (object) $would,
				'not_checked'  => array_merge( IATO_MCP_Server::DRY_RUN_NOT_CHECKED, [ 'A slug used by a term under another parent, which WordPress would change by adding a number', 'A parent that would create a loop, which WordPress would break by changing other terms' ] ),
			] );
		}

		// Snapshot before state.
		// term_id in both snapshots (1.13.0): rollback restores that term, and the
		// conflict check reads it back. Earlier receipts lacked it, so their
		// rollback never found the term.
		$before_snapshot = wp_json_encode( [ 'term_id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => (int) $term->parent, 'taxonomy' => $taxonomy ] );

		// Slashed: wp_update_term() unslashes the name and description.
		$result = wp_update_term( $term_id, $taxonomy, wp_slash( $term_args ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_term( $result['term_id'], $taxonomy );

		$after_snapshot = wp_json_encode( [ 'term_id' => (int) $updated->term_id, 'name' => $updated->name, 'slug' => $updated->slug, 'description' => $updated->description, 'parent' => (int) $updated->parent, 'taxonomy' => $taxonomy ] );

		$receipt = IATO_MCP_Change_Receipt::record( null, 'taxonomy', 'update_term', $before_snapshot, $after_snapshot );

		$data = [
			'term_id'   => $updated->term_id,
			'name'      => $updated->name,
			'slug'      => $updated->slug,
			'taxonomy'  => $taxonomy,
			'parent_id' => (int) $updated->parent,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
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
				'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
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

		$object_check = IATO_MCP_Auth::require_cap( 'delete_term', $term_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}

		if ( ! empty( $args['dry_run'] ) ) {
			if ( 'category' === $taxonomy && (int) get_option( 'default_category' ) === $term_id ) {
				return new WP_Error( 'delete_failed', 'Cannot delete the default category.' );
			}
			return IATO_MCP_Server::ok( [
				'dry_run'     => true,
				'action'      => 'would_delete',
				'term_id'     => $term_id,
				'name'        => $term->name,
				'taxonomy'    => $taxonomy,
				'post_count'  => (int) $term->count,
				'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED,
			] );
		}

		// Snapshot before deletion for rollback.
		$before_snapshot = wp_json_encode( [ 'term_id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => (int) $term->parent, 'taxonomy' => $taxonomy ] );

		$name   = $term->name;
		$result = wp_delete_term( $term_id, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// 1.16.0: core returns 0, not false, for the default category. Before this, a
		// site without a bound key reported it deleted and recorded a receipt.
		if ( 0 === $result ) {
			return new WP_Error( 'delete_failed', 'Cannot delete the default category.' );
		}
		if ( false === $result ) {
			return new WP_Error( 'delete_failed', 'The term could not be deleted.' );
		}

		$receipt = IATO_MCP_Change_Receipt::record( null, 'taxonomy', 'delete_term', $before_snapshot, null );

		$data = [
			'term_id'  => $term_id,
			'name'     => $name,
			'taxonomy' => $taxonomy,
			'deleted'  => true,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);

// ── update_taxonomy (set all terms for a post — replaces, not appends) ──────

IATO_MCP_Server::register_tool(
	'update_taxonomy',
	[
		'description' => 'Set the full list of categories or tags for a post, replacing all existing assignments. Use assign_term to add without replacing.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_id'  => [ 'type' => 'integer', 'description' => 'Post ID (required).' ],
				'taxonomy' => [ 'type' => 'string',  'description' => 'category or post_tag (required).' ],
				'terms'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
					'description' => 'Array of term IDs to assign (required). Replaces all existing terms for this taxonomy.',
				],
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'post_id', 'taxonomy', 'terms' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$post_id  = absint( $args['post_id'] ?? 0 );
		$taxonomy = sanitize_text_field( $args['taxonomy'] ?? '' );
		$terms    = array_map( 'absint', $args['terms'] ?? [] );
		$dry_run  = ! empty( $args['dry_run'] );

		if ( ! $post_id ) return new WP_Error( 'missing_post_id', 'post_id is required.' );
		if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
			return new WP_Error( 'invalid_taxonomy', 'taxonomy must be category or post_tag.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}
		$assign_check = IATO_MCP_Auth::require_cap( IATO_MCP_Auth::taxonomy_cap( $taxonomy, 'assign_terms' ) );
		if ( is_wp_error( $assign_check ) ) return $assign_check;

		$before_ids = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $before_ids ) ) {
			$before_ids = [];
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'    => true,
				'post_id'    => $post_id,
				'taxonomy'   => $taxonomy,
				'before_ids' => $before_ids,
				'after_ids'  => $terms,
			] );
		}

		$result = wp_set_post_terms( $post_id, $terms, $taxonomy, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$after_ids = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $after_ids ) ) {
			$after_ids = $terms;
		}

		$receipt = IATO_MCP_Change_Receipt::record( $post_id, 'taxonomy', 'terms', $before_ids, $after_ids );

		$data = [
			'post_id'    => $post_id,
			'taxonomy'   => $taxonomy,
			'before_ids' => $before_ids,
			'after_ids'  => $after_ids,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);
