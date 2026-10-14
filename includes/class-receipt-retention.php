<?php
/**
 * Receipt retention (1.13.0): clears the stored page copies of old Elementor
 * change receipts.
 *
 * Since 1.13.0 every Elementor edit made through MCP keeps the page's
 * `_elementor_data` as it was before the edit in the receipt's before_value,
 * so the edit can be rolled back exactly. That is one full copy of the page
 * per edit in {prefix}iato_change_receipts. After the retention period
 * (90 days by default) this job empties before_value and sets pruned_at; the
 * receipt row stays as an audit record (who changed what, when) and its
 * rollback is refused with rollback_unavailable, pointing at Elementor's
 * revisions.
 *
 * Runs daily on WP-Cron (hook `iato_mcp_prune_receipts`, scheduled on `init`
 * when missing, cleared on deactivation and uninstall). WP-Cron only runs
 * when the site gets visits and some hosts disable it, so a write that
 * records a receipt also runs one batch at the end of its request when the
 * last run is more than two days old. Work is done in batches of BATCH rows
 * with a time budget, so the table is never locked for long.
 *
 * Retention period: filter `iato_mcp_receipt_retention_days` (int, default
 * 90; 0 or less keeps every copy).
 *
 * The space freed is reused by MySQL for new rows; the table file on disk only
 * shrinks after OPTIMIZE TABLE. Backups shrink at once.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Receipt_Retention {

	/** WP-Cron hook. */
	public const HOOK = 'iato_mcp_prune_receipts';

	/** Schema version that added receipts.pruned_at (IATO_MCP_Schema step 5). */
	public const SCHEMA = 5;

	public const DEFAULT_DAYS = 90;

	/** Rows per UPDATE. */
	public const BATCH = 200;

	/** Seconds a run may keep issuing batches. */
	public const TIME_BUDGET = 10;

	/** A run older than this (seconds) is overdue: the next receipt write runs one batch. */
	public const OVERDUE = 172800;

	/** Option holding the last run: at (UTC), pruned, cutoff, days. */
	public const LAST_RUN_OPTION = 'iato_mcp_receipt_prune_last';

	/** Receipt types whose before_value holds a full page copy. */
	public const TYPES = [ 'elementor_widget' ];

	private static bool $overdue_checked = false;

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run_scheduled' ] );
		add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );
	}

	/** Schedule the daily run when it is missing (fresh install, upgrade, reactivation). */
	public static function ensure_scheduled(): void {
		if ( function_exists( 'wp_next_scheduled' ) && false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/** Retention period in days; 0 or less keeps every copy. */
	public static function days(): int {
		return (int) apply_filters( 'iato_mcp_receipt_retention_days', self::DEFAULT_DAYS );
	}

	/** WP-Cron entry point. */
	public static function run_scheduled(): void {
		self::run();
	}

	/**
	 * Clear the page copies of Elementor receipts older than the retention
	 * period, in batches.
	 *
	 * @param int      $max_batches Upper bound on UPDATE statements this run.
	 * @param int|null $now         Unix time (tests).
	 * @return array{ran:bool, pruned:int, cutoff:?string, reason?:string}
	 */
	public static function run( int $max_batches = 25, ?int $now = null ): array {
		$now = $now ?? time();
		if ( ! class_exists( 'IATO_MCP_Schema' ) || IATO_MCP_Schema::current() < self::SCHEMA ) {
			return [ 'ran' => false, 'pruned' => 0, 'cutoff' => null, 'reason' => 'schema' ];
		}
		$days = self::days();
		if ( $days <= 0 ) {
			self::note_run( $now, 0, null, $days );
			return [ 'ran' => false, 'pruned' => 0, 'cutoff' => null, 'reason' => 'disabled' ];
		}

		global $wpdb;
		$cutoff  = gmdate( 'Y-m-d H:i:s', $now - $days * 86400 );
		$stamp   = gmdate( 'Y-m-d H:i:s', $now );
		$types   = self::TYPES;
		$in      = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$started = microtime( true );
		$pruned  = 0;

		for ( $i = 0; $i < $max_batches; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table maintenance; $in is a list of %s placeholders.
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET before_value = NULL, pruned_at = %s WHERE target_type IN ($in) AND pruned_at IS NULL AND before_value IS NOT NULL AND applied_at < %s LIMIT %d",
					IATO_MCP_Change_Receipt::table_name(),
					$stamp,
					...array_merge( $types, [ $cutoff, self::BATCH ] )
				)
			);
			$affected = is_int( $affected ) ? $affected : 0;
			$pruned  += $affected;
			if ( $affected < self::BATCH || microtime( true ) - $started > self::TIME_BUDGET ) {
				break;
			}
		}

		self::note_run( $now, $pruned, $cutoff, $days );
		return [ 'ran' => true, 'pruned' => $pruned, 'cutoff' => $cutoff ];
	}

	/**
	 * Called when a receipt is recorded: if the daily run is overdue (WP-Cron
	 * not running), run one batch at the end of this request. Checked once per
	 * request.
	 */
	public static function maybe_run_overdue(): void {
		if ( self::$overdue_checked ) {
			return;
		}
		self::$overdue_checked = true;
		if ( ! class_exists( 'IATO_MCP_Schema' ) || IATO_MCP_Schema::current() < self::SCHEMA ) {
			return;
		}
		$last = self::last_run();
		$at   = null !== $last ? strtotime( $last['at'] . ' UTC' ) : false;
		if ( false !== $at && time() - $at < self::OVERDUE ) {
			return;
		}
		add_action( 'shutdown', [ __CLASS__, 'run_one_batch' ] );
	}

	public static function run_one_batch(): void {
		self::run( 1 );
	}

	/** @return array{at:string, pruned:int, cutoff:?string, days:int}|null */
	public static function last_run(): ?array {
		$last = get_option( self::LAST_RUN_OPTION, null );
		return is_array( $last ) && ! empty( $last['at'] ) ? $last : null;
	}

	private static function note_run( int $now, int $pruned, ?string $cutoff, int $days ): void {
		update_option( self::LAST_RUN_OPTION, [ 'at' => gmdate( 'Y-m-d H:i:s', $now ), 'pruned' => $pruned, 'cutoff' => $cutoff, 'days' => $days ], false );
	}

	/** Test helper: forget the once-per-request overdue check. */
	public static function reset_request_state(): void {
		self::$overdue_checked = false;
	}
}
