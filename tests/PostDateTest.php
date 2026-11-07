<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-post-date.php';
require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';

/**
 * Dates on create_post and update_post (1.19.0): the rules. What the tools do with
 * them on real WordPress is covered by tests/wp-env/post-dates-live.py.
 */
final class PostDateTest extends TestCase {

	private static function future( int $seconds = 86400 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $seconds );
	}

	public function test_nothing_sent_is_no_date(): void {
		$this->assertNull( IATO_MCP_Post_Date::from_args( [ 'title' => 'x' ] ) );
	}

	public function test_a_local_date_gets_its_utc_date_from_the_site_timezone(): void {
		$this->assertSame(
			[ 'local' => '2026-10-27 09:00:00', 'gmt' => '2026-10-27 14:00:00' ],
			IATO_MCP_Post_Date::from_args( [ 'date' => '2026-10-27T09:00:00' ] )
		);
	}

	public function test_a_utc_date_gets_its_local_date(): void {
		foreach ( [ '2026-10-27T14:00:00', '2026-10-27T14:00:00Z', '2026-10-27T14:00:00+00:00', '2026-10-27 14:00:00' ] as $sent ) {
			$this->assertSame( [ 'local' => '2026-10-27 09:00:00', 'gmt' => '2026-10-27 14:00:00' ], IATO_MCP_Post_Date::from_args( [ 'date_gmt' => $sent ] ), $sent );
		}
	}

	public function test_both_that_agree_are_taken(): void {
		$this->assertSame(
			[ 'local' => '2026-10-27 09:00:00', 'gmt' => '2026-10-27 14:00:00' ],
			IATO_MCP_Post_Date::from_args( [ 'date' => '2026-10-27T09:00:00', 'date_gmt' => '2026-10-27T14:00:00' ] )
		);
	}

	public function test_both_that_disagree_are_refused_with_what_was_expected(): void {
		$r = IATO_MCP_Post_Date::from_args( [ 'date' => '2026-10-27T09:00:00', 'date_gmt' => '2026-10-27T09:00:00' ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'date_mismatch', $r->get_error_code() );
		$this->assertSame( '2026-10-27 14:00:00', $r->get_error_data()['expected_date_gmt'] );
	}

	/** @return array<string,array{0:array}> */
	public static function bad_dates(): array {
		return [
			'not a date'             => [ [ 'date' => 'last tuesday' ] ],
			'date only'              => [ [ 'date' => '2026-10-27' ] ],
			'no seconds'             => [ [ 'date' => '2026-10-27T09:00' ] ],
			'empty'                  => [ [ 'date' => '' ] ],
			'a number'               => [ [ 'date' => 1793000000 ] ],
			'an array'               => [ [ 'date' => [ '2026-10-27T09:00:00' ] ] ],
			'no such day'            => [ [ 'date' => '2026-02-30T09:00:00' ] ],
			'no such hour'           => [ [ 'date' => '2026-10-27T24:00:00' ] ],
			'year zero'              => [ [ 'date' => '0000-00-00T00:00:00' ] ],
			'before MySQL can store' => [ [ 'date' => '0999-12-31T23:59:59' ] ],
			'an offset on local'     => [ [ 'date' => '2026-10-27T09:00:00-05:00' ] ],
			'Z on local'             => [ [ 'date' => '2026-10-27T09:00:00Z' ] ],
			'an offset on utc'       => [ [ 'date_gmt' => '2026-10-27T09:00:00-05:00' ] ],
			'trailing text'          => [ [ 'date' => "2026-10-27T09:00:00\n<x>" ] ],
		];
	}

	#[DataProvider( 'bad_dates' )]
	public function test_what_is_not_a_date_is_refused( array $args ): void {
		$r = IATO_MCP_Post_Date::from_args( $args );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'invalid_date', $r->get_error_code() );
		$this->assertArrayHasKey( 'field', $r->get_error_data() );
	}

	public function test_future_uses_the_same_minute_wordpress_does(): void {
		$this->assertTrue( IATO_MCP_Post_Date::is_future( self::future( 120 ) ) );
		$this->assertFalse( IATO_MCP_Post_Date::is_future( self::future( 30 ) ), 'WordPress publishes anything under a minute ahead' );
		$this->assertFalse( IATO_MCP_Post_Date::is_future( '2020-01-01 00:00:00' ) );
		$this->assertFalse( IATO_MCP_Post_Date::is_future( IATO_MCP_Post_Date::ZERO ), 'a draft with no date of its own' );
		$this->assertFalse( IATO_MCP_Post_Date::is_future( '' ) );
	}

	public function test_the_status_wordpress_will_store(): void {
		$soon = self::future();
		$past = '2020-01-01 00:00:00';
		$this->assertSame( 'future', IATO_MCP_Post_Date::resulting_status( 'publish', $soon, 'post' ) );
		$this->assertSame( 'publish', IATO_MCP_Post_Date::resulting_status( 'publish', $past, 'post' ) );
		$this->assertSame( 'publish', IATO_MCP_Post_Date::resulting_status( 'future', $past, 'post' ), 'a scheduled post whose date passed goes live' );
		$this->assertSame( 'future', IATO_MCP_Post_Date::resulting_status( 'future', $soon, 'post' ) );
		$this->assertSame( 'draft', IATO_MCP_Post_Date::resulting_status( 'draft', $soon, 'post' ) );
		$this->assertSame( 'draft', IATO_MCP_Post_Date::resulting_status( 'draft', $past, 'post' ) );
		$this->assertSame( 'private', IATO_MCP_Post_Date::resulting_status( 'private', $soon, 'post' ) );
		$this->assertSame( 'publish', IATO_MCP_Post_Date::resulting_status( 'publish', $soon, 'attachment' ), 'wp_insert_post() leaves attachments alone' );
	}

	public function test_a_draft_with_no_date_of_its_own_shows_none(): void {
		$this->assertNull( IATO_MCP_Post_Date::gmt_or_null( IATO_MCP_Post_Date::ZERO ) );
		$this->assertSame( '2026-10-27 14:00:00', IATO_MCP_Post_Date::gmt_or_null( '2026-10-27 14:00:00' ) );
	}

	public function test_the_note_only_when_the_date_changed_the_status(): void {
		$this->assertStringContainsString( '2030-01-01 09:00:00', (string) IATO_MCP_Post_Date::status_note( 'publish', 'future', '2030-01-01 09:00:00' ) );
		$this->assertStringContainsString( 'now published', (string) IATO_MCP_Post_Date::status_note( 'future', 'publish', '2020-01-01 09:00:00' ) );
		$this->assertNull( IATO_MCP_Post_Date::status_note( 'draft', 'publish', '2020-01-01 09:00:00' ) );
		$this->assertNull( IATO_MCP_Post_Date::status_note( 'publish', 'publish', '2020-01-01 09:00:00' ) );
		$this->assertNull( IATO_MCP_Post_Date::status_note( 'future', 'future', '2030-01-01 09:00:00' ) );
	}

	public function test_the_receipts_ask_for_what_their_rollback_does(): void {
		IATO_Test_Roles::$users = [ 1 => 'administrator' ];
		IATO_Test_Roles::$posts = [ 9 => [ 'post_type' => 'post', 'post_author' => 1, 'post_status' => 'publish' ] ];
		$req = IATO_MCP_Change_Receipt::object_cap_for( [ 'target_type' => 'post', 'field' => 'date', 'post_id' => 9, 'before_value' => '{"date":"x","date_gmt":"y","status":"publish"}' ] );
		$this->assertSame( 'edit_post', $req['cap'] );
		$this->assertSame( 'publish_posts', $req['also'][0]['cap'], 'it can put the post back on the site' );

		$req = IATO_MCP_Change_Receipt::object_cap_for( [ 'target_type' => 'post', 'field' => 'date', 'post_id' => 9, 'before_value' => '{"date":"x","date_gmt":"y","status":"draft"}' ] );
		$this->assertSame( [], $req['also'] );

		$req = IATO_MCP_Change_Receipt::object_cap_for( [ 'target_type' => 'post', 'field' => 'author', 'post_id' => 9, 'before_value' => '3' ] );
		$this->assertSame( 'edit_others_posts', $req['also'][0]['cap'] );
	}
}
