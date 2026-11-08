<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-site.php';

/**
 * update_site_settings (1.15.0): validation before any write, one receipt per
 * option changed, and rollback.
 */
final class UpdateSiteSettingsTest extends TestCase {

	private const ADMIN = 1, EDITOR = 9;
	private const HOME = 30, BLOG = 31, OTHER = 32, DRAFT = 33, A_POST = 34;

	protected function setUp(): void {
		IATO_Test_Cache_State::reset();
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator', self::EDITOR => 'editor' ];
		$posts = [
			self::HOME   => [ 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => self::ADMIN ],
			self::BLOG   => [ 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => self::ADMIN ],
			self::OTHER  => [ 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => self::ADMIN ],
			self::DRAFT  => [ 'post_type' => 'page', 'post_status' => 'draft', 'post_author' => self::ADMIN ],
			self::A_POST => [ 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => self::ADMIN ],
		];
		IATO_Test_Roles::$posts = $posts;
		IATO_Test_WP::$posts    = $posts;
		foreach ( [ 'blogname' => 'Old name', 'blogdescription' => '', 'show_on_front' => 'posts', 'page_on_front' => '0', 'page_for_posts' => '0' ] as $option => $value ) {
			IATO_Test_Abilities_State::$options[ $option ] = $value;
		}
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

	private function call( array $args ): array {
		$r = ( IATO_MCP_Server::get_catalog_entry( 'update_site_settings' )['handler'] )( $args );
		if ( is_wp_error( $r ) ) {
			return [ 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ];
		}
		return json_decode( $r['content'][0]['text'], true );
	}

	private function option( string $name ): mixed {
		return IATO_Test_Abilities_State::$options[ $name ] ?? null;
	}

	/** The receipt row for a change, as the rollback code gets it back from the table. */
	private static function row( array $receipt ): array {
		return [
			'change_id'    => $receipt['change_id'],
			'post_id'      => null,
			'target_type'  => 'site_setting',
			'field'        => $receipt['field'],
			'before_value' => $receipt['before_value'],
			'after_value'  => $receipt['after_value'],
			'applied_at'   => '2026-11-03 10:00:00',
		];
	}

	// ── Who, and what is accepted ─────────────────────────────────────────

	public function test_needs_manage_options(): void {
		$this->as_user( self::EDITOR );
		$r = $this->call( [ 'title' => 'New' ] );
		$this->assertSame( 'iato_mcp_forbidden', $r['error'] );
		$this->assertSame( 'Old name', $this->option( 'blogname' ) );
	}

	public function test_schema_refuses_other_keys(): void {
		$schema = IATO_MCP_Server::get_catalog_entry( 'update_site_settings' )['definition']['inputSchema'];
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'title', 'tagline', 'homepage_displays', 'front_page_id', 'posts_page_id', 'site_icon', 'custom_logo', 'permalink_structure', 'category_base', 'tag_base', 'confirm_url_break', 'redirect_old_urls', 'dry_run' ], array_keys( $schema['properties'] ) );
	}

	public function test_any_other_key_is_refused_and_nothing_is_written(): void {
		$r = $this->call( [ 'title' => 'New', 'admin_email' => 'x@example.com', 'siteurl' => 'https://evil.example' ] );
		$this->assertSame( 'unknown_setting', $r['error'] );
		$this->assertStringContainsString( 'admin_email, siteurl', $r['message'] );
		$this->assertSame( 'Old name', $this->option( 'blogname' ) );
	}

	public function test_nothing_to_do_is_refused(): void {
		$this->assertSame( 'no_settings', $this->call( [] )['error'] );
		$this->assertSame( 'no_settings', $this->call( [ 'dry_run' => true ] )['error'] );
	}

	/** @return array<string,array{0:array,1:string}> */
	public static function refused(): array {
		return [
			'title not a string'      => [ [ 'title' => [ 'x' ] ], 'invalid_setting' ],
			'tagline not a string'    => [ [ 'tagline' => 5 ], 'invalid_setting' ],
			'bad homepage_displays'   => [ [ 'homepage_displays' => 'latest' ], 'invalid_setting' ],
			'page without front page' => [ [ 'homepage_displays' => 'page' ], 'front_page_required' ],
			'front id not a number'   => [ [ 'front_page_id' => 'home' ], 'invalid_setting' ],
			'front id negative'       => [ [ 'front_page_id' => -3 ], 'invalid_page' ],
			'front id zero'           => [ [ 'front_page_id' => 0 ], 'invalid_page' ],
			'front page missing'      => [ [ 'front_page_id' => 999 ], 'invalid_page' ],
			'front page a draft'      => [ [ 'front_page_id' => self::DRAFT ], 'invalid_page' ],
			'front page a post'       => [ [ 'front_page_id' => self::A_POST ], 'invalid_page' ],
			'posts page a draft'      => [ [ 'posts_page_id' => self::DRAFT ], 'invalid_page' ],
			'posts page a post'       => [ [ 'posts_page_id' => self::A_POST ], 'invalid_page' ],
			'same page both'          => [ [ 'front_page_id' => self::HOME, 'posts_page_id' => self::HOME ], 'same_page' ],
		];
	}

	#[DataProvider( 'refused' )]
	public function test_invalid_values_are_refused_before_anything_is_written( array $args, string $code ): void {
		$r = $this->call( $args + [ 'title' => 'Should not be stored' ] );
		$this->assertSame( $code, $r['error'] ?? null );
		$this->assertSame( 'Old name', $this->option( 'blogname' ) );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
	}

	public function test_the_posts_page_cannot_become_the_front_page_nor_the_reverse(): void {
		IATO_Test_Abilities_State::$options['page_for_posts'] = (string) self::BLOG;
		$this->assertSame( 'same_page', $this->call( [ 'front_page_id' => self::BLOG ] )['error'] );

		IATO_Test_Abilities_State::$options['page_for_posts'] = '0';
		IATO_Test_Abilities_State::$options['page_on_front']  = (string) self::HOME;
		$this->assertSame( 'same_page', $this->call( [ 'posts_page_id' => self::HOME ] )['error'] );
	}

	public function test_moving_both_pages_at_once_is_fine(): void {
		IATO_Test_Abilities_State::$options['page_on_front']  = (string) self::HOME;
		IATO_Test_Abilities_State::$options['page_for_posts'] = (string) self::BLOG;
		$r = $this->call( [ 'front_page_id' => self::BLOG, 'posts_page_id' => self::HOME ] );
		$this->assertArrayNotHasKey( 'error', $r );
		$this->assertSame( self::BLOG, (int) $this->option( 'page_on_front' ) );
		$this->assertSame( self::HOME, (int) $this->option( 'page_for_posts' ) );
	}

	// ── Writing ───────────────────────────────────────────────────────────

	public function test_static_front_page_writes_each_option_with_a_receipt(): void {
		$r = $this->call( [ 'homepage_displays' => 'page', 'front_page_id' => self::HOME, 'posts_page_id' => self::BLOG ] );
		$this->assertSame( 'page', $this->option( 'show_on_front' ) );
		$this->assertSame( self::HOME, (int) $this->option( 'page_on_front' ) );
		$this->assertSame( self::BLOG, (int) $this->option( 'page_for_posts' ) );

		$this->assertSame( [ 'front_page_id', 'posts_page_id', 'homepage_displays' ], array_keys( $r['changes'] ) );
		$this->assertSame( [ 'option' => 'show_on_front', 'before' => 'posts', 'after' => 'page' ], $r['changes']['homepage_displays'] );
		$this->assertSame( [ 'option' => 'page_on_front', 'before' => '0', 'after' => (string) self::HOME ], $r['changes']['front_page_id'] );

		$this->assertCount( 3, $r['change_receipts'] );
		$this->assertSame( [ 'page_on_front', 'page_for_posts', 'show_on_front' ], array_column( $r['change_receipts'], 'field' ) );
		foreach ( $r['change_receipts'] as $receipt ) {
			$this->assertSame( 'site_setting', $receipt['target_type'] );
			$this->assertNull( $receipt['post_id'] );
			$this->assertMatchesRegularExpression( '/^wr_[a-f0-9]{16}$/', $receipt['change_id'] );
		}
		$this->assertContains( 'flush_rewrite_rules:soft', IATO_Test_Cache_State::$calls, 'as wp-admin does for the front page' );
	}

	public function test_one_change_returns_change_receipt(): void {
		$r = $this->call( [ 'title' => 'New name' ] );
		$this->assertSame( 'blogname', $r['change_receipt']['field'] );
		$this->assertSame( 'Old name', $r['change_receipt']['before_value'] );
		$this->assertSame( 'New name', $r['change_receipt']['after_value'] );
		$this->assertArrayNotHasKey( 'change_receipts', $r );
		$this->assertNotContains( 'flush_rewrite_rules:soft', IATO_Test_Cache_State::$calls, 'only the front page needs it' );
	}

	public function test_an_unchanged_value_writes_nothing_and_records_nothing(): void {
		$r = $this->call( [ 'title' => 'Old name', 'homepage_displays' => 'posts', 'posts_page_id' => 0 ] );
		$this->assertSame( [], $r['changes'] );
		$this->assertArrayNotHasKey( 'change_receipt', $r );
		$this->assertArrayNotHasKey( 'change_receipts', $r );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
		$this->assertFalse( IATO_MCP_Cache::has_pending() );
	}

	public function test_no_changes_is_a_json_object_not_a_list(): void {
		$r = ( IATO_MCP_Server::get_catalog_entry( 'update_site_settings' )['handler'] )( [ 'title' => 'Old name' ] );
		$this->assertStringContainsString( '"changes":{}', $r['content'][0]['text'] );
	}

	public function test_tags_go_percent_octets_stay_and_the_receipt_holds_what_was_stored(): void {
		$r = $this->call( [ 'title' => 'Q&A <b>night</b> 100%ab', 'tagline' => 'Fix <script>x</script>' ] );
		$this->assertSame( 'Q&A night 100%ab', $this->option( 'blogname' ), 'esc_html on the write is covered by the live suite' );
		$this->assertSame( $this->option( 'blogname' ), $r['changes']['title']['after'] );
		$this->assertSame( $this->option( 'blogname' ), $r['change_receipts'][0]['after_value'], 'read back, or the conflict check would misfire' );
	}

	public function test_a_write_purges_the_whole_site(): void {
		$this->call( [ 'title' => 'New name' ] );
		$this->assertTrue( IATO_MCP_Cache::has_pending() );
		IATO_MCP_Cache::set_detector( fn( string $slug, bool $real ) => 'litespeed' === $slug );
		$cache = IATO_MCP_Cache::flush();
		IATO_MCP_Cache::set_detector( null );
		$this->assertSame( 'all', $cache['scope'] );
		$this->assertTrue( $cache['purged'] );
	}

	public function test_dry_run_reports_and_writes_nothing(): void {
		$r = $this->call( [ 'title' => 'New & <b>name</b>', 'homepage_displays' => 'page', 'front_page_id' => self::HOME, 'dry_run' => true ] );
		$this->assertTrue( $r['dry_run'] );
		$this->assertSame( [ 'option' => 'blogname', 'before' => 'Old name', 'after' => 'New &amp; name' ], $r['changes']['title'] );
		$this->assertSame( 'Old name', $this->option( 'blogname' ) );
		$this->assertSame( 'posts', $this->option( 'show_on_front' ) );
		$this->assertArrayNotHasKey( 'change_receipts', $r );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
		$this->assertFalse( IATO_MCP_Cache::has_pending() );
		$this->assertSame( [], IATO_Test_Cache_State::$calls );
	}

	// ── Reads ─────────────────────────────────────────────────────────────

	public function test_get_site_settings_reports_the_same_options(): void {
		$this->call( [ 'homepage_displays' => 'page', 'front_page_id' => self::HOME, 'posts_page_id' => self::BLOG ] );
		$r = ( IATO_MCP_Server::get_catalog_entry( 'get_site_settings' )['handler'] )( [] );
		$body = json_decode( $r['content'][0]['text'], true );
		$this->assertSame( 'page', $body['show_on_front'] );
		$this->assertSame( self::HOME, $body['page_on_front'] );
		$this->assertSame( self::BLOG, $body['page_for_posts'] );
		$this->assertSame( 'Old name', $body['title'], 'existing fields stay' );
	}

	// ── Rollback ──────────────────────────────────────────────────────────

	public function test_rollback_restores_each_option(): void {
		$r = $this->call( [ 'title' => 'New name', 'homepage_displays' => 'page', 'front_page_id' => self::HOME ] );
		foreach ( $r['change_receipts'] as $receipt ) {
			$result = IATO_MCP_Rollback::execute( self::row( $receipt ) );
			$this->assertTrue( $result['success'], $receipt['field'] );
			$this->assertSame( 'match', $result['conflict_check']['status'], $receipt['field'] );
		}
		$this->assertSame( 'Old name', $this->option( 'blogname' ) );
		$this->assertSame( 'posts', $this->option( 'show_on_front' ) );
		$this->assertSame( '0', $this->option( 'page_on_front' ) );
		$this->assertSame( 2, count( array_keys( IATO_Test_Cache_State::$calls, 'flush_rewrite_rules:soft', true ) ), 'on the write and on its rollback' );
	}

	public function test_rolling_back_to_an_empty_tagline_empties_it(): void {
		$r = $this->call( [ 'tagline' => 'Something' ] );
		$this->assertNull( $r['change_receipt']['before_value'], 'an empty value is recorded as null' );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( '', $this->option( 'blogdescription' ), 'restored as empty, not deleted' );
	}

	public function test_rolling_back_to_page_zero_keeps_zero(): void {
		$r = $this->call( [ 'posts_page_id' => self::BLOG ] );
		$this->assertSame( '0', $r['change_receipt']['before_value'] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( '0', $this->option( 'page_for_posts' ) );
	}

	public function test_a_newer_change_blocks_the_rollback_unless_forced(): void {
		$r = $this->call( [ 'title' => 'New name' ] );
		IATO_Test_Abilities_State::$options['blogname'] = 'Someone else changed it';

		$refused = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $refused->get_error_code() );
		$this->assertSame( 'Someone else changed it', $this->option( 'blogname' ) );

		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'force' => true ] )['success'] );
		$this->assertSame( 'Old name', $this->option( 'blogname' ) );
	}

	public function test_rollback_dry_run_changes_nothing(): void {
		$r = $this->call( [ 'title' => 'New name' ] );
		$dry = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'dry_run' => true ] );
		$this->assertTrue( $dry['would_roll_back'] );
		$this->assertSame( 'Old name', $dry['would_restore'] );
		$this->assertSame( 'New name', $this->option( 'blogname' ) );
	}

	public function test_a_page_no_longer_published_cannot_be_restored(): void {
		IATO_Test_Abilities_State::$options['page_on_front'] = (string) self::HOME;
		$r = $this->call( [ 'front_page_id' => self::OTHER ] );
		IATO_Test_WP::$posts[ self::HOME ]['post_status']    = 'trash';
		IATO_Test_Roles::$posts[ self::HOME ]['post_status'] = 'trash';

		foreach ( [ [], [ 'force' => true ], [ 'dry_run' => true ] ] as $opts ) {
			$refused = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), $opts );
			$this->assertSame( 'rollback_unavailable', $refused->get_error_code(), wp_json_encode( $opts ) );
		}
		$this->assertSame( self::OTHER, (int) $this->option( 'page_on_front' ) );
	}

	public function test_a_rollback_cannot_put_one_page_in_both_roles(): void {
		IATO_Test_Abilities_State::$options['page_on_front']  = (string) self::HOME;
		IATO_Test_Abilities_State::$options['page_for_posts'] = (string) self::BLOG;
		$posts = $this->call( [ 'posts_page_id' => self::OTHER ] );      // BLOG was the posts page
		$this->call( [ 'front_page_id' => self::BLOG ] );                // BLOG is now the front page

		$refused = IATO_MCP_Rollback::execute( self::row( $posts['change_receipt'] ), [ 'force' => true ] );
		$this->assertSame( 'rollback_unavailable', $refused->get_error_code() );
		$this->assertStringContainsString( 'both the front page and the posts page', $refused->get_error_message() );
		$this->assertSame( self::OTHER, (int) $this->option( 'page_for_posts' ) );
	}

	public function test_a_receipt_naming_another_option_is_refused(): void {
		$row = [ 'change_id' => 'wr_0000000000000001', 'post_id' => null, 'target_type' => 'site_setting', 'field' => 'siteurl', 'before_value' => 'https://evil.example', 'after_value' => 'x', 'applied_at' => '2026-11-03 10:00:00' ];
		IATO_Test_Abilities_State::$options['siteurl'] = 'https://site.example';
		$refused = IATO_MCP_Rollback::execute( $row, [ 'force' => true ] );
		$this->assertSame( 'rollback_unavailable', $refused->get_error_code() );
		$this->assertSame( 'https://site.example', $this->option( 'siteurl' ) );
		unset( IATO_Test_Abilities_State::$options['siteurl'] );
	}

	public function test_a_rollback_purges_the_whole_site(): void {
		$r = $this->call( [ 'title' => 'New name' ] );
		IATO_MCP_Cache::begin();
		IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		IATO_MCP_Cache::set_detector( fn( string $slug, bool $real ) => 'litespeed' === $slug );
		$cache = IATO_MCP_Cache::flush();
		IATO_MCP_Cache::set_detector( null );
		$this->assertSame( 'all', $cache['scope'] );
	}

	public function test_only_an_administrator_may_roll_one_back(): void {
		$r = $this->call( [ 'title' => 'New name' ] );
		$this->assertSame( 'manage_options', IATO_MCP_Change_Receipt::cap_required_for( self::row( $r['change_receipt'] ) ) );
		$this->as_user( self::EDITOR );
		$check = IATO_MCP_Rollback::check_permission( self::row( $r['change_receipt'] ) );
		$this->assertTrue( is_wp_error( $check ) );
	}
}
