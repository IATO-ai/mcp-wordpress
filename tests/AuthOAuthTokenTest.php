<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * IATO_MCP_Auth::authenticate() with OAuth access tokens (1.13.0): a live
 * token runs as its approving administrator exactly like a bound site key;
 * expired, revoked, junk, user-missing and user-demoted tokens get 401, the
 * last two also revoke the grant and are logged; the site-key and
 * Application Password paths are unchanged.
 */
final class AuthOAuthTokenTest extends TestCase {

	private const KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

	private IATO_Test_OAuth_Store $store;

	protected function setUp(): void {
		IATO_Test_Abilities_State::reset();
		IATO_Test_Auth_State::reset();
		IATO_Test_OAuth_State::reset();
		$this->reset_auth_statics();
		$this->store = IATO_Test_OAuth_Store::install();
		if ( class_exists( 'IATO_Test_Roles' ) ) {
			IATO_Test_Roles::$users = [];
		}
		IATO_Test_Abilities_State::$options['iato_mcp_key'] = self::KEY;
		IATO_Test_Auth_State::$users = [
			1 => [ 'login' => 'admin',  'caps' => [ 'read' => true, 'edit_posts' => true, 'manage_options' => true, 'unfiltered_html' => true ], 'exists' => true ],
			5 => [ 'login' => 'editor', 'caps' => [ 'read' => true, 'edit_posts' => true ], 'exists' => true ],
		];
	}

	protected function tearDown(): void {
		IATO_MCP_OAuth_Store::set_instance( null );
	}

