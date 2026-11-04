<?php
/**
 * Stubs for the cache plugins' public functions and the WordPress calls
 * IATO_MCP_Cache makes. Each records what was called. Loading this file makes
 * every plugin "present" as far as function_exists() goes, so the tests use
 * IATO_MCP_Cache::set_detector() to choose which ones count; a plugin that truly
 * is absent is covered by CachePurgeAbsentTest, which does not load this file.
 *
 * @package IATO_MCP
 */

declare( strict_types=1 );

final class IATO_Test_Cache_State {
	/** @var string[] every purge call, in order, as "name" or "name:arg" */
	public static array $calls = [];
	/** @var array<int,string> post id => post type */
	public static array $post_types = [];
	/** @var array<string,mixed> */
	public static array $transients = [];
	public static array|\WP_Error $head_response = [ 'headers' => [] ];
	public static int $head_requests = 0;
	/** Names whose call throws. */
	public static array $throw = [];
	/** @var string[] registered post types with no public page */
	public static array $not_viewable = [];

	public static function reset(): void {
		self::$calls         = [];
		self::$post_types    = [];
		self::$transients    = [];
		self::$head_response = [ 'headers' => [] ];
		self::$head_requests = 0;
		self::$throw         = [];
		self::$not_viewable  = [];
	}

	public static function call( string $name, mixed $arg = null ): void {
		self::$calls[] = null === $arg ? $name : $name . ':' . ( is_scalar( $arg ) ? (string) $arg : wp_json_encode( $arg ) );
		if ( in_array( $name, self::$throw, true ) ) {
			throw new RuntimeException( $name . ' failed' );
		}
	}
}

// ── The cache plugins ─────────────────────────────────────────────────────
if ( ! function_exists( 'rocket_clean_post' ) ) {
	function rocket_clean_post( int $id ): bool { IATO_Test_Cache_State::call( 'rocket_clean_post', $id ); return false; } // returns false after a valid purge, like the real one
	function rocket_clean_domain(): bool { IATO_Test_Cache_State::call( 'rocket_clean_domain' ); return true; }
	function rocket_clean_home(): void { IATO_Test_Cache_State::call( 'rocket_clean_home' ); }
	function w3tc_flush_post( int $id ): void { IATO_Test_Cache_State::call( 'w3tc_flush_post', $id ); }
	function w3tc_flush_all(): void { IATO_Test_Cache_State::call( 'w3tc_flush_all' ); }
	function w3tc_flush_url( string $url ): void { IATO_Test_Cache_State::call( 'w3tc_flush_url', $url ); }
	function wp_cache_post_change( int $id ): void { IATO_Test_Cache_State::call( 'wp_cache_post_change', $id ); }
	function wp_cache_clear_cache(): void { IATO_Test_Cache_State::call( 'wp_cache_clear_cache' ); }
	function sg_cachepress_purge_cache( ?string $url = null ): void { IATO_Test_Cache_State::call( 'sg_cachepress_purge_cache', $url ); }
	function sg_cachepress_purge_everything(): void { IATO_Test_Cache_State::call( 'sg_cachepress_purge_everything' ); }
}

// ── WordPress ─────────────────────────────────────────────────────────────
if ( ! function_exists( 'has_action' ) ) {
	function has_action( string $hook ): bool { return true; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		if ( str_starts_with( $hook, 'litespeed_' ) || str_starts_with( $hook, 'wpfc_' ) ) {
			IATO_Test_Cache_State::call( $hook, $args[ count( $args ) - 1 ] ?? null );
		}
		foreach ( IATO_Test_Abilities_State::$actions[ $hook ] ?? [] as $cb ) {
			$cb( ...$args );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int { return 0; }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $id ): string|false { return IATO_Test_Cache_State::$post_types[ $id ] ?? ( IATO_Test_WP::$posts[ $id ]['post_type'] ?? false ); }
}
if ( ! function_exists( 'post_type_exists' ) ) {
	// Only the types a test registered: other test files rely on elementor_library being absent.
	function post_type_exists( string $type ): bool { return in_array( $type, IATO_Test_Cache_State::$not_viewable, true ); }
}
if ( ! function_exists( 'is_post_type_viewable' ) ) {
	function is_post_type_viewable( string $type ): bool { return ! in_array( $type, IATO_Test_Cache_State::$not_viewable, true ); }
}
if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( string $url, array $args = [] ): array|\WP_Error { ++IATO_Test_Cache_State::$head_requests; return IATO_Test_Cache_State::$head_response; }
}
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( array $response ): array { return $response['headers'] ?? []; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string { return trim( strip_tags( $text ) ); }
}
if ( ! function_exists( 'sanitize_option' ) ) {
	function sanitize_option( string $option, mixed $value ): mixed {
		if ( in_array( $option, [ 'page_on_front', 'page_for_posts' ], true ) ) {
			return abs( (int) $value );
		}
		if ( in_array( $option, [ 'blogname', 'blogdescription' ], true ) ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8', false ); // what esc_html does
		}
		return $value;
	}
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( bool $hard = true ): void { IATO_Test_Cache_State::$calls[] = 'flush_rewrite_rules:' . ( $hard ? 'hard' : 'soft' ); }
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url( string $path = '' ): string { return 'https://site.example' . $path; }
}
