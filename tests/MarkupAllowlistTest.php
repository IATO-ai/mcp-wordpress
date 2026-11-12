<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 1.21.0: the markup rule is WordPress's post list as other plugins have filtered it,
 * with gaps filled. Nothing that passed before stops passing: a tag or attribute that is
 * already allowed keeps its rule. The real kses runs on a live site, where
 * tests/wp-env/sanitizer-compat-live.sh compares it with 1.20.0's.
 * Each test runs in its own process: it defines wp_kses_allowed_html().
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class MarkupAllowlistTest extends TestCase {

	/** What a site's filtered post list holds, for the stub. */
	public static array $post = [];

	protected function setUp(): void {
		if ( ! function_exists( 'wp_kses_allowed_html' ) ) {
			eval( 'function wp_kses_allowed_html( $context = "" ) { return MarkupAllowlistTest::$post; }' );
		}
		self::$post = [
			'p'        => [ 'class' => true, 'aria-hidden' => true ],
			'button'   => [ 'type' => true, 'disabled' => true ],
			'textarea' => [ 'rows' => true ],
			'fieldset' => [],
			'b'        => true, // a plugin's shorthand for "no attributes"
		];
	}

	public function test_aria_and_inert_reach_every_tag(): void {
		$list = IATO_MCP_Inline_Markup::markup_allowlist();
		foreach ( [ 'p', 'button', 'textarea', 'fieldset', 'b', 'input' ] as $tag ) {
			foreach ( [ 'aria-pressed', 'aria-hidden', 'aria-describedby', 'inert' ] as $attribute ) {
				$this->assertArrayHasKey( $attribute, $list[ $tag ], "{$tag} {$attribute}" );
			}
		}
	}

	public function test_disabled_and_readonly_where_html_has_them(): void {
		$list = IATO_MCP_Inline_Markup::markup_allowlist();
		$this->assertArrayHasKey( 'disabled', $list['fieldset'] );
		$this->assertArrayHasKey( 'disabled', $list['textarea'] );
		$this->assertArrayHasKey( 'readonly', $list['textarea'] );
		$this->assertArrayHasKey( 'readonly', $list['input'] );
		$this->assertArrayNotHasKey( 'readonly', $list['button'] );
		$this->assertArrayNotHasKey( 'disabled', $list['p'] );
	}

	public function test_input_is_added_narrow_where_nothing_allows_it(): void {
		$input = IATO_MCP_Inline_Markup::markup_allowlist()['input'];
		$this->assertSame( [ 'values' => [ 'text', 'search', 'email', 'url', 'tel', 'number', 'range', 'checkbox', 'radio', 'color', 'date', 'time', 'datetime-local', 'month', 'week', 'button', 'reset' ] ], $input['type'] );
		foreach ( [ 'src', 'formaction', 'form', 'onfocus', 'autofocus' ] as $never ) {
			$this->assertArrayNotHasKey( $never, $input, $never );
		}
		$this->assertArrayHasKey( 'checked', $input );
		$this->assertArrayHasKey( 'class', $input, 'the global attributes' );
	}

	public function test_a_plugins_input_keeps_its_own_rules(): void {
		self::$post['input'] = [ 'type' => true, 'name' => true, 'value' => true, 'checked' => true, 'onchange' => true ];
		$input               = IATO_MCP_Inline_Markup::markup_allowlist()['input'];
		$this->assertTrue( $input['type'], 'any type, as before' );
		$this->assertTrue( $input['onchange'], 'whatever the plugin allowed still passes' );
		$this->assertArrayHasKey( 'readonly', $input );
		$this->assertArrayHasKey( 'disabled', $input );
	}

	public function test_no_rule_already_there_changes(): void {
		self::$post['p']['aria-hidden'] = [ 'values' => [ 'true' ] ];
		$list                          = IATO_MCP_Inline_Markup::markup_allowlist();
		$this->assertSame( [ 'values' => [ 'true' ] ], $list['p']['aria-hidden'] );
		foreach ( self::$post as $tag => $attributes ) {
			foreach ( is_array( $attributes ) ? $attributes : [] as $name => $rule ) {
				$this->assertSame( $rule, $list[ $tag ][ $name ], "{$tag} {$name}" );
			}
		}
	}

	public function test_form_is_never_added(): void {
		$this->assertArrayNotHasKey( 'form', IATO_MCP_Inline_Markup::markup_allowlist() );
		self::$post['form'] = [ 'action' => true ];
		$this->assertSame( [ 'action' => true ] + array_fill_keys( IATO_MCP_Inline_Markup::ARIA, true ) + [ 'inert' => true ], IATO_MCP_Inline_Markup::markup_allowlist()['form'], 'a site that allows it keeps it' );
	}
}
