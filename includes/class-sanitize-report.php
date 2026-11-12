<?php
/**
 * What sanitising removed from a value (1.15.0): the `sanitized_report` entries
 * write tools return next to `sanitized` and `sanitized_paths`.
 *
 * Sanitising functions report nothing about what they removed, so the input and
 * the output are compared: each is reduced to an inventory of tags and
 * attributes, and whatever the output has fewer of was removed. Pure PHP, no
 * WordPress calls, except same_markup(), which uses WordPress's tokenizer when
 * there is one.
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

	/** HTML's void elements: a self-closing slash on one changes nothing. */
	private const VOID = [ 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'SOURCE', 'TRACK', 'WBR' ];

	/**
	 * The same HTML written differently? Same tags in the same order, the same
	 * attributes with the same decoded values, the same decoded text; quotes,
	 * character references, attribute order and case may differ (kses rewrites
	 * single-quoted attributes with double quotes). Null when it cannot tell:
	 * WordPress below 6.5 (no WP_HTML_Tag_Processor::next_token()), or markup
	 * the tokenizer stops in. 1.21.1, for `stored_difference`.
	 */
	public static function same_markup( string $a, string $b ): ?bool {
		$left = self::markup_tokens( $a );
		if ( null === $left ) {
			return null;
		}
		$right = self::markup_tokens( $b );
		return null === $right ? null : $left === $right;
	}

	/** @return array|null Each token as data, or null (see same_markup()). */
	private static function markup_tokens( string $html ): ?array {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || ! method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			return null;
		}
		$processor = new WP_HTML_Tag_Processor( $html );
		$tokens    = [];
		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();
			if ( '#tag' !== $type ) {
				$tokens[] = [ $type, $processor->get_modifiable_text() ];
				continue;
			}
			$tag        = (string) $processor->get_tag();
			$attributes = [];
			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				$attributes[ $name ] = $processor->get_attribute( $name );
			}
			ksort( $attributes, SORT_STRING );
			$self_closing = in_array( $tag, self::VOID, true ) ? false : $processor->has_self_closing_flag();
			$tokens[]     = [ '#tag', $tag, $processor->is_tag_closer(), $attributes, $self_closing ];
		}
		if ( method_exists( $processor, 'paused_at_incomplete_token' ) && $processor->paused_at_incomplete_token() ) {
			return null;
		}
		return $tokens;
	}

	/**
	 * Tags and attributes in an HTML string, names lowercased. A tag named in
	 * the input with a different case is the same tag (kses rewrites viewBox to
	 * viewbox).
	 *
	 * `styles` (1.21.0): the declarations of each tag's style attributes, as
	 * "property:value", so that one kses dropped can be named.
	 *
	 * @return array{tags:array<string,int>,attributes:array<string,array<string,int>>,styles:array<string,array<string,int>>}
	 */
	public static function inventory( string $html ): array {
		$tags       = [];
		$attributes = [];
		$styles     = [];
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
			$name  = strtolower( $match[1] );
			$style = null;
			$end   = self::read_attributes( $html, $at + strlen( $match[0] ), $length, $found, $style );
			if ( null === $end ) {
				break; // the tag never closes, so it is not one
			}
			$tags[ $name ] = ( $tags[ $name ] ?? 0 ) + 1;
			foreach ( $found as $attribute ) {
				$attributes[ $name ][ $attribute ] = ( $attributes[ $name ][ $attribute ] ?? 0 ) + 1;
			}
			foreach ( self::declarations( (string) $style ) as $declaration ) {
				$styles[ $name ][ $declaration ] = ( $styles[ $name ][ $declaration ] ?? 0 ) + 1;
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
		return [ 'tags' => $tags, 'attributes' => $attributes, 'styles' => $styles ];
	}

	/**
	 * A style attribute's declarations, each "property:value" with the property
	 * lowercased and the spacing around it removed. Split on `;` as WordPress's
	 * safecss_filter_attr() does, entities decoded first, since kses re-encodes them.
	 *
	 * @return string[]
	 */
	public static function declarations( string $style ): array {
		$out = [];
		foreach ( explode( ';', html_entity_decode( $style, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) as $part ) {
			$part = trim( (string) preg_replace( '/[\t\r\n]+/', ' ', $part ) );
			if ( '' === $part ) {
				continue;
			}
			$colon = strpos( $part, ':' );
			$out[] = false === $colon ? $part : strtolower( trim( substr( $part, 0, $colon ) ) ) . ':' . trim( substr( $part, $colon + 1 ) );
		}
		return $out;
	}

	/**
	 * Declarations the output's style attributes have fewer of, named with their tag:
	 * "style on <div>: color:rgb(1,2,3)". A tag that was removed takes its declarations
	 * with it, and the tag says so.
	 *
	 * @return string[]
	 */
	public static function removed_declarations( string $before, string $after ): array {
		$was = self::inventory( $before );
		$now = self::inventory( $after );
		$out = [];
		foreach ( $was['styles'] as $tag => $declarations ) {
			if ( empty( $now['tags'][ $tag ] ) ) {
				continue;
			}
			$tag_loss = (int) $was['tags'][ $tag ] - (int) $now['tags'][ $tag ];
			foreach ( $declarations as $declaration => $count ) {
				if ( $count - ( $now['styles'][ $tag ][ $declaration ] ?? 0 ) > $tag_loss ) {
					$out[] = "style on <{$tag}>: {$declaration}";
				}
			}
		}
		return $out;
	}

	/**
	 * Read a tag's attributes from $at to the closing `>`, respecting quotes.
	 *
	 * @param string[]    $found Filled with the lowercased attribute names.
	 * @param string|null $style Filled with the style attribute's value, when there is one.
	 * @return int|null The offset after `>`; null when the tag never closes.
	 */
	private static function read_attributes( string $html, int $at, int $length, ?array &$found, ?string &$style = null ): ?int {
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
			$attribute = strtolower( substr( $html, $at, $name_length ) );
			$found[]   = $attribute;
			$at       += $name_length;
			$at       += strspn( $html, " \t\r\n\f", $at );
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
				$value = substr( $html, $at + 1, $close - $at - 1 );
				$at    = $close + 1;
			} else {
				$span  = strcspn( $html, " \t\r\n\f>", $at );
				$value = substr( $html, $at, $span );
				$at   += $span;
			}
			if ( 'style' === $attribute && null === $style ) {
				$style = $value; // the first one counts, as in a browser
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
		// 1.21.0: declarations kses took out of style attributes, next to the CSS
		// a style block lost.
		$declarations = 'html' === $rule ? self::removed_declarations( $before, $after ) : [];
		if ( [] !== $declarations ) {
			$extra['removed_css'] = array_values( array_merge( (array) ( $extra['removed_css'] ?? [] ), $declarations ) );
		}
		$entry = [ 'path' => $path, 'rule' => $rule ] + $removed + $extra;

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
		// 1.21.1: a value kses rewrote without removing anything (single quotes become
		// double quotes) was reported with nothing in it.
		if ( 'html' === $rule && '' === $policy && [] === $removed['removed_tags'] && [] === $removed['removed_attributes'] && empty( $entry['removed_css'] ) ) {
			$notes[] = true === self::same_markup( $before, $after )
				? 'Nothing was removed: WordPress wrote the same HTML differently (attribute quotes, character references, attribute order or case).'
				: 'No tag or attribute was removed: WordPress rewrote part of the value, such as an attribute value or character references.';
		}
		// 1.21.0: where a page's CSS can go instead. set_page_css ships switched off, so
		// a client cannot see it in tools/list until an administrator turns it on.
		$style_lost = in_array( 'style', $removed['removed_tags'], true )
			|| [] !== preg_grep( '/^whole <style> block/', (array) ( $extra['removed_css'] ?? [] ) );
		if ( 'html' === $rule && $style_lost ) {
			$notes[] = self::page_css_enabled()
				? 'To give this page its own CSS, send it to set_page_css instead of a <style> block.'
				: 'set_page_css gives one page its own CSS, outside the widget. It is switched off on this site: an administrator turns it on under Settings > IATO MCP > Tools (Page assets).';
		}
		// Page scripts are the riskiest feature: named only where an administrator has
		// already turned both the tool and the setting on.
		if ( 'html' === $rule && in_array( 'script', $removed['removed_tags'], true ) && class_exists( 'IATO_MCP_Page_Script' ) && IATO_MCP_Page_Script::active() ) {
			$notes[] = 'Scripts never run inside a widget. set_page_script stores one for the page, which runs only after an administrator approves it in wp-admin.';
		}
		if ( [] !== $notes ) {
			$entry['note'] = implode( ' ', $notes );
		}
		return $entry;
	}

	/** Is set_page_css switched on? False when the settings are not loaded. */
	private static function page_css_enabled(): bool {
		return class_exists( 'IATO_MCP_Settings' ) && method_exists( 'IATO_MCP_Settings', 'is_tool_enabled' ) && IATO_MCP_Settings::is_tool_enabled( 'set_page_css' );
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
		return [] === $entry['removed_tags'] && [] === $entry['removed_attributes'] && empty( $entry['removed_css'] ) ? null : $entry;
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
					continue;
				}
				// 1.21.0: a second pass over the same value (Elementor's own kses for a user
				// without unfiltered_html) can remove more than the first; it used to be
				// dropped, so the report said nothing was removed.
				$added = [];
				foreach ( [ 'removed_tags', 'removed_attributes', 'removed_css' ] as $list ) {
					$new = array_values( array_diff( (array) ( $entry[ $list ] ?? [] ), (array) ( $out[ $key ][ $list ] ?? [] ) ) );
					if ( [] !== $new ) {
						$out[ $key ][ $list ] = array_values( array_merge( (array) ( $out[ $key ][ $list ] ?? [] ), $new ) );
						$added                = array_merge( $added, $new );
					}
				}
				if ( [] !== $added && 'elementor' === ( $entry['source'] ?? '' ) ) {
					$out[ $key ]['source'] = 'elementor';
					$out[ $key ]['note']   = trim( ( $out[ $key ]['note'] ?? '' ) . ' Elementor\'s own save removed: ' . implode( ', ', $added ) . '.' );
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
