<?php
/**
 * Drive the real admin-ajax settings save for the per-tool list (wp-env only).
 *
 *   wp eval-file .../tests/wp-env/tools-settings-save.php <tools> [media] --user=admin
 *     <tools>: comma-separated tool names to post, or ALL (every tool) or
 *              ALLWP (every non-bridge tool: what a browser posts while the
 *              bridge checkboxes are disabled), or NONE.
 *     [media]: 1|0 to post the media URL toggle (default 0).
 * Prints the stored iato_mcp_tools summary and the media flag afterwards.
 */
$spec   = (string) ( $args[0] ?? 'ALLWP' );
$media  = (string) ( $args[1] ?? '0' );
$ref    = new ReflectionClass( 'IATO_MCP_Settings' );
$all    = $ref->getConstant( 'TOOL_NAMES' );
$bridge = iato_mcp_bridge_tool_names();
switch ( $spec ) {
	case 'ALL':   $post_tools = $all; break;
	case 'ALLWP': $post_tools = array_values( array_diff( $all, $bridge ) ); break;
	case 'NONE':  $post_tools = []; break;
	default:      $post_tools = array_filter( array_map( 'trim', explode( ',', $spec ) ) );
}
$_POST = [
	'nonce'                              => wp_create_nonce( 'iato_mcp_save_settings' ),
	'iato_mcp_api_key'                   => get_option( 'iato_mcp_api_key', '' ),
	'iato_mcp_crawl_id'                  => get_option( 'iato_mcp_crawl_id', '' ),
	'iato_mcp_tools'                     => $post_tools,
	'iato_mcp_abilities_enabled'         => '1',
	'iato_mcp_media_url_source_enabled'  => $media,
];
$_REQUEST = $_POST;
add_filter( 'wp_die_ajax_handler', fn() => function () { throw new RuntimeException( 'die' ); } );
add_filter( 'wp_die_handler', fn() => function () { throw new RuntimeException( 'die' ); } );
ob_start();
try { IATO_MCP_Settings::ajax_save_settings(); } catch ( RuntimeException $e ) {}
ob_end_clean();
$saved = get_option( 'iato_mcp_tools', [] );
$on    = array_values( array_intersect( $bridge, (array) $saved ) );
printf( "saved=%d tools; bridge on=%d%s; media=%s\n", count( (array) $saved ), count( $on ), count( $on ) < 12 ? ' (off: ' . implode( ',', array_diff( $bridge, $on ) ) . ')' : '', var_export( get_option( 'iato_mcp_media_url_source_enabled', 'MISSING' ), true ) );
