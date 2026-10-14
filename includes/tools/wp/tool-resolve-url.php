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
		// A post the caller may not read (a draft reached through ?p=ID, a
		// private post for a user without read_private_*) answers as a 404,
		// as it does for a visitor (1.13.0). The router caches per URL, so the
		// check is applied here, per caller. Deliberately read_post, not
		// require_read_post(): resolve_url returns IDs and the route type, no
		// content, and the IDs of a published password-protected page (body
		// class page-id-N) and of a Theme Builder template rendering on a page
		// (data-elementor-id) are in that page's public HTML.
		foreach ( [ 'rendering_post_id', 'shadowed_post_id' ] as $id_key ) {
			$id = (int) ( $result[ $id_key ] ?? 0 );
			if ( $id > 0 && is_wp_error( IATO_MCP_Auth::require_cap( 'read_post', $id ) ) ) {
				$result = [
					'url'                        => $result['url'] ?? $url,
					'rendering_post_id'          => null,
					'rendering_post_type'        => null,
					'rendering_template_id'      => null,
					'effective_render_id'        => null,
					'effective_render_post_type' => null,
					'shadowed_post_id'           => null,
					'shadowed_route_type'        => null,
					'route_type'                 => '404',
				];
				break;
			}
		}
		return IATO_MCP_Server::ok( $result );
	}
);
