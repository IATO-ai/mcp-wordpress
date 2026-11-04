<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * With no cache plugin loaded at all (a fresh process that never saw
 * tests/stubs-cache.php), the real guards see nothing and nothing fatals.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class CachePurgeAbsentTest extends TestCase {

	public function test_no_plugin_functions_means_nothing_detected_and_no_fatal(): void {
		$this->assertFalse( function_exists( 'rocket_clean_post' ), 'this process has no cache plugin stubs' );
		if ( ! function_exists( 'do_action' ) ) {
			function do_action( string $hook, mixed ...$args ): void {}
		}
		$this->assertSame( [], IATO_MCP_Cache::detected() );
		$this->assertSame( [], IATO_MCP_Cache::purge( 'all' ) );
		$this->assertSame( [], IATO_MCP_Cache::purge( 'post', 5 ) );
	}
}
