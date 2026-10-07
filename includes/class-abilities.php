<?php
/**
 * WordPress Abilities API bridge.
 *
 * Registers IATO's read-only crawl tools as `iato/*` abilities so any MCP
 * server built on the WordPress MCP Adapter (and the core `wp-abilities/v1`
 * REST API) can discover and run them alongside abilities from Elementor,
 * Yoast, hosting plugins and WordPress core.
 *
 * One implementation: each ability's execute_callback invokes the same handler
 * the MCP tool uses, through IATO_MCP_Server::invoke_tool(), which unwraps the
 * MCP text envelope. Descriptions here are written for ability discovery (the
 * MCP tool descriptions assume the IATO MCP endpoint context).
 *
 * Authentication differs from the IATO MCP endpoint: abilities run as the
 * WordPress user the REST request authenticated (usually an Application
 * Password), and each permission_callback checks a real capability. Every call
 * to the IATO service uses the API key stored in plugin settings; nothing from
 * the request is used for authentication.
 *
 * The Abilities API exists in WordPress 6.9+. On older WordPress every entry
 * point is a no-op (function_exists guards), and the IATO MCP endpoint is
 * unaffected either way.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Abilities {

	public const OPTION   = 'iato_mcp_abilities_enabled';
	public const CATEGORY = 'iato';
	public const NS       = 'iato';

	/**
	 * Default capability every ability requires. Filterable per ability via
	 * `iato_mcp_ability_capability` so a site can lower it (e.g. to
	 * `edit_posts`, which is what the IATO MCP endpoint itself requires of
	 * Application Password users).
	 */
	public const CAPABILITY = 'manage_options';

	/** Filter hook name: function( string $cap, string $ability, string $tool ): string */
	public const CAPABILITY_FILTER = 'iato_mcp_ability_capability';

	/**
	 * Default MCP annotations for the read-only abilities (mapped by the MCP
	 * Adapter to readOnlyHint / destructiveHint / idempotentHint, and used by
	 * core's wp-abilities/v1 run route to pick GET for readonly abilities).
	 */
	public const DEFAULT_ANNOTATIONS = [
		'readonly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * Ability slug => MCP tool + discovery copy, with an optional
	 * `annotations` override for abilities that are not strictly read-only.
	 *
	 * Descriptions are deliberately front-loaded: MCP clients see name, label
	 * and description in a list of ~140 abilities. Keep each under ~400 chars.
	 */
	public const ABILITIES = [
		'get-broken-links' => [
			'tool'        => 'get_iato_broken_links',
			'label'       => 'IATO: Broken links',
			'description' => 'Lists broken pages and broken resources (images, scripts, links) found by IATO\'s full-site crawl, each with HTTP status, the page that references it, and the WordPress post ID and slug when resolvable. Use it to answer "what is failing on this site and where is it linked from?" before fixing links or adding redirects. Optional crawl_id, limit.',
		],
		'get-orphan-pages' => [
			'tool'        => 'get_iato_orphan_pages',
			'label'       => 'IATO: Orphan pages',
			'description' => 'Lists pages that IATO\'s full-site crawl found but that no navigation menu links to, with WordPress post IDs and slugs. Use it to answer "which content can visitors not reach from the menus?" when auditing navigation or deciding what to add to menus, link internally, or retire. Optional crawl_id.',
		],
		'get-content-gaps' => [
			'tool'        => 'get_iato_content_gaps',
			'label'       => 'IATO: Content gaps',
			'description' => 'Finds thin or weak pages from IATO\'s full-site crawl: low word count, missing H1, no images, or too few internal links, each with WordPress post ID and slug. Use it to answer "which pages need more or better content?" when planning content work. Optional crawl_id, min_word_count (default 300), limit.',
		],
		'get-nav-audit' => [
			'tool'        => 'get_iato_nav_audit',
			'label'       => 'IATO: Navigation audit',
			'description' => 'Audits site navigation from IATO\'s full-site crawl: every menu with its items, plus the pages that appear in no menu, all with WordPress slugs. Use it to answer "is the navigation complete and consistent with the site\'s pages?" before editing menus or adding internal links. Optional crawl_id.',
		],
		'get-seo-fixes' => [
			'tool'        => 'get_iato_seo_fixes',
			'label'       => 'IATO: SEO fixes',
			'description' => 'Lists on-page SEO issues from IATO\'s full-site crawl (titles, meta descriptions, alt text, headings, canonicals) with WordPress post IDs and slugs. Auto-fixable items carry current and suggested values; manual items carry instructions. Use it to answer "what SEO problems does this site have and how do I fix each one?" Optional crawl_id, severity, limit.',
		],
		'get-suggestions' => [
			'tool'        => 'get_iato_suggestions',
			'label'       => 'IATO: Suggestions',
			'description' => 'Returns IATO\'s prioritised improvement suggestions across SEO, content, broken links and performance, derived from a full-site crawl, with WordPress post IDs and slugs. The best starting point for "what should I fix first on this site?" If a completed crawl has no suggestions yet, generation is triggered once; call again shortly. Optional crawl_id, focus_areas, limit.',
			// Not annotated readonly / idempotent: the first call on a crawl
			// without suggestions triggers generation on IATO, and later calls
			// can return a different (freshly generated) list. Still not
			// destructive: nothing on this site or on IATO is removed.
			'annotations' => [ 'destructive' => false ],
		],
		'get-perf-report' => [
			'tool'        => 'get_iato_perf_report',
			'label'       => 'IATO: Performance report',
			'description' => 'Reports page load performance from IATO\'s full-site crawl: the slowest pages, the largest pages, and a site-level summary, each with WordPress post ID and slug. Use it to answer "which pages are slow or heavy and need image, caching or plugin work?" Optional crawl_id, limit.',
		],
		'get-sitemap' => [
			'tool'        => 'get_iato_sitemap',
			'label'       => 'IATO: Sitemap',
			'description' => 'Returns the site\'s full page hierarchy as mapped by IATO\'s crawl, with WordPress post IDs and slugs on every node. Use it to answer "how is this site structured?" before changing navigation, internal linking, or URL structure. Uses the most recent sitemap unless sitemap_id is given. Optional crawl_id.',
		],
		'get-taxonomy' => [
			'tool'        => 'get_iato_taxonomy',
			'label'       => 'IATO: Taxonomy',
			'description' => 'Returns the categories and tags IATO derived from a full-site crawl, mapped to WordPress term IDs, plus the count of pages matching no term. Use it to answer "is this site\'s content classified consistently?" when auditing or bulk-reassigning categories and tags. Optional crawl_id.',
		],
		'get-crawl-status' => [
			'tool'        => 'get_iato_crawl_status',
			'label'       => 'IATO: Crawl status',
			'description' => 'Reports the status, progress and metadata of one IATO crawl job: state (queued, running, completed, failed), pages crawled, start and completion times. Use it to check that a crawl has finished before reading its results, or to see what a crawl_id covers. Requires crawl_id unless a site default is set.',
		],
		'list-crawls' => [
			'tool'        => 'list_iato_crawls',
			'label'       => 'IATO: List crawls',
			'description' => 'Lists recent IATO crawl jobs for this site\'s workspace with their IDs, status, URL and dates. Use it first to find the latest completed crawl_id to pass to the other iato/ abilities. Optional limit (default 20, max 100).',
		],
	];

	/**
	 * Schema properties the handler reads but the MCP tool definition omits.
	 * Added so the ability's input_schema documents (and lets through) every
	 * argument the shared handler actually honours.
	 */
	private const EXTRA_PROPERTIES = [
		'get_iato_sitemap' => [
			'crawl_id' => [ 'type' => 'string', 'description' => 'IATO crawl job ID used to pick the matching sitemap. Falls back to the default crawl ID in Settings > IATO MCP.' ],
		],
	];

	// ── Bootstrap ─────────────────────────────────────────────────────────

	/**
	 * Is abilities exposure switched on? Missing option means on. The option is
	 * stored as a '1' / '0' string (see IATO_MCP_Settings::register_settings()),
	 * but any truthy/falsy value is accepted so `wp option update ... 0` works.
	 */
	public static function is_enabled(): bool {
		$value = get_option( self::OPTION, '1' );
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), [ '', '0', 'false', 'no', 'off' ], true );
		}
		return (bool) $value;
	}

	/**
	 * Attach the registration hooks. Called on plugins_loaded; the Abilities
	 * registries initialise lazily after `init`, firing both actions once.
	 * Silent no-op below WordPress 6.9 or when the setting is off.
	 */
	public static function init(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category( self::CATEGORY, [
			'label'       => __( 'IATO Site Crawl', 'iato-mcp' ),
			'description' => __( 'Read-only SEO, content, navigation, link and performance findings from IATO\'s full-site crawl of this WordPress site.', 'iato-mcp' ),
		] );
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( self::ABILITIES as $slug => $def ) {
			$args = self::build_args( $slug, $def );
			if ( null === $args ) {
				continue;
			}
			wp_register_ability( self::NS . '/' . $slug, $args );
		}
	}

	/**
	 * Build wp_register_ability() args for one entry, or null when the ability
	 * must not be registered (tool toggled off in Settings, or not catalogued).
	 */
	public static function build_args( string $slug, array $def ): ?array {
		$tool = $def['tool'];

		// Respect the per-tool toggle: an admin who switched a tool off expects
		// it off on every surface.
		if ( class_exists( 'IATO_MCP_Settings' ) && ! IATO_MCP_Settings::is_tool_enabled( $tool ) ) {
			return null;
		}

		$entry = IATO_MCP_Server::get_catalog_entry( $tool );
		if ( null === $entry ) {
			return null;
		}

		return [
			'label'               => $def['label'],
			'description'         => $def['description'],
			'category'            => self::CATEGORY,
			'input_schema'        => self::input_schema( $tool, $entry['definition']['inputSchema'] ?? [] ),
			'output_schema'       => [ 'type' => 'object' ],
			'execute_callback'    => static fn( $input = [] ) => self::execute( $slug, is_array( $input ) ? $input : [] ),
			'permission_callback' => static fn( $input = [] ) => current_user_can( self::capability_for( $slug ) ),
			'meta'                => [
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => [ 'public' => true ],
				'annotations'  => $def['annotations'] ?? self::DEFAULT_ANNOTATIONS,
			],
		];
	}

	/**
	 * Annotations an ability is registered with (default read-only set unless
	 * the table overrides them).
	 */
	public static function annotations_for( string $slug ): array {
		return self::ABILITIES[ $slug ]['annotations'] ?? self::DEFAULT_ANNOTATIONS;
	}

	/**
	 * Capability required to run an ability. Defaults to manage_options for
	 * all eleven; sites can lower it per ability:
	 *
	 *   add_filter( 'iato_mcp_ability_capability', fn( $cap, $ability, $tool ) =>
	 *       'iato/list-crawls' === $ability ? 'edit_posts' : $cap, 10, 3 );
	 *
	 * A non-string or empty filter result falls back to the default.
	 */
	public static function capability_for( string $slug ): string {
		$tool = self::ABILITIES[ $slug ]['tool'] ?? '';
		$cap  = function_exists( 'apply_filters' )
			? apply_filters( self::CAPABILITY_FILTER, self::CAPABILITY, self::NS . '/' . $slug, $tool )
			: self::CAPABILITY;
		return is_string( $cap ) && '' !== $cap ? $cap : self::CAPABILITY;
	}

	/**
	 * Ability input schema derived from the MCP tool's inputSchema.
	 * Ensures type:object and a top-level default so a null input (REST GET
	 * without ?input=) normalises to an empty object instead of failing.
	 */
	public static function input_schema( string $tool, array $schema ): array {
		$schema['type']       = 'object';
		$schema['properties'] = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
		foreach ( self::EXTRA_PROPERTIES[ $tool ] ?? [] as $key => $prop ) {
			if ( ! isset( $schema['properties'][ $key ] ) ) {
				$schema['properties'][ $key ] = $prop;
			}
		}
		if ( empty( $schema['properties'] ) ) {
			unset( $schema['properties'] );
		}
		if ( isset( $schema['required'] ) && empty( $schema['required'] ) ) {
			unset( $schema['required'] );
		}
		$schema['default'] = [];
		return $schema;
	}

	// ── Execution ─────────────────────────────────────────────────────────

	/**
	 * Execute an ability. Connection check first (with an actionable message),
	 * then the shared tool handler. Handler WP_Errors (missing_crawl_id,
	 * iato_api_error, ...) pass through unchanged so clients see exactly what
	 * the MCP tool would say. Any Throwable becomes a WP_Error.
	 */
	public static function execute( string $slug, array $input = [] ): array|WP_Error {
		$def = self::ABILITIES[ $slug ] ?? null;
		if ( null === $def ) {
			return new WP_Error( 'iato_ability_unknown', "Unknown IATO ability: {$slug}" );
		}

		try {
			if ( '' === sanitize_text_field( (string) get_option( 'iato_mcp_api_key', '' ) ) ) {
				return new WP_Error(
					'iato_not_connected',
					__( 'This site is not connected to IATO. Add your IATO API key under Settings > IATO MCP (or run the IATO MCP setup wizard), then try again.', 'iato-mcp' ),
					[ 'status' => 409 ]
				);
			}

			if ( null === IATO_MCP_Server::get_catalog_entry( $def['tool'] ) ) {
				return new WP_Error(
					'iato_tool_unavailable',
					sprintf(
						/* translators: %s: MCP tool name */
						__( 'The IATO tool %s is not available on this site.', 'iato-mcp' ),
						$def['tool']
					)
				);
			}

			return IATO_MCP_Server::invoke_tool( $def['tool'], $input );
		} catch ( Throwable $e ) {
			return new WP_Error(
				'iato_ability_exception',
				sprintf(
					/* translators: 1: ability slug, 2: exception message */
					__( 'IATO ability %1$s failed: %2$s', 'iato-mcp' ),
					$slug,
					$e->getMessage()
				)
			);
		}
	}
}
