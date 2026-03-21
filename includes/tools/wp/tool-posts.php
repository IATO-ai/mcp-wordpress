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
		// TODO: implement — use get_posts() or WP_Query
		// Sanitize: sanitize_text_field for post_type/status, absint for per_page/page
		// Cap per_page at 100
		// Return array of {id, title, slug, status, url, modified}
		return new WP_Error( 'not_implemented', 'get_posts not yet implemented' );
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
		// TODO: implement — get_post() by ID or get_page_by_path() by slug
		// Return {id, title, slug, content, excerpt, status, url, author, date, modified, categories, tags}
		return new WP_Error( 'not_implemented', 'get_post not yet implemented' );
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

		// TODO: implement — wp_insert_post(), sanitize all inputs
		// Default status to draft for safety
		return new WP_Error( 'not_implemented', 'create_post not yet implemented' );
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

		// TODO: implement — wp_update_post(), verify post exists first
		// Only update fields that were passed (partial update)
		return new WP_Error( 'not_implemented', 'update_post not yet implemented' );
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
		// TODO: implement — WP_Query with s parameter
		return new WP_Error( 'not_implemented', 'search_posts not yet implemented' );
	}
);
