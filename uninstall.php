<?php
/**
 * Uninstall — clean up all plugin data when deleted via WP Admin.
 *
 * This file runs only when the plugin is deleted through the WordPress
 * Plugins screen. It removes all options and transients created by the plugin.
 *
 * @package IATO_MCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Plugin options.
delete_option( 'iato_mcp_key' );
delete_option( 'iato_mcp_api_key' );
delete_option( 'iato_mcp_crawl_id' );
delete_option( 'iato_mcp_tools' );
delete_option( 'iato_mcp_show_wizard' );
delete_option( 'iato_mcp_wizard_dismissed' );
delete_option( 'iato_mcp_oauth_clients' );

// Transients.
delete_transient( 'iato_mcp_oauth_pkce' );
