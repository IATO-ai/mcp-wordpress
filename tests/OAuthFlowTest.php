<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * OAuth server (1.13.0): registration rules and limits, client pruning,
 * redirect and resource matching, authorize validation, single-use codes,
 * per-grant access/refresh tokens with rotation and reuse detection, the
 * absolute grant lifetime, revocation, and the grant listing. Runs against
 * the in-memory store from tests/stubs-oauth.php with a fixed clock.
 */
final class OAuthFlowTest extends TestCase {

	private const NOW  = 1800000000; // 2027-01-15 08:00:00 UTC
	private const KEY  = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';
	private const CB   = 'https://claude.ai/api/mcp/auth_callback';
	private const RES  = 'http://localhost:8888/wp-json/iato-mcp/v1/message';
	private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	private IATO_Test_OAuth_Store $store;

	protected function setUp(): void {
		IATO_Test_Abilities_State::reset();
		IATO_Test_Auth_State::reset();
		IATO_Test_OAuth_State::reset();
		$this->store = IATO_Test_OAuth_Store::install();
		if ( class_exists( 'IATO_Test_Roles' ) ) {
			IATO_Test_Roles::$users = []; // role-model users from other test files would shadow the caps below
		}
		IATO_Test_Abilities_State::$options['iato_mcp_key'] = self::KEY;
		IATO_Test_Auth_State::$users = [
			1 => [ 'login' => 'admin',  'caps' => [ 'read' => true, 'edit_posts' => true, 'manage_options' => true ], 'exists' => true ],
			2 => [ 'login' => 'admin2', 'caps' => [ 'read' => true, 'edit_posts' => true, 'manage_options' => true ], 'exists' => true ],
			5 => [ 'login' => 'editor', 'caps' => [ 'read' => true, 'edit_posts' => true ], 'exists' => true ],
		];
	}

	protected function tearDown(): void {
		IATO_MCP_OAuth_Store::set_instance( null );
	}

	// ── helpers ───────────────────────────────────────────────────────────

