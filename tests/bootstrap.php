<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs so the Elementor read layer can
 * be exercised on fixture JSON without booting WordPress.
 *
 * Only the classes/functions the read path touches are stubbed. Anything
 * else is a loud fatal, which is the point: the reader must stay WP-light.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/stub-abspath/' );
define( 'IATO_MCP_DIR', dirname( __DIR__ ) . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public array $errors     = [];
		public array $error_data = [];
		public function __construct( string $code = '', mixed $message = '', mixed $data = null ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( null !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}
		public function get_error_code(): string {
			return (string) ( array_key_first( $this->errors ) ?? '' );
		}
		public function get_error_message( string $code = '' ): mixed {
			$code = '' === $code ? $this->get_error_code() : $code;
			return $this->errors[ $code ][0] ?? '';
		}
		public function get_error_data( string $code = '' ): mixed {
			$code = '' === $code ? $this->get_error_code() : $code;
			return $this->error_data[ $code ] ?? null;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

/**
 * Fake attachment / permalink table used by the default resolver stubs. Tests
 * may also inject their own resolver via IATO_MCP_Elementor_Atomic::set_resolver().
 */
final class IATO_Test_WP {
	public static array $attachments = [
		5 => [
			'alt' => 'Library image alt text',
			'url' => 'http://localhost:8888/wp-content/uploads/2026/10/iato-fixture-image.png',
		],
	];
	public static array $permalinks = [
		42 => 'http://localhost:8888/sample-page/',
	];
	/** @var array<int,array<string,mixed>> generic post meta store: [post_id][key] */
	public static array $meta = [];
	/** @var array<int,array<string,mixed>> fake posts: [post_id] => [ 'post_content' => ... ] */
	public static array $posts = [];
	/** @var array<int,array{0:int,1:string,2:mixed}> every update_post_meta() call, in order */
	public static array $writes = [];
	/** @var array<int,array{0:string,1:int}> (meta key, number of live elementor meta hooks) at each update_post_meta() call */
	public static array $hooks_at_write = [];

	// ── $wp_filter model for IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing() ──
	public static function reset_hooks(): void {
		$GLOBALS['wp_filter'] = [];
		self::$hooks_at_write = [];
	}
	public static function add_hook( string $hook, callable $fn, int $priority = 10, int $args = 1 ): void {
		$GLOBALS['wp_filter'][ $hook ] ??= new WP_Hook();
		$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ 'cb' . count( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ] ?? [] ) . spl_object_id( (object) [] ) ] = [ 'function' => $fn, 'accepted_args' => $args ];
	}
	public static function hook_count( string $hook ): int {
		$n = 0;
		foreach ( ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? [] ) as $cbs ) {
			$n += count( $cbs );
		}
		return $n;
	}
	/** The elementor meta hooks that currently have callbacks, sorted. */
	public static function live_elementor_hooks(): array {
		$out = [];
		foreach ( ( $GLOBALS['wp_filter'] ?? [] ) as $hook => $h ) {
			if ( str_starts_with( $hook, 'sanitize_post_meta__elementor_' ) && self::hook_count( $hook ) > 0 ) {
				$out[] = $hook;
			}
		}
		sort( $out );
		return $out;
	}
	/** wp_unslash() on arrays, as update_metadata() applies it. */
	public static function unslash_deep( array $value ): array {
		return array_map( fn( $v ) => is_array( $v ) ? self::unslash_deep( $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ), $value );
	}
	public static function note_write( string $key ): void {
		self::$hooks_at_write[] = [ $key, count( self::live_elementor_hooks() ) ];
	}
}
/** Minimal WP_Hook: callbacks[priority][id] = [function, accepted_args]. */
if ( ! class_exists( 'WP_Hook' ) ) {
	class WP_Hook {
		public array $callbacks = [];
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		if ( '_wp_attachment_image_alt' === $key ) {
			return IATO_Test_WP::$attachments[ $post_id ]['alt'] ?? '';
		}
		if ( array_key_exists( $post_id, IATO_Test_WP::$meta ) && array_key_exists( $key, IATO_Test_WP::$meta[ $post_id ] ) ) {
			return IATO_Test_WP::$meta[ $post_id ][ $key ];
		}
		return $single ? '' : [];
	}
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( int $id, string $size = 'thumbnail' ): string|false {
		return IATO_Test_WP::$attachments[ $id ]['url'] ?? false;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post_id ): string|false {
		return IATO_Test_WP::$permalinks[ $post_id ] ?? false;
	}
}

require_once __DIR__ . '/stubs-abilities.php';
require_once __DIR__ . '/stubs-auth.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-atomic.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-sanitizer.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-adapter.php';
require_once IATO_MCP_DIR . 'includes/class-mcp-server.php';
require_once IATO_MCP_DIR . 'includes/class-abilities.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-oauth-store.php';
require_once __DIR__ . '/stubs-oauth.php';
require_once IATO_MCP_DIR . 'includes/class-oauth.php';
require_once IATO_MCP_DIR . 'includes/class-schema.php';

/** Load a fixture file as the decoded element list (same shape decode_data() returns). */
function iato_test_fixture( string $name ): array {
	$path = __DIR__ . '/fixtures/elementor/' . $name . '.json';
	$raw  = file_get_contents( $path );
	$data = json_decode( $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
		throw new RuntimeException( "Fixture {$name} is not valid JSON" );
	}
	return IATO_MCP_Elementor_Adapter::force_arrays( $data );
}

/**
 * The read-layer views the snapshot tests compare: flat list, tree, per-node
 * summary and a few representative find_by_filter queries.
 */
function iato_test_read_snapshot( array $elements ): array {
	$all_widgets = [];
	IATO_MCP_Elementor_Adapter::walk_widgets( $elements, function ( array $el ) use ( &$all_widgets ) {
		$all_widgets[] = IATO_MCP_Elementor_Adapter::summary( $el );
	} );
	return [
		'flat'              => IATO_MCP_Elementor_Adapter::flatten_widgets( $elements, 'flat' ),
		'tree'              => IATO_MCP_Elementor_Adapter::flatten_widgets( $elements, 'tree' ),
		'summary'           => $all_widgets,
		'find_untyped'      => IATO_MCP_Elementor_Adapter::find_by_filter( $elements, [], 1 ),
		'find_e_heading'    => IATO_MCP_Elementor_Adapter::find_by_filter( $elements, [ 'type' => 'e-heading' ], 1 ),
		'find_h1'           => IATO_MCP_Elementor_Adapter::find_by_filter( $elements, [ 'setting' => [ 'header_size' => [ 'eq' => 'h1' ] ] ], 1 ),
		'find_title_atomic' => IATO_MCP_Elementor_Adapter::find_by_filter( $elements, [ 'setting' => [ 'title' => [ 'contains' => 'atomic' ] ] ], 1 ),
		'find_e_flexbox'    => IATO_MCP_Elementor_Adapter::find_by_filter( $elements, [ 'type' => 'e-flexbox' ], 1 ),
		'document_schema'   => IATO_MCP_Elementor_Atomic::document_schema( $elements ),
	];
}
