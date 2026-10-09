<?php
/**
 * Authentication — validates incoming MCP requests on the REST endpoint.
 *
 * Three auth paths are accepted:
 *   1. Plugin Bearer token (`iato_mcp_key`) — auto-generated on activation, used by the
 *      IATO platform's own integrations and by clients that paste the Settings page config.
 *      Documented as full administrative access: only admins should possess this key.
 *      Token binding (Sprint C0): the key records an owner (`iato_mcp_key_owner`, set
 *      on activation, rotation, or the Settings "Token owner" control).
 *      A bound key runs the request as its owner via wp_set_current_user(), after
 *      re-checking on every request that the owner still exists and still holds
 *      manage_options (otherwise 403 iato_mcp_owner_missing / iato_mcp_owner_demoted).
 *      unfiltered_html is denied for the request so content sanitising stays exactly
 *      as it was for user 0. A key with no owner (issued before binding existed) keeps
 *      the historical behaviour: user 0, require_cap() passes everything, and
 *      require_bound_user() returns iato_mcp_token_unbound for features that need a user.
 *   2. OAuth access token (`Bearer iato_at_...`, 1.13.0) — issued per client by
 *      IATO_MCP_OAuth, stored hashed, expiring, revocable. Runs as the administrator
 *      who approved the connection, re-checked on every request exactly like a key
 *      owner; a refused token gets 401 (expired, revoked, user missing or demoted;
 *      the last two also revoke the grant). Before 1.13.0 OAuth handed out the site
 *      key itself, so connections made then still arrive on path 1.
 *   3. WordPress Application Password (Basic auth) — the WP-native credential, used by
 *      AI clients via the setup wizard (Methods 2 and 3) and by generic HTTP MCP tooling.
 *      Carries the authenticated WP user; per-tool capability checks via require_cap()
 *      enforce against that user. Not affected by token binding.
 *
 * Capability model:
 *   - Bound Bearer key / OAuth token: require_cap() calls user_can($user, $cap). Real.
 *   - Unbound legacy Bearer key: require_cap() returns true for any cap (as before).
 *   - Application Password: require_cap() calls user_can($user, $cap) against the
 *     authenticated user. v1.10.0 turned this enforcement on; prior to v1.10.0 the
 *     check was bypassed for both paths.
 *
 * credential() reports which path authenticated the request (for receipts / logs).
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

	/** Prefixes of OAuth-issued tokens (IATO_MCP_OAuth). The site key has no prefix. */
	public const OAUTH_ACCESS_PREFIX  = 'iato_at_';
	public const OAUTH_REFRESH_PREFIX = 'iato_rt_';

	/**
	 * Which credential authenticated the request:
	 * kind: site_key | oauth | application_password; user_id; for oauth also
	 * client_id and grant_id. Empty until authenticate() succeeds.
	 *
	 * @var array{kind?:string,user_id?:int,client_id?:string,grant_id?:string}
	 */
	private static array $credential = [];

	/** True when a Bearer value was presented and refused (adds error="invalid_token" to the 401). */
	private static bool $token_refused = false;

	/** @var array<string,array|null> OAuth token rows by hash; WP runs permission_callback more than once per request. */
	private static array $oauth_token_cache = [];

	/**
	 * May this request store unfiltered HTML (Elementor trees, post content)?
	 * False for the site key and OAuth tokens always. For an Application
	 * Password it is false unless the `iato_mcp_allow_unfiltered_html` filter
	 * returns true for that user and the user holds unfiltered_html; the
	 * decision is taken once in authenticate(), before the kses lock.
	 */
	private static bool $unfiltered_html_allowed = false;

	public static function unfiltered_html_allowed(): bool {
		return self::$unfiltered_html_allowed;
	}

	/** @return array{kind?:string,user_id?:int,client_id?:string,grant_id?:string} */
	public static function credential(): array {
		return self::$credential;
	}

	public static function token_was_refused(): bool {
		return self::$token_refused;
	}

	/**
	 * Is this user still an administrator? Shared by the site-key owner check,
	 * the OAuth token check and the OAuth token endpoint.
	 *
	 * @return array{state:'ok'|'missing'|'demoted',user:?WP_User}
	 */
	public static function administrator_state( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [ 'state' => 'missing', 'user' => null ];
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! $user->exists() ) {
			return [ 'state' => 'missing', 'user' => null ];
		}
		if ( ! user_can( $user, self::OWNER_CAP ) ) {
			return [ 'state' => 'demoted', 'user' => $user ];
		}
		return [ 'state' => 'ok', 'user' => $user ];
	}

	// ── Key owner ─────────────────────────────────────────────────────────

	/**
	 * WordPress user ID the site key is bound to. 0 means unbound (legacy).
	 */
	public static function key_owner_id(): int {
		return max( 0, (int) get_option( self::OWNER_OPTION, 0 ) );
	}

	/**
	 * Bind (or, with 0, unbind) the site key to a user. The single write path
	 * for the owner option: activation, key rotation and the Settings "Token
	 * owner" control go through here. (OAuth consent bound the key until
	 * 1.12.1; since 1.13.0 OAuth issues its own tokens and never touches it.)
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
	 * Run $fn with the kses lock lifted, for the plugin's own meta writes of
	 * values it has already sanitised (the Elementor fallback write, rollback
	 * restores, page-settings writes). Elementor registers a REST
	 * sanitize_callback on `_elementor_data` and `_elementor_page_settings`
	 * (modules/wp-rest/classes/elementor-post-meta.php) that runs kses over the
	 * *whole* value for users without unfiltered_html; left in place it would
	 * strip HTML a human put in widgets the request never touched. Lifting the
	 * lock restores the real user's capability for the duration of the write
	 * only; user 0 and users without the capability are unaffected. Never used
	 * around Elementor's own Document::save().
	 */
	public static function write_unlocked( callable $fn ): mixed {
		$was               = self::$kses_locked;
		self::$kses_locked = false;
		try {
			return $fn();
		} finally {
			self::$kses_locked = $was;
		}
	}

	/**
	 * Record a token rejection in the call log so Diagnostics shows why a key
	 * stopped working. permission_callback failures are otherwise invisible.
	 */
	private static function log_rejection( string $code, string $message, int $owner_id, array $args = [] ): void {
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
			'request_args'    => $args,
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
		// OAuth connections made before 1.13.0 held the old key; a new key is
		// the only thing that invalidates them, so the flags that say "pre-1.13.0
		// connections may still hold the key" (Diagnostics badge, Settings hint,
		// upgrade notice) are cleared here and nowhere else.
		delete_option( 'iato_mcp_oauth_legacy_key' );
		delete_option( 'iato_mcp_oauth_legacy_notice' );
		return $key;
	}

	/**
	 * Permission callback for the MCP REST route.
	 *
	 * Accepts three auth paths: plugin Bearer token, OAuth access token (also a
	 * Bearer header, recognised by its prefix) or WordPress Application Password
	 * (Basic auth, already validated by WP core's determine_current_user filter chain).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ): bool|WP_Error {
		$header = $request->get_header( 'Authorization' );
		$bearer = ( $header && 0 === strncasecmp( $header, 'Bearer ', 7 ) ) ? substr( $header, 7 ) : null;

		// OAuth access tokens never equal the site key and carry their own
		// user, so they are resolved before the site-key configuration check:
		// an OAuth client keeps working even if the key option were empty.
		if ( null !== $bearer && str_starts_with( $bearer, self::OAUTH_ACCESS_PREFIX ) ) {
			return self::authenticate_oauth_token( $bearer );
		}

		$stored_key = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );

		if ( '' === $stored_key ) {
			return new WP_Error(
				'iato_mcp_not_configured',
				__( 'MCP API key has not been generated. Deactivate and reactivate the plugin.', 'iato-mcp' ),
				[ 'status' => 500 ]
			);
		}

		if ( null !== $bearer ) {
			$provided_key = $bearer;

			if ( ! hash_equals( $stored_key, $provided_key ) ) {
				self::$token_refused = true;
				return new WP_Error(
					'iato_mcp_unauthorized',
					__( 'Invalid API key.', 'iato-mcp' ),
					[ 'status' => 401 ]
				);
			}

			// Token binding: run the request as the key's owner. This is the one
			// place the current user is set for site-key requests.
			$owner_id = self::key_owner_id();

			if ( 0 === $owner_id ) {
				// Legacy key issued before binding existed: historical behaviour
				// (user 0, full access). Features that need a user call
				// require_bound_user() and get a clear error. See the admin notice.
				self::$authenticated        = true;
				self::$legacy_unbound       = true;
				self::$site_key_full_access = true;
				self::$credential           = [ 'kind' => 'site_key', 'user_id' => 0 ];
				return true;
			}

			$admin = self::administrator_state( $owner_id );
			if ( 'missing' === $admin['state'] ) {
				$message = __( 'The WordPress user who owns this IATO MCP key no longer exists. Assign a new owner or regenerate the key under Settings > IATO MCP.', 'iato-mcp' );
				self::log_rejection( 'iato_mcp_owner_missing', $message, $owner_id );
				return new WP_Error( 'iato_mcp_owner_missing', $message, [ 'status' => 403 ] );
			}
			$owner = $admin['user'];
			if ( 'demoted' === $admin['state'] ) {
				$message = sprintf(
					/* translators: 1: user login, 2: capability name */
					__( 'The owner of this IATO MCP key (%1$s) no longer has the %2$s capability. Assign an administrator as owner or regenerate the key under Settings > IATO MCP.', 'iato-mcp' ),
					$owner->user_login,
					self::OWNER_CAP
				);
				self::log_rejection( 'iato_mcp_owner_demoted', $message, $owner_id );
				return new WP_Error( 'iato_mcp_owner_demoted', $message, [ 'status' => 403 ] );
			}

			self::run_as( $owner );
			self::$credential = [ 'kind' => 'site_key', 'user_id' => (int) $owner->ID ];
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
				self::$credential         = [ 'kind' => 'application_password', 'user_id' => (int) $user->ID ];

				// Content written through MCP is filtered like a non-administrator's
				// (1.13.0): the AI client is the actor, whatever the user's role.
				// A developer who needs raw HTML through MCP opts in with
				//   add_filter( 'iato_mcp_allow_unfiltered_html', fn( $allow, $user_id, $kind ) => true, 10, 3 );
				// which is honoured only for Application Password users who hold
				// unfiltered_html. The site key and OAuth tokens are always locked.
				$allow = (bool) apply_filters( 'iato_mcp_allow_unfiltered_html', false, (int) $user->ID, 'application_password' )
					&& user_can( $user, 'unfiltered_html' );
				if ( ! $allow ) {
					self::lock_unfiltered_html();
					// WP core decided its content filters at init, before this
					// callback ran and before the lock existed; re-evaluate them.
					if ( function_exists( 'kses_init' ) ) {
						kses_init();
					}
				}
				self::$unfiltered_html_allowed = $allow;
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
	 * Run the rest of the request as an administrator (bound site key or
	 * OAuth token). Denies unfiltered_html before the user switch so
	 * kses_init() (which re-runs on set_current_user) keeps WordPress'
	 * content filters on, exactly as they were for user 0.
	 */
	private static function run_as( WP_User $user ): void {
		self::lock_unfiltered_html();
		wp_set_current_user( $user->ID );
		self::$authenticated      = true;
		self::$authenticated_user = $user;
	}

	/**
	 * OAuth access token path (1.13.0). The token row must be an unexpired,
	 * unrevoked access token and its approving user must still be an
	 * administrator. Every refusal is a 401 so a well-behaved client tries
	 * its refresh token, which fails with invalid_grant for a dead grant and
	 * sends the user back to consent.
	 */
	private static function authenticate_oauth_token( string $token ): bool|WP_Error {
		$refuse = function ( string $message, int $user_id = 0, ?string $log_code = null, array $log_args = [] ): WP_Error {
			self::$token_refused = true;
			if ( null !== $log_code ) {
				self::log_rejection( $log_code, $message, $user_id, $log_args );
			}
			return new WP_Error( 'iato_mcp_unauthorized', $message, [ 'status' => 401 ] );
		};

		if ( ! class_exists( 'IATO_MCP_OAuth_Store' ) ) {
			return $refuse( __( 'Invalid or expired access token.', 'iato-mcp' ) );
		}

		$hash = hash( 'sha256', $token );
		if ( ! array_key_exists( $hash, self::$oauth_token_cache ) ) {
			self::$oauth_token_cache[ $hash ] = IATO_MCP_OAuth_Store::instance()->find_token( $hash );
		}
		$row = self::$oauth_token_cache[ $hash ];
		$now = time();
		$ts  = gmdate( 'Y-m-d H:i:s', $now );

		if ( null === $row || 'access' !== ( $row['kind'] ?? '' ) ) {
			return $refuse( __( 'Invalid or expired access token.', 'iato-mcp' ) );
		}
		// The grant row is the authority: a token issued under a revoked grant
		// is refused whenever it was created, and a token with no grant row at
		// all is refused the same way.
		if ( ( class_exists( 'IATO_MCP_OAuth' ) && IATO_MCP_OAuth::grant_is_dead( $row ) ) || null !== $row['revoked_at'] ) {
			return $refuse( __( 'This access token has been revoked. Reconnect the application.', 'iato-mcp' ) );
		}
		if ( (string) $row['expires_at'] <= $ts ) {
			return $refuse( __( 'Access token expired. Refresh the token or reconnect the application.', 'iato-mcp' ) );
		}

		$user_id = (int) $row['user_id'];
		$admin   = self::administrator_state( $user_id );
		$args    = [ 'client_id' => (string) $row['client_id'], 'grant_id' => (string) $row['grant_id'] ];
		if ( 'ok' !== $admin['state'] ) {
			IATO_MCP_OAuth_Store::instance()->revoke_grant( (string) $row['grant_id'], $ts, 'user_' . $admin['state'] );
			self::$oauth_token_cache[ $hash ]['revoked_at'] = $ts;
			if ( 'missing' === $admin['state'] ) {
				$message = __( 'The WordPress user who approved this connection no longer exists. The connection has been revoked; an administrator must approve it again.', 'iato-mcp' );
				return $refuse( $message, $user_id, 'iato_mcp_token_user_missing', $args );
			}
			$message = sprintf(
				/* translators: 1: user login, 2: capability name */
				__( 'The WordPress user who approved this connection (%1$s) no longer has the %2$s capability. The connection has been revoked; an administrator must approve it again.', 'iato-mcp' ),
				$admin['user']->user_login,
				self::OWNER_CAP
			);
			return $refuse( $message, $user_id, 'iato_mcp_token_user_demoted', $args );
		}

		self::run_as( $admin['user'] );
		self::$credential = [ 'kind' => 'oauth', 'user_id' => $user_id ] + $args;

		// "Last used" for the Settings screen, written at most once a minute.
		$last = (string) ( $row['last_used_at'] ?? '' );
		if ( '' === $last || ( strtotime( $last . ' UTC' ) ?: 0 ) + 60 <= $now ) {
			IATO_MCP_OAuth_Store::instance()->touch_last_used( (int) $row['id'], $ts );
			self::$oauth_token_cache[ $hash ]['last_used_at'] = $ts;
		}
		return true;
	}

	/**
	 * Assert the current request has permission for the given capability.
	 *
	 * Capability model (v1.10.0, token binding v1.12, OAuth tokens v1.13):
	 *
	 *   - Unbound site key (self::$site_key_full_access): returns true for
	 *     any cap. Documented as full administrative access — the plugin key
	 *     is admin-issued and the threat model is "only admins should possess
	 *     it." Security comes from key issuance, not per-tool checks.
	 *
	 *   - Bound site key, OAuth token, Application Password
	 *     (self::$authenticated_user is the WP_User): calls user_can($user,
	 *     $cap). Returns true if the user has the cap, WP_Error 403 otherwise.
	 *
	 * @param string $cap     Capability (primitive or meta capability such as edit_post).
	 * @param mixed  ...$args Object arguments forwarded to user_can() for meta
	 *                        capabilities (e.g. the post ID for edit_post). The
	 *                        Bearer plugin-key path ignores them, as it does the cap.
	 */
	public static function require_cap( string $cap, mixed ...$args ): bool|WP_Error {
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
		if ( self::$authenticated_user instanceof WP_User && user_can( self::$authenticated_user, $cap, ...$args ) ) {
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

	// ── Object-level helpers ──────────────────────────────────────────────

	/**
	 * Statuses that need the post type's publish capability to set. Same rule
	 * as core's REST posts controller (handle_status_param).
	 */
	public const PUBLISH_STATUSES = [ 'publish', 'future', 'private' ];

	/**
	 * Capability name for an action on a post type, read from the type's
	 * registered capability map so `page` and custom post types resolve to
	 * their own names (edit_pages, publish_pages, …). Falls back to the core
	 * post / page names when the type is not registered.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $key       Key in WP_Post_Type::$cap: create_posts, publish_posts, edit_posts, …
	 */
	public static function post_type_cap( string $post_type, string $key ): string {
		if ( function_exists( 'get_post_type_object' ) ) {
			$pto = get_post_type_object( $post_type );
			if ( $pto && isset( $pto->cap->$key ) && is_string( $pto->cap->$key ) ) {
				return $pto->cap->$key;
			}
		}
		$key = 'create_posts' === $key ? 'edit_posts' : $key;
		return 'page' === $post_type ? str_replace( '_posts', '_pages', $key ) : $key;
	}

	/**
	 * Capability name for an action on a taxonomy (manage_terms, edit_terms,
	 * delete_terms, assign_terms), read from the taxonomy's capability map.
	 */
	public static function taxonomy_cap( string $taxonomy, string $key ): string {
		if ( function_exists( 'get_taxonomy' ) ) {
			$tax = get_taxonomy( $taxonomy );
			if ( $tax && isset( $tax->cap->$key ) && is_string( $tax->cap->$key ) ) {
				return $tax->cap->$key;
			}
		}
		return 'assign_terms' === $key ? 'edit_posts' : 'manage_categories';
	}

	/**
	 * Require the capability to publish (or schedule / make private) a post of
	 * the given type: publish_posts, publish_pages, or the custom type's own.
	 */
	public static function require_publish_cap( string $post_type ): bool|WP_Error {
		return self::require_cap( self::post_type_cap( $post_type, 'publish_posts' ) );
	}

	/**
	 * May the current request read this post through a read tool?
	 *
	 *  - read_post on the post: published public posts need `read`, private
	 *    ones read_private_posts / read_private_pages, drafts and pending posts
	 *    resolve to edit_post (so a Contributor reads their own drafts and
	 *    nobody else's);
	 *  - edit_post as well when the post is password-protected, because the
	 *    tools return the content without asking for the password;
	 *  - manage_options for Elementor Theme Builder templates
	 *    (elementor_library), the same bar as list_elementor_templates.
	 *
	 * A revision is resolved to its parent first and all three rules run
	 * against the parent: a revision row has post_type `revision` and no
	 * post_password of its own, so checking it directly would let the body
	 * of a password-protected page or of a template through.
	 *
	 * The site key passes all of these (require_cap() short-circuits before
	 * any user_can() call), so its behaviour is unchanged.
	 */
	public static function require_read_post( int $post_id ): bool|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		if ( 'revision' === (string) ( $post->post_type ?? '' ) ) {
			$parent = (int) ( $post->post_parent ?? 0 ) > 0 ? get_post( (int) $post->post_parent ) : null;
			if ( ! $parent ) {
				return new WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] );
			}
			$post    = $parent;
			$post_id = (int) $parent->ID;
		}
		if ( 'elementor_library' === (string) ( $post->post_type ?? '' ) ) {
			return self::require_cap( 'manage_options' );
		}
		$read = self::require_cap( 'read_post', $post_id );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		if ( '' !== (string) ( $post->post_password ?? '' ) ) {
			return self::require_cap( 'edit_post', $post_id );
		}
		return true;
	}
}
