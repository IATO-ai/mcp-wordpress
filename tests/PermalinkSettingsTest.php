<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';

/**
 * 1.20.0: the permalink settings in update_site_settings. The rules and the rewrite
 * bookkeeping here; what WordPress serves afterwards is checked by
 * tests/wp-env/restructure-live.py.
 */
final class PermalinkSettingsTest extends TestCase {

	private array $options = [];
	private array $filters = [];
	private mixed $rewrite = null;

	protected function setUp(): void {
		$this->options = IATO_Test_Abilities_State::$options;
		$this->filters = IATO_Test_Abilities_State::$filters;
		$this->rewrite = $GLOBALS['wp_rewrite'] ?? null;
		IATO_Test_Abilities_State::$options['permalink_structure'] = '/%postname%/';
		IATO_Test_Abilities_State::$options['category_base']       = '';
		IATO_Test_Abilities_State::$options['tag_base']            = '';
	}

	protected function tearDown(): void {
		IATO_Test_Abilities_State::$options = $this->options;
		IATO_Test_Abilities_State::$filters = $this->filters;
		$GLOBALS['wp_rewrite']               = $this->rewrite;
	}

	/** @return array<string,array{0:mixed,1:string}> */
	public static function bad_structures(): array {
		return [
			'plain'              => [ '', 'plain_permalinks_not_supported' ],
			'not a string'       => [ 7, 'invalid_permalink_setting' ],
			'no leading slash'   => [ 'blog/%postname%/', 'invalid_permalink_setting' ],
			'no trailing slash'  => [ '/blog/%postname%', 'invalid_permalink_setting' ],
			'no post name or id' => [ '/%year%/%monthnum%/', 'invalid_permalink_setting' ],
			'an unknown tag'     => [ '/%postname%/%foo%/', 'invalid_permalink_setting' ],
			'tag base as a tag'  => [ '/%tag%/%postname%/', 'invalid_permalink_setting' ],
			'capitals'           => [ '/Blog/%postname%/', 'invalid_permalink_setting' ],
			'a double slash'     => [ '/blog//%postname%/', 'invalid_permalink_setting' ],
			'index.php'          => [ '/index.php/%postname%/', 'invalid_permalink_setting' ],
			'a dot'              => [ '/blog.x/%postname%/', 'invalid_permalink_setting' ],
			'a lone percent'     => [ '/50%/%postname%/', 'invalid_permalink_setting' ],
			'a query'            => [ '/%postname%/?x=1/', 'invalid_permalink_setting' ],
		];
	}

	#[DataProvider( 'bad_structures' )]
	public function test_a_structure_wordpress_could_not_use_is_refused( mixed $raw, string $code ): void {
		$r = IATO_MCP_Permalinks::check_structure( $raw );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( $code, $r->get_error_code() );
		$this->assertStringContainsString( 'Nothing was written', $r->get_error_message() );
	}

	public function test_the_usual_structures_are_taken_as_sent(): void {
		foreach ( [ '/%postname%/', '/blog/%postname%/', '/%year%/%monthnum%/%day%/%postname%/', '/archives/%post_id%/', '/%category%/%postname%/', '/news/%author%/%post_id%-%postname%/' ] as $structure ) {
			$this->assertSame( $structure, IATO_MCP_Permalinks::check_structure( $structure ), $structure );
		}
	}

	public function test_a_base_is_stored_as_wp_admin_stores_it(): void {
		$this->assertSame( '', IATO_MCP_Permalinks::check_base( 'category_base', '' ) );
		$this->assertSame( '', IATO_MCP_Permalinks::check_base( 'category_base', '/' ) );
		$this->assertSame( '/topics', IATO_MCP_Permalinks::check_base( 'category_base', 'topics' ) );
		$this->assertSame( '/topics', IATO_MCP_Permalinks::check_base( 'category_base', '/topics/' ) );
		$this->assertSame( '/blog/topics', IATO_MCP_Permalinks::check_base( 'tag_base', 'blog/topics' ) );
		foreach ( [ 'Topics', 'two words', 'a//b', 'x.y', 5 ] as $bad ) {
			$this->assertInstanceOf( WP_Error::class, IATO_MCP_Permalinks::check_base( 'tag_base', $bad ), var_export( $bad, true ) );
		}
	}

	public function test_nothing_named_is_no_plan(): void {
		$this->assertNull( IATO_MCP_Permalinks::plan( [ 'title' => 'x', 'confirm_url_break' => true ] ) );
	}

	public function test_the_same_settings_are_no_change(): void {
		IATO_Test_Abilities_State::$options['category_base'] = 'topics'; // stored without its slash by another plugin
		$plan = IATO_MCP_Permalinks::plan( [ 'permalink_structure' => '/%postname%/', 'category_base' => '/topics/' ] );
		$this->assertFalse( $plan['changes'] );
	}

