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
	 * The stored `_elementor_data` as text, the form compute_revision() hashes:
	 * a tree stored as an array (pre-1.13.0 update_post_meta writes) is
	 * JSON-encoded, as decode_data() does; '' when there is none.
	 */
	public static function stored_raw( int $post_id ): string {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return (string) wp_json_encode( $raw );
		}
		return is_string( $raw ) ? $raw : '';
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
		if ( is_array( $raw ) ) {
			// Stored as an array (a pre-1.13.0 update_post_meta write); Elementor
			// accepts that shape, json_decode() would not.
			$raw = wp_json_encode( $raw );
		}
		if ( empty( $raw ) || ! is_string( $raw ) ) {
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
				'schema'    => IATO_MCP_Elementor_Atomic::schema_of( $element ),
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
			'schema'    => IATO_MCP_Elementor_Atomic::schema_of( $element ),
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
	 *
	 * Atomic (Elementor V4) nodes are delegated to IATO_MCP_Elementor_Atomic,
	 * which unwraps the typed settings envelopes and emits the same key names
	 * (title, header_size, text, editor, link) plus `tag` and image/link
	 * extras. Classic nodes keep the original scalar-only heuristic.
	 */
	private static function peek_fields( array $element ): array {
		if ( IATO_MCP_Elementor_Atomic::is_atomic( $element ) ) {
			$peek = IATO_MCP_Elementor_Atomic::normalize( $element );
			unset( $peek['schema'] ); // Node builders already set it.
			return $peek;
		}

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
			'schema'    => IATO_MCP_Elementor_Atomic::schema_of( $element ),
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
	 * add, copy and move insert into a list at an index, as RFC 6902 says (1.21.1:
	 * they replaced the element there until then), and each op on a list item
	 * reports the list as it is afterwards (`list`, `from_list`).
	 *
	 * Each op that sets a value also carries `_stored_at`, where annotate_stored()
	 * finds it in the stored tree: its element's id and the path inside it, so a
	 * later op that shifts the list does not send it to another element.
	 * annotate_stored() removes the key.
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
			if ( in_array( $result['op'], [ 'add', 'replace' ], true ) ) {
				$result['_stored_at'] = self::stored_at( $elements, $result['_resolved_path'] ?? $result['path'] );
			}
			unset( $result['_resolved_path'] );
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
				$added = self::pointer_add( $elements, $path, $op['value'] );
				if ( is_wp_error( $added ) ) {
					return $added;
				}
				return self::with_list( [ 'op' => 'add', 'path' => $path, 'value' => $op['value'] ], $elements, $path, $added );

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
				$removed = self::list_position( $elements, $path );
				$err     = self::pointer_remove( $elements, $path );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
				$out = [ 'op' => 'remove', 'path' => $path, 'previous_value' => $prev ];
				if ( null !== $removed ) {
					$out['list'] = self::list_report( $elements, self::parent_pointer( $path ), $removed['index'], $removed['count_before'] );
				}
				return $out;

			case 'move':
				$from = (string) ( $op['from'] ?? '' );
				if ( '' === $from ) {
					return new WP_Error( 'invalid_patch', "move op missing 'from' at {$path}" );
				}
				// RFC 6902 4.4: an element cannot move into its own children.
				if ( str_starts_with( $path, $from . '/' ) ) {
					return new WP_Error( 'invalid_patch', "move op cannot move {$from} into itself ({$path})." );
				}
				$value = self::pointer_get( $elements, $from );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$taken = self::list_position( $elements, $from );
				$err   = self::pointer_remove( $elements, $from );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
				$added = self::pointer_add( $elements, $path, $value );
				if ( is_wp_error( $added ) ) {
					return $added;
				}
				$out         = [ 'op' => 'move', 'from' => $from, 'path' => $path, 'value' => $value ];
				$same_parent = self::parent_pointer( $from ) === self::parent_pointer( $path );
				if ( $same_parent && null !== $taken && null !== $added['index'] ) {
					// One list: its length before the move, not between the two halves of it.
					$added['count_before'] = $taken['count_before'];
				} elseif ( null !== $taken ) {
					$out['from_list'] = self::list_report( $elements, self::parent_pointer( $from ), $taken['index'], $taken['count_before'] );
				}
				return self::with_list( $out, $elements, $path, $added );

			case 'copy':
				$from = (string) ( $op['from'] ?? '' );
				if ( '' === $from ) {
					return new WP_Error( 'invalid_patch', "copy op missing 'from' at {$path}" );
				}
				$value = self::pointer_get( $elements, $from );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
				$added = self::pointer_add( $elements, $path, $value );
				if ( is_wp_error( $added ) ) {
					return $added;
				}
				return self::with_list( [ 'op' => 'copy', 'from' => $from, 'path' => $path, 'value' => $value ], $elements, $path, $added );

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
		// Asked before the unset (1.21.1): afterwards a list missing its middle item
		// has keys 0 and 2, was never re-indexed, and was stored as a JSON object.
		$was_list = self::is_list( $node );
		unset( $node[ $key ] );
		// Re-index lists so subsequent path resolution doesn't break.
		if ( $was_list ) {
			$node = array_values( $node );
		}
		return null;
	}

	/**
	 * RFC 6902 add: insert into a list at an index (`-` or the list's length
	 * appends), set a member of an object. Mutates by reference.
	 *
	 * A list is an array keyed 0..n-1, or empty. Anything else is an object,
	 * including a stored page whose elements have gaps in their numbering, which
	 * keeps the behaviour it had: the key is set, and `-` appends.
	 *
	 * @return array|WP_Error `index` and `count_before` for a list insert, both null otherwise.
	 */
	public static function pointer_add( array &$root, string $path, mixed $value ): array|WP_Error {
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
		if ( self::is_list( $node ) && ( '-' === $last || ctype_digit( $last ) ) ) {
			$count = count( $node );
			if ( '-' === $last ) {
				$index = $count;
			} else {
				if ( strlen( $last ) > 1 && '0' === $last[0] ) {
					return new WP_Error( 'invalid_patch_path', "Index {$last} has a leading zero at {$path}." );
				}
				$index = (int) $last;
				if ( $index > $count ) {
					return new WP_Error( 'invalid_patch_path', "Index {$index} is past the end of a list of {$count} at {$path}. Use {$count} or - to append." );
				}
			}
			array_splice( $node, $index, 0, [ $value ] );
			return [ 'index' => $index, 'count_before' => $count ];
		}
		if ( '-' === $last ) {
			$node[] = $value;
		} else {
			$node[ ctype_digit( $last ) ? (int) $last : $last ] = $value;
		}
		return [ 'index' => null, 'count_before' => null ];
	}

	/** Keyed 0..n-1, or empty: a JSON array. (array_is_list() needs PHP 8.1.) */
	private static function is_list( array $value ): bool {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/** The pointer of the array that holds $path's target ('' for the root). */
	private static function parent_pointer( string $path ): string {
		$at = strrpos( $path, '/' );
		return false === $at ? '' : substr( $path, 0, $at );
	}

	/** Where $path's target sits in its list, or null when its parent is not a list. */
	private static function list_position( array $root, string $path ): ?array {
		$parent = self::pointer_get( $root, self::parent_pointer( $path ) );
		$last   = (string) substr( (string) strrchr( $path, '/' ), 1 );
		if ( ! is_array( $parent ) || ! self::is_list( $parent ) || ! ctype_digit( $last ) ) {
			return null;
		}
		return [ 'index' => (int) $last, 'count_before' => count( $parent ) ];
	}

	/** At most this many ids in a `list` report. */
	private const LIST_IDS_MAX = 100;

	/**
	 * The list at $parent after an op: where the op acted, its length before and
	 * after, and each item's `id` (elements) or `_id` (repeater rows), null for
	 * an item with neither. Shows a misplaced insert before it is written.
	 */
	private static function list_report( array $root, string $parent, int $index, int $count_before ): array {
		$out  = [ 'path' => $parent, 'index' => $index, 'count_before' => $count_before ];
		$list = self::pointer_get( $root, $parent );
		if ( ! is_array( $list ) ) {
			return $out;
		}
		$items              = array_values( $list );
		$out['count_after'] = count( $items );
		$ids                = [];
		foreach ( array_slice( $items, 0, self::LIST_IDS_MAX ) as $item ) {
			$id    = is_array( $item ) ? ( $item['id'] ?? $item['_id'] ?? null ) : null;
			$ids[] = is_scalar( $id ) ? (string) $id : null;
		}
		$out['ids_after'] = $ids;
		if ( count( $items ) > self::LIST_IDS_MAX ) {
			$out['ids_truncated'] = true;
		}
		return $out;
	}

	/** Add an insert's `list` report to its op, and the index `-` stood for. */
	private static function with_list( array $op, array $root, string $path, array $added ): array {
		if ( null === $added['index'] ) {
			return $op;
		}
		$parent              = self::parent_pointer( $path );
		$op['list']          = self::list_report( $root, $parent, $added['index'], $added['count_before'] );
		$op['_resolved_path'] = $parent . '/' . $added['index'];
		return $op;
	}

	/**
	 * Where annotate_stored() finds an op's value: the deepest element (an object
	 * with `id` and `elType`) on its path, by id, and the path inside it. The whole
	 * path when no element lies on it.
	 */
	private static function stored_at( array $root, string $path ): array {
		$segs   = self::pointer_segments( $path );
		$node   = $root;
		$anchor = null;
		foreach ( $segs as $i => $seg ) {
			if ( ! is_array( $node ) ) {
				break;
			}
			if ( array_key_exists( $seg, $node ) ) {
				$node = $node[ $seg ];
			} elseif ( ctype_digit( $seg ) && array_key_exists( (int) $seg, $node ) ) {
				$node = $node[ (int) $seg ];
			} else {
				break;
			}
			if ( is_array( $node ) && isset( $node['elType'] ) && isset( $node['id'] ) && is_scalar( $node['id'] ) && '' !== (string) $node['id'] ) {
				$anchor = [ 'id' => (string) $node['id'], 'rest' => $i + 1 ];
			}
		}
		if ( null === $anchor ) {
			return [ 'path' => $path ];
		}
		$rest = array_slice( $segs, $anchor['rest'] );
		return [
			'id'   => $anchor['id'],
			'rest' => [] === $rest ? '' : '/' . implode( '/', array_map( fn( $seg ) => self::escape_pointer_segment( (string) $seg ), $rest ) ),
			'path' => $path,
		];
	}

	// ── find_by_filter ────────────────────────────────────────────────────

	/**
	 * Walk all widgets in $elements and return those matching $filter.
	 *
	 * Filter shape: {
	 *   type?: string,                    // matches widgetType (e.g. heading, e-heading) or elType (e-flexbox)
	 *   setting: { key: { op: value } }   // op = eq | ne | in | nin | exists | contains ; bare value = eq
	 * }
	 *
	 * Setting clauses are evaluated against raw settings for classic widgets
	 * and against IATO_MCP_Elementor_Atomic::match_settings() for atomic nodes
	 * (unwrapped values plus derived header_size / editor / link_url /
	 * image_url / image_id / image_alt), so the same filter works on both.
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
				'schema'    => IATO_MCP_Elementor_Atomic::schema_of( $element ),
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

		$settings = IATO_MCP_Elementor_Atomic::is_atomic( $element )
			? IATO_MCP_Elementor_Atomic::match_settings( $element )
			: ( is_array( $element['settings'] ?? null ) ? $element['settings'] : [] );
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
				case 'contains':
					// Case-insensitive substring match against scalar settings.
					// Useful for finding a widget by its content ("editor" field
					// stores HTML, "title" is a heading string, etc.). Scalar-only
					// by design — never recurses into nested arrays.
					if ( ! $has || ! is_scalar( $value ) || ! is_scalar( $expected ) ) {
						return false;
					}
					if ( '' === (string) $expected ) {
						return false;
					}
					if ( false === stripos( (string) $value, (string) $expected ) ) {
						return false;
					}
					break;
				default:
					return false;
			}
		}
		return true;
	}

	// ── Elementor's REST meta sanitize callbacks ──────────────────────────

	/** Hooks WordPress runs from sanitize_meta() for the keys the plugin writes verbatim. */
	private const META_SANITIZE_HOOK_PATTERN = '/^sanitize_post_meta__elementor_(data|page_settings)(_for_.+)?$/';

	/** The options Elementor copies into its active kit, and the kit setting each goes to. */
	private const KIT_COPIED_OPTIONS = [ 'blogname' => 'site_name', 'blogdescription' => 'site_description' ];

	private const KIT_SETTINGS_META = '_elementor_page_settings';

	/** Nesting depth of without_elementor_meta_sanitizing(); callbacks are removed at 0→1 and restored at 1→0. */
	private static int $meta_guard_depth = 0;

	/** @var array<string,array<int,array<string,array>>> what was removed: hook => priority => id => callback */
	private static array $meta_guard_removed = [];

	/**
	 * Run $fn with Elementor's sanitize callbacks for `_elementor_data` and
	 * `_elementor_page_settings` removed, then put them back.
	 *
	 * Elementor (4.3.4, modules/wp-rest/classes/elementor-post-meta.php)
	 * registers those keys with register_meta() on rest_api_init with a
	 * sanitize_callback that runs wp_kses_post over every string in the value
	 * for requests without the unfiltered_html capability. Through 1.12.1 that
	 * filtered the whole tree on every edit made with the site key: iframes,
	 * scripts and other HTML in widgets the edit never touched were stripped
	 * and `&` became `&amp;` in every text. The plugin sanitises the values an
	 * edit changes itself (IATO_MCP_Elementor_Sanitizer) and writes everything
	 * else back as it was, inside this guard (1.12.2). Since 1.13.0 the kses
	 * lock is also lifted around these writes (IATO_MCP_Auth::write_unlocked()),
	 * which covers users who hold the capability; this guard is what covers a
	 * request with no capability to show through: an unbound legacy key, which
	 * runs as user 0.
	 *
	 * The hooks are found at runtime in $wp_filter (both the plain hook and
	 * every `_for_<post_type>` variant register_meta() created), every
	 * callback on them is removed and restored in a finally block, so a write
	 * that throws still restores them. Nested calls are safe: only the
	 * outermost removes and restores. Every callback on the matched hooks is
	 * removed, whichever plugin added it; under WP_DEBUG each one is written
	 * to the PHP error log so a clash with another plugin can be diagnosed.
	 * Used only around the plugin's own writes of already-sanitised or
	 * previously stored values.
	 */
	public static function without_elementor_meta_sanitizing( callable $fn ): mixed {
		if ( 0 === self::$meta_guard_depth ) {
			self::$meta_guard_removed = [];
			if ( isset( $GLOBALS['wp_filter'] ) && is_array( $GLOBALS['wp_filter'] ) ) {
				foreach ( $GLOBALS['wp_filter'] as $hook => $wp_hook ) {
					if ( ! is_string( $hook ) || ! preg_match( self::META_SANITIZE_HOOK_PATTERN, $hook ) || ! is_object( $wp_hook ) || empty( $wp_hook->callbacks ) ) {
						continue;
					}
					foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
						foreach ( $callbacks as $id => $callback ) {
							if ( ! isset( $callback['function'] ) ) {
								continue;
							}
							self::$meta_guard_removed[ $hook ][ $priority ][ $id ] = $callback;
							remove_filter( $hook, $callback['function'], (int) $priority );
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								error_log( sprintf( '[iato-mcp] temporarily removed %s (priority %d) from %s for an Elementor data write', self::describe_callback( $callback['function'] ), (int) $priority, $hook ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							}
						}
					}
				}
			}
		}
		++self::$meta_guard_depth;
		try {
			return $fn();
		} finally {
			--self::$meta_guard_depth;
			if ( 0 === self::$meta_guard_depth ) {
				foreach ( self::$meta_guard_removed as $hook => $priorities ) {
					foreach ( $priorities as $priority => $callbacks ) {
						foreach ( $callbacks as $callback ) {
							add_filter( $hook, $callback['function'], (int) $priority, (int) ( $callback['accepted_args'] ?? 1 ) );
						}
					}
				}
				self::$meta_guard_removed = [];
			}
		}
	}

	/**
	 * update_option() for a site setting. Elementor copies the title and tagline into
	 * its active kit from update_option_{option}, through Document::update_settings(),
	 * which throws when the user cannot edit the kit (the unbound key's user 0) or no
	 * kit is usable (missing or trashed). The throw comes after the value is stored,
	 * so the client got an error and no receipt, and the kit kept the old text, which
	 * publishing Site Settings then wrote back. Such a write takes Elementor's callback
	 * off the hook and makes the copy itself; other plugins' callbacks still run.
	 *
	 * @return string|null A warning for the client, when the copy failed.
	 */
	public static function update_site_option( string $option, mixed $value ): ?string {
		$setting = self::KIT_COPIED_OPTIONS[ $option ] ?? null;
		$kit_id  = null === $setting ? null : self::active_kit_id();
		if ( null === $kit_id || ( $kit_id > 0 && current_user_can( 'edit_post', $kit_id ) ) ) {
			update_option( $option, $value ); // update_option() does not unslash
			return null;
		}

		$updated = self::without_kit_copy( $option, static fn(): bool => update_option( $option, $value ) );
		if ( ! $updated || 0 === $kit_id ) {
			return null;
		}
		$stored = (string) get_option( $option );
		if ( self::copy_to_kit( $kit_id, $setting, $stored ) ) {
			return null;
		}
		return sprintf( 'Elementor\'s Site Settings still holds the old value of %s, and publishing Site Settings would put it back. Change it there too.', $option );
	}

	/**
	 * The kit Elementor's hook would write to: its ID, 0 when no kit is usable, null
	 * when that cannot be told (Elementor not running), which leaves the write alone.
	 */
	private static function active_kit_id(): ?int {
		if ( ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance->kits_manager ) ) {
			return null;
		}
		try {
			return (int) \Elementor\Plugin::$instance->kits_manager->get_active_kit()->get_main_id();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Run $fn with Elementor's copy off update_option_{option}, restored afterwards, also on a throw. */
	private static function without_kit_copy( string $option, callable $fn ): mixed {
		$hook    = 'update_option_' . $option;
		$removed = [];
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? [] as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( self::is_kit_copy_callback( $callback['function'] ?? null ) ) {
					remove_filter( $hook, $callback['function'], (int) $priority );
					$removed[] = [ $callback['function'], (int) $priority, (int) ( $callback['accepted_args'] ?? 1 ) ];
				}
			}
		}
		try {
			return $fn();
		} finally {
			foreach ( $removed as [ $function, $priority, $accepted_args ] ) {
				add_filter( $hook, $function, $priority, $accepted_args );
			}
		}
	}

	/** Elementor's kits manager registers these as closures. Anything else on the hook is left alone. */
	private static function is_kit_copy_callback( mixed $fn ): bool {
		if ( is_array( $fn ) ) {
			return ( $fn[0] ?? null ) instanceof \Elementor\Core\Kits\Manager;
		}
		return $fn instanceof \Closure && ( new \ReflectionFunction( $fn ) )->getClosureThis() instanceof \Elementor\Core\Kits\Manager;
	}

	/** What Elementor's copy stores, through the meta because Document::update_settings() is what refused. */
	private static function copy_to_kit( int $kit_id, string $setting, string $value ): bool {
		$settings = get_post_meta( $kit_id, self::KIT_SETTINGS_META, true );
		$settings = is_array( $settings ) ? $settings : [];

		$settings[ $setting ] = $value;
		try {
			self::write_elementor_meta( static fn() => update_post_meta( $kit_id, self::KIT_SETTINGS_META, wp_slash( $settings ) ) );
		} catch ( \Throwable $e ) {
			return false;
		}
		$saved = get_post_meta( $kit_id, self::KIT_SETTINGS_META, true );
		return is_array( $saved ) && ( $saved[ $setting ] ?? null ) === $value;
	}

	/** Human-readable name of a hook callback, for the WP_DEBUG log line. */
	private static function describe_callback( mixed $fn ): string {
		if ( is_string( $fn ) ) {
			return $fn;
		}
		if ( is_array( $fn ) && 2 === count( $fn ) ) {
			return ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . (string) $fn[1];
		}
		return is_object( $fn ) ? get_class( $fn ) : gettype( $fn );
	}

	/**
	 * Run a write of already-sanitised (or previously stored) Elementor meta:
	 * the plugin's kses lock is lifted (IATO_MCP_Auth::write_unlocked()) and
	 * Elementor's whole-value REST sanitize callbacks are out of the way
	 * (without_elementor_meta_sanitizing()). Both are restored afterwards,
	 * also when the write throws.
	 */
	public static function write_elementor_meta( callable $fn ): mixed {
		return self::without_elementor_meta_sanitizing( fn() => IATO_MCP_Auth::write_unlocked( $fn ) );
	}

	// ── Write pipeline ────────────────────────────────────────────────────

	/**
	 * Persist a modified element tree back into the post:
	 *
	 *   0. Sanitise the values the request changed
	 *   1. Delete this post's CSS meta and element cache
	 *   2. \Elementor\Plugin::$instance->documents->get($id)->save(['elements' => $decoded])
	 *   3. Only when Elementor did not store it: the plugin writes the tree itself
	 *   4. If post_content didn't update, force via Frontend::get_builder_content
	 *   5. Clear this post's Elementor caches and queue its CSS and the page purge
	 *      (IATO_MCP_Elementor_Adapter::clear_post_css(), IATO_MCP_Cache)
	 *   6. Verify the stored tree has the same elements
	 *
	 * Returns a status array. Caller is responsible for revision computation
	 * — both `previous_revision` (compared on entry) and `current_revision`
	 * (computed on the just-written string) are passed back here so callers
	 * don't re-read meta.
	 *
	 * @param array      $decoded_elements  Mutated elements array.
	 * @param string     $previous_revision Revision hash captured before mutation.
	 * @param array|null $stored_elements   The tree as stored before the mutation
	 *                                      (what the caller decoded); read from
	 *                                      meta when null. Used to sanitise only
	 *                                      the values the request changed.
	 * @param array      $opts              1.13.1: `atomic_ids` — ids of the
	 *                                      Elementor Atomic (V4) elements this
	 *                                      write changes. Whenever Elementor's
	 *                                      save does not store the tree (it threw,
	 *                                      was skipped or was refused), those
	 *                                      elements go through Elementor's
	 *                                      per-element validation first; if one
	 *                                      fails, nothing is written.
	 *                                      `atomic_paths` — JSON pointer paths of
	 *                                      the elements a single-element tool
	 *                                      edits; only the node at each path is
	 *                                      checked, not other nodes with its id.
	 *                                      1.14.0 (IATO_MCP_Elementor_Atomic_Writer):
	 *                                      `atomic` — the tree was built and
	 *                                      validated by the atomic writer:
	 *                                      Elementor refusing the save (false)
	 *                                      or rejecting the settings is a
	 *                                      refusal, not a fallback write.
	 *                                      `skip_save` — reason to store the tree
	 *                                      with the plugin's own write instead of
	 *                                      Elementor's save (the writer's page
	 *                                      check found that the save would alter
	 *                                      or reject elements this write does not
	 *                                      change); `skip_ids` — those elements.
	 *                                      `on_written` — called right after the
	 *                                      tree is stored, before caches and
	 *                                      checks (receipts).
	 *
	 * @return array{
	 *   previous_revision:string,
	 *   current_revision:string,
	 *   content_updated:bool,
	 *   post_content_length:int,
	 *   meta_persisted:bool,
	 *   meta_length:int,
	 *   elementor_save:bool|string|null,
	 *   stored_by:string,
	 *   sanitized?:bool,
	 *   sanitized_paths?:string[],
	 *   sanitized_report?:array
	 * }|WP_Error
	 */
	public static function write_pipeline(
		int $post_id,
		array $decoded_elements,
		string $previous_revision,
		?array $stored_elements = null,
		array $opts = []
	): array|WP_Error {
		// Elementor's save and the render below replace post_content: every save they
		// make would otherwise also filter the title and excerpt (1.16.0).
		return IATO_MCP_Auth::keeping_post_columns(
			$post_id,
			fn() => self::run_write_pipeline( $post_id, $decoded_elements, $previous_revision, $stored_elements, $opts ),
			[ 'post_content' ]
		);
	}

	/** See write_pipeline(). */
	private static function run_write_pipeline(
		int $post_id,
		array $decoded_elements,
		string $previous_revision,
		?array $stored_elements,
		array $opts
	): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', "Post {$post_id} not found." );
		}

		// 1.16.0: Elementor's save replaces post_content, which on a page with
		// Elementor turned off is what visitors see. Every tool checks this before
		// its dry run; this is the backstop for anything that reaches the save.
		if ( empty( $opts['set_builder_mode'] ) ) {
			$refusal = self::inactive_tree_refusal( $post_id );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}

		$old_content      = (string) get_post_field( 'post_content', $post_id );
		$decoded_elements = self::force_arrays( $decoded_elements );

		// 1.16.0: the tools check this before their dry runs; this is the backstop. A
		// rollback puts back what was stored, whatever that was.
		if ( empty( $opts['verbatim'] ) ) {
			$shape = self::tree_shape_error( $decoded_elements );
			if ( null !== $shape ) {
				return $shape;
			}
		}

		// 0. Sanitise what the request changed (1.13.0). Every value that
		// differs from the stored tree at the same element + path goes through
		// IATO_MCP_Elementor_Sanitizer; identical values are kept verbatim, so
		// HTML a human put in an untouched widget survives. Skipped only when
		// the request may store unfiltered HTML (IATO_MCP_Auth, filter
		// iato_mcp_allow_unfiltered_html). Elementor's own save, below, adds
		// its blanket kses pass on top for users without unfiltered_html.
		$changed = [];
		$report  = [];
		// 1.14.0: `verbatim` (an atomic rollback restoring values recorded from
		// Elementor's own sanitisers) skips this pass.
		if ( empty( $opts['verbatim'] ) && ! IATO_MCP_Auth::unfiltered_html_allowed() ) {
			if ( null === $stored_elements ) {
				$stored_elements = self::stored_elements( $post_id );
			}
			$diff             = IATO_MCP_Elementor_Sanitizer::diff_tree( $decoded_elements, $stored_elements );
			$decoded_elements = $diff['elements'];
			$changed          = $diff['changed'];
			$report           = $diff['report'];
		}

		$encoded = wp_json_encode( $decoded_elements );
		if ( false === $encoded ) {
			return new WP_Error( 'encode_failed', 'Failed to JSON-encode the modified Elementor tree.' );
		}

		// 0b (1.13.1). Element types Elementor cannot load right now (a
		// deactivated addon's widgets, atomic elements while the Atomic Editor is
		// off): Document::save() rebuilds the tree through each element's class
		// and silently drops every element it cannot build, children included.
		// Such a tree is stored by the plugin's own write instead (step 3).
		$unregistered = self::unregistered_element_types( $decoded_elements );
		if ( ! empty( $unregistered ) && null === $stored_elements ) {
			$stored_elements = self::stored_elements( $post_id ); // for the check after the write
		}

		$atomic    = ! empty( $opts['atomic'] );
		$skip_save = isset( $opts['skip_save'] ) && is_string( $opts['skip_save'] ) && '' !== $opts['skip_save'] ? $opts['skip_save'] : null;

		// 1. Cache clear before the save so Elementor renders fresh. Only this
		// post's: since 1.15.0 no write here clears every page's CSS, which is
		// what Elementor's own editor does on a page save too. A write that other
		// pages render (kit, Theme Builder, global widgets, saved templates) clears
		// all of it afterwards, in IATO_MCP_Cache.
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		wp_cache_delete( $post_id, 'post_meta' );
		wp_cache_delete( $post_id, 'posts' );

		// 2. Elementor's own Document::save() first: it validates and stores the
		// elements through their classes and renders post_content. Guarded: it
		// is another plugin's code. false = Elementor refused (the current user
		// may not edit the post; always the case under an unbound legacy key,
		// which runs as user 0); 'error' = it threw (addon packs whose controls
		// fail validation); null = Elementor is not loaded.
		//
		// The save runs with the plugin's kses lock lifted (IATO_MCP_Auth::
		// write_unlocked(), restored in a finally block even when the save
		// throws). The lift only restores the capability the user holds
		// without the lock: an administrator saves the tree as sanitised in
		// step 0 and Elementor skips its own whole-document kses pass (which
		// would otherwise strip HTML a person put in widgets the request never
		// touched); a user who does not hold unfiltered_html (Author,
		// Contributor, every role on multisite) gets Elementor's standard
		// filtering exactly as in its editor. Nothing request-controlled other
		// than the sanitised tree reaches the save: no page settings are passed.
		$regenerated    = false;
		$elementor_save = null;
		$save_error     = null;
		if ( ! empty( $unregistered ) || null !== $skip_save ) {
			$elementor_save = 'skipped';
			$regenerated    = true;
		} elseif ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				$document = \Elementor\Plugin::$instance->documents->get( $post_id );
			} catch ( \Throwable $e ) {
				$document       = null;
				$elementor_save = 'error';
				$save_error     = $e->getMessage();
			}
			if ( $document ) {
				try {
					$elementor_save = IATO_MCP_Inline_Markup::write_without_style_in_content(
						$encoded,
						fn() => IATO_MCP_Auth::write_unlocked( fn() => (bool) $document->save( [ 'elements' => $decoded_elements ] ) )
					);
				} catch ( \Throwable $e ) {
					$elementor_save = 'error';
					$save_error     = $e->getMessage();
				}
				$regenerated = true;
			}
		}

		// 1.14.0: on the atomic writer's path the tree was validated with
		// Elementor's parser and checked against its save transform, so
		// Elementor refusing the save is Elementor's own editability rule
		// (Role Manager, post type not enabled for Elementor, trashed post) and
		// a validation failure means Elementor and that check disagree: both
		// are refusals, and nothing is written. Any other Throwable (an addon
		// failing in its own save code) keeps the fallback write of the
		// validated tree below.
		if ( $atomic && false === $elementor_save ) {
			return new WP_Error(
				'elementor_cannot_edit',
				"Elementor does not let the current user edit post {$post_id} (Elementor's Role Manager, the post type not enabled for Elementor, or a trashed post). Nothing was written.",
				[ 'status' => 403, 'post_id' => $post_id ]
			);
		}
		if ( $atomic && 'error' === $elementor_save && str_starts_with( (string) $save_error, 'Settings validation failed' ) ) {
			return new WP_Error(
				'elementor_validation_failed',
				"Elementor's save rejected the page: {$save_error}. Nothing was written.",
				[ 'status' => 400, 'post_id' => $post_id, 'elementor_error' => $save_error ]
			);
		}

		// 2b (1.13.1). Elementor did not store the tree: its save threw, was
		// skipped because the page holds types Elementor cannot load, or was
		// refused (the unbound key runs as user 0, whom Elementor does not let
		// save). Before the plugin stores the tree itself, every atomic element
		// this write changed (every node with a changed id, copies included)
		// goes through Elementor's own per-element save transform; if one fails,
		// nothing is written, because the fallback would store exactly what
		// Elementor refuses. When the changed elements validate and Elementor's
		// message was "Settings validation failed", another element on the page
		// is what it rejected (a value stored without its envelope by IATO
		// 1.12.x / 1.13.0): the write goes ahead, so a damaged page can be
		// repaired one element at a time, and the result names the damaged
		// elements.
		$invalid_elsewhere = null;
		$atomic_ids        = array_values( array_map( 'strval', (array) ( $opts['atomic_ids'] ?? [] ) ) );
		$atomic_paths      = array_values( array_map( 'strval', (array) ( $opts['atomic_paths'] ?? [] ) ) );
		if ( ( ! empty( $atomic_ids ) || ! empty( $atomic_paths ) ) && true !== $elementor_save ) {
			$refusal = self::atomic_validation_error( $post_id, $decoded_elements, $atomic_ids, $save_error, $atomic_paths );
			if ( null !== $refusal ) {
				return $refusal;
			}
			if ( 'error' === $elementor_save && str_starts_with( (string) $save_error, 'Settings validation failed' ) ) {
				$invalid_elsewhere = self::invalid_elements( $decoded_elements );
			}
		}

		// 3. The plugin writes the tree itself only when Elementor did not: the
		// tree written is the sanitised one from step 0 (an unsanitised tree is
		// never on disk, not even transiently).
		// The write runs with the kses lock lifted and Elementor's REST
		// sanitize_callback on _elementor_data removed (write_elementor_meta()):
		// the tree was sanitised in step 0, and that callback would otherwise
		// kses every string, including untouched human content. The callback
		// removal is what covers an unbound legacy key (user 0, no capability
		// for the lift to show through).
		$stored_by = 'elementor';
		if ( true !== $elementor_save ) {
			$stored_by = 'plugin';
			self::write_elementor_meta( function () use ( $post_id, $encoded ) {
				update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
			} );
			wp_cache_delete( $post_id, 'post_meta' );
		}

		// 1.16.0: only now, with the tree stored: a save refused above must not
		// switch visitors to the old tree.
		$builder_mode_set = false;
		if ( ! empty( $opts['set_builder_mode'] ) ) {
			self::write_elementor_meta( fn() => update_post_meta( $post_id, '_elementor_edit_mode', 'builder' ) );
			wp_cache_delete( $post_id, 'post_meta' );
			$builder_mode_set = true;
		}

		// 1.14.0: the tree is stored; the caller records its receipts now, so a
		// failure in the steps below never leaves a live change without one.
		if ( isset( $opts['on_written'] ) && is_callable( $opts['on_written'] ) ) {
			( $opts['on_written'] )();
		}


		// 4. Verify post_content actually changed.
		wp_cache_delete( $post_id, 'posts' );
		wp_cache_delete( $post_id, 'post_meta' );
		$new_content     = (string) get_post_field( 'post_content', $post_id );
		$content_updated = ( $new_content !== $old_content );

		// 5. Frontend fallback.
		// Guarded (1.14.0): the render runs every widget's code, addons
		// included, after the tree and its receipts are stored; a throw here is
		// reported and the cache clears below still run.
		$render_error = null;
		if ( $regenerated && ! $content_updated && class_exists( '\Elementor\Plugin' ) ) {
			try {
				$frontend = \Elementor\Plugin::instance()->frontend;
				if ( $frontend && method_exists( $frontend, 'get_builder_content' ) ) {
					// Without inline CSS: post_content is text for search and feeds, and
					// kses would leave a style block's CSS in it as visible text (1.16.0).
					$rendered = $frontend->get_builder_content( $post_id, false );
					if ( ! empty( $rendered ) && $rendered !== $old_content ) {
						IATO_MCP_Inline_Markup::write_without_style_in_content(
							$encoded,
							fn() => wp_update_post( wp_slash( [ // wp_update_post() unslashes
								'ID'           => $post_id,
								'post_content' => $rendered,
							] ) )
						);
						$new_content     = $rendered;
						$content_updated = true;
					}
				}
			} catch ( \Throwable $e ) {
				$render_error = $e->getMessage();
			} finally {
				// Elementor adds this for the render and removes it only on success.
				// Left on, the next save would write its cache shortcode into post_content.
				remove_filter( 'elementor/element/should_render_shortcode', '__return_true' );
			}
		}

		// This post's caches are cleared after the frontend render above, which
		// would otherwise keep CSS and element HTML rendered while some types could
		// not be loaded. 1.15.0: after every write. The CSS file is rebuilt only
		// when Elementor stored the page, which means it could load every element;
		// the atomic path keeps rebuilding on the next page view.
		$cache_cleared = self::clear_post_css( $post_id, true === $elementor_save && ! $atomic );

		// 6. Verify meta persisted. Structural, not byte-length: when Elementor's
		// own save ran it re-encodes the tree (defaults, key order, version
		// fields), so the stored string legitimately differs from $encoded while
		// holding the same elements.
		wp_cache_delete( $post_id, 'post_meta' );
		$persisted_meta   = (string) get_post_meta( $post_id, '_elementor_data', true );
		$meta_persisted   = self::same_element_tree( $persisted_meta, $decoded_elements );
		$current_revision = self::compute_revision( $persisted_meta );

		// 1.15.0: Elementor's own kses pass runs over the whole document for a user
		// without unfiltered_html (Author, every role on multisite or under
		// DISALLOW_UNFILTERED_HTML), widgets this write did not touch included.
		// Nothing else reports what it removed.
		if ( true === $elementor_save && ! IATO_MCP_Auth::unfiltered_html_allowed() && ! IATO_MCP_Auth::write_unlocked( fn() => current_user_can( 'unfiltered_html' ) ) ) {
			$persisted_tree = json_decode( $persisted_meta, true );
			$lost           = is_array( $persisted_tree ) ? IATO_MCP_Elementor_Sanitizer::compare_stored( $decoded_elements, self::force_arrays( $persisted_tree ) ) : [];
			$report         = IATO_MCP_Sanitize_Report::merge( $report, $lost );
			$changed        = array_values( array_unique( array_merge( $changed, array_column( $lost, 'path' ) ) ) );
		}

		$result = [
			'previous_revision'   => $previous_revision,
			'current_revision'    => $current_revision,
			'content_updated'     => $content_updated,
			'post_content_length' => strlen( $new_content ),
			'meta_persisted'      => $meta_persisted,
			'meta_length'         => strlen( $persisted_meta ),
			'elementor_save'      => $elementor_save,
			'stored_by'           => $stored_by,
		];
		if ( $builder_mode_set ) {
			$result['builder_mode_set'] = true;
		}
		if ( null !== $save_error ) {
			$result['elementor_save_error'] = $save_error;
		}
		if ( null !== $render_error ) {
			$result['render_error'] = $render_error;
		}
		if ( ! empty( $invalid_elsewhere ) ) {
			$result['invalid_elements'] = $invalid_elsewhere;
		}
		if ( 'skipped' === $elementor_save && ! empty( $unregistered ) ) {
			$result['skipped_reason']             = 'unregistered_element_types';
			$result['unregistered_element_types'] = $unregistered;
			$result['cache_cleared']              = $cache_cleared;
			$result['verification']               = self::verify_untouched( $stored_elements ?? [], $decoded_elements, $persisted_meta );
		} elseif ( 'skipped' === $elementor_save ) {
			$result['skipped_reason'] = $skip_save;
			if ( ! empty( $opts['skip_ids'] ) ) {
				$result['skipped_for_elements'] = array_values( array_map( 'strval', (array) $opts['skip_ids'] ) );
			}
		}
		if ( $atomic ) {
			$result['css'] = [ 'scope' => 'post' ] + (array) $cache_cleared;
		} elseif ( 'plugin' === $stored_by && 'skipped' !== $elementor_save ) {
			// 1.14.0: classic write stored by the plugin because Elementor's save
			// failed. Elementor did not rebuild this page's CSS.
			$result['cache_cleared']   = $cache_cleared;
			$result['css_regenerated'] = false;
			$result['css_note']        = "Elementor did not save the page (its save failed or was refused), so Elementor did not rebuild this page's CSS. IATO deleted the page's CSS and element cache; Elementor rebuilds them on the next page view. If the page still looks unstyled, clear any page cache and use Elementor > Tools > Regenerate Files & Data.";
		}
		if ( ! empty( $changed ) ) {
			$result['sanitized']       = true;
			$result['sanitized_paths'] = $changed;
		}
		if ( ! empty( $report ) ) {
			$result['sanitized_report'] = $report; // can come alone: markup kept by the opt-in changed no value
		}
		return $result;
	}

	/** @var callable|null Test seam for unregistered_element_types(): fn(string $el_type, string $widget_type): bool */
	private static $type_checker = null;

	/** Inject a registered-type check for tests; null restores Elementor's registries. */
	public static function set_type_checker( ?callable $checker ): void {
		self::$type_checker = $checker;
	}

	/**
	 * Element types in a tree (nested elements included) that Elementor cannot
	 * load right now, by the test Elementor itself applies before building an
	 * element (Elements_Manager::get_element(): widgets_manager for widgets,
	 * elements_manager for containers, sections and columns,
	 * elementor/includes/managers/elements.php:79-111). Document::save() drops
	 * such elements (elementor/core/base/document.php:1111-1115). Empty when
	 * every type is registered or Elementor's registries cannot be asked; a
	 * single entry with `error` when a lookup throws.
	 *
	 * @return array<int,array{type:string,element_ids:string[],error?:string}>
	 */
	public static function unregistered_element_types( array $elements ): array {
		$is_registered = self::$type_checker;
		if ( null === $is_registered ) {
			if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
				return [];
			}
			$plugin   = \Elementor\Plugin::$instance;
			$widgets  = isset( $plugin->widgets_manager ) ? $plugin->widgets_manager : null;
			$elements_manager = isset( $plugin->elements_manager ) ? $plugin->elements_manager : null;
			if ( ! is_object( $widgets ) || ! is_object( $elements_manager )
				|| ! method_exists( $widgets, 'get_widget_types' ) || ! method_exists( $elements_manager, 'get_element_types' ) ) {
				return [];
			}
			$is_registered = static function ( string $el_type, string $widget_type ) use ( $widgets, $elements_manager ): bool {
				if ( 'widget' === $el_type ) {
					return '' !== $widget_type && null !== $widgets->get_widget_types( $widget_type );
				}
				return '' !== $el_type && null !== $elements_manager->get_element_types( $el_type );
			};
		}

		$found = [];
		$known = [];
		try {
			self::walk_widgets( $elements, function ( array $element ) use ( &$found, &$known, $is_registered ) {
				$el_type     = (string) ( $element['elType'] ?? '' );
				$widget_type = 'widget' === $el_type ? (string) ( $element['widgetType'] ?? '' ) : '';
				$key         = $el_type . '|' . $widget_type;
				if ( ! array_key_exists( $key, $known ) ) {
					$known[ $key ] = (bool) $is_registered( $el_type, $widget_type );
				}
				if ( ! $known[ $key ] ) {
					$type             = 'widget' === $el_type ? ( '' !== $widget_type ? $widget_type : 'widget' ) : ( '' !== $el_type ? $el_type : 'unknown' );
					$found[ $type ][] = isset( $element['id'] ) ? (string) $element['id'] : '';
				}
			} );
		} catch ( \Throwable $e ) {
			// The registry itself failed (an addon's register hook threw): it may
			// now be half built for the rest of the request, and Elementor's save
			// would drop every element that never registered. Treat it as
			// unregistered so the plugin stores the tree itself.
			return [ [ 'type' => '(Elementor element registry failed)', 'element_ids' => [], 'error' => $e->getMessage() ] ];
		}

		$out = [];
		foreach ( $found as $type => $ids ) {
			$out[] = [ 'type' => (string) $type, 'element_ids' => $ids ];
		}
		return $out;
	}

	/**
	 * Clear one post's Elementor caches after a write and queue what comes next
	 * (rebuilding its CSS, purging the page) in IATO_MCP_Cache. Replaces the
	 * global files_manager->clear_cache() every classic write made until 1.15.0,
	 * which deleted every page's CSS file while pages cached by a plugin that
	 * does not listen for it (W3 Total Cache, WP Super Cache, WP Fastest Cache,
	 * SiteGround Optimizer) still linked to those files.
	 *
	 * The clear counts as reliable only when it can be seen to have worked: when
	 * it cannot, IATO_MCP_Cache falls back to the global clear and a full purge.
	 *
	 * @param bool $regenerate Rebuild the CSS file now rather than on the next page
	 *                         view. Never for a page holding elements Elementor cannot
	 *                         load right now: the file would miss their styles, and
	 *                         Elementor would keep it after they come back.
	 * @return array{post_css:bool,element_cache:bool,atomic_styles:bool}
	 */
	public static function clear_post_css( int $post_id, bool $regenerate = true ): array {
		$cleared = self::clear_post_caches( $post_id );
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$reliable = class_exists( '\Elementor\Core\Files\CSS\Post' )
				&& ! empty( $cleared['post_css'] )
				&& empty( get_post_meta( $post_id, '_elementor_css', true ) );
			if ( $regenerate && [] !== self::unregistered_element_types( self::stored_elements( $post_id ) ?? [] ) ) {
				$regenerate = false;
			}
			IATO_MCP_Cache::queue_post_css( $post_id, $reliable, $regenerate );
		}
		return $cleared;
	}

	/**
	 * Clear one post's Elementor caches: its CSS file and meta, its element cache
	 * and its atomic styles. Rebuilt on the next page view. Reports what was
	 * cleared.
	 */
	private static function clear_post_caches( int $post_id ): array {
		$cleared = [ 'post_css' => false, 'element_cache' => false, 'atomic_styles' => false ];
		try {
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
			}
			delete_post_meta( $post_id, '_elementor_css' );
			$cleared['post_css'] = true;
		} catch ( \Throwable $e ) {
			$cleared['post_css'] = false;
		}
		delete_post_meta( $post_id, '_elementor_element_cache' );
		$cleared['element_cache'] = true;
		if ( function_exists( 'do_action' ) ) {
			try {
				do_action( 'elementor/atomic-widgets/styles/clear', [ 'local', $post_id ] );
				$cleared['atomic_styles'] = true;
			} catch ( \Throwable $e ) {
				$cleared['atomic_styles'] = false;
			}
		}
		wp_cache_delete( $post_id, 'post_meta' );
		return $cleared;
	}

	/**
	 * After the plugin stored a tree itself: did the page keep every element,
	 * and is every element this write did not change stored exactly as before?
	 * Elements compare without their children (each child is compared on its
	 * own). An element counts as changed when the written tree differs from the
	 * stored one at that id, or the id is new.
	 *
	 * @return array{element_count_before:int,element_count_after:int,element_count_expected:int,untouched_identical:bool,mismatched_element_ids?:string[]}
	 */
	public static function verify_untouched( array $before, array $written, string $persisted_meta ): array {
		$persisted = json_decode( $persisted_meta, true );
		$persisted = is_array( $persisted ) ? self::force_arrays( $persisted ) : [];
		$index     = function ( array $tree ): array {
			$out   = [];
			$count = 0;
			self::walk_widgets( $tree, function ( array $element ) use ( &$out, &$count ) {
				$count++;
				$node = $element;
				unset( $node['elements'] );
				$out[ (string) ( $element['id'] ?? '' ) ] = wp_json_encode( $node );
			} );
			return [ $out, $count ];
		};
		[ $b, $before_count ]    = $index( $before );
		[ $w, $written_count ]   = $index( $written );
		[ $p, $persisted_count ] = $index( $persisted );

		$mismatched = [];
		foreach ( $b as $id => $node ) {
			if ( ( $w[ $id ] ?? null ) !== $node ) {
				continue; // changed or removed by this write
			}
			if ( ( $p[ $id ] ?? null ) !== $node ) {
				$mismatched[] = (string) $id;
			}
		}
		$report = [
			'element_count_before'   => $before_count,
			'element_count_after'    => $persisted_count,
			'element_count_expected' => $written_count,
			'untouched_identical'    => empty( $mismatched ) && $persisted_count === $written_count,
		];
		if ( ! empty( $mismatched ) ) {
			$report['mismatched_element_ids'] = $mismatched;
		}
		return $report;
	}

	/**
	 * Dry-run counterpart of what write_pipeline() reports: the sanitising
	 * preview plus, when the tree holds element types Elementor cannot load
	 * right now, the same skip report a real write gives (nothing is written).
	 */
	public static function preview_report( array $new_elements, ?array $stored_elements ): array {
		$report       = self::sanitize_preview( $new_elements, $stored_elements );
		$unregistered = self::unregistered_element_types( self::force_arrays( $new_elements ) );
		if ( ! empty( $unregistered ) ) {
			$report['elementor_save']             = 'skipped';
			$report['skipped_reason']             = 'unregistered_element_types';
			$report['unregistered_element_types'] = $unregistered;
		}
		return $report;
	}

	/**
	 * The refusal for atomic elements Elementor's own validation rejects: every
	 * node of the tree whose id is in $atomic_ids goes through
	 * validate_element_for_save(). Used by write_pipeline() before the plugin
	 * stores a tree itself, and by dry runs, which report the refusal the real
	 * write would give. Null when every changed element passes (or cannot be
	 * checked).
	 */
	public static function atomic_validation_error( int $post_id, array $elements, array $atomic_ids, ?string $save_error = null, array $atomic_paths = [] ): ?WP_Error {
		if ( empty( $atomic_ids ) && empty( $atomic_paths ) ) {
			return null;
		}
		$elements = self::force_arrays( $elements );
		$rejected = array_merge(
			self::rejected_atomic_elements( $elements, array_values( array_map( 'strval', $atomic_ids ) ) ),
			self::rejected_atomic_elements_at( $elements, array_values( array_map( 'strval', $atomic_paths ) ) )
		);
		if ( empty( $rejected ) ) {
			return null;
		}
		$list = implode( '; ', array_map( fn( $r ) => "{$r['element_id']} ({$r['type']}): {$r['elementor_error']}", $rejected ) );
		$data = [
			'status'   => 400,
			'post_id'  => $post_id,
			'elements' => $rejected,
		];
		if ( null !== $save_error ) {
			$data['elementor_error'] = $save_error;
		}
		return new WP_Error(
			'atomic_invalid_settings',
			"Elementor's own validation rejects the new settings of {$list}. Nothing was written. Send each value as the typed value Elementor expects (the shape get_elementor_widget returns under settings for the element, with a valid value), or make the change in Elementor's editor.",
			$data
		);
	}

	/**
	 * Dry-run form of atomic_validation_error(): the tree is first sanitised the
	 * way write_pipeline() step 0 sanitises it (change-only, unless unfiltered
	 * HTML is allowed), so the dry run validates exactly what the write would
	 * hand Elementor and reports the same refusal.
	 */
	public static function dry_run_validation_error( int $post_id, array $elements, ?array $stored_elements, array $atomic_ids, array $atomic_paths = [] ): ?WP_Error {
		if ( empty( $atomic_ids ) && empty( $atomic_paths ) ) {
			return null;
		}
		$elements = self::force_arrays( $elements );
		if ( ! IATO_MCP_Auth::unfiltered_html_allowed() ) {
			$elements = IATO_MCP_Elementor_Sanitizer::diff_tree( $elements, $stored_elements )['elements'];
		}
		return self::atomic_validation_error( $post_id, $elements, $atomic_ids, null, $atomic_paths );
	}

	/**
	 * Atomic nodes of a tree whose id is in $ids (every match: a copy keeps the
	 * id of its source; an element without an id has the id ''), each run
	 * through validate_element_for_save(). Returns the ones Elementor rejects.
	 *
	 * @return array<int,array{element_id:string,type:string,elementor_error:string}>
	 */
	private static function rejected_atomic_elements( array $elements, array $ids ): array {
		$rejected = [];
		self::walk_widgets( $elements, function ( array $element ) use ( &$rejected, $ids ) {
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( ! in_array( $id, $ids, true ) || ! IATO_MCP_Elementor_Atomic::has_atomic_type( $element ) ) {
				return;
			}
			$error = self::validate_element_for_save( $element );
			if ( null !== $error ) {
				$rejected[] = [
					'element_id'      => $id,
					'type'            => IATO_MCP_Elementor_Atomic::type_of( $element ),
					'elementor_error' => $error,
				];
			}
		} );
		return $rejected;
	}

	/**
	 * The atomic nodes at the given JSON pointer paths (the element a
	 * single-element tool edits), each run through validate_element_for_save().
	 * Unlike rejected_atomic_elements(), another node that happens to carry the
	 * same id (a damaged twin on a page with duplicate ids) is not checked.
	 *
	 * @return array<int,array{element_id:string,type:string,elementor_error:string}>
	 */
	private static function rejected_atomic_elements_at( array $elements, array $paths ): array {
		$rejected = [];
		foreach ( $paths as $path ) {
			$node = self::pointer_get( $elements, $path );
			if ( ! is_array( $node ) ) {
				// The edited element is not where the write put it: refuse rather
				// than store a tree nothing validated (1.14.0).
				$rejected[] = [ 'element_id' => '', 'type' => 'unknown', 'elementor_error' => "no element at {$path}" ];
				continue;
			}
			if ( ! IATO_MCP_Elementor_Atomic::has_atomic_type( $node ) && ! IATO_MCP_Elementor_Atomic_Writer::is_target( $node ) ) {
				continue;
			}
			$error = self::validate_element_for_save( $node );
			if ( null !== $error ) {
				$rejected[] = [
					'element_id'      => isset( $node['id'] ) ? (string) $node['id'] : '',
					'type'            => IATO_MCP_Elementor_Atomic::type_of( $node ),
					'elementor_error' => $error,
				];
			}
		}
		return $rejected;
	}

	/** @var callable|null Test seam for validate_element_for_save(): fn(array $node): ?string */
	private static $element_validator = null;

	/** Inject an element validator for tests; null restores Elementor's. */
	public static function set_element_validator( ?callable $validator ): void {
		self::$element_validator = $validator;
	}

	/**
	 * Run one element (without its children, which are checked on their own)
	 * through Elementor's per-element save transform, the step of
	 * Document::save() that validates atomic settings (Has_Atomic_Base::
	 * get_data_for_save(), elementor/modules/atomic-widgets/elements/base/
	 * has-atomic-base.php:247-283). Null when it passes or cannot be checked;
	 * otherwise Elementor's message.
	 */
	private static function validate_element_for_save( array $node ): ?string {
		if ( null !== self::$element_validator ) {
			return ( self::$element_validator )( $node );
		}
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return null;
		}
		$node['elements'] = [];
		try {
			$manager = isset( \Elementor\Plugin::$instance->elements_manager ) ? \Elementor\Plugin::$instance->elements_manager : null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'create_element_instance' ) ) {
				return null;
			}
			$element = $manager->create_element_instance( $node );
			if ( ! is_object( $element ) || ! method_exists( $element, 'get_data_for_save' ) ) {
				return null;
			}
			$element->get_data_for_save();
			return null;
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * Atomic elements of a tree whose stored settings hold a value without its
	 * envelope (IATO_MCP_Elementor_Atomic::invalid_keys()), as
	 * [{widget_id, type, invalid_keys}]. Empty for valid pages.
	 */
	public static function invalid_elements( array $elements ): array {
		$out = [];
		self::walk_widgets( $elements, function ( array $element ) use ( &$out ) {
			$keys = IATO_MCP_Elementor_Atomic::invalid_keys( $element );
			if ( ! empty( $keys ) ) {
				$out[] = [
					'widget_id'    => isset( $element['id'] ) ? (string) $element['id'] : null,
					'type'         => IATO_MCP_Elementor_Atomic::type_of( $element ),
					'invalid_keys' => $keys,
				];
			}
		} );
		return $out;
	}

	/**
	 * The 1.13.1 refusal for values an atomic element cannot store.
	 *
	 * @param array $problems [{element_id, type, plain_keys, unknown_keys}]
	 * @param array $nodes    element id => stored node (for the example shape)
	 * @param array $sent     element id => the settings the caller sent
	 */
	public static function atomic_value_refusal( array $problems, array $nodes, array $sent, ?WP_Error $unavailable = null ): WP_Error {
		$parts    = [];
		$examples = [];
		foreach ( $problems as $p ) {
			$label = "Element {$p['element_id']} ({$p['type']})";
			if ( ! empty( $p['plain_keys'] ) ) {
				$parts[] = $label . ': ' . implode( ', ', $p['plain_keys'] ) . ' sent as a plain value';
				foreach ( $p['plain_keys'] as $key ) {
					$example = isset( $nodes[ $p['element_id'] ] )
						? IATO_MCP_Elementor_Atomic::envelope_example( $nodes[ $p['element_id'] ], $key, $sent[ $p['element_id'] ][ $key ] ?? null )
						: null;
					if ( null !== $example ) {
						$examples[ $p['element_id'] ][ $key ] = $example;
					}
				}
			}
			if ( ! empty( $p['unknown_keys'] ) ) {
				$schema  = isset( $nodes[ $p['element_id'] ] ) ? IATO_MCP_Elementor_Atomic::schema_props( $nodes[ $p['element_id'] ] ) : null;
				$known   = null !== $schema ? ' (its settings: ' . implode( ', ', array_keys( $schema ) ) . ')' : '';
				$parts[] = $label . ': ' . implode( ', ', $p['unknown_keys'] ) . ' ' . ( count( $p['unknown_keys'] ) > 1 ? 'are not settings' : 'is not a setting' ) . " of {$p['type']}{$known}";
			}
		}

		// The writer's absence explains plain values only; keys the element does
		// not have are refused as in 1.13.1.
		$has_plain = (bool) array_filter( $problems, fn( $p ) => ! empty( $p['plain_keys'] ) );
		if ( ! $has_plain ) {
			$unavailable = null;
		}
		$message = 'Refused: ' . implode( '; ', $parts ) . '. ';
		if ( $has_plain ) {
			$why      = null !== $unavailable
				? 'IATO converts plain values for these elements with Elementor\'s own code, which is not available here: ' . preg_replace( '/ Nothing was written\..*$/s', '', $unavailable->get_error_message() )
				: 'IATO could not convert these values with Elementor\'s code for this element.';
			$message .= 'Elementor Atomic Editor (V4) elements store each setting as a typed value, {"$$type": ..., "value": ...}. '
				. $why . ' '
				. 'Storing a plain value would break the element: Elementor drops links and images it cannot read and refuses to save the page. '
				. 'To make the change now, send each setting as a typed value in the shape get_elementor_widget returns under settings for the element';
		} else {
			$message .= 'Send only the element\'s own settings';
		}
		if ( ! empty( $examples ) ) {
			$first_id  = array_key_first( $examples );
			$first_key = array_key_first( $examples[ $first_id ] );
			$message  .= ', for example ' . wp_json_encode( [ $first_key => $examples[ $first_id ][ $first_key ] ], JSON_UNESCAPED_SLASHES );
		}
		$message .= ", or make the change in Elementor's editor. Nothing was written.";

		$data = [ 'status' => 400, 'elements' => $problems ];
		if ( ! empty( $examples ) ) {
			$data['examples'] = $examples;
		}
		if ( $has_plain ) {
			$extra = null !== $unavailable ? $unavailable->get_error_data() : null;
			if ( is_array( $extra ) && isset( $extra['elementor_version'] ) ) {
				$data['elementor_version'] = $extra['elementor_version'];
			}
			return new WP_Error( 'atomic_write_unavailable', $message, $data );
		}
		return new WP_Error( 'atomic_plain_value_unsupported', $message, $data );
	}

	/**
	 * The 1.13.1 guard for whole-tree writes (update_elementor_patch,
	 * update_elementor_data): what the change would store on atomic elements,
	 * compared with the stored tree by element id
	 * (IATO_MCP_Elementor_Atomic::tree_changes()). Returns the refusal (null
	 * when nothing is refused) and the ids of the atomic elements the change
	 * touches, for write_pipeline()'s atomic_ids.
	 *
	 * @return array{error:?WP_Error,changed_ids:string[]}
	 */
	public static function check_atomic_tree( ?array $stored, array $new, ?WP_Error $unavailable = null ): array {
		$stored  = $stored ?? [];
		$changes = IATO_MCP_Elementor_Atomic::tree_changes( $stored, $new );
		if ( empty( $changes['problems'] ) ) {
			return [ 'error' => null, 'changed_ids' => $changes['changed_ids'] ];
		}
		$nodes = [];
		$sent  = [];
		foreach ( $changes['problems'] as $problem ) {
			$id          = $problem['element_id'];
			$stored_node = self::find_widget( $stored, $id )['element'] ?? null;
			$new_node    = self::find_widget( $new, $id )['element'] ?? [];
			// The example shape comes from the stored element when it is the same
			// kind of element, otherwise from the element as the change sets it.
			$same_kind    = is_array( $stored_node ) && IATO_MCP_Elementor_Atomic::type_of( $stored_node ) === IATO_MCP_Elementor_Atomic::type_of( $new_node );
			$nodes[ $id ] = $same_kind ? $stored_node : $new_node;
			$sent[ $id ]  = is_array( $new_node['settings'] ?? null ) ? $new_node['settings'] : [];
		}
		return [ 'error' => self::atomic_value_refusal( $changes['problems'], $nodes, $sent, $unavailable ), 'changed_ids' => $changes['changed_ids'] ];
	}

	/** Error codes the Elementor tools return as JSON text (code, message, details). */
	public const DETAILED_ERRORS = [
		'atomic_plain_value_unsupported',
		'atomic_invalid_settings',
		// 1.14.0, the atomic writer.
		'atomic_write_unavailable',
		'atomic_prop_not_supported',
		'atomic_type_not_supported',
		'atomic_duplicate_key',
		'atomic_value_unresolvable',
		'atomic_value_dropped',
		'atomic_subkeys_dropped',
		'atomic_url_rejected',
		'atomic_library_alt_separate',
		'elementor_cannot_edit',
		'elementor_validation_failed',
		'elementor_invalid_tree',
		'elementor_editor_unsaved_changes',
		// 1.16.0.
		'elementor_not_active',
	];

	/** Post types whose classic content never shows; revisions follow their parent. */
	private const NO_CLASSIC_CONTENT_POST_TYPES = [ 'elementor_library', 'e-floating-buttons', 'revision' ];

	/**
	 * Where a post stands with Elementor (1.16.0).
	 *
	 * "Back to WordPress Editor" clears Elementor's flag and keeps the elements, so
	 * a post can hold both. Visitors then see post_content, which Elementor's save
	 * replaces.
	 *
	 * @return string `active`: the flag is on (any truthy value, Elementor's own
	 *                rule), or the post type never shows classic content.
	 *                `no_tree`: flag off and no elements stored.
	 *                `inactive_tree`: flag off with elements stored, or stored
	 *                data that cannot be read, which is treated the same way.
	 */
	public static function builder_state( int $post_id ): string {
		if ( (bool) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return 'active';
		}
		$post = get_post( $post_id );
		if ( $post && in_array( (string) ( $post->post_type ?? '' ), self::NO_CLASSIC_CONTENT_POST_TYPES, true ) ) {
			return 'active';
		}
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return 'no_tree';
		}
		$tree = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return [] === $tree ? 'no_tree' : 'inactive_tree';
	}

	/**
	 * A tree Elementor cannot render: storing one turns the page into an HTTP 500 for
	 * every visitor, and Elementor's save reports nothing wrong (1.16.0). Limited to
	 * what was seen to break it (checked on Elementor 4.3.5): an element with no id or
	 * an unknown type, or elements with gaps in their numbering, render and stay
	 * allowed, so old pages are not refused. It looks at the tree's shape, not at each
	 * widget's settings: a setting of the wrong type can still break a page.
	 *
	 * @param array $tree The decoded tree, objects as arrays.
	 * @return WP_Error|null WP_Error('elementor_invalid_tree'), with invalid_nodes.
	 */
	public static function tree_shape_error( array $tree ): ?WP_Error {
		$problems = [];
		// Numbered, with or without gaps: a tree is never an object with named keys,
		// which is also what an envelope is (IATO_MCP_Rollback).
		if ( [] !== array_filter( array_keys( $tree ), 'is_string' ) ) {
			$problems[] = 'the tree is an object, not a list of elements';
		} else {
			self::collect_tree_problems( $tree, '', $problems );
		}
		if ( [] === $problems ) {
			return null;
		}
		return new WP_Error(
			'elementor_invalid_tree',
			'This is not a valid Elementor element tree, and storing it would break the page for visitors. Nothing was written. Send a JSON array of elements, each with an elType, and a widgetType on widgets.',
			[ 'invalid_nodes' => array_slice( $problems, 0, 10 ) ]
		);
	}

	/** @param array $nodes A tree or an element's children. */
	private static function collect_tree_problems( array $nodes, string $path, array &$problems ): void {
		foreach ( $nodes as $i => $node ) {
			$here = $path . '/' . $i;
			if ( ! is_array( $node ) ) {
				$problems[] = $here . ' is not an element';
				continue;
			}
			if ( ! is_string( $node['elType'] ?? null ) || '' === $node['elType'] ) {
				$problems[] = $here . ' has no elType';
				continue;
			}
			if ( 'widget' === $node['elType'] && ( ! is_string( $node['widgetType'] ?? null ) || '' === $node['widgetType'] ) ) {
				$problems[] = $here . ' is a widget with no widgetType';
			}
			if ( array_key_exists( 'settings', $node ) && ! is_array( $node['settings'] ) ) {
				$problems[] = $here . ' has settings that are not an object';
			}
			if ( isset( $node['id'] ) && ! is_scalar( $node['id'] ) ) {
				$problems[] = $here . ' has an id that is not a string';
			}
			if ( is_array( $node['elements'] ?? null ) ) {
				self::collect_tree_problems( $node['elements'], $here . '/elements', $problems );
			}
		}
	}

	/**
	 * Refuses an Elementor write to a post with Elementor turned off, whose
	 * post_content the save would replace. A stored empty tree counts too: only
	 * update_elementor_data can start a page from one, because it saves what it
	 * replaces. A post with no Elementor data at all gets null, since the tools
	 * that need data answer not_elementor themselves.
	 */
	public static function inactive_tree_refusal( int $post_id ): ?WP_Error {
		if ( 'active' === self::builder_state( $post_id ) || empty( get_post_meta( $post_id, '_elementor_data', true ) ) ) {
			return null;
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		$is_text = self::content_is_elementor_text( $post_id, $content );
		$detail  = true === $is_text
			? 'Its content is only Elementor\'s own text version of that data, so switching loses nothing written by hand.'
			: 'Visitors see the post\'s own content, which an Elementor write would replace, so ask the user first.';
		return new WP_Error(
			'elementor_not_active',
			"Post {$post_id} holds Elementor data, but Elementor is turned off for it. Nothing was written. {$detail} To switch it to Elementor, call update_elementor_data with activate_builder: true; rollback then restores the content, the setting and the old data.",
			[
				'status'                            => 409,
				'post_id'                           => $post_id,
				'content_chars'                     => strlen( $content ),
				'content_is_elementor_text'         => $is_text,
			]
		);
	}

	/**
	 * After a rollback puts a tree back directly, post_content still holds the text
	 * of the tree it replaced, which search and feeds read. This brings it in line,
	 * as Elementor's own save does. Elementor's page output comes from the tree, so
	 * visitors are unaffected either way, and a failure is only a note.
	 *
	 * Skipped when Elementor is off (post_content is what visitors see) or the page
	 * holds elements Elementor cannot load, whose text would go missing.
	 *
	 * @param array|null $elements the tree a dry run would restore; null after the
	 *                             real restore, when it is the stored one
	 * @return array{content_refreshed?:true,would_refresh_content?:true,content_note?:string}
	 */
	public static function refresh_post_content( int $post_id, ?array $elements = null ): array {
		$dry_run = null !== $elements;
		$tree    = $elements ?? self::stored_elements( $post_id ) ?? [];
		if ( 'active' !== self::builder_state( $post_id ) || ! empty( self::unregistered_element_types( $tree ) ) ) {
			return [];
		}
		$text = self::plain_text_of( $post_id, $elements );
		if ( null === $text ) {
			return class_exists( '\Elementor\Plugin' ) ? [ 'content_note' => 'The page was restored, but its post_content could not be brought up to date. Search and feeds may show the older text until the page is next saved.' ] : [];
		}
		if ( $text === (string) get_post_field( 'post_content', $post_id ) ) {
			return [];
		}
		if ( $dry_run ) {
			return [ 'would_refresh_content' => true ];
		}
		try {
			$result = IATO_MCP_Inline_Markup::write_without_style_in_content(
				(string) wp_json_encode( $tree ),
				fn() => IATO_MCP_Auth::write_unlocked( fn() => IATO_MCP_Auth::update_post_fields( [ 'ID' => $post_id, 'post_content' => $text ] ) )
			);
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'refresh_failed', $e->getMessage() );
		}
		if ( is_wp_error( $result ) || empty( $result ) ) {
			return [ 'content_note' => 'The page was restored, but its post_content could not be brought up to date. Search and feeds may show the older text until the page is next saved.' ];
		}
		wp_cache_delete( $post_id, 'posts' );
		return [ 'content_refreshed' => true ];
	}

	/**
	 * Is this content only Elementor's text version of the post's own data? Then
	 * nothing written by hand is at stake (a page from before 1.16.0, when
	 * update_elementor_data did not turn Elementor on). Used for wording only, never
	 * to let a write through. Null when it cannot be told.
	 */
	public static function content_is_elementor_text( int $post_id, string $content ): ?bool {
		if ( '' === trim( $content ) ) {
			return true;
		}
		$text = self::plain_text_of( $post_id );
		if ( null === $text ) {
			return null;
		}
		$visible = static fn( string $html ): string => trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return $visible( $content ) === $visible( $text );
	}

	/**
	 * Elementor's text version of a post's elements, or null when it cannot be
	 * had. Elementor's save_plain_text() hides errors and can leave an output buffer
	 * open when a widget throws, which would corrupt the JSON response, so the
	 * parsing mode and the buffers are restored here whatever happens.
	 *
	 * @param array|null $elements a tree to render instead of the stored one
	 */
	public static function plain_text_of( int $post_id, ?array $elements = null ): ?string {
		if ( ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance->db ) ) {
			return null;
		}
		$plugin = \Elementor\Plugin::$instance;
		$tags   = $plugin->dynamic_tags ?? null;
		$mode   = null;
		$level  = ob_get_level();
		try {
			if ( $tags && class_exists( '\Elementor\Core\DynamicTags\Manager' ) ) {
				$mode = $tags->get_parsing_mode();
				$tags->set_parsing_mode( \Elementor\Core\DynamicTags\Manager::MODE_REMOVE );
			}
			$text = null === $elements ? $plugin->db->get_plain_text( $post_id ) : $plugin->db->get_plain_text_from_data( $elements );
			return is_string( $text ) ? $text : null;
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			IATO_MCP_Server::close_buffers( $level );
			if ( null !== $mode ) {
				$tags->set_parsing_mode( $mode );
			}
		}
	}

	/**
	 * Tool-level form of the atomic refusals: an isError result whose text is
	 * JSON (error code, message, details), because MCP clients see only the
	 * text. Any other WP_Error is returned unchanged.
	 */
	public static function tool_error( WP_Error $error ): array|WP_Error {
		$code = $error->get_error_code();
		if ( ! in_array( $code, self::DETAILED_ERRORS, true ) ) {
			return $error;
		}
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];
		unset( $data['status'] );
		return [
			'isError' => true,
			'content' => [ [
				'type' => 'text',
				'text' => wp_json_encode( [ 'error' => $code, 'message' => $error->get_error_message() ] + $data, JSON_UNESCAPED_SLASHES ),
			] ],
		];
	}

	/** The stored element tree (arrays), or null when there is none. */
	public static function stored_elements( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return self::force_arrays( $raw );
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? self::force_arrays( $decoded ) : null;
	}

	/**
	 * Keys write_pipeline() adds about Elementor's save and sanitising, copied
	 * onto tool responses so the caller sees what happened to its content.
	 */
	public static function pipeline_report( array $pipeline ): array {
		$out = [ 'elementor_save' => $pipeline['elementor_save'] ?? null, 'stored_by' => $pipeline['stored_by'] ?? null ];
		if ( ! empty( $pipeline['builder_mode_set'] ) ) {
			$out['builder_mode_set'] = true;
		}
		if ( isset( $pipeline['elementor_save_error'] ) ) {
			$out['elementor_save_error'] = $pipeline['elementor_save_error'];
		}
		if ( ! empty( $pipeline['invalid_elements'] ) ) {
			// 1.13.1: elements elsewhere on the page that Elementor rejected.
			$out['invalid_elements'] = $pipeline['invalid_elements'];
		}
		// 1.13.1: Elementor's save skipped because the page holds element types
		// Elementor cannot load right now; the plugin stored the page itself.
		foreach ( [ 'skipped_reason', 'unregistered_element_types', 'skipped_for_elements', 'cache_cleared', 'verification', 'css', 'css_regenerated', 'css_note', 'render_error' ] as $key ) {
			if ( isset( $pipeline[ $key ] ) ) {
				$out[ $key ] = $pipeline[ $key ];
			}
		}
		if ( ! empty( $pipeline['sanitized'] ) ) {
			$out['sanitized']       = true;
			$out['sanitized_paths'] = $pipeline['sanitized_paths'];
		}
		if ( ! empty( $pipeline['sanitized_report'] ) ) {
			$out['sanitized_report'] = $pipeline['sanitized_report'];
		}
		return $out;
	}

	// ── What was stored, and smaller responses (1.21.0) ─────────────────────

	/** A value's size and SHA-256: of the string itself, or of its JSON for anything else. */
	public static function value_digest( mixed $value ): array {
		$text = is_string( $value ) ? $value : (string) wp_json_encode( $value );
		return [ 'bytes' => strlen( $text ), 'sha256' => hash( 'sha256', $text ) ];
	}

	/**
	 * The same data? Strings byte for byte, objects whatever the order of their
	 * keys (1.21.1: Elementor's save writes an element's keys in its own order, so
	 * a real write read back as different where the dry run did not), lists in order.
	 */
	private static function same_stored_value( mixed $a, mixed $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		if ( is_string( $a ) || is_string( $b ) ) {
			return false;
		}
		return wp_json_encode( self::canonical( $a ) ) === wp_json_encode( self::canonical( $b ) );
	}

	/** Objects with their keys sorted, recursively; lists keep their order. */
	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			$out[ $key ] = self::canonical( $item );
		}
		if ( ! self::is_list( $out ) ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	/** At most this many paths in `added_paths` and in `removed_paths`. */
	private const ADDED_PATHS_MAX = 20;

	/**
	 * Why a stored value differs from the one sent, as `stored_difference`:
	 * - `markup_normalized`: a string holds the same HTML written differently
	 *   (quotes, character references, attribute order or case). Needs WordPress
	 *   6.5; below it such a string is `changed`.
	 * - `keys_added`: the save added keys, listed in `added_paths`.
	 * - `keys_removed`: the save dropped keys that were sent, listed in
	 *   `removed_paths` (Elementor's save drops `isInner` from a widget).
	 * - `changed`: a value differs otherwise; sanitized_report names what the
	 *   filters removed.
	 *
	 * @param array $kinds   Set of the kinds found, by reference.
	 * @param array $added   Paths of added keys, by reference.
	 * @param array $removed Paths of dropped keys, by reference.
	 */
	private static function stored_difference( mixed $sent, mixed $stored, string $path, array &$kinds, array &$added, array &$removed ): void {
		if ( self::same_stored_value( $sent, $stored ) ) {
			return;
		}
		if ( is_string( $sent ) && is_string( $stored ) ) {
			$kinds[ true === IATO_MCP_Sanitize_Report::same_markup( $sent, $stored ) ? 'markup_normalized' : 'changed' ] = true;
			return;
		}
		if ( ! is_array( $sent ) || ! is_array( $stored ) ) {
			$kinds['changed'] = true;
			return;
		}
		$lists = self::is_list( $sent ) && self::is_list( $stored ) && [] !== $sent && [] !== $stored;
		if ( $lists && count( $sent ) !== count( $stored ) ) {
			$kinds['changed'] = true;
			return;
		}
		foreach ( $sent as $key => $item ) {
			$at = $path . '/' . self::escape_pointer_segment( (string) $key );
			if ( ! array_key_exists( $key, $stored ) ) {
				$kinds[ $lists ? 'changed' : 'keys_removed' ] = true;
				$removed[] = $at;
				continue;
			}
			self::stored_difference( $item, $stored[ $key ], $at, $kinds, $added, $removed );
		}
		foreach ( array_diff_key( $stored, $sent ) as $key => $unused ) {
			$kinds[ $lists ? 'changed' : 'keys_added' ] = true;
			$added[] = $path . '/' . self::escape_pointer_segment( (string) $key );
		}
	}

	/** Every element's pointer, by id: [id => [pointer, ...]]. */
	private static function element_paths( array $nodes, string $prefix, array &$out ): void {
		foreach ( $nodes as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$pointer = $prefix . '/' . self::escape_pointer_segment( (string) $key );
			if ( isset( $node['id'], $node['elType'] ) && is_scalar( $node['id'] ) ) {
				$out[ (string) $node['id'] ][] = $pointer;
			}
			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::element_paths( $node['elements'], $pointer . '/elements', $out );
			}
		}
	}

	/**
	 * Where an op's value is stored: through its element's id when apply_rfc6902()
	 * noted one (exactly one element with that id), else at its path.
	 *
	 * @return string|null The pointer, or null when it cannot be found.
	 */
	private static function stored_pointer( array $tree, array $op, ?array $at, ?array &$ids ): ?string {
		if ( is_array( $at ) && isset( $at['id'] ) ) {
			if ( null === $ids ) {
				$ids = [];
				self::element_paths( $tree, '', $ids );
			}
			$paths = $ids[ $at['id'] ] ?? [];
			return 1 === count( $paths ) ? $paths[0] . (string) ( $at['rest'] ?? '' ) : null;
		}
		return (string) ( $at['path'] ?? $op['path'] ?? '' );
	}

	/**
	 * Say for each op that set a value whether the tree as stored holds what was
	 * sent (`stored_matches_input`), and when it does not, what it holds instead:
	 * `stored_bytes`, `stored_sha256` and `stored_value`, and why it differs
	 * (`stored_difference`, see stored_difference()). The sanitising pass, or
	 * Elementor's own save, can change a value on the way in, and `value` is what
	 * the client sent. An op whose target cannot be read back is left as it is.
	 * Removes apply_rfc6902()'s `_stored_at` from every op.
	 *
	 * @param array      $applied The ops, each with a JSON pointer `path` into the tree.
	 * @param array|null $tree    The tree as stored (or, for a dry run, as it would be).
	 */
	public static function annotate_stored( array $applied, ?array $tree ): array {
		$ids = null;
		foreach ( $applied as $i => $op ) {
			if ( ! is_array( $op ) ) {
				continue;
			}
			$at = isset( $op['_stored_at'] ) && is_array( $op['_stored_at'] ) ? $op['_stored_at'] : null;
			unset( $applied[ $i ]['_stored_at'] );
			if ( null === $tree || ! array_key_exists( 'value', $op ) || ! in_array( $op['op'] ?? '', [ 'add', 'replace' ], true ) ) {
				continue;
			}
			$pointer = self::stored_pointer( $tree, $op, $at, $ids );
			$stored  = null === $pointer ? null : self::pointer_get( $tree, $pointer );
			if ( null === $pointer || is_wp_error( $stored ) ) {
				continue;
			}
			$matches                              = self::same_stored_value( $op['value'], $stored );
			$applied[ $i ]['stored_matches_input'] = $matches;
			if ( ! $matches ) {
				$digest                         = self::value_digest( $stored );
				$applied[ $i ]['stored_bytes']  = $digest['bytes'];
				$applied[ $i ]['stored_sha256'] = $digest['sha256'];
				$applied[ $i ]                  = self::with_difference( $applied[ $i ], $op['value'], $stored, $pointer );
				$applied[ $i ]['stored_value']  = $stored;
			}
		}
		return $applied;
	}

	/** Add `stored_difference` (and `added_paths`) to an op whose stored value differs. */
	private static function with_difference( array $op, mixed $sent, mixed $stored, string $path ): array {
		$kinds   = [];
		$added   = [];
		$removed = [];
		self::stored_difference( $sent, $stored, $path, $kinds, $added, $removed );
		if ( [] === $kinds ) {
			$kinds['changed'] = true; // differs, and no part of it says how
		}
		$op['stored_difference'] = array_values( array_intersect( [ 'changed', 'keys_added', 'keys_removed', 'markup_normalized' ], array_keys( $kinds ) ) );
		if ( isset( $kinds['keys_added'] ) ) {
			$op['added_paths'] = array_slice( $added, 0, self::ADDED_PATHS_MAX );
		}
		if ( isset( $kinds['keys_removed'] ) ) {
			$op['removed_paths'] = array_slice( $removed, 0, self::ADDED_PATHS_MAX );
		}
		return $op;
	}

	/**
	 * Atomic ops already carry the stored value in `value`: compare it with what the
	 * patch sent for that key. A plain value Elementor wrapped in its typed envelope
	 * is stored differently, and says so.
	 */
	public static function annotate_stored_atomic( array $applied, array $settings_patch ): array {
		foreach ( $applied as $i => $op ) {
			if ( ! is_array( $op ) || ! array_key_exists( 'value', $op ) ) {
				continue;
			}
			$key = str_replace( [ '~1', '~0' ], [ '/', '~' ], (string) substr( (string) strrchr( (string) ( $op['path'] ?? '' ), '/' ), 1 ) );
			if ( ! array_key_exists( $key, $settings_patch ) ) {
				continue;
			}
			$matches                              = self::same_stored_value( $settings_patch[ $key ], $op['value'] );
			$applied[ $i ]['stored_matches_input'] = $matches;
			if ( ! $matches ) {
				$digest                         = self::value_digest( $op['value'] );
				$applied[ $i ]['stored_bytes']  = $digest['bytes'];
				$applied[ $i ]['stored_sha256'] = $digest['sha256'];
				// No stored_difference: the difference here is Elementor's typed envelope.
			}
		}
		return $applied;
	}

	/** The tree a dry run would store: the sanitising pass's output, unless it does not run. */
	public static function sanitized_tree( array $new_elements, ?array $stored_elements ): array {
		$new_elements = self::force_arrays( $new_elements );
		if ( IATO_MCP_Auth::unfiltered_html_allowed() ) {
			return $new_elements;
		}
		return IATO_MCP_Elementor_Sanitizer::diff_tree( $new_elements, $stored_elements )['elements'];
	}

	/** The `return` argument of the Elementor write tools: 'full' (the default), 'minimal', or a refusal. */
	public static function return_mode( array $args ): string|WP_Error {
		$mode = $args['return'] ?? 'full';
		if ( ! in_array( $mode, [ 'full', 'minimal' ], true ) ) {
			return new WP_Error( 'invalid_return', 'return must be "full" or "minimal". Nothing was written.' );
		}
		return $mode;
	}

	/**
	 * With `return: "minimal"`, values become their size and hash: each op's `value`,
	 * `previous_value` and `stored_value`, an atomic dry run's `would_store`, a dry run's
	 * planned inherited values; inherited receipts become slim. Everything else stays,
	 * the sanitising report, the receipt and the cache object included.
	 */
	public static function shape_response( array $response, string $mode ): array {
		if ( 'minimal' !== $mode ) {
			return $response;
		}
		if ( isset( $response['applied_patch'] ) && is_array( $response['applied_patch'] ) ) {
			foreach ( $response['applied_patch'] as $i => $op ) {
				if ( ! is_array( $op ) ) {
					continue;
				}
				foreach ( [ 'value' => 'value', 'previous_value' => 'previous' ] as $key => $prefix ) {
					if ( array_key_exists( $key, $op ) ) {
						$digest                           = self::value_digest( $op[ $key ] );
						$op[ $prefix . '_bytes' ]         = $digest['bytes'];
						$op[ $prefix . '_sha256' ]        = $digest['sha256'];
						unset( $op[ $key ] );
					}
				}
				unset( $op['stored_value'] );
				$response['applied_patch'][ $i ] = $op;
			}
		}
		if ( isset( $response['would_store'] ) && is_array( $response['would_store'] ) ) {
			$response['would_store'] = array_map( [ self::class, 'value_digest' ], $response['would_store'] );
		}
		if ( isset( $response['inherit_planned'] ) && is_array( $response['inherit_planned'] ) ) {
			foreach ( $response['inherit_planned'] as $i => $step ) {
				foreach ( [ 'before', 'after' ] as $key ) {
					if ( is_array( $step ) && array_key_exists( $key, $step ) ) {
						$response['inherit_planned'][ $i ][ $key ] = self::value_digest( $step[ $key ] );
					}
				}
			}
		}
		if ( isset( $response['change_receipts'] ) && is_array( $response['change_receipts'] ) ) {
			$response['change_receipts'] = array_map( [ 'IATO_MCP_Change_Receipt', 'slim' ], $response['change_receipts'] );
		}
		return $response;
	}

	/** Dry-run counterpart: what the sanitising pass would change. Empty when nothing. */
	public static function sanitize_preview( array $new_elements, ?array $stored_elements ): array {
		if ( IATO_MCP_Auth::unfiltered_html_allowed() ) {
			return [];
		}
		$r = IATO_MCP_Elementor_Sanitizer::preview( self::force_arrays( $new_elements ), $stored_elements );
		$sanitized = ! empty( $r['sanitized'] );
		unset( $r['sanitized'] );
		return ( $sanitized ? [ 'sanitized' => true ] : [] ) + $r;
	}

	/**
	 * Does the stored _elementor_data hold the same element tree (ids, in
	 * order, with the same nesting) as the tree we wrote? Used instead of a
	 * byte-length comparison because Elementor's Document::save() re-encodes
	 * the JSON when it runs.
	 */
	public static function same_element_tree( string $stored_json, array $written ): bool {
		$stored = json_decode( $stored_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $stored ) ) {
			return false;
		}
		return self::id_tree( self::force_arrays( $stored ) ) === self::id_tree( $written );
	}

	/** @return array<int,array{0:string,1:array}> nested [id, children] tuples */
	private static function id_tree( array $elements ): array {
		$out = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? self::id_tree( $element['elements'] ) : [];
			$out[]    = [ (string) ( $element['id'] ?? '' ), $children ];
		}
		return $out;
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
		$refusal = self::inactive_tree_refusal( $post_id );
		if ( null !== $refusal ) {
			return $refusal;
		}
		[ $elements, $raw ] = $decoded;
		$previous_revision  = self::compute_revision( $raw );
		$stored_elements    = $elements; // the tree before this request's change

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

		// Elementor Atomic (V4) elements store every setting as a typed
		// envelope. 1.14.0: the atomic writer converts and validates values with
		// Elementor's own code (IATO_MCP_Elementor_Atomic_Writer). Where it
		// cannot (Elementor before 4.3.0, the element type not registered), the
		// 1.13.1 guard stays: plain values and keys the element does not have
		// are refused (dry runs included), typed values go through as before.
		$atomic_target = IATO_MCP_Elementor_Atomic_Writer::is_target( $found['element'] );
		if ( $atomic_target ) {
			// A type Elementor cannot load right now: nothing can validate it.
			$unregistered = IATO_MCP_Elementor_Atomic_Writer::unregistered( $found['element'] );
			if ( null !== $unregistered ) {
				return $unregistered;
			}
			$unavailable = IATO_MCP_Elementor_Atomic_Writer::unavailable( $found['element'] );
			if ( null === $unavailable ) {
				$result = IATO_MCP_Elementor_Atomic_Writer::update_widget(
					$post_id,
					$widget_id,
					$found['path'],
					$settings_patch,
					[ 'dry_run' => $dry_run, 'echo_previous' => null !== $if_revision ]
				);
				if ( is_array( $result ) && isset( $result['applied_patch'] ) ) {
					$result['applied_patch'] = self::annotate_stored_atomic( (array) $result['applied_patch'], $settings_patch );
				}
				return $result;
			}
			$problems = IATO_MCP_Elementor_Atomic::patch_problems( $found['element'], $settings_patch );
			if ( $problems['plain_keys'] || $problems['unknown_keys'] ) {
				return self::atomic_value_refusal(
					[ [ 'element_id' => $widget_id, 'type' => IATO_MCP_Elementor_Atomic::type_of( $found['element'] ) ] + $problems ],
					[ $widget_id => $found['element'] ],
					[ $widget_id => $settings_patch ],
					$unavailable
				);
			}
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
			] + self::classic_markers( $atomic_target );
			if ( $echo_prev ) {
				$noop = [ 'previous_revision' => $previous_revision ] + $noop;
			}
			return $noop;
		}

		if ( $dry_run ) {
			// A page already holding what the write's backstop refuses.
			$shape = self::tree_shape_error( self::force_arrays( $elements ) );
			if ( null !== $shape ) {
				return $shape;
			}
			// 1.13.1: the per-element validation the write would run.
			if ( $atomic_target ) {
				$refusal = self::dry_run_validation_error( $post_id, $elements, $stored_elements, [], [ $widget_path ] );
				if ( null !== $refusal ) {
					return $refusal;
				}
			}
			$preview = [
				'post_id'          => $post_id,
				'widget_id'        => $widget_id,
				'dry_run'          => true,
				'current_revision' => null,
				'applied_patch'    => self::annotate_stored( $applied_patch, self::sanitized_tree( $elements, $stored_elements ) ),
			] + self::classic_markers( $atomic_target ) + self::preview_report( $elements, $stored_elements );
			if ( $echo_prev ) {
				$preview = [ 'previous_revision' => $previous_revision ] + $preview;
			}
			return $preview;
		}

		$pipeline = self::write_pipeline(
			$post_id,
			$elements,
			$previous_revision,
			$stored_elements,
			$atomic_target ? [ 'atomic_paths' => [ $widget_path ] ] : []
		);
		if ( is_wp_error( $pipeline ) ) {
			return $pipeline;
		}

		$response = [
			'post_id'             => $post_id,
			'widget_id'           => $widget_id,
			'current_revision'    => $pipeline['current_revision'],
			// 1.21.0: read back, so the response says what was stored, not only what was sent.
			'applied_patch'       => self::annotate_stored( $applied_patch, self::stored_elements( $post_id ) ),
			'content_updated'     => $pipeline['content_updated'],
			'post_content_length' => $pipeline['post_content_length'],
		] + self::classic_markers( $atomic_target ) + self::pipeline_report( $pipeline );
		if ( $echo_prev ) {
			$response = [ 'previous_revision' => $pipeline['previous_revision'] ] + $response;
		}

		// Before value: the stored tree as it was (1.13.0), so the change can be
		// rolled back; after value: the revision hash of what the write stored,
		// which the rollback conflict check compares with the page's current one.
		$receipt_field = 'elementor:' . $widget_id . ':' . self::truncate_pointer( $widget_path . '/settings' );
		$receipt       = IATO_MCP_Change_Receipt::record(
			$post_id,
			'elementor_widget',
			$receipt_field,
			$raw,
			$pipeline['current_revision']
		);
		// Slim receipt for the API response — client already has applied_patch
		// at the top level + previous_revision / current_revision as dedicated
		// fields. Stored row keeps full before/after for audit/rollback.
		$response['change_receipt'] = IATO_MCP_Change_Receipt::slim( $receipt );

		return $response;
	}

	/**
	 * 1.14.0 result markers: how the change was applied (`applied_via`), the
	 * element's schema, and `state: live` (written to the post, not staged in
	 * an autosave). Atomic results from the writer carry the same keys.
	 */
	public static function classic_markers( bool $atomic = false ): array {
		return [
			'applied_via' => IATO_MCP_Elementor_Atomic_Writer::APPLIED_VIA,
			'schema'      => $atomic ? IATO_MCP_Elementor_Atomic::SCHEMA_ATOMIC : IATO_MCP_Elementor_Atomic::SCHEMA_CLASSIC,
			'state'       => 'live',
		];
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
