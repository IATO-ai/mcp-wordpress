<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-actor.php';
require_once __DIR__ . '/stubs-elementor.php';
require_once __DIR__ . '/stubs-pro.php';
require_once __DIR__ . '/stubs-router.php';

require_once IATO_MCP_DIR . 'includes/class-elementor-router.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-resolve-url.php';

/**
 * resolve_url and Theme Builder headers and footers (1.17.0). A header's page is
 * every page: it must not be reported as what renders them, or an assistant asked
 * to edit a page edits the site header. Which header a URL gets is its own field,
 * theme_locations, read from Elementor Pro's own conditions cache.
 */
final class ElementorRouterTest extends TestCase {

	private const PAGE = 10, FRONT = 11, BLOG_PAGE = 12, A_POST = 13;
	private const ADMIN = 1, READER = 6;
	public static int $shop_page = -1;
	private const HOME_URL = 'http://site.test';
	private string $saved_home = '';

	protected function setUp(): void {
		IATO_Test_Router::reset();
		$this->saved_home = IATO_Test_OAuth_State::$home_url;
		IATO_Test_OAuth_State::$home_url = self::HOME_URL;
		IATO_Test_Cache_State::$not_viewable = [ 'elementor_library' ];
		IATO_Test_Pro::reset();
		IATO_Test_WP::$meta  = [];
		IATO_Test_WP::$posts = [
			self::PAGE      => [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About' ],
			self::FRONT     => [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home' ],
			self::BLOG_PAGE => [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Blog' ],
			self::A_POST    => [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'A post' ],
		];
		IATO_Test_Roles::$posts = array_map( static fn( array $post ): array => $post + [ 'post_author' => self::ADMIN, 'post_password' => '' ], IATO_Test_WP::$posts );
		IATO_Test_WP::$permalinks[ self::PAGE ] = self::HOME_URL . '/about/';
		IATO_Test_Router::$url_ids = [ self::HOME_URL . '/about/' => self::PAGE, self::HOME_URL . '/a-post/' => self::A_POST, self::HOME_URL . '/blog/' => self::BLOG_PAGE ];
		IATO_Test_Actor::$slugs['category'] = [ 'news' => 7 ];
		IATO_Test_Abilities_State::$options['show_on_front']  = 'posts';
		IATO_Test_Abilities_State::$options['page_on_front']  = '0';
		IATO_Test_Abilities_State::$options['page_for_posts'] = '0';
		self::forget_resolved();
	}

	protected function tearDown(): void {
		IATO_Test_OAuth_State::$home_url = $this->saved_home;
		IATO_Test_Cache_State::$not_viewable = [];
		IATO_Test_Pro::reset();
		IATO_Test_WP::$meta  = [];
		IATO_Test_WP::$posts = [];
		IATO_Test_Roles::$posts = [];
		IATO_Test_Roles::$users = [];
		IATO_Test_Actor::$slugs = [];
		IATO_Test_Router::reset();
		\Elementor\Plugin::$instance->kits_manager = null;
		foreach ( [ 'show_on_front', 'page_on_front', 'page_for_posts' ] as $option ) {
			unset( IATO_Test_Abilities_State::$options[ $option ] );
		}
		self::forget_resolved();
	}

	private static function forget_resolved(): void {
		( new ReflectionProperty( IATO_MCP_Elementor_Router::class, 'cache' ) )->setValue( null, [] );
	}

	/** A published template in the library, with conditions in its meta. */
	private static function template( int $id, string $type, array $conditions, string $status = 'publish' ): void {
		IATO_Test_WP::$posts[ $id ] = [ 'post_type' => 'elementor_library', 'post_status' => $status, 'post_title' => "{$type} {$id}" ];
		IATO_Test_Roles::$posts[ $id ] = IATO_Test_WP::$posts[ $id ] + [ 'post_author' => self::ADMIN, 'post_password' => '' ];
		IATO_Test_WP::$meta[ $id ] = [ '_elementor_conditions' => $conditions, '_elementor_template_type' => $type ];
	}

	/** Pro active, with these templates in its conditions cache. */
	private static function in_pro_cache( string $location, array $templates ): void {
		IATO_Test_Pro::$active = true;
		IATO_Test_Pro::$cache[ $location ] = $templates;
	}

	private static function resolve( string $path ): array {
		return IATO_MCP_Elementor_Router::resolve_url( self::HOME_URL . $path );
	}

	// ── What counts as shadowing a page ───────────────────────────────────

	/** @return array<string,array{0:string}> */
	public static function non_content_types(): array {
		return [ 'header' => [ 'header' ], 'footer' => [ 'footer' ], 'popup' => [ 'popup' ], 'section' => [ 'section' ], 'container' => [ 'container' ], 'loop item' => [ 'loop-item' ] ];
	}

	#[DataProvider( 'non_content_types' )]
	public function test_a_site_wide_template_of_another_kind_does_not_shadow_a_page( string $type ): void {
		self::template( 100, $type, [ 'include/general' ] );
		$r = self::resolve( '/about/' );
		$this->assertNull( $r['rendering_template_id'], 'the template is not what renders this page' );
		$this->assertSame( self::PAGE, $r['effective_render_id'] );
		$this->assertSame( 'page', $r['effective_render_post_type'] );
		$this->assertSame( 'page', $r['route_type'] );
		$this->assertNull( $r['shadowed_post_id'] );
		$this->assertArrayNotHasKey( 'template', $r );
	}

	public function test_get_post_shadowing_does_not_blame_a_site_wide_header(): void {
		self::template( 100, 'header', [ 'include/general' ] );
		$this->assertNull( IATO_MCP_Elementor_Router::get_shadowing_for_post( self::PAGE ) );
	}

	/** @return array<string,array{0:string,1:string,2:string}> */
	public static function content_cases(): array {
		return [
			'single page' => [ 'single-page', 'include/singular/page', '/about/' ],
			'single page by ID' => [ 'single-page', 'include/singular/page/10', '/about/' ],
			'single post' => [ 'single-post', 'include/singular/post', '/a-post/' ],
			'legacy single' => [ 'single', 'include/singular', '/about/' ],
			'archive' => [ 'archive', 'include/archive/category/7', '/category/news/' ],
		];
	}

	#[DataProvider( 'content_cases' )]
	public function test_a_content_template_still_shadows( string $type, string $condition, string $path ): void {
		self::template( 100, $type, [ $condition ] );
		$r = self::resolve( $path );
		$this->assertSame( 100, $r['rendering_template_id'] );
		$this->assertSame( 100, $r['effective_render_id'] );
		$this->assertSame( 'elementor_library', $r['effective_render_post_type'] );
		$this->assertSame( 'theme_builder', $r['route_type'] );
		$this->assertSame( [ 'template_id' => 100, 'template_type' => $type, 'condition_matched' => $condition, 'builder' => 'elementor' ], $r['template'] );
	}

	/**
	 * What shadows a URL follows Pro's own conditions. The blog archive used to match
	 * any URL that was not a recognised archive, so it took the blame for ordinary
	 * pages, and the front page and author archive conditions matched nothing.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public static function shadowing_against_urls(): array {
		return [
			'blog archive on the blog home'   => [ 'include/archive/post_archive', '/', true ],
			'blog archive on a page'          => [ 'include/archive/post_archive', '/about/', false ],
			'blog archive on a post'          => [ 'include/archive/post_archive', '/a-post/', false ],
			'blog archive on a not-found URL' => [ 'include/archive/post_archive', '/no-such-page/', false ],
			'blog archive on a search'        => [ 'include/archive/post_archive', '/?s=x', false ],
			'blog archive on a date archive'  => [ 'include/archive/post_archive', '/2026/11/', false ],
			'blog archive on a category'      => [ 'include/archive/post_archive', '/category/news/', false ],
			'any archive on the blog home'    => [ 'include/archive', '/', true ],
			'any archive on a search'         => [ 'include/archive', '/?s=x', true ],
			'any archive on a date archive'   => [ 'include/archive', '/2026/11/', true ],
			'any archive on a category'       => [ 'include/archive', '/category/news/', true ],
			'any archive on a page'           => [ 'include/archive', '/about/', false ],
			'any archive on a not-found URL'  => [ 'include/archive', '/no-such-page/', false ],
			'search on a search'              => [ 'include/archive/search', '/?s=x', true ],
			'search on a page'                => [ 'include/archive/search', '/about/', false ],
			'date on a date archive'          => [ 'include/archive/date', '/2026/11/', true ],
			'date on a page'                  => [ 'include/archive/date', '/about/', false ],
			'author archive on an author'     => [ 'include/archive/author', '/author/admin/', true ],
			'author archive on a page'        => [ 'include/archive/author', '/about/', false ],
		];
	}

	#[DataProvider( 'shadowing_against_urls' )]
	public function test_what_shadows_a_url_follows_pros_conditions( string $condition, string $path, bool $shadows ): void {
		IATO_Test_Router::$authors = [ 'admin' => 1 ];
		self::template( 100, 'archive', [ $condition ] );
		$this->assertSame( $shadows ? 100 : null, self::resolve( $path )['rendering_template_id'], "{$condition} on {$path}" );
	}

	public function test_a_blog_archive_template_does_not_take_the_blame_for_a_page(): void {
		self::template( 100, 'archive', [ 'include/archive/post_archive' ] );
		$this->assertNull( IATO_MCP_Elementor_Router::get_shadowing_for_post( self::PAGE ) );
		$r = self::resolve( '/about/' );
		$this->assertSame( self::PAGE, $r['effective_render_id'] );
		$this->assertSame( 'page', $r['route_type'] );
	}

	public function test_a_blog_archive_template_shadows_a_posts_page(): void {
		IATO_Test_Abilities_State::$options['show_on_front']  = 'page';
		IATO_Test_Abilities_State::$options['page_for_posts'] = (string) self::BLOG_PAGE;
		self::template( 100, 'archive', [ 'include/archive/post_archive' ] );
		$this->assertSame( 100, self::resolve( '/blog/' )['rendering_template_id'] );
		$this->assertNull( self::resolve( '/about/' )['rendering_template_id'] );
	}

	public function test_a_front_page_template_shadows_the_front_page_alone(): void {
		IATO_Test_Abilities_State::$options['show_on_front'] = 'page';
		IATO_Test_Abilities_State::$options['page_on_front'] = (string) self::FRONT;
		IATO_Test_Router::$url_ids[ self::HOME_URL . '/' ] = self::FRONT;
		self::template( 100, 'single-page', [ 'include/singular/front_page' ] );
		$front = self::resolve( '/' );
		$this->assertSame( 100, $front['rendering_template_id'] );
		$this->assertSame( self::FRONT, $front['shadowed_post_id'] );
		$this->assertNull( self::resolve( '/about/' )['rendering_template_id'] );
	}

	public function test_a_front_page_condition_means_nothing_when_the_home_page_lists_posts(): void {
		self::template( 100, 'single-page', [ 'include/singular/front_page' ] );
		$this->assertNull( self::resolve( '/' )['rendering_template_id'] );
	}

	public function test_excluding_the_front_page_from_a_content_template(): void {
		IATO_Test_Abilities_State::$options['show_on_front'] = 'page';
		IATO_Test_Abilities_State::$options['page_on_front'] = (string) self::FRONT;
		IATO_Test_Router::$url_ids[ self::HOME_URL . '/' ] = self::FRONT;
		self::template( 100, 'single-page', [ 'include/singular/page', 'exclude/singular/front_page' ] );
		$this->assertNull( self::resolve( '/' )['rendering_template_id'] );
		$this->assertSame( 100, self::resolve( '/about/' )['rendering_template_id'] );
	}

	public function test_a_specific_template_still_beats_a_general_one(): void {
		IATO_Test_Abilities_State::$options['show_on_front'] = 'page';
		IATO_Test_Abilities_State::$options['page_on_front'] = (string) self::FRONT;
		IATO_Test_Router::$url_ids[ self::HOME_URL . '/' ] = self::FRONT;
		self::template( 100, 'single-page', [ 'include/singular/page' ] );
		self::template( 101, 'single-page', [ 'include/singular/front_page' ] );
		$this->assertSame( 101, self::resolve( '/' )['rendering_template_id'] );
		$this->assertSame( 100, self::resolve( '/about/' )['rendering_template_id'] );
	}

	public function test_an_author_condition_is_read_as_an_author_not_a_taxonomy(): void {
		foreach ( [ 'include/archive/author', 'include/archive/by_author' ] as $condition ) {
			$parsed = IATO_MCP_Elementor_Router::parse_condition_string( $condition . '/1' );
			$this->assertSame( 1, $parsed['target_id'], $condition );
			$this->assertSame( IATO_MCP_Elementor_Router::parse_condition_string( 'include/archive/by_author/1' )['target_label'], $parsed['target_label'], $condition );
		}
	}

	public function test_a_site_wide_header_does_not_hide_the_template_that_does_shadow(): void {
		self::template( 100, 'header', [ 'include/singular/page/10' ] );
		self::template( 101, 'single-page', [ 'include/singular/page' ] );
		$this->assertSame( 101, self::resolve( '/about/' )['rendering_template_id'] );
		$this->assertSame( [ 'type' => 'elementor_theme_builder', 'shadowing_id' => 101, 'reason' => 'Theme Builder template matches condition: include/singular/page' ], IATO_MCP_Elementor_Router::get_shadowing_for_post( self::PAGE ) );
	}

	public function test_a_template_with_no_recorded_type_counts_as_it_did(): void {
		IATO_Test_WP::$posts[ 100 ] = [ 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Old' ];
		IATO_Test_WP::$meta[ 100 ]  = [ '_elementor_conditions' => [ 'include/singular/page' ] ];
		$r = self::resolve( '/about/' );
		$this->assertSame( 100, $r['rendering_template_id'] );
		$this->assertSame( 'single', $r['template']['template_type'] );
	}

	public function test_pro_is_only_asked_about_content_locations_for_shadowing(): void {
		IATO_Test_Pro::$active = true;
		self::resolve( '/about/' );
		$this->assertSame( [ 'single', 'archive' ], IATO_Test_Pro::$asked );
	}

	public function test_a_document_pro_reports_for_a_content_location_keeps_its_shape(): void {
		IATO_Test_Pro::$active = true;
		IATO_Test_Pro::$documents['single'] = [ new class { public function get_main_id(): int { return 200; } } ];
		$r = self::resolve( '/about/' );
		$this->assertSame( 200, $r['rendering_template_id'] );
		$this->assertSame( [ 'template_id' => 200, 'template_type' => 'single', 'condition_matched' => null, 'builder' => 'elementor' ], $r['template'] );
	}

	// ── The fields ────────────────────────────────────────────────────────

	public function test_existing_fields_keep_their_shape(): void {
		$r = self::resolve( '/about/' );
		$this->assertSame(
			[
				'url'                        => self::HOME_URL . '/about/',
				'rendering_post_id'          => self::PAGE,
				'rendering_post_type'        => 'page',
				'rendering_template_id'      => null,
				'effective_render_id'        => self::PAGE,
				'effective_render_post_type' => 'page',
				'shadowed_post_id'           => null,
				'shadowed_route_type'        => null,
				'route_type'                 => 'page',
			],
			array_diff_key( $r, [ 'theme_locations' => true ] )
		);
	}

	// ── theme_locations ───────────────────────────────────────────────────

	public function test_without_pro_nothing_can_be_said_about_headers(): void {
		self::template( 100, 'header', [ 'include/general' ] );
		$r = self::resolve( '/about/' );
		$this->assertArrayHasKey( 'theme_locations', $r );
		$this->assertNull( $r['theme_locations'] );
	}

	public function test_with_pro_and_no_templates_both_locations_are_empty(): void {
		IATO_Test_Pro::$active = true;
		$this->assertSame( [ 'header' => null, 'footer' => null ], self::resolve( '/about/' )['theme_locations'] );
	}

	/** @return array<string,array{0:string}> */
	public static function every_kind_of_url(): array {
		return [ 'page' => [ '/about/' ], 'post' => [ '/a-post/' ], 'archive' => [ '/category/news/' ], 'home' => [ '/' ], 'not found' => [ '/no-such-page/' ] ];
	}

	#[DataProvider( 'every_kind_of_url' )]
	public function test_an_entire_site_header_and_footer_apply_everywhere( string $path ): void {
		self::template( 100, 'header', [ 'include/general' ] );
		self::template( 101, 'footer', [ 'include/general' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/general' ] ] );
		self::in_pro_cache( 'footer', [ 101 => [ 'include/general' ] ] );
		$this->assertSame(
			[
				'header' => [ 'template_id' => 100, 'condition_matched' => 'include/general' ],
				'footer' => [ 'template_id' => 101, 'condition_matched' => 'include/general' ],
			],
			self::resolve( $path )['theme_locations']
		);
	}

	public function test_the_location_is_answered_even_when_a_content_template_shadows_the_page(): void {
		self::template( 100, 'header', [ 'include/general' ] );
		self::template( 101, 'single-page', [ 'include/singular/page' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/general' ] ] );
		$r = self::resolve( '/about/' );
		$this->assertSame( 101, $r['rendering_template_id'] );
		$this->assertSame( 100, $r['theme_locations']['header']['template_id'] );
	}

	public function test_what_is_not_in_pros_cache_is_not_reported_because_it_will_not_render(): void {
		self::template( 100, 'header', [ 'include/general' ] );
		IATO_Test_Pro::$active = true;
		$this->assertNull( self::resolve( '/about/' )['theme_locations']['header'] );
	}

	public function test_a_draft_in_the_cache_does_not_render(): void {
		// Conditions_Cache::regenerate() includes drafts; get_location_templates() skips them.
		self::template( 100, 'header', [ 'include/general' ], 'draft' );
		self::in_pro_cache( 'header', [ 100 => [ 'include/general' ] ] );
		$this->assertNull( self::resolve( '/about/' )['theme_locations']['header'] );
	}

	public function test_a_template_in_the_trash_does_not_render(): void {
		self::template( 100, 'header', [ 'include/general' ], 'trash' );
		self::in_pro_cache( 'header', [ 100 => [ 'include/general' ] ] );
		$this->assertNull( self::resolve( '/about/' )['theme_locations']['header'] );
	}

	public function test_an_exclude_removes_the_template_for_that_url_only(): void {
		$conditions = [ 'include/general', 'exclude/singular/page/10' ];
		self::template( 100, 'header', $conditions );
		self::in_pro_cache( 'header', [ 100 => $conditions ] );
		$this->assertNull( self::resolve( '/about/' )['theme_locations']['header'] );
		$this->assertSame( 100, self::resolve( '/a-post/' )['theme_locations']['header']['template_id'] );
	}

	public function test_excluding_the_front_page(): void {
		IATO_Test_Abilities_State::$options['show_on_front'] = 'page';
		IATO_Test_Abilities_State::$options['page_on_front'] = (string) self::FRONT;
		IATO_Test_Router::$url_ids[ self::HOME_URL . '/' ] = self::FRONT;
		$conditions = [ 'include/general', 'exclude/singular/front_page' ];
		self::template( 100, 'header', $conditions );
		self::in_pro_cache( 'header', [ 100 => $conditions ] );
		$this->assertNull( self::resolve( '/' )['theme_locations']['header'] );
		$this->assertSame( 100, self::resolve( '/about/' )['theme_locations']['header']['template_id'] );
	}

	public function test_the_most_specific_condition_wins(): void {
		self::template( 100, 'header', [ 'include/general' ] );
		self::template( 101, 'header', [ 'include/singular/page' ] );
		self::template( 102, 'header', [ 'include/singular/page/10' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/general' ], 101 => [ 'include/singular/page' ], 102 => [ 'include/singular/page/10' ] ] );
		$this->assertSame( 102, self::resolve( '/about/' )['theme_locations']['header']['template_id'] );
		self::forget_resolved();
		IATO_Test_Pro::$cache['header'] = [ 100 => [ 'include/general' ], 101 => [ 'include/singular/page' ] ];
		$this->assertSame( 101, self::resolve( '/about/' )['theme_locations']['header']['template_id'] );
	}

	public function test_a_tie_goes_to_the_first_in_pros_cache_as_on_the_page(): void {
		self::template( 100, 'header', [ 'include/general' ] );
		self::template( 101, 'header', [ 'include/general' ] );
		self::in_pro_cache( 'header', [ 101 => [ 'include/general' ], 100 => [ 'include/general' ] ] );
		$this->assertSame( 101, self::resolve( '/about/' )['theme_locations']['header']['template_id'] );
	}

	public function test_the_last_matching_include_decides_a_templates_priority_as_in_pro(): void {
		$both = [ 'include/singular/page/10', 'include/general' ];
		self::template( 100, 'header', $both );
		self::template( 101, 'header', [ 'include/singular/page' ] );
		self::in_pro_cache( 'header', [ 100 => $both, 101 => [ 'include/singular/page' ] ] );
		$this->assertSame( 101, self::resolve( '/about/' )['theme_locations']['header']['template_id'] );
	}

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function conditions_against_urls(): array {
		return [
			'singular on a page'             => [ 'include/singular', '/about/', true ],
			'singular on a not-found URL'    => [ 'include/singular', '/no-such-page/', true ],
			'singular on an archive'         => [ 'include/singular', '/category/news/', false ],
			'singular on the blog home'      => [ 'include/singular', '/', false ],
			'page type on a page'            => [ 'include/singular/page', '/about/', true ],
			'page type on a post'            => [ 'include/singular/page', '/a-post/', false ],
			'page by ID, the right one'      => [ 'include/singular/page/10', '/about/', true ],
			'page by ID, another'            => [ 'include/singular/page/99', '/about/', false ],
			'not found on a not-found URL'   => [ 'include/singular/not_found404', '/no-such-page/', true ],
			'not found on a page'            => [ 'include/singular/not_found404', '/about/', false ],
			'archive on an archive'          => [ 'include/archive', '/category/news/', true ],
			'archive on the blog home'       => [ 'include/archive', '/', true ],
			'archive on a page'              => [ 'include/archive', '/about/', false ],
			'blog archive on the blog home'  => [ 'include/archive/post_archive', '/', true ],
			'blog archive on a page'         => [ 'include/archive/post_archive', '/about/', false ],
			'blog archive on a not-found URL' => [ 'include/archive/post_archive', '/no-such-page/', false ],
			'category, any'                  => [ 'include/archive/category', '/category/news/', true ],
			'category by ID, the right one'  => [ 'include/archive/category/7', '/category/news/', true ],
			'category by ID, another'        => [ 'include/archive/category/8', '/category/news/', false ],
			'category on a page'             => [ 'include/archive/category', '/about/', false ],
			'date archive'                   => [ 'include/archive/date', '/2026/11/', true ],
			'date archive on a page'         => [ 'include/archive/date', '/about/', false ],
			'search'                         => [ 'include/archive/search', '/?s=word', true ],
			'search on a page'               => [ 'include/archive/search', '/about/', false ],
		];
	}

	#[DataProvider( 'conditions_against_urls' )]
	public function test_a_condition_against_a_url( string $condition, string $path, bool $applies ): void {
		self::template( 100, 'header', [ $condition ] );
		self::in_pro_cache( 'header', [ 100 => [ $condition ] ] );
		$locations = self::resolve( $path )['theme_locations'];
		$this->assertSame( $applies ? 100 : null, $locations['header']['template_id'] ?? null );
		$this->assertArrayNotHasKey( 'not_evaluated', $locations );
	}

	public function test_a_posts_page_is_the_blog_not_a_page(): void {
		IATO_Test_Abilities_State::$options['show_on_front']  = 'page';
		IATO_Test_Abilities_State::$options['page_for_posts'] = (string) self::BLOG_PAGE;
		foreach ( [ [ 'include/archive/post_archive', true ], [ 'include/singular/page', false ] ] as [ $condition, $applies ] ) {
			self::forget_resolved();
			self::template( 100, 'header', [ $condition ] );
			self::in_pro_cache( 'header', [ 100 => [ $condition ] ] );
			$this->assertSame( $applies ? 100 : null, self::resolve( '/blog/' )['theme_locations']['header']['template_id'] ?? null, $condition );
		}
	}

	public function test_a_condition_this_plugin_cannot_evaluate_is_named_not_guessed(): void {
		self::template( 100, 'header', [ 'include/singular/child_of/5' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/singular/child_of/5' ] ] );
		$locations = self::resolve( '/about/' )['theme_locations'];
		$this->assertNull( $locations['header'] );
		$this->assertSame( [ [ 'location' => 'header', 'template_id' => 100, 'condition' => 'include/singular/child_of/5' ] ], $locations['not_evaluated'] );
	}

	public function test_an_exclude_this_plugin_cannot_evaluate_leaves_the_template_and_is_named(): void {
		$conditions = [ 'include/general', 'exclude/singular/in_category/3' ];
		self::template( 100, 'header', $conditions );
		self::in_pro_cache( 'header', [ 100 => $conditions ] );
		$locations = self::resolve( '/a-post/' )['theme_locations'];
		$this->assertSame( 100, $locations['header']['template_id'] );
		$this->assertSame( 'exclude/singular/in_category/3', $locations['not_evaluated'][0]['condition'] );
	}

	public function test_a_condition_on_the_wrong_kind_of_url_needs_no_evaluating(): void {
		// child_of is a singular condition, so it cannot apply to an archive.
		self::template( 100, 'header', [ 'include/singular/child_of/5' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/singular/child_of/5' ] ] );
		$this->assertSame( [ 'header' => null, 'footer' => null ], self::resolve( '/category/news/' )['theme_locations'] );
	}

	// ── What WordPress makes of a URL ─────────────────────────────────────

	private function location_on( string $path, string $condition ): array {
		self::forget_resolved();
		self::template( 100, 'header', [ $condition ] );
		self::in_pro_cache( 'header', [ 100 => [ $condition ] ] );
		return self::resolve( $path );
	}

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function common_urls(): array {
		return [
			'the blog, page 2, is the blog'             => [ '/page/2/', 'include/archive/post_archive', true ],
			'the blog, page 2, is not singular'         => [ '/page/2/', 'include/singular', false ],
			'the blog, page 2, is not a 404'            => [ '/page/2/', 'include/singular/not_found404', false ],
			'a category, page 2, by ID'                 => [ '/category/news/page/2/', 'include/archive/category/7', true ],
			'a category, page 2, is an archive'         => [ '/category/news/page/2/', 'include/archive', true ],
			'a category, page 2, is not singular'       => [ '/category/news/page/2/', 'include/singular', false ],
			'a pretty search URL'                       => [ '/search/shoes/', 'include/archive/search', true ],
			'a pretty search URL is an archive'         => [ '/search/shoes/', 'include/archive', true ],
			'a pretty search URL is not a 404'          => [ '/search/shoes/', 'include/singular/not_found404', false ],
			'a custom taxonomy'                         => [ '/genre/rock/', 'include/archive/genre', true ],
			'a custom taxonomy, by ID'                  => [ '/genre/rock/', 'include/archive/genre/31', true ],
			'a custom taxonomy is an archive'           => [ '/genre/rock/', 'include/archive', true ],
			'a custom taxonomy is not a 404'            => [ '/genre/rock/', 'include/singular', false ],
			'a term that does not exist is a 404'       => [ '/category/no-such-term/', 'include/singular/not_found404', true ],
			'a term that does not exist is no archive'  => [ '/category/no-such-term/', 'include/archive', false ],
			'an author that does not exist is a 404'    => [ '/author/nobody/', 'include/singular/not_found404', true ],
			'an author that does not exist is no archive' => [ '/author/nobody/', 'include/archive', false ],
			'an author that exists'                     => [ '/author/jane/', 'include/archive/author', true ],
			'an author by ID'                           => [ '/author/jane/', 'include/archive/author/5', true ],
			'a post type archive'                       => [ '/books/', 'include/archive/book_archive', true ],
			'a page that does not exist'                => [ '/no-such-page/', 'include/singular/not_found404', true ],
		];
	}

	#[DataProvider( 'common_urls' )]
	public function test_a_common_url_is_what_wordpress_says( string $path, string $condition, bool $applies ): void {
		IATO_Test_Actor::$slugs['genre'] = [ 'rock' => 31 ];
		IATO_Test_Router::$authors = [ 'jane' => 5 ];
		$r = $this->location_on( $path, $condition );
		$this->assertSame( $applies ? 100 : null, $r['theme_locations']['header']['template_id'] ?? null );
		$this->assertArrayNotHasKey( 'not_evaluated', $r['theme_locations'] );
	}

	public function test_plain_permalinks_read_the_query_string_on_a_static_front_page(): void {
		// url_to_postid() drops the query and answers with the static front page.
		IATO_Test_Abilities_State::$options['show_on_front'] = 'page';
		IATO_Test_Abilities_State::$options['page_on_front'] = (string) self::FRONT;
		IATO_Test_Router::$plain_permalinks = true;
		$r = $this->location_on( '/?cat=7', 'include/archive/category/7' );
		$this->assertSame( 100, $r['theme_locations']['header']['template_id'] ?? null );
		$this->assertNull( $r['rendering_post_id'], 'the front page is not what this URL shows' );
		$this->assertSame( 'category_archive', $r['route_type'] );
		$this->assertNull( $this->location_on( '/?cat=7', 'include/singular/front_page' )['theme_locations']['header'] );
		$this->assertNull( $this->location_on( '/?cat=7', 'include/singular/page' )['theme_locations']['header'] );
		$this->assertSame( 100, $this->location_on( '/?page_id=10', 'include/singular/page/10' )['theme_locations']['header']['template_id'] ?? null );
		$this->assertSame( 100, $this->location_on( '/?s=shoes', 'include/archive/search' )['theme_locations']['header']['template_id'] ?? null );
		$this->assertSame( 'search_results', self::resolve( '/?s=shoes' )['route_type'] );
	}

	public function test_an_empty_search_is_a_search(): void {
		$this->assertSame( 100, $this->location_on( '/?s=', 'include/archive/search' )['theme_locations']['header']['template_id'] ?? null );
	}

	public function test_page_two_of_a_static_front_page_is_the_front_page(): void {
		IATO_Test_Abilities_State::$options['show_on_front'] = 'page';
		IATO_Test_Abilities_State::$options['page_on_front'] = (string) self::FRONT;
		IATO_Test_Router::$url_ids[ self::HOME_URL . '/page/2/' ] = self::FRONT;
		$this->assertSame( 100, $this->location_on( '/page/2/', 'include/singular/front_page' )['theme_locations']['header']['template_id'] ?? null );
	}

	public function test_route_type_follows_wordpress_too(): void {
		IATO_Test_Actor::$slugs['genre'] = [ 'rock' => 31 ];
		foreach ( [ '/category/news/page/2/' => 'category_archive', '/genre/rock/' => 'taxonomy_archive', '/2026/11/' => 'date_archive', '/search/shoes/' => 'search_results', '/' => 'blog_home', '/page/2/' => 'blog_home', '/category/no-such-term/' => '404', '/no-such-page/' => '404' ] as $path => $route ) {
			self::forget_resolved();
			$this->assertSame( $route, self::resolve( $path )['route_type'], $path );
		}
	}

	public function test_an_archive_past_its_last_page_or_a_date_with_no_posts_is_a_404(): void {
		IATO_Test_Router::$found_posts = [];
		$this->assertSame( 100, $this->location_on( '/category/news/page/99/', 'include/singular/not_found404' )['theme_locations']['header']['template_id'] ?? null );
		$this->assertSame( 100, $this->location_on( '/2026/11/', 'include/singular/not_found404' )['theme_locations']['header']['template_id'] ?? null );
		$this->assertNull( $this->location_on( '/2026/11/', 'include/archive/date' )['theme_locations']['header'] );
		$this->assertSame( 100, $this->location_on( '/page/99/', 'include/singular/not_found404' )['theme_locations']['header']['template_id'] ?? null );
	}

	public function test_an_empty_category_on_its_first_page_is_still_its_archive(): void {
		IATO_Test_Router::$found_posts = [];
		$this->assertSame( 100, $this->location_on( '/category/news/', 'include/archive/category/7' )['theme_locations']['header']['template_id'] ?? null );
	}

	public function test_another_host_says_nothing(): void {
		self::template( 100, 'header', [ 'include/general' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/general' ] ] );
		$r = IATO_MCP_Elementor_Router::resolve_url( 'https://other.example/about/' );
		$this->assertNull( $r['theme_locations'] );
		$this->assertNull( $r['rendering_template_id'] );
		$this->assertSame( '404', $r['route_type'] );
		self::forget_resolved();
		$this->assertNotNull( IATO_MCP_Elementor_Router::resolve_url( 'https://WWW.site.test/about/' )['theme_locations'], 'the same host, with www and in capitals' );
		self::forget_resolved();
		self::template( 101, 'single-page', [ 'include/general' ] );
		$this->assertNull( IATO_MCP_Elementor_Router::resolve_url( 'https://other.example/about/' )['rendering_template_id'] );
	}

	/** @return array<string,array{0:string}> */
	public static function what_cannot_be_judged(): array {
		return [ 'a feed' => [ '/feed/' ], 'two kinds at once' => [ '/category/news/?s=shoes' ], 'a post named in the query, with no rewrite' => [ '/?name=hello' ] ];
	}

	#[DataProvider( 'what_cannot_be_judged' )]
	public function test_a_url_this_cannot_judge_gets_no_confident_answer( string $path ): void {
		foreach ( [ 'include/singular', 'include/archive', 'include/singular/not_found404' ] as $condition ) {
			$r = $this->location_on( $path, $condition );
			$this->assertNull( $r['theme_locations']['header'], $condition );
			$this->assertSame( $condition, $r['theme_locations']['not_evaluated'][0]['condition'] );
		}
		$this->assertSame( 100, $this->location_on( $path, 'include/general' )['theme_locations']['header']['template_id'] ?? null, 'Entire Site needs no judging' );
	}

	public function test_woocommerces_shop_page_is_left_unjudged(): void {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			eval( 'function wc_get_page_id( $page ) { return "shop" === $page ? ElementorRouterTest::$shop_page : -1; }' );
		}
		self::$shop_page = self::PAGE;
		$r = $this->location_on( '/about/', 'include/singular/page' );
		self::$shop_page = -1;
		$this->assertNull( $r['theme_locations']['header'] );
		$this->assertSame( 'include/singular/page', $r['theme_locations']['not_evaluated'][0]['condition'] );
	}

	public function test_a_failure_asking_wordpress_is_no_confident_answer_either(): void {
		$saved = $GLOBALS['wp_rewrite'];
		unset( $GLOBALS['wp_rewrite'] );
		try {
			$r = $this->location_on( '/category/news/', 'include/archive' );
		} finally {
			$GLOBALS['wp_rewrite'] = $saved;
		}
		$this->assertNull( $r['theme_locations']['header'] );
		$this->assertSame( 'include/archive', $r['theme_locations']['not_evaluated'][0]['condition'] );
	}

	// ── A singular template and the blog (item 16 of the 1.17.0 review) ───

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function singular_shadowing(): array {
		return [
			'a page'                      => [ 'include/singular', '/about/', true ],
			'the posts page'              => [ 'include/singular', '/blog/', false ],
			'a search'                    => [ 'include/singular', '/?s=x', false ],
			'an archive'                  => [ 'include/singular', '/category/news/', false ],
			'a not-found URL, as in Pro'  => [ 'include/singular', '/no-such-page/', true ],
			'the 404 condition on one'    => [ 'include/singular/not_found404', '/no-such-page/', true ],
			'the 404 condition on a page' => [ 'include/singular/not_found404', '/about/', false ],
			'page type on the posts page' => [ 'include/singular/page', '/blog/', false ],
		];
	}

	#[DataProvider( 'singular_shadowing' )]
	public function test_a_singular_template_shadows_only_what_pro_says( string $condition, string $path, bool $shadows ): void {
		IATO_Test_Abilities_State::$options['show_on_front']  = 'page';
		IATO_Test_Abilities_State::$options['page_on_front']  = (string) self::FRONT;
		IATO_Test_Abilities_State::$options['page_for_posts'] = (string) self::BLOG_PAGE;
		self::template( 100, 'single-page', [ $condition ] );
		$this->assertSame( $shadows ? 100 : null, self::resolve( $path )['rendering_template_id'] );
	}

	// ── A page template that hides the header (item 2) ────────────────────

	private static function header_everywhere(): void {
		self::template( 100, 'header', [ 'include/general' ] );
		self::template( 101, 'footer', [ 'include/general' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/general' ] ] );
		self::in_pro_cache( 'footer', [ 101 => [ 'include/general' ] ] );
	}

	public function test_a_canvas_page_gets_no_header_and_says_why(): void {
		self::header_everywhere();
		IATO_Test_WP::$meta[ self::PAGE ]['_wp_page_template'] = 'elementor_canvas';
		$this->assertSame(
			[ 'header' => null, 'footer' => null, 'hidden_by_page_template' => 'elementor_canvas' ],
			self::resolve( '/about/' )['theme_locations']
		);
		$this->assertSame( self::PAGE, self::resolve( '/about/' )['rendering_post_id'], 'the rest of the answer is as it was' );
	}

	/** @return array<string,array{0:?string}> */
	public static function templates_that_show_it(): array {
		return [ 'the default' => [ 'default' ], 'none' => [ null ], 'full width' => [ 'elementor_header_footer' ], 'a theme template' => [ 'page-wide.php' ] ];
	}

	#[DataProvider( 'templates_that_show_it' )]
	public function test_other_page_templates_show_it( ?string $template ): void {
		self::header_everywhere();
		if ( null !== $template ) {
			IATO_Test_WP::$meta[ self::PAGE ]['_wp_page_template'] = $template;
		}
		$locations = self::resolve( '/about/' )['theme_locations'];
		$this->assertSame( 100, $locations['header']['template_id'] );
		$this->assertArrayNotHasKey( 'hidden_by_page_template', $locations );
	}

	public function test_canvas_as_the_kits_default_counts_for_a_page_built_with_elementor(): void {
		// Elementor's PageTemplates module: no template of Elementor's or the theme's, built with Elementor.
		self::header_everywhere();
		\Elementor\Plugin::$instance->kits_manager = new class { public function get_current_settings( string $key ) { return 'default_page_template' === $key ? 'elementor_canvas' : null; } };
		IATO_Test_WP::$meta[ self::PAGE ]['_elementor_edit_mode'] = 'builder';
		$this->assertSame( 'elementor_canvas', self::resolve( '/about/' )['theme_locations']['hidden_by_page_template'] ?? null );
		self::forget_resolved();
		IATO_Test_WP::$meta[ self::PAGE ]['_wp_page_template'] = 'elementor_theme';
		$this->assertArrayNotHasKey( 'hidden_by_page_template', self::resolve( '/about/' )['theme_locations'], 'the page asked for the theme' );
		self::forget_resolved();
		unset( IATO_Test_WP::$meta[ self::PAGE ]['_wp_page_template'], IATO_Test_WP::$meta[ self::PAGE ]['_elementor_edit_mode'] );
		$this->assertArrayNotHasKey( 'hidden_by_page_template', self::resolve( '/about/' )['theme_locations'], 'not built with Elementor' );
	}

	public function test_a_kit_that_throws_does_not_cost_the_answer(): void {
		self::header_everywhere();
		\Elementor\Plugin::$instance->kits_manager = new class { public function get_current_settings( string $key ) { throw new RuntimeException( 'no kit' ); } };
		IATO_Test_WP::$meta[ self::PAGE ]['_elementor_edit_mode'] = 'builder';
		$this->assertSame( 100, self::resolve( '/about/' )['theme_locations']['header']['template_id'] );
	}

	public function test_canvas_only_matters_where_a_page_is_shown(): void {
		self::header_everywhere();
		IATO_Test_WP::$meta[ self::PAGE ]['_wp_page_template'] = 'elementor_canvas';
		$this->assertSame( 100, self::resolve( '/category/news/' )['theme_locations']['header']['template_id'] );
	}

	// ── The tool, per caller ──────────────────────────────────────────────

	private function as_user( ?int $id ): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, null !== $id );
		$ref->getProperty( 'authenticated_user' )->setValue( null, $id ? new WP_User( $id ) : null );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, false );
	}

	private function tool_as( int $user, string $path ): array {
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator', self::READER => 'subscriber' ];
		$this->as_user( $user );
		$r = ( IATO_MCP_Server::get_catalog_entry( 'resolve_url' )['handler'] )( [ 'url' => self::HOME_URL . $path ] );
		$this->as_user( null );
		return json_decode( $r['content'][0]['text'], true );
	}

	public function test_an_administrator_is_told_which_conditions_were_not_evaluated(): void {
		self::template( 100, 'header', [ 'include/singular/child_of/5' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/singular/child_of/5' ] ] );
		$this->assertSame( [ [ 'location' => 'header', 'template_id' => 100, 'condition' => 'include/singular/child_of/5' ] ], $this->tool_as( self::ADMIN, '/about/' )['theme_locations']['not_evaluated'] );
	}

	public function test_anyone_else_learns_only_that_the_answer_may_be_incomplete(): void {
		// A template that may not render here: its ID and conditions are not public.
		self::template( 100, 'header', [ 'include/singular/child_of/5', 'include/singular/child_of/6' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/singular/child_of/5', 'include/singular/child_of/6' ] ] );
		$locations = $this->tool_as( self::READER, '/about/' )['theme_locations'];
		$this->assertNull( $locations['header'] );
		$this->assertSame( [ [ 'location' => 'header' ] ], $locations['not_evaluated'] );
	}

	public function test_a_page_the_caller_may_not_read_answers_as_a_404_with_the_field_empty(): void {
		IATO_Test_Roles::$posts[ self::PAGE ] = [ 'post_type' => 'page', 'post_status' => 'draft', 'post_author' => self::ADMIN ];
		IATO_Test_WP::$posts[ self::PAGE ]['post_status'] = 'draft';
		self::template( 100, 'header', [ 'include/singular/page/10' ] );
		self::in_pro_cache( 'header', [ 100 => [ 'include/singular/page/10' ] ] );
		$r = $this->tool_as( self::READER, '/about/' );
		$this->assertSame( '404', $r['route_type'] );
		$this->assertNull( $r['rendering_post_id'] );
		$this->assertNull( $r['theme_locations'] );
	}

	public function test_a_failure_reading_pro_leaves_the_rest_of_the_answer(): void {
		IATO_Test_Pro::$active       = true;
		IATO_Test_Pro::$cache_throws = true;
		$r = self::resolve( '/about/' );
		$this->assertSame( self::PAGE, $r['rendering_post_id'] );
		$this->assertSame( 'page', $r['route_type'] );
		$this->assertNull( $r['theme_locations'] );
		$this->assertArrayNotHasKey( 'error', $r );
	}
}
