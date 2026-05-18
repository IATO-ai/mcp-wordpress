<?php
/**
 * WP Tool: list_elementor_templates.
 *
 * Enumerates Elementor Theme Builder templates (`elementor_library` posts)
 * with their Display Conditions surfaced as both raw condition strings and
 * structured / human-readable form. Closes the discovery gap that motivated
 * Layers 1 and 2: without this tool, a template like Portfolio Archieve
 * (post 309 on garennebigby.dev) could only be found by brute-forcing post
 * IDs until a revision slug leaked the parent.
 *
 * Gated behind `manage_options`. The disclosure level — full structural map
 * of every Theme Builder construct on the site and its targeting rules — is
 * comparable to or greater than `get_site_settings`, which is the existing
 * `manage_options`-gated structural read. Per-resource template questions
 * ("what renders THIS URL") are answered by `resolve_url` at the existing
 * authenticated-only level. Enumeration deserves a strictly higher gate
 * than per-resource lookup.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'list_elementor_templates',
	[
		'description' => 'Enumerate Elementor Theme Builder templates (elementor_library posts) with their Display Conditions surfaced. Each result includes the template ID, title, type (single/archive/header/footer/404/popup/etc.), edit URL, and conditions array — each condition shown both as the raw stored string (e.g. include/archive/category/1) and a structured parsed shape with resolved target labels (e.g. the category named "Build"). Use this when you need to find a template by what it targets rather than by ID, or to audit the full theme-builder skeleton of a site. Requires manage_options. Per-URL template lookup ("what renders this URL") is handled by resolve_url at the authenticated-only level; this tool exists specifically for the enumeration access pattern.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'template_type'      => [ 'type' => 'string',  'description' => 'Optional filter on _elementor_template_type meta (single|archive|header|footer|404|popup|<other location>).' ],
				'status'             => [ 'type' => 'string',  'description' => 'publish|draft|pending|private|any (default: publish)' ],
				'per_page'           => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default: 50)' ],
				'page'               => [ 'type' => 'integer', 'description' => 'Page number (default: 1)' ],
				'include_conditions' => [ 'type' => 'boolean', 'description' => 'Include the Display Conditions array on each template (default: true). Set false for a cheaper ID/title-only listing.' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		// Gate. Use the Bearer-auth-compatible helper, not current_user_can() —
		// current_user_can() returns false under Bearer auth because
		// wp_get_current_user() is 0 in that context. Same pattern as v1.3.1 /
		// v1.6.4 / v1.8.x.
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		if ( ! post_type_exists( 'elementor_library' ) ) {
			return IATO_MCP_Server::ok( [
				'templates'   => [],
				'total'       => 0,
				'total_pages' => 0,
				'page'        => 1,
			] );
		}

		$template_type      = isset( $args['template_type'] ) ? sanitize_text_field( (string) $args['template_type'] ) : '';
		$status_in          = isset( $args['status'] )        ? sanitize_text_field( (string) $args['status'] ) : 'publish';
		$per_page           = isset( $args['per_page'] )      ? min( max( absint( $args['per_page'] ), 1 ), 100 ) : 50;
		$page               = isset( $args['page'] )          ? max( absint( $args['page'] ), 1 ) : 1;
		$include_conditions = isset( $args['include_conditions'] ) ? (bool) $args['include_conditions'] : true;

		$query_args = [
			'post_type'      => 'elementor_library',
			'post_status'    => 'any' === $status_in
				? [ 'publish', 'draft', 'pending', 'private' ]
				: $status_in,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];

		if ( '' !== $template_type ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded, single meta key, indexed in typical setups.
			$query_args['meta_query'] = [
				[
					'key'   => '_elementor_template_type',
					'value' => $template_type,
				],
			];
		}

		$query = new WP_Query( $query_args );

		$templates = [];
		foreach ( $query->posts as $post ) {
			$template_type_meta = get_post_meta( $post->ID, '_elementor_template_type', true );

			$entry = [
				'id'            => (int) $post->ID,
				'title'         => get_the_title( $post ),
				'slug'          => $post->post_name,
				'status'        => $post->post_status,
				'template_type' => ( is_string( $template_type_meta ) && '' !== $template_type_meta ) ? $template_type_meta : null,
				'modified'      => $post->post_modified_gmt,
				'edit_url'      => admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ),
			];

			if ( $include_conditions ) {
				$entry['conditions'] = iato_mcp_format_template_conditions( $post->ID );
			}

			$templates[] = $entry;
		}

		return IATO_MCP_Server::ok( [
			'templates'   => $templates,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		] );
	}
);

/**
 * Read the `_elementor_conditions` meta for a template and return it as the
 * tool's `conditions` array shape: each entry has `raw` (the literal stored
 * string) and `parsed` (the structured representation, or null when the
 * parser doesn't recognize the format).
 *
 * Implemented as a file-local helper rather than an additional public method
 * on the router class — keeps the response-shape concerns owned by the tool
 * file. The actual condition parsing lives in
 * IATO_MCP_Elementor_Router::parse_condition_string().
 *
 * @return list<array{raw:string,parsed:?array}>
 */
function iato_mcp_format_template_conditions( int $template_id ): array {
	$raw_conditions = get_post_meta( $template_id, '_elementor_conditions', true );
	if ( ! is_array( $raw_conditions ) ) {
		return [];
	}

	$out = [];
	foreach ( $raw_conditions as $condition_string ) {
		$cs = (string) $condition_string;
		$out[] = [
			'raw'    => $cs,
			'parsed' => IATO_MCP_Elementor_Router::parse_condition_string( $cs ),
		];
	}
	return $out;
}
