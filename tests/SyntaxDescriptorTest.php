<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';

/**
 * 1.21.0: a @property syntax descriptor may hold `<` (syntax: '<number>') where the
 * caller says the block is read as text. Nothing else with `<` passes, and without the
 * caller's word the old rule stands. Which style blocks qualify inside an HTML widget
 * is decided by WordPress's HTML parser, on a live site: tests/wp-env/page-assets-live.py.
 */
final class SyntaxDescriptorTest extends TestCase {

	protected function setUp(): void {
		IATO_Test_OAuth_State::$home_url = 'https://site.example';
	}

	private const SPEC = "@property --p{syntax:'<number>';inherits:true;initial-value:84}\n.a>b{color:red}\n#x:checked~.y [for=x]{content:'x'}\n@media (min-width:1440px){.z{display:block}}\n@keyframes k{from{opacity:0}to{opacity:1}}";

	public function test_the_spec_block_is_kept_unchanged_when_the_caller_allows_it(): void {
		$r = IATO_MCP_Inline_Markup::sanitize_css( self::SPEC, true );
		$this->assertSame( self::SPEC, $r['css'] );
		$this->assertSame( [], $r['removed'] );
	}

	public function test_without_the_callers_word_the_old_rule_stands(): void {
		$r = IATO_MCP_Inline_Markup::sanitize_css( self::SPEC );
		$this->assertNull( $r['css'] );
		$this->assertSame( [ 'whole <style> block: it contains <' ], $r['removed'] );
	}

	/** @return array<string,array{0:string}> */
	public static function descriptors(): array {
		return [
			'number'            => [ "@property --a{syntax:'<number>'}" ],
			'double quotes'     => [ '@property --a{syntax:"<length>"}' ],
			'a list'            => [ "@property --a{syntax:'<length> | <percentage>'}" ],
			'multipliers'       => [ "@property --a{syntax:'<length>+ | <color>#'}" ],
			'an identifier too' => [ "@property --a{syntax:'auto | <length-percentage>'}" ],
			'spaces'            => [ "@property --a { syntax : '<angle>' ; inherits : false }" ],
			'a comment between' => [ "@property --a{syntax/**/:/**/'<time>'}" ],
			'escaped'           => [ "@property --a{syntax:'\\3c number\\3e '}" ],
			'in @supports'      => [ "@supports (x:y){@property --a{syntax:'<integer>'}}" ],
			'twice'             => [ "@property --a{syntax:'<number>'}@property --b{syntax:'<color>'}" ],
		];
	}

	#[DataProvider( 'descriptors' )]
	public function test_a_descriptor_is_kept( string $css ): void {
		$r = IATO_MCP_Inline_Markup::sanitize_css( $css, true );
		$this->assertNotNull( $r['css'], json_encode( $r['removed'] ) );
		$this->assertSame( [], $r['removed'] );
		$this->assertStringContainsString( 'syntax', (string) $r['css'] );
	}

	/** @return array<string,array{0:string}> */
	public static function not_descriptors(): array {
		return [
			'closes the element'       => [ "@property --a{syntax:'</style><script>alert(1)</script>'}" ],
			'a tag in the descriptor'  => [ "@property --a{syntax:'<img src=x onerror=alert(1)>'}" ],
			'an unknown type'          => [ "@property --a{syntax:'<script>'}" ],
			'a slash'                  => [ "@property --a{syntax:'<number>/'}" ],
			'outside @property'        => [ ".a{syntax:'<number>'}" ],
			'not after syntax'         => [ "@property --a{initial-value:'<number>'}" ],
			'content'                  => [ ".a::before{content:'<b>'}" ],
			'bare'                     => [ '.a<b{color:red}' ],
			'in a selector'            => [ "@property --a{syntax:'<number>'} .a<b{}" ],
			'escaped tag'              => [ ".a::before{content:'\\3c b\\3e '}" ],
			'escaped breakout'         => [ "@property --a{syntax:'\\3c /style\\3e '}" ],
			'nested block'             => [ "@property --a{x{syntax:'<number>'}}" ],
			'a second string'          => [ "@property --a{syntax:'<number>' '<b>'}" ],
			'url hidden in escapes'    => [ "@property --a{syntax:'<number>'} .b{background:\\75 rl(https://evil.example/a)}" ],
		];
	}

	#[DataProvider( 'not_descriptors' )]
	public function test_anything_else_with_a_less_than_drops_the_block( string $css ): void {
		$r = IATO_MCP_Inline_Markup::sanitize_css( $css, true );
		$this->assertNull( $r['css'], (string) $r['css'] );
	}

	public function test_off_site_loads_are_still_removed_next_to_a_descriptor(): void {
		$r = IATO_MCP_Inline_Markup::sanitize_css( "@property --a{syntax:'<number>'} @import url(https://evil.example/a.css); .b{background:url(https://evil.example/b.png)}", true );
		$this->assertNotNull( $r['css'] );
		$this->assertStringNotContainsString( 'evil.example', (string) $r['css'] );
		$this->assertStringContainsString( "syntax:'<number>'", (string) $r['css'] );
		$this->assertCount( 2, $r['removed'] );
	}

	/** @return array<string,array{0:string,1:bool}> */
	public static function grammar(): array {
		return [
			'one type'      => [ '<number>', true ],
			'list'          => [ '<length> | <percentage>', true ],
			'plus'          => [ '<length>+', true ],
			'hash'          => [ '<color>#', true ],
			'ident'         => [ 'auto | <length>', true ],
			'universal'     => [ '*', false ], // no `<`, so it never needs this rule
			'unknown'       => [ '<script>', false ],
			'two mult'      => [ '<length>+#', false ],
			'attribute'     => [ '<number x>', false ],
			'trailing bar'  => [ '<number> |', false ],
			'newline'       => [ "<number>\n", false ],
			'closing slash' => [ '</number>', false ],
		];
	}

	#[DataProvider( 'grammar' )]
	public function test_the_grammar( string $value, bool $ok ): void {
		$this->assertSame( $ok, IATO_MCP_Inline_Markup::is_syntax_descriptor( $value ) );
	}

	public function test_page_css_may_hold_a_descriptor_and_additional_css_may_not(): void {
		$css = "@property --a{syntax:'<number>';inherits:false;initial-value:0}";
		$this->assertSame( [], IATO_MCP_Custom_CSS::refused_parts( $css, true ) );
		$this->assertSame( [ 'whole <style> block: it contains <' ], IATO_MCP_Custom_CSS::refused_parts( $css ) );
	}
}
