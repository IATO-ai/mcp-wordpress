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
		/** @var array<int,int|false> values insert() returns, in order (1 once empty) */
		public array $insert_results = [];
		public function insert( string $table, array $data, mixed $format = null ): int|false { $this->inserts[] = [ $table, $data ]; return empty( $this->insert_results ) ? 1 : array_shift( $this->insert_results ); }
		/** @var array<int,array{0:string,1:array,2:array}> */
		public array $updates = [];
		/** @var array<int,int|false> values update() returns, in order (1 once empty) */
		public array $update_results = [];
		public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false { $this->updates[] = [ $table, $data, $where ]; return empty( $this->update_results ) ? 1 : array_shift( $this->update_results ); }
		public string $last_error = '';
		public function suppress_errors( bool $suppress = true ): bool { return false; }
		/** @var array<string,array<string,string>> what DESC returns: table => column => type */
		public array $described = [];
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
		public function get_results( mixed ...$a ): array {
			if ( str_starts_with( (string) ( $a[0] ?? '' ), 'DESC' ) ) {
				$table = (string) ( end( $this->prepared )[1][0] ?? '' );
				$rows  = [];
				foreach ( $this->described[ $table ] ?? [] as $field => $type ) {
					$rows[] = (object) [ 'Field' => $field, 'Type' => $type ];
				}
				return $rows;
			}
			return [];
		}
		/** @var string|null another spelling of the key, as stored on another post */
		public ?string $other_spelling = null;
		public function get_var( mixed ...$a ): mixed { return $this->other_spelling; }
		public string $postmeta = 'wp_postmeta';
		/** @var string[] the meta_key rows get_col() returns: what the database's collation matched */
		public array $stored_keys = [];
		public function get_col( mixed ...$a ): array { return $this->stored_keys; }
		public function query( mixed ...$a ): int|false { $this->queries[] = $a[0] ?? null; return empty( $this->query_results ) ? 0 : array_shift( $this->query_results ); }
	}
}
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new IATO_Test_WPDB();
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ): ?object { return isset( IATO_Test_WP::$posts[ $id ] ) ? (object) ( [ 'ID' => $id ] + IATO_Test_WP::$posts[ $id ] ) : null; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $id, string $key, mixed $value ): bool { if ( IATO_Test_WP::$meta_write_fails ) { return false; } $v = is_string( $value ) ? stripslashes( $value ) : ( is_array( $value ) ? IATO_Test_WP::unslash_deep( $value ) : $value ); IATO_Test_WP::$meta[ $id ][ $key ] = $v; IATO_Test_WP::$writes[] = [ $id, $key, $v ]; IATO_Test_WP::note_write( $key ); return true; }
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
