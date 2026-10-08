<?php
/**
 * Authentication — validates incoming MCP requests on the REST endpoint.
 *
 * Two auth paths are accepted:
 *   1. Plugin Bearer token (`iato_mcp_key`) — auto-generated on activation, used by the
 *      IATO platform's own integrations and by clients that paste the Settings page config.
 *      Documented as full administrative access: only admins should possess this key.
 *      OAuth-issued tokens also flow through this path: OAuth's access_token IS the
 *      plugin key (Known Issue KI-1), so OAuth requests are indistinguishable from
 *      plugin-key requests here.
 *      Token binding (Sprint C0): the key records an owner (`iato_mcp_key_owner`, set
 *      on activation, rotation, the Settings "Token owner" control, or OAuth consent).
 *      A bound key runs the request as its owner via wp_set_current_user(), after
 *      re-checking on every request that the owner still exists and still holds
 *      manage_options (otherwise 403 iato_mcp_owner_missing / iato_mcp_owner_demoted).
 *      unfiltered_html is denied for the request so content sanitising stays exactly
 *      as it was for user 0. A key with no owner (issued before binding existed) keeps
 *      the historical behaviour: user 0, require_cap() passes everything, and
 *      require_bound_user() returns iato_mcp_token_unbound for features that need a user.
 *   2. WordPress Application Password (Basic auth) — the WP-native credential, used by
 *      AI clients via the setup wizard (Methods 2 and 3) and by generic HTTP MCP tooling.
 *      Carries the authenticated WP user; per-tool capability checks via require_cap()
 *      enforce against that user. Not affected by token binding.
 *
 * Capability model:
 *   - Bound Bearer / OAuth key: require_cap() calls user_can($owner, $cap). Real.
 *   - Unbound legacy Bearer key: require_cap() returns true for any cap (as before).
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

	/** Option holding the WordPress user ID that owns the site key (0 / missing = unbound legacy key). */
	public const OWNER_OPTION = 'iato_mcp_key_owner';

	/** Capability the key owner must hold on every request. */
	public const OWNER_CAP = 'manage_options';

	/**
	 * True when the request authenticated with a Bearer key that has no owner
	 * (a key issued before token binding existed). Such requests keep the
	 * historical behaviour: user 0, require_cap() passes everything.
	 */
	private static bool $legacy_unbound = false;

	/**
	 * True while the map_meta_cap guard that denies unfiltered_html is active
	 * (bound Bearer / OAuth requests only). Keeps WordPress' kses filters on
	 * for the owner exactly as they were for user 0.
	 */
	private static bool $kses_locked = false;

	/** Guards log_rejection() so a rejected request is logged once (see there). */
	private static bool $rejection_logged = false;

	/**
	 * Set only by authenticate() when the request carried the valid site key
	 * and the key has no owner (legacy mode). That path has no WordPress user
	 * and keeps the documented full administrative access, so require_cap()
	 * grants every capability when this flag is set. The flag, not "current
	 * user is 0", is what tells a site-key request apart from an
	 * unauthenticated visitor (also user 0, for whom $authenticated stays false).
	 */
	private static bool $site_key_full_access = false;

	// ── Key owner ─────────────────────────────────────────────────────────

	/**
	 * WordPress user ID the site key is bound to. 0 means unbound (legacy).
	 */
	public static function key_owner_id(): int {
		return max( 0, (int) get_option( self::OWNER_OPTION, 0 ) );
	}

	/**
	 * Bind (or, with 0, unbind) the site key to a user. The single write path
	 * for the owner option: activation, key rotation, the Settings "Token
	 * owner" control and OAuth consent all go through here.
	 */
	public static function bind_key_owner( int $user_id ): void {
		$previous = self::key_owner_id();
		$user_id  = max( 0, $user_id );
		update_option( self::OWNER_OPTION, $user_id );

		// Every owner change is recorded in the call log (Diagnostics page):
		// who changed it (the current user: activating / regenerating /
		// assigning / consenting admin), from which owner, to which owner.
		if ( $previous !== $user_id && class_exists( 'IATO_MCP_Call_Log' ) ) {
			$actor = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			IATO_MCP_Call_Log::record( [
				'rpc_method'      => 'owner',
				'tool_name'       => 'bind_key_owner',
				'request_args'    => [ 'from' => $previous, 'to' => $user_id, 'by' => $actor ],
				'response_status' => 'success',
				'duration_ms'     => 0,
				'auth_user_id'    => $actor,
			] );
		}
	}

	/**
	 * Did the current request authenticate with an unbound legacy key?
	 */
	public static function is_unbound(): bool {
		return self::$legacy_unbound;
	}

	/**
	 * For features that need a real WordPress user (Elementor's own abilities,
	 * authorship): true when the request runs as a user, WP_Error otherwise.
	 */
	public static function require_bound_user(): bool|WP_Error {
		if ( ! self::$authenticated ) {
			return new WP_Error( 'iato_mcp_forbidden', __( 'Not authenticated.', 'iato-mcp' ), [ 'status' => 403 ] );
		}
		if ( self::$legacy_unbound ) {
			return new WP_Error(
				'iato_mcp_token_unbound',
				__( 'This IATO MCP key is not assigned to a WordPress user. Assign an owner or regenerate the key under Settings > IATO MCP, then retry.', 'iato-mcp' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * map_meta_cap filter: deny unfiltered_html / edit_css while locked, so a
	 * bound administrator's request is sanitised exactly like user 0 was.
	 */
	public static function filter_map_meta_cap( array $caps, string $cap, int $user_id = 0, array $args = [] ): array {
		if ( self::$kses_locked && in_array( $cap, [ 'unfiltered_html', 'edit_css' ], true ) ) {
			return [ 'do_not_allow' ];
		}
		return $caps;
	}

	private static function lock_unfiltered_html(): void {
		self::$kses_locked = true;
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'map_meta_cap', [ self::class, 'filter_map_meta_cap' ], 10, 4 );
		}
	}

	/**
	 * Record a token rejection in the call log so Diagnostics shows why a key
	 * stopped working. permission_callback failures are otherwise invisible.
	 */
	private static function log_rejection( string $code, string $message, int $owner_id ): void {
		if ( ! class_exists( 'IATO_MCP_Call_Log' ) ) {
			return;
		}
		// WP core calls a route's permission_callback more than once per request
		// (once to dispatch, again per method when it builds the Allow header),
		// so record each rejection only once per request.
		if ( self::$rejection_logged ) {
			return;
		}
		self::$rejection_logged = true;
		IATO_MCP_Call_Log::record( [
			'rpc_method'      => 'auth',
			'tool_name'       => null,
			'response_status' => 'auth_error',
			'error_code'      => $code,
			'error_message'   => $message,
			'duration_ms'     => 0,
			'auth_user_id'    => $owner_id,
		] );
	}

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
		// The activating user owns the new key. WP-CLI activation has no user,
		// which leaves the key unbound (legacy mode) until an admin assigns one.
		self::bind_key_owner( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
	}

	/**
	 * Regenerate the API key. Returns the new key. The regenerating user
	 * becomes the owner.
	 */
	public static function rotate_key(): string {
		$key = wp_generate_password( 32, false );
		update_option( 'iato_mcp_key', sanitize_text_field( $key ) );
		self::bind_key_owner( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
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

			// Token binding: run the request as the key's owner. This is the one
			// place the current user is set for Bearer / OAuth requests.
			$owner_id = self::key_owner_id();

			if ( 0 === $owner_id ) {
				// Legacy key issued before binding existed: historical behaviour
				// (user 0, full access). Features that need a user call
				// require_bound_user() and get a clear error. See the admin notice.
				self::$authenticated        = true;
				self::$legacy_unbound       = true;
				self::$site_key_full_access = true;
				return true;
			}

			$owner = get_user_by( 'id', $owner_id );
			if ( ! $owner || ! $owner->exists() ) {
				$message = __( 'The WordPress user who owns this IATO MCP key no longer exists. Assign a new owner or regenerate the key under Settings > IATO MCP.', 'iato-mcp' );
				self::log_rejection( 'iato_mcp_owner_missing', $message, $owner_id );
				return new WP_Error( 'iato_mcp_owner_missing', $message, [ 'status' => 403 ] );
			}

			if ( ! user_can( $owner, self::OWNER_CAP ) ) {
				$message = sprintf(
					/* translators: 1: user login, 2: capability name */
					__( 'The owner of this IATO MCP key (%1$s) no longer has the %2$s capability. Assign an administrator as owner or regenerate the key under Settings > IATO MCP.', 'iato-mcp' ),
					$owner->user_login,
					self::OWNER_CAP
				);
				self::log_rejection( 'iato_mcp_owner_demoted', $message, $owner_id );
				return new WP_Error( 'iato_mcp_owner_demoted', $message, [ 'status' => 403 ] );
			}

			// Deny unfiltered_html before the user switch so kses_init() (which
			// re-runs on set_current_user) keeps WordPress' content filters on.
			self::lock_unfiltered_html();
			wp_set_current_user( $owner->ID );

			self::$authenticated      = true;
			self::$authenticated_user = $owner;
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

		// Unbound site-key path: documented full admin access, decided by the
		// explicit flag set in authenticate(), never by the absence of a user
		// (an unauthenticated visitor is also user 0 but never gets here, because
		// $authenticated is false for them). A bound key carries its owner as
		// $authenticated_user and is checked like an Application Password user.
		if ( self::$site_key_full_access ) {
			return true;
		}

		// Application Password path and bound site key: enforce the cap against
		// the authenticated user. user_can() with an explicit WP_User is more
		// robust than current_user_can() against any callers that mutate the
		// $current_user global later in the request lifecycle.
		if ( self::$authenticated_user instanceof WP_User && user_can( self::$authenticated_user, $cap ) ) {
			return true;
		}

		// Authenticated by neither path, or the user lacks the capability: refuse.
		return new WP_Error(
			'iato_mcp_forbidden',
			/* translators: %s: WordPress capability string */
			sprintf( __( 'You do not have the required capability: %s', 'iato-mcp' ), $cap ),
			[ 'status' => 403 ]
		);
	}
}
