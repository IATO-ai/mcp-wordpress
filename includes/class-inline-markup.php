<?php
/**
 * Inline SVG and <style> in Elementor HTML widgets, for administrators who opt in
 * (1.15.0, setting "Allow inline SVG and style blocks in HTML widgets for
 * administrators", default off).
 *
 * This does not grant unfiltered_html. The kses lock stays on for the whole MCP
 * request (IATO_MCP_Auth); the capability is only read, with the lock lifted, to
 * learn what the user holds outside MCP. When the setting is on and the user holds
 * both unfiltered_html and manage_options, the allowed tags are widened for one
 * value: the `html` setting of a classic Elementor `html` widget
 * (IATO_MCP_Elementor_Sanitizer decides that). Everything else, <script>, event
 * attributes, javascript: URLs and foreignObject included, is still removed.
 *
 * filter() never uses a regular expression to find style blocks: an attribute
 * value can hold the text "<style>", and treating that as a style block lets markup
 * bypass kses (<img alt="<style>" src=x onerror=alert(1) x="</style>"> survives a
 * regex extraction). WordPress' own tokenizer finds the real ones, which is why
 * this needs WordPress 6.5.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Inline_Markup {

	public const OPTION = 'iato_mcp_inline_markup_enabled';

	public const KEPT                   = 'kept';
	public const STRIPPED_SETTING_OFF   = 'stripped_setting_off';
	public const STRIPPED_NO_CAPABILITY = 'stripped_no_capability';
	public const STRIPPED_WP_VERSION    = 'stripped_wp_version';
	public const STRIPPED_UNSAFE        = 'stripped_unsafe';

	/**
	 * The SVG elements allowed, and for each the attributes. Lowercase: wp_kses
	 * compares lowercased names, so a `viewBox` key would strip the attribute.
	 * Never here: href, xlink:href (kses does not URL-check it, so it would pass
	 * javascript:), use, image, animate, set, foreignObject, feImage, event
	 * handlers.
	 */
	private const SVG_ELEMENTS = [
		'svg'      => [ 'xmlns', 'viewbox', 'width', 'height', 'x', 'y', 'preserveaspectratio', 'version' ],
		'g'        => [],
		'path'     => [ 'd', 'pathlength' ],
		'rect'     => [ 'x', 'y', 'width', 'height', 'rx', 'ry' ],
		'circle'   => [ 'cx', 'cy', 'r' ],
		'line'     => [ 'x1', 'y1', 'x2', 'y2' ],
		'polyline' => [ 'points' ],
		'polygon'  => [ 'points' ],
		'title'    => [],
	];

	/** Presentational attributes every SVG element may carry. */
	private const SVG_COMMON = [
		'class', 'id', 'style', 'role', 'aria-hidden', 'aria-label', 'aria-labelledby', 'focusable',
		'fill', 'fill-opacity', 'fill-rule', 'clip-rule',
		'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit', 'stroke-opacity',
		'opacity', 'transform', 'transform-origin', 'color', 'display', 'visibility', 'vector-effect', 'shape-rendering',
	];

	public static function is_enabled(): bool {
		$value = get_option( self::OPTION, '0' );
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), [ '', '0', 'false', 'no', 'off' ], true );
		}
		return (bool) $value;
	}

	/** May inline markup be kept in this request, and if not, why? */
	public static function policy(): string {
		if ( ! self::is_enabled() ) {
			return self::STRIPPED_SETTING_OFF;
		}
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || ! method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			return self::STRIPPED_WP_VERSION;
		}
		// Only reads what the user holds outside MCP: the lock denies this capability
		// to every MCP request. User 0 (an unbound site key) holds neither.
		$holds = IATO_MCP_Auth::write_unlocked( fn() => current_user_can( 'unfiltered_html' ) && current_user_can( 'manage_options' ) );
		return $holds ? self::KEPT : self::STRIPPED_NO_CAPABILITY;
	}

	public static function has_inline_markup( string $html ): bool {
		$tags = IATO_MCP_Sanitize_Report::inventory( $html )['tags'];
		return isset( $tags['style'] ) || isset( $tags['svg'] );
	}

	/** @return array<string,array<string,bool>> */
	public static function svg_allowlist(): array {
		$out = [];
		foreach ( self::SVG_ELEMENTS as $element => $attributes ) {
			$out[ $element ] = array_fill_keys( array_merge( $attributes, self::SVG_COMMON ), true );
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> Post tags plus the SVG subset. Not <style>: it never reaches kses. */
	private static function allowed_html(): array {
		return self::svg_allowlist() + (array) wp_kses_allowed_html( 'post' );
	}

	/**
	 * Keep <style> and the SVG subset, filter the rest as wp_kses_post does.
	 *
	 *   1. the tokenizer finds real STYLE elements; each body goes through
	 *      sanitize_css() and is replaced by a placeholder no caller can predict;
	 *   2. wp_kses runs over everything else;
	 *   3. the placeholders become style elements again, with a rebuilt opening tag;
	 *   4. the result is walked once more: any tag or attribute outside the allowed
	 *      lists fails the whole value, and the caller falls back to wp_kses_post.
	 *
	 * An unclosed <style> is not a STYLE token, so kses treats it like any other
	 * disallowed tag.
	 *
	 * @return array{ok:bool,html:string,kept_tags:string[],removed_css:string[]}
	 */
	/**
	 * Remove whole STYLE and SCRIPT elements, found with WordPress' own tokenizer
	 * (1.16.0). kses removes those tags and keeps what is between them, so CSS and
	 * JavaScript would otherwise show on the page as text. Run this before kses, never
	 * after: removing text from sanitised markup can join what is left into something
	 * live. An unclosed element stays for kses, because the tokenizer stops there.
	 * Below WordPress 6.5 there is no next_token(), and the value comes back unchanged.
	 */
	public static function remove_style_and_script( string $html ): string {
		if ( false === stripos( $html, '<style' ) && false === stripos( $html, '<script' ) ) {
			return $html;
		}
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || ! method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			return $html;
		}
		require_once __DIR__ . '/class-html-token-scanner.php';
		$scanner = new IATO_MCP_Html_Token_Scanner( $html );
		$out     = '';
		$at      = 0;
		while ( $scanner->next_token() ) {
			if ( '#tag' !== $scanner->get_token_type() || $scanner->is_tag_closer() || ! in_array( $scanner->get_tag(), [ 'STYLE', 'SCRIPT' ], true ) ) {
				continue;
			}
			$span = $scanner->token_span();
			if ( null === $span ) {
				return $html;
			}
			$out .= substr( $html, $at, $span[0] - $at );
			$at   = $span[0] + $span[1];
		}
		return $out . substr( $html, $at );
	}

	public static function filter( string $html ): array {
		$fail = [ 'ok' => false, 'html' => '', 'kept_tags' => [], 'removed_css' => [] ];

		require_once __DIR__ . '/class-html-token-scanner.php';
		$scanner = new IATO_MCP_Html_Token_Scanner( $html );
		$nonce   = bin2hex( random_bytes( 8 ) );
		$rest    = '';
		$at      = 0;
		$blocks  = [];
		$removed = [];
		while ( $scanner->next_token() ) {
			if ( '#tag' !== $scanner->get_token_type() || $scanner->is_tag_closer() || ! in_array( $scanner->get_tag(), [ 'STYLE', 'SCRIPT' ], true ) ) {
				continue;
			}
			$span = $scanner->token_span();
			if ( null === $span ) {
				return $fail;
			}
			// 1.16.0: a script goes whole; kses would leave its body as text.
			if ( 'SCRIPT' === $scanner->get_tag() ) {
				$rest .= substr( $html, $at, $span[0] - $at );
				$at    = $span[0] + $span[1];
				continue;
			}
			$css     = self::sanitize_css( $scanner->get_modifiable_text() );
			$removed = array_merge( $removed, $css['removed'] );
			$rest   .= substr( $html, $at, $span[0] - $at );
			$at      = $span[0] + $span[1];
			if ( null !== $css['css'] ) {
				$token            = 'IATOMCPSTYLE' . $nonce . 'N' . count( $blocks ) . 'X';
				$blocks[ $token ] = self::style_tag( $scanner->get_attribute( 'media' ) ) . $css['css'] . '</style>';
				$rest            .= $token;
			}
		}
		$rest .= substr( $html, $at );

		$out = (string) wp_kses( $rest, self::allowed_html() );
		foreach ( $blocks as $token => $block ) {
			if ( 1 !== substr_count( $out, $token ) ) {
				return $fail;
			}
			$out = str_replace( $token, $block, $out );
		}
		if ( ! self::passes_final_check( $out, count( $blocks ) ) ) {
			return $fail;
		}

		$kept = array_values( array_intersect( array_keys( IATO_MCP_Sanitize_Report::inventory( $out )['tags'] ), array_merge( [ 'style' ], array_keys( self::SVG_ELEMENTS ) ) ) );
		return [ 'ok' => true, 'html' => $out, 'kept_tags' => array_map( 'strval', $kept ), 'removed_css' => $removed ];
	}

	/** @param mixed $media The opening tag's media attribute. */
	private static function style_tag( mixed $media ): string {
		if ( is_string( $media ) && 1 === preg_match( '/^[a-zA-Z0-9 ,:().\-]+$/', $media ) ) {
			return '<style media="' . $media . '">';
		}
		return '<style>';
	}

	/**
	 * The last word on what is stored: every token of the final string, read by
	 * WordPress' tokenizer, must be allowed. SVG paint and clip attributes may only
	 * point inside the document: url(https://...) there would fetch off-site. No style
	 * attribute on any tag may name another site either: a kept SVG can reach it from
	 * an ancestor, <div style="--x:url(https://...)"> with fill="var(--x)".
	 */
	private static function passes_final_check( string $html, int $style_blocks ): bool {
		$allowed = self::allowed_html();
		$svg     = self::svg_allowlist();
		$walker  = new WP_HTML_Tag_Processor( $html );
		$styles  = 0;
		while ( $walker->next_token() ) {
			$type = $walker->get_token_type();
			if ( '#tag' !== $type ) {
				if ( ! in_array( $type, [ '#text', '#comment' ], true ) ) {
					return false;
				}
				continue;
			}
			if ( $walker->is_tag_closer() ) {
				continue;
			}
			$tag   = strtolower( (string) $walker->get_tag() );
			$names = (array) $walker->get_attribute_names_with_prefix( '' );
			if ( 'style' === $tag ) {
				++$styles;
				if ( array_diff( $names, [ 'media' ] ) ) {
					return false;
				}
				continue;
			}
			if ( ! isset( $allowed[ $tag ] ) ) {
				return false;
			}
			foreach ( $names as $name ) {
				$name = (string) $name;
				if ( ! isset( $allowed[ $tag ][ $name ] ) && ! ( str_starts_with( $name, 'data-' ) && isset( $allowed[ $tag ]['data-*'] ) ) ) {
					return false;
				}
				$value = $walker->get_attribute( $name );
				// Decoded first: the browser reads fill="u\72l(https://...)" as url().
				if ( ! is_string( $value ) ) {
					continue;
				}
				$decoded = self::decode_escapes( $value );
				if ( isset( $svg[ $tag ] ) && 1 === preg_match( '/url\(\s*[\'"]?\s*(?!#)/i', $decoded ) ) {
					return false;
				}
				if ( 'style' === $name && self::style_attribute_leaves_site( $value ) ) {
					return false;
				}
			}
		}
		return ! $walker->paused_at_incomplete_token() && $styles === $style_blocks;
	}

	/**
	 * Filter a style element's body. @import and anything that loads from another
	 * site are removed and listed; everything else stays.
	 *
	 * This is a tokenizer, not a set of regular expressions: text that only looks
	 * like a reference has to be told apart from the real thing, and a regex cannot
	 * (`--x:"url(";background:url(https://evil.example/)` hides the second url() from
	 * one, and deleting `@import x;` from `@im@import x;port "…"` assembles a new
	 * @import). The result is then read again, naively as well as with the tokenizer.
	 * Anything that does not add up drops the whole block: css is null. So do bodies
	 * that could break out of the element, run script in an old browser, or use a
	 * character reference, which a browser decodes in a style element inside <svg>.
	 *
	 * @return array{css:?string,removed:string[]}
	 */
	public static function sanitize_css( string $css ): array {
		$drop = fn( string $why ) => [ 'css' => null, 'removed' => [ "whole <style> block: {$why}" ] ];

		// Invalid UTF-8 and control characters: tokenizers disagree on them.
		if ( 1 !== preg_match( '//u', $css ) || 1 === preg_match( '/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $css ) ) {
			return $drop( 'it contains invalid characters' );
		}
		$tokens = self::css_tokens( $css );
		if ( null === $tokens ) {
			return $drop( 'it could not be read (an unterminated string, url() or comment)' );
		}
		$raw_scan = preg_replace( '#/\*.*?\*/#s', '', $css );
		if ( ! is_string( $raw_scan ) ) {
			return $drop( 'it could not be read' );
		}
		$hazard = self::css_hazard( $raw_scan );
		if ( null !== $hazard ) {
			return $drop( "it contains {$hazard}" );
		}
		$raw_lower = strtolower( $raw_scan );
		$decoded   = strtolower( self::decode_escapes( $raw_scan ) );
		foreach ( [ '@import', 'url(', 'image-set(' ] as $needle ) {
			if ( substr_count( $decoded, $needle ) > substr_count( $raw_lower, $needle ) ) {
				return $drop( "{$needle} hidden behind CSS escapes" );
			}
		}

		$removed = [];
		$out     = self::rewrite_css( $tokens, $removed );
		if ( null === $out ) {
			return $drop( 'it could not be read (an unclosed function)' );
		}
		$out = trim( $out );
		if ( '' === $out ) {
			return [ 'css' => null, 'removed' => $removed ];
		}
		$why = self::css_final_check( $out );
		if ( null !== $why ) {
			return [ 'css' => null, 'removed' => array_merge( $removed, [ "whole <style> block: {$why}" ] ) ];
		}
		return [ 'css' => $out, 'removed' => $removed ];
	}

	/** What in this CSS means the block cannot be kept, or null. */
	private static function css_hazard( string $css ): ?string {
		$hazards = [
			'<'                     => '/</',
			'a character reference' => '/&[#a-z]/i',
			'expression('           => '/expression\s*\(/i',
			'behavior'              => '/(?<![-\w])behavior\s*:/i',
			'-moz-binding'          => '/-moz-binding/i',
			'javascript:'           => '/javascript\s*:/i',
			'vbscript:'             => '/vbscript\s*:/i',
		];
		$decoded = self::decode_escapes( $css );
		foreach ( $hazards as $label => $pattern ) {
			if ( 1 === preg_match( $pattern, $css ) || 1 === preg_match( $pattern, $decoded ) ) {
				return $label;
			}
		}
		return null;
	}

	/**
	 * Read the result again. The tokens and a plain text search of the decoded CSS
	 * have to agree, so a block fooling this tokenizer has to fool the search too.
	 */
	private static function css_final_check( string $css ): ?string {
		$tokens = self::css_tokens( $css );
		if ( null === $tokens ) {
			return 'it could not be read after filtering';
		}
		$hazard = self::css_hazard( $css );
		if ( null !== $hazard ) {
			return "it contains {$hazard}";
		}
		$scan = strtolower( self::decode_escapes( $css ) );
		if ( str_contains( $scan, '@import' ) ) {
			return 'it would still hold @import';
		}
		if ( self::text_leaves_site( $scan ) ) {
			return 'it still names another site';
		}
		$urls = 0;
		foreach ( $tokens as $token ) {
			$urls += 'url' === $token['t'] || ( 'function' === $token['t'] && 'url' === $token['v'] ) ? 1 : 0;
		}
		if ( substr_count( $scan, 'url(' ) !== $urls ) {
			return 'the text url( appears where no url() can be, which is how a reference gets hidden';
		}
		return null;
	}

	/**
	 * Does this text name another site anywhere, however it is read? Knows nothing of
	 * CSS syntax, on purpose: three reviews each found a way to make the tokenizer and
	 * the browser disagree about where a string ends, and a block that fools the
	 * tokenizer still has to get past this. Strict by design, so it also catches a URL in
	 * an attribute selector or in text. Run it on decoded text.
	 *
	 * URL parsers drop tabs and newlines, so whitespace goes first. Where a URL can begin,
	 * what follows up to the first / ? # or backslash, where a browser ends the host,
	 * must be this site's host exactly. Nothing here parses a URL: parse_url() reading a
	 * loosely cut string is how a review got `//evil.example);--a:@localhost/` past an
	 * earlier version, as a host of localhost.
	 *
	 * Passes: base64 images, whose alphabet holds slashes but no quote or parenthesis,
	 * which is what would have to stand before a real URL; `a[href^="https://"]`, which
	 * names nothing; SVG's namespaces, in nearly every inline SVG image. A slash pair
	 * after a name character is a path, `/img//a.png`, and `.btn-http:hover` is a selector.
	 * `///` is not exempt: browsers read it as `//`.
	 */
	private static function text_leaves_site( string $text ): bool {
		$compact = preg_replace( '/[\x00-\x20]/', '', $text );
		$compact = is_string( $compact ) ? preg_replace( '~data:image/[a-z0-9.+\-]+;base64,[a-z0-9+/=]*~i', 'data:', $compact ) : null;
		if ( ! is_string( $compact ) ) {
			return true;
		}
		// One match at a time: this stops at the first answer, and a value of nothing but
		// slashes costs no memory.
		$pattern = '~(?<![a-z0-9_\-.%\~])(?:https?:)?[/\\\\]{2}|(?<![a-z0-9_\-.#])(?:https?|ftps?|gopher|wss?|file):~i';
		$at      = 0;
		while ( 1 === ( $hit = preg_match( $pattern, $compact, $found, PREG_OFFSET_CAPTURE, $at ) ) ) {
			[ $match, $offset ] = $found[0];
			$start              = $offset + strlen( $match );
			$at                 = $start;
			if ( ! preg_match( '~^(?:https?:)?(?:[/\\\\]{2})?$~i', $match ) ) {
				return true; // another scheme
			}
			if ( 1 === preg_match( '~\G["\']\]~', $compact, $unused, 0, $start ) ) {
				continue; // a[href^="https://"] names nothing
			}
			if ( 1 === preg_match( '~\Gwww\.w3\.org/(?:2000/svg|1999/xlink|1999/xhtml)~i', $compact, $unused, 0, $start ) ) {
				continue;
			}
			$authority = substr( $compact, $start, strcspn( $compact, '/?#\\', $start ) );
			if ( ! self::is_own_host( (string) preg_replace( '/:[0-9]*$/', '', $authority ) ) ) {
				return true;
			}
		}
		if ( 0 !== $hit ) {
			return true; // the search itself failed
		}
		return false;
	}

	/**
	 * Would this style attribute load from another site? The tokenizer's reading and
	 * the plain search both have to clear it. The tokenizer knows url('ftp:...') is a
	 * URL in any scheme; the plain search does not have to know CSS.
	 */
	private static function style_attribute_leaves_site( string $value ): bool {
		$tokens = self::css_tokens( $value );
		if ( null === $tokens ) {
			return true;
		}
		$removed = [];
		if ( null === self::rewrite_css( $tokens, $removed ) || [] !== $removed ) {
			return true;
		}
		return self::text_leaves_site( self::decode_escapes( $value ) );
	}

	/**
	 * Remove @import and what leaves the site from a token list.
	 *
	 * @param array<int,array{t:string,raw:string,v:string}> $tokens
	 * @param string[]                                       $removed
	 * @return string|null null for a function that never closes.
	 */
	private static function rewrite_css( array $tokens, array &$removed ): ?string {
		$out     = '';
		$count   = count( $tokens );
		$bracket = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			switch ( $token['t'] ) {
				case 'at':
					if ( 'import' !== $token['v'] ) {
						$out .= $token['raw'];
						break;
					}
					$text = '';
					for ( ; $i < $count; $i++ ) {
						$text .= $tokens[ $i ]['raw'];
						if ( ';' === $tokens[ $i ]['raw'] ) {
							break;
						}
					}
					$removed[] = trim( $text );
					$out      .= ' '; // never join what stood on either side of it
					break;

				case 'url':
					if ( self::url_stays_on_site( $token['v'] ) ) {
						$out .= $token['raw'];
					} else {
						$removed[] = $token['raw'];
						$out      .= 'none';
					}
					break;

				case 'function':
					$close = self::closing_paren( $tokens, $i );
					if ( null === $close ) {
						return null;
					}
					$text = implode( '', array_column( array_slice( $tokens, $i, $close - $i + 1 ), 'raw' ) );
					if ( self::function_leaves_site( $token['v'], array_slice( $tokens, $i + 1, $close - $i - 1 ) ) ) {
						$removed[] = $text;
						$out      .= 'none';
					} else {
						$out .= $text;
					}
					$i = $close;
					break;

				case 'string':
					// A string in an attribute selector is only compared, never fetched.
					if ( 0 === $bracket && self::string_is_off_site_url( $token['v'] ) ) {
						$removed[] = $token['raw'];
						$out      .= '""';
					} else {
						$out .= $token['raw'];
					}
					break;

				default:
					if ( '[' === $token['raw'] ) {
						++$bracket;
					} elseif ( ']' === $token['raw'] ) {
						$bracket = max( 0, $bracket - 1 );
					}
					$out .= $token['raw'];
			}
		}
		return $out;
	}

	/** The index of the `)` closing the function token at $open, or null. */
	private static function closing_paren( array $tokens, int $open ): ?int {
		$depth = 0;
		$count = count( $tokens );
		for ( $i = $open; $i < $count; $i++ ) {
			if ( 'function' === $tokens[ $i ]['t'] || '(' === $tokens[ $i ]['raw'] ) {
				++$depth;
			} elseif ( ')' === $tokens[ $i ]['raw'] && --$depth <= 0 ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Does anything inside this function load from another site? In a function that
	 * takes an image or a URL, every string counts as a URL, and any nested function
	 * except url() and the gradients counts as one too: var(), attr() and if() can
	 * hand it a value nothing here can see. The same holds when such a function sits
	 * anywhere inside this one: var(--n, image-set(...)) is just as much an image.
	 *
	 * @param string                                         $name  Function name, lowercase.
	 * @param array<int,array{t:string,raw:string,v:string}> $inner The tokens between its parentheses.
	 */
	private static function function_leaves_site( string $name, array $inner ): bool {
		$url_function = '/^(?:url|src|image|(?:-webkit-)?image-set|(?:-webkit-)?cross-fade|(?:-moz-)?element|paint)$/';
		$takes_url    = 1 === preg_match( $url_function, $name );
		foreach ( $inner as $token ) {
			if ( 'function' === $token['t'] && 1 === preg_match( $url_function, $token['v'] ) ) {
				$takes_url = true;
			}
		}
		foreach ( $inner as $token ) {
			switch ( $token['t'] ) {
				case 'at':
					return true;
				case 'url':
					if ( ! self::url_stays_on_site( $token['v'] ) ) {
						return true;
					}
					break;
				case 'string':
					if ( $takes_url ? ! self::url_stays_on_site( $token['v'] ) : self::string_is_off_site_url( $token['v'] ) ) {
						return true;
					}
					break;
				case 'function':
					if ( $takes_url && 1 !== preg_match( '/^(?:url|(?:repeating-)?(?:linear|radial|conic)-gradient|rgba?|hsla?)$/', $token['v'] ) ) {
						return true;
					}
					break;
			}
		}
		return false;
	}

	/** Is this string a URL to another site? Plain text such as "Note: x" is not. */
	private static function string_is_off_site_url( string $value ): bool {
		$compact = (string) preg_replace( '/[\x00-\x20]/', '', $value );
		return 1 === preg_match( '#^(?:https?:)?[/\\\\]{2}#i', $compact ) && ! self::url_stays_on_site( $value );
	}

	/**
	 * Tokens of a CSS body, following CSS Syntax Level 3 where it matters here:
	 * strings, url(), functions, at-keywords, identifiers with their escapes decoded.
	 * Comments become whitespace. A number takes its unit and a # its name, so 1url( and
	 * #url( are not url(). Other punctuation comes through a byte at a
	 * time. Null for what a browser would repair but this will not guess at: an
	 * unterminated string, comment or url(), and a bad url().
	 *
	 * @return array<int,array{t:string,raw:string,v:string}>|null
	 */
	private static function css_tokens( string $css ): ?array {
		$out = [];
		$n   = strlen( $css );
		$i   = 0;
		while ( $i < $n ) {
			$c = $css[ $i ];
			if ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$out[] = [ 't' => 'ws', 'raw' => ' ', 'v' => ' ' ];
				$i     = $end + 2;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$string = self::css_string( $css, $i );
				if ( null === $string ) {
					return null;
				}
				$out[] = $string;
				continue;
			}
			if ( ctype_digit( $c ) || ( '.' === $c && ctype_digit( $css[ $i + 1 ] ?? '' ) ) ) {
				$from = $i;
				preg_match( '/\G[0-9]*\.?[0-9]+(?:[eE][+-]?[0-9]+)?/', $css, $number, 0, $i );
				$i += strlen( $number[0] );
				if ( self::starts_name( $css, $i ) ) {
					self::css_name( $css, $i );
				}
				$out[] = [ 't' => 'number', 'raw' => substr( $css, $from, $i - $from ), 'v' => '' ];
				continue;
			}
			if ( '#' === $c && ( 1 === preg_match( '/\G[a-zA-Z0-9_\-\x80-\xff]/', $css, $unused, 0, $i + 1 ) || self::valid_escape( $css, $i + 1 ) ) ) {
				$from = $i;
				++$i;
				self::css_name( $css, $i );
				$out[] = [ 't' => 'hash', 'raw' => substr( $css, $from, $i - $from ), 'v' => '' ];
				continue;
			}
			if ( '@' === $c && self::starts_name( $css, $i + 1 ) ) {
				$from  = $i;
				++$i;
				$name  = self::css_name( $css, $i );
				$out[] = [ 't' => 'at', 'raw' => substr( $css, $from, $i - $from ), 'v' => strtolower( $name ) ];
				continue;
			}
			if ( self::starts_name( $css, $i ) ) {
				$from = $i;
				$name = strtolower( self::css_name( $css, $i ) );
				if ( '(' !== ( $css[ $i ] ?? '' ) ) {
					$out[] = [ 't' => 'ident', 'raw' => substr( $css, $from, $i - $from ), 'v' => $name ];
					continue;
				}
				++$i;
				if ( 'url' === $name ) {
					$at = $i + strspn( $css, " \t\r\n\f", $i );
					if ( ! in_array( $css[ $at ] ?? '', [ '"', "'" ], true ) ) {
						$url = self::css_unquoted_url( $css, $i );
						if ( null === $url ) {
							return null;
						}
						$out[] = [ 't' => 'url', 'raw' => substr( $css, $from, $i - $from ), 'v' => $url ];
						continue;
					}
				}
				$out[] = [ 't' => 'function', 'raw' => substr( $css, $from, $i - $from ), 'v' => $name ];
				continue;
			}
			$out[] = [ 't' => 'delim', 'raw' => $c, 'v' => $c ];
			++$i;
		}
		return $out;
	}

	/** A quoted string from $i, leaving $i after it; null when it never closes or a newline breaks it. */
	private static function css_string( string $css, int &$i ): ?array {
		$from  = $i;
		$quote = $css[ $i ];
		$value = '';
		$n     = strlen( $css );
		++$i;
		while ( $i < $n ) {
			$c = $css[ $i ];
			if ( $c === $quote ) {
				++$i;
				return [ 't' => 'string', 'raw' => substr( $css, $from, $i - $from ), 'v' => $value ];
			}
			if ( "\n" === $c || "\r" === $c || "\f" === $c ) {
				return null;
			}
			if ( '\\' === $c ) {
				$next = $css[ $i + 1 ] ?? '';
				if ( '' === $next ) {
					return null;
				}
				if ( "\n" === $next || "\r" === $next || "\f" === $next ) {
					$i += 2;
					continue;
				}
				$value .= self::css_escape( $css, $i );
				continue;
			}
			$value .= $c;
			++$i;
		}
		return null;
	}

	/** An unquoted url() body from $i, leaving $i after the `)`; null for a bad or unterminated one. */
	private static function css_unquoted_url( string $css, int &$i ): ?string {
		$value = '';
		$n     = strlen( $css );
		$i    += strspn( $css, " \t\r\n\f", $i );
		while ( $i < $n ) {
			$c = $css[ $i ];
			if ( ')' === $c ) {
				++$i;
				return $value;
			}
			if ( false !== strpos( " \t\r\n\f", $c ) ) {
				$i += strspn( $css, " \t\r\n\f", $i );
				if ( ')' !== ( $css[ $i ] ?? '' ) ) {
					return null;
				}
				continue;
			}
			if ( '"' === $c || "'" === $c || '(' === $c ) {
				return null;
			}
			if ( '\\' === $c ) {
				if ( ! self::valid_escape( $css, $i ) ) {
					return null;
				}
				$value .= self::css_escape( $css, $i );
				continue;
			}
			$value .= $c;
			++$i;
		}
		return null;
	}

	/** Would an identifier start at $i? */
	private static function starts_name( string $css, int $i ): bool {
		$c = $css[ $i ] ?? '';
		if ( '-' === $c ) {
			$next = $css[ $i + 1 ] ?? '';
			return '-' === $next || self::is_name_start( $next ) || self::valid_escape( $css, $i + 1 );
		}
		return self::is_name_start( $c ) || self::valid_escape( $css, $i );
	}

	private static function is_name_start( string $c ): bool {
		return '' !== $c && ( ctype_alpha( $c ) || '_' === $c || ord( $c ) >= 0x80 );
	}

	/** Is there a backslash at $i that begins an escape (not one before a newline or the end)? */
	private static function valid_escape( string $css, int $i ): bool {
		$next = $css[ $i + 1 ] ?? '';
		return '\\' === ( $css[ $i ] ?? '' ) && '' !== $next && "\n" !== $next && "\r" !== $next && "\f" !== $next;
	}

	/** An identifier from $i, escapes decoded, leaving $i after it. */
	private static function css_name( string $css, int &$i ): string {
		$name = '';
		$n    = strlen( $css );
		while ( $i < $n ) {
			$c = $css[ $i ];
			if ( ctype_alnum( $c ) || '_' === $c || '-' === $c || ord( $c ) >= 0x80 ) {
				$name .= $c;
				++$i;
			} elseif ( self::valid_escape( $css, $i ) ) {
				$name .= self::css_escape( $css, $i );
			} else {
				break;
			}
		}
		return $name;
	}

	/** The escape at $i decoded, leaving $i after it. */
	private static function css_escape( string $css, int &$i ): string {
		if ( 1 === preg_match( '/\G\\\\([0-9a-fA-F]{1,6})(?:\r\n|[ \t\r\n\f])?/', $css, $m, 0, $i ) ) {
			$i += strlen( $m[0] );
			return self::code_point( (int) hexdec( $m[1] ) );
		}
		// One whole character. sanitize_css() has already refused invalid UTF-8.
		$lead = ord( $css[ $i + 1 ] ?? "\0" );
		$char = substr( $css, $i + 1, $lead >= 0xF0 ? 4 : ( $lead >= 0xE0 ? 3 : ( $lead >= 0xC0 ? 2 : 1 ) ) );
		$i   += 1 + strlen( $char );
		return $char;
	}

	private static function code_point( int $code ): string {
		if ( $code <= 0 || $code > 0x10FFFF || ( $code >= 0xD800 && $code <= 0xDFFF ) ) {
			return "\u{FFFD}";
		}
		if ( $code < 0x80 ) {
			return chr( $code );
		}
		if ( $code < 0x800 ) {
			return chr( 0xC0 | $code >> 6 ) . chr( 0x80 | $code & 0x3F );
		}
		if ( $code < 0x10000 ) {
			return chr( 0xE0 | $code >> 12 ) . chr( 0x80 | $code >> 6 & 0x3F ) . chr( 0x80 | $code & 0x3F );
		}
		return chr( 0xF0 | $code >> 18 ) . chr( 0x80 | $code >> 12 & 0x3F ) . chr( 0x80 | $code >> 6 & 0x3F ) . chr( 0x80 | $code & 0x3F );
	}

	/** Undo CSS escapes (\40 and \@ are both @), to see what the browser would see. */
	private static function decode_escapes( string $css ): string {
		$out = preg_replace_callback(
			'/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/s',
			fn( array $m ): string => '' !== $m[1] ? self::code_point( (int) hexdec( $m[1] ) ) : $m[2],
			$css
		);
		return is_string( $out ) ? $out : $css;
	}

	/** Does this url() target stay on the site: a fragment, a relative path, this host, or an inline image? */
	private static function url_stays_on_site( string $target ): bool {
		// URL parsers drop whitespace and read a backslash as a slash, so "/\evil.example"
		// is "//evil.example".
		$target = (string) preg_replace( '/[\x00-\x20]/', '', $target );
		if ( '' === $target || '#' === $target[0] ) {
			return true;
		}
		if ( str_contains( $target, '\\' ) ) {
			return false;
		}
		if ( 1 === preg_match( '#^data:image/#i', $target ) ) {
			return true;
		}
		if ( str_starts_with( $target, '//' ) ) {
			return self::is_own_host( (string) parse_url( 'https:' . $target, PHP_URL_HOST ) );
		}
		if ( 1 === preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $target, $scheme ) ) {
			return in_array( strtolower( $scheme[1] ), [ 'http', 'https' ], true )
				&& self::is_own_host( (string) parse_url( $target, PHP_URL_HOST ) );
		}
		return true;
	}

	private static function is_own_host( string $host ): bool {
		$host = (string) preg_replace( '/^www\./', '', strtolower( $host ) );
		if ( '' === $host ) {
			return false;
		}
		foreach ( [ home_url( '/' ), site_url( '/' ) ] as $own ) {
			if ( $host === (string) preg_replace( '/^www\./', '', strtolower( (string) parse_url( $own, PHP_URL_HOST ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	// ── post_content ──────────────────────────────────────────────────────

	/**
	 * Run a write that renders the page into post_content without the style
	 * blocks. Elementor's plain-text render keeps <style>, and the content kses
	 * filter that stays on for the request then leaves the CSS as text in
	 * post_content (search, feeds, excerpts). Priority 9: before kses. Whenever the
	 * tree holds a style block, not only when the opt-in applies: one stored earlier
	 * stays in the tree after the setting is turned off or another user edits the page.
	 *
	 * @param string   $encoded_tree The tree being written, as JSON.
	 * @param callable $write        The write.
	 */
	public static function write_without_style_in_content( string $encoded_tree, callable $write ): mixed {
		if ( false === stripos( $encoded_tree, '<style' ) ) {
			return $write();
		}
		add_filter( 'content_save_pre', [ self::class, 'strip_style_blocks' ], 9 );
		try {
			return $write();
		} finally {
			remove_filter( 'content_save_pre', [ self::class, 'strip_style_blocks' ], 9 );
		}
	}

	/** @param mixed $content post_content as it is being saved (slashed). */
	public static function strip_style_blocks( mixed $content ): mixed {
		if ( ! is_string( $content ) ) {
			return $content;
		}
		$out = preg_replace( '#<style\b[^>]*>.*?</style\s*>#is', '', $content );
		return is_string( $out ) ? $out : $content;
	}
}