	private function reset_auth_statics(): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		foreach ( [ 'authenticated' => false, 'authenticated_user' => null, 'legacy_unbound' => false, 'kses_locked' => false, 'rejection_logged' => false, 'site_key_full_access' => false, 'credential' => [], 'token_refused' => false, 'oauth_token_cache' => [] ] as $name => $value ) {
			$ref->getProperty( $name )->setValue( null, $value );
		}
	}

	private static function bearer( string $token ): WP_REST_Request {
		return new WP_REST_Request( [ 'Authorization' => 'Bearer ' . $token ] );
	}

	/** Insert a grant (access + refresh) and return the raw access token. */
	private function issue( int $user_id = 1, int $expires_in = 3600, string $grant_id = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1' ): string {
		$access  = IATO_MCP_Auth::OAUTH_ACCESS_PREFIX . IATO_MCP_OAuth::random_string( 32 );
		$refresh = IATO_MCP_Auth::OAUTH_REFRESH_PREFIX . IATO_MCP_OAuth::random_string( 32 );
		$base    = [ 'grant_id' => $grant_id, 'client_id' => 'client-x', 'user_id' => $user_id, 'created_at' => IATO_MCP_OAuth::ts( time() ) ];
		if ( null === $this->store->find_grant( $grant_id ) ) {
			$this->store->insert_grant( [ 'grant_id' => $grant_id, 'client_id' => 'client-x', 'user_id' => $user_id, 'redirect_uri' => 'https://claude.ai/cb', 'granted_at' => IATO_MCP_OAuth::ts( time() ) ] );
		}
		$this->store->insert_token( $base + [ 'kind' => 'access', 'token_hash' => IATO_MCP_OAuth::hash_token( $access ), 'expires_at' => IATO_MCP_OAuth::ts( time() + $expires_in ) ] );
		$this->store->insert_token( $base + [ 'kind' => 'refresh', 'token_hash' => IATO_MCP_OAuth::hash_token( $refresh ), 'expires_at' => IATO_MCP_OAuth::ts( time() + 86400 ) ] );
		return $access;
	}

	public function test_live_token_runs_as_the_approving_administrator(): void {
		$token = $this->issue();
		$this->assertTrue( IATO_MCP_Auth::authenticate( self::bearer( $token ) ) );
		$this->assertSame( [ 1 ], IATO_Test_Auth_State::$set_current_user_calls );
		$this->assertSame( 1, get_current_user_id() );
		$this->assertFalse( IATO_MCP_Auth::is_unbound() );
		$this->assertTrue( IATO_MCP_Auth::require_bound_user() );
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'manage_options' ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'edit_theme_options' ), 'caps are really enforced' );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'kses lock as for a bound key' );
		$this->assertSame( [ 'kind' => 'oauth', 'user_id' => 1, 'client_id' => 'client-x', 'grant_id' => 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1' ], IATO_MCP_Auth::credential() );
		$this->assertFalse( IATO_MCP_Auth::token_was_refused() );
		$this->assertNotNull( $this->store->row_for( $token )['last_used_at'], 'last used recorded' );
		$this->assertSame( [], IATO_Test_Auth_State::$call_log, 'a normal request is not logged by auth' );
	}

	public function test_lookup_is_memoised_and_last_used_is_throttled(): void {
		$token = $this->issue();
		IATO_MCP_Auth::authenticate( self::bearer( $token ) );
		IATO_MCP_Auth::authenticate( self::bearer( $token ) ); // WP calls permission_callback more than once
		$this->assertSame( 1, $this->store->find_calls );
		$first = $this->store->row_for( $token )['last_used_at'];
		$this->assertSame( [ 1, 1 ], IATO_Test_Auth_State::$set_current_user_calls );
		$this->assertSame( $first, $this->store->row_for( $token )['last_used_at'], 'written once within a minute' );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'dead_tokens' )]
	public function test_dead_tokens_get_401_without_side_effects( string $case ): void {
		$token = $this->issue();
		switch ( $case ) {
			case 'expired':
				$this->store->set( $token, 'expires_at', IATO_MCP_OAuth::ts( time() - 1 ) );
				break;
			case 'revoked':
				$this->store->set( $token, 'revoked_at', IATO_MCP_OAuth::ts( time() - 1 ) );
				break;
			case 'junk':
				$token = IATO_MCP_Auth::OAUTH_ACCESS_PREFIX . 'not-a-real-token';
				break;
			case 'refresh-as-access':
				$this->store->set( $token, 'kind', 'refresh' );
				break;
		}
		$err = IATO_MCP_Auth::authenticate( self::bearer( $token ) );
		$this->assertInstanceOf( WP_Error::class, $err, $case );
		$this->assertSame( 'iato_mcp_unauthorized', $err->get_error_code() );
		$this->assertSame( 401, $err->get_error_data()['status'] );
		$this->assertTrue( IATO_MCP_Auth::token_was_refused() );
		$this->assertSame( [], IATO_Test_Auth_State::$set_current_user_calls );
		$this->assertSame( [], IATO_MCP_Auth::credential() );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'read' ) );
		$this->assertSame( [], IATO_Test_Auth_State::$call_log, 'routine refusals are not logged' );
	}

	public static function dead_tokens(): array {
		return [ [ 'expired' ], [ 'revoked' ], [ 'junk' ], [ 'refresh-as-access' ] ];
	}

	public function test_revoked_grant_refuses_a_token_whose_own_row_is_untouched(): void {
		$token = $this->issue();
		$this->store->grants['a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1']['revoked_at'] = IATO_MCP_OAuth::ts( time() - 1 );
		$this->assertNull( $this->store->row_for( $token )['revoked_at'], 'token row itself is not marked' );
		$err = IATO_MCP_Auth::authenticate( self::bearer( $token ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 401, $err->get_error_data()['status'] );
		$this->assertStringContainsString( 'revoked', $err->get_error_message() );
		$this->assertSame( [], IATO_Test_Auth_State::$set_current_user_calls );
	}

	public function test_token_without_a_grant_row_is_refused_as_revoked(): void {
		$token = $this->issue();
		unset( $this->store->grants['a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'] );
		$err = IATO_MCP_Auth::authenticate( self::bearer( $token ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 401, $err->get_error_data()['status'] );
		$this->assertStringContainsString( 'revoked', $err->get_error_message() );
		$this->assertTrue( IATO_MCP_Auth::token_was_refused() );
		$this->assertSame( [], IATO_Test_Auth_State::$set_current_user_calls );
	}

	public function test_missing_user_is_refused_and_the_grant_is_revoked(): void {
		$token = $this->issue( 42 ); // no such user
		$err   = IATO_MCP_Auth::authenticate( self::bearer( $token ) );
		$this->assertSame( 'iato_mcp_unauthorized', $err->get_error_code() );
		$this->assertSame( 401, $err->get_error_data()['status'] );
		$this->assertStringContainsString( 'no longer exists', $err->get_error_message() );
		foreach ( $this->store->tokens as $t ) {
			$this->assertNotNull( $t['revoked_at'], 'both rows of the grant revoked' );
		}
		$this->assertSame( 'user_missing', $this->store->find_grant( 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1' )['revoked_reason'] );
		$this->assertCount( 1, IATO_Test_Auth_State::$call_log );
		$log = IATO_Test_Auth_State::$call_log[0];
		$this->assertSame( [ 'auth', 'iato_mcp_token_user_missing', 42 ], [ $log['rpc_method'], $log['error_code'], $log['auth_user_id'] ] );
		$this->assertSame( 'client-x', $log['request_args']['client_id'] );
		$this->assertSame( 'iato_mcp_unauthorized', IATO_MCP_Auth::authenticate( self::bearer( $token ) )->get_error_code(), 'and stays refused' );
	}

	public function test_demoted_user_is_refused_and_the_grant_is_revoked(): void {
		$token = $this->issue( 5 ); // editor
		$err   = IATO_MCP_Auth::authenticate( self::bearer( $token ) );
		$this->assertSame( 401, $err->get_error_data()['status'] );
		$this->assertStringContainsString( 'editor', $err->get_error_message() );
		$this->assertStringContainsString( 'manage_options', $err->get_error_message() );
		$this->assertNotNull( $this->store->row_for( $token )['revoked_at'] );
		$this->assertSame( 'iato_mcp_token_user_demoted', IATO_Test_Auth_State::$call_log[0]['error_code'] );
		$this->assertSame( [], IATO_Test_Auth_State::$set_current_user_calls );
	}

	public function test_site_key_and_refresh_prefix_follow_the_site_key_path(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Auth::OWNER_OPTION ] = 1;
		$this->assertTrue( IATO_MCP_Auth::authenticate( self::bearer( self::KEY ) ) );
		$this->assertSame( [ 'kind' => 'site_key', 'user_id' => 1 ], IATO_MCP_Auth::credential() );
		$this->assertSame( 0, $this->store->find_calls, 'the site key never touches the OAuth store' );

		$this->reset_auth_statics();
		$err = IATO_MCP_Auth::authenticate( self::bearer( IATO_MCP_Auth::OAUTH_REFRESH_PREFIX . 'abc' ) );
		$this->assertSame( 'iato_mcp_unauthorized', $err->get_error_code(), 'a refresh token is not a bearer credential' );
		$this->assertTrue( IATO_MCP_Auth::token_was_refused() );

		$this->reset_auth_statics();
		unset( IATO_Test_Abilities_State::$options[ IATO_MCP_Auth::OWNER_OPTION ] );
		$this->assertTrue( IATO_MCP_Auth::authenticate( self::bearer( self::KEY ) ) );
		$this->assertSame( [ 'kind' => 'site_key', 'user_id' => 0 ], IATO_MCP_Auth::credential(), 'unbound legacy key' );
	}

	public function test_oauth_token_works_even_when_no_site_key_is_configured(): void {
		unset( IATO_Test_Abilities_State::$options['iato_mcp_key'] );
		$this->assertTrue( IATO_MCP_Auth::authenticate( self::bearer( $this->issue() ) ) );
		$this->reset_auth_statics();
		$this->assertSame( 'iato_mcp_not_configured', IATO_MCP_Auth::authenticate( self::bearer( 'anything' ) )->get_error_code(), 'site-key path unchanged' );
	}

	public function test_application_password_reports_its_credential_kind(): void {
		IATO_Test_Auth_State::$logged_in       = true;
		IATO_Test_Auth_State::$current_user_id = 5;
		$this->assertTrue( IATO_MCP_Auth::authenticate( new WP_REST_Request( [ 'Authorization' => 'Basic ZWRpdG9yOnB3' ] ) ) );
		$this->assertSame( [ 'kind' => 'application_password', 'user_id' => 5 ], IATO_MCP_Auth::credential() );
	}
}
