<?php
/**
 * In-memory OAuth store and the WordPress stubs the OAuth server needs.
 * Loaded by tests/bootstrap.php before the production OAuth classes; the
 * tests install IATO_Test_OAuth_Store through IATO_MCP_OAuth_Store::set_instance().
 */

declare( strict_types=1 );

final class IATO_Test_OAuth_State {
	/** @var array<string,array{value:mixed,expires:int}> */
	public static array $transients = [];
	public static string $home_url  = 'http://localhost:8888';

	public static function reset(): void {
		self::$transients = [];
		self::$home_url   = 'http://localhost:8888';
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		IATO_Test_OAuth_State::$transients[ $key ] = [ 'value' => $value, 'expires' => $expiration > 0 ? time() + $expiration : 0 ];
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ): mixed {
		$t = IATO_Test_OAuth_State::$transients[ $key ] ?? null;
		if ( null === $t || ( $t['expires'] > 0 && $t['expires'] <= time() ) ) {
			return false;
		}
		return $t['value'];
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( IATO_Test_OAuth_State::$transients[ $key ] );
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return IATO_Test_OAuth_State::$home_url . $path;
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return IATO_Test_OAuth_State::$home_url . '/wp-json/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( string $action = '', string $name = '_wpnonce' ): int {
		return 1;
	}
}

/**
 * In-memory store with the same API as the production class. Rows are
 * plain arrays shaped like the DB rows (string timestamps, int ids).
 */
final class IATO_Test_OAuth_Store extends IATO_MCP_OAuth_Store {
	/** @var array<string,array> client_id => row */
	public array $clients = [];
	/** @var array<string,array> grant_id => row */
	public array $grants = [];
	/** @var array<int,array> id => row */
	public array $tokens = [];
	private int $next_id = 1;
	/** Test switch: make insert_client() fail. */
	public bool $fail_insert_client = false;
	/** Test switch: make insert_grant() fail. */
	public bool $fail_insert_grant = false;
	/** Test counter: how often find_token() was called (memoisation checks). */
	public int $find_calls = 0;

	public static function install(): self {
		$s = new self();
		IATO_MCP_OAuth_Store::set_instance( $s );
		return $s;
	}

	public function insert_client( array $row ): bool {
		if ( $this->fail_insert_client ) {
			return false;
		}
		$this->clients[ $row['client_id'] ] = [
			'client_id'       => $row['client_id'],
			'client_name'     => $row['client_name'],
			'redirect_uris'   => array_values( $row['redirect_uris'] ),
			'registration_ip' => $row['registration_ip'] ?? null,
			'created_at'      => $row['created_at'],
			'authorized_at'   => $row['authorized_at'] ?? null,
		];
		return true;
	}

	public function find_client( string $client_id ): ?array {
		return $this->clients[ $client_id ] ?? null;
	}

	public function mark_client_authorized( string $client_id, string $at ): void {
		if ( isset( $this->clients[ $client_id ] ) && null === $this->clients[ $client_id ]['authorized_at'] ) {
			$this->clients[ $client_id ]['authorized_at'] = $at;
		}
	}

	public function prune_clients( string $unauthorized_before, string $idle_before ): int {
		$n = 0;
		foreach ( $this->clients as $id => $c ) {
			if ( null === $c['authorized_at'] ) {
				if ( $c['created_at'] < $unauthorized_before ) {
					unset( $this->clients[ $id ] );
					++$n;
				}
				continue;
			}
			if ( $c['authorized_at'] >= $idle_before ) {
				continue;
			}
			$live = false;
			foreach ( $this->tokens as $t ) {
				if ( $t['client_id'] === $id && $t['expires_at'] > $idle_before && ( null === $t['revoked_at'] || $t['revoked_at'] > $idle_before ) ) {
					$live = true;
					break;
				}
			}
			if ( ! $live ) {
				unset( $this->clients[ $id ] );
				++$n;
			}
		}
		return $n;
	}

	public function count_pending_clients(): int {
		return count( array_filter( $this->clients, fn( $c ) => null === $c['authorized_at'] ) );
	}

	public function insert_grant( array $row ): bool {
		if ( $this->fail_insert_grant ) {
			return false;
		}
		$this->grants[ $row['grant_id'] ] = [
			'grant_id'       => $row['grant_id'],
			'client_id'      => $row['client_id'],
			'user_id'        => (int) $row['user_id'],
			'redirect_uri'   => $row['redirect_uri'] ?? null,
			'granted_at'     => $row['granted_at'],
			'revoked_at'     => null,
			'revoked_reason' => null,
		];
		return true;
	}

	public function find_grant( string $grant_id ): ?array {
		return $this->grants[ $grant_id ] ?? null;
	}

	public function revoke_grant( string $grant_id, string $at, string $reason = '' ): int {
		$n = 0;
		if ( isset( $this->grants[ $grant_id ] ) && null === $this->grants[ $grant_id ]['revoked_at'] ) {
			$this->grants[ $grant_id ]['revoked_at']     = $at;
			$this->grants[ $grant_id ]['revoked_reason'] = $reason;
			$n                                           = 1;
		}
		foreach ( $this->tokens as &$t ) {
			if ( $t['grant_id'] === $grant_id && null === $t['revoked_at'] ) {
				$t['revoked_at'] = $at;
			}
		}
		return $n;
	}

