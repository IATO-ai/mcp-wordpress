<?php
/**
 * Build the Sprint A fixture pages through Elementor's own save path.
 *
 * Run inside wp-env as an administrator so Document::save() passes
 * is_editable_by_current_user():
 *
 *   npx @wordpress/env run cli wp eval-file \
 *     wp-content/plugins/mcp-wordpress/tests/wp-env/create-fixture-pages.php --user=admin
 *
 * Document::save() runs every node through its element class, so atomic nodes
 * are validated and canonicalised by Props_Parser exactly as the editor does
 * (elementor/modules/atomic-widgets/elements/base/has-atomic-base.php:170-181,
 * :247-283). The resulting _elementor_data is what tests/fixtures/elementor/*.json
 * are captured from. Prints a JSON map of page slug → post ID plus the
 * attachment ID.
 */

if ( ! class_exists( '\Elementor\Plugin' ) ) {
	WP_CLI::error( 'Elementor is not active.' );
}

$results = [];

// ── Media Library image (for the attachment-alt fallback) ───────────────────
$existing = get_posts( [
	'post_type'   => 'attachment',
	'name'        => 'iato-fixture-image',
	'post_status' => 'inherit',
	'numberposts' => 1,
	'fields'      => 'ids',
] );
if ( $existing ) {
	$attachment_id = (int) $existing[0];
} else {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$src = WP_PLUGIN_DIR . '/elementor/assets/images/placeholder.png';
	$tmp = wp_tempnam( 'iato-fixture-image.png' );
	copy( $src, $tmp );
	$attachment_id = media_handle_sideload( [ 'name' => 'iato-fixture-image.png', 'tmp_name' => $tmp ], 0, 'IATO fixture image' );
	if ( is_wp_error( $attachment_id ) ) {
		WP_CLI::error( 'Sideload failed: ' . $attachment_id->get_error_message() );
	}
	wp_update_post( [ 'ID' => $attachment_id, 'post_name' => 'iato-fixture-image' ] );
}
update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Library image alt text' );
$results['attachment_id'] = $attachment_id;

// ── Envelope helpers (shape: has-generate.php:12-22) ────────────────────────
$env = fn( string $type, $value ) => [ '$$type' => $type, 'value' => $value ];
$str = fn( string $v ) => $env( 'string', $v );
$txt = fn( string $v ) => $env( 'escaped-html', $v );
$url_link = fn( string $url, bool $blank = false ) => $env( 'link', [
	'destination'   => $env( 'url', $url ),
	'isTargetBlank' => $env( 'boolean', $blank ),
] );

// ── Atomic nodes ────────────────────────────────────────────────────────────
$atomic_heading = [
	'id'         => 'a7e4d01',
	'elType'     => 'widget',
	'widgetType' => 'e-heading',
	'settings'   => [
		'title' => $txt( 'Atomic H1 heading' ),
		'tag'   => $str( 'h1' ),
	],
	'elements'   => [],
];
$atomic_heading_default_tag = [
	'id'         => 'a7e4d02',
	'elType'     => 'widget',
	'widgetType' => 'e-heading',
	'settings'   => [
		'title' => $txt( 'Atomic heading with default tag' ),
	],
	'elements'   => [],
];
$atomic_paragraph = [
	'id'         => 'a7e4d03',
	'elType'     => 'widget',
	'widgetType' => 'e-paragraph',
	'settings'   => [
		'paragraph' => $txt( 'Atomic paragraph with <strong>inline HTML</strong> and a <a href="https://example.com/inline">link</a>.' ),
	],
	'elements'   => [],
];
$atomic_image_library = [
	'id'         => 'a7e4d04',
	'elType'     => 'widget',
	'widgetType' => 'e-image',
	'settings'   => [
		'image' => $env( 'image', [
			'src'  => $env( 'image-src', [
				'id'  => $env( 'image-attachment-id', $attachment_id ),
				'url' => null,
			] ),
			'size' => $str( 'full' ),
		] ),
	],
	'elements'   => [],
];
$atomic_image_external = [
	'id'         => 'a7e4d05',
	'elType'     => 'widget',
	'widgetType' => 'e-image',
	'settings'   => [
		'image' => $env( 'image', [
			'src'  => $env( 'image-src', [
				'id'  => null,
				'url' => $env( 'url', 'https://example.com/photos/external.jpg' ),
				'alt' => $str( 'External image alt text' ),
			] ),
			'size' => $str( 'full' ),
		] ),
		'link'  => $url_link( 'https://example.com/photo-target' ),
	],
	'elements'   => [],
];
$atomic_button = [
	'id'         => 'a7e4d06',
	'elType'     => 'widget',
	'widgetType' => 'e-button',
	'settings'   => [
		'text' => $txt( 'Atomic button' ),
		'link' => $url_link( 'https://example.com/go', true ),
	],
	'elements'   => [],
];
$atomic_button_no_link = [
	'id'         => 'a7e4d07',
	'elType'     => 'widget',
	'widgetType' => 'e-button',
	'settings'   => [
		'text' => $txt( 'Atomic button without link' ),
	],
	'elements'   => [],
];

