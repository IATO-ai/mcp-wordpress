<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * 1.20.0: the redirects a moved URL gets, and their removal by rollback. The rules
 * here; the plugin's built-in list for real (an option), the plugins through a fake
 * store. tests/wp-env/restructure-live.py checks Redirection and Safe Redirect Manager.
 */
final class RedirectBatchTest extends TestCase {

	private const HOST = 'example.test';

	private array $options = [];

	protected function setUp(): void {
		$this->options = IATO_Test_Abilities_State::$options;
		unset( IATO_Test_Abilities_State::$options['iato_mcp_redirects'] );
		IATO_MCP_Redirects::set_store( null );
	}

	protected function tearDown(): void {
		IATO_MCP_Redirects::set_store( null );
		IATO_Test_Abilities_State::$options = $this->options;
	}

	private static function change( string $old, string $new, string $status = 'publish' ): array {
		return [ 'old_url' => 'https://example.test' . $old, 'new_url' => 'https://example.test' . $new, 'status' => $status ];
	}

	private static function stored(): array {
		return get_option( 'iato_mcp_redirects', [] );
	}

	public function test_a_path_is_on_this_site_and_has_no_query(): void {
		$this->assertSame( '/a/b/', IATO_MCP_Redirects::path_of( 'https://example.test/a/b/', self::HOST ) );
		$this->assertSame( '/wp/a/', IATO_MCP_Redirects::path_of( 'https://EXAMPLE.test/wp/a/', self::HOST ), 'the site\'s own folder stays, as the built-in handler compares it' );
		$this->assertSame( '/', IATO_MCP_Redirects::path_of( 'https://example.test', self::HOST ) );
		$this->assertNull( IATO_MCP_Redirects::path_of( 'https://other.test/a/', self::HOST ) );
		$this->assertNull( IATO_MCP_Redirects::path_of( 'https://example.test/?p=12', self::HOST ), 'a post with no pretty URL' );
		$this->assertNull( IATO_MCP_Redirects::path_of( '', self::HOST ) );
	}

	public function test_only_published_moves_that_change_a_path_are_planned(): void {
		$plan = IATO_MCP_Redirects::plan(
			[
				self::change( '/a/', '/s/a/' ),
				self::change( '/b/', '/s/b/', 'draft' ),
				self::change( '/c/', '/s/c/', 'private' ),
				self::change( '/d/', '/d/' ),
				self::change( '/a/', '/s/a-again/' ),
				[ 'old_url' => 'https://other.test/e/', 'new_url' => 'https://example.test/s/e/', 'status' => 'publish' ],
			],
			self::HOST,
			static fn( string $p ): bool => false
		);
		$this->assertSame( [ [ 'from' => '/a/', 'to' => '/s/a/' ] ], $plan['planned'] );
		$this->assertSame( [ 'not_a_path_on_this_site' ], array_column( $plan['skipped'], 'reason' ) );
	}

	public function test_no_redirect_starts_at_a_live_url(): void {
		$plan = IATO_MCP_Redirects::plan( [ self::change( '/a/', '/s/a/' ), self::change( '/b/', '/s/b/' ) ], self::HOST, static fn( string $p ): bool => '/b/' === $p );
		$this->assertSame( [ '/a/' ], array_column( $plan['planned'], 'from' ) );
		$this->assertSame( [ [ 'from' => '/b/', 'to' => '/s/b/', 'reason' => 'would_shadow_live_url' ] ], $plan['skipped'] );
	}

	public function test_a_path_the_handler_already_redirects_is_left_alone(): void {
		update_option( 'iato_mcp_redirects', [ [ 'from_url' => '/a', 'to_url' => '/elsewhere/', 'type' => 302 ] ] );
		$kept = IATO_MCP_Redirects::without_existing( [ [ 'from' => '/a/', 'to' => '/s/a/' ], [ 'from' => '/b/', 'to' => '/s/b/' ] ], 'fallback' );
		$this->assertSame( [ [ 'from' => '/b/', 'to' => '/s/b/' ] ], $kept['planned'] );
		$this->assertSame( 'existing_rule_kept', $kept['skipped'][0]['reason'] );
		$this->assertSame( '/elsewhere/', $kept['skipped'][0]['existing_to'] );
	}

	public function test_more_than_a_thousand_is_none(): void {
		$this->assertNull( IATO_MCP_Redirects::no_room( 'redirection', 1000 ) );
		$this->assertStringContainsString( 'none were created', (string) IATO_MCP_Redirects::no_room( 'redirection', 1001 ) );
	}

	public function test_the_built_in_list_holds_five_hundred(): void {
		update_option( 'iato_mcp_redirects', array_fill( 0, 497, [ 'from_url' => '/x/', 'to_url' => '/y/', 'type' => 301 ] ) );
		$this->assertNull( IATO_MCP_Redirects::no_room( 'fallback', 3 ) );
		$note = IATO_MCP_Redirects::no_room( 'fallback', 4 );
		$this->assertStringContainsString( 'at most 500', (string) $note );
		$this->assertStringContainsString( 'has 497', (string) $note );
	}

