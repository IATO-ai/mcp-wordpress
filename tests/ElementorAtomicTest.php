<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IATO_MCP_Elementor_Atomic: detection, envelope unwrapping,
 * default filling, tag computation and the normalised peek view.
 */
final class ElementorAtomicTest extends TestCase {

	protected function tearDown(): void {
		IATO_MCP_Elementor_Atomic::set_resolver( null );
	}

	private static function env( string $type, mixed $value, bool $disabled = false ): array {
		$e = [ '$$type' => $type, 'value' => $value ];
		if ( $disabled ) {
			$e['disabled'] = true;
		}
		return $e;
	}

	private static function widget( string $widget_type, array $settings, string $id = 'w000001' ): array {
		return [ 'id' => $id, 'elType' => 'widget', 'widgetType' => $widget_type, 'settings' => $settings, 'elements' => [] ];
	}

	// ── Detection ─────────────────────────────────────────────────────────

	public function test_detects_atomic_by_type_prefix(): void {
		$this->assertTrue( IATO_MCP_Elementor_Atomic::is_atomic( self::widget( 'e-heading', [] ) ) );
		$this->assertTrue( IATO_MCP_Elementor_Atomic::is_atomic( [ 'id' => 'x', 'elType' => 'e-flexbox', 'elements' => [] ] ) );
		$this->assertTrue( IATO_MCP_Elementor_Atomic::is_atomic( [ 'id' => 'x', 'elType' => 'e-div-block' ] ) );
		$this->assertFalse( IATO_MCP_Elementor_Atomic::is_atomic( self::widget( 'heading', [ 'title' => 'x' ] ) ) );
		$this->assertFalse( IATO_MCP_Elementor_Atomic::is_atomic( [ 'id' => 'x', 'elType' => 'container', 'settings' => [] ] ) );
		$this->assertFalse( IATO_MCP_Elementor_Atomic::is_atomic( [ 'id' => 'x', 'elType' => 'section' ] ) );
	}

	public function test_detects_atomic_by_envelope_when_prefix_missing(): void {
		$node = self::widget( 'third-party', [ 'title' => self::env( 'escaped-html', 'x' ) ] );
		$this->assertTrue( IATO_MCP_Elementor_Atomic::is_atomic( $node ) );
	}

	public function test_version_key_is_not_the_signal(): void {
		// Classic widget that somehow carries version must stay classic; atomic
		// without version (pre-save data) must stay atomic.
		$classic = self::widget( 'heading', [ 'title' => 'x' ] ) + [ 'version' => '0.0' ];
		$this->assertFalse( IATO_MCP_Elementor_Atomic::is_atomic( $classic ) );
		$atomic = self::widget( 'e-heading', [] );
		$this->assertTrue( IATO_MCP_Elementor_Atomic::is_atomic( $atomic ) );
	}

	public function test_document_schema(): void {
		$this->assertSame( 'empty', IATO_MCP_Elementor_Atomic::document_schema( [] ) );
		$this->assertSame( 'classic', IATO_MCP_Elementor_Atomic::document_schema( iato_test_fixture( 'classic-only' ) ) );
		$this->assertSame( 'atomic', IATO_MCP_Elementor_Atomic::document_schema( iato_test_fixture( 'atomic-only' ) ) );
		$this->assertSame( 'mixed', IATO_MCP_Elementor_Atomic::document_schema( iato_test_fixture( 'mixed' ) ) );
	}

	// ── Unwrap ────────────────────────────────────────────────────────────

	public function test_unwrap_text_family(): void {
		$u = [ IATO_MCP_Elementor_Atomic::class, 'unwrap' ];
		$this->assertSame( 'a', $u( self::env( 'string', 'a' ) ) );
		$this->assertSame( '<b>a</b>', $u( self::env( 'html', '<b>a</b>' ) ) );
		$this->assertSame( 'a', $u( self::env( 'escaped-html', 'a' ) ) );
		$this->assertSame( 'v2', $u( self::env( 'html-v2', [ 'content' => 'v2', 'children' => [] ] ) ) );
		$this->assertSame( 'v3', $u( self::env( 'html-v3', [ 'content' => self::env( 'string', 'v3' ), 'children' => [] ] ) ) );
		$this->assertNull( $u( self::env( 'html-v3', [ 'content' => null ] ) ) );
		$this->assertNull( $u( self::env( 'escaped-html', null ) ) );
	}

	public function test_unwrap_scalars_and_disabled(): void {
		$u = [ IATO_MCP_Elementor_Atomic::class, 'unwrap' ];
		$this->assertSame( 'https://x', $u( self::env( 'url', 'https://x' ) ) );
		$this->assertTrue( $u( self::env( 'boolean', true ) ) );
		$this->assertFalse( $u( self::env( 'boolean', false ) ) );
		$this->assertSame( 12, $u( self::env( 'number', 12 ) ) );
		$this->assertSame( 1.5, $u( self::env( 'number', '1.5' ) ) );
		$this->assertSame( 7, $u( self::env( 'image-attachment-id', '7' ) ) );
		$this->assertNull( $u( self::env( 'escaped-html', 'hidden', true ) ) );
		$this->assertSame( 'plain', $u( 'plain' ) );
		$this->assertSame( 3, $u( 3 ) );
		$this->assertNull( $u( null ) );
	}

