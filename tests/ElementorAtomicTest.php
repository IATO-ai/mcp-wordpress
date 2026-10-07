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
}
