<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-actor.php';
require_once __DIR__ . '/stubs-cron.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';
require_once IATO_MCP_DIR . 'includes/class-receipt-retention.php';

/**
 * Receipt retention (1.13.0): old Elementor page copies are cleared in
 * batches by a daily WP-Cron job (or one batch at the end of a write when the
 * job is overdue); a cleared receipt is never rolled back.
 */
final class ReceiptRetentionTest extends TestCase {

	private const NOW = 1791000000; // a fixed "now"

	protected function setUp(): void {
		IATO_Test_Abilities_State::$options['iato_mcp_schema_version'] = 5;
		unset( IATO_Test_Abilities_State::$options[ IATO_MCP_Receipt_Retention::LAST_RUN_OPTION ] );
		IATO_Test_Abilities_State::$filters = [];
		IATO_Test_Abilities_State::$actions = [];
		IATO_Test_Cron::reset();
		IATO_MCP_Receipt_Retention::reset_request_state();
		$GLOBALS['wpdb']->prepared      = [];
		$GLOBALS['wpdb']->queries       = [];
		$GLOBALS['wpdb']->query_results = [];
		$GLOBALS['wpdb']->updates       = [];
	}

	protected function tearDown(): void {
		unset( IATO_Test_Abilities_State::$options['iato_mcp_schema_version'], IATO_Test_Abilities_State::$options[ IATO_MCP_Receipt_Retention::LAST_RUN_OPTION ] );
		IATO_Test_Abilities_State::$filters = [];
		IATO_Test_Abilities_State::$actions = [];
		$GLOBALS['wpdb']->query_results = [];
	}

	private function updates(): array {
		return array_values( array_filter( $GLOBALS['wpdb']->prepared, fn( $p ) => str_starts_with( $p[0], 'UPDATE' ) ) );
	}

