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
		'description' => 'Get full details for a single post or page by ID or slug. Pass include_shadowing=true to detect when an Elementor Theme Builder template overrides the slug-based render (small extra cost; default off to keep the hot path fast).',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'                => [ 'type' => 'integer', 'description' => 'Post ID' ],
				'slug'              => [ 'type' => 'string',  'description' => 'Post slug (used if id not provided)' ],
				'include_shadowing' => [ 'type' => 'boolean', 'description' => 'If true, attach is_shadowed_by when an Elementor Theme Builder template overrides this post (default: false).' ],
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

		$response = [
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
		];

		if ( ! empty( $args['include_shadowing'] ) && class_exists( 'IATO_MCP_Elementor_Router' ) ) {
			$shadowing = IATO_MCP_Elementor_Router::get_shadowing_for_post( $post->ID );
			if ( null !== $shadowing ) {
				$response['is_shadowed_by'] = $shadowing;
			}
		}

		return IATO_MCP_Server::ok( $response );
	}
);

// ── create_post ───────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'create_post',
	[
		'description' => 'Create a new post or page. Returns the new post ID and URL. On page-builder sites (Elementor, Divi, etc.), follow the new-post workflow in server instructions BEFORE calling — plain HTML in post_content will not match the site\'s existing post format.',
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

		$receipt = IATO_MCP_Change_Receipt::record(
			$post_id,
			'post',
			'create',
			null,
			[
				'post_type' => $post_type,
				'title'     => $postarr['post_title'],
			]
		);

		$data = [
			'id'  => $post_id,
			'url' => get_permalink( $post_id ),
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		$notice = iato_mcp_page_builder_notice( 'create' );
		if ( null !== $notice ) {
			$data['notice'] = $notice;
		}

		return IATO_MCP_Server::ok( $data );
	}
);

// ── update_post ───────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_post',
	[
		'description' => 'Update an existing post title, content, slug, excerpt, or status. On page-builder sites (Elementor, Divi, etc.), content edits go through the builder tools — see server instructions.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'                 => [ 'type' => 'integer', 'description' => 'Post ID to update (required)' ],
				'title'              => [ 'type' => 'string',  'description' => 'New title' ],
				'content'            => [ 'type' => 'string',  'description' => 'New content' ],
				'slug'               => [ 'type' => 'string',  'description' => 'New post slug (lowercase a-z 0-9 and hyphens, no leading/trailing/double hyphens, max 200 chars). Returns slug_conflict error if taken; non-draft posts also require confirm_url_break=true.' ],
				'excerpt'            => [ 'type' => 'string',  'description' => 'New excerpt (manual summary shown on archive/listing pages)' ],
				'status'             => [ 'type' => 'string',  'description' => 'New status: draft|publish' ],
				'confirm_url_break'  => [ 'type' => 'boolean', 'description' => 'Required true when changing the slug of a non-draft post; acknowledges the URL break.' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		// Accept both schema name (id) and Autopilot name (post_id).
		$post_id = absint( $args['id'] ?? $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$postarr = [ 'ID' => $post_id ];
		$before  = [];

		if ( isset( $args['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $args['title'] );
			$before['title']       = $post->post_title;
		}
		if ( isset( $args['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $args['content'] );
			$before['content']       = $post->post_content;
		}
		if ( isset( $args['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $args['excerpt'] );
			$before['excerpt']       = $post->post_excerpt;
		}
		if ( isset( $args['status'] ) && in_array( $args['status'], [ 'draft', 'publish' ], true ) ) {
			$postarr['post_status'] = $args['status'];
			$before['status']       = $post->post_status;
		}
		if ( isset( $args['slug'] ) ) {
			$slug_or_error = iato_mcp_validate_post_slug( $args['slug'], $post_id );
			if ( is_wp_error( $slug_or_error ) ) {
				return $slug_or_error;
			}
			// Guard non-draft posts: changing a live slug breaks inbound URLs.
			if ( 'draft' !== $post->post_status && $slug_or_error !== $post->post_name && empty( $args['confirm_url_break'] ) ) {
				return new WP_Error(
					'slug_change_requires_confirmation',
					'Changing the slug of a non-draft post breaks its current URL. Pass confirm_url_break=true to proceed.',
					[
						'current_url'  => get_permalink( $post_id ),
						'current_slug' => $post->post_name,
						'post_status'  => $post->post_status,
					]
				);
			}
			$postarr['post_name'] = $slug_or_error;
			$before['slug']       = $post->post_name;
		}

		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$field_to_postkey = [
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
			'slug'    => 'post_name',
		];

		$receipts = [];
		foreach ( $before as $field => $before_value ) {
			$after_value = $postarr[ $field_to_postkey[ $field ] ];
			if ( $before_value === $after_value ) {
				continue;
			}
			$receipts[] = IATO_MCP_Change_Receipt::record( $post_id, 'post', $field, $before_value, $after_value );
		}

		$fresh = get_post( $post_id );
		$data  = [
			'id'       => $post_id,
			'url'      => get_permalink( $post_id ),
			'slug'     => $fresh->post_name,
			'modified' => $fresh->post_modified_gmt,
		];

		if ( count( $receipts ) === 1 ) {
			IATO_MCP_Change_Receipt::append( $data, $receipts[0] );
		} elseif ( count( $receipts ) > 1 ) {
			$data['change_receipts'] = $receipts;
		}

		// Surface a builder-aware notice if content was rewritten on a builder-driven site.
		if ( isset( $args['content'] ) ) {
			$notice = iato_mcp_page_builder_notice( 'update' );
			if ( null !== $notice ) {
				$data['notice'] = $notice;
			}
		}

		return IATO_MCP_Server::ok( $data );
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
