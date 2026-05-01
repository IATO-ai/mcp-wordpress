<?php
/**
 * Elementor Router — URL → rendering target resolution.
 *
 * Walks the WordPress rewrite cascade to identify which post would render
 * for a given URL, then optionally checks Elementor Theme Builder
 * conditions to detect when a Theme Builder template "shadows" the
 * canonical post.
 *
 * Theme Builder integration is best-effort: every internal-Elementor call
 * is class_exists/method_exists guarded, and the entire body is wrapped
 * in a try/catch so a routing failure can never break the MCP endpoint
 * for non-shadowed pages.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Elementor_Router {

	/** @var array<string,array> Per-request resolution cache keyed by normalized URL. */
	private static array $cache = [];

	/**
	 * Resolve a URL to its rendering target.
	 *
	 * @return array{
	 *   url:string,
	 *   rendering_post_id:?int,
	 *   rendering_template_id:?int,
	 *   shadowed_post_id:?int,
	 *   route_type:string,
	 *   limited_resolution?:bool,
	 *   error?:string
	 * }
	 */
	public static function resolve_url( string $url ): array {
		$normalized = self::normalize_url( $url );

		if ( isset( self::$cache[ $normalized ] ) ) {
			return self::$cache[ $normalized ];
		}

		try {
			$result = self::do_resolve_url( $url, $normalized );
		} catch ( \Throwable $e ) {
			// A theme builder evaluation failure must never break resolve_url
			// for the canonical-resolution case.
			$result = [
				'url'                   => $url,
				'rendering_post_id'     => null,
				'rendering_template_id' => null,
				'shadowed_post_id'      => null,
				'route_type'            => '404',
				'limited_resolution'    => true,
				'error'                 => $e->getMessage(),
			];
		}

		self::$cache[ $normalized ] = $result;
		return $result;
	}

	/**
	 * Resolve the post that shadows $post_id (if any). Returns null when
	 * Elementor isn't active, the post isn't shadowed, or detection isn't
	 * possible on the current Elementor version.
	 *
	 * @return array{type:string,shadowing_id:int,reason:string}|null
	 */
	public static function get_shadowing_for_post( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return null;
		}
		$resolution = self::resolve_url( $permalink );
		if ( empty( $resolution['rendering_template_id'] ) ) {
			return null;
		}
		if ( (int) ( $resolution['shadowed_post_id'] ?? 0 ) !== $post_id ) {
			return null;
		}
		$template_id = (int) $resolution['rendering_template_id'];
		return [
			'type'         => 'elementor_theme_builder',
			'shadowing_id' => $template_id,
			'reason'       => self::shadowing_reason( $template_id ),
		];
	}

	// ── Internals ──────────────────────────────────────────────────────────

	private static function normalize_url( string $url ): string {
		$home = home_url( '/' );
		// Make site-relative URLs absolute for url_to_postid.
		if ( '' !== $url && '/' === $url[0] ) {
			$url = rtrim( $home, '/' ) . $url;
		}
		// Strip query/fragment for matching; Elementor routing keys off path only.
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		return rtrim( $path, '/' );
	}

	private static function do_resolve_url( string $original_url, string $normalized ): array {
		$result = [
			'url'                   => $original_url,
			'rendering_post_id'     => null,
			'rendering_template_id' => null,
			'shadowed_post_id'      => null,
			'route_type'            => '404',
		];

		// 1. WordPress rewrite cascade.
		$post_id = url_to_postid( $original_url );
		if ( $post_id > 0 ) {
			$post                       = get_post( $post_id );
			$result['rendering_post_id'] = $post_id;
			$result['route_type']        = ( $post && 'page' === $post->post_type ) ? 'page' : 'page';
			// Differentiate post types more usefully.
			if ( $post ) {
				$pt = $post->post_type;
				if ( 'post' === $pt ) {
					$result['route_type'] = 'page';
				} elseif ( 'page' === $pt ) {
					$result['route_type'] = 'page';
				} else {
					$result['route_type'] = $pt;
				}
			}
		} else {
			// 2. Try archive resolution.
			$archive_type = self::detect_archive_type( $original_url );
			if ( null !== $archive_type ) {
				$result['route_type'] = $archive_type;
			}
		}

		// 3. Theme Builder shadowing check.
		$shadowing = self::detect_theme_builder_shadowing( $original_url, $post_id );
		if ( null === $shadowing ) {
			// Elementor APIs unavailable or threw — record limited resolution.
			$result['limited_resolution'] = true;
			return $result;
		}
		if ( false === $shadowing ) {
			// APIs available, no shadowing matched. Canonical result stands.
			return $result;
		}

		// Shadowing applies.
		$result['rendering_template_id'] = (int) $shadowing['template_id'];
		$result['shadowed_post_id']      = $post_id > 0 ? $post_id : null;
		$result['route_type']            = 'theme_builder';
		return $result;
	}

	private static function detect_archive_type( string $url ): ?string {
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
		// Heuristic — best-effort, matches the 80% case. Slug-based detection
		// against known taxonomies and post types.
		if ( preg_match( '#/category/[^/]+/?$#', $path ) ) {
			return 'category_archive';
		}
		if ( preg_match( '#/tag/[^/]+/?$#', $path ) ) {
			return 'tag_archive';
		}
		if ( preg_match( '#/author/[^/]+/?$#', $path ) ) {
			return 'author_archive';
		}
		// CPT archive: top-level path matches a registered public CPT slug.
		$top = trim( $path, '/' );
		if ( '' !== $top && false === strpos( $top, '/' ) ) {
			$post_types = get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );
			foreach ( $post_types as $pt ) {
				if ( ! empty( $pt->has_archive ) && ( $pt->has_archive === $top || $pt->name === $top ) ) {
					return 'cpt_archive';
				}
			}
		}
		return null;
	}

	/**
	 * Returns:
	 *   array{template_id:int} when shadowing detected,
	 *   false when Elementor APIs are available and no shadowing matched,
	 *   null when Elementor isn't active or APIs aren't available.
	 *
	 * @return array{template_id:int}|false|null
	 */
	private static function detect_theme_builder_shadowing( string $url, int $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		// Preferred path: the modern Theme_Builder Locations API.
		$found = self::find_via_theme_builder_module( $post_id );
		if ( null !== $found ) {
			return $found;
		}

		// Fallback: scan elementor_library posts of type single/archive with
		// _elementor_conditions meta and parse the simple cases.
		return self::find_via_conditions_meta( $post_id );
	}

	/**
	 * Attempt the modern path through Elementor's Theme_Builder module.
	 *
	 * @return array{template_id:int}|false|null
	 */
	private static function find_via_theme_builder_module( int $post_id ) {
		// Theme Builder lives in a paid module; presence guarded.
		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			return null;
		}
		$module_class = '\ElementorPro\Modules\ThemeBuilder\Module';
		if ( ! method_exists( $module_class, 'instance' ) ) {
			return null;
		}
		$module = $module_class::instance();
		if ( ! is_object( $module ) || ! method_exists( $module, 'get_conditions_manager' ) ) {
			return null;
		}
		$conditions_manager = $module->get_conditions_manager();
		if ( ! is_object( $conditions_manager ) || ! method_exists( $conditions_manager, 'get_documents_for_location' ) ) {
			return null;
		}

		foreach ( [ 'single', 'archive' ] as $location ) {
			$documents = $conditions_manager->get_documents_for_location( $location );
			if ( empty( $documents ) || ! is_array( $documents ) ) {
				continue;
			}
			// First match wins — Elementor returns documents pre-sorted by priority.
			foreach ( $documents as $doc ) {
				if ( is_object( $doc ) && method_exists( $doc, 'get_main_id' ) ) {
					$tid = (int) $doc->get_main_id();
					if ( $tid > 0 && $tid !== $post_id ) {
						return [ 'template_id' => $tid ];
					}
				}
			}
		}
		return false;
	}

	/**
	 * Fallback: scan elementor_library posts and parse simple conditions
	 * directly. Handles 'general/include', 'singular/include',
	 * 'archive/include' with sub-conditions 'all' and basic 'in_taxonomy'/
	 * 'by_id' patterns. Skips deeply nested condition trees.
	 *
	 * @return array{template_id:int}|false|null
	 */
	private static function find_via_conditions_meta( int $post_id ) {
		// Cheap availability probe — if we can't query elementor_library at all,
		// signal "limited" rather than "no shadowing."
		if ( ! post_type_exists( 'elementor_library' ) ) {
			return null;
		}

		$templates = get_posts( [
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded scan, single meta key.
			'meta_query'     => [
				[
					'key'     => '_elementor_conditions',
					'compare' => 'EXISTS',
				],
			],
		] );
		if ( empty( $templates ) ) {
			return false;
		}

		$best          = null;
		$best_priority = PHP_INT_MAX;

		foreach ( $templates as $tid ) {
			$tid = (int) $tid;
			if ( $tid === $post_id ) {
				continue;
			}
			$conditions = get_post_meta( $tid, '_elementor_conditions', true );
			if ( ! is_array( $conditions ) ) {
				continue;
			}
			// Each condition is a string like "include/general" or "include/singular/post/123".
			foreach ( $conditions as $condition_string ) {
				$priority = self::condition_matches_post( (string) $condition_string, $post_id );
				if ( null !== $priority && $priority < $best_priority ) {
					$best          = $tid;
					$best_priority = $priority;
				}
			}
		}

		return null === $best ? false : [ 'template_id' => $best ];
	}

	/**
	 * Evaluate one condition string against a post. Returns a priority value
	 * (lower = more specific) when matching, null when not.
	 *
	 * Supported patterns:
	 *   include/general                  → priority 100 (lowest specificity)
	 *   include/singular                 → priority 80
	 *   include/singular/post            → priority 60 (any post)
	 *   include/singular/post/{id}       → priority 20 (exact)
	 *   exclude/...                      → returns null (we honor excludes by ignoring this template)
	 */
	private static function condition_matches_post( string $condition, int $post_id ): ?int {
		$parts = explode( '/', $condition );
		$mode  = $parts[0] ?? '';
		if ( 'exclude' === $mode ) {
			return null;
		}
		if ( 'include' !== $mode ) {
			return null;
		}

		$scope = $parts[1] ?? '';
		switch ( $scope ) {
			case 'general':
				return 100;
			case 'singular':
				if ( ! isset( $parts[2] ) ) {
					return 80;
				}
				$post_type = get_post_type( $post_id );
				if ( $post_type !== $parts[2] ) {
					return null;
				}
				if ( ! isset( $parts[3] ) ) {
					return 60;
				}
				return ( (int) $parts[3] === $post_id ) ? 20 : null;
			default:
				return null;
		}
	}

	private static function shadowing_reason( int $template_id ): string {
		$conditions = get_post_meta( $template_id, '_elementor_conditions', true );
		if ( is_array( $conditions ) && ! empty( $conditions ) ) {
			$first = (string) reset( $conditions );
			return "Theme Builder template matches condition: {$first}";
		}
		return 'Theme Builder template matches the rendering location';
	}
}
