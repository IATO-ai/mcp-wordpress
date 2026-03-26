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
		$target_type  = sanitize_text_field( $body['target_type'] ?? '' );
		$field        = sanitize_text_field( $body['field'] ?? '' );
		$before_value = $body['before_value'] ?? null;

		if ( empty( $change_id ) ) {
			return new WP_Error( 'missing_change_id', 'change_id is required.', [ 'status' => 400 ] );
		}

		// Look up receipt.
		$receipt = IATO_MCP_Change_Receipt::get( $change_id );
		if ( ! $receipt ) {
			return self::error_response( 'change_id not found', $change_id, 404 );
		}

		// Already rolled back?
		if ( ! empty( $receipt['rolled_back_at'] ) ) {
			return self::error_response( 'already rolled back', $change_id, 400, [
				'rolled_back_at' => $receipt['rolled_back_at'],
			] );
		}

		// Validate before_value matches stored receipt.
		$stored_before = $receipt['before_value'];
		if ( ! self::values_match( $before_value, $stored_before ) ) {
			return self::error_response( 'before_value mismatch — rollback rejected', $change_id, 400 );
		}

		// Dispatch rollback by target_type + field.
		$post_id     = $receipt['post_id'] ? (int) $receipt['post_id'] : null;
		$stored_type = $receipt['target_type'];
		$stored_field = $receipt['field'];

		$result = self::dispatch_rollback( $stored_type, $stored_field, $post_id, $stored_before );

		// Special case: create_term rollback needs after_value from the receipt.
		if ( is_wp_error( $result ) && '__internal__' === $result->get_error_message() ) {
			$after_data = json_decode( $receipt['after_value'] ?? '{}', true );
			if ( is_array( $after_data ) && ! empty( $after_data['term_id'] ) && ! empty( $after_data['taxonomy'] ) ) {
				$del = wp_delete_term( (int) $after_data['term_id'], $after_data['taxonomy'] );
				$result = is_wp_error( $del ) ? $del : true;
			} else {
				return self::error_response( 'Cannot rollback create_term: missing term data in receipt.', $change_id, 400 );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Mark as rolled back.
		IATO_MCP_Change_Receipt::mark_rolled_back( $change_id );

		$rolled_back_at = gmdate( 'c' );

		return new WP_REST_Response( [
			'success'        => true,
			'change_id'      => $change_id,
			'post_id'        => $post_id,
			'target_type'    => $stored_type,
			'field'          => $stored_field,
			'restored_value' => $stored_before,
			'rolled_back_at' => $rolled_back_at,
		], 200 );
	}

	/**
	 * Dispatch the rollback action based on target_type and field.
	 *
	 * @param string   $target_type
	 * @param string   $field
	 * @param int|null $post_id
	 * @param mixed    $before_value The value to restore.
	 * @return true|WP_Error
	 */
	private static function dispatch_rollback( string $target_type, string $field, ?int $post_id, mixed $before_value ): true|WP_Error {
		switch ( $target_type ) {
			case 'page':
				return self::rollback_page( $field, $post_id, $before_value );
			case 'image':
				return self::rollback_image( $field, $post_id, $before_value );
			case 'menu_item':
				return self::rollback_menu_item( $field, $post_id, $before_value );
			case 'taxonomy':
				return self::rollback_taxonomy( $field, $post_id, $before_value );
			case 'redirect':
				return self::rollback_redirect( $field, $before_value );
			default:
				return new WP_Error( 'unsupported_target_type', "Rollback not supported for target_type: {$target_type}" );
		}
	}

	/**
	 * Rollback page-level fields (SEO title, description, canonical, structured data).
	 */
	private static function rollback_page( string $field, ?int $post_id, mixed $before_value ): true|WP_Error {
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
				update_post_meta( $post_id, '_iato_mcp_structured_data', $before_value );
				return true;

			default:
				return new WP_Error( 'unsupported_field', "Rollback not supported for page field: {$field}" );
		}
	}

	/**
	 * Rollback image alt text.
	 */
	private static function rollback_image( string $field, ?int $post_id, mixed $before_value ): true|WP_Error {
		if ( 'alt_text' !== $field ) {
			return new WP_Error( 'unsupported_field', "Rollback not supported for image field: {$field}" );
		}
		if ( ! $post_id ) {
			return new WP_Error( 'post_not_found', 'Attachment ID is required.' );
		}

		if ( null === $before_value ) {
			delete_post_meta( $post_id, '_wp_attachment_image_alt' );
		} else {
			update_post_meta( $post_id, '_wp_attachment_image_alt', sanitize_text_field( $before_value ) );
		}

		return true;
	}

	/**
	 * Rollback menu item changes.
	 */
	private static function rollback_menu_item( string $field, ?int $post_id, mixed $before_value ): true|WP_Error {
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

				$result = wp_update_nav_menu_item( (int) $snapshot['menu_id'], 0, $item_data );
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

				$result = wp_update_nav_menu_item( $menu_id, $post_id, $item_data );
				return is_wp_error( $result ) ? $result : true;

			default:
				return new WP_Error( 'unsupported_field', "Rollback not supported for menu_item field: {$field}" );
		}
	}

	/**
	 * Rollback taxonomy changes.
	 */
	private static function rollback_taxonomy( string $field, ?int $post_id, mixed $before_value ): true|WP_Error {
		switch ( $field ) {
			case 'assign':
			case 'terms':
				// Restore previous term list.
				if ( ! $post_id ) {
					return new WP_Error( 'missing_post_id', 'post_id required for taxonomy rollback.' );
				}
				$before_ids = json_decode( $before_value, true );
				if ( ! is_array( $before_ids ) ) {
					$before_ids = [];
				}
				// Determine taxonomy from current assignments or default to category.
				$cats = wp_get_post_terms( $post_id, 'category', [ 'fields' => 'ids' ] );
				$tags = wp_get_post_terms( $post_id, 'post_tag', [ 'fields' => 'ids' ] );

				// Try to figure out taxonomy: if before_ids overlaps with categories or tags.
				$taxonomy = 'category';
				if ( ! empty( $before_ids ) ) {
					$term = get_term( $before_ids[0] );
					if ( $term && ! is_wp_error( $term ) ) {
						$taxonomy = $term->taxonomy;
					}
				}

				$result = wp_set_post_terms( $post_id, $before_ids, $taxonomy, false );
				return is_wp_error( $result ) ? $result : true;

			case 'create_term':
				// before_value is null for creates; we need after_value from the receipt
				// to know which term to delete. The handle() method passes stored before_value
				// but we can look up the receipt again since we have the change_id in context.
				// However, for simplicity, we handle this in the dispatch_rollback caller.
				// The receipt's after_value contains the created term JSON.
				return new WP_Error( 'create_term_needs_after', '__internal__' );

			case 'update_term':
				// Restore term to previous values.
				$before_data = json_decode( $before_value, true );
				if ( ! is_array( $before_data ) || empty( $before_data['taxonomy'] ) ) {
					return new WP_Error( 'invalid_before', 'Cannot restore term: invalid before_value.' );
				}
				$term_id  = $before_data['term_id'] ?? 0;
				$taxonomy = $before_data['taxonomy'];
				$update_args = [];
				if ( isset( $before_data['name'] ) ) $update_args['name'] = $before_data['name'];
				if ( isset( $before_data['slug'] ) ) $update_args['slug'] = $before_data['slug'];
				if ( isset( $before_data['description'] ) ) $update_args['description'] = $before_data['description'];
				if ( isset( $before_data['parent'] ) ) $update_args['parent'] = $before_data['parent'];

				if ( $term_id && ! empty( $update_args ) ) {
					$result = wp_update_term( $term_id, $taxonomy, $update_args );
					return is_wp_error( $result ) ? $result : true;
				}
				return true;

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

				$result = wp_insert_term( $term_data['name'], $term_data['taxonomy'], $insert_args );
				return is_wp_error( $result ) ? $result : true;

			default:
				return new WP_Error( 'unsupported_field', "Rollback not supported for taxonomy field: {$field}" );
		}
	}

	/**
	 * Rollback redirect changes.
	 */
	private static function rollback_redirect( string $field, mixed $before_value ): true|WP_Error {
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
	private static function delete_seo_title_meta( int $post_id ): true|WP_Error {
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
	private static function delete_seo_desc_meta( int $post_id ): true|WP_Error {
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
