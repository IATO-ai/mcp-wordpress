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
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$change_id = sanitize_text_field( $args['change_id'] ?? '' );
		if ( '' === $change_id || ! preg_match( '/^wr_[a-f0-9]{16}$/', $change_id ) ) {
			return new WP_Error( 'invalid_change_id', 'change_id must match ^wr_[a-f0-9]{16}$' );
		}

		// Elevated capability check for receipt types whose original write
		// required manage_options. Mirror the cap the original tool enforced
		// so a Subscriber with edit_posts cannot reverse an Admin's menu/redirect change.
		$receipt = IATO_MCP_Change_Receipt::get( $change_id );
		if ( ! $receipt ) {
			return new WP_Error( 'not_found', 'change_id not found.' );
		}

		$elevated_types = [ 'menu_item', 'redirect' ];
		if ( in_array( $receipt['target_type'], $elevated_types, true ) ) {
			$elevated = IATO_MCP_Auth::require_cap( 'manage_options' );
			if ( is_wp_error( $elevated ) ) {
				return $elevated;
			}
		}

		$result = IATO_MCP_Rollback::rollback_by_id( $change_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return IATO_MCP_Server::ok( $result );
	}
);
