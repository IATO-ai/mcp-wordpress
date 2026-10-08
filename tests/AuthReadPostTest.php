<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';

/**
 * IATO_MCP_Auth::require_read_post(): the gate shared by the single-item read
 * tools, get_posts and find_elementor_widgets.
 */
final class AuthReadPostTest extends TestCase {

	private const ADMIN = 1;
	private const CONTRIB = 8;
	private const EDITOR = 9;

	private const PUBLISHED      = 10;
	private const OTHERS_DRAFT   = 11;
	private const PRIVATE_POST   = 12;
	private const PASSWORD_PAGE  = 13;
	private const TEMPLATE       = 14;
	private const OWN_DRAFT      = 21;
	private const OWN_PENDING    = 22;
	private const REV_PASSWORD   = 31; // revision of PASSWORD_PAGE
	private const REV_TEMPLATE   = 32; // revision of TEMPLATE
	private const REV_OWN_DRAFT  = 33; // revision of OWN_DRAFT
	private const REV_ORPHAN     = 34; // revision whose parent is gone

	protected function setUp(): void {
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator', self::CONTRIB => 'contributor', self::EDITOR => 'editor' ];
		IATO_Test_Roles::$posts = [
			self::PUBLISHED     => [ 'post_type' => 'page', 'post_author' => self::ADMIN,   'post_status' => 'publish' ],
			self::OTHERS_DRAFT  => [ 'post_type' => 'page', 'post_author' => self::ADMIN,   'post_status' => 'draft' ],
			self::PRIVATE_POST  => [ 'post_type' => 'post', 'post_author' => self::ADMIN,   'post_status' => 'private' ],
			self::PASSWORD_PAGE => [ 'post_type' => 'page', 'post_author' => self::ADMIN,   'post_status' => 'publish', 'post_password' => 'hunter2' ],
			self::TEMPLATE      => [ 'post_type' => 'elementor_library', 'post_author' => self::ADMIN, 'post_status' => 'publish' ],
			self::OWN_DRAFT     => [ 'post_type' => 'post', 'post_author' => self::CONTRIB, 'post_status' => 'draft' ],
			self::OWN_PENDING   => [ 'post_type' => 'post', 'post_author' => self::CONTRIB, 'post_status' => 'pending' ],
			// Revision rows: post_type revision, status inherit, no password of their own.
			self::REV_PASSWORD  => [ 'post_type' => 'revision', 'post_author' => self::ADMIN, 'post_status' => 'inherit', 'post_parent' => self::PASSWORD_PAGE ],
			self::REV_TEMPLATE  => [ 'post_type' => 'revision', 'post_author' => self::ADMIN, 'post_status' => 'inherit', 'post_parent' => self::TEMPLATE ],
			self::REV_OWN_DRAFT => [ 'post_type' => 'revision', 'post_author' => self::CONTRIB, 'post_status' => 'inherit', 'post_parent' => self::OWN_DRAFT ],
			self::REV_ORPHAN    => [ 'post_type' => 'revision', 'post_author' => self::ADMIN, 'post_status' => 'inherit', 'post_parent' => 999 ],
		];
		$this->auth_as( null );
	}

	/** null = unauthenticated; 0 = site key; >0 = Application Password user */
	private function auth_as( ?int $user_id ): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, null !== $user_id );
		$ref->getProperty( 'authenticated_user' )->setValue( null, $user_id ? new WP_User( $user_id ) : null );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, 0 === $user_id );
	}

	private function refused( int $post_id, string $cap ): void {
		$err = IATO_MCP_Auth::require_read_post( $post_id );
		$this->assertInstanceOf( WP_Error::class, $err, "post {$post_id}" );
		$this->assertSame( 'iato_mcp_forbidden', $err->get_error_code(), "post {$post_id}" );
		$this->assertStringContainsString( "capability: {$cap}", $err->get_error_message(), "post {$post_id}" );
	}

	public function test_contributor_reads_own_drafts_and_published_content_only(): void {
		$this->auth_as( self::CONTRIB );
		$this->assertTrue( IATO_MCP_Auth::require_read_post( self::OWN_DRAFT ) );
		$this->assertTrue( IATO_MCP_Auth::require_read_post( self::OWN_PENDING ) );
		$this->assertTrue( IATO_MCP_Auth::require_read_post( self::PUBLISHED ) );
		$this->refused( self::OTHERS_DRAFT, 'read_post' );
		$this->refused( self::PRIVATE_POST, 'read_post' );
	}

	public function test_password_protected_content_needs_edit_post(): void {
		$this->auth_as( self::CONTRIB );
		$this->refused( self::PASSWORD_PAGE, 'edit_post' );
		$this->auth_as( self::EDITOR );
		$this->assertTrue( IATO_MCP_Auth::require_read_post( self::PASSWORD_PAGE ), 'an Editor may edit the page, so may read it' );
	}

	public function test_templates_need_manage_options(): void {
		$this->auth_as( self::CONTRIB );
		$this->refused( self::TEMPLATE, 'manage_options' );
		$this->auth_as( self::EDITOR );
		$this->refused( self::TEMPLATE, 'manage_options' );
		$this->auth_as( self::ADMIN );
		$this->assertTrue( IATO_MCP_Auth::require_read_post( self::TEMPLATE ) );
	}

	public function test_revisions_are_judged_by_their_parent(): void {
		$this->auth_as( self::CONTRIB );
		$this->refused( self::REV_PASSWORD, 'edit_post' );
		$this->refused( self::REV_TEMPLATE, 'manage_options' );
		$this->assertTrue( IATO_MCP_Auth::require_read_post( self::REV_OWN_DRAFT ), 'revision of the own draft' );
		$this->assertSame( 'not_found', IATO_MCP_Auth::require_read_post( self::REV_ORPHAN )->get_error_code(), 'orphan revision' );
		$this->auth_as( self::EDITOR );
		$this->assertTrue( IATO_MCP_Auth::require_read_post( self::REV_PASSWORD ) );
		$this->refused( self::REV_TEMPLATE, 'manage_options' );
	}

	public function test_editor_reads_private_and_other_users_drafts(): void {
		$this->auth_as( self::EDITOR );
		foreach ( [ self::PUBLISHED, self::OTHERS_DRAFT, self::PRIVATE_POST, self::OWN_DRAFT ] as $id ) {
			$this->assertTrue( IATO_MCP_Auth::require_read_post( $id ), "post {$id}" );
		}
	}

	public function test_administrator_reads_everything(): void {
		$this->auth_as( self::ADMIN );
		foreach ( array_diff( array_keys( IATO_Test_Roles::$posts ), [ self::REV_ORPHAN ] ) as $id ) {
			$this->assertTrue( IATO_MCP_Auth::require_read_post( $id ), "post {$id}" );
		}
	}

	public function test_site_key_reads_everything_unchanged(): void {
		$this->auth_as( 0 );
		foreach ( array_diff( array_keys( IATO_Test_Roles::$posts ), [ self::REV_ORPHAN ] ) as $id ) {
			$this->assertTrue( IATO_MCP_Auth::require_read_post( $id ), "post {$id}" );
		}
	}

	public function test_missing_post_is_not_found_and_unauthenticated_is_refused(): void {
		$this->auth_as( self::ADMIN );
		$err = IATO_MCP_Auth::require_read_post( 999 );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'not_found', $err->get_error_code() );
		$this->auth_as( null );
		$this->assertSame( 'iato_mcp_forbidden', IATO_MCP_Auth::require_read_post( self::PUBLISHED )->get_error_code() );
	}
}
