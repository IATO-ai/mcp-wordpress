<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * IATO_MCP_Schema::run(): steps above the stored version run in order and
 * bump the option one at a time; reruns are no-ops; a throwing step stops
 * the run without advancing past it and is retried next time; the real
 * step map is contiguous.
 */
final class SchemaMigrationTest extends TestCase {

	/** @var int[] */
	private array $calls = [];

	protected function setUp(): void {
		IATO_Test_Abilities_State::reset();
		$this->calls = [];
	}

	private function steps( array $throwing = [] ): array {
		$steps = [];
		foreach ( [ 1, 2, 3, 4 ] as $n ) {
			$steps[ $n ] = function () use ( $n, $throwing ) {
				$this->calls[] = $n;
				if ( in_array( $n, $throwing, true ) ) {
					throw new RuntimeException( "boom $n" );
				}
			};
		}
		return $steps;
	}

	public function test_fresh_site_runs_every_step_in_order_and_records_the_version(): void {
		$r = IATO_MCP_Schema::run( $this->steps(), 4 );
		$this->assertSame( [ 1, 2, 3, 4 ], $this->calls );
		$this->assertSame( [ 'from' => 0, 'to' => 4, 'ran' => [ 1, 2, 3, 4 ], 'error' => '' ], $r );
		$this->assertSame( 4, IATO_MCP_Schema::current() );
	}

	public function test_rerun_is_a_noop(): void {
		IATO_MCP_Schema::run( $this->steps(), 4 );
		$this->calls = [];
		$r = IATO_MCP_Schema::run( $this->steps(), 4 );
		$this->assertSame( [], $this->calls );
		$this->assertSame( [ 'from' => 4, 'to' => 4, 'ran' => [], 'error' => '' ], $r );
	}

	public function test_a_pre_release_site_gets_only_the_steps_it_has_not_seen(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Schema::OPTION ] = 2;
		IATO_Test_Abilities_State::$options['iato_mcp_db_version']    = '1.13.0'; // irrelevant to the schema runner
		$r = IATO_MCP_Schema::run( $this->steps(), 4 );
		$this->assertSame( [ 3, 4 ], $this->calls );
		$this->assertSame( 4, $r['to'] );
	}

	public function test_unordered_step_map_still_runs_in_version_order(): void {
		$steps = $this->steps();
		$steps = [ 3 => $steps[3], 1 => $steps[1], 2 => $steps[2] ];
		IATO_MCP_Schema::run( $steps, 3 );
		$this->assertSame( [ 1, 2, 3 ], $this->calls );
	}

	public function test_a_failing_step_stops_the_run_and_is_retried_next_time(): void {
		$r = IATO_MCP_Schema::run( $this->steps( [ 3 ] ), 4 );
		$this->assertSame( [ 1, 2, 3 ], $this->calls, 'step 4 did not run' );
		$this->assertSame( 2, IATO_MCP_Schema::current(), 'the version stays at the last successful step' );
		$this->assertSame( 'step 3: boom 3', $r['error'] );
		$this->assertSame( 'step 3: boom 3', IATO_MCP_Schema::last_error() );

		$this->calls = [];
		$r = IATO_MCP_Schema::run( $this->steps(), 4 ); // fixed
		$this->assertSame( [ 3, 4 ], $this->calls );
		$this->assertSame( 4, IATO_MCP_Schema::current() );
		$this->assertSame( '', $r['error'] );
	}

	public function test_target_caps_the_run(): void {
		IATO_MCP_Schema::run( $this->steps(), 2 );
		$this->assertSame( [ 1, 2 ], $this->calls );
		$this->assertSame( 2, IATO_MCP_Schema::current() );
	}

	public function test_a_deleted_option_replays_everything_safely(): void {
		IATO_MCP_Schema::run( $this->steps(), 4 );
		unset( IATO_Test_Abilities_State::$options[ IATO_MCP_Schema::OPTION ] );
		$this->calls = [];
		IATO_MCP_Schema::run( $this->steps(), 4 );
		$this->assertSame( [ 1, 2, 3, 4 ], $this->calls, 'every step must therefore be idempotent' );
	}

	public function test_real_step_map_is_contiguous_and_matches_the_version(): void {
		$steps = IATO_MCP_Schema::steps();
		$this->assertSame( range( 1, IATO_MCP_Schema::VERSION ), array_keys( $steps ) );
		foreach ( $steps as $n => $callable ) {
			$this->assertTrue( is_callable( $callable ), "step $n" );
		}
	}
}
