<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';

/**
 * require_cap() decides on explicit authentication state, never on "the
 * current user is 0" (true for an unauthenticated visitor as well as for a
 * site-key request).
 */
final class AuthRequireCapTest extends TestCase {

	private function set( bool $authenticated, ?int $user_id, bool $site_key ): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, $authenticated );
		$ref->getProperty( 'authenticated_user' )->setValue( null, $user_id ? new WP_User( $user_id ) : null );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, $site_key );
	}

	protected function setUp(): void {
		IATO_Test_Roles::$users = [ 1 => 'administrator', 8 => 'contributor' ];
		IATO_Test_Roles::$posts = [ 10 => [ 'post_type' => 'page', 'post_author' => 1, 'post_status' => 'publish' ] ];
		$this->set( false, null, false );
	}

	public function test_unauthenticated_call_is_refused(): void {
		foreach ( [ 'read', 'edit_posts', 'manage_options' ] as $cap ) {
			$err = IATO_MCP_Auth::require_cap( $cap );
			$this->assertInstanceOf( WP_Error::class, $err, $cap );
			$this->assertSame( 'iato_mcp_forbidden', $err->get_error_code() );
			$this->assertSame( 403, $err->get_error_data()['status'] );
		}
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'edit_post', 10 ) );
	}

	public function test_authenticated_without_user_and_without_site_key_flag_is_refused(): void {
		// Defensive: $authenticated alone must not grant anything. Only the
		// explicit site-key flag or a WP_User does.
		$this->set( true, null, false );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'read' ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'edit_post', 10 ) );
	}

	public function test_site_key_flag_grants_every_capability_including_object_level(): void {
		$this->set( true, null, true );
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'manage_options' ) );
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'edit_post', 10 ) );
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'delete_post', 999 ), 'even for a missing object' );
	}

	public function test_application_password_user_is_checked_with_object_args(): void {
		$this->set( true, 8, false ); // contributor
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'edit_posts' ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'manage_options' ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Auth::require_cap( 'edit_post', 10 ), "another user's published page" );
		$this->set( true, 1, false ); // administrator
		$this->assertTrue( IATO_MCP_Auth::require_cap( 'edit_post', 10 ) );
	}

	public function test_post_type_and_taxonomy_caps_fall_back_to_core_names_without_wordpress(): void {
		// No get_post_type_object() / get_taxonomy() in the stubs: the fallback
		// must still name the right core capability.
		$this->assertSame( 'edit_posts', IATO_MCP_Auth::post_type_cap( 'post', 'create_posts' ) );
		$this->assertSame( 'edit_pages', IATO_MCP_Auth::post_type_cap( 'page', 'create_posts' ) );
		$this->assertSame( 'publish_posts', IATO_MCP_Auth::post_type_cap( 'post', 'publish_posts' ) );
		$this->assertSame( 'publish_pages', IATO_MCP_Auth::post_type_cap( 'page', 'publish_posts' ) );
		$this->assertSame( 'publish_posts', IATO_MCP_Auth::post_type_cap( 'some_cpt', 'publish_posts' ) );
		$this->assertSame( 'manage_categories', IATO_MCP_Auth::taxonomy_cap( 'category', 'manage_terms' ) );
		$this->assertSame( 'edit_posts', IATO_MCP_Auth::taxonomy_cap( 'post_tag', 'assign_terms' ) );
		$this->assertSame( [ 'publish', 'future', 'private' ], IATO_MCP_Auth::PUBLISH_STATUSES );
	}

	public function test_require_publish_cap_is_enforced_per_user_and_waived_for_the_site_key(): void {
		$this->set( true, 8, false ); // contributor
		$err = IATO_MCP_Auth::require_publish_cap( 'post' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertStringContainsString( 'publish_posts', $err->get_error_message() );
		$this->set( true, 1, false ); // administrator
		$this->assertTrue( IATO_MCP_Auth::require_publish_cap( 'post' ) );
		$this->set( true, null, true ); // site key
		$this->assertTrue( IATO_MCP_Auth::require_publish_cap( 'page' ) );
	}
}
