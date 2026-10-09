<?php
/**
 * Sanitising pass for Elementor element trees written by the plugin.
 *
 * Elementor filters a page it saves for a user without unfiltered_html by
 * running wp_kses_post over every string in the document (Document::save(),
 * Utils::kses_post_deep). This pass, run on the plugin's own writes, is
 * narrower on purpose:
 *
 *   - only values the request changed are touched: each leaf is compared with
 *     the stored tree at the same path (element id + setting path) and kept
 *     verbatim when identical, so an edit to one widget never alters HTML a
 *     human put in another one;
 *   - values are sanitised by shape, not by widget name, so unknown and addon
 *     widgets follow the same rules:
 *       URL fields (`url`, `destination`, `href`, `*_url`)   esc_url_raw
 *         (drops javascript: and other disallowed schemes, keeps & in queries;
 *         what Elementor's Url_Prop_Type::sanitize_value() does for atomic links)
 *       custom_css (classic / page settings; atomic `custom_css.raw`, base64)
 *                                                           sanitize_textarea_field
 *         (what Elementor's Style_Parser::sanitize_custom_css() does; keeps
 *         `>` selectors, removes tags and `</style><script>` sequences)
 *       strings containing `<`                               wp_kses_post
 *       everything else (plain text, numbers, booleans)      unchanged
 *         (wp_kses_post would turn & into &amp;, which double-encodes in
 *         widgets that escape on output, e.g. the heading's wp_kses_post())
 *
 * The three WordPress functions are injectable so the pass is unit-tested
 * without WordPress; the real behaviour is covered by the live suite.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Elementor_Sanitizer {

	/** Reported changed-path list is capped at this many entries. */
	public const MAX_PATHS = 50;

	/** Setting keys (last path segment) whose string value is a URL. */
	private const URL_KEYS = [ 'url', 'destination', 'href', 'link_url', 'image_url', 'video_url', 'src_url' ];

	/** @var array{kses:callable,url:callable,css:callable}|null test seam */
	private static ?array $functions = null;

	/** @var int bumped on each cap hit, for the "and N more" note */
	private static int $overflow = 0;

	/**
	 * Test seam: replace the WordPress sanitising functions. null restores them.
	 *
	 * @param array{kses?:callable,url?:callable,css?:callable}|null $functions
	 */
	public static function set_functions( ?array $functions ): void {
		self::$functions = $functions;
	}

	/** @return array{kses:callable,url:callable,css:callable} */
	private static function functions(): array {
		$defaults = [
			'kses' => 'wp_kses_post',
			'url'  => 'esc_url_raw',
			'css'  => 'sanitize_textarea_field',
		];
		return array_merge( $defaults, self::$functions ?? [] );
	}

	// ── Value rules ───────────────────────────────────────────────────────

	/**
	 * Sanitise one leaf value by shape. $path is the list of keys from the
	 * element's settings (or styles) root down to this leaf.
	 *
	 * @param string[] $path
	 */
	public static function sanitize_value( mixed $value, array $path, string $type_hint = '' ): mixed {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$fns  = self::functions();
		$key  = (string) end( $path );
		$prev = count( $path ) > 1 ? (string) $path[ count( $path ) - 2 ] : '';

		// Atomic envelope { $$type: 'url', value: '...' }: the type says it is a URL.
		if ( 'url' === $type_hint ) {
			return (string) $fns['url']( $value );
		}

		if ( 'custom_css' === $key ) {
			return (string) $fns['css']( $value );
		}
		if ( 'raw' === $key && 'custom_css' === $prev ) {
			// Atomic styles store the CSS base64-encoded (Elementor Utils::encode_string):
			// decode strictly, sanitise the CSS, re-encode canonically. A value
			// that does not decode is dropped, as Elementor's Style_Parser does.
			$decoded = base64_decode( $value, true );
			if ( false === $decoded ) {
				return '';
			}
			return base64_encode( (string) $fns['css']( $decoded ) );
		}
		if ( self::is_url_key( $key ) ) {
			return (string) $fns['url']( $value );
		}
		if ( str_contains( $value, '<' ) ) {
			return (string) $fns['kses']( $value );
		}
		return $value;
	}

	public static function is_url_key( string $key ): bool {
		$key = strtolower( $key );
		return in_array( $key, self::URL_KEYS, true ) || str_ends_with( $key, '_url' );
	}

	// ── Diff + sanitise ───────────────────────────────────────────────────

	/**
	 * Sanitise the values of $new that differ from $stored, element by element
	 * (matched by id; a new element has no stored counterpart, so all of its
	 * values are new). Every key of an element is diffed (`settings`,
	 * `styles`, and anything else a client sends), except `elements`, which
	 * is recursed into; a non-array entry in an element list is a leaf too.
	 *
	 * @param array<int,array> $new    the tree about to be written
	 * @param array<int,array> $stored the tree currently stored (null = nothing stored)
	 * @return array{elements:array,changed:string[]}  changed = "<id>/settings/<path>" entries, capped
	 */
	public static function diff_tree( array $new, ?array $stored ): array {
		self::$overflow = 0;
		$stored_by_id   = [];
		if ( null !== $stored ) {
			self::index_by_id( $stored, $stored_by_id );
		}
		$changed = [];
		$out     = self::walk( $new, $stored_by_id, $changed );
		if ( self::$overflow > 0 ) {
			$changed[] = sprintf( '… and %d more', self::$overflow );
		}
		return [ 'elements' => $out, 'changed' => $changed ];
	}

	/**
	 * Diff + sanitise a settings-shaped array (e.g. `_elementor_page_settings`).
	 *
	 * @return array{value:array,changed:string[]}
	 */
	public static function diff_settings( array $new, ?array $stored, string $label = 'settings' ): array {
		self::$overflow = 0;
		$changed        = [];
		$out            = self::diff_value( $new, $stored, [ $label ], $changed );
		if ( self::$overflow > 0 ) {
			$changed[] = sprintf( '… and %d more', self::$overflow );
		}
		return [ 'value' => is_array( $out ) ? $out : $new, 'changed' => $changed ];
	}

	/**
	 * What diff_tree() would report, for dry runs.
	 *
	 * @return array{sanitized:bool,sanitized_paths?:string[]}
	 */
	public static function preview( array $new, ?array $stored ): array {
		$r = self::diff_tree( $new, $stored );
		return self::report( $r['changed'] );
	}

	/** @return array{sanitized:bool,sanitized_paths?:string[]} */
	public static function report( array $changed ): array {
		if ( empty( $changed ) ) {
			return [ 'sanitized' => false ];
		}
		return [ 'sanitized' => true, 'sanitized_paths' => $changed ];
	}

	private static function index_by_id( array $elements, array &$map ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) && is_scalar( $el['id'] ) ) {
				$map[ (string) $el['id'] ] = $el;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::index_by_id( $el['elements'], $map );
			}
		}
	}

	/** Do two elements share elType and widgetType (both compared as strings, missing = '')? */
	public static function same_type( array $a, array $b ): bool {
		foreach ( [ 'elType', 'widgetType' ] as $key ) {
			if ( (string) ( $a[ $key ] ?? '' ) !== (string) ( $b[ $key ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param string[] $changed */
	private static function walk( array $elements, array $stored_by_id, array &$changed ): array {
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				// Not an element, but it is stored: a leaf with no stored counterpart.
				$clean = self::sanitize_value( $el, [ '#' . $i ] );
				if ( $clean !== $el ) {
					self::note_change( [ '#' . $i ], $changed );
				}
				$elements[ $i ] = $clean;
				continue;
			}
			$id     = isset( $el['id'] ) && is_scalar( $el['id'] ) ? (string) $el['id'] : '#' . $i;
			$stored = $stored_by_id[ $id ] ?? null;
			// Same id but another widget or element type: every setting counts as
			// new. A value kept verbatim could otherwise move from a field the
			// old widget escapes on output into one the new widget renders raw.
			if ( is_array( $stored ) && ! self::same_type( $el, $stored ) ) {
				$stored = null;
			}
			foreach ( $el as $key => $value ) {
				if ( 'elements' === $key && is_array( $value ) ) {
					continue; // children: recursed below
				}
				$stored_value = ( is_array( $stored ) && array_key_exists( $key, $stored ) ) ? $stored[ $key ] : null;
				$el[ $key ]   = self::diff_value( $value, $stored_value, [ $id, (string) $key ], $changed );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::walk( $el['elements'], $stored_by_id, $changed );
			}
			$elements[ $i ] = $el;
		}
		return $elements;
	}

	/** @param string[] $changed */
	private static function note_change( array $path, array &$changed ): void {
		if ( count( $changed ) < self::MAX_PATHS ) {
			$changed[] = implode( '/', $path );
		} else {
			++self::$overflow;
		}
	}

	/**
	 * Recursive compare-and-sanitise. Identical subtrees are returned as-is;
	 * leaves that differ from the stored value go through sanitize_value().
	 *
	 * @param string[] $path     keys from the element root (first entry is the element id / label)
	 * @param string[] $changed  collects "id/section/key/..." for every altered leaf
	 */
	private static function diff_value( mixed $new, mixed $stored, array $path, array &$changed, string $type_hint = '' ): mixed {
		if ( $new === $stored ) {
			return $new;
		}
		if ( is_array( $new ) ) {
			$envelope = isset( $new['$$type'] ) && is_string( $new['$$type'] ) ? $new['$$type'] : '';
			foreach ( $new as $k => $v ) {
				$sub       = ( is_array( $stored ) && array_key_exists( $k, $stored ) ) ? $stored[ $k ] : null;
				$hint      = ( '' !== $envelope && 'value' === (string) $k ) ? $envelope : '';
				$new[ $k ] = self::diff_value( $v, $sub, array_merge( $path, [ (string) $k ] ), $changed, $hint );
			}
			return $new;
		}
		$clean = self::sanitize_value( $new, count( $path ) > 2 ? array_slice( $path, 2 ) : $path, $type_hint );
		if ( $clean !== $new ) {
			self::note_change( $path, $changed );
		}
		return $clean;
	}
}
