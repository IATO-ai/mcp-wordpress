<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * 1.21.1: update_elementor_patch follows RFC 6902 for lists. Until then add, copy
 * and move set the item at an index, so adding at an index replaced (deleted) the
 * element there, and removing a middle item left a gap that was stored as a JSON
 * object. Each op on a list item now reports the list as it is afterwards, on dry
 * runs too. Live: tests/wp-env/patch-ops-live.py.
 */
final class PatchOpsTest extends TestCase {

	private static function tree(): array {
		return [
			[ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'A', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>A</p>' ], 'elements' => [] ],
				[ 'id' => 'B', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>B</p>' ], 'elements' => [] ],
				[ 'id' => 'C', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>C</p>' ], 'elements' => [] ],
			] ],
		];
	}

	private const W = [ 'id' => 'W', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>W</p>' ], 'elements' => [] ];

	/** @return array{0:array,1:array|WP_Error} The tree after the ops, and what apply_rfc6902() returned. */
	private static function apply( array $ops ): array {
		$tree   = self::tree();
		$result = IATO_MCP_Elementor_Adapter::apply_rfc6902( $tree, $ops );
		return [ $tree, $result ];
	}

	private static function ids( array $tree ): array {
		return array_map( static fn( $e ) => $e['id'], $tree[0]['elements'] );
	}

	public function test_add_at_an_index_inserts_before_the_element_there(): void {
		[ $tree, $applied ] = self::apply( [ [ 'op' => 'add', 'path' => '/0/elements/1', 'value' => self::W ] ] );
		$this->assertSame( [ 'A', 'W', 'B', 'C' ], self::ids( $tree ), 'the field report: [A,B,C] + add at 1 => [A,W,B,C]' );
		$this->assertSame( [ 0, 1, 2, 3 ], array_keys( $tree[0]['elements'] ) );
		$this->assertSame(
			[ 'path' => '/0/elements', 'index' => 1, 'count_before' => 3, 'count_after' => 4, 'ids_after' => [ 'A', 'W', 'B', 'C' ] ],
			$applied[0]['list']
		);
	}

	public function test_add_at_the_start_the_end_and_with_a_dash(): void {
		$this->assertSame( [ 'W', 'A', 'B', 'C' ], self::ids( self::apply( [ [ 'op' => 'add', 'path' => '/0/elements/0', 'value' => self::W ] ] )[0] ) );
		$this->assertSame( [ 'A', 'B', 'C', 'W' ], self::ids( self::apply( [ [ 'op' => 'add', 'path' => '/0/elements/3', 'value' => self::W ] ] )[0] ), 'the length appends' );
		[ $tree, $applied ] = self::apply( [ [ 'op' => 'add', 'path' => '/0/elements/-', 'value' => self::W ] ] );
		$this->assertSame( [ 'A', 'B', 'C', 'W' ], self::ids( $tree ) );
		$this->assertSame( 3, $applied[0]['list']['index'], 'the index - stood for' );
		$this->assertSame( '/0/elements/-', $applied[0]['path'], 'the path stays as sent' );
	}

	public function test_an_index_past_the_end_or_with_a_leading_zero_is_refused(): void {
		$past = self::apply( [ [ 'op' => 'add', 'path' => '/0/elements/4', 'value' => self::W ] ] )[1];
		$this->assertInstanceOf( WP_Error::class, $past );
		$this->assertSame( 'invalid_patch_path', $past->get_error_code() );
		$this->assertStringContainsString( 'past the end of a list of 3', $past->get_error_message() );
		$zero = self::apply( [ [ 'op' => 'add', 'path' => '/0/elements/01', 'value' => self::W ] ] )[1];
		$this->assertInstanceOf( WP_Error::class, $zero );
		$this->assertSame( 'invalid_patch_path', $zero->get_error_code() );
	}

	public function test_add_to_an_object_sets_the_member_as_before(): void {
		[ $tree, $applied ] = self::apply( [
			[ 'op' => 'add', 'path' => '/0/settings/title', 'value' => 'x' ],
			[ 'op' => 'add', 'path' => '/0/elements/0/settings/html', 'value' => '<p>A2</p>' ],
		] );
		$this->assertSame( [ 'title' => 'x' ], $tree[0]['settings'] );
		$this->assertSame( '<p>A2</p>', $tree[0]['elements'][0]['settings']['html'], 'an existing member is replaced, as RFC 6902 says' );
		$this->assertArrayNotHasKey( 'list', $applied[0] );
		$this->assertSame( [ 'A', 'B', 'C' ], self::ids( $tree ) );
	}

	public function test_move_keeps_every_element(): void {
		[ $tree, $applied ] = self::apply( [ [ 'op' => 'move', 'from' => '/0/elements/2', 'path' => '/0/elements/0' ] ] );
		$this->assertSame( [ 'C', 'A', 'B' ], self::ids( $tree ), 'until 1.21.1: [C,B], A lost' );
		$this->assertSame( [ 'path' => '/0/elements', 'index' => 0, 'count_before' => 3, 'count_after' => 3, 'ids_after' => [ 'C', 'A', 'B' ] ], $applied[0]['list'] );
		$this->assertArrayNotHasKey( 'from_list', $applied[0], 'one list' );
		$this->assertSame( [ 'B', 'C', 'A' ], self::ids( self::apply( [ [ 'op' => 'move', 'from' => '/0/elements/0', 'path' => '/0/elements/2' ] ] )[0] ), 'the path is read after the removal' );
	}

	public function test_move_between_lists_reports_both(): void {
		$tree                               = self::tree();
		$tree[0]['elements'][2]['elType']   = 'container';
		$tree[0]['elements'][2]['elements'] = [ [ 'id' => 'X', 'elType' => 'widget', 'settings' => [], 'elements' => [] ] ];
		$applied                            = IATO_MCP_Elementor_Adapter::apply_rfc6902( $tree, [ [ 'op' => 'move', 'from' => '/0/elements/0', 'path' => '/0/elements/1/elements/0' ] ] );
		$this->assertSame( [ 'B', 'C' ], self::ids( $tree ), 'C became index 1, so the path names its children' );
		$this->assertSame( [ 'A', 'X' ], array_column( $tree[0]['elements'][1]['elements'], 'id' ) );
		$this->assertSame( [ 'path' => '/0/elements', 'index' => 0, 'count_before' => 3, 'count_after' => 2, 'ids_after' => [ 'B', 'C' ] ], $applied[0]['from_list'] );
		$this->assertSame( [ 'A', 'X' ], $applied[0]['list']['ids_after'] );
	}

	public function test_an_element_cannot_move_into_its_own_children(): void {
		$err = self::apply( [ [ 'op' => 'move', 'from' => '/0', 'path' => '/0/elements/0' ] ] )[1];
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_patch', $err->get_error_code() );
	}

	public function test_copy_to_an_index_inserts(): void {
		[ $tree, $applied ] = self::apply( [ [ 'op' => 'copy', 'from' => '/0/elements/2', 'path' => '/0/elements/1' ] ] );
		$this->assertSame( [ 'A', 'C', 'B', 'C' ], self::ids( $tree ), 'until 1.21.1: B was overwritten' );
		$this->assertSame( 4, $applied[0]['list']['count_after'] );
	}

	public function test_removing_a_middle_item_keeps_a_list(): void {
		[ $tree, $applied ] = self::apply( [
			[ 'op' => 'remove', 'path' => '/0/elements/1' ],
			[ 'op' => 'replace', 'path' => '/0/elements/1/settings/html', 'value' => '<p>C2</p>' ],
		] );
		$this->assertNotInstanceOf( WP_Error::class, $applied, 'the next op finds index 1' );
		$this->assertSame( [ 0, 1 ], array_keys( $tree[0]['elements'] ), 'until 1.21.1: keys 0 and 2, stored as a JSON object' );
		$this->assertSame( '[{"id":"A"},{"id":"C"}]', wp_json_encode( array_map( static fn( $e ) => [ 'id' => $e['id'] ], $tree[0]['elements'] ) ) );
		$this->assertSame( '<p>C2</p>', $tree[0]['elements'][1]['settings']['html'] );
		$this->assertSame( [ 'path' => '/0/elements', 'index' => 1, 'count_before' => 3, 'count_after' => 2, 'ids_after' => [ 'A', 'C' ] ], $applied[0]['list'] );
	}

	public function test_a_page_with_gaps_in_its_numbering_keeps_its_behaviour(): void {
		$tree = [ [ 'id' => 'g1', 'elType' => 'container', 'settings' => [], 'elements' => [ 0 => [ 'id' => 'A' ], 2 => [ 'id' => 'C' ] ] ] ];
		$out  = IATO_MCP_Elementor_Adapter::apply_rfc6902( $tree, [ [ 'op' => 'add', 'path' => '/0/elements/2', 'value' => [ 'id' => 'W' ] ] ] );
		$this->assertNotInstanceOf( WP_Error::class, $out );
		$this->assertSame( [ 0 => 'A', 2 => 'W' ], array_map( static fn( $e ) => $e['id'], $tree[0]['elements'] ), 'not a list: the key is set, as before' );
		$this->assertArrayNotHasKey( 'list', $out[0] );
	}

	public function test_repeater_rows_report_their_ids(): void {
		$tree = [ [ 'id' => 'w1', 'elType' => 'widget', 'settings' => [ 'tabs' => [ [ '_id' => 'r1' ], [ '_id' => 'r2' ] ] ], 'elements' => [] ] ];
		$out  = IATO_MCP_Elementor_Adapter::apply_rfc6902( $tree, [ [ 'op' => 'add', 'path' => '/0/settings/tabs/1', 'value' => [ '_id' => 'rN' ] ] ] );
		$this->assertSame( [ 'r1', 'rN', 'r2' ], $out[0]['list']['ids_after'] );
	}

	public function test_a_long_list_is_cut_at_a_hundred_ids(): void {
		$tree = [ [ 'id' => 'w1', 'elType' => 'widget', 'settings' => [ 'rows' => array_fill( 0, 120, 'x' ) ], 'elements' => [] ] ];
		$out  = IATO_MCP_Elementor_Adapter::apply_rfc6902( $tree, [ [ 'op' => 'add', 'path' => '/0/settings/rows/-', 'value' => 'y' ] ] );
		$this->assertCount( 100, $out[0]['list']['ids_after'] );
		$this->assertNull( $out[0]['list']['ids_after'][0], 'an item with no id' );
		$this->assertTrue( $out[0]['list']['ids_truncated'] );
		$this->assertSame( 121, $out[0]['list']['count_after'] );
	}

	public function test_replace_at_an_index_still_replaces(): void {
		[ $tree ] = self::apply( [ [ 'op' => 'replace', 'path' => '/0/elements/1', 'value' => self::W ] ] );
		$this->assertSame( [ 'A', 'W', 'C' ], self::ids( $tree ) );
	}

	public function test_the_internal_location_never_reaches_a_response(): void {
		[ $tree, $applied ] = self::apply( [ [ 'op' => 'add', 'path' => '/0/elements/1', 'value' => self::W ] ] );
		$this->assertArrayHasKey( '_stored_at', $applied[0], 'apply notes where to read it back' );
		$this->assertArrayNotHasKey( '_resolved_path', $applied[0] );
		$annotated = IATO_MCP_Elementor_Adapter::annotate_stored( $applied, $tree );
		$this->assertArrayNotHasKey( '_stored_at', $annotated[0] );
		$this->assertArrayNotHasKey( '_stored_at', IATO_MCP_Elementor_Adapter::annotate_stored( $applied, null )[0], 'removed even with no tree' );
	}
}
