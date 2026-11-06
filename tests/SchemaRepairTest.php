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
 * 1.16.0: dbDelta reports nothing, so IATO_MCP_Schema::repair() checks what it
 * left. A problem is recorded and retried at most hourly, and never stops
 * the run, so a site that cannot be repaired still gets later steps.
 */
final class SchemaRepairTest extends TestCase {

	private const TABLE = 'wp_iato_change_receipts';
	private const SQL   = "CREATE TABLE wp_iato_change_receipts (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		field VARCHAR(255) NOT NULL,
		actor_kind VARCHAR(30) DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY field (field),
		KEY actor_kind (actor_kind)
	) ;";

	private int $created = 0;

	protected function setUp(): void {
		IATO_Test_Abilities_State::reset();
		$this->created = 0;
		$db                = $GLOBALS['wpdb'];
		$db->queries       = [];
		$db->query_results = [];
		$db->last_error    = '';
		$db->described     = [ self::TABLE => [ 'id' => 'bigint unsigned', 'field' => 'varchar(255)', 'actor_kind' => 'varchar(30)' ] ];
		IATO_MCP_Schema::set_test_tables(
			function (): void {
				$this->created++;
			},
			[ self::TABLE => self::SQL ]
		);
	}

	protected function tearDown(): void {
		IATO_MCP_Schema::set_test_tables( null, null );
		$GLOBALS['wpdb']->described     = [];
		$GLOBALS['wpdb']->query_results = [];
		$GLOBALS['wpdb']->queries       = [];
		IATO_Test_Abilities_State::reset();
	}

	private static function health(): ?array {
		return IATO_MCP_Schema::health();
	}

	private static function build(): string {
		return defined( 'IATO_MCP_VERSION' ) ? IATO_MCP_VERSION : '';
	}

	public function test_step_seven_is_the_repair_and_the_last_step(): void {
		$this->assertSame( [ IATO_MCP_Schema::class, 'step_repair_tables' ], IATO_MCP_Schema::steps()[7] );
		$this->assertSame( 7, IATO_MCP_Schema::VERSION );
	}

	public function test_column_definitions_skip_the_keys(): void {
		$this->assertSame(
			[ 'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'field' => 'VARCHAR(255) NOT NULL', 'actor_kind' => 'VARCHAR(30) DEFAULT NULL' ],
			IATO_MCP_Schema::column_definitions( self::SQL )
		);
		$this->assertSame( [ 'a' => 'INT' ], IATO_MCP_Schema::column_definitions( "CREATE TABLE t (\n a INT,\n PRIMARY KEY (a)\n) ;" ) );
	}

	public function test_the_real_tables_parse(): void {
		$receipts = IATO_MCP_Schema::column_definitions( IATO_MCP_Change_Receipt::schema_sql() );
		$this->assertArrayHasKey( 'rolled_back_by_client_id', $receipts );
		$this->assertArrayHasKey( 'pruned_at', $receipts );
		$this->assertArrayNotHasKey( 'primary', $receipts );
		$this->assertArrayNotHasKey( 'key', $receipts );
		foreach ( IATO_MCP_OAuth_Store::schema_sql() as $table => $sql ) {
			$this->assertStringContainsString( $table, $sql );
			$this->assertArrayHasKey( 'client_id', IATO_MCP_Schema::column_definitions( $sql ) );
		}
	}

	public function test_a_healthy_site_stores_nothing(): void {
		$this->assertSame( [], IATO_MCP_Schema::repair() );
		$this->assertSame( 1, $this->created );
		$this->assertNull( self::health() );
		$this->assertSame( [], $GLOBALS['wpdb']->queries, 'nothing to fix directly' );
	}

	public function test_column_names_compare_in_lower_case(): void {
		$GLOBALS['wpdb']->described[ self::TABLE ] = [ 'ID' => 'BIGINT UNSIGNED', 'FIELD' => 'VARCHAR(255)', 'ACTOR_KIND' => 'VARCHAR(30)' ];
		$this->assertSame( [], IATO_MCP_Schema::table_problems() );
	}

	public function test_a_column_dbdelta_left_behind_is_recorded_with_the_database_message(): void {
		unset( $GLOBALS['wpdb']->described[ self::TABLE ]['actor_kind'] );
		$GLOBALS['wpdb']->query_results = [ false ];
		$GLOBALS['wpdb']->last_error    = 'ALTER command denied to user';

		$problems = IATO_MCP_Schema::repair();

		$this->assertSame( [ 'actor_kind' ], $problems[ self::TABLE ]['missing'] );
		$this->assertSame( 'ALTER TABLE `wp_iato_change_receipts` ADD COLUMN `actor_kind` VARCHAR(30) DEFAULT NULL', $GLOBALS['wpdb']->queries[0] );
		$health = self::health();
		$this->assertSame( 'ALTER command denied to user', $health['error'] );
		$this->assertSame( 1, $health['attempts'] );
		$this->assertSame( [ 'actor_kind' ], $health['problems'][ self::TABLE ]['missing'] );
	}

	public function test_a_refused_change_with_no_message_still_says_something(): void {
		unset( $GLOBALS['wpdb']->described[ self::TABLE ]['actor_kind'] );
		$GLOBALS['wpdb']->query_results = [ false ];
		IATO_MCP_Schema::repair();
		$this->assertStringContainsString( 'refused', self::health()['error'] );
	}

	public function test_a_narrow_field_column_is_a_problem(): void {
		$GLOBALS['wpdb']->described[ self::TABLE ]['field'] = 'varchar(100)';
		$problems = IATO_MCP_Schema::repair();
		$this->assertSame( [ 'field' => 'varchar(100)' ], $problems[ self::TABLE ]['wrong_type'] );
		$this->assertSame( 'ALTER TABLE `wp_iato_change_receipts` MODIFY COLUMN `field` VARCHAR(255) NOT NULL', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_a_missing_table_is_a_problem_and_is_created_directly(): void {
		$GLOBALS['wpdb']->described = [];
		$problems = IATO_MCP_Schema::repair();
		$this->assertTrue( $problems[ self::TABLE ]['table_missing'] );
		$this->assertSame( self::SQL, $GLOBALS['wpdb']->queries[0] );
	}

	public function test_a_later_repair_clears_the_problem(): void {
		$GLOBALS['wpdb']->described[ self::TABLE ]['field'] = 'varchar(100)';
		IATO_MCP_Schema::repair();
		$this->assertNotNull( self::health() );
		$GLOBALS['wpdb']->described[ self::TABLE ]['field'] = 'varchar(255)';
		IATO_MCP_Schema::repair();
		$this->assertNull( self::health() );
	}

	public function test_repair_never_throws_and_the_run_finishes(): void {
		IATO_MCP_Schema::set_test_tables(
			function (): void {
				throw new RuntimeException( 'boom' );
			},
			[ self::TABLE => self::SQL ]
		);
		$after = false;
		$result = IATO_MCP_Schema::run(
			[
				7 => [ IATO_MCP_Schema::class, 'step_repair_tables' ],
				8 => function () use ( &$after ): void {
					$after = true;
				},
			],
			8
		);
		$this->assertSame( '', $result['error'] );
		$this->assertTrue( $after, 'a later step still runs on a site that cannot be repaired' );
		$this->assertSame( 8, IATO_MCP_Schema::current() );
		$this->assertSame( 'boom', self::health()['error'] );
	}

	public function test_a_healthy_site_does_no_work_on_a_request(): void {
		IATO_MCP_Schema::maybe_repair();
		$this->assertSame( 0, $this->created );
	}

	public function test_the_retry_waits_an_hour(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Schema::HEALTH_OPTION ] = [
			'problems'       => [],
			'error'          => 'x',
			'checked_at'     => time() - IATO_MCP_Schema::RETRY_SECONDS + 60,
			'plugin_version' => self::build(),
			'attempts'       => 1,
		];
		IATO_MCP_Schema::maybe_repair();
		$this->assertSame( 0, $this->created, 'too soon' );

		IATO_Test_Abilities_State::$options[ IATO_MCP_Schema::HEALTH_OPTION ]['checked_at'] = time() - IATO_MCP_Schema::RETRY_SECONDS - 1;
		IATO_MCP_Schema::maybe_repair();
		$this->assertSame( 1, $this->created );
	}

	public function test_a_plugin_update_retries_at_once(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Schema::HEALTH_OPTION ] = [
			'problems'       => [],
			'error'          => 'x',
			'checked_at'     => time(),
			'plugin_version' => '0.0.1',
			'attempts'       => 1,
		];
		IATO_MCP_Schema::maybe_repair();
		$this->assertSame( 1, $this->created );
	}

	public function test_activation_retries_at_once(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Schema::HEALTH_OPTION ] = [
			'problems'       => [],
			'error'          => 'x',
			'checked_at'     => time(),
			'plugin_version' => self::build(),
			'attempts'       => 1,
		];
		IATO_MCP_Schema::maybe_repair( true );
		$this->assertSame( 1, $this->created );
	}

	public function test_missing_columns_asks_the_table(): void {
		$this->assertSame( [ 'nope' ], IATO_MCP_Schema::missing_columns( self::TABLE, [ 'FIELD', 'nope' ] ) );
		$this->assertSame( [ 'a' ], IATO_MCP_Schema::missing_columns( 'no_such_table', [ 'a' ] ) );
	}
}
