<?php
/**
 * Test-only mu-plugin for restructure-live: what other plugins register on init and a
 * permalink change must keep. A rewrite rule and an endpoint (lost from the stored rules
 * when they are rebuilt in the request that changed the structure), a custom post type
 * with an archive and a taxonomy whose URLs sit under the structure's front. Copied into mu-plugins for
 * the run and removed by --cleanup. Never shipped (.distignore).
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function (): void {
		add_rewrite_tag( '%iato_probe%', '([0-9]+)' );
		// Top for requests (a bottom rule sits after the page catch-all, which takes it
		// under a structure that starts with a tag); bottom for what init() empties.
		add_rewrite_rule( '^iato-probe-rule/?$', 'index.php?iato_probe=1', 'top' );
		add_rewrite_rule( '^iato-probe-bottom/?$', 'index.php?iato_probe=2', 'bottom' );
		add_rewrite_endpoint( 'iato-probe-endpoint', EP_PERMALINK | EP_PAGES );
		register_post_type(
			'iato_probe_work',
			[
				'public'  => true,
				'label'       => 'Probe work',
				'has_archive' => true, // its rule is added once, with the front of the time
				'rewrite'     => [ 'slug' => 'probe-work', 'with_front' => true ],
			]
		);
		register_taxonomy(
			'iato_probe_topic',
			'post',
			[
				'public'  => true,
				'label'   => 'Probe topics',
				'rewrite' => [ 'slug' => 'probe-topic', 'with_front' => true ],
			]
		);
	}
);

add_action(
	'template_redirect',
	static function (): void {
		global $wp_query;
		if ( get_query_var( 'iato_probe' ) ) {
			status_header( 200 );
			echo 'IATO PROBE RULE OK';
			exit;
		}
		if ( isset( $wp_query->query_vars['iato-probe-endpoint'] ) ) {
			status_header( 200 );
			echo 'IATO PROBE ENDPOINT OK ' . (int) get_queried_object_id();
			exit;
		}
	}
);
