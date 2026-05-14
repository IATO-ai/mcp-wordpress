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
delete_option( 'iato_mcp_setup_complete' );
delete_option( 'iato_mcp_wizard_step' );
delete_option( 'iato_mcp_redirects' );
delete_option( 'iato_mcp_api_key_valid' );
delete_option( 'iato_mcp_db_version' );
delete_option( 'iato_mcp_media_url_source_enabled' );
delete_option( 'iato_mcp_media_url_host_allowlist' );
delete_option( 'iato_mcp_media_max_upload_size' );
delete_option( 'iato_mcp_media_upload_rate_limit' );

// Transients.
delete_transient( 'iato_mcp_oauth_pkce' );

// Drop change receipts + call log tables.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup: drop our own custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iato_change_receipts" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup: drop our own custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iato_mcp_call_log" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup: drop our own custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iato_mcp_media_phase_log" );

// Delete suggestion generation transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall bulk delete of our own transient keys; delete_transient() cannot do pattern matching.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_iato_suggestions_generated_%' OR option_name LIKE '_transient_timeout_iato_suggestions_generated_%'" );

// Delete structured data post meta.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall bulk cleanup of plugin-owned meta key.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_iato_mcp_structured_data'" );
