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
	public const VERSION = 5;

	/** Last step failure, for diagnostics ("step N: message"). */
	private static string $last_error = '';

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
}
