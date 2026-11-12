<?php
/**
 * Test-only mu-plugin for page-assets-live: says in a response header when a request
 * told LiteSpeed Cache not to cache it, since no local site runs a LiteSpeed server and
 * LiteSpeed Cache sends its own headers only there. Copied into mu-plugins for the run
 * and removed by --cleanup. Never shipped (.distignore).
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'litespeed_control_set_nocache',
	static function ( $reason = '' ): void {
		if ( ! headers_sent() ) {
			header( 'X-Iato-Test-Litespeed-Nocache: ' . preg_replace( '/[^A-Za-z0-9 .\-]/', '', (string) $reason ) );
		}
	},
	1
);
