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
require_once IATO_MCP_DIR . 'includes/class-elementor-router.php';
require_once IATO_MCP_DIR . 'includes/class-theme-builder.php';

/** A document class as get_document_types() names them. */
final class IATO_Test_Header_Document {
	public static function get_property( string $key ): mixed {
		return [ 'cpt' => [ 'elementor_library' ], 'location' => 'header', 'support_conditions' => true ][ $key ] ?? null;
	}
}
final class IATO_Test_Section_Document {
	public static function get_property( string $key ): mixed {
		return [ 'cpt' => [ 'elementor_library' ], 'support_conditions' => true ][ $key ] ?? null;
	}
}
final class IATO_Test_Post_Document {
	public static function get_property( string $key ): mixed {
		return [ 'cpt' => [ 'post' ] ][ $key ] ?? null;
	}
}
final class IATO_Test_Made_Document {
	public function __construct( private int $id ) {}
	public function get_main_id(): int { return $this->id; }
}

/**
 * IATO_MCP_Theme_Builder itself, against a fake Elementor Pro with the method names and
 * return values of Pro 4.3.3: that every call is guarded, and that nothing Pro returns
 * is taken on trust. Live: tests/wp-env/theme-builder-live.py.
 */
final class ThemeBuilderEngineTest extends TestCase {

	private const TEMPLATE = 900;
	private IATO_MCP_Theme_Builder $builder;

	protected function setUp(): void {
		IATO_Test_Pro::reset();
		IATO_Test_Pro::$active = true;
		IATO_Test_WP::$posts   = [ self::TEMPLATE => [ 'post_type' => 'elementor_library', 'post_status' => 'draft', 'post_title' => 'T' ] ];
		IATO_Test_WP::$meta    = [];
		\Elementor\FakeDocuments::$types     = [];
		\Elementor\FakeDocuments::$on_create = null;
		IATO_Test_Abilities_State::$actions['wp_insert_post'] = [];
		$this->builder = new IATO_MCP_Theme_Builder();
	}

	protected function tearDown(): void {
		IATO_Test_Pro::reset();
		IATO_Test_WP::$posts = [];
		IATO_Test_WP::$meta  = [];
		\Elementor\FakeDocuments::$types     = [];
		\Elementor\FakeDocuments::$on_create = null;
		IATO_Test_Abilities_State::$actions['wp_insert_post'] = [];
		IATO_MCP_Theme_Builder::set_instance( null );
	}

	// ── PHP 8.0, the plugin's minimum ─────────────────────────────────────

	/** `true` in a type, and `false` or `null` alone, are 8.2: a fatal on 8.0 and 8.1. PHPUnit here runs newer than that. */
	public function test_no_shipped_return_type_needs_a_newer_php(): void {
		$files = array_merge( [ IATO_MCP_DIR . 'iato-mcp.php' ], (array) glob( IATO_MCP_DIR . 'includes/*.php' ), (array) glob( IATO_MCP_DIR . 'includes/tools/*/*.php' ) );
		foreach ( $files as $file ) {
			foreach ( self::return_types( (string) file_get_contents( (string) $file ) ) as $type ) {
				$this->assertStringNotContainsString( 'true', strtolower( $type ), basename( (string) $file ) . ": {$type}" );
				$this->assertNotContains( strtolower( ltrim( $type, '?' ) ), [ 'false', 'null' ], basename( (string) $file ) . ": {$type}" );
			}
		}
	}

