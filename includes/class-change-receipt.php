<?php
/**
 * Change Receipt — records before/after values for every write operation.
 *
 * Provides the foundation for Phase 2 write-with-rollback. Every write tool
 * calls record() after mutating data; the rollback endpoint uses get() and
 * mark_rolled_back() to reverse changes.
 *
 * Table: {prefix}iato_change_receipts
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Change_Receipt {

	/**
	 * Get the full table name including prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'iato_change_receipts';
	}

	/**
	 * Create the change receipts table. Called on plugin activation.
	 *
	 * Uses dbDelta() for safe creation and future schema upgrades.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			change_id VARCHAR(19) NOT NULL,
			post_id BIGINT UNSIGNED DEFAULT NULL,
			target_type VARCHAR(50) NOT NULL,
			field VARCHAR(100) NOT NULL,
			before_value LONGTEXT DEFAULT NULL,
			after_value LONGTEXT DEFAULT NULL,
			applied_at DATETIME NOT NULL,
			rolled_back_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY change_id (change_id),
			KEY post_id (post_id),
			KEY target_type (target_type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Generate a unique change_id: wr_ + 16 hex characters.
	 *
	 * @return string e.g. "wr_a3f8c2d1b4e5f6a7"
	 */
	public static function generate_id(): string {
		return 'wr_' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Record a change receipt after a successful write.
	 *
	 * @param int|null $post_id     WordPress post/attachment/menu-item ID, or null for non-post targets.
	 * @param string   $target_type One of: page, image, menu_item, taxonomy, redirect, structured_data.
	 * @param string   $field       The field that was changed.
	 * @param mixed    $before      Value before the write. null if field was unset. Arrays are JSON-encoded.
	 * @param mixed    $after       Value after the write. null if field was deleted. Arrays are JSON-encoded.
	 * @return array The change receipt array (ready to append to tool response).
	 */
	public static function record( ?int $post_id, string $target_type, string $field, mixed $before, mixed $after ): array {
		global $wpdb;

		// Normalize: empty strings become null (spec: null if unset, never empty string).
		if ( '' === $before ) {
			$before = null;
		}
		if ( '' === $after ) {
			$after = null;
		}

		// JSON-encode arrays/objects for storage.
		$before_stored = is_array( $before ) || is_object( $before ) ? wp_json_encode( $before ) : $before;
		$after_stored  = is_array( $after ) || is_object( $after ) ? wp_json_encode( $after ) : $after;

		$change_id  = self::generate_id();
		$applied_at = current_time( 'mysql', true ); // UTC.

		$wpdb->insert(
			self::table_name(),
			[
				'change_id'    => $change_id,
				'post_id'      => $post_id,
				'target_type'  => $target_type,
				'field'        => $field,
				'before_value' => $before_stored,
				'after_value'  => $after_stored,
				'applied_at'   => $applied_at,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		return [
			'change_id'    => $change_id,
			'post_id'      => $post_id,
			'target_type'  => $target_type,
			'field'        => $field,
			'before_value' => $before,
			'after_value'  => $after,
			'applied_at'   => gmdate( 'c', strtotime( $applied_at ) ),
		];
	}

	/**
	 * Fetch a change receipt by change_id.
	 *
	 * @param string $change_id The wr_ prefixed ID.
	 * @return array|null Row as associative array, or null if not found.
	 */
	public static function get( string $change_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE change_id = %s',
				$change_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Mark a receipt as rolled back.
	 *
	 * @param string $change_id The wr_ prefixed ID.
	 * @return bool True on success.
	 */
	public static function mark_rolled_back( string $change_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			self::table_name(),
			[ 'rolled_back_at' => current_time( 'mysql', true ) ],
			[ 'change_id' => $change_id ],
			[ '%s' ],
			[ '%s' ]
		);

		return false !== $updated;
	}

	/**
	 * Append a change_receipt key to a tool response data array.
	 *
	 * @param array $data    The response data array (passed by reference).
	 * @param array $receipt The receipt from record().
	 */
	public static function append( array &$data, array $receipt ): void {
		$data['change_receipt'] = $receipt;
	}
}
