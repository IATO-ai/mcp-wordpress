<?php
/**
 * Test-only mu-plugin for tests/wp-env/cache-purge-live.py. Records what the
 * plugin's cache code did on real requests, in options the test reads back.
 * Installed by the live script and removed by --cleanup; never shipped.
 */

// Every purge, as the documented action reports it.
add_action( 'iato_mcp_after_cache_purge', function ( string $scope, ?int $post_id, array $results ): void {
	$log   = (array) get_option( 'iato_mcp_test_purges', [] );
	$log[] = [ 'scope' => $scope, 'post_id' => $post_id, 'results' => $results ];
	update_option( 'iato_mcp_test_purges', $log, false );
}, 10, 3 );

// Elementor's global clear, which deletes every page's CSS.
add_action( 'elementor/core/files/clear_cache', function (): void {
	update_option( 'iato_mcp_test_global_clears', 1 + (int) get_option( 'iato_mcp_test_global_clears', 0 ), false );
} );

// The documented opt-out, driven by an option.
add_filter( 'iato_mcp_cache_purge', function ( bool $purge ): bool {
	return '1' === get_option( 'iato_mcp_test_purge_filter_off', '0' ) ? false : $purge;
} );

// The loopback: counted, and answered with made-up headers when a test asks.
add_filter( 'pre_http_request', function ( $pre, array $args, string $url ) {
	if ( 'HEAD' !== ( $args['method'] ?? '' ) || 0 !== strpos( $url, home_url() ) ) {
		return $pre;
	}
	update_option( 'iato_mcp_test_head_requests', 1 + (int) get_option( 'iato_mcp_test_head_requests', 0 ), false );
	$headers = get_option( 'iato_mcp_test_edge_headers', '' );
	if ( '' === $headers ) {
		return $pre;
	}
	return [ 'headers' => json_decode( (string) $headers, true ), 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ];
}, 10, 3 );
