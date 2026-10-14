<?php
/**
 * OAuth 2.1 authorization server for MCP clients (Claude.ai, Claude Desktop,
 * Claude Code, Cursor, ...). Follows the MCP authorization specification,
 * revision 2026-07-28: Protected Resource Metadata (RFC 9728), Authorization
 * Server Metadata (RFC 8414), PKCE S256 (required), Resource Indicators
 * (RFC 8707), the `iss` response parameter (RFC 9207), Dynamic Client
 * Registration (RFC 7591, kept for compatibility: Claude registers this way)
 * and Token Revocation (RFC 7009).
 *
 * Credentials (since 1.13.0; before that every step handed out the site key):
 *   - authorization code: random, single use, 5 minutes, bound to the client,
 *     redirect URI, PKCE challenge and approving administrator;
 *   - access token: random, per grant, 1 hour, prefixed `iato_at_`;
 *   - refresh token: random, rotating, 30-day sliding expiry, `iato_rt_`;
 *   - absolute grant lifetime 180 days (filter `iato_mcp_oauth_grant_lifetime`).
 * All of them are stored as SHA-256 hashes in the tables owned by
 * IATO_MCP_OAuth_Store. A token acts as the administrator who approved it;
 * IATO_MCP_Auth re-checks that user on every request. The site key is never
 * involved and keeps working unchanged on the Bearer path.
 *
 * Endpoints (served from the `init` hook, so they work on any permalink
 * setting): /.well-known/oauth-protected-resource[/...], /.well-known/
 * oauth-authorization-server, /oauth/register, /oauth/authorize, /oauth/token,
 * /oauth/revoke. The request wrappers (handle_*) read the request and exit;
 * the *_response() / validate_* / approve() / deny() methods take plain
 * arrays and a clock and return plain arrays, so the protocol logic is
 * unit-tested without WordPress.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_OAuth {

	/** Authorization code lifetime, seconds. */
	public const CODE_TTL = 300;
	/** Access token lifetime, seconds. */
	public const ACCESS_TTL = 3600;
	/** Refresh token lifetime, seconds (sliding: each rotation issues a fresh one). */
	public const REFRESH_TTL = 30 * 86400;
	/** Absolute grant lifetime from consent, seconds. Filter: iato_mcp_oauth_grant_lifetime. */
	public const GRANT_LIFETIME = 180 * 86400;
	/** Registered clients that never completed an authorization are pruned after this. */
	public const CLIENT_PENDING_TTL = 86400;
	/** Authorized clients with no live grant for this long are pruned. */
	public const CLIENT_IDLE_TTL = 30 * 86400;
	/** Expired / consumed / revoked token rows are kept this long (replay detection). */
	public const TOKEN_RETENTION = 30 * 86400;
	/** Default registration limits: [max, window seconds]. Filter: iato_mcp_oauth_registration_limits. */
	public const REGISTRATION_LIMITS = [
		'ip'   => [ 10, 600 ],
		'site' => [ 100, 3600 ],
	];
	/** Hosts that may use plain http redirect URIs (RFC 8252 loopback, plus localhost for Claude Code). */
	public const LOOPBACK_HOSTS = [ 'localhost', '127.0.0.1', '[::1]' ];
	/** Registration accepts at most this many redirect URIs. */
	public const MAX_REDIRECT_URIS = 10;

	private const GRANT_TYPES    = [ 'authorization_code', 'refresh_token' ];
	private const RESPONSE_TYPES = [ 'code' ];

	/**
	 * Hook into WordPress early to intercept OAuth paths.
	 */
	public static function init(): void {
		add_action( 'init', [ self::class, 'handle_request' ], 1 );
	}

	/**
	 * Route the request to the correct OAuth handler.
	 */
	public static function handle_request(): void {
		$path = self::get_request_path();

		// Path-aware protected resource metadata (RFC 9728 §3.1): clients that
		// get no WWW-Authenticate pointer try /.well-known/oauth-protected-resource/
		// <mcp path> before the root document. Both describe the same resource.
		if ( '.well-known/oauth-protected-resource' === $path || str_starts_with( $path, '.well-known/oauth-protected-resource/' ) ) {
			self::json_response( self::resource_metadata() );
		}

		switch ( $path ) {
			case '.well-known/oauth-authorization-server':
				self::json_response( self::metadata() );
				break;
			case 'oauth/register':
				self::handle_register();
				break;
			case 'oauth/authorize':
				self::handle_authorize();
				break;
			case 'oauth/token':
				self::handle_token();
				break;
			case 'oauth/revoke':
				self::handle_revoke();
				break;
		}
	}

	// ── Metadata ──────────────────────────────────────────────────────────

	/**
	 * The issuer identifier. Used verbatim for the metadata `issuer`, the
	 * protected-resource `authorization_servers` entry and the `iss`
	 * authorization-response parameter, which clients compare as strings.
	 */
	public static function issuer(): string {
		return home_url();
	}

	/** Canonical resource URI of the MCP server (RFC 8707 §2). */
	public static function canonical_resource(): string {
		return rest_url( 'iato-mcp/v1/message' );
	}

	/** Protected Resource Metadata (RFC 9728). */
	public static function resource_metadata(): array {
		return [
			'resource'                 => self::canonical_resource(),
			'resource_name'            => 'IATO MCP',
			'authorization_servers'    => [ self::issuer() ],
			'bearer_methods_supported' => [ 'header' ],
		];
	}

	/** Authorization Server Metadata (RFC 8414). */
	public static function metadata(): array {
		$base = self::issuer();

		return [
			'issuer'                                         => $base,
			'authorization_endpoint'                         => $base . '/oauth/authorize',
			'token_endpoint'                                 => $base . '/oauth/token',
			'registration_endpoint'                          => $base . '/oauth/register',
			'revocation_endpoint'                            => $base . '/oauth/revoke',
			'response_types_supported'                       => self::RESPONSE_TYPES,
			'grant_types_supported'                          => self::GRANT_TYPES,
			'code_challenge_methods_supported'               => [ 'S256' ],
			'token_endpoint_auth_methods_supported'          => [ 'none' ],
			'revocation_endpoint_auth_methods_supported'     => [ 'none' ],
			'authorization_response_iss_parameter_supported' => true,
		];
	}

	// ── Resource indicator (RFC 8707) ─────────────────────────────────────

	/**
	 * Normalise a resource URI for comparison: lowercase scheme and host, no
	 * default port, no trailing slash, query kept (plain permalinks put the
	 * route in ?rest_route=). Null when the value is not an absolute URI or
	 * carries a fragment.
	 */
	public static function normalize_resource( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		$p = parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) || isset( $p['fragment'] ) ) {
			return null;
		}
		$scheme = strtolower( $p['scheme'] );
		$host   = strtolower( $p['host'] );
		$port   = isset( $p['port'] ) ? (int) $p['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		$path = rtrim( $p['path'] ?? '', '/' );
		if ( '' === $path && isset( $p['query'] ) && '' !== $p['query'] ) {
			$path = '/';
		}
		$out  = $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path;
		if ( isset( $p['query'] ) && '' !== $p['query'] ) {
			$out .= '?' . $p['query'];
		}
		return $out;
	}

	/**
	 * Check a `resource` parameter against this server. Absent is fine (the
	 * parameter is a client MUST, but tokens are only ever valid here anyway).
	 * An https resource is accepted for an http canonical URL with the same
	 * host and path (a site behind a TLS-terminating proxy whose home URL is
	 * still http); the reverse is refused. The caller logs upgrades.
	 *
	 * @return array{ok:bool,upgraded:bool}
	 */
	public static function resource_check( ?string $resource ): array {
		if ( null === $resource || '' === trim( $resource ) ) {
			return [ 'ok' => true, 'upgraded' => false ];
		}
		$given = self::normalize_resource( $resource );
		$canon = self::normalize_resource( self::canonical_resource() );
		if ( null === $given || null === $canon ) {
			return [ 'ok' => false, 'upgraded' => false ];
		}
		if ( $given === $canon ) {
			return [ 'ok' => true, 'upgraded' => false ];
		}
		if ( str_starts_with( $canon, 'http://' ) && str_starts_with( $given, 'https://' ) && substr( $canon, 7 ) === substr( $given, 8 ) ) {
			return [ 'ok' => true, 'upgraded' => true ];
		}
		return [ 'ok' => false, 'upgraded' => false ];
	}

	// ── Redirect URIs ─────────────────────────────────────────────────────

	public static function is_loopback_host( string $host ): bool {
		return in_array( strtolower( $host ), self::LOOPBACK_HOSTS, true );
	}

	/**
	 * Registration-time rule (MCP spec, Security Considerations): https, or
	 * plain http to a loopback host. No fragment, no whitespace or control
	 * characters, no credentials. Validated before any WordPress escaping so
	 * the stored value is exactly what the client registered.
	 */
	public static function validate_redirect_uri( string $uri ): bool {
		if ( '' === $uri || strlen( $uri ) > 2000 || preg_match( '/[\s\x00-\x1f\x7f]/', $uri ) ) {
			return false;
		}
		$p = parse_url( $uri );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) || isset( $p['fragment'] ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( $p['scheme'] );
		if ( 'https' === $scheme ) {
			return true;
		}
		return 'http' === $scheme && self::is_loopback_host( $p['host'] );
	}

	/**
	 * Authorization-time match: exact, or port-agnostic when both URIs are
	 * plain http to the same loopback host with the same path and query
	 * (RFC 8252 §7.3; Claude Code picks an ephemeral port per session).
	 */
	public static function redirect_uri_matches( string $registered, string $given ): bool {
		if ( $registered === $given ) {
			return true;
		}
		$a = parse_url( $registered );
		$b = parse_url( $given );
		if ( ! is_array( $a ) || ! is_array( $b ) ) {
			return false;
		}
		if ( 'http' !== strtolower( $a['scheme'] ?? '' ) || 'http' !== strtolower( $b['scheme'] ?? '' ) ) {
			return false;
		}
		$host_a = strtolower( $a['host'] ?? '' );
		$host_b = strtolower( $b['host'] ?? '' );
		if ( '' === $host_a || $host_a !== $host_b || ! self::is_loopback_host( $host_a ) ) {
			return false;
		}
		if ( isset( $a['user'] ) || isset( $b['user'] ) || isset( $a['fragment'] ) || isset( $b['fragment'] ) ) {
			return false;
		}
		return ( $a['path'] ?? '' ) === ( $b['path'] ?? '' ) && ( $a['query'] ?? null ) === ( $b['query'] ?? null );
	}

	/** Append query parameters to a redirect URI that may already carry a query. */
	public static function append_query( string $url, array $params ): string {
		$qs = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		if ( '' === $qs ) {
			return $url;
		}
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $qs;
	}

	// ── Dynamic Client Registration (RFC 7591) ──────────────────────────────

	private static function handle_register(): void {
		if ( 'POST' !== self::request_method() ) {
			self::json_response( [ 'error' => 'invalid_request', 'error_description' => 'POST required' ], 405 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- raw request body.
		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) ) {
			self::json_response( [ 'error' => 'invalid_request', 'error_description' => 'Invalid JSON body' ], 400 );
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$res = self::register_response( $body, $ip, time() );
		self::json_response( $res['body'], $res['status'], $res['headers'] ?? [] );
	}

	/**
	 * @param array<string,mixed> $body decoded registration request
	 * @return array{status:int,body:array,headers?:array<string,string>}
	 */
	public static function register_response( array $body, string $ip, int $now ): array {
		$wait = self::registration_rate_limit( $ip, $now );
		if ( $wait > 0 ) {
			return [
				'status'  => 429,
				'body'    => [ 'error' => 'invalid_request', 'error_description' => 'Too many client registrations. Retry later.' ],
				'headers' => [ 'Retry-After' => (string) $wait ],
			];
		}

		$uris = $body['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || empty( $uris ) ) {
			return [ 'status' => 400, 'body' => [ 'error' => 'invalid_client_metadata', 'error_description' => 'redirect_uris required' ] ];
		}
		if ( count( $uris ) > self::MAX_REDIRECT_URIS ) {
			return [ 'status' => 400, 'body' => [ 'error' => 'invalid_client_metadata', 'error_description' => 'at most ' . self::MAX_REDIRECT_URIS . ' redirect_uris are accepted' ] ];
		}
		$clean = [];
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! self::validate_redirect_uri( $uri ) ) {
				return [
					'status' => 400,
					'body'   => [
						'error'             => 'invalid_redirect_uri',
						'error_description' => 'redirect_uris must use https, or http to localhost / 127.0.0.1 / [::1], without a fragment',
					],
				];
			}
			$clean[ $uri ] = true;
		}
		$clean = array_keys( $clean );

		$auth_method = $body['token_endpoint_auth_method'] ?? 'none';
		if ( 'none' !== $auth_method ) {
			return [ 'status' => 400, 'body' => [ 'error' => 'invalid_client_metadata', 'error_description' => 'only token_endpoint_auth_method "none" is supported (public clients)' ] ];
		}

		$name = sanitize_text_field( (string) ( $body['client_name'] ?? '' ) );
		$name = mb_substr( $name, 0, 100 );
		if ( '' === $name ) {
			$name = 'Unknown client';
		}

		$store = IATO_MCP_OAuth_Store::instance();
		$store->prune_clients( self::ts( $now - self::CLIENT_PENDING_TTL ), self::ts( $now - self::CLIENT_IDLE_TTL ) );

		$client_id = self::random_string( 24 );
		$stored    = $store->insert_client( [
			'client_id'       => $client_id,
			'client_name'     => $name,
			'redirect_uris'   => $clean,
			'registration_ip' => '' !== $ip ? substr( $ip, 0, 45 ) : null,
			'created_at'      => self::ts( $now ),
		] );
		if ( ! $stored ) {
			return [ 'status' => 500, 'body' => [ 'error' => 'server_error', 'error_description' => 'the client registration could not be stored' ] ];
		}

		return [
			'status' => 201,
			'body'   => [
				'client_id'                  => $client_id,
				'client_id_issued_at'        => $now,
				'client_name'                => $name,
				'redirect_uris'              => $clean,
				'grant_types'                => self::GRANT_TYPES,
				'response_types'             => self::RESPONSE_TYPES,
				'token_endpoint_auth_method' => 'none',
			],
		];
	}

	/**
	 * Fixed-window counters in transients: one per source IP, one for the
	 * site. Returns the seconds to wait, 0 when the registration may proceed.
	 * Behind a reverse proxy REMOTE_ADDR is the proxy, so the IP bucket is
	 * effectively a second site-wide bucket; the limits are filterable.
	 */
	private static function registration_rate_limit( string $ip, int $now ): int {
		$limits  = apply_filters( 'iato_mcp_oauth_registration_limits', self::REGISTRATION_LIMITS );
		$buckets = [
			'iato_mcp_oauth_reg_' . md5( $ip ) => $limits['ip'] ?? self::REGISTRATION_LIMITS['ip'],
			'iato_mcp_oauth_reg_site'          => $limits['site'] ?? self::REGISTRATION_LIMITS['site'],
		];
		foreach ( $buckets as $key => $limit ) {
			[ $max, $window ] = $limit;
			if ( $max <= 0 ) {
				continue;
			}
			$b = get_transient( $key );
			if ( ! is_array( $b ) || (int) ( $b['until'] ?? 0 ) <= $now ) {
				$b = [ 'n' => 0, 'until' => $now + (int) $window ];
			}
			if ( (int) $b['n'] >= (int) $max ) {
				return max( 1, (int) $b['until'] - $now );
			}
			$b['n'] = (int) $b['n'] + 1;
			set_transient( $key, $b, max( 1, (int) $b['until'] - $now ) );
		}
		return 0;
	}

	// ── Authorize ─────────────────────────────────────────────────────────

	private static function handle_authorize(): void {
		$method = self::request_method();
		if ( 'GET' !== $method && 'POST' !== $method ) {
			self::json_response( [ 'error' => 'invalid_request' ], 405 );
		}

		// Not logged in: to wp-login and back here. Logged in without
		// manage_options: a 403 page, not a login loop.
		if ( ! is_user_logged_in() ) {
			// wp_unslash only — sanitize_text_field strips %XX percent-encoded sequences
			// (HTML-entity defense), which would corrupt the inner redirect_uri parameter.
			// REQUEST_URI is server-set and consumed only as a redirect target via
			// wp_login_url() + wp_safe_redirect() (which validates the host).
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			wp_safe_redirect( wp_login_url( self::login_return_url( $request_uri ) ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::render_forbidden_screen();
		}

		// OAuth params live in the query string on both GET and POST: the
		// consent form posts back to its own URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only parse; the POST branch checks the nonce.
		$query = is_array( $_GET ) ? wp_unslash( $_GET ) : [];
		$check = self::validate_authorize_request( $query );

		if ( 'json' === $check['kind'] ) {
			self::json_response( $check['body'], $check['status'] );
		}
		if ( 'redirect' === $check['kind'] ) {
			self::redirect_to_client( $check['url'] );
		}

		$params = $check['params'];
		$client = $check['client'];

		if ( 'POST' === $method ) {
			check_admin_referer( self::consent_nonce_action( $params ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just above.
			$decision = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( $_POST['decision'] ) ) : 'approve';
			if ( 'deny' === $decision ) {
				self::redirect_to_client( self::deny( $params ) );
			}
			self::redirect_to_client( self::approve( $params, (int) get_current_user_id(), time() ) );
		}

		self::render_authorize_screen( $client, $params, wp_get_current_user() );
	}

	/**
	 * Validate an authorization request. Until the client and its redirect
	 * URI are verified, errors are returned as JSON (never redirect to an
	 * unverified URI); after that, protocol errors go back to the client by
	 * redirect with error, error_description, state and iss.
	 *
	 * @param array<string,mixed> $q query parameters
	 * @return array{kind:'ok',client:array,params:array}|array{kind:'json',status:int,body:array}|array{kind:'redirect',url:string}
	 */
	public static function validate_authorize_request( array $q ): array {
		$client_id    = sanitize_text_field( (string) ( $q['client_id'] ?? '' ) );
		$redirect_uri = trim( (string) ( $q['redirect_uri'] ?? '' ) );

		if ( '' === $client_id || '' === $redirect_uri ) {
			return [ 'kind' => 'json', 'status' => 400, 'body' => [ 'error' => 'invalid_request', 'error_description' => 'client_id and redirect_uri required' ] ];
		}

		$client = IATO_MCP_OAuth_Store::instance()->find_client( $client_id );
		if ( null === $client ) {
			return [
				'kind'   => 'json',
				'status' => 400,
				'body'   => [
					'error'             => 'invalid_client',
					'error_description' => 'client_id is not registered. Use the dynamic client registration endpoint at /oauth/register first.',
				],
			];
		}

		$matched = false;
		foreach ( $client['redirect_uris'] as $registered ) {
			if ( self::redirect_uri_matches( $registered, $redirect_uri ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched || ! self::validate_redirect_uri( $redirect_uri ) ) {
			return [ 'kind' => 'json', 'status' => 400, 'body' => [ 'error' => 'invalid_request', 'error_description' => 'redirect_uri not registered' ] ];
		}

		// state is opaque to us; keep printable ASCII only and never alter it.
		$state = (string) ( $q['state'] ?? '' );
		if ( ! preg_match( '/^[\x20-\x7e]{0,512}$/', $state ) ) {
			return [ 'kind' => 'redirect', 'url' => self::error_redirect( $redirect_uri, 'invalid_request', 'state must be printable ASCII, at most 512 characters', '' ) ];
		}

		$params = [
			'client_id'         => $client_id,
			'redirect_uri'      => $redirect_uri,
			'state'             => $state,
			'code_challenge'    => '',
			'resource'          => '',
			'resource_upgraded' => false,
		];

		if ( 'code' !== (string) ( $q['response_type'] ?? '' ) ) {
			return [ 'kind' => 'redirect', 'url' => self::error_redirect( $redirect_uri, 'unsupported_response_type', 'response_type must be code', $state ) ];
		}

		// PKCE is mandatory (MCP spec) and S256 only: `plain` sends the
		// verifier in the clear at authorization time.
		$challenge = (string) ( $q['code_challenge'] ?? '' );
		$cc_method = (string) ( $q['code_challenge_method'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $challenge ) ) {
			return [ 'kind' => 'redirect', 'url' => self::error_redirect( $redirect_uri, 'invalid_request', 'code_challenge is required (PKCE, S256)', $state ) ];
		}
		if ( 'S256' !== $cc_method ) {
			return [ 'kind' => 'redirect', 'url' => self::error_redirect( $redirect_uri, 'invalid_request', 'code_challenge_method must be S256', $state ) ];
		}
		$params['code_challenge'] = $challenge;

		$resource = (string) ( $q['resource'] ?? '' );
		$rc       = self::resource_check( $resource );
		if ( ! $rc['ok'] ) {
			return [ 'kind' => 'redirect', 'url' => self::error_redirect( $redirect_uri, 'invalid_target', 'resource does not identify this MCP server', $state ) ];
		}
		$params['resource']          = $resource;
		$params['resource_upgraded'] = $rc['upgraded'];

		return [ 'kind' => 'ok', 'client' => $client, 'params' => $params ];
	}

	/**
	 * Record the administrator's approval: one grant, one single-use code.
	 * Returns the redirect URL carrying code, state and iss.
	 *
	 * @param array{client_id:string,redirect_uri:string,state:string,code_challenge:string,resource:string,resource_upgraded:bool} $params
	 */
	public static function approve( array $params, int $user_id, int $now ): string {
		$store    = IATO_MCP_OAuth_Store::instance();
		$grant_id = self::random_hex( 16 );
		$code     = self::random_string( 32 );

		$recorded = $store->insert_grant( [
			'grant_id'     => $grant_id,
			'client_id'    => $params['client_id'],
			'user_id'      => $user_id,
			'redirect_uri' => $params['redirect_uri'],
			'granted_at'   => self::ts( $now ),
		] );
		if ( ! $recorded ) {
			return self::error_redirect( $params['redirect_uri'], 'server_error', 'the authorization could not be recorded', $params['state'] );
		}
		$store->insert_token( [
			'kind'           => 'code',
			'token_hash'     => self::hash_token( $code ),
			'grant_id'       => $grant_id,
			'client_id'      => $params['client_id'],
			'user_id'        => $user_id,
			'redirect_uri'   => $params['redirect_uri'],
			'code_challenge' => $params['code_challenge'],
			'granted_at'     => self::ts( $now ),
			'created_at'     => self::ts( $now ),
			'expires_at'     => self::ts( $now + self::CODE_TTL ),
		] );
		$store->mark_client_authorized( $params['client_id'], self::ts( $now ) );

		self::log( 'grant', [ 'client_id' => $params['client_id'], 'grant_id' => $grant_id, 'redirect_host' => self::redirect_host( $params['redirect_uri'] ) ], $user_id );
		if ( ! empty( $params['resource_upgraded'] ) ) {
			self::log( 'resource_scheme_upgrade', [ 'client_id' => $params['client_id'], 'resource' => $params['resource'] ], $user_id );
		}

		$out = [ 'code' => $code ];
		if ( '' !== $params['state'] ) {
			$out['state'] = $params['state'];
		}
		$out['iss'] = self::issuer();
		return self::append_query( $params['redirect_uri'], $out );
	}

	/** @param array{redirect_uri:string,state:string} $params */
	public static function deny( array $params ): string {
		return self::error_redirect( $params['redirect_uri'], 'access_denied', 'The administrator denied the request', $params['state'] );
	}

	private static function error_redirect( string $redirect_uri, string $error, string $description, string $state ): string {
		$out = [ 'error' => $error, 'error_description' => $description ];
		if ( '' !== $state ) {
			$out['state'] = $state;
		}
		$out['iss'] = self::issuer();
		return self::append_query( $redirect_uri, $out );
	}

	private static function redirect_to_client( string $url ): void {
		// wp_redirect (not wp_safe_redirect): the redirect_uri is the OAuth client's
		// external callback by protocol design and was validated against the
		// client's registration above, so this is not an open redirect.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	// ── Token endpoint ────────────────────────────────────────────────────

	private static function handle_token(): void {
		if ( 'POST' !== self::request_method() ) {
			self::json_response( [ 'error' => 'invalid_request' ], 405 );
		}
		$res = self::token_response( self::read_post_input(), time() );
		self::json_response( $res['body'], $res['status'] );
	}

	/**
	 * @param array<string,mixed> $input form or JSON fields
	 * @return array{status:int,body:array}
	 */
	public static function token_response( array $input, int $now ): array {
		$grant_type = sanitize_text_field( (string) ( $input['grant_type'] ?? '' ) );
		$client_id  = sanitize_text_field( (string) ( $input['client_id'] ?? '' ) );
		$resource   = (string) ( $input['resource'] ?? '' );
		$store      = IATO_MCP_OAuth_Store::instance();

		if ( 'authorization_code' === $grant_type ) {
			$code = trim( (string) ( $input['code'] ?? '' ) );
			$row  = '' === $code ? null : $store->find_token( self::hash_token( $code ) );
			if ( null === $row || 'code' !== $row['kind'] ) {
				return self::error( 'invalid_grant', 'unknown authorization code' );
			}
			if ( self::grant_is_dead( $row ) ) {
				return self::error( 'invalid_grant', 'authorization revoked' );
			}
			// Consumed on first presentation, success or failure. The UPDATE
			// is the atomic claim: zero rows means another request got here
			// first, i.e. a replay. Whoever holds the code now also holds, or is
			// trying to steal, the tokens it produced (OAuth 2.1 §4.1.3).
			if ( 0 === $store->mark_used( $row['id'], self::ts( $now ) ) ) {
				$store->revoke_grant( $row['grant_id'], self::ts( $now ), 'code_replay' );
				self::log( 'revoke', [ 'reason' => 'code_replay', 'client_id' => $row['client_id'], 'grant_id' => $row['grant_id'] ], $row['user_id'], 'error', 'code_replay' );
				return self::error( 'invalid_grant', 'authorization code already used' );
			}

			if ( null !== $row['revoked_at'] || $row['expires_at'] <= self::ts( $now ) ) {
				return self::error( 'invalid_grant', 'authorization code expired' );
			}
			$failure = self::verify_pkce(
				[
					'code_challenge'        => (string) $row['code_challenge'],
					'code_challenge_method' => 'S256',
					'client_id'             => (string) $row['client_id'],
					'redirect_uri'          => (string) $row['redirect_uri'],
				],
				$client_id,
				trim( (string) ( $input['redirect_uri'] ?? '' ) ),
				(string) ( $input['code_verifier'] ?? '' )
			);
			if ( null !== $failure ) {
				return self::error( 'invalid_grant', $failure );
			}
			$rc = self::resource_check( $resource );
			if ( ! $rc['ok'] ) {
				return self::error( 'invalid_target', 'resource does not identify this MCP server' );
			}
			$admin = IATO_MCP_Auth::administrator_state( $row['user_id'] );
			if ( 'ok' !== $admin['state'] ) {
				$store->revoke_grant( $row['grant_id'], self::ts( $now ), 'user_' . $admin['state'] );
				self::log( 'revoke', [ 'reason' => 'user_' . $admin['state'], 'client_id' => $row['client_id'], 'grant_id' => $row['grant_id'] ], $row['user_id'], 'error', 'user_' . $admin['state'] );
				return self::error( 'invalid_grant', 'the approving user no longer exists or is no longer an administrator' );
			}
			if ( $rc['upgraded'] ) {
				self::log( 'resource_scheme_upgrade', [ 'client_id' => $row['client_id'], 'resource' => $resource, 'at' => 'token' ], $row['user_id'] );
			}
			return [ 'status' => 200, 'body' => self::issue_tokens( $store, $row, $now ) ];
		}

		if ( 'refresh_token' === $grant_type ) {
			$rt  = trim( (string) ( $input['refresh_token'] ?? '' ) );
			$row = '' === $rt ? null : $store->find_token( self::hash_token( $rt ) );
			if ( null === $row || 'refresh' !== $row['kind'] ) {
				return self::error( 'invalid_grant', 'unknown refresh token' );
			}
			if ( (string) $row['client_id'] !== $client_id ) {
				return self::error( 'invalid_grant', 'refresh token was issued to a different client' );
			}
			if ( self::grant_is_dead( $row ) || null !== $row['revoked_at'] ) {
				return self::error( 'invalid_grant', 'refresh token revoked' );
			}
			// Atomic claim (see the code branch): zero rows means the token was
			// already rotated, i.e. reuse (OAuth 2.1 §4.3.1). The whole grant
			// is revoked, which also kills the pair the first use produced.
			if ( 0 === $store->mark_used( $row['id'], self::ts( $now ) ) ) {
				$store->revoke_grant( $row['grant_id'], self::ts( $now ), 'refresh_reuse' );
				self::log( 'revoke', [ 'reason' => 'refresh_reuse', 'client_id' => $row['client_id'], 'grant_id' => $row['grant_id'] ], $row['user_id'], 'error', 'refresh_reuse' );
				return self::error( 'invalid_grant', 'refresh token already used' );
			}
			if ( $row['expires_at'] <= self::ts( $now ) ) {
				return self::error( 'invalid_grant', 'refresh token expired' );
			}
			$lifetime   = (int) apply_filters( 'iato_mcp_oauth_grant_lifetime', self::GRANT_LIFETIME );
			$granted_at = (string) ( $row['grant_granted_at'] ?? $row['granted_at'] ?? $row['created_at'] );
			if ( $lifetime > 0 && self::to_time( $granted_at ) + $lifetime <= $now ) {
				$store->revoke_grant( $row['grant_id'], self::ts( $now ), 'grant_lifetime' );
				self::log( 'revoke', [ 'reason' => 'grant_lifetime', 'client_id' => $row['client_id'], 'grant_id' => $row['grant_id'] ], $row['user_id'], 'error', 'grant_lifetime' );
				return self::error( 'invalid_grant', 'authorization expired; approve the connection again' );
			}
			$admin = IATO_MCP_Auth::administrator_state( $row['user_id'] );
			if ( 'ok' !== $admin['state'] ) {
				$store->revoke_grant( $row['grant_id'], self::ts( $now ), 'user_' . $admin['state'] );
				self::log( 'revoke', [ 'reason' => 'user_' . $admin['state'], 'client_id' => $row['client_id'], 'grant_id' => $row['grant_id'] ], $row['user_id'], 'error', 'user_' . $admin['state'] );
				return self::error( 'invalid_grant', 'the approving user no longer exists or is no longer an administrator' );
			}
			$rc = self::resource_check( $resource );
			if ( ! $rc['ok'] ) {
				return self::error( 'invalid_target', 'resource does not identify this MCP server' );
			}
			if ( $rc['upgraded'] ) {
				self::log( 'resource_scheme_upgrade', [ 'client_id' => $row['client_id'], 'resource' => $resource, 'at' => 'refresh' ], $row['user_id'] );
			}
			// Rotate: the old refresh token was claimed above, the old access tokens die.
			$store->revoke_grant_access_tokens( $row['grant_id'], self::ts( $now ) );
			return [ 'status' => 200, 'body' => self::issue_tokens( $store, $row, $now ) ];
		}

		return [ 'status' => 400, 'body' => [ 'error' => 'unsupported_grant_type' ] ];
	}

	/**
	 * Issue a fresh access + refresh pair for a grant and prune old rows.
	 * $from is the code row (first exchange) or the spent refresh row
	 * (rotation); grant identity, consent time and redirect URI carry over.
	 */
	private static function issue_tokens( IATO_MCP_OAuth_Store $store, array $from, int $now ): array {
		$access  = IATO_MCP_Auth::OAUTH_ACCESS_PREFIX . self::random_string( 32 );
		$refresh = IATO_MCP_Auth::OAUTH_REFRESH_PREFIX . self::random_string( 32 );

		$base = [
			'grant_id'     => (string) $from['grant_id'],
			'client_id'    => (string) $from['client_id'],
			'user_id'      => (int) $from['user_id'],
			'redirect_uri' => $from['redirect_uri'] ?? null,
			'granted_at'   => (string) ( $from['granted_at'] ?? $from['created_at'] ),
			'created_at'   => self::ts( $now ),
		];
		$store->insert_token( $base + [ 'kind' => 'access', 'token_hash' => self::hash_token( $access ), 'expires_at' => self::ts( $now + self::ACCESS_TTL ) ] );
		$store->insert_token( $base + [ 'kind' => 'refresh', 'token_hash' => self::hash_token( $refresh ), 'expires_at' => self::ts( $now + self::REFRESH_TTL ) ] );
		$store->prune_tokens( self::ts( $now - self::TOKEN_RETENTION ) );

		return [
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
		];
	}

	// ── Revocation (RFC 7009) ─────────────────────────────────────────────

	private static function handle_revoke(): void {
		if ( 'POST' !== self::request_method() ) {
			self::json_response( [ 'error' => 'invalid_request' ], 405 );
		}
		$res = self::revoke_response( self::read_post_input(), time() );
		self::json_response( $res['body'], $res['status'] );
	}

	/**
	 * Revoking either token of a grant revokes the grant. Always 200: the
	 * endpoint does not reveal whether a token existed (RFC 7009 §2.2).
	 *
	 * @param array<string,mixed> $input
	 * @return array{status:int,body:array}
	 */
	public static function revoke_response( array $input, int $now ): array {
		$token     = trim( (string) ( $input['token'] ?? '' ) );
		$client_id = sanitize_text_field( (string) ( $input['client_id'] ?? '' ) );
		if ( '' === $token ) {
			return [ 'status' => 200, 'body' => (object) [] ];
		}
		$store = IATO_MCP_OAuth_Store::instance();
		$row   = $store->find_token( self::hash_token( $token ) );
		if ( null !== $row && in_array( $row['kind'], [ 'access', 'refresh' ], true ) && ( '' === $client_id || (string) $row['client_id'] === $client_id ) ) {
			if ( $store->revoke_grant( $row['grant_id'], self::ts( $now ), 'endpoint' ) > 0 ) {
				self::log( 'revoke', [ 'reason' => 'endpoint', 'client_id' => $row['client_id'], 'grant_id' => $row['grant_id'] ], $row['user_id'] );
			}
		}
		return [ 'status' => 200, 'body' => (object) [] ];
	}

	/** Revoke one grant from the Settings screen. Returns false when nothing was live. */
	public static function revoke_grant_by_admin( string $grant_id, int $admin_id, int $now ): bool {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $grant_id ) ) {
			return false;
		}
		$store  = IATO_MCP_OAuth_Store::instance();
		$origin = $store->find_grant( $grant_id );
		$n      = $store->revoke_grant( $grant_id, self::ts( $now ), 'admin' );
		if ( $n > 0 ) {
			self::log( 'revoke', [ 'reason' => 'admin', 'by' => $admin_id, 'client_id' => $origin['client_id'] ?? '', 'grant_id' => $grant_id ], $origin['user_id'] ?? 0 );
		}
		return $n > 0;
	}

	/** "Disconnect all" from the Settings screen. Returns the number of grants that were live. */
	public static function revoke_all_grants_by_admin( int $admin_id, int $now ): int {
		$n = IATO_MCP_OAuth_Store::instance()->revoke_all_grants( self::ts( $now ), self::ts( $now ), 'admin_all' );
		if ( $n > 0 ) {
			self::log( 'revoke', [ 'reason' => 'admin_all', 'by' => $admin_id, 'grants' => $n ], $admin_id );
		}
		return $n;
	}

	/** Revoke every grant a deleted user approved (`deleted_user` hook). */
	public static function on_deleted_user( int $user_id ): void {
		$n = IATO_MCP_OAuth_Store::instance()->revoke_user_grants( $user_id, self::ts( time() ), 'user_deleted' );
		if ( $n > 0 ) {
			self::log( 'revoke', [ 'reason' => 'user_deleted', 'user_id' => $user_id, 'rows' => $n ], $user_id );
		}
	}

	// ── PKCE ──────────────────────────────────────────────────────────────

	/**
	 * PKCE check for the token endpoint (RFC 7636 §4.6), given the challenge
	 * stored at authorization time. Returns null when the exchange may proceed,
	 * otherwise the error_description to return with invalid_grant. Pure, so it
	 * is unit-tested directly. S256 is the only accepted method.
	 *
	 * @param array{code_challenge:string,code_challenge_method:string,client_id:string,redirect_uri:string} $pkce
	 */
	public static function verify_pkce( array $pkce, string $client_id, string $redirect_uri, string $code_verifier ): ?string {
		if ( ( $pkce['client_id'] ?? '' ) !== $client_id ) {
			return 'client_id does not match the authorization request';
		}
		if ( ( $pkce['redirect_uri'] ?? '' ) !== $redirect_uri ) {
			return 'redirect_uri does not match the authorization request';
		}
		if ( '' === $code_verifier ) {
			return 'code_verifier is required: a code_challenge was issued for this authorization';
		}
		// S256 only, mirroring the authorize endpoint; a stored `plain`
		// challenge (from a pre-patch authorization) fails rather than being
		// compared in the clear.
		if ( 'S256' !== (string) ( $pkce['code_challenge_method'] ?? 'S256' ) ) {
			return 'unsupported code_challenge_method';
		}
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( (string) ( $pkce['code_challenge'] ?? '' ), $expected ) ) {
			return 'PKCE verification failed';
		}
		return null;
	}

	// ── Consent screen ────────────────────────────────────────────────────

	/**
	 * Return the inline CSS for the authorize screen.
	 */
	private static function get_authorize_styles(): string {
		return <<<'CSS'
				:root {
					--iato-primary: #1e40af;
					--iato-primary-hover: #1e3a8a;
					--iato-primary-light: #dbeafe;
					--iato-text: #0f172a;
					--iato-text-secondary: #475569;
					--iato-text-muted: #94a3b8;
					--iato-border: #e2e8f0;
					--iato-bg: #f1f5f9;
					--iato-success: #16a34a;
					--iato-warning: #b45309;
					--iato-warning-bg: #fef3c7;
				}
				* { box-sizing: border-box; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
					display: flex;
					justify-content: center;
					align-items: center;
					min-height: 100vh;
					margin: 0;
					background: var(--iato-bg);
					color: var(--iato-text);
				}
				.auth-card {
					background: #fff;
					padding: 32px;
					border-radius: 16px;
					box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
					max-width: 440px;
					width: 100%;
					margin: 20px;
				}
				.auth-brand {
					font-size: 22px;
					font-weight: 700;
					color: var(--iato-primary);
					letter-spacing: -0.5px;
					margin-bottom: 20px;
					display: flex;
					align-items: center;
					gap: 8px;
				}
				.auth-brand img {
					height: 32px;
					width: auto;
				}
				.auth-brand span {
					font-weight: 400;
					color: var(--iato-text-secondary);
				}
				.auth-title {
					font-size: 18px;
					font-weight: 600;
					margin: 0 0 8px;
					color: var(--iato-text);
				}
				.auth-desc {
					font-size: 14px;
					color: var(--iato-text-secondary);
					margin: 0 0 20px;
					line-height: 1.5;
				}
				.auth-desc strong {
					color: var(--iato-text);
				}
				.auth-client {
					border: 1px solid var(--iato-border);
					border-radius: 10px;
					padding: 12px 16px;
					margin-bottom: 16px;
					font-size: 14px;
				}
				.auth-client div { padding: 4px 0; }
				.auth-client .auth-k {
					display: inline-block;
					width: 110px;
					color: var(--iato-text-secondary);
					font-size: 12px;
					text-transform: uppercase;
					letter-spacing: 0.5px;
				}
				.auth-client code {
					font-size: 14px;
					font-weight: 600;
					color: var(--iato-text);
					background: var(--iato-bg);
					padding: 2px 6px;
					border-radius: 4px;
				}
				.auth-warning {
					background: var(--iato-warning-bg);
					color: var(--iato-warning);
					border-radius: 8px;
					padding: 10px 14px;
					font-size: 13px;
					margin-bottom: 16px;
					line-height: 1.45;
				}
				.auth-permissions {
					background: var(--iato-bg);
					border: 1px solid var(--iato-border);
					border-radius: 10px;
					padding: 16px 20px;
					margin-bottom: 24px;
				}
				.auth-permissions h3 {
					font-size: 12px;
					font-weight: 600;
					text-transform: uppercase;
					letter-spacing: 0.5px;
					color: var(--iato-text-secondary);
					margin: 0 0 12px;
				}
				.auth-permissions p {
					font-size: 14px;
					margin: 0 0 8px;
					line-height: 1.5;
				}
				.auth-permissions p:last-child { margin-bottom: 0; }
				.auth-permissions ul {
					list-style: none;
					margin: 0;
					padding: 0;
				}
				.auth-permissions li {
					font-size: 14px;
					color: var(--iato-text);
					padding: 6px 0;
					display: flex;
					align-items: center;
					gap: 10px;
				}
				.auth-permissions li::before {
					content: '\2713';
					display: inline-flex;
					align-items: center;
					justify-content: center;
					width: 20px;
					height: 20px;
					background: #dcfce7;
					color: var(--iato-success);
					border-radius: 50%;
					font-size: 12px;
					font-weight: 700;
					flex-shrink: 0;
				}
				.auth-actions {
					display: flex;
					gap: 10px;
					margin-top: 0;
				}
				.auth-btn {
					display: inline-flex;
					align-items: center;
					justify-content: center;
					padding: 10px 24px;
					border-radius: 8px;
					font-size: 14px;
					font-weight: 600;
					cursor: pointer;
					text-decoration: none;
					transition: background 0.15s, color 0.15s, box-shadow 0.15s;
					border: none;
					font-family: inherit;
				}
				.auth-btn--primary {
					flex: 1;
					background: var(--iato-primary);
					color: #fff;
				}
				.auth-btn--primary:hover {
					background: var(--iato-primary-hover);
					box-shadow: 0 2px 8px rgba(30, 64, 175, 0.3);
				}
				.auth-btn--secondary {
					padding: 10px 20px;
					background: #fff;
					color: var(--iato-text-secondary);
					border: 1px solid var(--iato-border);
				}
				.auth-btn--secondary:hover {
					background: var(--iato-bg);
					color: var(--iato-text);
				}
				.auth-footer {
					text-align: center;
					margin-top: 20px;
					padding-top: 16px;
					border-top: 1px solid var(--iato-border);
					font-size: 12px;
					color: var(--iato-text-muted);
				}
CSS;
	}

	/**
	 * The consent screen. Shows the client name, the redirect host, when the
	 * client registered, and the administrator the connection will act as.
	 *
	 * @param array<string,mixed> $client stored client row
	 * @param array<string,mixed> $params validated authorization parameters
	 */
	private static function render_authorize_screen( array $client, array $params, WP_User $approver ): void {
		$site_name     = sanitize_text_field( get_bloginfo( 'name' ) );
		$settings_url  = admin_url( 'options-general.php?page=iato-mcp' );
		$client_name   = (string) ( $client['client_name'] ?? '' );
		$redirect_host = self::redirect_host( (string) $params['redirect_uri'] );
		$loopback      = self::is_loopback_host( (string) ( parse_url( (string) $params['redirect_uri'], PHP_URL_HOST ) ?? '' ) );
		$registered_at = self::format_local( (string) ( $client['created_at'] ?? '' ) );

		// Never render the consent form inside a frame. Client registration is
		// open, so an attacker could otherwise register a client, frame this
		// screen and clickjack a logged-in administrator into Approve. The
		// nonce on the form stops CSRF but not clickjacking. Both headers:
		// X-Frame-Options for browsers without CSP, frame-ancestors for the rest.
		//
		// headers_sent() guard: this runs on `init` at priority 1, before any
		// theme or template output, so headers can normally still be sent. If
		// another plugin has already emitted output, header() would only raise
		// a warning; the guard keeps that warning out of the response.
		if ( ! headers_sent() ) {
			header( 'X-Frame-Options: DENY' );
			header( "Content-Security-Policy: frame-ancestors 'none'" );
			nocache_headers();
		}

		wp_register_style( 'iato-mcp-oauth', false, [], IATO_MCP_VERSION );
		wp_enqueue_style( 'iato-mcp-oauth' );
		wp_add_inline_style( 'iato-mcp-oauth', self::get_authorize_styles() );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php
			/* translators: %s: site name */
			printf( esc_html__( 'Authorize — %s', 'iato-mcp' ), esc_html( $site_name ) );
			?></title>
			<?php wp_print_styles( 'iato-mcp-oauth' ); ?>
		</head>
		<body>
			<div class="auth-card">
				<div class="auth-brand"><?php echo iato_mcp_logo_svg( 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns self-escaped <img> markup (attributes wrapped in esc_attr inside the helper); fallback is a static <span>. ?> <span>MCP</span></div>
				<h2 class="auth-title"><?php esc_html_e( 'Authorize Application', 'iato-mcp' ); ?></h2>
				<p class="auth-desc">
					<?php
					printf(
						/* translators: %1$s: client/application name, %2$s: site name */
						esc_html__( '%1$s wants to connect to %2$s via the Model Context Protocol.', 'iato-mcp' ),
						'<strong>' . esc_html( $client_name ) . '</strong>',
						'<strong>' . esc_html( $site_name ) . '</strong>'
					);
					?>
				</p>
				<div class="auth-client">
					<div><span class="auth-k"><?php esc_html_e( 'Application', 'iato-mcp' ); ?></span> <code><?php echo esc_html( $client_name ); ?></code></div>
					<div><span class="auth-k"><?php esc_html_e( 'Sends you to', 'iato-mcp' ); ?></span> <code><?php echo esc_html( $redirect_host ); ?></code></div>
					<?php if ( '' !== $registered_at ) : ?>
						<div><span class="auth-k"><?php esc_html_e( 'Registered', 'iato-mcp' ); ?></span> <?php echo esc_html( $registered_at ); ?></div>
					<?php endif; ?>
				</div>
				<?php if ( $loopback ) : ?>
					<div class="auth-warning">
						<?php esc_html_e( 'This application runs on this computer (the redirect goes to a local port). Any program on this machine could present itself this way. Approve only if you started this connection yourself just now.', 'iato-mcp' ); ?>
					</div>
				<?php endif; ?>
				<div class="auth-permissions">
					<h3><?php esc_html_e( 'This will allow', 'iato-mcp' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Read site content and settings', 'iato-mcp' ); ?></li>
						<li><?php esc_html_e( 'Create and modify posts', 'iato-mcp' ); ?></li>
						<li><?php esc_html_e( 'Update SEO metadata', 'iato-mcp' ); ?></li>
						<li><?php esc_html_e( 'Manage media and navigation', 'iato-mcp' ); ?></li>
					</ul>
				</div>
				<div class="auth-permissions auth-actor">
					<h3><?php esc_html_e( 'This connection will act as', 'iato-mcp' ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: 1: approver display name, 2: approver login */
							esc_html__( '%1$s (%2$s), you. Everything this application does will run with, and be attributed to, your account. It gets its own token, which you can revoke at any time without affecting other connections.', 'iato-mcp' ),
							'<strong>' . esc_html( $approver->display_name ) . '</strong>',
							esc_html( $approver->user_login )
						);
						?>
					</p>
					<p>
						<?php
						printf(
							/* translators: %s: link to the IATO MCP settings page */
							esc_html__( 'Connected applications are listed, and can be revoked, under %s.', 'iato-mcp' ),
							'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings > IATO MCP', 'iato-mcp' ) . '</a>'
						);
						?>
					</p>
				</div>
				<form method="post" class="auth-actions">
					<?php wp_nonce_field( self::consent_nonce_action( $params ) ); ?>
					<button type="submit" name="decision" value="approve" class="auth-btn auth-btn--primary"><?php esc_html_e( 'Approve', 'iato-mcp' ); ?></button>
					<button type="submit" name="decision" value="deny" class="auth-btn auth-btn--secondary"><?php esc_html_e( 'Deny', 'iato-mcp' ); ?></button>
				</form>
				<p class="auth-footer"><?php esc_html_e( 'Powered by IATO MCP', 'iato-mcp' ); ?></p>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * The consent form's nonce is bound to the request it was rendered for,
	 * so a nonce obtained for one client / redirect URI / PKCE challenge
	 * cannot approve another.
	 *
	 * @param array{client_id:string,redirect_uri:string,code_challenge:string} $params
	 */
	public static function consent_nonce_action( array $params ): string {
		return 'iato_mcp_oauth_authorize_' . hash( 'sha256', $params['client_id'] . "\n" . $params['redirect_uri'] . "\n" . $params['code_challenge'] );
	}

	/**
	 * Where wp-login should send the user back to: the site's origin plus the
	 * request URI as received. (home_url( $request_uri ) doubled the path on
	 * subdirectory installs, because REQUEST_URI already contains it.)
	 */
	public static function login_return_url( string $request_uri ): string {
		$p      = wp_parse_url( home_url() );
		$origin = ( $p['scheme'] ?? 'http' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
		if ( '' === $request_uri || '/' !== $request_uri[0] ) {
			$request_uri = '/' . ltrim( $request_uri, '/' );
		}
		return $origin . $request_uri;
	}

	/** Logged in, but not an administrator: a 403 page, not a login loop. */
	private static function render_forbidden_screen(): void {
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		$user = wp_get_current_user();
		wp_die(
			'<p>' . esc_html__( 'Only administrators can approve connections to this site.', 'iato-mcp' ) . '</p>' .
			'<p>' . sprintf(
				/* translators: %s: user login */
				esc_html__( 'You are logged in as %s. Log in as an administrator, then start the connection again from your AI client.', 'iato-mcp' ),
				'<strong>' . esc_html( $user->user_login ) . '</strong>'
			) . '</p>' .
			'<p><a href="' . esc_url( wp_logout_url() ) . '">' . esc_html__( 'Log out', 'iato-mcp' ) . '</a></p>',
			esc_html__( 'Administrators only', 'iato-mcp' ),
			[ 'response' => 403 ]
		);
	}

	/**
	 * Is the grant behind a token row revoked or absent? The grant row is the
	 * authority; a token with no grant row (never recorded, or pruned) is
	 * treated as revoked everywhere, never as "no restriction".
	 *
	 * @param array<string,mixed> $row from IATO_MCP_OAuth_Store::find_token()
	 */
	public static function grant_is_dead( array $row ): bool {
		if ( ! array_key_exists( 'grant_missing', $row ) || $row['grant_missing'] ) {
			return true; // no grant information: fail closed
		}
		return null !== ( $row['grant_revoked_at'] ?? null );
	}

	/** Host (plus non-default port) of a redirect URI, for display and logs. */
	public static function redirect_host( string $uri ): string {
		$p = parse_url( $uri );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return '';
		}
		return strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
	}

	/** UTC "Y-m-d H:i:s" for a Unix timestamp. */
	public static function ts( int $time ): string {
		return gmdate( 'Y-m-d H:i:s', $time );
	}

	/** Unix timestamp for a stored UTC "Y-m-d H:i:s" string. */
	public static function to_time( string $utc ): int {
		$t = strtotime( $utc . ' UTC' );
		return false === $t ? 0 : $t;
	}

	/** Stored UTC timestamp rendered in the site's timezone and date format. */
	public static function format_local( string $utc ): string {
		if ( '' === $utc ) {
			return '';
		}
		$t = self::to_time( $utc );
		if ( 0 === $t ) {
			return '';
		}
		$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
		return function_exists( 'wp_date' ) ? (string) wp_date( $format, $t ) : gmdate( $format, $t );
	}

	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/** URL-safe random string (base64url of $bytes random bytes, no padding). */
	public static function random_string( int $bytes ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	public static function random_hex( int $bytes ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/** @return array{status:int,body:array} */
	private static function error( string $error, string $description ): array {
		return [ 'status' => 400, 'body' => [ 'error' => $error, 'error_description' => $description ] ];
	}

	/** Record an OAuth event in the call log (Diagnostics). */
	private static function log( string $event, array $args, int $user_id, string $status = 'success', ?string $error_code = null ): void {
		if ( ! class_exists( 'IATO_MCP_Call_Log' ) ) {
			return;
		}
		IATO_MCP_Call_Log::record( [
			'rpc_method'      => 'oauth',
			'tool_name'       => $event,
			'request_args'    => $args,
			'response_status' => $status,
			'error_code'      => $error_code,
			'duration_ms'     => 0,
			'auth_user_id'    => $user_id,
		] );
	}

	private static function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	}

	/** Token and revocation endpoints accept form-encoded (required) and JSON bodies. */
	private static function read_post_input(): array {
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '';
		if ( false !== strpos( $content_type, 'application/json' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- raw request body.
			$input = json_decode( (string) file_get_contents( 'php://input' ), true );
			return is_array( $input ) ? $input : [];
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth endpoint; nonce not applicable.
		return is_array( $_POST ) ? wp_unslash( $_POST ) : [];
	}

	/**
	 * Extract the request path relative to the WordPress home URL.
	 * Handles subdirectory installs correctly.
	 */
	private static function get_request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = trim( (string) strtok( $uri, '?' ), '/' );

		$home_path = trim( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '', '/' );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) + 1 );
		} elseif ( $home_path === $path ) {
			$path = '';
		}

		return $path;
	}

	/**
	 * Send a JSON response and terminate.
	 *
	 * @param array|object          $data
	 * @param array<string,string>  $headers extra headers
	 */
	private static function json_response( array|object $data, int $status = 200, array $headers = [] ): void {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Pragma: no-cache' );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo wp_json_encode( $data );
		exit;
	}
}