	public function test_the_built_in_list_gets_them_all_in_one_write(): void {
		update_option( 'iato_mcp_redirects', [ [ 'from_url' => '/old/', 'to_url' => '/keep/', 'type' => 302 ] ] );
		$made = IATO_MCP_Redirects::create_all( [ [ 'from' => '/a/', 'to' => '/s/a/' ], [ 'from' => '/b/', 'to' => '/s/b/' ] ], 'fallback' );
		$this->assertSame( [], $made['failed'] );
		$this->assertSame( [ 'fallback', 'fallback' ], array_column( $made['created'], 'handler' ) );
		$this->assertSame( [ 301, 301 ], array_column( $made['created'], 'type' ) );
		$this->assertSame(
			[
				[ 'from_url' => '/old/', 'to_url' => '/keep/', 'type' => 302 ],
				[ 'from_url' => '/a/', 'to_url' => '/s/a/', 'type' => 301 ],
				[ 'from_url' => '/b/', 'to_url' => '/s/b/', 'type' => 301 ],
			],
			self::stored()
		);
	}

	public function test_removal_takes_only_what_still_points_where_it_was_set(): void {
		update_option(
			'iato_mcp_redirects',
			[
				[ 'from_url' => '/old/', 'to_url' => '/keep/', 'type' => 302 ],
				[ 'from_url' => '/a/', 'to_url' => '/s/a/', 'type' => 301 ],
				[ 'from_url' => '/b/', 'to_url' => '/changed-since/', 'type' => 301 ],
			]
		);
		$rules  = [
			[ 'handler' => 'fallback', 'from' => '/a/', 'to' => '/s/a/' ],
			[ 'handler' => 'fallback', 'from' => '/b/', 'to' => '/s/b/' ],
			[ 'handler' => 'fallback', 'from' => '/c/', 'to' => '/s/c/' ],
		];
		$report = IATO_MCP_Redirects::remove_all( $rules );
		$this->assertSame( 1, $report['removed'] );
		$this->assertSame( 1, $report['gone'], 'one that is already gone counts as removed' );
		$this->assertSame( [ '/b/' ], array_column( $report['kept'], 'from' ) );
		$this->assertSame( [], $report['failed'] );
		$this->assertSame( [ '/old/', '/b/' ], array_column( self::stored(), 'from_url' ) );

		$again = IATO_MCP_Redirects::remove_all( $rules );
		$this->assertSame( [ 0, 2, [] ], [ $again['removed'], $again['gone'], $again['failed'] ], 'a rollback that stopped can run again' );
	}

	public function test_a_plugin_that_is_off_cannot_be_reached_and_that_is_no_failure(): void {
		$report = IATO_MCP_Redirects::remove_all( [ [ 'handler' => 'redirection', 'from' => '/a/', 'to' => '/s/a/' ] ] );
		$this->assertSame( [], $report['failed'] );
		$this->assertSame( [ 'redirection' ], array_column( $report['unreachable'], 'handler' ) );
		$this->assertStringContainsString( 'not active', (string) IATO_MCP_Redirects::removal_note( $report ) );
	}

	public function test_a_store_failure_is_reported_as_failed(): void {
		IATO_MCP_Redirects::set_store(
			new class() extends IATO_MCP_Redirect_Store {
				public function reachable( string $handler ): bool {
					return true;
				}
				public function remove( string $handler, array $rules ): array {
					return [ 'removed' => 0, 'gone' => 0, 'kept' => [], 'failed' => [ $rules[0] + [ 'handler' => $handler, 'error' => 'refused' ] ] ];
				}
			}
		);
		$report = IATO_MCP_Redirects::remove_all( [ [ 'handler' => 'safe_redirect_manager', 'from' => '/a/', 'to' => '/s/a/' ], 'not a rule' ] );
		$this->assertCount( 1, $report['failed'] );
	}

	public function test_the_report_lists_a_hundred_and_counts_any_number(): void {
		$rules = array_map( static fn( int $i ): array => [ 'from' => "/{$i}/", 'to' => "/s/{$i}/", 'type' => 301 ], range( 1, 100 ) );
		$short = IATO_MCP_Redirects::report( $rules, 'wr_1' );
		$this->assertSame( 100, $short['redirects_created_count'] );
		$this->assertSame( [ 'from' => '/1/', 'to' => '/s/1/', 'type' => 301, 'rollback_id' => 'wr_1' ], $short['redirects_created'][0] );

		$rules[] = [ 'from' => '/101/', 'to' => '/s/101/', 'type' => 301 ];
		$long    = IATO_MCP_Redirects::report( $rules, 'wr_1' );
		$this->assertSame( [ 'redirects_created_count' => 101 ], $long );

		$this->assertSame( [ 'redirects_planned_count' => 0, 'redirects_planned' => [] ], IATO_MCP_Redirects::report( [], null, 'redirects_planned' ) );
	}

