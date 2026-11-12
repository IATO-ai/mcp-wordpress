<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 1.21.0: the page asset tools ship switched off. On a fresh install, where the saved
 * list is empty and every other tool counts as on, they count as off; on an upgrade
 * they are never backfilled. The real settings class is loaded under another name,
 * since unit tests use a stand-in for it.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class DefaultOffToolsTest extends TestCase {

	private static function source(): string {
		return (string) file_get_contents( IATO_MCP_DIR . 'includes/class-settings.php' );
	}

	/** @return string[] The names in one of the class's constant lists. */
	private static function constant_list( string $name ): string {
		if ( 1 !== preg_match( '/const ' . $name . ' = \[(.*?)\n\t\];/s', self::source(), $m ) ) {
			self::fail( "no {$name}" );
		}
		return $m[1];
	}

	protected function setUp(): void {
		require_once IATO_MCP_DIR . 'includes/tools-selection.php';
		if ( ! class_exists( 'IATO_MCP_Settings_Real' ) ) {
			$code = preg_replace( '/^<\?php|defined\( \'ABSPATH\' \) \|\| exit;/m', '', self::source() );
			eval( str_replace( 'class IATO_MCP_Settings ', 'class IATO_MCP_Settings_Real ', (string) $code ) );
		}
		IATO_Test_Abilities_State::$options = [];
	}

	public function test_the_stand_in_mirrors_the_real_list(): void {
		$this->assertSame( IATO_MCP_Settings_Real::DEFAULT_OFF_TOOLS, IATO_MCP_Settings::DEFAULT_OFF_TOOLS );
	}

	public function test_a_fresh_install_leaves_them_off_and_the_rest_on(): void {
		foreach ( IATO_MCP_Settings_Real::DEFAULT_OFF_TOOLS as $tool ) {
			$this->assertFalse( IATO_MCP_Settings_Real::is_tool_enabled( $tool ), $tool );
		}
		$this->assertTrue( IATO_MCP_Settings_Real::is_tool_enabled( 'update_custom_css' ) );
		$this->assertTrue( IATO_MCP_Settings_Real::is_tool_enabled( 'get_posts' ) );
	}

	public function test_a_saved_list_decides(): void {
		IATO_Test_Abilities_State::$options['iato_mcp_tools'] = [ 'get_posts', 'set_page_css' ];
		$this->assertTrue( IATO_MCP_Settings_Real::is_tool_enabled( 'set_page_css' ) );
		$this->assertFalse( IATO_MCP_Settings_Real::is_tool_enabled( 'create_preview_link' ) );
		$this->assertFalse( IATO_MCP_Settings_Real::is_tool_enabled( 'update_post' ) );
	}

	public function test_they_are_listed_described_and_placed_but_never_backfilled(): void {
		$names       = self::constant_list( 'TOOL_NAMES' );
		$descriptions = self::constant_list( 'TOOL_DESCRIPTIONS' );
		$categories  = self::constant_list( 'TOOL_CATEGORIES' );
		$backfill    = self::constant_list( 'TOOL_MIGRATION_BACKFILL' );
		foreach ( IATO_MCP_Settings_Real::DEFAULT_OFF_TOOLS as $tool ) {
			$this->assertStringContainsString( "'{$tool}'", $names, $tool );
			$this->assertMatchesRegularExpression( "/'{$tool}'\s*=> '/", $descriptions, $tool );
			$this->assertStringContainsString( "'{$tool}'", $categories, $tool );
			$this->assertStringNotContainsString( "'{$tool}'", $backfill, "{$tool} must stay off on upgrades" );
		}
	}

	public function test_the_gated_merge_counts_them_as_off_on_a_fresh_install(): void {
		$this->assertFalse( iato_mcp_tool_was_enabled( 'set_page_css', [] ) );
		$this->assertTrue( iato_mcp_tool_was_enabled( 'get_iato_sitemap', [] ) );
	}
}
