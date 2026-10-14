<?php
/**
 * Stubs for the Part 3 tests (receipt actors, rollback conflict check):
 * user logins, post terms and term lookups. Every function is guarded.
 */

declare( strict_types=1 );

final class IATO_Test_Actor {
	/** @var array<int,string> user id => login */
	public static array $logins = [];
	/** @var array<int,array<string,int[]>> post id => taxonomy => term ids */
	public static array $post_terms = [];
	/** @var array<int,array{0:int,1:array,2:string}> wp_set_post_terms() calls */
	public static array $set_terms = [];
	/** @var array<string,array<string,int>> taxonomy => slug => term id */
	public static array $slugs = [];

	public static function reset(): void {
		self::$logins     = [];
		self::$post_terms = [];
		self::$set_terms  = [];
		self::$slugs      = [];
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $id ): object|false {
		return isset( IATO_Test_Actor::$logins[ $id ] ) ? (object) [ 'ID' => $id, 'user_login' => IATO_Test_Actor::$logins[ $id ] ] : false;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( int $post_id, string $taxonomy, array $args = [] ): array {
		return IATO_Test_Actor::$post_terms[ $post_id ][ $taxonomy ] ?? [];
	}
}
if ( ! function_exists( 'wp_set_post_terms' ) ) {
	function wp_set_post_terms( int $post_id, array $terms, string $taxonomy, bool $append = false ): array {
		IATO_Test_Actor::$set_terms[]                         = [ $post_id, $terms, $taxonomy ];
		IATO_Test_Actor::$post_terms[ $post_id ][ $taxonomy ] = $terms;
		return $terms;
	}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( string $field, string $value, string $taxonomy ): object|false {
		$id = IATO_Test_Actor::$slugs[ $taxonomy ][ $value ] ?? null;
		return null === $id ? false : (object) [ 'term_id' => $id, 'slug' => $value, 'taxonomy' => $taxonomy ];
	}
}
