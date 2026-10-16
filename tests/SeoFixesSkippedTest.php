<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-elementor.php';
require_once __DIR__ . '/stubs-tools.php';

if ( ! class_exists( 'IATO_MCP_IATO_Client' ) ) {
	/** The IATO API, answering get_seo_issues() with a fixed list. */
	final class IATO_MCP_IATO_Client {
		public static array $issues = [];
		public static function get_seo_issues( string $crawl_id, ?string $severity, int $limit ): array {
			return [ 'success' => true, 'data' => [ 'issues' => self::$issues ] ];
		}
	}
}
if ( ! function_exists( 'url_to_postid' ) ) {
	function url_to_postid( string $url ): int {
		return [ 'https://site.test/about/' => 11, 'https://www.site.test/contact/' => 12, '/team/' => 13 ][ $url ] ?? 0;
	}
}

/**
 * 1.14.0: get_iato_seo_fixes lists issues the WordPress tools cannot act on
 * (another domain, a URL that is not a post of this site) under `skipped`
 * with the reason, instead of the fix list.
 */
final class SeoFixesSkippedTest extends TestCase {

	protected function setUp(): void {
		IATO_Test_OAuth_State::$home_url = 'https://site.test';
		IATO_Test_WP::$posts             = [ 11 => [ 'post_name' => 'about' ], 12 => [ 'post_name' => 'contact' ], 13 => [ 'post_name' => 'team' ] ];
		IATO_MCP_IATO_Client::$issues    = [
			[ 'type' => 'title', 'url' => 'https://site.test/about/', 'title' => 'Title too long' ],
			[ 'type' => 'meta_description', 'url' => 'https://www.site.test/contact/', 'title' => 'Missing description' ],
			[ 'type' => 'h1_missing', 'url' => '/team/', 'title' => 'No H1' ],
			[ 'type' => 'title', 'url' => 'https://other.test/page/', 'title' => 'Off-site' ],
			[ 'type' => 'alt_text', 'url' => 'https://site.test/category/news/', 'title' => 'Archive' ],
			[ 'type' => 'canonical', 'url' => '', 'title' => 'Site-wide' ],
			[ 'type' => 'title', 'url' => 'mailto:hi@site.test', 'title' => 'Mail link' ],
		];
	}

	public function test_issues_the_tools_cannot_act_on_are_listed_with_the_reason(): void {
		require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-seo-fixes.php';
		$r    = ( IATO_MCP_Server::get_catalog_entry( 'get_iato_seo_fixes' )['handler'] )( [ 'crawl_id' => 'c06736dd' ] );
		$body = json_decode( $r['content'][0]['text'], true );

		$this->assertSame( [ 11, 12, 13 ], array_column( $body['issues'], 'wp_post_id' ), 'www. and site-relative URLs are this site' );
		$this->assertSame( [ 3, 2, 1 ], [ $body['total'], $body['auto_fixable'], $body['manual'] ] );
		$this->assertSame(
			[
				[ 'https://other.test/page/', 'other_domain' ],
				[ 'https://site.test/category/news/', 'not_a_wordpress_post' ],
				[ '', 'not_a_wordpress_post' ],
				[ 'mailto:hi@site.test', 'not_a_wordpress_post' ],
			],
			array_map( fn( $s ) => [ $s['url'], $s['reason'] ], $body['skipped'] )
		);
		$this->assertSame( 'Off-site', $body['skipped'][0]['title'] );
	}
}
