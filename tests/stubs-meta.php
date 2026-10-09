<?php
/**
 * Stubs for running the update_post_meta tool handler without WordPress.
 * Every function is guarded; tests/ElementorWritePipelineTest.php declares
 * compatible versions of some of them (same semantics over IATO_Test_WP).
 */

declare( strict_types=1 );

if ( ! class_exists( 'IATO_Test_WPDB' ) ) {
	final class IATO_Test_WPDB {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		/** @var array<int,array{0:string,1:array}> */
		public array $inserts = [];
		public function insert( string $table, array $data, mixed $format = null ): int { $this->inserts[] = [ $table, $data ]; return 1; }
		public function get_charset_collate(): string { return ''; }
		public function prepare( string $q, mixed ...$a ): string { return $q; }
		public function get_row( mixed ...$a ): ?array { return null; }
		public function get_results( mixed ...$a ): array { return []; }
		public function get_var( mixed ...$a ): mixed { return null; }
		public function query( mixed ...$a ): int { return 0; }
	}
}
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new IATO_Test_WPDB();
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ): ?object { return isset( IATO_Test_WP::$posts[ $id ] ) ? (object) ( [ 'ID' => $id ] + IATO_Test_WP::$posts[ $id ] ) : null; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $id, string $key, mixed $value ): bool { $v = is_string( $value ) ? stripslashes( $value ) : $value; IATO_Test_WP::$meta[ $id ][ $key ] = $v; IATO_Test_WP::$writes[] = [ $id, $key, $v ]; IATO_Test_WP::note_write( $key ); return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $id, string $key ): bool { unset( IATO_Test_WP::$meta[ $id ][ $key ] ); return true; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( mixed $key, string $group = '' ): bool { return true; }
}
if ( ! function_exists( 'clean_post_cache' ) ) {
	function clean_post_cache( mixed $post ): void {}
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( mixed $v ): mixed { return $v; }
}
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( mixed $v ): mixed { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( mixed $v ): mixed { return is_string( $v ) ? addslashes( $v ) : $v; }
}
