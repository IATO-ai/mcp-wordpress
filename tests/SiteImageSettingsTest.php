<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-site-image.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-site.php';

/**
 * site_icon and custom_logo in update_site_settings and get_site_settings (1.18.0):
 * validation before any write, receipts, rollback, Elementor's saved value.
 */
final class SiteImageSettingsTest extends TestCase {

	private const ADMIN = 1, EDITOR = 9, OPTIONS_ONLY = 10;
	private const ICON = 40, OTHER_ICON = 41, SMALL = 42, WIDE = 43, SVG = 44, PDF = 45, LIAR = 46, DEFERRED = 47, NO_FILE = 48, UNREADABLE = 49, TRASHED = 50, A_PAGE = 51, OFFLOADED = 52;

	private FakeSiteImage $images;

	protected function setUp(): void {
		IATO_Test_Cache_State::reset();
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator', self::EDITOR => 'editor', self::OPTIONS_ONLY => 'options_only' ];
		$attachment             = [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_author' => self::ADMIN ];
		$posts                  = array_fill_keys(
			[ self::ICON, self::OTHER_ICON, self::SMALL, self::WIDE, self::SVG, self::PDF, self::LIAR, self::DEFERRED, self::NO_FILE, self::UNREADABLE, self::OFFLOADED ],
			$attachment
		);
		$posts[ self::TRASHED ] = [ 'post_status' => 'trash' ] + $attachment;
		$posts[ self::A_PAGE ]  = [ 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => self::ADMIN ];
		IATO_Test_Roles::$posts = $posts;
		IATO_Test_WP::$posts    = $posts;

		$this->images         = new FakeSiteImage();
		$this->images->images = [
			self::ICON       => FakeSiteImage::image( 512, 512 ),
			self::OTHER_ICON => FakeSiteImage::image( 1024, 1024 ),
			self::SMALL      => FakeSiteImage::image( 100, 100 ),
			self::WIDE       => FakeSiteImage::image( 600, 400 ),
			self::SVG        => FakeSiteImage::image( 512, 512, [ 'stored_type' => 'image/svg+xml', 'real_type' => false ] ),
			self::PDF        => FakeSiteImage::image( 0, 0, [ 'stored_type' => 'application/pdf', 'real_type' => false, 'has_metadata' => false ] ),
			self::LIAR       => FakeSiteImage::image( 0, 0, [ 'real_type' => false ] ),
			self::DEFERRED   => FakeSiteImage::image( 512, 512, [ 'has_metadata' => false, 'sizes' => [] ] ),
			self::NO_FILE    => FakeSiteImage::image( 0, 0, [ 'has_file' => false, 'has_metadata' => false, 'real_type' => false, 'sizes' => [] ] ),
			self::UNREADABLE => FakeSiteImage::image( 0, 0 ),
			self::TRASHED    => FakeSiteImage::image( 512, 512 ),
			self::OFFLOADED  => FakeSiteImage::image( 512, 512, [ 'has_file' => false, 'real_type' => false ] ),
		];
		IATO_MCP_Site_Image::set_instance( $this->images );

		IATO_Test_Abilities_State::$options['site_icon'] = '0';
		IATO_Test_Abilities_State::$options['blogname']  = 'Old name';
		$GLOBALS['wpdb']->inserts                        = [];
		$this->as_user( self::ADMIN );
		IATO_MCP_Cache::begin();
	}

	protected function tearDown(): void {
		IATO_MCP_Site_Image::set_instance( null );
		unset( IATO_Test_Abilities_State::$options['site_icon'] );
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

	private function settings(): array {
		$r = ( IATO_MCP_Server::get_catalog_entry( 'get_site_settings' )['handler'] )( [] );
		return json_decode( $r['content'][0]['text'], true );
	}

	private function icon(): mixed {
		return IATO_Test_Abilities_State::$options['site_icon'] ?? null;
	}

	/** The row the rollback code gets back from the table, where arrays are JSON. */
	private static function row( array $receipt ): array {
		$stored = static fn( mixed $v ): mixed => is_array( $v ) ? wp_json_encode( $v ) : $v;
		return [
			'change_id'    => $receipt['change_id'],
			'post_id'      => null,
			'target_type'  => 'site_setting',
			'field'        => $receipt['field'],
			'before_value' => $stored( $receipt['before_value'] ),
			'after_value'  => $stored( $receipt['after_value'] ),
			'applied_at'   => '2026-11-03 10:00:00',
		];
	}

	// ── Validation ────────────────────────────────────────────────────────────

	/** @return array<string,array{0:mixed,1:string}> */
	public static function refused_icons(): array {
		return [
			'missing'           => [ 999, 'attachment_not_found' ],
			'a page'            => [ self::A_PAGE, 'not_an_attachment' ],
			'in the trash'      => [ self::TRASHED, 'attachment_in_trash' ],
			'an SVG'            => [ self::SVG, 'unsupported_image_type' ],
			'not an image'      => [ self::PDF, 'unsupported_image_type' ],
			'wrong bytes'       => [ self::LIAR, 'unsupported_image_type' ],
			'still generating'  => [ self::DEFERRED, 'attachment_not_ready' ],
			'nothing to read'   => [ self::NO_FILE, 'attachment_unreadable' ],
			'no dimensions'     => [ self::UNREADABLE, 'attachment_unreadable' ],
			'not square'        => [ self::WIDE, 'image_not_square' ],
		];
	}

	#[DataProvider( 'refused_icons' )]
	public function test_a_bad_icon_is_refused_and_nothing_is_written( mixed $value, string $code ): void {
		foreach ( [ [], [ 'dry_run' => true ] ] as $extra ) {
			$r = $this->call( [ 'title' => 'New name', 'site_icon' => $value ] + $extra );
			$this->assertSame( $code, $r['error'], 'the code reaches the client' );
			$this->assertNotEmpty( $r['message'] );
		}
		$this->assertSame( 'Old name', IATO_Test_Abilities_State::$options['blogname'], 'nothing written when another setting in the call is bad' );
		$this->assertSame( '0', $this->icon() );
		$this->assertSame( [], $this->images->made );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
	}

	/** @return array<string,array{0:mixed}> */
	public static function not_ids(): array {
		return [ 'negative' => [ -3 ], 'text' => [ 'icon' ], 'float' => [ 1.5 ], 'true' => [ true ], 'null' => [ null ], 'empty' => [ '' ], 'list' => [ [ 40 ] ] ];
	}

	#[DataProvider( 'not_ids' )]
	public function test_a_value_that_is_not_an_id_is_refused( mixed $value ): void {
		$this->assertSame( 'invalid_setting', $this->call( [ 'site_icon' => $value ] )['error'] );
		$this->assertSame( 'invalid_setting', $this->call( [ 'custom_logo' => $value ] )['error'] );
		$this->assertSame( '0', $this->icon() );
	}

	public function test_not_square_gives_the_actual_dimensions(): void {
		$r = $this->call( [ 'site_icon' => self::WIDE ] );
		$this->assertSame( 600, $r['width'] );
		$this->assertSame( 400, $r['height'] );
		$this->assertStringContainsString( '600x400', $r['message'] );
	}

	public function test_a_refused_type_lists_what_is_allowed(): void {
		$r = $this->call( [ 'site_icon' => self::SVG ] );
		$this->assertSame( 'image/svg+xml', $r['mime_type'] );
		$this->assertSame( [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ], $r['allowed'] );
	}

	public function test_needs_manage_options(): void {
		$this->as_user( self::EDITOR );
		$this->assertSame( 'iato_mcp_forbidden', $this->call( [ 'site_icon' => self::ICON ] )['error'] );
		$this->assertSame( '0', $this->icon() );
	}

	public function test_needs_edit_post_on_the_attachment_because_its_metadata_changes(): void {
		$this->as_user( self::OPTIONS_ONLY );
		$this->assertSame( 'iato_mcp_forbidden', $this->call( [ 'site_icon' => self::ICON ] )['error'] );
		$this->assertSame( '0', $this->icon() );
		$this->assertSame( [], $this->images->made );
	}

	public function test_schema_declares_both(): void {
		$schema = IATO_MCP_Server::get_catalog_entry( 'update_site_settings' )['definition'];
		$this->assertSame( 'integer', $schema['inputSchema']['properties']['site_icon']['type'] );
		$this->assertSame( 'integer', $schema['inputSchema']['properties']['custom_logo']['type'] );
		$this->assertFalse( $schema['inputSchema']['additionalProperties'] );
		foreach ( [ 'favicon', 'square', '512x512', '0 clears it' ] as $words ) {
			$this->assertStringContainsString( $words, $schema['description'] );
		}
	}

	// ── Setting the icon ──────────────────────────────────────────────────────

	public function test_a_valid_square_image_becomes_the_icon(): void {
		$r = $this->call( [ 'site_icon' => self::ICON ] );

		$this->assertSame( (string) self::ICON, (string) $this->icon() );
		$this->assertSame( [ 'option' => 'site_icon', 'before' => [ 'id' => 0, 'url' => null ], 'after' => [ 'id' => self::ICON, 'url' => 'https://example.test/40.png' ] ], $r['changes']['site_icon'] );
		$this->assertSame( array_keys( FakeSiteImage::SIZES ), $r['sizes_created'] );
		$this->assertSame( 'https://example.test/40-32.png', $r['icon_url_32'] );
		$this->assertSame( 'https://example.test/40-192.png', $r['icon_url_192'] );
		$this->assertSame( [], $r['warnings'], '512 is enough' );
		$this->assertSame( 'site_setting', $r['change_receipt']['target_type'] );
		$this->assertSame( 'site_icon', $r['change_receipt']['field'] );
		$this->assertSame( '0', $r['change_receipt']['before_value'] );
		$this->assertSame( (string) self::ICON, $r['change_receipt']['after_value'] );
		$this->assertTrue( IATO_MCP_Cache::has_pending(), 'the whole site is purged' );
	}

	public function test_only_missing_sizes_are_created(): void {
		$this->images->images[ self::ICON ]['sizes'] = [ 'thumbnail', 'site_icon-270', 'site_icon-32' ];
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		$this->assertSame( [ 'site_icon-192', 'site_icon-180', 'site_icon-64' ], $this->images->made );
		$this->assertSame( [ 'site_icon-192', 'site_icon-180', 'site_icon-64' ], $r['sizes_created'] );
	}

	public function test_under_512_is_written_with_a_warning_and_only_smaller_sizes(): void {
		$r = $this->call( [ 'site_icon' => self::SMALL ] );
		$this->assertSame( (string) self::SMALL, (string) $this->icon() );
		$this->assertCount( 1, $r['warnings'] );
		$this->assertStringContainsString( '100x100', $r['warnings'][0] );
		$this->assertSame( [ 'site_icon-64', 'site_icon-32' ], $this->images->made, 'a size as large as the image cannot be made' );
	}

	public function test_sizes_that_cannot_be_made_still_set_the_icon_and_warn(): void {
		$this->images->cannot_make = [ 'site_icon-32' ];
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		$this->assertSame( (string) self::ICON, (string) $this->icon() );
		$this->assertStringContainsString( 'site_icon-32', $r['warnings'][0] );
		$this->assertNotContains( 'site_icon-32', $r['sizes_created'] );
	}

	public function test_a_file_not_on_this_server_is_set_with_a_warning(): void {
		$r = $this->call( [ 'site_icon' => self::OFFLOADED ] );
		$this->assertSame( (string) self::OFFLOADED, (string) $this->icon() );
		$this->assertStringContainsString( 'not on this server', $r['warnings'][0] );
		$this->assertSame( [], $this->images->made );
	}

	public function test_zero_clears_and_leaves_the_attachment_alone(): void {
		IATO_Test_Abilities_State::$options['site_icon'] = (string) self::ICON;
		$r = $this->call( [ 'site_icon' => 0 ] );
		$this->assertSame( 0, (int) $this->icon() );
		$this->assertSame( [ 'id' => self::ICON, 'url' => 'https://example.test/40.png' ], $r['changes']['site_icon']['before'] );
		$this->assertSame( [ 'id' => 0, 'url' => null ], $r['changes']['site_icon']['after'] );
		$this->assertNotNull( get_post( self::ICON ) );
		$this->assertNull( $r['icon_url_32'] );
		$this->assertSame( '0', $r['change_receipt']['after_value'] );
	}

	public function test_dry_run_writes_nothing(): void {
		$r = $this->call( [ 'site_icon' => self::ICON, 'dry_run' => true ] );
		$this->assertTrue( $r['dry_run'] );
		$this->assertSame( self::ICON, $r['changes']['site_icon']['after']['id'] );
		$this->assertSame( array_keys( FakeSiteImage::SIZES ), $r['would_create_sizes'] );
		$this->assertNotEmpty( $r['not_checked'] );
		$this->assertSame( [], $r['warnings'] );
		$this->assertSame( '0', $this->icon() );
		$this->assertSame( [], $this->images->made );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts );
		$this->assertFalse( IATO_MCP_Cache::has_pending() );
		$this->assertArrayNotHasKey( 'change_receipt', $r );
	}

	public function test_dry_run_shows_the_warning_too(): void {
		$r = $this->call( [ 'site_icon' => self::SMALL, 'dry_run' => true ] );
		$this->assertStringContainsString( '100x100', $r['warnings'][0] );
		$this->assertSame( '0', $this->icon() );
	}

	public function test_the_same_id_writes_no_option_but_creates_missing_sizes(): void {
		IATO_Test_Abilities_State::$options['site_icon'] = (string) self::ICON; // as Elementor's Site Favicon leaves it
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		$this->assertEmpty( $r['changes'] );
		$this->assertArrayNotHasKey( 'change_receipt', $r );
		$this->assertSame( array_keys( FakeSiteImage::SIZES ), $r['sizes_created'] );
		$this->assertSame( 'https://example.test/40-32.png', $r['icon_url_32'] );
		$this->assertTrue( IATO_MCP_Cache::has_pending(), 'cached pages still link to the larger images' );

		IATO_MCP_Cache::begin();
		$this->images->made = [];
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		$this->assertArrayNotHasKey( 'sizes_created', $r, 'nothing left to do' );
		$this->assertSame( [], $this->images->made );
		$this->assertFalse( IATO_MCP_Cache::has_pending(), 'nothing changed, so nothing is purged' );
	}

	/** @return array<string,array{0:mixed}> */
	public static function stored_nones(): array {
		return [ 'zero' => [ '0' ], 'empty, as Elementor writes it' => [ '' ], 'missing' => [ null ] ];
	}

	#[DataProvider( 'stored_nones' )]
	public function test_no_icon_stored_any_way_is_no_icon( mixed $stored ): void {
		if ( null === $stored ) {
			unset( IATO_Test_Abilities_State::$options['site_icon'] );
		} else {
			IATO_Test_Abilities_State::$options['site_icon'] = $stored;
		}
		$r = $this->call( [ 'site_icon' => 0 ] );
		$this->assertEmpty( $r['changes'], 'clearing nothing is no change' );
		$this->assertArrayNotHasKey( 'change_receipt', $r );
		$this->assertNull( $this->settings()['site_icon'] );

		$r = $this->call( [ 'site_icon' => self::ICON ] );
		$this->assertSame( '0', $r['change_receipt']['before_value'], 'recorded as 0, which rollback can restore' );
	}

	// ── get_site_settings ─────────────────────────────────────────────────────

	public function test_get_site_settings_reports_id_and_url_or_null(): void {
		$this->assertNull( $this->settings()['site_icon'] );
		$this->assertNull( $this->settings()['custom_logo'] );

		$this->call( [ 'site_icon' => self::ICON, 'custom_logo' => self::WIDE ] );
		$this->assertSame( [ 'id' => self::ICON, 'url' => 'https://example.test/40-512.png' ], $this->settings()['site_icon'] );
		$this->assertSame( [ 'id' => self::WIDE, 'url' => 'https://example.test/43.png' ], $this->settings()['custom_logo'] );
		$this->assertSame( 'Old name', $this->settings()['title'], 'existing fields stay' );
	}

	public function test_an_icon_whose_image_has_gone_shows_a_null_url(): void {
		IATO_Test_Abilities_State::$options['site_icon'] = '999';
		$this->assertSame( [ 'id' => 999, 'url' => null ], $this->settings()['site_icon'] );
	}

	// ── Rollback ──────────────────────────────────────────────────────────────

	public function test_rollback_restores_the_previous_icon(): void {
		IATO_Test_Abilities_State::$options['site_icon'] = (string) self::OTHER_ICON;
		$r      = $this->call( [ 'site_icon' => self::ICON ] );
		$result = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'match', $result['conflict_check']['status'] );
		$this->assertSame( self::OTHER_ICON, (int) $this->icon() );
	}