	public function test_unwrap_structures(): void {
		$u = [ IATO_MCP_Elementor_Atomic::class, 'unwrap' ];
		// Object with nested envelopes.
		$this->assertSame(
			[ 'size' => 10, 'unit' => 'px' ],
			$u( self::env( 'size', [ 'size' => 10, 'unit' => 'px' ] ) )
		);
		// Lists drop nulls (disabled items), objects keep null keys.
		$this->assertSame(
			[ 1, 3 ],
			$u( [ self::env( 'number', 1 ), self::env( 'number', 2, true ), self::env( 'number', 3 ) ] )
		);
		$this->assertSame(
			[ 'a' => 'x', 'b' => null ],
			$u( [ 'a' => self::env( 'string', 'x' ), 'b' => self::env( 'string', 'y', true ) ] )
		);
		// Unknown $$type recurses into its payload instead of erroring.
		$this->assertSame(
			[ 'k' => 'v' ],
			$u( self::env( 'something-new', [ 'k' => self::env( 'string', 'v' ) ] ) )
		);
		$this->assertSame( 'scalar', $u( self::env( 'something-new', 'scalar' ) ) );
	}

	public function test_unwrap_special_types(): void {
		$u = [ IATO_MCP_Elementor_Atomic::class, 'unwrap' ];
		$this->assertSame( [ 'a', 'b' ], $u( self::env( 'classes', [ 'a', 'b', 3 ] ) ) );
		$this->assertSame(
			[ 'data-x' => '1', 'rel' => 'nofollow' ],
			$u( self::env( 'attributes', [
				self::env( 'key-value', [ 'key' => self::env( 'string', 'data-x' ), 'value' => self::env( 'string', '1' ) ] ),
				self::env( 'key-value', [ 'key' => self::env( 'string', '' ), 'value' => self::env( 'string', 'dropped' ) ] ),
				self::env( 'key-value', [ 'key' => self::env( 'string', 'rel' ), 'value' => self::env( 'string', 'nofollow' ) ] ),
			] ) )
		);
		$this->assertSame(
			[ 'post_id' => 42, 'label' => 'Sample' ],
			$u( self::env( 'query', [ 'id' => self::env( 'number', 42 ), 'label' => self::env( 'string', 'Sample' ) ] ) )
		);
		$this->assertSame(
			[ '$dynamic' => [ 'name' => 'post-title', 'group' => 'post', 'settings' => [ 'before' => 'Pre' ] ] ],
			$u( self::env( 'dynamic', [ 'name' => 'post-title', 'group' => 'post', 'settings' => [ 'before' => self::env( 'string', 'Pre' ) ] ] ) )
		);
	}

