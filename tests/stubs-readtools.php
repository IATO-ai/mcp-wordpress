<?php
/**
 * Stubs for the Part 4 read-tool permission tests: comments, menus, site
 * info. Every function is guarded.
 */

declare( strict_types=1 );

final class IATO_Test_Read {
	/** @var array<int,object> comments: comment_ID, comment_post_ID, comment_approved ('1','0','spam','trash') */
	public static array $comments = [];
	/** @var array<int,array> get_comments() calls */
	public static array $comment_queries = [];
	/** @var array<int,array<int,object>> menu id => items */
	public static array $menu_items = [];

	public static function reset(): void {
		self::$comments        = [];
		self::$comment_queries = [];
		self::$menu_items      = [];
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
}
if ( ! function_exists( 'get_comments' ) ) {
	function get_comments( array $args ): array {
		IATO_Test_Read::$comment_queries[] = $args;
		$want = [ 'approve' => [ '1' ], 'hold' => [ '0' ], 'spam' => [ 'spam' ], 'trash' => [ 'trash' ], 'all' => [ '1', '0' ] ][ $args['status'] ] ?? [];
		$rows = array_values( array_filter( IATO_Test_Read::$comments, fn( $c ) => in_array( $c->comment_approved, $want, true ) && ( empty( $args['post_id'] ) || (int) $c->comment_post_ID === (int) $args['post_id'] ) ) );
		return array_slice( $rows, (int) ( $args['offset'] ?? 0 ), (int) $args['number'] );
	}
}
if ( ! function_exists( 'wp_get_comment_status' ) ) {
	function wp_get_comment_status( object $c ): string { return [ '1' => 'approved', '0' => 'unapproved' ][ $c->comment_approved ] ?? $c->comment_approved; }
}
if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
	function wp_get_nav_menu_items( int $menu ): array|false { return IATO_Test_Read::$menu_items[ $menu ] ?? false; }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string { return 'version' === $show ? '7.1.3' : 'Test Site'; }
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url(): string { return 'http://localhost:8888'; }
}
if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme(): object { return new class { public function get( string $k ): string { return 'Hello Elementor'; } }; }
}
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( string $type ): bool { return false; }
}
