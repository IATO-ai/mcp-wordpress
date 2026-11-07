<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules of update_custom_css (1.19.0). What it does on real WordPress is covered
 * by tests/wp-env/custom-css-live.py.
 */
final class CustomCssTest extends TestCase {

	/** @return array<string,array{0:string,1:string}> */
	public static function closing_tags(): array {
		return [
			'the plain tag'             => [ 'a{}</style>b{}', 'a{}b{}' ],
			'upper case'                => [ 'a{}</STYLE>b{}', 'a{}b{}' ],
			'a space before the end'    => [ 'a{}</style >b{}', 'a{}b{}' ],
			'a slash'                   => [ 'a{}</style/>b{}', 'a{}b{}' ],
			'attributes'                => [ 'a{}</style class="x">b{}', 'a{}b{}' ],
			'a new line'                => [ "a{}</style\n>b{}", 'a{}b{}' ],
			'a tab'                     => [ "a{}</style\t>b{}", 'a{}b{}' ],
			'at the very end'           => [ 'a{}</style', 'a{}' ],
			'at the end with a space'   => [ 'a{}</style ', 'a{}' ],
			'one hidden inside another' => [ 'a{}</st</style>yle>b{}', 'a{}b{}' ],
			'twice'                     => [ '</style>a{}</style>', 'a{}' ],
		];
	}

	#[DataProvider( 'closing_tags' )]
	public function test_what_closes_the_element_is_taken_out( string $sent, string $stored ): void {
		$this->assertSame( $stored, IATO_MCP_Custom_CSS::strip_style_close( $sent ) );
	}

	public function test_what_does_not_close_it_is_left_alone(): void {
		foreach ( [ 'a > b { color: red }', '.x::after { content: "</styles>" }', '</ style>', 'a{}' ] as $css ) {
			$this->assertSame( $css, IATO_MCP_Custom_CSS::strip_style_close( $css ) );
		}
	}

	/** @return array<string,array{0:string}> */
	public static function allowed(): array {
		return [
			'empty'                  => [ '' ],
			'only whitespace'        => [ "  \n" ],
			'child combinator'       => [ 'a > b { color: red; }' ],
			'a media query'          => [ '@media (min-width: 600px) { .x { display: none; } }' ],
			'a local image'          => [ '.x { background: url(/wp-content/uploads/a.png); }' ],
			'a relative image'       => [ '.x { background: url(a.png); }' ],
			'a data image'           => [ '.x { background: url(data:image/png;base64,iVBORw0KGgo=); }' ],
			'a gradient'             => [ '.x { background: linear-gradient(red, blue); }' ],
			'a variable'             => [ ':root { --x: 4px; } .y { margin: var(--x); }' ],
			'nesting'                => [ '.x { &:hover { color: red; } }' ],
			'an ampersand in text'   => [ '.x::after { content: "A & B"; }' ],
			'a backslash'            => [ '.x::after { content: "\201C"; }' ],
		];
	}

	#[DataProvider( 'allowed' )]
	public function test_ordinary_css_passes( string $css ): void {
		$this->assertSame( [], IATO_MCP_Custom_CSS::refused_parts( $css ) );
	}

	/** @return array<string,array{0:string}> */
	public static function refused(): array {
		return [
			'an import'               => [ '@import url(https://evil.example/x.css);' ],
			'an import of a string'   => [ '@import "https://evil.example/x.css";' ],
			'an off-site image'       => [ '.x { background: url(https://evil.example/x.png); }' ],
			'no scheme'               => [ '.x { background: url(//evil.example/x.png); }' ],
			'an escaped slash'        => [ '.x { background: url(/\/evil.example/x.png); }' ],
			'a quoted backslash host' => [ '.x { background: url("/\\\\evil.example/x.png"); }' ],
			'an image-set'            => [ '.x { background: image-set("https://evil.example/x.png" 1x); }' ],
			'an escaped url'          => [ '.x { background: \75 rl(https://evil.example/x.png); }' ],
			'an escaped import'       => [ '@\69mport "https://evil.example/x.css";' ],
			'a font from elsewhere'   => [ '@font-face { font-family: x; src: url(https://evil.example/x.woff2); }' ],
			'markup'                  => [ '<script>alert(1)</script>' ],
			'a character reference'   => [ '@&#105;mport "https://evil.example/x.css";' ],
			'expression'              => [ '.x { width: expression(alert(1)); }' ],
			'a javascript url'        => [ '.x { background: url(javascript:alert(1)); }' ],
			'a broken comment'        => [ '.x { color: red; } /* never closed' ],
			'invalid characters'      => [ ".x { color: red\x01; }" ],
		];
	}

	#[DataProvider( 'refused' )]
	public function test_what_could_leave_the_site_or_run_is_refused( string $css ): void {
		$this->assertNotSame( [], IATO_MCP_Custom_CSS::refused_parts( $css ) );
	}

	public function test_css_that_is_only_comments_passes(): void {
		$this->assertSame( [], IATO_MCP_Custom_CSS::refused_parts( '/* nothing yet */' ) );
	}

	public function test_what_strip_tags_would_change_is_noticed(): void {
		$this->assertTrue( IATO_MCP_Custom_CSS::printed_differently( '/* width<768 */ a{}' ) );
		$this->assertFalse( IATO_MCP_Custom_CSS::printed_differently( '/* a < b */ a > b {}' ) );
	}

	/** Comments are not judged, and strip_tags() in WordPress below 7.1 takes from inside them. */
	public function test_the_comment_trick_for_classic_themes_is_caught(): void {
		$css = '/*<a*/b>"*/ " /* " {} body{background:url(https://evil.example/x)} /* */';
		$this->assertTrue( [] === IATO_MCP_Custom_CSS::refused_parts( $css ) ? IATO_MCP_Custom_CSS::printed_differently( $css ) : true );
	}

	public function test_stored_css_left_open_is_not_joined_to(): void {
		$this->assertFalse( IATO_MCP_Inline_Markup::css_is_readable( 'a{} /* TODO' ) );
		$this->assertFalse( IATO_MCP_Inline_Markup::css_is_readable( 'a{content:"open' ) );
		$this->assertTrue( IATO_MCP_Inline_Markup::css_is_readable( 'a{} /* done */' ) );
		$this->assertTrue( IATO_MCP_Inline_Markup::css_is_readable( '' ) );
	}

	public function test_a_rollback_response_does_not_carry_the_css(): void {
		$view = IATO_MCP_Custom_CSS::restore_view( '{"stylesheet":"t","post_id":3,"css":"a{}"}' );
		$this->assertSame( [ 'stylesheet' => 't', 'bytes' => 3, 'sha256' => hash( 'sha256', 'a{}' ) ], $view );
	}

	public function test_a_breakout_attempt_is_refused_once_its_tag_is_gone(): void {
		$clean = IATO_MCP_Custom_CSS::strip_style_close( 'a{}</style><script>alert(1)</script>' );
		$this->assertNotSame( [], IATO_MCP_Custom_CSS::refused_parts( $clean ) );
	}

	public function test_the_receipt_asks_for_an_administrator(): void {
		$this->assertSame( 'manage_options', IATO_MCP_Change_Receipt::cap_required_for( [ 'target_type' => 'custom_css', 'field' => 'custom_css' ] ) );
	}

	public function test_the_retention_job_covers_it(): void {
		$this->assertContains( 'custom_css', IATO_MCP_Receipt_Retention::TYPES );
	}
}
