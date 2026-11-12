<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * 1.21.0: Elementor write responses say what was stored, and `return: "minimal"`
 * keeps a large widget's response small. The read-back after a real write runs on a
 * live site: tests/wp-env/page-assets-live.py.
 */
final class ResponseShapeTest extends TestCase {

	private const TREE = [
		[ 'id' => 's1', 'elType' => 'container', 'settings' => [], 'elements' => [
			[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>clean</p>', 'list' => [ 1, 2 ] ], 'elements' => [] ],
		] ],
	];

	public function test_a_value_stored_as_sent_says_so(): void {
		$ops = [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/html', 'value' => '<p>clean</p>', 'previous_value' => 'x' ] ];
		$out = IATO_MCP_Elementor_Adapter::annotate_stored( $ops, self::TREE );
		$this->assertTrue( $out[0]['stored_matches_input'] );
		$this->assertArrayNotHasKey( 'stored_sha256', $out[0] );
	}

	public function test_a_value_stored_differently_says_what_was_stored(): void {
		$ops = [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/html', 'value' => '<p>clean</p><script>x</script>', 'previous_value' => 'x' ] ];
		$out = IATO_MCP_Elementor_Adapter::annotate_stored( $ops, self::TREE );
		$this->assertFalse( $out[0]['stored_matches_input'] );
		$this->assertSame( '<p>clean</p>', $out[0]['stored_value'] );
		$this->assertSame( strlen( '<p>clean</p>' ), $out[0]['stored_bytes'] );
		$this->assertSame( hash( 'sha256', '<p>clean</p>' ), $out[0]['stored_sha256'], 'the hash of the string get_elementor_widget returns' );
	}

	public function test_arrays_compare_as_json_and_hash_their_json(): void {
		$ops = [ [ 'op' => 'add', 'path' => '/0/elements/0/settings/list', 'value' => [ 1, 2 ] ] ];
		$this->assertTrue( IATO_MCP_Elementor_Adapter::annotate_stored( $ops, self::TREE )[0]['stored_matches_input'] );
		$this->assertSame( [ 'bytes' => 5, 'sha256' => hash( 'sha256', '[1,2]' ) ], IATO_MCP_Elementor_Adapter::value_digest( [ 1, 2 ] ) );
	}

	public function test_removals_and_unreadable_paths_are_left_alone(): void {
		$ops = [
			[ 'op' => 'remove', 'path' => '/0/elements/0/settings/x', 'previous_value' => 'y' ],
			[ 'op' => 'add', 'path' => '/0/elements/9/settings/x', 'value' => 'y' ],
		];
		$this->assertSame( $ops, IATO_MCP_Elementor_Adapter::annotate_stored( $ops, self::TREE ) );
		$this->assertSame( $ops, IATO_MCP_Elementor_Adapter::annotate_stored( $ops, null ) );
	}

	/** 1.21.1: Elementor's save writes an element's keys in its own order. */
	public function test_key_order_is_not_a_difference(): void {
		$sent   = [ 'id' => 'W', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>w</p>', 'a' => 1 ] ];
		$stored = [ 'settings' => [ 'a' => 1, 'html' => '<p>w</p>' ], 'elType' => 'widget', 'id' => 'W', 'widgetType' => 'html' ];
		$tree   = [ [ 'id' => 's1', 'elType' => 'container', 'settings' => [], 'elements' => [ $stored ] ] ];
		$out    = IATO_MCP_Elementor_Adapter::annotate_stored( [ [ 'op' => 'add', 'path' => '/0/elements/0', 'value' => $sent ] ], $tree );
		$this->assertTrue( $out[0]['stored_matches_input'] );
		$lists = [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/list', 'value' => [ 2, 1 ] ] ];
		$this->assertFalse( IATO_MCP_Elementor_Adapter::annotate_stored( $lists, self::TREE )[0]['stored_matches_input'], 'a list keeps its order' );
	}

	/** 1.21.1: a `-` path is read back where the value went, and so is one a later op moved. */
	public function test_ops_are_read_back_by_element_id(): void {
		$w    = [ 'id' => 'w9', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>new</p>' ], 'elements' => [] ];
		$tree = self::TREE;
		$ops  = IATO_MCP_Elementor_Adapter::apply_rfc6902( $tree, [
			[ 'op' => 'add', 'path' => '/0/elements/-', 'value' => $w ],
			[ 'op' => 'replace', 'path' => '/0/elements/1/settings/html', 'value' => '<p>edited</p>' ],
			[ 'op' => 'add', 'path' => '/0/elements/0', 'value' => [ 'id' => 'w0', 'elType' => 'widget', 'settings' => [], 'elements' => [] ] ],
		] );
		$stored = $tree;
		$stored[0]['elements'][2]['settings']['html'] = '<p>edited</p><b>x</b>'; // w9, now at index 2
		$out = IATO_MCP_Elementor_Adapter::annotate_stored( $ops, $stored );
		$this->assertFalse( $out[0]['stored_matches_input'], 'the - op is read back' );
		$this->assertFalse( $out[1]['stored_matches_input'], 'w9 at its new index, not w1, which now sits at index 1' );
		$this->assertSame( '<p>edited</p><b>x</b>', $out[1]['stored_value'] );
		$this->assertTrue( $out[2]['stored_matches_input'] );
	}

	public function test_keys_the_save_added_are_named(): void {
		$sent = [ 'id' => 'W', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>w</p>' ] ];
		$tree = [ [ 'id' => 's1', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
		$ops  = IATO_MCP_Elementor_Adapter::apply_rfc6902( $tree, [ [ 'op' => 'add', 'path' => '/0/elements/-', 'value' => $sent ] ] );
		$tree[0]['elements'][0] += [ 'isInner' => false, 'elements' => [] ];
		$out  = IATO_MCP_Elementor_Adapter::annotate_stored( $ops, $tree );
		$this->assertFalse( $out[0]['stored_matches_input'] );
		$this->assertSame( [ 'keys_added' ], $out[0]['stored_difference'] );
		$this->assertSame( [ '/0/elements/0/isInner', '/0/elements/0/elements' ], $out[0]['added_paths'], 'where it was stored, not /-' );
	}

	/** Elementor 4.3.4's save drops `isInner` from a widget (seen live). */
	public function test_keys_the_save_dropped_are_named(): void {
		$sent = [ 'id' => 'W', 'elType' => 'widget', 'widgetType' => 'html', 'isInner' => false, 'settings' => [ 'html' => '<p>w</p>' ], 'elements' => [] ];
		$tree = [ [ 'id' => 's1', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
		$ops  = IATO_MCP_Elementor_Adapter::apply_rfc6902( $tree, [ [ 'op' => 'add', 'path' => '/0/elements/0', 'value' => $sent ] ] );
		unset( $tree[0]['elements'][0]['isInner'] );
		$tree[0]['elements'][0]['settings']['html'] = '<p>w2</p>';
		$out = IATO_MCP_Elementor_Adapter::annotate_stored( $ops, $tree );
		$this->assertSame( [ 'changed', 'keys_removed' ], $out[0]['stored_difference'] );
		$this->assertSame( [ '/0/elements/0/isInner' ], $out[0]['removed_paths'] );
		$this->assertArrayNotHasKey( 'added_paths', $out[0] );
	}

	public function test_a_string_that_differs_is_changed_without_wordpress_s_tokenizer(): void {
		// The tokenizer path (markup_normalized) runs live: PHPUnit has no WordPress.
		$this->assertNull( IATO_MCP_Sanitize_Report::same_markup( "<p class='a'>x</p>", '<p class="a">x</p>' ) );
		$ops = [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/html', 'value' => "<p class='clean'>x</p>" ] ];
		$out = IATO_MCP_Elementor_Adapter::annotate_stored( $ops, self::TREE );
		$this->assertSame( [ 'changed' ], $out[0]['stored_difference'] );
		$this->assertArrayNotHasKey( 'added_paths', $out[0] );
	}

	public function test_minimal_keeps_the_difference_and_the_list(): void {
		$response = [ 'applied_patch' => [ [ 'op' => 'add', 'path' => '/x', 'value' => 'v', 'stored_matches_input' => false, 'stored_value' => 'w', 'stored_difference' => [ 'changed' ], 'list' => [ 'count_after' => 1 ] ] ] ];
		$op       = IATO_MCP_Elementor_Adapter::shape_response( $response, 'minimal' )['applied_patch'][0];
		$this->assertSame( [ 'changed' ], $op['stored_difference'] );
		$this->assertSame( [ 'count_after' => 1 ], $op['list'] );
		$this->assertArrayNotHasKey( 'stored_value', $op );
	}

	public function test_atomic_ops_compare_with_what_the_patch_sent(): void {
		$envelope = [ '$$type' => 'string', 'value' => 'h3' ];
		$ops      = [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag', 'value' => $envelope ] ];
		$plain    = IATO_MCP_Elementor_Adapter::annotate_stored_atomic( $ops, [ 'tag' => 'h3' ] );
		$this->assertFalse( $plain[0]['stored_matches_input'], 'wrapped in its envelope' );
		$this->assertArrayNotHasKey( 'stored_value', $plain[0], 'value already is what was stored' );
		$typed = IATO_MCP_Elementor_Adapter::annotate_stored_atomic( $ops, [ 'tag' => $envelope ] );
		$this->assertTrue( $typed[0]['stored_matches_input'] );
	}

	public function test_minimal_turns_values_into_sizes_and_hashes(): void {
		$big      = str_repeat( 'a', 20000 );
		$response = [
			'post_id'          => 5,
			'applied_patch'    => [ [ 'op' => 'replace', 'path' => '/0/x', 'value' => $big, 'previous_value' => $big . 'b', 'stored_matches_input' => false, 'stored_bytes' => 3, 'stored_sha256' => 'h', 'stored_value' => $big ] ],
			'sanitized_report' => [ [ 'path' => 'w1/settings/html', 'removed_tags' => [ 'script' ] ] ],
			'change_receipt'   => [ 'change_id' => 'wr_1' ],
		];
		$out = IATO_MCP_Elementor_Adapter::shape_response( $response, 'minimal' );
		$op  = $out['applied_patch'][0];
		$this->assertSame( [ 'op', 'path', 'stored_matches_input', 'stored_bytes', 'stored_sha256', 'value_bytes', 'value_sha256', 'previous_bytes', 'previous_sha256' ], array_keys( $op ) );
		$this->assertSame( 20000, $op['value_bytes'] );
		$this->assertSame( hash( 'sha256', $big . 'b' ), $op['previous_sha256'] );
		$this->assertSame( $response['sanitized_report'], $out['sanitized_report'] );
		$this->assertSame( $response['change_receipt'], $out['change_receipt'] );
		$this->assertLessThan( 2048, strlen( (string) wp_json_encode( $out ) ), 'a 20 KB widget update under 2 KB' );
	}

	public function test_full_changes_nothing(): void {
		$response = [ 'applied_patch' => [ [ 'op' => 'add', 'path' => '/x', 'value' => 'v' ] ] ];
		$this->assertSame( $response, IATO_MCP_Elementor_Adapter::shape_response( $response, 'full' ) );
	}

	public function test_minimal_on_dry_run_extras(): void {
		$out = IATO_MCP_Elementor_Adapter::shape_response(
			[
				'would_store'     => [ 'tag' => [ '$$type' => 'string', 'value' => 'h3' ] ],
				'inherit_planned' => [ [ 'key' => 'site-post-title', 'before' => '', 'after' => 'disabled' ] ],
			],
			'minimal'
		);
		$this->assertSame( [ 'bytes', 'sha256' ], array_keys( $out['would_store']['tag'] ) );
		$this->assertSame( [ 'bytes' => 8, 'sha256' => hash( 'sha256', 'disabled' ) ], $out['inherit_planned'][0]['after'] );
		$this->assertSame( 'site-post-title', $out['inherit_planned'][0]['key'] );
	}

	public function test_the_return_argument(): void {
		$this->assertSame( 'full', IATO_MCP_Elementor_Adapter::return_mode( [] ) );
		$this->assertSame( 'minimal', IATO_MCP_Elementor_Adapter::return_mode( [ 'return' => 'minimal' ] ) );
		$this->assertSame( 'invalid_return', IATO_MCP_Elementor_Adapter::return_mode( [ 'return' => 'tiny' ] )->get_error_code() );
	}
}
