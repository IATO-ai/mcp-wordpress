<?php
/**
 * Page-cache purging and the `cache` object on write responses (1.15.0).
 *
 * A write is what records a change receipt: IATO_MCP_Change_Receipt::record()
 * and IATO_MCP_Rollback::execute() report here, so a dry run or a no-op (which
 * record nothing) never purges, whatever shape the tool's response has. The
 * pending targets are worked off once per tool call, after the handler returned
 * (every database write has committed by then), in
 * IATO_MCP_Server::handle_tools_call() and IATO_MCP_Rollback::handle():
 *
 *   1. Elementor CSS: the edited post's file is rebuilt, or, for a write that
 *      other pages render (kit, Theme Builder, global widgets, saved templates),
 *      every page's CSS is cleared;
 *   2. page caches: one post purge per distinct post, or one full purge;
 *   3. the response gets a `cache` object saying what happened.
 *
 * Elementor CSS is part of a correct write, so it runs even when page-cache
 * purging is switched off. A purge failure never fails the write: it is caught,
 * logged under WP_DEBUG and reported in `cache.notes`.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Cache {

	/** "Purge page caches after changes": '1' / '0', missing means on. */
	public const OPTION = 'iato_mcp_cache_purge_enabled';

	/** What the loopback request saw, so it runs at most once per TTL. */
	public const EDGE_TRANSIENT = 'iato_mcp_cache_edge';
	public const EDGE_TTL       = 43200; // 12 hours
	private const EDGE_TIMEOUT  = 2;

	/**
	 * Every tool that writes to WordPress. These carry a `cache` object even when
	 * nothing changed. start_iato_crawl is left out: it only starts a crawl on
	 * the IATO platform. tests/CacheResponseTest.php fails when a registered tool
	 * is neither listed here nor named like a read.
	 */
	public const WRITE_TOOLS = [
		'create_post',
		'update_post',
		'update_seo_data',
		'update_alt_text',
		'create_media',
		'update_menu_item',
		'create_menu_item',
		'delete_menu_item',
		'update_menu_item_details',
		'create_menu',
		'assign_menu_location',
		'create_elementor_template',
		'set_elementor_template_conditions',
		'assign_term',
		'create_term',
		'update_term',
		'delete_term',
		'update_taxonomy',
		'update_canonical',
		'update_structured_data',
		'update_redirect',
		'update_elementor_data',
		'update_elementor_widget',
		'update_elementor_patch',
		'update_elementor_widgets_bulk',
		'set_heading_level',
		'set_widget_setting',
		'rollback',
		'update_post_meta',
		'set_page_settings',
		'set_featured_image',
		'update_site_settings',
	];

	/** Receipt types whose change shows on every page. */
	private const SITE_WIDE_TARGETS = [ 'menu_item', 'menu', 'menu_location', 'elementor_template', 'taxonomy', 'redirect', 'site_setting' ];

	/**
	 * Post types other pages render, so their own URL says nothing about what is
	 * stale. Any type without a public page counts too (is_post_type_viewable()):
	 * an addon's hooks and snippets show up inside other pages. This list is for
	 * those that do have one.
	 */
	public const SITE_WIDE_POST_TYPES = [
		'elementor_library',
		'e-floating-buttons',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
	];

	/** Of those, what Elementor renders into other pages: its CSS goes with them. */
	private const ELEMENTOR_SITE_WIDE_POST_TYPES = [ 'elementor_library', 'e-floating-buttons' ];

	private const NOTE_HOSTINGER  = "Hostinger server cache or a CDN may still hold the old page; purge it in hPanel or the CDN dashboard if changes don't appear.";
	private const NOTE_CLOUDFLARE = "Cloudflare may still hold the old page; purge it in the Cloudflare dashboard if changes don't appear.";
	private const NOTE_LITESPEED  = "A LiteSpeed server cache is in front of this site, but the LiteSpeed Cache plugin is not active, so IATO cannot purge it; purge it in the hosting panel if changes don't appear.";

	/** @var callable|null Test seam: fn( string $slug, bool $present ): bool */
	private static $detector = null;

	/** @var array{all:bool,global_css:bool,posts:array<int,true>,css:array<int,bool>,notes:array<string,string>} */
	private static array $pending = [ 'all' => false, 'global_css' => false, 'posts' => [], 'css' => [], 'notes' => [] ];

	public static function set_detector( ?callable $detector ): void {
		self::$detector = $detector;
	}

	public static function is_enabled(): bool {
		$value = get_option( self::OPTION, '1' );
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), [ '', '0', 'false', 'no', 'off' ], true );
		}
		return (bool) $value;
	}

	// ── What a write reports ──────────────────────────────────────────────

	/** Forget anything pending; called before a tool handler runs. */
	public static function begin(): void {
		self::$pending = [ 'all' => false, 'global_css' => false, 'posts' => [], 'css' => [], 'notes' => [] ];
	}

	public static function has_pending(): bool {
		return self::$pending['all'] || self::$pending['global_css'] || [] !== self::$pending['posts'] || [] !== self::$pending['css'];
	}

	/**
	 * A receipt was recorded. Never throws: it runs inside every write. A value
	 * that did not change (assign_term on a term the post already has) is no
	 * reason to purge.
	 */
	public static function note_write( string $target_type, ?int $post_id, string $field = '', mixed $before = null, mixed $after = null ): void {
		try {
			if ( ! self::same_value( $before, $after ) ) {
				self::add_target( $target_type, $post_id, $field, $before );
			}
		} catch ( \Throwable $e ) {
			self::$pending['all'] = true; // unknown target: fail safe
		}
	}

	/**
	 * A receipt is being rolled back. Called before the restore, while a post the
	 * rollback deletes can still be looked at. The old and new values swap: the
	 * value leaving the site is the receipt's after value.
	 *
	 * @param array $receipt The receipt row.
	 */
	public static function note_rollback( array $receipt ): void {
		try {
			$type  = (string) ( $receipt['target_type'] ?? '' );
			$field = (string) ( $receipt['field'] ?? '' );
			$id    = ! empty( $receipt['post_id'] ) ? (int) $receipt['post_id'] : null;
			if ( 'post' === $type && 'create' === $field ) {
				self::$pending['all'] = true; // the post is trashed; its URL is gone
				return;
			}
			self::add_target( $type, $id, $field, $receipt['after_value'] ?? null );
		} catch ( \Throwable $e ) {
			self::$pending['all'] = true;
		}
	}

	/** A post whose rendered page changed without a receipt naming it (alt text routed to an attachment). */
	public static function note_post( int $post_id ): void {
		try {
			self::add_target( 'post', $post_id, '', null );
		} catch ( \Throwable $e ) {
			self::$pending['all'] = true;
		}
	}

	/**
	 * This post's Elementor caches were cleared after a write.
	 *
	 * @param bool $reliable   Did the per-post clear demonstrably work? When not,
	 *                         every page's CSS is cleared and the whole site purged.
	 * @param bool $regenerate Rebuild the CSS file now, once, before the purge.
	 */
	public static function queue_post_css( int $post_id, bool $reliable, bool $regenerate = true ): void {
		try {
			self::add_target( 'post', $post_id, '', null );
			if ( ! $reliable ) {
				self::$pending['all']               = true;
				self::$pending['global_css']        = true;
				self::$pending['notes']['css_fallback'] = "Elementor could not clear this page's CSS on its own, so all Elementor CSS was cleared and the whole page cache purged.";
				return;
			}
			// A write that said not to rebuild wins: it knows elements could not be loaded.
			self::$pending['css'][ $post_id ] = $regenerate && ( self::$pending['css'][ $post_id ] ?? true );
		} catch ( \Throwable $e ) {
			self::$pending['all'] = true;
		}
	}

	private static function add_target( string $type, ?int $post_id, string $field, mixed $before ): void {
		if ( in_array( $type, self::SITE_WIDE_TARGETS, true ) || null === $post_id || $post_id <= 0 ) {
			self::$pending['all'] = true;
			return;
		}
		// The URL cached under the old slug or status cannot be derived from the post any more.
		if ( 'post' === $type && ( 'slug' === $field || ( 'status' === $field && 'publish' === $before ) ) ) {
			self::$pending['all'] = true;
			return;
		}
		if ( 'image' === $type || 'attachment' === $type ) {
			$parent = (int) get_post_field( 'post_parent', $post_id );
			self::$pending['posts'][ $parent > 0 ? $parent : $post_id ] = true;
			if ( 'image' === $type ) {
				self::$pending['notes']['image'] = 'Other pages that use this image may still show the old version until their cache expires.';
			}
			return;
		}
		$post_type = self::post_type( $post_id );
		if ( in_array( $post_type, self::SITE_WIDE_POST_TYPES, true ) || self::has_no_public_page( $post_type ) ) {
			self::$pending['all']        = true;
			self::$pending['global_css'] = self::$pending['global_css']
				|| in_array( $post_type, self::ELEMENTOR_SITE_WIDE_POST_TYPES, true )
				|| 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
			return;
		}
		self::$pending['posts'][ $post_id ] = true;
	}

	private static function post_type( int $post_id ): string {
		return function_exists( 'get_post_type' ) ? (string) get_post_type( $post_id ) : '';
	}

	/** A registered post type no visitor can open. Unknown types are not assumed either way. */
	private static function has_no_public_page( string $post_type ): bool {
		return '' !== $post_type && function_exists( 'is_post_type_viewable' ) && function_exists( 'post_type_exists' )
			&& post_type_exists( $post_type ) && ! is_post_type_viewable( $post_type );
	}

	private static function same_value( mixed $a, mixed $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		if ( null === $a || null === $b ) {
			return false;
		}
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	// ── After the handler ─────────────────────────────────────────────────

	/**
	 * Work off what the tool call wrote and add `cache` to its result. A dry run
	 * or a read, which wrote nothing, is returned as it came.
	 *
	 * @param string $tool   The tool name.
	 * @param mixed  $args   The tool arguments (may not be an array).
	 * @param array  $result The tool's result envelope.
	 */
	public static function finish( string $tool, mixed $args, array $result ): array {
		if ( self::has_pending() ) {
			$cache = self::run_pending();
			return empty( $result['isError'] ) ? self::decorate( $result, $cache ) : $result;
		}
		if ( ! empty( $result['isError'] ) || ! in_array( $tool, self::WRITE_TOOLS, true ) ) {
			return $result;
		}
		if ( is_array( $args ) && ! empty( $args['dry_run'] ) ) {
			return $result;
		}
		return self::decorate( $result, self::nothing_changed() );
	}

	/** Work off what is pending when there is no JSON result to add `cache` to. */
	public static function flush(): ?array {
		return self::has_pending() ? self::run_pending() : null;
	}

	/**
	 * Add `cache` to the JSON text without decoding it: a decode and re-encode
	 * would turn every empty object in the response into an empty list.
	 */
	private static function decorate( array $result, array $cache ): array {
		$text = $result['content'][0]['text'] ?? null;
		$json = wp_json_encode( $cache );
		if ( ! is_string( $text ) || ! is_string( $json ) ) {
			return $result;
		}
		$text = trim( $text );
		if ( ! str_starts_with( $text, '{' ) || ! str_ends_with( $text, '}' ) ) {
			return $result;
		}
		$body = substr( $text, 0, -1 );
		$sep  = '{' === rtrim( $body ) ? '' : ',';

		$result['content'][0]['text'] = $body . $sep . '"cache":' . $json . '}';
		return $result;
	}

	private static function nothing_changed(): array {
		return [
			'detected' => self::detected(),
			'purged'   => null,
			'scope'    => null,
			'notes'    => [ 'Nothing was written by this call, so no cache was purged.' ],
		];
	}

	/** @return array{detected:string[],purged:?bool,scope:?string,notes:string[],post_ids?:int[]} */
	private static function run_pending(): array {
		$pending = self::$pending;
		self::begin();

		$notes = [];
		try {
			$notes = self::refresh_elementor_css( $pending );
		} catch ( \Throwable $e ) {
			self::log( 'elementor_css', $e );
			$notes[] = 'Elementor CSS could not be refreshed; use Elementor > Tools > Regenerate Files & Data if the page looks unstyled.';
		}

		$scope    = $pending['all'] ? 'all' : ( [] !== $pending['posts'] ? 'post' : null );
		$post_ids = array_map( 'intval', array_keys( $pending['posts'] ) );
		$detected = [];
		$purged   = null;
		try {
			$detected = self::detected();
			$purged   = self::purge_pending( $scope, $post_ids, $detected, $notes );
			$notes    = array_merge( $notes, self::edge_notes( true, $detected ) );
		} catch ( \Throwable $e ) {
			self::log( 'purge', $e );
			$purged  = [] === $detected ? null : false;
			$notes[] = 'The page cache could not be purged; purge it by hand if changes do not appear.';
		}

		$out = [
			'detected' => $detected,
			'purged'   => $purged,
			'scope'    => $scope,
			'notes'    => array_values( array_unique( array_merge( array_values( 'all' === $scope ? array_diff_key( $pending['notes'], [ 'image' => 1 ] ) : $pending['notes'] ), $notes ) ) ),
		];
		if ( 'post' === $scope && count( $post_ids ) > 1 ) {
			$out['post_ids'] = $post_ids;
		}
		return $out;
	}

	/** @return string[] notes */
	private static function refresh_elementor_css( array $pending ): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return [];
		}
		if ( $pending['global_css'] ) {
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
				$plugin->files_manager->clear_cache();
			}
			return [];
		}
		$notes = [];
		foreach ( $pending['css'] as $post_id => $regenerate ) {
			if ( $regenerate && ! self::regenerate_post_css( (int) $post_id ) ) {
				$notes[] = "Elementor could not rebuild this page's CSS right away; it rebuilds it on the next page view.";
			}
		}
		return $notes;
	}

	/** Rebuild one post's CSS file. False = not rebuilt; Elementor does it on the next page view. */
	private static function regenerate_post_css( int $post_id ): bool {
		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) || 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return true;
		}
		try {
			$file = \Elementor\Core\Files\CSS\Post::create( $post_id );
			$file->update();
			$status = (string) $file->get_meta( 'status' );
			if ( 'file' === $status ) {
				return file_exists( $file->get_path() );
			}
			return in_array( $status, [ 'inline', 'empty' ], true );
		} catch ( \Throwable $e ) {
			self::log( 'post_css', $e );
			return false;
		}
	}

	/** @param string[] $detected @param string[] $notes */
	private static function purge_pending( ?string $scope, array $post_ids, array $detected, array &$notes ): ?bool {
		if ( null === $scope ) {
			return null;
		}
		if ( ! self::is_enabled() ) {
			$notes[] = 'Purging page caches after changes is switched off in Settings > IATO MCP; purge the cache by hand if changes do not appear.';
			return [] === $detected ? null : false;
		}
		$runs    = 'all' === $scope ? [ null ] : $post_ids;
		$results = [];
		$skipped = false;
		foreach ( $runs as $post_id ) {
			$run = self::purge( $scope, $post_id );
			if ( null === $run ) {
				$skipped = true;
				continue;
			}
			foreach ( $run as $slug => $ok ) {
				$results[ $slug ] = ( $results[ $slug ] ?? true ) && $ok;
			}
		}
		if ( $skipped ) {
			$notes[] = 'A site filter (iato_mcp_cache_purge) skipped the page cache purge; purge the cache by hand if changes do not appear.';
		}
		foreach ( $results as $slug => $ok ) {
			if ( ! $ok ) {
				$notes[] = sprintf( '%s could not be purged; purge it by hand if changes do not appear.', $slug );
			}
		}
		if ( [] === $detected ) {
			return null;
		}
		return ! $skipped && ! in_array( false, $results, true );
	}

	// ── Purging ───────────────────────────────────────────────────────────

	/**
	 * Purge every cache plugin found. Each result says whether the call ran
	 * without an error, not whether the plugin did the work: some act later in
	 * the request (W3 Total Cache on shutdown, LiteSpeed through a response
	 * header) and some return false after a valid purge (WP Rocket), so return
	 * values are not read.
	 *
	 * @param string   $scope   'post' or 'all'.
	 * @param int|null $post_id The post, for scope 'post'.
	 * @return array<string,bool>|null Results by plugin slug; null when the iato_mcp_cache_purge filter said no.
	 */
	public static function purge( string $scope, ?int $post_id = null ): ?array {
		/**
		 * Filter: return false to skip this purge (for example to purge another way).
		 *
		 * @param bool     $purge   Whether to purge.
		 * @param string   $scope   'post' or 'all'.
		 * @param int|null $post_id The post, for scope 'post'.
		 */
		if ( ! apply_filters( 'iato_mcp_cache_purge', true, $scope, $post_id ) ) {
			return null;
		}

		$home    = 'post' === $scope && null !== $post_id && self::is_home_target( $post_id );
		$results = [];
		foreach ( self::integrations() as $slug => $integration ) {
			if ( ! self::present( $slug, $integration ) ) {
				continue;
			}
			try {
				if ( 'all' === $scope ) {
					$integration['all']();
				} elseif ( null !== $post_id ) {
					$integration['post']( $post_id );
					if ( $home && isset( $integration['home'] ) ) {
						$integration['home']();
					}
				}
				$results[ $slug ] = true;
			} catch ( \Throwable $e ) {
				self::log( $slug, $e );
				$results[ $slug ] = false;
			}
		}

		/**
		 * Action: runs after each purge, also when no cache plugin was found, so a
		 * site can add its own purge here.
		 *
		 * @param string             $scope   'post' or 'all'.
		 * @param int|null           $post_id The post, for scope 'post'.
		 * @param array<string,bool> $results Results by plugin slug.
		 */
		do_action( 'iato_mcp_after_cache_purge', $scope, $post_id, $results );

		return $results;
	}

	/** Is this post what the home URL shows? */
	private static function is_home_target( int $post_id ): bool {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}
		return in_array( $post_id, [ (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ], true );
	}

	/**
	 * The cache plugins and how to purge them. Names checked against each plugin's
	 * source on 2026-11-03: LiteSpeed Cache 7.9.3, WP Rocket 3.24, W3 Total Cache
	 * 2.11.0, WP Super Cache 3.1.5, WP Fastest Cache 1.5.4, SiteGround Optimizer 7.8.6.
	 *
	 * @return array<string,array{present:callable,post:callable,all:callable,home?:callable}>
	 */
	private static function integrations(): array {
		return [
			'litespeed'        => [
				'present' => fn() => function_exists( 'has_action' ) && has_action( 'litespeed_purge_all' ),
				'post'    => function ( int $id ): void {
					self::litespeed_silent();
					do_action( 'litespeed_purge_post', $id );
				},
				'all'     => function (): void {
					self::litespeed_silent();
					// Elementor's clear_cache makes LiteSpeed purge everything too; once is enough.
					if ( ! did_action( 'litespeed_purged_all' ) ) {
						do_action( 'litespeed_purge_all', 'IATO MCP' );
					}
				},
				'home'    => function (): void {
					self::litespeed_silent();
					do_action( 'litespeed_purge_url', home_url( '/' ) );
				},
			],
			'wp-rocket'        => [
				'present' => fn() => function_exists( 'rocket_clean_domain' ) && function_exists( 'rocket_clean_post' ),
				'post'    => fn( int $id ) => rocket_clean_post( $id ),
				'all'     => fn() => rocket_clean_domain(),
				'home'    => fn() => function_exists( 'rocket_clean_home' ) ? rocket_clean_home() : null,
			],
			'w3-total-cache'   => [
				'present' => fn() => function_exists( 'w3tc_flush_all' ) && function_exists( 'w3tc_flush_post' ),
				'post'    => fn( int $id ) => w3tc_flush_post( $id ),
				'all'     => fn() => w3tc_flush_all(),
				'home'    => fn() => function_exists( 'w3tc_flush_url' ) ? w3tc_flush_url( home_url( '/' ) ) : null,
			],
			// wp_cache_post_change() deletes the home page and page_on_front itself;
			// wpsc_delete_url_cache() refuses the home URL.
			'wp-super-cache'   => [
				'present' => fn() => function_exists( 'wp_cache_clear_cache' ) && function_exists( 'wp_cache_post_change' ),
				'post'    => fn( int $id ) => wp_cache_post_change( $id ),
				'all'     => fn() => wp_cache_clear_cache(),
			],
			'wp-fastest-cache' => [
				'present' => fn() => function_exists( 'has_action' ) && has_action( 'wpfc_clear_all_cache' ),
				'post'    => fn( int $id ) => do_action( 'wpfc_clear_post_cache_by_id', false, $id ),
				'all'     => fn() => do_action( 'wpfc_clear_all_cache' ),
				'home'    => fn() => do_action( 'wpfc_clear_cache_by_url', home_url( '/' ) ),
			],
			'sg-optimizer'     => [
				'present' => fn() => function_exists( 'sg_cachepress_purge_cache' ),
				'post'    => function ( int $id ): void {
					$url = get_permalink( $id );
					if ( is_string( $url ) && '' !== $url ) {
						sg_cachepress_purge_cache( $url );
					}
				},
				// sg_cachepress_purge_cache() with no URL only targets the home URL path.
				'all'     => fn() => function_exists( 'sg_cachepress_purge_everything' ) ? sg_cachepress_purge_everything() : sg_cachepress_purge_cache(),
				'home'    => fn() => sg_cachepress_purge_cache( home_url( '/' ) ),
			],
		];
	}

	/** LiteSpeed queues an admin notice per purge unless this is set; an MCP write would leave one each. */
	private static function litespeed_silent(): void {
		if ( ! defined( 'LITESPEED_PURGE_SILENT' ) ) {
			define( 'LITESPEED_PURGE_SILENT', true );
		}
	}

	private static function present( string $slug, array $integration ): bool {
		$present = (bool) $integration['present']();
		return null === self::$detector ? $present : (bool) ( self::$detector )( $slug, $present );
	}

	/** @return string[] Slugs of the cache plugins found. */
	public static function detected(): array {
		$found = [];
		foreach ( self::integrations() as $slug => $integration ) {
			if ( self::present( $slug, $integration ) ) {
				$found[] = $slug;
			}
		}
		return $found;
	}

	// ── Caches that cannot be purged from here ────────────────────────────

	/** What get_site_info reports: it never makes the loopback request. */
	public static function site_info(): array {
		$detected = self::detected();
		return [ 'detected' => $detected, 'notes' => self::edge_notes( false, $detected ) ];
	}

	/**
	 * Notes about caches IATO cannot purge. They never change `purged`, which
	 * speaks for the plugins in `detected`.
	 *
	 * @param bool     $probe    May this call make the loopback request?
	 * @param string[] $detected The cache plugins found.
	 * @return string[]
	 */
	private static function edge_notes( bool $probe, array $detected ): array {
		$seen  = self::edge_headers( $probe );
		$notes = [];
		if ( defined( 'CLOUDFLARE_PLUGIN_DIR' ) || ! empty( $seen['cloudflare'] ) ) {
			$notes[] = self::NOTE_CLOUDFLARE;
		}
		if ( defined( 'HOSTINGER_WORDPRESS_PLUGIN_VERSION' ) || ! empty( $_SERVER['H_PLATFORM'] ) || ! empty( $seen['hostinger'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- presence only
			$notes[] = self::NOTE_HOSTINGER;
		}
		if ( ! empty( $seen['litespeed'] ) && ! in_array( 'litespeed', $detected, true ) ) {
			$notes[] = self::NOTE_LITESPEED;
		}
		return $notes;
	}

	/**
	 * Which edge caches answered a request for the home URL. Cached for 12 hours,
	 * failures too. The short timeout bounds what a write can wait for, at most
	 * twice a day.
	 *
	 * @return array{cloudflare?:bool,hostinger?:bool,litespeed?:bool,failed?:bool}
	 */
	private static function edge_headers( bool $probe ): array {
		$cached = get_transient( self::EDGE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! $probe ) {
			return [];
		}
		$response = wp_remote_head( home_url( '/' ), [
			'timeout'     => self::EDGE_TIMEOUT,
			'redirection' => 2,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
		] );
		$seen = is_wp_error( $response ) ? [ 'failed' => true ] : self::read_edge_headers( wp_remote_retrieve_headers( $response ) );
		set_transient( self::EDGE_TRANSIENT, $seen, self::EDGE_TTL );
		return $seen;
	}

	/** @param iterable|object $headers Response headers (WP_HTTP_Requests_Response headers or an array). */
	private static function read_edge_headers( mixed $headers ): array {
		$names = [];
		foreach ( $headers as $name => $value ) {
			$names[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
		}
		$hcdn = false;
		foreach ( array_keys( $names ) as $name ) {
			$hcdn = $hcdn || str_starts_with( $name, 'x-hcdn-' );
		}
		return [
			'cloudflare' => isset( $names['cf-cache-status'] ) || isset( $names['cf-ray'] ),
			'hostinger'  => $hcdn || 'hcdn' === strtolower( $names['server'] ?? '' ),
			'litespeed'  => isset( $names['x-litespeed-cache'] ),
		];
	}

	private static function log( string $what, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( sprintf( '[iato-mcp] cache %s: %s: %s', $what, get_class( $e ), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only
		}
	}
}