	public function test_a_change_keeps_what_was_not_named(): void {
		IATO_Test_Abilities_State::$options['tag_base'] = '/labels';
		$plan = IATO_MCP_Permalinks::plan( [ 'permalink_structure' => '/blog/%postname%/' ] );
		$this->assertTrue( $plan['changes'] );
		$this->assertSame( [ 'permalink_structure' => '/blog/%postname%/', 'category_base' => '', 'tag_base' => '/labels' ], $plan['after'] );
		$this->assertSame( '/%postname%/', $plan['before']['permalink_structure'] );
	}

	public function test_plain_or_index_permalinks_now_are_left_to_wp_admin(): void {
		foreach ( [ '', '/index.php/%postname%/' ] as $current ) {
			IATO_Test_Abilities_State::$options['permalink_structure'] = $current;
			$r = IATO_MCP_Permalinks::plan( [ 'permalink_structure' => '/blog/%postname%/' ] );
			$this->assertSame( 'permalinks_not_pretty', $r->get_error_code(), $current );
		}
	}

	public function test_category_and_tag_archives_cannot_share_a_base(): void {
		$this->assertSame( 'invalid_permalink_setting', IATO_MCP_Permalinks::plan( [ 'category_base' => 'topics', 'tag_base' => 'topics' ] )->get_error_code() );
		$this->assertSame( 'invalid_permalink_setting', IATO_MCP_Permalinks::plan( [ 'tag_base' => 'category' ] )->get_error_code(), 'the default counts' );
	}

	public function test_the_front_is_what_wordpress_calls_it(): void {
		$this->assertSame( '/', IATO_MCP_Permalinks::front( '/%postname%/' ) );
		$this->assertSame( '/blog/', IATO_MCP_Permalinks::front( '/blog/%postname%/' ) );
		$this->assertSame( '/archives/', IATO_MCP_Permalinks::front( '/archives/%post_id%/' ) );
	}

	/** The extra permastructs as WordPress registers them under /%postname%/. */
	private static function rewrite(): object {
		return new class() {
			public array $extra_permastructs = [
				'category'    => [ 'with_front' => true, 'struct' => '/category/%category%' ],
				'post_tag'    => [ 'with_front' => true, 'struct' => '/tag/%post_tag%' ],
				'post_format' => [ 'with_front' => true, 'struct' => '/type/%post_format%' ],
				'project'     => [ 'with_front' => true, 'struct' => '/work/%project%' ],
				'product'     => [ 'with_front' => false, 'struct' => '/product/%product%' ],
			];
			public int $inits = 0;
			public function init(): void {
				$this->inits++;
			}
		};
	}

	public function test_archives_move_under_a_new_front(): void {
		$r = self::rewrite();
		IATO_MCP_Permalinks::rebase(
			$r,
			[ 'permalink_structure' => '/%postname%/', 'category_base' => '', 'tag_base' => '' ],
			[ 'permalink_structure' => '/blog/%postname%/', 'category_base' => '', 'tag_base' => '' ]
		);
		$this->assertSame(
			[ '/blog/category/%category%', '/blog/tag/%post_tag%', '/blog/type/%post_format%', '/blog/work/%project%', '/product/%product%' ],
			array_column( $r->extra_permastructs, 'struct' ),
			'without its front, a custom type stays where it is'
		);
	}

	public function test_a_base_takes_categories_out_from_under_the_front(): void {
		$r = self::rewrite();
		IATO_MCP_Permalinks::rebase(
			$r,
			[ 'permalink_structure' => '/%postname%/', 'category_base' => '', 'tag_base' => '' ],
			[ 'permalink_structure' => '/blog/%postname%/', 'category_base' => '/topics', 'tag_base' => '' ]
		);
		$this->assertSame( '/topics/%category%', $r->extra_permastructs['category']['struct'] );
		$this->assertFalse( $r->extra_permastructs['category']['with_front'], 'as create_initial_taxonomies() registers a base' );
		$this->assertSame( '/blog/tag/%post_tag%', $r->extra_permastructs['post_tag']['struct'] );

		// And back to the default, under the front again.
		IATO_MCP_Permalinks::rebase(
			$r,
			[ 'permalink_structure' => '/blog/%postname%/', 'category_base' => '/topics', 'tag_base' => '' ],
			[ 'permalink_structure' => '/%postname%/', 'category_base' => '', 'tag_base' => '' ]
		);
		$this->assertSame( '/category/%category%', $r->extra_permastructs['category']['struct'] );
		$this->assertTrue( $r->extra_permastructs['category']['with_front'] );
		$this->assertSame( '/work/%project%', $r->extra_permastructs['project']['struct'] );
	}

