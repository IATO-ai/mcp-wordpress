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
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			actor_kind VARCHAR(30) DEFAULT NULL,
			actor_client_id VARCHAR(64) DEFAULT NULL,
			rolled_back_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			rolled_back_by_kind VARCHAR(30) DEFAULT NULL,
			rolled_back_by_client_id VARCHAR(64) DEFAULT NULL,
			pruned_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY change_id (change_id),
			KEY post_id (post_id),
			KEY target_type (target_type),
			KEY actor_user_id (actor_user_id),
			KEY target_applied (target_type, applied_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Schema version that added the actor columns (IATO_MCP_Schema step 4). */
	public const ACTOR_SCHEMA = 4;

	/**
	 * Do the actor columns exist yet? False only between an upgrade and its
	 * schema step 4 (or while that step keeps failing); receipts are then
	 * written without an actor, exactly as before 1.13.0, rather than lost.
	 */
	public static function has_actor_columns(): bool {
		return ! class_exists( 'IATO_MCP_Schema' ) || IATO_MCP_Schema::current() >= self::ACTOR_SCHEMA;
	}

	/**
	 * Who is making the current request: the MCP credential when one
	 * authenticated it (IATO_MCP_Auth::credential()), otherwise the logged-in
	 * WordPress user with kind `wordpress` (code paths outside the MCP
	 * endpoints).
	 *
	 * @return array{user_id:int,kind:string,client_id:?string}
	 */
	public static function current_actor(): array {
		$credential = class_exists( 'IATO_MCP_Auth' ) ? IATO_MCP_Auth::credential() : [];
		if ( ! empty( $credential['kind'] ) ) {
			return [
				'user_id'   => (int) ( $credential['user_id'] ?? 0 ),
				'kind'      => (string) $credential['kind'],
				'client_id' => isset( $credential['client_id'] ) && '' !== (string) $credential['client_id'] ? (string) $credential['client_id'] : null,
			];
		}
		return [
			'user_id'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'kind'      => 'wordpress',
			'client_id' => null,
		];
	}

	/**
	 * The actor stored on a receipt row, or null for a receipt recorded
	 * before 1.13.0 (no actor columns, or columns left NULL). Receipts
	 * without an actor keep the rollback rules they had.
	 *
	 * @return array{user_id:int,kind:string,client_id:?string}|null
	 */
	public static function actor_of( array $receipt ): ?array {
		$kind = $receipt['actor_kind'] ?? null;
		if ( null === $kind || '' === (string) $kind ) {
			return null;
		}
		$client = $receipt['actor_client_id'] ?? null;
		return [
			'user_id'   => (int) ( $receipt['actor_user_id'] ?? 0 ),
			'kind'      => (string) $kind,
			'client_id' => null !== $client && '' !== (string) $client ? (string) $client : null,
		];
	}

	/** Who rolled the receipt back, or null (not rolled back, or rolled back before 1.13.0). */
	public static function rolled_back_by_of( array $receipt ): ?array {
		return self::actor_of( [
			'actor_user_id'   => $receipt['rolled_back_by_user_id'] ?? null,
			'actor_kind'      => $receipt['rolled_back_by_kind'] ?? null,
			'actor_client_id' => $receipt['rolled_back_by_client_id'] ?? null,
		] );
	}

	/**
	 * An actor for responses: user id, login (null for the unbound site key,
	 * user 0, or a deleted user), credential kind, and for OAuth the client
	 * id and, when the client is still registered, its name.
	 *
	 * @param array{user_id:int,kind:string,client_id:?string}|null $actor
	 */
	public static function describe_actor( ?array $actor ): ?array {
		if ( null === $actor ) {
			return null;
		}
		$login = null;
		if ( $actor['user_id'] > 0 && function_exists( 'get_userdata' ) ) {
			$user  = get_userdata( $actor['user_id'] );
			$login = $user ? (string) $user->user_login : null;
		}
		$out = [
			'user_id'    => $actor['user_id'],
			'user_login' => $login,
			'kind'       => $actor['kind'],
		];
		if ( null !== $actor['client_id'] ) {
			$out['client_id'] = $actor['client_id'];
			$name             = self::client_name( $actor['client_id'] );
			if ( null !== $name ) {
				$out['client_name'] = $name;
			}
		}
		return $out;
	}

	/** @var array<string,?string> client_id => name, per request */
	private static array $client_names = [];

	/** A registered OAuth client's name (memoised per request), or null. */
	public static function client_name( string $client_id ): ?string {
		if ( ! array_key_exists( $client_id, self::$client_names ) ) {
			$name = null;
			if ( class_exists( 'IATO_MCP_OAuth_Store' ) ) {
				$client = IATO_MCP_OAuth_Store::instance()->find_client( $client_id );
				if ( is_array( $client ) && '' !== (string) ( $client['client_name'] ?? '' ) ) {
					$name = (string) $client['client_name'];
				}
			}
			self::$client_names[ $client_id ] = $name;
		}
		return self::$client_names[ $client_id ];
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
	 * Object-level capability a rollback of this receipt requires.
	 *
	 * cap_required_for() answers "which kind of user may roll this type back";
	 * this answers "may they roll back THIS one", by naming the WordPress meta
	 * capability and the object it applies to, so that for example a
	 * Contributor cannot revert another user's published page with a bare
	 * edit_posts. Rules, per target_type (field):
	 *
	 *   post / page / post_meta / elementor_widget   edit_post   <post_id>
	 *   post create (rollback trashes the post)      delete_post <post_id>
	 *   post status restoring publish/future/private edit_post   <post_id> + the type's publish cap (also[])
	 *   image (alt text; post_id is the attachment) edit_post   <attachment_id>
	 *   attachment (create; rollback deletes it)     delete_post <attachment_id>
	 *   taxonomy assign / terms (post-level)         edit_post   <post_id>
	 *   taxonomy create_term (rollback deletes it)   delete_term <after_value.term_id>
	 *   taxonomy update_term                         edit_term   <before_value.term_id>
	 *   taxonomy delete_term (rollback re-creates)   manage_categories (type-level by design: the
	 *                                                 object does not exist until the rollback runs)
	 *   menu_item                                    edit_theme_options (type-level: menus are site-wide)
	 *   redirect                                     manage_options (type-level: site-wide)
	 *   anything else                                manage_options (fail closed)
	 *
	 * When the object no longer exists (deleted post, attachment or term) there
	 * is nothing object-specific left to check, and the rollback will mostly be
	 * a no-op or fail on its own; the check falls back to the type-level
	 * capability from cap_required_for() (edit_posts for post-like receipts,
	 * manage_categories for term receipts). The Bearer plugin-key path passes
	 * every capability regardless, exactly as it does for the type-level check.
	 *
	 * @return array{cap:string, object_id:?int, basis:string, also:list<array{cap:string, object_id:?int}>}
	 *         basis = 'object' | 'type' (object missing) | 'type-by-design'; also = further
	 *         capabilities that must all pass as well (currently only the publish cap).
	 */
	public static function object_cap_for( array $receipt ): array {
		$target_type = isset( $receipt['target_type'] ) ? (string) $receipt['target_type'] : '';
		$field       = isset( $receipt['field'] )       ? (string) $receipt['field']       : '';
		$post_id     = isset( $receipt['post_id'] ) ? (int) $receipt['post_id'] : 0;
		$type_cap    = self::cap_required_for( $receipt );

		$object = static fn( string $cap, int $id ): array => [ 'cap' => $cap, 'object_id' => $id, 'basis' => 'object', 'also' => [] ];
		$typed  = static fn( string $cap, string $basis = 'type' ): array => [ 'cap' => $cap, 'object_id' => null, 'basis' => $basis, 'also' => [] ];

		switch ( $target_type ) {
			case 'post':
				if ( 'create' === $field ) {
					// Rolling back a create trashes the post: that is a delete.
					return ( $post_id > 0 && self::post_exists( $post_id ) ) ? $object( 'delete_post', $post_id ) : $typed( $type_cap );
				}
				if ( 'status' === $field ) {
					if ( $post_id <= 0 || ! self::post_exists( $post_id ) ) {
						return $typed( $type_cap );
					}
					$req = $object( 'edit_post', $post_id );
					// Restoring a live status re-publishes the post, which needs
					// the post type's publish capability on top of edit_post —
					// otherwise a Contributor could publish their own draft by
					// rolling back an Editor's unpublish.
					$restoring_to = is_string( $receipt['before_value'] ?? null ) ? $receipt['before_value'] : '';
					if ( in_array( $restoring_to, IATO_MCP_Auth::PUBLISH_STATUSES, true ) ) {
						$post_type     = (string) ( get_post( $post_id )->post_type ?? 'post' );
						$req['also'][] = [ 'cap' => IATO_MCP_Auth::post_type_cap( $post_type, 'publish_posts' ), 'object_id' => null ];
					}
					return $req;
				}
				return ( $post_id > 0 && self::post_exists( $post_id ) ) ? $object( 'edit_post', $post_id ) : $typed( $type_cap );

			case 'page':
			case 'post_meta':
			case 'elementor_widget':
			case 'image':
				return ( $post_id > 0 && self::post_exists( $post_id ) ) ? $object( 'edit_post', $post_id ) : $typed( $type_cap );

			case 'attachment':
				return ( $post_id > 0 && self::post_exists( $post_id ) ) ? $object( 'delete_post', $post_id ) : $typed( $type_cap );

			case 'taxonomy':
				switch ( $field ) {
					case 'create_term':
						$after   = self::decode_json_field( $receipt['after_value'] ?? null );
						$term_id = (int) ( $after['term_id'] ?? 0 );
						return ( $term_id > 0 && self::term_exists( $term_id ) ) ? $object( 'delete_term', $term_id ) : $typed( $type_cap );
					case 'update_term':
						$before  = self::decode_json_field( $receipt['before_value'] ?? null );
						$term_id = (int) ( $before['term_id'] ?? 0 );
						return ( $term_id > 0 && self::term_exists( $term_id ) ) ? $object( 'edit_term', $term_id ) : $typed( $type_cap );
					case 'delete_term':
						return $typed( $type_cap, 'type-by-design' );
					default: // assign, terms
						return ( $post_id > 0 && self::post_exists( $post_id ) ) ? $object( 'edit_post', $post_id ) : $typed( $type_cap );
				}

			case 'menu_item':
			case 'redirect':
				return $typed( $type_cap, 'type-by-design' );

			default:
				return $typed( $type_cap, 'type-by-design' ); // cap_required_for() fails closed at manage_options
		}
	}

	private static function post_exists( int $post_id ): bool {
		return function_exists( 'get_post' ) && null !== get_post( $post_id );
	}

	private static function term_exists( int $term_id ): bool {
		if ( ! function_exists( 'get_term' ) ) {
			return false;
		}
		$term = get_term( $term_id );
		return $term && ! ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) );
	}

	private static function decode_json_field( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : [];
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
		$actor      = self::current_actor();

		$row    = [
			'change_id'    => $change_id,
			'post_id'      => $post_id,
			'target_type'  => $target_type,
			'field'        => $field,
			'before_value' => $before_stored,
			'after_value'  => $after_stored,
			'applied_at'   => $applied_at,
		];
		$format = [ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ];
		if ( self::has_actor_columns() ) {
			// Who made the change (1.13.0): the WordPress user the request ran
			// as, the credential kind, and the OAuth client. The rollback rule
			// (same user or an administrator) reads these.
			$row['actor_user_id']   = $actor['user_id'];
			$row['actor_kind']      = $actor['kind'];
			$row['actor_client_id'] = $actor['client_id'];
			array_push( $format, '%d', '%s', '%s' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; no cache to invalidate, insert is the canonical source of truth.
		$wpdb->insert( self::table_name(), $row, $format );

		// Retention: if the daily cleanup of old Elementor page copies is overdue
		// (WP-Cron not running), run one batch at the end of this request.
		if ( class_exists( 'IATO_MCP_Receipt_Retention' ) ) {
			IATO_MCP_Receipt_Retention::maybe_run_overdue();
		}

		return [
			'change_id'    => $change_id,
			'post_id'      => $post_id,
			'target_type'  => $target_type,
			'field'        => $field,
			'before_value' => $before,
			'after_value'  => $after,
			'applied_at'   => gmdate( 'c', strtotime( $applied_at ) ),
			'actor'        => self::describe_actor( $actor ),
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
	 * Mark a receipt as rolled back, and by whom (1.13.0).
	 *
	 * @param string     $change_id The wr_ prefixed ID.
	 * @param array|null $by        Actor (current_actor() shape); defaults to the current request's.
	 * @return bool True on success.
	 */
	public static function mark_rolled_back( string $change_id, ?array $by = null ): bool {
		global $wpdb;

		$data   = [ 'rolled_back_at' => current_time( 'mysql', true ) ];
		$format = [ '%s' ];
		if ( self::has_actor_columns() ) {
			$by = $by ?? self::current_actor();
			$data['rolled_back_by_user_id']   = (int) $by['user_id'];
			$data['rolled_back_by_kind']      = (string) $by['kind'];
			$data['rolled_back_by_client_id'] = $by['client_id'];
			array_push( $format, '%d', '%s', '%s' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; no cache to invalidate.
		$updated = $wpdb->update(
			self::table_name(),
			$data,
			[ 'change_id' => $change_id ],
			$format,
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
