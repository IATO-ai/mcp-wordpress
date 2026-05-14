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
	 * v1.8.0 contract — purely additive over v1.7.x. Existing field semantics
	 * are preserved exactly; new fields cover "what actually renders" and the
	 * structured template details so callers don't have to brute-force IDs to
	 * find Theme Builder templates.
	 *
	 * @return array{
	 *   url:string,
	 *   rendering_post_id:?int,            // canonical/slug-based post — null (not 0) when no canonical post exists
	 *   rendering_post_type:?string,       // 'post'|'page'|<cpt>|null
	 *   rendering_template_id:?int,        // template ID when shadowing applies, else null
	 *   effective_render_id:?int,          // what actually renders — template ID when shadowed,
	 *                                      // else rendering_post_id; null ONLY on a true 404
	 *   effective_render_post_type:?string,
	 *   shadowed_post_id:?int,             // canonical post being overridden (null on archive shadowing)
	 *   shadowed_route_type:?string,       // route the URL would have had without the template
	 *   route_type:string,                 // 'page'|'category_archive'|'tag_archive'|'author_archive'|
	 *                                      //  'cpt_archive'|'theme_builder'|'404'|<post_type>
	 *   template?:array{
	 *     template_id:int,
	 *     template_type:?string,           // 'single'|'archive'|'header'|'footer'|'404'|<location>
	 *     condition_matched:?string,
	 *     builder:string,                  // 'elementor' (Layer 3 adds 'divi'/'beaver-builder')
	 *   },
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
			$result                       = self::empty_result( $url );
			$result['limited_resolution'] = true;
			$result['error']              = $e->getMessage();
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
		$result = self::empty_result( $original_url );

		// 1. Canonical (slug-based) resolution. url_to_postid returns 0 on
		//    archives and 404s; normalize to null so callers can distinguish
		//    "no canonical post" from "post ID 0" with === null.
		$raw_post_id          = url_to_postid( $original_url );
		$canonical_post_id    = $raw_post_id > 0 ? $raw_post_id : null;
		$canonical_post_type  = null;
		if ( null !== $canonical_post_id ) {
			$post = get_post( $canonical_post_id );
			if ( $post ) {
				$canonical_post_type = $post->post_type;
			}
		}

		// 2. URL-derived archive context — always populated so shadowed_route_type
		//    can be filled even when url_to_postid() returns 0. This is the fix
		//    for the bug that motivated v1.8.0: archive URLs served by a Theme
		//    Builder template silently returned route_type='404' because the
		//    conditions evaluator never reached archive/... patterns.
		$archive_info = self::detect_archive_info( $original_url );
		$url_context  = [
			'post_id'      => $canonical_post_id,
			'post_type'    => $canonical_post_type,
			'archive_kind' => $archive_info['archive_kind'] ?? null,
			'term_id'      => $archive_info['term_id']      ?? null,
			'taxonomy'     => $archive_info['taxonomy']     ?? null,
			'cpt_name'     => $archive_info['cpt_name']     ?? null,
			'author_id'    => $archive_info['author_id']    ?? null,
		];

		// 3. Pre-shadowing route_type — what the URL would resolve to without
		//    any template overriding it. Used as shadowed_route_type when
		//    shadowing applies, used as the canonical route_type when it doesn't.
		$pre_route = self::default_route_type( $canonical_post_type, $url_context['archive_kind'] );

		// 4. Populate canonical fields.
		$result['rendering_post_id']          = $canonical_post_id;
		$result['rendering_post_type']        = $canonical_post_type;
		$result['effective_render_id']        = $canonical_post_id;
		$result['effective_render_post_type'] = $canonical_post_type;
		$result['route_type']                 = $pre_route;

		// 5. Theme Builder shadowing check.
		$shadowing = self::detect_theme_builder_shadowing( $url_context );
		if ( null === $shadowing ) {
			$result['limited_resolution'] = true;
			return $result;
		}
		if ( false === $shadowing ) {
			return $result;
		}

		// 6. Shadowing applies — template renders, canonical resolution becomes the
		//    "shadowed" view. rendering_post_id semantics UNCHANGED (still the
		//    canonical post or null); effective_render_id is the template.
		$template_id                          = (int) $shadowing['template_id'];
		$result['rendering_template_id']      = $template_id;
		$result['shadowed_post_id']           = $canonical_post_id;
		$result['shadowed_route_type']        = $pre_route;
		$result['route_type']                 = 'theme_builder';
		$result['effective_render_id']        = $template_id;
		$result['effective_render_post_type'] = 'elementor_library';
		$result['template']                   = [
			'template_id'       => $template_id,
			'template_type'     => $shadowing['template_type'] ?? null,
			'condition_matched' => $shadowing['condition_matched'] ?? null,
			'builder'           => 'elementor',
		];
		return $result;
	}

	/**
	 * Empty-shell result with every contract field set to its default. The
	 * catch block in resolve_url() reuses this for the limited-resolution path.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_result( string $url ): array {
		return [
			'url'                        => $url,
			'rendering_post_id'          => null,
			'rendering_post_type'        => null,
			'rendering_template_id'      => null,
			'effective_render_id'        => null,
			'effective_render_post_type' => null,
			'shadowed_post_id'           => null,
			'shadowed_route_type'        => null,
			'route_type'                 => '404',
		];
	}

	/**
	 * Derive route_type from canonical post type or archive kind. Used as
	 * the pre-shadowing route value — assigned directly to route_type when no
	 * shadowing applies, or to shadowed_route_type when it does.
	 */
	private static function default_route_type( ?string $canonical_post_type, ?string $archive_kind ): string {
		if ( null !== $canonical_post_type ) {
			if ( 'post' === $canonical_post_type || 'page' === $canonical_post_type ) {
				return 'page';
			}
			return $canonical_post_type;
		}
		if ( null !== $archive_kind ) {
			return $archive_kind;
		}
		return '404';
	}

	/**
	 * Inspect the URL and return archive metadata. Returns null when the URL
	 * doesn't look like any recognised archive. Term / author / CPT IDs are
	 * resolved here so the condition matcher can do exact-term checks against
	 * include/archive/{taxonomy}/{term_id} patterns.
	 *
	 * Three-stage cascade (cheap → expensive):
	 *   1. Default-pattern regex (covers default-permalink sites at zero new cost).
	 *   2. Configured-base regex (handles sites that changed category_base/tag_base
	 *      via Settings > Permalinks but don't strip the prefix).
	 *   3. Reverse lookup via get_term_link() (handles Yoast strip-category-base
	 *      and any other filter-based URL rewriter; bounded segment walk).
	 *
	 * The Stage 3 reverse lookup is empirically validated by Gate-1 of v1.8.1's
	 * verification list — /build/ is the Yoast-stripped URL, so Gate-1 passing
	 * proves get_term_link() returned the stripped URL inside the REST callback.
	 *
	 * @return array{archive_kind:string,term_id?:?int,taxonomy?:?string,cpt_name?:?string,author_id?:?int}|null
	 */
	private static function detect_archive_info( string $url ): ?array {
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?? '';

		// ── Stage 1: default patterns ────────────────────────────────────────
		if ( preg_match( '#/category/([^/]+)/?$#', $path, $m ) ) {
			$term = get_term_by( 'slug', $m[1], 'category' );
			return [
				'archive_kind' => 'category_archive',
				'taxonomy'     => 'category',
				'term_id'      => ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : null,
			];
		}
		if ( preg_match( '#/tag/([^/]+)/?$#', $path, $m ) ) {
			$term = get_term_by( 'slug', $m[1], 'post_tag' );
			return [
				'archive_kind' => 'tag_archive',
				'taxonomy'     => 'post_tag',
				'term_id'      => ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : null,
			];
		}
		if ( preg_match( '#/author/([^/]+)/?$#', $path, $m ) ) {
			$user = get_user_by( 'slug', $m[1] );
			return [
				'archive_kind' => 'author_archive',
				'author_id'    => $user ? (int) $user->ID : null,
			];
		}

		// ── Stage 2: configured-base patterns ────────────────────────────────
		// Handles sites that changed Settings > Permalinks > Category base /
		// Tag base to a non-default value but did not strip it.
		$cat_base = trim( (string) get_option( 'category_base', '' ), '/' );
		if ( '' !== $cat_base && 'category' !== $cat_base ) {
			$pattern = '#/' . preg_quote( $cat_base, '#' ) . '/([^/]+)/?$#';
			if ( preg_match( $pattern, $path, $m ) ) {
				$term = get_term_by( 'slug', $m[1], 'category' );
				return [
					'archive_kind' => 'category_archive',
					'taxonomy'     => 'category',
					'term_id'      => ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : null,
				];
			}
		}
		$tag_base = trim( (string) get_option( 'tag_base', '' ), '/' );
		if ( '' !== $tag_base && 'tag' !== $tag_base ) {
			$pattern = '#/' . preg_quote( $tag_base, '#' ) . '/([^/]+)/?$#';
			if ( preg_match( $pattern, $path, $m ) ) {
				$term = get_term_by( 'slug', $m[1], 'post_tag' );
				return [
					'archive_kind' => 'tag_archive',
					'taxonomy'     => 'post_tag',
					'term_id'      => ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : null,
				];
			}
		}

		// ── Stage 3: reverse lookup via get_term_link() ──────────────────────
		// For each path segment (right-to-left, capped at 5 to bound cost),
		// try category-then-tag as a candidate slug. If get_term_link() of
		// the matched term equals the normalised input path, the URL is that
		// term's archive. get_term_link() goes through Yoast's term_link
		// filter (registered unconditionally at plugins_loaded priority 14),
		// so Yoast strip-category-base is handled automatically — we don't
		// need to detect Yoast specifically.
		//
		// REST-context premise: get_term_link()'s filter pipeline runs in REST.
		// Empirically validated by Gate-1 (/build/ → template 309). If that
		// breaks, this premise is the first hypothesis to check.
		$normalised_input = self::normalise_archive_path( $path );
		if ( '' !== $normalised_input ) {
			$segments     = explode( '/', trim( $path, '/' ) );
			$segment_cap  = 5;
			$start_index  = max( 0, count( $segments ) - $segment_cap );

			for ( $i = count( $segments ) - 1; $i >= $start_index; $i-- ) {
				$candidate_slug = $segments[ $i ];
				if ( '' === $candidate_slug ) {
					continue;
				}

				foreach ( [ 'category', 'post_tag' ] as $taxonomy ) {
					$term = get_term_by( 'slug', $candidate_slug, $taxonomy );
					if ( ! $term || is_wp_error( $term ) ) {
						continue;
					}
					$term_url = get_term_link( $term );
					if ( is_wp_error( $term_url ) || ! is_string( $term_url ) ) {
						continue;
					}
					$term_path        = wp_parse_url( $term_url, PHP_URL_PATH ) ?? '';
					$normalised_term  = self::normalise_archive_path( $term_path );
					if ( '' !== $normalised_term && $normalised_term === $normalised_input ) {
						return [
							'archive_kind' => 'category' === $taxonomy ? 'category_archive' : 'tag_archive',
							'taxonomy'     => $taxonomy,
							'term_id'      => (int) $term->term_id,
						];
					}
				}
			}

			// Author reverse lookup — only meaningful when the path looks like
			// it could be a single nicename segment.
			if ( count( $segments ) === 1 && '' !== $segments[0] ) {
				$user = get_user_by( 'slug', $segments[0] );
				if ( $user ) {
					$author_url      = get_author_posts_url( (int) $user->ID );
					$author_path     = wp_parse_url( (string) $author_url, PHP_URL_PATH ) ?? '';
					$normalised_auth = self::normalise_archive_path( $author_path );
					if ( '' !== $normalised_auth && $normalised_auth === $normalised_input ) {
						return [
							'archive_kind' => 'author_archive',
							'author_id'    => (int) $user->ID,
						];
					}
				}
			}
		}

		// ── CPT archive: top-level path segment matches a registered public CPT slug.
		$top = trim( $path, '/' );
		if ( '' !== $top && false === strpos( $top, '/' ) ) {
			$post_types = get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );
			foreach ( $post_types as $pt ) {
				if ( ! empty( $pt->has_archive ) && ( $pt->has_archive === $top || $pt->name === $top ) ) {
					return [
						'archive_kind' => 'cpt_archive',
						'cpt_name'     => (string) $pt->name,
					];
				}
			}
		}
		return null;
	}

	/**
	 * Normalise a URL path for archive comparison. Strips leading and
	 * trailing slashes so equivalent paths compare equal regardless of
	 * trailing-slash style. Returns '' for the root path.
	 */
	private static function normalise_archive_path( string $path ): string {
		return trim( $path, '/' );
	}

	/**
	 * @param array<string,mixed> $ctx URL-derived context.
	 * @return array{template_id:int,template_type:?string,condition_matched:?string}|false|null
	 */
	private static function detect_theme_builder_shadowing( array $ctx ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		// Preferred path: Elementor Pro's Theme_Builder Locations API.
		// Return only on a positive match (array). The module's `false` return
		// means "Pro loaded but get_documents_for_location matched nothing" —
		// which is unreliable in REST context, because that method is bound to
		// the current $wp_query (the REST endpoint, not the URL we're asking
		// about). Treating `false` as authoritative would skip the conditions
		// scan that DOES evaluate against our explicit URL context. Both
		// `false` and `null` (Pro class not loaded) fall through to the meta
		// scan below.
		$found = self::find_via_theme_builder_module( $ctx );
		if ( is_array( $found ) ) {
			return $found;
		}

		// Fallback: scan _elementor_conditions meta directly.
		return self::find_via_conditions_meta( $ctx );
	}

	/**
	 * Modern path through Elementor's Theme_Builder module.
	 *
	 * @return array{template_id:int,template_type:?string,condition_matched:?string}|false|null
	 */
	private static function find_via_theme_builder_module( array $ctx ) {
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

		$post_id = $ctx['post_id'];
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
						return [
							'template_id'       => $tid,
							'template_type'     => $location,
							'condition_matched' => null,
						];
					}
				}
			}
		}
		return false;
	}

	/**
	 * Fallback: scan elementor_library posts and parse _elementor_conditions
	 * meta directly. Handles include/general, include/singular/..., and
	 * include/archive/... patterns; honors exclude/... by skipping the
	 * matched template entirely (conservative; Elementor's real semantics
	 * are more nuanced but this is the safer fallback when the module
	 * isn't loaded).
	 *
	 * @return array{template_id:int,template_type:?string,condition_matched:?string}|false|null
	 */
	private static function find_via_conditions_meta( array $ctx ) {
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
				[ 'key' => '_elementor_conditions', 'compare' => 'EXISTS' ],
			],
		] );
		if ( empty( $templates ) ) {
			return false;
		}

		$best_tid       = null;
		$best_priority  = PHP_INT_MAX;
		$best_condition = null;
		$best_type      = null;

		foreach ( $templates as $tid ) {
			$tid = (int) $tid;
			if ( null !== $ctx['post_id'] && $tid === $ctx['post_id'] ) {
				continue;
			}
			$conditions = get_post_meta( $tid, '_elementor_conditions', true );
			if ( ! is_array( $conditions ) ) {
				continue;
			}

			// First pass: any matching exclude/... kills this template entirely.
			$excluded = false;
			foreach ( $conditions as $condition_string ) {
				if ( self::condition_excludes_context( (string) $condition_string, $ctx ) ) {
					$excluded = true;
					break;
				}
			}
			if ( $excluded ) {
				continue;
			}

			// Second pass: find the best include match for this template.
			foreach ( $conditions as $condition_string ) {
				$cs       = (string) $condition_string;
				$priority = self::condition_matches_context( $cs, $ctx );
				if ( null === $priority ) {
					continue;
				}
				if ( $priority < $best_priority ) {
					$best_priority  = $priority;
					$best_tid       = $tid;
					$best_condition = $cs;
					$best_type      = self::infer_template_type( $tid, $cs );
				}
			}
		}

		if ( null === $best_tid ) {
			return false;
		}
		return [
			'template_id'       => $best_tid,
			'template_type'     => $best_type,
			'condition_matched' => $best_condition,
		];
	}

	/**
	 * Best-effort inference of the template's location ('single'|'archive'|
	 * 'header'|'footer'|'404'|<location>|null). Prefers the
	 * _elementor_template_type meta, falls back to the matched condition's
	 * scope.
	 */
	private static function infer_template_type( int $template_id, string $matched_condition ): ?string {
		$meta = get_post_meta( $template_id, '_elementor_template_type', true );
		if ( is_string( $meta ) && '' !== $meta ) {
			return $meta;
		}
		$parts = explode( '/', $matched_condition );
		$scope = $parts[1] ?? '';
		if ( 'singular' === $scope ) {
			return 'single';
		}
		if ( 'archive' === $scope ) {
			return 'archive';
		}
		return null;
	}

	/**
	 * Does this condition string exclude the current context? Treats
	 * 'exclude/X' as 'would the matching include/X fire'. Conservative model;
	 * the real Elementor evaluator is more nuanced but this is the safer
	 * fallback when the module isn't loaded.
	 */
	private static function condition_excludes_context( string $condition, array $ctx ): bool {
		$parts = explode( '/', $condition );
		if ( ( $parts[0] ?? '' ) !== 'exclude' ) {
			return false;
		}
		$parts[0]   = 'include';
		$as_include = implode( '/', $parts );
		return null !== self::condition_matches_context( $as_include, $ctx );
	}

	/**
	 * Evaluate one include/... condition against the URL context. Returns
	 * priority (lower = more specific) on match, null otherwise. Excludes
	 * are handled separately by condition_excludes_context.
	 *
	 * Supported patterns and priorities:
	 *   include/general                                → 100
	 *   include/singular                               → 80
	 *   include/singular/{post_type}                   → 60
	 *   include/singular/{post_type}/{post_id}         → 20
	 *   include/archive                                → 90
	 *   include/archive/{taxonomy}                     → 70
	 *   include/archive/{taxonomy}/{term_id}           → 30
	 *   include/archive/in_taxonomy/{term_id}          → 30
	 *   include/archive/by_author                      → 80
	 *   include/archive/by_author/{author_id}          → 40
	 *   include/archive/post_archive                   → 70
	 *   include/archive/{cpt}_archive                  → 50
	 */
	private static function condition_matches_context( string $condition, array $ctx ): ?int {
		$parts = explode( '/', $condition );
		$mode  = $parts[0] ?? '';
		if ( 'include' !== $mode ) {
			return null;
		}
		$scope = $parts[1] ?? '';
		switch ( $scope ) {
			case 'general':
				return 100;

			case 'singular':
				if ( null === $ctx['post_id'] ) {
					return null;
				}
				if ( ! isset( $parts[2] ) ) {
					return 80;
				}
				if ( $ctx['post_type'] !== $parts[2] ) {
					return null;
				}
				if ( ! isset( $parts[3] ) ) {
					return 60;
				}
				return ( (int) $parts[3] === (int) $ctx['post_id'] ) ? 20 : null;

			case 'archive':
				return self::match_archive_condition( $parts, $ctx );

			default:
				return null;
		}
	}

	/**
	 * Match one include/archive/... pattern against the URL context.
	 *
	 * @param array<int,string> $parts Exploded condition string.
	 * @param array<string,mixed> $ctx URL context.
	 */
	private static function match_archive_condition( array $parts, array $ctx ): ?int {
		// parts[1] === 'archive' already established by caller.
		if ( ! isset( $parts[2] ) ) {
			return null === $ctx['archive_kind'] ? null : 90;
		}
		$subscope = $parts[2];

		// by_author / by_author/{id}
		if ( 'by_author' === $subscope ) {
			if ( 'author_archive' !== $ctx['archive_kind'] ) {
				return null;
			}
			if ( ! isset( $parts[3] ) ) {
				return 80;
			}
			if ( null === $ctx['author_id'] ) {
				return null;
			}
			return ( (int) $parts[3] === (int) $ctx['author_id'] ) ? 40 : null;
		}

		// in_taxonomy/{term_id} — taxonomy-agnostic term match.
		if ( 'in_taxonomy' === $subscope ) {
			if ( null === $ctx['term_id'] || ! isset( $parts[3] ) ) {
				return null;
			}
			return ( (int) $parts[3] === (int) $ctx['term_id'] ) ? 30 : null;
		}

		// post_archive — the WordPress blog index.
		if ( 'post_archive' === $subscope ) {
			return null === $ctx['archive_kind'] ? 70 : null;
		}

		// {cpt}_archive — CPT-specific archive.
		if ( substr( $subscope, -8 ) === '_archive' ) {
			if ( 'cpt_archive' !== $ctx['archive_kind'] ) {
				return null;
			}
			$cpt = substr( $subscope, 0, -8 );
			return ( $cpt === $ctx['cpt_name'] ) ? 50 : null;
		}

		// Otherwise treat parts[2] as a taxonomy slug.
		if ( null === $ctx['taxonomy'] || $subscope !== $ctx['taxonomy'] ) {
			return null;
		}
		if ( ! isset( $parts[3] ) ) {
			return 70;
		}
		if ( null === $ctx['term_id'] ) {
			return null;
		}
		return ( (int) $parts[3] === (int) $ctx['term_id'] ) ? 30 : null;
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
