<?php
/**
 * Plugin Name: IATO MCP test: a hook that throws on publish
 * Description: TEST-ONLY must-use plugin for tests/wp-env/theme-builder-live.sh. While the option iato_mcp_test_template_throw is set, publishing an elementor_library post throws from save_post, as another plugin's hook might. Never ship this.
 */
add_action( 'save_post_elementor_library', function ( $post_id, $post ) {
	if ( get_option( 'iato_mcp_test_template_throw' ) && 'publish' === $post->post_status ) {
		throw new RuntimeException( 'iato-test: a hook failed on publish' );
	}
}, 10, 2 );
