<?php
/**
 * WP Tools: get_posts, get_post, create_post, update_post, search_posts
 *
 * All write tools require edit_posts capability.
 * get_posts / get_post / search_posts require read only.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_posts ─────────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_posts',
	[
		'description' => 'List posts or pages with optional filters. Returns ID, title, slug, status, URL, and modified date.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_type'   => [ 'type' => 'string',  'description' => 'post|page|any (default: post)' ],
				'status'      => [ 'type' => 'string',  'description' => 'publish|draft|any (default: publish)' ],
				'per_page'    => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default: 20)' ],
				'page'        => [ 'type' => 'integer', 'description' => 'Page number (default: 1)' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_type = sanitize_text_field( $args['post_type'] ?? 'post' );
		$status    = sanitize_text_field( $args['status']    ?? 'publish' );
		$per_page  = min( absint( $args['per_page'] ?? 20 ), 100 );
		$page      = max( absint( $args['page']     ?? 1 ), 1 );

		$query_args = [
			'post_type'      => 'any' === $post_type ? [ 'post', 'page' ] : $post_type,
			'post_status'    => 'any' === $status ? [ 'publish', 'draft', 'pending', 'private' ] : $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];

		$query = new WP_Query( $query_args );
		$posts = [];

		foreach ( $query->posts as $post ) {
			$posts[] = [
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'slug'     => $post->post_name,
				'status'   => $post->post_status,
				'url'      => get_permalink( $post ),
				'modified' => $post->post_modified_gmt,
			];
		}

		return IATO_MCP_Server::ok( [
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		] );
	}
);

// ── get_post ──────────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_post',
	[
		'description' => 'Get full details for a single post or page by ID or slug.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'   => [ 'type' => 'integer', 'description' => 'Post ID' ],
				'slug' => [ 'type' => 'string',  'description' => 'Post slug (used if id not provided)' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$post = null;

		if ( ! empty( $args['id'] ) ) {
			$post = get_post( absint( $args['id'] ) );
		} elseif ( ! empty( $args['slug'] ) ) {
			$slug  = sanitize_text_field( $args['slug'] );
			$found = get_posts( [
				'name'           => $slug,
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'any',
				'posts_per_page' => 1,
			] );
			$post = $found[0] ?? null;
		}

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$categories = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
		$tags       = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );

		return IATO_MCP_Server::ok( [
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'slug'       => $post->post_name,
			'content'    => $post->post_content,
			'excerpt'    => $post->post_excerpt,
			'status'     => $post->post_status,
			'url'        => get_permalink( $post ),
			'author'     => (int) $post->post_author,
			'date'       => $post->post_date_gmt,
			'modified'   => $post->post_modified_gmt,
			'categories' => is_array( $categories ) ? $categories : [],
			'tags'       => is_array( $tags ) ? $tags : [],
		] );
	}
);

// ── create_post ───────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'create_post',
	[
		'description' => 'Create a new post or page. Returns the new post ID and URL.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'title'     => [ 'type' => 'string', 'description' => 'Post title (required)' ],
				'content'   => [ 'type' => 'string', 'description' => 'Post content (HTML or plain text)' ],
				'status'    => [ 'type' => 'string', 'description' => 'draft|publish (default: draft)' ],
				'post_type' => [ 'type' => 'string', 'description' => 'post|page (default: post)' ],
			],
			'required' => [ 'title' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$post_type = sanitize_text_field( $args['post_type'] ?? 'post' );
		if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
			return new WP_Error( 'invalid_post_type', 'post_type must be post or page.' );
		}

		$postarr = [
			'post_title'   => sanitize_text_field( $args['title'] ),
			'post_content' => wp_kses_post( $args['content'] ?? '' ),
			'post_status'  => in_array( $args['status'] ?? 'draft', [ 'draft', 'publish' ], true )
				? $args['status']
				: 'draft',
			'post_type'    => $post_type,
		];

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return IATO_MCP_Server::ok( [
			'id'  => $post_id,
			'url' => get_permalink( $post_id ),
		] );
	}
);

// ── update_post ───────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_post',
	[
		'description' => 'Update an existing post title, content, or status.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer', 'description' => 'Post ID to update (required)' ],
				'title'   => [ 'type' => 'string',  'description' => 'New title' ],
				'content' => [ 'type' => 'string',  'description' => 'New content' ],
				'status'  => [ 'type' => 'string',  'description' => 'New status: draft|publish' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$post_id = absint( $args['id'] );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$postarr = [ 'ID' => $post_id ];

		if ( isset( $args['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $args['content'] );
		}
		if ( isset( $args['status'] ) && in_array( $args['status'], [ 'draft', 'publish' ], true ) ) {
			$postarr['post_status'] = $args['status'];
		}

		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return IATO_MCP_Server::ok( [
			'id'       => $post_id,
			'url'      => get_permalink( $post_id ),
			'modified' => get_post( $post_id )->post_modified_gmt,
		] );
	}
);

// ── search_posts ──────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'search_posts',
	[
		'description' => 'Full-text search across posts and pages. Returns matching posts with title, slug, and URL.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'query'    => [ 'type' => 'string',  'description' => 'Search query (required)' ],
				'per_page' => [ 'type' => 'integer', 'description' => 'Max results (default: 20)' ],
			],
			'required' => [ 'query' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$search   = sanitize_text_field( $args['query'] );
		$per_page = min( absint( $args['per_page'] ?? 20 ), 100 );

		$query = new WP_Query( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			's'              => $search,
			'posts_per_page' => $per_page,
			'orderby'        => 'relevance',
		] );

		$posts = [];
		foreach ( $query->posts as $post ) {
			$posts[] = [
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'slug'  => $post->post_name,
				'url'   => get_permalink( $post ),
				'type'  => $post->post_type,
			];
		}

		return IATO_MCP_Server::ok( [
			'posts' => $posts,
			'total' => (int) $query->found_posts,
		] );
	}
);
