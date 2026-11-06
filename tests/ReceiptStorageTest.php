<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-actor.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';

/**
 * 1.16.0: a receipt is an undo promise. record() checks that its row was saved
 * and never returns an ID that was not; only a column that is really missing
 * makes it save the row without the actor.
 */
final class ReceiptStorageTest extends TestCase {

	protected function setUp(): void {
		IATO_Test_Abilities_State::$options['iato_mcp_schema_version'] = 7;
		IATO_MCP_Change_Receipt::clear_failures();
		$db                 = $GLOBALS['wpdb'];
		$db->inserts        = [];
		$db->insert_results = [];
		$db->updates        = [];
		$db->update_results = [];
		$db->last_error     = '';
		$db->described      = [ IATO_MCP_Change_Receipt::table_name() => array_fill_keys( IATO_MCP_Change_Receipt::ACTOR_COLUMNS, 'varchar(64)' ) ];
	}

	protected function tearDown(): void {
		unset( IATO_Test_Abilities_State::$options['iato_mcp_schema_version'] );
		IATO_MCP_Change_Receipt::clear_failures();
		$GLOBALS['wpdb']->described = [];
	}

	private static function record(): array {
		return IATO_MCP_Change_Receipt::record( 1, 'post', 'title', 'old', 'new' );
	}

	public function test_a_saved_receipt_has_its_id_and_no_warning(): void {
		$receipt = self::record();
		$this->assertMatchesRegularExpression( '/^wr_/', $receipt['change_id'] );
		$this->assertArrayNotHasKey( 'stored', $receipt );
		$this->assertArrayNotHasKey( 'warning', $receipt );
		$this->assertSame( $receipt['change_id'], $GLOBALS['wpdb']->inserts[0][1]['change_id'] );
		$this->assertSame( [], IATO_MCP_Change_Receipt::failures() );
	}

	public function test_a_receipt_that_was_not_saved_never_returns_an_id(): void {
		$GLOBALS['wpdb']->insert_results = [ false ];
		$GLOBALS['wpdb']->last_error     = 'Processing the value for the following field failed: field.';
		$receipt = self::record();
		$this->assertNull( $receipt['change_id'] );
		$this->assertFalse( $receipt['stored'] );
		$this->assertStringContainsString( 'cannot undo', $receipt['warning'] );
		$this->assertSame( 'old', $receipt['before_value'], 'the caller can still see what was replaced' );
		$failures = IATO_MCP_Change_Receipt::failures();
		$this->assertSame( 1, $failures['count'] );
		$this->assertSame( 'post / title', $failures['last_what'] );
		$this->assertStringContainsString( 'field failed', $failures['last_error'] );
	}

	public function test_a_one_off_failure_does_not_drop_the_actor(): void {
		$GLOBALS['wpdb']->insert_results = [ false ];
		self::record();
		$this->assertCount( 1, $GLOBALS['wpdb']->inserts, 'every actor column exists, so nothing is retried without it' );
	}

	public function test_a_missing_actor_column_saves_the_row_without_the_actor(): void {
		$GLOBALS['wpdb']->insert_results = [ false ];
		$GLOBALS['wpdb']->described[ IATO_MCP_Change_Receipt::table_name() ] = [ 'actor_user_id' => 'bigint', 'actor_kind' => 'varchar(30)' ];
		$receipt = self::record();
		$this->assertCount( 2, $GLOBALS['wpdb']->inserts );
		foreach ( IATO_MCP_Change_Receipt::ACTOR_COLUMNS as $column ) {
			$this->assertArrayNotHasKey( $column, $GLOBALS['wpdb']->inserts[1][1] );
		}
		$this->assertSame( $receipt['change_id'], $GLOBALS['wpdb']->inserts[1][1]['change_id'] );
		$this->assertStringContainsString( 'same-user rule', $receipt['warning'] );
		$this->assertArrayNotHasKey( 'stored', $receipt, 'it was stored' );
		$this->assertSame( 1, IATO_MCP_Change_Receipt::failures()['count'] );
	}

	public function test_a_failed_retry_is_not_stored_either(): void {
		$GLOBALS['wpdb']->insert_results = [ false, false ];
		$GLOBALS['wpdb']->described[ IATO_MCP_Change_Receipt::table_name() ] = [];
		$receipt = self::record();
		$this->assertCount( 2, $GLOBALS['wpdb']->inserts );
		$this->assertNull( $receipt['change_id'] );
		$this->assertFalse( $receipt['stored'] );
		$this->assertStringContainsString( 'cannot undo', $receipt['warning'] );
	}

	public function test_failures_add_up_until_cleared(): void {
		$GLOBALS['wpdb']->insert_results = [ false, false ];
		self::record();
		self::record();
		$this->assertSame( 2, IATO_MCP_Change_Receipt::failures()['count'] );
		IATO_MCP_Change_Receipt::clear_failures();
		$this->assertSame( [], IATO_MCP_Change_Receipt::failures() );
	}

	public function test_slim_receipts_carry_the_warning_only_when_there_is_one(): void {
		$slim = IATO_MCP_Change_Receipt::slim( self::record() );
		$this->assertSame( [ 'change_id', 'target_type', 'field', 'applied_at', 'actor' ], array_keys( $slim ) );

		$GLOBALS['wpdb']->insert_results = [ false ];
		$slim = IATO_MCP_Change_Receipt::slim( self::record() );
		$this->assertNull( $slim['change_id'] );
		$this->assertFalse( $slim['stored'] );
		$this->assertStringContainsString( 'cannot undo', $slim['warning'] );
	}

	public function test_a_rollback_mark_that_cannot_be_saved_returns_false_and_counts(): void {
		$GLOBALS['wpdb']->update_results = [ false ];
		$GLOBALS['wpdb']->described[ IATO_MCP_Change_Receipt::table_name() ] = array_fill_keys( IATO_MCP_Change_Receipt::ROLLED_BACK_BY_COLUMNS, 'varchar(64)' );
		$this->assertFalse( IATO_MCP_Change_Receipt::mark_rolled_back( 'wr_x', [ 'user_id' => 1, 'kind' => 'site_key', 'client_id' => null ] ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->updates );
		$this->assertSame( 1, IATO_MCP_Change_Receipt::failures()['count'] );
	}

	public function test_the_field_column_holds_any_meta_key(): void {
		$this->assertStringContainsString( 'field VARCHAR(255) NOT NULL', IATO_MCP_Change_Receipt::schema_sql() );
	}
}
