<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-actor.php';
require_once __DIR__ . '/stubs-elementor.php';
require_once __DIR__ . '/stubs-router.php';
require_once __DIR__ . '/stubs-theme-builder.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-router.php';
require_once IATO_MCP_DIR . 'includes/class-theme-builder.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-elementor-templates.php';

/**
 * create_elementor_template and set_elementor_template_conditions (1.17.0): who may,
 * what is refused before anything is written, dry runs, the order things go live in,
 * the overlap warning, verified, receipts and rollback. Elementor and Pro are a fake;
 * ThemeBuilderEngineTest covers the class that calls them.
 */
final class ThemeBuilderToolsTest extends TestCase {

	private const ADMIN = 1, EDITOR = 9, OPTIONS_ONLY = 12;
	private const HEADER = 800, OTHER_HEADER = 801, SECTION = 802, A_PAGE = 803, POPUP = 804, OTHER_POPUP = 805;
	private const GENERAL = [ [ 'type' => 'include', 'name' => 'general' ] ];
	private const TREE = '[{"id":"a1","elType":"container","settings":{},"elements":[]}]';

	private FakeThemeBuilder $builder;

	protected function setUp(): void {
		IATO_Test_Cache_State::reset();
		IATO_Test_Pro::reset();
		IATO_Test_Pro::$active = true;
		IATO_Test_WP::$posts   = [];
		IATO_Test_WP::$meta    = [];
		IATO_Test_WP::$update_post_error  = null;
		IATO_Test_WP::$update_post_throws = false;
		FakeThemeBuilder::$trash_fails    = null;
		IATO_MCP_Rollback_Conflict::set_trash_deletes( null );
		IATO_Test_Router::reset();
		IATO_Test_Roles::$posts = [];
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator', self::EDITOR => 'editor', self::OPTIONS_ONLY => 'options_only' ];
		$GLOBALS['wpdb']->inserts = [];
		$this->builder = new FakeThemeBuilder();
		IATO_MCP_Theme_Builder::set_instance( $this->builder );
		IATO_MCP_Elementor_Router::forget();
		$this->as_user( self::ADMIN );
		IATO_MCP_Cache::begin();
	}

	protected function tearDown(): void {
		$this->as_user( null );
		IATO_MCP_Theme_Builder::set_instance( null );
		IATO_MCP_Elementor_Router::forget();
		IATO_MCP_Cache::begin();
		IATO_Test_Pro::reset();
		IATO_Test_WP::$posts = [];
		IATO_Test_WP::$meta  = [];
		IATO_Test_WP::$update_post_error  = null;
		IATO_Test_WP::$update_post_throws = false;
		FakeThemeBuilder::$trash_fails    = null;
		IATO_MCP_Rollback_Conflict::set_trash_deletes( null );
		IATO_Test_Router::reset();
		IATO_Test_Roles::$posts = [];
		IATO_Test_Roles::$users = [];
		foreach ( [ 'show_on_front', 'page_on_front', 'page_for_posts' ] as $option ) {
			unset( IATO_Test_Abilities_State::$options[ $option ] );
		}
	}

