<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * Token binding (Sprint C0 Part 1): the site key records an owner, bound
 * Bearer requests run as that owner, missing/demoted owners are rejected,
 * and unbound legacy keys keep today's user-0 behaviour.
 */
final class AuthBindingTest extends TestCase {

	private const KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk'; // wp_generate_password stub output

	protected function setUp(): void {
		IATO_Test_Abilities_State::reset();
		IATO_Test_Auth_State::reset();
		$this->reset_auth_statics();
		IATO_Test_Abilities_State::$options['iato_mcp_key'] = self::KEY;
		IATO_Test_Auth_State::$users = [
			1 => [ 'login' => 'admin',   'caps' => [ 'read' => true, 'edit_posts' => true, 'manage_options' => true, 'unfiltered_html' => true ], 'exists' => true ],
			5 => [ 'login' => 'editor',  'caps' => [ 'read' => true, 'edit_posts' => true ], 'exists' => true ],
		];
	}

	private function reset_auth_statics(): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		foreach ( [ 'authenticated' => false, 'authenticated_user' => null, 'legacy_unbound' => false, 'kses_locked' => false, 'rejection_logged' => false, 'site_key_full_access' => false, 'credential' => [], 'token_refused' => false, 'oauth_token_cache' => [], 'unfiltered_html_allowed' => false ] as $name => $value ) {
			if ( $ref->hasProperty( $name ) ) {
				$ref->getProperty( $name )->setValue( null, $value );
			}
		}
	}

	private static function bearer( string $key = self::KEY ): WP_REST_Request {
		return new WP_REST_Request( [ 'Authorization' => 'Bearer ' . $key ] );
	}

	private static function bind( int $user_id ): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Auth::OWNER_OPTION ] = $user_id;
	}

	// ── Owner recorded ────────────────────────────────────────────────────

	public function test_generate_key_records_the_generating_user_as_owner(): void {
		unset( IATO_Test_Abilities_State::$options['iato_mcp_key'] );
		IATO_Test_Auth_State::$current_user_id = 1;
		IATO_MCP_Auth::maybe_generate_key();
		$this->assertSame( self::KEY, IATO_Test_Abilities_State::$options['iato_mcp_key'] );
		$this->assertSame( 1, IATO_MCP_Auth::key_owner_id() );
	}

	public function test_generate_key_without_a_user_leaves_the_key_unbound(): void {
		unset( IATO_Test_Abilities_State::$options['iato_mcp_key'] );
		IATO_Test_Auth_State::$current_user_id = 0; // WP-CLI activation
		IATO_MCP_Auth::maybe_generate_key();
		$this->assertSame( 0, IATO_MCP_Auth::key_owner_id() );
	}

	public function test_generate_key_is_a_noop_when_a_key_exists(): void {
		self::bind( 5 );
		IATO_Test_Auth_State::$current_user_id = 1;
		IATO_MCP_Auth::maybe_generate_key();
		$this->assertSame( 5, IATO_MCP_Auth::key_owner_id(), 'existing binding untouched' );
	}

	public function test_rotate_key_rebinds_to_the_rotating_user(): void {
		self::bind( 5 );
		IATO_Test_Auth_State::$current_user_id = 1;
		IATO_Test_Abilities_State::$options['iato_mcp_oauth_legacy_key']    = '1';
		IATO_Test_Abilities_State::$options['iato_mcp_oauth_legacy_notice'] = '1';
		IATO_MCP_Auth::rotate_key();
		$this->assertSame( 1, IATO_MCP_Auth::key_owner_id() );
		$this->assertArrayNotHasKey( 'iato_mcp_oauth_legacy_key', IATO_Test_Abilities_State::$options, 'a new key is what invalidates pre-1.13.0 OAuth connections' );
		$this->assertArrayNotHasKey( 'iato_mcp_oauth_legacy_notice', IATO_Test_Abilities_State::$options );
	}

	public function test_bind_key_owner_helper_logs_every_change(): void {
		IATO_Test_Auth_State::$current_user_id = 1;
		IATO_MCP_Auth::bind_key_owner( 5 );
		$this->assertSame( 5, IATO_MCP_Auth::key_owner_id() );
		IATO_MCP_Auth::bind_key_owner( 5 ); // no change, no log row
		IATO_MCP_Auth::bind_key_owner( 0 );
		$this->assertSame( 0, IATO_MCP_Auth::key_owner_id() );

		$rows = array_values( array_filter( IATO_Test_Auth_State::$call_log, fn( $r ) => 'owner' === $r['rpc_method'] ) );
		$this->assertCount( 2, $rows, 'one row per actual change' );
		$this->assertSame( [ 'from' => 0, 'to' => 5, 'by' => 1 ], $rows[0]['request_args'] );
		$this->assertSame( [ 'from' => 5, 'to' => 0, 'by' => 1 ], $rows[1]['request_args'] );
		$this->assertSame( 1, $rows[0]['auth_user_id'] );
	}

	public function test_generate_and_rotate_log_the_owner_change(): void {
		unset( IATO_Test_Abilities_State::$options['iato_mcp_key'] );
		IATO_Test_Auth_State::$current_user_id = 1;
		IATO_MCP_Auth::maybe_generate_key();
		IATO_Test_Auth_State::$current_user_id = 5;
		IATO_MCP_Auth::rotate_key();
		$rows = array_values( array_filter( IATO_Test_Auth_State::$call_log, fn( $r ) => 'owner' === $r['rpc_method'] ) );
		$this->assertSame( [ [ 'from' => 0, 'to' => 1, 'by' => 1 ], [ 'from' => 1, 'to' => 5, 'by' => 5 ] ], array_column( $rows, 'request_args' ) );
	}

	// ── Bound Bearer runs as the owner ────────────────────────────────────

	public function test_bound_bearer_sets_the_current_user_and_enforces_caps(): void {
		self::bind( 1 );
		$this->assertTrue( IATO_MCP_Auth::authenticate( self::bearer() ) );
		$this->assertSame( [ 1 ], IATO_Test_Auth_State::$set_current_user_calls, 'wp_set_current_user called once with the owner' );
		$this->assertSame( 1, get_current_user_id() );
		$this->assertFalse( IATO_MCP_Auth::is_unbound() );
		$this->assertTrue( IATO_MCP_Auth::require_bound_user() );
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'edit_posts' ) );
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'manage_options' ) );
		$err = IATO_MCP_Auth::require_cap( 'edit_theme_options' );
		$this->assertInstanceOf( WP_Error::class, $err, 'caps the owner lacks are now really enforced' );
	}

	public function test_bound_bearer_denies_unfiltered_html_for_the_request(): void {
		self::bind( 1 );
		IATO_MCP_Auth::authenticate( self::bearer() );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ) );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'edit_css' ], 'edit_css', 1, [] ) );
		$this->assertSame( [ 'edit_posts' ], apply_filters( 'map_meta_cap', [ 'edit_posts' ], 'edit_posts', 1, [] ), 'other caps untouched' );
	}

	public function test_kses_lock_is_off_outside_a_bearer_request(): void {
		// Filter is only attached by authenticate(); a fresh request has no lock.
		$this->assertSame( [ 'unfiltered_html' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ) );
		$this->assertSame( [ 'unfiltered_html' ], IATO_MCP_Auth::filter_map_meta_cap( [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'callback is a no-op when unlocked' );
	}

	// ── Owner missing / demoted ───────────────────────────────────────────

	public function test_owner_missing_is_rejected(): void {
		self::bind( 9 ); // no such user
		$err = IATO_MCP_Auth::authenticate( self::bearer() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'iato_mcp_owner_missing', $err->get_error_code() );
		$this->assertSame( 403, $err->get_error_data()['status'] );
		$this->assertStringContainsString( 'Settings > IATO MCP', $err->get_error_message() );
		$this->assertSame( [], IATO_Test_Auth_State::$set_current_user_calls );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'read' ), 'nothing is authenticated' );
		$this->assertCount( 1, IATO_Test_Auth_State::$call_log );
		$this->assertSame( 'iato_mcp_owner_missing', IATO_Test_Auth_State::$call_log[0]['error_code'] );
		$this->assertSame( 9, IATO_Test_Auth_State::$call_log[0]['auth_user_id'] );
	}

	public function test_owner_demoted_is_rejected(): void {
		self::bind( 5 ); // editor: no manage_options
		$err = IATO_MCP_Auth::authenticate( self::bearer() );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'iato_mcp_owner_demoted', $err->get_error_code() );
		$this->assertStringContainsString( 'editor', $err->get_error_message() );
		$this->assertStringContainsString( 'manage_options', $err->get_error_message() );
		$this->assertSame( [], IATO_Test_Auth_State::$set_current_user_calls );
		$this->assertSame( 'iato_mcp_owner_demoted', IATO_Test_Auth_State::$call_log[0]['error_code'] );
	}

	public function test_wrong_key_is_still_401_before_any_owner_logic(): void {
		self::bind( 1 );
		$err = IATO_MCP_Auth::authenticate( self::bearer( 'wrong' ) );
		$this->assertSame( 'iato_mcp_unauthorized', $err->get_error_code() );
		$this->assertSame( [], IATO_Test_Auth_State::$set_current_user_calls );
	}

	// ── Unbound legacy key ────────────────────────────────────────────────

	public function test_unbound_legacy_key_runs_as_user_0_exactly_as_before(): void {
		// no owner option at all
		$this->assertTrue( IATO_MCP_Auth::authenticate( self::bearer() ) );
		$this->assertSame( [], IATO_Test_Auth_State::$set_current_user_calls );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'manage_options' ), 'legacy: any cap passes, as today' );
		$this->assertTrue( IATO_MCP_Auth::is_unbound() );
		$err = IATO_MCP_Auth::require_bound_user();
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'iato_mcp_token_unbound', $err->get_error_code() );
		$this->assertStringContainsString( 'Assign an owner or regenerate', $err->get_error_message() );
		$this->assertSame( [ 'unfiltered_html' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 0, [] ), 'no kses lock needed for user 0' );
	}

	// ── require_cap() decides on explicit state, not on user 0 ───────────

	public function test_unauthenticated_call_to_require_cap_is_refused(): void {
		// No authenticate() call at all: user 0 just like a site-key request,
		// but nothing is granted.
		$this->assertSame( 0, get_current_user_id() );
		foreach ( [ 'read', 'edit_posts', 'manage_options' ] as $cap ) {
			$err = IATO_MCP_Auth::require_cap( $cap );
			$this->assertInstanceOf( WP_Error::class, $err, $cap );
			$this->assertSame( 'iato_mcp_forbidden', $err->get_error_code() );
			$this->assertSame( 403, $err->get_error_data()['status'] );
		}
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_bound_user() );
	}

	public function test_authenticated_without_user_and_without_site_key_flag_is_refused(): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, true );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'read' ), '$authenticated alone grants nothing' );
	}

	public function test_only_the_unbound_site_key_path_sets_full_access(): void {
		$ref  = new ReflectionClass( IATO_MCP_Auth::class );
		$flag = fn() => $ref->getProperty( 'site_key_full_access' )->getValue();

		IATO_MCP_Auth::authenticate( self::bearer() ); // unbound
		$this->assertTrue( $flag() );

		$this->reset_auth_statics();
		self::bind( 1 );
		IATO_MCP_Auth::authenticate( self::bearer() ); // bound: real user, no blanket access
		$this->assertFalse( $flag() );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'edit_theme_options' ) );
	}

	// ── Application Password path unchanged ──────────────────────────────

	public function test_application_password_path_is_untouched(): void {
		self::bind( 1 );
		IATO_Test_Auth_State::$logged_in       = true;
		IATO_Test_Auth_State::$current_user_id = 5;
		$req = new WP_REST_Request( [ 'Authorization' => 'Basic ZWRpdG9yOnB3' ] );
		$this->assertTrue( IATO_MCP_Auth::authenticate( $req ) );
		$this->assertSame( [], IATO_Test_Auth_State::$set_current_user_calls, 'owner is not applied to App Password requests' );
		$this->assertSame( 5, get_current_user_id() );
		$this->assertFalse( IATO_MCP_Auth::is_unbound() );
		$this->assertTrue( IATO_MCP_Auth::require_bound_user() );
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'edit_posts' ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'manage_options' ) );
	}

	// ── Application Password: kses lock by default (1.13.0) ───────────────

	private function app_password_request( int $user_id ): WP_REST_Request {
		IATO_Test_Auth_State::$logged_in       = true;
		IATO_Test_Auth_State::$current_user_id = $user_id;
		return new WP_REST_Request( [ 'Authorization' => 'Basic ZWRpdG9yOnB3' ] );
	}

	public function test_application_password_is_kses_locked_by_default(): void {
		$this->assertTrue( IATO_MCP_Auth::authenticate( $this->app_password_request( 1 ) ) ); // admin holds unfiltered_html
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'locked like a bound key' );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'edit_css' ], 'edit_css', 1, [] ) );
		$this->assertFalse( IATO_MCP_Auth::unfiltered_html_allowed() );
	}

	public function test_filter_allows_unfiltered_html_only_for_users_who_hold_it(): void {
		$seen = [];
		add_filter( 'iato_mcp_allow_unfiltered_html', function ( bool $allow, int $user_id, string $kind ) use ( &$seen ) {
			$seen[] = [ $user_id, $kind ];
			return true;
		}, 10, 3 );

		$this->assertTrue( IATO_MCP_Auth::authenticate( $this->app_password_request( 1 ) ) );
		$this->assertTrue( IATO_MCP_Auth::unfiltered_html_allowed(), 'admin with unfiltered_html + filter true' );
		$this->assertSame( [ 'unfiltered_html' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'no lock' );
		$this->assertSame( [ [ 1, 'application_password' ] ], $seen, 'filter receives the user id and the credential kind' );

		$this->reset_auth_statics();
		$this->assertTrue( IATO_MCP_Auth::authenticate( $this->app_password_request( 5 ) ) ); // editor lacks the cap in this fixture
		$this->assertFalse( IATO_MCP_Auth::unfiltered_html_allowed(), 'the filter cannot grant a capability the user lacks' );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 5, [] ) );
	}

	public function test_write_unlocked_lifts_the_kses_lock_for_the_callback_only(): void {
		self::bind( 1 );
		IATO_MCP_Auth::authenticate( self::bearer() );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ) );
		$inside = IATO_MCP_Auth::write_unlocked( fn() => apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ) );
		$this->assertSame( [ 'unfiltered_html' ], $inside, 'the real capability shows through during the write' );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'locked again afterwards' );
		try {
			IATO_MCP_Auth::write_unlocked( function () { throw new RuntimeException( 'boom' ); } );
			$this->fail( 'exception should propagate' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'relocked even when the write throws' );
		}
		$this->assertSame( 'x', IATO_MCP_Auth::write_unlocked( fn() => 'x' ), 'returns the callback result' );
	}

	public function test_site_key_and_oauth_never_get_unfiltered_html(): void {
		add_filter( 'iato_mcp_allow_unfiltered_html', fn() => true, 10, 3 );
		self::bind( 1 );
		$this->assertTrue( IATO_MCP_Auth::authenticate( self::bearer() ) );
		$this->assertFalse( IATO_MCP_Auth::unfiltered_html_allowed() );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ) );
	}
}
