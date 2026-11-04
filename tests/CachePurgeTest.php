<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-elementor.php';

/**
 * IATO_MCP_Cache (1.15.0): which write purges what, each cache plugin's calls,
 * and what the `cache` object says.
 */
final class CachePurgeTest extends TestCase {

	private const POST = 501, FRONT = 502, BLOG = 503, LIBRARY = 504, ATTACHMENT = 505, PARENT = 506;

	/** @var string[] the plugins this test treats as installed */
	private array $present = [];

	protected function setUp(): void {
		IATO_Test_Cache_State::reset();
		IATO_Test_Abilities_State::$filters = [];
		IATO_Test_Abilities_State::$actions = [];
		unset( IATO_Test_Abilities_State::$options[ IATO_MCP_Cache::OPTION ] );
		IATO_Test_Abilities_State::$options['show_on_front']  = 'page';
		IATO_Test_Abilities_State::$options['page_on_front']  = self::FRONT;
		IATO_Test_Abilities_State::$options['page_for_posts'] = self::BLOG;
		IATO_Test_OAuth_State::$transients = [];
		IATO_Test_Cache_State::$post_types = [ self::POST => 'page', self::FRONT => 'page', self::BLOG => 'page', self::LIBRARY => 'elementor_library', self::ATTACHMENT => 'attachment', self::PARENT => 'page' ];
		IATO_Test_WP::$posts = [ self::ATTACHMENT => [ 'post_parent' => self::PARENT ], self::POST => [ 'post_parent' => 0 ] ];
		IATO_Test_WP::$meta  = [];
		\Elementor\FakeFilesManager::$cleared = 0;
		\Elementor\Core\Files\CSS\Post::$mode    = 'ok';
		\Elementor\Core\Files\CSS\Post::$status  = 'empty';
		\Elementor\Core\Files\CSS\Post::$updated = [];
		$this->present = [ 'litespeed' ];
		IATO_MCP_Cache::set_detector( fn( string $slug, bool $real ) => in_array( $slug, $this->present, true ) );
		IATO_MCP_Cache::begin();
	}

	protected function tearDown(): void {
		IATO_MCP_Cache::set_detector( null );
		IATO_MCP_Cache::begin();
		IATO_Test_Abilities_State::$filters = [];
		IATO_Test_Abilities_State::$actions = [];
	}

	/** Note a write, run the end of the tool call, return the `cache` object. */
	private function write( string $type, ?int $post_id, string $field = 'x', mixed $before = 'a', mixed $after = 'b' ): array {
		IATO_MCP_Cache::note_write( $type, $post_id, $field, $before, $after );
		return $this->finish();
	}

	private function finish(): array {
		$r = IATO_MCP_Cache::finish( 'update_post', [], IATO_MCP_Server::ok( [ 'id' => 1 ] ) );
		return json_decode( $r['content'][0]['text'], true )['cache'];
	}

	// ── Each plugin's calls ───────────────────────────────────────────────

	/** @return array<string,array{0:string,1:string[],2:string[],3:string[]}> slug, post calls, full calls, front-page extra */
	public static function plugins(): array {
		return [
			'litespeed'        => [ 'litespeed', [ 'litespeed_purge_post:501' ], [ 'litespeed_purge_all:IATO MCP' ], [ 'litespeed_purge_url:https://site.example/' ] ],
			'wp-rocket'        => [ 'wp-rocket', [ 'rocket_clean_post:501' ], [ 'rocket_clean_domain' ], [ 'rocket_clean_home' ] ],
			'w3-total-cache'   => [ 'w3-total-cache', [ 'w3tc_flush_post:501' ], [ 'w3tc_flush_all' ], [ 'w3tc_flush_url:https://site.example/' ] ],
			'wp-super-cache'   => [ 'wp-super-cache', [ 'wp_cache_post_change:501' ], [ 'wp_cache_clear_cache' ], [] ],
			'wp-fastest-cache' => [ 'wp-fastest-cache', [ 'wpfc_clear_post_cache_by_id:501' ], [ 'wpfc_clear_all_cache' ], [ 'wpfc_clear_cache_by_url:https://site.example/' ] ],
			'sg-optimizer'     => [ 'sg-optimizer', [ 'sg_cachepress_purge_cache:https://site.example/p/501/' ], [ 'sg_cachepress_purge_everything' ], [ 'sg_cachepress_purge_cache:https://site.example/' ] ],
		];
	}

