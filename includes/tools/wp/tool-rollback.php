<?php
/**
 * WP Tool: rollback
 *
 * Reverses a previous write using the change_id returned in a prior tool
 * response's change_receipt. Wraps IATO_MCP_Rollback::rollback_by_id, which
 * handles the receipt lookup, idempotency check, dispatch, and mark-rolled-back.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'rollback',
	[
		'description' => 'Reverse a previous write using its change_id. Pass the change_id from a prior tool response\'s change_receipt. Returns the restored value.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'change_id' => [
					'type'        => 'string',
					'description' => 'The wr_ prefixed change_id from a prior tool response.',
					'pattern'     => '^wr_[a-f0-9]{16}$',
				],
			],
			'required' => [ 'change_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		// Auth baseline — must be authenticated to even look up a receipt.
		// edit_posts is the minimum any rollback could require (post / page /
		// image / attachment / post_meta / elementor_widget all use it; menus
		// and redirects raise the bar via the cap map below).
		$auth_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $auth_check ) ) {
			return $auth_check;
		}

		$change_id = sanitize_text_field( $args['change_id'] ?? '' );
		if ( '' === $change_id || ! preg_match( '/^wr_[a-f0-9]{16}$/', $change_id ) ) {
			return new WP_Error( 'invalid_change_id', 'change_id must match ^wr_[a-f0-9]{16}$' );
		}

		$receipt = IATO_MCP_Change_Receipt::get( $change_id );
		if ( ! $receipt ) {
			return new WP_Error( 'not_found', 'change_id not found.' );
		}

		// Per-receipt-type cap enforcement. The cap map lives in
		// IATO_MCP_Change_Receipt::cap_required_for so a developer adding a
		// new target_type sees the cap requirement at the receipt-type
		// declaration site. Unknown types fail closed at manage_options.
		//
		// v1.10.0 replaced the prior hand-maintained `$elevated_types` switch
		// (only menu_item + redirect were elevated). New shape catches drift:
		// any future receipt type without an explicit cap entry rolls back
		// only for admins until the entry is added, instead of silently
		// inheriting edit_posts.
		$required_cap = IATO_MCP_Change_Receipt::cap_required_for( $receipt );
		$type_check   = IATO_MCP_Auth::require_cap( $required_cap );
		if ( is_wp_error( $type_check ) ) {
			return $type_check;
		}

		$result = IATO_MCP_Rollback::rollback_by_id( $change_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return IATO_MCP_Server::ok( $result );
	}
);
