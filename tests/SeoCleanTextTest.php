<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-tools.php';
require_once IATO_MCP_DIR . 'includes/class-seo-adapter.php';

/**
 * SEO plugins' variables start with what looks like an encoded character (1.16.0).
 * The test stub of sanitize_text_field() keeps %xx, so this proves the wrapping; the
 * live suite (write-safety-live) proves it against the real function.
 */
final class SeoCleanTextTest extends TestCase {

	public function test_percent_signs_survive(): void {
		$this->assertSame( '%%category%% %%date%% 100%', IATO_MCP_SEO_Adapter::clean_text( '%%category%% %%date%% 100%' ) );
		$this->assertSame( 'a%20b', IATO_MCP_SEO_Adapter::clean_text( 'a%20b' ) );
	}

	public function test_tags_still_go(): void {
		$this->assertSame( 'Title', IATO_MCP_SEO_Adapter::clean_text( '<b>Title</b>' ) );
	}

	public function test_what_is_not_text_becomes_empty(): void {
		$this->assertSame( '', IATO_MCP_SEO_Adapter::clean_text( [ 'a' ] ) );
		$this->assertSame( '', IATO_MCP_SEO_Adapter::clean_text( null ) );
		$this->assertSame( '12', IATO_MCP_SEO_Adapter::clean_text( 12 ) );
	}
}
