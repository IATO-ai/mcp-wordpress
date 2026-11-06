<?php
/**
 * Schema migrations, keyed by an integer schema version that is independent
 * of the plugin version.
 *
 * Why not the plugin version: a site that ran a pre-release build already
 * carries that build's `iato_mcp_db_version`, so a version_compare gate on
 * the final release would skip every table change made after that build.
 * The schema version only moves when a release changes tables or needs a
 * one-shot data fix-up, and every step is idempotent, so a step can run
 * again (or the option can be deleted to replay everything) without harm.
 *
 * Adding a step: append `N => [ self::class, 'step_name' ]` to steps() with
 * N = VERSION + 1, bump VERSION, keep the step idempotent (dbDelta,
 * INSERT IGNORE / NOT EXISTS, option writes that tolerate repeats).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Schema {

	/** Option holding the schema version the database is at. */
	public const OPTION = 'iato_mcp_schema_version';

	/** The schema version this code expects. Bump when adding a step. */
	public const VERSION = 7;

	/** Option describing a table problem. It exists only while there is one. */
	public const HEALTH_OPTION = 'iato_mcp_schema_health';

	/** Seconds between automatic repair attempts on a site with a problem. */
	public const RETRY_SECONDS = 3600;

	/** Column types that matter beyond the column existing: table suffix => column => type. */
	private const REQUIRED_TYPES = [ 'iato_change_receipts' => [ 'field' => 'varchar(255)' ] ];

	/** Last step failure, for diagnostics ("step N: message"). */
	private static string $last_error = '';

	/** Replaces the dbDelta calls in tests, which have no wp-admin to load. */
	private static $table_creator = null;

	/** Replaces the real table list in tests. */
	private static ?array $table_definitions = null;

	public static function current(): int {
		return max( 0, (int) get_option( self::OPTION, 0 ) );
	}

	public static function last_error(): string {
		return self::$last_error;
	}

	/**
	 * Ordered steps: version => callable. Contiguous from 1 to VERSION.
	 *
	 * @return array<int,callable>
	 */
	public static function steps(): array {
		return [
			1 => [ self::class, 'step_base_tables' ],
			2 => [ self::class, 'step_oauth_tables' ],
			3 => [ self::class, 'step_oauth_grants' ],
			4 => [ self::class, 'step_receipt_actors' ],
			5 => [ self::class, 'step_receipt_retention' ],
			6 => [ self::class, 'step_element_cache_refresh' ],
			7 => [ self::class, 'step_repair_tables' ],
		];
	}

	/**
	 * Run every step above the stored version, in order, bumping the option
	 * after each successful step. A step that throws stops the run; the
	 * option stays at the last successful step, so the next request retries
	 * from there. Steps and target are injectable for tests.
	 *
	 * @param array<int,callable>|null $steps  defaults to steps()
	 * @param int|null                 $target defaults to VERSION
	 * @return array{from:int,to:int,ran:int[],error:string}
	 */
	public static function run( ?array $steps = null, ?int $target = null ): array {
		$steps  = $steps ?? self::steps();
		$target = $target ?? self::VERSION;
		ksort( $steps );
		$from = self::current();
		$at   = $from;
		$ran  = [];
		self::$last_error = '';

		foreach ( $steps as $version => $callable ) {
			$version = (int) $version;
			if ( $version <= $at || $version > $target ) {
				continue;
			}
			try {
				call_user_func( $callable );
			} catch ( Throwable $e ) {
				self::$last_error = 'step ' . $version . ': ' . $e->getMessage();
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[iato-mcp schema] ' . self::$last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				break;
			}
			$at    = $version;
			$ran[] = $version;
			update_option( self::OPTION, $at, false );
		}

		return [ 'from' => $from, 'to' => $at, 'ran' => $ran, 'error' => self::$last_error ];
	}

	// ── Steps (all idempotent) ─────────────────────────────────────────────

	/** 1: the tables every release before 1.13.0 had (dbDelta, so a no-op when present). */
	public static function step_base_tables(): void {
		IATO_MCP_Change_Receipt::create_table();
		IATO_MCP_Call_Log::create_table();
		IATO_MCP_Media_Phase_Log::create_table();
	}

	/**
	 * 2 (1.13.0, Part 1): OAuth client and token tables; clients registered
	 * under the retired option are carried over (skipping any that already
	 * exist) and marked as authorized so pending-client pruning leaves them
	 * alone; the global PKCE transient is removed. Sites that had OAuth
	 * clients get two flags: the dismissible notice and the fact that
	 * pre-1.13.0 connections may still hold the key (cleared by Regenerate).
	 */
	public static function step_oauth_tables(): void {
		IATO_MCP_OAuth_Store::create_tables();
		$legacy_clients = get_option( 'iato_mcp_oauth_clients', [] );
		if ( is_array( $legacy_clients ) && ! empty( $legacy_clients ) ) {
			$store = IATO_MCP_OAuth_Store::instance();
			foreach ( $legacy_clients as $client_id => $client ) {
				$client_id = sanitize_text_field( (string) $client_id );
				if ( '' === $client_id || ! is_array( $client ) || null !== $store->find_client( $client_id ) ) {
					continue;
				}
				$uris = array_values( array_filter( (array) ( $client['redirect_uris'] ?? [] ), 'is_string' ) );
				$when = gmdate( 'Y-m-d H:i:s', (int) ( $client['created'] ?? time() ) );
				$store->insert_client( [
					'client_id'       => substr( $client_id, 0, 64 ),
					'client_name'     => mb_substr( sanitize_text_field( (string) ( $client['client_name'] ?? '' ) ), 0, 100 ),
					'redirect_uris'   => $uris,
					'registration_ip' => null,
					'created_at'      => $when,
					'authorized_at'   => $when,
				] );
			}
			update_option( 'iato_mcp_oauth_legacy_notice', '1', false );
			update_option( 'iato_mcp_oauth_legacy_key', '1', false );
		}
		delete_option( 'iato_mcp_oauth_clients' );
		delete_transient( 'iato_mcp_oauth_pkce' );
	}

	/**
	 * 3 (1.13.0, Part 1 second pass): the grants table, and a grant row for
	 * every grant_id in the token table that has none (INSERT IGNORE + NOT
	 * EXISTS, so repeat runs add nothing). Retires the one-shot flag the
	 * first build used for the same purpose.
	 */
	public static function step_oauth_grants(): void {
		IATO_MCP_OAuth_Store::create_tables();
		IATO_MCP_OAuth_Store::backfill_grants();
		delete_option( 'iato_mcp_oauth_grants_backfilled' );
	}

	/**
	 * 4 (1.13.0, Part 3): who made each change. Receipts gain the actor
	 * (WordPress user, credential kind, OAuth client) and who rolled them back;
	 * the call log gains the credential kind and OAuth client. dbDelta adds the
	 * columns and the index; existing rows keep NULL, which the rollback rule
	 * reads as "recorded before actors existed".
	 */
	public static function step_receipt_actors(): void {
		IATO_MCP_Change_Receipt::create_table();
		IATO_MCP_Call_Log::create_table();
	}

	/**
	 * 5 (1.13.0, Part 3): receipt retention. Receipts gain pruned_at (set when
	 * IATO_MCP_Receipt_Retention clears an old Elementor page copy) and an index
	 * on (target_type, applied_at) for the cleanup query. dbDelta.
	 */
	public static function step_receipt_retention(): void {
		IATO_MCP_Change_Receipt::create_table();
	}

	/** 6 (1.15.1): refresh Elementor's element cache (IATO_MCP_Element_Cache_Guard::refresh()). */
	public static function step_element_cache_refresh(): void {
		IATO_MCP_Element_Cache_Guard::refresh();
	}

	/**
	 * 7 (1.16.0): re-run every table's dbDelta, which also widens receipts.field,
	 * and check the result. Earlier steps counted as done even when an ALTER had
	 * failed, because dbDelta reports nothing. This step never throws: a site whose
	 * tables cannot be repaired must still receive later steps.
	 */
	public static function step_repair_tables(): void {
		self::repair();
	}

	// ── Table health ─────────────────────────────────────────────────────

	/**
	 * Run the tables' dbDelta and check what they left. A problem is kept in
	 * HEALTH_OPTION, with the database's own message, for Diagnostics and the
	 * retry in maybe_repair(). Never throws.
	 *
	 * @return array what is wrong (table_problems() shape), empty when healthy
	 */
	public static function repair(): array {
		$error    = '';
		$problems = [];
		$threw    = false;
		try {
			self::create_tables();
			$problems = self::table_problems();
			if ( ! empty( $problems ) ) {
				$error    = self::fix_directly( $problems );
				$problems = self::table_problems();
			}
		} catch ( Throwable $e ) {
			$threw = true;
			$error = substr( $e->getMessage(), 0, 500 );
		}

		if ( empty( $problems ) && ! $threw ) {
			delete_option( self::HEALTH_OPTION );
			return [];
		}

		$previous = self::health();
		// Autoloaded, so the check on every request costs no query.
		update_option(
			self::HEALTH_OPTION,
			[
				'problems'       => $problems,
				'error'          => $error,
				'checked_at'     => time(),
				'plugin_version' => defined( 'IATO_MCP_VERSION' ) ? IATO_MCP_VERSION : '',
				'attempts'       => (int) ( $previous['attempts'] ?? 0 ) + 1,
			],
			true
		);
		return $problems;
	}

	/**
	 * Retry the repair on a site with a problem: at most hourly, at once after a
	 * plugin update (which may carry the fix) or when forced (activation).
	 * Healthy sites have no option, so this does no work there.
	 */
	public static function maybe_repair( bool $force = false ): void {
		$health = self::health();
		if ( null === $health ) {
			return;
		}
		$same_build = (string) ( $health['plugin_version'] ?? '' ) === ( defined( 'IATO_MCP_VERSION' ) ? IATO_MCP_VERSION : '' );
		if ( ! $force && $same_build && time() - (int) ( $health['checked_at'] ?? 0 ) < self::RETRY_SECONDS ) {
			return;
		}
		self::repair();
	}

	/**
	 * The stored problem, or null on a healthy site.
	 *
	 * @return array{problems:array,error:string,checked_at:int,plugin_version:string,attempts:int}|null
	 */
	public static function health(): ?array {
		$health = get_option( self::HEALTH_OPTION, null );
		return is_array( $health ) ? $health : null;
	}

	/**
	 * What differs from the tables' definitions. Column names are compared in
	 * lower case; types only where REQUIRED_TYPES names one.
	 *
	 * @return array<string,array{table_missing?:bool,missing?:string[],wrong_type?:array<string,string>}>
	 */
	public static function table_problems(): array {
		global $wpdb;

		$problems = [];
		foreach ( self::table_definitions() as $table => $sql ) {
			$have = self::columns_of( $table );
			if ( empty( $have ) ) {
				$problems[ $table ] = [ 'table_missing' => true ];
				continue;
			}
			$problem = [];
			$missing = array_values( array_diff( array_keys( self::column_definitions( $sql ) ), array_keys( $have ) ) );
			if ( ! empty( $missing ) ) {
				$problem['missing'] = $missing;
			}
			$required = self::REQUIRED_TYPES[ substr( $table, strlen( (string) $wpdb->prefix ) ) ] ?? [];
			foreach ( $required as $column => $type ) {
				if ( isset( $have[ $column ] ) && $have[ $column ] !== $type ) {
					$problem['wrong_type'][ $column ] = $have[ $column ];
				}
			}
			if ( ! empty( $problem ) ) {
				$problems[ $table ] = $problem;
			}
		}
		return $problems;
	}

	/**
	 * Which of these columns does the table lack? For write paths that have just
	 * failed: asking the table works in any database language, unlike reading
	 * the error message.
	 *
	 * @param string[] $expected
	 * @return string[]
	 */
	public static function missing_columns( string $table, array $expected ): array {
		return array_values( array_diff( array_map( 'strtolower', $expected ), array_keys( self::columns_of( $table ) ) ) );
	}

	/**
	 * The columns in a CREATE TABLE statement.
	 *
	 * @return array<string,string> lower-case column name => its definition
	 */
	public static function column_definitions( string $sql ): array {
		$columns = [];
		foreach ( (array) preg_split( '/\R/', $sql ) as $line ) {
			$line = rtrim( trim( (string) $line ), ',' );
			if ( '' === $line || 1 === preg_match( '/^(?:CREATE\s|\)|PRIMARY\s|UNIQUE\s|KEY\s|INDEX\s|FULLTEXT\s|SPATIAL\s)/i', $line ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^`?([a-z0-9_]+)`?\s+(.+)$/i', $line, $m ) ) {
				$columns[ strtolower( $m[1] ) ] = $m[2];
			}
		}
		return $columns;
	}

	/**
	 * For tests: what runs in place of the dbDelta calls, and the tables to check.
	 *
	 * @param array<string,string>|null $definitions table name => CREATE TABLE statement
	 */
	public static function set_test_tables( ?callable $creator, ?array $definitions ): void {
		self::$table_creator     = $creator;
		self::$table_definitions = $definitions;
	}

	private static function create_tables(): void {
		if ( null !== self::$table_creator ) {
			call_user_func( self::$table_creator );
			return;
		}
		IATO_MCP_Change_Receipt::create_table();
		IATO_MCP_Call_Log::create_table();
		IATO_MCP_Media_Phase_Log::create_table();
		IATO_MCP_OAuth_Store::create_tables();
	}

	/** @return array<string,string> table name => CREATE TABLE statement */
	private static function table_definitions(): array {
		if ( null !== self::$table_definitions ) {
			return self::$table_definitions;
		}
		return [
			IATO_MCP_Change_Receipt::table_name()  => IATO_MCP_Change_Receipt::schema_sql(),
			IATO_MCP_Call_Log::table_name()        => IATO_MCP_Call_Log::schema_sql(),
			IATO_MCP_Media_Phase_Log::table_name() => IATO_MCP_Media_Phase_Log::schema_sql(),
		] + IATO_MCP_OAuth_Store::schema_sql();
	}

	/** @return array<string,string> lower-case column name => lower-case type; empty when unreadable */
	private static function columns_of( string $table ): array {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the live table definition.
		$rows = $wpdb->get_results( $wpdb->prepare( 'DESC %i', $table ) );
		$wpdb->suppress_errors( $suppress );

		$columns = [];
		foreach ( (array) $rows as $row ) {
			$columns[ strtolower( (string) $row->Field ) ] = strtolower( (string) $row->Type );
		}
		return $columns;
	}

	/**
	 * dbDelta reports nothing and wpdb clears last_error on the next query, so what
	 * dbDelta could not do is tried once more directly, which also yields the
	 * database's own reason.
	 *
	 * @return string the first error, empty when these all worked
	 */
	private static function fix_directly( array $problems ): string {
		global $wpdb;

		$definitions = self::table_definitions();
		$first_error = '';
		foreach ( $problems as $table => $problem ) {
			$columns = self::column_definitions( $definitions[ $table ] );
			$queries = ! empty( $problem['table_missing'] ) ? [ $definitions[ $table ] ] : [];
			foreach ( $problem['missing'] ?? [] as $column ) {
				$queries[] = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$columns[ $column ]}";
			}
			foreach ( array_keys( $problem['wrong_type'] ?? [] ) as $column ) {
				$queries[] = "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$columns[ $column ]}";
			}
			foreach ( $queries as $query ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Built from this plugin's own table definitions.
				if ( false === $wpdb->query( $query ) && '' === $first_error ) {
					$first_error = (string) $wpdb->last_error;
					if ( '' === $first_error ) {
						$first_error = 'The database refused the change without giving a reason.';
					}
				}
			}
		}
		return substr( $first_error, 0, 500 );
	}
}
