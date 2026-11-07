<?php
/**
 * Dates on create_post and update_post (1.19.0): reading what a call sends, and
 * what WordPress will do to a post's status because of it.
 *
 * Pure logic apart from WordPress's two date helpers, so it can be tested alone.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_Post_Date {

	/** What WordPress stores as the UTC date of a draft that has no date of its own yet. */
	public const ZERO = '0000-00-00 00:00:00';

	/** wp_insert_post() treats a date less than a minute ahead as now. */
	private const FUTURE_MARGIN = 60;

	/** MySQL's DATETIME starts here, and WordPress reads anything earlier as no date. */
	private const FIRST_YEAR = 1000;

	/**
	 * The date a call sends, in the two forms WordPress stores.
	 *
	 * @return array{local:string,gmt:string}|WP_Error|null Null when neither was sent.
	 */
	public static function from_args( array $args ): array|WP_Error|null {
		$local = isset( $args['date'] ) ? self::parse( $args['date'], 'date' ) : null;
		if ( is_wp_error( $local ) ) {
			return $local;
		}
		$gmt = isset( $args['date_gmt'] ) ? self::parse( $args['date_gmt'], 'date_gmt' ) : null;
		if ( is_wp_error( $gmt ) ) {
			return $gmt;
		}
		if ( null === $local && null === $gmt ) {
			return null;
		}
		if ( null === $gmt ) {
			return [ 'local' => $local, 'gmt' => (string) get_gmt_from_date( $local ) ];
		}
		if ( null === $local ) {
			return [ 'local' => (string) get_date_from_gmt( $gmt ), 'gmt' => $gmt ];
		}
		$expected = (string) get_gmt_from_date( $local );
		if ( $expected !== $gmt ) {
			return new WP_Error(
				'date_mismatch',
				sprintf( 'date and date_gmt are different moments: in this site\'s timezone, date %1$s is %2$s UTC, not %3$s. Send one of them. Nothing was written.', $local, $expected, $gmt ),
				[ 'date' => $local, 'date_gmt' => $gmt, 'expected_date_gmt' => $expected ]
			);
		}
		return [ 'local' => $local, 'gmt' => $gmt ];
	}

	/** One date, as MySQL's Y-m-d H:i:s. Only date_gmt may say it is UTC. */
	private static function parse( mixed $value, string $name ): string|WP_Error {
		$pattern = 'date_gmt' === $name
			? '/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2}):(\d{2})(?:[Zz]|[+-]00(?::?00)?)?$/D'
			: '/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2}):(\d{2})$/D';
		$example = 'date_gmt' === $name ? '2026-10-27T14:00:00' : '2026-10-27T09:00:00';
		$hint    = 'date_gmt' === $name ? 'in UTC' : 'in the site\'s local time, with no offset (send UTC as date_gmt)';

		if ( ! is_string( $value ) || 1 !== preg_match( $pattern, trim( $value ), $m ) ) {
			return new WP_Error(
				'invalid_date',
				sprintf( '%1$s must look like %2$s, %3$s. Nothing was written.', $name, $example, $hint ),
				[ 'field' => $name ]
			);
		}
		[ , $year, $month, $day, $hour, $minute, $second ] = array_map( 'intval', $m );
		if ( $year < self::FIRST_YEAR || ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
			return new WP_Error(
				'invalid_date',
				sprintf( '%1$s is not a real date and time. Nothing was written.', $name ),
				[ 'field' => $name ]
			);
		}
		return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second );
	}

	/** Far enough ahead that WordPress schedules rather than publishes? */
	public static function is_future( string $gmt ): bool {
		if ( '' === $gmt || self::ZERO === $gmt ) {
			return false;
		}
		return (int) strtotime( $gmt . ' UTC' ) - time() >= self::FUTURE_MARGIN;
	}

	/** The status wp_insert_post() will store when asked for this one with this date. */
	public static function resulting_status( string $status, string $gmt, string $post_type ): string {
		if ( 'attachment' === $post_type ) {
			return $status;
		}
		if ( 'publish' === $status && self::is_future( $gmt ) ) {
			return 'future';
		}
		if ( 'future' === $status && ! self::is_future( $gmt ) ) {
			return 'publish';
		}
		return $status;
	}

	/** What a date receipt holds: the pair, and the status they can change. */
	public static function snapshot( object $post ): array {
		return [
			'date'     => (string) $post->post_date,
			'date_gmt' => (string) $post->post_date_gmt,
			'status'   => (string) $post->post_status,
		];
	}

	/** The UTC date for a response. A draft with no date of its own has none. */
	public static function gmt_or_null( string $gmt ): ?string {
		return self::ZERO === $gmt ? null : $gmt;
	}

	public static function future_refusal( string $local, string $gmt ): WP_Error {
		return new WP_Error(
			'future_date_on_published',
			sprintf( 'A published post with a future date (%s) would be scheduled, and leave the site until then. Pass allow_future=true to schedule it. Nothing was written.', $local ),
			[ 'date' => $local, 'date_gmt' => $gmt ]
		);
	}

	/** Says what the date did to the status, when it did something the caller did not ask for by name. */
	public static function status_note( string $before, string $after, string $local ): ?string {
		if ( 'future' === $after && 'future' !== $before ) {
			return sprintf( 'This post is now scheduled. It is not on the site until %s, the site\'s local time.', $local );
		}
		if ( 'publish' === $after && 'future' === $before ) {
			return 'This post was scheduled. Its new date has passed, so it is now published.';
		}
		return null;
	}
}
