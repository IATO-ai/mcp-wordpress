<?php
/**
 * WP Tools (1.21.0): get_page_script, set_page_script. Both ship switched off in the
 * Tools card, and set_page_script also needs the "Allow page scripts" setting. A script
 * runs only after an administrator approves that exact text in wp-admin. See
 * IATO_MCP_Page_Script.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_page_script',
	[
		'description' => 'Returns the status of one page\'s script: none, pending (waiting for an administrator to approve it in wp-admin; it does not run) or approved (it runs on that page), with its bytes and sha256, who approved it (their display name) and when, and whether page scripts run on this site now. Requires edit_post on the page.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Post or page ID (required).' ],
			],
			'required'   => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post = iato_mcp_page_asset_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$script   = IATO_MCP_Page_Script::stored( $post->ID );
		$approval = IATO_MCP_Page_Script::approval( $post->ID );
		$status   = IATO_MCP_Page_Script::status( $post->ID );
		$data     = [ 'id' => $post->ID, 'status' => $status, 'bytes' => strlen( $script ), 'sha256' => '' === $script ? null : hash( 'sha256', $script ), 'scripts_run_on_this_site' => IATO_MCP_Page_Script::active() ];
		if ( 'approved' === $status && $approval ) {
			$user                = get_userdata( (int) $approval['user_id'] );
			$data['approved_by'] = $user ? $user->display_name : null; // the public name, not the login
			$data['approved_at'] = gmdate( 'c', (int) $approval['at'] );
		}
		return IATO_MCP_Server::ok( $data );
	}
);

IATO_MCP_Server::register_tool(
	'set_page_script',
	[
		'description' => 'Stores inline JavaScript for one page, to run in its footer. It does NOT run until an administrator reads it and approves it in wp-admin (Settings > IATO MCP > Page assets), and any change to it waits for approval again: tell the user it needs their approval. Send only the JavaScript, with no <script> tags; <script, </script and <!-- are refused. At most 100 KB. An empty string removes it. Refused with page_scripts_disabled unless an administrator has turned on "Allow page scripts". Accepts dry_run. Returns status (pending), bytes, sha256, a change_receipt (rollback restores the previous script, which runs again only if that exact text is still the approved one) and a cache object. Requires an administrator who may post unfiltered HTML, with a WordPress user behind the credential.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer', 'description' => 'Post or page ID (required).' ],
				'script'  => [ 'type' => 'string', 'description' => 'The JavaScript, with no <script> tags (required). An empty string removes it.' ],
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Check without storing (default: false).' ],
			],
			'required'   => [ 'id', 'script' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post = iato_mcp_page_asset_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$may = IATO_MCP_Page_Script::may_write( $post->ID );
		if ( is_wp_error( $may ) ) {
			return 'page_scripts_disabled' === $may->get_error_code() ? IATO_MCP_Server::refusal( $may ) : $may;
		}
		$script  = $args['script'] ?? null;
		$refusal = IATO_MCP_Page_Script::refusal( $script );
		if ( null !== $refusal ) {
			return IATO_MCP_Server::refusal( $refusal );
		}
		$stored = IATO_MCP_Page_Script::stored( $post->ID );
		$data   = [ 'id' => $post->ID, 'bytes' => strlen( $script ), 'sha256' => '' === $script ? null : hash( 'sha256', $script ) ];
		if ( ! empty( $args['dry_run'] ) ) {
			return IATO_MCP_Server::ok( [ 'dry_run' => true, 'action' => $script === $stored ? 'no_change' : ( '' === $script ? 'would_remove' : 'would_store_pending' ), 'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED ] + $data );
		}
		if ( $script !== $stored ) {
			$before = IATO_MCP_Page_Script::before_value( $post->ID );
			IATO_MCP_Page_Script::write( $post->ID, $script );
			$receipt                = IATO_MCP_Change_Receipt::record( $post->ID, IATO_MCP_Page_Script::RECEIPT, IATO_MCP_Page_Script::FIELD, $before, IATO_MCP_Page_Script::after_value( $post->ID ) );
			$data['change_receipt'] = IATO_MCP_Change_Receipt::slim( $receipt );
		}
		$data['status'] = IATO_MCP_Page_Script::status( $post->ID );
		if ( 'pending' === $data['status'] ) {
			$data['note'] = 'Stored, and not running: an administrator has to read and approve it under Settings > IATO MCP > Page assets in wp-admin. Tell the user.';
		}
		return IATO_MCP_Server::ok( $data );
	}
);
