<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-actor.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-rollback.php';

/**
 * Part 3 (1.13.0): receipts record who made each change; only that user or an
 * administrator may roll it back (on top of the object-level checks); a
 * rollback whose value has changed since is refused unless force; dry_run
 * writes nothing; Elementor receipts restore the stored tree verbatim.
 */
final class RollbackActorConflictTest extends TestCase {

	private const ADMIN   = 1;
	private const AUTHOR  = 7;
	private const CONTRIB = 8;
	private const EDITOR  = 9;
	private const PAGE    = 10; // the admin's published page
	private const POST    = 20; // the author's published post

	protected function setUp(): void {
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator', self::AUTHOR => 'author', self::CONTRIB => 'contributor', self::EDITOR => 'editor' ];
		$posts = [
			self::PAGE => [ 'post_type' => 'page', 'post_author' => self::ADMIN, 'post_status' => 'publish', 'post_title' => 'Admin page', 'post_modified_gmt' => '2026-10-01 10:00:00' ],
			self::POST => [ 'post_type' => 'post', 'post_author' => self::AUTHOR, 'post_status' => 'publish', 'post_title' => 'Now', 'post_modified_gmt' => '2026-10-01 10:00:00' ],
		];
		IATO_Test_Roles::$posts = $posts; // whichever get_post() stub loaded first sees them
		IATO_Test_WP::$posts    = $posts;
		IATO_Test_Roles::$terms = [ 4 => 'post_tag', 5 => 'post_tag', 3 => 'category' ];
		IATO_Test_WP::$meta     = [];
		IATO_Test_WP::$writes   = [];
		IATO_Test_Actor::reset();
		IATO_Test_Actor::$logins = [ self::ADMIN => 'admin', self::AUTHOR => 'author', self::CONTRIB => 'contributor', self::EDITOR => 'editor' ];
		IATO_Test_Abilities_State::$options['iato_mcp_schema_version'] = 4;
		$GLOBALS['wpdb']->inserts = [];
		$GLOBALS['wpdb']->updates = [];
		$this->as_user( null );
		$this->oauth = IATO_Test_OAuth_Store::install();
	}

	private IATO_Test_OAuth_Store $oauth;

	protected function tearDown(): void {
		IATO_MCP_OAuth_Store::set_instance( null );
		$this->as_user( null );
		unset( IATO_Test_Abilities_State::$options['iato_mcp_schema_version'] );
		IATO_Test_Auth_State::$current_user_id = 0;
	}