	public function test_a_simulation_puts_the_rewrite_state_back_whatever_happens(): void {
		$real                  = self::rewrite();
		$GLOBALS['wp_rewrite'] = $real;
		$before                = [ 'permalink_structure' => '/%postname%/', 'category_base' => '', 'tag_base' => '' ];
		$after                 = [ 'permalink_structure' => '/blog/%postname%/', 'category_base' => '', 'tag_base' => '' ];

		$this->assertSame( [], IATO_MCP_Permalinks::simulate( $before, $after, [ 'posts' => [], 'terms' => [], 'authors' => [] ] ) );
		$this->assertSame( $real, $GLOBALS['wp_rewrite'], 'the same object, not a copy' );
		$this->assertSame( '/category/%category%', $real->extra_permastructs['category']['struct'], 'the copy was changed, not the real one' );
		$this->assertSame( 0, $real->inits );
		foreach ( IATO_MCP_Permalinks::KEYS as $key ) {
			$this->assertSame( [], IATO_Test_Abilities_State::$filters[ 'pre_option_' . $key ] ?? [], "pre_option_{$key} removed" );
		}

		$failing = new class() {
			public array $extra_permastructs = [];
			public function init(): void {
				throw new RuntimeException( 'boom' );
			}
		};
		$GLOBALS['wp_rewrite'] = $failing;
		try {
			IATO_MCP_Permalinks::simulate( $before, $after, [ 'posts' => [], 'terms' => [], 'authors' => [] ] );
			$this->fail( 'the exception comes through' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( $failing, $GLOBALS['wp_rewrite'] );
			$this->assertSame( [], IATO_Test_Abilities_State::$filters['pre_option_permalink_structure'] ?? [] );
		}
	}

	public function test_rules_are_emptied_and_this_request_cannot_store_a_rebuild(): void {
		IATO_Test_Abilities_State::$options['rewrite_rules'] = [ 'old/?$' => 'index.php' ];
		IATO_MCP_Permalinks::forget_rules();
		IATO_MCP_Permalinks::forget_rules();
		$this->assertSame( '', IATO_Test_Abilities_State::$options['rewrite_rules'] );
		$hooked = array_filter( IATO_Test_Abilities_State::$filters['pre_update_option_rewrite_rules'] ?? [], static fn( $cb ) => [ IATO_MCP_Permalinks::class, 'keep_rules_unstored' ] === $cb );
		$this->assertLessThanOrEqual( 1, count( $hooked ), 'added once per request' );
		$this->assertSame( '', IATO_MCP_Permalinks::keep_rules_unstored( [ 'new/?$' => 'index.php' ], '' ), 'what update_option() would store stays as it was' );
	}

	public function test_changes_are_the_urls_that_differ(): void {
		$changes = IATO_MCP_Permalinks::changes(
			[ 'post:1' => 'https://x.test/a/', 'term:2' => 'https://x.test/category/c/', 'author:3' => 'https://x.test/author/u/', 'post:4' => 'https://x.test/d/' ],
			[ 'post:1' => 'https://x.test/blog/a/', 'term:2' => 'https://x.test/blog/category/c/', 'author:3' => 'https://x.test/author/u/' ]
		);
		$this->assertSame(
			[
				[ 'type' => 'post', 'id' => 1, 'status' => 'publish', 'old_url' => 'https://x.test/a/', 'new_url' => 'https://x.test/blog/a/' ],
				[ 'type' => 'term', 'id' => 2, 'status' => 'publish', 'old_url' => 'https://x.test/category/c/', 'new_url' => 'https://x.test/blog/category/c/' ],
			],
			$changes
		);
	}

	public function test_the_conflict_check_compares_the_settings(): void {
		$receipt = [ 'after_value' => wp_json_encode( [ 'permalink_structure' => '/blog/%postname%/', 'category_base' => '', 'tag_base' => '', 'redirects' => [] ] ) ];
		IATO_Test_Abilities_State::$options['permalink_structure'] = '/blog/%postname%/';
		$this->assertSame( 'match', IATO_MCP_Permalinks::conflict( $receipt )['status'] );
		IATO_Test_Abilities_State::$options['tag_base'] = '/labels';
		$check = IATO_MCP_Permalinks::conflict( $receipt );
		$this->assertSame( 'conflict', $check['status'] );
		$this->assertSame( '/labels', $check['current']['tag_base'] );
		$this->assertSame( 'unverifiable', IATO_MCP_Permalinks::conflict( [ 'after_value' => '{}' ] )['status'] );
	}

	public function test_a_receipt_without_settings_cannot_be_rolled_back(): void {
		$this->assertSame( 'rollback_unavailable', IATO_MCP_Permalinks::rollback_unavailable( [ 'before_value' => '{"permalink_structure":"/%postname%/"}' ] )->get_error_code() );
		$this->assertNull( IATO_MCP_Permalinks::rollback_unavailable( [ 'before_value' => '{"permalink_structure":"/%postname%/","category_base":"","tag_base":""}' ] ) );
	}

	public function test_permalink_receipts_are_administrators_and_purge_the_site(): void {
		$this->assertSame( 'manage_options', IATO_MCP_Change_Receipt::cap_required_for( [ 'target_type' => 'permalinks', 'field' => 'structure' ] ) );
	}
}
