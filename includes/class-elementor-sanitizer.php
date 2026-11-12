<?php
/**
 * Sanitising pass for Elementor element trees written by the plugin.
 *
 * Applied to every tree the plugin writes or hands to Elementor's
 * Document::save() (IATO_MCP_Elementor_Adapter::write_pipeline()), and to
 * `_elementor_page_settings` writes. Elementor's own save runs wp_kses_post
 * over every string in the document when the user lacks unfiltered_html
 * (Utils::kses_post_deep); the plugin lifts its kses lock around that save,
 * so for classic widgets this pass is the sanitiser on both paths. It is
 * narrower than Elementor's on purpose:
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
 *       strings containing `<`                               clean_markup()
 *       everything else (plain text, numbers, booleans)      unchanged
 *         (wp_kses_post would turn & into &amp;, which double-encodes in
 *         widgets that escape on output, e.g. the heading's wp_kses_post())
 *
 * One value has its own rule (1.15.0): the `html` setting of a classic Elementor
 * `html` widget can keep inline SVG and style blocks for administrators who opt in
 * (IATO_MCP_Inline_Markup). Which value that is gets decided in walk(), which knows
 * the element: sanitize_value() only sees a path, and `styles/html` looks the same.
 *
 * Every altered value is also described in a report: what was removed, not only
 * where (IATO_MCP_Sanitize_Report).
 *
 * The WordPress functions are injectable so the pass is unit-tested without
 * WordPress; the real behaviour is covered by the live suite.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Elementor_Sanitizer {

	/** Reported changed-path list is capped at this many entries. */
	public const MAX_PATHS = 50;

	/** Setting keys (last path segment) whose string value is a URL. */
	private const URL_KEYS = [ 'url', 'destination', 'href', 'link_url', 'image_url', 'video_url', 'src_url' ];

	/** @var array{kses?:callable,url?:callable,css?:callable,inline_policy?:callable,inline_filter?:callable}|null test seam */
	private static ?array $functions = null;

	/** @var int bumped on each cap hit, for the "and N more" note */
	private static int $overflow = 0;

	/**
	 * Test seam: replace the WordPress sanitising functions. null restores them.
	 *
	 * @param array{kses?:callable,url?:callable,css?:callable,inline_policy?:callable,inline_filter?:callable}|null $functions
	 */
	public static function set_functions( ?array $functions ): void {
		self::$functions = $functions;
	}

	/** @return array{kses:callable,url:callable,css:callable,inline_policy:callable,inline_filter:callable} */
	private static function functions(): array {
		$defaults = [
			'kses'          => [ self::class, 'clean_markup' ],
			'url'           => 'esc_url_raw',
			'css'           => 'sanitize_textarea_field',
			'inline_policy' => [ 'IATO_MCP_Inline_Markup', 'policy' ],
			'inline_filter' => [ 'IATO_MCP_Inline_Markup', 'filter' ],
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

	/**
	 * Which rule sanitize_value() applies to this leaf, for the report.
	 *
	 * @param string[] $path
	 */
	private static function rule_for( array $path, string $type_hint ): string {
		$key  = (string) end( $path );
		$prev = count( $path ) > 1 ? (string) $path[ count( $path ) - 2 ] : '';
		if ( 'url' === $type_hint || self::is_url_key( $key ) ) {
			return 'url';
		}
		return 'custom_css' === $key || ( 'raw' === $key && 'custom_css' === $prev ) ? 'css' : 'html';
	}

	/**
	 * The `html` setting of a classic HTML widget: the markup rule, except that
	 * inline SVG and style blocks are kept when the opt-in applies.
	 *
	 * @return array{0:string,1:array} The value, and what the report should add.
	 */
	private static function sanitize_html_setting( string $value ): array {
		if ( ! str_contains( $value, '<' ) ) {
			return [ $value, [] ];
		}
		$fns = self::functions();
		if ( ! IATO_MCP_Inline_Markup::has_inline_markup( $value ) ) {
			return [ (string) $fns['kses']( $value ), [] ];
		}
		$policy = (string) $fns['inline_policy']();
		if ( IATO_MCP_Inline_Markup::KEPT !== $policy ) {
			return [ (string) $fns['kses']( $value ), [ 'inline_markup' => $policy ] ];
		}
		$filtered = $fns['inline_filter']( $value );
		if ( empty( $filtered['ok'] ) ) {
			return [ (string) $fns['kses']( $value ), [ 'inline_markup' => IATO_MCP_Inline_Markup::STRIPPED_UNSAFE ] ];
		}
		$extra = [ 'inline_markup' => IATO_MCP_Inline_Markup::KEPT, 'kept_tags' => $filtered['kept_tags'] ];
		if ( ! empty( $filtered['removed_css'] ) ) {
			$extra['removed_css'] = $filtered['removed_css'];
		}
		return [ (string) $filtered['html'], $extra ];
	}

	/**
	 * The markup rule: whole STYLE and SCRIPT elements go first, so their contents do
	 * not stay on the page as text, then wp_kses_post, always last (1.16.0).
	 */
	public static function clean_markup( string $html ): string {
		// 1.21.0: WordPress's post list with gaps filled (ARIA, readonly, disabled,
		// <input>), never narrower: IATO_MCP_Inline_Markup::markup_allowlist().
		return (string) wp_kses( IATO_MCP_Inline_Markup::remove_style_and_script( $html ), IATO_MCP_Inline_Markup::markup_allowlist() );
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
	 * @return array{elements:array,changed:string[],report:array}  changed = "<id>/settings/<path>" entries, capped; report = what was removed
	 */
	public static function diff_tree( array $new, ?array $stored ): array {
		self::$overflow = 0;
		$stored_by_id   = [];
		if ( null !== $stored ) {
			self::index_by_id( $stored, $stored_by_id );
		}
		$changed = [];
		$report  = [];
		$out     = self::walk( $new, $stored_by_id, $changed, $report );
		if ( self::$overflow > 0 ) {
			$changed[] = sprintf( '… and %d more', self::$overflow );
		}
		return [ 'elements' => $out, 'changed' => $changed, 'report' => IATO_MCP_Sanitize_Report::cap( $report ) ];
	}

	/**
	 * Diff + sanitise a settings-shaped array (e.g. `_elementor_page_settings`).
	 *
	 * @return array{value:array,changed:string[],report:array}
	 */
	public static function diff_settings( array $new, ?array $stored, string $label = 'settings' ): array {
		self::$overflow = 0;
		$changed        = [];
		$report         = [];
		$out            = self::diff_value( $new, $stored, [ $label ], $changed, $report );
		if ( self::$overflow > 0 ) {
			$changed[] = sprintf( '… and %d more', self::$overflow );
		}
		return [ 'value' => is_array( $out ) ? $out : $new, 'changed' => $changed, 'report' => IATO_MCP_Sanitize_Report::cap( $report ) ];
	}

	/**
	 * What a save removed from the tree it was handed: Elementor's own kses pass
	 * runs over the whole document for a user without unfiltered_html, widgets the
	 * request never touched included.
	 *
	 * @param array $sent   The tree handed to the save.
	 * @param array $stored The tree stored afterwards.
	 * @return array Report entries; only leaves that lost tags or attributes.
	 */
	public static function compare_stored( array $sent, array $stored ): array {
		$stored_by_id = [];
		self::index_by_id( $stored, $stored_by_id );
		$report = [];
		foreach ( self::flatten( $sent ) as $id => $element ) {
			if ( ! isset( $stored_by_id[ $id ] ) ) {
				continue;
			}
			self::compare_value( $element, $stored_by_id[ $id ], [ $id ], $report );
		}
		return IATO_MCP_Sanitize_Report::cap( $report );
	}

	/** @return array<string,array> Elements by id, without their children. */
	private static function flatten( array $elements ): array {
		$out = [];
		self::index_by_id( $elements, $out );
		foreach ( $out as $id => $element ) {
			unset( $out[ $id ]['elements'] );
		}
		return $out;
	}

	private static function compare_value( mixed $sent, mixed $stored, array $path, array &$report ): void {
		if ( is_array( $sent ) ) {
			foreach ( $sent as $key => $value ) {
				self::compare_value( $value, is_array( $stored ) ? ( $stored[ $key ] ?? null ) : null, array_merge( $path, [ (string) $key ] ), $report );
			}
			return;
		}
		$entry = IATO_MCP_Sanitize_Report::for_value( implode( '/', $path ), 'html', $sent, $stored, [ 'source' => 'elementor' ] );
		if ( null !== $entry ) {
			$report[] = $entry;
		}
	}

	/**
	 * What diff_tree() would report, for dry runs.
	 *
	 * @return array{sanitized:bool,sanitized_paths?:string[],sanitized_report?:array}
	 */
	public static function preview( array $new, ?array $stored ): array {
		$r = self::diff_tree( $new, $stored );
		return self::report( $r['changed'], $r['report'] );
	}

	/**
	 * sanitized_report can come alone: markup kept by the opt-in changed no value.
	 *
	 * @return array{sanitized:bool,sanitized_paths?:string[],sanitized_report?:array}
	 */
	public static function report( array $changed, array $report = [] ): array {
		$out = empty( $changed ) ? [ 'sanitized' => false ] : [ 'sanitized' => true, 'sanitized_paths' => $changed ];
		if ( ! empty( $report ) ) {
			$out['sanitized_report'] = $report;
		}
		return $out;
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

	/**
	 * @param string[] $changed
	 * @param array    $report
	 */
	private static function walk( array $elements, array $stored_by_id, array &$changed, array &$report ): array {
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				// Not an element, but it is stored: a leaf with no stored counterpart.
				$clean = self::sanitize_value( $el, [ '#' . $i ] );
				if ( $clean !== $el ) {
					self::note_change( [ '#' . $i ], $changed );
					if ( is_string( $el ) && is_string( $clean ) ) {
						$report[] = IATO_MCP_Sanitize_Report::entry( '#' . $i, 'html', $el, $clean );
					}
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
			$html_widget = 'widget' === ( $el['elType'] ?? '' ) && 'html' === ( $el['widgetType'] ?? '' );
			foreach ( $el as $key => $value ) {
				if ( 'elements' === $key && is_array( $value ) ) {
					continue; // children: recursed below
				}
				$stored_value = ( is_array( $stored ) && array_key_exists( $key, $stored ) ) ? $stored[ $key ] : null;
				$el[ $key ]   = self::diff_value( $value, $stored_value, [ $id, (string) $key ], $changed, $report, '', $html_widget );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::walk( $el['elements'], $stored_by_id, $changed, $report );
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
	 * @param string[] $path        keys from the element root (first entry is the element id / label)
	 * @param string[] $changed     collects "id/section/key/..." for every altered leaf
	 * @param array    $report      collects what was removed from each altered leaf
	 * @param bool     $html_widget is this a classic Elementor HTML widget?
	 */
	private static function diff_value( mixed $new, mixed $stored, array $path, array &$changed, array &$report, string $type_hint = '', bool $html_widget = false ): mixed {
		if ( $new === $stored ) {
			return $new;
		}
		if ( is_array( $new ) ) {
			$envelope = isset( $new['$$type'] ) && is_string( $new['$$type'] ) ? $new['$$type'] : '';
			foreach ( $new as $k => $v ) {
				$sub       = ( is_array( $stored ) && array_key_exists( $k, $stored ) ) ? $stored[ $k ] : null;
				$hint      = ( '' !== $envelope && 'value' === (string) $k ) ? $envelope : '';
				$new[ $k ] = self::diff_value( $v, $sub, array_merge( $path, [ (string) $k ] ), $changed, $report, $hint, $html_widget );
			}
			return $new;
		}
		$leaf_path = count( $path ) > 2 ? array_slice( $path, 2 ) : $path;
		$extra     = [];
		// Exactly <id>/settings/html: `styles/html` and `editor_settings/html` get the plain rule.
		if ( $html_widget && is_string( $new ) && 3 === count( $path ) && 'settings' === $path[1] && 'html' === $path[2] ) {
			[ $clean, $extra ] = self::sanitize_html_setting( $new );
		} else {
			$clean = self::sanitize_value( $new, $leaf_path, $type_hint );
		}
		if ( $clean !== $new ) {
			self::note_change( $path, $changed );
		}
		if ( ( $clean !== $new || [] !== $extra ) && is_string( $new ) && is_string( $clean ) ) {
			$report[] = IATO_MCP_Sanitize_Report::entry( implode( '/', $path ), self::rule_for( $leaf_path, $type_hint ), $new, $clean, $extra );
		}
		return $clean;
	}
}
