<?php
/**
 * Stubs for SlugConflictTest, loaded in its own processes only: get_post() and the
 * database answer from these tables.
 */
declare( strict_types=1 );

final class IATO_Test_Slugs {
	/** @var array<int,object> */
	public static array $posts = [];
	/** @var array<string,string> slug => what wp_unique_post_slug() answers */
	public static array $would_use = [];
	/** @var list<array> every wp_unique_post_slug() call */
	public static array $calls = [];
	/** @var list<string> every query */
	public static array $queries = [];
	public static array $hierarchical = [ 'page' => true ];
}

function get_post( int $id ): ?object {
	return IATO_Test_Slugs::$posts[ $id ] ?? null;
}
function wp_unique_post_slug( string $slug, int $id, string $status, string $type, int $parent ): string {
	IATO_Test_Slugs::$calls[] = [ $slug, $id, $status, $type, $parent ];
	return IATO_Test_Slugs::$would_use[ $slug ] ?? $slug;
}
function is_post_type_hierarchical( string $type ): bool {
	return IATO_Test_Slugs::$hierarchical[ $type ] ?? false;
}
function get_the_title( int $id ): string {
	return (string) ( IATO_Test_Slugs::$posts[ $id ]->post_title ?? '' );
}

/** The holder query, answered from the posts table above by WordPress's rule. */
$GLOBALS['wpdb'] = new class() {
	public string $posts = 'wp_posts';
	public function prepare( string $sql, mixed ...$args ): string {
		return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $sql ), $args );
	}
	public function get_var( string $sql ): ?string {
		IATO_Test_Slugs::$queries[] = $sql;
		preg_match( "/post_name = '([^']*)'/", $sql, $slug );
		preg_match( '/ID != (\d+)/', $sql, $not );
		$type   = preg_match( "/post_type IN \( '([^']*)', 'attachment' \)/", $sql, $m ) ? [ $m[1], 'attachment' ] : ( preg_match( "/post_type = '([^']*)'/", $sql, $m ) ? [ $m[1] ] : null );
		$parent = preg_match( '/post_parent = (\d+)/', $sql, $m ) ? (int) $m[1] : null;
		foreach ( IATO_Test_Slugs::$posts as $id => $post ) {
			if ( $post->post_name === $slug[1] && $id !== (int) $not[1]
				&& ( null === $type || in_array( $post->post_type, $type, true ) )
				&& ( null === $parent || (int) $post->post_parent === $parent ) ) {
				return (string) $id;
			}
		}
		return null;
	}
};
