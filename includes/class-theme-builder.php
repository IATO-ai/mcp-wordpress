<?php
/**
 * Elementor Pro's Theme Builder, behind one guarded object (1.17.0).
 *
 * create_elementor_template and set_elementor_template_conditions, and the rollback
 * of what they did, reach Elementor and Pro only through this class. Every call is
 * wrapped, so a Throwable from Elementor, Pro or an addon becomes a WP_Error or a null
 * answer, never a fatal. Unit tests replace the object with a fake through set_instance().
 *
 * Dependencies, checked against Elementor 4.3.6 and Elementor Pro 4.3.3:
 *   \Elementor\Plugin::$instance->documents->create(), get_document_types()
 *   <document class>::get_property( 'cpt' | 'location' | 'support_conditions' )
 *   \ElementorPro\Modules\ThemeBuilder\Module::instance(), get_document()
 *   Module::get_locations_manager()->get_location()          its `multiple` setting
 *   Module::get_conditions_manager()->get_conditions_config(), save_conditions(), get_cache()
 *   Conditions_Cache::get_by_location(), regenerate()
 *   Theme_Document::get_location()
 *
 * What a template is made of is Elementor's to write, so documents->create() does it:
 * as an administrator the result is the same as wp-admin's Add New Template, and with
 * no user Document::save() refuses and leaves a post without its version or type term.
 * Writing `_elementor_conditions` alone would not work either: Pro's conditions cache
 * decides what renders, and save_conditions() is what regenerates it.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Theme_Builder {

	/** Types that exist only with Elementor Pro: asking for one without it names the plugin. */
	public const THEME_TYPES = [ 'header', 'footer', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'popup' ];

	/** Types free Elementor registers too. */
	public const LIBRARY_TYPES = [ 'section', 'container', 'page' ];

	/** The `items` of a `conditions` array, for a tool's input schema: a stored string or a rule. */
	public const CONDITION_ITEMS = [
		'anyOf' => [
			[ 'type' => 'string', 'description' => 'As stored: include/general, exclude/singular/page/42.' ],
			[
				'type'       => 'object',
				'properties' => [
					'type'     => [ 'type' => 'string', 'enum' => [ 'include', 'exclude' ] ],
					'name'     => [ 'type' => 'string', 'description' => 'general, singular or archive.' ],
					'sub_name' => [ 'type' => 'string', 'description' => 'What under name: page, post, front_page, category, and so on.' ],
					'sub_id'   => [ 'type' => [ 'integer', 'string' ], 'description' => 'A post, term or user ID, where sub_name takes one.' ],
				],
				'required'   => [ 'type', 'name' ],
			],
		],
	];

	private const LIBRARY_POST_TYPE = 'elementor_library';
	private const MODULE            = '\ElementorPro\Modules\ThemeBuilder\Module';

	/** @var object|null This class or a test double. */
	private static ?object $instance = null;

	public static function get(): object {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Replace Elementor's code with a test double; null restores it. */
	public static function set_instance( ?object $instance ): void {
		self::$instance = $instance;
	}

	// ── Plain logic, no Elementor ──────────────────────────────────────────

	/** A refusal the client needs its details from: MCP clients see only a tool error's text. */
	public static function refusal( string $code, string $message, array $extra = [] ): array {
		return [
			'isError' => true,
			'content' => [ [
				'type' => 'text',
				'text' => (string) wp_json_encode( [ 'error' => $code, 'message' => $message ] + $extra, JSON_UNESCAPED_SLASHES ),
			] ],
		];
	}

	/** @return string[] A template's condition strings, as stored. */
	public static function stored_conditions( int $template_id ): array {
		$stored = get_post_meta( $template_id, '_elementor_conditions', true );
		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : [];
	}

	/** What if_revision compares: revisions elsewhere hash _elementor_data, which conditions are not in. */
	public static function conditions_revision( array $conditions ): string {
		return IATO_MCP_Elementor_Adapter::compute_revision( (string) wp_json_encode( array_values( $conditions ) ) );
	}

	/**
	 * Condition strings as the rules save_conditions() joins, whose keys must come in
	 * this order: it implodes the values.
	 *
	 * @param string[] $conditions
	 * @return list<array{type:string,name:string,sub_name:string,sub_id:string}>
	 */
	public static function rules_from( array $conditions ): array {
		$rules = [];
		foreach ( $conditions as $condition ) {
			[ $type, $name, $sub_name, $sub_id ] = array_pad( explode( '/', (string) $condition, 4 ), 4, '' );
			$rules[] = [ 'type' => $type, 'name' => $name, 'sub_name' => $sub_name, 'sub_id' => $sub_id ];
		}
		return $rules;
	}

	/**
	 * Check conditions against what Pro registers, in either form: a rule, as Elementor
	 * reports them, or the stored string list_elementor_templates returns.
	 *
	 * @param mixed                                       $input      The tool's `conditions`.
	 * @param array<string,array{sub:string[],takes_id:bool}> $registered conditions_config().
	 * @return string[]|WP_Error The condition strings to store, in the order given.
	 */
	public static function normalize_conditions( mixed $input, array $registered ): array|WP_Error {
		if ( ! is_array( $input ) || isset( $input['type'] ) || isset( $input['name'] ) ) {
			return self::condition_error( 'conditions must be a list of rules, such as [{"type":"include","name":"general"}], or of strings, such as ["include/general"].', $registered );
		}
		$strings = [];
		foreach ( $input as $entry ) {
			$string = self::condition_string( $entry, $registered );
			if ( is_wp_error( $string ) ) {
				return $string;
			}
			$strings[] = $string;
		}
		return array_values( array_unique( $strings ) );
	}

	/** One condition, checked, as the string Pro stores. */
	private static function condition_string( mixed $entry, array $registered ): string|WP_Error {
		if ( is_string( $entry ) ) {
			$parts = explode( '/', $entry );
			if ( count( $parts ) > 4 ) {
				return self::condition_error( sprintf( 'Too many parts in the condition "%s".', $entry ), $registered );
			}
			[ $type, $name, $sub_name, $sub_id ] = array_pad( $parts, 4, '' );
		} elseif ( is_array( $entry ) ) {
			$unknown = array_diff( array_keys( $entry ), [ 'type', 'name', 'sub_name', 'sub_id' ] );
			if ( [] !== $unknown ) {
				return self::condition_error( sprintf( 'A condition has only type, name, sub_name and sub_id; this one also has %s.', implode( ', ', $unknown ) ), $registered );
			}
			$type     = $entry['type'] ?? '';
			$name     = $entry['name'] ?? '';
			$sub_name = $entry['sub_name'] ?? '';
			$sub_id   = $entry['sub_id'] ?? '';
			if ( ! is_string( $type ) || ! is_string( $name ) || ! is_string( $sub_name ) || ! ( is_string( $sub_id ) || is_int( $sub_id ) ) ) {
				return self::condition_error( 'type, name and sub_name are strings, and sub_id an ID.', $registered );
			}
			$sub_id = (string) $sub_id;
		} else {
			return self::condition_error( 'Each condition is a rule or a string.', $registered );
		}

		$shown = rtrim( "{$type}/{$name}/{$sub_name}/{$sub_id}", '/' );
		if ( 'include' !== $type && 'exclude' !== $type ) {
			return self::condition_error( sprintf( 'The condition "%s" must start with include or exclude.', $shown ), $registered );
		}
		// The top level is general and what general lists under it, as Pro's editor offers them.
		$top = array_merge( [ 'general' ], $registered['general']['sub'] ?? [] );
		if ( ! in_array( $name, $top, true ) ) {
			return self::condition_error( sprintf( 'Unknown condition name "%1$s" in "%2$s".', $name, $shown ), $registered );
		}
		// General has singular and archive under it as names, not as sub-names: Entire Site stands alone.
		if ( '' !== $sub_name && ( 'general' === $name || ! in_array( $sub_name, self::reachable_from( $name, $registered ), true ) ) ) {
			return self::condition_error( sprintf( '"%1$s" is not a condition under "%2$s", in "%3$s".', $sub_name, $name, $shown ), $registered );
		}
		if ( '' !== $sub_id ) {
			if ( '' === $sub_name || ! ctype_digit( $sub_id ) || 0 === (int) $sub_id ) {
				return self::condition_error( sprintf( 'sub_id in "%s" must be a positive ID, with a sub_name.', $shown ), $registered );
			}
			if ( empty( $registered[ $sub_name ]['takes_id'] ) ) {
				return self::condition_error( sprintf( '"%1$s" does not take an ID, in "%2$s".', $sub_name, $shown ), $registered );
			}
		}
		return $shown;
	}

	/** @return string[] Every condition below this one. */
	private static function reachable_from( string $name, array $registered ): array {
		$found = [];
		$queue = $registered[ $name ]['sub'] ?? [];
		while ( [] !== $queue ) {
			$next = (string) array_shift( $queue );
			if ( ! in_array( $next, $found, true ) ) {
				$found[] = $next;
				$queue   = array_merge( $queue, $registered[ $next ]['sub'] ?? [] );
			}
		}
		return $found;
	}

	/** The refusal, carrying what could have been asked for. */
	private static function condition_error( string $message, array $registered ): WP_Error {
		$tree = [ 'general' => [] ];
		foreach ( $registered['general']['sub'] ?? [] as $name ) {
			$tree[ $name ] = self::reachable_from( $name, $registered );
		}
		$takes_id = [];
		foreach ( $registered as $name => $condition ) {
			if ( ! empty( $condition['takes_id'] ) ) {
				$takes_id[] = (string) $name;
			}
		}
		return new WP_Error( 'unknown_condition', $message, [ 'registered' => $tree, 'takes_id' => $takes_id ] );
	}

	/**
	 * Published templates in the location that may show on some of the same pages. Pro
	 * shows one per URL, by priority, and on a tie by its cache's order, which a
	 * regeneration can change. Popups are not in competition.
	 *
	 * @param string[] $conditions What this template would have.
	 * @return list<array{template_id:int,title:string,condition:string,your_condition:string,relation:string}>
	 *         relation: same, wider (theirs covers yours), narrower (yours covers theirs), may_overlap.
	 */
	public static function overlaps( int $template_id, string $location, array $conditions ): array {
		$builder = self::get();
		if ( $builder->location_allows_many( $location ) ) {
			return [];
		}
		$registered = (array) $builder->conditions_config();
		$found      = [];
		foreach ( $builder->cached( $location ) as $other_id => $other_conditions ) {
			$other_id = (int) $other_id;
			if ( $other_id === $template_id || 'publish' !== get_post_status( $other_id ) ) {
				continue;
			}
			foreach ( (array) $other_conditions as $theirs ) {
				foreach ( $conditions as $mine ) {
					$relation = self::overlap_relation( (string) $theirs, (string) $mine, $registered );
					if ( null !== $relation ) {
						$found[] = [ 'template_id' => $other_id, 'title' => (string) get_post_field( 'post_title', $other_id ), 'condition' => (string) $theirs, 'your_condition' => (string) $mine, 'relation' => $relation ];
					}
				}
			}
		}
		return $found;
	}

	/**
	 * How two includes relate, or null when no URL can match both. Null is only for what is
	 * certain from the shape of the conditions; anything else may overlap.
	 */
	private static function overlap_relation( string $theirs, string $mine, array $registered ): ?string {
		if ( ! str_starts_with( $theirs, 'include/' ) || ! str_starts_with( $mine, 'include/' ) ) {
			return null;
		}
		if ( $theirs === $mine ) {
			return 'same';
		}
		[ , $their_name, $their_sub, $their_id ] = array_pad( explode( '/', $theirs, 4 ), 4, '' );
		[ , $my_name, $my_sub, $my_id ]          = array_pad( explode( '/', $mine, 4 ), 4, '' );
		if ( 'general' === $their_name ) {
			return 'wider';
		}
		if ( 'general' === $my_name ) {
			return 'narrower';
		}
		if ( $their_name !== $my_name ) {
			// A URL is singular or an archive, never both. WooCommerce's names, say, are not known here.
			return in_array( $their_name, [ 'singular', 'archive' ], true ) && in_array( $my_name, [ 'singular', 'archive' ], true ) ? null : 'may_overlap';
		}
		if ( '' === $their_sub ) {
			return 'wider';
		}
		if ( '' === $my_sub ) {
			return 'narrower';
		}
		if ( $their_sub === $my_sub ) {
			if ( '' === $their_id ) {
				return 'wider';
			}
			if ( '' === $my_id ) {
				return 'narrower';
			}
			// A post can be in two terms, or under two ancestors. It has one ID, one parent, one author.
			return 1 === preg_match( '/^(?:in_|any_child_of)/', $their_sub ) ? 'may_overlap' : null;
		}
		if ( 'singular' === $their_name ) {
			return self::singular_overlap( $their_sub, $their_id, $my_sub, $my_id, $registered );
		}
		if ( 'archive' === $their_name ) {
			return self::archive_family( $their_sub ) === self::archive_family( $my_sub ) ? 'may_overlap' : null;
		}
		return 'may_overlap';
	}

	/** Two different sub-conditions of singular. */
	private static function singular_overlap( string $their_sub, string $their_id, string $my_sub, string $my_id, array $registered ): ?string {
		if ( 'not_found404' === $their_sub || 'not_found404' === $my_sub ) {
			return null; // a not-found URL has no post
		}
		$their_type = self::post_type_of( $their_sub, $registered );
		$my_type    = self::post_type_of( $my_sub, $registered );
		if ( null !== $their_type && null !== $my_type && $their_type !== $my_type ) {
			return null;
		}
		if ( $their_sub === $my_type ) {
			return '' === $their_id ? 'wider' : 'may_overlap';
		}
		if ( $my_sub === $their_type ) {
			return '' === $my_id ? 'narrower' : 'may_overlap';
		}
		return 'may_overlap';
	}

	/** The post type a singular sub-condition is about, or null when it can be any. */
	private static function post_type_of( string $sub, array $registered ): ?string {
		if ( 'front_page' === $sub ) {
			return 'page';
		}
		if ( in_array( $sub, [ 'child_of', 'any_child_of', 'by_author', 'not_found404' ], true ) ) {
			return null;
		}
		$types = $registered['singular']['sub'] ?? [];
		if ( in_array( $sub, $types, true ) ) {
			return $sub;
		}
		foreach ( $types as $type ) {
			if ( in_array( $sub, $registered[ $type ]['sub'] ?? [], true ) ) {
				return (string) $type;
			}
		}
		return null;
	}

	/** What an archive sub-condition is an archive of: terms of one taxonomy can overlap, and nothing else does. */
	private static function archive_family( string $sub ): string {
		return (string) preg_replace( [ '/^(?:any_child_of_|child_of_|in_)/', '/_children$/' ], '', $sub );
	}

	/**
	 * Includes that can never show this template. Pro prints the `single` location on
	 * singular pages and the `archive` location on archives, so a condition about the
	 * other kind of page matches nowhere it is printed.
	 *
	 * @param string[] $conditions
	 * @return string[]
	 */
	public static function conditions_that_cannot_render( string $location, array $conditions ): array {
		$wrong = [ 'single' => 'include/archive', 'archive' => 'include/singular' ][ $location ] ?? null;
		if ( null === $wrong ) {
			return [];
		}
		return array_values( array_filter( $conditions, static fn( string $c ): bool => $c === $wrong || str_starts_with( $c, $wrong . '/' ) ) );
	}

	/** A header or footer with no elements still replaces the theme's: visitors see nothing there. */
	public static function is_empty( int $template_id ): bool {
		return [] === (array) IATO_MCP_Elementor_Adapter::stored_elements( $template_id );
	}

	/**
	 * Publishing a saved template that would take the theme's header or footer off the pages
	 * it matches and put nothing there. create_elementor_template checks what it was sent.
	 *
	 * @return array|null The refusal, or null when it may be published.
	 */
	public static function empty_publish_refusal( int $template_id ): ?array {
		$type = (string) get_post_meta( $template_id, '_elementor_template_type', true );
		if ( 'header' !== $type && 'footer' !== $type ) {
			return null;
		}
		$conditions = array_filter( (array) get_post_meta( $template_id, '_elementor_conditions', true ), static fn( $c ): bool => is_string( $c ) && str_starts_with( $c, 'include/' ) );
		if ( [] === $conditions || ! self::is_empty( $template_id ) ) {
			return null;
		}
		return self::refusal(
			'empty_template',
			sprintf( "Publishing an empty %1\$s with conditions would remove the theme's %1\$s from those pages. Fill it with update_elementor_data first, then publish it.", $type )
		);
	}

	/** A popup opens on its triggers, which Elementor's editor sets, whatever its conditions say. */
	private static function popup_has_triggers( int $template_id ): bool {
		$settings = get_post_meta( $template_id, '_elementor_popup_display_settings', true );
		return is_array( $settings ) && ! empty( $settings['triggers'] );
	}

	/**
	 * What the caller should know that is no reason to refuse.
	 *
	 * @param int       $template_id 0 for one that does not exist yet.
	 * @param string[]  $conditions
	 * @param bool|null $empty       For one that does not exist yet: will it have no elements?
	 * @return string[]
	 */
	public static function warnings( int $template_id, string $location, array $conditions, string $status, ?bool $empty = null ): array {
		$warnings = [];
		foreach ( self::conditions_that_cannot_render( $location, $conditions ) as $condition ) {
			$warnings[] = sprintf(
				'The condition %1$s can never show this template: Elementor Pro prints the %2$s location only on %3$s pages.',
				$condition,
				$location,
				'single' === $location ? 'singular' : 'archive'
			);
		}
		$has_include = [] !== array_filter( $conditions, static fn( string $c ): bool => str_starts_with( $c, 'include/' ) );
		if ( 'publish' === $status && $has_include ) {
			if ( in_array( $location, [ 'header', 'footer' ], true ) && ( $empty ?? self::is_empty( $template_id ) ) ) {
				$warnings[] = sprintf( "This %1\$s is published and empty, so on the pages its conditions match it replaces the theme's %1\$s with nothing. Fill it, or set it back to draft.", $location );
			}
			if ( 'popup' === $location && ! self::popup_has_triggers( $template_id ) ) {
				$warnings[] = "This popup has no triggers, so it will not open by itself. Set when it opens in Elementor's editor.";
			}
		}
		return $warnings;
	}

	/** @return array{data_sha256:string,page_settings_sha256:string} What rollback compares to tell a template was edited. */
	public static function content_hashes( int $template_id ): array {
		return [
			'data_sha256'          => hash( 'sha256', IATO_MCP_Elementor_Adapter::stored_raw( $template_id ) ),
			'page_settings_sha256' => hash( 'sha256', (string) wp_json_encode( get_post_meta( $template_id, '_elementor_page_settings', true ) ) ),
		];
	}

	/**
	 * Is the template doing what its conditions say? From the data that decides it, not a
	 * page request, which a page cache or a blocked loopback could answer wrongly: a draft
	 * sits in Pro's cache and does not render, and a cache missing it renders nothing.
	 * A header or footer on Entire Site is also looked up on the home page, with the same
	 * logic as resolve_url.
	 *
	 * @param string[] $conditions As stored.
	 * @return array{verified:bool,verified_reason?:string,winning_template_id?:int,may_win?:list<array{template_id:int,condition:string}>}
	 */
	public static function verify( int $template_id, string $location, array $conditions ): array {
		$includes = array_filter( $conditions, static fn( string $c ): bool => str_starts_with( $c, 'include/' ) );
		if ( [] === $includes ) {
			return [ 'verified' => false, 'verified_reason' => 'no_include_condition' ];
		}
		if ( 'publish' !== get_post_status( $template_id ) ) {
			return [ 'verified' => false, 'verified_reason' => 'not_published' ];
		}
		if ( [] !== self::conditions_that_cannot_render( $location, $conditions ) ) {
			return [ 'verified' => false, 'verified_reason' => 'condition_cannot_render' ];
		}
		$is_header_or_footer = in_array( $location, [ 'header', 'footer' ], true );
		if ( $is_header_or_footer && self::is_empty( $template_id ) ) {
			return [ 'verified' => false, 'verified_reason' => 'empty_template' ];
		}
		$cached = self::get()->cached( $location );
		if ( array_values( array_map( 'strval', (array) ( $cached[ $template_id ] ?? [] ) ) ) !== array_values( $conditions ) ) {
			return [ 'verified' => false, 'verified_reason' => 'not_in_conditions_cache' ];
		}
		if ( ! $is_header_or_footer || ! in_array( 'include/general', $conditions, true ) || count( $conditions ) !== count( $includes ) ) {
			return [ 'verified' => true ];
		}

		IATO_MCP_Elementor_Router::forget();
		$home = IATO_MCP_Elementor_Router::resolve_url( home_url( '/' ) )['theme_locations'] ?? null;
		if ( isset( $home['hidden_by_page_template'] ) ) {
			return [ 'verified' => false, 'verified_reason' => 'home_page_template_hides_it' ];
		}
		$winner = $home[ $location ]['template_id'] ?? null;
		if ( $winner !== $template_id ) {
			return [ 'verified' => false, 'verified_reason' => null === $winner ? 'not_on_home_page' : 'another_template_wins' ]
				+ ( null === $winner ? [] : [ 'winning_template_id' => (int) $winner ] );
		}
		// It wins as far as the router could judge. A rival include it could not judge might beat it.
		$rivals = [];
		foreach ( (array) ( $home['not_evaluated'] ?? [] ) as $entry ) {
			if ( $location === ( $entry['location'] ?? '' ) && (int) ( $entry['template_id'] ?? 0 ) !== $template_id && str_starts_with( (string) ( $entry['condition'] ?? '' ), 'include/' ) ) {
				$rivals[] = [ 'template_id' => (int) $entry['template_id'], 'condition' => (string) $entry['condition'] ];
			}
		}
		return [] === $rivals ? [ 'verified' => true ] : [ 'verified' => false, 'verified_reason' => 'another_template_may_win', 'may_win' => $rivals ];
	}

	// ── Elementor and Pro, guarded ─────────────────────────────────────────

	private function module(): ?object {
		$module_class = self::MODULE;
		if ( ! class_exists( $module_class ) || ! method_exists( $module_class, 'instance' ) ) {
			return null;
		}
		$module = $module_class::instance();
		return is_object( $module ) ? $module : null;
	}

	private function manager(): ?object {
		$module = $this->module();
		if ( null === $module || ! method_exists( $module, 'get_conditions_manager' ) ) {
			return null;
		}
		$manager = $module->get_conditions_manager();
		return is_object( $manager ) ? $manager : null;
	}

	private function cache_object(): ?object {
		$manager = $this->manager();
		if ( null === $manager || ! method_exists( $manager, 'get_cache' ) ) {
			return null;
		}
		$cache = $manager->get_cache();
		return is_object( $cache ) ? $cache : null;
	}

	/** Why Theme Builder work cannot run here, naming the plugin; null when it can. */
	public function missing_pro(): ?string {
		$message = 'Elementor Pro is not active on this site. Theme Builder templates and display conditions need it.';
		try {
			return null === $this->manager() ? $message : null;
		} catch ( \Throwable $e ) {
			return $message;
		}
	}

	/**
	 * What the installed Elementor and Pro register for the template library.
	 *
	 * @return array<string,array{location:?string,supports_conditions:bool}>|null null without Elementor
	 */
	public function registered_types(): ?array {
		try {
			if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->documents ) ) {
				return null;
			}
			$types = [];
			foreach ( (array) \Elementor\Plugin::$instance->documents->get_document_types() as $name => $class ) {
				if ( ! is_string( $class ) || ! method_exists( $class, 'get_property' ) || ! in_array( self::LIBRARY_POST_TYPE, (array) $class::get_property( 'cpt' ), true ) ) {
					continue;
				}
				$location       = (string) $class::get_property( 'location' );
				$types[ $name ] = [ 'location' => '' === $location ? null : $location, 'supports_conditions' => (bool) $class::get_property( 'support_conditions' ) ];
			}
			return $types;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The conditions Pro registers: which sit under which, and which take an ID.
	 *
	 * @return array<string,array{sub:string[],takes_id:bool}>|null
	 */
	public function conditions_config(): ?array {
		try {
			$manager = $this->manager();
			if ( null === $manager || ! method_exists( $manager, 'get_conditions_config' ) ) {
				return null;
			}
			$config = [];
			foreach ( (array) $manager->get_conditions_config() as $name => $condition ) {
				$config[ (string) $name ] = [
					'sub'      => array_values( array_map( 'strval', (array) ( $condition['sub_conditions'] ?? [] ) ) ),
					'takes_id' => ! empty( $condition['controls'] ),
				];
			}
			return $config;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * A new draft, the way Add New Template makes one. A post Elementor left half-made
	 * goes in the trash.
	 *
	 * @param string $title Unslashed.
	 */
	public function create_document( string $type, string $title ): int|WP_Error {
		$made = 0;
		$note = static function ( $post_id ) use ( &$made ): void {
			$made = 0 === $made ? (int) $post_id : $made;
		};
		add_action( 'wp_insert_post', $note, 10, 1 );
		try {
			$document = \Elementor\Plugin::$instance->documents->create( $type, [ 'post_title' => wp_slash( $title ), 'post_status' => 'draft' ], [] );
			if ( is_wp_error( $document ) ) {
				return new WP_Error( 'template_create_failed', (string) $document->get_error_message() );
			}
			$id = (int) $document->get_main_id();
			if ( $id <= 0 || '' === (string) get_post_meta( $id, '_elementor_version', true ) ) {
				// Document::save() refused: the post has no version and no type term.
				self::trash_quietly( $id > 0 ? $id : $made );
				return new WP_Error( 'template_create_failed', 'Elementor refused to save the new template, so it was not kept.' );
			}
			return $id;
		} catch ( \Throwable $e ) {
			self::trash_quietly( $made );
			return new WP_Error( 'template_create_failed', 'Elementor could not create the template: ' . $e->getMessage() );
		} finally {
			remove_action( 'wp_insert_post', $note, 10 );
		}
	}

	private static function trash_quietly( int $post_id ): void {
		if ( $post_id > 0 ) {
			try {
				wp_trash_post( $post_id );
			} catch ( \Throwable $e ) {
				unset( $e ); // the caller already reports the failure that led here
			}
		}
	}

	/**
	 * What the write would refuse in this tree, with no post to write to: an atomic value
	 * or setting Elementor would reject. Neither check reads the post; its ID only labels
	 * the refusal. What Elementor's own Document::save() would do needs the template to exist.
	 *
	 * @return array<string,mixed>|WP_Error What the write would report.
	 */
	public function preview_tree( array $tree ): array|WP_Error {
		try {
			$check = IATO_MCP_Elementor_Atomic_Writer::prepare_tree_write( 0, null, $tree );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$refusal = IATO_MCP_Elementor_Adapter::dry_run_validation_error( 0, $check['tree'], null, $check['atomic_ids'] );
			if ( null !== $refusal ) {
				return $refusal;
			}
			$report = $check['report'] + IATO_MCP_Elementor_Adapter::sanitize_preview( $check['tree'], null );
			IATO_MCP_Sanitize_Report::add_to( $report, $check['sanitized_report'] ?? [] );
			return $report;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'template_not_stored', 'The Elementor data could not be checked: ' . $e->getMessage() );
		}
	}

	/**
	 * Fill a template through the shared Elementor write path, as update_elementor_data does.
	 *
	 * @return array<string,mixed>|WP_Error What the write reports, for the tool's response.
	 */
	public function store_tree( int $template_id, array $tree ): array|WP_Error {
		try {
			$stored = IATO_MCP_Elementor_Adapter::stored_elements( $template_id );
			$check  = IATO_MCP_Elementor_Atomic_Writer::prepare_tree_write( $template_id, $stored, $tree );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$pipeline = IATO_MCP_Elementor_Adapter::write_pipeline(
				$template_id,
				$check['tree'],
				IATO_MCP_Elementor_Adapter::compute_revision( IATO_MCP_Elementor_Adapter::stored_raw( $template_id ) ),
				$stored,
				$check['pipeline_opts']
			);
			if ( is_wp_error( $pipeline ) ) {
				return $pipeline;
			}
			if ( empty( $pipeline['meta_persisted'] ) ) {
				return new WP_Error( 'template_not_stored', 'The Elementor data was not stored.' );
			}
			$report = [ 'current_revision' => $pipeline['current_revision'] ] + $check['report'] + IATO_MCP_Elementor_Adapter::pipeline_report( $pipeline );
			IATO_MCP_Sanitize_Report::add_to( $report, $check['sanitized_report'] ?? [] );
			return $report;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'template_not_stored', 'The Elementor data could not be stored: ' . $e->getMessage() );
		}
	}

	/** The location this template renders in, or null: a section or loop item has none, and a condition on it does nothing. */
	public function location_of( int $template_id ): ?string {
		try {
			$module   = $this->module();
			$document = null !== $module && method_exists( $module, 'get_document' ) ? $module->get_document( $template_id ) : null;
			if ( ! is_object( $document ) || ! method_exists( $document, 'get_location' ) ) {
				return null;
			}
			$location = (string) $document->get_location();
			return '' === $location ? null : $location;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Can several templates show in this location on one URL? Pro's popups can. */
	public function location_allows_many( string $location ): bool {
		try {
			$module = $this->module();
			if ( null === $module || ! method_exists( $module, 'get_locations_manager' ) ) {
				return false;
			}
			$settings = $module->get_locations_manager()->get_location( $location );
			return ! empty( $settings['multiple'] );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Store conditions through Pro's manager, which also regenerates its cache. Its return
	 * value proves nothing: false also means the same value was saved again, so this reads
	 * the meta back. Pro writes the meta first and rebuilds the cache after, so a throw can
	 * leave them saved: that is cache_not_regenerated, not conditions_not_saved.
	 *
	 * @param string[] $conditions Empty removes them.
	 */
	public function save_conditions( int $template_id, array $conditions ): bool|WP_Error {
		$failure = null;
		try {
			$manager = $this->manager();
			if ( null === $manager || ! method_exists( $manager, 'save_conditions' ) ) {
				return new WP_Error( 'elementor_pro_required', (string) $this->missing_pro() );
			}
			$manager->save_conditions( $template_id, self::rules_from( $conditions ) );
		} catch ( \Throwable $e ) {
			$failure = $e->getMessage();
		}
		if ( self::stored_conditions( $template_id ) !== array_values( $conditions ) ) {
			return new WP_Error( 'conditions_not_saved', null === $failure ? 'Elementor Pro did not store these conditions on this template.' : 'Elementor Pro could not save the conditions: ' . $failure );
		}
		if ( null !== $failure ) {
			return new WP_Error( 'cache_not_regenerated', "The conditions were saved, but Elementor Pro's conditions cache could not be rebuilt: {$failure}" );
		}
		return true;
	}

	/** @return array<int,string[]> Template ID => conditions, in the order Pro's cache holds them. */
	public function cached( string $location ): array {
		try {
			$cache = $this->cache_object();
			return null !== $cache && method_exists( $cache, 'get_by_location' ) ? (array) $cache->get_by_location( $location ) : [];
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/** Rebuild Pro's cache: wp_delete_post() does not take a template out of it. */
	public function regenerate(): bool|WP_Error {
		try {
			$cache = $this->cache_object();
			if ( null === $cache || ! method_exists( $cache, 'regenerate' ) ) {
				return new WP_Error( 'elementor_pro_required', (string) $this->missing_pro() );
			}
			$cache->regenerate();
			return true;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'cache_not_regenerated', "Elementor Pro's conditions cache could not be rebuilt: " . $e->getMessage() );
		}
	}
}
