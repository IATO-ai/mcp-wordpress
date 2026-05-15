<?php
/**
 * Authentication — validates incoming MCP requests on the REST endpoint.
 *
 * Two auth paths are accepted:
 *   1. Plugin Bearer token (`iato_mcp_key`) — auto-generated on activation, used by the
 *      IATO platform's own integrations and by clients that paste the Settings page config.
 *      Documented as full administrative access: only admins should possess this key.
 *      OAuth-issued tokens also currently flow through this path — see Known Issue KI-1
 *      in the v1.10.0 plan: OAuth's access_token IS the plugin key today, so OAuth-
 *      authenticated requests are admin-key-equivalent regardless of authorizing user.
 *      Per-user OAuth token issuance is tracked for a future cycle.
 *   2. WordPress Application Password (Basic auth) — the WP-native credential, used by
 *      AI clients via the setup wizard (Methods 2 and 3) and by generic HTTP MCP tooling.
 *      Carries the authenticated WP user; per-tool capability checks via require_cap()
 *      enforce against that user.
 *
 * Capability model:
 *   - Bearer plugin key: require_cap() returns true for any cap. Full admin access.
 *   - Application Password: require_cap() calls user_can($user, $cap) against the
 *     authenticated user. v1.10.0 turned this enforcement on; prior to v1.10.0 the
 *     check was bypassed for both paths.
 *
 * Stateless per-request auth: no nonces, no sessions.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Auth {

	/** @var bool Whether the current request has been authenticated by any accepted path. */
	private static bool $authenticated = false;

	/**
	 * The WordPress user object for the current request, when authentication
	 * carries user context (Application Password path). Null for the Bearer
	 * plugin-key path (which has no associated user — the key itself is the
	 * credential and grants documented full admin access).
	 *
	 * Set in authenticate(); consumed by require_cap().
	 *
	 * @var WP_User|null
	 */
	private static ?WP_User $authenticated_user = null;

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
		//
		// edit_posts is the auth-time baseline ONLY (Subscribers rejected here). Per-tool
		// caps are enforced downstream by require_cap() — see capability-model comment at
		// the top of this class. v1.10.0 turned that per-tool enforcement on; prior to
		// v1.10.0, require_cap() bypassed the cap check for any authenticated request.
		if ( $header && 0 === strncasecmp( $header, 'Basic ', 6 ) && is_user_logged_in() ) {
			$user = wp_get_current_user();

			if ( $user && $user->exists() && user_can( $user, 'edit_posts' ) ) {
				self::$authenticated      = true;
				self::$authenticated_user = $user;
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
	 * Capability model (v1.10.0):
	 *
	 *   - Bearer plugin key (self::$authenticated_user is null): returns true
	 *     for any cap. Documented as full administrative access — the plugin
	 *     key is admin-issued and the threat model is "only admins should
	 *     possess it." Security comes from key issuance, not per-tool checks.
	 *
	 *   - Application Password (self::$authenticated_user is the WP_User):
	 *     calls user_can($user, $cap). Returns true if the user has the cap,
	 *     WP_Error 403 otherwise. This is the per-user capability enforcement
	 *     that prior versions deferred — see the v1.10.0 plan.
	 *
	 * OAuth-issued tokens currently flow through the Bearer plugin-key path
	 * because OAuth's access_token IS the plugin key (Known Issue KI-1).
	 * They therefore receive full admin access regardless of the WP user who
	 * authorized the OAuth flow. Fixing that requires per-user OAuth token
	 * issuance — separate cycle.
	 *
	 * @param string $cap WordPress capability string.
	 * @return true|WP_Error
	 */
	public static function require_cap( string $cap ): bool|WP_Error {
		if ( ! self::$authenticated ) {
			return new WP_Error(
				'iato_mcp_forbidden',
				/* translators: %s: WordPress capability string */
				sprintf( __( 'You do not have the required capability: %s', 'iato-mcp' ), $cap ),
				[ 'status' => 403 ]
			);
		}

		// Bearer plugin-key path: no associated user, documented full admin access.
		if ( null === self::$authenticated_user ) {
			return true;
		}

		// Application Password path: enforce the cap against the authenticated user.
		// user_can() with an explicit WP_User is more robust than current_user_can()
		// against any callers that mutate the $current_user global later in the
		// request lifecycle. (Equivalent under normal REST auth, but the explicit-user
		// form removes one assumption about global state.)
		if ( user_can( self::$authenticated_user, $cap ) ) {
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
