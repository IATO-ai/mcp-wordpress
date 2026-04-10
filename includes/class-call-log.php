<?php
/**
 * MCP Call Log — records every incoming MCP JSON-RPC request.
 *
 * Provides local observability into incoming MCP traffic so operators can
 * verify whether external clients (Claude Desktop, IATO Autopilot callbacks,
 * etc.) are reaching this WordPress install. Ring-buffered to the most recent
 * 200 entries to cap storage.
 *
 * Table: {prefix}iato_mcp_call_log
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Call_Log {

	/** Maximum number of log entries to retain. */
	const MAX_ENTRIES = 200;

	/** Maximum bytes of the request args JSON stored. */
	const MAX_ARGS_BYTES = 2048;

	/**
	 * Fully qualified table name including wp prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'iato_mcp_call_log';
	}

	/**
	 * Create the call log table. Called on plugin activation.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(255) DEFAULT NULL,
			auth_user_id BIGINT UNSIGNED DEFAULT 0,
			rpc_method VARCHAR(50) DEFAULT NULL,
			tool_name VARCHAR(100) DEFAULT NULL,
			request_args TEXT DEFAULT NULL,
			response_status VARCHAR(20) DEFAULT NULL,
			error_code VARCHAR(50) DEFAULT NULL,
			error_message TEXT DEFAULT NULL,
			duration_ms INT UNSIGNED DEFAULT 0,
			PRIMARY KEY (id),
			KEY created_at (created_at),
			KEY rpc_method (rpc_method),
			KEY response_status (response_status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a call log entry and trim old rows if over capacity.
	 *
	 * Accepts the following fields (all optional except rpc_method):
	 *   - rpc_method      string  e.g. 'tools/call'
	 *   - tool_name       string  tool invoked via tools/call
	 *   - request_args    array   raw arguments (will be JSON-encoded and truncated)
	 *   - response_status string  'success' | 'error' | 'unauthorized'
	 *   - error_code      string
	 *   - error_message   string
	 *   - duration_ms     int
	 *   - auth_user_id    int
	 *   - ip_address      string
	 *   - user_agent      string
	 */
	public static function record( array $fields ): void {
		global $wpdb;

		$args_json = null;
		if ( isset( $fields['request_args'] ) && ! empty( $fields['request_args'] ) ) {
			$encoded = wp_json_encode( $fields['request_args'] );
			if ( is_string( $encoded ) ) {
				if ( strlen( $encoded ) > self::MAX_ARGS_BYTES ) {
					$encoded = substr( $encoded, 0, self::MAX_ARGS_BYTES ) . '…(truncated)';
				}
				$args_json = $encoded;
			}
		}

		$ip = $fields['ip_address'] ?? ( $_SERVER['REMOTE_ADDR'] ?? null );
		$ua = $fields['user_agent'] ?? ( $_SERVER['HTTP_USER_AGENT'] ?? null );
		if ( is_string( $ua ) ) {
			$ua = substr( $ua, 0, 255 );
		}

		$wpdb->insert(
			self::table_name(),
			[
				'created_at'      => current_time( 'mysql', true ),
				'ip_address'      => $ip ? substr( (string) $ip, 0, 45 ) : null,
				'user_agent'      => $ua,
				'auth_user_id'    => (int) ( $fields['auth_user_id'] ?? 0 ),
				'rpc_method'      => isset( $fields['rpc_method'] ) ? substr( (string) $fields['rpc_method'], 0, 50 ) : null,
				'tool_name'       => isset( $fields['tool_name'] ) ? substr( (string) $fields['tool_name'], 0, 100 ) : null,
				'request_args'    => $args_json,
				'response_status' => isset( $fields['response_status'] ) ? substr( (string) $fields['response_status'], 0, 20 ) : null,
				'error_code'      => isset( $fields['error_code'] ) ? substr( (string) $fields['error_code'], 0, 50 ) : null,
				'error_message'   => $fields['error_message'] ?? null,
				'duration_ms'     => (int) ( $fields['duration_ms'] ?? 0 ),
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		// Trim the log if it exceeds MAX_ENTRIES. Cheap because the table is tiny.
		self::trim_old( self::MAX_ENTRIES );
	}

	/**
	 * Fetch the most recent $limit entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$table = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Count total entries in the log.
	 */
	public static function count(): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete rows older than the newest $keep entries.
	 */
	public static function trim_old( int $keep = self::MAX_ENTRIES ): void {
		global $wpdb;
		$table = self::table_name();

		$cutoff_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
				$keep
			)
		);

		if ( $cutoff_id > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE id <= %d",
					$cutoff_id
				)
			);
		}
	}

	/**
	 * Wipe all log entries.
	 */
	public static function purge(): void {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
