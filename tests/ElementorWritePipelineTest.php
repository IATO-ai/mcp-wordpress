<?php
declare( strict_types=1 );

/**
 * What the Elementor write pipeline does when Elementor's Document::save()
 * throws or refuses during a classic-widget edit (Sprint C0 question).
 *
 * The stubs below model exactly the WordPress and Elementor calls
 * IATO_MCP_Elementor_Adapter::write_pipeline() makes.
 */

// ── Fake Elementor ─────────────────────────────────────────────────────────
namespace Elementor {
	final class FakeDocument {
		/** @var 'ok'|'refuse'|'throw' */
		public static string $mode = 'ok';
		public static array $received = [];
		/** @var array<int,array> map_meta_cap for unfiltered_html as seen inside save() */
		public static array $cap_during_save = [];
		public function save( array $data ): bool {
			self::$received[] = $data;
			self::$cap_during_save[] = \apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] );
			if ( 'throw' === self::$mode ) {
				throw new \RuntimeException( 'Settings validation failed. simulated Elementor failure' );
			}
			if ( 'refuse' === self::$mode ) {
				return false; // what Elementor does for a user who may not edit the post (e.g. user 0)
			}
			// On a real save Elementor re-encodes the data and renders post_content.
			\IATO_Test_WP::$meta[ \ElementorWritePipelineHelper::POST ]['_elementor_data'] = json_encode( \ElementorWritePipelineHelper::reencode( $data['elements'] ) );
			\IATO_Test_WP::$posts[ \ElementorWritePipelineHelper::POST ]['post_content'] = '<h2>rendered by elementor</h2>';
			return true;
		}
	}
	final class FakeDocuments { public static bool $absent = false; public function get( int $id ) { return self::$absent ? null : new FakeDocument(); } }
	final class FakeFilesManager { public static int $cleared = 0; public function clear_cache(): void { self::$cleared++; } }
	final class FakeFrontend {
		public static int $renders = 0;
		public function get_builder_content( int $id, bool $with_css = false ): string { self::$renders++; return '<h2>frontend fallback render</h2>'; }
	}
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

// ── WordPress stubs the pipeline calls ────────────────────────────────────
namespace {
	use PHPUnit\Framework\TestCase;

