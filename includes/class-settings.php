<?php
/**
 * Settings page — registers "Settings > IATO MCP" in WP Admin.
 *
 * Fields:
 *   - iato_mcp_api_key      IATO API key (password input, stored in wp_options)
 *   - iato_mcp_crawl_id     Default crawl ID used as fallback by bridge tools
 *   - iato_mcp_tools        Array of enabled tool names (all enabled by default)
 *
 * On save: validate API key by calling GET /api/v1/workspaces and checking 200.
 * On activation: show setup wizard admin notice (dismissed via iato_mcp_wizard_dismissed option).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Settings {

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_notices', [ self::class, 'setup_wizard_notice' ] );
		add_action( 'admin_post_iato_mcp_dismiss_wizard', [ self::class, 'dismiss_wizard' ] );
	}

	/**
	 * Add settings submenu under Settings.
	 */
	public static function add_menu(): void {
		// TODO: implement — add_options_page('IATO MCP', 'IATO MCP', 'manage_options', 'iato-mcp', [self::class, 'render_page'])
	}

	/**
	 * Register settings with the Settings API.
	 */
	public static function register_settings(): void {
		// TODO: implement — register_setting for iato_mcp_api_key, iato_mcp_crawl_id
		// add_settings_section, add_settings_field for each option
		// Sanitize API key: sanitize_text_field
	}

	/**
	 * Render the settings page HTML.
	 */
	public static function render_page(): void {
		// TODO: implement — settings_fields(), do_settings_sections(), submit_button()
	}

	/**
	 * Show the setup wizard admin notice on first activation.
	 * Includes: Application Passwords link, Claude Desktop JSON snippet, API key link.
	 */
	public static function setup_wizard_notice(): void {
		// TODO: implement — check get_option('iato_mcp_show_wizard') and !get_option('iato_mcp_wizard_dismissed')
		// Show notice with:
		//   1. Link to WP Admin > Users > Profile > Application Passwords
		//   2. JSON config snippet auto-populated with site_url()
		//   3. Link to Settings > IATO MCP for API key
	}

	/**
	 * Handle wizard dismiss form post.
	 */
	public static function dismiss_wizard(): void {
		// TODO: implement — check_admin_referer, update_option('iato_mcp_wizard_dismissed', true), wp_redirect back
	}
}