	public function test_a_rule_changed_since_is_named_in_the_note(): void {
		$note = IATO_MCP_Redirects::removal_note( [ 'removed' => 1, 'gone' => 0, 'kept' => [ [ 'from' => '/b/' ] ], 'unreachable' => [], 'failed' => [] ] );
		$this->assertStringContainsString( '/b/', (string) $note );
		$this->assertNull( IATO_MCP_Redirects::removal_note( [ 'removed' => 2, 'gone' => 0, 'kept' => [], 'unreachable' => [], 'failed' => [] ] ) );
	}

	public function test_a_percent_encoded_path_is_not_redirected(): void {
		$plan = IATO_MCP_Redirects::plan( [ self::change( '/%e6%97%a5/', '/s/%e6%97%a5/' ) ], self::HOST, static fn( string $p ): bool => false );
		$this->assertSame( [], $plan['planned'], 'the built-in list and Safe Redirect Manager delete %XX from the request before comparing' );
		$this->assertSame( 'encoded_path', $plan['skipped'][0]['reason'] );
	}

	public function test_a_target_is_read_as_a_path_on_this_site(): void {
		$this->assertSame( '/a/', IATO_MCP_Redirects::target_path( 'https://example.test/a/', self::HOST ) );
		$this->assertSame( '/a/', IATO_MCP_Redirects::target_path( '/a/?x=1', self::HOST ) );
		$this->assertSame( '/a/', IATO_MCP_Redirects::target_path( 'a/', self::HOST ) );
		$this->assertNull( IATO_MCP_Redirects::target_path( 'https://other.test/a/', self::HOST ) );
		$this->assertNull( IATO_MCP_Redirects::target_path( '//other.test/a/', self::HOST ) );
	}

	public function test_a_rule_left_by_an_earlier_move_on_a_new_url_is_stale_and_any_other_blocks(): void {
		update_option(
			'iato_mcp_redirects',
			[
				[ 'from_url' => '/p/', 'to_url' => '/x/p/', 'type' => 301 ],        // the page moving back: points to its old URL
				[ 'from_url' => '/q/', 'to_url' => '/elsewhere/', 'type' => 301 ],  // someone's rule on the new URL
				[ 'from_url' => '/r/', 'to_url' => '/x/r/', 'type' => 301 ],        // a draft's new URL: not live
			]
		);
		$found = IATO_MCP_Redirects::rules_on_new_urls(
			[ self::change( '/x/p/', '/p/' ), self::change( '/x/q/', '/q/' ), self::change( '/x/r/', '/r/', 'draft' ), self::change( '/x/s/', '/s/' ) ],
			self::HOST
		);
		$this->assertSame( [ [ 'handler' => 'fallback', 'from' => '/p/', 'to' => '/x/p/', 'type' => 301 ] ], $found['stale'] );
		$this->assertSame( [ [ 'handler' => 'fallback', 'from' => '/q/', 'to' => '/elsewhere/' ] ], $found['blocking'] );
		$refusal = IATO_MCP_Redirects::blocking_refusal( $found['blocking'] );
		$this->assertSame( 'new_url_redirected', $refusal->get_error_code() );
		$this->assertStringContainsString( '/q/ -> /elsewhere/', $refusal->get_error_message() );
	}

	public function test_a_stale_rule_is_removed_and_a_rollback_puts_it_back_once(): void {
		update_option( 'iato_mcp_redirects', [ [ 'from_url' => '/p/', 'to_url' => '/x/p/', 'type' => 301 ] ] );
		$stale   = [ [ 'handler' => 'fallback', 'from' => '/p/', 'to' => '/x/p/', 'type' => 301 ] ];
		$removed = IATO_MCP_Redirects::remove_stale( $stale );
		$this->assertSame( $stale, $removed );
		$this->assertSame( [], self::stored() );
		$this->assertNull( IATO_MCP_Redirects::restore_removed( $removed ) );
		$this->assertSame( [ [ 'from_url' => '/p/', 'to_url' => '/x/p/', 'type' => 301 ] ], self::stored() );
		$this->assertNull( IATO_MCP_Redirects::restore_removed( $removed ) );
		$this->assertCount( 1, self::stored(), 'a path that has a rule again is left alone' );
	}

	public function test_a_stale_rule_someone_changed_is_not_removed(): void {
		update_option( 'iato_mcp_redirects', [ [ 'from_url' => '/p/', 'to_url' => '/changed/', 'type' => 301 ] ] );
		$this->assertSame( [], IATO_MCP_Redirects::remove_stale( [ [ 'handler' => 'fallback', 'from' => '/p/', 'to' => '/x/p/', 'type' => 301 ] ] ) );
		$this->assertCount( 1, self::stored() );
	}
}
