<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * 1.16.0: every tool that writes honours dry_run, and a tool that cannot is not run
 * when asked for one. What each tool's dry run answers is covered by
 * tests/wp-env/dry-run-live.py.
 */
final class DryRunContractTest extends TestCase {

	private static function call( string $name, array $arguments ): array {
		return ( new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' ) )->invoke( null, [ 'name' => $name, 'arguments' => $arguments ] );
	}

	private static function register( string $name, array $properties, callable $handler ): void {
		IATO_MCP_Server::register_tool( $name, [ 'description' => 'test tool', 'inputSchema' => [ 'type' => 'object', 'properties' => $properties ] ], $handler );
	}

	protected function setUp(): void {
		IATO_MCP_Cache::begin();
	}

	public function test_every_write_tool_declares_dry_run(): void {
		$blocks = [];
		foreach ( array_merge( (array) glob( IATO_MCP_DIR . 'includes/tools/wp/*.php' ), (array) glob( IATO_MCP_DIR . 'includes/tools/bridge/*.php' ) ) as $file ) {
			foreach ( explode( 'IATO_MCP_Server::register_tool(', (string) file_get_contents( (string) $file ) ) as $block ) {
				if ( 1 === preg_match( "/^\s*'([a-z_]+)'/", $block, $m ) ) {
					// The definition ends where the handler starts.
					$blocks[ $m[1] ] = strstr( $block, 'function (', true ) ?: $block;
				}
			}
		}
		foreach ( IATO_MCP_Cache::WRITE_TOOLS as $tool ) {
			$this->assertArrayHasKey( $tool, $blocks, "{$tool} is registered" );
			$this->assertStringContainsString( "'dry_run'", $blocks[ $tool ], "{$tool} declares dry_run" );
		}
	}

	public function test_a_tool_with_no_dry_run_is_not_run_when_asked_for_one(): void {
		$ran = false;
		self::register( '_dry_none', [], function ( array $args ) use ( &$ran ): array {
			$ran = true;
			return IATO_MCP_Server::ok( [ 'ok' => true ] );
		} );
		$r = self::call( '_dry_none', [ 'dry_run' => true ] );
		$this->assertFalse( $ran );
		$this->assertTrue( $r['isError'] );
		$text = json_decode( $r['content'][0]['text'], true );
		$this->assertSame( 'dry_run_unsupported', $text['error'] );
		$this->assertStringContainsString( 'Nothing was changed', $text['message'] );
	}

	public function test_a_tool_with_no_inputs_is_refused_too(): void {
		$ran = false;
		IATO_MCP_Server::register_tool( '_dry_empty_object', [ 'description' => 'test tool', 'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass() ] ], function ( array $args ) use ( &$ran ): array {
			$ran = true;
			return IATO_MCP_Server::ok( [ 'ok' => true ] );
		} );
		$this->assertTrue( self::call( '_dry_empty_object', [ 'dry_run' => true ] )['isError'], 'tools with no inputs declare properties as an object' );
		$this->assertFalse( $ran );
	}

	public function test_a_truthy_value_counts_as_asking(): void {
		$ran = false;
		self::register( '_dry_truthy', [], function ( array $args ) use ( &$ran ): array {
			$ran = true;
			return IATO_MCP_Server::ok( [ 'ok' => true ] );
		} );
		foreach ( [ 1, '1', 'true', 'false' ] as $value ) {
			$this->assertTrue( self::call( '_dry_truthy', [ 'dry_run' => $value ] )['isError'], var_export( $value, true ) );
		}
		$this->assertFalse( $ran, 'a string the caller meant as a flag is not worth a real write' );
	}

	public function test_without_the_flag_the_tool_runs(): void {
		$runs = 0;
		self::register( '_dry_absent', [], function ( array $args ) use ( &$runs ): array {
			$runs++;
			return IATO_MCP_Server::ok( [ 'ok' => true ] );
		} );
		self::call( '_dry_absent', [] );
		self::call( '_dry_absent', [ 'dry_run' => false ] );
		self::call( '_dry_absent', [ 'dry_run' => 0 ] );
		$this->assertSame( 3, $runs );
	}

	public function test_a_tool_that_declares_dry_run_receives_it(): void {
		$seen = null;
		self::register( '_dry_declared', [ 'dry_run' => [ 'type' => 'boolean' ] ], function ( array $args ) use ( &$seen ): array {
			$seen = $args['dry_run'];
			return IATO_MCP_Server::ok( [ 'ok' => true ] );
		} );
		self::call( '_dry_declared', [ 'dry_run' => true ] );
		$this->assertTrue( $seen );
	}
}
