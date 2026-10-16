<?php
/**
 * Plugin Name: IATO MCP test: force Elementor save failure / allow unfiltered HTML
 * Description: TEST-ONLY must-use plugin. Copy into wp-content/mu-plugins/ on the wp-env site; never ship (tests/ is excluded from the release zip).
 *
 * Two switches, both plain options so the live suite flips them with
 * `wp option update` between requests:
 *   iato_mcp_test_force_save_fail  → Elementor's Document::save() throws
 *                                    (from the elementor/document/save/data
 *                                    filter, before anything is written),
 *                                    which is the b2lconstructions.com addon
 *                                    failure shape;
 *   iato_mcp_test_allow_unfiltered → the plugin's iato_mcp_allow_unfiltered_html
 *                                    filter returns true (Application Password
 *                                    administrators keep raw HTML);
 *   iato_mcp_test_force_save_fail_message → the message of that throw (1.14.0);
 *   iato_mcp_test_throw_resolver   → a hook on Elementor's settings resolver
 *                                    registration throws (1.14.0);
 *   iato_mcp_test_throw_in_tool    → get_bloginfo( 'name' ) throws inside a
 *                                    tool handler (get_site_info), for the
 *                                    tools/call catch (1.14.0);
 *   iato_mcp_test_record_styles_clear → records the path of every
 *                                    elementor/atomic-widgets/styles/clear
 *                                    action in iato_mcp_test_styles_cleared
 *                                    (1.14.0);
 *   iato_mcp_test_record_widget_registration → records in
 *                                    iato_mcp_test_widgets_registered whether
 *                                    Elementor's widget registration ran in a
 *                                    REST request (1.13.1 read-cost check).
 */

add_filter( 'elementor/document/save/data', function ( $data ) {
	// Probe first, so the forced throw below still records the capability.
	update_option( 'iato_mcp_test_cap_during_save', current_user_can( 'unfiltered_html' ) ? '1' : '0', false );
	// 1.14.0: count Elementor saves (the bulk tool saves a page's atomic rows once).
	update_option( 'iato_mcp_test_saves', (int) get_option( 'iato_mcp_test_saves', 0 ) + 1, false );
	if ( get_option( 'iato_mcp_test_force_save_fail' ) ) {
		// 1.14.0: iato_mcp_test_force_save_fail_message picks the message (for
		// example "Settings validation failed. tag: invalid_value").
		throw new RuntimeException( (string) get_option( 'iato_mcp_test_force_save_fail_message', 'forced by iato-mcp test mu-plugin' ) );
	}
	return $data;
}, 1 );

add_filter( 'iato_mcp_allow_unfiltered_html', function ( $allow, $user_id, $kind ) {
	return get_option( 'iato_mcp_test_allow_unfiltered' ) ? true : $allow;
}, 10, 3 );

// Probe: what does current_user_can( 'unfiltered_html' ) say inside Elementor's
// save (recorded above, while the plugin may have lifted its lock) and at the
// end of the request (lock restored)? Written to options the live suite reads back.
add_action( 'shutdown', function () {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		update_option( 'iato_mcp_test_cap_at_shutdown', current_user_can( 'unfiltered_html' ) ? '1' : '0', false );
	}
}, 999 );

// 1.13.1: record whether Elementor's widget registration ran in this request
// (switch iato_mcp_test_record_widget_registration; the live suite deletes
// iato_mcp_test_widgets_registered before a request and reads it after).
add_action( 'elementor/widgets/register', function () {
	if ( get_option( 'iato_mcp_test_record_widget_registration' ) && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		update_option( 'iato_mcp_test_widgets_registered', '1', false );
	}
}, 1 );

// 1.14.0: an addon hook on Elementor's settings resolver registration throws.
add_action( 'elementor/atomic-widgets/settings-resolvers/register', function () {
	if ( get_option( 'iato_mcp_test_throw_resolver' ) ) {
		throw new RuntimeException( 'resolver hook forced to throw by iato-mcp test mu-plugin' );
	}
}, 1 );

// 1.14.0: a throw inside a tool handler (get_site_info reads the site name).
add_filter( 'option_blogname', function ( $value ) {
	if ( get_option( 'iato_mcp_test_throw_in_tool' ) && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		throw new RuntimeException( 'tool handler forced to throw by iato-mcp test mu-plugin' );
	}
	return $value;
} );

// 1.14.0: record which per-post atomic styles were cleared.
add_action( 'elementor/atomic-widgets/styles/clear', function ( $path ) {
	if ( get_option( 'iato_mcp_test_record_styles_clear' ) ) {
		$seen   = json_decode( (string) get_option( 'iato_mcp_test_styles_cleared', '[]' ), true ) ?: [];
		$seen[] = $path;
		update_option( 'iato_mcp_test_styles_cleared', wp_json_encode( $seen ), false );
	}
}, 1 );