	public function test_rollback_restores_zero(): void {
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( '0', (string) $this->icon(), 'back to no icon, never deleted' );
		$this->assertNull( $this->settings()['site_icon'] );
	}

	public function test_rollback_of_a_clear_puts_the_icon_back(): void {
		IATO_Test_Abilities_State::$options['site_icon'] = (string) self::ICON;
		$r = $this->call( [ 'site_icon' => 0 ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( self::ICON, (int) $this->icon() );
	}

	public function test_a_clear_followed_by_elementor_writing_empty_is_no_conflict(): void {
		IATO_Test_Abilities_State::$options['site_icon'] = (string) self::ICON;
		$r = $this->call( [ 'site_icon' => 0 ] );
		IATO_Test_Abilities_State::$options['site_icon'] = '';
		$result = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( $result['success'], 'still no icon, stored another way' );
		$this->assertSame( self::ICON, (int) $this->icon() );
	}

	public function test_a_newer_icon_blocks_the_rollback_unless_forced(): void {
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		IATO_Test_Abilities_State::$options['site_icon'] = (string) self::OTHER_ICON;

		$refused = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 'rollback_conflict', $refused->get_error_code() );
		$this->assertSame( self::OTHER_ICON, (int) $this->icon() );

		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'force' => true ] )['success'] );
		$this->assertSame( '0', (string) $this->icon() );
	}

	public function test_a_previous_icon_that_has_gone_cannot_be_restored_even_forced(): void {
		IATO_Test_Abilities_State::$options['site_icon'] = (string) self::OTHER_ICON;
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		unset( IATO_Test_Roles::$posts[ self::OTHER_ICON ], IATO_Test_WP::$posts[ self::OTHER_ICON ] );

		foreach ( [ [], [ 'force' => true ], [ 'dry_run' => true ] ] as $opts ) {
			$refused = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), $opts );
			$this->assertSame( 'rollback_unavailable', $refused->get_error_code(), wp_json_encode( $opts ) );
		}
		$this->assertSame( self::ICON, (int) $this->icon() );
	}

	public function test_a_rollback_purges_the_whole_site(): void {
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		IATO_MCP_Cache::begin();
		IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertTrue( IATO_MCP_Cache::has_pending() );
	}

	// ── Elementor's saved value ───────────────────────────────────────────────

	public function test_a_saved_favicon_is_brought_in_line_so_publishing_cannot_put_it_back(): void {
		$this->images->kit['site_favicon'] = self::OTHER_ICON;
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		$this->assertSame( self::ICON, $this->images->kit['site_favicon'] );
		$this->assertSame( [ 'site_favicon' ], $r['elementor_settings_synced'] );
	}

	public function test_nothing_saved_in_elementor_is_left_alone(): void {
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		$this->assertSame( 0, $this->images->kit_writes );
		$this->assertArrayNotHasKey( 'elementor_settings_synced', $r );
	}

	public function test_a_dry_run_reports_the_sync_and_does_not_do_it(): void {
		$this->images->kit['site_favicon'] = self::OTHER_ICON;
		$r = $this->call( [ 'site_icon' => self::ICON, 'dry_run' => true ] );
		$this->assertSame( [ 'site_favicon' ], $r['would_sync_elementor_settings'] );
		$this->assertSame( self::OTHER_ICON, $this->images->kit['site_favicon'] );
	}

	public function test_a_rollback_brings_it_back_in_line(): void {
		IATO_Test_Abilities_State::$options['site_icon'] = (string) self::OTHER_ICON;
		$this->images->kit['site_favicon']               = self::OTHER_ICON;
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( self::OTHER_ICON, $this->images->kit['site_favicon'] );
	}

	public function test_a_sync_that_fails_warns_and_the_icon_stays_set(): void {
		$this->images->kit['site_favicon'] = self::OTHER_ICON;
		$this->images->kit_write_fails     = true;
		$r = $this->call( [ 'site_icon' => self::ICON ] );
		$this->assertSame( self::ICON, (int) $this->icon() );
		$this->assertStringContainsString( 'Site Settings', $r['warnings'][0] );
	}

	// ── custom_logo ───────────────────────────────────────────────────────────

	public function test_a_logo_does_not_have_to_be_square_and_makes_no_sizes(): void {
		$r = $this->call( [ 'custom_logo' => self::WIDE ] );
		$this->assertSame( self::WIDE, $this->images->logo );
		$this->assertSame( [], $this->images->made );
		$this->assertSame( [], $r['warnings'] );
		$this->assertArrayNotHasKey( 'icon_url_32', $r );
		$this->assertSame( 'custom_logo', $r['change_receipt']['field'] );
		$this->assertSame( self::WIDE, $r['changes']['custom_logo']['after']['id'] );
		$this->assertSame( 0, $r['changes']['custom_logo']['before']['id'] );
	}

	public function test_a_logo_has_the_other_checks(): void {
		$this->assertSame( 'unsupported_image_type', $this->call( [ 'custom_logo' => self::SVG ] )['error'] );
		$this->assertSame( 'attachment_not_found', $this->call( [ 'custom_logo' => 999 ] )['error'] );
		$this->assertSame( 'attachment_in_trash', $this->call( [ 'custom_logo' => self::TRASHED ] )['error'] );
		$this->assertSame( 0, $this->images->logo );
	}

	public function test_rollback_restores_the_logo_including_none(): void {
		$r = $this->call( [ 'custom_logo' => self::WIDE ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( 0, $this->images->logo );

		$this->images->logo = self::ICON;
		$r = $this->call( [ 'custom_logo' => self::WIDE ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )['success'] );
		$this->assertSame( self::ICON, $this->images->logo );
	}

	public function test_a_logo_rollback_is_refused_after_a_theme_switch_even_forced(): void {
		$r = $this->call( [ 'custom_logo' => self::WIDE ] );
		$this->images->active_theme = 'another-theme';
		foreach ( [ [], [ 'force' => true ], [ 'dry_run' => true ] ] as $opts ) {
			$refused = IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), $opts );
			$this->assertSame( 'rollback_unavailable', $refused->get_error_code() );
			$this->assertStringContainsString( 'another-theme', $refused->get_error_message() );
		}
		$this->assertSame( self::WIDE, $this->images->logo );
	}

	public function test_a_newer_logo_blocks_the_rollback_unless_forced(): void {
		$r = $this->call( [ 'custom_logo' => self::WIDE ] );
		$this->images->logo = self::ICON;
		$this->assertSame( 'rollback_conflict', IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) )->get_error_code() );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ), [ 'force' => true ] )['success'] );
		$this->assertSame( 0, $this->images->logo );
	}

	public function test_a_saved_logo_in_elementor_follows_too(): void {
		$this->images->kit['site_logo'] = self::OTHER_ICON;
		$r = $this->call( [ 'custom_logo' => self::WIDE ] );
		$this->assertSame( self::WIDE, $this->images->kit['site_logo'] );
		$this->assertSame( [ 'site_logo' ], $r['elementor_settings_synced'] );
		IATO_MCP_Rollback::execute( self::row( $r['change_receipt'] ) );
		$this->assertSame( 0, $this->images->kit['site_logo'] );
	}

	public function test_both_in_one_call_have_a_receipt_each(): void {
		$r = $this->call( [ 'site_icon' => self::ICON, 'custom_logo' => self::WIDE ] );
		$this->assertCount( 2, $r['change_receipts'] );
		$this->assertEqualsCanonicalizing( [ 'site_icon', 'custom_logo' ], array_column( $r['change_receipts'], 'field' ) );
	}

	public function test_images_are_written_after_the_other_settings(): void {
		$r = $this->call( [ 'site_icon' => self::ICON, 'title' => 'New name', 'custom_logo' => self::WIDE ] );
		$this->assertSame( [ 'blogname', 'site_icon', 'custom_logo' ], array_column( $r['change_receipts'], 'field' ), 'a failure on the title must not leave an image written with a receipt no one sees' );
	}

	// ── Deleting the image in use: rollback of create_media ───────────────────

	private static function media_receipt( int $id ): array {
		return [ 'change_id' => 'wr_media', 'post_id' => $id, 'target_type' => 'attachment', 'field' => 'create', 'before_value' => null, 'after_value' => (string) $id, 'applied_at' => '2026-11-03 10:00:00' ];
	}

	public function test_rolling_back_the_upload_of_the_icon_in_use_needs_force_and_clears_it(): void {
		$this->call( [ 'site_icon' => self::ICON ] );
		$this->images->kit['site_favicon'] = self::ICON;

		$refused = IATO_MCP_Rollback::execute( self::media_receipt( self::ICON ) );
		$this->assertSame( 'rollback_conflict', $refused->get_error_code() );
		$this->assertStringContainsString( "the site's icon", $refused->get_error_message() );
		$this->assertStringContainsString( 'force', $refused->get_error_message() );
		$this->assertStringContainsString( 'site_icon', wp_json_encode( $refused->get_error_data() ) );
		$this->assertNotNull( get_post( self::ICON ), 'not deleted' );

		IATO_MCP_Cache::begin();
		$this->assertTrue( IATO_MCP_Rollback::execute( self::media_receipt( self::ICON ), [ 'force' => true ] )['success'] );
		$this->assertNull( get_post( self::ICON ) );
		$this->assertSame( 0, (int) $this->icon(), 'nothing left pointing at it' );
		$this->assertSame( 0, $this->images->kit['site_favicon'] );
		$this->assertTrue( IATO_MCP_Cache::has_pending() );
	}

	public function test_rolling_back_the_upload_of_the_logo_in_use_needs_force_and_clears_it(): void {
		$this->call( [ 'custom_logo' => self::WIDE ] );
		$this->assertSame( 'rollback_conflict', IATO_MCP_Rollback::execute( self::media_receipt( self::WIDE ) )->get_error_code() );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::media_receipt( self::WIDE ), [ 'force' => true ] )['success'] );
		$this->assertSame( 0, $this->images->logo );
	}

	public function test_rolling_back_an_upload_not_in_use_is_unaffected(): void {
		$this->call( [ 'site_icon' => self::ICON ] );
		$this->assertTrue( IATO_MCP_Rollback::execute( self::media_receipt( self::OTHER_ICON ), [ 'force' => true ] )['success'] );
		$this->assertSame( self::ICON, (int) $this->icon() );
	}
}
