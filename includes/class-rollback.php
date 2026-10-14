<?php
/**
 * Rollback Endpoint — POST /wp-json/iato-mcp/v1/rollback
 *
 * Restores a previous value using a stored change receipt.
 * Validates before_value against the stored receipt to prevent tampering.
 * This is the authoritative rollback mechanism — Phase 1 will call this endpoint.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Rollback {

	/**
	 * Register the REST route.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
	}

	/**
	 * Register POST /wp-json/iato-mcp/v1/rollback.
	 */
	public static function register_route(): void {
		register_rest_route( 'iato-mcp/v1', '/rollback', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle' ],
			'permission_callback' => [ 'IATO_MCP_Auth', 'authenticate' ],
		] );
	}

	/**
	 * Handle the rollback request.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();

		$change_id    = sanitize_text_field( $body['change_id'] ?? '' );
		$before_value = $body['before_value'] ?? null;
		$force        = ! empty( $body['force'] );
		$dry_run      = ! empty( $body['dry_run'] );

		if ( empty( $change_id ) ) {
			return new WP_Error( 'missing_change_id', 'change_id is required.', [ 'status' => 400 ] );
		}

		// Auth baseline: the route's permission_callback only proves the caller
		// holds a valid credential. Enforce the same capabilities the `rollback`
		// MCP tool enforces (includes/tools/wp/tool-rollback.php): edit_posts to
		// look up a receipt at all, then the receipt type's own capability
		// (menu_item → edit_theme_options, redirect → manage_options, term CRUD →
		// manage_categories, unknown types → manage_options). Without this, any
		// edit_posts Application Password user who knows a change_id and its
		// before_value could roll back admin-level changes through this route.
		$auth_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $auth_check ) ) {
			return $auth_check;
		}

		if ( ! preg_match( '/^wr_[a-f0-9]{16}$/', $change_id ) ) {
			return new WP_Error( 'invalid_change_id', 'change_id must match ^wr_[a-f0-9]{16}$', [ 'status' => 400 ] );
		}

		// Look up receipt.
		$receipt = IATO_MCP_Change_Receipt::get( $change_id );
		if ( ! $receipt ) {
			return self::error_response( 'change_id not found', $change_id, 404 );
		}

		$perm = self::check_permission( $receipt );
		if ( is_wp_error( $perm ) ) {
			return $perm;
		}

		// Already rolled back?
		if ( ! empty( $receipt['rolled_back_at'] ) ) {
			return self::error_response( 'already rolled back', $change_id, 400, [
				'rolled_back_at' => $receipt['rolled_back_at'],
				'rolled_back_by' => IATO_MCP_Change_Receipt::describe_actor( IATO_MCP_Change_Receipt::rolled_back_by_of( $receipt ) ),
			] );
		}

		// Validate before_value matches stored receipt.
		if ( ! self::values_match( $before_value, $receipt['before_value'] ) ) {
			return self::error_response( 'before_value mismatch — rollback rejected', $change_id, 400 );
		}

		$result = self::execute( $receipt, [ 'force' => $force, 'dry_run' => $dry_run ] );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$data = is_array( $data ) ? $data : [];
			if ( 'rollback_conflict' === $result->get_error_code() ) {
				unset( $data['status'], $data['change_id'] );
				return self::error_response( $result->get_error_message(), $change_id, 409, [ 'code' => 'rollback_conflict' ] + $data );
			}
			if ( '__internal__' === $result->get_error_code() ) {
				return self::error_response( $result->get_error_message(), $change_id, 400 );
			}
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * May the authenticated caller roll back this receipt? Object-level where
	 * the object still exists (edit_post / delete_post / edit_term / delete_term
	 * on the specific ID), the type-level capability otherwise. Shared by the
	 * REST route and the `rollback` MCP tool so they cannot drift. Goes through
	 * IATO_MCP_Auth::require_cap(), so the site Bearer key (no user) passes as
	 * it does for every other capability.
	 *
	 * @return true|WP_Error
	 */
	public static function check_permission( array $receipt ): bool|WP_Error {
		$req    = IATO_MCP_Change_Receipt::object_cap_for( $receipt );
		$checks = array_merge( [ [ 'cap' => $req['cap'], 'object_id' => $req['object_id'] ] ], $req['also'] ?? [] );
		foreach ( $checks as $check ) {
			$result = null !== $check['object_id']
				? IATO_MCP_Auth::require_cap( $check['cap'], $check['object_id'] )
				: IATO_MCP_Auth::require_cap( $check['cap'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Who may undo whose change (1.13.0): the user who made it, or an
		// administrator (manage_options; the unbound site key counts as one, as
		// it does for every capability). On top of the object-level checks
		// above, never instead of them. Receipts recorded before 1.13.0 carry no
		// actor and keep the rules above alone.
		$actor = IATO_MCP_Change_Receipt::actor_of( $receipt );
		if ( null !== $actor && ! self::may_undo_for( $actor ) ) {
			$described = IATO_MCP_Change_Receipt::describe_actor( $actor );
			$who       = $described['user_login'] ?? null;
			$who       = null !== $who ? $who : ( 0 === $actor['user_id'] ? 'the site key (no user)' : 'user ' . $actor['user_id'] );
			return new WP_Error(
				'rollback_actor_mismatch',
				sprintf(
					/* translators: 1: user login or description, 2: credential kind */
					__( 'This change was made by %1$s (%2$s). Only that user or an administrator can roll it back.', 'iato-mcp' ),
					$who,
					$actor['kind']
				),
				[ 'status' => 403, 'actor' => $described ]
			);
		}
		return true;
	}

	/** Is the current request the actor's own user, or an administrator? */
	private static function may_undo_for( array $actor ): bool {
		if ( ! is_wp_error( IATO_MCP_Auth::require_cap( 'manage_options' ) ) ) {
			return true;
		}
		$me = IATO_MCP_Change_Receipt::current_actor();
		return $me['user_id'] > 0 && $me['user_id'] === (int) $actor['user_id'];
	}

	/**
	 * Perform a rollback by change_id. The caller has already run
	 * check_permission() (the `rollback` tool does).
	 *
	 * @param string $change_id The wr_ prefixed change receipt ID.
	 * @param array  $opts      force (bool): roll back despite a conflict;
	 *                          dry_run (bool): report, write nothing.
	 * @return array|WP_Error Success array with rollback details, or WP_Error.
	 */
	public static function rollback_by_id( string $change_id, array $opts = [] ): array|WP_Error {
		$receipt = IATO_MCP_Change_Receipt::get( $change_id );
		if ( ! $receipt ) {
			return new WP_Error( 'not_found', 'change_id not found.' );
		}
		$result = self::execute( $receipt, $opts );
		if ( is_wp_error( $result ) && '__internal__' === $result->get_error_code() ) {
			return new WP_Error( 'rollback_failed', $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * The rollback itself, shared by the REST route and the MCP tool:
	 * already-rolled-back check, receipts that cannot be rolled back, the
	 * conflict check (refused unless force), dispatch, and the rolled-back
	 * mark with who did it.
	 *
	 * @return array|WP_Error
	 */
	public static function execute( array $receipt, array $opts = [] ): array|WP_Error {
		$force     = ! empty( $opts['force'] );
		$dry_run   = ! empty( $opts['dry_run'] );
		$change_id = (string) ( $receipt['change_id'] ?? '' );
		$post_id   = ! empty( $receipt['post_id'] ) ? (int) $receipt['post_id'] : null;
		$before    = $receipt['before_value'] ?? null;

		if ( ! empty( $receipt['rolled_back_at'] ) ) {
			return new WP_Error(
				'already_rolled_back',
				'Already rolled back at ' . $receipt['rolled_back_at'],
				[
					'status'         => 409,
					'rolled_back_at' => $receipt['rolled_back_at'],
					'rolled_back_by' => IATO_MCP_Change_Receipt::describe_actor( IATO_MCP_Change_Receipt::rolled_back_by_of( $receipt ) ),
				]
			);
		}

		$unavailable = self::preflight( $receipt );
		if ( is_wp_error( $unavailable ) ) {
			return $unavailable;
		}

		$base = [
			'change_id'   => $change_id,
			'post_id'     => $post_id,
			'target_type' => (string) $receipt['target_type'],
			'field'       => (string) $receipt['field'],
			'actor'       => IATO_MCP_Change_Receipt::describe_actor( IATO_MCP_Change_Receipt::actor_of( $receipt ) ),
		];
		// An Elementor receipt's before value is the whole stored tree; responses
		// carry its revision hash instead (the hash get_elementor_data reports).
		$is_tree = 'elementor_widget' === $receipt['target_type'];
		$restore = [ 'restored_value' => $before ];
		if ( $is_tree ) {
			$restore = null === $before || null !== self::no_elementor_from( $before )
				? [ 'restored_revision' => null, 'restored' => 'no_elementor_data' ]
				: [ 'restored_revision' => IATO_MCP_Elementor_Adapter::compute_revision( (string) $before ) ];
		}

		$check  = IATO_MCP_Rollback_Conflict::check( $receipt );
		$report = [ 'status' => $check['status'], 'reason' => $check['reason'] ];
		if ( 'conflict' === $check['status'] ) {
			$report['current_value']  = $check['current'] ?? null;
			$report['expected_value'] = $check['expected'] ?? null;
			if ( $force ) {
				$report['forced'] = true;
			}
		}

		if ( $dry_run ) {
			return [ 'dry_run' => true, 'would_roll_back' => 'conflict' !== $check['status'] || $force ]
				+ $base
				+ ( $is_tree ? [ 'would_restore_revision' => $restore['restored_revision'] ] : [ 'would_restore' => $before ] )
				+ [ 'conflict_check' => $report ];
		}

		if ( 'conflict' === $check['status'] && ! $force ) {
			return new WP_Error(
				'rollback_conflict',
				sprintf( 'Rollback refused: %s. Rolling back would overwrite that newer change. Roll back the later change first, or pass force: true to roll back anyway.', $check['reason'] ),
				[ 'status' => 409, 'conflict_check' => $report ] + $base
			);
		}

		$result = self::dispatch_rollback( $receipt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$by = IATO_MCP_Change_Receipt::current_actor();
		IATO_MCP_Change_Receipt::mark_rolled_back( $change_id, $by );

		return [ 'success' => true ]
			+ $base
			+ $restore
			+ [
				'rolled_back_at' => gmdate( 'c' ),
				'rolled_back_by' => IATO_MCP_Change_Receipt::describe_actor( $by ),
				'conflict_check' => $report,
			];
	}

	/**
	 * Receipts that cannot be rolled back at all, reported before the
	 * conflict check so the caller learns why.
	 *
	 * @return true|WP_Error
	 */
	private static function preflight( array $receipt ): bool|WP_Error {
		$type   = (string) ( $receipt['target_type'] ?? '' );
		$field  = (string) ( $receipt['field'] ?? '' );
		$before = $receipt['before_value'] ?? null;

		// The stored copy was removed by the retention cleanup. Checked first: an
		// emptied before value must never be read as "there was nothing before".
		if ( ! empty( $receipt['pruned_at'] ) ) {
			return new WP_Error(
				'rollback_unavailable',
				sprintf(
					'The copy of the page this change replaced was removed on %s, when the receipt became older than the retention period. Restore the page in Elementor under History > Revisions.',
					(string) $receipt['pruned_at']
				),
				[ 'status' => 409, 'pruned_at' => (string) $receipt['pruned_at'] ]
			);
		}

		if ( 'elementor_widget' === $type && is_string( $before ) && preg_match( '/^sha256:[a-f0-9]{64}$/', $before ) ) {
			return new WP_Error(
				'rollback_unavailable',
				'This Elementor change was recorded before 1.13.0, which stored only a revision hash, not the page as it was. Restore the page in Elementor under History > Revisions.',
				[ 'status' => 409 ]
			);
		}
		if ( 'taxonomy' === $type && 'update_term' === $field ) {
			$snapshot = IATO_MCP_Rollback_Conflict::json_array( $before );
			if ( (int) ( $snapshot['term_id'] ?? 0 ) <= 0 ) {
				return new WP_Error(
					'rollback_unavailable',
					'This term change was recorded before 1.13.0 without the term ID, so the term to restore cannot be identified. Edit the term under Posts > Categories or Tags.',
					[ 'status' => 409 ]
				);
			}
		}
		return true;
	}

	/**
	 * Dispatch the rollback action based on target_type and field.
	 *
	 * @param array $receipt The receipt row (before_value is the value to restore).
	 * @return true|WP_Error
	 */
	private static function dispatch_rollback( array $receipt ): bool|WP_Error {
		$target_type  = (string) $receipt['target_type'];
		$field        = (string) $receipt['field'];
		$post_id      = ! empty( $receipt['post_id'] ) ? (int) $receipt['post_id'] : null;
		$before_value = $receipt['before_value'] ?? null;

		switch ( $target_type ) {
			case 'page':
				return self::rollback_page( $field, $post_id, $before_value );
			case 'post':
				return self::rollback_post( $field, $post_id, $before_value );
			case 'image':
				return self::rollback_image( $field, $post_id, $before_value );
			case 'menu_item':
				return self::rollback_menu_item( $field, $post_id, $before_value );
			case 'taxonomy':
				return self::rollback_taxonomy( $field, $post_id, $before_value, $receipt['after_value'] ?? null );
			case 'redirect':
				return self::rollback_redirect( $field, $before_value );
			case 'post_meta':
				return self::rollback_post_meta( $field, $post_id, $before_value );
			case 'attachment':
				return self::rollback_attachment( $field, $post_id, $before_value );
			case 'elementor_widget':
				return self::rollback_elementor( $post_id, $before_value );
			default:
				return new WP_Error( 'unsupported_target_type', "Rollback not supported for target_type: {$target_type}" );
		}
	}

	/**
	 * Before value of an Elementor receipt for a post that had no Elementor
	 * data (update_elementor_data on a plain post): Elementor's save replaced
	 * post_content with its rendering of the tree, so the receipt keeps the
	 * original content and builder flag. A JSON object, never mistaken for a
	 * tree (Elementor data is a JSON array).
	 */
	public static function no_elementor_envelope( string $post_content, string $edit_mode ): string {
		return (string) wp_json_encode( [ 'iato_no_elementor_data' => true, 'post_content' => $post_content, 'edit_mode' => $edit_mode ] );
	}

	/** The envelope's fields, or null when $before is not one. */
	public static function no_elementor_from( mixed $before ): ?array {
		if ( ! is_string( $before ) || ! str_starts_with( ltrim( $before ), '{' ) ) {
			return null;
		}
		$decoded = json_decode( $before, true );
		return is_array( $decoded ) && ! empty( $decoded['iato_no_elementor_data'] ) ? $decoded : null;
	}

	/**
	 * Rollback an Elementor write (1.13.0): the receipt's before value is the
	 * `_elementor_data` text as it was stored before the write, restored
	 * verbatim through the post_meta path (kses lock lifted, Elementor's meta
	 * sanitize callbacks out of the way, caches cleared). For a post that had
	 * no Elementor data the before value is an envelope: the data is removed,
	 * the builder flag and the original post_content are put back.
	 */
	private static function rollback_elementor( ?int $post_id, mixed $before_value ): bool|WP_Error {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		$plain = self::no_elementor_from( $before_value );
		if ( null === $plain && null !== $before_value ) {
			return self::rollback_post_meta( '_elementor_data', $post_id, $before_value );
		}

		$result = self::rollback_post_meta( '_elementor_data', $post_id, null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$mode = null === $plain ? '' : (string) ( $plain['edit_mode'] ?? '' );
		if ( '' === $mode ) {
			delete_post_meta( $post_id, '_elementor_edit_mode' );
		} else {
			update_post_meta( $post_id, '_elementor_edit_mode', wp_slash( $mode ) );
		}
		if ( null !== $plain && isset( $plain['post_content'] ) ) {
			return self::restore_post_content( $post_id, (string) $plain['post_content'] );
		}
		return true;
	}

	/**
	 * Put a post's content back exactly as it was stored (a value read from the
	 * database when the receipt was recorded). WordPress' kses content filters
	 * are on for every MCP request (the 1.13.0 lock); they would filter the
	 * restored content again and strip what a person with unfiltered_html had
	 * stored, so they are removed for this one write and put back afterwards,
	 * also when it fails. The value is slashed because wp_update_post() unslashes.
	 */
	private static function restore_post_content( int $post_id, string $content ): bool|WP_Error {
		$kses_on = function_exists( 'kses_remove_filters' ) && false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses_on ) {
			kses_remove_filters();
		}
		try {
			$result = IATO_MCP_Auth::write_unlocked( fn() => wp_update_post( wp_slash( [ 'ID' => $post_id, 'post_content' => $content ] ), true ) );
		} finally {
			if ( $kses_on ) {
				kses_init_filters();
			}
		}
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Rollback an arbitrary post_meta write.
	 *
	 * Used by update_post_meta, set_page_settings, and set_featured_image —
	 * they all record their changes under target_type=post_meta with the
	 * meta key in `field`. If the before_value was null the key was unset
	 * before the write, so rollback deletes it; otherwise the previous value
	 * is restored.
	 */
	private static function rollback_post_meta( string $field, ?int $post_id, mixed $before_value ): bool|WP_Error {
		if ( ! $post_id ) {
			return new WP_Error( 'post_not_found', 'post_id is required for post_meta rollback.' );
		}
		if ( '' === $field ) {
			return new WP_Error( 'unsupported_field', 'post_meta rollback requires a non-empty field (meta key).' );
		}
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		if ( null === $before_value ) {
			delete_post_meta( $post_id, $field );
		} else {
			// Arrays/objects were JSON-encoded into the receipt; decode them back
			// for round-trip parity. Elementor's _elementor_data is JSON text in
			// the database itself and must come back as a string (Elementor and
			// the plugin's reader expect text). For other keys, decode only when
			// the key does not currently hold a string.
			$restore          = $before_value;
			$current          = get_post_meta( $post_id, $field, true );
			$is_json_text_key = ( '_elementor_data' === $field );
			if ( ! $is_json_text_key && is_string( $restore ) && ( str_starts_with( $restore, '{' ) || str_starts_with( $restore, '[' ) ) && ! ( is_string( $current ) && '' !== $current ) ) {
				$decoded = json_decode( $restore, true );
				if ( null !== $decoded ) {
					$restore = $decoded;
				}
			}
			// Verbatim: the value was captured from the database before the
			// change; for Elementor keys the kses lock is lifted and Elementor's
			// REST sanitize_callback removed, so nothing re-filters it on the way
			// back.
			// Slashed, arrays included: update_post_meta() unslashes both.
			$value = wp_slash( $restore );
			if ( 0 === stripos( $field, '_elementor_' ) && class_exists( 'IATO_MCP_Elementor_Adapter' ) ) {
				IATO_MCP_Elementor_Adapter::write_elementor_meta( fn() => update_post_meta( $post_id, $field, $value ) );
			} else {
				IATO_MCP_Auth::write_unlocked( fn() => update_post_meta( $post_id, $field, $value ) );
			}
		}

		clean_post_cache( $post_id );
		if ( 0 === stripos( $field, '_elementor_' ) ) {
			delete_post_meta( $post_id, '_elementor_css' );
			wp_cache_delete( $post_id, 'post_meta' );
			wp_cache_delete( $post_id, 'posts' );
			if ( class_exists( '\Elementor\Plugin' ) ) {
				$plugin = \Elementor\Plugin::$instance;
				if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
					$plugin->files_manager->clear_cache();
				}
			}
		}

		return true;
	}

	/**
	 * Rollback an attachment created via create_media.
	 *
	 * field='create' is the only currently-supported attachment receipt; the
	 * rollback action is to fully delete the attachment (file and post record).
	 */
	private static function rollback_attachment( string $field, ?int $post_id, mixed $before_value ): bool|WP_Error {
		if ( 'create' !== $field ) {
			return new WP_Error( 'unsupported_field', "Rollback not supported for attachment field: {$field}" );
		}
		if ( ! $post_id ) {
			return new WP_Error( 'post_not_found', 'Attachment ID is required for attachment rollback.' );
		}
		$attachment = get_post( $post_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			// Already removed — treat as success so the receipt can be marked rolled-back.
			return true;
		}
		$deleted = wp_delete_attachment( $post_id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', 'Failed to delete attachment.' );
		}
		return true;
	}

	/**
	 * Rollback WP-core post fields (title, content, excerpt, status) and create.
	 */
	private static function rollback_post( string $field, ?int $post_id, mixed $before_value ): bool|WP_Error {
		if ( ! $post_id ) {
			return new WP_Error( 'post_not_found', 'post_id is required for post rollback.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		// Reverse a create by trashing the post. Already-trashed is treated as success.
		if ( 'create' === $field ) {
			if ( 'trash' === $post->post_status ) {
				return true;
			}
			$trashed = wp_trash_post( $post_id );
			if ( ! $trashed ) {
				return new WP_Error( 'trash_failed', 'Failed to trash post.' );
			}
			return true;
		}

		$post_key = match ( $field ) {
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
			'slug'    => 'post_name',
			default   => null,
		};

		if ( null === $post_key ) {
			return new WP_Error( 'unsupported_field', "Rollback not supported for post field: {$field}" );
		}

		// Slashed: wp_update_post() unslashes, which would strip the restored
		// value's backslashes.
		$result = wp_update_post( wp_slash( [
			'ID'      => $post_id,
			$post_key => $before_value,
		] ), true );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Rollback page-level fields (SEO title, description, canonical, structured data).
	 */
	private static function rollback_page( string $field, ?int $post_id, mixed $before_value ): bool|WP_Error {
		if ( ! $post_id ) {
			return new WP_Error( 'post_not_found', 'post_id is required for page rollback.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		switch ( $field ) {
			case 'title':
				if ( null === $before_value ) {
					return self::delete_seo_title_meta( $post_id );
				}
				return IATO_MCP_SEO_Adapter::update_title( $post_id, $before_value );

			case 'meta_description':
				if ( null === $before_value ) {
					return self::delete_seo_desc_meta( $post_id );
				}
				return IATO_MCP_SEO_Adapter::update_description( $post_id, $before_value );

			case 'canonical_url':
				if ( null === $before_value ) {
					IATO_MCP_SEO_Adapter::delete_canonical( $post_id );
					return true;
				}
				return IATO_MCP_SEO_Adapter::update_canonical( $post_id, $before_value );

			case 'structured_data':
				if ( null === $before_value ) {
					delete_post_meta( $post_id, '_iato_mcp_structured_data' );
					return true;
				}
				update_post_meta( $post_id, '_iato_mcp_structured_data', wp_slash( (string) $before_value ) );
				return true;

			default:
				return new WP_Error( 'unsupported_field', "Rollback not supported for page field: {$field}" );
		}
	}

	/**
	 * Rollback image alt text.
	 */
	private static function rollback_image( string $field, ?int $post_id, mixed $before_value ): bool|WP_Error {
		if ( 'alt_text' !== $field ) {
			return new WP_Error( 'unsupported_field', "Rollback not supported for image field: {$field}" );
		}
		if ( ! $post_id ) {
			return new WP_Error( 'post_not_found', 'Attachment ID is required.' );
		}

		if ( null === $before_value ) {
			delete_post_meta( $post_id, '_wp_attachment_image_alt' );
		} else {
			update_post_meta( $post_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $before_value ) ) );
		}

		return true;
	}

	/**
	 * Rollback menu item changes.
	 */
	private static function rollback_menu_item( string $field, ?int $post_id, mixed $before_value ): bool|WP_Error {
		switch ( $field ) {
			case 'create':
				// Reverse creation: delete the menu item.
				if ( ! $post_id ) {
					return new WP_Error( 'missing_post_id', 'Menu item ID required for rollback.' );
				}
				$deleted = wp_delete_post( $post_id, true );
				return $deleted ? true : new WP_Error( 'delete_failed', 'Failed to delete menu item.' );

			case 'delete':
				// Reverse deletion: re-create the menu item from snapshot.
				$snapshot = json_decode( $before_value, true );
				if ( ! is_array( $snapshot ) || empty( $snapshot['menu_id'] ) ) {
					return new WP_Error( 'invalid_snapshot', 'Cannot restore deleted menu item: invalid snapshot.' );
				}

				$item_data = [
					'menu-item-title'     => $snapshot['title'] ?? '',
					'menu-item-url'       => $snapshot['url'] ?? '',
					'menu-item-type'      => $snapshot['type'] ?? 'custom',
					'menu-item-object'    => $snapshot['object'] ?? '',
					'menu-item-object-id' => $snapshot['object_id'] ?? 0,
					'menu-item-parent-id' => $snapshot['parent_id'] ?? 0,
					'menu-item-position'  => $snapshot['position'] ?? 0,
					'menu-item-status'    => 'publish',
				];

				$result = wp_update_nav_menu_item( (int) $snapshot['menu_id'], 0, wp_slash( $item_data ) );
				return is_wp_error( $result ) ? $result : true;

			case 'details':
				// Reverse detail changes: re-apply the before values.
				if ( ! $post_id ) {
					return new WP_Error( 'missing_post_id', 'Menu item ID required for rollback.' );
				}

				$before_vals = json_decode( $before_value, true );
				if ( ! is_array( $before_vals ) ) {
					return new WP_Error( 'invalid_before', 'Cannot restore menu item details: invalid before_value.' );
				}

				$menu_terms = wp_get_object_terms( $post_id, 'nav_menu' );
				if ( is_wp_error( $menu_terms ) || empty( $menu_terms ) ) {
					return new WP_Error( 'no_menu', 'Could not determine menu for this item.' );
				}
				$menu_id = $menu_terms[0]->term_id;

				$item = get_post( $post_id );
				if ( ! $item ) {
					return new WP_Error( 'post_not_found', 'Menu item not found.' );
				}

				$item_data = [
					'menu-item-type'      => get_post_meta( $post_id, '_menu_item_type', true ),
					'menu-item-object'    => get_post_meta( $post_id, '_menu_item_object', true ),
					'menu-item-object-id' => get_post_meta( $post_id, '_menu_item_object_id', true ),
					'menu-item-title'     => $before_vals['title'] ?? $item->post_title,
					'menu-item-url'       => $before_vals['url'] ?? get_post_meta( $post_id, '_menu_item_url', true ),
					'menu-item-status'    => 'publish',
					'menu-item-position'  => $before_vals['position'] ?? $item->menu_order,
					'menu-item-parent-id' => $before_vals['parent_id'] ?? get_post_meta( $post_id, '_menu_item_menu_item_parent', true ),
				];

				$result = wp_update_nav_menu_item( $menu_id, $post_id, wp_slash( $item_data ) );
				return is_wp_error( $result ) ? $result : true;

			default:
				return new WP_Error( 'unsupported_field', "Rollback not supported for menu_item field: {$field}" );
		}
	}

	/**
	 * Rollback taxonomy changes.
	 */
	private static function rollback_taxonomy( string $field, ?int $post_id, mixed $before_value, mixed $after_value = null ): bool|WP_Error {
		switch ( $field ) {
			case 'assign':
			case 'terms':
				// Restore previous term list.
				if ( ! $post_id ) {
					return new WP_Error( 'missing_post_id', 'post_id required for taxonomy rollback.' );
				}
				$before_ids = IATO_MCP_Rollback_Conflict::json_array( $before_value );
				$after_ids  = IATO_MCP_Rollback_Conflict::json_array( $after_value );
				// The receipt stores IDs only. The taxonomy is that of any term in
				// the receipt, after IDs included: with an empty before list (a post
				// that had no tags, say) the old guess fell back to `category` and
				// emptied the post's categories instead of removing the tag.
				$taxonomy = IATO_MCP_Rollback_Conflict::taxonomy_of_ids( array_merge( $before_ids, $after_ids ) );
				if ( null === $taxonomy ) {
					if ( empty( $before_ids ) && empty( $after_ids ) ) {
						return true; // the receipt changed nothing
					}
					return new WP_Error( 'rollback_failed', 'Cannot determine the taxonomy: none of the terms in the receipt exist any more.' );
				}

				$result = wp_set_post_terms( $post_id, array_map( 'intval', $before_ids ), $taxonomy, false );
				return is_wp_error( $result ) ? $result : true;

			case 'create_term':
				// Reverse: delete the created term, named by the receipt's after value.
				$after_data = IATO_MCP_Rollback_Conflict::json_array( $after_value );
				if ( empty( $after_data['term_id'] ) || empty( $after_data['taxonomy'] ) ) {
					return new WP_Error( '__internal__', 'Cannot rollback create_term: missing term data in receipt.' );
				}
				$term = get_term( (int) $after_data['term_id'], (string) $after_data['taxonomy'] );
				if ( ! $term || is_wp_error( $term ) ) {
					return true; // already gone
				}
				$deleted = wp_delete_term( (int) $after_data['term_id'], (string) $after_data['taxonomy'] );
				return is_wp_error( $deleted ) ? $deleted : true;

			case 'update_term':
				// Restore term to previous values. preflight() refuses receipts
				// without a term ID (recorded before 1.13.0).
				$before_data = IATO_MCP_Rollback_Conflict::json_array( $before_value );
				if ( empty( $before_data['taxonomy'] ) || (int) ( $before_data['term_id'] ?? 0 ) <= 0 ) {
					return new WP_Error( 'invalid_before', 'Cannot restore term: invalid before_value.' );
				}
				$term_id  = (int) $before_data['term_id'];
				$taxonomy = (string) $before_data['taxonomy'];
				$update_args = [];
				if ( isset( $before_data['name'] ) ) $update_args['name'] = $before_data['name'];
				if ( isset( $before_data['slug'] ) ) $update_args['slug'] = $before_data['slug'];
				if ( isset( $before_data['description'] ) ) $update_args['description'] = $before_data['description'];
				if ( isset( $before_data['parent'] ) ) $update_args['parent'] = (int) $before_data['parent'];

				$result = wp_update_term( $term_id, $taxonomy, wp_slash( $update_args ) );
				return is_wp_error( $result ) ? $result : true;

			case 'delete_term':
				// Reverse: re-create the deleted term.
				$term_data = json_decode( $before_value, true );
				if ( ! is_array( $term_data ) || empty( $term_data['taxonomy'] ) || empty( $term_data['name'] ) ) {
					return new WP_Error( 'invalid_before', 'Cannot restore deleted term: invalid before_value.' );
				}
				$insert_args = [];
				if ( ! empty( $term_data['slug'] ) ) $insert_args['slug'] = $term_data['slug'];
				if ( ! empty( $term_data['description'] ) ) $insert_args['description'] = $term_data['description'];
				if ( isset( $term_data['parent'] ) ) $insert_args['parent'] = (int) $term_data['parent'];

				$result = wp_insert_term( wp_slash( $term_data['name'] ), $term_data['taxonomy'], wp_slash( $insert_args ) );
				return is_wp_error( $result ) ? $result : true;

			default:
				return new WP_Error( 'unsupported_field', "Rollback not supported for taxonomy field: {$field}" );
		}
	}

	/**
	 * Rollback redirect changes.
	 */
	private static function rollback_redirect( string $field, mixed $before_value ): bool|WP_Error {
		if ( 'rule' !== $field ) {
			return new WP_Error( 'unsupported_field', "Rollback not supported for redirect field: {$field}" );
		}

		// If before_value is null, the redirect was newly created — remove it.
		// If before_value exists, restore the previous redirect rule.
		// For the fallback handler, we work with the iato_mcp_redirects option.
		$handler = iato_mcp_detect_redirect_handler();

		if ( null === $before_value ) {
			// Need to find and remove the redirect that was created.
			// The after_value from the receipt has the from_url, but we only have before_value here.
			// The receipt's after_value should be looked up. For now, return a descriptive error.
			// In practice, the IATO platform would pass the from_url in the request.
			return new WP_Error( 'redirect_rollback_incomplete', 'Removing a created redirect requires the from_url. Check the change receipt after_value.' );
		}

		// Restore the previous redirect rule.
		$previous = json_decode( $before_value, true );
		if ( ! is_array( $previous ) || empty( $previous['from_url'] ) || empty( $previous['to_url'] ) ) {
			return new WP_Error( 'invalid_before', 'Cannot restore redirect: invalid before_value.' );
		}

		$result = iato_mcp_write_redirect(
			$previous['from_url'],
			$previous['to_url'],
			(int) ( $previous['type'] ?? 301 ),
			$handler
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Delete SEO title meta key (when before_value is null).
	 */
	private static function delete_seo_title_meta( int $post_id ): bool|WP_Error {
		$meta = IATO_MCP_SEO_Adapter::get_meta( $post_id );
		$plugin = $meta['plugin'];

		$key = match ( $plugin ) {
			'yoast'    => '_yoast_wpseo_title',
			'rankmath' => 'rank_math_title',
			'seopress' => '_seopress_titles_title',
			default    => null,
		};

		if ( $key ) {
			delete_post_meta( $post_id, $key );
		}

		return true;
	}

	/**
	 * Delete SEO description meta key (when before_value is null).
	 */
	private static function delete_seo_desc_meta( int $post_id ): bool|WP_Error {
		$meta = IATO_MCP_SEO_Adapter::get_meta( $post_id );
		$plugin = $meta['plugin'];

		$key = match ( $plugin ) {
			'yoast'    => '_yoast_wpseo_metadesc',
			'rankmath' => 'rank_math_description',
			'seopress' => '_seopress_titles_desc',
			default    => null,
		};

		if ( $key ) {
			delete_post_meta( $post_id, $key );
		}

		return true;
	}

	/**
	 * Compare two values for equivalence (handles null, strings, JSON).
	 */
	private static function values_match( mixed $request_val, mixed $stored_val ): bool {
		// Both null.
		if ( null === $request_val && null === $stored_val ) {
			return true;
		}

		// One null, other not.
		if ( null === $request_val || null === $stored_val ) {
			return false;
		}

		// String comparison (handles JSON strings).
		return (string) $request_val === (string) $stored_val;
	}

	/**
	 * Build a standard error response.
	 */
	private static function error_response( string $error, string $change_id, int $status, array $extra = [] ): WP_REST_Response {
		return new WP_REST_Response(
			array_merge( [
				'success'   => false,
				'error'     => $error,
				'change_id' => $change_id,
			], $extra ),
			$status
		);
	}
}
