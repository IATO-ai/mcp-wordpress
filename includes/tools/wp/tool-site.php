<?php
/**
 * WP Tools: get_site_info, get_site_settings
 *
 * get_site_info  — read-only, any authenticated user
 * get_site_settings — requires manage_options
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_site_info ─────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_site_info',
	[
		'description' => 'Returns site name, URL, WordPress version, active theme, active plugin count, and the Theme Builder template count. The theme_builder_template_count field (added v1.11.0) counts elementor_library posts that are actually Theme Builder templates (have a non-empty _elementor_template_type meta) — excluding saved sections, reusable blocks, and condition-less popups, since those don\'t shadow URLs. Use list_elementor_templates (manage_options) for the full enumeration once you know templates exist.',
		'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass(), 'required' => [] ],
	],
	function ( array $args ): array|WP_Error {
		return IATO_MCP_Server::ok( [
			'name'                          => sanitize_text_field( get_bloginfo( 'name' ) ),
			'url'                           => site_url(),
			'wp_version'                    => get_bloginfo( 'version' ),
			'active_theme'                  => wp_get_theme()->get( 'Name' ),
			'plugin_count'                  => count( get_option( 'active_plugins', [] ) ),
			'theme_builder_template_count'  => iato_mcp_count_theme_builder_templates(),
		] );
	}
);

/**
 * Count elementor_library posts that are actually Theme Builder templates —
 * i.e. have a non-empty _elementor_template_type meta value. Excludes saved
 * sections, reusable blocks, and other elementor_library entries that don't
 * carry a template-type assignment (those don't shadow URLs and would
 * inflate the discovery signal).
 *
 * Returns 0 when Elementor isn't installed (post_type doesn't exist). Cheap:
 * single WP_Query with meta-exists check and fields=ids; result count via
 * found_posts. Always returns an int so the field is reliable across calls.
 */
function iato_mcp_count_theme_builder_templates(): int {
	if ( ! post_type_exists( 'elementor_library' ) ) {
		return 0;
	}
	$query = new WP_Query( [
		'post_type'              => 'elementor_library',
		'post_status'            => 'publish',
		'posts_per_page'         => 1, // we only need found_posts; no need to materialise rows
		'fields'                 => 'ids',
		'no_found_rows'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded, single meta key, indexed in typical setups.
		'meta_query'             => [
			[
				'key'     => '_elementor_template_type',
				'compare' => 'EXISTS',
			],
		],
	] );
	return (int) $query->found_posts;
}

// ── get_site_settings ─────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_site_settings',
	[
		'description' => 'Returns site title, tagline, admin email, timezone, and permalink structure. Requires administrator.',
		'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass(), 'required' => [] ],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		// title, tagline, permalink_structure are returned raw — sanitize_text_field
		// runs _sanitize_text_fields which strips %[a-f0-9]{2} octets, destroying
		// legitimate placeholder/literal content in these fields (e.g. /%category%/
		// in permalink_structure). admin_email and timezone retain sanitize_text_field
		// because their value types cannot legitimately carry %xx. JSON encoding in
		// IATO_MCP_Server::ok() handles transport-level escaping. See v1.8.2 changelog.
		return IATO_MCP_Server::ok( [
			'title'               => (string) get_option( 'blogname', '' ),
			'tagline'             => (string) get_option( 'blogdescription', '' ),
			'admin_email'         => sanitize_text_field( get_option( 'admin_email', '' ) ),
			'timezone'            => sanitize_text_field( get_option( 'timezone_string', '' ) ),
			'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
		] );
	}
);
