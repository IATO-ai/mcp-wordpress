<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 1.21.0: page scripts run only when an administrator has approved that exact text,
 * the setting is on and set_page_script is switched on. Approving through wp-admin
 * (session, nonce, password, rate limit) and the cache purges run on a live site:
 * tests/wp-env/page-assets-live.py. Each test runs in its own process: the meta and
 * option stubs answer from small tables.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PageScriptTest extends TestCase {

	private const POST = 80;

	protected function setUp(): void {
		require_once __DIR__ . '/stubs-meta.php';
		IATO_Test_WP::$posts                 = [ self::POST => [ 'post_type' => 'page', 'post_status' => 'publish' ] ];
		IATO_Test_WP::$meta                  = [];
		IATO_Test_Abilities_State::$options  = [];
	}

	/** @return array<string,array{0:mixed,1:?string}> */
	public static function scripts(): array {
		return [
			'plain'              => [ "document.querySelector('.x').classList.add('on');", null ],
			'empty removes'      => [ '', null ],
			'a script tag'       => [ "a();<script src=https://evil.example/x.js></script>", 'script_not_allowed' ],
			'a closing tag'      => [ "var s = '</script><img src=x onerror=alert(1)>';", 'script_not_allowed' ],
			'upper case'         => [ 'x = "</SCRIPT>"', 'script_not_allowed' ],
			'a comment opener'   => [ 'x = "<!--";', 'script_not_allowed' ],
			'not text'           => [ [ 'a' ], 'invalid_script' ],
			'too large'          => [ str_repeat( 'a', IATO_MCP_Page_Script::MAX_BYTES + 1 ), 'script_too_large' ],
			'invalid utf-8'      => [ "a\xff", 'script_not_allowed' ],
			'a less-than is fine' => [ 'if (a < b) { c(); }', null ],
			// 1.21.0 review: what the administrator reads must be what runs (Trojan Source).
			'right-to-left override' => [ "if (mode != \"view\u{202E} \u{2066}// admins\u{2069} \u{2066}\") { x(); }", 'script_not_allowed' ],
			'isolate'                => [ "a = 1;\u{2068}b = 2;", 'script_not_allowed' ],
			'zero-width joiner'      => [ "var url\u{200D} = 'https://evil.example/';", 'script_not_allowed' ],
			'zero-width space'       => [ "a\u{200B}b();", 'script_not_allowed' ],
			'byte order mark'        => [ "\u{FEFF}a();", 'script_not_allowed' ],
			'line separator'         => [ "// note\u{2028}evil();", 'script_not_allowed' ],
			'arabic letter mark'     => [ "a\u{061C}();", 'script_not_allowed' ],
			'an accented letter'     => [ "label = 'Café';", null ],
		];
	}

	#[DataProvider( 'scripts' )]
	public function test_what_may_be_stored( mixed $script, ?string $code ): void {
		$refusal = IATO_MCP_Page_Script::refusal( $script );
		$this->assertSame( $code, null === $refusal ? null : $refusal->get_error_code() );
	}

	public function test_a_stored_script_is_pending_until_that_exact_text_is_approved(): void {
		$this->assertSame( 'none', IATO_MCP_Page_Script::status( self::POST ) );
		IATO_MCP_Page_Script::write( self::POST, 'a();' );
		$this->assertSame( 'pending', IATO_MCP_Page_Script::status( self::POST ) );
		IATO_Test_Abilities_State::$options[ IATO_MCP_Page_Script::APPROVALS ] = [ self::POST => [ 'sha256' => hash( 'sha256', 'a();' ), 'user_id' => 1, 'at' => 1 ] ];
		$this->assertSame( 'approved', IATO_MCP_Page_Script::status( self::POST ) );
		IATO_MCP_Page_Script::write( self::POST, 'a(); b();' );
		$this->assertSame( 'pending', IATO_MCP_Page_Script::status( self::POST ), 'any change waits for approval again' );
		IATO_MCP_Page_Script::write( self::POST, 'a();' );
		$this->assertSame( 'approved', IATO_MCP_Page_Script::status( self::POST ), 'a rollback to the approved text runs again' );
	}

	public function test_the_review_shows_every_character_outside_ascii(): void {
		foreach ( [ 'esc_attr', 'esc_html' ] as $fn ) {
			if ( ! function_exists( $fn ) ) {
				eval( "function {$fn}( \$t ) { return htmlspecialchars( (string) \$t, ENT_QUOTES ); }" );
			}
		}
		$review = IATO_MCP_Page_Script::review_html( "var p\u{0430}ssword = '<b>';" ); // a Cyrillic а
		$this->assertSame( 1, $review['non_ascii'] );
		$this->assertStringContainsString( '\u{0430}</mark>', $review['html'] );
		$this->assertStringContainsString( '&lt;b&gt;', $review['html'], 'and the rest escaped' );
		$this->assertSame( [ 'html' => 'a();', 'non_ascii' => 0 ], IATO_MCP_Page_Script::review_html( 'a();' ) );
	}

	public function test_an_approval_lives_in_an_option_not_in_post_meta(): void {
		IATO_MCP_Page_Script::write( self::POST, 'a();' );
		IATO_Test_WP::$meta[ self::POST ]['_iato_mcp_page_script_approval'] = hash( 'sha256', 'a();' );
		$this->assertSame( 'pending', IATO_MCP_Page_Script::status( self::POST ), 'meta written another way approves nothing' );
	}

	public function test_scripts_run_only_with_the_setting_and_the_tool_on(): void {
		$this->assertFalse( IATO_MCP_Page_Script::setting_on(), 'off by default' );
		IATO_Test_Abilities_State::$options[ IATO_MCP_Page_Script::OPTION ] = '1';
		$this->assertTrue( IATO_MCP_Page_Script::setting_on() );
		IATO_Test_Abilities_State::$disabled_tools = [ 'set_page_script' ];
		$this->assertFalse( IATO_MCP_Page_Script::active() );
		IATO_Test_Abilities_State::$disabled_tools = [];
		$this->assertTrue( IATO_MCP_Page_Script::active() );
	}

	/** @return array<string,array{0:mixed,1:bool}> */
	public static function tool_lists(): array {
		return [
			'empty: the defaults, where it is off' => [ [], false ],
			'not an array'                         => [ '', false ],
			'without it'                           => [ [ 'get_posts' ], false ],
			'with it'                              => [ [ 'get_posts', 'set_page_script' ], true ],
		];
	}

	#[DataProvider( 'tool_lists' )]
	public function test_which_saved_lists_switch_the_tool_on( mixed $tools, bool $on ): void {
		$this->assertSame( $on, IATO_MCP_Page_Script::tool_on_in( $tools ) );
	}

	public function test_the_deactivation_warning_adds_pages_with_an_approved_script(): void {
		$GLOBALS['wpdb']->stored_keys = [ self::POST ];
		IATO_MCP_Page_Script::write( self::POST, 'a();' );
		$this->assertSame( 2, IATO_MCP_Page_Script::count_for_warning( 2, [ 10, 11 ] ), 'a pending script stops nothing' );
		IATO_Test_Abilities_State::$options[ IATO_MCP_Page_Script::APPROVALS ] = [ self::POST => [ 'sha256' => hash( 'sha256', 'a();' ), 'user_id' => 1, 'at' => 1 ] ];
		$this->assertSame( 3, IATO_MCP_Page_Script::count_for_warning( 2, [ 10, 11 ] ) );
		$this->assertSame( 2, IATO_MCP_Page_Script::count_for_warning( 2, [ 10, self::POST ] ), 'a page with CSS and a script counts once' );
	}
}
