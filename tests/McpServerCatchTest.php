<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * 1.14.0: an uncaught Throwable from a tool handler becomes a clear tool
 * error (isError, JSON text with tool_exception) instead of a PHP fatal.
 */
final class McpServerCatchTest extends TestCase {

	private static function call( string $name, mixed $arguments = [] ): array {
		$m = new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' );
		return $m->invoke( null, [ 'name' => $name, 'arguments' => $arguments ] );
	}

	private static function register( string $name, callable $handler ): void {
		IATO_MCP_Server::register_tool( $name, [ 'description' => 'test tool', 'inputSchema' => [ 'type' => 'object' ] ], $handler );
	}

	public function test_a_throwing_handler_becomes_a_tool_exception_error(): void {
		self::register( '_test_throws', function ( array $args ): array {
			throw new RuntimeException( 'addon exploded' );
		} );
		$r = self::call( '_test_throws' );
		$this->assertTrue( $r['isError'] );
		$body = json_decode( $r['content'][0]['text'], true );
		$this->assertSame( 'tool_exception', $body['error'] );
		$this->assertSame( '_test_throws', $body['tool'] );
		$this->assertSame( 'RuntimeException', $body['exception'] );
		$this->assertStringContainsString( 'addon exploded', $body['message'] );
		$this->assertStringContainsString( 'check the change receipts', $body['message'] );
	}

	public function test_errors_and_type_errors_are_caught_too(): void {
		self::register( '_test_error', function ( array $args ): array {
			return intdiv( 1, 0 ); // DivisionByZeroError, an Error not an Exception
		} );
		$this->assertSame( 'DivisionByZeroError', json_decode( self::call( '_test_error' )['content'][0]['text'], true )['exception'] );

		self::register( '_test_typed', fn( array $args ): array => IATO_MCP_Server::ok( [ 'ok' => true ] ) );
		$bad = self::call( '_test_typed', 'not an object' );
		$this->assertSame( 'TypeError', json_decode( $bad['content'][0]['text'], true )['exception'], 'arguments that are not an object no longer fatal' );
	}

	public function test_a_buffer_a_tool_left_open_cannot_come_out_ahead_of_the_response(): void {
		$level = ob_get_level();
		self::register( '_test_open_buffer', function ( array $args ): array {
			ob_start();
			echo '<div>half a render</div>';
			return IATO_MCP_Server::ok( [ 'ok' => true ] );
		} );
		ob_start();
		$r = self::call( '_test_open_buffer' );
		$this->assertSame( $level + 1, ob_get_level(), 'only what the tool opened is closed' );
		$this->assertSame( '', ob_get_clean(), 'and what it held is gone' );
		$this->assertSame( IATO_MCP_Server::ok( [ 'ok' => true ] ), $r );

		self::register( '_test_throws_with_buffer', function ( array $args ): array {
			ob_start();
			throw new RuntimeException( 'render failed' );
		} );
		$this->assertSame( 'tool_exception', json_decode( self::call( '_test_throws_with_buffer' )['content'][0]['text'], true )['error'] );
		$this->assertSame( $level, ob_get_level() );
	}

	public function test_normal_results_and_wp_errors_are_unchanged(): void {
		self::register( '_test_ok', fn( array $args ): array => IATO_MCP_Server::ok( [ 'ok' => true ] ) );
		$this->assertSame( IATO_MCP_Server::ok( [ 'ok' => true ] ), self::call( '_test_ok' ) );
		self::register( '_test_wp_error', fn( array $args ) => new WP_Error( 'nope', 'Plain refusal.' ) );
		$this->assertSame( [ 'isError' => true, 'content' => [ [ 'type' => 'text', 'text' => 'Plain refusal.' ] ] ], self::call( '_test_wp_error' ) );
	}
}
