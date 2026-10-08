<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * Read-layer tests over captured fixtures: all-classic, all-atomic, mixed,
 * and hand-written legacy/edge cases. Fixtures in tests/fixtures/elementor/
 * were captured from a wp-env site running Elementor 4.3.4 via
 * tests/wp-env/create-fixture-pages.php.
 */
final class ElementorAdapterReadTest extends TestCase {

	private static function strip_key( array $data, string $key ): array {
		$out = [];
		foreach ( $data as $k => $v ) {
			if ( $k === $key ) {
				continue;
			}
			$out[ $k ] = is_array( $v ) ? self::strip_key( $v, $key ) : $v;
		}
		return $out;
	}

	private static function expected( string $name ): array {
		return json_decode( file_get_contents( __DIR__ . "/fixtures/elementor/{$name}.expected.json" ), true, 512, JSON_THROW_ON_ERROR );
	}

	private static function ids( array $nodes ): array {
		return array_map( fn( $n ) => $n['widget_id'], $nodes );
	}

	// ── Classic-only: identical to pre-Sprint-A output ───────────────────

	public function test_classic_page_output_unchanged_except_schema_marker(): void {
		$elements = iato_test_fixture( 'classic-only' );
		$golden   = json_decode( file_get_contents( __DIR__ . '/fixtures/elementor/classic-only.pre-sprint-a.expected.json' ), true, 512, JSON_THROW_ON_ERROR );

		$now = [
			'flat'                => IATO_MCP_Elementor_Adapter::flatten_widgets( $elements, 'flat' ),
			'tree'                => IATO_MCP_Elementor_Adapter::flatten_widgets( $elements, 'tree' ),
			'summary'             => array_map( [ IATO_MCP_Elementor_Adapter::class, 'summary' ], $elements[0]['elements'] ),
			'find_all_widgets'    => IATO_MCP_Elementor_Adapter::find_by_filter( $elements, [], 6 ),
			'find_heading_h2'     => IATO_MCP_Elementor_Adapter::find_by_filter( $elements, [ 'type' => 'heading', 'setting' => [ 'header_size' => [ 'eq' => 'h2' ] ] ], 6 ),
			'find_contains_class' => IATO_MCP_Elementor_Adapter::find_by_filter( $elements, [ 'setting' => [ 'title' => [ 'contains' => 'classic' ] ] ], 6 ),
			'find_widget_c1a5504' => IATO_MCP_Elementor_Adapter::find_widget( $elements, 'c1a5504' ),
		];

		$this->assertSame( $golden, self::strip_key( $now, 'schema' ), 'classic output must match the pre-change golden file once the additive schema key is removed' );

		foreach ( [ 'flat', 'summary', 'find_all_widgets' ] as $view ) {
			foreach ( $now[ $view ] as $node ) {
				$this->assertSame( 'classic', $node['schema'], "{$view} node {$node['widget_id']}" );
				$this->assertArrayNotHasKey( 'tag', $node, 'classic nodes gain no tag key' );
			}
		}
	}

	// ── Atomic-only ───────────────────────────────────────────────────────

	public function test_atomic_page_snapshot(): void {
		$this->assertSame( self::expected( 'atomic-only' ), iato_test_read_snapshot( iato_test_fixture( 'atomic-only' ) ) );
	}

