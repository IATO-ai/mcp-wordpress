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
		'description' => 'Resolve a URL to its rendering target. Returns the canonical post ID, route_type (page|category_archive|tag_archive|cpt_archive|theme_builder|404), and shadowed_post_id when an Elementor Theme Builder template overrides the slug-based resolution. Best-effort across Elementor versions; returns limited_resolution=true when the platform can\'t introspect Theme Builder conditions.',
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
