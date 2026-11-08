<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';

/**
 * 1.20.0: parent and menu_order on create_post and update_post. The rules here; moving
 * real pages, their URLs, redirects and rollback are checked by
 * tests/wp-env/restructure-live.py.
 */
final class PageParentTest extends TestCase {

	private static function page( string $type = 'page', string $status = 'publish' ): object {
		return (object) [ 'ID' => 30, 'post_type' => $type, 'post_status' => $status ];
	}

	private static function problem( ?WP_Error $error ): ?string {
		return null === $error ? null : $error->get_error_code();
	}

	public function test_an_id_is_an_integer_or_digits(): void {
		$this->assertSame( 0, IATO_MCP_URL_Move::int_arg( 0 ) );
		$this->assertSame( 12, IATO_MCP_URL_Move::int_arg( '12' ) );
		$this->assertSame( -3, IATO_MCP_URL_Move::int_arg( '-3', PHP_INT_MIN ) );
		foreach ( [ -1, '1.5', 'x', '', null, true, [ 3 ], '12345678901' ] as $bad ) {
			$this->assertNull( IATO_MCP_URL_Move::int_arg( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_a_post_has_no_parent_even_zero(): void {
		$e = IATO_MCP_URL_Move::parent_problem( 5, 'post', false, 0, null, false, [] );
		$this->assertSame( 'parent_not_supported', $e->get_error_code() );
		$this->assertStringContainsString( 'Nothing was written', $e->get_error_message() );
	}

	public function test_zero_clears_the_parent(): void {
		$this->assertNull( IATO_MCP_URL_Move::parent_problem( 5, 'page', true, 0, null, false, [] ) );
	}

	public function test_a_page_cannot_be_its_own_parent_or_go_under_its_own_child(): void {
		$this->assertSame( 'parent_cycle', self::problem( IATO_MCP_URL_Move::parent_problem( 30, 'page', true, 30, self::page(), true, [] ) ) );
		$this->assertSame( 'parent_cycle', self::problem( IATO_MCP_URL_Move::parent_problem( 5, 'page', true, 30, self::page(), true, [ 12, 5, 1 ] ) ), 'post 30 is under post 5' );
		$this->assertNull( IATO_MCP_URL_Move::parent_problem( 5, 'page', true, 30, self::page(), true, [ 12, 1 ] ) );
		$this->assertNull( IATO_MCP_URL_Move::parent_problem( 0, 'page', true, 30, self::page(), true, [ 0 ] ), 'a page being created has no children' );
	}

	public function test_every_unusable_parent_gets_the_same_answer(): void {
		$cases = [
			'missing'       => [ null, true ],
			'not readable'  => [ self::page(), false ],
			'another type'  => [ self::page( 'post' ), true ],
			'in the trash'  => [ self::page( 'page', 'trash' ), true ],
			'an auto-draft' => [ self::page( 'page', 'auto-draft' ), true ],
		];
		$messages = [];
		foreach ( $cases as $name => [ $parent, $readable ] ) {
			$e = IATO_MCP_URL_Move::parent_problem( 5, 'page', true, 30, $parent, $readable, [] );
			$this->assertSame( 'invalid_parent', self::problem( $e ), $name );
			$messages[ $e->get_error_message() ] = true;
		}
		$this->assertCount( 1, $messages, 'the refusal does not say which IDs exist' );
	}

	public function test_a_parent_visitors_cannot_open_is_a_warning(): void {
		$this->assertNull( IATO_MCP_URL_Move::parent_warning( 30, 'publish' ) );
		$this->assertNull( IATO_MCP_URL_Move::parent_warning( 0, 'draft' ) );
		$this->assertStringContainsString( 'draft', (string) IATO_MCP_URL_Move::parent_warning( 30, 'draft' ) );
	}

	public function test_changes_are_the_posts_whose_url_differs(): void {
		$posts   = [
			(object) [ 'ID' => 1, 'post_title' => 'A', 'post_status' => 'publish' ],
			(object) [ 'ID' => 2, 'post_title' => 'B', 'post_status' => 'draft' ],
			(object) [ 'ID' => 3, 'post_title' => 'C', 'post_status' => 'publish' ],
		];
		$changes = IATO_MCP_URL_Move::changes( $posts, [ 1 => '/a/', 2 => '/?page_id=2', 3 => '/a/c/' ], [ 1 => '/s/a/', 2 => '/?page_id=2', 3 => '/s/a/c/' ] );
		$this->assertSame( [ 1, 3 ], array_column( $changes, 'id' ) );
		$this->assertSame( [ 'id' => 1, 'title' => 'A', 'status' => 'publish', 'old_url' => '/a/', 'new_url' => '/s/a/' ], $changes[0] );
	}

	public function test_which_moves_need_confirmation_and_which_get_redirects(): void {
		$draft     = [ [ 'status' => 'draft' ], [ 'status' => 'pending' ] ];
		$private   = [ [ 'status' => 'private' ] ];
		$scheduled = [ [ 'status' => 'future' ] ];
		$published = [ [ 'status' => 'draft' ], [ 'status' => 'publish' ] ];
		$this->assertFalse( IATO_MCP_URL_Move::moves_live_url( $draft ) );
		$this->assertTrue( IATO_MCP_URL_Move::moves_live_url( $private ) );
		$this->assertTrue( IATO_MCP_URL_Move::moves_live_url( $scheduled ) );
		$this->assertFalse( IATO_MCP_URL_Move::moves_published_url( $private ), 'a private page\'s visitors are logged in: no redirect by default' );
		$this->assertTrue( IATO_MCP_URL_Move::moves_published_url( $published ) );
	}

	public function test_the_receipt_holds_parent_order_and_the_slug_when_it_moved_too(): void {
		$post = (object) [ 'post_parent' => '7', 'menu_order' => '-2', 'post_name' => 'ai' ];
		$this->assertSame( [ 'parent' => 7, 'menu_order' => -2 ], IATO_MCP_URL_Move::snapshot( $post, false ) );
		$this->assertSame( [ 'parent' => 7, 'menu_order' => -2, 'slug' => 'ai' ], IATO_MCP_URL_Move::snapshot( $post, true ) );
	}

	public function test_a_receipt_names_its_redirects(): void {
		$after = wp_json_encode( [ 'parent' => 7, 'menu_order' => 0, 'redirects' => [ [ 'handler' => 'fallback', 'from' => '/a/', 'to' => '/s/a/', 'type' => 301 ], 'junk' ] ] );
		$this->assertSame( [ [ 'handler' => 'fallback', 'from' => '/a/', 'to' => '/s/a/', 'type' => 301 ] ], IATO_MCP_URL_Move::receipt_redirects( $after ) );
		$this->assertSame( [], IATO_MCP_URL_Move::receipt_redirects( '{"parent":0,"menu_order":0}' ) );
		$this->assertSame( [], IATO_MCP_URL_Move::receipt_redirects( null ) );
	}

	public function test_undoing_a_move_with_redirects_needs_an_administrator(): void {
		$with    = [ 'target_type' => 'post', 'field' => 'page_attributes', 'post_id' => 0, 'after_value' => '{"parent":7,"menu_order":0,"redirects":[{"handler":"fallback","from":"/a/","to":"/s/a/"}]}' ];
		$without = [ 'target_type' => 'post', 'field' => 'page_attributes', 'post_id' => 0, 'after_value' => '{"parent":7,"menu_order":0,"redirects":[]}' ];
		$this->assertSame( [ [ 'cap' => 'manage_options', 'object_id' => null ] ], IATO_MCP_Change_Receipt::object_cap_for( $with )['also'] );
		$this->assertSame( [], IATO_MCP_Change_Receipt::object_cap_for( $without )['also'] );
		$this->assertSame( 'edit_posts', IATO_MCP_Change_Receipt::object_cap_for( $without )['cap'] );
	}
}
