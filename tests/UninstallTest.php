<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 1.21.0: deleting the plugin keeps page CSS and fonts, which are page content and
 * come back when the plugin is installed again, and removes what only works while it
 * runs: preview links (and, with page scripts, the scripts and their approvals).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class UninstallTest extends TestCase {

	/** @var list<string> */
	public static array $queries = [];
	/** @var list<string> */
	public static array $options_deleted = [];

	private function run_uninstall(): string {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'mcp-wordpress/iato-mcp.php' );
		}
		foreach ( [
			'delete_option'          => 'function delete_option( $o ) { UninstallTest::$options_deleted[] = $o; return true; }',
			'wp_clear_scheduled_hook' => 'function wp_clear_scheduled_hook( $h ) { return 0; }',
			'wp_upload_dir'          => 'function wp_upload_dir( $a = null, $b = true ) { return [ "basedir" => sys_get_temp_dir() . "/iato-no-such-dir-" . getmypid() ]; }',
			'delete_transient'       => 'function delete_transient( $t ) { return true; }',
			'wp_delete_file'         => 'function wp_delete_file( $f ) {}',
		] as $name => $code ) {
			if ( ! function_exists( $name ) ) {
				eval( $code );
			}
		}
		$GLOBALS['wpdb'] = new class() {
			public string $prefix   = 'wp_';
			public string $options  = 'wp_options';
			public string $postmeta = 'wp_postmeta';
			public function query( string $sql ): int {
				UninstallTest::$queries[] = $sql;
				return 0;
			}
			public function prepare( string $sql, mixed ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
			}
		};
		include IATO_MCP_DIR . 'uninstall.php';
		return implode( "\n", self::$queries );
	}

	public function test_page_css_and_fonts_stay_and_preview_links_go(): void {
		$sql = $this->run_uninstall();
		$this->assertStringContainsString( "meta_key = '_iato_mcp_preview_links'", $sql );
		$this->assertStringNotContainsString( '_iato_mcp_page_css\'', $sql, 'page CSS is page content' );
		$this->assertStringNotContainsString( '_iato_mcp_page_fonts', $sql, 'page fonts are page content' );
		$this->assertStringContainsString( '_transient_iato_mcp_page_css_ok_%', $sql, 'the cache of checked CSS goes' );
	}
}
