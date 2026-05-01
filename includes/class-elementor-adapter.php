<?php
/**
 * Elementor Adapter — JSON tree walking, widget lookup, settings merge,
 * RFC 6902 patch application, format flatteners, and the write pipeline
 * for v2 widget-grained tools.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Elementor_Adapter {

	/** @var array<int,bool> Per-request cache of "post_id has elementor data". */
	private static array $has_data_cache = [];

	/**
	 * Compute the canonical revision hash from a stored Elementor JSON string.
	 * Empty/null inputs hash deterministically — empty string maps to a stable
	 * "no data" hash so revision checks on uninitialized posts don't get a free
	 * pass.
	 */
	public static function compute_revision( string $data_str ): string {
		return 'sha256:' . hash( 'sha256', $data_str );
	}

	/**
	 * Read _elementor_data and return a decoded array of top-level elements.
	 *
	 * Returns WP_Error('not_elementor', ...) when meta is absent or the post
	 * isn't in builder mode. Returns WP_Error('invalid_json', ...) on decode
	 * failure (rare — Elementor itself stores valid JSON).
	 *
	 * @return array{0:array,1:string}|WP_Error On success: [decoded_elements, raw_json_string].
	 */
	public static function decode_data( int $post_id ): array|WP_Error {
		if ( $post_id <= 0 ) {
			return new WP_Error( 'invalid_id', 'Post ID must be positive.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', "Post {$post_id} not found." );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return new WP_Error(
				'not_elementor',
				"Post {$post_id} has no Elementor data. Use get_page_builder to confirm."
			);
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'invalid_json', 'Stored Elementor data is invalid JSON: ' . json_last_error_msg() );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'invalid_json', 'Stored Elementor data is not a JSON array.' );
		}

		$decoded = self::force_arrays( $decoded );
		return [ $decoded, (string) $raw ];
	}

	/**
	 * Recursive depth-first walker. Visits every element in the tree
	 * (sections, columns, widgets, inner sections). The visitor receives the
	 * element by-value plus parent_id and depth context.
	 *
	 * @param callable $visitor function(array $element, ?string $parent_id, int $depth): void
	 */
	public static function walk_widgets(
		array $elements,
		callable $visitor,
		?string $parent_id = null,
		int $depth = 0
	): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$visitor( $element, $parent_id, $depth );
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$child_parent = isset( $element['id'] ) ? (string) $element['id'] : $parent_id;
				self::walk_widgets( $element['elements'], $visitor, $child_parent, $depth + 1 );
			}
		}
	}

	/**
	 * Locate a widget by id within an element tree. Returns a structure
	 * including a JSON Pointer path so callers can edit the in-tree node
	 * via apply_rfc6902 (which mutates by path) rather than a by-ref hack.
	 *
	 * Path format: /0/elements/2/elements/0
	 *
	 * @return array{path:string,element:array,parent_id:?string,depth:int}|null
	 */
	public static function find_widget( array $elements, string $widget_id ): ?array {
		return self::find_widget_recursive( $elements, $widget_id, '', null, 0 );
	}

	private static function find_widget_recursive(
		array $elements,
		string $widget_id,
		string $path_prefix,
		?string $parent_id,
		int $depth
	): ?array {
		foreach ( $elements as $i => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$current_path = $path_prefix . '/' . $i;
			if ( isset( $element['id'] ) && (string) $element['id'] === $widget_id ) {
				return [
					'path'      => $current_path,
					'element'   => $element,
					'parent_id' => $parent_id,
					'depth'     => $depth,
				];
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$child_parent = isset( $element['id'] ) ? (string) $element['id'] : $parent_id;
				$found        = self::find_widget_recursive(
					$element['elements'],
					$widget_id,
					$current_path . '/elements',
					$child_parent,
					$depth + 1
				);
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Build a flat or tree representation of all widgets in a document.
	 *
	 * 'flat' returns a depth-first list with parent_id pointers — easy for
	 * clients to render or filter without recursion.
	 *
	 * 'tree' returns a nested structure mirroring the source document but
	 * stripped of full settings (peek fields only). Useful for context.
	 *
	 * @param string $format 'flat' | 'tree'
	 */
	public static function flatten_widgets( array $elements, string $format = 'flat' ): array {
		if ( 'tree' === $format ) {
			return array_values( array_filter(
				array_map( [ self::class, 'tree_node' ], $elements )
			) );
		}

		$out = [];
		self::walk_widgets( $elements, function ( array $element, ?string $parent_id, int $depth ) use ( &$out ) {
			$type = (string) ( $element['elType'] ?? 'unknown' );
			$node = [
				'widget_id' => isset( $element['id'] ) ? (string) $element['id'] : null,
				'type'      => 'widget' === $type ? (string) ( $element['widgetType'] ?? 'widget' ) : $type,
				'parent_id' => $parent_id,
				'depth'     => $depth,
			];
			$peek = self::peek_fields( $element );
			if ( ! empty( $peek ) ) {
				$node = array_merge( $node, $peek );
			}
			$out[] = $node;
		} );
		return $out;
	}

	private static function tree_node( $element ): ?array {
		if ( ! is_array( $element ) ) {
			return null;
		}
		$type = (string) ( $element['elType'] ?? 'unknown' );
		$node = [
			'widget_id' => isset( $element['id'] ) ? (string) $element['id'] : null,
			'type'      => 'widget' === $type ? (string) ( $element['widgetType'] ?? 'widget' ) : $type,
		];
		$peek = self::peek_fields( $element );
		if ( ! empty( $peek ) ) {
			$node = array_merge( $node, $peek );
		}
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$node['children'] = array_values( array_filter(
				array_map( [ self::class, 'tree_node' ], $element['elements'] )
			) );
		}
		return $node;
	}

	/**
	 * Pluck the 1–3 most useful settings fields per widget for the flat list.
	 * Heuristic: the fields humans use to identify a widget at a glance.
	 */
	private static function peek_fields( array $element ): array {
		$settings = $element['settings'] ?? [];
		if ( ! is_array( $settings ) ) {
			return [];
		}
		$out = [];
		foreach ( [ 'title', 'header_size', 'text', 'editor', 'link' ] as $key ) {
			if ( isset( $settings[ $key ] ) && ( is_string( $settings[ $key ] ) || is_numeric( $settings[ $key ] ) ) ) {
				$value         = (string) $settings[ $key ];
				$out[ $key ]   = strlen( $value ) > 80 ? substr( $value, 0, 80 ) . '…' : $value;
			}
		}
		return $out;
	}

	/**
	 * Build a summary view of a single widget — id, type, peek fields only.
	 * Used by 'summary' format and the get_elementor_widget response when
	 * the client wants minimal payload.
	 */
	public static function summary( array $element ): array {
		$type = (string) ( $element['elType'] ?? 'unknown' );
		$out  = [
			'widget_id' => isset( $element['id'] ) ? (string) $element['id'] : null,
			'type'      => 'widget' === $type ? (string) ( $element['widgetType'] ?? 'widget' ) : $type,
		];
		$peek = self::peek_fields( $element );
		if ( ! empty( $peek ) ) {
			$out = array_merge( $out, $peek );
		}
		return $out;
	}

	/**
	 * Strip settings keys whose value matches the canonical default for
	 * this widget type. Reduces wire size for 'compact' format.
	 *
	 * Falls open: unknown widget types are returned unchanged. Better to
	 * over-include than to silently drop fields the client may need.
	 */
	public static function compact( array $element ): array {
		$type     = (string) ( $element['widgetType'] ?? $element['elType'] ?? '' );
		$defaults = self::defaults_table()[ $type ] ?? null;

		if ( null === $defaults || ! is_array( $element['settings'] ?? null ) ) {
			return $element;
		}

		$compacted_settings = [];
		foreach ( $element['settings'] as $key => $value ) {
			if ( array_key_exists( $key, $defaults ) && self::loose_equals( $defaults[ $key ], $value ) ) {
				continue;
			}
			$compacted_settings[ $key ] = $value;
		}
		$element['settings'] = $compacted_settings;
		return $element;
	}

	/**
	 * Lazy-load the per-widget defaults table from the data file.
	 */
	public static function defaults_table(): array {
		static $cached = null;
		if ( null === $cached ) {
			$path = IATO_MCP_DIR . 'includes/data/elementor-defaults.php';
			if ( file_exists( $path ) ) {
				$cached = require $path;
				if ( ! is_array( $cached ) ) {
					$cached = [];
				}
			} else {
				$cached = [];
			}
		}
		return $cached;
	}

	/**
	 * Recursive cast of stdClass → assoc array. Elementor's AI module filter
	 * expects array elements; json_decode with assoc=true handles top level
	 * but nested objects can sneak through if the input was decoded
	 * non-associatively earlier in the request. Defensive only.
	 */
	public static function force_arrays( mixed $data ): mixed {
		if ( is_object( $data ) ) {
			$data = (array) $data;
		}
		if ( is_array( $data ) ) {
			$out = [];
			foreach ( $data as $k => $v ) {
				$out[ $k ] = self::force_arrays( $v );
			}
			return $out;
		}
		return $data;
	}

	/**
	 * Compare two settings values for "match" — null/missing equivalence,
	 * array-vs-array deep compare, scalar strict.
	 */
	private static function loose_equals( mixed $a, mixed $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		if ( ( null === $a && '' === $b ) || ( '' === $a && null === $b ) ) {
			return true;
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) ) {
				return false;
			}
			foreach ( $a as $k => $v ) {
				if ( ! array_key_exists( $k, $b ) || ! self::loose_equals( $v, $b[ $k ] ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	// ── Settings patch + RFC 6902 ──────────────────────────────────────────

	/**
	 * Apply a flat settings_patch to a widget's settings. Returns RFC 6902
	 * ops describing every change made — replace for existing keys, add for
	 * new keys, remove for null values. Each op carries previous_value.
	 *
	 * Replace-only semantics for arrays: if the patch sets `key: [array]`,
	 * the entire existing array is replaced (no deep merge). Use
	 * apply_rfc6902 with explicit indexed ops for surgical array edits.
	 *
	 * Mutates $element['settings'] in place.
	 */
	public static function apply_settings_patch( array &$element, array $patch, string $widget_path ): array {
		if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
			$element['settings'] = [];
		}

		$applied = [];
		foreach ( $patch as $key => $new_value ) {
			$pointer = $widget_path . '/settings/' . self::escape_pointer_segment( (string) $key );
			$exists  = array_key_exists( $key, $element['settings'] );
			$prev    = $exists ? $element['settings'][ $key ] : null;

			if ( null === $new_value ) {
				if ( ! $exists ) {
					continue;
				}
				unset( $element['settings'][ $key ] );
				$applied[] = [
					'op'             => 'remove',
					'path'           => $pointer,
					'previous_value' => $prev,
				];
				continue;
			}

			if ( $exists ) {
				if ( self::loose_equals( $prev, $new_value ) ) {
					continue;
				}
				$element['settings'][ $key ] = $new_value;
				$applied[]                    = [
					'op'             => 'replace',
					'path'           => $pointer,
					'value'          => $new_value,
					'previous_value' => $prev,
				];
			} else {
				$element['settings'][ $key ] = $new_value;
				$applied[]                    = [
					'op'    => 'add',
					'path'  => $pointer,
					'value' => $new_value,
				];
			}
		}

		return $applied;
	}

	/**
	 * Apply an RFC 6902 patch to the elements tree. Returns ops enriched
	 * with previous_value where applicable.
	 *
	 * Implements: add, remove, replace, move, copy, test.
	 * Strict path semantics: replace/remove/move/copy fail on missing paths.
	 *
	 * @return array|WP_Error Applied ops on success.
	 */
	public static function apply_rfc6902( array &$elements, array $ops ): array|WP_Error {
		$applied = [];
		foreach ( $ops as $i => $op ) {
			if ( ! is_array( $op ) || ! isset( $op['op'], $op['path'] ) ) {
				return new WP_Error( 'invalid_patch', "Op #{$i} missing 'op' or 'path'." );
			}
			$result = self::apply_one_op( $elements, $op );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$applied[] = $result;
		}
		return $applied;
	}

	private static function apply_one_op( array &$elements, array $op ): array|WP_Error {
		$type = (string) $op['op'];
		$path = (string) $op['path'];
		switch ( $type ) {
			case 'add':
				if ( ! array_key_exists( 'value', $op ) ) {
					return new WP_Error( 'invalid_patch', "add op missing 'value' at {$path}" );
				}
				$err = self::pointer_set( $elements, $path, $op['value'], true );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
				return [ 'op' => 'add', 'path' => $path, 'value' => $op['value'] ];

			case 'replace':
				if ( ! array_key_exists( 'value', $op ) ) {
					return new WP_Error( 'invalid_patch', "replace op missing 'value' at {$path}" );
				}
				$prev = self::pointer_get( $elements, $path );
				if ( is_wp_error( $prev ) ) {
					return $prev;
				}
				$err = self::pointer_set( $elements, $path, $op['value'], false );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
				return [
					'op'             => 'replace',
					'path'           => $path,
					'value'          => $op['value'],
					'previous_value' => $prev,
				];

			case 'remove':
				$prev = self::pointer_get( $elements, $path );
				if ( is_wp_error( $prev ) ) {
					return $prev;
				}
				$err = self::pointer_remove( $elements, $path );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
				return [ 'op' => 'remove', 'path' => $path, 'previous_value' => $prev ];

			case 'move':
				$from = (string) ( $op['from'] ?? '' );
				if ( '' === $from ) {
					return new WP_Error( 'invalid_patch', "move op missing 'from' at {$path}" );
				}
				$value = self::pointer_get( $elements, $from );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$err = self::pointer_remove( $elements, $from );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
				$err = self::pointer_set( $elements, $path, $value, true );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
				return [ 'op' => 'move', 'from' => $from, 'path' => $path, 'value' => $value ];

			case 'copy':
				$from = (string) ( $op['from'] ?? '' );
				if ( '' === $from ) {
					return new WP_Error( 'invalid_patch', "copy op missing 'from' at {$path}" );
				}
				$value = self::pointer_get( $elements, $from );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$err = self::pointer_set( $elements, $path, $value, true );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
				return [ 'op' => 'copy', 'from' => $from, 'path' => $path, 'value' => $value ];

			case 'test':
				$actual = self::pointer_get( $elements, $path );
				if ( is_wp_error( $actual ) ) {
					return $actual;
				}
				$expected = $op['value'] ?? null;
				if ( ! self::loose_equals( $actual, $expected ) ) {
					return new WP_Error( 'patch_test_failed', "test op failed at {$path}" );
				}
				return [ 'op' => 'test', 'path' => $path, 'value' => $expected ];

			default:
				return new WP_Error( 'invalid_patch', "Unknown op '{$type}'" );
		}
	}

	// ── JSON Pointer (RFC 6901) ────────────────────────────────────────────

	private static function pointer_segments( string $path ): array {
		if ( '' === $path || '/' !== $path[0] ) {
			return [];
		}
		$parts = explode( '/', substr( $path, 1 ) );
		return array_map( fn( $p ) => str_replace( [ '~1', '~0' ], [ '/', '~' ], $p ), $parts );
	}

	private static function escape_pointer_segment( string $segment ): string {
		return str_replace( [ '~', '/' ], [ '~0', '~1' ], $segment );
	}

	/**
	 * Walk a JSON Pointer to its target node. Returns the value or WP_Error.
	 * For root path '' returns the whole array.
	 *
	 * @param array $root
	 * @return mixed|WP_Error
	 */
	public static function pointer_get( array $root, string $path ) {
		if ( '' === $path ) {
			return $root;
		}
		$segs = self::pointer_segments( $path );
		$node = $root;
		foreach ( $segs as $seg ) {
			if ( is_array( $node ) ) {
				if ( array_key_exists( $seg, $node ) ) {
					$node = $node[ $seg ];
					continue;
				}
				if ( ctype_digit( $seg ) && array_key_exists( (int) $seg, $node ) ) {
					$node = $node[ (int) $seg ];
					continue;
				}
				return new WP_Error( 'invalid_patch_path', "Path not found: {$path}" );
			}
			return new WP_Error( 'invalid_patch_path', "Cannot traverse non-array at {$path}" );
		}
		return $node;
	}

	/**
	 * Set or add a value at a JSON Pointer path. Mutates by reference.
	 *
	 * @param bool $allow_add When true, intermediate-missing keys may be added (RFC 'add').
	 *                       When false (RFC 'replace'), the leaf must already exist.
	 */
	public static function pointer_set( array &$root, string $path, mixed $value, bool $allow_add ): null|WP_Error {
		if ( '' === $path ) {
			return new WP_Error( 'invalid_patch_path', 'Cannot replace root document.' );
		}
		$segs = self::pointer_segments( $path );
		$last = array_pop( $segs );
		$node = &$root;
		foreach ( $segs as $seg ) {
			if ( ! is_array( $node ) ) {
				return new WP_Error( 'invalid_patch_path', "Cannot traverse non-array at {$path}" );
			}
			if ( array_key_exists( $seg, $node ) ) {
				$node = &$node[ $seg ];
				continue;
			}
			if ( ctype_digit( $seg ) && array_key_exists( (int) $seg, $node ) ) {
				$node = &$node[ (int) $seg ];
				continue;
			}
			return new WP_Error( 'invalid_patch_path', "Path not found: {$path}" );
		}
		if ( ! is_array( $node ) ) {
			return new WP_Error( 'invalid_patch_path', "Cannot set on non-array at {$path}" );
		}
		// Array index handling — '-' = append, numeric = insert/replace.
		if ( '-' === $last ) {
			if ( ! $allow_add ) {
				return new WP_Error( 'invalid_patch_path', "Cannot replace at append marker '-'" );
			}
			$node[] = $value;
			return null;
		}
		$key = ctype_digit( $last ) ? (int) $last : $last;
		if ( ! array_key_exists( $key, $node ) ) {
			if ( ! $allow_add ) {
				return new WP_Error( 'invalid_patch_path', "Path not found: {$path}" );
			}
		}
		$node[ $key ] = $value;
		return null;
	}

	/**
	 * Remove a value at a JSON Pointer path. Mutates by reference.
	 */
	public static function pointer_remove( array &$root, string $path ): null|WP_Error {
		if ( '' === $path ) {
			return new WP_Error( 'invalid_patch_path', 'Cannot remove root document.' );
		}
		$segs = self::pointer_segments( $path );
		$last = array_pop( $segs );
		$node = &$root;
		foreach ( $segs as $seg ) {
			if ( ! is_array( $node ) ) {
				return new WP_Error( 'invalid_patch_path', "Cannot traverse non-array at {$path}" );
			}
			if ( array_key_exists( $seg, $node ) ) {
				$node = &$node[ $seg ];
				continue;
			}
			if ( ctype_digit( $seg ) && array_key_exists( (int) $seg, $node ) ) {
				$node = &$node[ (int) $seg ];
				continue;
			}
			return new WP_Error( 'invalid_patch_path', "Path not found: {$path}" );
		}
		$key = ctype_digit( $last ) ? (int) $last : $last;
		if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
			return new WP_Error( 'invalid_patch_path', "Path not found: {$path}" );
		}
		unset( $node[ $key ] );
		// Re-index numeric arrays so subsequent path resolution doesn't break.
		if ( array_keys( $node ) === range( 0, count( $node ) - 1 ) ) {
			$node = array_values( $node );
		}
		return null;
	}

	// ── find_by_filter ────────────────────────────────────────────────────

	/**
	 * Walk all widgets in $elements and return those matching $filter.
	 *
	 * Filter shape: {
	 *   type?: string,                    // matches widgetType
	 *   setting: { key: { op: value } }   // op = eq | ne | in | nin | exists ; bare value = eq
	 * }
	 *
	 * @return array list of { post_id?, widget_id, type, ...peek }
	 */
	public static function find_by_filter( array $elements, array $filter, ?int $post_id = null ): array {
		$out = [];
		self::walk_widgets( $elements, function ( array $element ) use ( &$out, $filter, $post_id ) {
			if ( ! self::matches_filter( $element, $filter ) ) {
				return;
			}
			$type    = (string) ( $element['elType'] ?? 'unknown' );
			$summary = [
				'widget_id' => isset( $element['id'] ) ? (string) $element['id'] : null,
				'type'      => 'widget' === $type ? (string) ( $element['widgetType'] ?? 'widget' ) : $type,
			];
			if ( null !== $post_id ) {
				$summary = [ 'post_id' => $post_id ] + $summary;
			}
			$peek = self::peek_fields( $element );
			if ( ! empty( $peek ) ) {
				$summary = array_merge( $summary, $peek );
			}
			$out[] = $summary;
		} );
		return $out;
	}

	private static function matches_filter( array $element, array $filter ): bool {
		// Container types (section/column) don't match unless explicitly typed-out.
		$el_type        = (string) ( $element['elType'] ?? '' );
		$is_widget      = 'widget' === $el_type;
		$concrete_type  = $is_widget ? (string) ( $element['widgetType'] ?? 'widget' ) : $el_type;

		if ( ! empty( $filter['type'] ) ) {
			if ( $concrete_type !== $filter['type'] ) {
				return false;
			}
		} elseif ( ! $is_widget ) {
			// Untyped filter targets only widgets to keep "all heading widgets" type queries clean.
			return false;
		}

		if ( empty( $filter['setting'] ) || ! is_array( $filter['setting'] ) ) {
			return true;
		}

		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		foreach ( $filter['setting'] as $key => $clause ) {
			if ( ! self::matches_setting( $settings, (string) $key, $clause ) ) {
				return false;
			}
		}
		return true;
	}

	private static function matches_setting( array $settings, string $key, mixed $clause ): bool {
		$has   = array_key_exists( $key, $settings );
		$value = $has ? $settings[ $key ] : null;

		// Bare scalar = eq.
		if ( ! is_array( $clause ) ) {
			return $has && self::loose_equals( $value, $clause );
		}

		foreach ( $clause as $op => $expected ) {
			switch ( (string) $op ) {
				case 'eq':
					if ( ! $has || ! self::loose_equals( $value, $expected ) ) {
						return false;
					}
					break;
				case 'ne':
					if ( $has && self::loose_equals( $value, $expected ) ) {
						return false;
					}
					break;
				case 'in':
					if ( ! is_array( $expected ) || ! $has ) {
						return false;
					}
					$found = false;
					foreach ( $expected as $candidate ) {
						if ( self::loose_equals( $value, $candidate ) ) {
							$found = true;
							break;
						}
					}
					if ( ! $found ) {
						return false;
					}
					break;
				case 'nin':
					if ( ! is_array( $expected ) ) {
						return false;
					}
					if ( $has ) {
						foreach ( $expected as $candidate ) {
							if ( self::loose_equals( $value, $candidate ) ) {
								return false;
							}
						}
					}
					break;
				case 'exists':
					if ( (bool) $expected !== $has ) {
						return false;
					}
					break;
				default:
					return false;
			}
		}
		return true;
	}

	// ── Write pipeline ────────────────────────────────────────────────────

	/**
	 * Persist a modified element tree back into the post. Mirrors the v1
	 * update_elementor_data flow (tool-page-builder.php:179-247):
	 *
	 *   1. wp_slash + json_encode + update_post_meta('_elementor_data')
	 *   2. update_post_meta('_elementor_edit_mode', 'builder')
	 *   3. delete _elementor_css meta + cache invalidation + Elementor cache clear
	 *   4. \Elementor\Plugin::$instance->documents->get($id)->save(['elements' => $decoded])
	 *   5. If post_content didn't update, force via Frontend::get_builder_content
	 *   6. Verify meta length matches input
	 *
	 * Returns a status array. Caller is responsible for revision computation
	 * — both `previous_revision` (compared on entry) and `current_revision`
	 * (computed on the just-written string) are passed back here so callers
	 * don't re-read meta.
	 *
	 * @param array  $decoded_elements Mutated elements array.
	 * @param string $previous_revision Revision hash captured before mutation.
	 *
	 * @return array{
	 *   previous_revision:string,
	 *   current_revision:string,
	 *   content_updated:bool,
	 *   post_content_length:int,
	 *   meta_persisted:bool,
	 *   meta_length:int
	 * }|WP_Error
	 */
	public static function write_pipeline(
		int $post_id,
		array $decoded_elements,
		string $previous_revision
	): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', "Post {$post_id} not found." );
		}

		$old_content = (string) get_post_field( 'post_content', $post_id );
		$encoded     = wp_json_encode( $decoded_elements );
		if ( false === $encoded ) {
			return new WP_Error( 'encode_failed', 'Failed to JSON-encode the modified Elementor tree.' );
		}

		// 1. Write meta.
		update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		// 2. Cache clear.
		delete_post_meta( $post_id, '_elementor_css' );
		wp_cache_delete( $post_id, 'post_meta' );
		wp_cache_delete( $post_id, 'posts' );

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
				$plugin->files_manager->clear_cache();
			}
		}

		// 3. Document::save() to render post_content.
		$regenerated = false;
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );
			if ( $document ) {
				$document->save( [ 'elements' => self::force_arrays( $decoded_elements ) ] );
				$regenerated = true;
			}
		}

		// 4. Verify post_content actually changed.
		wp_cache_delete( $post_id, 'posts' );
		wp_cache_delete( $post_id, 'post_meta' );
		$new_content     = (string) get_post_field( 'post_content', $post_id );
		$content_updated = ( $new_content !== $old_content );

		// 5. Frontend fallback.
		if ( $regenerated && ! $content_updated && class_exists( '\Elementor\Plugin' ) ) {
			$frontend = \Elementor\Plugin::instance()->frontend;
			if ( $frontend && method_exists( $frontend, 'get_builder_content' ) ) {
				$rendered = $frontend->get_builder_content( $post_id, true );
				if ( ! empty( $rendered ) && $rendered !== $old_content ) {
					wp_update_post( [
						'ID'           => $post_id,
						'post_content' => $rendered,
					] );
					$new_content     = $rendered;
					$content_updated = true;
				}
			}
		}

		// 6. Verify meta persisted.
		wp_cache_delete( $post_id, 'post_meta' );
		$persisted_meta   = (string) get_post_meta( $post_id, '_elementor_data', true );
		$meta_persisted   = ( strlen( $persisted_meta ) === strlen( $encoded ) );
		$current_revision = self::compute_revision( $persisted_meta );

		return [
			'previous_revision'   => $previous_revision,
			'current_revision'    => $current_revision,
			'content_updated'     => $content_updated,
			'post_content_length' => strlen( $new_content ),
			'meta_persisted'      => $meta_persisted,
			'meta_length'         => strlen( $persisted_meta ),
		];
	}

	/**
	 * Single-widget update entrypoint shared by update_elementor_widget,
	 * set_heading_level, set_widget_setting. Implements the full handler
	 * body so the helper tools can call it directly without going through
	 * the MCP server's tools/call dispatcher.
	 *
	 * Required args: id, widget_id, settings_patch.
	 * Optional args: dry_run, if_revision, idempotency_key.
	 *
	 * Returns the response data array (with applied_patch, revisions, etc.)
	 * or WP_Error.
	 */
	public static function do_update_widget( array $args ): array|WP_Error {
		$post_id        = absint( $args['id'] ?? 0 );
		$widget_id      = isset( $args['widget_id'] ) ? (string) $args['widget_id'] : '';
		$settings_patch = $args['settings_patch'] ?? null;
		$dry_run        = ! empty( $args['dry_run'] );
		$if_revision    = isset( $args['if_revision'] ) ? (string) $args['if_revision'] : null;

		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		if ( '' === $widget_id ) {
			return new WP_Error( 'missing_widget_id', 'widget_id is required.' );
		}
		if ( ! is_array( $settings_patch ) ) {
			return new WP_Error( 'missing_patch', 'settings_patch must be an object.' );
		}

		$decoded = self::decode_data( $post_id );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		[ $elements, $raw ] = $decoded;
		$previous_revision  = self::compute_revision( $raw );

		if ( null !== $if_revision && $previous_revision !== $if_revision ) {
			return new WP_Error(
				'revision_conflict',
				'Stored revision does not match if_revision.',
				[
					'status'           => 409,
					'current_revision' => $previous_revision,
				]
			);
		}

		$found = self::find_widget( $elements, $widget_id );
		if ( null === $found ) {
			return new WP_Error( 'widget_not_found', "Widget {$widget_id} not found in post {$post_id}." );
		}

		// Apply patch in-place on a working copy so we can capture before/after
		// without a second tree-walk.
		$widget_path = $found['path'];
		$widget_ref  = self::pointer_get( $elements, $widget_path );
		if ( is_wp_error( $widget_ref ) ) {
			return $widget_ref;
		}

		$applied_patch = self::apply_settings_patch( $widget_ref, $settings_patch, $widget_path );

		// Re-write the mutated widget back into the tree.
		$err = self::pointer_set( $elements, $widget_path, $widget_ref, false );
		if ( is_wp_error( $err ) ) {
			return $err;
		}

		// previous_revision is only echoed when the caller passed if_revision —
		// they already had it (confirms what the server saw on the matched read),
		// and clients that didn't pass if_revision don't need it on the wire.
		$echo_prev = ( null !== $if_revision );

		// Short-circuit no-op writes — same input + zero applied ops means
		// nothing to do. Skip the round-trip through Document::save and
		// avoid any whitespace-difference noise from re-encoding.
		if ( empty( $applied_patch ) ) {
			$noop = [
				'post_id'             => $post_id,
				'widget_id'           => $widget_id,
				'current_revision'    => $previous_revision,
				'applied_patch'       => [],
				'content_updated'     => false,
				'post_content_length' => strlen( (string) get_post_field( 'post_content', $post_id ) ),
			];
			if ( $echo_prev ) {
				$noop = [ 'previous_revision' => $previous_revision ] + $noop;
			}
			return $noop;
		}

		if ( $dry_run ) {
			$preview = [
				'post_id'          => $post_id,
				'widget_id'        => $widget_id,
				'dry_run'          => true,
				'current_revision' => null,
				'applied_patch'    => $applied_patch,
			];
			if ( $echo_prev ) {
				$preview = [ 'previous_revision' => $previous_revision ] + $preview;
			}
			return $preview;
		}

		$pipeline = self::write_pipeline( $post_id, $elements, $previous_revision );
		if ( is_wp_error( $pipeline ) ) {
			return $pipeline;
		}

		$response = [
			'post_id'             => $post_id,
			'widget_id'           => $widget_id,
			'current_revision'    => $pipeline['current_revision'],
			'applied_patch'       => $applied_patch,
			'content_updated'     => $pipeline['content_updated'],
			'post_content_length' => $pipeline['post_content_length'],
		];
		if ( $echo_prev ) {
			$response = [ 'previous_revision' => $pipeline['previous_revision'] ] + $response;
		}

		$receipt_field = 'elementor:' . $widget_id . ':' . self::truncate_pointer( $widget_path . '/settings' );
		$receipt       = IATO_MCP_Change_Receipt::record(
			$post_id,
			'elementor_widget',
			$receipt_field,
			$pipeline['previous_revision'],
			$pipeline['current_revision']
		);
		// Slim receipt for the API response — client already has applied_patch
		// at the top level + previous_revision / current_revision as dedicated
		// fields. Stored row keeps full before/after for audit/rollback.
		$response['change_receipt'] = [
			'change_id'   => $receipt['change_id'],
			'target_type' => $receipt['target_type'],
			'field'       => $receipt['field'],
			'applied_at'  => $receipt['applied_at'],
		];

		return $response;
	}

	/**
	 * Bound the path portion of the change-receipt field so we don't blow
	 * the VARCHAR(100) column on deeply-nested paths. Truncate-with-ellipsis
	 * preserves diagnostic value without breaking inserts.
	 */
	private static function truncate_pointer( string $path, int $max = 70 ): string {
		if ( strlen( $path ) <= $max ) {
			return $path;
		}
		return substr( $path, 0, $max - 3 ) . '...';
	}
}