	public function revoke_all_grants( string $now, string $at, string $reason = 'admin_all' ): int {
		$counted = [];
		foreach ( $this->tokens as $t ) {
			if ( null === $t['revoked_at'] && $t['expires_at'] > $now && 'code' !== $t['kind'] && null === ( $this->grants[ $t['grant_id'] ]['revoked_at'] ?? null ) ) {
				$counted[ $t['grant_id'] ] = true;
			}
		}
		foreach ( $this->grants as &$g ) {
			if ( null === $g['revoked_at'] ) {
				$g['revoked_at']     = $at;
				$g['revoked_reason'] = $reason;
			}
		}
		unset( $g );
		foreach ( $this->tokens as &$t ) {
			if ( null === $t['revoked_at'] && $t['expires_at'] > $now ) {
				$t['revoked_at'] = $at;
			}
		}
		return count( $counted );
	}

	public function revoke_user_grants( int $user_id, string $at, string $reason = 'user_deleted' ): int {
		$n = 0;
		foreach ( $this->grants as &$g ) {
			if ( $g['user_id'] === $user_id && null === $g['revoked_at'] ) {
				$g['revoked_at']     = $at;
				$g['revoked_reason'] = $reason;
				++$n;
			}
		}
		unset( $g );
		foreach ( $this->tokens as &$t ) {
			if ( $t['user_id'] === $user_id && null === $t['revoked_at'] ) {
				$t['revoked_at'] = $at;
			}
		}
		return $n;
	}

	public function insert_token( array $row ): void {
		$id                  = $this->next_id++;
		$this->tokens[ $id ] = [
			'id'             => $id,
			'kind'           => $row['kind'],
			'token_hash'     => $row['token_hash'],
			'grant_id'       => $row['grant_id'],
			'client_id'      => $row['client_id'],
			'user_id'        => (int) $row['user_id'],
			'redirect_uri'   => $row['redirect_uri'] ?? null,
			'code_challenge' => $row['code_challenge'] ?? null,
			'granted_at'     => $row['granted_at'] ?? $row['created_at'],
			'created_at'     => $row['created_at'],
			'expires_at'     => $row['expires_at'],
			'used_at'        => null,
			'revoked_at'     => null,
			'last_used_at'   => null,
		];
	}

	public function find_token( string $token_hash ): ?array {
		++$this->find_calls;
		foreach ( $this->tokens as $t ) {
			if ( $t['token_hash'] === $token_hash ) {
				$g                     = $this->grants[ $t['grant_id'] ] ?? null;
				$t['grant_missing']    = null === $g;
				$t['grant_revoked_at'] = $g['revoked_at'] ?? null;
				$t['grant_granted_at'] = $g['granted_at'] ?? null;
				return $t;
			}
		}
		return null;
	}

	public function mark_used( int $id, string $at ): int {
		if ( isset( $this->tokens[ $id ] ) && null === $this->tokens[ $id ]['used_at'] ) {
			$this->tokens[ $id ]['used_at'] = $at;
			return 1;
		}
		return 0;
	}

	public function revoke_grant_access_tokens( string $grant_id, string $at ): int {
		$n = 0;
		foreach ( $this->tokens as &$t ) {
			if ( $t['grant_id'] === $grant_id && 'access' === $t['kind'] && null === $t['revoked_at'] ) {
				$t['revoked_at'] = $at;
				++$n;
			}
		}
		return $n;
	}

	public function touch_last_used( int $id, string $at ): void {
		if ( isset( $this->tokens[ $id ] ) ) {
			$this->tokens[ $id ]['last_used_at'] = $at;
		}
	}

	public function prune_tokens( string $before ): int {
		$n = 0;
		foreach ( $this->tokens as $id => $t ) {
			if ( $t['expires_at'] < $before ) {
				unset( $this->tokens[ $id ] );
				++$n;
			}
		}
		foreach ( $this->grants as $gid => $g ) {
			if ( $g['granted_at'] < $before && ! array_filter( $this->tokens, fn( $t ) => $t['grant_id'] === $gid ) ) {
				unset( $this->grants[ $gid ] );
			}
		}
		return $n;
	}

	public function list_active_grants( string $now ): array {
		$out = [];
		foreach ( $this->grants as $gid => $g ) {
			if ( null !== $g['revoked_at'] ) {
				continue;
			}
			$entry = null;
			foreach ( $this->tokens as $t ) {
				$live = $t['grant_id'] === $gid && null === $t['revoked_at'] && $t['expires_at'] > $now && ( ( 'refresh' === $t['kind'] && null === $t['used_at'] ) || 'access' === $t['kind'] );
				if ( ! $live ) {
					continue;
				}
				if ( null === $entry ) {
					$entry = [
						'grant_id'     => $gid,
						'client_id'    => $g['client_id'],
						'client_name'  => $this->clients[ $g['client_id'] ]['client_name'] ?? '',
						'user_id'      => $g['user_id'],
						'redirect_uri' => (string) ( $g['redirect_uri'] ?? '' ),
						'connected_at' => (string) $g['granted_at'],
						'last_used_at' => null,
						'expires_at'   => $t['expires_at'],
					];
				}
				if ( null !== $t['last_used_at'] && ( null === $entry['last_used_at'] || $t['last_used_at'] > $entry['last_used_at'] ) ) {
					$entry['last_used_at'] = $t['last_used_at'];
				}
				if ( $t['expires_at'] > $entry['expires_at'] ) {
					$entry['expires_at'] = $t['expires_at'];
				}
			}
			if ( null !== $entry ) {
				$out[] = $entry;
			}
		}
		usort( $out, fn( $a, $b ) => strcmp( $b['connected_at'], $a['connected_at'] ) );
		return $out;
	}

	/** Test helper: the row for a raw token value. */
	public function row_for( string $raw ): ?array {
		return $this->find_token( hash( 'sha256', $raw ) );
	}

	/** Test helper: overwrite one column of the row holding a raw token. */
	public function set( string $raw, string $column, mixed $value ): void {
		$row = $this->row_for( $raw );
		if ( null !== $row ) {
			$this->tokens[ $row['id'] ][ $column ] = $value;
		}
	}
}
