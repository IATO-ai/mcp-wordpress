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

// Core classes
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-iato-client.php';
require_once IATO_MCP_DIR . 'includes/class-seo-adapter.php';
require_once IATO_MCP_DIR . 'includes/class-oauth.php';
require_once IATO_MCP_DIR . 'includes/class-settings.php';
require_once IATO_MCP_DIR . 'includes/class-mcp-server.php';

// Phase 1 — WP native tools
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-site.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-posts.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-seo.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-media.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-comments.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-menus.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-taxonomy.php';

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
}
add_action( 'plugins_loaded', 'iato_mcp_init' );

/**
 * Activation hook — show setup wizard on first run.
 */
function iato_mcp_activate() {
	IATO_MCP_Auth::maybe_generate_key();
	update_option( 'iato_mcp_show_wizard', true );
}
register_activation_hook( __FILE__, 'iato_mcp_activate' );

/**
 * Deactivation hook — clean up transients.
 * Options are preserved for reactivation; full cleanup is in uninstall.php.
 */
function iato_mcp_deactivate() {
	delete_transient( 'iato_mcp_oauth_pkce' );
}
register_deactivation_hook( __FILE__, 'iato_mcp_deactivate' );
