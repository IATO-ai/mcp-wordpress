<?php
declare( strict_types=1 );

/** The site's timezone for these tests: five hours behind UTC, with no daylight saving. */
if ( ! function_exists( 'get_gmt_from_date' ) ) {
	function get_gmt_from_date( string $date, string $format = 'Y-m-d H:i:s' ): string {
		return gmdate( $format, (int) strtotime( $date . ' UTC' ) + 5 * 3600 );
	}
}
if ( ! function_exists( 'get_date_from_gmt' ) ) {
	function get_date_from_gmt( string $date, string $format = 'Y-m-d H:i:s' ): string {
		return gmdate( $format, (int) strtotime( $date . ' UTC' ) - 5 * 3600 );
	}
}
