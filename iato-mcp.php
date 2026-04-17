<?php
/**
 * Plugin Name: IATO MCP
 * Description: Exposes an MCP server from any self-hosted WordPress install, enabling IATO analyze-and-fix workflows via Claude Desktop and other AI clients.
 * Version:     1.0.0
 * Author:      IATO
 * Author URI:  https://iato.ai
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: iato-mcp
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

define( 'IATO_MCP_VERSION', '1.0.0' );
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
require_once IATO_MCP_DIR . 'includes/class-review-queue.php';
require_once IATO_MCP_DIR . 'includes/class-diagnostics.php';
require_once IATO_MCP_DIR . 'includes/class-dashboard-widget.php';
require_once IATO_MCP_DIR . 'includes/class-mcp-server.php';

// Phase 1 — WP native tools
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-site.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-posts.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-seo.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-media.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-comments.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-menus.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-taxonomy.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-builder.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-canonical.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-structured-data.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-redirects.php';

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
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-sync.php';
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
	IATO_MCP_Review_Queue::init();
	IATO_MCP_Diagnostics::init();
	IATO_MCP_Dashboard_Widget::init();
}
add_action( 'plugins_loaded', 'iato_mcp_init' );

/**
 * Activation hook — show setup wizard on first run.
 */
function iato_mcp_activate() {
	IATO_MCP_Auth::maybe_generate_key();
	IATO_MCP_Change_Receipt::create_table();
	IATO_MCP_Call_Log::create_table();
	update_option( 'iato_mcp_show_wizard', true );

	// Clear stale autopilot queue ONLY on the very first install.
	// Re-activation must never wipe real Autopilot items — users often
	// deactivate/reactivate during troubleshooting and expect their data
	// to survive.
	if ( ! get_option( 'iato_mcp_initial_cleanup_done' ) ) {
		$api_key = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		if ( '' !== $api_key ) {
			$workspace_id = get_option( 'iato_mcp_workspace_id', '' );
			if ( ! empty( $workspace_id ) ) {
				IATO_MCP_IATO_Client::bulk_reject_all_pending( $workspace_id );
			}
		}
		update_option( 'iato_mcp_initial_cleanup_done', true );
	}
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
