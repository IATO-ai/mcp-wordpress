<?php
/**
 * Stubs for the 1.17.0 menu tests: nav menus, theme mods per stylesheet, registered
 * locations. Every function is guarded. wp_delete_nav_menu() does what core's does
 * beyond removing the term: it empties any location holding the menu and deletes
 * the menu's items.
 */

declare( strict_types=1 );

final class IATO_Test_Menus {
	/** @var array<int,object> id => term_id, name, slug, count */
	public static array $menus = [];
	/** @var array<int,int[]> menu id => item post IDs */
	public static array $items = [];
	/** @var array<string,string> slug => label */
	public static array $registered = [];
	public static string $stylesheet = 'hello-elementor';
	/** @var array<string,array<string,mixed>> stylesheet => mod name => value */
	public static array $mods = [];
	public static int $next_id = 500;
	/** @var string[] every write, in order */
	public static array $writes = [];
	public static ?WP_Error $create_error = null;
	public static bool $delete_fails = false;

	public static function reset(): void {
		self::$menus        = [];
		self::$items        = [];
		self::$registered   = [ 'menu-1' => 'Header', 'menu-2' => 'Footer' ];
		self::$stylesheet   = 'hello-elementor';
		self::$mods         = [];
		self::$next_id      = 500;
		self::$writes       = [];
		self::$create_error = null;
		self::$delete_fails = false;
		unset( IATO_Test_Actor::$slugs['nav_menu'] );
	}

	public static function add_menu( int $id, string $name, string $slug = '' ): void {
		self::$menus[ $id ] = (object) [ 'term_id' => $id, 'name' => $name, 'slug' => '' !== $slug ? $slug : strtolower( str_replace( ' ', '-', $name ) ), 'count' => 0 ];
		IATO_Test_Actor::$slugs['nav_menu'][ $name ] = $id;
	}

	public static function locations(): array {
		return self::$mods[ self::$stylesheet ]['nav_menu_locations'] ?? [];
	}
}

if ( ! function_exists( 'wp_get_nav_menus' ) ) {
	function wp_get_nav_menus( array $args = [] ): array { return array_values( IATO_Test_Menus::$menus ); }
}
if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
	function wp_get_nav_menu_object( mixed $menu ): object|false {
		if ( isset( IATO_Test_Menus::$menus[ (int) $menu ] ) ) {
			return IATO_Test_Menus::$menus[ (int) $menu ];
		}
		// As core does: then by slug, then by name, whatever was asked for.
		foreach ( IATO_Test_Menus::$menus as $candidate ) {
			if ( (string) $menu === $candidate->slug || (string) $menu === $candidate->name ) {
				return $candidate;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'wp_create_nav_menu' ) ) {
	function wp_create_nav_menu( string $name ): int|WP_Error {
		if ( null !== IATO_Test_Menus::$create_error ) {
			return IATO_Test_Menus::$create_error;
		}
		$id = IATO_Test_Menus::$next_id++;
		IATO_Test_Menus::add_menu( $id, sanitize_term_field( 'name', stripslashes( $name ), 0, 'nav_menu', 'db' ) );
		IATO_Test_Menus::$writes[] = "create:{$id}";
		return $id;
	}
}
if ( ! function_exists( 'wp_delete_nav_menu' ) ) {
	function wp_delete_nav_menu( mixed $menu ): bool|WP_Error {
		$id = (int) $menu;
		if ( ! isset( IATO_Test_Menus::$menus[ $id ] ) || IATO_Test_Menus::$delete_fails ) {
			return false;
		}
		$locations = IATO_Test_Menus::locations();
		foreach ( $locations as $location => $held ) {
			if ( $held === $id ) {
				$locations[ $location ] = 0;
			}
		}
		set_theme_mod( 'nav_menu_locations', $locations );
		unset( IATO_Test_Menus::$menus[ $id ], IATO_Test_Menus::$items[ $id ] );
		IATO_Test_Menus::$writes[] = "delete:{$id}";
		return true;
	}
}
if ( ! function_exists( 'get_objects_in_term' ) ) {
	function get_objects_in_term( mixed $term_ids, mixed $taxonomies, array $args = [] ): array|WP_Error { return IATO_Test_Menus::$items[ (int) $term_ids ] ?? []; }
}
if ( ! function_exists( 'get_registered_nav_menus' ) ) {
	function get_registered_nav_menus(): array { return IATO_Test_Menus::$registered; }
}
if ( ! function_exists( 'get_nav_menu_locations' ) ) {
	function get_nav_menu_locations(): array { return IATO_Test_Menus::locations(); }
}
if ( ! function_exists( 'set_theme_mod' ) ) {
	function set_theme_mod( string $name, mixed $value ): bool {
		IATO_Test_Menus::$mods[ IATO_Test_Menus::$stylesheet ][ $name ] = $value;
		IATO_Test_Menus::$writes[] = "mod:{$name}";
		return true;
	}
}
if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet(): string { return IATO_Test_Menus::$stylesheet; }
}
if ( ! function_exists( 'sanitize_term_field' ) ) {
	// The name filter that shows in a result: & is stored as &amp;.
	function sanitize_term_field( string $field, mixed $value, int $term_id, string $taxonomy, string $context ): mixed {
		return 'name' === $field ? str_replace( '&', '&amp;', str_replace( '&amp;', '&', (string) $value ) ) : $value;
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string { return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( str_replace( '&amp;', '', $title ) ) ), '-' ); }
}
