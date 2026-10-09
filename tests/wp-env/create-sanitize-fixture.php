<?php
/**
 * Seed the Elementor sanitising fixture page through Elementor's own save path,
 * as an administrator who holds unfiltered_html, so the page carries
 * human-authored raw HTML (an iframe and a script in an HTML widget) exactly
 * as a site editor would have left it.
 *
 *   npx @wordpress/env run cli wp eval-file \
 *     wp-content/plugins/mcp-wordpress/tests/wp-env/create-sanitize-fixture.php --user=admin
 *
 * Prints JSON: { page_id, ids: { heading, html, editor, button } }.
 */

if ( ! class_exists( '\Elementor\Plugin' ) ) {
	WP_CLI::error( 'Elementor is not active.' );
}
if ( ! current_user_can( 'unfiltered_html' ) ) {
	WP_CLI::error( 'Run with --user=admin (needs unfiltered_html).' );
}

$existing = get_posts( [ 'post_type' => 'page', 'name' => 'iato-sanitize-fixture', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
foreach ( $existing as $old ) {
	wp_delete_post( (int) $old, true );
}

$page_id = wp_insert_post( [
	'post_type'   => 'page',
	'post_status' => 'publish',
	'post_title'  => 'IATO sanitize fixture',
	'post_name'   => 'iato-sanitize-fixture',
	'post_author' => get_current_user_id(),
] );
update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );

$ids = [ 'container' => 'c0ntain', 'heading' => 'headng1', 'html' => 'htmlwdg', 'editor' => 'textedi', 'button' => 'buttonw' ];

$elements = [ [
	'id'       => $ids['container'],
	'elType'   => 'container',
	'settings' => [],
	'elements' => [
		[ 'id' => $ids['heading'], 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Human heading', 'header_size' => 'h2' ], 'elements' => [] ],
		[ 'id' => $ids['html'], 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<iframe src="https://maps.example.com/embed?q=b2l&z=12" width="300" height="200"></iframe><script>var humanAuthored = 1;</script>' ], 'elements' => [] ],
		[ 'id' => $ids['editor'], 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>Tom &amp; Jerry, by a human.</p>' ], 'elements' => [] ],
		[ 'id' => $ids['button'], 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Human button', 'link' => [ 'url' => 'https://example.com/?a=1&b=2', 'is_external' => '', 'nofollow' => '' ] ], 'elements' => [] ],
	],
] ];

$document = \Elementor\Plugin::$instance->documents->get( $page_id );
$saved    = $document->save( [ 'elements' => $elements ] );
$stored   = (string) get_post_meta( $page_id, '_elementor_data', true );

echo wp_json_encode( [
	'page_id'       => $page_id,
	'ids'           => $ids,
	'elementor_save' => $saved,
	'iframe_stored' => str_contains( $stored, '<iframe' ),
	'script_stored' => str_contains( $stored, '<script>' ),
	'meta_length'   => strlen( $stored ),
] ), "\n";
