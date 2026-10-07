<?php
/**
 * Exercise the real admin-ajax save path for the abilities toggle.
 *
 *   npx @wordpress/env run cli wp eval-file \
 *     wp-content/plugins/mcp-wordpress/tests/wp-env/abilities-settings-save.php 0|1 --user=admin
 *
 * Simulates the Settings form POST (hidden "0" sibling plus optional checked
 * "1") exactly as the browser sends it, calls IATO_MCP_Settings::ajax_save_settings(),
 * and prints the option afterwards. wp_send_json() calls wp_die(); the die
 * handler is swapped for an exception so the script can continue.
 */
$want = isset( $args[0] ) && '1' === (string) $args[0];

$_POST = [
	'nonce'                      => wp_create_nonce( 'iato_mcp_save_settings' ),
	'iato_mcp_api_key'           => get_option( 'iato_mcp_api_key', '' ),
	'iato_mcp_crawl_id'          => get_option( 'iato_mcp_crawl_id', '' ),
	// iato_mcp_tools omitted on purpose: the handler then stores [] (= all tools on).
	'iato_mcp_abilities_enabled' => $want ? '1' : '0',
];
$_REQUEST = $_POST;

add_filter( 'wp_die_ajax_handler', fn() => function ( $message ) { throw new RuntimeException( 'wp_die:' . wp_json_encode( $message ) ); } );
add_filter( 'wp_die_handler', fn() => function ( $message ) { throw new RuntimeException( 'wp_die:' . wp_json_encode( $message ) ); } );

ob_start();
try {
	IATO_MCP_Settings::ajax_save_settings();
} catch ( RuntimeException $e ) {
	// expected: wp_send_json_* ends with wp_die
}
$out = ob_get_clean();
WP_CLI::line( 'ajax response: ' . trim( $out ) );
WP_CLI::line( 'option iato_mcp_abilities_enabled = ' . var_export( get_option( 'iato_mcp_abilities_enabled', 'MISSING' ), true ) );
