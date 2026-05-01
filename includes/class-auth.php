<?php
/**
 * Authentication — validates incoming MCP requests on the REST endpoint.
 *
 * Two auth paths are accepted:
 *   1. Plugin Bearer token (`iato_mcp_key`) — auto-generated on activation, used by the
 *      IATO platform's own integrations and by clients that paste the Settings page config.
 *   2. WordPress Application Password (Basic auth) — the WP-native credential, used by
 *      AI clients via the setup wizard (Methods 2 and 3) and by generic HTTP MCP tooling.
 *
 * Both paths grant full administrative access in this version (matching the long-standing
 * "plugin key grants full administrative access" invariant). Per-user capability enforcement
 * under Application Password is tracked separately as a v1.6 hardening item — see the
 * deferred section in the v1.4.2 plan.
 *
 * Stateless per-request auth: no nonces, no sessions.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Auth {

	/** @var bool Whether the current request has been authenticated via the plugin key. */
	private static bool $authenticated = false;

	/**
	 * Generate and store a new API key on first activation.
	 * Called from the activation hook in iato-mcp.php.
	 */
	public static function maybe_generate_key(): void {
		if ( get_option( 'iato_mcp_key' ) ) {
			return;
		}
		$key = wp_generate_password( 32, false );
		update_option( 'iato_mcp_key', sanitize_text_field( $key ) );
	}

	/**
	 * Regenerate the API key. Returns the new key.
	 */
	public static function rotate_key(): string {
		$key = wp_generate_password( 32, false );
		update_option( 'iato_mcp_key', sanitize_text_field( $key ) );
		return $key;
	}

	/**
	 * Permission callback for the MCP REST route.
	 *
	 * Accepts two auth paths: plugin Bearer token or WordPress Application Password
	 * (Basic auth, already validated by WP core's determine_current_user filter chain).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ): bool|WP_Error {
		$stored_key = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );

		if ( '' === $stored_key ) {
			return new WP_Error(
				'iato_mcp_not_configured',
				__( 'MCP API key has not been generated. Deactivate and reactivate the plugin.', 'iato-mcp' ),
				[ 'status' => 500 ]
			);
		}

		$header = $request->get_header( 'Authorization' );

		if ( $header && 0 === strncasecmp( $header, 'Bearer ', 7 ) ) {
			$provided_key = substr( $header, 7 );

			if ( ! hash_equals( $stored_key, $provided_key ) ) {
				return new WP_Error(
					'iato_mcp_unauthorized',
					__( 'Invalid API key.', 'iato-mcp' ),
					[ 'status' => 401 ]
				);
			}

			self::$authenticated = true;
			return true;
		}

		// WP core's determine_current_user filter has already validated the Basic header
		// against Application Passwords by the time this callback runs, so we just check
		// whether a real user with edit_posts is now logged in.
		if ( $header && 0 === strncasecmp( $header, 'Basic ', 6 ) && is_user_logged_in() ) {
			$user = wp_get_current_user();

			if ( $user && $user->exists() && user_can( $user, 'edit_posts' ) ) {
				self::$authenticated = true;
				return true;
			}

			return new WP_Error(
				'iato_mcp_forbidden',
				__( 'Authenticated user lacks the edit_posts capability required for MCP.', 'iato-mcp' ),
				[ 'status' => 403 ]
			);
		}

		return new WP_Error(
			'iato_mcp_unauthorized',
			__( 'Authentication required. Use Authorization: Bearer <your-mcp-key> or a WordPress Application Password (Basic auth).', 'iato-mcp' ),
			[ 'status' => 401 ]
		);
	}

	/**
	 * Assert the current request has permission for the given capability.
	 *
	 * Because the plugin key grants full administrative access (only site admins
	 * should possess it), this returns true for any capability when the request
	 * has been authenticated via the plugin key.
	 *
	 * @param string $cap WordPress capability string (kept for call-site compatibility).
	 * @return true|WP_Error
	 */
	public static function require_cap( string $cap ): bool|WP_Error {
		if ( self::$authenticated ) {
			return true;
		}

		return new WP_Error(
			'iato_mcp_forbidden',
			/* translators: %s: WordPress capability string */
			sprintf( __( 'You do not have the required capability: %s', 'iato-mcp' ), $cap ),
			[ 'status' => 403 ]
		);
	}
}
