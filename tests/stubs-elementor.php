<?php
declare( strict_types=1 );

/**
 * Fake Elementor (Document::save(), the documents/files managers, the
 * frontend renderer) and the WordPress calls the Elementor write pipeline
 * makes. Shared by ElementorWritePipelineTest and ElementorWriterAtomicTest
 * (moved here from ElementorWritePipelineTest in 1.14.0).
 */

// ── Fake Elementor ─────────────────────────────────────────────────────────
namespace Elementor {
	final class FakeDocument {
		/** @var 'ok'|'refuse'|'throw' */
		public static string $mode = 'ok';
		public static string $throw_message = 'Settings validation failed. simulated Elementor failure';
		/** @var callable|null what Elementor's kses_post_deep does to each string, for a user without unfiltered_html */
		public static $kses = null;
		public static array $received = [];
		/** @var array<int,array> map_meta_cap for unfiltered_html as seen inside save() */
		public static array $cap_during_save = [];
		public function save( array $data ): bool {
			self::$received[] = $data;
			self::$cap_during_save[] = \apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] );
			if ( 'throw' === self::$mode ) {
				throw new \RuntimeException( self::$throw_message );
			}
			if ( 'refuse' === self::$mode ) {
				return false; // what Elementor does for a user who may not edit the post (e.g. user 0)
			}
			// On a real save Elementor re-encodes the data and renders post_content.
			$elements = \ElementorWritePipelineHelper::reencode( $data['elements'] );
			if ( null !== self::$kses ) {
				array_walk_recursive( $elements, function ( &$v ) { $v = is_string( $v ) ? ( self::$kses )( $v ) : $v; } );
			}
			\IATO_Test_WP::$meta[ \ElementorWritePipelineHelper::POST ]['_elementor_data'] = json_encode( $elements );
			\IATO_Test_WP::$posts[ \ElementorWritePipelineHelper::POST ]['post_content'] = '<h2>rendered by elementor</h2>';
			return true;
		}
	}
	final class FakeDocuments {
		public static bool $absent = false;
		public function get( int $id ) { return self::$absent ? null : new FakeDocument(); }
		/** @var array<string,string> type => document class, what get_document_types() answers */
		public static array $types = [];
		/** @var callable|null what create() does, for tests of IATO_MCP_Theme_Builder */
		public static $on_create = null;
		public function get_document_types(): array { return self::$types; }
		public function create( string $type, array $post_data = [], array $meta_data = [] ) { return ( self::$on_create )( $type, $post_data, $meta_data ); }
	}
	final class FakeFilesManager {
		public static int $cleared = 0;
		public function clear_cache(): void {
			self::$cleared++;
			if ( class_exists( 'IATO_Test_Cache_State' ) ) {
				\IATO_Test_Cache_State::$calls[] = 'elementor_clear_cache';
			}
		}
	}
	final class FakeFrontend {
		public static int $renders = 0;
		/** 1.14.0: an addon widget throwing while the page renders. */
		public static bool $throw = false;
		public function get_builder_content( int $id, bool $with_css = false ): string {
			self::$renders++;
			if ( self::$throw ) {
				throw new \RuntimeException( 'addon widget failed to render' );
			}
			return '<h2>frontend fallback render</h2>';
		}
	}
	/** Elementor's text version of a page (includes/db.php). */
	final class FakeDb {
		/** @var 'ok'|'throw' */
		public static string $mode = 'ok';
		public static string $text = 'the text of the page';
		public static ?array $rendered = null;
		public function get_plain_text( int $id ): string { return $this->render( null ); }
		public function get_plain_text_from_data( array $data ): string { return $this->render( $data ); }
		private function render( ?array $data ): string {
			self::$rendered = $data;
			ob_start(); // Elementor's own unguarded ob_start()
			if ( 'throw' === self::$mode ) {
				throw new \RuntimeException( 'a widget failed' );
			}
			ob_end_clean();
			return self::$text;
		}
	}
	final class FakeDynamicTags {
		public string $mode = 'all';
		public function get_parsing_mode(): string { return $this->mode; }
		public function set_parsing_mode( string $mode ): void { $this->mode = $mode; }
	}
	final class Plugin {
		public static ?Plugin $instance = null;
		public FakeDocuments $documents;
		public FakeFilesManager $files_manager;
		public FakeFrontend $frontend;
		public FakeDb $db;
		public FakeDynamicTags $dynamic_tags;
		/** @var object|null what get_current_settings( 'default_page_template' ) comes from */
		public ?object $kits_manager = null;
		public function __construct() { $this->db = new FakeDb(); $this->dynamic_tags = new FakeDynamicTags(); $this->documents = new FakeDocuments(); $this->files_manager = new FakeFilesManager(); $this->frontend = new FakeFrontend(); }
		public static function instance(): Plugin { return self::$instance ??= new Plugin(); }
	}
	Plugin::$instance = Plugin::instance();
}

