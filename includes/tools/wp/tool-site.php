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
		'description' => 'Returns site name, URL, WordPress version, active theme, and active plugin count.',
		'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass(), 'required' => [] ],
	],
	function ( array $args ): array|WP_Error {
		return IATO_MCP_Server::ok( [
			'name'         => sanitize_text_field( get_bloginfo( 'name' ) ),
			'url'          => site_url(),
			'wp_version'   => get_bloginfo( 'version' ),
			'active_theme' => wp_get_theme()->get( 'Name' ),
			'plugin_count' => count( get_option( 'active_plugins', [] ) ),
		] );
	}
);

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

		return IATO_MCP_Server::ok( [
			'title'               => sanitize_text_field( get_option( 'blogname', '' ) ),
			'tagline'             => sanitize_text_field( get_option( 'blogdescription', '' ) ),
			'admin_email'         => sanitize_text_field( get_option( 'admin_email', '' ) ),
			'timezone'            => sanitize_text_field( get_option( 'timezone_string', '' ) ),
			'permalink_structure' => sanitize_text_field( get_option( 'permalink_structure', '' ) ),
		] );
	}
);
