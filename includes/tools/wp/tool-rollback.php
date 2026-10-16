<?php
/**
 * WP Tool: rollback
 *
 * Reverses a previous write using the change_id returned in a prior tool
 * response's change_receipt. Wraps IATO_MCP_Rollback::rollback_by_id, which
 * handles the receipt lookup, idempotency check, conflict check, dispatch,
 * and mark-rolled-back. Since 1.13.0 only the user who made the change or an
 * administrator may roll it back, a change that something else has since
 * overwritten is refused unless `force` is passed, and `dry_run` reports
 * what would happen.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'rollback',
	[
		'description' => 'Reverse a previous write using its change_id. Pass the change_id from a prior tool response\'s change_receipt. Returns the restored value, who made the change and who rolled it back. Only the user who made the change or an administrator can roll it back. If the value has changed since (a later edit, another rollback), the rollback is refused with rollback_conflict and the current and expected values; roll back the later change first, or pass force: true. Use dry_run: true to check first.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'change_id' => [
					'type'        => 'string',
					'description' => 'The wr_ prefixed change_id from a prior tool response.',
					'pattern'     => '^wr_[a-f0-9]{16}$',
				],
				'force'     => [
					'type'        => 'boolean',
					'description' => 'Roll back even though the value has changed since this change was made, overwriting the newer value (default: false).',
				],
				'dry_run'   => [
					'type'        => 'boolean',
					'description' => 'Report what would be restored and whether the value has changed since, without writing (default: false).',
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
		//
		// Object-level since the rollback-route fix: edit_post / delete_post /
		// edit_term / delete_term on the specific object where it still exists,
		// the type-level cap otherwise. Shared with the REST route via
		// IATO_MCP_Rollback::check_permission().
		$perm = IATO_MCP_Rollback::check_permission( $receipt );
		if ( is_wp_error( $perm ) ) {
			return iato_mcp_rollback_error( $perm );
		}

		$result = IATO_MCP_Rollback::rollback_by_id( $change_id, [
			'force'   => ! empty( $args['force'] ),
			'dry_run' => ! empty( $args['dry_run'] ),
		] );
		if ( is_wp_error( $result ) ) {
			return iato_mcp_rollback_error( $result );
		}

		return IATO_MCP_Server::ok( $result );
	}
);

/**
 * MCP clients only see a tool error's text. Refusals that carry details the
 * client needs to act on (the conflicting values, who made the change) are
 * returned as a tool error whose text is JSON: { error, message, ... }.
 * Other errors pass through unchanged.
 */
if ( ! function_exists( 'iato_mcp_rollback_error' ) ) :
function iato_mcp_rollback_error( WP_Error $error ): array|WP_Error {
	$detailed = [ 'rollback_conflict', 'rollback_actor_mismatch', 'rollback_unavailable', 'already_rolled_back' ];
	if ( ! in_array( $error->get_error_code(), $detailed, true ) ) {
		// 1.14.0: refusals from rolling back an atomic element change (the page
		// check, Elementor's editability, an open editor) carry details too.
		return class_exists( 'IATO_MCP_Elementor_Adapter' ) ? IATO_MCP_Elementor_Adapter::tool_error( $error ) : $error;
	}
	$data = $error->get_error_data();
	$data = is_array( $data ) ? $data : [];
	unset( $data['status'] );
	return [
		'isError' => true,
		'content' => [ [
			'type' => 'text',
			'text' => (string) wp_json_encode( [ 'error' => $error->get_error_code(), 'message' => $error->get_error_message() ] + $data ),
		] ],
	];
}
endif;