/**
 * Concurrency + idempotency helpers for v2 Elementor write tools.
 * Lives in the same file as the adapter so we don't proliferate single-class
 * files; there's no use for either of these outside the v2 surface.
 */
class IATO_MCP_Elementor_Concurrency {

	/** Idempotency cache window. */
	private const TTL_SECONDS = 60;

	/**
	 * Verify a caller-supplied revision matches what's currently stored.
	 * Null if_revision is a no-op pass (last-write-wins).
	 */
	public static function check_revision( int $post_id, ?string $if_revision ): bool|WP_Error {
		if ( null === $if_revision || '' === $if_revision ) {
			return true;
		}
		$raw     = (string) get_post_meta( $post_id, '_elementor_data', true );
		$current = IATO_MCP_Elementor_Adapter::compute_revision( $raw );
		if ( $current !== $if_revision ) {
			return new WP_Error(
				'revision_conflict',
				'Stored revision does not match if_revision.',
				[
					'status'           => 409,
					'current_revision' => $current,
				]
			);
		}
		return true;
	}

	/**
	 * Look up a cached idempotency response. Three return modes:
	 *   - null: cache miss; caller should execute and call store().
	 *   - array (response): hit with matching payload; return as-is with
	 *     idempotency_replay: true injected into the data envelope.
	 *   - WP_Error('idempotency_replay'): hit with different payload.
	 */
	public static function idempotency_lookup(
		int $user_id,
		string $tool,
		?string $key,
		array $args
	): null|array|WP_Error {
		if ( null === $key || '' === $key ) {
			return null;
		}
		$transient = self::transient_name( $user_id, $tool, $key );
		$cached    = get_transient( $transient );
		if ( false === $cached || ! is_array( $cached ) ) {
			return null;
		}
		$payload_hash = self::canonical_args_hash( $args );
		if ( ( $cached['payload_hash'] ?? '' ) !== $payload_hash ) {
			return new WP_Error(
				'idempotency_replay',
				'Same idempotency_key reused with a different payload.',
				[ 'status' => 409 ]
			);
		}
		$response = is_array( $cached['response'] ?? null ) ? $cached['response'] : [];
		$response['idempotency_replay'] = true;
		return $response;
	}

