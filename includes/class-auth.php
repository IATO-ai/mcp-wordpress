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
		// TODO: implement — check get_current_user_id() > 0 and current_user_can('read')
		// Return true on success, WP_Error on failure.
		// WP core Application Password auth runs automatically via authenticate filter.
		// Reference: https://developer.wordpress.org/reference/functions/wp_authenticate_application_password/
		return new WP_Error( 'not_implemented', 'Auth not yet implemented', [ 'status' => 501 ] );
	}

	/**
	 * Assert the current user has a given capability, for use inside tool handlers.
	 *
	 * @param string $cap WordPress capability string.
	 * @return true|WP_Error
	 */
	public static function require_cap( string $cap ): true|WP_Error {
		// TODO: implement — return WP_Error with status 403 if !current_user_can($cap)
		return new WP_Error( 'not_implemented', 'Cap check not yet implemented', [ 'status' => 501 ] );
	}
}
