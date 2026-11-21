<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 1.22.0: update_post_content_patch's rules (IATO_MCP_Content_Patch): the
 * operations, exactly-once matching, all or nothing, and the spans the response
 * reports instead of the content. What the tool does on real WordPress (dry run,
 * rollback, sanitising, the builder refusals) is covered by
 * tests/wp-env/content-patch-live.py.
 */
final class ContentPatchTest extends TestCase {

	private const ARTICLE = '<p>The quick brown fox jumps over the lazy dog.</p>' . "\n\n" . '<p>Foxes are quick. Dogs are lazy.</p>';

	/** @return array{content:string,spans:list<array>} */
	private static function patch( string $content, array $ops ): array {
		$checked = IATO_MCP_Content_Patch::validate_ops( $ops );
		self::assertIsArray( $checked, is_wp_error( $checked ) ? $checked->get_error_message() : '' );
		$result = IATO_MCP_Content_Patch::apply( $content, $checked );
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	private static function refusal( string $content, array $ops ): WP_Error {
		$checked = IATO_MCP_Content_Patch::validate_ops( $ops );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$result = IATO_MCP_Content_Patch::apply( $content, $checked );
		self::assertInstanceOf( WP_Error::class, $result );
		return $result;
	}

	private static function is_utf8( string $text ): bool {
		return 1 === preg_match( '//u', $text );
	}

	// ── The operations ──────────────────────────────────────────────────────

	/** @return array<string,array{0:array,1:string}> */
	public static function one_op(): array {
		return [
			'replace'       => [ [ 'op' => 'replace', 'find' => 'lazy dog', 'replace' => 'sleepy cat' ], '<p>The quick brown fox jumps over the sleepy cat.</p>' . "\n\n" . '<p>Foxes are quick. Dogs are lazy.</p>' ],
			'replace with nothing deletes' => [ [ 'op' => 'replace', 'find' => ' Dogs are lazy.', 'replace' => '' ], '<p>The quick brown fox jumps over the lazy dog.</p>' . "\n\n" . '<p>Foxes are quick.</p>' ],
			'insert_before' => [ [ 'op' => 'insert_before', 'anchor' => '<p>Foxes', 'html' => "<h2>About foxes</h2>\n" ], '<p>The quick brown fox jumps over the lazy dog.</p>' . "\n\n" . "<h2>About foxes</h2>\n<p>Foxes are quick. Dogs are lazy.</p>" ],
			'insert_after'  => [ [ 'op' => 'insert_after', 'anchor' => 'lazy dog.</p>', 'html' => '<p>New.</p>' ], '<p>The quick brown fox jumps over the lazy dog.</p><p>New.</p>' . "\n\n" . '<p>Foxes are quick. Dogs are lazy.</p>' ],
			'append'        => [ [ 'op' => 'append', 'html' => '<p>The end.</p>' ], self::ARTICLE . '<p>The end.</p>' ],
		];
	}

	#[DataProvider( 'one_op' )]
	public function test_each_operation_on_its_own( array $op, string $expected ): void {
		$r = self::patch( self::ARTICLE, [ $op ] );
		$this->assertSame( $expected, $r['content'] );
		$this->assertCount( 1, $r['spans'] );
		$this->assertSame( [ 0 ], $r['spans'][0]['ops'] );
	}

	public function test_a_replace_reports_what_it_removed_and_inserted_where(): void {
		$span = self::patch( self::ARTICLE, [ [ 'op' => 'replace', 'find' => 'lazy dog', 'replace' => 'sleepy cat' ] ] )['spans'][0];
		$at   = strpos( self::ARTICLE, 'lazy dog' );
		$this->assertSame( $at, $span['offset_before'] );
		$this->assertSame( $at, $span['offset_after'] );
		$this->assertSame( 'lazy dog', $span['removed'] );
		$this->assertSame( 'sleepy cat', $span['inserted'] );
		$this->assertSame( 8, $span['removed_bytes'] );
		$this->assertSame( 10, $span['inserted_bytes'] );
		$this->assertSame( '<p>The quick brown fox jumps over the ', $span['context_before'], 'the whole start, shorter than 80 characters' );
		$this->assertSame( '.</p>' . "\n\n" . '<p>Foxes are quick. Dogs are lazy.</p>', $span['context_after'] );
	}

	public function test_a_delete_inserts_nothing(): void {
		$span = self::patch( self::ARTICLE, [ [ 'op' => 'replace', 'find' => ' Dogs are lazy.', 'replace' => '' ] ] )['spans'][0];
		$this->assertSame( '', $span['inserted'] );
		$this->assertSame( 0, $span['inserted_bytes'] );
		$this->assertSame( ' Dogs are lazy.', $span['removed'] );
	}

	public function test_append_works_on_empty_content_and_adds_no_line_break(): void {
		$r = self::patch( '', [ [ 'op' => 'append', 'html' => '<p>First.</p>' ], [ 'op' => 'append', 'html' => '<p>Second.</p>' ] ] );
		$this->assertSame( '<p>First.</p><p>Second.</p>', $r['content'] );
		$this->assertCount( 1, $r['spans'], 'two appends touch, so they are one span' );
		$this->assertSame( [ 0, 1 ], $r['spans'][0]['ops'] );
	}

	public function test_operations_apply_in_order_and_see_what_earlier_ones_did(): void {
		$r = self::patch( self::ARTICLE, [
			[ 'op' => 'replace', 'find' => 'lazy dog', 'replace' => 'sleepy cat' ],
			[ 'op' => 'insert_after', 'anchor' => 'sleepy cat', 'html' => ' (a tabby)' ],
		] );
		$this->assertStringContainsString( 'over the sleepy cat (a tabby).</p>', $r['content'] );
		$this->assertCount( 1, $r['spans'] );
		$this->assertSame( [ 0, 1 ], $r['spans'][0]['ops'] );
		$this->assertSame( 'lazy dog', $r['spans'][0]['removed'] );
		$this->assertSame( 'sleepy cat (a tabby)', $r['spans'][0]['inserted'] );
	}

	public function test_an_earlier_operation_can_make_a_later_find_ambiguous(): void {
		$e = self::refusal( self::ARTICLE, [
			[ 'op' => 'append', 'html' => '<p>The lazy dog again.</p>' ],
			[ 'op' => 'replace', 'find' => 'lazy dog', 'replace' => 'cat' ],
		] );
		$this->assertSame( 'patch_multiple_matches', $e->get_error_code() );
		$this->assertSame( 1, $e->get_error_data()['op_index'] );
	}

	// ── Matching exactly once ───────────────────────────────────────────────

	public function test_no_match_is_refused_with_the_operation_and_count(): void {
		$e = self::refusal( self::ARTICLE, [
			[ 'op' => 'replace', 'find' => 'quick brown', 'replace' => 'slow brown' ],
			[ 'op' => 'insert_before', 'anchor' => 'a wolf', 'html' => 'x' ],
		] );
		$this->assertSame( 'patch_no_match', $e->get_error_code() );
		$data = $e->get_error_data();
		$this->assertSame( 1, $data['op_index'], 'the first op matched; the second did not' );
		$this->assertSame( 'insert_before', $data['op'] );
		$this->assertSame( 'anchor', $data['field'] );
		$this->assertSame( 0, $data['matches'] );
		$this->assertStringContainsString( 'Nothing was written', $e->get_error_message() );
		$this->assertArrayNotHasKey( 'hint', $data );
	}

	public function test_several_matches_are_refused_with_the_count_and_where(): void {
		$e = self::refusal( self::ARTICLE, [ [ 'op' => 'replace', 'find' => 'quick', 'replace' => 'fast' ] ] );
		$this->assertSame( 'patch_multiple_matches', $e->get_error_code() );
		$data = $e->get_error_data();
		$this->assertSame( 2, $data['matches'] );
		$this->assertSame( [ strpos( self::ARTICLE, 'quick' ), strrpos( self::ARTICLE, 'quick' ) ], array_column( $data['match_contexts'], 'offset' ) );
		$this->assertSame( '<p>The ', $data['match_contexts'][0]['context_before'] );
		$this->assertStringStartsWith( ' brown fox', $data['match_contexts'][0]['context_after'] );
	}

	public function test_at_most_five_matches_are_shown(): void {
		$e    = self::refusal( str_repeat( '<p>row</p>', 7 ), [ [ 'op' => 'insert_after', 'anchor' => '<p>row</p>', 'html' => 'x' ] ] );
		$data = $e->get_error_data();
		$this->assertSame( 7, $data['matches'] );
		$this->assertCount( IATO_MCP_Content_Patch::MAX_MATCH_CONTEXTS, $data['match_contexts'] );
		$this->assertArrayNotHasKey( 'matches_counted_up_to', $data );
	}

	public function test_counting_stops_at_a_thousand(): void {
		$e = self::refusal( str_repeat( 'a ', 3000 ), [ [ 'op' => 'replace', 'find' => 'a', 'replace' => 'b' ] ] );
		$this->assertSame( IATO_MCP_Content_Patch::MAX_COUNTED, $e->get_error_data()['matches'] );
		$this->assertSame( IATO_MCP_Content_Patch::MAX_COUNTED, $e->get_error_data()['matches_counted_up_to'] );
		$this->assertStringContainsString( 'at least 1000', $e->get_error_message() );
	}

	public function test_overlapping_matches_count(): void {
		$this->assertSame( 2, IATO_MCP_Content_Patch::count_matches( 'aaa', 'aa' ) );
		$this->assertSame( 3, IATO_MCP_Content_Patch::count_matches( 'abababab', 'abab' ) );
		$this->assertSame( 0, IATO_MCP_Content_Patch::count_matches( 'abc', '' ) );
		$this->assertSame( 'patch_multiple_matches', self::refusal( 'aaa', [ [ 'op' => 'replace', 'find' => 'aa', 'replace' => 'b' ] ] )->get_error_code() );
	}

	public function test_matching_is_byte_exact(): void {
		$this->assertSame( 'patch_no_match', self::refusal( self::ARTICLE, [ [ 'op' => 'replace', 'find' => 'the Lazy dog', 'replace' => 'x' ] ] )->get_error_code() );
		$this->assertSame( 'patch_no_match', self::refusal( "<p>a</p>\r\n<p>b</p>", [ [ 'op' => 'replace', 'find' => "</p>\n<p>", 'replace' => 'x' ] ] )->get_error_code() );
	}

	/** @return array<string,array{0:string,1:string,2:string}> */
	public static function near_misses(): array {
		return [
			'entities'      => [ '<p>Tom &amp; Jerry</p>', 'Tom & Jerry', 'HTML entities' ],
			'line breaks'   => [ "<p>a</p>\r\n<p>b</p>", "</p>\n<p>", 'Windows line breaks' ],
			'capitals'      => [ '<p>Hello World</p>', 'hello world', 'capital letters' ],
		];
	}

	#[DataProvider( 'near_misses' )]
	public function test_a_near_miss_gets_a_hint( string $content, string $find, string $hint ): void {
		$data = self::refusal( $content, [ [ 'op' => 'replace', 'find' => $find, 'replace' => 'x' ] ] )->get_error_data();
		$this->assertSame( 0, $data['matches'] );
		$this->assertStringContainsString( $hint, $data['hint'] );
	}

	// ── The operations sent ─────────────────────────────────────────────────

	/** @return array<string,array{0:mixed,1:string,2:?int}> */
	public static function bad_ops(): array {
		$one = [ 'op' => 'append', 'html' => 'x' ];
		return [
			'missing'              => [ null, 'invalid_patch_ops', null ],
			'a string'             => [ 'replace foo with bar', 'invalid_patch_ops', null ],
			'empty'                => [ [], 'invalid_patch_ops', null ],
			'an object'            => [ [ 'first' => $one ], 'invalid_patch_ops', null ],
			'a list with a gap'    => [ [ 0 => $one, 2 => $one ], 'invalid_patch_ops', null ],
			'too many'             => [ array_fill( 0, IATO_MCP_Content_Patch::MAX_OPS + 1, $one ), 'invalid_patch_ops', null ],
			'not an object'        => [ [ $one, 'append x' ], 'invalid_patch_op', 1 ],
			'no op'                => [ [ [ 'html' => 'x' ] ], 'invalid_patch_op', 0 ],
			'an unknown op'        => [ [ [ 'op' => 'delete', 'find' => 'x' ] ], 'invalid_patch_op', 0 ],
			'a field it lacks'     => [ [ [ 'op' => 'replace', 'find' => 'x' ] ], 'invalid_patch_op', 0 ],
			'another op\'s field'  => [ [ [ 'op' => 'append', 'html' => 'x', 'anchor' => 'y' ] ], 'invalid_patch_op', 0 ],
			'an empty find'        => [ [ [ 'op' => 'replace', 'find' => '', 'replace' => 'x' ] ], 'invalid_patch_op', 0 ],
			'an empty anchor'      => [ [ [ 'op' => 'insert_after', 'anchor' => '', 'html' => 'x' ] ], 'invalid_patch_op', 0 ],
			'empty html'           => [ [ $one, [ 'op' => 'append', 'html' => '' ] ], 'invalid_patch_op', 1 ],
			'a number as text'     => [ [ [ 'op' => 'replace', 'find' => 5, 'replace' => 'x' ] ], 'invalid_patch_op', 0 ],
			'a null replace'       => [ [ [ 'op' => 'replace', 'find' => 'x', 'replace' => null ] ], 'invalid_patch_op', 0 ],
		];
	}

	#[DataProvider( 'bad_ops' )]
	public function test_bad_operations_are_refused( mixed $ops, string $code, ?int $index ): void {
		$e = IATO_MCP_Content_Patch::validate_ops( $ops );
		$this->assertInstanceOf( WP_Error::class, $e );
		$this->assertSame( $code, $e->get_error_code() );
		$this->assertSame( $index, $e->get_error_data()['op_index'] ?? null );
		$this->assertStringContainsString( 'Nothing was written', $e->get_error_message() );
	}

	public function test_fifty_operations_and_an_empty_replace_are_accepted(): void {
		$this->assertCount( 50, IATO_MCP_Content_Patch::validate_ops( array_fill( 0, 50, [ 'op' => 'append', 'html' => 'x' ] ) ) );
		$this->assertSame(
			[ [ 'op' => 'replace', 'find' => 'x', 'replace' => '' ] ],
			IATO_MCP_Content_Patch::validate_ops( [ [ 'replace' => '', 'find' => 'x', 'op' => 'replace' ] ] )
		);
	}

	// ── Spans ───────────────────────────────────────────────────────────────

	public function test_edits_apart_are_separate_spans_in_content_order(): void {
		$r = self::patch( self::ARTICLE, [
			[ 'op' => 'replace', 'find' => 'Dogs are lazy', 'replace' => 'Dogs nap' ],
			[ 'op' => 'replace', 'find' => 'brown', 'replace' => 'red' ],
		] );
		$this->assertCount( 2, $r['spans'] );
		$this->assertSame( [ [ 1 ], [ 0 ] ], array_column( $r['spans'], 'ops' ) );
		$this->assertSame( [ 'brown', 'Dogs are lazy' ], array_column( $r['spans'], 'removed' ) );
		$second = $r['spans'][1];
		$this->assertSame( strpos( self::ARTICLE, 'Dogs are lazy' ), $second['offset_before'] );
		$this->assertSame( $second['offset_before'] - 2, $second['offset_after'], '"red" is two bytes shorter than "brown"' );
	}

	public function test_touching_and_overlapping_edits_are_one_span(): void {
		$touching = self::patch( 'abcdef', [ [ 'op' => 'insert_after', 'anchor' => 'abc', 'html' => 'X' ], [ 'op' => 'insert_before', 'anchor' => 'def', 'html' => 'Y' ] ] );
		$this->assertSame( 'abcXYdef', $touching['content'] );
		$this->assertCount( 1, $touching['spans'] );
		$this->assertSame( '', $touching['spans'][0]['removed'] );
		$this->assertSame( 'XY', $touching['spans'][0]['inserted'] );

		$over = self::patch( 'one two three four', [ [ 'op' => 'replace', 'find' => 'two', 'replace' => '2' ], [ 'op' => 'replace', 'find' => '2 three', 'replace' => 'II III' ] ] );
		$this->assertSame( 'one II III four', $over['content'] );
		$this->assertCount( 1, $over['spans'] );
		$this->assertSame( 'two three', $over['spans'][0]['removed'] );
		$this->assertSame( 'II III', $over['spans'][0]['inserted'] );
		$this->assertSame( 4, $over['spans'][0]['offset_before'] );

		$bridge = self::patch( 'a1b2c3d', [
			[ 'op' => 'replace', 'find' => '1', 'replace' => 'one' ],
			[ 'op' => 'replace', 'find' => '3', 'replace' => 'three' ],
			[ 'op' => 'replace', 'find' => 'oneb2cthree', 'replace' => '-' ],
		] );
		$this->assertSame( 'a-d', $bridge['content'] );
		$this->assertCount( 1, $bridge['spans'], 'an edit across two spans joins them' );
		$this->assertSame( [ 0, 1, 2 ], $bridge['spans'][0]['ops'] );
		$this->assertSame( '1b2c3', $bridge['spans'][0]['removed'] );
	}

	/** Random patches: the spans, applied to the original, give the patched content. */
	public function test_spans_rebuild_the_patched_content(): void {
		mt_srand( 1220 );
		$words = [ 'alpha', 'beta', 'gamma', 'delta', '<p>', '</p>', ' ', "\n", 'é', '日本', '😀', '&amp;' ];
		for ( $round = 0; $round < 300; $round++ ) {
			$original = '';
			for ( $w = mt_rand( 0, 60 ); $w > 0; $w-- ) {
				$original .= $words[ mt_rand( 0, count( $words ) - 1 ) ];
			}
			$content = $original;
			$ops     = [];
			for ( $tries = 0; $tries < 30 && count( $ops ) < 6; $tries++ ) {
				$kind = [ 'replace', 'insert_before', 'insert_after', 'append' ][ mt_rand( 0, 3 ) ];
				$text = $words[ mt_rand( 0, count( $words ) - 1 ) ] . mt_rand( 0, 9 );
				if ( 'append' === $kind ) {
					$op = [ 'op' => 'append', 'html' => $text ];
				} else {
					if ( strlen( $content ) < 2 ) {
						continue;
					}
					$from   = mt_rand( 0, strlen( $content ) - 1 );
					$needle = substr( $content, $from, mt_rand( 1, 12 ) );
					// Only needles that are whole UTF-8 and match once, as a client's would.
					if ( '' === $needle || ! self::is_utf8( $needle ) || 1 !== IATO_MCP_Content_Patch::count_matches( $content, $needle ) ) {
						continue;
					}
					$op = 'replace' === $kind
						? [ 'op' => 'replace', 'find' => $needle, 'replace' => mt_rand( 0, 3 ) ? $text : '' ]
						: [ 'op' => $kind, 'anchor' => $needle, 'html' => $text ];
				}
				$step = IATO_MCP_Content_Patch::apply( $content, [ $op ] );
				$this->assertIsArray( $step );
				$content = $step['content'];
				$ops[]   = $op;
			}
			if ( [] === $ops ) {
				continue;
			}
			$r = self::patch( $original, $ops );
			$this->assertSame( $content, $r['content'], "round {$round}" );

			$rebuilt = '';
			$cursor  = 0;
			$last    = -1;
			foreach ( $r['spans'] as $span ) {
				$this->assertGreaterThan( $last, $span['offset_before'], "round {$round}: spans in order, with unchanged text between them" );
				$this->assertSame( substr( $original, $span['offset_before'], $span['removed_bytes'] ), $span['removed'], "round {$round}: removed" );
				$this->assertSame( substr( $r['content'], $span['offset_after'], $span['inserted_bytes'] ), $span['inserted'], "round {$round}: inserted" );
				$this->assertSame( strlen( $rebuilt ) + ( $span['offset_before'] - $cursor ), $span['offset_after'], "round {$round}: offset_after" );
				$rebuilt .= substr( $original, $cursor, $span['offset_before'] - $cursor ) . $span['inserted'];
				$cursor   = $span['offset_before'] + $span['removed_bytes'];
				$last     = $cursor;
				$this->assertTrue( self::is_utf8( $span['context_before'] . $span['context_after'] ), "round {$round}: contexts are whole characters" );
			}
			$rebuilt .= substr( $original, $cursor );
			$this->assertSame( $r['content'], $rebuilt, "round {$round}: rebuilt from the spans" );
		}
	}

	// ── Context and long text ───────────────────────────────────────────────

	public function test_context_is_eighty_characters_each_side(): void {
		$span = self::patch( str_repeat( 'a', 200 ) . 'X' . str_repeat( 'b', 200 ), [ [ 'op' => 'replace', 'find' => 'X', 'replace' => 'Y' ] ] )['spans'][0];
		$this->assertSame( str_repeat( 'a', 80 ), $span['context_before'] );
		$this->assertSame( str_repeat( 'b', 80 ), $span['context_after'] );
	}

	/** @return array<string,array{0:string}> */
	public static function wide_characters(): array {
		return [ 'two bytes' => [ 'é' ], 'three bytes' => [ '日' ], 'four bytes' => [ '😀' ] ];
	}

	#[DataProvider( 'wide_characters' )]
	public function test_context_counts_characters_not_bytes( string $char ): void {
		$span = self::patch( str_repeat( $char, 200 ) . 'X' . str_repeat( $char, 200 ), [ [ 'op' => 'replace', 'find' => 'X', 'replace' => 'Y' ] ] )['spans'][0];
		$this->assertSame( str_repeat( $char, 80 ), $span['context_before'] );
		$this->assertSame( str_repeat( $char, 80 ), $span['context_after'] );
	}

	public function test_long_text_is_cut_at_character_boundaries(): void {
		$long = str_repeat( 'é', 300 ); // 600 bytes
		$span = self::patch( '<p>x</p>', [ [ 'op' => 'replace', 'find' => 'x', 'replace' => $long ] ] )['spans'][0];
		$this->assertSame( 600, $span['inserted_bytes'] );
		$this->assertTrue( $span['inserted_cut'] );
		$this->assertArrayNotHasKey( 'removed_cut', $span );
		$this->assertTrue( self::is_utf8( $span['inserted'] ) );
		$this->assertSame( str_repeat( 'é', 100 ) . '…' . str_repeat( 'é', 100 ), $span['inserted'] );

		$short = self::patch( '<p>x</p>', [ [ 'op' => 'replace', 'find' => 'x', 'replace' => str_repeat( 'y', IATO_MCP_Content_Patch::CUT_BYTES ) ] ] )['spans'][0];
		$this->assertArrayNotHasKey( 'inserted_cut', $short, 'up to CUT_BYTES is shown whole' );
	}

	public function test_bytes_that_are_not_utf8_are_shown_as_replacement_characters(): void {
		$content = "<p>caf\xE9 ok</p>"; // Latin-1 é, stored as it was
		$span    = self::patch( $content, [ [ 'op' => 'replace', 'find' => 'ok', 'replace' => 'fine' ] ] )['spans'][0];
		$this->assertSame( "<p>caf\u{FFFD} ", $span['context_before'] );
		$this->assertSame( strpos( $content, 'ok' ), $span['offset_before'], 'offsets count the stored bytes' );
		$this->assertNotFalse( json_encode( $span ) );
	}

	// ── Hashes ──────────────────────────────────────────────────────────────

	public function test_the_hash_is_the_elementor_revision_format(): void {
		$this->assertSame( 'sha256:' . hash( 'sha256', self::ARTICLE ), IATO_MCP_Content_Patch::hash( self::ARTICLE ) );
		$this->assertSame( IATO_MCP_Elementor_Adapter::compute_revision( self::ARTICLE ), IATO_MCP_Content_Patch::hash( self::ARTICLE ) );
		$this->assertSame(
			[ 'content_hash_before' => IATO_MCP_Content_Patch::hash( 'ab' ), 'content_hash_after' => IATO_MCP_Content_Patch::hash( 'abc' ), 'bytes_before' => 2, 'bytes_after' => 3 ],
			IATO_MCP_Content_Patch::sizes( 'ab', 'abc' )
		);
	}

	// ── The tool's place in the plugin ──────────────────────────────────────

	public function test_the_tool_is_on_every_list(): void {
		$this->assertContains( 'update_post_content_patch', IATO_MCP_Cache::WRITE_TOOLS );
		$source = (string) file_get_contents( IATO_MCP_DIR . 'includes/class-settings.php' );
		foreach ( [ 'TOOL_NAMES', 'TOOL_DESCRIPTIONS', 'TOOL_CATEGORIES', 'TOOL_MIGRATION_BACKFILL' ] as $constant ) {
			$this->assertSame( 1, preg_match( '/const ' . $constant . ' = \[(.*?)\n\t\];/s', $source, $m ), $constant );
			$this->assertStringContainsString( "'update_post_content_patch'", $m[1], $constant );
		}
		$this->assertMatchesRegularExpression( "/'1\.22\.0' => \[[^\]]*'update_post_content_patch',/", $source );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_schemas(): void {
		require_once IATO_MCP_DIR . 'includes/tools/wp/tool-posts.php';
		require_once IATO_MCP_DIR . 'includes/tools/wp/tool-content-patch.php';
		$patch = IATO_MCP_Server::get_catalog_entry( 'update_post_content_patch' )['definition']['inputSchema'];
		$this->assertSame( [ 'id', 'ops', 'dry_run', 'if_revision', 'idempotency_key', 'allow_markup_removal' ], array_keys( $patch['properties'] ) );
		$this->assertSame( [ 'id', 'ops' ], $patch['required'] );
		$this->assertSame( array_keys( IATO_MCP_Content_Patch::OPS ), $patch['properties']['ops']['items']['properties']['op']['enum'] );

		$update = IATO_MCP_Server::get_catalog_entry( 'update_post' )['definition']['inputSchema'];
		$this->assertSame( [ 'full', 'minimal' ], $update['properties']['return']['enum'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_update_posts_minimal_dry_run_gives_text_as_bytes_and_sha256(): void {
		require_once IATO_MCP_DIR . 'includes/tools/wp/tool-posts.php';
		$would = iato_mcp_update_post_minimal_preview( [
			'content' => [ 'before' => '<p>Old</p>', 'after' => '<p>New text</p>' ],
			'title'   => [ 'before' => 'A', 'after' => 'B' ],
			'slug'    => [ 'before' => 'a', 'after' => 'b' ],
			'author'  => [ 'before' => 1, 'after' => 3 ],
		] );
		$this->assertSame( [ 'bytes' => 10, 'sha256' => hash( 'sha256', '<p>Old</p>' ) ], $would['content']['before'] );
		$this->assertSame( [ 'bytes' => 15, 'sha256' => hash( 'sha256', '<p>New text</p>' ) ], $would['content']['after'] );
		$this->assertSame( [ 'bytes' => 1, 'sha256' => hash( 'sha256', 'B' ) ], $would['title']['after'] );
		$this->assertSame( [ 'before' => 'a', 'after' => 'b' ], $would['slug'], 'short fields stay as they are' );
		$this->assertSame( [ 'before' => 1, 'after' => 3 ], $would['author'] );
	}
}