$atomic_flexbox = fn( string $id, array $children, array $extra_settings = [] ) => [
	'id'       => $id,
	'elType'   => 'e-flexbox',
	'settings' => $extra_settings,
	'elements' => $children,
];
$atomic_div_block = fn( string $id, array $children ) => [
	'id'       => $id,
	'elType'   => 'e-div-block',
	'settings' => [],
	'elements' => $children,
];

// ── Classic nodes ───────────────────────────────────────────────────────────
$classic_heading = [
	'id'         => 'c1a5501',
	'elType'     => 'widget',
	'widgetType' => 'heading',
	'settings'   => [
		'title'       => 'Classic H2 heading',
		'header_size' => 'h2',
	],
	'elements'   => [],
];
$classic_text = [
	'id'         => 'c1a5502',
	'elType'     => 'widget',
	'widgetType' => 'text-editor',
	'settings'   => [
		'editor' => '<p>Classic text editor <em>content</em>.</p>',
	],
	'elements'   => [],
];
$classic_image = [
	'id'         => 'c1a5503',
	'elType'     => 'widget',
	'widgetType' => 'image',
	'settings'   => [
		'image' => [ 'id' => $attachment_id, 'url' => wp_get_attachment_image_url( $attachment_id, 'full' ) ],
	],
	'elements'   => [],
];
$classic_button = [
	'id'         => 'c1a5504',
	'elType'     => 'widget',
	'widgetType' => 'button',
	'settings'   => [
		'text' => 'Classic button',
		'link' => [ 'url' => 'https://example.com/classic', 'is_external' => 'on' ],
	],
	'elements'   => [],
];
$classic_container = fn( string $id, array $children ) => [
	'id'       => $id,
	'elType'   => 'container',
	'settings' => [],
	'elements' => $children,
];

// ── Pages ───────────────────────────────────────────────────────────────────
$pages = [
	'iato-fixture-classic-only' => [
		'title'    => 'IATO fixture: classic only',
		'elements' => [
			$classic_container( 'c1a5500', [ $classic_heading, $classic_text, $classic_image, $classic_button ] ),
		],
	],
	'iato-fixture-atomic-only'  => [
		'title'    => 'IATO fixture: atomic only',
		'elements' => [
			$atomic_flexbox( 'a7e4d00', [
				$atomic_heading,
				$atomic_heading_default_tag,
				$atomic_paragraph,
				$atomic_image_library,
				$atomic_image_external,
				$atomic_button,
				$atomic_button_no_link,
				$atomic_div_block( 'a7e4d10', [] ),
			] ),
			// A linked container: rendered tag follows the link (html-tag-computer.php:15-29).
			$atomic_flexbox( 'a7e4d20', [], [ 'link' => $url_link( 'https://example.com/container-link' ), 'tag' => $str( 'section' ) ] ),
		],
	],
	'iato-fixture-mixed'        => [
		'title'    => 'IATO fixture: mixed',
		'elements' => [
			$classic_container( 'm1x0001', [ $classic_heading, $classic_text ] ),
			$atomic_flexbox( 'm1x0002', [
				$atomic_heading,
				$atomic_paragraph,
				$atomic_image_library,
				$atomic_button,
				// Classic widget nested inside an atomic container.
				$classic_button,
			] ),
			$classic_container( 'm1x0003', [ $classic_image ] ),
		],
	],
];

foreach ( $pages as $slug => $spec ) {
	$found = get_posts( [ 'post_type' => 'page', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] );
	$post_id = $found ? (int) $found[0] : wp_insert_post( [
		'post_type'   => 'page',
		'post_title'  => $spec['title'],
		'post_name'   => $slug,
		'post_status' => 'publish',
	], true );
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( "Insert {$slug}: " . $post_id->get_error_message() );
	}

	$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
	if ( ! $document ) {
		WP_CLI::error( "No Elementor document for {$slug}" );
	}
	$document->set_is_built_with_elementor( true );

	try {
		$saved = $document->save( [ 'elements' => $spec['elements'], 'settings' => [] ] );
	} catch ( \Throwable $e ) {
		$results[ $slug ] = [ 'post_id' => $post_id, 'error' => get_class( $e ) . ': ' . $e->getMessage() ];
		continue;
	}
	$results[ $slug ] = [ 'post_id' => $post_id, 'saved' => $saved, 'current_user' => get_current_user_id() ];
}

WP_CLI::line( wp_json_encode( $results, JSON_PRETTY_PRINT ) );
