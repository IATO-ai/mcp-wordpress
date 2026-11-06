<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-actor.php';
require_once __DIR__ . '/stubs-readtools.php';
require_once __DIR__ . '/stubs-menus.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-taxonomy.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-menus.php';

/**
 * create_menu, assign_menu_location and get_menus' locations (1.17.0): who may, what
 * is refused before anything is written, dry runs, receipts, and rollback with its
 * two safeguards: a menu's items are deleted with it, and locations belong to a theme.
 */
final class MenuToolsTest extends TestCase {

	private const ADMIN = 1, EDITOR = 9;
	private const MAIN = 40, OTHER = 41;

	protected function setUp(): void {
		IATO_Test_Menus::reset();
		IATO_Test_Cache_State::reset();
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator', self::EDITOR => 'editor' ];
		$GLOBALS['wpdb']->inserts = [];
		$this->as_user( self::ADMIN );
		IATO_MCP_Cache::begin();
	}

	protected function tearDown(): void {
		$this->as_user( null );
		IATO_MCP_Cache::begin();
	}

	private function as_user( ?int $id ): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, null !== $id );
		$ref->getProperty( 'authenticated_user' )->setValue( null, $id ? new WP_User( $id ) : null );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, false );
		$ref->getProperty( 'credential' )->setValue( null, null === $id ? [] : [ 'kind' => 'application_password', 'user_id' => $id ] );
	}

	/** The handler's answer, with a WP_Error or an isError result as error and message. */
	private function call( string $tool, array $args = [] ): array {
		$r = ( IATO_MCP_Server::get_catalog_entry( $tool )['handler'] )( $args );
		if ( is_wp_error( $r ) ) {
			return [ 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ];
		}
		return json_decode( $r['content'][0]['text'], true ) + ( ! empty( $r['isError'] ) ? [ 'isError' => true ] : [] );
	}

	/** The receipt row, as the rollback code gets it back from the table. */
	private static function row( array $receipt, string $applied_at = '2026-11-03 10:00:00' ): array {
		return [
			'change_id'    => $receipt['change_id'],
			'post_id'      => null,
			'target_type'  => $receipt['target_type'],
			'field'        => $receipt['field'],
			'before_value' => $receipt['before_value'],
			'after_value'  => $receipt['after_value'],
			'applied_at'   => $applied_at,
		];
	}

	private static function location( string $slug ): ?int {
		$id = (int) ( IATO_Test_Menus::locations()[ $slug ] ?? 0 );
		return $id > 0 ? $id : null;
	}

	private static function put( string $slug, int $menu_id ): void {
		IATO_Test_Menus::$mods[ IATO_Test_Menus::$stylesheet ]['nav_menu_locations'][ $slug ] = $menu_id;
	}

	// ── get_menus ─────────────────────────────────────────────────────────

	public function test_get_menus_adds_locations_and_keeps_what_it_returned(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		IATO_Test_Menus::$menus[ self::MAIN ]->count = 3;
		self::put( 'menu-1', self::MAIN );
		$r = $this->call( 'get_menus' );
		$this->assertSame( [ [ 'id' => self::MAIN, 'name' => 'Main', 'slug' => 'main', 'item_count' => 3, 'location' => 'menu-1' ] ], $r['menus'] );
		$this->assertSame(
			[
				[ 'slug' => 'menu-1', 'label' => 'Header', 'menu_id' => self::MAIN ],
				[ 'slug' => 'menu-2', 'label' => 'Footer', 'menu_id' => null ],
			],
			$r['locations']
		);
	}

	public function test_get_menus_on_a_theme_with_no_locations(): void {
		IATO_Test_Menus::$registered = [];
		$this->assertSame( [], $this->call( 'get_menus' )['locations'] );
	}

	public function test_a_location_the_theme_no_longer_registers_is_not_listed(): void {
		self::put( 'old-slot', 7 );
		$this->assertSame( [ 'menu-1', 'menu-2' ], array_column( $this->call( 'get_menus' )['locations'], 'slug' ) );
	}

	// ── create_menu ───────────────────────────────────────────────────────

	public function test_create_menu_needs_edit_theme_options(): void {
		$this->as_user( self::EDITOR );
		$this->assertSame( 'iato_mcp_forbidden', $this->call( 'create_menu', [ 'name' => 'Main' ] )['error'] );
		$this->assertSame( [], IATO_Test_Menus::$writes );
	}

	/** @return array<string,array{0:array,1:string}> */
	public static function bad_create_args(): array {
		return [
			'no name'          => [ [], 'missing_name' ],
			'empty name'       => [ [ 'name' => '   ' ], 'missing_name' ],
			'name not a string' => [ [ 'name' => [ 'Main' ] ], 'missing_name' ],
			'unknown location' => [ [ 'name' => 'Main', 'location' => 'sidebar' ], 'unknown_menu_location' ],
			'location not a string' => [ [ 'name' => 'Main', 'location' => [ 'menu-1' ] ], 'unknown_menu_location' ],
		];
	}

	#[DataProvider( 'bad_create_args' )]
	public function test_create_menu_refuses_before_writing( array $args, string $code ): void {
		foreach ( [ false, true ] as $dry_run ) {
			$r = $this->call( 'create_menu', $args + ( $dry_run ? [ 'dry_run' => true ] : [] ) );
			$this->assertTrue( $r['isError'] ?? false );
			$this->assertSame( $code, $r['error'] );
		}
		$this->assertSame( [], IATO_Test_Menus::$writes );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
	}

	public function test_an_unknown_location_lists_the_ones_there_are(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main', 'location' => 'sidebar' ] );
		$this->assertSame( [ [ 'slug' => 'menu-1', 'label' => 'Header' ], [ 'slug' => 'menu-2', 'label' => 'Footer' ] ], $r['locations'] );
		$this->assertStringContainsString( 'menu-1', $r['message'] );
	}

	public function test_a_theme_with_no_locations_says_so(): void {
		IATO_Test_Menus::$registered = [];
		$r = $this->call( 'create_menu', [ 'name' => 'Main', 'location' => 'menu-1' ] );
		$this->assertSame( 'unknown_menu_location', $r['error'] );
		$this->assertStringContainsString( 'registers no menu locations', $r['message'] );
	}

	public function test_a_name_in_use_is_refused_in_a_dry_run_too(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		foreach ( [ false, true ] as $dry_run ) {
			$r = $this->call( 'create_menu', [ 'name' => 'Main' ] + ( $dry_run ? [ 'dry_run' => true ] : [] ) );
			$this->assertSame( 'menu_exists', $r['error'] );
			$this->assertSame( self::MAIN, $r['menu_id'] );
		}
		$this->assertSame( [], IATO_Test_Menus::$writes );
	}

	public function test_what_wordpress_refuses_on_save_comes_back_with_its_code(): void {
		IATO_Test_Menus::$create_error = new WP_Error( 'term_exists', 'Stored already.' );
		$r = $this->call( 'create_menu', [ 'name' => 'Main' ] );
		$this->assertSame( 'term_exists', $r['error'] );
		$this->assertSame( 'Stored already.', $r['message'] );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
	}

	public function test_a_dry_run_writes_nothing_and_says_what_would_happen(): void {
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', self::OTHER );
		IATO_Test_Menus::$writes = [];
		$r = $this->call( 'create_menu', [ 'name' => 'A & B', 'location' => 'menu-1', 'dry_run' => true ] );
		$this->assertTrue( $r['dry_run'] );
		$this->assertSame( 'would_create', $r['action'] );
		$this->assertSame( 'A &amp; B', $r['name'] );
		$this->assertSame( 'a-b', $r['slug'] );
		$this->assertSame( 'menu-1', $r['location'] );
		$this->assertSame( self::OTHER, $r['would_replace_menu_id'] );
		$this->assertNotEmpty( $r['not_checked'] );
		$this->assertSame( [], IATO_Test_Menus::$writes );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
		$this->assertFalse( IATO_MCP_Cache::has_pending(), 'a dry run does not purge' );
	}

	public function test_create_menu_with_no_location(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main' ] );
		$this->assertSame( 500, $r['menu_id'] );
		$this->assertSame( 'Main', $r['name'] );
		$this->assertSame( 'main', $r['slug'] );
		$this->assertNull( $r['location'] );
		$this->assertArrayNotHasKey( 'replaced_menu_id', $r );
		$this->assertSame( 'menu', $r['change_receipt']['target_type'] );
		$this->assertSame( 'create', $r['change_receipt']['field'] );
		$this->assertNull( $r['change_receipt']['before_value'] );
		$after = json_decode( $r['change_receipt']['after_value'], true );
		$this->assertSame( [ 'menu_id' => 500, 'name' => 'Main', 'slug' => 'main', 'location' => null, 'theme' => 'hello-elementor' ], $after );
		$this->assertSame( [ 'create:500' ], IATO_Test_Menus::$writes, 'no location, no theme mod' );
	}

	public function test_create_menu_returns_what_was_stored(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'A & B' ] );
		$this->assertSame( 'A &amp; B', $r['name'] );
		$this->assertSame( 'A &amp; B', json_decode( $r['change_receipt']['after_value'], true )['name'] );
	}

	public function test_a_backslash_in_a_name_survives(): void {
		// wp_insert_term() unslashes the name, so an unslashed one loses its backslashes.
		$name = 'C:' . chr( 92 ) . 'Users';
		$r    = $this->call( 'create_menu', [ 'name' => $name ] );
		$this->assertSame( $name, $r['name'] );
		$this->assertSame( $name, IATO_Test_Menus::$menus[ $r['menu_id'] ]->name );
	}

	public function test_create_menu_with_a_location_takes_it_and_says_what_it_replaced(): void {
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', self::OTHER );
		$r = $this->call( 'create_menu', [ 'name' => 'Main', 'location' => 'menu-1' ] );
		$this->assertSame( 'menu-1', $r['location'] );
		$this->assertSame( self::OTHER, $r['replaced_menu_id'] );
		$this->assertSame( 500, self::location( 'menu-1' ) );
		$this->assertSame( [ 'location' => 'menu-1', 'menu_id' => self::OTHER, 'theme' => 'hello-elementor' ], json_decode( $r['change_receipt']['before_value'], true ) );
	}

	public function test_a_new_menu_purges_the_whole_site(): void {
		$this->call( 'create_menu', [ 'name' => 'Main' ] );
		$cache = ( new ReflectionProperty( IATO_MCP_Cache::class, 'pending' ) )->getValue();
		$this->assertTrue( $cache['all'] );
	}

	// ── assign_menu_location ──────────────────────────────────────────────

	public function test_assign_needs_edit_theme_options(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		$this->as_user( self::EDITOR );
		$this->assertSame( 'iato_mcp_forbidden', $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] )['error'] );
		$this->assertNull( self::location( 'menu-1' ) );
	}

	/** @return array<string,array{0:array,1:string}> */
	public static function bad_assign_args(): array {
		return [
			'no location'        => [ [ 'menu_id' => 40 ], 'unknown_menu_location' ],
			'unknown location'   => [ [ 'location' => 'sidebar', 'menu_id' => 40 ], 'unknown_menu_location' ],
			'no menu_id'         => [ [ 'location' => 'menu-1' ], 'invalid_menu_id' ],
			'menu_id a word'     => [ [ 'location' => 'menu-1', 'menu_id' => 'main' ], 'invalid_menu_id' ],
			'menu_id negative'   => [ [ 'location' => 'menu-1', 'menu_id' => -40 ], 'invalid_menu_id' ],
			'menu_id a fraction' => [ [ 'location' => 'menu-1', 'menu_id' => 40.5 ], 'invalid_menu_id' ],
			'menu_id null'       => [ [ 'location' => 'menu-1', 'menu_id' => null ], 'invalid_menu_id' ],
			'no such menu'       => [ [ 'location' => 'menu-1', 'menu_id' => 99 ], 'not_found' ],
		];
	}

	#[DataProvider( 'bad_assign_args' )]
	public function test_assign_refuses_before_writing( array $args, string $code ): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		foreach ( [ false, true ] as $dry_run ) {
			$r = $this->call( 'assign_menu_location', $args + ( $dry_run ? [ 'dry_run' => true ] : [] ) );
			$this->assertTrue( $r['isError'] ?? false );
			$this->assertSame( $code, $r['error'] );
		}
		$this->assertSame( [], IATO_Test_Menus::$writes );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
	}

	public function test_a_menu_named_like_the_id_asked_for_is_not_that_menu(): void {
		// wp_get_nav_menu_object( 99 ) answers with the menu called "99" when no menu has that ID.
		IATO_Test_Menus::add_menu( self::MAIN, '99' );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => 99 ] );
		$this->assertSame( 'not_found', $r['error'] );
		$this->assertNull( self::location( 'menu-1' ) );
	}

	public function test_assign_moves_a_menu_into_a_location(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', self::OTHER );
		self::put( 'menu-2', self::OTHER );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] );
		$this->assertSame( 'menu-1', $r['location'] );
		$this->assertSame( [ 'menu_id' => self::OTHER ], $r['before'] );
		$this->assertSame( [ 'menu_id' => self::MAIN ], $r['after'] );
		$this->assertSame( self::MAIN, self::location( 'menu-1' ) );
		$this->assertSame( self::OTHER, self::location( 'menu-2' ), 'other locations stay as they were' );
		$this->assertSame( 'menu_location', $r['change_receipt']['target_type'] );
		$this->assertSame( 'menu-1', $r['change_receipt']['field'] );
		$this->assertSame( [ 'location' => 'menu-1', 'menu_id' => self::OTHER, 'theme' => 'hello-elementor' ], json_decode( $r['change_receipt']['before_value'], true ) );
		$this->assertSame( [ 'location' => 'menu-1', 'menu_id' => self::MAIN, 'theme' => 'hello-elementor' ], json_decode( $r['change_receipt']['after_value'], true ) );
	}

	public function test_zero_clears_a_location(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		self::put( 'menu-1', self::MAIN );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => 0 ] );
		$this->assertSame( [ 'menu_id' => self::MAIN ], $r['before'] );
		$this->assertSame( [ 'menu_id' => null ], $r['after'] );
		$this->assertNull( self::location( 'menu-1' ) );
	}

	public function test_a_numeric_string_is_a_menu_id(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => (string) self::MAIN ] );
		$this->assertSame( [ 'menu_id' => self::MAIN ], $r['after'] );
	}

	/** @return array<string,array{0:int,1:int,2:string}> */
	public static function no_changes(): array {
		return [ 'the same menu' => [ 40, 40, 'no_change' ], 'clearing what is clear' => [ 0, 0, 'no_change' ] ];
	}

	#[DataProvider( 'no_changes' )]
	public function test_no_change_writes_nothing_and_has_no_receipt( int $held, int $asked, string $action ): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		self::put( 'menu-1', $held );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => $asked ] );
		$this->assertSame( $action, $r['action'] );
		$this->assertArrayNotHasKey( 'change_receipt', $r );
		$this->assertSame( [], IATO_Test_Menus::$writes );
		$this->assertFalse( IATO_MCP_Cache::has_pending() );
	}

	/** @return array<string,array{0:int,1:int,2:string,3:?int}> */
	public static function assign_previews(): array {
		return [
			'assign'    => [ 0, 40, 'would_assign', 40 ],
			'replace'   => [ 41, 40, 'would_assign', 40 ],
			'clear'     => [ 40, 0, 'would_clear', null ],
			'no change' => [ 40, 40, 'no_change', 40 ],
		];
	}

	#[DataProvider( 'assign_previews' )]
	public function test_assign_dry_run( int $held, int $asked, string $action, ?int $after ): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', $held );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => $asked, 'dry_run' => true ] );
		$this->assertTrue( $r['dry_run'] );
		$this->assertSame( $action, $r['action'] );
		$this->assertSame( [ 'menu_id' => $held > 0 ? $held : null ], $r['before'] );
		$this->assertSame( [ 'menu_id' => $after ], $r['after'] );
		$this->assertSame( $held, (int) ( IATO_Test_Menus::locations()['menu-1'] ?? 0 ) );
		$this->assertSame( [], IATO_Test_Menus::$writes );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
		$this->assertFalse( IATO_MCP_Cache::has_pending() );
	}

	// ── Rollback of create_menu ───────────────────────────────────────────

	public function test_rollback_deletes_an_empty_menu(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main' ] );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( 'match', $out['conflict_check']['status'] );
		$this->assertArrayNotHasKey( 500, IATO_Test_Menus::$menus );
	}

	public function test_rollback_puts_the_previous_menu_back_in_the_location(): void {
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', self::OTHER );
		$r = $this->call( 'create_menu', [ 'name' => 'Main', 'location' => 'menu-1' ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertArrayNotHasKey( 500, IATO_Test_Menus::$menus );
		$this->assertSame( self::OTHER, self::location( 'menu-1' ) );
	}

	public function test_rollback_leaves_an_empty_location_empty(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main', 'location' => 'menu-1' ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertNull( self::location( 'menu-1' ) );
	}

	public function test_a_location_given_to_another_menu_since_is_left_alone(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', self::OTHER );
		$r = $this->call( 'create_menu', [ 'name' => 'New', 'location' => 'menu-1' ] );
		self::put( 'menu-1', self::MAIN );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( self::MAIN, self::location( 'menu-1' ) );
	}

	public function test_the_previous_menu_having_gone_leaves_the_location_empty(): void {
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', self::OTHER );
		$r = $this->call( 'create_menu', [ 'name' => 'Main', 'location' => 'menu-1' ] );
		unset( IATO_Test_Menus::$menus[ self::OTHER ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertNull( self::location( 'menu-1' ) );
	}

	public function test_a_menu_with_items_is_not_deleted_without_force(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main' ] );
		IATO_Test_Menus::$items[500] = [ 70, 71 ];
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertStringContainsString( '2 items', $out->get_error_message() );
		$this->assertStringContainsString( 'permanently', $out->get_error_message() );
		$this->assertArrayHasKey( 500, IATO_Test_Menus::$menus );
		$this->assertSame( [ 'item_count' => 2 ], $out->get_error_data()['conflict_check']['current_value'] );
	}

	public function test_the_dry_run_of_that_says_so_and_deletes_nothing(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main' ] );
		IATO_Test_Menus::$items[500] = [ 70 ];
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'dry_run' => true ] );
		$this->assertFalse( $out['would_roll_back'] );
		$this->assertSame( 'conflict', $out['conflict_check']['status'] );
		$this->assertArrayHasKey( 500, IATO_Test_Menus::$menus );
	}

	public function test_force_deletes_the_menu_and_its_items(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main' ] );
		IATO_Test_Menus::$items[500] = [ 70, 71 ];
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'force' => true ] );
		$this->assertTrue( $out['success'] );
		$this->assertTrue( $out['conflict_check']['forced'] );
		$this->assertArrayNotHasKey( 500, IATO_Test_Menus::$menus );
		$this->assertArrayNotHasKey( 500, IATO_Test_Menus::$items );
	}

	public function test_a_renamed_menu_is_a_conflict(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main' ] );
		IATO_Test_Menus::$menus[500]->name = 'Renamed';
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertStringContainsString( 'renamed', $out->get_error_message() );
	}

	public function test_a_menu_given_a_location_later_is_not_deleted_out_of_it(): void {
		// Deleting it would empty the location, and undoing the assignment could no longer
		// find what to restore: the old menu would be out of the header for good.
		IATO_Test_Menus::add_menu( self::OTHER, 'Legacy' );
		IATO_Test_Menus::$items[ self::OTHER ] = [ 1001, 1002 ];
		self::put( 'menu-1', self::OTHER );
		$made   = $this->call( 'create_menu', [ 'name' => 'New' ] );
		$assign = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => $made['menu_id'] ] );

		$dry = IATO_MCP_Rollback::execute( self::row( $made['change_receipt'] ), [ 'dry_run' => true ] );
		$this->assertFalse( $dry['would_roll_back'] );
		$out = IATO_MCP_Rollback::execute( self::row( $made['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertStringContainsString( 'menu-1', $out->get_error_message() );
		$this->assertStringContainsString( 'Roll back the location change first', $out->get_error_message() );
		$this->assertArrayHasKey( $made['menu_id'], IATO_Test_Menus::$menus );
		$this->assertSame( $made['menu_id'], self::location( 'menu-1' ) );

		// In the right order, each with no force, the old menu is back.
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $assign['change_receipt'] ) )['success'] );
		$this->assertSame( self::OTHER, self::location( 'menu-1' ) );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $made['change_receipt'] ) )['success'] );
		$this->assertArrayNotHasKey( $made['menu_id'], IATO_Test_Menus::$menus );
		$this->assertSame( self::OTHER, self::location( 'menu-1' ) );
	}

	public function test_force_still_gets_past_that(): void {
		$made = $this->call( 'create_menu', [ 'name' => 'New' ] );
		$this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => $made['menu_id'] ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $made['change_receipt'] ), [ 'force' => true ] )['success'] );
		$this->assertNull( self::location( 'menu-1' ) );
	}

	public function test_the_location_the_create_itself_assigned_is_no_conflict(): void {
		$made = $this->call( 'create_menu', [ 'name' => 'New', 'location' => 'menu-1' ] );
		$out  = IATO_MCP_Rollback::execute( self::row( $made['change_receipt'] ), [ 'dry_run' => true ] );
		$this->assertTrue( $out['would_roll_back'] );
	}

	public function test_another_location_on_top_of_the_one_it_was_created_with_is_a_conflict(): void {
		$made = $this->call( 'create_menu', [ 'name' => 'New', 'location' => 'menu-1' ] );
		$this->call( 'assign_menu_location', [ 'location' => 'menu-2', 'menu_id' => $made['menu_id'] ] );
		$out = IATO_MCP_Rollback::execute( self::row( $made['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertStringContainsString( 'menu-2', $out->get_error_message() );
		$this->assertStringNotContainsString( 'menu-1', $out->get_error_message() );
	}

	public function test_a_menu_already_gone_is_no_trouble(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main' ] );
		unset( IATO_Test_Menus::$menus[500] );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( 'the menu is already gone', $out['conflict_check']['reason'] );
	}

	public function test_a_refused_delete_is_an_error_and_the_location_stays(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main', 'location' => 'menu-1' ] );
		IATO_Test_Menus::$delete_fails = true;
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'delete_failed', $out->get_error_code() );
		$this->assertSame( 500, self::location( 'menu-1' ) );
	}

	public function test_after_a_theme_switch_a_menu_given_a_location_cannot_be_rolled_back(): void {
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', self::OTHER );
		$r = $this->call( 'create_menu', [ 'name' => 'Main', 'location' => 'menu-1' ] );
		IATO_Test_Menus::$stylesheet = 'twentytwentyfive';
		foreach ( [ [], [ 'force' => true ], [ 'dry_run' => true ] ] as $opts ) {
			$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), $opts );
			$this->assertSame( 'rollback_unavailable', $out->get_error_code() );
			$this->assertStringContainsString( 'hello-elementor', $out->get_error_message() );
			$this->assertStringContainsString( 'twentytwentyfive', $out->get_error_message() );
		}
		$this->assertArrayHasKey( 500, IATO_Test_Menus::$menus );
	}

	public function test_after_a_theme_switch_a_menu_with_no_location_still_rolls_back(): void {
		$r = $this->call( 'create_menu', [ 'name' => 'Main' ] );
		IATO_Test_Menus::$stylesheet = 'twentytwentyfive';
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertArrayNotHasKey( 500, IATO_Test_Menus::$menus );
	}

	// ── Rollback of assign_menu_location ──────────────────────────────────

	public function test_rollback_restores_the_location(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', self::OTHER );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( 'match', $out['conflict_check']['status'] );
		$this->assertSame( self::OTHER, self::location( 'menu-1' ) );
	}

	public function test_rollback_of_a_first_assignment_empties_the_location(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertNull( self::location( 'menu-1' ) );
	}

	public function test_rollback_of_a_clear_puts_the_menu_back(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		self::put( 'menu-1', self::MAIN );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => 0 ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( self::MAIN, self::location( 'menu-1' ) );
	}

	public function test_a_location_changed_since_is_a_conflict_unless_forced(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] );
		self::put( 'menu-1', self::OTHER );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertSame( self::OTHER, self::location( 'menu-1' ) );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'force' => true ] )['success'] );
		$this->assertNull( self::location( 'menu-1' ) );
	}

	public function test_a_rollback_dry_run_changes_nothing(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		$r   = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'dry_run' => true ] );
		$this->assertTrue( $out['would_roll_back'] );
		$this->assertSame( self::MAIN, self::location( 'menu-1' ) );
	}

	public function test_after_a_theme_switch_a_location_cannot_be_rolled_back(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] );
		IATO_Test_Menus::$stylesheet = 'twentytwentyfive';
		foreach ( [ [], [ 'force' => true ], [ 'dry_run' => true ] ] as $opts ) {
			$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), $opts );
			$this->assertSame( 'rollback_unavailable', $out->get_error_code() );
		}
		$this->assertSame( [], IATO_Test_Menus::locations() );
		IATO_Test_Menus::$stylesheet = 'hello-elementor';
		$this->assertSame( self::MAIN, self::location( 'menu-1' ), 'the old theme keeps its own' );
	}

	public function test_the_previous_menu_having_gone_makes_a_location_rollback_unavailable(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		IATO_Test_Menus::add_menu( self::OTHER, 'Other' );
		self::put( 'menu-1', self::OTHER );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] );
		unset( IATO_Test_Menus::$menus[ self::OTHER ] );
		foreach ( [ [], [ 'force' => true ] ] as $opts ) {
			$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), $opts );
			$this->assertSame( 'rollback_unavailable', $out->get_error_code() );
			$this->assertStringContainsString( (string) self::OTHER, $out->get_error_message() );
		}
		$this->assertSame( self::MAIN, self::location( 'menu-1' ) );
	}

	public function test_a_rollback_purges_the_whole_site(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		$r = $this->call( 'assign_menu_location', [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] );
		IATO_MCP_Cache::begin();
		IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( ( new ReflectionProperty( IATO_MCP_Cache::class, 'pending' ) )->getValue()['all'] );
	}

	// ── Who may undo ──────────────────────────────────────────────────────

	/** @return array<string,array{0:string}> */
	public static function menu_receipt_types(): array {
		return [ 'menu' => [ 'menu' ], 'menu location' => [ 'menu_location' ] ];
	}

	#[DataProvider( 'menu_receipt_types' )]
	public function test_undoing_needs_edit_theme_options( string $type ): void {
		$receipt = [ 'target_type' => $type, 'field' => 'menu-1' === $type ? 'menu-1' : 'create' ];
		$this->assertSame( 'edit_theme_options', IATO_MCP_Change_Receipt::cap_required_for( $receipt ) );
		$req = IATO_MCP_Change_Receipt::object_cap_for( $receipt );
		$this->assertSame( 'edit_theme_options', $req['cap'] );
		$this->assertNull( $req['object_id'] );
		$this->assertSame( 'type-by-design', $req['basis'] );
		$this->as_user( self::EDITOR );
		$this->assertSame( 'iato_mcp_forbidden', IATO_MCP_Rollback::check_permission( $receipt )->get_error_code() );
	}

	// ── What clients are told ─────────────────────────────────────────────

	private static function instructions_source(): string {
		$this_file = (string) file_get_contents( IATO_MCP_DIR . 'iato-mcp.php' );
		self::assertSame( 1, preg_match( '/function iato_mcp_build_server_instructions\(\).*?return trim\( \$instructions \);/s', $this_file, $m ) );
		return $m[0];
	}

	public function test_the_instructions_describe_the_menu_workflow(): void {
		$text = self::instructions_source();
		foreach ( [ 'create_menu,', 'create_menu_item', 'assign_menu_location', 'menu setting', "menu's slug", 'theme_locations', 'not_evaluated' ] as $phrase ) {
			$this->assertStringContainsString( $phrase, $text );
		}
	}

	public function test_the_instructions_name_only_tools_that_exist(): void {
		$tools = [];
		foreach ( array_merge( (array) glob( IATO_MCP_DIR . 'includes/tools/wp/*.php' ), (array) glob( IATO_MCP_DIR . 'includes/tools/bridge/*.php' ) ) as $file ) {
			preg_match_all( "/register_tool\(\s*'([a-z_]+)'/", (string) file_get_contents( (string) $file ), $found );
			$tools = array_merge( $tools, $found[1] );
		}
		$heredocs = '';
		preg_match_all( '/<<<(END_[A-Z]+)\n(.*?)\n\1;/s', self::instructions_source(), $blocks );
		foreach ( $blocks[2] as $block ) {
			$heredocs .= "\n" . $block;
		}
		$this->assertStringContainsString( 'NAVIGATION MENUS', $heredocs );
		preg_match_all( '/\b(?:create|update|delete|assign|set|get|list|find|search)_[a-z_]+\b/', $heredocs, $named );
		foreach ( array_unique( $named[0] ) as $name ) {
			$this->assertContains( $name, $tools, "{$name} is named in the server instructions" );
		}
	}

	// ── The lists every tool has to be on ─────────────────────────────────

	/** @return array<string,array{0:string}> */
	public static function new_tools(): array {
		return [ 'create_menu' => [ 'create_menu' ], 'assign_menu_location' => [ 'assign_menu_location' ] ];
	}

	#[DataProvider( 'new_tools' )]
	public function test_a_new_tool_is_on_every_list( string $tool ): void {
		$this->assertContains( $tool, IATO_MCP_Cache::WRITE_TOOLS );
		$source = (string) file_get_contents( IATO_MCP_DIR . 'includes/class-settings.php' );
		foreach ( [ 'TOOL_NAMES', 'TOOL_DESCRIPTIONS', 'TOOL_CATEGORIES', 'TOOL_MIGRATION_BACKFILL' ] as $constant ) {
			$this->assertSame( 1, preg_match( '/const ' . $constant . ' = \[(.*?)\n\t\];/s', $source, $m ), $constant );
			$this->assertStringContainsString( "'{$tool}'", $m[1], $constant );
		}
		$this->assertMatchesRegularExpression( "/'1\.17\.0' => \[[^\]]*'{$tool}',/", $source );
	}

	#[DataProvider( 'new_tools' )]
	public function test_the_schema_declares_dry_run( string $tool ): void {
		$schema = IATO_MCP_Server::get_catalog_entry( $tool )['definition']['inputSchema'];
		$this->assertArrayHasKey( 'dry_run', $schema['properties'] );
	}

	public function test_a_dry_run_through_the_server_is_not_refused_and_gets_no_cache_object(): void {
		$call = new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' );
		$r    = $call->invoke( null, [ 'name' => 'create_menu', 'arguments' => [ 'name' => 'Main', 'dry_run' => true ] ] );
		$body = json_decode( $r['content'][0]['text'], true );
		$this->assertArrayNotHasKey( 'isError', $r );
		$this->assertSame( 'would_create', $body['action'] );
		$this->assertArrayNotHasKey( 'cache', $body );
	}

	public function test_a_write_through_the_server_gets_a_site_wide_cache_object(): void {
		$call = new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' );
		$r    = $call->invoke( null, [ 'name' => 'create_menu', 'arguments' => [ 'name' => 'Main' ] ] );
		$this->assertSame( 'all', json_decode( $r['content'][0]['text'], true )['cache']['scope'] );
	}

	public function test_a_no_op_through_the_server_says_nothing_was_written(): void {
		IATO_Test_Menus::add_menu( self::MAIN, 'Main' );
		self::put( 'menu-1', self::MAIN );
		$call = new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' );
		$r    = $call->invoke( null, [ 'name' => 'assign_menu_location', 'arguments' => [ 'location' => 'menu-1', 'menu_id' => self::MAIN ] ] );
		$this->assertNull( json_decode( $r['content'][0]['text'], true )['cache']['purged'] );
	}
}