	final class ElementorWritePipelineHelper {
		public const POST = 777;
		/** Elementor's save normalises nodes: adds isInner/version-ish noise and reorders keys. */
		public static function reencode( array $elements ): array {
			$out = [];
			foreach ( $elements as $el ) {
				$node = [ 'id' => $el['id'], 'elType' => $el['elType'] ];
				if ( isset( $el['widgetType'] ) ) { $node['widgetType'] = $el['widgetType']; }
				$node['settings'] = $el['settings'] ?? [];
				$node['elements'] = self::reencode( $el['elements'] ?? [] );
				$node['isInner']  = false;
				$out[] = $node;
			}
			return $out;
		}
	}
	if ( ! function_exists( 'get_post' ) ) {
		function get_post( int $id ): ?object { return isset( IATO_Test_WP::$posts[ $id ] ) ? (object) ( [ 'ID' => $id ] + IATO_Test_WP::$posts[ $id ] ) : null; }
	}
	if ( ! function_exists( 'get_post_field' ) ) {
		function get_post_field( string $field, int $id ): string { return (string) ( IATO_Test_WP::$posts[ $id ][ $field ] ?? '' ); }
	}
	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( int $id, string $key, mixed $value ): bool { $v = is_string( $value ) ? stripslashes( $value ) : ( is_array( $value ) ? IATO_Test_WP::unslash_deep( $value ) : $value ); IATO_Test_WP::$meta[ $id ][ $key ] = $v; IATO_Test_WP::$writes[] = [ $id, $key, $v ]; IATO_Test_WP::note_write( $key ); return true; }
	}
	if ( ! function_exists( 'delete_post_meta' ) ) {
		function delete_post_meta( int $id, string $key ): bool { unset( IATO_Test_WP::$meta[ $id ][ $key ] ); return true; }
	}
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		function wp_cache_delete( mixed $key, string $group = '' ): bool { return true; }
	}
	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( mixed $v ): mixed { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
	}
	if ( ! function_exists( 'wp_update_post' ) ) {
		function wp_update_post( array $postarr ): int { IATO_Test_WP::$posts[ $postarr['ID'] ]['post_content'] = stripslashes( (string) ( $postarr['post_content'] ?? '' ) ); return $postarr['ID']; } // core unslashes
	}

	final class ElementorWritePipelineTest extends TestCase {
		private const POST = ElementorWritePipelineHelper::POST;

		private function tree(): array {
			return [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Edited title', 'header_size' => 'h2' ], 'elements' => [] ],
			] ] ];
		}

		protected function setUp(): void {
			IATO_Test_WP::$meta   = [ self::POST => [ '_elementor_data' => '[]' ] ];
			IATO_Test_WP::$posts  = [ self::POST => [ 'post_content' => '<h2>old content</h2>' ] ];
			IATO_Test_WP::$writes = [];
			\Elementor\FakeDocument::$received = [];
			\Elementor\FakeDocuments::$absent  = false;
			\Elementor\FakeFrontend::$renders  = 0;
			// Marker sanitisers: the pass is proven by the markers, the real
			// wp_kses_post / esc_url_raw behaviour by the live suite.
			IATO_MCP_Elementor_Sanitizer::set_functions( [
				'kses' => fn( string $v ) => 'K[' . strip_tags( $v ) . ']',
				'url'  => fn( string $v ) => str_starts_with( strtolower( $v ), 'javascript:' ) ? '' : $v,
				'css'  => fn( string $v ) => 'C[' . strip_tags( $v ) . ']',
			] );
			self::allow_unfiltered( false );
			// What Elementor 4.3.4 registers on rest_api_init: the plain hook and
			// one _for_<post_type> variant per Elementor-enabled post type.
			IATO_Test_WP::reset_hooks();
			$kses = fn( $v ) => $v; // never invoked in these tests; identity is enough
			foreach ( self::ELEMENTOR_HOOKS as $hook ) {
				IATO_Test_WP::add_hook( $hook, $kses, 10, 1 );
			}
			IATO_Test_WP::add_hook( 'sanitize_post_meta_other_key', $kses, 10, 1 );
		}

		protected function tearDown(): void {
			IATO_MCP_Elementor_Sanitizer::set_functions( null );
			self::allow_unfiltered( false );
			unset( $GLOBALS['wp_filter'] );
		}

		private const ELEMENTOR_HOOKS = [ 'sanitize_post_meta__elementor_data', 'sanitize_post_meta__elementor_data_for_page', 'sanitize_post_meta__elementor_data_for_post', 'sanitize_post_meta__elementor_page_settings_for_page' ];

		private static function allow_unfiltered( bool $allow ): void {
			$ref = new ReflectionClass( IATO_MCP_Auth::class );
			$ref->getProperty( 'unfiltered_html_allowed' )->setValue( null, $allow );
		}

		private const PAYLOAD = '<b>Hi</b><script>alert(1)</script><span onclick="alert(1)">x</span>';

		/** The stored page: a human-authored HTML widget (iframe + script) next to the heading the request edits. */
		private function stored(): array {
			return [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Old title', 'header_size' => 'h2' ], 'elements' => [] ],
				[ 'id' => 'w2', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<iframe src="https://maps.example.com/"></iframe><script>var human=1;</script>' ], 'elements' => [] ],
			] ] ];
		}

		/** The same page with the heading changed to the payload. */
		private function edited(): array {
			$t = $this->stored();
			$t[0]['elements'][0]['settings']['title'] = self::PAYLOAD;
			return $t;
		}

		private function meta_writes_of( string $key ): array {
			return array_values( array_filter( IATO_Test_WP::$writes, fn( $w ) => $w[1] === $key ) );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'fallback_modes' )]
		public function test_fallback_sanitises_only_what_changed( string $mode ): void {
			if ( 'absent' === $mode ) {
				\Elementor\FakeDocuments::$absent = true;
			} else {
				\Elementor\FakeDocument::$mode = $mode;
			}
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );

			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );

			$this->assertIsArray( $r );
			$this->assertSame( 'plugin', $r['stored_by'] );
			$this->assertSame( 'absent' === $mode ? null : ( 'throw' === $mode ? 'error' : false ), $r['elementor_save'] );
			$this->assertTrue( $r['sanitized'] );
			$this->assertSame( [ 'w1/settings/title' ], $r['sanitized_paths'] );
			$stored = json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true );
			$this->assertSame( 'K[Hi' . 'alert(1)' . 'x]', $stored[0]['elements'][0]['settings']['title'], 'the changed value went through the kses marker' );
			$this->assertStringContainsString( '<iframe', $stored[0]['elements'][1]['settings']['html'], 'the untouched HTML widget survives verbatim' );
			$this->assertStringContainsString( '<script>var human=1;</script>', $stored[0]['elements'][1]['settings']['html'] );
			foreach ( $this->meta_writes_of( '_elementor_data' ) as $w ) {
				$this->assertStringNotContainsString( '<script>alert(1)', (string) $w[2], 'the raw payload was never written, not even transiently' );
			}
			$this->assertTrue( $r['meta_persisted'] );
		}

		public static function fallback_modes(): array {
			return [ [ 'throw' ], [ 'refuse' ], [ 'absent' ] ];
		}

		public function test_success_path_hands_elementor_the_sanitised_tree_and_writes_nothing_itself(): void {
			\Elementor\FakeDocument::$mode = 'ok';
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertSame( 'elementor', $r['stored_by'] );
			$this->assertTrue( $r['elementor_save'] );
			$this->assertTrue( $r['sanitized'] );
			$this->assertSame( 'K[Hialert(1)x]', \Elementor\FakeDocument::$received[0]['elements'][0]['elements'][0]['settings']['title'], 'Elementor received the sanitised value' );
			$this->assertSame( [], $this->meta_writes_of( '_elementor_data' ), 'no plugin write of _elementor_data when Elementor stored it' );
		}

		public function test_nothing_reported_when_nothing_was_altered(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			$t = $this->stored();
			$t[0]['elements'][0]['settings']['title'] = 'Tom & Jerry';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $t, 'sha256:prev', $this->stored() );
			$this->assertArrayNotHasKey( 'sanitized', $r );
			$this->assertArrayNotHasKey( 'sanitized_paths', $r );
			$this->assertSame( 'Tom & Jerry', json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][0]['settings']['title'] );
		}

		public function test_stored_tree_is_read_from_meta_when_the_caller_passes_none(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev' );
			$this->assertSame( [ 'w1/settings/title' ], $r['sanitized_paths'] );
			$this->assertStringContainsString( '<iframe', json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][1]['settings']['html'] );
		}

		public function test_unfiltered_html_allowed_keeps_the_raw_payload(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			self::allow_unfiltered( true );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertArrayNotHasKey( 'sanitized', $r );
			$this->assertSame( self::PAYLOAD, json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][0]['settings']['title'] );
		}

		public function test_dry_run_preview_reports_the_same_paths(): void {
			$this->assertSame( [ 'sanitized' => true, 'sanitized_paths' => [ 'w1/settings/title' ] ], IATO_MCP_Elementor_Adapter::sanitize_preview( $this->edited(), $this->stored() ) );
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::sanitize_preview( $this->stored(), $this->stored() ) );
			self::allow_unfiltered( true );
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::sanitize_preview( $this->edited(), $this->stored() ) );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'lift_modes' )]
		public function test_kses_lock_is_lifted_only_during_elementors_save( string $mode ): void {
			\Elementor\FakeDocument::$mode            = $mode;
			\Elementor\FakeDocument::$cap_during_save = [];
			// Lock as authenticate() does for a bound key / OAuth token.
			$ref = new ReflectionClass( IATO_MCP_Auth::class );
			$ref->getProperty( 'kses_locked' )->setValue( null, true );
			add_filter( 'map_meta_cap', [ IATO_MCP_Auth::class, 'filter_map_meta_cap' ], 10, 4 );
			$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'locked before' );

			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );

			$this->assertSame( [ [ 'unfiltered_html' ] ], \Elementor\FakeDocument::$cap_during_save, 'inside the save the user\'s real capability shows through' );
			$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'locked again after the save, also when it threw' );
			$this->assertSame( 'throw' === $mode ? 'error' : true, $r['elementor_save'] );
			$this->assertSame( 'K[Hialert(1)x]', \Elementor\FakeDocument::$received[0]['elements'][0]['elements'][0]['settings']['title'], 'Elementor still receives the sanitised tree' );
			$ref->getProperty( 'kses_locked' )->setValue( null, false );
		}

		public static function lift_modes(): array {
			return [ [ 'ok' ], [ 'throw' ] ];
		}

		// ── Elementor's REST meta sanitize callbacks are kept out of the plugin's own writes (from 1.12.2) ──

		public function test_elementor_meta_callbacks_are_removed_during_the_fallback_write_and_restored_after(): void {
			\Elementor\FakeDocument::$mode = 'refuse'; // user 0: Elementor refuses, the plugin writes
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks() );
			IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$data_writes = array_values( array_filter( IATO_Test_WP::$hooks_at_write, fn( $w ) => '_elementor_data' === $w[0] ) );
			$this->assertNotEmpty( $data_writes );
			$this->assertSame( 0, $data_writes[0][1], 'no elementor meta hook had callbacks while _elementor_data was written' );
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks(), 'every callback is back afterwards' );
			$this->assertSame( 1, IATO_Test_WP::hook_count( 'sanitize_post_meta_other_key' ), 'unrelated hooks untouched' );
		}

		public function test_callbacks_are_restored_when_the_write_throws_and_nested_calls_are_safe(): void {
			try {
				IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing( function () {
					$this->assertSame( [], IATO_Test_WP::live_elementor_hooks(), 'removed inside' );
					IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing( function () {
						$this->assertSame( [], IATO_Test_WP::live_elementor_hooks(), 'still removed in a nested call' );
					} );
					$this->assertSame( [], IATO_Test_WP::live_elementor_hooks(), 'the inner call did not restore early' );
					throw new RuntimeException( 'boom' );
				} );
				$this->fail( 'exception should propagate' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'boom', $e->getMessage() );
			}
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks(), 'restored after the throw' );
			$this->assertSame( 'ok', IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing( fn() => 'ok' ) );
		}

		public function test_write_elementor_meta_lifts_the_lock_and_removes_the_callbacks_together(): void {
			$ref = new ReflectionClass( IATO_MCP_Auth::class );
			$ref->getProperty( 'kses_locked' )->setValue( null, true );
			$seen = IATO_MCP_Elementor_Adapter::write_elementor_meta( function () use ( $ref ) {
				return [ $ref->getProperty( 'kses_locked' )->getValue(), IATO_Test_WP::live_elementor_hooks() ];
			} );
			$this->assertSame( [ false, [] ], $seen, 'inside: lock lifted, callbacks gone' );
			$this->assertTrue( $ref->getProperty( 'kses_locked' )->getValue(), 'lock back' );
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks(), 'callbacks back' );
			$ref->getProperty( 'kses_locked' )->setValue( null, false );
		}

		public function test_a_throwing_elementor_save_leaves_the_callbacks_in_place_for_the_fallback_write(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertSame( 'error', $r['elementor_save'] );
			$this->assertSame( 'plugin', $r['stored_by'] );
			$data_writes = array_values( array_filter( IATO_Test_WP::$hooks_at_write, fn( $w ) => '_elementor_data' === $w[0] ) );
			$this->assertSame( 0, $data_writes[0][1], 'the fallback write ran without the callbacks' );
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks(), 'and they are back afterwards' );
		}

		public function test_without_elementor_callbacks_registered_changed_leaves_are_still_sanitised(): void {
			IATO_Test_WP::reset_hooks(); // an Elementor version that registers no meta sanitize callback
			\Elementor\FakeDocument::$mode = 'refuse';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertSame( [ 'w1/settings/title' ], $r['sanitized_paths'] );
			$this->assertSame( 'K[Hialert(1)x]', json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][0]['settings']['title'] );
		}

		public function test_decode_data_accepts_a_tree_stored_as_an_array(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = $this->stored(); // a pre-1.13.0 update_post_meta write
			$r = IATO_MCP_Elementor_Adapter::decode_data( self::POST );
			$this->assertIsArray( $r );
			$this->assertSame( 'w1', $r[0][0]['elements'][0]['id'] );
		}

		public function test_elementor_save_throws_the_tool_still_completes_and_reports_it(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'sha256:prev' );

			$this->assertIsArray( $r, 'no WP_Error, no uncaught exception' );
			$this->assertSame( 'error', $r['elementor_save'] );
			$this->assertStringContainsString( 'simulated Elementor failure', $r['elementor_save_error'] );
			// The plugin's own direct write happened first and is what is stored.
			$this->assertSame( json_encode( $this->tree() ), IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] );
			$this->assertTrue( $r['meta_persisted'] );
			// post_content came from the frontend fallback, not from Elementor's save.
			$this->assertSame( 1, \Elementor\FakeFrontend::$renders );
			$this->assertSame( '<h2>frontend fallback render</h2>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
			$this->assertTrue( $r['content_updated'] );
			$this->assertSame( 'sha256:' . hash( 'sha256', json_encode( $this->tree() ) ), $r['current_revision'] );
		}

		public function test_elementor_save_refuses_same_fallback_reported_as_false(): void {
			\Elementor\FakeDocument::$mode = 'refuse';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'sha256:prev' );
			$this->assertFalse( $r['elementor_save'] );
			$this->assertArrayNotHasKey( 'elementor_save_error', $r );
			$this->assertTrue( $r['meta_persisted'] );
			$this->assertSame( 1, \Elementor\FakeFrontend::$renders );
			$this->assertSame( '<h2>frontend fallback render</h2>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
		}

		public function test_elementor_save_succeeds_reencoded_meta_still_counts_as_persisted(): void {
			\Elementor\FakeDocument::$mode = 'ok';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'sha256:prev' );
			$this->assertTrue( $r['elementor_save'] );
			$this->assertSame( 0, \Elementor\FakeFrontend::$renders, 'no fallback when Elementor rendered' );
			$this->assertSame( '<h2>rendered by elementor</h2>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
			$stored = IATO_Test_WP::$meta[ self::POST ]['_elementor_data'];
			$this->assertNotSame( json_encode( $this->tree() ), $stored, 'Elementor re-encoded the tree' );
			$this->assertTrue( $r['meta_persisted'], 'structural check: same ids, same nesting' );
			$this->assertNotSame( strlen( json_encode( $this->tree() ) ), strlen( $stored ), 'the old strlen check would have reported false here' );
			$this->assertSame( 'sha256:' . hash( 'sha256', $stored ), $r['current_revision'], 'revision is of what is actually stored' );
		}

		public function test_structural_check_detects_a_lost_write(): void {
			$this->assertFalse( IATO_MCP_Elementor_Adapter::same_element_tree( '[]', $this->tree() ) );
			$this->assertFalse( IATO_MCP_Elementor_Adapter::same_element_tree( 'not json', $this->tree() ) );
			$this->assertTrue( IATO_MCP_Elementor_Adapter::same_element_tree( json_encode( ElementorWritePipelineHelper::reencode( $this->tree() ) ), $this->tree() ) );
		}
	}
}
