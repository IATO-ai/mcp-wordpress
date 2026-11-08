<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 1.20.0: update_post's slug check follows WordPress's own rule. Setting a page's slug
 * to "services" was refused because a menu item had that name, which WordPress itself
 * accepts: it compares a page only with pages and attachments under the same parent.
 * Each test runs in its own process: its get_post() and database stubs answer from a
 * small table and must not replace the stubs other tests use.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SlugConflictTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/stubs-url-move.php';
		IATO_Test_Slugs::$posts = [
			47 => (object) [ 'ID' => 47, 'post_type' => 'nav_menu_item', 'post_name' => 'services', 'post_parent' => 0, 'post_title' => 'Services', 'post_status' => 'publish' ],
			60 => (object) [ 'ID' => 60, 'post_type' => 'page', 'post_name' => 'about', 'post_parent' => 0, 'post_title' => 'About', 'post_status' => 'publish' ],
			61 => (object) [ 'ID' => 61, 'post_type' => 'page', 'post_name' => 'team', 'post_parent' => 70, 'post_title' => 'Team', 'post_status' => 'publish' ],
			62 => (object) [ 'ID' => 62, 'post_type' => 'page', 'post_name' => 'new-page', 'post_parent' => 0, 'post_title' => 'New', 'post_status' => 'draft' ],
			63 => (object) [ 'ID' => 63, 'post_type' => 'post', 'post_name' => 'about', 'post_parent' => 0, 'post_title' => 'A post', 'post_status' => 'publish' ],
		];
	}

	public function test_a_menu_items_name_is_no_conflict_for_a_page(): void {
		$this->assertNull( IATO_MCP_URL_Move::slug_refusal( 'services', 62, 'page', 0 ) );
		$this->assertSame( [ [ 'services', 62, 'publish', 'page', 0 ] ], IATO_Test_Slugs::$calls );
	}

	public function test_a_draft_is_checked_as_if_it_were_published(): void {
		IATO_MCP_URL_Move::slug_refusal( 'x', 62, 'page', 0 );
		$this->assertSame( 'publish', IATO_Test_Slugs::$calls[0][2], 'WordPress returns a draft\'s slug unchanged, so the -2 would only appear at publish time' );
	}

	public function test_a_siblings_slug_is_refused_and_named(): void {
		IATO_Test_Slugs::$would_use = [ 'about' => 'about-2' ];
		$e                          = IATO_MCP_URL_Move::slug_refusal( 'about', 62, 'page', 0 );
		$this->assertSame( 'slug_conflict', $e->get_error_code() );
		$data = $e->get_error_data();
		$this->assertSame( 60, $data['conflict_post_id'], 'the page, not the post called about' );
		$this->assertSame( 'in_use', $data['reason'] );
		$this->assertSame( 'about-2', $data['wordpress_would_use'] );
		$this->assertNull( $data['conflict_post_title'], 'a caller who cannot read the holder does not get its title' );
		$this->assertStringContainsString( 'under the same parent', $e->get_error_message() );
	}

	public function test_the_parent_it_will_have_is_the_one_asked_about(): void {
		IATO_Test_Slugs::$would_use = [ 'team' => 'team-2' ];
		$e                          = IATO_MCP_URL_Move::slug_refusal( 'team', 62, 'page', 70 );
		$this->assertSame( 61, $e->get_error_data()['conflict_post_id'] );
		$this->assertSame( 70, IATO_Test_Slugs::$calls[0][4] );
		$this->assertStringContainsString( 'post_parent = 70', IATO_Test_Slugs::$queries[0] );
	}

	public function test_a_slug_wordpress_reserves_is_refused_without_a_holder(): void {
		IATO_Test_Slugs::$would_use = [ 'feed' => 'feed-2' ];
		$e                          = IATO_MCP_URL_Move::slug_refusal( 'feed', 62, 'page', 0 );
		$this->assertSame( 'slug_conflict', $e->get_error_code() );
		$this->assertNull( $e->get_error_data()['conflict_post_id'] );
		$this->assertSame( 'reserved', $e->get_error_data()['reason'] );
	}

	public function test_a_post_is_compared_with_posts_only(): void {
		IATO_Test_Slugs::$would_use = [ 'about' => 'about-2' ];
		$e                          = IATO_MCP_URL_Move::slug_refusal( 'about', 64, 'post', 0 );
		$this->assertSame( 63, $e->get_error_data()['conflict_post_id'] );
		$this->assertStringNotContainsString( 'post_parent', IATO_Test_Slugs::$queries[0] );
	}

	public function test_the_holder_query_never_reaches_menu_items(): void {
		$this->assertNull( IATO_MCP_URL_Move::slug_holder( 'services', 62, 'page', 0 ) );
		$this->assertSame( 47, IATO_MCP_URL_Move::slug_holder( 'services', 62, 'attachment', 0 ), 'an attachment is compared with every post, as WordPress does' );
	}
}
