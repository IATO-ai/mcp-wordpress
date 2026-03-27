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
delete_option( 'iato_mcp_workspace_id' );
delete_option( 'iato_mcp_schedule_id' );
delete_option( 'iato_mcp_setup_complete' );
delete_option( 'iato_mcp_wizard_step' );
delete_option( 'iato_mcp_redirects' );
delete_option( 'iato_mcp_widget_sections' );
delete_option( 'iato_mcp_api_key_valid' );

// Transients.
delete_transient( 'iato_mcp_oauth_pkce' );
delete_transient( 'iato_mcp_dashboard_data' );

// Delete widget data transients (keyed by workspace ID).
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_iato_mcp_widget_data_%' OR option_name LIKE '_transient_timeout_iato_mcp_widget_data_%'" );

// Drop change receipts table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iato_change_receipts" );

// Delete structured data post meta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_iato_mcp_structured_data'" );
