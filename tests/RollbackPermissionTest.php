<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';

/**
 * Object-level rollback permissions (fix/rollback-route-cap), shared by the
 * REST route and the MCP tool through IATO_MCP_Rollback::check_permission().
 */
final class RollbackPermissionTest extends TestCase {

	private const ADMIN = 1;
	private const AUTHOR = 7;
	private const CONTRIB = 8;
	private const EDITOR = 9;

	protected function setUp(): void {
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator', self::AUTHOR => 'author', self::CONTRIB => 'contributor', self::EDITOR => 'editor' ];
		IATO_Test_Roles::$posts = [
			10 => [ 'post_type' => 'page',       'post_author' => self::ADMIN,   'post_status' => 'publish' ], // someone else's published page
			20 => [ 'post_type' => 'post',       'post_author' => self::AUTHOR,  'post_status' => 'publish' ], // the author's own published post
			21 => [ 'post_type' => 'post',       'post_author' => self::CONTRIB, 'post_status' => 'draft' ],   // the contributor's own draft
			22 => [ 'post_type' => 'post',       'post_author' => self::AUTHOR,  'post_status' => 'draft' ],   // the author's own draft
			5  => [ 'post_type' => 'attachment', 'post_author' => self::ADMIN,   'post_status' => 'inherit' ],
		];
		IATO_Test_Roles::$terms = [ 3 => 'category' ];
		$this->auth_as( null );
	}