	/** @return string[] Every function's declared return type, from PHP's own tokens. */
	private static function return_types( string $source ): array {
		$tokens = array_values( array_filter( token_get_all( $source ), static fn( $t ) => ! is_array( $t ) || ! in_array( $t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) );
		$types  = [];
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], [ T_FUNCTION, T_FN ], true ) ) {
				continue;
			}
			$j = $i + 1;
			while ( isset( $tokens[ $j ] ) && '(' !== $tokens[ $j ] ) {
				++$j;
			}
			$depth = 0;
			for ( ; isset( $tokens[ $j ] ); $j++ ) {
				$depth += '(' === $tokens[ $j ] ? 1 : ( ')' === $tokens[ $j ] ? -1 : 0 );
				if ( 0 === $depth ) {
					break;
				}
			}
			if ( ':' !== ( $tokens[ $j + 1 ] ?? null ) ) {
				continue;
			}
			$type = '';
			for ( $k = $j + 2; isset( $tokens[ $k ] ) && ! in_array( $tokens[ $k ], [ '{', ';' ], true ) && ! ( is_array( $tokens[ $k ] ) && T_DOUBLE_ARROW === $tokens[ $k ][0] ); $k++ ) {
				$type .= is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
			}
			$types[] = $type;
		}
		return $types;
	}

	// ── Is Pro there ──────────────────────────────────────────────────────

	public function test_with_pro_nothing_is_missing(): void {
		$this->assertNull( $this->builder->missing_pro() );
	}

	public function test_without_pro_it_names_the_plugin(): void {
		IATO_Test_Pro::$active = false;
		$this->assertStringContainsString( 'Elementor Pro is not active', $this->builder->missing_pro() );
	}

	public function test_pro_throwing_is_pro_missing_not_a_fatal(): void {
		IATO_Test_Pro::$instance_throws = true;
		$this->assertStringContainsString( 'Elementor Pro', $this->builder->missing_pro() );
		$this->assertNull( $this->builder->conditions_config() );
		$this->assertNull( $this->builder->location_of( self::TEMPLATE ) );
		$this->assertFalse( $this->builder->location_allows_many( 'popup' ) );
		$this->assertSame( [], $this->builder->cached( 'header' ) );
		$this->assertSame( 'conditions_not_saved', $this->builder->save_conditions( self::TEMPLATE, [ 'include/general' ] )->get_error_code() );
		$this->assertSame( 'cache_not_regenerated', $this->builder->regenerate()->get_error_code() );
	}

	public function test_without_pro_writes_are_refused_by_name(): void {
		IATO_Test_Pro::$active = false;
		$this->assertSame( 'elementor_pro_required', $this->builder->save_conditions( self::TEMPLATE, [ 'include/general' ] )->get_error_code() );
		$this->assertSame( 'elementor_pro_required', $this->builder->regenerate()->get_error_code() );
	}

	// ── What is registered ────────────────────────────────────────────────

	public function test_only_library_types_are_listed_with_their_location(): void {
		\Elementor\FakeDocuments::$types = [ 'header' => IATO_Test_Header_Document::class, 'section' => IATO_Test_Section_Document::class, 'wp-post' => IATO_Test_Post_Document::class, 'odd' => 'NoSuchClass' ];
		$this->assertSame(
			[
				'header'  => [ 'location' => 'header', 'supports_conditions' => true ],
				'section' => [ 'location' => null, 'supports_conditions' => true ],
			],
			$this->builder->registered_types()
		);
	}

	public function test_the_conditions_config_is_reduced_to_what_validation_needs(): void {
		IATO_Test_Pro::$config = [
			'general'  => [ 'name' => 'general', 'label' => 'General', 'sub_conditions' => [ 'archive', 'singular' ], 'controls' => [] ],
			'singular' => [ 'name' => 'singular', 'sub_conditions' => [ 'page' ], 'controls' => [] ],
			'page'     => [ 'name' => 'page', 'sub_conditions' => [], 'controls' => [ 'post_id' => [] ] ],
		];
		$this->assertSame(
			[
				'general'  => [ 'sub' => [ 'archive', 'singular' ], 'takes_id' => false ],
				'singular' => [ 'sub' => [ 'page' ], 'takes_id' => false ],
				'page'     => [ 'sub' => [], 'takes_id' => true ],
			],
			$this->builder->conditions_config()
		);
	}

	// ── Making a template ─────────────────────────────────────────────────

	public function test_create_asks_elementor_for_a_draft_with_a_slashed_title(): void {
		$seen = [];
		\Elementor\FakeDocuments::$on_create = static function ( string $type, array $post, array $meta ) use ( &$seen ) {
			$seen = [ $type, $post, $meta ];
			IATO_Test_WP::$meta[ self::TEMPLATE ]['_elementor_version'] = '4.3.6';
			return new IATO_Test_Made_Document( self::TEMPLATE );
		};
		$this->assertSame( self::TEMPLATE, $this->builder->create_document( 'header', 'C:' . chr( 92 ) . 'Users' ) );
		$this->assertSame( 'header', $seen[0] );
		$this->assertSame( 'draft', $seen[1]['post_status'] );
		$this->assertSame( 'C:' . chr( 92 ) . chr( 92 ) . 'Users', $seen[1]['post_title'], 'wp_insert_post() unslashes' );
	}

	public function test_a_template_elementor_would_not_save_is_trashed_not_kept(): void {
		// As found on the Pro site: with no user the post exists, with no version and no type term.
		\Elementor\FakeDocuments::$on_create = static fn() => new IATO_Test_Made_Document( self::TEMPLATE );
		$r = $this->builder->create_document( 'header', 'T' );
		$this->assertSame( 'template_create_failed', $r->get_error_code() );
		$this->assertSame( 'trash', IATO_Test_WP::$posts[ self::TEMPLATE ]['post_status'] );
	}

	public function test_elementors_refusal_comes_back_as_an_error(): void {
		\Elementor\FakeDocuments::$on_create = static fn() => new WP_Error( '500', 'Type nosuch does not exist.' );
		$r = $this->builder->create_document( 'nosuch', 'T' );
		$this->assertSame( 'template_create_failed', $r->get_error_code() );
		$this->assertStringContainsString( 'nosuch', $r->get_error_message() );
	}

	public function test_a_throw_after_the_post_exists_trashes_it(): void {
		\Elementor\FakeDocuments::$on_create = static function () {
			do_action( 'wp_insert_post', self::TEMPLATE );
			do_action( 'wp_insert_post', 12345 ); // something else saved during the save is not ours
			throw new RuntimeException( 'an addon failed' );
		};
		$r = $this->builder->create_document( 'header', 'T' );
		$this->assertSame( 'template_create_failed', $r->get_error_code() );
		$this->assertStringContainsString( 'an addon failed', $r->get_error_message() );
		$this->assertSame( 'trash', IATO_Test_WP::$posts[ self::TEMPLATE ]['post_status'] );
		$this->assertSame( [], IATO_Test_Abilities_State::$actions['wp_insert_post'], 'the listener is gone' );
	}

	// ── Saving conditions ─────────────────────────────────────────────────

	public function test_rules_reach_pro_in_the_order_it_joins_them(): void {
		IATO_Test_Pro::$theme_documents[ self::TEMPLATE ] = 'header';
		$this->assertTrue( $this->builder->save_conditions( self::TEMPLATE, [ 'include/general', 'exclude/singular/page/42' ] ) );
		$this->assertSame(
			[ [ 'type' => 'include', 'name' => 'general', 'sub_name' => '', 'sub_id' => '' ], [ 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '42' ] ],
			IATO_Test_Pro::$saved[0]
		);
		$this->assertSame( [ 'include/general', 'exclude/singular/page/42' ], IATO_Test_WP::$meta[ self::TEMPLATE ]['_elementor_conditions'] );
		$this->assertSame( 1, IATO_Test_Pro::$regenerated, 'Pro regenerates its cache on the save' );
	}

	public function test_pros_false_for_a_value_saved_again_is_not_a_failure(): void {
		IATO_Test_Pro::$theme_documents[ self::TEMPLATE ] = 'header';
		$this->builder->save_conditions( self::TEMPLATE, [ 'include/general' ] );
		$this->assertTrue( $this->builder->save_conditions( self::TEMPLATE, [ 'include/general' ] ) );
	}

	public function test_a_save_that_stored_nothing_is_a_failure(): void {
		// A page or other non-Theme_Document: save_conditions() returns false and stores nothing.
		$r = $this->builder->save_conditions( self::TEMPLATE, [ 'include/general' ] );
		$this->assertSame( 'conditions_not_saved', $r->get_error_code() );
	}

	public function test_an_empty_list_removes_them(): void {
		IATO_Test_Pro::$theme_documents[ self::TEMPLATE ] = 'header';
		$this->builder->save_conditions( self::TEMPLATE, [ 'include/general' ] );
		$this->assertTrue( $this->builder->save_conditions( self::TEMPLATE, [] ) );
		$this->assertArrayNotHasKey( '_elementor_conditions', IATO_Test_WP::$meta[ self::TEMPLATE ] );
	}

	public function test_a_rebuild_that_throws_after_the_meta_is_stored_is_not_a_failed_save(): void {
		// Conditions_Manager::save_conditions() updates the meta, then regenerates.
		IATO_Test_Pro::$theme_documents[ self::TEMPLATE ] = 'header';
		IATO_Test_Pro::$regenerate_throws = true;
		$r = $this->builder->save_conditions( self::TEMPLATE, [ 'include/general' ] );
		$this->assertSame( 'cache_not_regenerated', $r->get_error_code() );
		$this->assertStringContainsString( 'regenerate failed', $r->get_error_message() );
		$this->assertSame( [ 'include/general' ], IATO_Test_WP::$meta[ self::TEMPLATE ]['_elementor_conditions'] );
	}

	public function test_a_throw_while_saving_is_an_error(): void {
		IATO_Test_Pro::$save_mode = 'throw';
		$r = $this->builder->save_conditions( self::TEMPLATE, [ 'include/general' ] );
		$this->assertSame( 'conditions_not_saved', $r->get_error_code() );
		$this->assertStringContainsString( 'an addon failed', $r->get_error_message() );
	}

	// ── Checking a tree with no post ──────────────────────────────────────

	public function test_atomic_checks_need_no_post(): void {
		// The real call's first refusal: a plain value on an atomic element, with Elementor's writer unavailable.
		$engine = new \FakeAtomicEngine();
		$engine->missing = 'Elementor is not active on this site.';
		IATO_MCP_Elementor_Atomic_Writer::set_engine( $engine );
		try {
			$r = $this->builder->preview_tree( [ [ 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'title' => [ '$$type' => 'string', 'value' => 'Old' ], 'tag' => 'h2' ], 'elements' => [] ] ] );
		} finally {
			IATO_MCP_Elementor_Atomic_Writer::set_engine( null );
		}
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringStartsWith( 'atomic_', $r->get_error_code() );
	}

	public function test_a_plain_classic_tree_passes(): void {
		IATO_MCP_Elementor_Atomic_Writer::set_engine( new \FakeAtomicEngine() );
		try {
			$r = $this->builder->preview_tree( [ [ 'id' => 'a1', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] );
		} finally {
			IATO_MCP_Elementor_Atomic_Writer::set_engine( null );
		}
		$this->assertIsArray( $r );
	}

	// ── The rest of what it reads ─────────────────────────────────────────

	public function test_location_of(): void {
		IATO_Test_Pro::$theme_documents[ self::TEMPLATE ] = 'header';
		$this->assertSame( 'header', $this->builder->location_of( self::TEMPLATE ) );
		IATO_Test_Pro::$theme_documents[ self::TEMPLATE ] = '';
		$this->assertNull( $this->builder->location_of( self::TEMPLATE ), 'a section with no location' );
		$this->assertNull( $this->builder->location_of( 99 ), 'not a Theme_Document' );
	}

	public function test_location_allows_many(): void {
		IATO_Test_Pro::$locations = [ 'popup' => [ 'multiple' => true ], 'header' => [ 'multiple' => false ] ];
		$this->assertTrue( $this->builder->location_allows_many( 'popup' ) );
		$this->assertFalse( $this->builder->location_allows_many( 'header' ) );
		$this->assertFalse( $this->builder->location_allows_many( 'nosuch' ) );
	}

	public function test_the_cache_is_read_and_rebuilt_through_pro(): void {
		IATO_Test_Pro::$cache['header'] = [ 7 => [ 'include/general' ] ];
		$this->assertSame( [ 7 => [ 'include/general' ] ], $this->builder->cached( 'header' ) );
		$this->assertSame( [], $this->builder->cached( 'footer' ) );
		$this->assertTrue( $this->builder->regenerate() );
		$this->assertSame( 1, IATO_Test_Pro::$regenerated );
	}

	public function test_a_cache_that_will_not_rebuild_is_an_error(): void {
		IATO_Test_Pro::$regenerate_throws = true;
		$this->assertSame( 'cache_not_regenerated', $this->builder->regenerate()->get_error_code() );
	}

	// ── Checking conditions: plain logic ──────────────────────────────────

	private const REGISTERED = [
		'general'        => [ 'sub' => [ 'archive', 'singular' ], 'takes_id' => false ],
		'archive'        => [ 'sub' => [ 'date', 'post_archive' ], 'takes_id' => false ],
		'date'           => [ 'sub' => [], 'takes_id' => false ],
		'post_archive'   => [ 'sub' => [ 'category' ], 'takes_id' => false ],
		'category'       => [ 'sub' => [], 'takes_id' => true ],
		'singular'       => [ 'sub' => [ 'front_page', 'post', 'page' ], 'takes_id' => false ],
		'front_page'     => [ 'sub' => [], 'takes_id' => false ],
		'post'           => [ 'sub' => [ 'in_category' ], 'takes_id' => true ],
		'in_category'    => [ 'sub' => [], 'takes_id' => true ],
		'page'           => [ 'sub' => [], 'takes_id' => true ],
	];

	/** @return array<string,array{0:mixed,1:string}> */
	public static function good_conditions(): array {
		return [
			'entire site'             => [ [ 'type' => 'include', 'name' => 'general' ], 'include/general' ],
			'as a string'             => [ 'include/general', 'include/general' ],
			'empty parts are dropped' => [ [ 'type' => 'include', 'name' => 'general', 'sub_name' => '', 'sub_id' => '' ], 'include/general' ],
			'an exclude'              => [ [ 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => 42 ], 'exclude/singular/page/42' ],
			'an ID as a string'       => [ [ 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '42' ], 'exclude/singular/page/42' ],
			'a string with an ID'     => [ 'exclude/singular/page/42', 'exclude/singular/page/42' ],
			'no ID'                   => [ [ 'type' => 'include', 'name' => 'singular', 'sub_name' => 'front_page' ], 'include/singular/front_page' ],
			'two levels down'         => [ [ 'type' => 'include', 'name' => 'singular', 'sub_name' => 'in_category', 'sub_id' => 3 ], 'include/singular/in_category/3' ],
			'under the blog archive'  => [ 'include/archive/category/7', 'include/archive/category/7' ],
			'a name alone'            => [ 'include/singular', 'include/singular' ],
		];
	}

	#[DataProvider( 'good_conditions' )]
	public function test_good_conditions( mixed $entry, string $stored ): void {
		$this->assertSame( [ $stored ], IATO_MCP_Theme_Builder::normalize_conditions( [ $entry ], self::REGISTERED ) );
	}

	/** @return array<string,array{0:mixed,1:string}> */
	public static function bad_conditions(): array {
		return [
			'no type'                  => [ [ 'name' => 'general' ], 'must start with include or exclude' ],
			'another type'             => [ [ 'type' => 'only', 'name' => 'general' ], 'must start with include or exclude' ],
			'an unknown name'          => [ [ 'type' => 'include', 'name' => 'nosuch' ], 'Unknown condition name' ],
			'a sub-condition as name'  => [ 'include/page', 'Unknown condition name' ],
			'no name'                  => [ 'include', 'Unknown condition name' ],
			'a sub of nothing'         => [ 'include/general/page', 'not a condition under' ],
			'an unknown sub'           => [ 'include/singular/nosuch', 'not a condition under' ],
			'a sub from the other side' => [ 'include/archive/page/42', 'not a condition under' ],
			'an ID on what takes none' => [ 'include/singular/front_page/9', 'does not take an ID' ],
			'an ID with no sub'        => [ [ 'type' => 'include', 'name' => 'singular', 'sub_id' => 9 ], 'positive ID' ],
			'an ID that is a word'     => [ 'include/singular/page/abc', 'positive ID' ],
			'an ID of zero'            => [ 'include/singular/page/0', 'positive ID' ],
			'a negative ID'            => [ [ 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => -4 ], 'positive ID' ],
			'too many parts'           => [ 'include/singular/page/4/5', 'Too many parts' ],
			'another key'              => [ [ 'type' => 'include', 'name' => 'general', 'x' => 1 ], 'also has x' ],
			'a name that is a list'    => [ [ 'type' => 'include', 'name' => [ 'general' ] ], 'are strings' ],
			'a number'                 => [ 7, 'a rule or a string' ],
			'a slash smuggled in'      => [ [ 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page/42' ], 'not a condition under' ],
		];
	}

	#[DataProvider( 'bad_conditions' )]
	public function test_bad_conditions( mixed $entry, string $says ): void {
		$r = IATO_MCP_Theme_Builder::normalize_conditions( [ $entry ], self::REGISTERED );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'unknown_condition', $r->get_error_code() );
		$this->assertStringContainsString( $says, $r->get_error_message() );
		$data = $r->get_error_data();
		$this->assertSame( [ 'date', 'post_archive', 'category' ], $data['registered']['archive'] );
		$this->assertContains( 'page', $data['takes_id'] );
	}

	public function test_a_single_rule_instead_of_a_list_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Theme_Builder::normalize_conditions( [ 'type' => 'include', 'name' => 'general' ], self::REGISTERED ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Theme_Builder::normalize_conditions( 'include/general', self::REGISTERED ) );
	}

	public function test_an_empty_list_and_repeats(): void {
		$this->assertSame( [], IATO_MCP_Theme_Builder::normalize_conditions( [], self::REGISTERED ) );
		$this->assertSame( [ 'include/general' ], IATO_MCP_Theme_Builder::normalize_conditions( [ 'include/general', [ 'type' => 'include', 'name' => 'general' ] ], self::REGISTERED ) );
	}

	public function test_nothing_registered_refuses_everything(): void {
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Theme_Builder::normalize_conditions( [ 'include/singular' ], [] ) );
	}

	public function test_rules_from_undoes_the_string_form(): void {
		$this->assertSame(
			[ [ 'type' => 'include', 'name' => 'general', 'sub_name' => '', 'sub_id' => '' ], [ 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '42' ] ],
			IATO_MCP_Theme_Builder::rules_from( [ 'include/general', 'exclude/singular/page/42' ] )
		);
	}

	public function test_the_revision_changes_with_the_conditions_and_their_order(): void {
		$a = IATO_MCP_Theme_Builder::conditions_revision( [ 'include/general', 'exclude/singular/page/4' ] );
		$this->assertSame( $a, IATO_MCP_Theme_Builder::conditions_revision( [ 'include/general', 'exclude/singular/page/4' ] ) );
		$this->assertNotSame( $a, IATO_MCP_Theme_Builder::conditions_revision( [ 'exclude/singular/page/4', 'include/general' ] ) );
		$this->assertNotSame( $a, IATO_MCP_Theme_Builder::conditions_revision( [] ) );
	}
}