	private function as_user( ?int $id, bool $legacy_unbound = false ): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, null !== $id );
		$ref->getProperty( 'authenticated_user' )->setValue( null, $id ? new WP_User( $id ) : null );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, $legacy_unbound );
		$ref->getProperty( 'legacy_unbound' )->setValue( null, $legacy_unbound );
		$ref->getProperty( 'credential' )->setValue( null, null === $id ? [] : [ 'kind' => 'application_password', 'user_id' => $id ] );
	}

	private function call( string $tool, array $args = [] ): array {
		$r = ( IATO_MCP_Server::get_catalog_entry( $tool )['handler'] )( $args );
		if ( is_wp_error( $r ) ) {
			return [ 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ];
		}
		return json_decode( $r['content'][0]['text'], true ) + ( ! empty( $r['isError'] ) ? [ 'isError' => true ] : [] );
	}

	private function create( array $args = [] ): array {
		return $this->call( 'create_elementor_template', $args + [ 'title' => 'Site header', 'template_type' => 'header' ] );
	}

	private function set( int $id, array $conditions, array $more = [] ): array {
		return $this->call( 'set_elementor_template_conditions', [ 'id' => $id, 'conditions' => $conditions ] + $more );
	}

	/** The receipt row, as the rollback code gets it back from the table. */
	private static function row( array $receipt, string $applied_at = '2026-11-03 10:00:00' ): array {
		return [
			'change_id'    => $receipt['change_id'],
			'post_id'      => $receipt['post_id'],
			'target_type'  => $receipt['target_type'],
			'field'        => $receipt['field'],
			'before_value' => $receipt['before_value'],
			'after_value'  => $receipt['after_value'],
			'applied_at'   => $applied_at,
		];
	}

	private static function post_status( int $id ): ?string {
		return IATO_Test_WP::$posts[ $id ]['post_status'] ?? null;
	}

	private static function conditions( int $id ): array {
		return IATO_Test_WP::$meta[ $id ]['_elementor_conditions'] ?? [];
	}

	private static function edited( int $id, string $when = '2026-11-03 11:00:00' ): void {
		IATO_Test_WP::$posts[ $id ]['post_modified_gmt']   = $when;
		IATO_Test_Roles::$posts[ $id ]['post_modified_gmt'] = $when;
	}

	private function nothing_was_written(): void {
		$this->assertSame( [], $this->builder->calls );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
		$this->assertFalse( IATO_MCP_Cache::has_pending() );
	}

	// ── create_elementor_template: who, and what is refused ───────────────

	public function test_create_needs_manage_options(): void {
		$this->as_user( self::EDITOR );
		$this->assertSame( 'iato_mcp_forbidden', $this->create()['error'] );
		$this->nothing_was_written();
	}

	public function test_create_refuses_an_unbound_key(): void {
		// Elementor's Document::save() refuses user 0: no version, no type term.
		$this->as_user( null );
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, true );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, true );
		$ref->getProperty( 'legacy_unbound' )->setValue( null, true );
		$this->assertSame( 'iato_mcp_token_unbound', $this->create()['error'] );
		$this->nothing_was_written();
	}

	/** @return array<string,array{0:string}> */
	public static function theme_types(): array {
		return array_combine( IATO_MCP_Theme_Builder::THEME_TYPES, array_map( static fn( $t ) => [ $t ], IATO_MCP_Theme_Builder::THEME_TYPES ) );
	}

	#[DataProvider( 'theme_types' )]
	public function test_a_theme_builder_type_without_pro_names_the_plugin( string $type ): void {
		$this->builder->missing = 'Elementor Pro is not active on this site. Theme Builder templates and display conditions need it.';
		foreach ( [ [], [ 'dry_run' => true ] ] as $more ) {
			$r = $this->create( [ 'template_type' => $type ] + $more );
			$this->assertSame( 'elementor_pro_required', $r['error'] );
			$this->assertStringContainsString( 'Elementor Pro', $r['message'] );
		}
		$this->nothing_was_written();
	}

	/** @return array<string,array{0:string}> */
	public static function library_types(): array {
		return [ 'section' => [ 'section' ], 'container' => [ 'container' ], 'page' => [ 'page' ] ];
	}

	#[DataProvider( 'library_types' )]
	public function test_free_elementor_types_need_no_pro( string $type ): void {
		$this->builder->missing = 'Elementor Pro is not active.';
		$r = $this->create( [ 'template_type' => $type ] );
		$this->assertSame( 700, $r['id'] );
		$this->assertSame( $type, $r['template_type'] );
		$this->assertArrayNotHasKey( 'verified', $r, 'it has no location to verify' );
	}

	public function test_without_elementor_it_says_so(): void {
		$this->builder->types = null;
		$this->assertSame( 'elementor_required', $this->create()['error'] );
	}

	public function test_an_unknown_type_lists_what_this_site_offers(): void {
		$this->builder->types = array_diff_key( $this->builder->types, [ 'popup' => true ] );
		$r = $this->create( [ 'template_type' => 'nosuch' ] );
		$this->assertSame( 'unknown_template_type', $r['error'] );
		$this->assertContains( 'header', $r['allowed'] );
		$this->assertContains( 'page', $r['allowed'] );
		$this->assertNotContains( 'popup', $r['allowed'], 'not registered here' );
		$this->assertNotContains( 'loop-item', $r['allowed'], 'registered, but not one this tool makes' );
		$this->assertStringContainsString( 'header', $r['message'] );
		$this->nothing_was_written();
	}

	/** @return array<string,array{0:array,1:string}> */
	public static function bad_create_args(): array {
		return [
			'no title'             => [ [ 'title' => '' ], 'missing_title' ],
			'title not a string'   => [ [ 'title' => [ 'x' ] ], 'missing_title' ],
			'type not a string'    => [ [ 'template_type' => [ 'header' ] ], 'unknown_template_type' ],
			'published is not it'  => [ [ 'status' => 'published' ], 'invalid_status' ],
			'private is not it'    => [ [ 'status' => 'private' ], 'invalid_status' ],
			'broken JSON'          => [ [ 'elementor_data' => '[{' ], 'invalid_json' ],
			'data not a string'    => [ [ 'elementor_data' => [ [ 'id' => 'a' ] ] ], 'invalid_json' ],
			'data not a list'      => [ [ 'elementor_data' => '7' ], 'invalid_json' ],
			'a tree that crashes'  => [ [ 'elementor_data' => '[{"id":"a"}]' ], 'elementor_invalid_tree' ],
			'unknown condition'    => [ [ 'conditions' => [ 'include/nosuch' ] ], 'unknown_condition' ],
			'conditions not a list' => [ [ 'conditions' => [ 'type' => 'include', 'name' => 'general' ] ], 'unknown_condition' ],
			'conditions on a page' => [ [ 'template_type' => 'page', 'conditions' => [ 'include/general' ] ], 'conditions_not_supported' ],
			'conditions on a section' => [ [ 'template_type' => 'section', 'conditions' => self::GENERAL ], 'conditions_not_supported' ],
		];
	}

	#[DataProvider( 'bad_create_args' )]
	public function test_create_refuses_before_writing( array $args, string $code ): void {
		foreach ( [ false, true ] as $dry_run ) {
			$r = $this->create( $args + ( $dry_run ? [ 'dry_run' => true ] : [] ) );
			$this->assertSame( $code, $r['error'] );
		}
		$this->nothing_was_written();
	}

	public function test_an_empty_condition_list_on_a_section_is_fine(): void {
		$r = $this->create( [ 'template_type' => 'section', 'conditions' => [] ] );
		$this->assertSame( [], $r['conditions'] );
	}

	// ── create_elementor_template: dry run ────────────────────────────────

	public function test_a_dry_run_writes_nothing_and_says_what_would_happen(): void {
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE, 'dry_run' => true ] );
		$this->assertTrue( $r['dry_run'] );
		$this->assertSame( 'would_create', $r['action'] );
		$this->assertSame( 'header', $r['template_type'] );
		$this->assertSame( 'publish', $r['status'] );
		$this->assertSame( [ 'include/general' ], $r['conditions'] );
		$this->assertNotEmpty( $r['not_checked'] );
		$this->nothing_was_written();
	}

	public function test_a_dry_run_warns_of_an_overlap_too(): void {
		$this->builder->seed( self::OTHER_HEADER, 'header', 'Old header', 'publish', [ 'include/general' ] );
		$r = $this->create( [ 'conditions' => self::GENERAL, 'dry_run' => true ] );
		$this->assertSame( self::OTHER_HEADER, $r['overlap_warnings'][0]['template_id'] );
	}

	public function test_through_the_server_a_dry_run_has_no_cache_object(): void {
		$call = new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' );
		$r    = $call->invoke( null, [ 'name' => 'create_elementor_template', 'arguments' => [ 'title' => 'T', 'template_type' => 'header', 'dry_run' => true ] ] );
		$this->assertArrayNotHasKey( 'cache', json_decode( $r['content'][0]['text'], true ) );
		$this->assertFalse( IATO_MCP_Cache::has_pending() );
	}

	// ── create_elementor_template: the order things go live in ────────────

	public function test_it_is_a_draft_until_the_very_end(): void {
		$seen = [];
		$probe = new class( $this->builder, $seen ) {
			public function __construct( public FakeThemeBuilder $inner, public array &$seen ) {}
			public function __call( string $m, array $a ): mixed {
				if ( in_array( $m, [ 'store_tree', 'save_conditions' ], true ) ) {
					$this->seen[ $m ] = IATO_Test_WP::$posts[ $a[0] ]['post_status'] ?? null;
				}
				return $this->inner->$m( ...$a );
			}
		};
		IATO_MCP_Theme_Builder::set_instance( $probe );
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		$this->assertSame( [ 'store_tree' => 'draft', 'save_conditions' => 'draft' ], $seen );
		$this->assertSame( 'publish', $r['status'] );
		$this->assertSame( 'publish', self::post_status( $r['id'] ) );
		$this->assertSame( [ 'create:header', "tree:{$r['id']}", "conditions:{$r['id']}", 'regenerate' ], $this->builder->calls );
	}

	public function test_a_draft_stays_a_draft_by_default(): void {
		$r = $this->create( [ 'conditions' => self::GENERAL ] );
		$this->assertSame( 'draft', $r['status'] );
		$this->assertFalse( $r['verified'] );
		$this->assertSame( 'not_published', $r['verified_reason'] );
	}

	public function test_what_it_returns(): void {
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		$this->assertSame( 700, $r['id'] );
		$this->assertSame( 'Site header', $r['title'] );
		$this->assertStringContainsString( 'post=700&action=elementor', $r['edit_url'] );
		$this->assertSame( 'header', $r['template_type'] );
		$this->assertSame( [ 'include/general' ], $r['conditions'] );
		$this->assertSame( IATO_MCP_Theme_Builder::conditions_revision( [ 'include/general' ] ), $r['conditions_revision'] );
		$this->assertSame( 'sha256:filled', $r['current_revision'] );
		$this->assertTrue( $r['elementor_save'] );
		$this->assertTrue( $r['verified'] );
		$this->assertArrayNotHasKey( 'verified_reason', $r );
		$receipt = $r['change_receipt'];
		$this->assertSame( 'elementor_template', $receipt['target_type'] );
		$this->assertSame( 'create', $receipt['field'] );
		$this->assertSame( 700, $receipt['post_id'] );
		$this->assertNull( $receipt['before_value'] );
		$this->assertSame( [ 'template_type' => 'header', 'title' => 'Site header', 'status' => 'publish', 'location' => 'header', 'conditions' => [ 'include/general' ] ], array_diff_key( json_decode( $receipt['after_value'], true ), [ 'data_sha256' => 1, 'page_settings_sha256' => 1 ] ) );
	}

	public function test_with_no_data_nothing_is_stored_and_no_tree_is_reported(): void {
		$r = $this->create();
		$this->assertSame( [ 'create:header' ], $this->builder->calls );
		$this->assertArrayNotHasKey( 'current_revision', $r );
		$this->assertSame( 'no_include_condition', $r['verified_reason'] );
	}

	public function test_a_write_purges_the_whole_site(): void {
		$call = new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' );
		$r    = $call->invoke( null, [ 'name' => 'create_elementor_template', 'arguments' => [ 'title' => 'T', 'template_type' => 'header' ] ] );
		$this->assertSame( 'all', json_decode( $r['content'][0]['text'], true )['cache']['scope'] );
	}

	// ── create_elementor_template: failures leave nothing showing ─────────

	/** @return array<string,array{0:string}> */
	public static function failures(): array {
		return [ 'filling it' => [ 'tree' ], 'its conditions' => [ 'conditions' ], 'publishing it' => [ 'publish' ] ];
	}

	#[DataProvider( 'failures' )]
	public function test_a_failure_on_the_way_trashes_the_draft( string $step ): void {
		$error = new WP_Error( 'it_failed', 'It failed.' );
		match ( $step ) {
			'tree'       => $this->builder->tree_error = $error,
			'conditions' => $this->builder->save_error = $error,
			'publish'    => IATO_Test_WP::$update_post_error = $error,
		};
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		$this->assertSame( 'it_failed', $r['error'] );
		$this->assertStringContainsString( 'trash', $r['message'] );
		$this->assertSame( 700, $r['trashed_template_id'] );
		$this->assertSame( 'trash', self::post_status( 700 ) );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts, 'no receipt: there is nothing to roll back' );
		$this->assertSame( [], $this->builder->cached( 'header' ), 'nothing left in the cache that decides what renders' );
		$this->assertContains( 'regenerate', $this->builder->calls );
	}

	public function test_a_refused_create_is_returned_with_its_code(): void {
		$this->builder->create_error = new WP_Error( 'template_create_failed', 'Elementor refused.' );
		$r = $this->create();
		$this->assertSame( 'template_create_failed', $r['error'] );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
	}

	// ── create_elementor_template: rollback ───────────────────────────────

	public function test_rollback_of_an_untouched_template_trashes_it_and_rebuilds_the_cache(): void {
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		$this->builder->calls = [];
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( 'trash', self::post_status( 700 ) );
		$this->assertContains( 'regenerate', $this->builder->calls );
		$this->assertSame( [], $this->builder->cached( 'header' ) );
	}

	public function test_rollback_of_a_filled_live_header_needs_force_and_says_why(): void {
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		self::edited( 700 );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertStringContainsString( 'live site-wide header', $out->get_error_message() );
		$this->assertStringContainsString( 'every page', $out->get_error_message() );
		$this->assertStringContainsString( 'force: true', $out->get_error_message() );
		$this->assertSame( 'publish', self::post_status( 700 ) );
		$forced = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'force' => true ] );
		$this->assertTrue( $forced['success'] );
		$this->assertTrue( $forced['conflict_check']['forced'] );
		$this->assertSame( 'trash', self::post_status( 700 ) );
	}

	public function test_a_template_live_on_some_pages_says_that_instead(): void {
		$r = $this->create( [ 'status' => 'publish', 'conditions' => [ 'include/singular/page' ], 'elementor_data' => self::TREE ] );
		self::edited( 700 );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertStringContainsString( 'the pages its conditions match', $out->get_error_message() );
		$this->assertStringNotContainsString( 'site-wide', $out->get_error_message() );
	}

	public function test_an_edited_draft_is_a_conflict_with_no_talk_of_being_live(): void {
		$r = $this->create( [ 'conditions' => self::GENERAL ] );
		self::edited( 700 );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertStringNotContainsString( 'live', $out->get_error_message() );
	}

	public function test_conditions_set_later_are_a_conflict_though_the_post_was_not_modified(): void {
		// update_meta() does not touch post_modified.
		$r = $this->create( [ 'status' => 'publish' ] );
		$this->builder->save_conditions( 700, [ 'include/general' ] );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertStringContainsString( 'conditions were changed', $out->get_error_message() );
		$this->assertStringContainsString( 'live site-wide header', $out->get_error_message() );
	}

	public function test_a_rollback_dry_run_changes_nothing(): void {
		$r   = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'dry_run' => true ] );
		$this->assertTrue( $out['would_roll_back'] );
		$this->assertSame( 'publish', self::post_status( 700 ) );
	}

	public function test_a_template_already_in_the_trash_is_no_trouble(): void {
		$r = $this->create( [ 'status' => 'publish' ] );
		wp_trash_post( 700 );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
	}

	public function test_without_pro_it_is_still_trashed(): void {
		$r = $this->create( [ 'template_type' => 'section' ] );
		$this->builder->missing = 'Elementor Pro is not active.';
		$this->builder->calls   = [];
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( 'trash', self::post_status( 700 ) );
		$this->assertNotContains( 'regenerate', $this->builder->calls );
	}

	public function test_a_cache_that_cannot_be_rebuilt_is_a_warning_because_the_template_is_gone(): void {
		$r = $this->create( [ 'status' => 'publish' ] );
		$this->builder->regenerate_error = new WP_Error( 'cache_not_regenerated', 'It failed.' );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( 'trash', self::post_status( 700 ) );
		$this->assertStringContainsString( 'It failed.', $out['warning'] );
	}

	public function test_a_rollback_purges_the_whole_site(): void {
		$r = $this->create();
		IATO_MCP_Cache::begin();
		IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( ( new ReflectionProperty( IATO_MCP_Cache::class, 'pending' ) )->getValue()['all'] );
	}

	// ── set_elementor_template_conditions: who, and what is refused ───────

	public function test_set_needs_manage_options(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->as_user( self::EDITOR );
		$this->assertSame( 'iato_mcp_forbidden', $this->set( self::HEADER, self::GENERAL )['error'] );
		$this->assertSame( [], self::conditions( self::HEADER ) );
	}

	public function test_set_refuses_an_unbound_key(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->as_user( null );
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, true );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, true );
		$ref->getProperty( 'legacy_unbound' )->setValue( null, true );
		$this->assertSame( 'iato_mcp_token_unbound', $this->set( self::HEADER, self::GENERAL )['error'] );
	}

	public function test_set_without_pro_names_the_plugin(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->builder->missing = 'Elementor Pro is not active on this site.';
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertSame( 'elementor_pro_required', $r['error'] );
		$this->assertStringContainsString( 'Elementor Pro', $r['message'] );
	}

	public function test_set_refuses_what_is_not_a_template(): void {
		IATO_Test_WP::$posts[ self::A_PAGE ] = [ 'post_type' => 'page', 'post_status' => 'publish' ];
		IATO_Test_Roles::$posts[ self::A_PAGE ] = IATO_Test_WP::$posts[ self::A_PAGE ] + [ 'post_author' => 1 ];
		$this->assertSame( 'not_a_template', $this->set( self::A_PAGE, self::GENERAL )['error'] );
		$this->assertSame( 'not_a_template', $this->set( 99999, self::GENERAL )['error'] );
		$this->assertSame( 'not_a_template', $this->call( 'set_elementor_template_conditions', [ 'conditions' => self::GENERAL ] )['error'] );
	}

	public function test_set_refuses_a_template_in_the_trash(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'trash' );
		$this->assertSame( 'template_in_trash', $this->set( self::HEADER, self::GENERAL )['error'] );
	}

	public function test_set_refuses_a_template_with_no_location(): void {
		$this->builder->seed( self::SECTION, 'section' );
		$r = $this->set( self::SECTION, self::GENERAL );
		$this->assertSame( 'conditions_not_supported', $r['error'] );
		$this->assertSame( [], self::conditions( self::SECTION ) );
	}

	public function test_set_requires_conditions(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->assertSame( 'missing_conditions', $this->call( 'set_elementor_template_conditions', [ 'id' => self::HEADER ] )['error'] );
	}

	public function test_an_unknown_condition_returns_what_pro_registers(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, [ [ 'type' => 'include', 'name' => 'nosuch' ] ] );
		$this->assertSame( 'unknown_condition', $r['error'] );
		$this->assertContains( 'page', $r['registered']['singular'] );
		$this->assertContains( 'in_category', $r['registered']['singular'], 'a level further down' );
		$this->assertContains( 'post_archive', $r['registered']['archive'] );
		$this->assertContains( 'child_of', $r['takes_id'] );
		$this->assertSame( [], self::conditions( self::HEADER ) );
	}

	// ── set_elementor_template_conditions: the write ──────────────────────

	public function test_both_forms_store_the_same(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->builder->seed( self::OTHER_HEADER, 'header' );
		$this->set( self::HEADER, [ [ 'type' => 'include', 'name' => 'general' ], [ 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => 42 ] ] );
		$this->set( self::OTHER_HEADER, [ 'include/general', 'exclude/singular/page/42' ] );
		$this->assertSame( [ 'include/general', 'exclude/singular/page/42' ], self::conditions( self::HEADER ) );
		$this->assertSame( self::conditions( self::HEADER ), self::conditions( self::OTHER_HEADER ) );
	}

	public function test_what_set_returns(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/singular/page' ] );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertSame( self::HEADER, $r['id'] );
		$this->assertSame( 'header', $r['location'] );
		$this->assertSame( [ 'include/singular/page' ], $r['before'] );
		$this->assertSame( [ 'include/general' ], $r['after'] );
		$this->assertSame( IATO_MCP_Theme_Builder::conditions_revision( [ 'include/general' ] ), $r['conditions_revision'] );
		$this->assertTrue( $r['verified'] );
		$this->assertSame( 'elementor_template', $r['change_receipt']['target_type'] );
		$this->assertSame( 'conditions', $r['change_receipt']['field'] );
		$this->assertSame( '["include\/singular\/page"]', $r['change_receipt']['before_value'] );
		$this->assertContains( "conditions:" . self::HEADER, $this->builder->calls, 'through Pro, not by writing the meta' );
		$this->assertContains( 'regenerate', $this->builder->calls );
	}

	public function test_a_dry_run_writes_nothing(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/singular/page' ] );
		$r = $this->set( self::HEADER, self::GENERAL, [ 'dry_run' => true ] );
		$this->assertSame( 'would_update', $r['action'] );
		$this->assertSame( [ 'include/singular/page' ], $r['before'] );
		$this->assertSame( [ 'include/general' ], $r['after'] );
		$this->assertSame( IATO_MCP_Theme_Builder::conditions_revision( [ 'include/singular/page' ] ), $r['conditions_revision'] );
		$this->assertSame( [ 'include/singular/page' ], self::conditions( self::HEADER ) );
		$this->nothing_was_written();
	}

	public function test_no_change_writes_nothing_and_has_no_receipt(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/general' ] );
		foreach ( [ [], [ 'dry_run' => true ] ] as $more ) {
			$r = $this->set( self::HEADER, self::GENERAL, $more );
			$this->assertSame( 'no_change', $r['action'] );
			$this->assertArrayNotHasKey( 'change_receipt', $r );
		}
		$this->nothing_was_written();
	}

	public function test_a_change_of_order_is_a_change(): void {
		// The last matching include sets a template's priority.
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/general', 'include/singular/page' ] );
		$r = $this->set( self::HEADER, [ 'include/singular/page', 'include/general' ] );
		$this->assertArrayHasKey( 'change_receipt', $r );
	}

	public function test_an_empty_list_removes_them(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/general' ] );
		$r = $this->set( self::HEADER, [] );
		$this->assertSame( [], $r['after'] );
		$this->assertSame( [], self::conditions( self::HEADER ) );
		$this->assertSame( 'no_include_condition', $r['verified_reason'] );
		$this->assertSame( [], $this->builder->cached( 'header' ) );
	}

	public function test_if_revision_guards_the_write(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/singular/page' ] );
		$r = $this->set( self::HEADER, self::GENERAL, [ 'if_revision' => 'sha256:stale' ] );
		$this->assertSame( 'revision_conflict', $r['error'] );
		$this->assertSame( IATO_MCP_Theme_Builder::conditions_revision( [ 'include/singular/page' ] ), $r['conditions_revision'] );
		$this->assertSame( [ 'include/singular/page' ], self::conditions( self::HEADER ) );
		$this->assertSame( 'revision_conflict', $this->set( self::HEADER, self::GENERAL, [ 'if_revision' => 'sha256:stale', 'dry_run' => true ] )['error'] );
		$good = $this->set( self::HEADER, self::GENERAL, [ 'if_revision' => IATO_MCP_Theme_Builder::conditions_revision( [ 'include/singular/page' ] ) ] );
		$this->assertSame( [ 'include/general' ], $good['after'] );
	}

	public function test_a_failed_save_is_returned_with_no_receipt(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->builder->save_error = new WP_Error( 'conditions_not_saved', 'Not stored.' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertSame( 'conditions_not_saved', $r['error'] );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
	}

	public function test_a_write_through_the_server_purges_the_whole_site(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$call = new ReflectionMethod( IATO_MCP_Server::class, 'handle_tools_call' );
		$r    = $call->invoke( null, [ 'name' => 'set_elementor_template_conditions', 'arguments' => [ 'id' => self::HEADER, 'conditions' => [ 'include/general' ] ] ] );
		$this->assertSame( 'all', json_decode( $r['content'][0]['text'], true )['cache']['scope'] );
	}

	// ── verified ──────────────────────────────────────────────────────────

	public function test_verified_follows_the_home_page(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->assertTrue( $this->set( self::HEADER, self::GENERAL )['verified'] );
	}

	public function test_a_draft_is_not_verified(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'draft' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertFalse( $r['verified'] );
		$this->assertSame( 'not_published', $r['verified_reason'] );
	}

	public function test_conditions_missing_from_the_cache_are_not_verified(): void {
		// The silent failure the brief warns about: the template would simply not show.
		$this->builder->seed( self::HEADER, 'header' );
		$this->builder->leave_out_of_cache = true;
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertFalse( $r['verified'] );
		$this->assertSame( 'not_in_conditions_cache', $r['verified_reason'] );
	}

	public function test_only_excludes_can_never_show(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, [ 'exclude/singular/page/42' ] );
		$this->assertFalse( $r['verified'] );
		$this->assertSame( 'no_include_condition', $r['verified_reason'] );
	}

	public function test_another_template_winning_the_home_page_is_named(): void {
		$this->builder->seed( self::OTHER_HEADER, 'header', 'Old header', 'publish', [ 'include/general' ] );
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertFalse( $r['verified'] );
		$this->assertSame( 'another_template_wins', $r['verified_reason'] );
		$this->assertSame( self::OTHER_HEADER, $r['winning_template_id'] );
	}

	public function test_a_narrower_condition_is_checked_against_the_cache_only(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->assertTrue( $this->set( self::HEADER, [ 'include/singular/page/42' ] )['verified'] );
	}

	public function test_entire_site_with_an_exclude_is_not_looked_up_on_the_home_page(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->assertTrue( $this->set( self::HEADER, [ 'include/general', 'exclude/singular/front_page' ] )['verified'] );
	}

	public function test_a_popup_is_verified_without_a_home_page_lookup(): void {
		$this->builder->seed( self::POPUP, 'popup' );
		$this->assertTrue( $this->set( self::POPUP, self::GENERAL )['verified'] );
	}

	// ── The overlap warning ───────────────────────────────────────────────

	public function test_two_entire_site_headers_are_warned_about(): void {
		$this->builder->seed( self::OTHER_HEADER, 'header', 'Old header', 'publish', [ 'include/general' ] );
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertSame(
			[ [ 'template_id' => self::OTHER_HEADER, 'title' => 'Old header', 'condition' => 'include/general', 'your_condition' => 'include/general', 'relation' => 'same' ] ],
			$r['overlap_warnings']
		);
		$this->assertStringContainsString( 'may', $r['overlap_note'] );
		$this->assertStringContainsString( 'narrower', $r['overlap_note'] );
	}

	/** @return array<string,array{0:string,1:string,2:?string}> */
	public static function overlap_cases(): array {
		return [
			'the same'                  => [ 'include/singular/page', 'include/singular/page', 'same' ],
			'general over a page'       => [ 'include/general', 'include/singular/page/42', 'wider' ],
			'all pages over one'        => [ 'include/singular/page', 'include/singular/page/42', 'wider' ],
			'singular over a page'      => [ 'include/singular', 'include/singular/page', 'wider' ],
			'mine is general'           => [ 'include/singular/page/42', 'include/general', 'narrower' ],
			'mine is all pages'         => [ 'include/singular/page/42', 'include/singular/page', 'narrower' ],
			'mine is singular'          => [ 'include/archive/date', 'include/general', 'narrower' ],
			'posts and a category'      => [ 'include/singular/post', 'include/singular/in_category/5', 'wider' ],
			'one post and a category'   => [ 'include/singular/post/9', 'include/singular/in_category/5', 'may_overlap' ],
			'two categories'            => [ 'include/singular/in_category/4', 'include/singular/in_category/5', 'may_overlap' ],
			'a page and the front page' => [ 'include/singular/page', 'include/singular/front_page', 'wider' ],
			'one page and the front page' => [ 'include/singular/page/9', 'include/singular/front_page', 'may_overlap' ],
			'a page and child pages'    => [ 'include/singular/page', 'include/singular/child_of/3', 'may_overlap' ],
			'an author and a post type' => [ 'include/singular/by_author/2', 'include/singular/post', 'may_overlap' ],
			'a term and its children'   => [ 'include/archive/category/3', 'include/archive/child_of_category/2', 'may_overlap' ],
			'all archives over a date'  => [ 'include/archive', 'include/archive/date', 'wider' ],
			'pages and posts'           => [ 'include/singular/page', 'include/singular/post', null ],
			'a page and a category'     => [ 'include/singular/page', 'include/singular/in_category/5', null ],
			'the front page and posts'  => [ 'include/singular/post', 'include/singular/front_page', null ],
			'one page and another'      => [ 'include/singular/page/41', 'include/singular/page/42', null ],
			'one category archive and another' => [ 'include/archive/category/3', 'include/archive/category/4', null ],
			'a 404 and a page'          => [ 'include/singular/not_found404', 'include/singular/page', null ],
			'singular and archive'      => [ 'include/singular', 'include/archive', null ],
			'a date and a search'       => [ 'include/archive/date', 'include/archive/search', null ],
			'a category and a tag'      => [ 'include/archive/category', 'include/archive/post_tag', null ],
			'a word sharing a start'    => [ 'include/singular/page', 'include/singular/page_by_author/2', 'wider' ],
			'an exclude covers nothing' => [ 'exclude/singular/page/42', 'include/singular/page/42', null ],
		];
	}

	#[DataProvider( 'overlap_cases' )]
	public function test_what_counts_as_an_overlap( string $theirs, string $mine, ?string $relation ): void {
		$this->builder->seed( self::OTHER_HEADER, 'header', 'Other', 'publish', [ $theirs ] );
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, [ $mine ] );
		if ( null === $relation ) {
			$this->assertArrayNotHasKey( 'overlap_warnings', $r );
		} else {
			$this->assertSame( $relation, $r['overlap_warnings'][0]['relation'] );
		}
	}

	public function test_a_draft_is_no_overlap(): void {
		$this->builder->seed( self::OTHER_HEADER, 'header', 'Other', 'draft', [ 'include/general' ] );
		$this->builder->seed( self::HEADER, 'header' );
		$this->assertArrayNotHasKey( 'overlap_warnings', $this->set( self::HEADER, self::GENERAL ) );
	}

	public function test_a_template_does_not_overlap_itself(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/singular' ] );
		$this->assertArrayNotHasKey( 'overlap_warnings', $this->set( self::HEADER, self::GENERAL ) );
	}

	public function test_a_footer_is_no_overlap_for_a_header(): void {
		$this->builder->seed( self::OTHER_HEADER, 'footer', 'F', 'publish', [ 'include/general' ] );
		$this->builder->seed( self::HEADER, 'header' );
		$this->assertArrayNotHasKey( 'overlap_warnings', $this->set( self::HEADER, self::GENERAL ) );
	}

	public function test_popups_do_not_compete(): void {
		$this->builder->seed( self::OTHER_POPUP, 'popup', 'P', 'publish', [ 'include/general' ] );
		$this->builder->seed( self::POPUP, 'popup' );
		$this->assertArrayNotHasKey( 'overlap_warnings', $this->set( self::POPUP, self::GENERAL ) );
	}

	// ── Rollback of conditions ────────────────────────────────────────────

	public function test_rollback_puts_the_conditions_back_through_pro(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/singular/page' ] );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->builder->calls = [];
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( 'match', $out['conflict_check']['status'] );
		$this->assertSame( [ 'include/singular/page' ], self::conditions( self::HEADER ) );
		$this->assertSame( [ 'conditions:' . self::HEADER, 'regenerate' ], $this->builder->calls );
	}

	public function test_rollback_of_the_first_conditions_removes_them(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( [], self::conditions( self::HEADER ) );
		$this->assertSame( [], $this->builder->cached( 'header' ) );
	}

	public function test_conditions_changed_since_are_a_conflict_unless_forced(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->builder->save_conditions( self::HEADER, [ 'include/singular' ] );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertSame( [ 'include/singular' ], self::conditions( self::HEADER ) );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'force' => true ] )['success'] );
		$this->assertSame( [], self::conditions( self::HEADER ) );
	}

	public function test_a_rollback_dry_run_of_conditions_changes_nothing(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$r   = $this->set( self::HEADER, self::GENERAL );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'dry_run' => true ] );
		$this->assertTrue( $out['would_roll_back'] );
		$this->assertSame( [ 'include/general' ], self::conditions( self::HEADER ) );
	}

	public function test_without_pro_conditions_cannot_be_put_back(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->builder->missing = 'Elementor Pro is not active on this site.';
		foreach ( [ [], [ 'force' => true ], [ 'dry_run' => true ] ] as $opts ) {
			$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), $opts );
			$this->assertSame( 'rollback_unavailable', $out->get_error_code() );
			$this->assertStringContainsString( 'Elementor Pro', $out->get_error_message() );
		}
	}

	public function test_a_template_gone_makes_its_conditions_unavailable(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, self::GENERAL );
		wp_trash_post( self::HEADER );
		foreach ( [ [], [ 'force' => true ] ] as $opts ) {
			$this->assertSame( 'rollback_unavailable', IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), $opts )->get_error_code() );
		}
	}

	// ── The publish step (review item 5) ──────────────────────────────────

	public function test_a_throw_while_publishing_takes_the_template_down(): void {
		IATO_Test_WP::$update_post_throws = true;
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		$this->assertSame( 'template_create_failed', $r['error'] );
		$this->assertStringContainsString( 'an addon failed', $r['message'] );
		$this->assertStringContainsString( 'trash', $r['message'] );
		$this->assertSame( 700, $r['trashed_template_id'] );
		$this->assertSame( 'trash', self::post_status( 700 ) );
		$this->assertSame( [], $this->builder->cached( 'header' ) );
		$this->assertContains( 'regenerate', $this->builder->calls );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
		$this->assertTrue( ( new ReflectionProperty( IATO_MCP_Cache::class, 'pending' ) )->getValue()['all'], 'it was live for a moment' );
	}

	/** @return array<string,array{0:string}> */
	public static function trash_failures(): array {
		return [ 'it throws' => [ 'throw' ], 'it refuses' => [ 'false' ] ];
	}

	#[DataProvider( 'trash_failures' )]
	public function test_a_template_that_cannot_be_taken_down_is_named_as_still_live( string $how ): void {
		IATO_Test_WP::$update_post_throws = true;
		FakeThemeBuilder::$trash_fails    = $how;
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		$this->assertSame( 'template_create_failed', $r['error'] );
		$this->assertTrue( $r['still_live'] );
		$this->assertSame( 700, $r['template_id'] );
		$this->assertStringContainsString( 'still live', $r['message'] );
		$this->assertStringContainsString( '700', $r['message'] );
		$this->assertArrayNotHasKey( 'trashed_template_id', $r );
	}

	public function test_a_draft_that_cannot_be_trashed_is_named_as_a_draft(): void {
		$this->builder->tree_error     = new WP_Error( 'it_failed', 'It failed.' );
		FakeThemeBuilder::$trash_fails = 'false';
		$r = $this->create( [ 'elementor_data' => self::TREE ] );
		$this->assertSame( 'it_failed', $r['error'] );
		$this->assertArrayNotHasKey( 'still_live', $r );
		$this->assertSame( 700, $r['template_id'] );
		$this->assertStringContainsString( 'draft', $r['message'] );
	}

	public function test_a_rebuild_that_throws_during_the_take_down_is_said_too(): void {
		$this->builder->tree_error        = new WP_Error( 'it_failed', 'It failed.' );
		$this->builder->regenerate_throws = true;
		$r = $this->create( [ 'elementor_data' => self::TREE ] );
		$this->assertSame( 'it_failed', $r['error'] );
		$this->assertSame( 'trash', self::post_status( 700 ) );
		$this->assertStringContainsString( 'cache could not be rebuilt', $r['message'] );
	}

	// ── A page template that hides it (item 2) ────────────────────────────

	private static function static_front( int $id = self::A_PAGE ): void {
		IATO_Test_Abilities_State::$options['show_on_front'] = 'page';
		IATO_Test_Abilities_State::$options['page_on_front'] = (string) $id;
		IATO_Test_WP::$posts[ $id ]    = [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home' ];
		IATO_Test_Roles::$posts[ $id ] = IATO_Test_WP::$posts[ $id ] + [ 'post_author' => 1, 'post_password' => '' ];
	}

	public function test_a_canvas_home_page_is_not_verified(): void {
		self::static_front();
		IATO_Test_WP::$meta[ self::A_PAGE ]['_wp_page_template'] = 'elementor_canvas';
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertFalse( $r['verified'] );
		$this->assertSame( 'home_page_template_hides_it', $r['verified_reason'] );
	}

	// ── What the router could not judge (item 6) ──────────────────────────

	public function test_a_condition_the_router_could_not_judge_may_beat_this_template(): void {
		self::static_front();
		$this->builder->seed( self::OTHER_HEADER, 'header', 'Children header', 'publish', [ 'include/singular/child_of/5' ] );
		$this->builder->seed( self::HEADER, 'header' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertFalse( $r['verified'] );
		$this->assertSame( 'another_template_may_win', $r['verified_reason'] );
		$this->assertSame( [ [ 'template_id' => self::OTHER_HEADER, 'condition' => 'include/singular/child_of/5' ] ], $r['may_win'] );
		$this->assertSame( self::OTHER_HEADER, $r['overlap_warnings'][0]['template_id'] );
	}

	public function test_a_footer_it_could_not_judge_says_nothing_about_a_header(): void {
		self::static_front();
		$this->builder->seed( self::OTHER_HEADER, 'footer', 'F', 'publish', [ 'include/singular/child_of/5' ] );
		$this->builder->seed( self::HEADER, 'header' );
		$this->assertTrue( $this->set( self::HEADER, self::GENERAL )['verified'] );
	}

	public function test_its_own_exclude_it_could_not_judge_is_no_rival(): void {
		self::static_front();
		$this->builder->seed( self::HEADER, 'header' );
		$this->assertTrue( $this->set( self::HEADER, [ 'include/general', 'exclude/singular/child_of/5' ] )['verified'] );
	}

	// ── A save whose cache rebuild failed (item 7) ────────────────────────

	public function test_conditions_saved_with_the_cache_stale_are_a_success_with_a_warning(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->builder->save_then_stale = true;
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertArrayNotHasKey( 'error', $r );
		$this->assertSame( [ 'include/general' ], $r['after'] );
		$this->assertSame( [ 'include/general' ], self::conditions( self::HEADER ) );
		$this->assertFalse( $r['verified'] );
		$this->assertSame( 'cache_not_regenerated', $r['verified_reason'] );
		$this->assertStringContainsString( 'could not be rebuilt', $r['warnings'][0] );
		$this->assertArrayHasKey( 'change_receipt', $r );
	}

	public function test_a_new_template_with_a_stale_cache_is_kept_and_says_so(): void {
		$this->builder->save_then_stale = true;
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		$this->assertSame( 'publish', self::post_status( 700 ) );
		$this->assertSame( 'cache_not_regenerated', $r['verified_reason'] );
		$this->assertStringContainsString( 'could not be rebuilt', $r['warnings'][0] );
		$this->assertArrayHasKey( 'change_receipt', $r );
	}

	public function test_a_rollback_with_the_cache_stale_is_done_and_says_so(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/singular' ] );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->builder->save_then_stale = true;
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( [ 'include/singular' ], self::conditions( self::HEADER ) );
		$this->assertStringContainsString( 'could not be rebuilt', $out['warning'] );
	}

	// ── A stale cache (item 8) ────────────────────────────────────────────

	public function test_a_stale_cache_is_repaired_instead_of_no_change(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/general' ] );
		IATO_Test_Pro::$cache = [];
		$this->builder->calls = [];
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertTrue( $r['cache_repaired'] );
		$this->assertSame( 'cache_repaired', $r['action'] );
		$this->assertContains( 'regenerate', $this->builder->calls );
		$this->assertSame( [ self::HEADER => [ 'include/general' ] ], $this->builder->cached( 'header' ) );
		$this->assertTrue( $r['verified'] );
		$this->assertArrayNotHasKey( 'change_receipt', $r, 'nothing to undo' );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
		$this->assertTrue( ( new ReflectionProperty( IATO_MCP_Cache::class, 'pending' ) )->getValue()['all'], 'what visitors see may change' );
	}

	public function test_its_dry_run_only_says_so(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/general' ] );
		IATO_Test_Pro::$cache = [];
		$this->builder->calls = [];
		$r = $this->set( self::HEADER, self::GENERAL, [ 'dry_run' => true ] );
		$this->assertTrue( $r['would_repair_cache'] );
		$this->assertSame( [], $this->builder->cached( 'header' ) );
		$this->nothing_was_written();
	}

	public function test_a_repair_that_fails_is_an_error(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/general' ] );
		IATO_Test_Pro::$cache = [];
		$this->builder->regenerate_error = new WP_Error( 'cache_not_regenerated', 'It failed.' );
		$this->assertSame( 'cache_not_regenerated', $this->set( self::HEADER, self::GENERAL )['error'] );
	}

	public function test_an_up_to_date_cache_is_still_no_change(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'publish', [ 'include/general' ] );
		$this->assertSame( 'no_change', $this->set( self::HEADER, self::GENERAL )['action'] );
	}

	// ── A condition that can never render (item 10) ───────────────────────

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function render_cases(): array {
		return [
			'an archive condition on a single template' => [ 'single-page', 'include/archive', false ],
			'a date archive on a single template'       => [ 'single-post', 'include/archive/date', false ],
			'a singular condition on an archive'        => [ 'archive', 'include/singular/page', false ],
			'a singular condition on search results'    => [ 'search-results', 'include/singular', false ],
			'a singular condition on a single'          => [ 'single-page', 'include/singular/page', true ],
			'an archive condition on an archive'        => [ 'archive', 'include/archive', true ],
			'Entire Site on a single'                   => [ 'single-page', 'include/general', true ],
			'an archive condition on a header'          => [ 'header', 'include/archive', true ],
		];
	}

	#[DataProvider( 'render_cases' )]
	public function test_a_condition_on_the_wrong_kind_of_template( string $type, string $condition, bool $renders ): void {
		$this->builder->seed( self::HEADER, $type );
		$r = $this->set( self::HEADER, [ $condition ] );
		$this->assertArrayNotHasKey( 'error', $r, 'never refused: other Pro versions may differ' );
		$this->assertSame( [ $condition ], self::conditions( self::HEADER ) );
		if ( $renders ) {
			$this->assertTrue( $r['verified'] );
			$this->assertArrayNotHasKey( 'warnings', $r );
		} else {
			$this->assertFalse( $r['verified'] );
			$this->assertSame( 'condition_cannot_render', $r['verified_reason'] );
			$this->assertStringContainsString( $condition, $r['warnings'][0] );
		}
	}

	public function test_a_dry_run_of_a_filled_header_does_not_call_it_empty(): void {
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE, 'dry_run' => true ] );
		$this->assertArrayNotHasKey( 'warnings', $r );
	}

	public function test_create_warns_of_that_in_a_dry_run_too(): void {
		$r = $this->create( [ 'template_type' => 'single-page', 'conditions' => [ 'include/archive' ], 'dry_run' => true ] );
		$this->assertStringContainsString( 'include/archive', $r['warnings'][0] );
	}

	public function test_an_exclude_on_the_wrong_kind_does_no_harm(): void {
		$this->builder->seed( self::HEADER, 'single-page' );
		$r = $this->set( self::HEADER, [ 'include/singular/page', 'exclude/archive/date' ] );
		$this->assertTrue( $r['verified'] );
	}

	// ── An empty template (item 11) ───────────────────────────────────────

	/** @return array<string,array{0:string}> */
	public static function header_and_footer(): array {
		return [ 'header' => [ 'header' ], 'footer' => [ 'footer' ] ];
	}

	#[DataProvider( 'header_and_footer' )]
	public function test_publishing_an_empty_header_with_conditions_is_refused( string $type ): void {
		foreach ( [ [], [ 'dry_run' => true ], [ 'elementor_data' => '[]' ] ] as $more ) {
			$r = $this->create( [ 'template_type' => $type, 'status' => 'publish', 'conditions' => self::GENERAL ] + $more );
			$this->assertSame( 'empty_template', $r['error'] );
			$this->assertStringContainsString( "theme's {$type}", $r['message'] );
			$this->assertStringContainsString( 'draft', $r['message'] );
		}
		$this->nothing_was_written();
	}

	/** @return array<string,array{0:string,1:array,2:bool,3:?string}> */
	public static function publishing_a_saved_template(): array {
		$tree = '[{"id":"a","elType":"container","settings":[],"elements":[]}]';
		return [
			'empty header with conditions' => [ 'header', [ 'include/general' ], true, null ],
			'empty footer with conditions' => [ 'footer', [ 'include/singular' ], true, null ],
			'filled header'                => [ 'header', [ 'include/general' ], false, $tree ],
			'empty header, no conditions'  => [ 'header', [], false, null ],
			'empty header, excludes only'  => [ 'header', [ 'exclude/singular/page' ], false, null ],
			'empty single page'            => [ 'single-page', [ 'include/singular/page' ], false, null ],
		];
	}

	#[DataProvider( 'publishing_a_saved_template' )]
	public function test_publishing_a_saved_template_refuses_only_what_would_blank_a_header( string $type, array $conditions, bool $refused, ?string $tree ): void {
		IATO_Test_WP::$meta[ 900 ] = [ '_elementor_template_type' => $type, '_elementor_conditions' => $conditions ] + ( null === $tree ? [] : [ '_elementor_data' => $tree ] );
		$r = IATO_MCP_Theme_Builder::empty_publish_refusal( 900 );
		if ( $refused ) {
			$this->assertSame( 'empty_template', json_decode( $r['content'][0]['text'], true )['error'] ?? $r['error'] ?? null );
			$this->assertStringContainsString( "theme's {$type}", wp_json_encode( $r ) );
		} else {
			$this->assertNull( $r );
		}
	}

	public function test_what_that_does_not_refuse(): void {
		$this->assertArrayNotHasKey( 'error', $this->create( [ 'conditions' => self::GENERAL ] ), 'a draft' );
		$this->assertArrayNotHasKey( 'error', $this->create( [ 'title' => 'B', 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] ), 'filled' );
		$this->assertArrayNotHasKey( 'error', $this->create( [ 'title' => 'C', 'status' => 'publish' ] ), 'no conditions: it shows nowhere' );
		$this->assertArrayNotHasKey( 'error', $this->create( [ 'title' => 'D', 'template_type' => 'single-page', 'status' => 'publish', 'conditions' => [ 'include/singular/page' ] ] ), 'not a header' );
	}

	public function test_conditions_on_an_empty_published_header_warn_and_are_not_verified(): void {
		$this->builder->seed( self::HEADER, 'header' );
		unset( IATO_Test_WP::$meta[ self::HEADER ]['_elementor_data'] );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertSame( [ 'include/general' ], self::conditions( self::HEADER ), 'not refused' );
		$this->assertFalse( $r['verified'] );
		$this->assertSame( 'empty_template', $r['verified_reason'] );
		$this->assertStringContainsString( "theme's header", $r['warnings'][0] );
	}

	public function test_an_empty_draft_has_nothing_to_warn_of_yet(): void {
		$this->builder->seed( self::HEADER, 'header', 'H', 'draft' );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertSame( 'not_published', $r['verified_reason'] );
		$this->assertArrayNotHasKey( 'warnings', $r );
	}

	public function test_a_popup_with_no_triggers_gets_a_warning_only(): void {
		$this->builder->seed( self::POPUP, 'popup' );
		$r = $this->set( self::POPUP, self::GENERAL );
		$this->assertTrue( $r['verified'] );
		$this->assertStringContainsString( 'triggers', $r['warnings'][0] );
		$new = $this->create( [ 'title' => 'P', 'template_type' => 'popup', 'status' => 'publish', 'conditions' => self::GENERAL ] );
		$this->assertArrayNotHasKey( 'error', $new );
		$this->assertStringContainsString( 'triggers', $new['warnings'][0] );
	}

	public function test_a_popup_with_triggers_gets_none(): void {
		$this->builder->seed( self::POPUP, 'popup' );
		IATO_Test_WP::$meta[ self::POPUP ]['_elementor_popup_display_settings'] = [ 'triggers' => [ 'page_load' => 'yes' ], 'timing' => [] ];
		$this->assertArrayNotHasKey( 'warnings', $this->set( self::POPUP, self::GENERAL ) );
	}

	// ── The dry run refuses what the real call does (item 12) ─────────────

	public function test_atomic_checks_run_in_the_dry_run_and_before_the_post_exists(): void {
		$this->builder->preview_error = new WP_Error( 'atomic_invalid_settings', 'Elementor rejects it.' );
		foreach ( [ [ 'dry_run' => true ], [] ] as $more ) {
			$r = $this->create( [ 'elementor_data' => self::TREE ] + $more );
			$this->assertSame( 'atomic_invalid_settings', $r['error'] );
		}
		$this->assertSame( 2, $this->builder->previews );
		$this->nothing_was_written();
	}

	public function test_the_dry_run_reports_what_the_check_reports(): void {
		$this->builder->preview_report = [ 'schema' => 'atomic', 'sanitized' => true ];
		$r = $this->create( [ 'elementor_data' => self::TREE, 'dry_run' => true ] );
		$this->assertSame( 'atomic', $r['schema'] );
		$this->assertTrue( $r['sanitized'] );
	}

	// ── What rollback judges a template by (item 13) ──────────────────────

	/** @return array<string,array{0:string}> */
	public static function content_keys(): array {
		return [ 'its elements' => [ '_elementor_data' ], 'its page settings' => [ '_elementor_page_settings' ] ];
	}

	#[DataProvider( 'content_keys' )]
	public function test_a_filled_template_is_judged_by_its_content_not_a_timestamp( string $key ): void {
		$r = $this->create( [ 'status' => 'publish', 'conditions' => self::GENERAL, 'elementor_data' => self::TREE ] );
		IATO_Test_WP::$meta[ 700 ][ $key ] = 'changed, with post_modified left alone';
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertStringContainsString( 'content', $out->get_error_message() );
		$this->assertStringContainsString( 'live site-wide header', $out->get_error_message() );
		$this->assertSame( 'publish', self::post_status( 700 ) );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'force' => true ] )['success'] );
	}

	public function test_the_receipt_records_the_hashes(): void {
		$r     = $this->create( [ 'elementor_data' => self::TREE ] );
		$after = json_decode( $r['change_receipt']['after_value'], true );
		$this->assertSame( 64, strlen( $after['data_sha256'] ) );
		$this->assertSame( 64, strlen( $after['page_settings_sha256'] ) );
	}

	public function test_a_site_that_deletes_instead_of_trashing_needs_force(): void {
		IATO_MCP_Rollback_Conflict::set_trash_deletes( true );
		$r   = $this->create( [ 'elementor_data' => self::TREE ] );
		$out = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $out->get_error_code() );
		$this->assertStringContainsString( 'for good', $out->get_error_message() );
		$this->assertStringContainsString( 'EMPTY_TRASH_DAYS', $out->get_error_message() );
		$this->assertSame( 'draft', self::post_status( 700 ) );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'force' => true ] )['success'] );
	}

	// ── The coding rules (item 14) ────────────────────────────────────────

	public function test_set_checks_the_template_itself(): void {
		$this->builder->seed( self::HEADER, 'header' );
		$this->as_user( self::OPTIONS_ONLY );
		$r = $this->set( self::HEADER, self::GENERAL );
		$this->assertSame( 'iato_mcp_forbidden', $r['error'] );
		$this->assertStringContainsString( 'edit_post', $r['message'] );
		$this->assertSame( [], self::conditions( self::HEADER ) );
	}

	public function test_create_checks_the_right_to_publish(): void {
		$this->as_user( self::OPTIONS_ONLY );
		foreach ( [ [], [ 'dry_run' => true ] ] as $more ) {
			$r = $this->create( [ 'status' => 'publish', 'elementor_data' => self::TREE ] + $more );
			$this->assertSame( 'iato_mcp_forbidden', $r['error'] );
			$this->assertStringContainsString( 'publish', $r['message'] );
		}
		$this->nothing_was_written();
		$this->assertArrayNotHasKey( 'error', $this->create(), 'a draft needs no such right' );
	}

	// ── The schema (item 17) ──────────────────────────────────────────────

	/** @return array<string,array{0:string}> */
	public static function tools_taking_conditions(): array {
		return [ 'create' => [ 'create_elementor_template' ], 'set' => [ 'set_elementor_template_conditions' ] ];
	}

	#[DataProvider( 'tools_taking_conditions' )]
	public function test_conditions_have_an_items_schema( string $tool ): void {
		$conditions = IATO_MCP_Server::get_catalog_entry( $tool )['definition']['inputSchema']['properties']['conditions'];
		$this->assertSame( 'array', $conditions['type'] );
		$this->assertSame( [ 'string', 'object' ], array_column( $conditions['items']['anyOf'], 'type' ) );
		$this->assertSame( [ 'type', 'name', 'sub_name', 'sub_id' ], array_keys( $conditions['items']['anyOf'][1]['properties'] ) );
		$this->assertSame( [ 'include', 'exclude' ], $conditions['items']['anyOf'][1]['properties']['type']['enum'] );
	}

	// ── Who may undo ──────────────────────────────────────────────────────

	public function test_undoing_needs_manage_options(): void {
		$receipt = [ 'target_type' => 'elementor_template', 'field' => 'create', 'post_id' => self::HEADER ];
		$this->assertSame( 'manage_options', IATO_MCP_Change_Receipt::cap_required_for( $receipt ) );
		$req = IATO_MCP_Change_Receipt::object_cap_for( $receipt );
		$this->assertSame( 'manage_options', $req['cap'] );
		$this->assertSame( 'type-by-design', $req['basis'] );
		$this->as_user( self::EDITOR );
		$this->assertSame( 'iato_mcp_forbidden', IATO_MCP_Rollback::check_permission( $receipt )->get_error_code() );
	}

	// ── The lists every tool has to be on ─────────────────────────────────

	/** @return array<string,array{0:string}> */
	public static function new_tools(): array {
		return [ 'create_elementor_template' => [ 'create_elementor_template' ], 'set_elementor_template_conditions' => [ 'set_elementor_template_conditions' ] ];
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
		$this->assertArrayHasKey( 'dry_run', IATO_MCP_Server::get_catalog_entry( $tool )['definition']['inputSchema']['properties'] );
	}

	public function test_the_instructions_describe_the_header_workflow(): void {
		$source = (string) file_get_contents( IATO_MCP_DIR . 'iato-mcp.php' );
		$this->assertSame( 1, preg_match( '/function iato_mcp_build_server_instructions\(\).*?return trim\( \$instructions \);/s', $source, $m ) );
		foreach ( [ 'create_elementor_template', '{"type":"include","name":"general"}', 'update_elementor_data', 'theme_locations', 'elementor_header_footer', 'set_page_settings', 'force: true' ] as $phrase ) {
			$this->assertStringContainsString( $phrase, $m[0] );
		}
	}
}