	#[DataProvider( 'plugins' )]
	public function test_each_plugin_gets_its_own_calls_and_no_other( string $slug, array $post, array $all, array $home ): void {
		IATO_Test_OAuth_State::$home_url = 'https://site.example';
		IATO_Test_WP::$permalinks[ self::POST ]  = 'https://site.example/p/501/';
		IATO_Test_WP::$permalinks[ self::FRONT ] = 'https://site.example/';
		$this->present = [ $slug ];

		$this->assertSame( [ $slug ], IATO_MCP_Cache::detected() );

		$this->assertSame( [ $slug => true ], IATO_MCP_Cache::purge( 'post', self::POST ) );
		$this->assertSame( $post, IATO_Test_Cache_State::$calls );

		IATO_Test_Cache_State::$calls = [];
		$this->assertSame( [ $slug => true ], IATO_MCP_Cache::purge( 'all' ) );
		$this->assertSame( $all, IATO_Test_Cache_State::$calls );

		IATO_Test_Cache_State::$calls = [];
		IATO_MCP_Cache::purge( 'post', self::FRONT );
		$this->assertSame( $home, array_slice( IATO_Test_Cache_State::$calls, 1 ), 'the front page also purges the home URL' );
	}

	public function test_a_plugin_that_is_not_there_is_not_called(): void {
		$this->present = [];
		$this->assertSame( [], IATO_MCP_Cache::detected() );
		$this->assertSame( [], IATO_MCP_Cache::purge( 'all' ) );
		$this->assertSame( [], IATO_Test_Cache_State::$calls );
	}

	public function test_the_posts_page_purges_home_but_an_ordinary_page_does_not(): void {
		IATO_MCP_Cache::purge( 'post', self::BLOG );
		$this->assertContains( 'litespeed_purge_url:' . home_url( '/' ), IATO_Test_Cache_State::$calls );

		IATO_Test_Cache_State::$calls = [];
		IATO_MCP_Cache::purge( 'post', self::POST );
		$this->assertSame( [ 'litespeed_purge_post:501' ], IATO_Test_Cache_State::$calls );

		IATO_Test_Abilities_State::$options['show_on_front'] = 'posts';
		IATO_Test_Cache_State::$calls = [];
		IATO_MCP_Cache::purge( 'post', self::FRONT );
		$this->assertSame( [ 'litespeed_purge_post:502' ], IATO_Test_Cache_State::$calls, 'a stale page_on_front is not the home page' );
	}

	// ── Scope ─────────────────────────────────────────────────────────────

	/** @return array<string,array{0:string,1:?int,2:string,3:mixed,4:string}> */
	public static function scopes(): array {
		return [
			'post field'             => [ 'post', self::POST, 'title', 'a', 'post' ],
			'seo on a page'          => [ 'page', self::POST, 'title', 'a', 'post' ],
			'post meta'              => [ 'post_meta', self::POST, '_thumbnail_id', '1', 'post' ],
			'elementor write'        => [ 'elementor_widget', self::POST, 'elementor:document:replace', 'a', 'post' ],
			'atomic write'           => [ 'elementor_atomic', self::POST, 'elementor:a1:settings', 'a', 'post' ],
			'new post'               => [ 'post', self::POST, 'create', null, 'post' ],
			'menu item'              => [ 'menu_item', self::POST, 'details', 'a', 'all' ],
			'term'                   => [ 'taxonomy', null, 'update_term', 'a', 'all' ],
			'assign term'            => [ 'taxonomy', self::POST, 'assign', 'a', 'all' ],
			'redirect'               => [ 'redirect', null, 'rule', 'a', 'all' ],
			'site setting'           => [ 'site_setting', null, 'blogname', 'a', 'all' ],
			'kit or template'        => [ 'elementor_widget', self::LIBRARY, 'elementor:document:replace', 'a', 'all' ],
			'kit page settings'      => [ 'post_meta', self::LIBRARY, '_elementor_page_settings', 'a', 'all' ],
			'slug change'            => [ 'post', self::POST, 'slug', 'old', 'all' ],
			'unpublish'              => [ 'post', self::POST, 'status', 'publish', 'all' ],
			'publish'                => [ 'post', self::POST, 'status', 'draft', 'post' ],
			'unknown type no id'     => [ 'something_new', null, 'x', 'a', 'all' ],
		];
	}

	#[DataProvider( 'scopes' )]
	public function test_scope_of_each_kind_of_write( string $type, ?int $id, string $field, mixed $before, string $scope ): void {
		$this->assertSame( $scope, $this->write( $type, $id, $field, $before, 'new' )['scope'] );
	}