namespace Elementor\Core\DynamicTags {
	final class Manager {
		public const MODE_REMOVE = 'remove';
	}
}

// Elementor's per-post CSS file, the calls IATO_MCP_Cache makes.
namespace Elementor\Core\Files\CSS {
	final class Post {
		/** @var 'ok'|'throw_delete'|'throw_update'|'keep_meta' */
		public static string $mode = 'ok';
		public static string $status = 'empty';
		/** @var int[] posts whose file was rebuilt */
		public static array $updated = [];
		public function __construct( private int $id ) {}
		public static function create( int $id ): self { return new self( $id ); }
		public function delete(): void {
			if ( 'throw_delete' === self::$mode ) {
				throw new \RuntimeException( 'cannot delete CSS' );
			}
		}
		public function update(): void {
			if ( 'throw_update' === self::$mode ) {
				throw new \RuntimeException( 'addon control failed' );
			}
			self::$updated[] = $this->id;
			if ( class_exists( 'IATO_Test_Cache_State' ) ) {
				\IATO_Test_Cache_State::$calls[] = 'post_css_update:' . $this->id;
			}
		}
		public function get_meta( ?string $property = null ): mixed { return self::$status; }
		public function get_path(): string { return __FILE__; }
	}
}

// ── WordPress stubs the pipeline calls ────────────────────────────────────
namespace {


