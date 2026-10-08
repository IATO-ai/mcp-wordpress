<?php
/**
 * WP Tool: resolve_url.
 *
 * Walks the WordPress rewrite cascade for a given URL and reports which
 * post (and optionally which Theme Builder template) actually renders.
 * Useful when an LLM needs to disambiguate between a slug-based page and
 * a Theme Builder template that overrides it.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'resolve_url',
	[
		'description' => 'Resolve a URL to its rendering target. Returns the route_type (page|category_archive|tag_archive|author_archive|cpt_archive|theme_builder|404), the canonical post ID (rendering_post_id — null when no canonical post exists, e.g. on archives), and — when an Elementor Theme Builder template overrides the URL — the template ID and structured shadowing details. effective_render_id is the single field answering "what actually renders" (template ID when shadowed, canonical post ID otherwise, null only on a true 404). v1.8.0 fixes archive-URL shadowing: archive routes served by a Theme Builder template no longer silently return route_type=404 — they now resolve to the template and report shadowed_route_type so callers can see what was overridden. Best-effort across Elementor versions; returns limited_resolution=true when the platform can\'t introspect Theme Builder conditions.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'url' => [ 'type' => 'string', 'description' => 'Absolute or site-relative URL to resolve (required).' ],
			],
			'required' => [ 'url' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$url = isset( $args['url'] ) ? (string) $args['url'] : '';
		if ( '' === $url ) {
			return new WP_Error( 'missing_url', 'url is required.' );
		}
		$result = IATO_MCP_Elementor_Router::resolve_url( $url );
		return IATO_MCP_Server::ok( $result );
	}
);
