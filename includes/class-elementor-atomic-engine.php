<?php
/**
 * Elementor's own code for atomic (Editor V4) settings, behind one guarded
 * object (1.14.0).
 *
 * IATO_MCP_Elementor_Atomic_Writer never builds a `$$type` envelope itself:
 * plain values are converted by Elementor's settings plain-values resolver
 * (Elementor 4.3.0+), validated and sanitised by Elementor's Props_Parser, and
 * each element is checked with its own save transform. Every call is wrapped,
 * so a Throwable from Elementor or an addon becomes a WP_Error or a null
 * answer, never a fatal. Unit tests replace this object with a fake through
 * IATO_MCP_Elementor_Atomic_Writer::set_engine().
 *
 * Dependencies (all public PHP API; the atomic ones are Elementor internals):
 *   \Elementor\Plugin::$instance->widgets_manager / elements_manager           1.0
 *   <atomic class>::get_props_schema()                                        4.0.0
 *   Plugin::$instance->modules_manager->get_modules( 'atomic-widgets' )
 *     ->get_settings_plain_values_resolver()                                  4.3.0
 *   PlainResolvers\Plain_Values_Resolver::resolve()                           4.3.0
 *   PropTypes\Base\Object_Prop_Type::get_shape(), Prop_Type::get_aliases()    4.0.0 / 4.3.0
 *   Parsers\Props_Parser::make()->parse(), Parse_Result                       4.0.0
 *   elements_manager->create_element_instance()->get_data_for_save()          1.0 / 4.0.0
 *   Document::get_elements_raw_data() (runs the load/data prop migrations,
 *     captured in memory)                                                     2.0 / 3.35.0
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Elementor_Atomic_Engine {

	// No leading backslash: these names go to is_a() and class_exists().
	private const PROPS_PARSER = 'Elementor\Modules\AtomicWidgets\Parsers\Props_Parser';
	private const OBJECT_PROP  = 'Elementor\Modules\AtomicWidgets\PropTypes\Base\Object_Prop_Type';

	private $resolver = null;

	/** Message of a Throwable raised while Elementor built its resolver (an addon hooked into it). */
	private ?string $resolver_error = null;

	/** @var array<string,?array> props schema per element type, for this request */
	private array $schemas = [];

	/**
	 * What is missing for atomic writes on this site, in words a site owner
	 * can act on; null when everything is there.
	 */
	public function missing(): ?string {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return 'Elementor is not active on this site.';
		}
		$version = defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : 'unknown';
		if ( null === $this->resolver() ) {
			if ( null !== $this->resolver_error ) {
				return "Elementor's settings resolver for atomic elements failed to load ({$this->resolver_error}).";
			}
			// The atomic-widgets module is only loaded while its experiment is on.
			if ( 'unknown' !== $version && version_compare( $version, '4.3.0', '>=' ) ) {
				return "Elementor's Atomic Editor is off on this site (Elementor > Settings > Features), so Elementor's code for atomic elements is not loaded.";
			}
			return "Editing atomic elements with plain values needs Elementor 4.3.0 or later; this site runs Elementor {$version}.";
		}
		if ( ! class_exists( self::PROPS_PARSER ) ) {
			return "Elementor {$version} does not provide the atomic settings parser this needs.";
		}
		return null;
	}

	/** Elementor's settings plain-values resolver, or null. */
	private function resolver() {
		if ( null !== $this->resolver ) {
			return $this->resolver;
		}
		try {
			$plugin  = \Elementor\Plugin::$instance;
			$modules = isset( $plugin->modules_manager ) ? $plugin->modules_manager : null;
			$module  = is_object( $modules ) && method_exists( $modules, 'get_modules' ) ? $modules->get_modules( 'atomic-widgets' ) : null;
			if ( is_object( $module ) && method_exists( $module, 'get_settings_plain_values_resolver' ) ) {
				$this->resolver = $module->get_settings_plain_values_resolver();
			}
		} catch ( \Throwable $e ) {
			$this->resolver       = null;
			$this->resolver_error = get_class( $e ) . ': ' . $e->getMessage();
		}
		return $this->resolver;
	}

	/** The element's registered props schema (key → Prop_Type), or null. */
	public function schema( array $node ): ?array {
		$type = (string) ( $node['elType'] ?? '' );
		$name = 'widget' === $type ? (string) ( $node['widgetType'] ?? '' ) : $type;
		if ( array_key_exists( $type . '|' . $name, $this->schemas ) ) {
			return $this->schemas[ $type . '|' . $name ];
		}
		$schema = null;
		try {
			$plugin   = \Elementor\Plugin::$instance;
			$instance = 'widget' === $type
				? $plugin->widgets_manager->get_widget_types( $name )
				: $plugin->elements_manager->get_element_types( $name );
			if ( is_object( $instance ) && method_exists( $instance, 'get_props_schema' ) ) {
				$props  = $instance::get_props_schema();
				$schema = is_array( $props ) ? $props : null;
			}
		} catch ( \Throwable $e ) {
			$schema = null;
		}
		$this->schemas[ $type . '|' . $name ] = $schema;
		return $schema;
	}

	/** Setting keys of the element's schema, or null. */
	public function keys( array $node ): ?array {
		$schema = $this->schema( $node );
		return null === $schema ? null : array_map( 'strval', array_keys( $schema ) );
	}

	/**
	 * The prop type at a settings path (key, then object shape keys), or
	 * null. Elementor 4.3 wraps most props in a union (the prop, dynamic
	 * tags, component overrides: e-heading `link` is link|overridable, an
	 * image's `src` is a union too); a path steps into a union's object
	 * variant. The prop at the end of the path is returned as declared, so
	 * the resolver sees the union.
	 */
	private function prop_at( array $node, array $path ) {
		$schema = $this->schema( $node );
		$prop   = $schema[ (string) array_shift( $path ) ] ?? null;
		foreach ( $path as $segment ) {
			$object = $this->object_variant( $prop );
			if ( null === $object ) {
				return null;
			}
			try {
				$shape = $object->get_shape();
			} catch ( \Throwable $e ) {
				return null;
			}
			$prop = is_array( $shape ) ? ( $shape[ (string) $segment ] ?? null ) : null;
		}
		return is_object( $prop ) ? $prop : null;
	}

	/**
	 * The object prop a prop stands for: itself, or the one object variant of
	 * a union (the variant whose key is $prefer when several are). Null when
	 * there is none.
	 */
	private function object_variant( $prop, ?string $prefer = null ) {
		if ( ! is_object( $prop ) ) {
			return null;
		}
		if ( is_a( $prop, self::OBJECT_PROP ) ) {
			return $prop;
		}
		if ( ! method_exists( $prop, 'get_prop_types' ) ) {
			return null;
		}
		try {
			$objects = array_values( array_filter( (array) $prop->get_prop_types(), fn( $v ) => is_object( $v ) && is_a( $v, self::OBJECT_PROP ) ) );
			if ( null !== $prefer ) {
				foreach ( $objects as $variant ) {
					if ( $variant::get_key() === $prefer ) {
						return $variant;
					}
				}
			}
			return 1 === count( $objects ) ? $objects[0] : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The `$$type` key of the object prop at a settings path ('link',
	 * 'image', 'image-src', …, through a union), or null when the prop there
	 * is not an object prop. $prefer: the stored value's `$$type`.
	 */
	public function object_key_at( array $node, array $path, ?string $prefer = null ): ?string {
		$object = $this->object_variant( $this->prop_at( $node, $path ), $prefer );
		if ( null === $object ) {
			return null;
		}
		try {
			return (string) $object::get_key();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Field names of the object prop at a settings path, or null. */
	public function shape_keys( array $node, array $path, ?string $prefer = null ): ?array {
		$object = $this->object_variant( $this->prop_at( $node, $path ), $prefer );
		if ( null === $object ) {
			return null;
		}
		try {
			$shape = $object->get_shape();
			return is_array( $shape ) ? array_map( 'strval', array_keys( $shape ) ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The element's setting aliases, alias → setting key (Elementor 4.3:
	 * `title` on e-heading also answers to text, content and heading; Elementor's
	 * own manage-elements resolves them the same way, Prop_Canonicalizer).
	 */
	public function aliases( array $node ): array {
		$schema = $this->schema( $node );
		$map    = [];
		foreach ( (array) $schema as $key => $prop ) {
			try {
				$aliases = is_object( $prop ) && method_exists( $prop, 'get_aliases' ) ? $prop->get_aliases() : [];
			} catch ( \Throwable $e ) {
				$aliases = [];
			}
			foreach ( (array) $aliases as $alias ) {
				if ( is_string( $alias ) && '' !== $alias && ! isset( $schema[ $alias ] ) ) {
					$map[ $alias ] = (string) $key;
				}
			}
		}
		return $map;
	}

	/**
	 * Elementor's typed value for a plain value at a settings path, or null
	 * when Elementor cannot convert it.
	 */
	public function resolve_at( array $node, array $path, mixed $plain ): mixed {
		$prop     = $this->prop_at( $node, $path );
		$resolver = $this->resolver();
		if ( null === $prop || null === $resolver ) {
			return null;
		}
		try {
			return $resolver->resolve( $plain, $prop );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Elementor's Props_Parser over an element's settings:
	 * { valid, errors, error_keys, settings } with the sanitised envelopes
	 * when valid.
	 */
	public function parse( array $node, array $settings ): array {
		$schema = $this->schema( $node );
		if ( null === $schema ) {
			return [ 'valid' => false, 'errors' => 'the element has no registered schema', 'error_keys' => [], 'settings' => [] ];
		}
		try {
			$parser = self::PROPS_PARSER;
			$result = $parser::make( $schema )->parse( $settings );
			$valid  = (bool) $result->is_valid();
			$keys   = [];
			if ( ! $valid && method_exists( $result->errors(), 'all' ) ) {
				foreach ( (array) $result->errors()->all() as $error ) {
					if ( isset( $error['key'] ) ) {
						$keys[] = (string) $error['key'];
					}
				}
			}
			return [
				'valid'      => $valid,
				'errors'     => $valid ? '' : (string) $result->errors()->to_string(),
				'error_keys' => array_values( array_unique( $keys ) ),
				'settings'   => (array) $result->unwrap(),
			];
		} catch ( \Throwable $e ) {
			return [ 'valid' => false, 'errors' => $e->getMessage(), 'error_keys' => [], 'settings' => [] ];
		}
	}

	/**
	 * Elementor's per-element save transform (what Document::save() runs for
	 * each element), children left out: { ok: true, data } or { ok: false, error }.
	 */
	public function save_transform( array $node ): array {
		$node['elements'] = [];
		try {
			$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance( $node );
			if ( ! is_object( $element ) || ! method_exists( $element, 'get_data_for_save' ) ) {
				return [ 'ok' => true, 'data' => null ];
			}
			return [ 'ok' => true, 'data' => $element->get_data_for_save() ];
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'error' => $e->getMessage() ];
		}
	}

	/**
	 * Elementor's load-path prop migrations for a post (they only run on
	 * load, never on save), captured in memory: every post-meta write for the
	 * post is short-circuited while Elementor loads it, and the migrated tree
	 * Elementor would have written back is returned. The writer stores it
	 * together with the change in one write, so a refused call writes nothing
	 * (1.14.0 review F2). Elementor marks the page as migrated on its next
	 * load, when the stored tree no longer needs a migration.
	 *
	 * @return array{migrated:bool,tree:?string,error:?string}
	 */
	public function migrate( int $post_id ): array {
		$out = [ 'migrated' => false, 'tree' => null, 'error' => null ];
		try {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
			if ( ! is_object( $document ) || ! method_exists( $document, 'get_elements_raw_data' ) ) {
				return $out;
			}
			$captured = null;
			$block    = function ( $check, $object_id, $meta_key, $meta_value = null ) use ( $post_id, &$captured ) {
				if ( (int) $object_id !== $post_id ) {
					return $check;
				}
				// update_metadata() has unslashed the value before this filter.
				if ( '_elementor_data' === $meta_key && is_string( $meta_value ) ) {
					$captured = $meta_value;
				}
				return true; // nothing is written here
			};
			add_filter( 'update_post_metadata', $block, 1, 4 );
			add_filter( 'add_post_metadata', $block, 1, 4 );
			add_filter( 'delete_post_metadata', $block, 1, 4 );
			try {
				// Same meta guard as a write-back, so the captured tree is what
				// Elementor would store.
				IATO_MCP_Elementor_Adapter::write_elementor_meta( fn() => $document->get_elements_raw_data() );
			} finally {
				remove_filter( 'update_post_metadata', $block, 1 );
				remove_filter( 'add_post_metadata', $block, 1 );
				remove_filter( 'delete_post_metadata', $block, 1 );
			}
			if ( null !== $captured ) {
				$out['migrated'] = true;
				$out['tree']     = $captured;
			}
		} catch ( \Throwable $e ) {
			$out['error'] = $e->getMessage();
		}
		return $out;
	}

	public function version(): string {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
	}
}