	public function test_unwrap_depth_cap_terminates(): void {
		$deep = 'leaf';
		for ( $i = 0; $i < 60; $i++ ) {
			$deep = self::env( 'wrap', [ 'inner' => $deep ] );
		}
		$out = IATO_MCP_Elementor_Atomic::unwrap( $deep );
		// Shallow levels unwrap normally; past the cap the branch is cut to null
		// instead of recursing further. The leaf is 120 levels down so it is gone.
		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'inner', $out );
		$walk = $out;
		for ( $i = 0; $i < 100 && is_array( $walk ); $i++ ) {
			$walk = $walk['inner'] ?? null;
		}
		$this->assertNull( $walk );
	}

	// ── Defaults ──────────────────────────────────────────────────────────

	public function test_plain_settings_fills_unsaved_defaults(): void {
		$r = IATO_MCP_Elementor_Atomic::plain_settings( self::widget( 'e-heading', [ 'title' => self::env( 'escaped-html', 'Hi' ) ] ) );
		$this->assertSame( 'h2', $r['settings']['tag'] );
		$this->assertSame( 'Hi', $r['settings']['title'] );
		$this->assertSame( [ 'tag' ], $r['defaulted_keys'] );

		$r = IATO_MCP_Elementor_Atomic::plain_settings( self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ) ] ) );
		$this->assertSame( 'h1', $r['settings']['tag'] );
		$this->assertSame( 'This is a title', $r['settings']['title'] );
		$this->assertSame( [ 'title' ], $r['defaulted_keys'] );

		$r = IATO_MCP_Elementor_Atomic::plain_settings( self::widget( 'e-paragraph', [] ) );
		$this->assertSame( [ 'tag' => 'p', 'paragraph' => 'Type your paragraph here' ], $r['settings'] );

		$r = IATO_MCP_Elementor_Atomic::plain_settings( [ 'id' => 'c', 'elType' => 'e-flexbox' ] );
		$this->assertSame( [ 'tag' => 'div' ], $r['settings'] );
		$this->assertSame( [ 'tag' ], $r['defaulted_keys'] );
	}

	public function test_plain_settings_unknown_type_has_no_defaults(): void {
		$r = IATO_MCP_Elementor_Atomic::plain_settings( self::widget( 'e-mystery', [ 'x' => self::env( 'string', '1' ) ] ) );
		$this->assertSame( [ 'x' => '1' ], $r['settings'] );
		$this->assertSame( [], $r['defaulted_keys'] );
	}

	// ── Tag ───────────────────────────────────────────────────────────────

	public function test_compute_tag(): void {
		$t = [ IATO_MCP_Elementor_Atomic::class, 'compute_tag' ];
		$link = [ 'destination' => 'https://x', 'isTargetBlank' => false ];
		$this->assertSame( 'h1', $t( 'e-heading', [ 'tag' => 'h1' ] ) );
		$this->assertSame( 'h2', $t( 'e-heading', [] ) );
		$this->assertSame( 'h3', $t( 'e-heading', [ 'tag' => 'h3', 'link' => $link ] ), 'heading does not follow link' );
		$this->assertSame( 'p', $t( 'e-paragraph', [] ) );
		$this->assertSame( 'span', $t( 'e-paragraph', [ 'tag' => 'span' ] ) );
		$this->assertSame( 'img', $t( 'e-image', [ 'link' => $link ] ) );
		$this->assertSame( 'button', $t( 'e-button', [] ) );
		$this->assertSame( 'a', $t( 'e-button', [ 'link' => $link ] ) );
		$this->assertSame( 'button', $t( 'e-button', [ 'link' => $link + [ 'tag' => 'button' ] ] ) );
		$this->assertSame( 'div', $t( 'e-flexbox', [] ) );
		$this->assertSame( 'section', $t( 'e-flexbox', [ 'tag' => 'section' ] ) );
		$this->assertSame( 'a', $t( 'e-flexbox', [ 'tag' => 'section', 'link' => $link ] ), 'container follows link' );
		$this->assertSame( 'div', $t( 'e-div-block', [] ) );
		$this->assertSame( 'hr', $t( 'e-divider', [] ) );
		$this->assertSame( 'ul', $t( 'e-list', [] ) );
		$this->assertNull( $t( 'e-mystery', [] ) );
		$this->assertSame( 'aside', $t( 'e-mystery', [ 'tag' => 'aside' ] ) );
	}

	// ── Normalised view ───────────────────────────────────────────────────

	public function test_normalize_heading_paragraph_button(): void {
		$h = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-heading', [ 'title' => self::env( 'escaped-html', 'Hello' ), 'tag' => self::env( 'string', 'h1' ) ] ) );
		$this->assertSame( [ 'schema' => 'atomic', 'tag' => 'h1', 'title' => 'Hello', 'header_size' => 'h1' ], $h );

		$p = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-paragraph', [ 'paragraph' => self::env( 'escaped-html', str_repeat( 'x', 100 ) ) ] ) );
		$this->assertSame( 'p', $p['tag'] );
		$this->assertSame( str_repeat( 'x', 80 ) . '…', $p['editor'], 'truncated like classic peek' );

		$b = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-button', [
			'text' => self::env( 'escaped-html', 'Go' ),
			'link' => self::env( 'link', [ 'destination' => self::env( 'url', 'https://x/go' ), 'isTargetBlank' => self::env( 'boolean', true ) ] ),
		] ) );
		$this->assertSame( [ 'schema' => 'atomic', 'tag' => 'a', 'text' => 'Go', 'link' => 'https://x/go', 'link_new_tab' => true ], $b );
	}

	public function test_normalize_image_library_alt_from_attachment(): void {
		$n = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-image', [
			'image' => self::env( 'image', [
				'src'  => self::env( 'image-src', [ 'id' => self::env( 'image-attachment-id', 5 ), 'url' => null ] ),
				'size' => self::env( 'string', 'full' ),
			] ),
		] ) );
		$this->assertSame( 'img', $n['tag'] );
		$this->assertSame( 5, $n['image_id'] );
		$this->assertSame( 'Library image alt text', $n['image_alt'] );
		$this->assertSame( 'attachment', $n['image_alt_source'] );
		$this->assertSame( IATO_Test_WP::$attachments[5]['url'], $n['image_url'] );
	}

	public function test_normalize_image_external_alt_from_element(): void {
		$n = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-image', [
			'image' => self::env( 'image', [
				'src'  => self::env( 'image-src', [ 'id' => null, 'url' => self::env( 'url', 'https://cdn/x.jpg' ), 'alt' => self::env( 'string', 'Ext alt' ) ] ),
				'size' => self::env( 'string', 'full' ),
			] ),
		] ) );
		$this->assertNull( $n['image_id'] );
		$this->assertSame( 'https://cdn/x.jpg', $n['image_url'] );
		$this->assertSame( 'Ext alt', $n['image_alt'] );
		$this->assertSame( 'element', $n['image_alt_source'] );
	}

	public function test_normalize_missing_attachment_degrades_gracefully(): void {
		$n = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-image', [
			'image' => self::env( 'image', [ 'src' => self::env( 'image-src', [ 'id' => self::env( 'image-attachment-id', 999 ) ] ), 'size' => self::env( 'string', 'medium' ) ] ),
		] ) );
		$this->assertSame( 999, $n['image_id'] );
		$this->assertNull( $n['image_url'] );
		$this->assertSame( '', $n['image_alt'] );
	}

	public function test_normalize_query_link_resolves_permalink(): void {
		$n = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-button', [
			'text' => self::env( 'escaped-html', 'Post' ),
			'link' => self::env( 'link', [
				'destination' => self::env( 'query', [ 'id' => self::env( 'number', 42 ), 'label' => self::env( 'string', 'Sample' ) ] ),
				'tag'         => self::env( 'string', 'button' ),
			] ),
		] ) );
		$this->assertSame( 'http://localhost:8888/sample-page/', $n['link'] );
		$this->assertSame( 42, $n['link_post_id'] );
		$this->assertFalse( $n['link_new_tab'] );
		$this->assertSame( 'button', $n['tag'] );
	}

	public function test_normalize_dynamic_title(): void {
		$n = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-heading', [
			'title' => self::env( 'dynamic', [ 'name' => 'post-title', 'group' => 'post', 'settings' => [] ] ),
		] ) );
		$this->assertSame( 'post-title', $n['title_dynamic'] );
		$this->assertArrayNotHasKey( 'title', $n );
	}

	public function test_normalize_unknown_type_does_not_error(): void {
		$n = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-mystery', [ 'thing' => self::env( 'blob', [ 'a' => 1 ] ) ] ) );
		$this->assertSame( [ 'schema' => 'atomic' ], $n );
		$n = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-future-heading', [ 'title' => self::env( 'escaped-html', 'T' ), 'tag' => self::env( 'string', 'h5' ) ] ) );
		$this->assertSame( [ 'schema' => 'atomic', 'tag' => 'h5', 'title' => 'T', 'header_size' => 'h5' ], $n );
	}

	public function test_normalize_without_settings_key(): void {
		// Elementor omits `settings` entirely on untouched containers.
		$n = IATO_MCP_Elementor_Atomic::normalize( [ 'id' => 'c', 'elType' => 'e-flexbox', 'elements' => [], 'isInner' => false, 'version' => '0.0' ] );
		$this->assertSame( [ 'schema' => 'atomic', 'tag' => 'div' ], $n );
	}

	public function test_resolver_injection(): void {
		IATO_MCP_Elementor_Atomic::set_resolver( function ( string $op, ...$args ) {
			return match ( $op ) {
				'attachment_alt' => "alt-for-{$args[0]}",
				'attachment_url' => "url-for-{$args[0]}-{$args[1]}",
				'permalink'      => "perma-{$args[0]}",
				default          => null,
			};
		} );
		$n = IATO_MCP_Elementor_Atomic::normalize( self::widget( 'e-image', [
			'image' => self::env( 'image', [ 'src' => self::env( 'image-src', [ 'id' => self::env( 'image-attachment-id', 7 ) ] ), 'size' => self::env( 'string', 'large' ) ] ),
		] ) );
		$this->assertSame( 'alt-for-7', $n['image_alt'] );
		$this->assertSame( 'url-for-7-large', $n['image_url'] );
	}

	public function test_match_settings_exposes_derived_keys(): void {
		$m = IATO_MCP_Elementor_Atomic::match_settings( self::widget( 'e-heading', [ 'title' => self::env( 'escaped-html', 'T' ), 'tag' => self::env( 'string', 'h1' ) ] ) );
		$this->assertSame( 'h1', $m['header_size'] );
		$this->assertSame( 'T', $m['title'] );
		$m = IATO_MCP_Elementor_Atomic::match_settings( self::widget( 'e-paragraph', [ 'paragraph' => self::env( 'escaped-html', 'P' ) ] ) );
		$this->assertSame( 'P', $m['editor'] );
	}

	// ── Stored-value check and write guard (1.13.1) ───────────────────────

	/**
	 * A resolver standing in for Elementor's registry: $registered maps a type to
	 * its props schema (array) or to null (registered, classic class, no schema);
	 * types not listed are unregistered. $asked counts registry lookups.
	 */
	private static function with_registry( array $registered, ?array &$asked = null ): void {
		$asked = [];
		IATO_MCP_Elementor_Atomic::set_resolver( function ( string $op, ...$args ) use ( $registered, &$asked ) {
			if ( 'type_info' !== $op ) {
				return null;
			}
			$type    = 'widget' === $args[0] ? $args[1] : $args[0];
			$asked[] = $type;
			return array_key_exists( $type, $registered )
				? [ 'registered' => true, 'schema' => $registered[ $type ] ]
				: [ 'registered' => false, 'schema' => null ];
		} );
	}

	private const HEADING_SCHEMA = [
		'tag'   => [ 'type' => 'string', 'kind' => 'plain' ],
		'title' => [ 'type' => 'escaped-html', 'kind' => 'plain' ],
		'link'  => [ 'type' => 'link', 'kind' => 'object' ],
	];
	private const IMAGE_SCHEMA = [
		'image' => [ 'type' => 'image', 'kind' => 'object' ],
		'link'  => [ 'type' => 'link', 'kind' => 'object' ],
	];
	private const PARAGRAPH_SCHEMA = [ 'paragraph' => [ 'type' => 'escaped-html', 'kind' => 'plain' ], 'tag' => [ 'type' => 'string', 'kind' => 'plain' ] ];
	private const FLEXBOX_SCHEMA   = [ 'tag' => [ 'type' => 'string', 'kind' => 'plain' ], 'link' => [ 'type' => 'link', 'kind' => 'object' ] ];

	/** A registry like Elementor 4.3 with the Atomic Editor on. */
	private static function atomic_site( ?array &$asked = null ): void {
		self::with_registry( [ 'e-heading' => self::HEADING_SCHEMA, 'e-image' => self::IMAGE_SCHEMA, 'e-paragraph' => self::PARAGRAPH_SCHEMA, 'e-flexbox' => self::FLEXBOX_SCHEMA, 'heading' => null, 'e-iframe' => null ], $asked );
	}

	/** The shapes IATO 1.13.0 stored (Sprint C Step 0, rows R2, R4, R7, R8). */
	private static function damaged_heading(): array {
		return self::widget( 'e-heading', [ 'tag' => 'h3', 'title' => self::env( 'escaped-html', 'Atomic H1 heading' ) ], 'a7e4d01' );
	}
	private static function damaged_button(): array {
		return self::widget( 'e-button', [ 'text' => self::env( 'escaped-html', 'Atomic button' ), 'link' => [ 'destination' => 'https://example.com/new' ] ], 'a7e4d06' );
	}
	private static function damaged_image(): array {
		return self::widget( 'e-image', [ 'image' => [ 'src' => [ 'url' => 'https://example.com/photos/external.jpg', 'alt' => 'New alt' ] ] ], 'a7e4d05' );
	}
	/** e-addons registers classic widgets with e- names (e-iframe). */
	private static function eaddons_iframe(): array {
		return self::widget( 'e-iframe', [ 'url' => 'https://maps.example.test/', 'height' => [ 'unit' => 'px', 'size' => 400 ] ], 'ei1' );
	}

	public function test_detection_when_elementor_knows_the_type_its_class_decides(): void {
		self::atomic_site();
		$this->assertTrue( IATO_MCP_Elementor_Atomic::has_atomic_type( self::widget( 'e-heading', [] ) ), 'registered with a schema, even with no settings' );
		$this->assertFalse( IATO_MCP_Elementor_Atomic::has_atomic_type( self::eaddons_iframe() ), 'e-iframe: registered, classic class' );
		$this->assertFalse( IATO_MCP_Elementor_Atomic::has_atomic_type( self::widget( 'e-iframe', [ 'url' => self::env( 'string', 'x' ) ] ) ), 'even with envelope-looking settings' );
		self::with_registry( [ 'future-heading' => self::HEADING_SCHEMA ] );
		$this->assertTrue( IATO_MCP_Elementor_Atomic::has_atomic_type( self::widget( 'future-heading', [ 'tag' => self::env( 'string', 'h2' ) ] ) ), 'a registered schema counts without the prefix' );
	}

	public function test_detection_when_elementor_cannot_say_needs_prefix_and_envelope(): void {
		foreach ( [ 'registry: type unregistered' => fn() => self::with_registry( [] ), 'no registry at all' => fn() => IATO_MCP_Elementor_Atomic::set_resolver( null ) ] as $label => $setup ) {
			$setup();
			$this->assertTrue( IATO_MCP_Elementor_Atomic::has_atomic_type( self::damaged_heading() ), "{$label}: e- prefix and an envelope" );
			$this->assertFalse( IATO_MCP_Elementor_Atomic::has_atomic_type( self::damaged_image() ), "{$label}: e- prefix, plain settings only" );
			$this->assertFalse( IATO_MCP_Elementor_Atomic::has_atomic_type( self::eaddons_iframe() ), "{$label}: a deactivated e-addons widget" );
			$this->assertFalse( IATO_MCP_Elementor_Atomic::has_atomic_type( self::widget( 'addon-heading', [ 'title' => self::env( 'string', 'looks like an envelope' ) ] ) ), "{$label}: envelope without the prefix" );
			$this->assertFalse( IATO_MCP_Elementor_Atomic::has_atomic_type( self::widget( 'heading', [ 'title' => 'x' ] ) ), "{$label}: classic" );
		}
	}

	public function test_eaddons_style_widget_is_classic_for_writes_and_reads(): void {
		self::atomic_site();
		$node = self::eaddons_iframe();
		$this->assertSame( [], IATO_MCP_Elementor_Atomic::invalid_keys( $node ), 'registered: no flag' );
		$this->assertSame( [], IATO_MCP_Elementor_Adapter::invalid_elements( [ $node ] ) );
		$this->assertStringNotContainsString( 'stored_value_invalid', json_encode( IATO_MCP_Elementor_Adapter::flatten_widgets( [ $node ], 'flat' ) ) );
		self::with_registry( [] ); // the addon deactivated
		$this->assertSame( [], IATO_MCP_Elementor_Atomic::invalid_keys( $node ), 'unregistered with plain settings: still no flag' );
		$this->assertFalse( IATO_MCP_Elementor_Atomic::has_atomic_type( $node ) );
	}

	public function test_read_marker_follows_the_registered_class(): void {
		self::atomic_site();
		$this->assertFalse( IATO_MCP_Elementor_Atomic::is_atomic( self::eaddons_iframe() ), 'registered classic e-iframe: schema classic' );
		$this->assertSame( 'classic', IATO_MCP_Elementor_Atomic::schema_of( self::eaddons_iframe() ) );
		$flat = IATO_MCP_Elementor_Adapter::flatten_widgets( [ self::eaddons_iframe() ], 'flat' );
		$this->assertSame( 'classic', $flat[0]['schema'] );
		$this->assertArrayNotHasKey( 'tag', $flat[0], 'no atomic peek fields' );
		$this->assertTrue( IATO_MCP_Elementor_Atomic::is_atomic( self::damaged_heading() ), 'registered atomic: still atomic' );
		self::with_registry( [] );
		$this->assertTrue( IATO_MCP_Elementor_Atomic::is_atomic( self::eaddons_iframe() ), 'Elementor cannot say: the Sprint A reader rule (prefix) is kept for the marker' );
	}

	public function test_replacing_every_setting_with_plain_values_is_refused_when_elementor_cannot_say(): void {
		IATO_MCP_Elementor_Atomic::set_resolver( null ); // Atomic Editor off: no registry answer
		$stored = [ self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ), 'title' => self::env( 'escaped-html', 'T' ) ], 'h1' ) ];
		$new    = $stored;
		$new[0]['settings'] = [ 'tag' => 'h3', 'title' => 'T' ]; // replace …/settings with plain values only
		$r = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $new );
		$this->assertSame( [ 'tag', 'title' ], $r['problems'][0]['plain_keys'] ?? null, 'recognised by its stored envelopes' );
	}

	public function test_reading_a_classic_page_never_asks_the_registry(): void {
		self::atomic_site( $asked );
		$tree = iato_test_fixture( 'classic-only' );
		IATO_MCP_Elementor_Adapter::flatten_widgets( $tree, 'flat' );
		IATO_MCP_Elementor_Adapter::flatten_widgets( $tree, 'tree' );
		IATO_MCP_Elementor_Adapter::invalid_elements( $tree );
		IATO_MCP_Elementor_Adapter::find_by_filter( $tree, [ 'type' => 'heading' ] );
		IATO_MCP_Elementor_Atomic::tree_changes( $tree, $tree );
		$this->assertSame( [], $asked, 'no registry lookup for classic elements' );
		IATO_MCP_Elementor_Adapter::invalid_elements( iato_test_fixture( 'atomic-only' ) );
		$this->assertNotEmpty( $asked, 'atomic elements are looked up' );
	}

	public function test_invalid_keys_without_a_schema(): void {
		$this->assertSame( [ 'tag' ], IATO_MCP_Elementor_Atomic::invalid_keys( self::damaged_heading() ) );
		$this->assertSame( [ 'link' ], IATO_MCP_Elementor_Atomic::invalid_keys( self::damaged_button() ) );
		$this->assertSame( [], IATO_MCP_Elementor_Atomic::invalid_keys( self::damaged_image() ), 'plain settings only: Elementor cannot say it is atomic' );
		$this->assertSame( [ 'header_size' ], IATO_MCP_Elementor_Atomic::invalid_keys( self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ), 'header_size' => 'h3' ] ) ) );
		$this->assertSame( [], IATO_MCP_Elementor_Atomic::invalid_keys( self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ), 'title' => null ] ) ), 'null is not a stored value' );
		$this->assertSame( [], IATO_MCP_Elementor_Atomic::invalid_keys( self::widget( 'heading', [ 'title' => 'x', 'header_size' => 'h2' ] ) ), 'classic widgets are never flagged' );
		$this->assertSame( [], IATO_MCP_Elementor_Atomic::invalid_keys( self::widget( 'addon-heading', [ 'title' => 'plain', 'x' => self::env( 'string', 'y' ) ] ) ), 'nor addon widgets that look atomic' );
	}

	public function test_invalid_keys_with_a_schema(): void {
		self::atomic_site();
		$node = self::widget( 'e-heading', [ 'tag' => 'h3', 'title' => self::env( 'escaped-html', 'T' ), 'header_size' => 'h3' ] );
		$this->assertSame( [ 'tag' ], IATO_MCP_Elementor_Atomic::invalid_keys( $node ), 'header_size is not part of the element, Elementor ignores it' );
		$this->assertSame( [ 'image' ], IATO_MCP_Elementor_Atomic::invalid_keys( self::damaged_image() ), 'a fully plain element is flagged when Elementor knows its class' );
		$this->assertSame( [ 'settings' ], IATO_MCP_Elementor_Atomic::invalid_keys( [ 'id' => 'x', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => 'h3' ] ) );
	}

	public function test_null_or_missing_settings_are_not_flagged(): void {
		self::atomic_site();
		$this->assertSame( [], IATO_MCP_Elementor_Atomic::invalid_keys( [ 'id' => 'x', 'elType' => 'e-flexbox', 'settings' => null, 'elements' => [] ] ) );
		$this->assertSame( [], IATO_MCP_Elementor_Atomic::invalid_keys( [ 'id' => 'x', 'elType' => 'e-flexbox', 'elements' => [] ] ) );
	}

	public function test_valid_fixture_pages_produce_no_flags(): void {
		foreach ( [ 'mode B' => fn() => IATO_MCP_Elementor_Atomic::set_resolver( null ), 'with a schema' => fn() => self::atomic_site() ] as $label => $setup ) {
			$setup();
			foreach ( [ 'atomic-only', 'mixed', 'classic-only', 'legacy-and-edge-cases' ] as $fixture ) {
				$tree = iato_test_fixture( $fixture );
				$this->assertSame( [], IATO_MCP_Elementor_Adapter::invalid_elements( $tree ), "{$label} {$fixture}" );
				$this->assertStringNotContainsString( 'stored_value_invalid', json_encode( IATO_MCP_Elementor_Adapter::flatten_widgets( $tree, 'flat' ) ), "{$label} {$fixture}" );
				$this->assertStringNotContainsString( 'stored_value_invalid', json_encode( IATO_MCP_Elementor_Adapter::flatten_widgets( $tree, 'tree' ) ), "{$label} {$fixture}" );
			}
		}
	}

	public function test_damaged_values_are_reported_as_stored_and_nothing_is_derived_from_them(): void {
		self::atomic_site();
		$h = IATO_MCP_Elementor_Atomic::normalize( self::damaged_heading() );
		$this->assertTrue( $h['stored_value_invalid'] );
		$this->assertSame( [ 'tag' ], $h['invalid_keys'] );
		$this->assertArrayNotHasKey( 'tag', $h, 'no rendered tag guessed from a value Elementor cannot read' );
		$this->assertArrayNotHasKey( 'header_size', $h );
		$this->assertSame( 'Atomic H1 heading', $h['title'], 'valid settings still peek' );

		IATO_MCP_Elementor_Atomic::set_resolver( null ); // e-button: prefix + envelope, flagged without a registry
		$b = IATO_MCP_Elementor_Atomic::normalize( self::damaged_button() );
		$this->assertSame( [ 'link' ], $b['invalid_keys'] );
		$this->assertArrayNotHasKey( 'link', $b );
		$this->assertArrayNotHasKey( 'link_new_tab', $b );
		$this->assertSame( 'button', $b['tag'], 'Elementor renders a button: the link it cannot read is ignored' );

		self::atomic_site();
		$i = IATO_MCP_Elementor_Atomic::normalize( self::damaged_image() );
		$this->assertSame( [ 'image' ], $i['invalid_keys'] );
		$this->assertArrayNotHasKey( 'image_url', $i );
		$this->assertArrayNotHasKey( 'image_alt', $i );

		$partly = self::widget( 'e-button', [ 'text' => self::env( 'escaped-html', 'Go' ), 'link' => [ 'destination' => self::env( 'url', 'https://x.test/' ) ] ] );
		IATO_MCP_Elementor_Atomic::set_resolver( null );
		$this->assertSame( [ 'destination' => self::env( 'url', 'https://x.test/' ) ], IATO_MCP_Elementor_Atomic::plain_settings( $partly )['settings']['link'], 'the stored value, not unwrapped' );
		$m = IATO_MCP_Elementor_Atomic::match_settings( self::damaged_heading() );
		$this->assertArrayNotHasKey( 'header_size', $m );
		$this->assertSame( 'h3', $m['tag'], 'filters still see the stored value' );
	}

	public function test_invalid_elements_lists_damage_anywhere_in_the_tree(): void {
		self::atomic_site();
		$tree = [ [ 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => [ 'tag' => 'section' ], 'elements' => [ self::damaged_heading(), self::widget( 'heading', [ 'title' => 'classic' ] ), self::damaged_image(), self::eaddons_iframe() ] ] ];
		$this->assertSame( [
			[ 'widget_id' => 'f1', 'type' => 'e-flexbox', 'invalid_keys' => [ 'tag' ] ],
			[ 'widget_id' => 'a7e4d01', 'type' => 'e-heading', 'invalid_keys' => [ 'tag' ] ],
			[ 'widget_id' => 'a7e4d05', 'type' => 'e-image', 'invalid_keys' => [ 'image' ] ],
		], IATO_MCP_Elementor_Adapter::invalid_elements( $tree ) );
	}

	public function test_patch_problems(): void {
		$node = self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ) ] );
		$this->assertSame( [ 'plain_keys' => [ 'tag', 'title' ], 'unknown_keys' => [] ], IATO_MCP_Elementor_Atomic::patch_problems( $node, [ 'tag' => 'h3', 'title' => null ] ) );
		$this->assertSame( [ 'plain_keys' => [], 'unknown_keys' => [] ], IATO_MCP_Elementor_Atomic::patch_problems( $node, [ 'tag' => self::env( 'string', 'h3' ) ] ) );
		$this->assertSame( [ 'plain_keys' => [], 'unknown_keys' => [] ], IATO_MCP_Elementor_Atomic::patch_problems( $node, [ 'header_size' => self::env( 'string', 'h3' ) ] ), 'without a schema an envelope passes' );
		self::atomic_site();
		$this->assertSame( [ 'plain_keys' => [ 'tag' ], 'unknown_keys' => [ 'header_size' ] ], IATO_MCP_Elementor_Atomic::patch_problems( $node, [ 'header_size' => self::env( 'string', 'h3' ), 'tag' => 'h3' ] ) );
	}

	public function test_a_type_change_to_an_atomic_type_checks_every_setting(): void {
		self::atomic_site();
		$stored = [ self::widget( 'heading', [ 'title' => 'Hello' ], 'c1' ), self::widget( 'image', [ 'image' => [ 'url' => 'https://x.test/a.jpg' ] ], 'c2' ) ];
		$new    = $stored;
		$new[0]['widgetType'] = 'e-heading'; // replace /0/widgetType, settings untouched
		$new[1]['widgetType'] = 'e-image';
		$r = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $new );
		$this->assertSame( [ 'c1', 'c2' ], $r['changed_ids'] );
		$this->assertSame( [ [ 'title' ], [ 'image' ] ], array_column( $r['problems'], 'plain_keys' ) );
		$empty = [ self::widget( 'heading', [], 'c3' ) ];
		$to    = $empty;
		$to[0]['widgetType'] = 'e-heading';
		$this->assertSame( [ 'c3' ], IATO_MCP_Elementor_Atomic::tree_changes( $empty, $to )['changed_ids'], 'a type change with no settings is still validated' );
	}

	public function test_tree_changes_reports_only_what_the_change_would_store(): void {
		self::atomic_site();
		$stored = [ [ 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [
			self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ), 'title' => self::env( 'escaped-html', 'T' ) ], 'h1' ),
			self::damaged_image(),
			self::widget( 'heading', [ 'title' => 'classic' ], 'c1' ),
		] ] ];

		$value_path = $stored;
		$value_path[0]['elements'][0]['settings']['tag']['value'] = 'h3'; // replace …/settings/tag/value
		$r = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $value_path );
		$this->assertSame( [], $r['problems'] );
		$this->assertSame( [ 'h1' ], $r['changed_ids'] );

		$plain = $stored;
		$plain[0]['elements'][0]['settings']['tag'] = 'h3'; // replace …/settings/tag
		$plain[0]['elements'][2]['settings']['title'] = 'Classic edited'; // classic: not checked
		$r = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $plain );
		$this->assertSame( [ [ 'element_id' => 'h1', 'type' => 'e-heading', 'plain_keys' => [ 'tag' ], 'unknown_keys' => [] ] ], $r['problems'] );

		$removed = $stored;
		unset( $removed[0]['elements'][0]['settings']['title'] );
		$r = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $removed );
		$this->assertSame( [], $r['problems'], 'remove is allowed' );
		$this->assertSame( [ 'h1' ], $r['changed_ids'] );

		$whole = $stored;
		$whole[0]['elements'][0]['settings'] = [ 'tag' => 'h3', 'title' => self::env( 'escaped-html', 'T' ) ];
		$this->assertSame( [ 'tag' ], IATO_MCP_Elementor_Atomic::tree_changes( $stored, $whole )['problems'][0]['plain_keys'], 'a whole settings object with a plain value' );

		$added = $stored;
		$added[0]['elements'][] = self::widget( 'e-paragraph', [ 'paragraph' => 'plain text' ], 'new1' );
		$this->assertSame( 'new1', IATO_MCP_Elementor_Atomic::tree_changes( $stored, $added )['problems'][0]['element_id'], 'a new atomic element with plain settings' );

		$r = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $stored );
		$this->assertSame( [ [], [] ], [ $r['problems'], $r['changed_ids'] ], 'an element damaged earlier and left unchanged blocks nothing' );
	}

	public function test_a_copy_keeping_its_id_counts_as_new(): void {
		self::atomic_site();
		$stored = [ [ 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [ self::damaged_image(), self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ) ], 'ok1' ) ] ] ];
		$copied = $stored;
		$copied[0]['elements'][] = self::damaged_image(); // RFC 6902 copy of the damaged image, same id
		$r = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $copied );
		$this->assertSame( [ 'a7e4d05' ], array_values( array_unique( array_column( $r['problems'], 'element_id' ) ) ), 'spreading the damage is refused' );
		$this->assertSame( [ 'a7e4d05' ], $r['changed_ids'] );
		$valid = $stored;
		$valid[0]['elements'][] = $stored[0]['elements'][1]; // copy of a valid heading
		$r = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $valid );
		$this->assertSame( [], $r['problems'] );
		$this->assertSame( [ 'ok1' ], $r['changed_ids'], 'a copy of a valid element is validated' );
		$moved = [ [ 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [ $stored[0]['elements'][1], $stored[0]['elements'][0] ] ] ];
		$this->assertSame( [ [], [] ], array_values( IATO_MCP_Elementor_Atomic::tree_changes( $stored, $moved ) ), 'a move keeps one copy of each id: unchanged' );
	}

	public function test_a_copy_is_checked_like_the_read_flag(): void {
		self::atomic_site();
		$source = self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ), 'title' => null, 'header_size' => self::env( 'string', 'h3' ) ], 'src1' );
		$stored = [ [ 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [ $source ] ] ];
		$copied = $stored;
		$copied[0]['elements'][] = $source;
		$r = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $copied );
		$this->assertSame( [], $r['problems'], 'null values and non-schema keys copied verbatim pass, as the read flag ignores them' );
		$this->assertSame( [ 'src1' ], $r['changed_ids'], 'the copy is still validated' );
		$fixed_copy = $copied;
		$fixed_copy[0]['elements'][1]['settings']['tag'] = 'h2'; // the copy given a plain value
		$this->assertSame( [ 'tag' ], IATO_MCP_Elementor_Atomic::tree_changes( $stored, $fixed_copy )['problems'][0]['plain_keys'] ?? null );
	}

	public function test_envelope_example(): void {
		$node = self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ), 'link' => self::env( 'link', [ 'destination' => self::env( 'url', 'https://a.test/' ) ] ) ] );
		$this->assertSame( self::env( 'string', 'h3' ), IATO_MCP_Elementor_Atomic::envelope_example( $node, 'tag', 'h3' ), 'stored $$type with the value sent' );
		$this->assertSame( $node['settings']['link'], IATO_MCP_Elementor_Atomic::envelope_example( $node, 'link', [ 'destination' => 'https://b.test/' ] ), 'object props: the stored envelope as the template' );
		$this->assertNull( IATO_MCP_Elementor_Atomic::envelope_example( $node, 'title', 'T' ), 'no stored value, no schema: no example' );
		self::atomic_site();
		$this->assertSame( self::env( 'escaped-html', 'T' ), IATO_MCP_Elementor_Atomic::envelope_example( $node, 'title', 'T' ), 'from the schema for a plain prop type' );
		$no_link = self::widget( 'e-heading', [ 'tag' => self::env( 'string', 'h1' ) ] );
		$this->assertNull( IATO_MCP_Elementor_Atomic::envelope_example( $no_link, 'link', [ 'destination' => 'https://b.test/' ] ), 'an object prop that is not stored: no shape to show' );
	}
}
