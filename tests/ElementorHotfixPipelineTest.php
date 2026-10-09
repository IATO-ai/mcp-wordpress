<?php
declare( strict_types=1 );

/**
 * 1.12.2: the write pipeline sanitises only the values a request changed,
 * writes untouched values verbatim, and keeps Elementor's whole-value meta
 * sanitize callbacks out of its own writes (restoring them afterwards, also
 * when the write throws). Fake Elementor + fake $wp_filter below.
 */

// ── Fake Elementor ─────────────────────────────────────────────────────────
namespace Elementor {
	final class FakeDocument {
		/** @var 'refuse'|'throw' refuse = Elementor declines the save; throw stands in for addon failures */
		public static string $mode = 'refuse';
		public static array $received = [];
		public function save( array $data ): bool {
			self::$received[] = $data;
			if ( 'throw' === self::$mode ) {
				throw new \RuntimeException( 'simulated Elementor failure' );
			}
			return false;
		}
	}
	final class FakeDocuments { public function get( int $id ) { return new FakeDocument(); } }
	final class FakeFilesManager { public function clear_cache(): void {} }
	final class FakeFrontend { public function get_builder_content( int $id, bool $with_css = false ): string { return '<h2>frontend fallback render</h2>'; } }
	final class Plugin {
		public static ?Plugin $instance = null;
		public FakeDocuments $documents;
		public FakeFilesManager $files_manager;
		public FakeFrontend $frontend;
		public function __construct() { $this->documents = new FakeDocuments(); $this->files_manager = new FakeFilesManager(); $this->frontend = new FakeFrontend(); }
		public static function instance(): Plugin { return self::$instance ??= new Plugin(); }
	}
	Plugin::$instance = Plugin::instance();
}

// ── WordPress stubs ──────────────────────────────────────────────────────────
namespace {
	use PHPUnit\Framework\TestCase;

