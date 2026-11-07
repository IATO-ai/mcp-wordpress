<?php
/**
 * WP Tool: update_user_profile
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'update_user_profile',
	[
		'description' => 'Changes the public fields of a user\'s profile: display_name, nickname, first_name, last_name, description and url. Changes the authenticated user unless user_id is given, which needs edit_users. Email, password, roles and capabilities cannot be changed here, and any other key is refused. display_name must be one of the names WordPress offers for this user, which come from their nickname, login, and first and last names, or the call must also set nickname to the same value. Accepts dry_run. Returns the before and after of each field, a change_receipt (roll back with the rollback tool) and a cache object: the whole site is purged, because bylines and structured data show the name.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'user_id'      => [ 'type' => 'integer', 'description' => 'The user to change. Defaults to the authenticated user. Another user needs edit_users.' ],
				'display_name' => [ 'type' => 'string', 'description' => 'The name shown publicly. See above for which values are accepted.' ],
				'nickname'     => [ 'type' => 'string', 'description' => 'Cannot be empty.' ],
				'first_name'   => [ 'type' => 'string', 'description' => 'First name.' ],
				'last_name'    => [ 'type' => 'string', 'description' => 'Last name.' ],
				'description'  => [ 'type' => 'string', 'description' => 'Biographical info. WordPress removes most HTML.' ],
				'url'          => [ 'type' => 'string', 'description' => 'Website: an http or https address, or empty to clear it.' ],
				'dry_run'      => [ 'type' => 'boolean', 'description' => 'Report what would change without writing (default: false).' ],
			],
			'required'   => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$known   = array_merge( array_keys( IATO_MCP_User_Profile::FIELDS ), [ 'user_id', 'dry_run' ] );
		$unknown = array_values( array_diff( array_keys( $args ), $known ) );
		if ( [] !== $unknown ) {
			return IATO_MCP_Server::refusal(
				new WP_Error(
					'field_not_allowed',
					sprintf( 'This tool cannot change %s. It changes only the public fields listed in allowed. Nothing was written.', implode( ', ', $unknown ) ),
					[ 'fields' => $unknown, 'allowed' => array_keys( IATO_MCP_User_Profile::FIELDS ) ]
				)
			);
		}
		if ( [] === array_intersect( array_keys( $args ), array_keys( IATO_MCP_User_Profile::FIELDS ) ) ) {
			return IATO_MCP_Server::refusal(
				new WP_Error( 'no_fields', 'Send at least one field to change.', [ 'allowed' => array_keys( IATO_MCP_User_Profile::FIELDS ) ] )
			);
		}

		if ( array_key_exists( 'user_id', $args ) && null !== $args['user_id'] ) {
			$user_id = is_numeric( $args['user_id'] ) && (string) (int) $args['user_id'] === trim( (string) $args['user_id'] ) ? (int) $args['user_id'] : 0;
			if ( $user_id <= 0 ) {
				return IATO_MCP_Server::refusal( new WP_Error( 'invalid_user', 'user_id must be a user ID. Nothing was written.' ) );
			}
		} else {
			$bound = IATO_MCP_Auth::require_bound_user();
			if ( is_wp_error( $bound ) ) {
				return IATO_MCP_Server::refusal( new WP_Error( $bound->get_error_code(), $bound->get_error_message() . ' Or name a user with user_id.' ) );
			}
			$user_id = (int) get_current_user_id();
		}

		// Yourself, or anyone if you hold edit_users. Before the lookup, so the answer
		// does not tell anyone else which IDs exist.
		$may_edit = IATO_MCP_Auth::require_cap( 'edit_user', $user_id );
		if ( is_wp_error( $may_edit ) ) {
			return $may_edit;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return IATO_MCP_Server::refusal( new WP_Error( 'invalid_user', sprintf( 'There is no user %d. Nothing was written.', $user_id ) ) );
		}

		$changes = IATO_MCP_User_Profile::plan( $args, $user );
		if ( is_wp_error( $changes ) ) {
			return IATO_MCP_Server::refusal( $changes );
		}

		$before = array_intersect_key( IATO_MCP_User_Profile::read( $user ), $changes );
		$report = [];
		foreach ( $changes as $field => $value ) {
			$entry = IATO_MCP_Sanitize_Report::for_value( $field, 'description' === $field ? 'html' : 'text', $args[ $field ], $value );
			if ( null !== $entry ) {
				$report[] = $entry;
			}
		}

		if ( ! empty( $args['dry_run'] ) ) {
			$would = [];
			foreach ( $changes as $field => $value ) {
				$would[ $field ] = [ 'before' => $before[ $field ], 'after' => $value ];
			}
			$data = [
				'dry_run'      => true,
				'action'       => [] === $changes ? 'no_change' : 'would_update',
				'user_id'      => $user_id,
				'would_change' => (object) $would,
				'not_checked'  => IATO_MCP_Server::DRY_RUN_NOT_CHECKED,
			];
			IATO_MCP_Sanitize_Report::add_to( $data, $report );
			return IATO_MCP_Server::ok( $data );
		}

		$data = [ 'user_id' => $user_id, 'changes' => (object) [] ];
		if ( [] !== $changes ) {
			$saved = IATO_MCP_User_Profile::write( $user_id, $changes );
			if ( is_wp_error( $saved ) ) {
				return IATO_MCP_Server::refusal( $saved );
			}
			clean_user_cache( $user_id );
			$fresh  = get_userdata( $user_id );
			$after  = array_intersect_key( IATO_MCP_User_Profile::read( $fresh ?: $user ), $changes );
			$shown  = [];
			foreach ( $after as $field => $value ) {
				$shown[ $field ] = [ 'before' => $before[ $field ], 'after' => $value ];
			}
			$data['changes'] = (object) $shown;
			$receipt         = IATO_MCP_Change_Receipt::record(
				null,
				IATO_MCP_User_Profile::RECEIPT,
				'profile',
				[ 'user_id' => $user_id, 'fields' => $before ],
				[ 'user_id' => $user_id, 'fields' => $after ]
			);
			IATO_MCP_Change_Receipt::append( $data, $receipt );
		}
		IATO_MCP_Sanitize_Report::add_to( $data, $report );
		return IATO_MCP_Server::ok( $data );
	}
);
