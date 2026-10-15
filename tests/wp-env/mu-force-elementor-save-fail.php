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
 *   iato_mcp_test_record_widget_registration → records in
 *                                    iato_mcp_test_widgets_registered whether
 *                                    Elementor's widget registration ran in a
 *                                    REST request (1.13.1 read-cost check).
 */

add_filter( 'elementor/document/save/data', function ( $data ) {
	// Probe first, so the forced throw below still records the capability.
	update_option( 'iato_mcp_test_cap_during_save', current_user_can( 'unfiltered_html' ) ? '1' : '0', false );
	if ( get_option( 'iato_mcp_test_force_save_fail' ) ) {
		throw new RuntimeException( 'forced by iato-mcp test mu-plugin' );
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
