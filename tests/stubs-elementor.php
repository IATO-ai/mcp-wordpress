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
			\IATO_Test_WP::$meta[ \ElementorWritePipelineHelper::POST ]['_elementor_data'] = json_encode( \ElementorWritePipelineHelper::reencode( $data['elements'] ) );
			\IATO_Test_WP::$posts[ \ElementorWritePipelineHelper::POST ]['post_content'] = '<h2>rendered by elementor</h2>';
			return true;
		}
	}
	final class FakeDocuments { public static bool $absent = false; public function get( int $id ) { return self::$absent ? null : new FakeDocument(); } }
	final class FakeFilesManager { public static int $cleared = 0; public function clear_cache(): void { self::$cleared++; } }
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
	final class Plugin {
		public static ?Plugin $instance = null;
		public FakeDocuments $documents;
		public FakeFilesManager $files_manager;
		public FakeFrontend $frontend;
		public function __construct() { $this->documents = new FakeDocuments(); $this->files_manager = new FakeFilesManager(); $this->frontend = new FakeFrontend(); }
		public static function instance(): Plugin { return self::$instance ??= new Plugin(); }
	}
	Plugin::$instance = Plugin::instance();
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
	if ( ! function_exists( 'wp_update_post' ) ) {
		function wp_update_post( array $postarr ): int { IATO_Test_WP::$posts[ $postarr['ID'] ]['post_content'] = stripslashes( (string) ( $postarr['post_content'] ?? '' ) ); return $postarr['ID']; } // core unslashes
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
