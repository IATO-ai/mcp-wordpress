<?php
/**
 * OAuth storage — registered clients, grants and issued tokens.
 *
 * Three tables, all created on activation and through the upgrade routine in
 * iato-mcp.php (dbDelta, like the other plugin tables):
 *
 *   {prefix}iato_mcp_oauth_clients  one row per dynamically registered client
 *   {prefix}iato_mcp_oauth_grants   one row per approved authorization. Its
 *                                   revoked_at is the authority: every
 *                                   revocation path sets it, and the token
 *                                   endpoint and the authenticator check it,
 *                                   so a token issued under a revoked grant
 *                                   is refused whenever it was created.
 *   {prefix}iato_mcp_oauth_tokens   authorization codes, access tokens and
 *                                   refresh tokens, keyed to a grant.
 *
 * Only token hashes (SHA-256 hex) are stored; the raw value is returned to
 * the client once and never written anywhere. Every timestamp is a UTC
 * "Y-m-d H:i:s" string produced in PHP and compared in PHP, never MySQL NOW().
 *
 * This class holds every $wpdb call and makes no decisions. The OAuth server
 * (class-oauth.php) and the authenticator (class-auth.php) reach it through
 * instance(); tests swap in an in-memory subclass with set_instance().
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_OAuth_Store {

	private static ?IATO_MCP_OAuth_Store $instance = null;

	public static function instance(): IATO_MCP_OAuth_Store {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Test seam: install a fake store (null restores the real one). */
	public static function set_instance( ?IATO_MCP_OAuth_Store $store ): void {
		self::$instance = $store;
	}

	public static function clients_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'iato_mcp_oauth_clients';
	}

	public static function grants_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'iato_mcp_oauth_grants';
	}

	public static function tokens_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'iato_mcp_oauth_tokens';
	}

	/**
	 * Create or update the tables. Idempotent (dbDelta).
	 */
	public static function create_tables(): void {
		global $wpdb;

		$clients = self::clients_table();
		$grants  = self::grants_table();
		$tokens  = self::tokens_table();
		$charset = $wpdb->get_charset_collate();

		$sql_clients = "CREATE TABLE {$clients} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id VARCHAR(64) NOT NULL,
			client_name VARCHAR(100) NOT NULL DEFAULT '',
			redirect_uris TEXT NOT NULL,
			registration_ip VARCHAR(45) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			authorized_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id),
			KEY created_at (created_at)
		) {$charset};";

		$sql_grants = "CREATE TABLE {$grants} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			grant_id CHAR(32) NOT NULL,
			client_id VARCHAR(255) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			redirect_uri TEXT DEFAULT NULL,
			granted_at DATETIME NOT NULL,
			revoked_at DATETIME DEFAULT NULL,
			revoked_reason VARCHAR(32) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY grant_id (grant_id),
			KEY user_id (user_id),
			KEY client_id (client_id(64))
		) {$charset};";

		$sql_tokens = "CREATE TABLE {$tokens} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(10) NOT NULL,
			token_hash CHAR(64) NOT NULL,
			grant_id CHAR(32) NOT NULL,
			client_id VARCHAR(255) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			redirect_uri TEXT DEFAULT NULL,
			code_challenge VARCHAR(128) DEFAULT NULL,
			granted_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME DEFAULT NULL,
			revoked_at DATETIME DEFAULT NULL,
			last_used_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY grant_id (grant_id),
			KEY client_id (client_id(64)),
			KEY expires_at (expires_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_clients );
		dbDelta( $sql_grants );
		dbDelta( $sql_tokens );
	}

	/**
	 * One-shot upgrade for sites that ran the first 1.13.0 build, which had no
	 * grants table: derive a grant row from the token rows of every grant_id
	 * that has none. A grant whose rows are all revoked is recorded as
	 * revoked (reason "migrated").
	 */
	public static function backfill_grants(): int {
		global $wpdb;
		$grants = self::grants_table();
		$tokens = self::tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot migration.
		$n = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (grant_id, client_id, user_id, redirect_uri, granted_at, revoked_at, revoked_reason)
				 SELECT t.grant_id, MIN(t.client_id), MIN(t.user_id), MAX(t.redirect_uri), MIN(t.granted_at),
				        CASE WHEN SUM(t.revoked_at IS NULL) = 0 THEN MIN(t.revoked_at) ELSE NULL END,
				        CASE WHEN SUM(t.revoked_at IS NULL) = 0 THEN 'migrated' ELSE NULL END
				 FROM %i t
				 WHERE NOT EXISTS (SELECT 1 FROM %i g WHERE g.grant_id = t.grant_id)
				 GROUP BY t.grant_id",
				$grants,
				$tokens,
				$grants
			)
		);
		return is_int( $n ) ? $n : 0;
	}

	// ── Clients ───────────────────────────────────────────────────────────

	/**
	 * @param array{client_id:string,client_name:string,redirect_uris:string[],registration_ip:?string,created_at:string,authorized_at?:?string} $row
	 * @return bool false when the row could not be written
	 */
	public function insert_client( array $row ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$ok = $wpdb->insert(
			self::clients_table(),
			[
				'client_id'       => $row['client_id'],
				'client_name'     => $row['client_name'],
				'redirect_uris'   => wp_json_encode( array_values( $row['redirect_uris'] ) ),
				'registration_ip' => $row['registration_ip'] ?? null,
				'created_at'      => $row['created_at'],
				'authorized_at'   => $row['authorized_at'] ?? null,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		return false !== $ok && $ok > 0;
	}

	/**
	 * @return array{client_id:string,client_name:string,redirect_uris:string[],registration_ip:?string,created_at:string,authorized_at:?string}|null
	 */
	public function find_client( string $client_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE client_id = %s', self::clients_table(), $client_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$uris                 = json_decode( (string) $row['redirect_uris'], true );
		$row['redirect_uris'] = is_array( $uris ) ? array_values( array_filter( $uris, 'is_string' ) ) : [];
		return $row;
	}

	public function mark_client_authorized( string $client_id, string $at ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET authorized_at = %s WHERE client_id = %s AND authorized_at IS NULL',
				self::clients_table(),
				$at,
				$client_id
			)
		);
	}

	/**
	 * Remove clients that never completed an authorization before
	 * $unauthorized_before, and clients that completed one but have had no
	 * live grant since $idle_before (no token row that was still valid, or
	 * revoked, after that moment).
	 *
	 * @return int rows deleted
	 */
	public function prune_clients( string $unauthorized_before, string $idle_before ): int {
		global $wpdb;
		$clients = self::clients_table();
		$tokens  = self::tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table maintenance.
		$a = (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE authorized_at IS NULL AND created_at < %s', $clients, $unauthorized_before )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table maintenance.
		$b = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE c FROM %i c WHERE c.authorized_at IS NOT NULL AND c.authorized_at < %s
				 AND NOT EXISTS (SELECT 1 FROM %i t WHERE t.client_id = c.client_id AND t.expires_at > %s AND (t.revoked_at IS NULL OR t.revoked_at > %s))",
				$clients,
				$idle_before,
				$tokens,
				$idle_before,
				$idle_before
			)
		);
		return $a + $b;
	}

	public function count_pending_clients(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE authorized_at IS NULL', self::clients_table() ) );
	}

	// ── Grants ────────────────────────────────────────────────────────────

	/**
	 * @param array{grant_id:string,client_id:string,user_id:int,redirect_uri:?string,granted_at:string} $row
	 */
	public function insert_grant( array $row ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$ok = $wpdb->insert(
			self::grants_table(),
			[
				'grant_id'     => $row['grant_id'],
				'client_id'    => $row['client_id'],
				'user_id'      => (int) $row['user_id'],
				'redirect_uri' => $row['redirect_uri'] ?? null,
				'granted_at'   => $row['granted_at'],
			],
			[ '%s', '%s', '%d', '%s', '%s' ]
		);
		return false !== $ok && $ok > 0;
	}

	/** @return array<string,mixed>|null */
	public function find_grant( string $grant_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE grant_id = %s', self::grants_table(), $grant_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id']      = (int) $row['id'];
		$row['user_id'] = (int) $row['user_id'];
		return $row;
	}

	/**
	 * Revoke one grant: the grant row (the authority) and every live token
	 * row. Returns 1 when the grant was live, 0 when it was already revoked
	 * or does not exist.
	 */
	public function revoke_grant( string $grant_id, string $at, string $reason = '' ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$n = (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked_at = %s, revoked_reason = %s WHERE grant_id = %s AND revoked_at IS NULL',
				self::grants_table(),
				$at,
				substr( $reason, 0, 32 ),
				$grant_id
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET revoked_at = %s WHERE grant_id = %s AND revoked_at IS NULL', self::tokens_table(), $at, $grant_id )
		);
		return $n;
	}

	/**
	 * Revoke every live grant on the site ("Disconnect all"). Returns the
	 * number of grants that had a live access or refresh token (connections,
	 * as the Settings screen counts them); pending authorizations are revoked
	 * too but not counted.
	 */
	public function revoke_all_grants( string $now, string $at, string $reason = 'admin_all' ): int {
		global $wpdb;
		$grants = self::grants_table();
		$tokens = self::tokens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT t.grant_id) FROM %i t JOIN %i g ON g.grant_id = t.grant_id
				 WHERE g.revoked_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > %s AND t.kind IN ('access','refresh')",
				$tokens,
				$grants,
				$now
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at = %s, revoked_reason = %s WHERE revoked_at IS NULL', $grants, $at, substr( $reason, 0, 32 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked_at = %s WHERE revoked_at IS NULL AND expires_at > %s', $tokens, $at, $now ) );
		return $n;
	}

	/** Revoke every grant approved by a user (user deleted). Returns the number of grants newly revoked. */
	public function revoke_user_grants( int $user_id, string $at, string $reason = 'user_deleted' ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$n = (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET revoked_at = %s, revoked_reason = %s WHERE user_id = %d AND revoked_at IS NULL', self::grants_table(), $at, substr( $reason, 0, 32 ), $user_id )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL', self::tokens_table(), $at, $user_id )
		);
		return $n;
	}

	// ── Tokens ────────────────────────────────────────────────────────────

	/**
	 * @param array{kind:string,token_hash:string,grant_id:string,client_id:string,user_id:int,redirect_uri?:?string,code_challenge?:?string,granted_at?:string,created_at:string,expires_at:string} $row
	 */
	public function insert_token( array $row ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->insert(
			self::tokens_table(),
			[
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
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * The token row joined with its grant: `grant_revoked_at` and
	 * `grant_granted_at` come from the grants table, and `grant_missing` is
	 * true when no grant row exists. Consumers treat a missing grant exactly
	 * like a revoked one (IATO_MCP_OAuth::grant_is_dead()): a token that
	 * cannot be tied to an authorization is refused.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_token( string $token_hash ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT t.*, g.grant_id AS grant_row_id, g.revoked_at AS grant_revoked_at, g.granted_at AS grant_granted_at
				 FROM %i t LEFT JOIN %i g ON g.grant_id = t.grant_id
				 WHERE t.token_hash = %s',
				self::tokens_table(),
				self::grants_table(),
				$token_hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id']            = (int) $row['id'];
		$row['user_id']       = (int) $row['user_id'];
		$row['grant_missing'] = null === $row['grant_row_id'];
		unset( $row['grant_row_id'] );
		return $row;
	}

	/**
	 * Claim a code or refresh token: the single UPDATE is the atomic check
	 * that nobody else consumed it first. Returns 1 when this call consumed
	 * the row, 0 when it was already used (reuse).
	 */
	public function mark_used( int $id, string $at ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_at = %s WHERE id = %d AND used_at IS NULL', self::tokens_table(), $at, $id ) );
	}

	/** Revoke the live access tokens of a grant (refresh rotation). */
	public function revoke_grant_access_tokens( string $grant_id, string $at ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		return (int) $wpdb->query(
			$wpdb->prepare( "UPDATE %i SET revoked_at = %s WHERE grant_id = %s AND kind = 'access' AND revoked_at IS NULL", self::tokens_table(), $at, $grant_id )
		);
	}

	public function touch_last_used( int $id, string $at ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_used_at = %s WHERE id = %d', self::tokens_table(), $at, $id ) );
	}

	/**
	 * Delete token rows whose expiry is older than $before (revoked and used
	 * rows are kept until then as well, so replay detection keeps working for
	 * the retention window), then grant rows that no longer have any token.
	 */
	public function prune_tokens( string $before ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table maintenance.
		$n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', self::tokens_table(), $before ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table maintenance.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE g FROM %i g WHERE g.granted_at < %s AND NOT EXISTS (SELECT 1 FROM %i t WHERE t.grant_id = g.grant_id)',
				self::grants_table(),
				$before,
				self::tokens_table()
			)
		);
		return $n;
	}

	/**
	 * Active grants: unrevoked grants that still have a live access or
	 * refresh token at $now, with the client name.
	 *
	 * @return array<int,array{grant_id:string,client_id:string,client_name:string,user_id:int,redirect_uri:string,connected_at:string,last_used_at:?string,expires_at:string}>
	 */
	public function list_active_grants( string $now ): array {
		global $wpdb;
		$tokens  = self::tokens_table();
		$grants  = self::grants_table();
		$clients = self::clients_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.grant_id, g.client_id, g.user_id, g.granted_at AS connected_at, g.redirect_uri,
				        MAX(t.last_used_at) AS last_used_at, MAX(t.expires_at) AS expires_at,
				        (SELECT c.client_name FROM %i c WHERE c.client_id = g.client_id LIMIT 1) AS client_name
				 FROM %i g JOIN %i t ON t.grant_id = g.grant_id
				 WHERE g.revoked_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > %s
				   AND ((t.kind = 'refresh' AND t.used_at IS NULL) OR t.kind = 'access')
				 GROUP BY g.grant_id, g.client_id, g.user_id, g.granted_at, g.redirect_uri
				 ORDER BY g.granted_at DESC",
				$clients,
				$grants,
				$tokens,
				$now
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return [];
		}
		foreach ( $rows as &$r ) {
			$r['user_id']      = (int) $r['user_id'];
			$r['client_name']  = (string) ( $r['client_name'] ?? '' );
			$r['redirect_uri'] = (string) ( $r['redirect_uri'] ?? '' );
			$r['connected_at'] = (string) ( $r['connected_at'] ?? '' );
		}
		unset( $r );
		return $rows;
	}

	public function count_active_grants( string $now ): int {
		return count( $this->list_active_grants( $now ) );
	}
}
