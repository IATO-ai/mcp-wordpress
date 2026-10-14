<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-readtools.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-media.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-comments.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-menus.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-site.php';

/**
 * Part 4 (1.13.0): read tools follow WordPress permissions, and the IATO
 * bridge tools need manage_options on the MCP surface without changing the
 * abilities path.
 */
final class ReadToolPermissionsTest extends TestCase {

	private const ADMIN = 1, AUTHOR = 7, CONTRIB = 8, EDITOR = 9;
	private const PAGE = 10, DRAFT = 11, PRIVATE_POST = 12, LOCKED = 13, AUTHOR_POST = 20;

	protected function setUp(): void {
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator', self::AUTHOR => 'author', self::CONTRIB => 'contributor', self::EDITOR => 'editor' ];
		IATO_Test_Roles::$posts = [
			self::PAGE         => [ 'post_type' => 'page', 'post_author' => self::ADMIN, 'post_status' => 'publish', 'post_password' => '' ],
			self::DRAFT        => [ 'post_type' => 'page', 'post_author' => self::ADMIN, 'post_status' => 'draft', 'post_password' => '' ],
			self::PRIVATE_POST => [ 'post_type' => 'post', 'post_author' => self::ADMIN, 'post_status' => 'private', 'post_password' => '' ],
			self::LOCKED       => [ 'post_type' => 'page', 'post_author' => self::ADMIN, 'post_status' => 'publish', 'post_password' => 'secret' ],
			self::AUTHOR_POST  => [ 'post_type' => 'post', 'post_author' => self::AUTHOR, 'post_status' => 'publish', 'post_password' => '' ],
		];
		IATO_Test_WP::$posts = IATO_Test_Roles::$posts;
		IATO_Test_Read::reset();
		IATO_Test_Abilities_State::$filters = [];
	}

	protected function tearDown(): void {
		$this->as_user( null );
		IATO_Test_Abilities_State::$filters = [];
	}

