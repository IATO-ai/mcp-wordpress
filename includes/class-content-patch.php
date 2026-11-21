<?php
/**
 * Content patches (1.22.0): exact-match edits to a post's content, for
 * update_post_content_patch.
 *
 * A patch is a list of operations applied in order to the content as stored:
 *
 *   replace        {find, replace}   find is replaced; replace "" deletes it
 *   insert_before  {anchor, html}    html goes in front of anchor
 *   insert_after   {anchor, html}    html goes after anchor
 *   append         {html}            html goes at the end, as given
 *
 * find and anchor are byte-exact strings that must match exactly once in the
 * content as the operations before them left it. Overlapping matches count, so
 * "aa" in "aaa" is two matches and is refused. The first operation that matches
 * nothing, or more than once, stops the patch and nothing is applied.
 *
 * What changed is reported as spans: each edited region of the result with what
 * it replaced, the text it now holds and about 80 characters on each side, so a
 * client can check the edit without reading the post back. Edits that overlap or
 * touch are one span. Offsets and byte counts are bytes of the stored content.
 * Shown text (contexts, removed, inserted) is cut at character boundaries, and
 * bytes that are not UTF-8 are shown as U+FFFD; the byte counts are exact.
 *
 * Pure PHP apart from WP_Error, and no mbstring: WordPress does not require it.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Content_Patch {

	/** Most operations in one patch. */
	public const MAX_OPS = 50;

	/** Characters of context on each side of a span. */
	public const CONTEXT_CHARS = 80;

	/** Removed or inserted text longer than this is shown as its start and end. */
	public const CUT_BYTES = 400;

	/** Matches shown when find or anchor matches more than once. */
	public const MAX_MATCH_CONTEXTS = 5;

	/** Characters on each side of a match shown in that error. */
	private const MATCH_CONTEXT_CHARS = 40;

	/** Matches counted before patch_multiple_matches says "at least". */
	public const MAX_COUNTED = 1000;

	/** Each operation and its fields. */
	public const OPS = [
		'replace'       => [ 'find', 'replace' ],
		'insert_before' => [ 'anchor', 'html' ],
		'insert_after'  => [ 'anchor', 'html' ],
		'append'        => [ 'html' ],
	];

	/** "…" between the start and end of cut text. */
	private const ELLIPSIS = "\xE2\x80\xA6";

	/** U+FFFD, shown for a byte that is not UTF-8. */
	private const REPLACEMENT = "\xEF\xBF\xBD";

	/** The content hash if_revision takes: the format of Elementor revisions, 'sha256:' and hex. */
	public static function hash( string $content ): string {
		return 'sha256:' . hash( 'sha256', $content );
	}

	/**
	 * The hashes and sizes of the content before and after a write.
	 *
	 * @return array{content_hash_before:string,content_hash_after:string,bytes_before:int,bytes_after:int}
	 */
	public static function sizes( string $before, string $after ): array {
		return [
			'content_hash_before' => self::hash( $before ),
			'content_hash_after'  => self::hash( $after ),
			'bytes_before'        => strlen( $before ),
			'bytes_after'         => strlen( $after ),
		];
	}

	/**
	 * Check the operations sent. Returns them with only their own fields, or
	 * invalid_patch_ops (the list) / invalid_patch_op (one operation, with op_index).
	 *
	 * @return list<array<string,string>>|WP_Error
	 */
	public static function validate_ops( mixed $ops ): array|WP_Error {
		$count = is_array( $ops ) ? count( $ops ) : 0;
		// A list, not an object with named keys (no array_is_list(): PHP 8.0 is supported).
		if ( 0 === $count || $count > self::MAX_OPS || array_keys( $ops ) !== range( 0, $count - 1 ) ) {
			return new WP_Error(
				'invalid_patch_ops',
				sprintf( 'ops must be a list of 1 to %d operations. Nothing was written.', self::MAX_OPS ),
				[ 'status' => 400, 'max_ops' => self::MAX_OPS ]
			);
		}

		$out = [];
		foreach ( $ops as $i => $op ) {
			$name = is_array( $op ) && is_string( $op['op'] ?? null ) ? $op['op'] : '';
			if ( ! isset( self::OPS[ $name ] ) ) {
				return self::op_error( $i, sprintf( 'op must be one of %s.', implode( ', ', array_keys( self::OPS ) ) ) );
			}
			$fields = self::OPS[ $name ];
			$extra  = array_diff( array_map( 'strval', array_keys( $op ) ), array_merge( [ 'op' ], $fields ) );
			if ( [] !== $extra ) {
				return self::op_error( $i, sprintf( '%s takes %s; %s is not one of them.', $name, implode( ' and ', $fields ), implode( ', ', $extra ) ) );
			}
			$clean = [ 'op' => $name ];
			foreach ( $fields as $field ) {
				$value = $op[ $field ] ?? null;
				if ( ! is_string( $value ) ) {
					return self::op_error( $i, sprintf( '%s needs %s as a string.', $name, $field ) );
				}
				// replace may be empty, which deletes find. Nothing else may.
				if ( '' === $value && 'replace' !== $field ) {
					return self::op_error( $i, sprintf( '%s must not be empty.', $field ) );
				}
				$clean[ $field ] = $value;
			}
			$out[] = $clean;
		}
		return $out;
	}

	/** Matches of $needle in $haystack, overlapping ones included: "aa" is in "aaa" twice. */
	public static function count_matches( string $haystack, string $needle, int $stop_at = PHP_INT_MAX ): int {
		if ( '' === $needle ) {
			return 0;
		}
		$count  = 0;
		$offset = 0;
		while ( $count < $stop_at && false !== ( $at = strpos( $haystack, $needle, $offset ) ) ) {
			++$count;
			$offset = $at + 1;
		}
		return $count;
	}

	/**
	 * Apply checked operations (validate_ops()) in order, all or nothing.
	 *
	 * @param list<array<string,string>> $ops
	 * @return array{content:string,spans:list<array>}|WP_Error patch_no_match or patch_multiple_matches
	 */
	public static function apply( string $content, array $ops ): array|WP_Error {
		$original = $content;
		$regions  = [];
		foreach ( $ops as $i => $op ) {
			$name = $op['op'];
			if ( 'append' === $name ) {
				$p    = strlen( $content );
				$q    = $p;
				$text = $op['html'];
			} else {
				$field  = 'replace' === $name ? 'find' : 'anchor';
				$needle = $op[ $field ];
				$at     = self::only_match( $content, $needle, $i, $name, $field );
				if ( is_wp_error( $at ) ) {
					return $at;
				}
				[ $p, $q, $text ] = match ( $name ) {
					'replace'       => [ $at, $at + strlen( $needle ), $op['replace'] ],
					'insert_before' => [ $at, $at, $op['html'] ],
					'insert_after'  => [ $at + strlen( $needle ), $at + strlen( $needle ), $op['html'] ],
				};
			}
			$content = substr( $content, 0, $p ) . $text . substr( $content, $q );
			$regions = self::splice( $regions, $p, $q, strlen( $text ), $i );
		}
		return [ 'content' => $content, 'spans' => self::spans( $original, $content, $regions ) ];
	}

	/** Where $needle matches $content, when it matches exactly once. */
	private static function only_match( string $content, string $needle, int $i, string $name, string $field ): int|WP_Error {
		// Counting stops at MAX_COUNTED: a short find in a long post can match thousands of times.
		$matches = self::count_matches( $content, $needle, self::MAX_COUNTED );
		if ( 1 === $matches ) {
			return (int) strpos( $content, $needle );
		}
		$data = [ 'status' => 400, 'op_index' => $i, 'op' => $name, 'field' => $field, 'matches' => $matches ];
		if ( 0 === $matches ) {
			$hint = self::near_miss( $content, $needle );
			if ( null !== $hint ) {
				$data['hint'] = $hint;
			}
			return new WP_Error(
				'patch_no_match',
				sprintf( 'ops[%d] (%s): %s matches nothing in the content. It must match the content as get_post returns it, byte for byte, including line breaks and entities such as &amp;. Nothing was written.', $i, $name, $field ),
				$data
			);
		}
		if ( $matches >= self::MAX_COUNTED ) {
			$data['matches_counted_up_to'] = self::MAX_COUNTED;
		}
		$contexts = [];
		$offset   = 0;
		while ( count( $contexts ) < self::MAX_MATCH_CONTEXTS && false !== ( $at = strpos( $content, $needle, $offset ) ) ) {
			$from       = self::back( $content, $at, self::MATCH_CONTEXT_CHARS );
			$end        = $at + strlen( $needle );
			$contexts[] = [
				'offset'         => $at,
				'context_before' => self::shown( substr( $content, $from, $at - $from ) ),
				'context_after'  => self::shown( substr( $content, $end, self::forward( $content, $end, self::MATCH_CONTEXT_CHARS ) - $end ) ),
			];
			$offset = $at + 1;
		}
		$data['match_contexts'] = $contexts;
		return new WP_Error(
			'patch_multiple_matches',
			sprintf( 'ops[%d] (%s): %s matches %s%d places in the content. Make it longer, with text around it, so it matches once. Nothing was written.', $i, $name, $field, $matches >= self::MAX_COUNTED ? 'at least ' : '', $matches ),
			$data
		);
	}

	/** Why find may have missed: the same text with another letter case, entities or line breaks. */
	private static function near_miss( string $content, string $needle ): ?string {
		$escaped = htmlspecialchars( $needle, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
		if ( $escaped !== $needle && str_contains( $content, $escaped ) ) {
			return 'The content holds this text with HTML entities (such as &amp; for &). Copy it from get_post.';
		}
		if ( str_contains( $needle, "\n" ) && ! str_contains( $needle, "\r\n" ) && str_contains( $content, str_replace( "\n", "\r\n", $needle ) ) ) {
			return 'The content uses Windows line breaks (\r\n) here. Copy the text from get_post.';
		}
		if ( false !== stripos( $content, $needle ) ) {
			return 'The text is there with different capital letters.';
		}
		return null;
	}

	private static function op_error( int $i, string $why ): WP_Error {
		return new WP_Error(
			'invalid_patch_op',
			sprintf( 'ops[%d]: %s Nothing was written.', $i, $why ),
			[ 'status' => 400, 'op_index' => $i ]
		);
	}

	/**
	 * Record a splice of [$p, $q) by $length bytes in the edited regions, kept in
	 * the current content's coordinates as {s, e, orig_len, ops}. Regions the
	 * splice overlaps or touches merge into one; later regions shift. orig_len is
	 * how many bytes of the original content the region stands for.
	 *
	 * @param list<array{s:int,e:int,orig_len:int,ops:list<int>}> $regions sorted, apart
	 * @return list<array{s:int,e:int,orig_len:int,ops:list<int>}>
	 */
	private static function splice( array $regions, int $p, int $q, int $length, int $op_index ): array {
		$delta  = $length - ( $q - $p );
		$start  = $p;
		$end    = $q;
		$growth = 0;
		$ops    = [ $op_index ];
		$before = [];
		$after  = [];
		foreach ( $regions as $r ) {
			if ( $r['e'] < $p ) {
				$before[] = $r;
			} elseif ( $r['s'] > $q ) {
				$r['s']  += $delta;
				$r['e']  += $delta;
				$after[]  = $r;
			} else {
				$start   = min( $start, $r['s'] );
				$end     = max( $end, $r['e'] );
				$growth += ( $r['e'] - $r['s'] ) - $r['orig_len'];
				$ops     = array_merge( $ops, $r['ops'] );
			}
		}
		$ops = array_values( array_unique( $ops ) );
		sort( $ops );
		$merged = [ 's' => $start, 'e' => $end + $delta, 'orig_len' => ( $end - $start ) - $growth, 'ops' => $ops ];
		return array_merge( $before, [ $merged ], $after );
	}

	/** The regions as reported spans, with where each stood in the original. */
	private static function spans( string $original, string $patched, array $regions ): array {
		$spans = [];
		$delta = 0;
		foreach ( $regions as $r ) {
			$from_before = $r['s'] - $delta;
			$removed     = substr( $original, $from_before, $r['orig_len'] );
			$inserted    = substr( $patched, $r['s'], $r['e'] - $r['s'] );
			$delta      += ( $r['e'] - $r['s'] ) - $r['orig_len'];
			$ctx_from    = self::back( $patched, $r['s'], self::CONTEXT_CHARS );
			$ctx_to      = self::forward( $patched, $r['e'], self::CONTEXT_CHARS );
			$span        = [
				'ops'            => $r['ops'],
				'offset_before'  => $from_before,
				'offset_after'   => $r['s'],
				'removed_bytes'  => strlen( $removed ),
				'inserted_bytes' => strlen( $inserted ),
				'context_before' => self::shown( substr( $patched, $ctx_from, $r['s'] - $ctx_from ) ),
				'removed'        => self::cut( $removed ),
				'inserted'       => self::cut( $inserted ),
				'context_after'  => self::shown( substr( $patched, $r['e'], $ctx_to - $r['e'] ) ),
			];
			if ( strlen( $removed ) > self::CUT_BYTES ) {
				$span['removed_cut'] = true;
			}
			if ( strlen( $inserted ) > self::CUT_BYTES ) {
				$span['inserted_cut'] = true;
			}
			$spans[] = $span;
		}
		return $spans;
	}

	/** Text over CUT_BYTES as its start, "…" and its end, cut at character boundaries. */
	public static function cut( string $text ): string {
		if ( strlen( $text ) <= self::CUT_BYTES ) {
			return self::shown( $text );
		}
		$half = intdiv( self::CUT_BYTES, 2 );
		$head = self::boundary_at_or_before( $text, $half );
		$tail = self::boundary_at_or_after( $text, strlen( $text ) - $half );
		return self::shown( substr( $text, 0, $head ) ) . self::ELLIPSIS . self::shown( substr( $text, $tail ) );
	}

	/** Where $chars characters before $at start. */
	public static function back( string $text, int $at, int $chars ): int {
		$i = $at;
		for ( $n = 0; $n < $chars && $i > 0; $n++ ) {
			--$i;
			// Back over at most three continuation bytes to the character's first byte.
			for ( $k = 0; $k < 3 && $i > 0 && self::continues( $text, $i ); $k++ ) {
				--$i;
			}
		}
		return $i;
	}

	/** Where $chars characters after $at end. */
	public static function forward( string $text, int $at, int $chars ): int {
		$length = strlen( $text );
		$i      = $at;
		for ( $n = 0; $n < $chars && $i < $length; $n++ ) {
			++$i;
			for ( $k = 0; $k < 3 && $i < $length && self::continues( $text, $i ); $k++ ) {
				++$i;
			}
		}
		return $i;
	}

	private static function boundary_at_or_before( string $text, int $at ): int {
		for ( $k = 0; $k < 3 && $at > 0 && $at < strlen( $text ) && self::continues( $text, $at ); $k++ ) {
			--$at;
		}
		return $at;
	}

	private static function boundary_at_or_after( string $text, int $at ): int {
		for ( $k = 0; $k < 3 && $at < strlen( $text ) && self::continues( $text, $at ); $k++ ) {
			++$at;
		}
		return $at;
	}

	/** Is the byte at $i a UTF-8 continuation byte (10xxxxxx)? */
	private static function continues( string $text, int $i ): bool {
		return ( ord( $text[ $i ] ) & 0xC0 ) === 0x80;
	}

	/** The text with every byte that is not part of a UTF-8 character shown as U+FFFD, so it can be JSON. */
	public static function shown( string $text ): string {
		if ( 1 === preg_match( '//u', $text ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			'/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
			static fn( array $m ): string => isset( $m[1] ) && '' !== $m[1] ? $m[1] : self::REPLACEMENT,
			$text
		);
	}
}
