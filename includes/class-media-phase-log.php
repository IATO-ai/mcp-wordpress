<?php
/**
 * Media Phase Log — records the per-phase trace of each create_media call so
 * admins can triage upload failures from the Diagnostics page without enabling
 * WP_DEBUG_LOG.
 *
 * One row per create_media call. The deferred-subsizes cron path appends a
 * follow-up phase to its parent row by req_id; the parent is created
 * synchronously, then any later async events update the same row.
 *
 * Table: {prefix}iato_mcp_media_phase_log
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Media_Phase_Log {

	/** Maximum number of log entries to retain. */
	const MAX_ENTRIES = 100;

	/** Maximum bytes of the phases JSON stored. */
	const MAX_PHASES_BYTES = 4096;

	/**
	 * Fully qualified table name including wp prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'iato_mcp_media_phase_log';
	}

	/**
	 * Create the table. Called on plugin activation.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			req_id CHAR(8) NOT NULL,
			auth_user_id BIGINT UNSIGNED DEFAULT 0,
			attachment_id BIGINT UNSIGNED DEFAULT 0,
			filename VARCHAR(255) DEFAULT NULL,
			mime_type VARCHAR(64) DEFAULT NULL,
			width INT UNSIGNED DEFAULT NULL,
			height INT UNSIGNED DEFAULT NULL,
			bytes INT UNSIGNED DEFAULT NULL,
			outcome VARCHAR(32) DEFAULT NULL,
			error_code VARCHAR(64) DEFAULT NULL,
			total_ms INT UNSIGNED DEFAULT 0,
			phases TEXT NOT NULL,
			PRIMARY KEY (id),
			KEY created_at (created_at),
			KEY req_id (req_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a phase trace and trim old rows if over capacity.
	 *
	 * Required fields: req_id, phases (array). All other fields optional.
	 * `phases` is JSON-encoded; if the encoded form exceeds MAX_PHASES_BYTES
	 * it is truncated to fit, with a trailing "(truncated)" marker pushed in.
	 *
	 * Wrapped in try/catch so a DB write failure cannot bubble out and crash
	 * the create_media tool — observability must not regress reliability.
	 */
	public static function record( array $fields ): void {
		try {
			global $wpdb;

			$req_id = isset( $fields['req_id'] ) ? substr( (string) $fields['req_id'], 0, 8 ) : '';
			if ( '' === $req_id ) {
				return;
			}

			$phases = isset( $fields['phases'] ) && is_array( $fields['phases'] ) ? $fields['phases'] : [];
			$phases_json = wp_json_encode( $phases );
			if ( ! is_string( $phases_json ) ) {
				$phases_json = '[]';
			}
			if ( strlen( $phases_json ) > self::MAX_PHASES_BYTES ) {
				// Truncate the JSON array to fit, preserving a valid array structure
				// by re-encoding only as many phases as fit.
				$keep = $phases;
				while ( count( $keep ) > 1 ) {
					array_pop( $keep );
					$keep[] = [ 'p' => '(truncated)', 'e' => 0 ];
					$candidate = wp_json_encode( $keep );
					if ( is_string( $candidate ) && strlen( $candidate ) <= self::MAX_PHASES_BYTES ) {
						$phases_json = $candidate;
						break;
					}
					array_pop( $keep );
				}
			}

			$now = current_time( 'mysql', true );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
			$wpdb->insert(
				self::table_name(),
				[
					'created_at'    => $now,
					'updated_at'    => $now,
					'req_id'        => $req_id,
					'auth_user_id'  => (int) ( $fields['auth_user_id'] ?? 0 ),
					'attachment_id' => (int) ( $fields['attachment_id'] ?? 0 ),
					'filename'      => isset( $fields['filename'] ) ? substr( (string) $fields['filename'], 0, 255 ) : null,
					'mime_type'     => isset( $fields['mime_type'] ) ? substr( (string) $fields['mime_type'], 0, 64 ) : null,
					'width'         => isset( $fields['width'] ) ? (int) $fields['width'] : null,
					'height'        => isset( $fields['height'] ) ? (int) $fields['height'] : null,
					'bytes'         => isset( $fields['bytes'] ) ? (int) $fields['bytes'] : null,
					'outcome'       => isset( $fields['outcome'] ) ? substr( (string) $fields['outcome'], 0, 32 ) : null,
					'error_code'    => isset( $fields['error_code'] ) ? substr( (string) $fields['error_code'], 0, 64 ) : null,
					'total_ms'      => (int) ( $fields['total_ms'] ?? 0 ),
					'phases'        => $phases_json,
				],
				[ '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s' ]
			);

			self::trim_old( self::MAX_ENTRIES );
		} catch ( \Throwable $e ) {
			// Swallow — observability path must never regress create_media itself.
			// On-disk error_log mirror from the uploader still captures the phases.
		}
	}

	/**
	 * Append an out-of-band phase (e.g. async-subsizes done) to an existing
	 * row by req_id. Silent no-op if the parent row was already trimmed.
	 *
	 * Optionally upgrades the outcome (e.g. deferred -> success) and bumps
	 * updated_at so the row sorts to the top.
	 */
	public static function append_async_phase( string $req_id, array $phase, array $patch = [] ): void {
		try {
			global $wpdb;

			$req_id = substr( $req_id, 0, 8 );
			if ( '' === $req_id ) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT id, phases, outcome FROM %i WHERE req_id = %s ORDER BY id DESC LIMIT 1', self::table_name(), $req_id ),
				ARRAY_A
			);
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				return;
			}

			$existing = json_decode( (string) ( $row['phases'] ?? '[]' ), true );
			if ( ! is_array( $existing ) ) {
				$existing = [];
			}
			$existing[] = $phase;
			$encoded = wp_json_encode( $existing );
			if ( ! is_string( $encoded ) ) {
				return;
			}
			if ( strlen( $encoded ) > self::MAX_PHASES_BYTES ) {
				$encoded = substr( $encoded, 0, self::MAX_PHASES_BYTES - 16 ) . '...(truncated)]';
			}

			$data = [
				'phases'     => $encoded,
				'updated_at' => current_time( 'mysql', true ),
			];
			$format = [ '%s', '%s' ];
			if ( isset( $patch['outcome'] ) ) {
				$data['outcome'] = substr( (string) $patch['outcome'], 0, 32 );
				$format[] = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
			$wpdb->update(
				self::table_name(),
				$data,
				[ 'id' => (int) $row['id'] ],
				$format,
				[ '%d' ]
			);
		} catch ( \Throwable $e ) {
			// Swallow.
		}
	}

	/**
	 * Fetch the most recent $limit entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table_name(), $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Count total entries in the log.
	 */
	public static function count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table count.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table_name() )
		);
	}

	/**
	 * Delete rows older than the newest $keep entries.
	 */
	public static function trim_old( int $keep = self::MAX_ENTRIES ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read for trim.
		$cutoff_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', self::table_name(), $keep )
		);

		if ( $cutoff_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table trim.
			$wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', self::table_name(), $cutoff_id )
			);
		}
	}

	/**
	 * Wipe all log entries.
	 */
	public static function purge(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- TRUNCATE on custom table.
		$wpdb->query(
			$wpdb->prepare( 'TRUNCATE TABLE %i', self::table_name() )
		);
	}
}