	/** Minimal WP_Hook: callbacks[priority][id] = [function, accepted_args]. */
	if ( ! class_exists( 'WP_Hook' ) ) {
		class WP_Hook {
			public array $callbacks = [];
		}
	}
	final class IATO_Test_Filters {
		/** @var array<int,array{0:string,1:int}> hook names seen non-empty at each update_post_meta() call */
		public static array $hooks_at_write = [];
		public static function reset(): void {
			$GLOBALS['wp_filter'] = [];
			self::$hooks_at_write = [];
		}
		public static function add( string $hook, callable $fn, int $priority = 10, int $args = 1 ): void {
			$GLOBALS['wp_filter'][ $hook ] ??= new WP_Hook();
			$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ spl_object_hash( (object) [] ) . count( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ] ?? [] ) ] = [ 'function' => $fn, 'accepted_args' => $args ];
		}
		public static function count( string $hook ): int {
			$n = 0;
			foreach ( ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? [] ) as $cbs ) {
				$n += count( $cbs );
			}
			return $n;
		}
		/** The ids of the elementor meta hooks that currently have callbacks. */
		public static function live_elementor_hooks(): array {
			$out = [];
			foreach ( $GLOBALS['wp_filter'] ?? [] as $hook => $h ) {
				if ( str_starts_with( $hook, 'sanitize_post_meta__elementor_' ) && self::count( $hook ) > 0 ) {
					$out[] = $hook;
				}
			}
			sort( $out );
			return $out;
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): bool { IATO_Test_Filters::add( $hook, $cb, $priority, $args ); return true; }
	}
	if ( ! function_exists( 'remove_filter' ) ) {
		function remove_filter( string $hook, callable $cb, int $priority = 10 ): bool {
			foreach ( ( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ] ?? [] ) as $id => $entry ) {
				if ( $entry['function'] === $cb ) {
					unset( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ $id ] );
					return true;
				}
			}
			return false;
		}
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( string $cap, mixed ...$args ): bool { return IATO_Test_Caps::$caps[ $cap ] ?? false; }
	}
	final class IATO_Test_Caps { public static array $caps = []; }
	if ( ! function_exists( 'get_post' ) ) {
		function get_post( int $id ): ?object { return isset( IATO_Test_WP::$posts[ $id ] ) ? (object) ( [ 'ID' => $id ] + IATO_Test_WP::$posts[ $id ] ) : null; }
	}
	if ( ! function_exists( 'get_post_field' ) ) {
		function get_post_field( string $field, int $id ): string { return (string) ( IATO_Test_WP::$posts[ $id ][ $field ] ?? '' ); }
	}
	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( int $id, string $key, mixed $value ): bool {
			$v = is_string( $value ) ? stripslashes( $value ) : $value;
			IATO_Test_WP::$meta[ $id ][ $key ] = $v;
			IATO_Test_WP::$writes[] = [ $id, $key, $v ];
			IATO_Test_Filters::$hooks_at_write[] = [ $key, count( IATO_Test_Filters::live_elementor_hooks() ) ];
			return true;
		}
	}
	if ( ! function_exists( 'delete_post_meta' ) ) {
		function delete_post_meta( int $id, string $key ): bool { unset( IATO_Test_WP::$meta[ $id ][ $key ] ); return true; }
	}
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		function wp_cache_delete( mixed $key, string $group = '' ): bool { return true; }
	}
	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( mixed $v ): mixed { return is_string( $v ) ? addslashes( $v ) : $v; }
	}
	if ( ! function_exists( 'wp_update_post' ) ) {
		function wp_update_post( array $postarr ): int { IATO_Test_WP::$posts[ $postarr['ID'] ]['post_content'] = $postarr['post_content'] ?? ''; return $postarr['ID']; }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $data, $flags, $depth ); }
	}

	final class ElementorHotfixPipelineTest extends TestCase {
		private const POST    = 777;
		private const PAYLOAD = '<b>Hi</b><script>alert(1)</script><span onclick="alert(1)">x</span>';

		protected function setUp(): void {
			IATO_Test_Filters::reset();
			IATO_Test_Caps::$caps = []; // a request without the unfiltered_html capability
			IATO_Test_WP::$meta   = [ self::POST => [ '_elementor_data' => json_encode( $this->stored() ) ] ];
			IATO_Test_WP::$posts  = [ self::POST => [ 'post_content' => '<h2>old content</h2>' ] ];
			if ( class_exists( 'IATO_Test_Roles' ) ) {
				IATO_Test_Roles::$posts[ self::POST ] = [ 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => 1, 'post_content' => '<h2>old content</h2>' ]; // the get_post() stub another test file loads first
			}
			IATO_Test_WP::$writes = [];
			\Elementor\FakeDocument::$mode     = 'refuse';
			\Elementor\FakeDocument::$received = [];
			IATO_MCP_Elementor_Sanitizer::set_functions( [
				'kses' => fn( string $v ) => 'K[' . strip_tags( $v ) . ']',
				'url'  => fn( string $v ) => str_starts_with( strtolower( $v ), 'javascript:' ) ? '' : $v,
				'css'  => fn( string $v ) => 'C[' . strip_tags( $v ) . ']',
			] );
			// What Elementor 4.3.4 registers on rest_api_init: the plain hook and
			// one _for_<post_type> variant per Elementor-enabled post type.
			$kses = fn( $v ) => $v; // never invoked in these tests; identity is enough
			foreach ( [ 'sanitize_post_meta__elementor_data', 'sanitize_post_meta__elementor_data_for_page', 'sanitize_post_meta__elementor_data_for_post', 'sanitize_post_meta__elementor_page_settings_for_page', 'sanitize_post_meta_other_key' ] as $hook ) {
				IATO_Test_Filters::add( $hook, $kses, 10, 1 );
			}
		}

		protected function tearDown(): void {
			IATO_MCP_Elementor_Sanitizer::set_functions( null );
		}

		private function stored(): array {
			return [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Tom & Jerry', 'header_size' => 'h2' ], 'elements' => [] ],
				[ 'id' => 'w2', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<iframe src="https://maps.example.com/"></iframe><script>var human=1;</script>' ], 'elements' => [] ],
				[ 'id' => 'w3', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Go', 'link' => [ 'url' => 'https://example.com/?a=1&b=2', 'is_external' => '' ] ], 'elements' => [] ],
			] ] ];
		}

		private function stored_meta(): array {
			return json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true );
		}

		public function test_edit_to_one_widget_leaves_every_other_value_byte_for_byte(): void {
			$t = $this->stored();
			$t[0]['elements'][2]['settings']['text'] = 'Edited by bearer';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $t, 'sha256:prev', $this->stored() );
			$this->assertIsArray( $r );
			$this->assertArrayNotHasKey( 'sanitized', $r, 'nothing was altered, nothing reported' );
			$m = $this->stored_meta();
			$this->assertSame( 'Edited by bearer', $m[0]['elements'][2]['settings']['text'] );
			$this->assertSame( $this->stored()[0]['elements'][1], $m[0]['elements'][1], 'HTML widget with iframe and script verbatim' );
			$this->assertSame( 'Tom & Jerry', $m[0]['elements'][0]['settings']['title'], '& not encoded' );
			$this->assertSame( 'https://example.com/?a=1&b=2', $m[0]['elements'][2]['settings']['link']['url'] );
		}

		public function test_changed_leaf_is_sanitised_by_shape(): void {
			$t = $this->stored();
			$t[0]['elements'][0]['settings']['title'] = self::PAYLOAD;
			$t[0]['elements'][2]['settings']['link']['url'] = 'javascript:alert(1)';
			$t[0]['elements'][2]['settings']['text'] = 'Fish & Chips';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $t, 'sha256:prev', $this->stored() );
			$this->assertTrue( $r['sanitized'] );
			$this->assertSame( [ 'w1/settings/title', 'w3/settings/link/url' ], $r['sanitized_paths'] );
			$m = $this->stored_meta();
			$this->assertSame( 'K[Hialert(1)x]', $m[0]['elements'][0]['settings']['title'] );
			$this->assertSame( '', $m[0]['elements'][2]['settings']['link']['url'] );
			$this->assertSame( 'Fish & Chips', $m[0]['elements'][2]['settings']['text'], 'plain text untouched and unreported' );
			$this->assertStringContainsString( '<iframe', $m[0]['elements'][1]['settings']['html'] );
			foreach ( IATO_Test_WP::$writes as $w ) {
				if ( '_elementor_data' === $w[1] ) {
					$this->assertStringNotContainsString( '<script>alert(1)', (string) $w[2], 'the raw payload never reaches the database' );
				}
			}
			$this->assertSame( 'K[Hialert(1)x]', \Elementor\FakeDocument::$received[0]['elements'][0]['elements'][0]['settings']['title'], 'Elementor is handed the sanitised tree' );
		}

		public function test_stored_tree_is_read_from_meta_when_not_passed(): void {
			$t = $this->stored();
			$t[0]['elements'][0]['settings']['title'] = self::PAYLOAD;
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $t, 'sha256:prev' );
			$this->assertSame( [ 'w1/settings/title' ], $r['sanitized_paths'] );
			$this->assertStringContainsString( '<iframe', $this->stored_meta()[0]['elements'][1]['settings']['html'] );
		}

		public function test_unfiltered_html_users_keep_1_12_1_behaviour(): void {
			IATO_Test_Caps::$caps = [ 'unfiltered_html' => true ];
			$t = $this->stored();
			$t[0]['elements'][0]['settings']['title'] = self::PAYLOAD;
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $t, 'sha256:prev', $this->stored() );
			$this->assertArrayNotHasKey( 'sanitized', $r );
			$this->assertSame( self::PAYLOAD, $this->stored_meta()[0]['elements'][0]['settings']['title'] );
		}

		public function test_elementor_meta_callbacks_are_removed_during_the_write_and_restored_after(): void {
			$before = IATO_Test_Filters::live_elementor_hooks();
			$this->assertSame( [ 'sanitize_post_meta__elementor_data', 'sanitize_post_meta__elementor_data_for_page', 'sanitize_post_meta__elementor_data_for_post', 'sanitize_post_meta__elementor_page_settings_for_page' ], $before );
			$t = $this->stored();
			$t[0]['elements'][2]['settings']['text'] = 'x';
			IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $t, 'sha256:prev', $this->stored() );
			$data_writes = array_values( array_filter( IATO_Test_Filters::$hooks_at_write, fn( $w ) => '_elementor_data' === $w[0] ) );
			$this->assertNotEmpty( $data_writes );
			$this->assertSame( 0, $data_writes[0][1], 'no elementor meta hook had callbacks while _elementor_data was written' );
			$this->assertSame( $before, IATO_Test_Filters::live_elementor_hooks(), 'every callback is back afterwards' );
			$this->assertSame( 1, IATO_Test_Filters::count( 'sanitize_post_meta_other_key' ), 'unrelated hooks untouched' );
		}

		public function test_callbacks_are_restored_when_the_write_throws_and_nested_calls_are_safe(): void {
			$before = IATO_Test_Filters::live_elementor_hooks();
			try {
				IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing( function () {
					$this->assertSame( [], IATO_Test_Filters::live_elementor_hooks(), 'removed inside' );
					IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing( function () {
						$this->assertSame( [], IATO_Test_Filters::live_elementor_hooks(), 'still removed in a nested call' );
					} );
					$this->assertSame( [], IATO_Test_Filters::live_elementor_hooks(), 'the inner call did not restore early' );
					throw new RuntimeException( 'boom' );
				} );
				$this->fail( 'exception should propagate' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'boom', $e->getMessage() );
			}
			$this->assertSame( $before, IATO_Test_Filters::live_elementor_hooks(), 'restored after the throw' );
			$this->assertSame( 'ok', IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing( fn() => 'ok' ) );
		}

		public function test_pipeline_with_a_throwing_elementor_save_still_restores_the_callbacks(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			$before = IATO_Test_Filters::live_elementor_hooks();
			$t = $this->stored();
			$t[0]['elements'][2]['settings']['text'] = 'x';
			try {
				IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $t, 'sha256:prev', $this->stored() ); // 1.12.x has no try/catch around save(): the throw propagates
				$this->fail( 'the simulated failure should propagate as in 1.12.1' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( $before, IATO_Test_Filters::live_elementor_hooks() );
				$this->assertSame( 'x', $this->stored_meta()[0]['elements'][2]['settings']['text'], 'the sanitised write had already happened' );
			}
		}

		public function test_without_elementor_callbacks_registered_changed_leaves_are_still_sanitised(): void {
			IATO_Test_Filters::reset(); // an Elementor version that registers no meta sanitize callback
			$t = $this->stored();
			$t[0]['elements'][0]['settings']['title'] = self::PAYLOAD;
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $t, 'sha256:prev', $this->stored() );
			$this->assertSame( [ 'w1/settings/title' ], $r['sanitized_paths'] );
			$this->assertSame( 'K[Hialert(1)x]', $this->stored_meta()[0]['elements'][0]['settings']['title'] );
		}
	}
}
