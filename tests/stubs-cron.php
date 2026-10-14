<?php
/**
 * WP-Cron stubs for the receipt retention tests. Guarded.
 */

declare( strict_types=1 );

final class IATO_Test_Cron {
	/** @var array<string,array{at:int,recurrence:string}> hook => event */
	public static array $events = [];

	public static function reset(): void {
		self::$events = [];
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = [] ): int|false {
		return isset( IATO_Test_Cron::$events[ $hook ] ) ? IATO_Test_Cron::$events[ $hook ]['at'] : false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false ): bool {
		IATO_Test_Cron::$events[ $hook ] = [ 'at' => $timestamp, 'recurrence' => $recurrence ];
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = [], bool $wp_error = false ): int {
		$had = isset( IATO_Test_Cron::$events[ $hook ] ) ? 1 : 0;
		unset( IATO_Test_Cron::$events[ $hook ] );
		return $had;
	}
}
