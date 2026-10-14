<?php
/**
 * Plugin Name: IATO MCP test: receipt retention period
 * Description: TEST-ONLY must-use plugin for tests/wp-env/receipt-retention-live.sh. While the option iato_mcp_test_retention_days is set, the iato_mcp_receipt_retention_days filter returns it. Never ship (tests/ is excluded from the release zip).
 */
add_filter( 'iato_mcp_receipt_retention_days', function ( $days ) {
	$override = get_option( 'iato_mcp_test_retention_days', '' );
	return '' === $override || false === $override ? $days : (int) $override;
} );