	public function test_atomic_page_content(): void {
		$flat = IATO_MCP_Elementor_Adapter::flatten_widgets( iato_test_fixture( 'atomic-only' ), 'flat' );
		$by   = array_column( $flat, null, 'widget_id' );

		$this->assertSame( [ 'a7e4d00', 'a7e4d01', 'a7e4d02', 'a7e4d03', 'a7e4d04', 'a7e4d05', 'a7e4d06', 'a7e4d07', 'a7e4d10', 'a7e4d20' ], self::ids( $flat ), 'document order' );
		foreach ( $flat as $n ) {
			$this->assertSame( 'atomic', $n['schema'] );
		}

		// Container.
		$this->assertSame( 'e-flexbox', $by['a7e4d00']['type'] );
		$this->assertSame( 'div', $by['a7e4d00']['tag'] );
		$this->assertSame( 0, $by['a7e4d00']['depth'] );
		$this->assertSame( 'a7e4d00', $by['a7e4d01']['parent_id'] );

		// Heading: text + level, explicit and defaulted.
		$this->assertSame( 'Atomic H1 heading', $by['a7e4d01']['title'] );
		$this->assertSame( 'h1', $by['a7e4d01']['header_size'] );
		$this->assertSame( 'h1', $by['a7e4d01']['tag'] );
		$this->assertSame( 'h2', $by['a7e4d02']['header_size'], 'unsaved tag defaults to h2' );

		// Paragraph keeps inline HTML, surfaced under the classic `editor` key.
		$this->assertStringStartsWith( 'Atomic paragraph with <strong>inline HTML</strong>', $by['a7e4d03']['editor'] );
		$this->assertSame( 'p', $by['a7e4d03']['tag'] );

		// Image from the Media Library: alt from attachment.
		$this->assertSame( 5, $by['a7e4d04']['image_id'] );
		$this->assertSame( 'Library image alt text', $by['a7e4d04']['image_alt'] );
		$this->assertSame( 'attachment', $by['a7e4d04']['image_alt_source'] );
		$this->assertStringEndsWith( '/iato-fixture-image.png', $by['a7e4d04']['image_url'] );

		// External image: alt from element, plus its link.
		$this->assertNull( $by['a7e4d05']['image_id'] );
		$this->assertSame( 'https://example.com/photos/external.jpg', $by['a7e4d05']['image_url'] );
		$this->assertSame( 'External image alt text', $by['a7e4d05']['image_alt'] );
		$this->assertSame( 'element', $by['a7e4d05']['image_alt_source'] );
		$this->assertSame( 'https://example.com/photo-target', $by['a7e4d05']['link'] );
		$this->assertSame( 'img', $by['a7e4d05']['tag'], 'image tag does not follow link' );

		// Buttons.
		$this->assertSame( 'Atomic button', $by['a7e4d06']['text'] );
		$this->assertSame( 'https://example.com/go', $by['a7e4d06']['link'] );
		$this->assertTrue( $by['a7e4d06']['link_new_tab'] );
		$this->assertSame( 'a', $by['a7e4d06']['tag'] );
		$this->assertSame( 'button', $by['a7e4d07']['tag'] );
		$this->assertArrayNotHasKey( 'link', $by['a7e4d07'] );

		// Linked container renders as <a>.
		$this->assertSame( 'a', $by['a7e4d20']['tag'] );
		$this->assertSame( 'https://example.com/container-link', $by['a7e4d20']['link'] );
	}

	public function test_atomic_tree_nesting(): void {
		$tree = IATO_MCP_Elementor_Adapter::flatten_widgets( iato_test_fixture( 'atomic-only' ), 'tree' );
		$this->assertCount( 2, $tree );
		$this->assertSame( 'a7e4d00', $tree[0]['widget_id'] );
		$this->assertSame( 'atomic', $tree[0]['schema'] );
		$this->assertCount( 8, $tree[0]['children'] );
		$this->assertSame( 'Atomic H1 heading', $tree[0]['children'][0]['title'] );
		$this->assertArrayNotHasKey( 'children', $tree[1] );
	}

	public function test_get_widget_view_for_atomic_node(): void {
		$elements = iato_test_fixture( 'atomic-only' );
		$found    = IATO_MCP_Elementor_Adapter::find_widget( $elements, 'a7e4d04' );
		$this->assertNotNull( $found );
		$this->assertSame( '/0/elements/3', $found['path'] );

		// Raw settings stay enveloped (what Sprint C writes need).
		$this->assertSame( 'image', $found['element']['settings']['image']['$$type'] );

		$plain = IATO_MCP_Elementor_Atomic::plain_settings( $found['element'] );
		$this->assertSame( [ 'id' => 5, 'url' => null ], $plain['settings']['image']['src'] );
		$this->assertSame( 'full', $plain['settings']['image']['size'] );
	}

	// ── Mixed ─────────────────────────────────────────────────────────────

	public function test_mixed_page_snapshot(): void {
		$this->assertSame( self::expected( 'mixed' ), iato_test_read_snapshot( iato_test_fixture( 'mixed' ) ) );
	}

	public function test_mixed_page_returns_both_schemas_in_document_order(): void {
		$flat = IATO_MCP_Elementor_Adapter::flatten_widgets( iato_test_fixture( 'mixed' ), 'flat' );
		$this->assertSame(
			[ 'm1x0001', 'c1a5501', 'c1a5502', 'm1x0002', 'a7e4d01', 'a7e4d03', 'a7e4d04', 'a7e4d06', 'c1a5504', 'm1x0003', 'c1a5503' ],
			self::ids( $flat )
		);
		$schemas = array_map( fn( $n ) => $n['schema'], $flat );
		$this->assertSame(
			[ 'classic', 'classic', 'classic', 'atomic', 'atomic', 'atomic', 'atomic', 'atomic', 'classic', 'classic', 'classic' ],
			$schemas
		);
		$by = array_column( $flat, null, 'widget_id' );
		$this->assertSame( 'Classic H2 heading', $by['c1a5501']['title'] );
		$this->assertSame( 'Atomic H1 heading', $by['a7e4d01']['title'] );
		$this->assertSame( 'Classic button', $by['c1a5504']['text'], 'classic widget nested inside an atomic container' );
		$this->assertSame( 'm1x0002', $by['c1a5504']['parent_id'] );
	}