	/** null = unauthenticated; 0 = Bearer plugin key (no user); >0 = Application Password user */
	private function auth_as( ?int $user_id ): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, null !== $user_id );
		$ref->getProperty( 'authenticated_user' )->setValue( null, $user_id ? new WP_User( $user_id ) : null );
		// The site-key path is an explicit flag set by authenticate(), not "no user".
		$ref->getProperty( 'site_key_full_access' )->setValue( null, 0 === $user_id );
	}

	private static function r( string $type, string $field, ?int $post_id, mixed $before = null, mixed $after = null ): array {
		return [ 'change_id' => 'wr_0000000000000000', 'target_type' => $type, 'field' => $field, 'post_id' => $post_id, 'before_value' => $before, 'after_value' => $after ];
	}

	/** Every receipt shape used below. */
	private static function all_receipts(): array {
		return [
			'post title on page 10'        => self::r( 'post', 'title', 10, 'a', 'b' ),
			'page seo on page 10'          => self::r( 'page', 'title', 10, 'a', 'b' ),
			'post_meta on page 10'         => self::r( 'post_meta', '_thumbnail_id', 10, '1', '2' ),
			'elementor widget on page 10'  => self::r( 'elementor_widget', 'settings', 10, '{}', '{}' ),
			'image alt on attachment 5'    => self::r( 'image', 'alt_text', 5, 'old', 'new' ),
			'attachment create 5'          => self::r( 'attachment', 'create', 5, null, '{"id":5}' ),
			'taxonomy assign on page 10'   => self::r( 'taxonomy', 'assign', 10, '[1]', '[2]' ),
			'taxonomy create_term 3'       => self::r( 'taxonomy', 'create_term', null, null, '{"term_id":3,"taxonomy":"category"}' ),
			'taxonomy update_term 3'       => self::r( 'taxonomy', 'update_term', null, '{"term_id":3,"taxonomy":"category","name":"x"}', '{}' ),
			'taxonomy delete_term'         => self::r( 'taxonomy', 'delete_term', null, '{"taxonomy":"category","name":"gone"}', null ),
			'menu_item'                    => self::r( 'menu_item', 'update', 55, '{}', '{}' ),
			'redirect'                     => self::r( 'redirect', 'rule', null, '{}', '{}' ),
			'unknown type'                 => self::r( 'something_new', 'x', 10, 'a', 'b' ),
			'post on deleted post 999'     => self::r( 'post', 'title', 999, 'a', 'b' ),
			'attachment create on deleted' => self::r( 'attachment', 'create', 998, null, '{}' ),
			'create_term on deleted term'  => self::r( 'taxonomy', 'create_term', null, null, '{"term_id":404,"taxonomy":"category"}' ),
			'post create on draft 21'      => self::r( 'post', 'create', 21, null, '{"post_type":"post"}' ),
			'post create on deleted'       => self::r( 'post', 'create', 997, null, '{"post_type":"post"}' ),
			'status restore publish on 21' => self::r( 'post', 'status', 21, 'publish', 'draft' ),
			'status restore private on 21' => self::r( 'post', 'status', 21, 'private', 'draft' ),
			'status restore draft on 20'   => self::r( 'post', 'status', 20, 'draft', 'publish' ),
		];
	}

	// ── The resolver itself ───────────────────────────────────────────────

	public function test_object_cap_mapping(): void {
		$expect = [
			'post title on page 10'        => [ 'edit_post', 10, 'object' ],
			'page seo on page 10'          => [ 'edit_post', 10, 'object' ],
			'post_meta on page 10'         => [ 'edit_post', 10, 'object' ],
			'elementor widget on page 10'  => [ 'edit_post', 10, 'object' ],
			'image alt on attachment 5'    => [ 'edit_post', 5, 'object' ],
			'attachment create 5'          => [ 'delete_post', 5, 'object' ],
			'taxonomy assign on page 10'   => [ 'edit_post', 10, 'object' ],
			'taxonomy create_term 3'       => [ 'delete_term', 3, 'object' ],
			'taxonomy update_term 3'       => [ 'edit_term', 3, 'object' ],
			'taxonomy delete_term'         => [ 'manage_categories', null, 'type-by-design' ],
			'menu_item'                    => [ 'edit_theme_options', null, 'type-by-design' ],
			'redirect'                     => [ 'manage_options', null, 'type-by-design' ],
			'unknown type'                 => [ 'manage_options', null, 'type-by-design' ],
			'post on deleted post 999'     => [ 'edit_posts', null, 'type' ],
			'attachment create on deleted' => [ 'edit_posts', null, 'type' ],
			'create_term on deleted term'  => [ 'manage_categories', null, 'type' ],
			'post create on draft 21'      => [ 'delete_post', 21, 'object' ],
			'post create on deleted'       => [ 'edit_posts', null, 'type' ],
			'status restore publish on 21' => [ 'edit_post', 21, 'object' ],
			'status restore private on 21' => [ 'edit_post', 21, 'object' ],
			'status restore draft on 20'   => [ 'edit_post', 20, 'object' ],
		];
		foreach ( self::all_receipts() as $name => $receipt ) {
			$got = IATO_MCP_Change_Receipt::object_cap_for( $receipt );
			$this->assertSame( $expect[ $name ], [ $got['cap'], $got['object_id'], $got['basis'] ], $name );
		}
	}

	public function test_restoring_a_live_status_also_requires_the_publish_capability(): void {
		foreach ( [ 'status restore publish on 21', 'status restore private on 21' ] as $name ) {
			$got = IATO_MCP_Change_Receipt::object_cap_for( self::all_receipts()[ $name ] );
			$this->assertSame( [ [ 'cap' => 'publish_posts', 'object_id' => null ] ], $got['also'], $name );
		}
		// Restoring to draft, and every other receipt, adds nothing.
		foreach ( self::all_receipts() as $name => $receipt ) {
			if ( str_starts_with( $name, 'status restore p' ) ) {
				continue;
			}
			$this->assertSame( [], IATO_MCP_Change_Receipt::object_cap_for( $receipt )['also'], $name );
		}
	}

	public function test_contributor_cannot_republish_their_own_draft_through_a_status_rollback(): void {
		$this->auth_as( self::CONTRIB );
		foreach ( [ 'status restore publish on 21', 'status restore private on 21' ] as $name ) {
			$err = IATO_MCP_Rollback::check_permission( self::all_receipts()[ $name ] );
			$this->assertInstanceOf( WP_Error::class, $err, $name );
			$this->assertStringContainsString( 'publish_posts', $err->get_error_message(), $name );
		}
		// Un-publishing (restore to draft) is an ordinary edit, which they may do on their own post only.
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::r( 'post', 'status', 21, 'draft', 'pending' ) ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Rollback::check_permission( self::all_receipts()['status restore draft on 20'] ) );
	}

	public function test_author_can_republish_their_own_draft_through_a_status_rollback(): void {
		$this->auth_as( self::AUTHOR );
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::r( 'post', 'status', 22, 'publish', 'draft' ) ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Rollback::check_permission( self::all_receipts()['status restore publish on 21'] ), "another user's draft" );
	}

	public function test_create_rollback_requires_delete_post_on_the_created_post(): void {
		$this->auth_as( self::CONTRIB );
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::all_receipts()['post create on draft 21'] ), 'own draft' );
		$err = IATO_MCP_Rollback::check_permission( self::r( 'post', 'create', 10, null, '{}' ) );
		$this->assertInstanceOf( WP_Error::class, $err, "someone else's page" );
		$this->assertStringContainsString( 'delete_post', $err->get_error_message() );
		$this->auth_as( self::AUTHOR );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Rollback::check_permission( self::r( 'post', 'create', 10, null, '{}' ) ) );
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::r( 'post', 'create', 20, null, '{}' ) ), 'own published post' );
	}

	// ── Bearer plugin key keeps full access ───────────────────────────────

	public function test_bearer_key_without_a_user_can_roll_back_everything(): void {
		$this->auth_as( 0 );
		foreach ( self::all_receipts() as $name => $receipt ) {
			$this->assertTrue( IATO_MCP_Rollback::check_permission( $receipt ), $name );
		}
	}

	public function test_unauthenticated_is_refused_on_everything(): void {
		foreach ( self::all_receipts() as $name => $receipt ) {
			$err = IATO_MCP_Rollback::check_permission( $receipt );
			$this->assertInstanceOf( WP_Error::class, $err, $name );
			$this->assertSame( 'iato_mcp_forbidden', $err->get_error_code() );
		}
	}

	// ── Contributor ───────────────────────────────────────────────────────

	public function test_contributor_is_refused_on_another_users_published_page(): void {
		$this->auth_as( self::CONTRIB );
		foreach ( [ 'post title on page 10', 'page seo on page 10', 'post_meta on page 10', 'elementor widget on page 10' ] as $name ) {
			$err = IATO_MCP_Rollback::check_permission( self::all_receipts()[ $name ] );
			$this->assertInstanceOf( WP_Error::class, $err, $name );
			$this->assertStringContainsString( 'edit_post', $err->get_error_message() );
		}
	}

	public function test_contributor_is_refused_on_an_attachment_delete(): void {
		$this->auth_as( self::CONTRIB );
		$err = IATO_MCP_Rollback::check_permission( self::all_receipts()['attachment create 5'] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertStringContainsString( 'delete_post', $err->get_error_message() );
	}

	public function test_contributor_is_refused_on_a_taxonomy_change_to_a_post_they_cannot_edit(): void {
		$this->auth_as( self::CONTRIB );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Rollback::check_permission( self::all_receipts()['taxonomy assign on page 10'] ) );
		// Term CRUD stays at manage_categories, which a Contributor lacks either way.
		foreach ( [ 'taxonomy create_term 3', 'taxonomy update_term 3', 'taxonomy delete_term', 'create_term on deleted term' ] as $name ) {
			$this->assertInstanceOf( WP_Error::class, IATO_MCP_Rollback::check_permission( self::all_receipts()[ $name ] ), $name );
		}
	}

	public function test_contributor_can_roll_back_their_own_draft(): void {
		$this->auth_as( self::CONTRIB );
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::r( 'post', 'title', 21, 'a', 'b' ) ) );
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::r( 'taxonomy', 'assign', 21, '[1]', '[2]' ) ) );
	}

	// ── Author ────────────────────────────────────────────────────────────

	public function test_author_succeeds_on_their_own_post_and_not_on_others(): void {
		$this->auth_as( self::AUTHOR );
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::r( 'post', 'content', 20, 'a', 'b' ) ) );
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::r( 'post_meta', 'k', 20, 'a', 'b' ) ) );
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::r( 'taxonomy', 'terms', 20, '[1]', '[2]' ) ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Rollback::check_permission( self::all_receipts()['post title on page 10'] ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Rollback::check_permission( self::all_receipts()['attachment create 5'] ), "someone else's upload" );
	}

	// ── Editor: object-level where it exists, type-level where it does not ─

	public function test_editor_passes_object_and_type_level_checks_but_not_site_wide_ones(): void {
		$this->auth_as( self::EDITOR );
		foreach ( [ 'post title on page 10', 'attachment create 5', 'taxonomy assign on page 10', 'taxonomy create_term 3', 'taxonomy update_term 3', 'taxonomy delete_term', 'post on deleted post 999', 'attachment create on deleted', 'create_term on deleted term', 'post create on draft 21', 'post create on deleted', 'status restore publish on 21', 'status restore draft on 20' ] as $name ) {
			$this->assertTrue( IATO_MCP_Rollback::check_permission( self::all_receipts()[ $name ] ), $name );
		}
		foreach ( [ 'menu_item', 'redirect', 'unknown type' ] as $name ) {
			$this->assertInstanceOf( WP_Error::class, IATO_MCP_Rollback::check_permission( self::all_receipts()[ $name ] ), $name );
		}
	}

	// ── Missing objects fall back to the type-level capability ───────────

	public function test_missing_object_falls_back_to_type_level(): void {
		$this->auth_as( self::CONTRIB );
		// edit_posts is enough once the post is gone (nothing object-specific is left to check).
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::all_receipts()['post on deleted post 999'] ) );
		$this->assertTrue( IATO_MCP_Rollback::check_permission( self::all_receipts()['attachment create on deleted'] ) );
		// Term receipts fall back to manage_categories, which a Contributor lacks.
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Rollback::check_permission( self::all_receipts()['create_term on deleted term'] ) );
	}

	// ── Administrator ─────────────────────────────────────────────────────

	public function test_administrator_succeeds_on_all(): void {
		$this->auth_as( self::ADMIN );
		foreach ( self::all_receipts() as $name => $receipt ) {
			$this->assertTrue( IATO_MCP_Rollback::check_permission( $receipt ), $name );
		}
	}
}