	public static function idempotency_store(
		int $user_id,
		string $tool,
		?string $key,
		array $args,
		array $response
	): void {
		if ( null === $key || '' === $key ) {
			return;
		}
		$transient = self::transient_name( $user_id, $tool, $key );
		set_transient(
			$transient,
			[
				'payload_hash' => self::canonical_args_hash( $args ),
				'response'     => $response,
			],
			self::TTL_SECONDS
		);
	}

	private static function transient_name( int $user_id, string $tool, string $key ): string {
		$digest = hash( 'sha256', $user_id . '|' . $tool . '|' . $key );
		return 'iato_mcp_idem_' . substr( $digest, 0, 40 );
	}

	private static function canonical_args_hash( array $args ): string {
		$canonical = self::canonicalize( $args );
		return hash( 'sha256', wp_json_encode( $canonical ) );
	}

	/**
	 * Recursively ksort arrays so two argument hashes that differ only in
	 * key ordering still match. Strips idempotency_key itself from the
	 * hash so a retry with a different key but same payload doesn't get
	 * a cache miss artifact.
	 */
	private static function canonicalize( mixed $data ): mixed {
		if ( is_array( $data ) ) {
			$out = [];
			foreach ( $data as $k => $v ) {
				if ( 'idempotency_key' === $k ) {
					continue;
				}
				$out[ $k ] = self::canonicalize( $v );
			}
			ksort( $out );
			return $out;
		}
		return $data;
	}
}