	private static function challenge( string $verifier = self::VERIFIER ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private function register( array $uris = [ self::CB ], string $name = 'Claude', string $ip = '203.0.113.9', int $now = self::NOW ): array {
		return IATO_MCP_OAuth::register_response( [ 'client_name' => $name, 'redirect_uris' => $uris, 'grant_types' => [ 'authorization_code', 'refresh_token' ] ], $ip, $now );
	}

	private function client( array $uris = [ self::CB ] ): string {
		$r = $this->register( $uris );
		$this->assertSame( 201, $r['status'], json_encode( $r ) );
		return $r['body']['client_id'];
	}

	/** @return array<string,string> query parameters of the redirect URL */
	private static function redirect_params( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	/** Keys given as null in $extra are removed (a parameter that is absent, not empty). */
	private function authorize_query( string $client_id, array $extra = [] ): array {
		$q = $extra + [
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => self::CB,
			'state'                 => 'st-1',
			'code_challenge'        => self::challenge(),
			'code_challenge_method' => 'S256',
			'resource'              => self::RES,
		];
		return array_filter( $q, fn( $v ) => null !== $v );
	}

	/** Validate + approve; returns the redirect's query parameters. */
	private function approve( string $client_id, array $extra = [], int $user = 1, int $now = self::NOW ): array {
		$check = IATO_MCP_OAuth::validate_authorize_request( $this->authorize_query( $client_id, $extra ) );
		$this->assertSame( 'ok', $check['kind'], json_encode( $check ) );
		$url = IATO_MCP_OAuth::approve( $check['params'], $user, $now );
		$this->assertStringStartsWith( self::CB . '?', $url );
		return self::redirect_params( $url );
	}

	private function exchange( string $code, string $client_id, array $extra = [], int $now = self::NOW ): array {
		return IATO_MCP_OAuth::token_response( $extra + [
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => self::CB,
			'client_id'     => $client_id,
			'code_verifier' => self::VERIFIER,
			'resource'      => self::RES,
		], $now );
	}

	private function refresh( string $refresh, string $client_id, array $extra = [], int $now = self::NOW ): array {
		$r = IATO_MCP_OAuth::token_response( $extra + [ 'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $client_id ], $now );
		if ( 200 === $r['status'] ) {
			$this->raw_refresh[] = $r['body']['refresh_token'];
		}
		return $r;
	}

	/** Full happy path. @return array{client_id:string,access:string,refresh:string,grant_id:string} */
	private function grant( int $user = 1, int $now = self::NOW ): array {
		$client_id = $this->client();
		$q         = $this->approve( $client_id, [], $user, $now );
		$r         = $this->exchange( $q['code'], $client_id, [], $now );
		$this->assertSame( 200, $r['status'], json_encode( $r ) );
		$row                 = $this->store->row_for( $r['body']['access_token'] );
		$this->raw_refresh[] = $r['body']['refresh_token'];
		return [ 'client_id' => $client_id, 'access' => $r['body']['access_token'], 'refresh' => $r['body']['refresh_token'], 'grant_id' => $row['grant_id'] ];
	}

	private static function log_rows( string $tool ): array {
		return array_values( array_filter( IATO_Test_Auth_State::$call_log, fn( $r ) => 'oauth' === $r['rpc_method'] && $tool === $r['tool_name'] ) );
	}

	// ── metadata ──────────────────────────────────────────────────────────

	public function test_metadata_documents(): void {
		$m = IATO_MCP_OAuth::metadata();
		$this->assertSame( 'http://localhost:8888', $m['issuer'] );
		$this->assertSame( 'http://localhost:8888/oauth/revoke', $m['revocation_endpoint'] );
		$this->assertSame( [ 'authorization_code', 'refresh_token' ], $m['grant_types_supported'] );
		$this->assertSame( [ 'S256' ], $m['code_challenge_methods_supported'] );
		$this->assertSame( [ 'none' ], $m['token_endpoint_auth_methods_supported'] );
		$this->assertTrue( $m['authorization_response_iss_parameter_supported'] );
		$this->assertArrayNotHasKey( 'client_id_metadata_document_supported', $m, 'CIMD is not advertised (assessed, not built)' );

		$p = IATO_MCP_OAuth::resource_metadata();
		$this->assertSame( self::RES, $p['resource'] );
		$this->assertSame( [ $m['issuer'] ], $p['authorization_servers'] );
		$this->assertSame( [ 'header' ], $p['bearer_methods_supported'] );
	}

	// ── registration ──────────────────────────────────────────────────────

	public function test_registration_returns_rfc7591_fields_and_stores_the_client(): void {
		$r = $this->register( [ self::CB, 'http://localhost/callback', 'http://127.0.0.1/cb', 'http://[::1]/cb' ] );
		$this->assertSame( 201, $r['status'] );
		$b = $r['body'];
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{32}$/', $b['client_id'] );
		$this->assertSame( self::NOW, $b['client_id_issued_at'] );
		$this->assertSame( 'Claude', $b['client_name'] );
		$this->assertCount( 4, $b['redirect_uris'] );
		$this->assertSame( 'none', $b['token_endpoint_auth_method'] );
		$this->assertSame( [ 'authorization_code', 'refresh_token' ], $b['grant_types'] );
		$this->assertSame( [ 'code' ], $b['response_types'] );
		$c = $this->store->find_client( $b['client_id'] );
		$this->assertSame( 'Claude', $c['client_name'] );
		$this->assertSame( '203.0.113.9', $c['registration_ip'] );
		$this->assertNull( $c['authorized_at'] );
		$this->assertStringNotContainsString( self::KEY, json_encode( $r ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_redirect_uris' )]
	public function test_registration_rejects_unsafe_redirect_uris( string $uri ): void {
		$r = $this->register( [ self::CB, $uri ] );
		$this->assertSame( 400, $r['status'], $uri );
		$this->assertSame( 'invalid_redirect_uri', $r['body']['error'], $uri );
		$this->assertSame( [], $this->store->clients );
	}

	public static function bad_redirect_uris(): array {
		return [
			'http non-loopback'  => [ 'http://example.com/cb' ],
			'custom scheme'      => [ 'myapp://cb' ],
			'scheme-less'        => [ 'claude.ai/cb' ],
			'fragment'           => [ 'https://claude.ai/cb#frag' ],
			'credentials'        => [ 'https://user:pw@claude.ai/cb' ],
			'whitespace'         => [ "https://claude.ai/cb\n" ],
			'lookalike loopback' => [ 'http://localhost.evil.example/cb' ],
		];
	}

	public function test_registration_requires_redirect_uris_and_public_client(): void {
		$this->assertSame( 'invalid_client_metadata', IATO_MCP_OAuth::register_response( [ 'client_name' => 'x' ], '1.1.1.1', self::NOW )['body']['error'] );
		$this->assertSame( 'invalid_client_metadata', IATO_MCP_OAuth::register_response( [ 'redirect_uris' => 'not-a-list' ], '1.1.1.1', self::NOW )['body']['error'] );
		$r = IATO_MCP_OAuth::register_response( [ 'redirect_uris' => [ self::CB ], 'token_endpoint_auth_method' => 'client_secret_basic' ], '1.1.1.1', self::NOW );
		$this->assertSame( 400, $r['status'] );
		$this->assertSame( 'invalid_client_metadata', $r['body']['error'] );
	}

	public function test_registration_caps_redirect_uris_and_reports_storage_failures(): void {
		$ten = array_map( fn( $i ) => "https://claude.ai/cb{$i}", range( 1, 10 ) );
		$this->assertSame( 201, $this->register( $ten )['status'] );
		$r = $this->register( array_merge( $ten, [ 'https://claude.ai/cb11' ] ) );
		$this->assertSame( 400, $r['status'] );
		$this->assertSame( 'invalid_client_metadata', $r['body']['error'] );
		$this->assertStringContainsString( '10', $r['body']['error_description'] );

		$this->store->fail_insert_client = true;
		$r = $this->register();
		$this->assertSame( 500, $r['status'] );
		$this->assertSame( 'server_error', $r['body']['error'] );
		$this->assertArrayNotHasKey( 'client_id', $r['body'] );
	}

	public function test_consent_nonce_action_is_bound_to_the_request(): void {
		$a = [ 'client_id' => 'c1', 'redirect_uri' => self::CB, 'code_challenge' => self::challenge() ];
		$this->assertSame( IATO_MCP_OAuth::consent_nonce_action( $a ), IATO_MCP_OAuth::consent_nonce_action( $a ) );
		$this->assertStringStartsWith( 'iato_mcp_oauth_authorize_', IATO_MCP_OAuth::consent_nonce_action( $a ) );
		foreach ( [ 'client_id' => 'c2', 'redirect_uri' => 'https://claude.ai/other', 'code_challenge' => self::challenge( 'other-verifier-other-verifier-other-verifier' ) ] as $k => $v ) {
			$this->assertNotSame( IATO_MCP_OAuth::consent_nonce_action( $a ), IATO_MCP_OAuth::consent_nonce_action( [ $k => $v ] + $a ), $k );
		}
	}

	public function test_login_return_url_keeps_a_subdirectory_path_once(): void {
		IATO_Test_OAuth_State::$home_url = 'https://x.example/blog';
		$this->assertSame( 'https://x.example/blog/oauth/authorize?a=1&b=2', IATO_MCP_OAuth::login_return_url( '/blog/oauth/authorize?a=1&b=2' ) );
		IATO_Test_OAuth_State::$home_url = 'http://localhost:8888';
		$this->assertSame( 'http://localhost:8888/oauth/authorize?a=1', IATO_MCP_OAuth::login_return_url( '/oauth/authorize?a=1' ) );
		$this->assertSame( 'http://localhost:8888/oauth/authorize', IATO_MCP_OAuth::login_return_url( 'oauth/authorize' ) );
	}

	public function test_approve_fails_closed_when_the_grant_cannot_be_recorded(): void {
		$client_id = $this->client();
		$check     = IATO_MCP_OAuth::validate_authorize_request( $this->authorize_query( $client_id ) );
		$this->store->fail_insert_grant = true;
		$q = self::redirect_params( IATO_MCP_OAuth::approve( $check['params'], 1, self::NOW ) );
		$this->assertSame( 'server_error', $q['error'] );
		$this->assertArrayNotHasKey( 'code', $q );
		$this->assertSame( [], $this->store->tokens );
	}

	public function test_registration_sanitises_and_bounds_the_client_name(): void {
		$r = $this->register( [ self::CB ], '<b>' . str_repeat( 'n', 150 ) . '</b>' );
		$this->assertSame( 100, strlen( $r['body']['client_name'] ) );
		$this->assertStringNotContainsString( '<', $r['body']['client_name'] );
		$this->assertSame( 'Unknown client', $this->register( [ self::CB ], '' )['body']['client_name'] );
	}

	public function test_registration_is_rate_limited_per_ip_and_per_site(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 201, $this->register( [ self::CB ], 'c', '10.0.0.1', self::NOW + $i )['status'], "registration $i" );
		}
		$r = $this->register( [ self::CB ], 'c', '10.0.0.1', self::NOW + 10 );
		$this->assertSame( 429, $r['status'] );
		$this->assertSame( 'invalid_request', $r['body']['error'] );
		$this->assertGreaterThan( 0, (int) $r['headers']['Retry-After'] );
		$this->assertLessThanOrEqual( 600, (int) $r['headers']['Retry-After'] );
		$this->assertSame( 201, $this->register( [ self::CB ], 'c', '10.0.0.2', self::NOW + 10 )['status'], 'another IP has its own bucket' );
		$this->assertSame( 201, $this->register( [ self::CB ], 'c', '10.0.0.1', self::NOW + 601 )['status'], 'window elapsed' );

		// Site-wide bucket: 100 per hour across all IPs.
		IATO_Test_OAuth_State::$transients = [];
		$this->store->clients              = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertSame( 201, $this->register( [ self::CB ], 'c', '10.1.' . intdiv( $i, 5 ) . '.' . ( $i % 5 ), self::NOW )['status'] );
		}
		$this->assertSame( 429, $this->register( [ self::CB ], 'c', '10.9.9.9', self::NOW )['status'] );
	}

	public function test_registration_limits_are_filterable(): void {
		add_filter( 'iato_mcp_oauth_registration_limits', fn( array $l ) => [ 'ip' => [ 2, 60 ], 'site' => [ 0, 0 ] ] );
		$this->assertSame( 201, $this->register()['status'] );
		$this->assertSame( 201, $this->register()['status'] );
		$this->assertSame( 429, $this->register()['status'] );
	}

	public function test_registration_prunes_stale_clients(): void {
		$day = 86400;
		// Never authorized: pruned after 24h, kept before.
		$old_pending = $this->register( [ self::CB ], 'old-pending', '1.1.1.1', self::NOW - $day - 1 )['body']['client_id'];
		$new_pending = $this->register( [ self::CB ], 'new-pending', '1.1.1.2', self::NOW - $day + 3600 )['body']['client_id'];
		// Authorized long ago, grant long dead: pruned.
		$idle = $this->register( [ self::CB ], 'idle', '1.1.1.3', self::NOW - 90 * $day )['body']['client_id'];
		$this->store->mark_client_authorized( $idle, IATO_MCP_OAuth::ts( self::NOW - 90 * $day ) );
		$this->store->insert_token( [ 'kind' => 'access', 'token_hash' => 'h1', 'grant_id' => str_repeat( 'a', 32 ), 'client_id' => $idle, 'user_id' => 1, 'created_at' => IATO_MCP_OAuth::ts( self::NOW - 90 * $day ), 'expires_at' => IATO_MCP_OAuth::ts( self::NOW - 89 * $day ) ] );
		// Authorized long ago, but with a live grant: kept.
		$active = $this->register( [ self::CB ], 'active', '1.1.1.4', self::NOW - 90 * $day )['body']['client_id'];
		$this->store->mark_client_authorized( $active, IATO_MCP_OAuth::ts( self::NOW - 90 * $day ) );
		$this->store->insert_token( [ 'kind' => 'refresh', 'token_hash' => 'h2', 'grant_id' => str_repeat( 'b', 32 ), 'client_id' => $active, 'user_id' => 1, 'created_at' => IATO_MCP_OAuth::ts( self::NOW - $day ), 'expires_at' => IATO_MCP_OAuth::ts( self::NOW + 29 * $day ) ] );
		// Authorized long ago, grant revoked only yesterday: kept for now.
		$recent_revoke = $this->register( [ self::CB ], 'recent-revoke', '1.1.1.5', self::NOW - 90 * $day )['body']['client_id'];
		$this->store->mark_client_authorized( $recent_revoke, IATO_MCP_OAuth::ts( self::NOW - 90 * $day ) );
		$this->store->insert_token( [ 'kind' => 'refresh', 'token_hash' => 'h3', 'grant_id' => str_repeat( 'c', 32 ), 'client_id' => $recent_revoke, 'user_id' => 1, 'created_at' => IATO_MCP_OAuth::ts( self::NOW - 60 * $day ), 'expires_at' => IATO_MCP_OAuth::ts( self::NOW - 29 * $day ) ] );
		$this->store->revoke_grant( str_repeat( 'c', 32 ), IATO_MCP_OAuth::ts( self::NOW - $day ) );

		$this->register( [ self::CB ], 'trigger', '1.1.1.6', self::NOW );

		$this->assertNull( $this->store->find_client( $old_pending ), 'pending > 24h pruned' );
		$this->assertNotNull( $this->store->find_client( $new_pending ), 'pending < 24h kept' );
		$this->assertNull( $this->store->find_client( $idle ), 'authorized, no live grant for 30 days: pruned' );
		$this->assertNotNull( $this->store->find_client( $active ), 'live grant: kept' );
		$this->assertNotNull( $this->store->find_client( $recent_revoke ), 'revoked within 30 days: kept' );
	}

	// ── redirect and resource matching ────────────────────────────────────

	public function test_redirect_uri_matching_rules(): void {
		$m = [ IATO_MCP_OAuth::class, 'redirect_uri_matches' ];
		$this->assertTrue( $m( self::CB, self::CB ) );
		$this->assertTrue( $m( 'http://localhost/callback', 'http://localhost:3118/callback' ), 'loopback: port ignored' );
		$this->assertTrue( $m( 'http://127.0.0.1/cb', 'http://127.0.0.1:51234/cb' ) );
		$this->assertTrue( $m( 'http://[::1]/cb', 'http://[::1]:4000/cb' ) );
		$this->assertFalse( $m( 'http://localhost/callback', 'http://localhost:3118/other' ), 'path must match' );
		$this->assertFalse( $m( 'http://localhost/callback', 'http://127.0.0.1:3118/callback' ), 'no cross-match between loopback hosts' );
		$this->assertFalse( $m( 'http://localhost/callback', 'https://localhost:3118/callback' ), 'scheme must match' );
		$this->assertFalse( $m( 'https://claude.ai/cb', 'https://claude.ai:8443/cb' ), 'non-loopback: exact only' );
		$this->assertFalse( $m( 'https://claude.ai/cb', 'https://claude.ai/cb?x=1' ) );
		$this->assertFalse( $m( 'http://localhost/cb?x=1', 'http://localhost:9/cb?x=2' ), 'query must match' );
	}

	public function test_resource_normalisation_and_https_upgrade(): void {
		$ok = fn( ?string $r ) => IATO_MCP_OAuth::resource_check( $r );
		$this->assertSame( [ 'ok' => true, 'upgraded' => false ], $ok( null ), 'absent is allowed' );
		$this->assertSame( [ 'ok' => true, 'upgraded' => false ], $ok( '' ) );
		$this->assertSame( [ 'ok' => true, 'upgraded' => false ], $ok( self::RES ) );
		$this->assertSame( [ 'ok' => true, 'upgraded' => false ], $ok( self::RES . '/' ), 'trailing slash' );
		$this->assertSame( [ 'ok' => true, 'upgraded' => false ], $ok( 'HTTP://LOCALHOST:8888/wp-json/iato-mcp/v1/message' ), 'case of scheme/host' );
		$this->assertSame( [ 'ok' => true, 'upgraded' => true ], $ok( 'https://localhost:8888/wp-json/iato-mcp/v1/message' ), 'https accepted for an http canonical URL' );
		$this->assertSame( [ 'ok' => false, 'upgraded' => false ], $ok( 'http://localhost:8888/wp-json/' ), 'different path' );
		$this->assertSame( [ 'ok' => false, 'upgraded' => false ], $ok( 'http://other.example:8888/wp-json/iato-mcp/v1/message' ) );
		$this->assertSame( [ 'ok' => false, 'upgraded' => false ], $ok( self::RES . '#frag' ) );
		$this->assertSame( [ 'ok' => false, 'upgraded' => false ], $ok( 'localhost:8888/wp-json/iato-mcp/v1/message' ), 'no scheme' );

		// Default ports, and never a downgrade.
		IATO_Test_OAuth_State::$home_url = 'https://site.example';
		$this->assertSame( [ 'ok' => true, 'upgraded' => false ], $ok( 'https://site.example:443/wp-json/iato-mcp/v1/message' ) );
		$this->assertSame( [ 'ok' => false, 'upgraded' => false ], $ok( 'http://site.example/wp-json/iato-mcp/v1/message' ), 'http for an https canonical URL is refused' );

		// Plain permalinks keep the query string.
		$this->assertSame( 'http://a.example/?rest_route=/iato-mcp/v1/message', IATO_MCP_OAuth::normalize_resource( 'http://A.example:80/?rest_route=/iato-mcp/v1/message' ) );
	}

	// ── authorize ─────────────────────────────────────────────────────────

	public function test_authorize_refuses_unknown_client_and_unregistered_redirect_as_json(): void {
		$r = IATO_MCP_OAuth::validate_authorize_request( $this->authorize_query( 'nope' ) );
		$this->assertSame( [ 'json', 400, 'invalid_client' ], [ $r['kind'], $r['status'], $r['body']['error'] ] );

		$client_id = $this->client();
		$r         = IATO_MCP_OAuth::validate_authorize_request( $this->authorize_query( $client_id, [ 'redirect_uri' => 'https://evil.example/cb' ] ) );
		$this->assertSame( [ 'json', 400, 'invalid_request' ], [ $r['kind'], $r['status'], $r['body']['error'] ] );

		$r = IATO_MCP_OAuth::validate_authorize_request( [ 'client_id' => $client_id ] );
		$this->assertSame( 'json', $r['kind'] );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'protocol_errors' )]
	public function test_authorize_protocol_errors_redirect_to_the_client( array $extra, string $error ): void {
		$client_id = $this->client();
		$r         = IATO_MCP_OAuth::validate_authorize_request( $this->authorize_query( $client_id, $extra ) );
		$this->assertSame( 'redirect', $r['kind'], json_encode( $r ) );
		$this->assertStringStartsWith( self::CB . '?', $r['url'] );
		$q = self::redirect_params( $r['url'] );
		$this->assertSame( $error, $q['error'] );
		$this->assertSame( 'st-1', $q['state'] );
		$this->assertSame( 'http://localhost:8888', $q['iss'] );
		$this->assertArrayNotHasKey( 'code', $q );
	}

	public static function protocol_errors(): array {
		return [
			'wrong response_type' => [ [ 'response_type' => 'token' ], 'unsupported_response_type' ],
			'missing challenge'   => [ [ 'code_challenge' => '' ], 'invalid_request' ],
			'short challenge'     => [ [ 'code_challenge' => 'abc' ], 'invalid_request' ],
			'plain method'        => [ [ 'code_challenge_method' => 'plain' ], 'invalid_request' ],
			'missing method'      => [ [ 'code_challenge_method' => null ], 'invalid_request' ],
			'empty method'        => [ [ 'code_challenge_method' => '' ], 'invalid_request' ],
			'md5 method'          => [ [ 'code_challenge_method' => 'md5' ], 'invalid_request' ],
			'foreign resource'    => [ [ 'resource' => 'https://other.example/mcp' ], 'invalid_target' ],
		];
	}

	public function test_authorize_rejects_non_printable_state_by_redirect_without_state(): void {
		$client_id = $this->client();
		$r         = IATO_MCP_OAuth::validate_authorize_request( $this->authorize_query( $client_id, [ 'state' => "bad\nstate" ] ) );
		$this->assertSame( 'redirect', $r['kind'] );
		$q = self::redirect_params( $r['url'] );
		$this->assertSame( 'invalid_request', $q['error'] );
		$this->assertArrayNotHasKey( 'state', $q );
	}

	public function test_approve_issues_a_single_use_code_bound_to_the_request(): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id );

		$grant = $this->store->find_grant( $this->store->row_for( $q['code'] )['grant_id'] );
		$this->assertSame( [ $client_id, 1, self::CB, IATO_MCP_OAuth::ts( self::NOW ), null ], [ $grant['client_id'], $grant['user_id'], $grant['redirect_uri'], $grant['granted_at'], $grant['revoked_at'] ], 'a grant row records the authorization' );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $q['code'] );
		$this->assertNotSame( self::KEY, $q['code'] );
		$this->assertSame( 'st-1', $q['state'] );
		$this->assertSame( 'http://localhost:8888', $q['iss'], 'iss equals the metadata issuer' );

		$row = $this->store->row_for( $q['code'] );
		$this->assertSame( 'code', $row['kind'] );
		$this->assertSame( $client_id, $row['client_id'] );
		$this->assertSame( self::CB, $row['redirect_uri'] );
		$this->assertSame( self::challenge(), $row['code_challenge'] );
		$this->assertSame( 1, $row['user_id'] );
		$this->assertSame( IATO_MCP_OAuth::ts( self::NOW + 300 ), $row['expires_at'], '5-minute lifetime' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $row['grant_id'] );
		$this->assertNull( $row['used_at'] );
		$this->assertSame( IATO_MCP_OAuth::ts( self::NOW ), $this->store->find_client( $client_id )['authorized_at'] );

		$log = self::log_rows( 'grant' );
		$this->assertCount( 1, $log );
		$this->assertSame( 1, $log[0]['auth_user_id'] );
		$this->assertSame( $client_id, $log[0]['request_args']['client_id'] );
		$this->assertSame( 'claude.ai', $log[0]['request_args']['redirect_host'] );
		$this->assertSame( [], self::log_rows( 'resource_scheme_upgrade' ) );
	}

