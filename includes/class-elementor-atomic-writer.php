<?php
/**
 * Writes to Elementor Atomic Editor (V4) elements (1.14.0).
 *
 * The fix tools send plain values ("h3", {"destination": "https://…"},
 * {"src": {"alt": "…"}}). Atomic elements store every setting as a typed
 * envelope ({"$$type": …, "value": …}), and a plain value stored over one
 * breaks the element (IATO 1.12.x / 1.13.0 did this through the fallback
 * write; 1.13.1 refuses plain values). This class converts and validates
 * plain values with Elementor's own code (IATO_MCP_Elementor_Atomic_Engine:
 * the settings plain-values resolver, Elementor 4.3.0+, and Props_Parser),
 * builds each new value from the stored one so untouched sub-values keep
 * their bytes, checks the whole page before the write, writes through
 * IATO_MCP_Elementor_Adapter::write_pipeline() and verifies by re-reading.
 *
 * Sequence (apply()):
 *   gates (bound user, Elementor available, the editor not holding unsaved
 *   changes) → Elementor's load migrations (a dry run writes nothing) →
 *   build() per edit (schema keys only; envelopes pass through; plain
 *   scalars resolved; plain objects spliced into the stored envelope; image
 *   rules; Props_Parser; dropped / emptied checks) → save_check() over the
 *   page (Elementor's per-element save transform and, for users without
 *   unfiltered_html, the kses pass its save applies) → write_pipeline() with
 *   the atomic options → one receipt per element (`elementor_atomic`) →
 *   per-post caches → verify().
 *
 * The plugin never builds an envelope itself: new ones come from Elementor's
 * resolver and parser, stored ones are reused unchanged.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Elementor_Atomic_Writer {

	public const APPLIED_VIA  = 'iato-native';
	public const RECEIPT_TYPE = 'elementor_atomic';

	/** Atomic rows per page in one update_elementor_widgets_bulk call. */
	public const MAX_ROWS_PER_PAGE = 50;

	/** Element types the writer does not edit. */
	private const REFUSED_TYPES = [
		'e-component' => 'Component instances (e-component) are not edited by IATO yet; change them in Elementor\'s editor.',
	];

	/** Setting keys the writer does not change. */
	private const REFUSED_KEYS = [
		'classes' => 'global classes are not edited by IATO yet; change them in Elementor\'s editor',
	];

	/** Classic-style keys sent to atomic elements → what the atomic element calls it. */
	private const KEY_HINTS = [
		'header_size' => 'the heading level is `tag` (h1 to h6), e.g. {"tag": "h3"}',
		'editor'      => 'the text is `paragraph` on e-paragraph and `title` on e-heading',
		'url'         => 'a link is `link` with `destination`, e.g. {"link": {"destination": "https://…"}}; an image URL is `image` → `src` → `url`',
		'alt'         => 'the alt text is `image` → `src` → `alt`, e.g. {"image": {"src": {"alt": "…"}}}',
		'image_alt'   => 'the alt text is `image` → `src` → `alt`, e.g. {"image": {"src": {"alt": "…"}}}',
		'button_text' => 'the button text is `text`',
	];

	/** @var object|null IATO_MCP_Elementor_Atomic_Engine or a test double */
	private static $engine = null;

	/** Replace Elementor's code with a test double; null restores it. */
	public static function set_engine( ?object $engine ): void {
		self::$engine = $engine;
	}

	public static function engine(): object {
		if ( null === self::$engine ) {
			self::$engine = new IATO_MCP_Elementor_Atomic_Engine();
		}
		return self::$engine;
	}

	// ── Availability ──────────────────────────────────────────────────────

	/**
	 * Is this element an atomic element for a write? The 1.13.1 rule
	 * (IATO_MCP_Elementor_Atomic::has_atomic_type(), which asks Elementor only
	 * about `e-` types and elements holding typed values), or else Elementor's
	 * registered class for the type declares a props schema: writes ask
	 * Elementor about every element they edit, so a registered atomic type
	 * without the prefix and without typed values is caught too.
	 */
	public static function is_target( array $node ): bool {
		return IATO_MCP_Elementor_Atomic::has_atomic_type( $node ) || null !== self::engine()->keys( $node );
	}

	/**
	 * Null when the writer can edit this atomic element; otherwise
	 * atomic_write_unavailable naming what is missing.
	 */
	public static function unavailable( array $node ): ?WP_Error {
		$type    = IATO_MCP_Elementor_Atomic::type_of( $node );
		$missing = self::engine()->missing();
		if ( null === $missing && null === self::engine()->keys( $node ) ) {
			$missing = "Elementor does not have `{$type}` registered right now (the Atomic Editor is off, or the plugin that provides the element is inactive), so IATO cannot convert values for it.";
		}
		if ( null === $missing ) {
			return null;
		}
		return new WP_Error(
			'atomic_write_unavailable',
			$missing . ' Nothing was written. Until then IATO accepts only typed values for atomic elements ({"$$type": …, "value": …}, the shape get_elementor_widget returns under settings), or make the change in Elementor\'s editor.',
			[ 'status' => 400, 'element_id' => (string) ( $node['id'] ?? '' ), 'type' => $type, 'elementor_version' => self::engine()->version() ]
		);
	}

	/**
	 * An atomic element whose type Elementor does not have registered right
	 * now (the Atomic Editor is off, the plugin that provides it is inactive,
	 * Elementor is inactive): Elementor can neither validate nor render it,
	 * so no write changes it, typed values included (1.14.0; until 1.13.1 a
	 * typed value was stored unvalidated). Null when Elementor knows the type.
	 */
	public static function unregistered( array $node ): ?WP_Error {
		if ( null !== self::engine()->keys( $node ) ) {
			return null;
		}
		$type = IATO_MCP_Elementor_Atomic::type_of( $node );
		return new WP_Error(
			'atomic_write_unavailable',
			"Elementor does not have `{$type}` registered right now (the Atomic Editor is off, or the plugin that provides the element is inactive), so it can neither validate nor show this element, and IATO does not change it. Turn the Atomic Editor (or that plugin) back on to edit it. Nothing was written.",
			[ 'status' => 400, 'element_id' => (string) ( $node['id'] ?? '' ), 'type' => $type, 'elementor_version' => self::engine()->version() ]
		);
	}

	// ── Building one element's new settings ───────────────────────────────

	/**
	 * The new settings for one atomic element from a settings patch (key →
	 * plain value, typed value, or null to remove the key), validated by
	 * Elementor. Returns the plan, or a WP_Error naming the element.
	 *
	 * Plan keys: element_id, type, settings (the element's new settings),
	 * keys (setting keys the patch names), before / after (key → stored
	 * value, null when absent), applied ([{key, from, to}] in plain form),
	 * sanitized_paths, aliases (sent name → setting key), noop; or, for an
	 * alt-only change on a Media Library image, route (attachment alt).
	 */
	public static function build( array $node, array $patch ): array|WP_Error {
		$engine = self::engine();
		$id     = (string) ( $node['id'] ?? '' );
		$type   = IATO_MCP_Elementor_Atomic::type_of( $node );
		if ( isset( self::REFUSED_TYPES[ $type ] ) ) {
			return self::error( 'atomic_type_not_supported', self::REFUSED_TYPES[ $type ] . ' Nothing was written.', $id, $type );
		}
		$schema_keys = $engine->keys( $node );
		if ( null === $schema_keys ) {
			return self::unavailable( $node ) ?? self::error( 'atomic_write_unavailable', "Elementor does not have `{$type}` registered right now.", $id, $type );
		}
		$aliases = $engine->aliases( $node );
		$stored  = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];

		// 1. Setting keys (aliases resolved the way Elementor resolves them).
		$canon   = [];
		$unknown = [];
		$refused = [];
		$used    = [];
		foreach ( $patch as $name => $value ) {
			$name = (string) $name;
			$key  = in_array( $name, $schema_keys, true ) ? $name : ( $aliases[ $name ] ?? null );
			if ( null === $key ) {
				$unknown[] = $name;
				continue;
			}
			if ( isset( self::REFUSED_KEYS[ $key ] ) ) {
				$refused[] = $key;
				continue;
			}
			if ( array_key_exists( $key, $canon ) ) {
				return self::error( 'atomic_duplicate_key', "`{$name}` and `" . ( array_search( $key, $used, true ) ?: $key ) . "` name the same setting of {$type} (`{$key}`). Send it once. Nothing was written.", $id, $type );
			}
			if ( $key !== $name ) {
				$used[ $name ] = $key;
			}
			$canon[ $key ] = $value;
		}
		if ( $unknown || $refused ) {
			return self::unsupported_keys( $id, $type, $unknown, $refused, $schema_keys, $aliases );
		}

		// 2. Alt text of a Media Library image belongs to the attachment.
		foreach ( $canon as $key => $value ) {
			$stored_type = IATO_MCP_Elementor_Atomic::is_envelope( $stored[ $key ] ?? null ) ? (string) $stored[ $key ]['$$type'] : null;
			if ( 'image' !== $engine->object_key_at( $node, [ $key ], $stored_type ) ) {
				continue;
			}
			$library_id = self::library_image_id( $stored[ $key ] ?? null );
			if ( null === $library_id ) {
				continue;
			}
			$alt = self::alt_only( $value );
			if ( null !== $alt ) {
				if ( count( $canon ) > 1 ) {
					return self::error( 'atomic_library_alt_separate', "Element {$id} shows Media Library image {$library_id}, whose alt text belongs to the attachment and changes everywhere the image is used. Change the alt text in a call of its own (update_alt_text with this post_id and element_id, or this tool with only image.src.alt). Nothing was written.", $id, $type, [ 'attachment_id' => $library_id ] );
				}
				return [
					'element_id' => $id,
					'type'       => $type,
					'route'      => [ 'attachment_id' => $library_id, 'alt' => $alt, 'key' => $key ],
				];
			}
			if ( self::touches_alt( $value ) && ! self::sets_src( $value, 'url' ) ) {
				return self::error( 'atomic_library_alt_separate', "Element {$id} shows Media Library image {$library_id}, whose alt text belongs to the attachment. Change the alt text in a call of its own, then the other image settings. Nothing was written.", $id, $type, [ 'attachment_id' => $library_id ] );
			}
		}

		// 3. Each value, built from the stored one.
		$new     = $stored;
		$leaves  = [];
		$cleared = [];
		foreach ( $canon as $key => $value ) {
			if ( null === $value ) {
				unset( $new[ $key ] );
				continue;
			}
			$built = self::build_value( $node, [ (string) $key ], $stored[ $key ] ?? null, $value, $leaves, $cleared );
			if ( is_wp_error( $built ) ) {
				return self::with_element( $built, $id, $type );
			}
			$new[ $key ] = $built;
		}

		// 4. Elementor's own validation and sanitising. The output of an
		// invalid parse is never used (it silently drops the bad key).
		$parsed = $engine->parse( $node, $new );
		if ( empty( $parsed['valid'] ) ) {
			return self::invalid_settings( $id, $type, $parsed, array_keys( $canon ) );
		}

		// 5. The element's new settings: Elementor's result for the keys the
		// patch names, every other key exactly as stored.
		$final = $stored;
		foreach ( $canon as $key => $value ) {
			if ( null === $value ) {
				unset( $final[ $key ] );
				continue;
			}
			if ( ! array_key_exists( $key, (array) $parsed['settings'] ) ) {
				return self::error( 'atomic_value_dropped', "Elementor's validation removed `{$key}` from element {$id} ({$type}), so the value would not be stored. Check the value against the shape get_elementor_widget returns for the element. Nothing was written.", $id, $type, [ 'path' => $key ] );
			}
			$final[ $key ] = $parsed['settings'][ $key ];
		}

		// 6. What Elementor kept of each requested value.
		$sanitized = [];
		foreach ( $leaves as [ $path, $resolved ] ) {
			$got = self::at_path( $final, $path );
			$dot = implode( '.', $path );
			if ( self::missing() === $got ) {
				return self::error( 'atomic_value_dropped', "Elementor's validation removed `{$dot}` from element {$id} ({$type}), so the value would not be stored. Nothing was written.", $id, $type, [ 'path' => $dot ] );
			}
			if ( self::url_emptied( $resolved, $got ) ) {
				return self::error( 'atomic_url_rejected', "The URL sent for `{$dot}` on element {$id} ({$type}) is not allowed (Elementor's sanitising removed it, as it does for javascript: and other unsafe schemes). Send an http(s) URL. Nothing was written.", $id, $type, [ 'path' => $dot ] );
			}
			if ( self::canon_text( $resolved ) !== self::canon_text( $got ) ) {
				$sanitized[] = $id . '/settings/' . implode( '/', $path );
			}
		}
		foreach ( $canon as $key => $value ) {
			$lost = self::subkeys_lost( $stored[ $key ] ?? null, $final[ $key ] ?? null, (string) $key, $cleared );
			if ( $lost ) {
				return self::error( 'atomic_subkeys_dropped', "Storing `{$key}` on element {$id} ({$type}) would drop its stored " . implode( ', ', $lost ) . '. Nothing was written.', $id, $type, [ 'paths' => $lost ] );
			}
		}

		$before  = [];
		$after   = [];
		$applied = [];
		$noop    = true;
		foreach ( array_keys( $canon ) as $key ) {
			$was            = array_key_exists( $key, $stored ) ? $stored[ $key ] : null;
			$now            = array_key_exists( $key, $final ) ? $final[ $key ] : null;
			$before[ $key ] = $was;
			$after[ $key ]  = $now;
			if ( self::canon( $was ) !== self::canon( $now ) || array_key_exists( $key, $stored ) !== array_key_exists( $key, $final ) ) {
				$noop = false;
			}
			$applied[] = [
				'key'  => (string) $key,
				'from' => IATO_MCP_Elementor_Atomic::unwrap( $was ),
				'to'   => IATO_MCP_Elementor_Atomic::unwrap( $now ),
			];
		}

		return [
			'element_id'      => $id,
			'type'            => $type,
			'settings'        => $final,
			'keys'            => array_map( 'strval', array_keys( $canon ) ),
			'before'          => $before,
			'after'           => $after,
			'applied'         => $applied,
			'sanitized_paths' => $sanitized,
			'aliases'         => $used,
			'noop'            => $noop,
		];
	}

	/**
	 * One value at a settings path. Typed values pass through; plain objects
	 * for object props are spliced into the stored envelope (each sub-value
	 * built on its own, every other sub-value kept byte for byte); anything
	 * else is converted by Elementor's resolver.
	 *
	 * @param array $leaves  [path, typed value] of every value the request sets
	 * @param array $cleared paths of sub-values the request removes
	 */
	private static function build_value( array $node, array $path, mixed $stored, mixed $plain, array &$leaves, array &$cleared ): mixed {
		$engine = self::engine();
		$dot    = implode( '.', $path );
		if ( IATO_MCP_Elementor_Atomic::is_envelope( $plain ) ) {
			$leaves[] = [ $path, $plain ];
			return $plain;
		}
		$stored_type = IATO_MCP_Elementor_Atomic::is_envelope( $stored ) ? (string) $stored['$$type'] : null;
		$object_key  = $engine->object_key_at( $node, $path, $stored_type );
		if ( null !== $object_key && is_array( $plain ) && [] !== $plain && ! self::is_list( $plain ) ) {
			$shape   = (array) $engine->shape_keys( $node, $path, $stored_type );
			$unknown = array_values( array_diff( array_map( 'strval', array_keys( $plain ) ), $shape ) );
			if ( $unknown ) {
				return new WP_Error(
					'atomic_prop_not_supported',
					"`{$dot}` has no field " . self::quote_list( $unknown ) . '; its fields are ' . self::quote_list( $shape ) . '. Nothing was written.',
					[ 'status' => 400, 'path' => $dot, 'unknown' => $unknown, 'supported' => $shape ]
				);
			}
			if ( 'image-src' === $object_key && isset( $plain['url'] ) && ! IATO_MCP_Elementor_Atomic::is_envelope( $plain['url'] )
				&& ( ! is_string( $plain['url'] ) || false === filter_var( $plain['url'], FILTER_VALIDATE_URL ) ) ) {
				return new WP_Error( 'atomic_url_rejected', "`{$dot}.url` must be an absolute URL (https://…). Nothing was written.", [ 'status' => 400, 'path' => $dot . '.url' ] );
			}
			if ( IATO_MCP_Elementor_Atomic::is_envelope( $stored ) && $object_key === $stored['$$type'] && is_array( $stored['value'] ) ) {
				$out = $stored;
				foreach ( $plain as $sub => $value ) {
					$sub = (string) $sub;
					if ( null === $value ) {
						unset( $out['value'][ $sub ] );
						$cleared[] = $dot . '.' . $sub;
						continue;
					}
					$built = self::build_value( $node, array_merge( $path, [ $sub ] ), $stored['value'][ $sub ] ?? null, $value, $leaves, $cleared );
					if ( is_wp_error( $built ) ) {
						return $built;
					}
					$out['value'][ $sub ] = $built;
				}
				// Elementor requires an image to have an attachment id or a URL,
				// not both: setting one clears the other.
				if ( 'image-src' === $object_key ) {
					foreach ( [ 'url' => 'id', 'id' => 'url' ] as $set => $other ) {
						if ( isset( $plain[ $set ] ) && ! array_key_exists( $other, $plain ) && array_key_exists( $other, $out['value'] ) ) {
							unset( $out['value'][ $other ] );
							$cleared[] = $dot . '.' . $other;
						}
					}
				}
				return $out;
			}
			$resolved = $engine->resolve_at( $node, $path, $plain );
			if ( null === $resolved ) {
				return self::unresolvable( $dot, $plain );
			}
			$dropped = self::dropped_plain_keys( $plain, $resolved, $dot );
			if ( $dropped ) {
				return new WP_Error( 'atomic_value_dropped', 'Elementor could not convert ' . self::quote_list( $dropped ) . ', so it would not be stored. Nothing was written.', [ 'status' => 400, 'paths' => $dropped ] );
			}
			$leaves[] = [ $path, $resolved ];
			return $resolved;
		}
		$resolved = $engine->resolve_at( $node, $path, $plain );
		if ( null === $resolved ) {
			return self::unresolvable( $dot, $plain );
		}
		if ( is_array( $plain ) && ! self::is_list( $plain ) ) {
			$dropped = self::dropped_plain_keys( $plain, $resolved, $dot );
			if ( $dropped ) {
				return new WP_Error( 'atomic_value_dropped', 'Elementor could not convert ' . self::quote_list( $dropped ) . ', so it would not be stored. Nothing was written.', [ 'status' => 400, 'paths' => $dropped ] );
			}
		}
		$leaves[] = [ $path, $resolved ];
		return $resolved;
	}

	// ── The page check before the write ───────────────────────────────────

	/**
	 * Would Elementor's save store the page as written? Runs Elementor's
	 * per-element save transform over every atomic element and, when the
	 * user does not hold unfiltered_html (Elementor then applies kses to the
	 * whole document), the same kses pass over every element.
	 *
	 * @param array    $tree     the tree to write
	 * @param string[] $changed  ids of the elements this write changes
	 * @param array    $keys     element id → setting keys this write changes
	 * @return array{save:bool, reason:?string, element_ids:string[], invalid_elements:array, refusal:?WP_Error, checked:int}
	 */
	public static function save_check( array $tree, array $changed, array $keys = [] ): array {
		$engine     = self::engine();
		$altered    = [];
		$invalid    = [];
		$rejected   = [];
		$checked    = 0;
		$kses       = ! IATO_MCP_Auth::write_unlocked( fn() => current_user_can( 'unfiltered_html' ) );
		IATO_MCP_Elementor_Adapter::walk_widgets( $tree, function ( array $element ) use ( $engine, $changed, $keys, $kses, &$altered, &$invalid, &$rejected, &$checked ) {
			$id         = (string) ( $element['id'] ?? '' );
			$is_changed = in_array( $id, $changed, true );
			$own        = $element;
			unset( $own['elements'] );
			// Elementor's save runs kses over the whole document for users without
			// unfiltered_html: an untouched element, or an untouched setting of an
			// element this write changes (a link URL's & becomes &amp;), would be
			// altered (1.14.0 review B1). The keys the write sets are left out:
			// they were sanitised already. Changed elements without a key list
			// (classic elements of a whole-tree write) are left to Elementor, as
			// before.
			if ( $kses && ( ! $is_changed || isset( $keys[ $id ] ) ) ) {
				$compare = $own;
				if ( $is_changed && is_array( $compare['settings'] ?? null ) ) {
					foreach ( (array) $keys[ $id ] as $key ) {
						unset( $compare['settings'][ $key ] );
					}
				}
				if ( self::canon( $compare ) !== self::canon( self::kses_deep( $compare ) ) ) {
					$altered[ $id ] = 'kses';
				}
			}
			if ( ! IATO_MCP_Elementor_Atomic::has_atomic_type( $element ) || null === $engine->keys( $element ) ) {
				return;
			}
			$checked++;
			$out = $engine->save_transform( $element );
			if ( empty( $out['ok'] ) ) {
				if ( $is_changed ) {
					$rejected[] = [ 'element_id' => $id, 'type' => IATO_MCP_Elementor_Atomic::type_of( $element ), 'elementor_error' => (string) ( $out['error'] ?? '' ) ];
				} else {
					$invalid[] = [ 'widget_id' => $id, 'type' => IATO_MCP_Elementor_Atomic::type_of( $element ), 'elementor_error' => (string) ( $out['error'] ?? '' ) ];
				}
				return;
			}
			if ( ! is_array( $out['data'] ?? null ) ) {
				return;
			}
			$skip = $is_changed ? (array) ( $keys[ $id ] ?? [] ) : [];
			if ( self::transform_alters( $element, $out['data'], $skip ) ) {
				$altered[ $id ] = 'elementor_save';
			}
		} );

		if ( $rejected ) {
			$list = implode( '; ', array_map( fn( $r ) => "{$r['element_id']} ({$r['type']}): {$r['elementor_error']}", $rejected ) );
			return [
				'save'             => false,
				'reason'           => null,
				'element_ids'      => [],
				'invalid_elements' => [],
				'checked'          => $checked,
				'refusal'          => new WP_Error(
					'atomic_invalid_settings',
					"Elementor's own validation rejects the new settings of {$list}. Nothing was written.",
					[ 'status' => 400, 'elements' => $rejected ]
				),
			];
		}
		if ( $invalid ) {
			// Elementor's save would throw for these elements (values stored
			// without their typed wrapper by IATO 1.12.x / 1.13.0) and refuse the
			// page. The plugin stores the page itself so the change goes ahead
			// and the damaged elements keep their bytes; each can be repaired by
			// sending the named keys again through these tools.
			return [ 'save' => false, 'reason' => 'invalid_elements_on_page', 'element_ids' => array_column( $invalid, 'widget_id' ), 'invalid_elements' => $invalid, 'refusal' => null, 'checked' => $checked ];
		}
		if ( $altered ) {
			return [ 'save' => false, 'reason' => 'elementor_would_alter_untouched', 'element_ids' => array_map( 'strval', array_keys( $altered ) ), 'invalid_elements' => [], 'refusal' => null, 'checked' => $checked, 'causes' => $altered ];
		}
		return [ 'save' => true, 'reason' => null, 'element_ids' => [], 'invalid_elements' => [], 'refusal' => null, 'checked' => $checked ];
	}

	/**
	 * Does Elementor's save transform change an element's settings or styles
	 * (other than the keys this write sets)? Null entries count as absent:
	 * Elementor drops them on save.
	 */
	private static function transform_alters( array $element, array $saved, array $skip_keys ): bool {
		$in_settings  = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$out_settings = is_array( $saved['settings'] ?? null ) ? $saved['settings'] : [];
		foreach ( $skip_keys as $key ) {
			unset( $in_settings[ $key ], $out_settings[ $key ] );
		}
		if ( self::canon( $in_settings ) !== self::canon( $out_settings ) ) {
			return true;
		}
		$in_styles  = is_array( $element['styles'] ?? null ) ? $element['styles'] : [];
		$out_styles = is_array( $saved['styles'] ?? null ) ? $saved['styles'] : [];
		return self::canon( $in_styles ) !== self::canon( $out_styles );
	}

	/** @var callable|null test seam for kses_deep(): fn(string): string */
	private static $kses = null;

	/** Replace wp_kses_post in kses_deep() for tests; null restores it. */
	public static function set_kses( ?callable $kses ): void {
		self::$kses = $kses;
	}

	/** Elementor's Utils::kses_post_deep(), which Document::save() runs for users without unfiltered_html. */
	private static function kses_deep( array $data ): array {
		$kses = self::$kses ?? ( function_exists( 'wp_kses_post' ) ? 'wp_kses_post' : null );
		if ( null === $kses ) {
			return $data;
		}
		array_walk_recursive( $data, function ( &$value ) use ( $kses ) {
			if ( is_string( $value ) ) {
				$value = $kses( $value );
			}
		} );
		return $data;
	}

	// ── Gates ─────────────────────────────────────────────────────────────

	/**
	 * Elementor's own rule (Editor_Sync_State::check_mutation_guard()): refuse
	 * while another user holds the post lock AND the Elementor editor reported
	 * unsaved changes for the post.
	 */
	public static function editor_lock( int $post_id ): ?WP_Error {
		if ( ! function_exists( 'wp_check_post_lock' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/post.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$lock    = function_exists( 'wp_check_post_lock' ) ? wp_check_post_lock( $post_id ) : false;
		$unsaved = (bool) get_transient( "_elementor_editor_unsaved_{$post_id}" );
		if ( ! $lock || ! $unsaved ) {
			return null;
		}
		$user = function_exists( 'get_userdata' ) ? get_userdata( (int) $lock ) : null;
		return new WP_Error(
			'elementor_editor_unsaved_changes',
			'The editor has unsaved changes for this document. Ask the user to save or discard their changes in the Elementor editor before retrying this operation.',
			[ 'status' => 409, 'post_id' => $post_id, 'locked_by' => $user ? (string) $user->user_login : (int) $lock ]
		);
	}

	/**
	 * An Elementor autosave of the post newer than the post itself, by any
	 * user (the newest one), as {user, user_id, modified, note}; null when none.
	 */
	public static function newer_autosave( int $post_id ): ?array {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT ID, post_author, post_modified_gmt FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' AND post_name LIKE %s ORDER BY post_modified_gmt DESC LIMIT 1",
			$post_id,
			$wpdb->esc_like( $post_id . '-autosave' ) . '%'
		) );
		if ( ! $row || strcmp( (string) $row->post_modified_gmt, (string) $post->post_modified_gmt ) <= 0 ) {
			return null;
		}
		$user  = get_userdata( (int) $row->post_author );
		$login = $user ? (string) $user->user_login : (string) $row->post_author;
		return [
			'user'     => $login,
			'user_id'  => (int) $row->post_author,
			'modified' => (string) $row->post_modified_gmt,
			'note'     => "An autosave of this page by {$login} from {$row->post_modified_gmt} UTC is newer than the saved page. IATO's change went to the saved page and is live; after it the page is newer than that autosave, so Elementor no longer offers to restore it when the editor opens. The autosave is still listed under the page's revisions.",
		];
	}

	// ── The write ─────────────────────────────────────────────────────────

	/**
	 * Apply settings patches to atomic elements of one page in one write.
	 *
	 * @param array $edits list of { widget_id, patch, path?, if_revision?, index? }
	 * @param array $opts  dry_run (bool), tool (string, for messages)
	 * @return array{rows: array<int, array|WP_Error>, page: array}|WP_Error
	 *         WP_Error when the whole page is refused (nothing written)
	 */
	public static function apply( int $post_id, array $edits, array $opts = [] ): array|WP_Error {
		$dry_run = ! empty( $opts['dry_run'] );

		$bound = IATO_MCP_Auth::require_bound_user();
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}
		$missing = self::engine()->missing();
		if ( null !== $missing ) {
			return new WP_Error( 'atomic_write_unavailable', $missing . ' Nothing was written.', [ 'status' => 400, 'elementor_version' => self::engine()->version() ] );
		}
		$lock = self::editor_lock( $post_id );
		if ( null !== $lock ) {
			return $lock;
		}

		$start_raw      = IATO_MCP_Elementor_Adapter::stored_raw( $post_id );
		$start_revision = IATO_MCP_Elementor_Adapter::compute_revision( $start_raw );
		$autosave       = self::newer_autosave( $post_id );

		// Elementor upgrades stored prop shapes only when it loads a page, and
		// its save rejects the old shapes: its load migrations run first,
		// captured in memory. The migrated tree is stored together with the
		// change in the one write below, so a refused call or a dry run writes
		// nothing.
		$migration = self::engine()->migrate( $post_id );
		$tree      = null;
		if ( ! empty( $migration['migrated'] ) && is_string( $migration['tree'] ?? null ) ) {
			$tree = json_decode( $migration['tree'], true );
			$tree = is_array( $tree ) ? IATO_MCP_Elementor_Adapter::force_arrays( $tree ) : null;
		}
		$migrated = null !== $tree;
		if ( null === $tree ) {
			$tree = IATO_MCP_Elementor_Adapter::stored_elements( $post_id );
		}
		if ( null === $tree ) {
			return new WP_Error( 'no_elementor_data', "Post {$post_id} has no Elementor data.", [ 'status' => 400 ] );
		}

		// Build every edit against the working tree, so two rows that edit the
		// same element see each other.
		$working  = $tree;
		$rows     = [];
		$elements = []; // path → { id, type, path, before, keys, rows[] }
		$routes   = [];
		foreach ( array_values( $edits ) as $i => $edit ) {
			$widget_id = (string) ( $edit['widget_id'] ?? '' );
			if ( isset( $edit['if_revision'] ) && null !== $edit['if_revision'] && (string) $edit['if_revision'] !== $start_revision ) {
				$rows[ $i ] = new WP_Error( 'revision_conflict', 'Stored revision does not match if_revision.', [ 'status' => 409, 'current_revision' => $start_revision ] );
				continue;
			}
			$path = isset( $edit['path'] ) ? (string) $edit['path'] : null;
			$node = null !== $path ? IATO_MCP_Elementor_Adapter::pointer_get( $working, $path ) : null;
			if ( ! is_array( $node ) || (string) ( $node['id'] ?? '' ) !== $widget_id ) {
				$found = IATO_MCP_Elementor_Adapter::find_widget( $working, $widget_id );
				if ( null === $found ) {
					$rows[ $i ] = new WP_Error( 'widget_not_found', "Widget {$widget_id} not found in post {$post_id}.", [ 'status' => 404 ] );
					continue;
				}
				$path = $found['path'];
				$node = $found['element'];
			}
			$why = self::unavailable( $node );
			if ( null !== $why ) {
				$rows[ $i ] = $why;
				continue;
			}
			$plan = self::build( $node, is_array( $edit['patch'] ?? null ) ? $edit['patch'] : [] );
			if ( is_wp_error( $plan ) ) {
				$rows[ $i ] = $plan;
				continue;
			}
			if ( isset( $plan['route'] ) ) {
				$routes[ $i ] = $plan;
				continue;
			}
			if ( ! isset( $elements[ $path ] ) ) {
				$elements[ $path ] = [ 'id' => $plan['element_id'], 'type' => $plan['type'], 'path' => $path, 'original' => is_array( $node['settings'] ?? null ) ? $node['settings'] : [], 'keys' => [], 'rows' => [] ];
			}
			$elements[ $path ]['keys']   = array_values( array_unique( array_merge( $elements[ $path ]['keys'], $plan['keys'] ) ) );
			$elements[ $path ]['rows'][] = $i;
			$node['settings']            = $plan['settings'];
			IATO_MCP_Elementor_Adapter::pointer_set( $working, $path, $node, false );
			$rows[ $i ] = $plan;
		}

		$changed_paths = array_keys( array_filter( $elements, function ( $e ) use ( $rows ) {
			foreach ( $e['rows'] as $i ) {
				if ( empty( $rows[ $i ]['noop'] ) ) {
					return true;
				}
			}
			return false;
		} ) );

		// true once the migrated tree is stored with the change; would_migrate
		// when nothing is written (dry run, no-op, refusal).
		$page = [
			'post_id'               => $post_id,
			'previous_revision'     => $start_revision,
			'migrated_by_elementor' => $migrated ? 'would_migrate' : false,
		];
		if ( ! empty( $migration['error'] ) ) {
			$page['migration_error'] = (string) $migration['error'];
		}
		if ( null !== $autosave ) {
			$page['editor_autosave_pending'] = true;
			$page['editor_autosave']         = $autosave;
		}

		// Nothing in the tree to change (every row failed, was a no-op, or is an
		// attachment alt change).
		if ( empty( $changed_paths ) ) {
			$page['current_revision'] = $start_revision;
			$page['written']          = false;
			return [ 'rows' => self::with_routes( $rows, $routes, $post_id, $dry_run ), 'page' => $page, 'elements' => [] ];
		}

		$changed_ids = [];
		$keys_by_id  = [];
		foreach ( $changed_paths as $path ) {
			$changed_ids[]                          = $elements[ $path ]['id'];
			$keys_by_id[ $elements[ $path ]['id'] ] = $elements[ $path ]['keys'];
		}
		$check            = self::save_check( $working, $changed_ids, $keys_by_id );
		$page['save_check'] = [ 'elements_checked' => $check['checked'], 'elementor_save' => $check['save'] ? 'would_run' : 'skipped' ] + ( $check['reason'] ? [ 'reason' => $check['reason'], 'element_ids' => $check['element_ids'] ] : [] );
		if ( null !== $check['refusal'] ) {
			return $check['refusal'];
		}
		if ( $check['invalid_elements'] ) {
			$page['invalid_elements'] = $check['invalid_elements'];
		}

		if ( $dry_run ) {
			$page['dry_run']          = true;
			$page['current_revision'] = null;
			if ( ! $check['save'] ) {
				$page['elementor_save'] = 'skipped';
				$page['skipped_reason'] = $check['reason'];
			}
			return [ 'rows' => self::with_routes( $rows, $routes, $post_id, true ), 'page' => $page, 'elements' => $elements ];
		}

		$receipts = [];
		$pipeline = IATO_MCP_Elementor_Adapter::write_pipeline(
			$post_id,
			$working,
			$start_revision,
			$tree,
			[
				'atomic'       => true,
				'skip_save'    => $check['save'] ? null : $check['reason'],
				'skip_ids'     => $check['element_ids'],
				'atomic_paths' => $changed_paths,
				// Receipts straight after the write, before caches and the
				// re-read, so a later failure never leaves a live change
				// without one.
				'on_written'   => function () use ( $post_id, $elements, $changed_paths, &$receipts ) {
					$stored = IATO_MCP_Elementor_Adapter::stored_elements( $post_id ) ?? [];
					foreach ( $changed_paths as $path ) {
						$receipts[ $path ] = self::record_receipt( $post_id, $elements[ $path ], $stored );
					}
				},
			]
		);
		if ( is_wp_error( $pipeline ) ) {
			return $pipeline;
		}

		self::set_mutation_marker( $post_id );
		if ( $migrated ) {
			$page['migrated_by_elementor'] = true;
		}

		$verification = self::verify( $post_id, $tree, $working, $elements, $changed_paths );
		$page        += [
			'current_revision' => $pipeline['current_revision'],
			'content_updated'  => $pipeline['content_updated'],
			'post_content_length' => $pipeline['post_content_length'],
			'written'          => true,
			'verified'         => $verification['verified'],
		] + IATO_MCP_Elementor_Adapter::pipeline_report( $pipeline );
		if ( ! $verification['verified'] ) {
			$page['verification_diff'] = $verification['diff'];
		}

		foreach ( $elements as $path => $element ) {
			foreach ( $element['rows'] as $i ) {
				if ( is_array( $rows[ $i ] ) && isset( $receipts[ $path ] ) ) {
					$rows[ $i ]['receipt'] = $receipts[ $path ];
				}
			}
		}
		return [ 'rows' => self::with_routes( $rows, $routes, $post_id, false ), 'page' => $page, 'elements' => $elements ];
	}

	/**
	 * Single-element entry point (update_elementor_widget, set_widget_setting,
	 * set_heading_level, update_alt_text with element_id): one edit through
	 * apply(), answered in the classic response shape plus the atomic fields.
	 *
	 * @param array $opts dry_run, echo_previous (the caller passed if_revision)
	 */
	public static function update_widget( int $post_id, string $widget_id, string $path, array $patch, array $opts = [] ): array|WP_Error {
		$dry_run = ! empty( $opts['dry_run'] );
		$result  = self::apply( $post_id, [ [ 'widget_id' => $widget_id, 'path' => $path, 'patch' => $patch ] ], [ 'dry_run' => $dry_run ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$row = $result['rows'][0] ?? null;
		if ( ! is_array( $row ) ) {
			return $row instanceof WP_Error ? $row : new WP_Error( 'atomic_write_failed', 'The change could not be applied.' );
		}
		$out = [ 'post_id' => $post_id, 'widget_id' => $widget_id ] + self::row_response( $row, $result['page'], $path, $dry_run );
		if ( ! empty( $opts['echo_previous'] ) ) {
			$out = [ 'previous_revision' => $result['page']['previous_revision'] ] + $out;
		}
		return $out;
	}

	/**
	 * Response fields for one edit: the classic keys (current_revision,
	 * applied_patch, content_updated, post_content_length, elementor_save,
	 * stored_by, change_receipt) plus applied_via, schema, state, applied and
	 * the page report (verified, css, migrated_by_elementor, …).
	 */
	public static function row_response( array $row, array $page, string $path, bool $dry_run ): array {
		$out = [];
		if ( isset( $row['routed_to'] ) ) {
			$out = [
				'current_revision' => $page['current_revision'] ?? null,
				'applied_patch'    => [],
				'applied'          => $row['applied'],
				'routed_to'        => $row['routed_to'],
				'attachment_id'    => $row['attachment_id'],
				'scope'            => $row['scope'],
				'note'             => "Element {$row['element_id']} shows a Media Library image; its alt text is the attachment's, so the change applies to {$row['scope']}.",
			];
			if ( $dry_run ) {
				$out = [ 'dry_run' => true, 'current_revision' => null ] + $out;
			}
			$out += self::markers();
			if ( isset( $row['receipt'] ) ) {
				$out['change_receipt'] = self::slim_receipt( $row['receipt'] );
			}
			return $out;
		}

		$ops = [];
		foreach ( $row['keys'] as $key ) {
			$pointer = $path . '/settings/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], $key );
			$before  = $row['before'][ $key ] ?? null;
			$after   = $row['after'][ $key ] ?? null;
			if ( null === $after ) {
				if ( null !== $before ) {
					$ops[] = [ 'op' => 'remove', 'path' => $pointer, 'previous_value' => $before ];
				}
				continue;
			}
			if ( self::canon( $before ) !== self::canon( $after ) ) {
				$ops[] = null === $before
					? [ 'op' => 'add', 'path' => $pointer, 'value' => $after ]
					: [ 'op' => 'replace', 'path' => $pointer, 'value' => $after, 'previous_value' => $before ];
			}
		}

		if ( ! empty( $row['noop'] ) ) {
			return [
				// The page revision after this call (other rows may have written it).
				'current_revision'    => $page['current_revision'] ?? ( $page['previous_revision'] ?? null ),
				'applied_patch'       => [],
				'applied'             => $row['applied'],
				'content_updated'     => false,
				'post_content_length' => strlen( (string) get_post_field( 'post_content', (int) ( $page['post_id'] ?? 0 ) ) ),
			] + self::markers() + self::page_fields( $page, [ 'migrated_by_elementor', 'editor_autosave_pending', 'editor_autosave' ] );
		}

		if ( $dry_run ) {
			$out = [
				'dry_run'          => true,
				'current_revision' => null,
				'applied_patch'    => $ops,
				'would_apply'      => $row['applied'],
				'would_store'      => $row['after'],
			];
		} else {
			$out = [
				'current_revision'    => $page['current_revision'] ?? null,
				'applied_patch'       => $ops,
				'applied'             => self::applied_as_stored( $row ),
				'content_updated'     => $page['content_updated'] ?? false,
				'post_content_length' => $page['post_content_length'] ?? 0,
			];
		}
		$out += self::markers();
		$out += self::page_fields( $page, [ 'elementor_save', 'stored_by', 'elementor_save_error', 'skipped_reason', 'skipped_for_elements', 'unregistered_element_types', 'invalid_elements', 'verified', 'verification_diff', 'verification', 'cache_cleared', 'css', 'render_error', 'migrated_by_elementor', 'migration_error', 'editor_autosave_pending', 'editor_autosave', 'save_check' ] );
		$sanitized = array_values( array_unique( array_merge( (array) ( $row['sanitized_paths'] ?? [] ), (array) ( $page['sanitized_paths'] ?? [] ) ) ) );
		if ( $sanitized ) {
			$out['sanitized']       = true;
			$out['sanitized_paths'] = $sanitized;
		}
		if ( ! empty( $row['aliases'] ) ) {
			$out['aliases'] = $row['aliases'];
		}
		if ( isset( $row['receipt'] ) ) {
			$out['change_receipt'] = self::slim_receipt( $row['receipt'] );
		}
		return $out;
	}

	/** applied_via / schema / state, on every atomic result. */
	public static function markers(): array {
		return [ 'applied_via' => self::APPLIED_VIA, 'schema' => IATO_MCP_Elementor_Atomic::SCHEMA_ATOMIC, 'state' => 'live' ];
	}

	/** The `to` of each applied change as the receipt read it back after the write. */
	private static function applied_as_stored( array $row ): array {
		$after = IATO_MCP_Rollback_Conflict::json_array( $row['receipt']['after_value'] ?? null );
		$keys  = is_array( $after['keys'] ?? null ) ? $after['keys'] : null;
		if ( null === $keys ) {
			return $row['applied'];
		}
		$out = [];
		foreach ( $row['applied'] as $change ) {
			if ( array_key_exists( $change['key'], $keys ) ) {
				$change['to'] = IATO_MCP_Elementor_Atomic::unwrap( $keys[ $change['key'] ] );
			}
			$out[] = $change;
		}
		return $out;
	}

	private static function page_fields( array $page, array $keys ): array {
		$out = [];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $page ) && null !== $page[ $key ] ) {
				$out[ $key ] = $page[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Whole-tree writes (update_elementor_patch, update_elementor_data): the
	 * atomic elements the change touches get the plain values it sets
	 * converted and validated like a single-element write (a plain object is
	 * spliced into the element's stored value when the element keeps its id
	 * and type); typed values pass as sent. Elements the writer cannot handle
	 * keep the 1.13.1 guard. Then the page check (save_check()) decides
	 * between Elementor's save and the plugin's own write.
	 *
	 * Elementor's load migrations are not run first here: the ops address the
	 * tree as stored, which the caller read.
	 *
	 * @return array{tree:array, atomic_ids:string[], resolved_ids:string[], pipeline_opts:array, report:array}|WP_Error
	 */
	public static function prepare_tree_write( int $post_id, ?array $stored, array $new ): array|WP_Error {
		$stored   = $stored ?? [];
		$new      = IATO_MCP_Elementor_Adapter::force_arrays( $new );
		$by_id    = self::nodes_by_id( $stored );
		$new_ids  = self::nodes_by_id( $new );
		$resolved = [];
		$keys     = [];
		$why      = null;

		foreach ( self::nodes_with_paths( $new ) as [ $path, $node ] ) {
			if ( ! self::is_target( $node ) ) {
				continue;
			}
			$id       = (string) ( $node['id'] ?? '' );
			$settings = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
			$same     = ( count( $by_id[ $id ] ?? [] ) === 1 && count( $new_ids[ $id ] ?? [] ) === 1
				&& IATO_MCP_Elementor_Atomic::type_of( $by_id[ $id ][0] ) === IATO_MCP_Elementor_Atomic::type_of( $node ) ) ? $by_id[ $id ][0] : null;
			$before   = is_array( $same['settings'] ?? null ) ? $same['settings'] : [];
			$patch    = [];
			$diff     = [];
			foreach ( $settings as $key => $value ) {
				if ( array_key_exists( $key, $before ) && self::canon( $before[ $key ] ) === self::canon( $value ) ) {
					continue;
				}
				$diff[] = (string) $key;
				if ( null !== $value && ! IATO_MCP_Elementor_Atomic::is_envelope( $value ) ) {
					$patch[ (string) $key ] = $value;
				}
			}
			foreach ( array_keys( $before ) as $key ) {
				if ( ! array_key_exists( $key, $settings ) ) {
					$diff[] = (string) $key;
				}
			}
			if ( $diff ) {
				$keys[ $id ] = array_values( array_unique( array_merge( $keys[ $id ] ?? [], $diff ) ) );
				// A changed element of a type Elementor cannot load right now.
				$unregistered = self::unregistered( $node );
				if ( null !== $unregistered ) {
					return $unregistered;
				}
			}
			if ( ! $patch ) {
				continue;
			}
			$unavailable = self::unavailable( $node );
			if ( null !== $unavailable ) {
				$why = $why ?? $unavailable;
				continue; // the 1.13.1 guard below refuses it
			}
			$base = $node;
			foreach ( array_keys( $patch ) as $key ) {
				if ( array_key_exists( $key, $before ) ) {
					$base['settings'][ $key ] = $before[ $key ];
				} else {
					unset( $base['settings'][ $key ] );
				}
			}
			$plan = self::build( $base, $patch );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( isset( $plan['route'] ) ) {
				return self::error( 'atomic_library_alt_separate', "Element {$id} shows Media Library image {$plan['route']['attachment_id']}, whose alt text belongs to the attachment and changes everywhere the image is used. Change it with update_alt_text (this post_id and element_id). Nothing was written.", $id, $plan['type'], [ 'attachment_id' => $plan['route']['attachment_id'] ] );
			}
			$node['settings'] = $plan['settings'];
			IATO_MCP_Elementor_Adapter::pointer_set( $new, $path, $node, false );
			$resolved[] = $id;
		}

		$guard = IATO_MCP_Elementor_Adapter::check_atomic_tree( $stored, $new, $why );
		if ( null !== $guard['error'] ) {
			return $guard['error'];
		}
		$atomic_ids = $guard['changed_ids'];
		$report     = [];
		$opts       = [ 'atomic_ids' => $atomic_ids ];
		// Without Elementor's resolver (before 4.3.0) the 1.13.1 path stays as
		// it was: typed values only, Elementor's per-element validation before
		// any fallback write.
		if ( empty( $atomic_ids ) || null !== self::engine()->missing() ) {
			return [ 'tree' => $new, 'atomic_ids' => $atomic_ids, 'resolved_ids' => [], 'pipeline_opts' => $opts, 'report' => $report ];
		}

		if ( $resolved ) {
			$bound = IATO_MCP_Auth::require_bound_user();
			if ( is_wp_error( $bound ) ) {
				return $bound;
			}
			$lock = self::editor_lock( $post_id );
			if ( null !== $lock ) {
				return $lock;
			}
		}

		$changed_all     = self::changed_ids( $stored, $new );
		$classic_changed = array_values( array_diff( $changed_all, $atomic_ids ) );
		$check           = self::save_check( $new, $changed_all, $keys );
		if ( null !== $check['refusal'] ) {
			return $check['refusal'];
		}
		$report['schema']     = $classic_changed ? 'mixed' : IATO_MCP_Elementor_Atomic::SCHEMA_ATOMIC;
		$report['save_check'] = [ 'elements_checked' => $check['checked'], 'elementor_save' => $check['save'] ? 'would_run' : 'skipped' ] + ( $check['reason'] ? [ 'reason' => $check['reason'], 'element_ids' => $check['element_ids'] ] : [] );
		if ( $check['invalid_elements'] ) {
			$report['invalid_elements'] = $check['invalid_elements'];
		}
		if ( $resolved ) {
			$report['converted_elements'] = array_values( array_unique( $resolved ) );
		}
		if ( ! $check['save'] ) {
			$opts['skip_save'] = $check['reason'];
			$opts['skip_ids']  = $check['element_ids'];
		}
		// Plain values were converted and only atomic elements change: this
		// post's caches only, and Elementor refusing or rejecting the save is a
		// refusal (as for single edits). A typed-values-only change keeps the
		// 1.13.1 behaviour, including the validated fallback write when
		// Elementor refuses the save (the unbound key; review F1).
		if ( ! $classic_changed && $resolved ) {
			$opts['atomic'] = true;
		}
		return [ 'tree' => $new, 'atomic_ids' => $atomic_ids, 'resolved_ids' => $resolved, 'pipeline_opts' => $opts, 'report' => $report ];
	}

	/** [json pointer path, node] for every element of a tree, depth first. */
	private static function nodes_with_paths( array $elements, string $prefix = '' ): array {
		$out = [];
		foreach ( $elements as $i => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$path  = $prefix . '/' . $i;
			$out[] = [ $path, $element ];
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$out = array_merge( $out, self::nodes_with_paths( $element['elements'], $path . '/elements' ) );
			}
		}
		return $out;
	}

	/** id → every node carrying it. */
	private static function nodes_by_id( array $elements ): array {
		$out = [];
		foreach ( self::nodes_with_paths( $elements ) as [ , $node ] ) {
			$out[ (string) ( $node['id'] ?? '' ) ][] = $node;
		}
		return $out;
	}

	/** Ids of elements a whole-tree change adds or changes (children compared on their own). */
	private static function changed_ids( array $stored, array $new ): array {
		$was = [];
		foreach ( self::nodes_with_paths( $stored ) as [ , $node ] ) {
			unset( $node['elements'] );
			$was[ (string) ( $node['id'] ?? '' ) ][] = self::canon( $node );
		}
		$out = [];
		foreach ( self::nodes_with_paths( $new ) as [ , $node ] ) {
			$id = (string) ( $node['id'] ?? '' );
			unset( $node['elements'] );
			$key = array_search( self::canon( $node ), $was[ $id ] ?? [], true );
			if ( false === $key ) {
				$out[] = $id;
				continue;
			}
			unset( $was[ $id ][ $key ] ); // each stored copy matches once
		}
		return array_values( array_unique( $out ) );
	}

	/** Execute (or, in a dry run, describe) the attachment alt changes routed out of the tree. */
	private static function with_routes( array $rows, array $routes, int $post_id, bool $dry_run ): array {
		foreach ( $routes as $i => $plan ) {
			$rows[ $i ] = self::route_alt( $plan, $post_id, $dry_run );
		}
		ksort( $rows );
		return $rows;
	}

	/**
	 * The alt text of a Media Library image is the attachment's: written with
	 * the same code and checks as update_alt_text (edit_post on the
	 * attachment), recorded as an `image` / `alt_text` receipt.
	 */
	public static function route_alt( array $plan, int $post_id, bool $dry_run ): array|WP_Error {
		$attachment_id = (int) $plan['route']['attachment_id'];
		// As update_alt_text sanitises it (1.14.0 review F4).
		$alt = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $plan['route']['alt'] ) : (string) $plan['route']['alt'];
		$scope         = "all uses of attachment {$attachment_id}";
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'not_found', "Element {$plan['element_id']} shows attachment {$attachment_id}, which no longer exists.", [ 'status' => 404 ] );
		}
		$cap = IATO_MCP_Auth::require_cap( 'edit_post', $attachment_id );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}
		$before = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$base   = [
			'element_id'    => $plan['element_id'],
			'type'          => $plan['type'],
			'routed_to'     => 'attachment_alt',
			'attachment_id' => $attachment_id,
			'scope'         => $scope,
			'applied'       => [ [ 'key' => $plan['route']['key'] . '.src.alt', 'from' => $before, 'to' => $alt ] ],
		];
		if ( $dry_run ) {
			return $base + [ 'dry_run' => true ];
		}
		$receipt = self::write_attachment_alt( $attachment_id, $alt );
		return $base + [ 'applied' => [ [ 'key' => $plan['route']['key'] . '.src.alt', 'from' => $before, 'to' => $receipt['after_value'] ?? $alt ] ], 'receipt' => $receipt ];
	}

	/**
	 * Store an attachment's alt text and record its `image` / `alt_text`
	 * receipt (what was stored, read back). Shared with update_alt_text; the
	 * caller has checked edit_post on the attachment.
	 */
	public static function write_attachment_alt( int $attachment_id, string $alt ): array {
		$before = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$before = '' !== $before ? $before : null;
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $alt ) ); // update_post_meta() unslashes
		return IATO_MCP_Change_Receipt::record( $attachment_id, 'image', 'alt_text', $before, get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/** Tell an Elementor editor that has the page open that it changed outside the editor. */
	private static function set_mutation_marker( int $post_id ): void {
		try {
			$class = '\Elementor\Modules\Mcp\Utils\Editor_Sync_State';
			if ( class_exists( $class ) && method_exists( $class, 'set_mcp_mutation' ) ) {
				$class::set_mcp_mutation( $post_id );
			}
		} catch ( \Throwable $e ) {
			// The marker only drives a notice in an open editor.
		}
	}

	// ── Receipts ──────────────────────────────────────────────────────────

	/**
	 * One `elementor_atomic` receipt per element: before = the keys as they
	 * were stored, after = the same keys as read back after the write.
	 */
	private static function record_receipt( int $post_id, array $element, array $stored_tree ): array {
		$node   = self::node_after( $stored_tree, $element['path'], $element['id'] );
		$after  = [];
		$before = [];
		foreach ( $element['keys'] as $key ) {
			$before[ $key ] = array_key_exists( $key, $element['original'] ) ? $element['original'][ $key ] : null;
			$after[ $key ]  = is_array( $node['settings'] ?? null ) && array_key_exists( $key, $node['settings'] ) ? $node['settings'][ $key ] : null;
		}
		return IATO_MCP_Change_Receipt::record(
			$post_id,
			self::RECEIPT_TYPE,
			'elementor:' . $element['id'] . ':settings',
			wp_json_encode( [ 'element_id' => $element['id'], 'type' => $element['type'], 'path' => $element['path'], 'keys' => (object) $before ], JSON_UNESCAPED_SLASHES ),
			wp_json_encode( [ 'element_id' => $element['id'], 'type' => $element['type'], 'path' => $element['path'], 'keys' => (object) $after ], JSON_UNESCAPED_SLASHES )
		);
	}

	/** The element at $path in a tree when it still carries $id, else the first element with $id. */
	public static function node_after( array $tree, string $path, string $id ): ?array {
		$node = '' !== $path ? IATO_MCP_Elementor_Adapter::pointer_get( $tree, $path ) : null;
		if ( is_array( $node ) && (string) ( $node['id'] ?? '' ) === $id ) {
			return $node;
		}
		$found = IATO_MCP_Elementor_Adapter::find_widget( $tree, $id );
		return null === $found ? null : $found['element'];
	}

	// ── Rollback ──────────────────────────────────────────────────────────

	/**
	 * The element an `elementor_atomic` receipt names, on the page as stored
	 * now: the node at the recorded path when it still carries the id,
	 * otherwise the element with that id whose keys still hold the receipt's
	 * after values, otherwise the first element with that id.
	 *
	 * @return array{receipt_ok:bool, before:array, after:array, element_id:string, type:string, path:?string, node:?array, tree:?array}
	 */
	public static function receipt_target( array $receipt ): array {
		$before  = IATO_MCP_Rollback_Conflict::json_array( $receipt['before_value'] ?? null );
		$after   = IATO_MCP_Rollback_Conflict::json_array( $receipt['after_value'] ?? null );
		$id      = (string) ( $before['element_id'] ?? '' );
		$out     = [
			'receipt_ok' => '' !== $id && is_array( $before['keys'] ?? null ),
			'before'     => is_array( $before['keys'] ?? null ) ? $before['keys'] : [],
			'after'      => is_array( $after['keys'] ?? null ) ? $after['keys'] : [],
			'element_id' => $id,
			'type'       => (string) ( $before['type'] ?? '' ),
			'path'       => null,
			'node'       => null,
			'tree'       => null,
		];
		$post_id = (int) ( $receipt['post_id'] ?? 0 );
		$tree    = $post_id > 0 ? IATO_MCP_Elementor_Adapter::stored_elements( $post_id ) : null;
		if ( ! $out['receipt_ok'] || null === $tree ) {
			return $out;
		}
		$out['tree'] = $tree;
		$path        = (string) ( $before['path'] ?? '' );
		$at_path     = '' !== $path ? IATO_MCP_Elementor_Adapter::pointer_get( $tree, $path ) : null;
		if ( is_array( $at_path ) && (string) ( $at_path['id'] ?? '' ) === $id ) {
			$out['path'] = $path;
			$out['node'] = $at_path;
			return $out;
		}
		$first = null;
		foreach ( self::nodes_with_paths( $tree ) as [ $p, $node ] ) {
			if ( (string) ( $node['id'] ?? '' ) !== $id ) {
				continue;
			}
			$first = $first ?? [ $p, $node ];
			if ( self::canon_text( self::keys_of( $node, array_keys( $out['after'] ) ) ) === self::canon_text( $out['after'] ) ) {
				$first = [ $p, $node ];
				break;
			}
		}
		if ( null !== $first ) {
			[ $out['path'], $out['node'] ] = $first;
		}
		return $out;
	}

	/** The named setting keys of a node (null for an absent key). */
	private static function keys_of( array $node, array $keys ): array {
		$settings = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
		$out      = [];
		foreach ( $keys as $key ) {
			$out[ $key ] = array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
		}
		return $out;
	}

	/**
	 * Why an `elementor_atomic` receipt cannot be rolled back, or null:
	 * the receipt is incomplete, the element is no longer on the page, or
	 * Elementor's code is not available.
	 */
	public static function rollback_unavailable( array $receipt ): ?WP_Error {
		$target = self::receipt_target( $receipt );
		if ( ! $target['receipt_ok'] ) {
			return new WP_Error( 'rollback_unavailable', 'This receipt does not record which element it changed.', [ 'status' => 409 ] );
		}
		if ( null === $target['node'] ) {
			return new WP_Error( 'rollback_unavailable', "Element {$target['element_id']} is no longer on the page, so its settings cannot be restored. Restore the page in Elementor under History > Revisions.", [ 'status' => 409, 'element_id' => $target['element_id'] ] );
		}
		$missing = self::engine()->missing();
		if ( null !== $missing ) {
			return new WP_Error( 'rollback_unavailable', $missing . ' Rolling back an atomic element change needs it. Restore the page in Elementor under History > Revisions.', [ 'status' => 409, 'element_id' => $target['element_id'] ] );
		}
		return null;
	}

	/**
	 * Conflict check for an `elementor_atomic` receipt (IATO_MCP_Rollback_Conflict
	 * statuses): the element's current values for the receipt's keys against
	 * the values the change left (entities normalised, null = absent).
	 */
	public static function conflict( array $receipt ): array {
		$target = self::receipt_target( $receipt );
		if ( null === $target['node'] ) {
			return [ 'status' => 'unverifiable', 'reason' => 'the element is no longer on the page' ];
		}
		$current = self::keys_of( $target['node'], array_keys( $target['after'] ) );
		if ( self::canon_text( $current ) === self::canon_text( $target['after'] ) ) {
			return [ 'status' => 'match', 'reason' => "the settings of element {$target['element_id']} are as the change left them" ];
		}
		return [
			'status'   => 'conflict',
			'reason'   => "the settings of element {$target['element_id']} have changed since this change was made",
			'current'  => IATO_MCP_Elementor_Atomic::unwrap( $current ),
			'expected' => IATO_MCP_Elementor_Atomic::unwrap( $target['after'] ),
		];
	}

	/** Plain view of what a rollback restores: {element_id, keys}. */
	public static function restore_view( array $receipt ): array {
		$target = self::receipt_target( $receipt );
		return [ 'element_id' => $target['element_id'], 'keys' => IATO_MCP_Elementor_Atomic::unwrap( $target['before'] ) ];
	}

	/**
	 * Roll back an `elementor_atomic` receipt: the recorded values go back
	 * verbatim (absent keys are removed), validated with Elementor's parser,
	 * then the same page check and write as a change. No new receipt.
	 */
	public static function rollback( array $receipt, bool $dry_run = false ): bool|WP_Error {
		$bound = IATO_MCP_Auth::require_bound_user();
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}
		$post_id = (int) ( $receipt['post_id'] ?? 0 );
		$lock    = self::editor_lock( $post_id );
		if ( null !== $lock ) {
			return $lock;
		}
		$target = self::receipt_target( $receipt );
		if ( null === $target['node'] || null === $target['tree'] ) {
			return self::rollback_unavailable( $receipt ) ?? new WP_Error( 'rollback_unavailable', 'The element could not be found.', [ 'status' => 409 ] );
		}
		$node     = $target['node'];
		$settings = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
		foreach ( $target['before'] as $key => $value ) {
			if ( null === $value ) {
				unset( $settings[ $key ] );
			} else {
				$settings[ $key ] = $value;
			}
		}
		$parsed = self::engine()->parse( $node, $settings );
		if ( empty( $parsed['valid'] ) ) {
			return new WP_Error(
				'rollback_unavailable',
				"Elementor " . self::engine()->version() . " no longer accepts the recorded settings of element {$target['element_id']}: " . (string) ( $parsed['errors'] ?? '' ) . '. Restore the page in Elementor under History > Revisions.',
				[ 'status' => 409, 'element_id' => $target['element_id'], 'elementor_error' => (string) ( $parsed['errors'] ?? '' ) ]
			);
		}
		$node['settings'] = $settings;
		$tree             = $target['tree'];
		IATO_MCP_Elementor_Adapter::pointer_set( $tree, (string) $target['path'], $node, false );

		$check = self::save_check( $tree, [ $target['element_id'] ], [ $target['element_id'] => array_keys( $target['before'] ) ] );
		if ( null !== $check['refusal'] ) {
			return $check['refusal'];
		}
		if ( $dry_run ) {
			return true; // the gates and checks the real rollback runs, nothing written
		}
		$raw      = IATO_MCP_Elementor_Adapter::stored_raw( $post_id );
		$pipeline = IATO_MCP_Elementor_Adapter::write_pipeline(
			$post_id,
			$tree,
			IATO_MCP_Elementor_Adapter::compute_revision( $raw ),
			$target['tree'],
			[
				'atomic'       => true,
				'skip_save'    => $check['save'] ? null : $check['reason'],
				'skip_ids'     => $check['element_ids'],
				'atomic_paths' => [ (string) $target['path'] ],
				// The recorded values come from the receipts table, as stored by
				// Elementor's own sanitisers: restored verbatim, not sanitised again.
				'verbatim'     => true,
			]
		);
		if ( is_wp_error( $pipeline ) ) {
			return $pipeline;
		}
		self::set_mutation_marker( $post_id );
		return true;
	}

	/** Slim receipt for tool responses (the stored row keeps the full values). */
	public static function slim_receipt( array $receipt ): array {
		return [
			'change_id'   => $receipt['change_id'] ?? null,
			'target_type' => $receipt['target_type'] ?? null,
			'field'       => $receipt['field'] ?? null,
			'applied_at'  => $receipt['applied_at'] ?? null,
			'actor'       => $receipt['actor'] ?? null,
		];
	}

	// ── Verification ──────────────────────────────────────────────────────

	/**
	 * Re-read the stored page: each changed key must hold what Elementor's
	 * parser produced (entities normalised: Elementor stores & as &amp;), and
	 * every other element must be as it was before the write.
	 *
	 * @return array{verified:bool, diff:array}
	 */
	public static function verify( int $post_id, array $before_tree, array $written_tree, array $elements, array $changed_paths ): array {
		$stored = IATO_MCP_Elementor_Adapter::stored_elements( $post_id ) ?? [];
		$diff   = [];
		foreach ( $changed_paths as $path ) {
			$element  = $elements[ $path ];
			$expected = self::node_after( $written_tree, $path, $element['id'] );
			$actual   = self::node_after( $stored, $path, $element['id'] );
			foreach ( $element['keys'] as $key ) {
				$want = $expected['settings'][ $key ] ?? null;
				$have = is_array( $actual['settings'] ?? null ) ? ( $actual['settings'][ $key ] ?? null ) : null;
				if ( self::canon_text( $want ) !== self::canon_text( $have ) ) {
					$diff[] = [ 'element_id' => $element['id'], 'key' => $key, 'expected' => IATO_MCP_Elementor_Atomic::unwrap( $want ), 'stored' => IATO_MCP_Elementor_Atomic::unwrap( $have ) ];
				}
			}
		}
		$changed_ids = array_map( fn( $p ) => $elements[ $p ]['id'], $changed_paths );
		$index       = function ( array $tree ): array {
			$out = [];
			IATO_MCP_Elementor_Adapter::walk_widgets( $tree, function ( array $element ) use ( &$out ) {
				$id = (string) ( $element['id'] ?? '' );
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = self::canon( [ 'settings' => $element['settings'] ?? [], 'styles' => $element['styles'] ?? [] ] );
				}
			} );
			return $out;
		};
		$was = $index( $before_tree );
		$now = $index( $stored );
		foreach ( $was as $id => $value ) {
			if ( in_array( (string) $id, $changed_ids, true ) ) {
				continue;
			}
			if ( ( $now[ $id ] ?? null ) !== $value ) {
				$diff[] = [ 'element_id' => (string) $id, 'untouched' => true, 'reason' => isset( $now[ $id ] ) ? 'changed' : 'missing' ];
			}
		}
		return [ 'verified' => empty( $diff ), 'diff' => $diff ];
	}

	// ── Errors ────────────────────────────────────────────────────────────

	private static function error( string $code, string $message, string $id, string $type, array $data = [] ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => 400, 'element_id' => $id, 'type' => $type ] + $data );
	}

	private static function with_element( WP_Error $error, string $id, string $type ): WP_Error {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];
		return new WP_Error( $error->get_error_code(), "Element {$id} ({$type}): " . $error->get_error_message(), [ 'element_id' => $id, 'type' => $type ] + $data );
	}

	private static function unsupported_keys( string $id, string $type, array $unknown, array $refused, array $schema_keys, array $aliases ): WP_Error {
		$parts = [];
		foreach ( $refused as $key ) {
			$parts[] = "`{$key}`: " . self::REFUSED_KEYS[ $key ];
		}
		$hints = [];
		foreach ( $unknown as $key ) {
			$parts[] = "`{$key}` is not a setting of {$type}";
			if ( isset( self::KEY_HINTS[ $key ] ) ) {
				$hints[ $key ] = self::KEY_HINTS[ $key ];
			}
		}
		$supported = array_values( array_diff( $schema_keys, array_keys( self::REFUSED_KEYS ) ) );
		$message   = "Element {$id} ({$type}): " . implode( '; ', $parts ) . '. Its settings are ' . self::quote_list( $supported ) . '.';
		foreach ( $hints as $key => $hint ) {
			$message .= " For `{$key}`: on atomic elements {$hint}.";
		}
		$message .= ' Nothing was written.';
		$data = [ 'unknown' => $unknown, 'supported' => $supported ];
		if ( $refused ) {
			$data['refused'] = $refused;
		}
		if ( $aliases ) {
			$data['aliases'] = $aliases;
		}
		return self::error( 'atomic_prop_not_supported', $message, $id, $type, $data );
	}

	private static function unresolvable( string $dot, mixed $plain ): WP_Error {
		$shown = wp_json_encode( $plain, JSON_UNESCAPED_SLASHES );
		if ( is_string( $shown ) && strlen( $shown ) > 120 ) {
			$shown = substr( $shown, 0, 117 ) . '...';
		}
		return new WP_Error(
			'atomic_value_unresolvable',
			"Elementor could not convert {$shown} for `{$dot}`. Use the shape get_elementor_widget returns under settings_plain for the element. Nothing was written.",
			[ 'status' => 400, 'path' => $dot ]
		);
	}

	/**
	 * Elementor's validation failed. When it names keys the patch did not
	 * send, the element already held an invalid value there (stored without
	 * its typed wrapper by IATO 1.12.x / 1.13.0): say how to repair it.
	 */
	private static function invalid_settings( string $id, string $type, array $parsed, array $sent ): WP_Error {
		$error_keys = array_values( array_map( fn( $k ) => explode( '.', (string) $k )[0], (array) ( $parsed['error_keys'] ?? [] ) ) );
		$other      = array_values( array_diff( array_unique( $error_keys ), $sent ) );
		$message    = "Elementor's validation rejects the settings of element {$id} ({$type}): " . (string) ( $parsed['errors'] ?? '' ) . '.';
		if ( $other ) {
			$message .= ' The element already holds an invalid value in ' . self::quote_list( $other ) . ' (an earlier IATO version could store a plain value there); send ' . ( count( $other ) > 1 ? 'those settings' : 'that setting' ) . ' again through this tool to repair the element.';
		} else {
			$message .= ' Check the value against the element\'s settings (get_elementor_widget).';
		}
		$message .= ' Nothing was written.';
		return self::error( 'atomic_invalid_settings', $message, $id, $type, [ 'elementor_error' => (string) ( $parsed['errors'] ?? '' ), 'invalid_keys' => array_values( array_unique( $error_keys ) ), 'stored_invalid_keys' => $other ] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/** Attachment id of a stored Media Library image value ({image: {src: {id}}}), or null. */
	private static function library_image_id( mixed $stored ): ?int {
		if ( ! IATO_MCP_Elementor_Atomic::is_envelope( $stored ) || ! is_array( $stored['value'] ) ) {
			return null;
		}
		$src = $stored['value']['src'] ?? null;
		if ( ! IATO_MCP_Elementor_Atomic::is_envelope( $src ) || ! is_array( $src['value'] ) ) {
			return null;
		}
		$id = IATO_MCP_Elementor_Atomic::unwrap( $src['value']['id'] ?? null );
		$id = is_numeric( $id ) ? (int) $id : 0;
		return $id > 0 ? $id : null;
	}

	/** The alt text of a plain {src: {alt}} value and nothing else, or null. */
	private static function alt_only( mixed $plain ): ?string {
		if ( ! is_array( $plain ) || IATO_MCP_Elementor_Atomic::is_envelope( $plain ) || array_keys( $plain ) !== [ 'src' ] ) {
			return null;
		}
		$src = $plain['src'];
		if ( ! is_array( $src ) || IATO_MCP_Elementor_Atomic::is_envelope( $src ) || array_keys( $src ) !== [ 'alt' ] ) {
			return null;
		}
		$alt = IATO_MCP_Elementor_Atomic::is_envelope( $src['alt'] ) ? IATO_MCP_Elementor_Atomic::unwrap( $src['alt'] ) : $src['alt'];
		return is_scalar( $alt ) ? (string) $alt : null;
	}

	private static function touches_alt( mixed $plain ): bool {
		return is_array( $plain ) && ! IATO_MCP_Elementor_Atomic::is_envelope( $plain )
			&& is_array( $plain['src'] ?? null ) && ! IATO_MCP_Elementor_Atomic::is_envelope( $plain['src'] )
			&& array_key_exists( 'alt', $plain['src'] );
	}

	private static function sets_src( mixed $plain, string $field ): bool {
		return is_array( $plain ) && is_array( $plain['src'] ?? null ) && isset( $plain['src'][ $field ] );
	}

	/** Sentinel for at_path(). */
	private static function missing(): string {
		return "\0missing";
	}

	/** The typed value at a settings path ([key, sub, …]), or missing(). */
	private static function at_path( array $settings, array $path ): mixed {
		$key = (string) array_shift( $path );
		if ( ! array_key_exists( $key, $settings ) ) {
			return self::missing();
		}
		$value = $settings[ $key ];
		foreach ( $path as $sub ) {
			if ( ! IATO_MCP_Elementor_Atomic::is_envelope( $value ) || ! is_array( $value['value'] ) || ! array_key_exists( (string) $sub, $value['value'] ) ) {
				return self::missing();
			}
			$value = $value['value'][ (string) $sub ];
		}
		return $value;
	}

	/**
	 * A URL value the request set that Elementor's sanitising emptied, at the
	 * leaf or nested inside a typed value the caller sent whole (a link
	 * envelope with a javascript: destination).
	 */
	private static function url_emptied( mixed $sent, mixed $kept, int $depth = 0 ): bool {
		if ( ! IATO_MCP_Elementor_Atomic::is_envelope( $sent ) || $depth > 8 ) {
			return false;
		}
		if ( 'url' === $sent['$$type'] ) {
			$was = is_scalar( $sent['value'] ) ? trim( (string) $sent['value'] ) : '';
			$now = IATO_MCP_Elementor_Atomic::is_envelope( $kept ) && is_scalar( $kept['value'] ) ? trim( (string) $kept['value'] ) : '';
			return '' !== $was && '' === $now;
		}
		if ( ! is_array( $sent['value'] ) ) {
			return false;
		}
		$kept_value = IATO_MCP_Elementor_Atomic::is_envelope( $kept ) && is_array( $kept['value'] ) ? $kept['value'] : [];
		foreach ( $sent['value'] as $key => $sub ) {
			if ( self::url_emptied( $sub, $kept_value[ $key ] ?? null, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stored sub-values of an object prop that the new value no longer holds,
	 * other than the ones the request removed. Dotted paths.
	 */
	private static function subkeys_lost( mixed $stored, mixed $final, string $prefix, array $cleared ): array {
		if ( ! IATO_MCP_Elementor_Atomic::is_envelope( $stored ) || ! is_array( $stored['value'] ) || self::is_list( $stored['value'] ) ) {
			return [];
		}
		if ( ! IATO_MCP_Elementor_Atomic::is_envelope( $final ) || $final['$$type'] !== $stored['$$type'] || ! is_array( $final['value'] ) ) {
			return [];
		}
		$lost = [];
		foreach ( $stored['value'] as $sub => $value ) {
			$path = $prefix . '.' . $sub;
			if ( null === $value || in_array( $path, $cleared, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $sub, $final['value'] ) || null === $final['value'][ $sub ] ) {
				$lost[] = $path;
				continue;
			}
			$lost = array_merge( $lost, self::subkeys_lost( $value, $final['value'][ $sub ], $path, $cleared ) );
		}
		return $lost;
	}

	/** Plain sub-keys (non-null) that are missing from Elementor's converted value (its Dropped_Plain_Keys_Finder). */
	private static function dropped_plain_keys( mixed $plain, mixed $resolved, string $path ): array {
		$plain_fields    = IATO_MCP_Elementor_Atomic::is_envelope( $plain ) ? $plain['value'] : $plain;
		$resolved_fields = IATO_MCP_Elementor_Atomic::is_envelope( $resolved ) ? $resolved['value'] : null;
		if ( ! is_array( $plain_fields ) || [] === $plain_fields || self::is_list( $plain_fields ) || ! is_array( $resolved_fields ) ) {
			return [];
		}
		$dropped = [];
		foreach ( $plain_fields as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			$sub = $path . '.' . $key;
			if ( ! array_key_exists( $key, $resolved_fields ) ) {
				$dropped[] = $sub;
				continue;
			}
			$dropped = array_merge( $dropped, self::dropped_plain_keys( $value, $resolved_fields[ $key ], $sub ) );
		}
		return $dropped;
	}

	/**
	 * Canonical JSON for comparisons: map keys sorted, null map entries
	 * dropped (Elementor drops them on save), scalars as strings.
	 */
	public static function canon( mixed $value ): string {
		return (string) wp_json_encode( self::canon_value( $value, false ) );
	}

	/** canon() with HTML entities decoded in strings (Elementor stores & as &amp;). */
	public static function canon_text( mixed $value ): string {
		return (string) wp_json_encode( self::canon_value( $value, true ) );
	}

	private static function canon_value( mixed $value, bool $decode ): mixed {
		if ( is_array( $value ) ) {
			$list = self::is_list( $value );
			$out  = [];
			foreach ( $value as $k => $v ) {
				if ( null === $v && ! $list ) {
					continue;
				}
				$out[ $k ] = self::canon_value( $v, $decode );
			}
			if ( ! $list ) {
				ksort( $out, SORT_STRING );
			}
			return $out;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( null === $value ) {
			return null;
		}
		$value = (string) $value;
		return $decode ? html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $value;
	}

	private static function is_list( array $value ): bool {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function quote_list( array $items ): string {
		return implode( ', ', array_map( fn( $i ) => '`' . $i . '`', $items ) );
	}
}
