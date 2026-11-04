<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-elementor.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';

/**
 * The `cache` object on tool responses (1.15.0): on every write, never on a dry
 * run or a read, and added without disturbing what the tool returned.
 */
final class CacheResponseTest extends TestCase {

	protected function setUp(): void {
		IATO_Test_Cache_State::reset();
		IATO_Test_OAuth_State::$transients = [];
		unset( IATO_Test_Abilities_State::$options[ IATO_MCP_Cache::OPTION ] );
		IATO_MCP_Cache::set_detector( fn( string $slug, bool $real ) => 'litespeed' === $slug );
		IATO_MCP_Cache::begin();
	}

	protected function tearDown(): void {
		IATO_MCP_Cache::set_detector( null );
		IATO_MCP_Cache::begin();
	}

	private static function call( string $name, mixed $arguments = [] ): array {
		$m = new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' );
		return $m->invoke( null, [ 'name' => $name, 'arguments' => $arguments ] );
	}

	private static function register( string $name, callable $handler ): void {
		IATO_MCP_Server::register_tool( $name, [ 'description' => 'test tool', 'inputSchema' => [ 'type' => 'object' ] ], $handler );
	}

	private static function body( array $result ): array {
		return json_decode( $result['content'][0]['text'], true );
	}

	private static function pending_write(): void {
		IATO_MCP_Cache::note_write( 'post', 9, 'title', 'a', 'b' );
	}

	// ── Through the tool dispatch ─────────────────────────────────────────

	public function test_a_handler_that_records_a_receipt_gets_cache_and_a_purge(): void {
		self::register( '_cache_writer', function ( array $args ): array {
			$receipt = IATO_MCP_Change_Receipt::record( 9, 'post', 'title', 'a', 'b' );
			return IATO_MCP_Server::ok( [ 'id' => 9, 'change_receipt' => $receipt ] );
		} );
		$body = self::body( self::call( '_cache_writer' ) );
		$this->assertSame( 9, $body['id'] );
		$this->assertSame( 'b', $body['change_receipt']['after_value'], 'the rest of the response is untouched' );
		$this->assertSame( [ 'detected' => [ 'litespeed' ], 'purged' => true, 'scope' => 'post', 'notes' => [] ], $body['cache'] );
		$this->assertSame( [ 'litespeed_purge_post:9' ], IATO_Test_Cache_State::$calls );
	}

	public function test_a_handler_that_throws_after_writing_still_purges(): void {
		self::register( '_cache_throws', function ( array $args ): array {
			IATO_MCP_Change_Receipt::record( 9, 'post', 'title', 'a', 'b' );
			throw new RuntimeException( 'after the write' );
		} );
		$r = self::call( '_cache_throws' );
		$this->assertTrue( $r['isError'] );
		$this->assertSame( 'tool_exception', self::body( $r )['error'] );
		$this->assertArrayNotHasKey( 'cache', self::body( $r ) );
		$this->assertSame( [ 'litespeed_purge_post:9' ], IATO_Test_Cache_State::$calls );
	}

	public function test_a_wp_error_after_writing_still_purges(): void {
		self::register( '_cache_wp_error', function ( array $args ) {
			IATO_MCP_Change_Receipt::record( 9, 'post', 'title', 'a', 'b' );
			return new WP_Error( 'partial', 'Second field failed.' );
		} );
		$this->assertSame( [ 'isError' => true, 'content' => [ [ 'type' => 'text', 'text' => 'Second field failed.' ] ] ], self::call( '_cache_wp_error' ) );
		$this->assertSame( [ 'litespeed_purge_post:9' ], IATO_Test_Cache_State::$calls );
	}

	public function test_an_is_error_result_after_writing_purges_without_a_field(): void {
		self::register( '_cache_is_error', function ( array $args ): array {
			IATO_MCP_Change_Receipt::record( 9, 'post', 'title', 'a', 'b' );
			return [ 'isError' => true, 'content' => [ [ 'type' => 'text', 'text' => '{"error":"x"}' ] ] ];
		} );
		$this->assertSame( '{"error":"x"}', self::call( '_cache_is_error' )['content'][0]['text'] );
		$this->assertSame( [ 'litespeed_purge_post:9' ], IATO_Test_Cache_State::$calls );
	}

	public function test_a_previous_call_leaves_nothing_behind(): void {
		self::pending_write(); // e.g. a test or a request path that never reached finish()
		self::register( '_cache_reader', fn( array $args ): array => IATO_MCP_Server::ok( [ 'ok' => true ] ) );
		$this->assertSame( IATO_MCP_Server::ok( [ 'ok' => true ] ), self::call( '_cache_reader' ) );
		$this->assertSame( [], IATO_Test_Cache_State::$calls );
	}

	// ── finish() ──────────────────────────────────────────────────────────

	public function test_reads_are_returned_exactly_as_they_came(): void {
		$read = IATO_MCP_Server::ok( [ 'id' => 1 ] );
		$this->assertSame( $read, IATO_MCP_Cache::finish( 'get_post', [], $read ) );
	}

	public function test_a_dry_run_has_no_cache_and_does_not_purge(): void {
		$r = IATO_MCP_Server::ok( [ 'dry_run' => true ] );
		$this->assertSame( $r, IATO_MCP_Cache::finish( 'update_elementor_widget', [ 'dry_run' => true ], $r ) );
		$this->assertSame( [], IATO_Test_Cache_State::$calls );
	}

