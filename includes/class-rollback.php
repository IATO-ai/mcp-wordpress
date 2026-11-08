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

		IATO_MCP_Cache::begin();
		$level = ob_get_level();
		try {
			$result = self::execute( $receipt, [ 'force' => $force, 'dry_run' => $dry_run ] );
		} finally {
			IATO_MCP_Server::close_buffers( $level );
		}
		$cache  = IATO_MCP_Cache::flush();
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

		if ( null !== $cache ) {
			$result['cache'] = $cache;
		}

		return new WP_REST_Response( IATO_MCP_Element_Cache_Guard::scrub( $result ), 200 );
	}

	/** Set by a restore that did what it could and has something to add to the response (1.17.0). */
	private static ?string $restore_warning = null;

	/** For restores in other classes (1.20.0): a rollback that happened, with something to add. */
	public static function warn( string $warning ): void {
		self::$restore_warning = null === self::$restore_warning ? $warning : self::$restore_warning . ' ' . $warning;
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
		$is_tree   = 'elementor_widget' === $receipt['target_type'];
		$is_atomic = IATO_MCP_Elementor_Atomic_Writer::RECEIPT_TYPE === $receipt['target_type'];
		$restore   = [ 'restored_value' => $before ];
		if ( IATO_MCP_Custom_CSS::RECEIPT === $receipt['target_type'] ) {
			$restore = [ 'restored_value' => IATO_MCP_Custom_CSS::restore_view( $before ) ];
		}
		if ( $is_atomic ) {
			// 1.14.0: one atomic element's settings, shown in plain form.
			$restore = [ 'restored_value' => IATO_MCP_Elementor_Atomic_Writer::restore_view( $receipt ) ];
		}
		if ( $is_tree ) {
			$envelope = self::no_elementor_from( $before );
			$old_tree = self::envelope_tree( $envelope, (string) $receipt['field'] );
			if ( null !== $old_tree ) {
				// 1.16.0: all three come back: the tree, the flag, the content.
				$restore = [ 'restored_revision' => IATO_MCP_Elementor_Adapter::compute_revision( $old_tree ), 'restored' => '' === (string) ( $envelope['edit_mode'] ?? '' ) ? 'content_and_elementor_off' : 'content' ];
			} elseif ( null === $before || null !== $envelope ) {
				$restore = [ 'restored_revision' => null, 'restored' => 'no_elementor_data' ];
			} else {
				$restore = [ 'restored_revision' => IATO_MCP_Elementor_Adapter::compute_revision( (string) $before ) ];
			}
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

		// 1.14.0: an atomic element's dry run runs the real rollback's gates and
		// checks (bound user, open editor, Elementor's validation, the page
		// check), so it refuses what the real call would refuse.
		if ( $dry_run && $is_atomic && ( 'conflict' !== $check['status'] || $force ) ) {
			$gates = IATO_MCP_Elementor_Atomic_Writer::rollback( $receipt, true );
			if ( is_wp_error( $gates ) ) {
				return $gates;
			}
		}

		if ( $dry_run ) {
			return [ 'dry_run' => true, 'would_roll_back' => 'conflict' !== $check['status'] || $force ]
				+ $base
				+ ( $is_tree ? [ 'would_restore_revision' => $restore['restored_revision'] ] + ( isset( $restore['restored'] ) ? [ 'would_restore' => $restore['restored'] ] : [] ) : [ 'would_restore' => $is_atomic || IATO_MCP_Custom_CSS::RECEIPT === $receipt['target_type'] ? $restore['restored_value'] : $before ] )
				+ ( $is_tree ? self::content_refresh_preview( $post_id, $before ) : [] )
				+ [ 'conflict_check' => $report ];
		}

		if ( 'conflict' === $check['status'] && ! $force ) {
			return new WP_Error(
				'rollback_conflict',
				sprintf( 'Rollback refused: %s. Rolling back would overwrite that newer change. Roll back the later change first, or pass force: true to roll back anyway.', $check['reason'] ),
				[ 'status' => 409, 'conflict_check' => $report ] + $base
			);
		}

		// 1.15.0: before the restore, while a post it deletes can still be looked
		// at, and whatever the restore returns: a failed one may have written.
		IATO_MCP_Cache::note_rollback( $receipt );

		self::$restore_warning = null;
		$result = self::dispatch_rollback( $receipt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// 1.16.0: a tree put back directly leaves post_content holding the text of the
		// tree it replaced. An envelope restored the content itself.
		if ( $is_tree && null !== $post_id && is_string( $before ) && null === self::no_elementor_from( $before ) ) {
			$restore += IATO_MCP_Elementor_Adapter::refresh_post_content( $post_id );
		}

		$by = IATO_MCP_Change_Receipt::current_actor();
		$marked = IATO_MCP_Change_Receipt::mark_rolled_back( $change_id, $by );

		return [ 'success' => true ]
			+ $base
			+ $restore
			+ [
				'rolled_back_at' => gmdate( 'c' ),
				'rolled_back_by' => IATO_MCP_Change_Receipt::describe_actor( $by ),
				'conflict_check' => $report,
			]
			+ ( $marked ? [] : [ 'warning' => 'The change was rolled back, but its receipt could not be marked, so this rollback could run again. The plugin\'s Diagnostics page has details.' ] )
			+ ( null === self::$restore_warning ? [] : [ 'warning' => self::$restore_warning ] );
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
					IATO_MCP_Custom_CSS::RECEIPT === $type
						? 'The copy of the CSS this change replaced was removed on %s, when the receipt became older than the retention period. Restore it from the revisions of Additional CSS in the Customizer.'
						: 'The copy of the page this change replaced was removed on %s, when the receipt became older than the retention period. Restore the page in Elementor under History > Revisions.',
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
		if ( IATO_MCP_Elementor_Atomic_Writer::RECEIPT_TYPE === $type ) {
			$why = IATO_MCP_Elementor_Atomic_Writer::rollback_unavailable( $receipt );
			if ( null !== $why ) {
				return $why;
			}
		}
		if ( 'post' === $type && 'date' === $field && '' === (string) ( IATO_MCP_Rollback_Conflict::json_array( $before )['date'] ?? '' ) ) {
			return new WP_Error( 'rollback_unavailable', 'This receipt holds no date to restore.', [ 'status' => 409 ] );
		}
		if ( 'post' === $type && IATO_MCP_URL_Move::FIELD === $field ) {
			$why = IATO_MCP_URL_Move::rollback_unavailable( $receipt );
			if ( null !== $why ) {
				return $why;
			}
		}
		if ( IATO_MCP_Permalinks::RECEIPT === $type ) {
			$why = IATO_MCP_Permalinks::rollback_unavailable( $receipt );
			if ( null !== $why ) {
				return $why;
			}
		}
		if ( 'post' === $type && 'author' === $field && (int) $before > 0 && ! get_userdata( (int) $before ) ) {
			return new WP_Error(
				'rollback_unavailable',
				sprintf( 'The previous author, user %d, no longer exists. Choose another author with update_post.', (int) $before ),
				[ 'status' => 409 ]
			);
		}
		if ( IATO_MCP_User_Profile::RECEIPT === $type ) {
			$why = IATO_MCP_User_Profile::rollback_unavailable( $before );
			if ( null !== $why ) {
				return $why;
			}
		}
		if ( IATO_MCP_Custom_CSS::RECEIPT === $type ) {
			$why = IATO_MCP_Custom_CSS::rollback_unavailable( $before );
			if ( null !== $why ) {
				return $why;
			}
		}
		if ( 'site_setting' === $type ) {
			$why = self::site_setting_unavailable( $field, $before );
			if ( null !== $why ) {
				return $why;
			}
		}
		if ( 'elementor_template' === $type && 'conditions' === $field ) {
			$why = self::template_conditions_unavailable( (int) ( $receipt['post_id'] ?? 0 ) );
			if ( null !== $why ) {
				return $why;
			}
		}
		if ( 'menu' === $type || 'menu_location' === $type ) {
			$why = self::menu_unavailable( $type, $receipt );
			if ( null !== $why ) {
				return $why;
			}
		}
		if ( 'post_meta' === $type && ! empty( $receipt['post_id'] ) ) {
			$key_check = IATO_MCP_Meta_Policy::check_stored_key( (int) $receipt['post_id'], $field );
			if ( is_wp_error( $key_check ) ) {
				return new WP_Error( 'rollback_unavailable', $key_check->get_error_message(), [ 'status' => 409 ] );
			}
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
				if ( IATO_MCP_URL_Move::FIELD === $field ) {
					return IATO_MCP_URL_Move::rollback( $receipt );
				}
				return self::rollback_post( $field, $post_id, $before_value );
			case IATO_MCP_Permalinks::RECEIPT:
				return IATO_MCP_Permalinks::rollback( $receipt );
			case 'image':
				return self::rollback_image( $field, $post_id, $before_value );
			case 'menu_item':
				return self::rollback_menu_item( $field, $post_id, $before_value );
			case 'elementor_template':
				return self::rollback_elementor_template( $field, $post_id, $before_value );
			case 'menu':
				return self::rollback_menu( $field, $before_value, $receipt['after_value'] ?? null );
			case 'menu_location':
				return self::rollback_menu_location( $field, $before_value );
			case 'taxonomy':
				return self::rollback_taxonomy( $field, $post_id, $before_value, $receipt['after_value'] ?? null );
			case 'redirect':
				return self::rollback_redirect( $field, $before_value );
			case 'site_setting':
				return self::rollback_site_setting( $field, $before_value );
			case IATO_MCP_Custom_CSS::RECEIPT:
				return IATO_MCP_Custom_CSS::restore( $before_value );
			case IATO_MCP_User_Profile::RECEIPT:
				return IATO_MCP_User_Profile::restore( $before_value );
			case 'post_meta':
				return self::rollback_post_meta( $field, $post_id, $before_value );
			case 'attachment':
				return self::rollback_attachment( $field, $post_id, $before_value );
			case 'elementor_widget':
				return self::rollback_elementor( $post_id, $before_value, $field );
			case IATO_MCP_Elementor_Atomic_Writer::RECEIPT_TYPE:
				return IATO_MCP_Elementor_Atomic_Writer::rollback( $receipt );
			default:
				return new WP_Error( 'unsupported_target_type', "Rollback not supported for target_type: {$target_type}" );
		}
	}

	/**
	 * Why a `site_setting` receipt cannot be restored, or null. The field names an
	 * option to write, so it must be one update_site_settings writes; a page the
	 * site no longer has published cannot go back to being the front page.
	 */
	private static function site_setting_unavailable( string $field, mixed $before ): ?WP_Error {
		if ( ! in_array( $field, IATO_MCP_Rollback_Conflict::SITE_OPTIONS, true ) && ! in_array( $field, IATO_MCP_Rollback_Conflict::SITE_THEME_MODS, true ) ) {
			return new WP_Error( 'rollback_unavailable', "This receipt names an option update_site_settings does not write: {$field}.", [ 'status' => 409 ] );
		}
		if ( 'site_icon' === $field || 'custom_logo' === $field ) {
			$why = self::site_image_unavailable( $field, $before );
			if ( null !== $why ) {
				return $why;
			}
		}
		if ( in_array( $field, [ 'page_on_front', 'page_for_posts' ], true ) && (int) $before > 0 ) {
			if ( ! iato_mcp_is_published_page( (int) $before ) ) {
				return new WP_Error(
					'rollback_unavailable',
					sprintf( 'Page %d is no longer a published page, so it cannot be restored as %s. Publish it first, or choose another page with update_site_settings.', (int) $before, $field ),
					[ 'status' => 409 ]
				);
			}
			$other = 'page_on_front' === $field ? 'page_for_posts' : 'page_on_front';
			if ( (int) get_option( $other, 0 ) === (int) $before ) {
				return new WP_Error(
					'rollback_unavailable',
					sprintf( 'Page %1$d is now %2$s, and one page cannot be both the front page and the posts page. Change %2$s first, with update_site_settings or by rolling that change back.', (int) $before, $other ),
					[ 'status' => 409 ]
				);
			}
		}
		return null;
	}

	/**
	 * Why a site icon or logo cannot go back, or null (1.18.0). Neither case can be
	 * forced. A logo belongs to one theme: WordPress keeps theme mods per theme.
	 */
	private static function site_image_unavailable( string $field, mixed $before ): ?WP_Error {
		$previous = (int) $before;
		if ( 'custom_logo' === $field ) {
			$recorded = IATO_MCP_Rollback_Conflict::json_array( $before );
			$theme    = IATO_MCP_Site_Image::get()->theme();
			if ( ( $recorded['theme'] ?? null ) !== $theme ) {
				return new WP_Error(
					'rollback_unavailable',
					sprintf( 'This logo change was made on the theme %s and %s is active now. WordPress keeps a logo per theme, so it cannot be rolled back. Set the logo with update_site_settings.', (string) ( $recorded['theme'] ?? 'unknown' ), $theme ),
					[ 'status' => 409 ]
				);
			}
			$previous = (int) ( $recorded['id'] ?? 0 );
		}
		if ( $previous > 0 ) {
			$refusal = IATO_MCP_Site_Image::attachment_refusal( $field, $previous );
			if ( null !== $refusal ) {
				return new WP_Error(
					'rollback_unavailable',
					sprintf( 'Attachment %1$d can no longer be restored as %2$s: %3$s Choose another image with update_site_settings.', $previous, $field, $refusal->get_error_message() ),
					[ 'status' => 409 ]
				);
			}
		}
		return null;
	}

	/** Why a template's conditions cannot be put back, or null (1.17.0). Neither case can be forced. */
	private static function template_conditions_unavailable( int $template_id ): ?WP_Error {
		$missing = IATO_MCP_Theme_Builder::get()->missing_pro();
		if ( null !== $missing ) {
			return new WP_Error( 'rollback_unavailable', $missing . ' So the conditions cannot be put back now.', [ 'status' => 409 ] );
		}
		$post = $template_id > 0 ? get_post( $template_id ) : null;
		if ( ! $post || 'trash' === ( $post->post_status ?? '' ) ) {
			return new WP_Error( 'rollback_unavailable', 'That template no longer exists or is in the trash, so its conditions cannot be put back.', [ 'status' => 409 ] );
		}
		return null;
	}

	/**
	 * Undo create_elementor_template or set_elementor_template_conditions (1.17.0). A
	 * created template goes in the trash, which takes it out of Pro's cache, and the cache
	 * is rebuilt as well in case something else had changed it. Conditions go back through
	 * Pro, which rebuilds it.
	 */
	private static function rollback_elementor_template( string $field, ?int $post_id, mixed $before_value ): bool|WP_Error {
		if ( ! $post_id ) {
			return new WP_Error( 'missing_post_id', 'Template ID required for rollback.' );
		}
		$builder = IATO_MCP_Theme_Builder::get();
		if ( 'conditions' === $field ) {
			$saved = $builder->save_conditions( $post_id, array_values( array_map( 'strval', IATO_MCP_Rollback_Conflict::json_array( $before_value ) ) ) );
			if ( is_wp_error( $saved ) && 'cache_not_regenerated' === $saved->get_error_code() ) {
				// They are back, so the receipt must be marked. What is left is the cache.
				self::$restore_warning = $saved->get_error_message() . ' Send the same conditions to set_elementor_template_conditions to rebuild it.';
				return true;
			}
			return $saved;
		}
		if ( 'create' !== $field ) {
			return new WP_Error( 'unsupported_field', "Rollback not supported for elementor_template field: {$field}" );
		}
		$post = get_post( $post_id );
		if ( $post && 'trash' !== ( $post->post_status ?? '' ) ) {
			$trashed = IATO_MCP_Auth::keeping_post_columns( $post_id, static fn() => wp_trash_post( $post_id ) );
			if ( ! $trashed ) {
				return new WP_Error( 'trash_failed', 'Failed to trash the template.' );
			}
		}
		// Without Pro there is no cache to rebuild, and the trash has done the rest.
		if ( null === $builder->missing_pro() ) {
			$regenerated = $builder->regenerate();
			if ( is_wp_error( $regenerated ) ) {
				self::$restore_warning = 'The template was removed, but: ' . $regenerated->get_error_message();
			}
		}
		return true;
	}

	/** Put a menu in a theme location, 0 clearing it (1.17.0). The other locations stay. */
	public static function set_menu_location( string $location, int $menu_id ): void {
		$locations              = get_nav_menu_locations();
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
	}

	/**
	 * Why a menu receipt cannot be restored, or null (1.17.0). Locations are stored
	 * per theme, so after a theme switch this site has no record of what the receipt
	 * changed: writing it would set the new theme's location to the old theme's menu.
	 * Neither case can be forced.
	 */
	private static function menu_unavailable( string $type, array $receipt ): ?WP_Error {
		$after    = IATO_MCP_Rollback_Conflict::json_array( $receipt['after_value'] ?? null );
		$location = (string) ( $after['location'] ?? '' );
		if ( '' === $location ) {
			return null; // a menu made without a location: nothing here belongs to a theme
		}
		$theme = (string) ( $after['theme'] ?? '' );
		if ( get_stylesheet() !== $theme ) {
			return new WP_Error(
				'rollback_unavailable',
				sprintf(
					'This change set the menu location %1$s on the theme %2$s, and the site now uses %3$s. WordPress keeps menu locations per theme, so it cannot be undone from here. Use assign_menu_location, or switch back to %2$s first.',
					$location,
					$theme,
					get_stylesheet()
				),
				[ 'status' => 409, 'theme' => $theme, 'current_theme' => get_stylesheet() ]
			);
		}
		if ( 'menu_location' === $type ) {
			$before   = IATO_MCP_Rollback_Conflict::json_array( $receipt['before_value'] ?? null );
			$previous = (int) ( $before['menu_id'] ?? 0 );
			if ( $previous > 0 && null === IATO_MCP_Rollback_Conflict::menu_object( $previous ) ) {
				return new WP_Error(
					'rollback_unavailable',
					sprintf( 'Menu %1$d, which held the location %2$s before this change, no longer exists. Choose another with assign_menu_location.', $previous, $location ),
					[ 'status' => 409 ]
				);
			}
		}
		return null;
	}

	/**
	 * Undo create_menu (1.17.0): delete the menu, then give the location back. Only if
	 * this menu still holds it, because one given to another menu since is not this
	 * change's to undo. wp_delete_nav_menu() empties a location holding the menu, so
	 * the old menu goes back after it.
	 */
	private static function rollback_menu( string $field, mixed $before_value, mixed $after_value ): bool|WP_Error {
		if ( 'create' !== $field ) {
			return new WP_Error( 'unsupported_field', "Rollback not supported for menu field: {$field}" );
		}
		$after   = IATO_MCP_Rollback_Conflict::json_array( $after_value );
		$menu_id = (int) ( $after['menu_id'] ?? 0 );
		if ( $menu_id <= 0 ) {
			return new WP_Error( 'invalid_receipt', 'Cannot roll back create_menu: the receipt does not name the menu.' );
		}
		if ( null === IATO_MCP_Rollback_Conflict::menu_object( $menu_id ) ) {
			return true; // already gone
		}

		$location = (string) ( $after['location'] ?? '' );
		$holds    = '' !== $location && IATO_MCP_Rollback_Conflict::menu_in_location( $location ) === $menu_id;

		$deleted = wp_delete_nav_menu( $menu_id );
		if ( ! $deleted || is_wp_error( $deleted ) ) {
			return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'delete_failed', 'Failed to delete the menu.' );
		}

		if ( $holds ) {
			$previous = (int) ( IATO_MCP_Rollback_Conflict::json_array( $before_value )['menu_id'] ?? 0 );
			self::set_menu_location( $location, null !== IATO_MCP_Rollback_Conflict::menu_object( $previous ) ? $previous : 0 );
		}
		return true;
	}

	/** Undo assign_menu_location (1.17.0). preflight() has checked the theme and the menu. */
	private static function rollback_menu_location( string $location, mixed $before_value ): bool|WP_Error {
		self::set_menu_location( $location, (int) ( IATO_MCP_Rollback_Conflict::json_array( $before_value )['menu_id'] ?? 0 ) );
		return true;
	}

	/**
	 * Rollback update_site_settings. A null before value means the option was
	 * empty ('' is recorded as null), so it goes back to empty, never deleted:
	 * '0' is a real value of the page options.
	 */
	private static function rollback_site_setting( string $field, mixed $before_value ): bool|WP_Error {
		if ( 'custom_logo' === $field ) {
			$id = (int) ( IATO_MCP_Rollback_Conflict::json_array( $before_value )['id'] ?? 0 );
			IATO_MCP_Site_Image::get()->set_logo( $id );
			IATO_MCP_Site_Image::sync_kit( 'custom_logo', $id );
			return true;
		}
		IATO_MCP_Elementor_Adapter::update_site_option( $field, null === $before_value ? '' : (string) $before_value );
		if ( 'site_icon' === $field ) {
			IATO_MCP_Site_Image::sync_kit( 'site_icon', (int) $before_value );
		}
		if ( 'page_on_front' === $field ) {
			flush_rewrite_rules( false ); // as update_site_settings does
		}
		return true;
	}

	/**
	 * Before value of an Elementor receipt for a post that had no Elementor
	 * data (update_elementor_data on a plain post): Elementor's save replaced
	 * post_content with its rendering of the tree, so the receipt keeps the
	 * original content and builder flag. A JSON object, never mistaken for a
	 * tree (Elementor data is a JSON array).
	 */
	/** What a real rollback's content refresh would do, for a dry run. */
	private static function content_refresh_preview( ?int $post_id, mixed $before ): array {
		if ( null === $post_id || ! is_string( $before ) || null !== self::no_elementor_from( $before ) ) {
			return [];
		}
		$elements = json_decode( $before, true );
		return is_array( $elements ) ? IATO_MCP_Elementor_Adapter::refresh_post_content( $post_id, IATO_MCP_Elementor_Adapter::force_arrays( $elements ) ) : [];
	}

	public static function no_elementor_envelope( string $post_content, string $edit_mode, ?string $elementor_data = null ): string {
		return (string) wp_json_encode(
			[ 'iato_no_elementor_data' => true, 'post_content' => $post_content, 'edit_mode' => $edit_mode ]
			+ ( null === $elementor_data ? [] : [ 'elementor_data' => $elementor_data ] )
		);
	}

	/**
	 * An envelope gains a hash of the content the write left, for the conflict check
	 * (1.16.0). Anything else is returned as it is: a stored tree is a JSON array.
	 */
	public static function envelope_with_content_after( string $before, string $content ): string {
		$envelope = self::no_elementor_from( $before );
		if ( null === $envelope ) {
			return $before;
		}
		$envelope['content_after_sha256'] = hash( 'sha256', $content );
		return (string) wp_json_encode( $envelope );
	}

	/**
	 * The receipt field of a write that kept an envelope (1.16.0). The envelope is
	 * JSON, and so is a stored tree: only this column, which no request can set, says
	 * the plugin wrote it. Text from any other receipt that merely looks like an
	 * envelope came out of the post's own data.
	 */
	public const ACTIVATE_FIELD = 'elementor:document:activate';

	/**
	 * What a whole-tree write's receipt keeps, and its field. Elementor's save replaces
	 * post_content, which visitors see whenever the post has no elements, with the flag
	 * on too (Elementor sets it when its editor opens, saved or not): those writes, and
	 * any that turn Elementor on, keep an envelope.
	 *
	 * @return array{0:string,1:string} The before value and the receipt field.
	 */
	public static function tree_receipt( int $post_id, string $stored_raw, bool $turning_on, string $field ): array {
		// Data Elementor cannot read shows no elements either, so visitors see the content.
		$elements = '' === $stored_raw ? null : json_decode( $stored_raw, true );
		if ( ! $turning_on && is_array( $elements ) && [] !== $elements ) {
			return [ $stored_raw, $field ];
		}
		return [
			self::no_elementor_envelope(
				(string) get_post_field( 'post_content', $post_id ),
				(string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
				'' !== $stored_raw ? $stored_raw : null
			),
			self::ACTIVATE_FIELD,
		];
	}

	/** The stored tree an envelope holds, from a receipt whose field vouches for it. */
	private static function envelope_tree( ?array $envelope, string $field ): ?string {
		return self::ACTIVATE_FIELD === $field && isset( $envelope['elementor_data'] ) ? (string) $envelope['elementor_data'] : null;
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
	 * no Elementor data, or had Elementor turned off, the before value is an
	 * envelope: the data is removed or put back as it was (1.16.0), the builder flag
	 * and the original post_content are put back.
	 */
	private static function rollback_elementor( ?int $post_id, mixed $before_value, string $field = '' ): bool|WP_Error {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		$plain = self::no_elementor_from( $before_value );
		if ( null === $plain && null !== $before_value ) {
			return self::rollback_post_meta( '_elementor_data', $post_id, $before_value );
		}

		$result = self::rollback_post_meta( '_elementor_data', $post_id, self::envelope_tree( $plain, $field ) );
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
			$content = (string) $plain['post_content'];
			// A receipt from before 1.16.0, or text that only looks like an envelope:
			// nothing shows the plugin wrote it, so it is no reason to skip the filter.
			if ( self::ACTIVATE_FIELD !== $field && ! IATO_MCP_Auth::holds_unfiltered_html() ) {
				$content = IATO_MCP_Elementor_Sanitizer::clean_markup( $content );
			}
			return self::restore_post_content( $post_id, $content );
		}
		return true;
	}

	/**
	 * Put a post's content back exactly as it was stored (a value read from the
	 * database when the receipt was recorded), past the kses filters of the 1.13.0
	 * lock, which would strip what a person with unfiltered_html had stored.
	 */
	private static function restore_post_content( int $post_id, string $content ): bool|WP_Error {
		$result = IATO_MCP_Auth::write_unlocked( fn() => IATO_MCP_Auth::update_post_fields( [ 'ID' => $post_id, 'post_content' => $content ], true ) );
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
			wp_cache_delete( $post_id, 'posts' );
			IATO_MCP_Elementor_Adapter::clear_post_css( $post_id );
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
		$used_as = IATO_MCP_Site_Image::used_as( $post_id );
		$deleted = wp_delete_attachment( $post_id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', 'Failed to delete attachment.' );
		}
		IATO_MCP_Site_Image::forget( $post_id, $used_as );
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
			// Trashing saves the post, and the filters would take from its content.
			$trashed = IATO_MCP_Auth::keeping_post_columns( $post_id, fn() => wp_trash_post( $post_id ) );
			if ( ! $trashed ) {
				return new WP_Error( 'trash_failed', 'Failed to trash post.' );
			}
			return true;
		}

		if ( 'date' === $field ) {
			$date   = IATO_MCP_Rollback_Conflict::json_array( $before_value );
			$result = IATO_MCP_Auth::update_post_fields(
				[
					'ID'            => $post_id,
					'post_date'     => (string) ( $date['date'] ?? '' ),
					'post_date_gmt' => (string) ( $date['date_gmt'] ?? '' ),
					'post_status'   => (string) ( $date['status'] ?? $post->post_status ),
					'edit_date'     => true,
				],
				true
			);
			return is_wp_error( $result ) ? $result : true;
		}

		$post_key = match ( $field ) {
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
			'slug'    => 'post_name',
			'author'  => 'post_author',
			default   => null,
		};

		if ( null === $post_key ) {
			return new WP_Error( 'unsupported_field', "Rollback not supported for post field: {$field}" );
		}

		// The value goes back as the database held it (1.16.0), and the columns this
		// receipt is not about stay as they are.
		$result = IATO_MCP_Auth::update_post_fields( [ 'ID' => $post_id, $post_key => $before_value ], true );

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

		// A JSON value sent decoded (an object or array) is compared with the
		// stored JSON text in canonical form (1.14.0; it used to be cast to the
		// string "Array" and never matched).
		if ( is_array( $request_val ) || is_object( $request_val ) || is_array( $stored_val ) ) {
			return IATO_MCP_Rollback_Conflict::canonical( $request_val ) === IATO_MCP_Rollback_Conflict::canonical( $stored_val );
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
			], IATO_MCP_Element_Cache_Guard::scrub( $extra ) ),
			$status
		);
	}
}