	public function test_approve_without_state_omits_it_and_keeps_an_existing_query(): void {
		$client_id = $this->client( [ 'http://localhost/cb?app=1' ] );
		$check     = IATO_MCP_OAuth::validate_authorize_request( $this->authorize_query( $client_id, [ 'redirect_uri' => 'http://localhost:4321/cb?app=1', 'state' => '' ] ) );
		$this->assertSame( 'ok', $check['kind'], json_encode( $check ) );
		$url = IATO_MCP_OAuth::approve( $check['params'], 1, self::NOW );
		$this->assertStringStartsWith( 'http://localhost:4321/cb?app=1&code=', $url );
		$this->assertArrayNotHasKey( 'state', self::redirect_params( $url ) );
	}

	public function test_https_resource_on_http_site_is_accepted_and_logged(): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id, [ 'resource' => 'https://localhost:8888/wp-json/iato-mcp/v1/message' ] );
		$log       = self::log_rows( 'resource_scheme_upgrade' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'https://localhost:8888/wp-json/iato-mcp/v1/message', $log[0]['request_args']['resource'] );
		$this->assertSame( $client_id, $log[0]['request_args']['client_id'] );

		$r = $this->exchange( $q['code'], $client_id, [ 'resource' => 'https://localhost:8888/wp-json/iato-mcp/v1/message/' ] );
		$this->assertSame( 200, $r['status'] );
		$this->assertCount( 2, self::log_rows( 'resource_scheme_upgrade' ), 'logged at the token endpoint too' );
		$this->assertSame( 'token', self::log_rows( 'resource_scheme_upgrade' )[1]['request_args']['at'] );
	}

	public function test_deny_redirects_with_access_denied(): void {
		$client_id = $this->client();
		$check     = IATO_MCP_OAuth::validate_authorize_request( $this->authorize_query( $client_id ) );
		$q         = self::redirect_params( IATO_MCP_OAuth::deny( $check['params'] ) );
		$this->assertSame( 'access_denied', $q['error'] );
		$this->assertSame( 'st-1', $q['state'] );
		$this->assertSame( 'http://localhost:8888', $q['iss'] );
		$this->assertSame( [], $this->store->tokens, 'nothing issued' );
	}

	// ── token endpoint: authorization_code ────────────────────────────────

	public function test_exchange_issues_prefixed_hashed_expiring_tokens(): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id );
		$r         = $this->exchange( $q['code'], $client_id );
		$this->assertSame( 200, $r['status'], json_encode( $r ) );
		$b = $r['body'];
		$this->assertSame( 'Bearer', $b['token_type'] );
		$this->assertSame( 3600, $b['expires_in'] );
		$this->assertMatchesRegularExpression( '/^iato_at_[A-Za-z0-9_-]{43}$/', $b['access_token'] );
		$this->assertMatchesRegularExpression( '/^iato_rt_[A-Za-z0-9_-]{43}$/', $b['refresh_token'] );
		$this->assertStringNotContainsString( self::KEY, json_encode( $b ) );

		$access = $this->store->row_for( $b['access_token'] );
		$this->assertSame( 'access', $access['kind'] );
		$this->assertSame( IATO_MCP_OAuth::ts( self::NOW + 3600 ), $access['expires_at'] );
		$this->assertSame( 1, $access['user_id'] );
		$refresh = $this->store->row_for( $b['refresh_token'] );
		$this->assertSame( IATO_MCP_OAuth::ts( self::NOW + 30 * 86400 ), $refresh['expires_at'] );
		$this->assertSame( $access['grant_id'], $refresh['grant_id'] );
		$this->assertSame( $this->store->row_for( $q['code'] )['grant_id'], $access['grant_id'] );
		$this->assertNotNull( $this->store->row_for( $q['code'] )['used_at'], 'code consumed' );
		foreach ( $this->store->tokens as $t ) {
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $t['token_hash'], 'only hashes are stored' );
		}
	}

	public function test_exchange_accepts_json_style_input_and_absent_resource(): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id );
		$r         = IATO_MCP_OAuth::token_response( [ 'grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => self::CB, 'client_id' => $client_id, 'code_verifier' => self::VERIFIER ], self::NOW );
		$this->assertSame( 200, $r['status'] );
	}

	public function test_unknown_code_and_unsupported_grant_are_refused(): void {
		$this->assertSame( [ 400, 'invalid_grant' ], [ $this->exchange( 'nope', 'c' )['status'], $this->exchange( 'nope', 'c' )['body']['error'] ] );
		$this->assertSame( [ 400, 'invalid_grant' ], [ $this->exchange( self::KEY, 'c' )['status'], $this->exchange( self::KEY, 'c' )['body']['error'] ], 'the site key is not a code' );
		$this->assertSame( 'unsupported_grant_type', IATO_MCP_OAuth::token_response( [ 'grant_type' => 'client_credentials' ], self::NOW )['body']['error'] );
		$this->assertSame( 'invalid_grant', IATO_MCP_OAuth::token_response( [ 'grant_type' => 'authorization_code' ], self::NOW )['body']['error'] );
	}

	public function test_a_failed_exchange_consumes_the_code(): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id );
		$r         = $this->exchange( $q['code'], $client_id, [ 'code_verifier' => 'wrong-verifier-wrong-verifier-wrong-verifier' ] );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'PKCE', $r['body']['error_description'] );
		$this->assertNotNull( $this->store->row_for( $q['code'] )['used_at'] );
		$r = $this->exchange( $q['code'], $client_id );
		$this->assertSame( 'invalid_grant', $r['body']['error'], 'the correct verifier no longer helps' );
		$this->assertSame( 1, count( $this->store->tokens ), 'no tokens were ever issued' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'binding_mismatches' )]
	public function test_exchange_checks_client_redirect_and_verifier( array $extra, string $needle ): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id );
		$r         = $this->exchange( $q['code'], $client_id, $extra );
		$this->assertSame( 400, $r['status'] );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( $needle, $r['body']['error_description'] );
	}

	public static function binding_mismatches(): array {
		return [
			'other client'   => [ [ 'client_id' => 'someone-else' ], 'client_id' ],
			'other redirect' => [ [ 'redirect_uri' => 'https://claude.ai/other' ], 'redirect_uri' ],
			'no verifier'    => [ [ 'code_verifier' => '' ], 'code_verifier is required' ],
		];
	}

	public function test_resource_mismatch_at_exchange_is_invalid_target(): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id );
		$r         = $this->exchange( $q['code'], $client_id, [ 'resource' => 'https://other.example/mcp' ] );
		$this->assertSame( 'invalid_target', $r['body']['error'] );
	}

	public function test_expired_code_is_refused(): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id );
		$r         = $this->exchange( $q['code'], $client_id, [], self::NOW + 301 );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'expired', $r['body']['error_description'] );
		$this->assertSame( 200, $this->exchange( $this->approve( $client_id )['code'], $client_id, [], self::NOW + 299 )['status'], 'just inside the window' );
	}

	public function test_code_replay_after_success_revokes_the_grant(): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id );
		$ok        = $this->exchange( $q['code'], $client_id );
		$this->assertSame( 200, $ok['status'] );
		$r = $this->exchange( $q['code'], $client_id, [], self::NOW + 1 );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'already used', $r['body']['error_description'] );
		$this->assertNotNull( $this->store->row_for( $ok['body']['access_token'] )['revoked_at'], 'access token revoked' );
		$this->assertNotNull( $this->store->row_for( $ok['body']['refresh_token'] )['revoked_at'], 'refresh token revoked' );
		$log = self::log_rows( 'revoke' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'code_replay', $log[0]['error_code'] );
		$this->assertSame( 'code_replay', $log[0]['request_args']['reason'] );
		$this->assertSame( 'code_replay', $this->store->find_grant( $this->store->row_for( $q['code'] )['grant_id'] )['revoked_reason'] );
	}

	public function test_exchange_for_a_demoted_or_deleted_approver_fails_and_revokes(): void {
		$client_id = $this->client();
		$q         = $this->approve( $client_id, [], 5 ); // editor approved (cannot happen through the UI; defensive)
		$r         = $this->exchange( $q['code'], $client_id );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'administrator', $r['body']['error_description'] );
		$this->assertNotNull( $this->store->row_for( $q['code'] )['revoked_at'] );
		$this->assertSame( 'user_demoted', self::log_rows( 'revoke' )[0]['error_code'] );

		$q2 = $this->approve( $client_id, [], 77 ); // no such user
		$r  = $this->exchange( $q2['code'], $client_id );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertSame( 'user_missing', self::log_rows( 'revoke' )[1]['error_code'] );
	}

	// ── token endpoint: refresh_token ─────────────────────────────────────

	public function test_refresh_rotates_the_pair_under_the_same_grant(): void {
		$g = $this->grant();
		$r = $this->refresh( $g['refresh'], $g['client_id'], [], self::NOW + 3000 );
		$this->assertSame( 200, $r['status'], json_encode( $r ) );
		$b = $r['body'];
		$this->assertNotSame( $g['access'], $b['access_token'] );
		$this->assertNotSame( $g['refresh'], $b['refresh_token'] );
		$this->assertSame( 3600, $b['expires_in'] );
		$this->assertSame( $g['grant_id'], $this->store->row_for( $b['access_token'] )['grant_id'] );
		$this->assertSame( IATO_MCP_OAuth::ts( self::NOW + 3000 + 30 * 86400 ), $this->store->row_for( $b['refresh_token'] )['expires_at'], 'sliding refresh expiry' );
		$this->assertNotNull( $this->store->row_for( $g['refresh'] )['used_at'], 'old refresh spent' );
		$this->assertNotNull( $this->store->row_for( $g['access'] )['revoked_at'], 'old access revoked' );
		$this->assertNull( $this->store->row_for( $b['access_token'] )['revoked_at'] );
		$this->assertSame( [], self::log_rows( 'revoke' ), 'routine rotation is not logged' );
	}

	public function test_refresh_reuse_revokes_the_whole_grant(): void {
		$g  = $this->grant();
		$r1 = $this->refresh( $g['refresh'], $g['client_id'], [], self::NOW + 10 );
		$r2 = $this->refresh( $g['refresh'], $g['client_id'], [], self::NOW + 20 );
		$this->assertSame( 'invalid_grant', $r2['body']['error'] );
		$this->assertStringContainsString( 'already used', $r2['body']['error_description'] );
		$this->assertNotNull( $this->store->row_for( $r1['body']['access_token'] )['revoked_at'], 'the newest access token is dead too' );
		$this->assertNotNull( $this->store->row_for( $r1['body']['refresh_token'] )['revoked_at'] );
		$this->assertSame( 'refresh_reuse', self::log_rows( 'revoke' )[0]['error_code'] );
		$this->assertSame( 'invalid_grant', $this->refresh( $r1['body']['refresh_token'], $g['client_id'], [], self::NOW + 30 )['body']['error'], 'revoked' );
	}

	public function test_refresh_rejections_without_revocation(): void {
		$g = $this->grant();
		$this->assertSame( 'invalid_grant', $this->refresh( $g['refresh'], 'other-client' )['body']['error'], 'wrong client' );
		$this->assertNull( $this->store->row_for( $g['access'] )['revoked_at'], 'a wrong client_id does not kill the grant' );
		$this->assertNull( $this->store->row_for( $g['refresh'] )['used_at'], 'nor does it spend the token' );
		$this->assertSame( 'invalid_grant', $this->refresh( $g['access'], $g['client_id'] )['body']['error'], 'an access token is not a refresh token' );
		$this->assertSame( 'invalid_grant', $this->refresh( 'iato_rt_nope', $g['client_id'] )['body']['error'] );
		$this->assertSame( 'invalid_grant', IATO_MCP_OAuth::token_response( [ 'grant_type' => 'refresh_token' ], self::NOW )['body']['error'] );
		$this->assertSame( 'invalid_target', $this->refresh( $g['refresh'], $g['client_id'], [ 'resource' => 'https://other.example/x' ] )['body']['error'] );
		$this->assertNotNull( $this->store->row_for( $g['refresh'] )['used_at'], 'a refresh token is spent on first presentation, like a code' );
		$this->assertNull( $this->store->find_grant( $g['grant_id'] )['revoked_at'], 'but the grant survives a single failed attempt' );

		$g2 = $this->grant();
		$r  = $this->refresh( $g2['refresh'], $g2['client_id'], [], self::NOW + 30 * 86400 + 1 );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'expired', $r['body']['error_description'] );
	}

	public function test_a_revoked_grant_refuses_tokens_whenever_they_were_created(): void {
		// A race: the grant is revoked while a refresh is issuing a new pair.
		// The new pair's rows are unrevoked, but the grant row says no.
		$g = $this->grant();
		$this->store->grants[ $g['grant_id'] ]['revoked_at'] = IATO_MCP_OAuth::ts( self::NOW + 1 );
		$this->store->tokens = array_map( fn( $t ) => [ 'revoked_at' => null ] + $t, $this->store->tokens ); // token rows untouched
		$this->assertNull( $this->store->row_for( $g['refresh'] )['revoked_at'] );
		$r = $this->refresh( $g['refresh'], $g['client_id'], [], self::NOW + 2 );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'revoked', $r['body']['error_description'] );
		$this->assertNull( $this->store->row_for( $g['refresh'] )['used_at'], 'refused before the claim: not spent' );

		$client_id = $this->client();
		$q         = $this->approve( $client_id );
		$this->store->grants[ $this->store->row_for( $q['code'] )['grant_id'] ]['revoked_at'] = IATO_MCP_OAuth::ts( self::NOW + 1 );
		$r = $this->exchange( $q['code'], $client_id, [], self::NOW + 2 );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'revoked', $r['body']['error_description'] );
		$this->assertCount( 2, array_filter( $this->store->tokens, fn( $t ) => 'code' !== $t['kind'] ), 'no new tokens were issued' );
	}

	public function test_a_token_without_a_grant_row_is_treated_as_revoked(): void {
		$g = $this->grant();
		unset( $this->store->grants[ $g['grant_id'] ] );
		$this->assertTrue( $this->store->row_for( $g['refresh'] )['grant_missing'] );
		$r = $this->refresh( $g['refresh'], $g['client_id'], [], self::NOW + 1 );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'revoked', $r['body']['error_description'] );
		$this->assertNull( $this->store->row_for( $g['refresh'] )['used_at'], 'refused before the claim' );

		$client_id = $this->client();
		$q         = $this->approve( $client_id );
		unset( $this->store->grants[ $this->store->row_for( $q['code'] )['grant_id'] ] );
		$r = $this->exchange( $q['code'], $client_id, [], self::NOW + 1 );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'revoked', $r['body']['error_description'] );
		$this->assertCount( 2, array_filter( $this->store->tokens, fn( $t ) => 'code' !== $t['kind'] ), 'nothing new was issued' );

		$this->assertTrue( IATO_MCP_OAuth::grant_is_dead( [ 'grant_missing' => true ] ) );
		$this->assertTrue( IATO_MCP_OAuth::grant_is_dead( [ 'grant_missing' => false, 'grant_revoked_at' => '2026-01-01 00:00:00' ] ) );
		$this->assertFalse( IATO_MCP_OAuth::grant_is_dead( [ 'grant_missing' => false, 'grant_revoked_at' => null ] ) );
		$this->assertTrue( IATO_MCP_OAuth::grant_is_dead( [] ), 'a row with no grant information at all is dead, not unrestricted' );
	}

	public function test_claim_is_atomic_so_a_second_consumer_sees_reuse(): void {
		$g = $this->grant();
		$row = $this->store->row_for( $g['refresh'] );
		$this->assertSame( 1, $this->store->mark_used( $row['id'], IATO_MCP_OAuth::ts( self::NOW ) ), 'first claim wins' );
		$this->assertSame( 0, $this->store->mark_used( $row['id'], IATO_MCP_OAuth::ts( self::NOW ) ), 'second claim loses' );
		$r = $this->refresh( $g['refresh'], $g['client_id'], [], self::NOW + 1 );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'already used', $r['body']['error_description'] );
		$this->assertSame( 'refresh_reuse', $this->store->find_grant( $g['grant_id'] )['revoked_reason'] );
	}

	public function test_refresh_for_a_demoted_approver_fails_and_revokes(): void {
		$g = $this->grant();
		IATO_Test_Auth_State::$users[1]['caps'] = [ 'read' => true, 'edit_posts' => true ]; // demoted
		$r = $this->refresh( $g['refresh'], $g['client_id'] );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertNotNull( $this->store->row_for( $g['access'] )['revoked_at'] );
		$this->assertSame( 'user_demoted', self::log_rows( 'revoke' )[0]['error_code'] );
	}

	public function test_absolute_grant_lifetime_forces_a_new_consent(): void {
		$g   = $this->grant();
		$day = 86400;
		// Rotate every 20 days so the 30-day refresh token itself never expires.
		for ( $d = 20; $d <= 179; $d += 20 ) {
			$this->assertSame( 200, $this->refresh( $this->latest_refresh( $g['grant_id'] ), $g['client_id'], [], self::NOW + $d * $day )['status'], "day $d: fine" );
		}
		$this->assertSame( 200, $this->refresh( $this->latest_refresh( $g['grant_id'] ), $g['client_id'], [], self::NOW + 180 * $day - 1 )['status'], 'one second before day 180: fine' );
		$r = $this->refresh( $this->latest_refresh( $g['grant_id'] ), $g['client_id'], [], self::NOW + 180 * $day );
		$this->assertSame( 'invalid_grant', $r['body']['error'] );
		$this->assertStringContainsString( 'approve the connection again', $r['body']['error_description'] );
		$this->assertSame( 'grant_lifetime', self::log_rows( 'revoke' )[0]['error_code'] );
		foreach ( $this->store->tokens as $t ) {
			$this->assertNotNull( $t['revoked_at'], 'every row of the grant is revoked' );
		}
	}

	public function test_grant_lifetime_is_filterable(): void {
		add_filter( 'iato_mcp_oauth_grant_lifetime', fn( int $l ) => 86400 );
		$g = $this->grant();
		$this->assertSame( 200, $this->refresh( $g['refresh'], $g['client_id'], [], self::NOW + 3600 )['status'] );
		$this->assertSame( 'invalid_grant', $this->refresh( $this->latest_refresh( $g['grant_id'] ), $g['client_id'], [], self::NOW + 86400 )['body']['error'] );

		IATO_Test_Abilities_State::$filters = [];
		add_filter( 'iato_mcp_oauth_grant_lifetime', fn( int $l ) => 0 );
		$g2 = $this->grant();
		for ( $d = 20; $d <= 400; $d += 20 ) {
			$this->assertSame( 200, $this->refresh( $this->latest_refresh( $g2['grant_id'] ), $g2['client_id'], [], self::NOW + $d * 86400 )['status'], "0 disables the absolute lifetime (day $d)" );
		}
	}

	/** The live raw refresh token of a grant (the store only holds hashes, so refresh() records raw values). */
	private function latest_refresh( string $grant_id ): string {
		foreach ( $this->raw_refresh as $raw ) {
			$row = $this->store->row_for( $raw );
			if ( $row && $row['grant_id'] === $grant_id && null === $row['used_at'] && null === $row['revoked_at'] ) {
				return $raw;
			}
		}
		$this->fail( 'no live refresh token recorded for grant' );
	}

	/** @var string[] raw refresh tokens seen in responses (see refresh()) */
	private array $raw_refresh = [];

	// ── revocation ────────────────────────────────────────────────────────

	public function test_revocation_endpoint_revokes_the_grant_for_either_token(): void {
		$g = $this->grant();
		$r = IATO_MCP_OAuth::revoke_response( [ 'token' => $g['access'], 'client_id' => $g['client_id'] ], self::NOW );
		$this->assertSame( 200, $r['status'] );
		$this->assertNotNull( $this->store->row_for( $g['access'] )['revoked_at'] );
		$this->assertNotNull( $this->store->row_for( $g['refresh'] )['revoked_at'], 'refresh dies with the access token' );
		$this->assertSame( 'endpoint', self::log_rows( 'revoke' )[0]['request_args']['reason'] );
		$this->assertSame( 'endpoint', $this->store->find_grant( $g['grant_id'] )['revoked_reason'], 'the grant row carries the reason' );

		$g2 = $this->grant();
		IATO_MCP_OAuth::revoke_response( [ 'token' => $g2['refresh'], 'token_type_hint' => 'refresh_token' ], self::NOW );
		$this->assertNotNull( $this->store->row_for( $g2['access'] )['revoked_at'] );
		$this->assertSame( 'invalid_grant', $this->refresh( $g2['refresh'], $g2['client_id'] )['body']['error'] );
	}

	public function test_revocation_endpoint_is_quiet_about_unknown_or_foreign_tokens(): void {
		$g = $this->grant();
		$this->assertSame( 200, IATO_MCP_OAuth::revoke_response( [ 'token' => 'iato_at_nope' ], self::NOW )['status'] );
		$this->assertSame( 200, IATO_MCP_OAuth::revoke_response( [], self::NOW )['status'] );
		$this->assertSame( 200, IATO_MCP_OAuth::revoke_response( [ 'token' => $g['access'], 'client_id' => 'someone-else' ], self::NOW )['status'] );
		$this->assertNull( $this->store->row_for( $g['access'] )['revoked_at'], 'client_id mismatch: no-op' );
		$this->assertSame( 200, IATO_MCP_OAuth::revoke_response( [ 'token' => self::KEY ], self::NOW )['status'], 'the site key is not an OAuth token' );
		$this->assertSame( [], self::log_rows( 'revoke' ) );
	}

	public function test_admin_revoke_and_deleted_user_hook(): void {
		$g1 = $this->grant( 1 );
		$g2 = $this->grant( 2 );
		$this->assertTrue( IATO_MCP_OAuth::revoke_grant_by_admin( $g1['grant_id'], 2, self::NOW ) );
		$this->assertFalse( IATO_MCP_OAuth::revoke_grant_by_admin( $g1['grant_id'], 2, self::NOW ), 'already revoked' );
		$this->assertFalse( IATO_MCP_OAuth::revoke_grant_by_admin( 'not-a-grant', 2, self::NOW ) );
		$this->assertNull( $this->store->row_for( $g2['access'] )['revoked_at'], 'other grant untouched' );
		$log = self::log_rows( 'revoke' );
		$this->assertSame( [ 'reason' => 'admin', 'by' => 2, 'client_id' => $g1['client_id'], 'grant_id' => $g1['grant_id'] ], $log[0]['request_args'] );
		$this->assertSame( 1, $log[0]['auth_user_id'], 'attributed to the approver whose grant it was' );

		IATO_MCP_OAuth::on_deleted_user( 2 );
		$this->assertNotNull( $this->store->row_for( $g2['access'] )['revoked_at'] );
		$this->assertSame( 'user_deleted', self::log_rows( 'revoke' )[1]['request_args']['reason'] );
		$this->assertSame( 'admin', $this->store->find_grant( $g1['grant_id'] )['revoked_reason'] );
		$this->assertSame( 'user_deleted', $this->store->find_grant( $g2['grant_id'] )['revoked_reason'] );
	}

	public function test_disconnect_all_revokes_every_live_grant_and_pending_code(): void {
		$g1 = $this->grant( 1 );
		$g2 = $this->grant( 2 );
		$client_id = $this->client();
		$pending   = $this->approve( $client_id ); // code issued, not yet exchanged
		$old       = $this->grant( 1, self::NOW - 40 * 86400 ); // long expired: not counted
		$this->refresh( $g1['refresh'], $g1['client_id'], [], self::NOW + 1 ); // g1 rotated: still one grant

		$this->assertSame( 2, IATO_MCP_OAuth::revoke_all_grants_by_admin( 2, self::NOW + 2 ), 'two live grants (the pending code is not a grant yet, the expired one is dead)' );
		$this->assertNotNull( $this->store->row_for( $g2['access'] )['revoked_at'] );
		$this->assertNotNull( $this->store->row_for( $this->latest_refresh_raw() )['revoked_at'], 'the rotated refresh token of g1 is dead' );
		$this->assertSame( 'invalid_grant', $this->refresh( $g2['refresh'], $g2['client_id'], [], self::NOW + 3 )['body']['error'] );
		$r = $this->exchange( $pending['code'], $client_id, [], self::NOW + 3 );
		$this->assertSame( 'invalid_grant', $r['body']['error'], 'a code issued before "disconnect all" cannot be exchanged afterwards' );
		$this->assertSame( [], $this->store->list_active_grants( IATO_MCP_OAuth::ts( self::NOW + 3 ) ) );
		$this->assertNull( $this->store->row_for( $old['access'] ), 'expired rows were pruned earlier, not touched here' );

		$log = self::log_rows( 'revoke' );
		$this->assertCount( 1, $log );
		$this->assertSame( [ 'reason' => 'admin_all', 'by' => 2, 'grants' => 2 ], $log[0]['request_args'] );
		$this->assertSame( 'admin_all', $this->store->find_grant( $g2['grant_id'] )['revoked_reason'] );
		$this->assertSame( 'admin_all', $this->store->find_grant( $this->store->row_for( $pending['code'] )['grant_id'] )['revoked_reason'], 'the pending authorization is revoked too' );
		$this->assertSame( 2, $log[0]['auth_user_id'] );

		$this->assertSame( 0, IATO_MCP_OAuth::revoke_all_grants_by_admin( 2, self::NOW + 4 ), 'nothing left' );
		$this->assertCount( 1, self::log_rows( 'revoke' ), 'a no-op is not logged' );

		$g3 = $this->grant( 1, self::NOW + 10 );
		$this->assertSame( 200, $this->refresh( $g3['refresh'], $g3['client_id'], [], self::NOW + 11 )['status'], 'new connections work afterwards' );
	}

	/** The most recently recorded raw refresh token (any grant). */
	private function latest_refresh_raw(): string {
		return end( $this->raw_refresh );
	}

	// ── listing ───────────────────────────────────────────────────────────

	public function test_active_grants_listing(): void {
		$this->assertSame( [], $this->store->list_active_grants( IATO_MCP_OAuth::ts( self::NOW ) ) );
		$g = $this->grant();
		$this->store->touch_last_used( $this->store->row_for( $g['access'] )['id'], IATO_MCP_OAuth::ts( self::NOW + 5 ) );
		$list = $this->store->list_active_grants( IATO_MCP_OAuth::ts( self::NOW + 10 ) );
		$this->assertCount( 1, $list );
		$this->assertSame( $g['grant_id'], $list[0]['grant_id'] );
		$this->assertSame( 'Claude', $list[0]['client_name'] );
		$this->assertSame( self::CB, $list[0]['redirect_uri'] );
		$this->assertSame( 1, $list[0]['user_id'] );
		$this->assertSame( IATO_MCP_OAuth::ts( self::NOW ), $list[0]['connected_at'] );
		$this->assertSame( IATO_MCP_OAuth::ts( self::NOW + 5 ), $list[0]['last_used_at'] );

		$this->refresh( $g['refresh'], $g['client_id'], [], self::NOW + 100 );
		$this->assertCount( 1, $this->store->list_active_grants( IATO_MCP_OAuth::ts( self::NOW + 200 ) ), 'rotation keeps one entry' );
		$this->assertCount( 0, $this->store->list_active_grants( IATO_MCP_OAuth::ts( self::NOW + 100 + 31 * 86400 ) ), 'everything expired' );
		IATO_MCP_OAuth::revoke_grant_by_admin( $g['grant_id'], 1, self::NOW + 200 );
		$this->assertSame( [], $this->store->list_active_grants( IATO_MCP_OAuth::ts( self::NOW + 201 ) ) );
	}

	public function test_token_rows_are_pruned_after_the_retention_window(): void {
		$g = $this->grant();
		$this->assertCount( 3, $this->store->tokens ); // code + access + refresh
		$this->grant( 1, self::NOW + 31 * 86400 + 3600 ); // code and access of the first grant expired > 30 days ago
		$this->assertNull( $this->store->row_for( $g['access'] ) );
		$this->assertNotNull( $this->store->row_for( $g['refresh'] ), 'the 30-day refresh token has not been expired for 30 days yet' );
	}
}
