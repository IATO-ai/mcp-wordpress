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
	 * Required capability for rolling back a receipt.
	 *
	 * Convention: **rollback cap === create-time cap.** A user who could do
	 * the operation can undo it; a user who couldn't, can't. The map below
	 * mirrors the cap each recording tool enforces at its create-time
	 * require_cap() call. Keep them in lockstep — if a tool's create-time
	 * cap changes, the rollback entry here changes too.
	 *
	 * Lookup keys off the receipt's target_type, with a single field-
	 * discriminated branch for 'taxonomy' (because that target_type covers
	 * both editorial assign-term operations at edit_posts and admin-level
	 * term CRUD at manage_categories — neither single cap matches both
	 * create-time tools).
	 *
	 * Unknown target_types fail closed at manage_options — adding a new
	 * receipt type without an entry here means rollback for that type is
	 * gated at admin-only until the entry is added. Same fail-closed
	 * semantics extend to unknown fields under 'taxonomy': any field not in
	 * the known editorial set falls through to manage_categories (the
	 * higher of the two taxonomy caps), so an unrecognised taxonomy field
	 * doesn't silently inherit the lower cap.
	 *
	 *   target_type        record-time tool's require_cap      rollback cap (this map)
	 *   post / page        edit_posts                          edit_posts
	 *   image / attachment edit_posts / upload_files           edit_posts
	 *   post_meta          edit_posts                          edit_posts
	 *   elementor_widget   edit_posts                          edit_posts
	 *   menu_item          edit_theme_options (v1.10.0)        edit_theme_options
	 *   redirect           manage_options                      manage_options
	 *   taxonomy + assign/terms     edit_posts                 edit_posts
	 *   taxonomy + create/update/delete_term  manage_categories  manage_categories
	 *   <unknown>          n/a                                  manage_options (fail closed)
	 *
	 * @param array $receipt Receipt row (must contain at least target_type; field is consulted for taxonomy).
	 * @return string A WordPress capability string.
	 */
	public static function cap_required_for( array $receipt ): string {
		$target_type = isset( $receipt['target_type'] ) ? (string) $receipt['target_type'] : '';
		$field       = isset( $receipt['field'] )       ? (string) $receipt['field']       : '';

		// Field-discriminated branch: 'taxonomy' covers both editorial and
		// admin operations. Term CRUD requires the higher cap; assign / update
		// at the post level stays at edit_posts.
		if ( 'taxonomy' === $target_type ) {
			$admin_fields = [ 'create_term', 'update_term', 'delete_term' ];
			return in_array( $field, $admin_fields, true ) ? 'manage_categories' : 'edit_posts';
		}

		$caps = [
			'post'             => 'edit_posts',
			'page'             => 'edit_posts',
			'image'            => 'edit_posts',
			'attachment'       => 'edit_posts',
			'post_meta'        => 'edit_posts',
			'elementor_widget' => 'edit_posts',
			'menu_item'        => 'edit_theme_options',
			'redirect'         => 'manage_options',
		];

		// Fail closed: unknown target_types are gated at manage_options
		// until they're explicitly added to the map. A new receipt type
		// shouldn't silently inherit a lower cap.
		return $caps[ $target_type ] ?? 'manage_options';
	}

	/**
	 * Record a change receipt after a successful write.
	 *
	 * @param int|null $post_id     WordPress post/attachment/menu-item ID, or null for non-post targets.
	 * @param string   $target_type One of: post, page, image, menu_item, taxonomy, redirect, structured_data,
	 *                              elementor_widget, post_meta, attachment.
	 * @param string   $field       The field that was changed (for target_type=post_meta this is the meta key).
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; no cache to invalidate, insert is the canonical source of truth.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read; receipts aren't cached.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE change_id = %s', self::table_name(), $change_id ),
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; no cache to invalidate.
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
