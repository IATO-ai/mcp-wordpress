<?php
/**
 * Plugin Name: IATO MCP test: database refuses ALTER
 * Description: TEST-ONLY must-use plugin for tests/wp-env/schema-migration-live.sh. While the option iato_test_refuse_alter is set, every ALTER TABLE on a plugin table is replaced with one that fails, so dbDelta and the direct attempt get a real database error.
 */
add_filter(
	'query',
	function ( $query ) {
		if ( ! is_string( $query ) || 0 !== stripos( ltrim( $query ), 'ALTER TABLE' ) || false === stripos( $query, 'iato' ) ) {
			return $query;
		}
		return get_option( 'iato_test_refuse_alter' ) ? 'ALTER TABLE iato_test_no_such_table ADD COLUMN x INT' : $query;
	}
);
