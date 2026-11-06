<?php
/**
 * A fake Elementor Pro Theme Builder: only what IATO_MCP_Elementor_Router asks of
 * it. Inactive unless a test says otherwise, which is how Pro being absent looks to
 * the router's guards (instance() gives no object), so loading this file changes
 * nothing for other tests. Method names as in Pro 4.3.3,
 * modules/theme-builder/classes/conditions-manager.php and conditions-cache.php.
 */

declare( strict_types=1 );

namespace {
	final class IATO_Test_Pro {
		public static bool $active = false;
		/** @var array<string,array<int,string[]>> location => template id => conditions, in the cache's order */
		public static array $cache = [];
		/** @var array<string,object[]> location => documents get_documents_for_location() answers with */
		public static array $documents = [];
		/** @var string[] locations get_documents_for_location() was asked about */
		public static array $asked = [];
		public static bool $cache_throws = false;
		/** @var array<string,array> what get_conditions_config() answers */
		public static array $config = [];
		/** @var array<int,string> template ID => location; a template not here is not a Theme_Document */
		public static array $theme_documents = [];
		/** @var array<string,array> location => get_location() answer */
		public static array $locations = [];
		public static int $regenerated = 0;
		/** @var array<int,array> every rules list save_conditions() received */
		public static array $saved = [];
		/** @var 'ok'|'store_nothing'|'throw' */
		public static string $save_mode = 'ok';
		public static bool $regenerate_throws = false;
		public static bool $instance_throws = false;

		public static function reset(): void {
			self::$active       = false;
			self::$cache        = [];
			self::$documents    = [];
			self::$asked        = [];
			self::$cache_throws = false;
			self::$config = [];
			self::$theme_documents = [];
			self::$locations = [];
			self::$regenerated = 0;
			self::$saved = [];
			self::$save_mode = 'ok';
			self::$regenerate_throws = false;
			self::$instance_throws = false;
		}
	}
}

namespace {
	if ( ! function_exists( 'remove_action' ) ) {
		function remove_action( string $hook, callable $cb, int $priority = 10 ): bool {
			if ( class_exists( 'IATO_Test_Abilities_State' ) && isset( IATO_Test_Abilities_State::$actions[ $hook ] ) ) {
				IATO_Test_Abilities_State::$actions[ $hook ] = array_values( array_filter( IATO_Test_Abilities_State::$actions[ $hook ], static fn( $c ) => $c !== $cb ) );
			}
			return true;
		}
	}
}

namespace ElementorPro\Modules\ThemeBuilder {
	final class FakeConditionsCache {
		public function get_by_location( string $location ): array {
			if ( \IATO_Test_Pro::$cache_throws ) {
				throw new \RuntimeException( 'cache failed' );
			}
			return \IATO_Test_Pro::$cache[ $location ] ?? [];
		}
		public function regenerate(): self {
			if ( \IATO_Test_Pro::$regenerate_throws ) {
				throw new \RuntimeException( 'regenerate failed' );
			}
			\IATO_Test_Pro::$regenerated++;
			return $this;
		}
	}
	final class FakeConditionsManager {
		public function get_documents_for_location( string $location ): array {
			\IATO_Test_Pro::$asked[] = $location;
			return \IATO_Test_Pro::$documents[ $location ] ?? [];
		}
		public function get_cache(): FakeConditionsCache {
			return new FakeConditionsCache();
		}
		public function get_conditions_config(): array {
			return \IATO_Test_Pro::$config;
		}
		// As Pro 4.3.3: joins the values in order, stores them, regenerates, and answers
		// with update_meta()'s result, which is false for a value saved again.
		public function save_conditions( int $post_id, array $conditions ): mixed {
			\IATO_Test_Pro::$saved[] = $conditions;
			if ( 'throw' === \IATO_Test_Pro::$save_mode ) {
				throw new \RuntimeException( 'an addon failed' );
			}
			if ( 'store_nothing' === \IATO_Test_Pro::$save_mode || ! isset( \IATO_Test_Pro::$theme_documents[ $post_id ] ) ) {
				return false;
			}
			$strings = array_map( static fn( array $rule ): string => rtrim( implode( '/', $rule ), '/' ), $conditions );
			$before  = \IATO_Test_WP::$meta[ $post_id ]['_elementor_conditions'] ?? null;
			if ( [] === $strings ) {
				unset( \IATO_Test_WP::$meta[ $post_id ]['_elementor_conditions'] );
			} else {
				\IATO_Test_WP::$meta[ $post_id ]['_elementor_conditions'] = $strings;
			}
			( new FakeConditionsCache() )->regenerate();
			return $before === $strings ? false : 123;
		}
	}
	final class FakeThemeDocument {
		public function __construct( private string $location ) {}
		public function get_location(): string { return $this->location; }
	}
	final class FakeLocationsManager {
		public function get_location( string $location ): array { return \IATO_Test_Pro::$locations[ $location ] ?? []; }
	}
	final class Module {
		public static function instance(): ?self {
			if ( \IATO_Test_Pro::$instance_throws ) {
				throw new \RuntimeException( 'Pro failed to load' );
			}
			return \IATO_Test_Pro::$active ? new self() : null;
		}
		public function get_document( int $id ): ?FakeThemeDocument {
			return isset( \IATO_Test_Pro::$theme_documents[ $id ] ) ? new FakeThemeDocument( \IATO_Test_Pro::$theme_documents[ $id ] ) : null;
		}
		public function get_locations_manager(): FakeLocationsManager {
			return new FakeLocationsManager();
		}
		public function get_conditions_manager(): FakeConditionsManager {
			return new FakeConditionsManager();
		}
	}
}
