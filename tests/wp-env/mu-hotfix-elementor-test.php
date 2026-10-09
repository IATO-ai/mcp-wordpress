<?php
/**
 * Plugin Name: IATO MCP test: Elementor hotfix probes (1.12.2)
 * Description: TEST-ONLY must-use plugin. Copy into wp-content/mu-plugins/ on the wp-env site; never ship (tests/ is excluded from the release zip).
 *
 * Option switches, flipped by the live suite with `wp option update`:
 *   iato_mcp_test_force_save_fail        Elementor's Document::save() throws from the
 *                                        elementor/document/save/data filter.
 *   iato_mcp_test_drop_elementor_callback  on rest_api_init (late), remove every callback
 *                                        on the sanitize_post_meta__elementor_* hooks,
 *                                        standing in for an Elementor version that does
 *                                        not register them.
 * On shutdown of every request it records how many callbacks the
 * sanitize_post_meta__elementor_* hooks hold, in the option
 * iato_mcp_test_callbacks_at_shutdown, so the suite can prove they were
 * restored after a write and after a forced throw.
 */

add_filter( 'elementor/document/save/data', function ( $data ) {
	if ( get_option( 'iato_mcp_test_force_save_fail' ) ) {
		throw new RuntimeException( 'forced by iato-mcp test mu-plugin' );
	}
	return $data;
}, 1 );

add_action( 'rest_api_init', function () {
	if ( ! get_option( 'iato_mcp_test_drop_elementor_callback' ) ) {
		return;
	}
	foreach ( array_keys( $GLOBALS['wp_filter'] ) as $hook ) {
		if ( is_string( $hook ) && str_starts_with( $hook, 'sanitize_post_meta__elementor_' ) ) {
			remove_all_filters( $hook );
		}
	}
}, 999 );

add_action( 'shutdown', function () {
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return;
	}
	$n = 0;
	foreach ( $GLOBALS['wp_filter'] as $hook => $wp_hook ) {
		if ( is_string( $hook ) && str_starts_with( $hook, 'sanitize_post_meta__elementor_' ) && is_object( $wp_hook ) ) {
			foreach ( $wp_hook->callbacks as $cbs ) {
				$n += count( $cbs );
			}
		}
	}
	update_option( 'iato_mcp_test_callbacks_at_shutdown', (string) $n, false );
}, 999 );