	/**
	 * null = unauthenticated; otherwise the request's credential. $full = the
	 * unbound site key (full access, user 0).
	 */
	private function as_user( ?int $id, string $kind = 'application_password', ?string $client = null, bool $full = false ): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, null !== $id );
		$ref->getProperty( 'authenticated_user' )->setValue( null, $id ? new WP_User( $id ) : null );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, $full );
		$credential = null === $id ? [] : [ 'kind' => $kind, 'user_id' => $id ];
		if ( null !== $client ) {
			$credential += [ 'client_id' => $client, 'grant_id' => 'g1' ];
		}
		$ref->getProperty( 'credential' )->setValue( null, $credential );
	}

	private static function receipt( string $type, string $field, ?int $post_id, mixed $before, mixed $after, ?string $kind = null, ?int $user = null, array $extra = [] ): array {
		return array_merge( [
			'change_id'       => 'wr_' . bin2hex( random_bytes( 8 ) ),
			'post_id'         => $post_id,
			'target_type'     => $type,
			'field'           => $field,
			'before_value'    => $before,
			'after_value'     => $after,
			'applied_at'      => '2026-10-01 10:00:00',
			'rolled_back_at'  => null,
			'actor_user_id'   => $user,
			'actor_kind'      => $kind,
			'actor_client_id' => null,
		], $extra );
	}

	private function last_insert(): array {
		$inserts = $GLOBALS['wpdb']->inserts;
		return end( $inserts )[1];
	}

	// ── Recording ───────────────────────────────────────────────────────────

	public static function credentials(): array {
		return [
			'author Application Password' => [ self::AUTHOR, 'application_password', null, false, 'author' ],
			'bound site key (owner)'      => [ self::ADMIN, 'site_key', null, false, 'admin' ],
			'OAuth token'                 => [ self::ADMIN, 'oauth', 'client-x', false, 'admin' ],
			'unbound site key (user 0)'   => [ 0, 'site_key', null, true, null ],
		];
	}

	#[DataProvider( 'credentials' )]
	public function test_record_stores_the_requests_actor( int $user, string $kind, ?string $client, bool $full, ?string $login ): void {
		$this->as_user( $user, $kind, $client, $full );
		if ( null !== $client ) {
			$this->oauth->insert_client( [ 'client_id' => $client, 'client_name' => 'Client X', 'redirect_uris' => [ 'https://claude.ai/cb' ], 'registration_ip' => null, 'created_at' => '2026-10-01 10:00:00', 'authorized_at' => '2026-10-01 10:00:00' ] );
		}
		$r   = IATO_MCP_Change_Receipt::record( self::POST, 'post', 'title', 'a', 'b' );
		$row = $this->last_insert();
		$this->assertSame( [ $user, $kind, $client ], [ $row['actor_user_id'], $row['actor_kind'], $row['actor_client_id'] ] );
		$this->assertSame( $user, $r['actor']['user_id'] );
		$this->assertSame( $login, $r['actor']['user_login'] );
		$this->assertSame( $kind, $r['actor']['kind'] );
		$this->assertSame( $client, $r['actor']['client_id'] ?? null );
		$this->assertSame( null !== $client ? 'Client X' : null, $r['actor']['client_name'] ?? null, 'OAuth clients are named' );
	}

	public function test_record_outside_an_mcp_request_uses_the_logged_in_user(): void {
		IATO_Test_Auth_State::$current_user_id = self::EDITOR;
		IATO_MCP_Change_Receipt::record( self::POST, 'post', 'title', 'a', 'b' );
		$row = $this->last_insert();
		$this->assertSame( [ self::EDITOR, 'wordpress' ], [ $row['actor_user_id'], $row['actor_kind'] ] );
	}

	public function test_before_schema_step_4_receipts_are_still_written_without_the_actor_columns(): void {
		IATO_Test_Abilities_State::$options['iato_mcp_schema_version'] = 3;
		$this->as_user( self::AUTHOR );
		IATO_MCP_Change_Receipt::record( self::POST, 'post', 'title', 'a', 'b' );
		$row = $this->last_insert();
		$this->assertSame( 'b', $row['after_value'] );
		$this->assertArrayNotHasKey( 'actor_kind', $row );
		IATO_MCP_Change_Receipt::mark_rolled_back( 'wr_0000000000000000' );
		$this->assertSame( [ 'rolled_back_at' ], array_keys( end( $GLOBALS['wpdb']->updates )[1] ) );
	}

	public function test_mark_rolled_back_records_who(): void {
		$this->as_user( self::ADMIN, 'oauth', 'client-x' );
		IATO_MCP_Change_Receipt::mark_rolled_back( 'wr_0000000000000000' );
		$update = end( $GLOBALS['wpdb']->updates );
		$this->assertSame( [ self::ADMIN, 'oauth', 'client-x' ], [ $update[1]['rolled_back_by_user_id'], $update[1]['rolled_back_by_kind'], $update[1]['rolled_back_by_client_id'] ] );
		$this->assertSame( [ 'change_id' => 'wr_0000000000000000' ], $update[2] );
	}

	public function test_actor_of_treats_a_receipt_without_actor_as_recorded_before_actors(): void {
		$this->assertNull( IATO_MCP_Change_Receipt::actor_of( [ 'change_id' => 'x' ] ) );
		$this->assertNull( IATO_MCP_Change_Receipt::actor_of( self::receipt( 'post', 'title', 1, 'a', 'b' ) ) );
		$this->assertSame( [ 'user_id' => 0, 'kind' => 'site_key', 'client_id' => null ], IATO_MCP_Change_Receipt::actor_of( self::receipt( 'post', 'title', 1, 'a', 'b', 'site_key', 0 ) ) );
	}

	// ── Who may roll back ───────────────────────────────────────────────────

	public static function permission_matrix(): array {
		// [ actor kind, actor user, post, requester [id, kind, client, full], expected ]
		return [
			'author undoes their own change'                         => [ 'application_password', self::AUTHOR, self::POST, [ self::AUTHOR ], true ],
			'editor cannot undo the author\'s change (edit_others passes)' => [ 'application_password', self::AUTHOR, self::POST, [ self::EDITOR ], 'rollback_actor_mismatch' ],
			'admin Application Password undoes it'                  => [ 'application_password', self::AUTHOR, self::POST, [ self::ADMIN ], true ],
			'bound site key (admin owner) undoes it'                => [ 'application_password', self::AUTHOR, self::POST, [ self::ADMIN, 'site_key' ], true ],
			'OAuth (admin) undoes an editor change'                 => [ 'application_password', self::EDITOR, self::PAGE, [ self::ADMIN, 'oauth', 'client-x' ], true ],
			'unbound site key undoes anything'                      => [ 'application_password', self::EDITOR, self::PAGE, [ 0, 'site_key', null, true ], true ],
			'editor cannot undo the unbound key\'s change'           => [ 'site_key', 0, self::PAGE, [ self::EDITOR ], 'rollback_actor_mismatch' ],
			'same user, other credential kind'                      => [ 'wordpress', self::AUTHOR, self::POST, [ self::AUTHOR ], true ],
			'object rule still first: author lacks edit_post on page' => [ 'application_password', self::AUTHOR, self::PAGE, [ self::AUTHOR ], 'iato_mcp_forbidden' ],
			'pre-1.13.0 receipt: editor, object rules only'         => [ null, null, self::PAGE, [ self::EDITOR ], true ],
			'pre-1.13.0 receipt: object rules still refuse author'  => [ null, null, self::PAGE, [ self::AUTHOR ], 'iato_mcp_forbidden' ],
		];
	}

	#[DataProvider( 'permission_matrix' )]
	public function test_rollback_needs_the_same_user_or_an_administrator( ?string $kind, ?int $user, int $post, array $as, bool|string $expected ): void {
		$this->as_user( $as[0], $as[1] ?? 'application_password', $as[2] ?? null, $as[3] ?? false );
		$result = IATO_MCP_Rollback::check_permission( self::receipt( 'post', 'title', $post, 'a', 'b', $kind, $user ) );
		if ( true === $expected ) {
			$this->assertTrue( $result );
			return;
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );
		if ( 'rollback_actor_mismatch' === $expected ) {
			$this->assertSame( $user, $result->get_error_data()['actor']['user_id'] );
			$this->assertStringContainsString( 0 === $user ? 'site key (no user)' : 'author', $result->get_error_message() );
		}
	}

	// ── Conflict check ──────────────────────────────────────────────────────

	public function test_canonical_form(): void {
		$c = fn( $v ) => IATO_MCP_Rollback_Conflict::canonical( $v );
		$this->assertNull( $c( '' ) );
		$this->assertNull( $c( null ) );
		$this->assertSame( $c( [ 'a' => 'x', 'b' => 1 ] ), $c( '{"b":1,"a":"x"}' ), 'JSON text and arrays, key order and scalar types ignored' );
		$this->assertSame( $c( [ 1, 2 ] ), $c( [ '1', '2' ] ) );
		$this->assertNotSame( $c( [ 2, 1 ] ), $c( [ 1, 2 ] ), 'list order matters' );
		$this->assertSame( '5', $c( 5 ) );
		$this->assertSame( 'Tom & Jerry', $c( 'Tom & Jerry' ) );
	}

	public function test_post_field_match_and_conflict(): void {
		$this->assertSame( 'match', IATO_MCP_Rollback_Conflict::check( self::receipt( 'post', 'title', self::POST, 'Then', 'Now' ) )['status'] );
		$c = IATO_MCP_Rollback_Conflict::check( self::receipt( 'post', 'title', self::POST, 'Before', 'Then' ) );
		$this->assertSame( [ 'conflict', 'Now', 'Then' ], [ $c['status'], $c['current'], $c['expected'] ] );
		$this->assertSame( 'unverifiable', IATO_MCP_Rollback_Conflict::check( self::receipt( 'post', 'title', 404, 'a', 'b' ) )['status'] );
		$this->assertSame( 'unverifiable', IATO_MCP_Rollback_Conflict::check( self::receipt( 'something_new', 'x', self::POST, 'a', 'b' ) )['status'] );
	}

	public function test_post_meta_compares_arrays_with_their_recorded_json(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_page_settings'] = [ 'a' => '1', 'b' => [ 'x' ] ];
		$this->assertSame( 'match', IATO_MCP_Rollback_Conflict::check( self::receipt( 'post_meta', '_elementor_page_settings', self::POST, null, '{"b":["x"],"a":1}' ) )['status'] );
		$this->assertSame( 'conflict', IATO_MCP_Rollback_Conflict::check( self::receipt( 'post_meta', '_elementor_page_settings', self::POST, null, '{"b":["y"],"a":1}' ) )['status'] );
	}

	public function test_elementor_receipts_compare_revision_hashes(): void {
		IATO_Test_WP::$meta[ self::PAGE ]['_elementor_data'] = '[{"id":"a"}]';
		$now = IATO_MCP_Elementor_Adapter::compute_revision( '[{"id":"a"}]' );
		$this->assertSame( 'match', IATO_MCP_Rollback_Conflict::check( self::receipt( 'elementor_widget', 'elementor:document:patch', self::PAGE, '[]', $now ) )['status'] );
		$c = IATO_MCP_Rollback_Conflict::check( self::receipt( 'elementor_widget', 'elementor:document:patch', self::PAGE, '[]', 'sha256:' . str_repeat( '0', 64 ) ) );
		$this->assertSame( [ 'conflict', $now ], [ $c['status'], $c['current'] ] );
	}

	public function test_create_receipts_conflict_when_the_object_was_modified_after(): void {
		$create = self::receipt( 'post', 'create', self::POST, null, '{"post_type":"post","title":"Now"}' );
		$this->assertSame( 'match', IATO_MCP_Rollback_Conflict::check( $create )['status'], 'modified at the creation second' );
		IATO_Test_Roles::$posts[ self::POST ]['post_modified_gmt'] = '2026-10-02 09:00:00';
		IATO_Test_WP::$posts[ self::POST ]['post_modified_gmt']    = '2026-10-02 09:00:00';
		$this->assertSame( 'conflict', IATO_MCP_Rollback_Conflict::check( $create )['status'] );
		IATO_Test_Roles::$posts[ self::POST ]['post_status'] = 'trash';
		IATO_Test_WP::$posts[ self::POST ]['post_status']    = 'trash';
		$this->assertSame( 'match', IATO_MCP_Rollback_Conflict::check( $create )['status'], 'already trashed: nothing to lose' );
	}

	public function test_taxonomy_receipts(): void {
		IATO_Test_Actor::$post_terms[ self::POST ]['post_tag'] = [ 4 ];
		$assign = self::receipt( 'taxonomy', 'assign', self::POST, '[]', '[4]' );
		$this->assertSame( 'match', IATO_MCP_Rollback_Conflict::check( $assign )['status'], 'taxonomy found through the after IDs' );
		IATO_Test_Actor::$post_terms[ self::POST ]['post_tag'] = [ 5, 4 ];
		$this->assertSame( 'conflict', IATO_MCP_Rollback_Conflict::check( $assign )['status'] );

		$deleted = self::receipt( 'taxonomy', 'delete_term', null, '{"term_id":9,"name":"Gone","slug":"gone","taxonomy":"category"}', null );
		$this->assertSame( 'match', IATO_MCP_Rollback_Conflict::check( $deleted )['status'] );
		IATO_Test_Actor::$slugs['category']['gone'] = 12;
		$this->assertSame( 'conflict', IATO_MCP_Rollback_Conflict::check( $deleted )['status'], 'a term with the slug exists again' );
	}

	// ── execute(): refusal, force, dry run ──────────────────────────────────

	private function meta_receipt(): array {
		IATO_Test_WP::$meta[ self::POST ]['rollback_key'] = 'theirs';
		return self::receipt( 'post_meta', 'rollback_key', self::POST, 'old', 'mine', 'application_password', self::AUTHOR );
	}

	public function test_a_conflict_is_refused_and_nothing_is_written(): void {
		$this->as_user( self::AUTHOR );
		$result = IATO_MCP_Rollback::execute( $this->meta_receipt() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rollback_conflict', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( [ 409, 'theirs', 'mine' ], [ $data['status'], $data['conflict_check']['current_value'], $data['conflict_check']['expected_value'] ] );
		$this->assertStringContainsString( 'force', $result->get_error_message() );
		$this->assertSame( [], IATO_Test_WP::$writes );
		$this->assertSame( [], $GLOBALS['wpdb']->updates );
	}

	public function test_force_rolls_back_and_reports_it(): void {
		$this->as_user( self::AUTHOR );
		$result = IATO_MCP_Rollback::execute( $this->meta_receipt(), [ 'force' => true ] );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['conflict_check']['forced'] );
		$this->assertSame( 'old', IATO_Test_WP::$meta[ self::POST ]['rollback_key'] );
		$this->assertSame( 'author', $result['actor']['user_login'] );
		$this->assertSame( 'author', $result['rolled_back_by']['user_login'] );
		$this->assertSame( self::AUTHOR, end( $GLOBALS['wpdb']->updates )[1]['rolled_back_by_user_id'] );
	}

	public function test_dry_run_writes_nothing(): void {
		$this->as_user( self::AUTHOR );
		$receipt = $this->meta_receipt();
		IATO_Test_WP::$meta[ self::POST ]['rollback_key'] = 'mine';
		$result = IATO_MCP_Rollback::execute( $receipt, [ 'dry_run' => true ] );
		$this->assertSame( [ true, true, 'old', 'match' ], [ $result['dry_run'], $result['would_roll_back'], $result['would_restore'], $result['conflict_check']['status'] ] );
		$this->assertSame( [], IATO_Test_WP::$writes );
		$this->assertSame( [], $GLOBALS['wpdb']->updates );
		$conflicted = IATO_MCP_Rollback::execute( $this->meta_receipt(), [ 'dry_run' => true ] );
		$this->assertFalse( $conflicted['would_roll_back'], 'a dry run reports a conflict instead of failing' );
	}

	public function test_an_already_rolled_back_receipt_names_who_did_it(): void {
		$receipt = self::receipt( 'post', 'title', self::POST, 'a', 'Now', null, null, [ 'rolled_back_at' => '2026-10-02 10:00:00', 'rolled_back_by_user_id' => self::EDITOR, 'rolled_back_by_kind' => 'application_password' ] );
		$result  = IATO_MCP_Rollback::execute( $receipt );
		$this->assertSame( 'already_rolled_back', $result->get_error_code() );
		$this->assertSame( 'editor', $result->get_error_data()['rolled_back_by']['user_login'] );
	}

	public function test_receipts_that_cannot_be_rolled_back_say_why_and_stay_open(): void {
		$hash_only = self::receipt( 'elementor_widget', 'elementor:document:patch', self::PAGE, 'sha256:' . str_repeat( 'a', 64 ), 'sha256:' . str_repeat( 'b', 64 ) );
		$r         = IATO_MCP_Rollback::execute( $hash_only );
		$this->assertSame( 'rollback_unavailable', $r->get_error_code() );
		$this->assertStringContainsString( 'Revisions', $r->get_error_message() );
		$no_term_id = self::receipt( 'taxonomy', 'update_term', null, '{"name":"x","taxonomy":"category"}', '{"name":"y","taxonomy":"category"}' );
		$this->assertSame( 'rollback_unavailable', IATO_MCP_Rollback::execute( $no_term_id )->get_error_code() );
		$this->assertSame( [], $GLOBALS['wpdb']->updates, 'not marked rolled back' );
	}

	public function test_elementor_rollback_restores_the_stored_tree_verbatim(): void {
		$this->as_user( 0, 'site_key', null, true );
		$tree = '[{"id":"h","settings":{"html":"<iframe src=\"x\"></iframe><script>var a = \"\\\\n\";</script>","title":"Tom & Jerry"}}]';
		IATO_Test_WP::$meta[ self::PAGE ]['_elementor_data'] = '[{"id":"h","settings":{"title":"edited"}}]';
		$receipt = self::receipt( 'elementor_widget', 'elementor:h:/settings', self::PAGE, $tree, IATO_MCP_Elementor_Adapter::compute_revision( '[{"id":"h","settings":{"title":"edited"}}]' ), 'site_key', 0 );
		$result  = IATO_MCP_Rollback::execute( $receipt );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $tree, IATO_Test_WP::$meta[ self::PAGE ]['_elementor_data'], 'byte for byte, backslashes included' );
		$this->assertSame( IATO_MCP_Elementor_Adapter::compute_revision( $tree ), $result['restored_revision'] );
		$this->assertArrayNotHasKey( 'restored_value', $result, 'the tree is not echoed back' );
	}

	public function test_elementor_rollback_of_a_post_that_had_no_elementor_data_puts_its_content_back(): void {
		$this->as_user( 0, 'site_key', null, true );
		IATO_Test_WP::$meta[ self::PAGE ]  = [ '_elementor_data' => '[{"id":"n"}]', '_elementor_edit_mode' => 'builder' ];
		IATO_Test_WP::$posts[ self::PAGE ]['post_content'] = '<h2>rendered by Elementor</h2>';
		$before  = IATO_MCP_Rollback::no_elementor_envelope( '<p>Original</p>', '' );
		$receipt = self::receipt( 'elementor_widget', 'elementor:document:replace', self::PAGE, $before, IATO_MCP_Elementor_Adapter::compute_revision( '[{"id":"n"}]' ), 'site_key', 0 );
		$result  = IATO_MCP_Rollback::execute( $receipt );
		$this->assertTrue( $result['success'] );
		$this->assertSame( [ null, 'no_elementor_data' ], [ $result['restored_revision'], $result['restored'] ] );
		$this->assertArrayNotHasKey( '_elementor_data', IATO_Test_WP::$meta[ self::PAGE ] );
		$this->assertArrayNotHasKey( '_elementor_edit_mode', IATO_Test_WP::$meta[ self::PAGE ] );
		$this->assertSame( '<p>Original</p>', IATO_Test_WP::$posts[ self::PAGE ]['post_content'] );
		$this->assertNull( IATO_MCP_Rollback::no_elementor_from( '[{"id":"a"}]' ), 'a tree is never read as the envelope' );
	}

	public function test_rollback_restores_backslashes_in_meta_strings_and_arrays(): void {
		$this->as_user( self::AUTHOR );
		$b    = chr( 92 );
		$orig = [ 'css' => ".x:before { content: \"{$b}201C\"; }", 'path' => "C:{$b}Users" ];
		IATO_Test_WP::$meta[ self::POST ]['probe_array']  = [ 'css' => 'replaced' ];
		IATO_Test_WP::$meta[ self::POST ]['probe_string'] = 'replaced';
		$r1 = IATO_MCP_Rollback::execute( self::receipt( 'post_meta', 'probe_array', self::POST, json_encode( $orig ), '{"css":"replaced"}', 'application_password', self::AUTHOR ) );
		$r2 = IATO_MCP_Rollback::execute( self::receipt( 'post_meta', 'probe_string', self::POST, "a{$b}b", 'replaced', 'application_password', self::AUTHOR ) );
		$this->assertTrue( $r1['success'] && $r2['success'] );
		$this->assertSame( $orig, IATO_Test_WP::$meta[ self::POST ]['probe_array'], 'arrays are slashed before update_post_meta() unslashes them' );
		$this->assertSame( "a{$b}b", IATO_Test_WP::$meta[ self::POST ]['probe_string'] );
	}

	public function test_tag_rollback_uses_the_taxonomy_of_the_after_ids(): void {
		$this->as_user( self::ADMIN );
		IATO_Test_Actor::$post_terms[ self::POST ]['post_tag'] = [ 4 ];
		$result = IATO_MCP_Rollback::execute( self::receipt( 'taxonomy', 'assign', self::POST, '[]', '[4]' ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( [ self::POST, [], 'post_tag' ], end( IATO_Test_Actor::$set_terms ), 'tags emptied; categories never touched' );
	}

	// ── Tool error envelope ─────────────────────────────────────────────────

	public function test_detailed_refusals_reach_the_client_as_json(): void {
		$env = iato_mcp_rollback_error( new WP_Error( 'rollback_conflict', 'Rollback refused: x.', [ 'status' => 409, 'conflict_check' => [ 'status' => 'conflict', 'current_value' => 'a' ] ] ) );
		$this->assertTrue( $env['isError'] );
		$body = json_decode( $env['content'][0]['text'], true );
		$this->assertSame( [ 'error' => 'rollback_conflict', 'message' => 'Rollback refused: x.', 'conflict_check' => [ 'status' => 'conflict', 'current_value' => 'a' ] ], $body );
		$plain = new WP_Error( 'not_found', 'change_id not found.' );
		$this->assertSame( $plain, iato_mcp_rollback_error( $plain ), 'other errors pass through' );
	}
}