	public function test_clears_elementor_copies_older_than_90_days_in_batches(): void {
		$GLOBALS['wpdb']->query_results = [ 200, 200, 7 ];
		$r = IATO_MCP_Receipt_Retention::run( 25, self::NOW );
		$this->assertSame( [ true, 407 ], [ $r['ran'], $r['pruned'] ] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 90 * 86400 ), $r['cutoff'] );
		$updates = $this->updates();
		$this->assertCount( 3, $updates, 'stops after the first short batch' );
		[ $sql, $args ] = $updates[0];
		$this->assertStringContainsString( 'SET before_value = NULL, pruned_at = %s', $sql );
		$this->assertStringContainsString( 'pruned_at IS NULL AND before_value IS NOT NULL AND applied_at < %s LIMIT %d', $sql );
		$this->assertSame( [ 'wp_iato_change_receipts', gmdate( 'Y-m-d H:i:s', self::NOW ), 'elementor_widget', $r['cutoff'], 200 ], $args, 'only Elementor receipts; the receipt rows themselves stay' );
		$last = IATO_MCP_Receipt_Retention::last_run();
		$this->assertSame( [ 407, $r['cutoff'], 90 ], [ $last['pruned'], $last['cutoff'], $last['days'] ] );
	}

	public function test_a_run_never_exceeds_its_batch_limit(): void {
		$GLOBALS['wpdb']->query_results = array_fill( 0, 10, 200 );
		$r = IATO_MCP_Receipt_Retention::run( 3, self::NOW );
		$this->assertSame( 600, $r['pruned'] );
		$this->assertCount( 3, $this->updates() );
	}

	public function test_the_period_comes_from_the_filter_and_zero_keeps_everything(): void {
		add_filter( 'iato_mcp_receipt_retention_days', fn() => 30 );
		$r = IATO_MCP_Receipt_Retention::run( 25, self::NOW );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 30 * 86400 ), $r['cutoff'] );

		IATO_Test_Abilities_State::$filters = [];
		$GLOBALS['wpdb']->prepared = [];
		add_filter( 'iato_mcp_receipt_retention_days', fn() => 0 );
		$r = IATO_MCP_Receipt_Retention::run( 25, self::NOW );
		$this->assertSame( [ false, 'disabled' ], [ $r['ran'], $r['reason'] ] );
		$this->assertSame( [], $this->updates() );
	}

	public function test_nothing_runs_before_schema_step_5(): void {
		IATO_Test_Abilities_State::$options['iato_mcp_schema_version'] = 4;
		$r = IATO_MCP_Receipt_Retention::run( 25, self::NOW );
		$this->assertSame( 'schema', $r['reason'] );
		$this->assertSame( [], $this->updates() );
	}

	public function test_an_overdue_job_runs_one_batch_at_the_end_of_a_write(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Receipt_Retention::LAST_RUN_OPTION ] = [ 'at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ), 'pruned' => 0, 'cutoff' => null, 'days' => 90 ];
		IATO_MCP_Receipt_Retention::maybe_run_overdue();
		$this->assertArrayNotHasKey( 'shutdown', IATO_Test_Abilities_State::$actions, 'ran yesterday: nothing to do' );

		IATO_MCP_Receipt_Retention::reset_request_state();
		IATO_Test_Abilities_State::$options[ IATO_MCP_Receipt_Retention::LAST_RUN_OPTION ]['at'] = gmdate( 'Y-m-d H:i:s', time() - 3 * 86400 );
		IATO_MCP_Receipt_Retention::maybe_run_overdue();
		IATO_MCP_Receipt_Retention::maybe_run_overdue();
		$this->assertCount( 1, IATO_Test_Abilities_State::$actions['shutdown'] ?? [], 'overdue: one batch, registered once per request' );

		IATO_MCP_Receipt_Retention::reset_request_state();
		IATO_Test_Abilities_State::$actions = [];
		unset( IATO_Test_Abilities_State::$options[ IATO_MCP_Receipt_Retention::LAST_RUN_OPTION ] );
		IATO_MCP_Receipt_Retention::maybe_run_overdue();
		$this->assertCount( 1, IATO_Test_Abilities_State::$actions['shutdown'] ?? [], 'never ran: overdue too' );
	}

	public function test_scheduling(): void {
		IATO_MCP_Receipt_Retention::ensure_scheduled();
		$this->assertSame( 'daily', IATO_Test_Cron::$events[ IATO_MCP_Receipt_Retention::HOOK ]['recurrence'] );
		$at = IATO_Test_Cron::$events[ IATO_MCP_Receipt_Retention::HOOK ]['at'];
		IATO_MCP_Receipt_Retention::ensure_scheduled();
		$this->assertSame( $at, IATO_Test_Cron::$events[ IATO_MCP_Receipt_Retention::HOOK ]['at'], 'not rescheduled when present' );
		IATO_MCP_Receipt_Retention::unschedule();
		$this->assertArrayNotHasKey( IATO_MCP_Receipt_Retention::HOOK, IATO_Test_Cron::$events );
	}

	public function test_a_cleared_receipt_is_refused_and_never_read_as_no_prior_data(): void {
		IATO_Test_WP::$posts[ 77 ] = [ 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => 1, 'post_content' => '' ];
		IATO_Test_Roles::$posts[ 77 ] = IATO_Test_WP::$posts[ 77 ];
		IATO_Test_WP::$meta[ 77 ]  = [ '_elementor_data' => '[{"id":"x"}]', '_elementor_edit_mode' => 'builder' ];
		$receipt = [
			'change_id'      => 'wr_00000000000000aa',
			'post_id'        => 77,
			'target_type'    => 'elementor_widget',
			'field'          => 'elementor:document:patch',
			'before_value'   => null, // cleared
			'after_value'    => IATO_MCP_Elementor_Adapter::compute_revision( '[{"id":"x"}]' ),
			'applied_at'     => '2026-01-01 10:00:00',
			'rolled_back_at' => null,
			'pruned_at'      => '2026-04-01 03:00:00',
		];
		$r = IATO_MCP_Rollback::execute( $receipt );
		$this->assertSame( 'rollback_unavailable', $r->get_error_code() );
		$this->assertStringContainsString( 'Revisions', $r->get_error_message() );
		$this->assertSame( '[{"id":"x"}]', IATO_Test_WP::$meta[ 77 ]['_elementor_data'], 'the page is untouched' );
		$this->assertSame( [], $GLOBALS['wpdb']->updates, 'not marked rolled back' );
		$dry = IATO_MCP_Rollback::execute( $receipt, [ 'dry_run' => true ] );
		$this->assertSame( 'rollback_unavailable', $dry->get_error_code(), 'a dry run says the same' );
	}
}
