<?php
/**
 * Plugin Name: IATO MCP test: menu locations
 * Description: TEST-ONLY must-use plugin for tests/wp-env/menu-tools-live.sh. Registers two menu locations on whatever theme is active, because block themes register none. Never ship this.
 */
add_action( 'after_setup_theme', function () {
	register_nav_menus( [
		'iato-live-a' => 'Live test A',
		'iato-live-b' => 'Live test B',
	] );
} );