	public function test_a_tool_that_ignores_dry_run_and_wrote_says_so(): void {
		self::pending_write(); // create_post and six others have no dry run: they really write
		$body = self::body( IATO_MCP_Cache::finish( 'create_post', [ 'dry_run' => true ], IATO_MCP_Server::ok( [ 'id' => 9 ] ) ) );
		$this->assertTrue( $body['cache']['purged'] );
	}

	public function test_a_write_tool_that_changed_nothing_says_so(): void {
		$body = self::body( IATO_MCP_Cache::finish( 'update_post', [], IATO_MCP_Server::ok( [ 'id' => 9 ] ) ) );
		$this->assertSame( [ 'litespeed' ], $body['cache']['detected'] );
		$this->assertNull( $body['cache']['purged'] );
		$this->assertNull( $body['cache']['scope'] );
		$this->assertStringContainsString( 'Nothing was written', $body['cache']['notes'][0] );
		$this->assertSame( [], IATO_Test_Cache_State::$calls );
	}

	public function test_an_idempotency_replay_is_not_a_write(): void {
		$body = self::body( IATO_MCP_Cache::finish( 'update_elementor_widget', [ 'idempotency_key' => 'k' ], IATO_MCP_Server::ok( [ 'idempotency_replay' => true ] ) ) );
		$this->assertTrue( $body['idempotency_replay'] );
		$this->assertNull( $body['cache']['purged'] );
	}

	public function test_a_write_tool_error_gets_no_field(): void {
		$r = [ 'isError' => true, 'content' => [ [ 'type' => 'text', 'text' => 'refused' ] ] ];
		$this->assertSame( $r, IATO_MCP_Cache::finish( 'update_post', [], $r ) );
	}

	public function test_arguments_that_are_not_an_array_do_not_matter(): void {
		$body = self::body( IATO_MCP_Cache::finish( 'update_post', 'nonsense', IATO_MCP_Server::ok( [ 'id' => 1 ] ) ) );
		$this->assertNull( $body['cache']['purged'] );
	}

	// ── Keeping the response intact ───────────────────────────────────────

	public function test_empty_objects_in_the_response_stay_objects(): void {
		self::pending_write();
		$text = wp_json_encode( [ 'a' => new stdClass(), 'b' => [] ] );
		$out  = IATO_MCP_Cache::finish( 'update_post', [], [ 'content' => [ [ 'type' => 'text', 'text' => $text ] ] ] );
		$this->assertStringStartsWith( '{"a":{},"b":[],"cache":{', $out['content'][0]['text'] );
		$this->assertIsArray( json_decode( $out['content'][0]['text'], true )['cache'] );
	}

	public function test_an_empty_object_gets_no_stray_comma(): void {
		self::pending_write();
		$out = IATO_MCP_Cache::finish( 'update_post', [], [ 'content' => [ [ 'type' => 'text', 'text' => '{}' ] ] ] );
		$this->assertArrayHasKey( 'cache', json_decode( $out['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR ) );
	}

	public function test_text_that_is_not_a_json_object_is_left_alone(): void {
		foreach ( [ '[1,2]', 'plain text', false, null ] as $text ) {
			self::pending_write();
			$in = [ 'content' => [ [ 'type' => 'text', 'text' => $text ] ] ];
			$this->assertSame( $in, IATO_MCP_Cache::finish( 'update_post', [], $in ), var_export( $text, true ) );
		}
	}

	// ── The tool lists ────────────────────────────────────────────────────

	/** @return string[] every tool name passed to register_tool() in the plugin */
	private static function registered_tools(): array {
		$names = [];
		$files = array_merge( glob( IATO_MCP_DIR . 'includes/tools/wp/*.php' ), glob( IATO_MCP_DIR . 'includes/tools/bridge/*.php' ) );
		foreach ( $files as $file ) {
			preg_match_all( "/register_tool\(\s*'([a-z_]+)'/", (string) file_get_contents( $file ), $m );
			$names = array_merge( $names, $m[1] );
		}
		sort( $names );
		return $names;
	}

	public function test_every_registered_tool_is_a_read_or_on_the_write_list(): void {
		$tools = self::registered_tools();
		$this->assertGreaterThan( 50, count( $tools ) );
		foreach ( $tools as $tool ) {
			$is_read = 1 === preg_match( '/^(get|list|find|search|resolve)_/', $tool );
			$is_listed = in_array( $tool, IATO_MCP_Cache::WRITE_TOOLS, true );
			$this->assertTrue( $is_read xor $is_listed || 'start_iato_crawl' === $tool, $tool . ' must be named like a read or be in IATO_MCP_Cache::WRITE_TOOLS' );
		}
		$this->assertSame( [], array_values( array_diff( IATO_MCP_Cache::WRITE_TOOLS, $tools ) ), 'every listed tool exists' );
	}

	public function test_a_new_tool_is_in_every_settings_list(): void {
		$source = (string) file_get_contents( IATO_MCP_DIR . 'includes/class-settings.php' );
		foreach ( [ 'TOOL_NAMES', 'TOOL_DESCRIPTIONS', 'TOOL_CATEGORIES', 'TOOL_MIGRATION_BACKFILL' ] as $constant ) {
			$this->assertSame( 1, preg_match( '/const ' . $constant . ' = \[(.*?)\n\t\];/s', $source, $m ), $constant );
			$this->assertStringContainsString( "'update_site_settings'", $m[1], $constant );
		}
		$this->assertMatchesRegularExpression( "/'1\.15\.0' => \[\s*'update_site_settings',/", $source );
	}
}