	private function as_user( ?int $id, bool $site_key = false ): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, null !== $id );
		$ref->getProperty( 'authenticated_user' )->setValue( null, $id ? new WP_User( $id ) : null );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, $site_key );
	}

	private function call( string $tool, array $args = [] ): array {
		$r = ( IATO_MCP_Server::get_catalog_entry( $tool )['handler'] )( $args );
		if ( is_wp_error( $r ) ) {
			return [ 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ];
		}
		return json_decode( $r['content'][0]['text'], true );
	}

	private static function comment( int $id, int $post, string $approved ): object {
		return (object) [ 'comment_ID' => $id, 'comment_post_ID' => $post, 'comment_approved' => $approved, 'comment_author' => "a$id", 'comment_date_gmt' => '2026-10-01 00:00:00', 'comment_content' => "c$id" ];
	}

	// ── get_media ───────────────────────────────────────────────────────────

	public function test_get_media_needs_upload_files(): void {
		$this->as_user( self::CONTRIB );
		$this->assertSame( 'iato_mcp_forbidden', $this->call( 'get_media' )['error'] ?? null );
		$this->assertStringContainsString( 'upload_files', $this->call( 'get_media' )['message'] );
	}

	// ── get_comments ────────────────────────────────────────────────────────

	public function test_unmoderated_statuses_need_moderate_comments(): void {
		$this->as_user( self::AUTHOR );
		foreach ( [ 'hold', 'spam', 'trash', 'all' ] as $status ) {
			$r = $this->call( 'get_comments', [ 'status' => $status ] );
			$this->assertSame( 'iato_mcp_forbidden', $r['error'] ?? null, $status );
		}
		$this->assertSame( 'invalid_status', $this->call( 'get_comments', [ 'status' => 'anything' ] )['error'] );
		$this->as_user( self::EDITOR );
		IATO_Test_Read::$comments = [ self::comment( 1, self::PAGE, '0' ) ];
		$this->assertSame( [ 1 ], array_column( $this->call( 'get_comments', [ 'status' => 'hold' ] )['comments'], 'id' ), 'an Editor moderates' );
	}

	public function test_comments_on_unreadable_posts_are_dropped_and_the_page_refilled(): void {
		$this->as_user( self::AUTHOR );
		$comments = [];
		foreach ( [ self::DRAFT, self::PRIVATE_POST, self::LOCKED, self::PAGE, self::DRAFT, self::AUTHOR_POST, self::PAGE ] as $i => $post ) {
			$comments[] = self::comment( $i + 1, $post, '1' );
		}
		IATO_Test_Read::$comments = $comments;
		$r = $this->call( 'get_comments', [ 'per_page' => 2 ] );
		$this->assertSame( [ 4, 6 ], array_column( $r['comments'], 'id' ), 'draft, private and password-protected posts skipped; refilled from the next page' );
		$this->assertGreaterThan( 1, count( IATO_Test_Read::$comment_queries ) );
	}

	public function test_post_filter_checks_the_post_first(): void {
		$this->as_user( self::AUTHOR );
		IATO_Test_Read::$comments = [ self::comment( 1, self::LOCKED, '1' ) ];
		$this->assertSame( 'iato_mcp_forbidden', $this->call( 'get_comments', [ 'post_id' => self::LOCKED ] )['error'] ?? null, 'password-protected: edit_post needed' );
		$this->as_user( self::ADMIN );
		$this->assertSame( [ 1 ], array_column( $this->call( 'get_comments', [ 'post_id' => self::LOCKED ] )['comments'], 'id' ) );
		$this->as_user( 0, true );
		$this->assertSame( [ 1 ], array_column( $this->call( 'get_comments', [ 'post_id' => self::LOCKED ] )['comments'], 'id' ), 'the unbound site key reads everything, as before' );
	}

	// ── get_menu_items ──────────────────────────────────────────────────────

	public function test_menu_items_pointing_at_unreadable_posts_are_left_out(): void {
		$item = fn( int $id, string $type, int $object ) => (object) [ 'ID' => $id, 'type' => $type, 'object_id' => $object, 'title' => "t$id", 'url' => "u$id", 'post_name' => "s$id", 'menu_item_parent' => 0, 'menu_order' => $id ];
		IATO_Test_Read::$menu_items[5] = [ $item( 1, 'post_type', self::PAGE ), $item( 2, 'post_type', self::DRAFT ), $item( 3, 'custom', 0 ), $item( 4, 'post_type', self::PRIVATE_POST ), $item( 5, 'taxonomy', 3 ) ];
		$this->as_user( self::AUTHOR );
		$r = $this->call( 'get_menu_items', [ 'menu_id' => 5 ] );
		$this->assertSame( [ 1, 3, 5 ], array_column( $r['items'], 'id' ) );
		$this->assertSame( 2, $r['omitted_items'] );
		$this->as_user( self::EDITOR );
		$r = $this->call( 'get_menu_items', [ 'menu_id' => 5 ] );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_column( $r['items'], 'id' ), 'an Editor reads drafts and private posts' );
		$this->assertArrayNotHasKey( 'omitted_items', $r );
	}

	// ── get_site_info ───────────────────────────────────────────────────────

	public function test_site_info_admin_fields(): void {
		$this->as_user( self::EDITOR );
		$r = $this->call( 'get_site_info' );
		$this->assertSame( [ 'Test Site', '7.1.3', 'Hello Elementor' ], [ $r['name'], $r['wp_version'], $r['active_theme'] ] );
		$this->assertArrayNotHasKey( 'plugin_count', $r );
		$this->assertSame( [ 'plugin_count', 'theme_builder_template_count' ], $r['restricted_fields'] );
		$this->as_user( self::ADMIN );
		$r = $this->call( 'get_site_info' );
		$this->assertArrayHasKey( 'plugin_count', $r );
		$this->assertSame( 0, $r['theme_builder_template_count'] );
		$this->assertArrayNotHasKey( 'restricted_fields', $r );
	}

	// ── Bridge tools: MCP dispatch gate, abilities path untouched ──────────

	private function dispatch( string $tool ): array {
		$m = new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' );
		return $m->invoke( null, [ 'name' => $tool, 'arguments' => [] ] );
	}

	private function with_fake_tool( string $name, callable $fn ): void {
		$ran   = false;
		$entry = [ 'definition' => [], 'handler' => function () use ( &$ran ) { $ran = true; return IATO_MCP_Server::ok( [ 'ran' => true ] ); } ];
		$ref   = new ReflectionClass( IATO_MCP_Server::class );
		$tools = $ref->getProperty( 'tools' );
		$cat   = $ref->getProperty( 'catalog' );
		$saved = [ $tools->getValue(), $cat->getValue() ];
		$tools->setValue( null, [ $name => $entry ] + $saved[0] );
		$cat->setValue( null, [ $name => $entry ] + $saved[1] );
		try {
			$fn( function () use ( &$ran ) { $r = $ran; $ran = false; return $r; } );
		} finally {
			$tools->setValue( null, $saved[0] );
			$cat->setValue( null, $saved[1] );
		}
	}

	public function test_bridge_tools_need_manage_options_over_mcp(): void {
		$this->with_fake_tool( 'get_iato_sitemap', function ( callable $ran ) {
			$this->as_user( self::EDITOR );
			$r = $this->dispatch( 'get_iato_sitemap' );
			$this->assertTrue( $r['isError'] );
			$this->assertStringContainsString( 'manage_options', $r['content'][0]['text'] );
			$this->assertFalse( $ran(), 'the handler never ran' );

			$this->as_user( self::ADMIN );
			$this->assertArrayNotHasKey( 'isError', $this->dispatch( 'get_iato_sitemap' ) );
			$this->assertTrue( $ran() );

			$this->as_user( 0, true );
			$this->dispatch( 'get_iato_sitemap' );
			$this->assertTrue( $ran(), 'the unbound site key keeps full access' );

			// One filter governs both surfaces.
			add_filter( IATO_MCP_Abilities::CAPABILITY_FILTER, fn( $cap, $ability, $tool ) => 'get_iato_sitemap' === $tool ? 'edit_posts' : $cap );
			$this->as_user( self::EDITOR );
			$this->assertArrayNotHasKey( 'isError', $this->dispatch( 'get_iato_sitemap' ) );
			$this->assertTrue( $ran() );
		} );
	}

	public function test_the_abilities_path_and_other_tools_are_unaffected(): void {
		$this->with_fake_tool( 'get_iato_sitemap', function ( callable $ran ) {
			$this->as_user( null ); // an ability runs outside MCP authentication
			$this->assertSame( [ 'ran' => true ], IATO_MCP_Server::invoke_tool( 'get_iato_sitemap', [] ) );
			$this->assertTrue( $ran() );
		} );
		$this->with_fake_tool( 'some_other_tool', function ( callable $ran ) {
			$this->as_user( self::CONTRIB );
			$this->dispatch( 'some_other_tool' );
			$this->assertTrue( $ran(), 'only bridge tools are gated here' );
		} );
		$this->assertNull( IATO_MCP_Abilities::capability_for_tool( 'start_iato_crawl' ), 'start_iato_crawl checks manage_options itself' );
		$this->assertSame( 'manage_options', IATO_MCP_Abilities::capability_for_tool( 'list_iato_crawls' ) );
	}
}
