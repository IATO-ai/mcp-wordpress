<?php
/**
 * Authentication — validates the plugin-generated API key on every MCP request.
 *
 * On plugin activation a 32-character random key is generated and stored in
 * wp_options as `iato_mcp_key`. Clients send it via `Authorization: Bearer <key>`.
 *
 * No nonces. No sessions. No WordPress Application Passwords. Stateless per-request auth.
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
	 * Extracts the Bearer token from the Authorization header and compares
	 * it against the stored plugin key using a timing-safe comparison.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ): true|WP_Error {
		$stored_key = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );

		if ( '' === $stored_key ) {
			return new WP_Error(
				'iato_mcp_not_configured',
				__( 'MCP API key has not been generated. Deactivate and reactivate the plugin.', 'iato-mcp' ),
				[ 'status' => 500 ]
			);
		}

		$header = $request->get_header( 'Authorization' );

		if ( ! $header || 0 !== strncasecmp( $header, 'Bearer ', 7 ) ) {
			return new WP_Error(
				'iato_mcp_unauthorized',
				__( 'Authentication required. Use Authorization: Bearer <your-mcp-key>.', 'iato-mcp' ),
				[ 'status' => 401 ]
			);
		}

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
	public static function require_cap( string $cap ): true|WP_Error {
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
