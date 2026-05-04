<?php
/**
 * Plugin Name: IATO MCP
 * Plugin URI:  https://iato.ai/wordpress-mcp
 * Description: Exposes an MCP server from any self-hosted WordPress install, enabling IATO analyze-and-fix workflows via Claude Desktop and other AI clients.
 * Version:     1.4.6
 * Author:      IATO
 * Author URI:  https://iato.ai
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: iato-mcp
 * Requires at least: 6.2
 * Requires PHP: 8.0
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

define( 'IATO_MCP_VERSION', '1.4.6' );
define( 'IATO_MCP_FILE', __FILE__ );
define( 'IATO_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'IATO_MCP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Return the IATO logo as an inline <img> tag using a base64 data URI.
 *
 * Some hosts block direct access to PNG files in the plugins directory,
 * so we embed the logo to guarantee it always renders.
 *
 * @param int $height Height attribute in pixels (default 36).
 * @return string <img> markup.
 */
function iato_mcp_logo_svg( int $height = 36 ): string {
	static $data_uri = null;
	if ( null === $data_uri ) {
		$path = IATO_MCP_DIR . 'assets/img/logo.png';
		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data_uri = 'data:image/png;base64,' . base64_encode( file_get_contents( $path ) );
		} else {
			$data_uri = '';
		}
	}
	if ( '' === $data_uri ) {
		return '<span style="font-weight:700;">IATO</span>';
	}
	return '<img src="' . esc_attr( $data_uri ) . '" alt="IATO" height="' . esc_attr( $height ) . '" style="vertical-align:middle;" />';
}

// Core classes
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-iato-client.php';
require_once IATO_MCP_DIR . 'includes/class-seo-adapter.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-call-log.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-oauth.php';
require_once IATO_MCP_DIR . 'includes/class-settings.php';
require_once IATO_MCP_DIR . 'includes/class-setup-wizard.php';
require_once IATO_MCP_DIR . 'includes/class-diagnostics.php';
require_once IATO_MCP_DIR . 'includes/class-mcp-server.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-adapter.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-router.php';

// Phase 1 — WP native tools
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-site.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-posts.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-seo.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-media.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-comments.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-menus.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-taxonomy.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-builder.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-elementor-widgets.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-elementor-bulk.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-elementor-helpers.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-resolve-url.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-canonical.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-structured-data.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-redirects.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-rollback.php';

// Phase 2 — IATO bridge tools (loaded only when IATO API key is configured)
if ( get_option( 'iato_mcp_api_key', '' ) !== '' ) {
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-sitemap.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-nav-audit.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-orphans.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-taxonomy.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-seo-fixes.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-content-gaps.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-broken-links.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-suggestions.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-perf.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-start-crawl.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-crawl-status.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-list-crawls.php';
}

/**
 * Boot the plugin after all plugins are loaded.
 */
function iato_mcp_init() {
	IATO_MCP_OAuth::init();
	IATO_MCP_Settings::init();
	IATO_MCP_Server::init();
	IATO_MCP_Rollback::init();
	IATO_MCP_Setup_Wizard::init();
	IATO_MCP_Diagnostics::init();
	iato_mcp_maybe_run_migrations();
}
add_action( 'plugins_loaded', 'iato_mcp_init' );

/**
 * Run idempotent one-shot migrations gated by stored db_version.
 *
 * Activation hooks don't fire on plugin update, so any post-install
 * data fix-ups have to live here. Each migration block compares against
 * iato_mcp_db_version and bumps the option on success.
 */
function iato_mcp_maybe_run_migrations() {
	$db_version = get_option( 'iato_mcp_db_version', '0' );

	// 1.3.5: append v2 tool names to iato_mcp_tools so existing installs
	// upgrading from 1.2.x don't see the new Elementor v2 tools auto-disabled
	// (is_tool_enabled() returns false for any name not in the saved array).
	if ( version_compare( $db_version, '1.3.5', '<' ) ) {
		$saved = get_option( 'iato_mcp_tools', null );
		if ( is_array( $saved ) && ! empty( $saved ) ) {
			$new_v2 = [
				'list_elementor_widgets',
				'get_elementor_widget',
				'update_elementor_widget',
				'update_elementor_patch',
				'update_elementor_widgets_bulk',
				'find_elementor_widgets',
				'set_heading_level',
				'set_widget_setting',
				'resolve_url',
			];
			$missing = array_diff( $new_v2, $saved );
			if ( ! empty( $missing ) ) {
				update_option( 'iato_mcp_tools', array_values( array_merge( $saved, $missing ) ), false );
			}
		}
	}

	// 1.4.0: append `rollback` to iato_mcp_tools so existing installs don't see
	// the new tool auto-disabled by the per-tool toggle gate.
	if ( version_compare( $db_version, '1.4.0', '<' ) ) {
		$saved = get_option( 'iato_mcp_tools', null );
		if ( is_array( $saved ) && ! empty( $saved ) && ! in_array( 'rollback', $saved, true ) ) {
			$saved[] = 'rollback';
			update_option( 'iato_mcp_tools', array_values( $saved ), false );
		}
	}

	// 1.4.5: re-restore `rollback` to iato_mcp_tools for installs where it was
	// stripped by sanitize_tools() between 1.4.0 and 1.4.5 (TOOL_NAMES was missing
	// it, so any user-triggered Settings save would array_intersect it out).
	// Idempotent — no-op for installs that didn't lose it.
	if ( version_compare( $db_version, '1.4.5', '<' ) ) {
		$saved = get_option( 'iato_mcp_tools', null );
		if ( is_array( $saved ) && ! empty( $saved ) && ! in_array( 'rollback', $saved, true ) ) {
			$saved[] = 'rollback';
			update_option( 'iato_mcp_tools', array_values( $saved ), false );
		}
	}

	if ( version_compare( $db_version, IATO_MCP_VERSION, '<' ) ) {
		update_option( 'iato_mcp_db_version', IATO_MCP_VERSION, false );
	}
}

/**
 * Activation hook — show setup wizard on first run.
 */
function iato_mcp_activate() {
	IATO_MCP_Auth::maybe_generate_key();
	IATO_MCP_Change_Receipt::create_table();
	IATO_MCP_Call_Log::create_table();
	update_option( 'iato_mcp_show_wizard', true );

	// Clear stale suggestion generation transients so a fresh install/update
	// always starts clean and can re-trigger generation.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation-only bulk delete of our own transient keys; no cache to invalidate, and delete_transient() cannot do pattern matching.
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_iato_suggestions_generated_%' OR option_name LIKE '_transient_timeout_iato_suggestions_generated_%'"
	);
}
register_activation_hook( __FILE__, 'iato_mcp_activate' );

/**
 * Deactivation hook — clean up transients.
 * Options are preserved for reactivation; full cleanup is in uninstall.php.
 */
function iato_mcp_deactivate() {
	delete_transient( 'iato_mcp_oauth_pkce' );
	delete_transient( 'iato_mcp_dashboard_data' );
}
register_deactivation_hook( __FILE__, 'iato_mcp_deactivate' );
