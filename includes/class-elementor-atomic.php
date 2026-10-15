<?php
/**
 * Elementor Atomic (Editor V4) reader.
 *
 * Pure-PHP normalisation layer for atomic elements stored in _elementor_data.
 * Atomic elements (Elementor 4.x "Atomic Editor") use `e-` prefixed type names
 * and wrap every setting in a typed envelope:
 *
 *     { "$$type": "<prop-type-key>", "value": <payload>, "disabled"?: true }
 *
 * (elementor/modules/atomic-widgets/prop-types/concerns/has-generate.php:12-22)
 *
 * This class unwraps those envelopes into plain values, fills schema defaults
 * that Elementor does not persist, and produces the same peek-field keys the
 * classic reader already emits (title, header_size, text, editor, link) so
 * downstream clients keep one code path.
 *
 * It reads stored data only. The WordPress calls (attachment alt / URL,
 * permalink for post-ID links) and the one Elementor lookup (an element type's
 * registered props schema, 1.13.1, guarded and cached) go through an
 * injectable resolver so the class is unit-testable without a WordPress
 * bootstrap and so reads keep working with Elementor deactivated.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Elementor_Atomic {

	public const SCHEMA_CLASSIC = 'classic';
	public const SCHEMA_ATOMIC  = 'atomic';

	/** Recursion guard for unwrap(). Elementor itself caps resolver passes at 3. */
	private const MAX_DEPTH = 32;

	/** Peek-field truncation length, mirrors IATO_MCP_Elementor_Adapter::peek_fields(). */
	private const PEEK_LEN = 80;

	/**
	 * Text prop-type keys, oldest to newest. Migrations between them only run
	 * when the editor loads a document (elementor/modules/atomic-widgets/
	 * prop-type-migrations/migrations-orchestrator.php:39-41), so raw meta may
	 * still hold any of them.
	 *
	 * string / html / escaped-html : value is a string
	 * html-v2                      : value is { content: string }
	 * html-v3                      : value is { content: {$$type:string,value}, children }
	 * (elementor/migrations/manifest.json:11-40)
	 */
	private const TEXT_TYPES = [ 'string', 'html', 'escaped-html', 'html-v2', 'html-v3' ];

	/**
	 * Atomic types whose rendered tag follows the link tag when a link is set
	 * (Has_Atomic_Base::html_tag_follows_link() defaults to true; heading,
	 * paragraph and image override it to false —
	 * elementor/modules/atomic-widgets/elements/base/has-atomic-base.php:36-38,
	 * atomic-heading.php:49-51, atomic-paragraph.php:50-52, atomic-image.php:48-50).
	 */
	private const NO_FOLLOW_LINK = [ 'e-heading', 'e-paragraph', 'e-image' ];

	/** Hard-coded render tags for types without a `tag` prop. */
	private const FIXED_TAGS = [
		'e-image'   => 'img',
		'e-divider' => 'hr',
		'e-svg'     => 'div',
		'e-tab'     => 'button',
		'e-youtube' => 'div',
	];

	/** @var callable|null Test seam: function(string $op, mixed ...$args): mixed */
	private static $resolver = null;

	/** @var array<string,array>|null */
	private static ?array $defaults = null;

	/** @var array<string,array|null> registered props schema per elType|widgetType, for the request */
	private static array $schema_cache = [];

	/** Elementor prop-type base classes, for the shape of a schema entry. */
	private const PROP_KINDS = [
		'union'  => '\Elementor\Modules\AtomicWidgets\PropTypes\Union_Prop_Type',
		'object' => '\Elementor\Modules\AtomicWidgets\PropTypes\Base\Object_Prop_Type',
		'array'  => '\Elementor\Modules\AtomicWidgets\PropTypes\Base\Array_Prop_Type',
		'plain'  => '\Elementor\Modules\AtomicWidgets\PropTypes\Base\Plain_Prop_Type',
	];

	// ── Detection ─────────────────────────────────────────────────────────

	/**
	 * Concrete type name of a node: widgetType for widgets, elType otherwise.
	 */
	public static function type_of( array $node ): string {
		$el_type = (string) ( $node['elType'] ?? '' );
		if ( 'widget' === $el_type ) {
			return (string) ( $node['widgetType'] ?? 'widget' );
		}
		return '' === $el_type ? 'unknown' : $el_type;
	}

	/**
	 * Is this node an Elementor atomic (V4) element?
	 *
	 * Elementor decides by PHP class (Atomic_Element_Base / Atomic_Widget_Base,
	 * elementor/modules/atomic-widgets/utils/utils.php:15-18), which is not
	 * available from stored data. Every registered atomic type name starts
	 * with `e-` (modules/atomic-widgets/module.php:391-451,
	 * modules/components/widgets/component-instance.php:20), so that is the
	 * primary rule. The stored `version` key is NOT used: atomic nodes save it
	 * as whatever was loaded, default "0.0" (atomic-widget-base.php:28,
	 * has-atomic-base.php:247-250), never a literal 4.
	 *
	 * Secondary rule: any top-level setting that is a typed envelope. Catches
	 * a future atomic type without the prefix.
	 *
	 * Since 1.13.1 a type Elementor has registered with a classic class (no
	 * props schema) is classic, so the read tools' `schema` marker and peek
	 * fields agree with the write guard (has_atomic_type()).
	 */
	public static function is_atomic( array $node ): bool {
		if ( ! str_starts_with( self::type_of( $node ), 'e-' ) && ! self::has_envelope_setting( $node ) ) {
			return false;
		}
		// 1.13.1: when Elementor's registry knows the type and its class has no
		// props schema, the element is classic whatever it is called (e-addons
		// registers classic widgets named e-iframe and the like). Only `e-` and
		// envelope-shaped elements reach the registry, so classic pages never
		// start it.
		$info = self::type_info( $node );
		if ( null !== $info && ! empty( $info['registered'] ) && null === ( $info['schema'] ?? null ) ) {
			return false;
		}
		return true;
	}

	public static function schema_of( array $node ): string {
		return self::is_atomic( $node ) ? self::SCHEMA_ATOMIC : self::SCHEMA_CLASSIC;
	}

	/**
	 * Classify a whole document: classic | atomic | mixed | empty.
	 */
	public static function document_schema( array $elements ): string {
		$seen_classic = false;
		$seen_atomic  = false;
		$stack        = $elements;
		while ( $stack ) {
			$node = array_pop( $stack );
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( self::is_atomic( $node ) ) {
				$seen_atomic = true;
			} else {
				$seen_classic = true;
			}
			if ( $seen_atomic && $seen_classic ) {
				return 'mixed';
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				foreach ( $node['elements'] as $child ) {
					$stack[] = $child;
				}
			}
		}
		if ( $seen_atomic ) {
			return self::SCHEMA_ATOMIC;
		}
		if ( $seen_classic ) {
			return self::SCHEMA_CLASSIC;
		}
		return 'empty';
	}

	/**
	 * A typed envelope is an array with a non-empty string `$$type` and a
	 * `value` key (elementor/modules/atomic-widgets/props-resolver/props-resolver.php:108-113;
	 * validation in prop-types/concerns/has-transformable-validation.php:10-27).
	 */
	public static function is_envelope( mixed $value ): bool {
		return is_array( $value )
			&& isset( $value['$$type'] )
			&& is_string( $value['$$type'] )
			&& '' !== $value['$$type']
			&& array_key_exists( 'value', $value );
	}

	// ── Stored-value check and write guard (1.13.1) ───────────────────────

	/**
	 * Atomic detection for the write guard and the stored-value check.
	 *
	 *   - When Elementor knows the element type, its class decides: atomic only
	 *     if it declares a props schema. A classic addon widget whose name
	 *     starts with `e-` (e-addons' `e-iframe`) stays classic.
	 *   - When Elementor cannot say (the type is not registered: Atomic Editor
	 *     off, addon deactivated, Elementor inactive), an element is atomic only
	 *     if its type has the `e-` prefix and its settings hold a typed envelope.
	 *
	 * The registry is consulted only for `e-` types and elements whose settings
	 * hold an envelope; any other element is classic without a lookup, so reads
	 * of classic pages never start Elementor's widget registry.
	 */
	public static function has_atomic_type( array $node ): bool {
		$prefixed  = str_starts_with( self::type_of( $node ), 'e-' );
		$enveloped = self::has_envelope_setting( $node );
		if ( ! $prefixed && ! $enveloped ) {
			return false;
		}
		$info = self::type_info( $node );
		if ( null !== $info && ! empty( $info['registered'] ) ) {
			return null !== ( $info['schema'] ?? null );
		}
		return $prefixed && $enveloped;
	}

	/** Does any top-level setting of the node hold a typed envelope? */
	private static function has_envelope_setting( array $node ): bool {
		$settings = $node['settings'] ?? null;
		if ( ! is_array( $settings ) ) {
			return false;
		}
		foreach ( $settings as $value ) {
			if ( self::is_envelope( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What Elementor's registry says about the node's type:
	 * { registered: bool, schema: array|null } (schema as in schema_props()),
	 * or null when the registry cannot be asked (Elementor not loaded).
	 */
	private static function type_info( array $node ): ?array {
		$info = self::resolve( 'type_info', (string) ( $node['elType'] ?? '' ), (string) ( $node['widgetType'] ?? '' ) );
		return is_array( $info ) ? $info : null;
	}

	/**
	 * The node type's registered props schema as key → { type: $$type key,
	 * kind: plain|object|array|union|other }, or null when Elementor is not
	 * loaded, the type is not registered, or its class has no props schema
	 * (every classic widget).
	 *
	 * @return array<string,array{type:string,kind:string}>|null
	 */
	public static function schema_props( array $node ): ?array {
		$info = self::type_info( $node );
		return ( null !== $info && ! empty( $info['registered'] ) && is_array( $info['schema'] ?? null ) ) ? $info['schema'] : null;
	}

	/**
	 * Keys of an atomic node whose stored value is not a typed envelope where
	 * Elementor expects one. IATO 1.12.x and 1.13.0 could store a plain value
	 * over an envelope; Elementor then drops links and images it cannot read and
	 * refuses to save the page. With the registered schema only schema keys are
	 * checked. Without it (Elementor inactive, type not registered) every
	 * top-level setting is: Elementor 4.x stores each atomic setting as an
	 * envelope (every prop type's validate() requires one,
	 * elementor/modules/atomic-widgets/prop-types/base/*.php, and its save keeps
	 * schema keys only). Empty for classic and valid nodes.
	 *
	 * @return string[]
	 */
	public static function invalid_keys( array $node ): array {
		// Missing or null settings: Elementor reads both as no settings.
		if ( ! isset( $node['settings'] ) || ! self::has_atomic_type( $node ) ) {
			return [];
		}
		if ( ! is_array( $node['settings'] ) ) {
			return [ 'settings' ];
		}
		$schema  = self::schema_props( $node );
		$invalid = [];
		foreach ( $node['settings'] as $key => $value ) {
			$key = (string) $key;
			if ( null === $value || self::is_envelope( $value ) ) {
				continue;
			}
			if ( null !== $schema && ! array_key_exists( $key, $schema ) ) {
				continue;
			}
			$invalid[] = $key;
		}
		return $invalid;
	}

	/**
	 * What a settings patch would store on an atomic node that Elementor does
	 * not accept: values that are not typed envelopes (null, a key removal,
	 * included) and keys that are not settings of the element, when its schema
	 * is known. The 1.13.1 write guard refuses both.
	 *
	 * @return array{plain_keys:string[],unknown_keys:string[]}
	 */
	public static function patch_problems( array $node, array $patch ): array {
		$schema  = self::schema_props( $node );
		$plain   = [];
		$unknown = [];
		foreach ( $patch as $key => $value ) {
			$key = (string) $key;
			if ( null !== $schema && ! array_key_exists( $key, $schema ) ) {
				$unknown[] = $key;
			} elseif ( ! self::is_envelope( $value ) ) {
				$plain[] = $key;
			}
		}
		return [ 'plain_keys' => $plain, 'unknown_keys' => $unknown ];
	}

	/**
	 * Compare a changed tree (update_elementor_patch) with the stored one by
	 * element id. For every atomic node: `changed_ids` lists the nodes whose
	 * settings differ, `problems` the nodes that would store a value Elementor
	 * does not accept (patch_problems() over the settings that changed or are
	 * new). Unchanged stored values are not reported, so an element damaged
	 * earlier does not block other edits; removed keys are not values.
	 *
	 * @return array{changed_ids:string[],problems:array<int,array{element_id:string,type:string,plain_keys:string[],unknown_keys:string[]}>}
	 */
	public static function tree_changes( array $stored, array $new ): array {
		$by_id        = [];
		$stored_count = [];
		$new_count    = [];
		self::index_by_id( $stored, $by_id, $stored_count );
		$ignored = [];
		self::index_by_id( $new, $ignored, $new_count );
		// An id that appears more often than on the stored page (an RFC 6902
		// copy keeps the id of its source) marks every element carrying it as
		// new, so a copy of an element is checked like any new element.
		$duplicated = [];
		foreach ( $new_count as $id => $count ) {
			if ( $count > ( $stored_count[ $id ] ?? 0 ) ) {
				$duplicated[ (string) $id ] = true;
			}
		}
		$report = [ 'changed_ids' => [], 'problems' => [] ];
		self::collect_tree_changes( $new, $by_id, $report, $duplicated );
		$report['changed_ids'] = array_values( array_unique( $report['changed_ids'] ) );
		return $report;
	}

	private static function index_by_id( array $elements, array &$by_id, array &$count ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id           = isset( $element['id'] ) ? (string) $element['id'] : '';
			$count[ $id ] = ( $count[ $id ] ?? 0 ) + 1;
			if ( isset( $element['id'] ) ) {
				$by_id[ $id ] = $element;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::index_by_id( $element['elements'], $by_id, $count );
			}
		}
	}

	private static function collect_tree_changes( array $elements, array $stored_by_id, array &$report, array $duplicated = [] ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id         = isset( $element['id'] ) ? (string) $element['id'] : '';
			$stored     = $stored_by_id[ $id ] ?? null;
			$same_type  = is_array( $stored )
				&& ( $stored['elType'] ?? '' ) === ( $element['elType'] ?? '' )
				&& self::type_of( $stored ) === self::type_of( $element );
			// Atomic when the element as changed is, or when the stored element of
			// the same id and type is: replacing every setting of an atomic element
			// with plain values leaves no envelope to recognise it by when
			// Elementor cannot say.
			if ( self::has_atomic_type( $element ) || ( $same_type && self::has_atomic_type( $stored ) ) ) {
				// An element whose id the change duplicates (an RFC 6902 copy keeps
				// the id of its source) is checked like the read flag checks a
				// stored element: values stored without their typed wrapper are
				// refused, so a damaged element cannot be copied. Null values and
				// keys outside the schema, which Elementor ignores, pass. Every
				// node with the id is checked, so a damaged source must be
				// repaired before it is copied.
				if ( isset( $duplicated[ $id ] ) ) {
					$report['changed_ids'][] = $id;
					$plain = self::plain_value_keys( $element );
					if ( ! empty( $plain ) ) {
						$report['problems'][] = [ 'element_id' => $id, 'type' => self::type_of( $element ), 'plain_keys' => $plain, 'unknown_keys' => [] ];
					}
					if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
						self::collect_tree_changes( $element['elements'], $stored_by_id, $report, $duplicated );
					}
					continue;
				}
				// An element whose type the change sets or changes (a classic
				// heading turned into e-heading by its widgetType) is new: every
				// setting it carries is checked, not only the ones that differ.
				$same_kind = $same_type;
				$before = $same_kind ? ( $stored['settings'] ?? [] ) : [];
				$before = is_array( $before ) ? $before : [];
				$settings = $element['settings'] ?? [];
				if ( ! is_array( $settings ) ) {
					$report['changed_ids'][] = $id;
					$report['problems'][]    = [ 'element_id' => $id, 'type' => self::type_of( $element ), 'plain_keys' => [ 'settings' ], 'unknown_keys' => [] ];
				} else {
					$changed = [];
					foreach ( $settings as $key => $value ) {
						$key = (string) $key;
						if ( ! array_key_exists( $key, $before ) || $before[ $key ] !== $value ) {
							$changed[ $key ] = $value;
						}
					}
					if ( ! empty( $changed ) || array_diff_key( $before, $settings ) || ! $same_kind ) {
						$report['changed_ids'][] = $id;
					}
					if ( ! empty( $changed ) ) {
						$problems = self::patch_problems( $element, $changed );
						if ( $problems['plain_keys'] || $problems['unknown_keys'] ) {
							$report['problems'][] = [ 'element_id' => $id, 'type' => self::type_of( $element ) ] + $problems;
						}
					}
				}
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_tree_changes( $element['elements'], $stored_by_id, $report, $duplicated );
			}
		}
	}

	/**
	 * Keys of a node (treated as atomic by the caller) whose value is stored
	 * without its typed wrapper: invalid_keys(), or, when detection cannot
	 * recognise the node any more (every setting plain while Elementor cannot
	 * say), every non-null top-level value that is not an envelope.
	 *
	 * @return string[]
	 */
	private static function plain_value_keys( array $node ): array {
		$plain = self::invalid_keys( $node );
		if ( ! empty( $plain ) || self::has_atomic_type( $node ) ) {
			return $plain;
		}
		if ( isset( $node['settings'] ) && ! is_array( $node['settings'] ) ) {
			return [ 'settings' ];
		}
		foreach ( (array) ( $node['settings'] ?? [] ) as $key => $value ) {
			if ( null !== $value && ! self::is_envelope( $value ) ) {
				$plain[] = (string) $key;
			}
		}
		return $plain;
	}

	/**
	 * A typed value to show in a refusal, so the caller can resend the change in
	 * the shape Elementor stores. Built from the value stored on the element (its
	 * $$type, with the scalar the caller sent), or from the registered schema for
	 * a plain prop type. For object props the stored envelope itself is the
	 * template. Null when no shape is known. Only ever shown in error messages,
	 * never written.
	 */
	public static function envelope_example( array $node, string $key, mixed $sent ): ?array {
		$stored = is_array( $node['settings'] ?? null ) ? ( $node['settings'][ $key ] ?? null ) : null;
		if ( self::is_envelope( $stored ) ) {
			if ( is_scalar( $sent ) && ( null === $stored['value'] || is_scalar( $stored['value'] ) ) ) {
				return [ '$$type' => $stored['$$type'], 'value' => $sent ];
			}
			return $stored;
		}
		$schema = self::schema_props( $node );
		if ( null !== $schema && isset( $schema[ $key ] ) && 'plain' === $schema[ $key ]['kind'] && '' !== $schema[ $key ]['type'] && is_scalar( $sent ) ) {
			return [ '$$type' => $schema[ $key ]['type'], 'value' => $sent ];
		}
		return null;
	}

	// ── Unwrapping ────────────────────────────────────────────────────────

	/**
	 * Convert a typed envelope (or any nested structure of them) to plain values.
	 *
	 * Rules mirror Elementor's render resolver
	 * (elementor/modules/atomic-widgets/props-resolver/render-props-resolver.php:90-104,
	 * props-resolver.php:61-88):
	 *   - `disabled: true`            → null
	 *   - objects                     → each key unwrapped
	 *   - lists                       → each item unwrapped, nulls dropped
	 *   - unknown `$$type`            → payload unwrapped recursively (never an error)
	 *   - `dynamic`                   → { "$dynamic": { name, group, settings } }
	 */
	public static function unwrap( mixed $value, int $depth = 0 ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $depth > self::MAX_DEPTH ) {
			return null;
		}

		if ( ! self::is_envelope( $value ) ) {
			// Plain array: list or object of (possibly) envelopes.
			$is_list = self::is_list( $value );
			$out     = [];
			foreach ( $value as $k => $v ) {
				$plain = self::unwrap( $v, $depth + 1 );
				if ( $is_list ) {
					if ( null !== $plain ) {
						$out[] = $plain;
					}
				} else {
					$out[ $k ] = $plain;
				}
			}
			return $out;
		}

		if ( ! empty( $value['disabled'] ) ) {
			return null;
		}

		$type    = $value['$$type'];
		$payload = $value['value'];

		if ( in_array( $type, self::TEXT_TYPES, true ) ) {
			return self::unwrap_text( $payload, $depth );
		}

		switch ( $type ) {
			case 'url':
				return is_scalar( $payload ) ? (string) $payload : null;

			case 'boolean':
				return null === $payload ? null : (bool) $payload;

			case 'number':
				if ( is_int( $payload ) || is_float( $payload ) ) {
					return $payload;
				}
				return is_numeric( $payload ) ? $payload + 0 : null;

			case 'image-attachment-id':
				return is_numeric( $payload ) ? (int) $payload : null;

			case 'query':
				// { id: number env, label: string env } — a post reference
				// (elementor/modules/atomic-widgets/prop-types/query-prop-type.php:18-23).
				$plain = is_array( $payload ) ? self::unwrap( $payload, $depth + 1 ) : [];
				return [
					'post_id' => isset( $plain['id'] ) && is_numeric( $plain['id'] ) ? (int) $plain['id'] : null,
					'label'   => isset( $plain['label'] ) && is_scalar( $plain['label'] ) ? (string) $plain['label'] : null,
				];

			case 'classes':
				// string[] of style ids (prop-types/classes-prop-type.php:13).
				return is_array( $payload ) ? array_values( array_filter( $payload, 'is_string' ) ) : [];

			case 'attributes':
				// list of key-value envelopes → { key: value } map
				// (prop-types/attributes-prop-type.php:13, key-value-prop-type.php:14).
				$map = [];
				if ( is_array( $payload ) ) {
					foreach ( $payload as $item ) {
						$pair = self::unwrap( $item, $depth + 1 );
						if ( is_array( $pair ) && isset( $pair['key'] ) && is_scalar( $pair['key'] ) && '' !== (string) $pair['key'] ) {
							$map[ (string) $pair['key'] ] = isset( $pair['value'] ) && is_scalar( $pair['value'] ) ? (string) $pair['value'] : null;
						}
					}
				}
				return $map;

			case 'dynamic':
				// { name, group, settings } (prop-types/dynamic-tags/dynamic-prop-type.php:25).
				$plain = is_array( $payload ) ? $payload : [];
				return [
					'$dynamic' => [
						'name'     => isset( $plain['name'] ) ? (string) $plain['name'] : null,
						'group'    => isset( $plain['group'] ) ? (string) $plain['group'] : null,
						'settings' => self::unwrap( $plain['settings'] ?? [], $depth + 1 ),
					],
				];

			default:
				// size → {size, unit}; image → {src, size}; image-src → {id, url, alt};
				// link → {destination, isTargetBlank, tag}; background, icon, svg-src,
				// video-src, component-instance, global-*-variable, and anything
				// added later: unwrap the payload structurally.
				return self::unwrap( $payload, $depth + 1 );
		}
	}

	/**
	 * Flatten the five text envelope payload shapes to a string (or null).
	 */
	private static function unwrap_text( mixed $payload, int $depth ): ?string {
		if ( null === $payload ) {
			return null;
		}
		if ( is_scalar( $payload ) ) {
			return (string) $payload;
		}
		if ( is_array( $payload ) && array_key_exists( 'content', $payload ) ) {
			// html-v2: content is a string; html-v3: content is a string envelope
			// (elementor/migrations/operations/html-v3-to-escaped-html.json).
			$content = self::unwrap( $payload['content'], $depth + 1 );
			return null === $content ? null : ( is_scalar( $content ) ? (string) $content : null );
		}
		return null;
	}

	// ── Settings ──────────────────────────────────────────────────────────

	/**
	 * Unwrap every setting of an atomic node and fill unsaved schema defaults.
	 *
	 * @return array{settings:array,defaulted_keys:string[]}
	 */
	public static function plain_settings( array $node ): array {
		$raw      = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
		$invalid  = self::invalid_keys( $node );
		$settings = [];
		foreach ( $raw as $key => $value ) {
			$key = (string) $key;
			// A value stored without its envelope is shown as stored, not
			// interpreted as if Elementor could read it (1.13.1).
			$settings[ $key ] = in_array( $key, $invalid, true ) ? $value : self::unwrap( $value );
		}

		$defaulted = [];
		$defaults  = self::defaults_table()[ self::type_of( $node ) ] ?? [];
		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $settings ) || null === $settings[ $key ] ) {
				$settings[ $key ] = $default;
				$defaulted[]      = $key;
			}
		}

		return [
			'settings'       => $settings,
			'defaulted_keys' => $defaulted,
		];
	}

	/**
	 * Settings view used by find_elementor_widgets filters: plain settings plus
	 * the derived scalar keys that normalize() emits (header_size, editor,
	 * link_url, image_url, image_id, image_alt), so `setting.title.contains`
	 * and `setting.header_size.eq` work the same for atomic and classic nodes.
	 */
	public static function match_settings( array $node ): array {
		$plain = self::plain_settings( $node )['settings'];
		$type  = self::type_of( $node );

		// Nothing is derived from a value stored without its envelope (1.13.1).
		$source = array_diff_key( $plain, array_flip( self::invalid_keys( $node ) ) );

		if ( 'e-heading' === $type && isset( $source['tag'] ) ) {
			$plain['header_size'] = $source['tag'];
		}
		if ( 'e-paragraph' === $type && isset( $source['paragraph'] ) ) {
			$plain['editor'] = $source['paragraph'];
		}

		$link = self::link_view( $source['link'] ?? null );
		if ( $link ) {
			$plain['link_url'] = $link['url'];
		}

		if ( 'e-image' === $type ) {
			$image = self::image_view( $source['image'] ?? null );
			if ( $image ) {
				$plain['image_url'] = $image['url'];
				$plain['image_id']  = $image['id'];
				$plain['image_alt'] = $image['alt'];
			}
		}

		return $plain;
	}

	// ── Normalised view ───────────────────────────────────────────────────

	/**
	 * Normalise an atomic node into the shape the read tools emit for every
	 * element: schema marker, rendered tag, and peek fields that reuse the
	 * classic key names.
	 *
	 * Keys (all optional except schema):
	 *   schema            'atomic'
	 *   tag               rendered HTML tag (h1..h6, p, img, a, button, div, ...)
	 *   title             e-heading text               (classic: heading.title)
	 *   header_size       e-heading level              (classic: heading.header_size)
	 *   editor            e-paragraph HTML             (classic: text-editor.editor)
	 *   text              e-button text                (classic: button.text)
	 *   link              link URL string              (classic peek key, string here)
	 *   link_new_tab      bool
	 *   link_post_id      int, for post-reference (query) links
	 *   image_url / image_id / image_alt / image_alt_source ('attachment'|'element')
	 *   <field>_dynamic   dynamic-tag name when a text field is bound to a dynamic tag
	 *   stored_value_invalid / invalid_keys   (1.13.1) settings stored without
	 *                     their envelope (invalid_keys()); no peek field is
	 *                     derived from them, since Elementor cannot read them
	 *                     either (it renders such a link as a plain button and
	 *                     such an image not at all)
	 */
	public static function normalize( array $node ): array {
		$type    = self::type_of( $node );
		$invalid = self::invalid_keys( $node );
		$plain   = array_diff_key( self::plain_settings( $node )['settings'], array_flip( $invalid ) );

		$out = [ 'schema' => self::SCHEMA_ATOMIC ];

		$tag = in_array( 'tag', $invalid, true ) ? null : self::compute_tag( $type, $plain );
		if ( null !== $tag ) {
			$out['tag'] = $tag;
		}

		switch ( $type ) {
			case 'e-heading':
				self::put_text( $out, 'title', $plain['title'] ?? null );
				if ( isset( $plain['tag'] ) && is_string( $plain['tag'] ) ) {
					$out['header_size'] = $plain['tag'];
				}
				break;

			case 'e-paragraph':
				self::put_text( $out, 'editor', $plain['paragraph'] ?? null );
				break;

			case 'e-button':
				self::put_text( $out, 'text', $plain['text'] ?? null );
				break;

			case 'e-image':
				$image = self::image_view( $plain['image'] ?? null );
				if ( $image ) {
					$out['image_url']        = $image['url'];
					$out['image_id']         = $image['id'];
					$out['image_alt']        = $image['alt'];
					$out['image_alt_source'] = $image['alt_source'];
				}
				break;

			default:
				// Unknown / future atomic types: surface the conventional text
				// keys if they happen to exist, so a new widget that reuses
				// `title` / `text` / `paragraph` still peeks sensibly.
				self::put_text( $out, 'title', $plain['title'] ?? null );
				self::put_text( $out, 'text', $plain['text'] ?? null );
				self::put_text( $out, 'editor', $plain['paragraph'] ?? null );
				if ( isset( $plain['tag'] ) && is_string( $plain['tag'] ) && preg_match( '/^h[1-6]$/', $plain['tag'] ) ) {
					$out['header_size'] = $plain['tag'];
				}
				break;
		}

		$link = self::link_view( $plain['link'] ?? null );
		if ( $link ) {
			$out['link']         = $link['url'];
			$out['link_new_tab'] = $link['new_tab'];
			if ( null !== $link['post_id'] ) {
				$out['link_post_id'] = $link['post_id'];
			}
		}

		if ( ! empty( $invalid ) ) {
			$out['stored_value_invalid'] = true;
			$out['invalid_keys']         = $invalid;
		}

		return $out;
	}

	/**
	 * Rendered HTML tag, per Elementor's Html_Tag_Computer::compute()
	 * (elementor/modules/atomic-widgets/elements/base/html-tag-computer.php:15-29):
	 *   1. if the element follows its link and a link is active → link.tag (default 'a')
	 *   2. else the `tag` setting
	 *   3. else the element default
	 */
	public static function compute_tag( string $type, array $plain ): ?string {
		if ( isset( self::FIXED_TAGS[ $type ] ) ) {
			return self::FIXED_TAGS[ $type ];
		}

		if ( ! in_array( $type, self::NO_FOLLOW_LINK, true ) ) {
			$link = self::link_view( $plain['link'] ?? null );
			if ( $link && '' !== (string) $link['url'] ) {
				return 'button' === $link['tag'] ? 'button' : 'a';
			}
		}

		$tag = $plain['tag'] ?? null;
		if ( is_string( $tag ) && '' !== $tag ) {
			return $tag;
		}

		// Element default for types we know; null for unknown types.
		return self::defaults_table()[ $type ]['tag'] ?? null;
	}

	/**
	 * Plain link object → { url, new_tab, tag, post_id, label } or null.
	 *
	 * destination is a url string or a post reference { post_id, label }
	 * (elementor/modules/atomic-widgets/prop-types/link-prop-type.php:38-48);
	 * post references resolve to a permalink (props-resolver/transformers/
	 * settings/link-transformer.php:25-30).
	 */
	public static function link_view( mixed $link ): ?array {
		if ( ! is_array( $link ) || ! array_key_exists( 'destination', $link ) ) {
			return null;
		}
		$dest    = $link['destination'];
		$post_id = null;
		$label   = null;
		$url     = null;

		if ( is_array( $dest ) && array_key_exists( 'post_id', $dest ) ) {
			$post_id = $dest['post_id'];
			$label   = $dest['label'] ?? null;
			$url     = $post_id ? self::resolve( 'permalink', (int) $post_id ) : null;
		} elseif ( is_scalar( $dest ) ) {
			$url = (string) $dest;
		}

		if ( null === $url && null === $post_id ) {
			return null;
		}

		return [
			'url'     => $url,
			'new_tab' => ! empty( $link['isTargetBlank'] ),
			'tag'     => isset( $link['tag'] ) && is_string( $link['tag'] ) ? $link['tag'] : 'a',
			'post_id' => $post_id,
			'label'   => $label,
		];
	}

	/**
	 * Plain image object → { url, id, alt, alt_source, size } or null.
	 *
	 * Media Library images store only `src.id`; alt comes from the attachment
	 * (`_wp_attachment_image_alt`) and the URL from the attachment at the stored
	 * size. External images store `src.url` and `src.alt`
	 * (elementor/modules/atomic-widgets/prop-types/image-src-prop-type.php:17-24,
	 * props-resolver/transformers/image-transformer.php:25-46).
	 */
	public static function image_view( mixed $image ): ?array {
		if ( ! is_array( $image ) ) {
			return null;
		}
		$src  = is_array( $image['src'] ?? null ) ? $image['src'] : [];
		$size = isset( $image['size'] ) && is_string( $image['size'] ) && '' !== $image['size'] ? $image['size'] : 'full';
		$id   = isset( $src['id'] ) && is_numeric( $src['id'] ) ? (int) $src['id'] : null;
		$url  = isset( $src['url'] ) && is_scalar( $src['url'] ) ? (string) $src['url'] : null;

		if ( $id ) {
			$alt = self::resolve( 'attachment_alt', $id );
			if ( null === $url || '' === $url ) {
				$url = self::resolve( 'attachment_url', $id, $size );
			}
			return [
				'url'        => $url,
				'id'         => $id,
				'alt'        => is_string( $alt ) ? $alt : '',
				'alt_source' => 'attachment',
				'size'       => $size,
			];
		}

		if ( null === $url ) {
			return null;
		}

		return [
			'url'        => $url,
			'id'         => null,
			'alt'        => isset( $src['alt'] ) && is_scalar( $src['alt'] ) ? (string) $src['alt'] : '',
			'alt_source' => 'element',
			'size'       => $size,
		];
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/** PHP 8.0-compatible array_is_list(). */
	private static function is_list( array $arr ): bool {
		$i = 0;
		foreach ( $arr as $k => $_ ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}

	private static function put_text( array &$out, string $key, mixed $value ): void {
		if ( is_array( $value ) && isset( $value['$dynamic'] ) ) {
			$out[ $key . '_dynamic' ] = $value['$dynamic']['name'] ?? null;
			return;
		}
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return;
		}
		$value       = (string) $value;
		$out[ $key ] = strlen( $value ) > self::PEEK_LEN ? substr( $value, 0, self::PEEK_LEN ) . '…' : $value;
	}

	public static function defaults_table(): array {
		if ( null === self::$defaults ) {
			$path = ( defined( 'IATO_MCP_DIR' ) ? IATO_MCP_DIR : dirname( __DIR__ ) . '/' ) . 'includes/data/elementor-atomic-defaults.php';
			$data = file_exists( $path ) ? require $path : [];
			self::$defaults = is_array( $data ) ? $data : [];
		}
		return self::$defaults;
	}

	/**
	 * Inject a resolver for tests. Signature: function(string $op, mixed ...$args): mixed
	 * Ops: attachment_alt(int $id), attachment_url(int $id, string $size), permalink(int $post_id),
	 * type_info(string $el_type, string $widget_type) ({registered: bool, schema: array|null} or null when
	 * Elementor cannot be asked; see has_atomic_type() and schema_props()).
	 * Pass null to restore the WordPress-backed default.
	 */
	public static function set_resolver( ?callable $resolver ): void {
		self::$resolver     = $resolver;
		self::$schema_cache = [];
	}

	/**
	 * What Elementor's registry says about an element type: whether it is
	 * registered right now and, if so, its props schema (Elementor 4.0+:
	 * Has_Atomic_Base::get_props_schema(),
	 * elementor/modules/atomic-widgets/elements/base/has-atomic-base.php:403;
	 * null for classic classes). Null when the registry cannot be asked
	 * (Elementor missing) or throws while it loads; detection then falls back
	 * to the stored data. Cached per type for the request.
	 */
	private static function registered_type_info( string $el_type, string $widget_type ): ?array {
		$cache_key = $el_type . '|' . $widget_type;
		if ( array_key_exists( $cache_key, self::$schema_cache ) ) {
			return self::$schema_cache[ $cache_key ];
		}
		self::$schema_cache[ $cache_key ] = null;
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return null;
		}
		try {
			$plugin   = \Elementor\Plugin::$instance;
			$instance = null;
			$asked    = false;
			if ( 'widget' === $el_type ) {
				$manager = isset( $plugin->widgets_manager ) ? $plugin->widgets_manager : null;
				if ( is_object( $manager ) && method_exists( $manager, 'get_widget_types' ) ) {
					$asked    = true;
					$instance = '' !== $widget_type ? $manager->get_widget_types( $widget_type ) : null;
				}
			} else {
				$manager = isset( $plugin->elements_manager ) ? $plugin->elements_manager : null;
				if ( is_object( $manager ) && method_exists( $manager, 'get_element_types' ) ) {
					$asked    = true;
					$instance = '' !== $el_type ? $manager->get_element_types( $el_type ) : null;
				}
			}
			if ( ! $asked ) {
				return null; // the registry cannot be asked: Elementor cannot say
			}
			if ( ! is_object( $instance ) ) {
				return self::$schema_cache[ $cache_key ] = [ 'registered' => false, 'schema' => null ];
			}
			$schema = method_exists( $instance, 'get_props_schema' ) ? $instance::get_props_schema() : null;
			$props  = null;
			if ( is_array( $schema ) ) {
				$props = [];
				foreach ( $schema as $key => $prop_type ) {
					$kind = 'other';
					foreach ( self::PROP_KINDS as $name => $class ) {
						if ( class_exists( $class ) && $prop_type instanceof $class ) {
							$kind = $name;
							break;
						}
					}
					$props[ (string) $key ] = [
						'type' => is_object( $prop_type ) && method_exists( $prop_type, 'get_key' ) ? (string) $prop_type::get_key() : '',
						'kind' => $kind,
					];
				}
			}
			self::$schema_cache[ $cache_key ] = [ 'registered' => true, 'schema' => $props ];
		} catch ( \Throwable $e ) {
			self::$schema_cache[ $cache_key ] = null; // Elementor cannot say
		}
		return self::$schema_cache[ $cache_key ];
	}

	private static function resolve( string $op, mixed ...$args ): mixed {
		if ( null !== self::$resolver ) {
			return ( self::$resolver )( $op, ...$args );
		}
		switch ( $op ) {
			case 'attachment_alt':
				if ( function_exists( 'get_post_meta' ) ) {
					$alt = get_post_meta( (int) $args[0], '_wp_attachment_image_alt', true );
					return is_string( $alt ) ? $alt : '';
				}
				return '';
			case 'attachment_url':
				if ( function_exists( 'wp_get_attachment_image_url' ) ) {
					$url = wp_get_attachment_image_url( (int) $args[0], (string) ( $args[1] ?? 'full' ) );
					return is_string( $url ) ? $url : null;
				}
				return null;
			case 'permalink':
				if ( function_exists( 'get_permalink' ) ) {
					$url = get_permalink( (int) $args[0] );
					return is_string( $url ) ? $url : null;
				}
				return null;
			case 'type_info':
				return self::registered_type_info( (string) $args[0], (string) $args[1] );
		}
		return null;
	}
}
