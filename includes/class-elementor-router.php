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
	 * Template types that replace what a URL shows (1.17.0): the types whose Elementor
	 * Pro document has the location `single` or `archive`. A header, footer, popup or
	 * section also carries display conditions, and one on Entire Site matches every
	 * URL, but it renders around or over the page, not instead of it.
	 */
	private const CONTENT_TEMPLATE_TYPES = [
		'single',
		'single-post',
		'single-page',
		'error-404',
		'archive',
		'search-results',
		'product',
		'product-post',
		'product-archive',
	];

	/** The Theme Builder locations theme_locations answers for (1.17.0). */
	private const THEME_LOCATIONS = [ 'header', 'footer' ];

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
	 *                                      //  'cpt_archive'|'taxonomy_archive'|'date_archive'|'search_results'|
	 *                                      //  'blog_home'|'theme_builder'|'404'|<post_type>
	 *   template?:array{
	 *     template_id:int,
	 *     template_type:?string,           // 'single'|'archive'|'header'|'footer'|'404'|<location>
	 *     condition_matched:?string,
	 *     builder:string,                  // 'elementor' (Layer 3 adds 'divi'/'beaver-builder')
	 *   },
	 *   theme_locations:?array{            // 1.17.0; null without Elementor Pro's Theme Builder
	 *     header:?array{template_id:int,condition_matched:string},
	 *     footer:?array{template_id:int,condition_matched:string},
	 *     not_evaluated?:list<array{location:string,template_id:int,condition:string}>,
	 *     hidden_by_page_template?:string, // the page's template shows no header or footer: both are null
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

	/** Forget what this request resolved: a write has just changed the answer (1.17.0). */
	public static function forget(): void {
		self::$cache = [];
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

		// 1.17.0: what WordPress makes of this URL. url_to_postid() drops the query and
		// answers with the static front page for /?cat=7 or /?s=shoes, and with whatever
		// ID it is given for /?p=999, so a post is only what renders where this says so.
		$url_context = self::with_url_kind( $original_url, $url_context );
		if ( in_array( $url_context['kind'], [ 'archive', 'date', 'search', '404', 'other_host' ], true ) ) {
			$canonical_post_id        = null;
			$canonical_post_type      = null;
			$url_context['post_id']   = null;
			$url_context['post_type'] = null;
		}

		// Headers and footers render around whatever step 5 finds, so this is
		// answered either way.
		$result['theme_locations'] = self::theme_locations( $url_context );

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
			'theme_locations'            => null,
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
		if ( 'other_host' === $ctx['kind'] ) {
			return false; // nothing on this site renders that URL
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
			// 1.17.0: a header on Entire Site matches every URL and shadows none. A
			// template with no recorded type is judged by its conditions, as before.
			$type = get_post_meta( $tid, '_elementor_template_type', true );
			if ( is_string( $type ) && '' !== $type && ! in_array( $type, self::CONTENT_TEMPLATE_TYPES, true ) ) {
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
	 *   include/singular/front_page                    → 15
	 *   include/archive                                → 90
	 *   include/archive/{taxonomy}                     → 70
	 *   include/archive/{taxonomy}/{term_id}           → 30
	 *   include/archive/in_taxonomy/{term_id}          → 30
	 *   include/archive/author, by_author              → 80
	 *   include/archive/author/{id}, by_author/{id}    → 40
	 *   include/archive/post_archive                   → 70
	 *   include/archive/date, search                   → 70
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
				// Pro's Singular condition: is_singular(), or a not-found page. Having a post
				// ID is not enough: the posts page has one and is the blog.
				if ( 'singular' !== $ctx['kind'] && '404' !== $ctx['kind'] ) {
					return null;
				}
				if ( ! isset( $parts[2] ) ) {
					return 80;
				}
				if ( 'not_found404' === $parts[2] ) {
					return '404' === $ctx['kind'] ? 10 : null;
				}
				if ( '404' === $ctx['kind'] ) {
					return null; // every other sub-condition is about a post
				}
				// A sub-condition of singular in Pro, not a post type.
				if ( 'front_page' === $parts[2] ) {
					return $ctx['is_front'] ? 15 : null;
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
		$kind = $ctx['kind'];
		if ( ! isset( $parts[2] ) ) {
			// Pro's Archive condition: is_archive() || is_home() || is_search().
			return in_array( $kind, [ 'archive', 'blog', 'date', 'search' ], true ) ? 90 : null;
		}
		$subscope = $parts[2];

		// author is what Pro stores; by_author is what this code first understood.
		if ( 'author' === $subscope || 'by_author' === $subscope ) {
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

		// post_archive — the WordPress blog index, and nothing else: not an ordinary page.
		if ( 'post_archive' === $subscope ) {
			return 'blog' === $kind ? 70 : null;
		}

		if ( 'date' === $subscope || 'search' === $subscope ) {
			return $subscope === $kind ? 70 : null;
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

	// ── theme_locations (1.17.0) ───────────────────────────────────────────

	/**
	 * Which header and footer this URL gets. Elementor Pro's own answer
	 * (get_documents_for_location()) is about the current request, here the REST call,
	 * not the URL asked about, so its rule is applied to the URL instead:
	 * Conditions_Manager::get_location_templates() in Pro 4.3.3. That rule reads Pro's
	 * conditions cache rather than the templates' meta, because the cache decides what
	 * renders: a template whose conditions did not reach it is rightly reported as absent.
	 *
	 * @param array<string,mixed> $ctx URL-derived context, with the URL's kind.
	 * @return array<string,mixed>|null null for a URL on another host, or when the Theme
	 *                                  Builder is not there to ask; a failure here must not
	 *                                  cost the rest of the answer.
	 */
	private static function theme_locations( array $ctx ): ?array {
		try {
			if ( 'other_host' === $ctx['kind'] ) {
				return null;
			}
			$cache = self::theme_builder_cache();
			if ( null === $cache ) {
				return null;
			}
			// Elementor Canvas prints no header or footer, whatever the conditions say.
			$hidden = self::page_template_hiding_locations( $ctx );
			if ( null !== $hidden ) {
				return [ 'header' => null, 'footer' => null, 'hidden_by_page_template' => $hidden ];
			}
			$locations     = [];
			$not_evaluated = [];
			foreach ( self::THEME_LOCATIONS as $location ) {
				$locations[ $location ] = self::location_template( $location, (array) $cache->get_by_location( $location ), $ctx, $not_evaluated );
			}
			if ( [] !== $not_evaluated ) {
				$locations['not_evaluated'] = $not_evaluated;
			}
			return $locations;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The page template that keeps a header and footer off this URL, or null. A page
	 * template is chosen as Elementor's PageTemplates module chooses it: the page's own,
	 * or for a page built with Elementor that named neither Elementor's nor the theme's,
	 * the kit's default. Pro drops its header and footer from Canvas
	 * (Locations_Manager::filter_page_template_locations()), and canvas.php never prints
	 * the theme's. elementor_header_footer keeps them.
	 *
	 * @param array<string,mixed> $ctx URL-derived context, with the URL's kind.
	 */
	private static function page_template_hiding_locations( array $ctx ): ?string {
		$post_id = (int) ( $ctx['post_id'] ?? 0 );
		if ( 'singular' !== $ctx['kind'] || $post_id <= 0 ) {
			return null;
		}
		$template = (string) get_post_meta( $post_id, '_wp_page_template', true );
		if ( 'elementor_canvas' === $template ) {
			return $template;
		}
		if ( in_array( $template, [ 'elementor_theme', 'elementor_header_footer' ], true ) || 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return null;
		}
		try {
			$kits = \Elementor\Plugin::$instance->kits_manager ?? null;
			return is_object( $kits ) && method_exists( $kits, 'get_current_settings' ) && 'elementor_canvas' === $kits->get_current_settings( 'default_page_template' ) ? 'elementor_canvas' : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Pro's conditions cache, or null when Pro's Theme Builder is not loaded. */
	private static function theme_builder_cache(): ?object {
		$module_class = '\ElementorPro\Modules\ThemeBuilder\Module';
		if ( ! class_exists( $module_class ) || ! method_exists( $module_class, 'instance' ) ) {
			return null;
		}
		$module = $module_class::instance();
		if ( ! is_object( $module ) || ! method_exists( $module, 'get_conditions_manager' ) ) {
			return null;
		}
		$manager = $module->get_conditions_manager();
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_cache' ) ) {
			return null;
		}
		$cache = $manager->get_cache();
		return is_object( $cache ) && method_exists( $cache, 'get_by_location' ) ? $cache : null;
	}

	/**
	 * The template that wins a location for this URL, as Pro decides it: published
	 * only (its cache holds drafts too); any matching exclude removes the template;
	 * of a template's matching includes the last one sets its priority; the lowest
	 * priority wins, and a tie goes to whichever comes first in the cache.
	 *
	 * @param array<int,mixed>    $templates     Template ID => its condition strings.
	 * @param array<string,mixed> $ctx           URL-derived context with the URL's kind.
	 * @param list<array>         $not_evaluated Conditions this code could not judge.
	 * @return array{template_id:int,condition_matched:string}|null
	 */
	private static function location_template( string $location, array $templates, array $ctx, array &$not_evaluated ): ?array {
		$best          = null;
		$best_priority = PHP_INT_MAX;
		foreach ( $templates as $template_id => $conditions ) {
			$template_id = (int) $template_id;
			if ( 'publish' !== get_post_status( $template_id ) ) {
				continue;
			}
			$priority = null;
			$matched  = '';
			$excluded = false;
			foreach ( (array) $conditions as $condition ) {
				$condition = (string) $condition;
				$outcome   = self::location_condition( $condition, $ctx );
				if ( null === $outcome ) {
					$not_evaluated[] = [ 'location' => $location, 'template_id' => $template_id, 'condition' => $condition ];
				} elseif ( false !== $outcome ) {
					if ( str_starts_with( $condition, 'exclude/' ) ) {
						$excluded = true;
					} else {
						$priority = $outcome;
						$matched  = $condition;
					}
				}
			}
			if ( ! $excluded && null !== $priority && $priority < $best_priority ) {
				$best_priority = $priority;
				$best          = [ 'template_id' => $template_id, 'condition_matched' => $matched ];
			}
		}
		return $best;
	}

	/**
	 * What kind of page this URL is, in the terms Pro's conditions use, decided the way
	 * WordPress decides it for a visitor: its rewrite rules and query variables go through
	 * WP_Query::parse_query(), and what a request would answer with a 404 is a 404
	 * (WP::handle_404()). The answers: singular, blog, archive, date, search, 404,
	 * other_host, or unknown when this cannot tell, which never gets a confident answer:
	 * conditions that depend on it go under not_evaluated. The archive fields of $ctx are
	 * replaced with what WordPress found.
	 *
	 * The path guessing in detect_archive_info() stays for one thing: a URL WordPress's
	 * rules read as not found which a plugin's term or author link really produces.
	 *
	 * @param array<string,mixed> $ctx URL-derived context, with detect_archive_info()'s guesses.
	 * @return array<string,mixed> The same, plus kind and is_front.
	 */
	private static function with_url_kind( string $url, array $ctx ): array {
		try {
			$view = self::wordpress_view( $url, (int) ( $ctx['post_id'] ?? 0 ) );
		} catch ( \Throwable $e ) {
			$view = [ 'kind' => 'unknown' ];
		}
		$guessed = null !== $ctx['term_id'] || null !== $ctx['author_id'] || null !== $ctx['cpt_name'];
		if ( '404' === $view['kind'] && $guessed ) {
			$ctx['kind']     = 'archive';
			$ctx['is_front'] = false;
			return $ctx;
		}
		$ctx['kind']         = $view['kind'];
		$ctx['is_front']     = ! empty( $view['is_front'] );
		$ctx['archive_kind'] = $view['archive_kind'] ?? null;
		$ctx['term_id']      = $view['term_id'] ?? null;
		$ctx['taxonomy']     = $view['taxonomy'] ?? null;
		$ctx['cpt_name']     = $view['cpt_name'] ?? null;
		$ctx['author_id']    = $view['author_id'] ?? null;
		return $ctx;
	}

	/**
	 * @param int $post_id What url_to_postid() answered, 0 for nothing.
	 * @return array{kind:string,is_front?:bool,archive_kind?:string,term_id?:int,taxonomy?:string,cpt_name?:string,author_id?:int}
	 */
	private static function wordpress_view( string $url, int $post_id ): array {
		$parts = wp_parse_url( $url );
		$parts = is_array( $parts ) ? $parts : [];
		if ( ! self::is_this_host( (string) ( $parts['host'] ?? '' ) ) ) {
			return [ 'kind' => 'other_host' ];
		}
		$vars = self::query_vars_of( $parts );
		if ( null === $vars ) {
			return [ 'kind' => 'unknown' ];
		}

		$query = new \WP_Query();
		$query->parse_query( $vars );
		$archive_flags = array_filter( [ $query->is_category, $query->is_tag, $query->is_tax, $query->is_author, $query->is_date, $query->is_post_type_archive, $query->is_search ] );
		if ( $query->is_feed || $query->is_embed || $query->is_trackback || $query->is_robots || $query->is_favicon || ! empty( $query->is_sitemap ) || count( $archive_flags ) > 1 ) {
			return [ 'kind' => 'unknown' ];
		}

		if ( $query->is_singular ) {
			if ( $post_id <= 0 || ! get_post( $post_id ) ) {
				// url_to_postid() ran these rules and found nothing. It does not read a post
				// named in the query string, so that is not a 404 this can vouch for.
				parse_str( (string) ( $parts['query'] ?? '' ), $given );
				return [ 'kind' => [] !== array_intersect_key( $given, array_flip( [ 'name', 'pagename', 'attachment', 'subpost', 'subpost_id' ] ) ) ? 'unknown' : '404' ];
			}
			// WooCommerce turns its shop page into a product archive in its own query filter,
			// and Pro hands that page to WooCommerce's conditions.
			if ( function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) === $post_id ) {
				return [ 'kind' => 'unknown' ];
			}
			return [ 'kind' => 'singular', 'is_front' => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post_id ];
		}

		// What a request would answer with a 404: a page past the end, or a date with no posts.
		if ( ( $query->is_paged || $query->is_date ) && ! self::has_posts( $vars ) ) {
			return [ 'kind' => '404' ];
		}
		if ( $query->is_home || $query->is_posts_page ) {
			return [ 'kind' => 'blog', 'archive_kind' => 'blog_home' ];
		}
		if ( $query->is_search ) {
			return [ 'kind' => 'search', 'archive_kind' => 'search_results' ];
		}
		if ( $query->is_date ) {
			return [ 'kind' => 'date', 'archive_kind' => 'date_archive' ];
		}
		$object = $query->get_queried_object();
		if ( $query->is_category || $query->is_tag || $query->is_tax ) {
			if ( ! is_object( $object ) ) {
				return [ 'kind' => '404' ]; // a term that does not exist
			}
			$taxonomy = (string) $object->taxonomy;
			if ( class_exists( 'WooCommerce' ) && ( str_starts_with( $taxonomy, 'product_' ) || str_starts_with( $taxonomy, 'pa_' ) ) ) {
				return [ 'kind' => 'unknown' ];
			}
			return [
				'kind'         => 'archive',
				'archive_kind' => $query->is_category ? 'category_archive' : ( $query->is_tag ? 'tag_archive' : 'taxonomy_archive' ),
				'taxonomy'     => $taxonomy,
				'term_id'      => (int) $object->term_id,
			];
		}
		if ( $query->is_author ) {
			return is_object( $object ) ? [ 'kind' => 'archive', 'archive_kind' => 'author_archive', 'author_id' => (int) $object->ID ] : [ 'kind' => '404' ];
		}
		if ( $query->is_post_type_archive ) {
			if ( ! is_object( $object ) || ( class_exists( 'WooCommerce' ) && 'product' === $object->name ) ) {
				return [ 'kind' => 'unknown' ];
			}
			return [ 'kind' => 'archive', 'archive_kind' => 'cpt_archive', 'cpt_name' => (string) $object->name ];
		}
		return [ 'kind' => 'unknown' ];
	}

	/** Is this the site's host? No host means a relative URL. A leading www. is optional, as in url_to_postid(). */
	private static function is_this_host( string $host ): bool {
		$plain = static fn( string $h ): string => (string) preg_replace( '/^www\./', '', strtolower( $h ) );
		return '' === $host || $plain( $host ) === $plain( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * The query variables WP::parse_request() would arrive at for this URL, from the
	 * rewrite rules as url_to_postid() walks them, then the query string, which wins.
	 *
	 * @param array<string,mixed> $parts wp_parse_url()'s answer.
	 * @return array<string,mixed>|null null when WordPress's rewrite layer is not there.
	 */
	private static function query_vars_of( array $parts ): ?array {
		global $wp_rewrite, $wp;
		if ( ! is_object( $wp_rewrite ) || ! is_object( $wp ) ) {
			return null;
		}

		$path = trim( (string) ( $parts['path'] ?? '' ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && ( $path === $home || str_starts_with( $path, $home . '/' ) ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		if ( ! $wp_rewrite->using_index_permalinks() ) {
			$path = trim( (string) preg_replace( '#^' . preg_quote( (string) $wp_rewrite->index, '#' ) . '(?:/|$)#', '', $path ), '/' );
		}

		$rules = (array) $wp_rewrite->wp_rewrite_rules();
		if ( '' !== $path && [] === $rules ) {
			return null; // plain permalinks: what a path means is up to the server in front of WordPress
		}
		$vars = [];
		if ( '' !== $path ) {
			foreach ( $rules as $match => $target ) {
				if ( 1 !== preg_match( "#^{$match}#", $path, $matches ) ) {
					continue;
				}
				if ( $wp_rewrite->use_verbose_page_rules && 1 === preg_match( '/pagename=\$matches\[([0-9]+)\]/', (string) $target, $page_match ) && ! get_page_by_path( $matches[ (int) $page_match[1] ] ) ) {
					continue;
				}
				parse_str( addslashes( \WP_MatchesMapRegex::apply( (string) preg_replace( '!^.+\?!', '', (string) $target ), $matches ) ), $vars );
				break;
			}
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $given );
		$vars = array_merge( $vars, $given );

		// Only what a visitor's URL can set, and a post type's own query variable names a post.
		$public = array_intersect_key( $vars, array_flip( (array) $wp->public_query_vars ) );
		foreach ( get_post_types( [], 'objects' ) as $post_type => $object ) {
			if ( ! empty( $object->query_var ) && isset( $public[ $object->query_var ] ) ) {
				$public['post_type'] = $post_type;
				$public['name']      = $public[ $object->query_var ];
			}
		}
		// Empty matches from optional rule groups are not values. An empty search still is a search.
		$public = array_filter( $public, static fn( $value, $key ): bool => '' !== $value || 's' === $key, ARRAY_FILTER_USE_BOTH );
		return wp_resolve_numeric_slug_conflicts( $public );
	}

	/** Would a visitor's request find any posts? WP::handle_404() answers 404 when a date or a later page has none. */
	private static function has_posts( array $vars ): bool {
		$found = new \WP_Query( $vars + [ 'fields' => 'ids', 'no_found_rows' => true, 'post_status' => 'publish' ] );
		return ! empty( $found->posts );
	}

	/**
	 * One stored condition against a URL. Priorities are what Pro's
	 * get_condition_priority() gives (lower is more specific); they only ever compare
	 * with each other.
	 *
	 * @param array<string,mixed> $ctx URL-derived context with the URL's kind.
	 * @return int|false|null The priority when it applies, false when it does not, null
	 *                        when it depends on something this code does not look at.
	 */
	private static function location_condition( string $condition, array $ctx ): int|false|null {
		[ $mode, $name, $sub_name, $sub_id ] = array_pad( explode( '/', $condition ), 4, '' );
		if ( 'include' !== $mode && 'exclude' !== $mode ) {
			return null;
		}
		switch ( $name ) {
			case 'general':
				return 100;
			case 'singular':
				return self::singular_location_condition( $sub_name, $sub_id, $ctx );
			case 'archive':
				return self::archive_location_condition( $sub_name, $sub_id, $ctx );
			default:
				return null;
		}
	}

	/** @return int|false|null See location_condition(). */
	private static function singular_location_condition( string $sub_name, string $sub_id, array $ctx ): int|false|null {
		if ( 'unknown' === $ctx['kind'] ) {
			return null;
		}
		// Pro's Singular condition also passes on a not-found page.
		if ( 'singular' !== $ctx['kind'] && '404' !== $ctx['kind'] ) {
			return false;
		}
		if ( '' === $sub_name ) {
			return 60;
		}
		if ( 'not_found404' === $sub_name ) {
			return '404' === $ctx['kind'] ? 5 : false;
		}
		if ( '404' === $ctx['kind'] ) {
			return false; // every other sub-condition is about a post
		}
		if ( 'front_page' === $sub_name ) {
			return $ctx['is_front'] ? 15 : false;
		}
		if ( 1 === preg_match( '/^(?:child_of|any_child_of|by_author|in_.+|.+_by_author)$/', $sub_name ) ) {
			return null;
		}
		if ( $ctx['post_type'] !== $sub_name ) {
			return false;
		}
		if ( '' === $sub_id ) {
			return 30;
		}
		return (int) $sub_id === (int) $ctx['post_id'] ? 20 : false;
	}

	/** @return int|false|null See location_condition(). */
	private static function archive_location_condition( string $sub_name, string $sub_id, array $ctx ): int|false|null {
		$kind = $ctx['kind'];
		if ( 'unknown' === $kind ) {
			return null;
		}
		if ( ! in_array( $kind, [ 'archive', 'blog', 'date', 'search' ], true ) ) {
			return false;
		}
		if ( '' === $sub_name ) {
			return 80;
		}
		if ( 'post_archive' === $sub_name ) {
			return 'blog' === $kind ? 60 : false;
		}
		if ( 'date' === $sub_name ) {
			return 'date' === $kind ? 55 : false;
		}
		if ( 'search' === $sub_name ) {
			return 'search' === $kind ? 55 : false;
		}
		if ( 'author' === $sub_name || 'by_author' === $sub_name ) {
			if ( 'author_archive' !== $ctx['archive_kind'] ) {
				return false;
			}
			if ( '' === $sub_id ) {
				return 55;
			}
			return (int) $sub_id === (int) $ctx['author_id'] ? 50 : false;
		}
		if ( 'archive' !== $kind ) {
			return false; // what is left is a taxonomy or a post type's archive
		}
		if ( 1 === preg_match( '/^(?:child_of_.+|any_child_of_.+|in_.+)$/', $sub_name ) ) {
			return null;
		}
		if ( str_ends_with( $sub_name, '_archive' ) ) {
			return 'cpt_archive' === $ctx['archive_kind'] && substr( $sub_name, 0, -8 ) === $ctx['cpt_name'] ? 60 : false;
		}
		if ( $sub_name !== $ctx['taxonomy'] ) {
			return false;
		}
		if ( '' === $sub_id ) {
			return 60;
		}
		return null !== $ctx['term_id'] && (int) $sub_id === (int) $ctx['term_id'] ? 50 : false;
	}

	/**
	 * Parse a single Elementor condition string into a structured representation.
	 *
	 * Returns a five-field array describing what the condition targets, without
	 * applying it to any URL context. Used by the `list_elementor_templates`
	 * tool (Layer 2 / v1.11.0) to surface human-readable condition information
	 * in template listings without requiring the caller to parse the raw
	 * string themselves.
	 *
	 * Intentionally an additive sibling to `condition_matches_context()` rather
	 * than a refactor — the matcher passed v1.8.1's empirical gates and shouldn't
	 * be disturbed by a structural refactor for unrelated downstream work.
	 *
	 * Supported patterns (kept in lockstep with condition_matches_context):
	 *   include/general
	 *   include/singular
	 *   include/singular/{post_type}
	 *   include/singular/{post_type}/{post_id}
	 *   include/archive
	 *   include/archive/{taxonomy}
	 *   include/archive/{taxonomy}/{term_id}
	 *   include/archive/in_taxonomy/{term_id}
	 *   include/archive/by_author
	 *   include/archive/by_author/{author_id}
	 *   include/archive/post_archive
	 *   include/archive/{cpt}_archive
	 *   exclude/...  (same patterns, mode='exclude')
	 *
	 * Returns null when the condition string isn't recognized — callers should
	 * surface the raw string when parsed is null so unknown formats stay visible.
	 *
	 * @return array{mode:string,scope:string,subscope:?string,target_id:?int,target_label:?string}|null
	 */
	public static function parse_condition_string( string $condition ): ?array {
		$parts = explode( '/', $condition );
		$mode  = $parts[0] ?? '';
		if ( 'include' !== $mode && 'exclude' !== $mode ) {
			return null;
		}
		$scope = $parts[1] ?? '';
		if ( ! in_array( $scope, [ 'general', 'singular', 'archive' ], true ) ) {
			return null;
		}

		$result = [
			'mode'         => $mode,
			'scope'        => $scope,
			'subscope'     => null,
			'target_id'    => null,
			'target_label' => null,
		];

		if ( 'general' === $scope ) {
			return $result;
		}

		if ( 'singular' === $scope ) {
			if ( ! isset( $parts[2] ) ) {
				return $result; // include/singular bare
			}
			$result['subscope'] = $parts[2]; // post_type slug
			if ( isset( $parts[3] ) && ctype_digit( $parts[3] ) ) {
				$result['target_id']    = (int) $parts[3];
				$result['target_label'] = self::resolve_target_label( 'singular', $result['subscope'], $result['target_id'] );
			}
			return $result;
		}

		// 'archive' scope.
		if ( ! isset( $parts[2] ) ) {
			return $result; // include/archive bare
		}
		$result['subscope'] = $parts[2];

		// Author archive: include/archive/author (Pro) or by_author, each with an optional {id}
		if ( 'author' === $result['subscope'] || 'by_author' === $result['subscope'] ) {
			if ( isset( $parts[3] ) && ctype_digit( $parts[3] ) ) {
				$result['target_id']    = (int) $parts[3];
				$result['target_label'] = self::resolve_target_label( 'author', null, $result['target_id'] );
			}
			return $result;
		}

		// Taxonomy-agnostic term match: include/archive/in_taxonomy/{term_id}
		if ( 'in_taxonomy' === $result['subscope'] ) {
			if ( isset( $parts[3] ) && ctype_digit( $parts[3] ) ) {
				$result['target_id']    = (int) $parts[3];
				$result['target_label'] = self::resolve_target_label( 'term', null, $result['target_id'] );
			}
			return $result;
		}

		// post_archive — the WordPress blog index.
		if ( 'post_archive' === $result['subscope'] ) {
			return $result;
		}

		// {cpt}_archive — CPT-specific archive.
		if ( substr( $result['subscope'], -8 ) === '_archive' ) {
			return $result;
		}

		// Taxonomy slug: include/archive/{taxonomy} or include/archive/{taxonomy}/{term_id}
		if ( isset( $parts[3] ) && ctype_digit( $parts[3] ) ) {
			$result['target_id']    = (int) $parts[3];
			$result['target_label'] = self::resolve_target_label( 'term', $result['subscope'], $result['target_id'] );
		}
		return $result;
	}

	/**
	 * Best-effort label resolution for a condition target. Returns null when
	 * the target was deleted or isn't resolvable. Never throws.
	 *
	 * @param string      $kind     'singular' | 'term' | 'author'
	 * @param string|null $context  post_type slug for 'singular', taxonomy slug for 'term', ignored for 'author'
	 * @param int         $id       target ID
	 */
	private static function resolve_target_label( string $kind, ?string $context, int $id ): ?string {
		if ( $id <= 0 ) {
			return null;
		}
		try {
			if ( 'singular' === $kind ) {
				$title = get_the_title( $id );
				return is_string( $title ) && '' !== $title ? $title : null;
			}
			if ( 'term' === $kind ) {
				$taxonomy = $context ?: '';
				$term     = '' !== $taxonomy ? get_term( $id, $taxonomy ) : get_term( $id );
				if ( $term && ! is_wp_error( $term ) && isset( $term->name ) ) {
					return (string) $term->name;
				}
				return null;
			}
			if ( 'author' === $kind ) {
				$user = get_userdata( $id );
				if ( $user && isset( $user->display_name ) && '' !== $user->display_name ) {
					return (string) $user->display_name;
				}
				return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}
}
