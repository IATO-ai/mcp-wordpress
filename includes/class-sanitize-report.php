<?php
/**
 * What sanitising removed from a value (1.15.0): the `sanitized_report` entries
 * write tools return next to `sanitized` and `sanitized_paths`.
 *
 * Sanitising functions report nothing about what they removed, so the input and
 * the output are compared: each is reduced to an inventory of tags and
 * attributes, and whatever the output has fewer of was removed. Pure PHP, no
 * WordPress calls.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Sanitize_Report {

	public const MAX_ENTRIES = 50;

	/** Elements whose content is text, not markup: tags inside them are not tags. */
	private const RAW_TEXT = [ 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes' ];

	/** Per `inline_markup` value, what the caller should know. */
	private const POLICY_NOTES = [
		'stripped_setting_off'   => 'Inline SVG and style blocks were removed. An administrator can keep them for administrators with "Allow inline SVG and style blocks in HTML widgets for administrators" under Settings > IATO MCP.',
		'stripped_no_capability' => "Inline SVG and style blocks were removed: they are kept only for a WordPress user who holds both unfiltered_html and manage_options, and this connection's user does not.",
		'stripped_wp_version'    => 'Inline SVG and style blocks were removed: keeping them needs WordPress 6.5 or later.',
		'stripped_unsafe'        => 'Inline SVG and style blocks were removed: the markup did not pass the safety check.',
	];

	/**
	 * Tags and attributes in an HTML string, names lowercased. A tag named in
	 * the input with a different case is the same tag (kses rewrites viewBox to
	 * viewbox).
	 *
	 * @return array{tags:array<string,int>,attributes:array<string,array<string,int>>}
	 */
	public static function inventory( string $html ): array {
		$tags       = [];
		$attributes = [];
		$length     = strlen( $html );
		$at         = 0;
		while ( false !== ( $at = strpos( $html, '<', $at ) ) ) {
			if ( str_starts_with( substr( $html, $at, 4 ), '<!--' ) ) {
				$end = strpos( $html, '-->', $at + 4 );
				if ( false === $end ) {
					break;
				}
				$at = $end + 3;
				continue;
			}
			if ( 1 !== preg_match( '/\G<([a-zA-Z][^\s\/>]*)/', $html, $match, 0, $at ) ) {
				++$at; // a closing tag, a declaration or a stray `<`
				continue;
			}
			$name = strtolower( $match[1] );
			$end  = self::read_attributes( $html, $at + strlen( $match[0] ), $length, $found );
			if ( null === $end ) {
				break; // the tag never closes, so it is not one
			}
			$tags[ $name ] = ( $tags[ $name ] ?? 0 ) + 1;
			foreach ( $found as $attribute ) {
				$attributes[ $name ][ $attribute ] = ( $attributes[ $name ][ $attribute ] ?? 0 ) + 1;
			}
			$at = $end;
			if ( in_array( $name, self::RAW_TEXT, true ) ) {
				$closer = stripos( $html, '</' . $name, $at );
				if ( false === $closer ) {
					break;
				}
				$at = $closer;
			}
		}
		return [ 'tags' => $tags, 'attributes' => $attributes ];
	}

	/**
	 * Read a tag's attributes from $at to the closing `>`, respecting quotes.
	 *
	 * @param string[] $found Filled with the lowercased attribute names.
	 * @return int|null The offset after `>`; null when the tag never closes.
	 */
	private static function read_attributes( string $html, int $at, int $length, ?array &$found ): ?int {
		$found = [];
		while ( $at < $length ) {
			$at += strspn( $html, " \t\r\n\f/", $at );
			if ( $at >= $length ) {
				return null;
			}
			if ( '>' === $html[ $at ] ) {
				return $at + 1;
			}
			$name_length = strcspn( $html, " \t\r\n\f/>=", $at );
			if ( 0 === $name_length ) {
				++$at; // a stray `=`
				continue;
			}
			$found[] = strtolower( substr( $html, $at, $name_length ) );
			$at     += $name_length;
			$at     += strspn( $html, " \t\r\n\f", $at );
			if ( $at >= $length || '=' !== $html[ $at ] ) {
				continue;
			}
			++$at;
			$at += strspn( $html, " \t\r\n\f", $at );
			if ( $at >= $length ) {
				return null;
			}
			if ( '"' === $html[ $at ] || "'" === $html[ $at ] ) {
				$close = strpos( $html, $html[ $at ], $at + 1 );
				if ( false === $close ) {
					return null;
				}
				$at = $close + 1;
			} else {
				$at += strcspn( $html, " \t\r\n\f>", $at );
			}
		}
		return null;
	}

	/**
	 * What the output has fewer of. Attributes of a tag that was removed are not
	 * listed: the tag says it.
	 *
	 * @return array{removed_tags:string[],removed_attributes:string[]}
	 */
	public static function removed( string $before, string $after ): array {
		$was = self::inventory( $before );
		$now = self::inventory( $after );

		$tags = [];
		foreach ( $was['tags'] as $tag => $count ) {
			if ( ( $now['tags'][ $tag ] ?? 0 ) < $count ) {
				$tags[] = (string) $tag;
			}
		}
		$attributes = [];
		foreach ( $was['attributes'] as $tag => $names ) {
			if ( empty( $now['tags'][ $tag ] ) ) {
				continue;
			}
			$tag_loss = (int) $was['tags'][ $tag ] - (int) $now['tags'][ $tag ];
			foreach ( $names as $name => $count ) {
				if ( $count - ( $now['attributes'][ $tag ][ $name ] ?? 0 ) > $tag_loss && ! in_array( (string) $name, $attributes, true ) ) {
					$attributes[] = (string) $name;
				}
			}
		}
		return [ 'removed_tags' => $tags, 'removed_attributes' => $attributes ];
	}

	/**
	 * One report entry.
	 *
	 * @param string $path   Where the value lives: "<element id>/settings/html", "content", ...
	 * @param string $rule   Which rule applied: html, url, css or text.
	 * @param array  $extra  inline_markup, kept_tags, removed_css, source.
	 */
	public static function entry( string $path, string $rule, string $before, string $after, array $extra = [] ): array {
		$removed = self::removed( $before, $after );
		$entry   = [ 'path' => $path, 'rule' => $rule ] + $removed + $extra;

		$notes  = [];
		$policy = (string) ( $extra['inline_markup'] ?? '' );
		// wp_kses removes the tags and keeps what is between them. The plugin removes
		// whole elements first (1.16.0), so this is only for what stays: an unclosed
		// tag, Elementor's own kses pass, or WordPress below 6.5.
		if ( 'html' === $rule && 'kept' !== $policy && array_intersect( [ 'style', 'script' ], $removed['removed_tags'] ) && self::removed_text_remains( $before, $after ) ) {
			$notes[] = 'The contents of removed <style> and <script> tags stay on the page as visible text; send the value again without them.';
		}
		if ( isset( self::POLICY_NOTES[ $policy ] ) ) {
			$notes[] = self::POLICY_NOTES[ $policy ];
		}
		if ( [] !== $notes ) {
			$entry['note'] = implode( ' ', $notes );
		}
		return $entry;
	}

	/** Does the text of a style or script element in $before still show in $after? For the note only. */
	private static function removed_text_remains( string $before, string $after ): bool {
		if ( ! preg_match_all( '#<(style|script)\b[^>]*>(.*?)(?:</\1\s*>|\z)#is', $before, $matches ) ) {
			return false;
		}
		$shown = html_entity_decode( $after, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( $matches[2] as $body ) {
			$body = trim( $body );
			if ( '' !== $body && str_contains( $shown, $body ) ) {
				return true;
			}
		}
		return false;
	}

	/** An entry for a value that passed through a sanitising function, or null when nothing was removed from it. */
	public static function for_value( string $path, string $rule, mixed $before, mixed $after, array $extra = [] ): ?array {
		if ( ! is_string( $before ) || ! is_string( $after ) || $before === $after ) {
			return null;
		}
		$entry = self::entry( $path, $rule, $before, $after, $extra );
		return [] === $entry['removed_tags'] && [] === $entry['removed_attributes'] ? null : $entry;
	}

	/** Cap a report, in the form sanitized_paths uses. */
	public static function cap( array $report ): array {
		$more = count( $report ) - self::MAX_ENTRIES;
		if ( $more <= 0 ) {
			return $report;
		}
		$report   = array_slice( $report, 0, self::MAX_ENTRIES );
		$report[] = [ 'path' => sprintf( '… and %d more', $more ), 'removed_tags' => [], 'removed_attributes' => [] ];
		return $report;
	}

	/**
	 * Combine reports, one entry per path. The atomic writer reports a value as
	 * `.../title` and the sanitising pass as `.../title/value`: the same value.
	 */
	public static function merge( array ...$reports ): array {
		$out = [];
		foreach ( $reports as $report ) {
			foreach ( $report as $entry ) {
				$key = preg_replace( '#/value$#', '', (string) ( $entry['path'] ?? '' ) );
				if ( ! isset( $out[ $key ] ) ) {
					$out[ $key ] = $entry;
				}
			}
		}
		return array_values( $out );
	}

	/** Did this entry's value change? An entry can also just say that markup was kept. */
	public static function is_change( array $entry ): bool {
		return 'kept' !== ( $entry['inline_markup'] ?? '' ) || ! empty( $entry['removed_tags'] ) || ! empty( $entry['removed_attributes'] ) || ! empty( $entry['removed_css'] );
	}

	/**
	 * Add entries to a response: sanitized_report, and for those that changed a
	 * value, sanitized and sanitized_paths.
	 *
	 * @param array $response The response data (passed by reference).
	 * @param array $entries  Report entries.
	 */
	public static function add_to( array &$response, array $entries ): void {
		if ( [] === $entries ) {
			return;
		}
		$response['sanitized_report'] = self::merge( (array) ( $response['sanitized_report'] ?? [] ), $entries );
		$paths                        = [];
		foreach ( $entries as $entry ) {
			if ( self::is_change( $entry ) ) {
				$paths[] = (string) $entry['path'];
			}
		}
		if ( [] !== $paths ) {
			$response['sanitized']       = true;
			$response['sanitized_paths'] = array_values( array_unique( array_merge( (array) ( $response['sanitized_paths'] ?? [] ), $paths ) ) );
		}
	}
}