	// ── find_by_filter across schemas ─────────────────────────────────────

	public function test_find_by_filter_mixed(): void {
		$elements = iato_test_fixture( 'mixed' );
		$f        = fn( array $filter ) => self::ids( IATO_MCP_Elementor_Adapter::find_by_filter( $elements, $filter, 10 ) );

		$this->assertSame( [ 'c1a5501', 'a7e4d01' ], $f( [ 'setting' => [ 'title' => [ 'contains' => 'heading' ] ] ] ), 'same filter hits both schemas' );
		$this->assertSame( [ 'a7e4d01' ], $f( [ 'type' => 'e-heading' ] ) );
		$this->assertSame( [ 'c1a5501' ], $f( [ 'type' => 'heading' ] ) );
		$this->assertSame( [ 'a7e4d01' ], $f( [ 'setting' => [ 'header_size' => [ 'eq' => 'h1' ] ] ] ) );
		$this->assertSame( [ 'c1a5501' ], $f( [ 'setting' => [ 'header_size' => 'h2' ] ] ) );
		$this->assertSame( [ 'c1a5502', 'a7e4d03' ], $f( [ 'setting' => [ 'editor' => [ 'exists' => true ] ] ] ) );
		$this->assertSame( [ 'a7e4d04' ], $f( [ 'setting' => [ 'image_alt' => [ 'contains' => 'library' ] ] ] ) );
		$this->assertSame( [ 'a7e4d06' ], $f( [ 'setting' => [ 'link_url' => [ 'contains' => 'example.com/go' ] ] ] ) );
		$this->assertSame( [ 'm1x0002' ], $f( [ 'type' => 'e-flexbox' ] ) );
		$this->assertSame( [ 'm1x0001', 'm1x0003' ], $f( [ 'type' => 'container' ] ) );
		$this->assertSame( [], $f( [ 'setting' => [ 'tag' => [ 'eq' => 'nope' ] ] ] ) );

		$match = IATO_MCP_Elementor_Adapter::find_by_filter( $elements, [ 'type' => 'e-button' ], 10 )[0];
		$this->assertSame( [ 'post_id' => 10, 'widget_id' => 'a7e4d06', 'type' => 'e-button', 'schema' => 'atomic', 'tag' => 'a', 'text' => 'Atomic button', 'link' => 'https://example.com/go', 'link_new_tab' => true ], $match );
	}

	// ── Legacy text formats and edge cases ───────────────────────────────

	public function test_legacy_and_edge_cases_snapshot(): void {
		$this->assertSame( self::expected( 'legacy-and-edge-cases' ), iato_test_read_snapshot( iato_test_fixture( 'legacy-and-edge-cases' ) ) );
	}

	public function test_legacy_text_envelopes_read_as_text(): void {
		$by = array_column( IATO_MCP_Elementor_Adapter::flatten_widgets( iato_test_fixture( 'legacy-and-edge-cases' ), 'flat' ), null, 'widget_id' );
		$this->assertSame( 'Plain string title (pre-html era)', $by['l3g0001']['title'] );
		$this->assertSame( 'Html title <em>v1</em>', $by['l3g0002']['title'] );
		$this->assertSame( 'h3', $by['l3g0002']['header_size'] );
		$this->assertSame( 'Html v2 title', $by['l3g0003']['title'] );
		$this->assertSame( 'Html v3 paragraph', $by['l3g0004']['editor'] );
		$this->assertSame( 'This is a title', $by['l3g0005']['title'], 'disabled value falls back to the schema default, as Elementor renders it' );
		$this->assertSame( 'post-title', $by['l3g0006']['title_dynamic'] );
		$this->assertSame( 'http://localhost:8888/sample-page/', $by['l3g0007']['link'] );
		$this->assertSame( 42, $by['l3g0007']['link_post_id'] );
		$this->assertSame( 'button', $by['l3g0007']['tag'] );
		$this->assertSame( 'atomic', $by['l3g0008']['schema'], 'unknown atomic type is returned, not dropped' );
		$this->assertSame( 'atomic', $by['l3g0009']['schema'], 'prefix-less type with envelopes is still atomic' );
		$this->assertSame( 'Prefix-less but enveloped', $by['l3g0009']['title'] );
		$this->assertSame( 999, $by['l3g0010']['image_id'] );
		$this->assertNull( $by['l3g0010']['image_url'] );
	}
}