	public function test_an_unchanged_value_does_not_purge(): void {
		IATO_MCP_Cache::note_write( 'taxonomy', self::POST, 'assign', [ 1, 2 ], [ 1, 2 ] );
		IATO_MCP_Cache::note_write( 'redirect', null, 'rule', 'same', 'same' );
		IATO_MCP_Cache::note_write( 'post_meta', self::POST, 'k', 5, '5' );
		$this->assertFalse( IATO_MCP_Cache::has_pending() );
	}

	public function test_alt_text_purges_the_parent_page_and_says_other_pages_stay_cached(): void {
		$cache = $this->write( 'image', self::ATTACHMENT, 'alt_text' );
		$this->assertSame( 'post', $cache['scope'] );
		$this->assertSame( [ 'litespeed_purge_post:506' ], array_slice( IATO_Test_Cache_State::$calls, 0, 1 ) );
		$this->assertStringContainsString( 'Other pages that use this image', implode( ' ', $cache['notes'] ) );

		IATO_Test_Cache_State::$calls = [];
		$this->assertSame( 'post', $this->write( 'attachment', self::POST, 'create', null, 'x' )['scope'] );
		$this->assertSame( [ 'litespeed_purge_post:501' ], IATO_Test_Cache_State::$calls, 'no parent: the attachment itself' );
	}

	public function test_a_routed_alt_also_purges_the_page_that_asked(): void {
		IATO_MCP_Cache::note_write( 'image', self::ATTACHMENT, 'alt_text', 'a', 'b' );
		IATO_MCP_Cache::note_post( self::POST );
		$cache = $this->finish();
		$this->assertSame( [ self::PARENT, self::POST ], $cache['post_ids'] );
	}

	public function test_several_posts_purge_once_each_and_a_full_purge_wins(): void {
		IATO_MCP_Cache::note_write( 'post', self::POST, 'title', 'a', 'b' );
		IATO_MCP_Cache::note_write( 'post', self::POST, 'content', 'a', 'b' );
		IATO_MCP_Cache::note_write( 'post', self::PARENT, 'title', 'a', 'b' );
		$cache = $this->finish();
		$this->assertSame( 'post', $cache['scope'] );
		$this->assertSame( [ self::POST, self::PARENT ], $cache['post_ids'] );
		$this->assertSame( [ 'litespeed_purge_post:501', 'litespeed_purge_post:506' ], IATO_Test_Cache_State::$calls );

		IATO_Test_Cache_State::$calls = [];
		IATO_MCP_Cache::note_write( 'post', self::POST, 'title', 'a', 'b' );
		IATO_MCP_Cache::note_write( 'redirect', null, 'rule', 'a', 'b' );
		$cache = $this->finish();
		$this->assertSame( 'all', $cache['scope'] );
		$this->assertArrayNotHasKey( 'post_ids', $cache );
		$this->assertSame( [ 'litespeed_purge_all:IATO MCP' ], IATO_Test_Cache_State::$calls );
	}

	public function test_rollback_scope_reads_the_receipt(): void {
		IATO_MCP_Cache::note_rollback( [ 'target_type' => 'post', 'field' => 'title', 'post_id' => self::POST, 'before_value' => 'a', 'after_value' => 'b' ] );
		$this->assertSame( 'post', $this->finish()['scope'] );

		foreach ( [
			[ 'target_type' => 'post', 'field' => 'create', 'post_id' => self::POST ],
			[ 'target_type' => 'post', 'field' => 'status', 'post_id' => self::POST, 'before_value' => 'draft', 'after_value' => 'publish' ],
			[ 'target_type' => 'site_setting', 'field' => 'blogname', 'post_id' => null ],
			[ 'target_type' => 'menu_item', 'field' => 'details', 'post_id' => self::POST ],
			[ 'target_type' => 'elementor_widget', 'field' => 'x', 'post_id' => self::LIBRARY ],
		] as $receipt ) {
			IATO_MCP_Cache::note_rollback( $receipt );
			$this->assertSame( 'all', $this->finish()['scope'], wp_json_encode( $receipt ) );
		}
	}

	// ── Elementor CSS ─────────────────────────────────────────────────────

