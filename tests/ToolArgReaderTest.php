<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/tool-arg-reader.php';
require_once __DIR__ . '/fixtures/arg-reader-fixtures.php';

/** The reader behind tests/ToolSchemaParityTest.php sees what it should, and says what it cannot. */
final class ToolArgReaderTest extends TestCase {

	private static function read( callable $handler, ?string $root = null ): IATO_Test_Arg_Reader {
		return IATO_Test_Arg_Reader::of( $handler, $root ?? __DIR__ . '/' );
	}

	private static function keys( IATO_Test_Arg_Reader $reader ): array {
		$keys = array_keys( $reader->keys );
		sort( $keys );
		return $keys;
	}

	public function test_plain_reads(): void {
		$r = self::read( function ( array $args ): array {
			$a = $args['plain'] ?? $args["double"] ?? null;
			if ( isset( $args['in_isset'] ) || ! empty( $args['in_empty'] ) || array_key_exists( 'in_exists', $args ) ) {
				$a = $args['nested']['inner'];
			}
			$keys = array_keys( $args );
			return [ $a, $keys, json_encode( $args ) ];
		} );
		$this->assertSame( [ 'double', 'in_empty', 'in_exists', 'in_isset', 'nested', 'plain' ], self::keys( $r ) );
		$this->assertSame( [], $r->unresolved, 'functions built into PHP only look at the array' );
	}

	public function test_loops_over_literal_lists_and_constants(): void {
		$r = self::read( function ( array $args ): void {
			foreach ( [ 'list_a', 'list_b' ] as $key ) {
				$x = $args[ $key ] ?? null;
			}
			foreach ( [ 'map_a' => 1, 'map_b' => [ 2, 3 ] ] as $key => [ $first ] ) {
				$x = $args[ $key ] ?? null;
			}
			foreach ( IATO_MCP_Fixture_Args::FIELDS as $field => $option ) {
				$x = $args[ $field ] ?? null;
			}
		} );
		$this->assertSame( [ 'const_a', 'const_b', 'list_a', 'list_b', 'map_a', 'map_b' ], self::keys( $r ) );
		$this->assertSame( [], $r->unresolved );
	}

	public function test_only_the_loop_around_the_read_counts(): void {
		$r = self::read( function ( array $args ): void {
			foreach ( [ 'elsewhere' ] as $key ) {
				$y = $key;
			}
			foreach ( [ 'around' ] as $key ) {
				$x = $args[ $key ] ?? null;
			}
		} );
		$this->assertSame( [ 'around' ], self::keys( $r ) );
	}

	public function test_follows_helpers_copies_and_literal_arguments(): void {
		$r = self::read( function ( array $args ): void {
			iato_mcp_fixture_reads_second( 'x', $args );
			IATO_MCP_Fixture_Args::entry( $args );
			$copy = $args;
			$z    = $copy['from_copy'] ?? null;
		} );
		$this->assertSame( [ 'bound_literal', 'const_a', 'const_b', 'from_copy', 'from_helper' ], self::keys( $r ) );
		$this->assertSame( [], $r->unresolved );
	}

	public function test_what_it_cannot_follow_is_reported(): void {
		$object = new ArrayObject();
		$r      = self::read( function ( array $args ) use ( $object ): void {
			foreach ( $args as $key => $value ) {
				$x = $value;
			}
			$list = [ 'a' ];
			foreach ( $list as $key ) {
				$x = $args[ $key ] ?? null;
			}
			$x = $args[ 'a' . 'b' ] ?? null;
			$object->exchangeArray( $args );
			$x = iato_mcp_fixture_returns( $args );
		} );
		$what = array_column( $r->unresolved, 'what' );
		$this->assertContains( 'foreach over $args', $what );
		$this->assertContains( '$key loops over $list', $what );
		$this->assertContains( '$args[ an expression ]', $what );
		$this->assertContains( '$args passed to ->exchangeArray() on an object', $what );
		$this->assertContains( "\$input in 'return \$input ;'", $what, 'a helper that hands the array back is followed and reported' );
	}

	public function test_plugin_code_that_is_only_a_stub_here_is_reported(): void {
		$r = self::read( function ( array $args ): void {
			iato_mcp_fixture_reads_second( 'x', $args );
		}, IATO_MCP_DIR . 'includes/' );
		$this->assertSame( [], self::keys( $r ) );
		$this->assertSame( [ 'iato_mcp_fixture_reads_second() is a test stub here, not the plugin code' ], array_column( $r->unresolved, 'what' ) );
	}
}
