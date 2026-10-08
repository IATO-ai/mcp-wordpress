<?php
/**
 * WordPress stubs for the token-binding tests (IATO_MCP_Auth). Loaded by
 * tests/bootstrap.php after stubs-abilities.php. Capturing fakes only.
 */

declare( strict_types=1 );

final class IATO_Test_Auth_State {
	/** @var array<int,array{login:string,caps:array<string,bool>,exists:bool}> */
	public static array $users = [];
	public static int $current_user_id = 0;
	/** @var int[] every wp_set_current_user() call */
	public static array $set_current_user_calls = [];
	/** @var array<string,mixed> capability resolution per cap for the current user */
	public static array $call_log = [];
	public static bool $logged_in = false;

	public static function reset(): void {
		self::$users                  = [];
		self::$current_user_id        = 0;
		self::$set_current_user_calls = [];
		self::$call_log               = [];
		self::$logged_in              = false;
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID = 0;
		public string $user_login = '';
		public string $display_name = '';
		public function __construct( int $id = 0 ) {
			$this->ID = $id;
			if ( isset( IATO_Test_Auth_State::$users[ $id ] ) ) {
				$this->user_login   = IATO_Test_Auth_State::$users[ $id ]['login'];
				$this->display_name = ucfirst( $this->user_login );
			}
		}
		public function exists(): bool {
			return $this->ID > 0 && ( IATO_Test_Auth_State::$users[ $this->ID ]['exists'] ?? false );
		}
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $headers;
		public function __construct( array $headers = [] ) {
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
		}
		public function get_header( string $name ): ?string {
			return $this->headers[ strtolower( $name ) ] ?? null;
		}
		public function get_route(): string {
			return '/iato-mcp/v1/message';
		}
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, mixed $value ): WP_User|false {
		$id = (int) $value;
		if ( 'id' !== strtolower( $field ) || ! ( IATO_Test_Auth_State::$users[ $id ]['exists'] ?? false ) ) {
			return false;
		}
		return new WP_User( $id );
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( mixed $user, string $cap, mixed ...$args ): bool {
		$id = $user instanceof WP_User ? $user->ID : (int) $user;
		return (bool) ( IATO_Test_Auth_State::$users[ $id ]['caps'][ $cap ] ?? false );
	}
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( int $id, string $name = '' ): WP_User {
		IATO_Test_Auth_State::$set_current_user_calls[] = $id;
		IATO_Test_Auth_State::$current_user_id          = $id;
		IATO_Test_Abilities_State::$caps                = IATO_Test_Auth_State::$users[ $id ]['caps'] ?? [];
		return new WP_User( $id );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return IATO_Test_Auth_State::$current_user_id;
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		return new WP_User( IATO_Test_Auth_State::$current_user_id );
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return IATO_Test_Auth_State::$logged_in;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, mixed $value, mixed $autoload = null ): bool {
		IATO_Test_Abilities_State::$options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( IATO_Test_Abilities_State::$options[ $name ] );
		return true;
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
		return substr( str_repeat( 'k', $length ), 0, $length );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES );
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, callable $cb, int $priority = 10 ): bool {
		return true;
	}
}
/** Stand-in for the call log; records auth rejections in tests. */
if ( ! class_exists( 'IATO_MCP_Call_Log' ) ) {
	class IATO_MCP_Call_Log {
		public static function record( array $fields ): void {
			IATO_Test_Auth_State::$call_log[] = $fields;
		}
	}
}