	public function test_a_page_write_rebuilds_that_post_and_never_clears_every_page(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] = 'builder';
		IATO_MCP_Elementor_Adapter::clear_post_css( self::POST );
		$cache = $this->write( 'elementor_widget', self::POST );
		$this->assertSame( 'post', $cache['scope'] );
		$this->assertSame( 0, \Elementor\FakeFilesManager::$cleared );
		$this->assertSame( [ 'post_css_update:501', 'litespeed_purge_post:501' ], IATO_Test_Cache_State::$calls, 'CSS first, then the page cache' );
	}

	public function test_a_post_is_rebuilt_once_however_many_writes_touched_it(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] = 'builder';
		IATO_MCP_Elementor_Adapter::clear_post_css( self::POST );
		IATO_MCP_Elementor_Adapter::clear_post_css( self::POST );
		$this->finish();
		$this->assertSame( [ self::POST ], \Elementor\Core\Files\CSS\Post::$updated );
	}

	public function test_a_post_elementor_does_not_build_is_not_rebuilt(): void {
		IATO_MCP_Elementor_Adapter::clear_post_css( self::POST );
		$this->finish();
		$this->assertSame( [], \Elementor\Core\Files\CSS\Post::$updated );
	}

	public function test_no_rebuild_when_the_caller_says_elementor_should_do_it_later(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] = 'builder';
		IATO_MCP_Elementor_Adapter::clear_post_css( self::POST, false );
		$cache = $this->finish();
		$this->assertSame( [], \Elementor\Core\Files\CSS\Post::$updated );
		$this->assertSame( 'post', $cache['scope'], 'still purged' );
	}

	public function test_a_library_write_clears_all_css_before_the_full_purge(): void {
		$cache = $this->write( 'elementor_widget', self::LIBRARY );
		$this->assertSame( 'all', $cache['scope'] );
		$this->assertSame( [ 'elementor_clear_cache', 'litespeed_purge_all:IATO MCP' ], IATO_Test_Cache_State::$calls );
	}

	public function test_a_block_template_purges_all_but_leaves_elementor_css_alone(): void {
		IATO_Test_Cache_State::$post_types[ self::POST ] = 'wp_template';
		$this->assertSame( 'all', $this->write( 'post', self::POST, 'content' )['scope'] );
		$this->assertSame( 0, \Elementor\FakeFilesManager::$cleared );
	}

	public function test_a_type_with_no_public_page_counts_as_site_wide(): void {
		IATO_Test_Cache_State::$not_viewable = [ 'astra-advanced-hook' ];
		IATO_Test_Cache_State::$post_types[ self::POST ] = 'astra-advanced-hook';
		$this->assertSame( 'all', $this->write( 'post', self::POST, 'content' )['scope'] );
		$this->assertSame( 0, \Elementor\FakeFilesManager::$cleared, 'not built with Elementor' );

		IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] = 'builder';
		$this->assertSame( 'all', $this->write( 'post', self::POST, 'content' )['scope'] );
		$this->assertSame( 1, \Elementor\FakeFilesManager::$cleared, 'built with Elementor: other pages show its CSS' );
	}

	public function test_a_write_that_says_not_to_rebuild_wins(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] = 'builder';
		IATO_MCP_Elementor_Adapter::clear_post_css( self::POST, false ); // the pipeline: elements it could not load
		IATO_MCP_Elementor_Adapter::clear_post_css( self::POST );        // inherit, in the same call
		$this->finish();
		$this->assertSame( [], \Elementor\Core\Files\CSS\Post::$updated );
	}

	public function test_a_page_holding_elements_elementor_cannot_load_is_not_rebuilt(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] = 'builder';
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data']      = json_encode( [ [ 'id' => 'a', 'elType' => 'widget', 'widgetType' => 'gone', 'settings' => [], 'elements' => [] ] ] );
		IATO_MCP_Elementor_Adapter::set_type_checker( fn( string $el, string $widget ) => 'gone' !== $widget );
		try {
			IATO_MCP_Elementor_Adapter::clear_post_css( self::POST ); // a meta write or a rollback on that page
			$cache = $this->finish();
		} finally {
			IATO_MCP_Elementor_Adapter::set_type_checker( null );
		}
		$this->assertSame( [], \Elementor\Core\Files\CSS\Post::$updated );
		$this->assertSame( 'post', $cache['scope'], 'still purged' );
	}

	public function test_menus_and_settings_purge_pages_but_leave_css_alone(): void {
		foreach ( [ [ 'menu_item', self::POST ], [ 'taxonomy', null ], [ 'redirect', null ], [ 'site_setting', null ] ] as [ $type, $id ] ) {
			$this->assertSame( 'all', $this->write( $type, $id )['scope'] );
		}
		$this->assertSame( 0, \Elementor\FakeFilesManager::$cleared );
	}

	public function test_an_unreliable_per_post_clear_falls_back_to_the_global_clear(): void {
		\Elementor\Core\Files\CSS\Post::$mode = 'throw_delete';
		IATO_MCP_Elementor_Adapter::clear_post_css( self::POST );
		$cache = $this->finish();
		$this->assertSame( 'all', $cache['scope'] );
		$this->assertSame( 1, \Elementor\FakeFilesManager::$cleared );
		$this->assertStringContainsString( 'all Elementor CSS was cleared', implode( ' ', $cache['notes'] ) );
	}

	public function test_a_failed_rebuild_is_only_a_note(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] = 'builder';
		\Elementor\Core\Files\CSS\Post::$mode = 'throw_update';
		IATO_MCP_Elementor_Adapter::clear_post_css( self::POST );
		$cache = $this->finish();
		$this->assertSame( 'post', $cache['scope'] );
		$this->assertTrue( $cache['purged'] );
		$this->assertSame( 0, \Elementor\FakeFilesManager::$cleared );
		$this->assertStringContainsString( 'rebuilds it on the next page view', implode( ' ', $cache['notes'] ) );
	}

	// ── purged, and the opt-outs ──────────────────────────────────────────

	public function test_purged_is_true_when_all_ran_null_when_none_found(): void {
		$this->present = [ 'litespeed', 'wp-rocket' ];
		$cache = $this->write( 'post', self::POST );
		$this->assertTrue( $cache['purged'], 'WP Rocket returning false is not a failure' );
		$this->assertSame( [ 'litespeed', 'wp-rocket' ], $cache['detected'] );

		$this->present = [];
		$cache = $this->write( 'post', self::POST );
		$this->assertNull( $cache['purged'] );
		$this->assertSame( [], $cache['detected'] );
	}

	public function test_a_plugin_that_throws_fails_nothing_else(): void {
		$this->present = [ 'litespeed', 'wp-rocket', 'w3-total-cache' ];
		IATO_Test_Cache_State::$throw = [ 'rocket_clean_post' ];
		$cache = $this->write( 'post', self::POST );
		$this->assertFalse( $cache['purged'] );
		$this->assertContains( 'w3tc_flush_post:501', IATO_Test_Cache_State::$calls, 'the next plugin still runs' );
		$this->assertStringContainsString( 'wp-rocket could not be purged', implode( ' ', $cache['notes'] ) );
	}

	public function test_setting_off_skips_page_caches_but_not_elementor_css(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Cache::OPTION ] = '0';
		$fired = [];
		IATO_Test_Abilities_State::$actions['iato_mcp_after_cache_purge'][] = function () use ( &$fired ) { $fired[] = 1; };

		$cache = $this->write( 'elementor_widget', self::LIBRARY );
		$this->assertFalse( $cache['purged'] );
		$this->assertSame( 'all', $cache['scope'] );
		$this->assertSame( [ 'elementor_clear_cache' ], IATO_Test_Cache_State::$calls );
		$this->assertStringContainsString( 'switched off', implode( ' ', $cache['notes'] ) );
		$this->assertSame( [], $fired );

		$this->present = [];
		$this->assertNull( $this->write( 'post', self::POST )['purged'], 'nothing to purge either way' );
	}

	public function test_the_filter_can_skip_a_purge_and_sees_its_arguments(): void {
		$seen = [];
		add_filter( 'iato_mcp_cache_purge', function ( bool $purge, string $scope, ?int $id ) use ( &$seen ) {
			$seen[] = [ $purge, $scope, $id ];
			return false;
		}, 10, 3 );
		$cache = $this->write( 'post', self::POST );
		$this->assertSame( [ [ true, 'post', self::POST ] ], $seen );
		$this->assertSame( [], IATO_Test_Cache_State::$calls );
		$this->assertFalse( $cache['purged'] );
		$this->assertStringContainsString( 'iato_mcp_cache_purge', implode( ' ', $cache['notes'] ) );
	}

	public function test_the_action_runs_after_each_purge_even_with_no_plugin(): void {
		$this->present = [];
		$seen = [];
		IATO_Test_Abilities_State::$actions['iato_mcp_after_cache_purge'][] = function ( string $scope, ?int $id, array $results ) use ( &$seen ) { $seen[] = [ $scope, $id, $results ]; };
		$this->write( 'post', self::POST );
		$this->write( 'redirect', null );
		$this->assertSame( [ [ 'post', self::POST, [] ], [ 'all', null, [] ] ], $seen );
	}

	public function test_nothing_pending_is_not_a_purge(): void {
		$this->assertNull( IATO_MCP_Cache::flush() );
		$this->assertSame( [], IATO_Test_Cache_State::$calls );
	}

	// ── Caches that cannot be purged ──────────────────────────────────────

	/** @return array<string,array{0:array,1:string,2:string}> */
	public static function edge_headers(): array {
		return [
			'cloudflare status' => [ [ 'cf-cache-status' => 'HIT' ], 'litespeed', 'Cloudflare' ],
			'cloudflare ray'    => [ [ 'CF-Ray' => 'abc' ], 'litespeed', 'Cloudflare' ],
			'hostinger cdn'     => [ [ 'x-hcdn-cache-status' => 'MISS' ], 'litespeed', 'Hostinger' ],
			'hcdn server'       => [ [ 'server' => 'hcdn' ], 'litespeed', 'Hostinger' ],
			'litespeed server'  => [ [ 'x-litespeed-cache' => 'hit' ], 'wp-rocket', 'LiteSpeed server cache' ],
		];
	}

	#[DataProvider( 'edge_headers' )]
	public function test_a_cache_seen_on_the_loopback_becomes_a_note( array $headers, string $plugin, string $expect ): void {
		$this->present = [ $plugin ];
		IATO_Test_Cache_State::$head_response = [ 'headers' => $headers ];
		$cache = $this->write( 'post', self::POST );
		$this->assertStringContainsString( $expect, implode( ' ', $cache['notes'] ) );
		$this->assertTrue( $cache['purged'], 'a note never changes purged' );
	}

	public function test_litespeed_header_needs_no_note_when_the_plugin_purges_it(): void {
		IATO_Test_Cache_State::$head_response = [ 'headers' => [ 'x-litespeed-cache' => 'hit' ] ];
		$this->assertSame( [], $this->write( 'post', self::POST )['notes'] );
	}

	public function test_hostinger_platform_variable_is_enough(): void {
		$_SERVER['H_PLATFORM'] = 'Hostinger';
		try {
			$this->assertStringContainsString( 'hPanel', implode( ' ', $this->write( 'post', self::POST )['notes'] ) );
		} finally {
			unset( $_SERVER['H_PLATFORM'] );
		}
	}

	public function test_the_loopback_runs_once_and_is_cached_failures_included(): void {
		$this->write( 'post', self::POST );
		$this->write( 'post', self::POST );
		$this->assertSame( 1, IATO_Test_Cache_State::$head_requests );

		IATO_Test_OAuth_State::$transients    = [];
		IATO_Test_Cache_State::$head_requests = 0;
		IATO_Test_Cache_State::$head_response = new WP_Error( 'http_request_failed', 'timed out' );
		$this->assertSame( [], $this->write( 'post', self::POST )['notes'], 'a failed probe adds nothing' );
		$this->write( 'post', self::POST );
		$this->assertSame( 1, IATO_Test_Cache_State::$head_requests );
		$this->assertSame( IATO_MCP_Cache::EDGE_TTL, 43200 );
	}

	public function test_no_loopback_on_a_read_or_a_no_op(): void {
		$info = IATO_MCP_Cache::site_info();
		$this->assertSame( [ 'litespeed' ], $info['detected'] );
		$this->assertSame( 0, IATO_Test_Cache_State::$head_requests );

		$r = IATO_MCP_Cache::finish( 'update_post', [], IATO_MCP_Server::ok( [ 'id' => 1 ] ) );
		$this->assertNull( json_decode( $r['content'][0]['text'], true )['cache']['purged'] );
		$this->assertSame( 0, IATO_Test_Cache_State::$head_requests );
	}

	public function test_site_info_reads_the_notes_a_write_already_found(): void {
		IATO_Test_Cache_State::$head_response = [ 'headers' => [ 'cf-ray' => 'x' ] ];
		$this->write( 'post', self::POST );
		$this->assertStringContainsString( 'Cloudflare', implode( ' ', IATO_MCP_Cache::site_info()['notes'] ) );
	}

	public function test_enabled_reads_the_stored_flag(): void {
		$this->assertTrue( IATO_MCP_Cache::is_enabled(), 'missing means on' );
		foreach ( [ '0' => false, '1' => true, '' => false, 'off' => false ] as $value => $expect ) {
			IATO_Test_Abilities_State::$options[ IATO_MCP_Cache::OPTION ] = (string) $value;
			$this->assertSame( $expect, IATO_MCP_Cache::is_enabled(), (string) $value );
		}
	}
}
