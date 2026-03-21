<?php
/**
 * Authentication — validates WordPress Application Passwords on every MCP request.
 *
 * Application Passwords are built into WordPress 5.6+. Users generate one via
 * WP Admin > Users > Profile > Application Passwords, then base64-encode
 * "username:app-password" for the Authorization: Basic header.
 *
 * No nonces. No sessions. Stateless per-request auth.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Auth {

	/**
	 * Permission callback for the MCP REST route.
	 *
	 * WordPress core's Application Password handler runs before this, so by
	 * the time we reach this callback, get_current_user_id() is already set
	 * if credentials were valid. We just check that a user is authenticated
	 * and has at minimum read access.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ): true|WP_Error {
		if ( 0 === get_current_user_id() ) {
			return new WP_Error(
				'iato_mcp_unauthorized',
				__( 'Authentication required. Use an Application Password with Basic Auth.', 'iato-mcp' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'iato_mcp_forbidden',
				__( 'Your user account does not have sufficient permissions.', 'iato-mcp' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Assert the current user has a given capability, for use inside tool handlers.
	 *
	 * @param string $cap WordPress capability string.
	 * @return true|WP_Error
	 */
	public static function require_cap( string $cap ): true|WP_Error {
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error(
				'iato_mcp_forbidden',
				/* translators: %s: WordPress capability string */
				sprintf( __( 'You do not have the required capability: %s', 'iato-mcp' ), $cap ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}
}
