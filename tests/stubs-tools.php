<?php
/**
 * Stubs that let a tool file (includes/tools/wp/tool-*.php) be loaded without
 * WordPress so its pure helper functions can be unit-tested. Registration and
 * hooks become no-ops; nothing here executes a tool handler.
 */

declare( strict_types=1 );

if ( ! class_exists( 'IATO_MCP_Server' ) ) {
	final class IATO_MCP_Server {
		/** @var array<string,callable> */
		public static array $handlers = [];
		public static function register_tool( string $name, array $definition, callable $handler ): void {
			self::$handlers[ $name ] = $handler;
		}
		public static function ok( array $data ): array {
			return [ 'content' => [ [ 'type' => 'text', 'text' => json_encode( $data ) ] ] ];
		}
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $flags, $depth );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int { return abs( (int) $value ); }
}
