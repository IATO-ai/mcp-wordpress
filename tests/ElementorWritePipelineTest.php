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
		public function save( array $data ): bool {
			self::$received[] = $data;
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
	final class FakeDocuments { public function get( int $id ) { return new FakeDocument(); } }
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
		function update_post_meta( int $id, string $key, mixed $value ): bool { IATO_Test_WP::$meta[ $id ][ $key ] = is_string( $value ) ? stripslashes( $value ) : $value; return true; }
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

	final class ElementorWritePipelineTest extends TestCase {
		private const POST = ElementorWritePipelineHelper::POST;

		private function tree(): array {
			return [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Edited title', 'header_size' => 'h2' ], 'elements' => [] ],
			] ] ];
		}

		protected function setUp(): void {
			IATO_Test_WP::$meta  = [ self::POST => [ '_elementor_data' => '[]' ] ];
			IATO_Test_WP::$posts = [ self::POST => [ 'post_content' => '<h2>old content</h2>' ] ];
			\Elementor\FakeDocument::$received = [];
			\Elementor\FakeFrontend::$renders  = 0;
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
