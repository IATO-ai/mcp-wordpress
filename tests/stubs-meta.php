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
		public string $posts = 'wp_posts';
		/** @var array<int,array{0:string,1:array}> */
		public array $inserts = [];
		public function insert( string $table, array $data, mixed $format = null ): int { $this->inserts[] = [ $table, $data ]; return 1; }
		/** @var array<int,array{0:string,1:array,2:array}> */
		public array $updates = [];
		public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int { $this->updates[] = [ $table, $data, $where ]; return 1; }
		public function get_charset_collate(): string { return ''; }
		/** @var array<int,array{0:string,1:array}> every prepare() call: [query, args] */
		public array $prepared = [];
		/** @var int[] values query() returns, in order (0 once empty) */
		public array $query_results = [];
		/** @var array<int,mixed> every query() call */
		public array $queries = [];
		public function prepare( string $q, mixed ...$a ): string { $this->prepared[] = [ $q, $a ]; return $q; }
		/** @var object|null what get_row() returns (an autosave row in ElementorWriterAtomicTest) */
		public ?object $row = null;
		public function get_row( mixed ...$a ): mixed { return $this->row; }
		public function esc_like( string $t ): string { return addcslashes( $t, '_%\\' ); }
		public function get_results( mixed ...$a ): array { return []; }
		public function get_var( mixed ...$a ): mixed { return null; }
		public function query( mixed ...$a ): int { $this->queries[] = $a[0] ?? null; return array_shift( $this->query_results ) ?? 0; }
	}
}
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new IATO_Test_WPDB();
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ): ?object { return isset( IATO_Test_WP::$posts[ $id ] ) ? (object) ( [ 'ID' => $id ] + IATO_Test_WP::$posts[ $id ] ) : null; }
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
	function wp_slash( mixed $v ): mixed { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
}
