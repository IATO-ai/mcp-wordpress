<?php
/**
 * Extra WordPress stubs for the Abilities layer tests. Loaded by
 * tests/bootstrap.php. Everything is a capturing fake so the registration
 * code can run without WordPress.
 */

declare( strict_types=1 );

final class IATO_Test_Abilities_State {
	/** @var array<string,mixed> get_option() backing store */
	public static array $options = [];
	/** @var array<string,callable[]> add_action() captures */
	public static array $actions = [];
	/** @var array<string,array> wp_register_ability() captures */
	public static array $registered = [];
	/** @var array<string,array> wp_register_ability_category() captures */
	public static array $categories = [];
	/** @var array<string,bool> current_user_can() answers */
	public static array $caps = [];
	/** @var string[] tools IATO_MCP_Settings::is_tool_enabled() reports off */
	public static array $disabled_tools = [];
	/** @var array<string,callable[]> add_filter() captures, applied by apply_filters() */
	public static array $filters = [];

	public static function reset(): void {
		self::$options        = [];
		self::$actions        = [];
		self::$registered     = [];
		self::$categories     = [];
		self::$caps           = [];
		self::$disabled_tools = [];
		self::$filters        = [];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): bool {
		IATO_Test_Abilities_State::$filters[ $hook ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		foreach ( IATO_Test_Abilities_State::$filters[ $hook ] ?? [] as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return array_key_exists( $name, IATO_Test_Abilities_State::$options ) ? IATO_Test_Abilities_State::$options[ $name ] : $default;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $cb, int $priority = 10, int $args = 1 ): bool {
		IATO_Test_Abilities_State::$actions[ $hook ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap, mixed ...$args ): bool {
		return IATO_Test_Abilities_State::$caps[ $cap ] ?? false;
	}
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): ?object {
		IATO_Test_Abilities_State::$registered[ $name ] = $args;
		return (object) [ 'name' => $name ];
	}
}
if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $slug, array $args ): ?object {
		IATO_Test_Abilities_State::$categories[ $slug ] = $args;
		return (object) [ 'slug' => $slug ];
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $str ): string {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $v ): int {
		return abs( (int) $v );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $flags, $depth );
	}
}
if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( mixed $v ): bool {
		return filter_var( $v, FILTER_VALIDATE_BOOLEAN );
	}
}

/** Stand-in for the real settings class (not loaded in unit tests). */
if ( ! class_exists( 'IATO_MCP_Settings' ) ) {
	class IATO_MCP_Settings {
		public static function is_tool_enabled( string $tool ): bool {
			return ! in_array( $tool, IATO_Test_Abilities_State::$disabled_tools, true );
		}
	}
}