	final class ElementorWritePipelineHelper {
		public const POST = 777;
		/** Elementor's save normalises nodes: adds isInner/version-ish noise and reorders keys. */
		public static function reencode( array $elements ): array {
			$out = [];
			foreach ( $elements as $el ) {
				$node = [ 'id' => $el['id'], 'elType' => $el['elType'] ];
				if ( isset( $el['widgetType'] ) ) { $node['widgetType'] = $el['widgetType']; }
				$node['settings'] = $el['settings'] ?? [];
				$node['elements'] = self::reencode( $el['elements'] ?? [] );
				$node['isInner']  = false;
				$out[] = $node;
			}
			return $out;
		}
	}
	if ( ! function_exists( 'get_post' ) ) {
		function get_post( int $id ): ?object { return isset( IATO_Test_WP::$posts[ $id ] ) ? (object) ( [ 'ID' => $id ] + IATO_Test_WP::$posts[ $id ] ) : null; }
	}
	if ( ! function_exists( 'get_post_field' ) ) {
		function get_post_field( string $field, int $id ): string { return (string) ( IATO_Test_WP::$posts[ $id ][ $field ] ?? '' ); }
	}
	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( int $id, string $key, mixed $value ): bool { $v = is_string( $value ) ? stripslashes( $value ) : ( is_array( $value ) ? IATO_Test_WP::unslash_deep( $value ) : $value ); IATO_Test_WP::$meta[ $id ][ $key ] = $v; IATO_Test_WP::$writes[] = [ $id, $key, $v ]; IATO_Test_WP::note_write( $key ); return true; }
	}
	if ( ! function_exists( 'delete_post_meta' ) ) {
		function delete_post_meta( int $id, string $key ): bool { unset( IATO_Test_WP::$meta[ $id ][ $key ] ); return true; }
	}
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		function wp_cache_delete( mixed $key, string $group = '' ): bool { return true; }
	}
	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( mixed $v ): mixed { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $text ): string { return trim( strip_tags( $text ) ); }
	}
	if ( ! function_exists( 'wp_update_post' ) ) {
		function wp_update_post( array $postarr, bool $wp_error = false ): int|WP_Error {
			if ( null !== IATO_Test_WP::$update_post_error ) {
				return IATO_Test_WP::$update_post_error;
			}
			if ( IATO_Test_WP::$update_post_throws && array_key_exists( 'post_status', $postarr ) ) {
				IATO_Test_WP::$posts[ $postarr['ID'] ]['post_status'] = $postarr['post_status'];
				if ( class_exists( 'IATO_Test_Roles' ) && isset( IATO_Test_Roles::$posts[ $postarr['ID'] ] ) ) {
					IATO_Test_Roles::$posts[ $postarr['ID'] ]['post_status'] = $postarr['post_status'];
				}
				throw new \RuntimeException( 'an addon failed in save_post' );
			}
			if ( array_key_exists( 'post_content', $postarr ) || ! array_key_exists( 'post_status', $postarr ) ) {
				IATO_Test_WP::$posts[ $postarr['ID'] ]['post_content'] = stripslashes( (string) ( $postarr['post_content'] ?? '' ) );
			}
			if ( array_key_exists( 'post_status', $postarr ) ) {
				IATO_Test_WP::$posts[ $postarr['ID'] ]['post_status'] = $postarr['post_status'];
				if ( class_exists( 'IATO_Test_Roles' ) && isset( IATO_Test_Roles::$posts[ $postarr['ID'] ] ) ) {
					IATO_Test_Roles::$posts[ $postarr['ID'] ]['post_status'] = $postarr['post_status'];
				}
			}
			return $postarr['ID'];
		}
	}

	/**
	 * Stand-in for IATO_MCP_Elementor_Atomic_Engine (Elementor's resolver,
	 * Props_Parser and save transform), modelled on Elementor 4.3.4:
	 * schemas are type => key => prop, prop = { type, kind: plain|object|union,
	 * shape?, enum?, url?, variants?, required? }.
	 */
	final class FakeAtomicEngine {
		public ?string $missing = null;
		public array $schemas   = [];
		public array $aliases   = []; // type => alias => key
		public array $save_fail = []; // element id => Elementor's message
		public array $save_alter = []; // element ids whose save transform changes their settings
		public ?string $migrated = null; // tree JSON the load migrations produce
		public array $calls     = [];

		public static function heading_site(): self {
			$e        = new self();
			$link     = [ 'type' => 'link', 'kind' => 'object', 'shape' => [
				'destination'   => [ 'kind' => 'union', 'variants' => [ 'url', 'query' ], 'type' => 'url', 'url' => true ],
				'isTargetBlank' => [ 'type' => 'boolean', 'kind' => 'plain' ],
				'tag'           => [ 'type' => 'string', 'kind' => 'plain', 'enum' => [ 'a', 'button' ] ],
			] ];
			$e->schemas = [
				'e-heading'   => [
					'classes' => [ 'type' => 'classes', 'kind' => 'plain' ],
					'tag'     => [ 'type' => 'string', 'kind' => 'plain', 'enum' => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] ],
					'title'   => [ 'type' => 'escaped-html', 'kind' => 'plain' ],
					'link'    => $link,
				],
				'e-paragraph' => [ 'paragraph' => [ 'type' => 'escaped-html', 'kind' => 'plain' ], 'tag' => [ 'type' => 'string', 'kind' => 'plain', 'enum' => [ 'p', 'span' ] ], 'link' => $link ],
				'e-button'    => [ 'text' => [ 'type' => 'escaped-html', 'kind' => 'plain' ], 'link' => $link ],
				'e-image'     => [
					'image' => [ 'type' => 'image', 'kind' => 'object', 'shape' => [
						'src'  => [ 'type' => 'image-src', 'kind' => 'object', 'required' => true, 'shape' => [
							'id'  => [ 'type' => 'image-attachment-id', 'kind' => 'plain', 'number' => true ],
							'url' => [ 'type' => 'url', 'kind' => 'plain', 'url' => true, 'validate_url' => true ],
							'alt' => [ 'type' => 'string', 'kind' => 'plain' ],
						] ],
						'size' => [ 'type' => 'string', 'kind' => 'plain' ],
					] ],
					'link'  => $link,
				],
				'e-flexbox'   => [ 'tag' => [ 'type' => 'string', 'kind' => 'plain' ] ],
				'e-component' => [ 'component' => [ 'type' => 'component', 'kind' => 'plain' ] ],
			];
			$e->aliases = [ 'e-heading' => [ 'text' => 'title', 'content' => 'title', 'heading' => 'title' ], 'e-paragraph' => [ 'text' => 'paragraph' ] ];
			return $e;
		}

		private static function type( array $node ): string {
			return 'widget' === ( $node['elType'] ?? '' ) ? (string) ( $node['widgetType'] ?? '' ) : (string) ( $node['elType'] ?? '' );
		}
		private function prop( array $node, array $path ): ?array {
			$prop = $this->schemas[ self::type( $node ) ][ (string) array_shift( $path ) ] ?? null;
			foreach ( $path as $seg ) {
				if ( 'object' !== ( $prop['kind'] ?? '' ) ) {
					return null;
				}
				$prop = $prop['shape'][ $seg ] ?? null;
			}
			return $prop;
		}

		public function missing(): ?string { return $this->missing; }
		public function version(): string { return '4.3.4'; }
		public function keys( array $node ): ?array {
			$s = $this->schemas[ self::type( $node ) ] ?? null;
			return null === $s ? null : array_keys( $s );
		}
		public function aliases( array $node ): array { return $this->aliases[ self::type( $node ) ] ?? []; }
		public function object_key_at( array $node, array $path, ?string $prefer = null ): ?string {
			$p = $this->prop( $node, $path );
			return 'object' === ( $p['kind'] ?? '' ) ? $p['type'] : null;
		}
		public function shape_keys( array $node, array $path, ?string $prefer = null ): ?array {
			$p = $this->prop( $node, $path );
			return 'object' === ( $p['kind'] ?? '' ) ? array_keys( $p['shape'] ) : null;
		}
		public function resolve_at( array $node, array $path, mixed $plain ): mixed {
			$this->calls[] = [ 'resolve', implode( '.', $path ), $plain ];
			$p = $this->prop( $node, $path );
			return null === $p ? null : self::resolve( $plain, $p );
		}
		private static function resolve( mixed $plain, array $p ): mixed {
			if ( null === $plain ) {
				return null;
			}
			if ( 'object' === $p['kind'] ) {
				if ( ! is_array( $plain ) || ! array_intersect_key( $plain, $p['shape'] ) ) {
					return null;
				}
				$out = [];
				foreach ( $p['shape'] as $k => $sub ) {
					if ( array_key_exists( $k, $plain ) && null !== ( $r = self::resolve( $plain[ $k ], $sub ) ) ) {
						$out[ $k ] = $r;
					}
				}
				return [ '$$type' => $p['type'], 'value' => $out ];
			}
			if ( 'union' === $p['kind'] ) {
				return is_string( $plain ) ? [ '$$type' => 'url', 'value' => $plain ] : null;
			}
			if ( ! is_scalar( $plain ) ) {
				return null;
			}
			if ( 'boolean' === $p['type'] ) {
				return [ '$$type' => 'boolean', 'value' => (bool) $plain ];
			}
			if ( ! empty( $p['number'] ) ) {
				return is_numeric( $plain ) ? [ '$$type' => $p['type'], 'value' => (int) $plain ] : null;
			}
			return [ '$$type' => $p['type'], 'value' => (string) $plain ];
		}
		/** Props_Parser: validate every schema key, then sanitise (strings like kses + esc_url_raw). */
		public function parse( array $node, array $settings ): array {
			$schema = $this->schemas[ self::type( $node ) ] ?? null;
			if ( null === $schema ) {
				return [ 'valid' => false, 'errors' => 'no schema', 'error_keys' => [], 'settings' => [] ];
			}
			$errors = [];
			$out    = [];
			foreach ( $schema as $key => $p ) {
				$value = $settings[ $key ] ?? null;
				if ( null === $value ) {
					continue;
				}
				if ( ! self::valid( $value, $p ) ) {
					$errors[] = $key;
					continue;
				}
				$out[ $key ] = self::sanitize( $value, $p );
			}
			return [ 'valid' => ! $errors, 'errors' => implode( ', ', array_map( fn( $k ) => "{$k}: invalid_value", $errors ) ), 'error_keys' => $errors, 'settings' => $out ];
		}
		private static function valid( mixed $v, array $p ): bool {
			if ( ! is_array( $v ) || ! isset( $v['$$type'] ) || ! array_key_exists( 'value', $v ) ) {
				return false;
			}
			if ( 'union' === $p['kind'] ) {
				return in_array( $v['$$type'], $p['variants'], true );
			}
			if ( $v['$$type'] !== $p['type'] ) {
				return false;
			}
			if ( 'object' === $p['kind'] ) {
				if ( ! is_array( $v['value'] ) ) {
					return false;
				}
				foreach ( $p['shape'] as $k => $sub ) {
					$sv = $v['value'][ $k ] ?? null;
					if ( null === $sv ) {
						if ( ! empty( $sub['required'] ) ) {
							return false;
						}
						continue;
					}
					if ( ! self::valid( $sv, $sub ) ) {
						return false;
					}
				}
				if ( 'image-src' === $p['type'] && empty( $v['value']['id'] ) === empty( $v['value']['url'] ) ) {
					return false; // id XOR url
				}
				return true;
			}
			if ( isset( $p['enum'] ) && ! in_array( $v['value'], $p['enum'], true ) ) {
				return false;
			}
			if ( ! empty( $p['validate_url'] ) && false === filter_var( $v['value'], FILTER_VALIDATE_URL ) ) {
				return false;
			}
			return true;
		}
		private static function sanitize( mixed $v, array $p ): mixed {
			if ( 'object' === $p['kind'] ) {
				foreach ( $p['shape'] as $k => $sub ) {
					if ( isset( $v['value'][ $k ] ) ) {
						$v['value'][ $k ] = self::sanitize( $v['value'][ $k ], $sub );
					}
				}
				return $v;
			}
			if ( is_string( $v['value'] ) ) {
				if ( 'url' === $v['$$type'] ) {
					$v['value'] = preg_match( '/^\s*javascript:/i', $v['value'] ) ? '' : $v['value'];
				} else {
					$v['value'] = str_replace( '&', '&amp;', str_replace( '&amp;', '&', strip_tags( preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $v['value'] ), '<b><strong><em><i><span><br><a>' ) ) );
				}
			}
			return $v;
		}
		public function save_transform( array $node ): array {
			$id = (string) ( $node['id'] ?? '' );
			if ( isset( $this->save_fail[ $id ] ) ) {
				return [ 'ok' => false, 'error' => $this->save_fail[ $id ] ];
			}
			$parsed = $this->parse( $node, is_array( $node['settings'] ?? null ) ? $node['settings'] : [] );
			if ( ! $parsed['valid'] ) {
				return [ 'ok' => false, 'error' => 'Settings validation failed. ' . $parsed['errors'] ];
			}
			$data             = $node;
			$data['settings'] = $parsed['settings'];
			if ( in_array( $id, $this->save_alter, true ) ) {
				$data['styles'] = []; // e.g. Elementor dropping an invalid style
				$data['settings']['_altered'] = true;
			}
			return [ 'ok' => true, 'data' => $data ];
		}
		/** Elementor's load migrations, captured in memory (never written). */
		public function migrate( int $post_id ): array {
			$this->calls[] = [ 'migrate', $post_id ];
			if ( null === $this->migrated ) {
				return [ 'migrated' => false, 'tree' => null, 'error' => null ];
			}
			return [ 'migrated' => true, 'tree' => $this->migrated, 'error' => null ];
		}
	}
}
