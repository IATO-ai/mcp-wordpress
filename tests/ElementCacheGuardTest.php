<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cron.php';
require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-elementor.php';

if ( ! function_exists( 'delete_post_meta_by_key' ) ) {
	function delete_post_meta_by_key( string $key ): bool {
		foreach ( IATO_Test_WP::$meta as $id => $meta ) {
			unset( IATO_Test_WP::$meta[ $id ][ $key ] );
		}
		return true;
	}
}

/**
 * IATO_MCP_Element_Cache_Guard (1.15.1): Elementor's element cache key and
 * shortcode stay out of tool responses and tool input.
 */
final class ElementCacheGuardTest extends TestCase {

	private const KEY = 'abcdef0123456789abcdef0123456789';

	protected function setUp(): void {
		IATO_Test_Cron::reset();
		IATO_Test_Cache_State::reset();
		IATO_Test_WP::$meta = [];
		IATO_Test_Abilities_State::$filters = [];
		IATO_Test_Abilities_State::$actions = [];
		unset( IATO_Test_Abilities_State::$options[ IATO_MCP_Cache::OPTION ], IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] );
		self::allow_unfiltered( false );
		IATO_MCP_Cache::set_detector( fn( string $slug, bool $real ) => 'litespeed' === $slug );
	}

	protected function tearDown(): void {
		self::allow_unfiltered( false );
		IATO_MCP_Cache::set_detector( null );
		IATO_MCP_Cache::begin();
		unset( IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] );
	}

	private static function allow_unfiltered( bool $allow ): void {
		( new ReflectionClass( IATO_MCP_Auth::class ) )->getProperty( 'unfiltered_html_allowed' )->setValue( null, $allow );
	}

	private static function call( string $name, mixed $arguments = [] ): array {
		return ( new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' ) )->invoke( null, [ 'name' => $name, 'arguments' => $arguments ] );
	}

	private static function register( string $name, callable $handler ): void {
		IATO_MCP_Server::register_tool( $name, [ 'description' => 'test tool', 'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ] ], $handler );
	}

	// ── Input ─────────────────────────────────────────────────────────────

	public static function carrying(): array {
		$tag = '[elementor-element k="x" data="y"]';
		return [
			'plain'                => [ $tag ],
			'in text'              => [ 'Before ' . $tag . ' after' ],
			'no closing bracket'   => [ 'text [elementor-element k=x' ],
			'other case'           => [ '[ELEMENTOR-ELEMENT k="x"]' ],
			'nested array'         => [ [ 'a' => [ 'b' => [ 'c' => $tag ] ] ] ],
			'object'               => [ (object) [ 'a' => $tag ] ],
			'JSON string'          => [ json_encode( [ [ 'settings' => [ 'editor' => $tag ] ] ] ) ],
			'JSON escape'          => [ '[{"settings":{"editor":"[elementor-element k=x"}}]' ],
			'JSON inside JSON'     => [ json_encode( [ 'inner' => json_encode( [ 'editor' => "\u{5b}elementor-element" ] ) ] ) ],
			'split by a tag'       => [ '[elementor-<img>element k="x"]' ],
			'split by a comment'   => [ '[elementor-<!-- -->element k="x"]' ],
			'URL encoded'          => [ '%5Belementor-element%20k%3D%22x%22%5D' ],
			'URL encoded twice'    => [ '%255Belementor-element' ],
			'dynamic tag settings' => [ '[elementor-tag id="a" name="post-title" settings="' . rawurlencode( '{"before":"[elementor-element k=x"}' ) . '"]' ],
			'entity encoded'       => [ '&#91;elementor-element k=x' ],
			'named entity'         => [ '&lbrack;elementor-element k=x' ],
			'entity then tag'      => [ '[elementor-&lt;b&gt;element k=x' ],
		];
	}

	#[DataProvider( 'carrying' )]
	public function test_the_shortcode_is_found( mixed $value ): void {
		$this->assertTrue( IATO_MCP_Element_Cache_Guard::holds_shortcode( $value ) );
	}

	public static function ordinary(): array {
		return [
			'css class'       => [ '<div class="elementor-element elementor-element-abc123">x</div>' ],
			'another tag'     => [ '[gallery ids="1,2"]' ],
			'a dynamic tag'   => [ '[elementor-tag id="a" name="post-title" settings="%7B%7D"]' ],
			'an element tree' => [ '[{"id":"a1","elType":"widget","widgetType":"heading","settings":{"title":"Hi"},"elements":[]}]' ],
			'text in JSON'    => [ '{"note":"100% done & dusted"}' ],
			'number'          => [ 5 ],
			'null'            => [ null ],
			'empty'           => [ [] ],
		];
	}

	#[DataProvider( 'ordinary' )]
	public function test_ordinary_values_pass( mixed $value ): void {
		$this->assertFalse( IATO_MCP_Element_Cache_Guard::holds_shortcode( $value ) );
	}

	public function test_nesting_too_deep_to_read_counts_as_holding_it(): void {
		$value = 'x';
		for ( $i = 0; $i < 300; $i++ ) {
			$value = [ $value ];
		}
		$this->assertTrue( IATO_MCP_Element_Cache_Guard::holds_shortcode( $value ) );
	}

	public function test_a_call_carrying_it_is_refused_before_the_handler_runs(): void {
		$ran = false;
		self::register( '_guard_write', function ( array $args ) use ( &$ran ): array {
			$ran = true;
			return IATO_MCP_Server::ok( [ 'ok' => true ] );
		} );
		$r = self::call( '_guard_write', [ 'content' => 'x [elementor-element k="a" data="b"]' ] );
		$this->assertFalse( $ran );
		$this->assertTrue( $r['isError'] );
		$this->assertSame( 'reserved_shortcode', json_decode( $r['content'][0]['text'], true )['error'] );
		$this->assertStringContainsString( 'Nothing was written', json_decode( $r['content'][0]['text'], true )['message'] );

		$this->assertSame( IATO_MCP_Server::ok( [ 'ok' => true ] ), self::call( '_guard_write', [ 'content' => 'fine' ] ) );
		$this->assertTrue( $ran );
	}

	public function test_a_user_allowed_unfiltered_html_is_not_refused(): void {
		self::allow_unfiltered( true );
		self::register( '_guard_unfiltered', fn( array $args ): array => IATO_MCP_Server::ok( [ 'ok' => true ] ) );
		$this->assertSame( IATO_MCP_Server::ok( [ 'ok' => true ] ), self::call( '_guard_unfiltered', [ 'content' => '[elementor-element k="a"]' ] ) );
	}

	public function test_a_read_may_search_for_it(): void {
		foreach ( [ 'get', 'list', 'find', 'search', 'resolve' ] as $prefix ) {
			$name = $prefix . '_guard_read';
			$ran  = false;
			self::register( $name, function ( array $args ) use ( &$ran ): array {
				$ran = true;
				return IATO_MCP_Server::ok( [ 'ok' => true ] );
			} );
			$this->assertSame( IATO_MCP_Server::ok( [ 'ok' => true ] ), self::call( $name, [ 'query' => '[elementor-element' ] ), $name );
			$this->assertTrue( $ran, $name );
		}
	}

	public function test_a_read_result_still_has_the_key_replaced(): void {
		$key = str_repeat( 'k', 32 );
		IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] = $key;
		self::register( 'get_guard_key', fn( array $args ): array => IATO_MCP_Server::ok( [ 'value' => 'x ' . $key ] ) );
		$this->assertStringNotContainsString( $key, self::call( 'get_guard_key', [ 'query' => '[elementor-element' ] )['content'][0]['text'] );
	}

	public function test_only_read_names_count_as_reads(): void {
		foreach ( [ 'getter', 'update_get_thing', '_get_thing', 'rollback', 'start_iato_crawl' ] as $name ) {
			$this->assertFalse( IATO_MCP_Element_Cache_Guard::is_read_tool( $name ), $name );
		}
		foreach ( IATO_MCP_Cache::WRITE_TOOLS as $tool ) {
			$this->assertFalse( IATO_MCP_Element_Cache_Guard::is_read_tool( $tool ), $tool );
		}
	}

	// ── Output ────────────────────────────────────────────────────────────

	public function test_the_key_is_replaced_in_a_tool_result(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] = self::KEY;
		self::register( '_guard_leak', fn( array $args ): array => IATO_MCP_Server::ok( [ 'a' => [ 'b' => 'k="' . self::KEY . '"' ], 'c' => 1 ] ) );
		$r = self::call( '_guard_leak' );
		$this->assertStringNotContainsString( self::KEY, $r['content'][0]['text'] );
		$this->assertSame( [ 'a' => [ 'b' => 'k="[redacted]"' ], 'c' => 1 ], json_decode( $r['content'][0]['text'], true ), 'still valid JSON' );
	}

	public function test_the_key_is_replaced_in_an_error_result(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] = self::KEY;
		self::register( '_guard_leak_error', fn( array $args ) => new WP_Error( 'x', 'value was ' . self::KEY ) );
		$this->assertSame( 'value was [redacted]', self::call( '_guard_leak_error' )['content'][0]['text'] );
		self::register( '_guard_leak_throw', function ( array $args ): array {
			throw new RuntimeException( self::KEY );
		} );
		$this->assertStringNotContainsString( self::KEY, self::call( '_guard_leak_throw' )['content'][0]['text'] );
	}

	public function test_nothing_is_replaced_without_a_real_key(): void {
		$value = [ 'a' => 'text', 'n' => 5, 'z' => null ];
		$this->assertSame( $value, IATO_MCP_Element_Cache_Guard::scrub( $value ), 'no option' );
		IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] = 'text';
		$this->assertSame( $value, IATO_MCP_Element_Cache_Guard::scrub( $value ), 'too short to be the key' );
		IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] = [ 'not', 'a', 'string' ];
		$this->assertSame( $value, IATO_MCP_Element_Cache_Guard::scrub( $value ), 'not a string' );
	}

	public function test_scrub_keeps_every_other_type(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] = self::KEY;
		$value = [ 'n' => 5, 'f' => 1.5, 'b' => true, 'z' => null, self::KEY => 'kept key' ];
		$this->assertSame( $value, IATO_MCP_Element_Cache_Guard::scrub( $value ) );
	}

	// ── Meta policy ───────────────────────────────────────────────────────

	public function test_the_meta_is_denied_for_reads_and_writes(): void {
		foreach ( [ '_elementor_element_cache', '_ELEMENTOR_ELEMENT_CACHE', '_elementor_element_cache_unique_id' ] as $key ) {
			$this->assertTrue( IATO_MCP_Meta_Policy::is_denied( $key ), $key );
			$write = IATO_MCP_Meta_Policy::check_write( $key, true );
			$this->assertInstanceOf( WP_Error::class, $write );
			$this->assertSame( 'meta_denied', $write->get_error_code(), 'force does not help' );
		}
		[ $out, $redacted ] = IATO_MCP_Meta_Policy::redact_for_read( [ '_elementor_element_cache' => 'x', '_elementor_data' => '[]' ], true );
		$this->assertSame( [ '_elementor_data' => '[]' ], $out );
		$this->assertSame( [ '_elementor_element_cache' ], $redacted );
		$this->assertFalse( IATO_MCP_Meta_Policy::is_denied( '_elementor_data' ) );
	}

	// ── Upgrade ───────────────────────────────────────────────────────────

	public function test_refresh_replaces_the_key_clears_the_meta_and_schedules_one_sweep(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] = self::KEY;
		IATO_Test_WP::$meta = [ 7 => [ IATO_MCP_Element_Cache_Guard::META_KEY => 'cached', '_elementor_data' => '[]' ] ];

		IATO_MCP_Element_Cache_Guard::refresh();

		$new = IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $new );
		$this->assertNotSame( self::KEY, $new );
		$this->assertSame( [ '_elementor_data' => '[]' ], IATO_Test_WP::$meta[7], 'only the cache goes' );
		$this->assertArrayHasKey( IATO_MCP_Element_Cache_Guard::SWEEP_HOOK, IATO_Test_Cron::$events );
		$at = IATO_Test_Cron::$events[ IATO_MCP_Element_Cache_Guard::SWEEP_HOOK ]['at'];
		$this->assertEqualsWithDelta( time() + IATO_MCP_Element_Cache_Guard::SWEEP_DELAY, $at, 5 );

		IATO_Test_Cron::$events[ IATO_MCP_Element_Cache_Guard::SWEEP_HOOK ]['at'] = 123;
		IATO_MCP_Element_Cache_Guard::refresh();
		$this->assertSame( 123, IATO_Test_Cron::$events[ IATO_MCP_Element_Cache_Guard::SWEEP_HOOK ]['at'], 'a replay keeps the one event' );
	}

	public function test_refresh_on_a_site_without_a_key_creates_none(): void {
		IATO_Test_WP::$meta = [ 7 => [ IATO_MCP_Element_Cache_Guard::META_KEY => 'cached' ] ];
		IATO_MCP_Element_Cache_Guard::refresh();
		$this->assertArrayNotHasKey( IATO_MCP_Element_Cache_Guard::KEY_OPTION, IATO_Test_Abilities_State::$options );
		$this->assertSame( [], IATO_Test_WP::$meta[7] );
		$this->assertSame( [], IATO_Test_Cron::$events );
	}

	public function test_the_sweep_clears_the_meta_and_purges_the_site(): void {
		IATO_Test_WP::$meta = [ 7 => [ IATO_MCP_Element_Cache_Guard::META_KEY => 'old key' ] ];
		IATO_MCP_Element_Cache_Guard::sweep();
		$this->assertSame( [], IATO_Test_WP::$meta[7] );
		$this->assertContains( 'litespeed_purge_all:IATO MCP', IATO_Test_Cache_State::$calls );
	}

	public function test_the_sweep_respects_the_purge_setting(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Cache::OPTION ] = '0';
		IATO_Test_WP::$meta = [ 7 => [ IATO_MCP_Element_Cache_Guard::META_KEY => 'old key' ] ];
		IATO_MCP_Element_Cache_Guard::sweep();
		$this->assertSame( [], IATO_Test_WP::$meta[7], 'the meta still goes' );
		$this->assertSame( [], IATO_Test_Cache_State::$calls );
	}

	public function test_the_schema_step_is_the_refresh(): void {
		$this->assertSame( [ IATO_MCP_Schema::class, 'step_element_cache_refresh' ], IATO_MCP_Schema::steps()[6] );
		$this->assertSame( 6, IATO_MCP_Schema::VERSION );
		IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] = self::KEY;
		IATO_MCP_Schema::step_element_cache_refresh();
		$this->assertNotSame( self::KEY, IATO_Test_Abilities_State::$options[ IATO_MCP_Element_Cache_Guard::KEY_OPTION ] );
	}
}
