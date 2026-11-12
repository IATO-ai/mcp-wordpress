<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 1.21.0: page CSS, page fonts and preview links, the parts that do not need a
 * WordPress user. Who may write them, the printing and a real browser are checked on a
 * live site: tests/wp-env/page-assets-live.py. Each test runs in its own process: the
 * meta, post and transient stubs answer from small tables.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PageAssetsTest extends TestCase {

	private const POST = 70;

	protected function setUp(): void {
		require_once __DIR__ . '/stubs-meta.php';
		require_once __DIR__ . '/stubs-cache.php';
		require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';
		if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
			define( 'MONTH_IN_SECONDS', 2592000 );
		}
		if ( ! function_exists( 'add_query_arg' ) ) {
			eval( 'function add_query_arg( $key, $value, $url ) { return $url . ( str_contains( $url, "?" ) ? "&" : "?" ) . $key . "=" . $value; }' );
		}
		IATO_Test_WP::$posts                  = [ self::POST => [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Home' ] ];
		IATO_Test_WP::$meta                   = [];
		IATO_Test_WP::$permalinks[ self::POST ] = 'http://site.example/?page_id=70';
		IATO_Test_OAuth_State::$transients    = [];
		IATO_Test_OAuth_State::$home_url      = 'https://site.example';
	}

	// ── Page CSS ──────────────────────────────────────────────────────────

	public function test_page_css_refuses_what_loads_from_another_site(): void {
		$r = IATO_MCP_Page_CSS::plan( self::POST, '@import url(https://fonts.example/a.css); .a{color:red}', false, 'css' );
		$this->assertSame( 'css_not_allowed', $r->get_error_code() );
		$this->assertSame( [ '@import url(https://fonts.example/a.css);' ], $r->get_error_data()['found'] );
		$this->assertSame( '', IATO_MCP_Page_CSS::stored( self::POST ), 'nothing stored' );
	}

	public function test_page_css_keeps_a_property_descriptor_and_stores_what_was_sent(): void {
		$css = "@property --p{syntax:'<number>';inherits:true;initial-value:84}\n.a>b{color:red}";
		$r   = IATO_MCP_Page_CSS::plan( self::POST, $css, false, 'css' );
		$this->assertSame( $css, $r['css'] );
		$this->assertSame( [], $r['report'] );
	}

	public function test_page_css_removes_and_reports_what_would_close_the_element(): void {
		$r = IATO_MCP_Page_CSS::plan( self::POST, '.a{color:red}</style >', false, 'css' );
		$this->assertSame( '.a{color:red}', $r['css'] );
		$this->assertSame( [ '</style' ], $r['report'][0]['removed_css'] );
		$this->assertSame( 'css_not_allowed', IATO_MCP_Page_CSS::plan( self::POST, '.a{}</style><p>x', false, 'css' )->get_error_code(), 'what follows is judged too' );
	}

	public function test_page_css_append_joins_and_refuses_after_an_open_comment(): void {
		IATO_MCP_Page_CSS::write( self::POST, '.a{color:red}' );
		$this->assertSame( ".a{color:red}\n.b{color:blue}", IATO_MCP_Page_CSS::plan( self::POST, '.b{color:blue}', true, 'append_css' )['css'] );
		IATO_Test_WP::$meta[ self::POST ][ IATO_MCP_Page_CSS::META ] = '.a{} /* open';
		$this->assertSame( 'stored_css_unreadable', IATO_MCP_Page_CSS::plan( self::POST, '.b{}', true, 'append_css' )->get_error_code() );
	}

	public function test_page_css_size_limit_is_checked_before_judging(): void {
		$this->assertSame( 'css_too_large', IATO_MCP_Page_CSS::plan( self::POST, str_repeat( 'a', IATO_MCP_Page_CSS::MAX_BYTES + 1 ), false, 'css' )->get_error_code() );
	}

	public function test_printing_checks_again_and_remembers_the_answer(): void {
		$this->assertTrue( IATO_MCP_Page_CSS::passes( '.a{color:red}' ) );
		$this->assertCount( 1, IATO_Test_OAuth_State::$transients, 'the answer is cached by hash' );
		$this->assertFalse( IATO_MCP_Page_CSS::passes( '.a{background:url(https://evil.example/x.png)}' ), 'a value that reached the meta another way' );
		$this->assertFalse( IATO_MCP_Page_CSS::passes( '.a{}</style><script>x</script>' ) );
		$this->assertFalse( IATO_MCP_Page_CSS::passes( '' ) );
		$key = array_key_first( IATO_Test_OAuth_State::$transients );
		IATO_Test_OAuth_State::$transients[ $key ]['value'] = '0';
		$this->assertFalse( IATO_MCP_Page_CSS::passes( '.a{color:red}' ), 'the cached answer is used' );
	}

	public function test_page_css_write_restore_and_values(): void {
		IATO_MCP_Page_CSS::write( self::POST, '.a{color:red}' );
		$before = IATO_MCP_Page_CSS::before_value( self::POST );
		IATO_MCP_Page_CSS::write( self::POST, '.b{}' );
		$this->assertSame( [ 'bytes' => 4, 'sha256' => hash( 'sha256', '.b{}' ) ], IATO_MCP_Page_CSS::after_value( self::POST ) );
		$this->assertTrue( IATO_MCP_Page_CSS::restore( self::POST, wp_json_encode( $before ) ) );
		$this->assertSame( '.a{color:red}', IATO_MCP_Page_CSS::stored( self::POST ) );
		$this->assertTrue( IATO_MCP_Page_CSS::restore( self::POST, wp_json_encode( [ 'css' => '' ] ) ) );
		$this->assertArrayNotHasKey( IATO_MCP_Page_CSS::META, IATO_Test_WP::$meta[ self::POST ] ?? [], 'an empty value removes the meta' );
	}

	public function test_no_meta_tool_may_write_the_page_asset_keys_even_with_force(): void {
		require_once IATO_MCP_DIR . 'includes/class-meta-policy.php';
		foreach ( [ '_iato_mcp_page_css', '_iato_mcp_page_fonts', '_iato_mcp_page_script', '_iato_mcp_preview_links', '_IATO_MCP_PAGE_SCRIPT' ] as $key ) {
			$this->assertInstanceOf( WP_Error::class, IATO_MCP_Meta_Policy::check_write( $key, true ), $key );
		}
	}

	// ── Page fonts ────────────────────────────────────────────────────────

	private function elementor( bool $google = true ): void {
		IATO_MCP_Page_Fonts::set_elementor(
			static fn( string $f ): string|false => [ 'JetBrains Mono' => 'googlefonts', 'Arial' => 'system', 'Brand Sans' => 'custom' ][ $f ] ?? false,
			static fn(): bool => $google
		);
	}

	public function test_fonts_are_checked_against_elementors_list(): void {
		$this->elementor();
		$r = IATO_MCP_Page_Fonts::plan( [ 'JetBrains Mono', ' Brand Sans ', 'JetBrains Mono', 'Arial' ] );
		$this->assertSame( [ 'JetBrains Mono', 'Brand Sans', 'Arial' ], $r['fonts'] );
		$this->assertSame( [ 'JetBrains Mono' => 'googlefonts', 'Brand Sans' => 'custom', 'Arial' => 'system' ], $r['types'] );
		$e = IATO_MCP_Page_Fonts::plan( [ 'JetBrains Mono', 'Comic Neue Pro' ] );
		$this->assertSame( 'unknown_font', $e->get_error_code() );
		$this->assertSame( [ 'Comic Neue Pro' ], $e->get_error_data()['unknown'] );
	}

	public function test_an_unknown_family_with_google_fonts_off_says_so(): void {
		$this->elementor( false );
		$this->assertSame( 'google_fonts_disabled', IATO_MCP_Page_Fonts::plan( [ 'Inter' ] )->get_error_code() );
	}

	public function test_fonts_limits_and_shapes(): void {
		$this->elementor();
		$this->assertSame( 'invalid_fonts', IATO_MCP_Page_Fonts::plan( 'Inter' )->get_error_code() );
		$this->assertSame( 'invalid_fonts', IATO_MCP_Page_Fonts::plan( [ '' ] )->get_error_code() );
		$this->assertSame( 'too_many_fonts', IATO_MCP_Page_Fonts::plan( array_map( static fn( int $i ): string => "F{$i}", range( 1, 11 ) ) )->get_error_code() );
		$this->assertSame( [ 'fonts' => [], 'types' => [] ], IATO_MCP_Page_Fonts::plan( [] ), '[] clears them' );
	}

	public function test_fonts_write_and_restore(): void {
		IATO_MCP_Page_Fonts::write( self::POST, [ 'Arial' ] );
		$this->assertSame( [ 'Arial' ], IATO_MCP_Page_Fonts::stored( self::POST ) );
		IATO_MCP_Page_Fonts::restore( self::POST, wp_json_encode( [] ) );
		$this->assertSame( [], IATO_MCP_Page_Fonts::stored( self::POST ) );
	}

	// ── Preview links ─────────────────────────────────────────────────────

	/** @return array<string,array{0:mixed,1:int|string}> */
	public static function ttls(): array {
		return [ 'default' => [ null, 900 ], 'least' => [ 60, 60 ], 'most' => [ 3600, 3600 ], 'digits' => [ '600', 600 ], 'too short' => [ 59, 'invalid_ttl' ], 'too long' => [ 3601, 'invalid_ttl' ], 'not a number' => [ '1h', 'invalid_ttl' ], 'a float' => [ 1.5, 'invalid_ttl' ] ];
	}

	#[DataProvider( 'ttls' )]
	public function test_lifetimes( mixed $raw, int|string $want ): void {
		$ttl = IATO_MCP_Preview_Link::ttl( $raw );
		$this->assertSame( $want, is_wp_error( $ttl ) ? $ttl->get_error_code() : $ttl );
	}

	public function test_a_link_opens_only_its_post_until_it_expires(): void {
		$link  = IATO_MCP_Preview_Link::create( self::POST, 900 );
		$token = (string) preg_replace( '/^.*iato_preview=/', '', $link['url'] );
		$this->assertStringStartsWith( 'http://site.example/?page_id=70&iato_preview=', $link['url'] );
		$this->assertTrue( IATO_MCP_Preview_Link::valid( self::POST, $token ) );
		$this->assertFalse( IATO_MCP_Preview_Link::valid( 71, $token ), 'another post' );
		$this->assertFalse( IATO_MCP_Preview_Link::valid( self::POST, strtoupper( $token ) ), 'another token' );
		$this->assertFalse( IATO_MCP_Preview_Link::valid( self::POST, $token . "'" ), 'a malformed one' );
		$stored = IATO_Test_WP::$meta[ self::POST ][ IATO_MCP_Preview_Link::META ];
		$this->assertStringNotContainsString( $token, (string) wp_json_encode( $stored ), 'only the hash is stored' );
		IATO_Test_WP::$meta[ self::POST ][ IATO_MCP_Preview_Link::META ][0]['expires'] = time() - 1;
		$this->assertFalse( IATO_MCP_Preview_Link::valid( self::POST, $token ), 'expired' );
		$this->assertSame( [], IATO_MCP_Preview_Link::links( self::POST ) );
	}

	public function test_a_link_copied_to_another_post_opens_nothing_there(): void {
		$link  = IATO_MCP_Preview_Link::create( self::POST, 900 );
		$token = (string) preg_replace( '/^.*iato_preview=/', '', $link['url'] );
		IATO_Test_WP::$posts[71] = [ 'post_type' => 'page', 'post_status' => 'draft' ];
		IATO_Test_WP::$meta[71][ IATO_MCP_Preview_Link::META ] = IATO_Test_WP::$meta[ self::POST ][ IATO_MCP_Preview_Link::META ];
		$this->assertTrue( IATO_MCP_Preview_Link::valid( self::POST, $token ) );
		$this->assertFalse( IATO_MCP_Preview_Link::valid( 71, $token ), 'the stored form is tied to the post' );
	}

	public function test_a_lifetime_no_link_is_given_does_not_count(): void {
		$link  = IATO_MCP_Preview_Link::create( self::POST, 900 );
		$token = (string) preg_replace( '/^.*iato_preview=/', '', $link['url'] );
		IATO_Test_WP::$meta[ self::POST ][ IATO_MCP_Preview_Link::META ][0]['expires'] = time() + 10 * IATO_MCP_Preview_Link::MAX_TTL;
		$this->assertFalse( IATO_MCP_Preview_Link::valid( self::POST, $token ), 'crafted meta that never expires' );
	}

	public function test_a_link_ends_when_its_post_is_published_or_trashed(): void {
		IATO_MCP_Preview_Link::create( self::POST, 900 );
		if ( ! class_exists( 'WP_Post' ) ) {
			eval( 'final class WP_Post { public int $ID = 0; public function __construct( object $o ) { $this->ID = (int) $o->ID; } }' );
		}
		$post = new WP_Post( (object) [ 'ID' => self::POST, 'post_type' => 'page', 'post_status' => 'publish' ] );
		IATO_MCP_Preview_Link::status_changed( 'pending', 'draft', $post );
		$this->assertCount( 1, IATO_MCP_Preview_Link::links( self::POST ), 'still a status it was made for' );
		IATO_MCP_Preview_Link::status_changed( 'publish', 'draft', $post );
		$this->assertSame( [], IATO_MCP_Preview_Link::links( self::POST ) );
	}

	public function test_revoking_a_link(): void {
		$link  = IATO_MCP_Preview_Link::create( self::POST, 900 );
		$token = (string) preg_replace( '/^.*iato_preview=/', '', $link['url'] );
		$this->assertTrue( IATO_MCP_Preview_Link::revoke( self::POST, $link['link_id'] ) );
		$this->assertFalse( IATO_MCP_Preview_Link::valid( self::POST, $token ) );
		$this->assertTrue( IATO_MCP_Preview_Link::revoke( self::POST, $link['link_id'] ), 'already gone counts as gone' );
	}

	public function test_at_most_ten_live_links_a_post(): void {
		for ( $i = 0; $i < IATO_MCP_Preview_Link::MAX_LINKS; $i++ ) {
			$this->assertIsArray( IATO_MCP_Preview_Link::create( self::POST, 900 ) );
		}
		$this->assertSame( 'too_many_preview_links', IATO_MCP_Preview_Link::create( self::POST, 900 )->get_error_code() );
	}
}
