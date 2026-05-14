<?php
/**
 * Settings page — registers "Settings > IATO MCP" in WP Admin.
 *
 * Fields:
 *   - iato_mcp_api_key      IATO API key (password input, stored in wp_options)
 *   - iato_mcp_crawl_id     Default crawl ID used as fallback by bridge tools
 *   - iato_mcp_tools        Array of enabled tool names (all enabled by default)
 *
 * On save: validate IATO API key by calling GET /api/v1/workspaces and checking 200.
 * On activation: show setup wizard admin notice with the plugin-generated MCP key.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Settings {

	/** Option group name. */
	private const OPTION_GROUP = 'iato_mcp_settings';

	/** Settings page slug. */
	private const PAGE_SLUG = 'iato-mcp';

	/** All tool names available for enable/disable toggles. */
	private const TOOL_NAMES = [
		'get_site_info',
		'get_site_settings',
		'get_posts',
		'get_post',
		'create_post',
		'update_post',
		'search_posts',
		'get_seo_data',
		'update_seo_data',
		'get_media',
		'update_alt_text',
		'get_comments',
		'get_menus',
		'get_menu_items',
		'update_menu_item',
		'create_menu_item',
		'delete_menu_item',
		'update_menu_item_details',
		'get_terms',
		'assign_term',
		'create_term',
		'update_term',
		'delete_term',
		'update_taxonomy',
		'update_canonical',
		'update_structured_data',
		'update_redirect',
		// Elementor.
		'get_page_builder',
		'get_elementor_data',
		'update_elementor_data',
		// Elementor v2 (widget-grained, v1.3.0).
		'list_elementor_widgets',
		'get_elementor_widget',
		'update_elementor_widget',
		'update_elementor_patch',
		'update_elementor_widgets_bulk',
		'find_elementor_widgets',
		'set_heading_level',
		'set_widget_setting',
		'resolve_url',
		// Safety (v1.4.0).
		'rollback',
		// Post meta + media (v1.6.0).
		'get_post_meta',
		'update_post_meta',
		'set_page_settings',
		'set_featured_image',
		'create_media',
		// IATO bridge (require API key).
		'get_iato_sitemap',
		'get_iato_nav_audit',
		'get_iato_orphan_pages',
		'get_iato_taxonomy',
		'get_iato_seo_fixes',
		'get_iato_content_gaps',
		'get_iato_broken_links',
		'get_iato_suggestions',
		'get_iato_perf_report',
		// IATO crawl management (require API key).
		'start_iato_crawl',
		'get_iato_crawl_status',
		'list_iato_crawls',
	];

	/**
	 * Tool migration backfill map: db_version gate => tool names to ensure are
	 * present in the saved `iato_mcp_tools` option.
	 *
	 * `is_tool_enabled()` returns false for any tool name not in the saved
	 * array on an upgraded install whose option was populated before the tool
	 * existed. This map drives the upgrade-time backfill in
	 * `iato_mcp_maybe_run_migrations()`: for each gate, if the install's
	 * `iato_mcp_db_version` is below the gate, the listed tools are appended
	 * to `iato_mcp_tools` (idempotent — only missing names are added).
	 *
	 * Convention: when adding a new tool to `TOOL_NAMES`, also add it here
	 * with the gate set to the release version it ships in. New installs are
	 * unaffected because their saved option starts empty (all tools enabled
	 * by default until the user first saves Settings > IATO MCP).
	 *
	 * Three of the four entries below cover migrations that historically
	 * shipped as hand-written `version_compare` blocks. The `1.6.2` entry
	 * catches the v1.2.0 crawl-management tools, which originally shipped
	 * without any migration and have been invisible on any upgraded install
	 * with a saved option for that whole interval; we backfill them now.
	 *
	 * @var array<string,list<string>>
	 */
	public const TOOL_MIGRATION_BACKFILL = [
		// v1.2.0 crawl-management tools — shipped without a migration at
		// the time, backfilled here in v1.6.2.
		'1.6.2' => [
			'start_iato_crawl',
			'get_iato_crawl_status',
			'list_iato_crawls',
		],
		// v1.3.0 widget-grained Elementor tools — migration originally
		// shipped as a hand-written block gated at < 1.3.5.
		'1.3.5' => [
			'list_elementor_widgets',
			'get_elementor_widget',
			'update_elementor_widget',
			'update_elementor_patch',
			'update_elementor_widgets_bulk',
			'find_elementor_widgets',
			'set_heading_level',
			'set_widget_setting',
			'resolve_url',
		],
		// rollback tool added in v1.4.0; the 1.4.0 + 1.4.5 hand-written
		// blocks are folded into a single 1.4.5 entry (the higher gate
		// captures every install that needs it).
		'1.4.5' => [
			'rollback',
		],
		// v1.6.0 post-meta + media tools — migration originally shipped
		// as a hand-written block gated at < 1.6.1.
		'1.6.1' => [
			'get_post_meta',
			'update_post_meta',
			'set_page_settings',
			'set_featured_image',
			'create_media',
		],
	];

	/** Tools that require the IATO API key before they'll be registered. */
	private const IATO_BRIDGE_TOOLS = [
		'get_iato_sitemap',
		'get_iato_nav_audit',
		'get_iato_orphan_pages',
		'get_iato_taxonomy',
		'get_iato_seo_fixes',
		'get_iato_content_gaps',
		'get_iato_broken_links',
		'get_iato_suggestions',
		'get_iato_perf_report',
		'start_iato_crawl',
		'get_iato_crawl_status',
		'list_iato_crawls',
	];

	/** Tool descriptions for UI display. */
	private const TOOL_DESCRIPTIONS = [
		'get_site_info'            => 'Basic site information and health',
		'get_site_settings'        => 'WordPress settings (admin only)',
		'get_posts'                => 'List published posts with filters',
		'get_post'                 => 'Single post details and meta',
		'create_post'              => 'Create new posts and pages',
		'update_post'              => 'Edit existing post content',
		'search_posts'             => 'Full-text search across posts',
		'get_seo_data'             => 'Read SEO meta fields',
		'update_seo_data'          => 'Update SEO titles and descriptions',
		'get_media'                => 'List media library items',
		'update_alt_text'          => 'Update image alt text',
		'get_comments'             => 'List and filter comments',
		'get_menus'                => 'List navigation menus',
		'get_menu_items'           => 'Menu item details and structure',
		'update_menu_item'         => 'Add items to a menu (admin only)',
		'create_menu_item'         => 'Create new menu items (admin only)',
		'delete_menu_item'         => 'Remove menu items (admin only)',
		'update_menu_item_details' => 'Edit menu item properties (admin only)',
		'get_terms'                => 'List categories, tags, and terms',
		'assign_term'              => 'Assign terms to posts',
		'create_term'              => 'Create new taxonomy terms (admin only)',
		'update_term'              => 'Edit existing terms (admin only)',
		'delete_term'              => 'Remove taxonomy terms (admin only)',
		'update_taxonomy'          => 'Replace all terms on a post',
		'update_canonical'         => 'Set canonical URL for a post',
		'update_structured_data'   => 'Add JSON-LD structured data',
		'update_redirect'          => 'Create or update redirect rules (admin only)',
		'get_page_builder'         => 'Detect which page builder a post uses',
		'get_elementor_data'       => 'Read Elementor JSON for a post (raw / compact / summary)',
		'update_elementor_data'    => 'Update Elementor JSON and regenerate rendered content',
		'list_elementor_widgets'   => 'List every widget in an Elementor post (flat or tree)',
		'get_elementor_widget'     => 'Read a single widget by ID',
		'update_elementor_widget'  => 'Patch a single widget\'s settings (with revision + idempotency)',
		'update_elementor_patch'   => 'Apply an RFC 6902 JSON Patch to the whole document',
		'update_elementor_widgets_bulk' => 'Patch many widgets across many posts in one call',
		'find_elementor_widgets'   => 'Search Elementor posts for widgets matching a filter',
		'set_heading_level'        => 'Set the header_size on a heading widget (h1-h6)',
		'set_widget_setting'       => 'Set a single key on a widget\'s settings',
		'resolve_url'              => 'Resolve a URL to its rendering post, with Theme Builder shadowing detection',
		'get_post_meta'            => 'Read post meta (single key or all) with credential-shaped keys redacted',
		'update_post_meta'         => 'Write a single post meta key (allowlist/denylist enforced; force=true to override)',
		'set_page_settings'        => 'Set per-post theme + Elementor page settings (hide title, sidebar layout, etc.)',
		'set_featured_image'       => 'Set or clear a post\'s featured image',
		'create_media'             => 'Upload an image to the media library — base64 for tiny assets (~4 KB), URL ingestion for anything larger (own host implicitly trusted)',
		'get_iato_sitemap'         => 'Full site hierarchy with WordPress post IDs attached',
		'get_iato_nav_audit'       => 'Audit menus and identify orphan pages in one call',
		'get_iato_orphan_pages'    => 'Pages not linked from any navigation menu',
		'get_iato_taxonomy'        => 'IATO categories and tags mapped to WordPress term IDs',
		'get_iato_seo_fixes'       => 'SEO issues with auto-fixable vs. manual classification',
		'get_iato_content_gaps'    => 'Thin content, missing H1, low image or link count',
		'get_iato_broken_links'    => 'Broken pages and resources mapped to source posts',
		'get_iato_suggestions'     => 'AI-prioritized improvements across SEO, content, links, performance',
		'get_iato_perf_report'     => 'Slowest and largest pages with WordPress slugs',
		'start_iato_crawl'         => 'Start a new IATO crawl of this site (admin only — consumes IATO quota)',
		'get_iato_crawl_status'    => 'Check status of a specific crawl job',
		'list_iato_crawls'         => 'List recent IATO crawl jobs with status and IDs',
	];

	/** Tool groupings for UI categories. */
	private const TOOL_CATEGORIES = [
		'Content'       => [ 'get_posts', 'get_post', 'create_post', 'update_post', 'search_posts', 'get_post_meta', 'update_post_meta', 'set_page_settings', 'set_featured_image' ],
		'Site'          => [ 'get_site_info', 'get_site_settings' ],
		'SEO'           => [ 'get_seo_data', 'update_seo_data', 'update_canonical', 'update_structured_data' ],
		'Media'         => [ 'get_media', 'update_alt_text', 'create_media' ],
		'Navigation'    => [ 'get_menus', 'get_menu_items', 'update_menu_item', 'create_menu_item', 'delete_menu_item', 'update_menu_item_details' ],
		'Taxonomy'      => [ 'get_terms', 'assign_term', 'create_term', 'update_term', 'delete_term', 'update_taxonomy' ],
		'Redirects'     => [ 'update_redirect' ],
		'Comments'      => [ 'get_comments' ],
		'Safety'        => [ 'rollback' ],
		'Elementor'     => [ 'get_page_builder', 'get_elementor_data', 'update_elementor_data' ],
		'Elementor v2'  => [ 'list_elementor_widgets', 'get_elementor_widget', 'update_elementor_widget', 'update_elementor_patch', 'update_elementor_widgets_bulk', 'find_elementor_widgets', 'set_heading_level', 'set_widget_setting', 'resolve_url' ],
		'IATO Platform' => [ 'get_iato_sitemap', 'get_iato_nav_audit', 'get_iato_orphan_pages', 'get_iato_taxonomy', 'get_iato_seo_fixes', 'get_iato_content_gaps', 'get_iato_broken_links', 'get_iato_suggestions', 'get_iato_perf_report' ],
		'Crawl Management' => [ 'start_iato_crawl', 'get_iato_crawl_status', 'list_iato_crawls' ],
	];

	/** Page hook suffix returned by add_options_page(). */
	private static string $page_hook = '';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ self::class, 'setup_wizard_notice' ] );
		add_action( 'admin_post_iato_mcp_dismiss_wizard', [ self::class, 'dismiss_wizard' ] );
		add_action( 'admin_post_iato_mcp_regenerate_key', [ self::class, 'handle_regenerate_key' ] );
		add_action( 'wp_ajax_iato_mcp_test_api_key', [ self::class, 'ajax_test_api_key' ] );
		add_action( 'wp_ajax_iato_mcp_save_settings', [ self::class, 'ajax_save_settings' ] );
	}

	/**
	 * AJAX: save settings via admin-ajax.php instead of options.php.
	 *
	 * Some hosts 503 on options.php POSTs (aggressive upstream timeout or WAF
	 * rules on admin POST bodies). admin-ajax.php typically isn't subject to
	 * the same limits. This handler applies the same sanitize callbacks the
	 * registered settings use, then updates options directly.
	 */
	public static function ajax_save_settings(): void {
		check_ajax_referer( 'iato_mcp_save_settings', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'iato-mcp' ) ], 403 );
		}

		// API key — sanitize_text_field at the read site, then sanitize_api_key
		// for the trim + clear-stale-valid-flag logic.
		$api_key_raw = isset( $_POST['iato_mcp_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['iato_mcp_api_key'] ) ) : '';
		$api_key     = self::sanitize_api_key( $api_key_raw );
		update_option( 'iato_mcp_api_key', $api_key );

		// Crawl ID.
		$crawl_id = isset( $_POST['iato_mcp_crawl_id'] ) ? sanitize_text_field( wp_unslash( $_POST['iato_mcp_crawl_id'] ) ) : '';
		update_option( 'iato_mcp_crawl_id', $crawl_id );

		// Enabled tools (unchecked boxes are absent from the POST).
		// Sanitize each element individually; sanitize_tools() additionally intersects with TOOL_NAMES.
		$tools_raw = [];
		if ( isset( $_POST['iato_mcp_tools'] ) && is_array( $_POST['iato_mcp_tools'] ) ) {
			$tools_raw = array_map( 'sanitize_text_field', wp_unslash( $_POST['iato_mcp_tools'] ) );
		}
		$tools = self::sanitize_tools( $tools_raw );
		update_option( 'iato_mcp_tools', $tools );

		// Media uploads — URL source toggle. The render emits a hidden value=0
		// sibling so the key is always present in $_POST regardless of checkbox
		// state (unchecked => "0", checked => "1" wins because PHP keeps the
		// last $_POST occurrence). rest_sanitize_boolean handles both.
		$media_url_enabled = isset( $_POST['iato_mcp_media_url_source_enabled'] )
			? rest_sanitize_boolean( wp_unslash( $_POST['iato_mcp_media_url_source_enabled'] ) )
			: false;
		update_option( 'iato_mcp_media_url_source_enabled', $media_url_enabled );

		// Host allowlist — newline-delimited textarea. sanitize_host_list()
		// strips schemes/paths and rejects malformed hostnames.
		$host_list_raw = isset( $_POST['iato_mcp_media_url_host_allowlist'] )
			? wp_unslash( $_POST['iato_mcp_media_url_host_allowlist'] )
			: '';
		$host_list = self::sanitize_host_list( $host_list_raw );
		update_option( 'iato_mcp_media_url_host_allowlist', $host_list );

		// Max upload size (bytes).
		$max_bytes = isset( $_POST['iato_mcp_media_max_upload_size'] )
			? absint( wp_unslash( $_POST['iato_mcp_media_max_upload_size'] ) )
			: 0;
		update_option( 'iato_mcp_media_max_upload_size', $max_bytes );

		// Per-user upload rate limit (per minute). 0 disables.
		$rate_limit = isset( $_POST['iato_mcp_media_upload_rate_limit'] )
			? absint( wp_unslash( $_POST['iato_mcp_media_upload_rate_limit'] ) )
			: 0;
		update_option( 'iato_mcp_media_upload_rate_limit', $rate_limit );

		wp_send_json_success( [
			'message' => __( 'Settings saved.', 'iato-mcp' ),
			'values'  => [
				'api_key_length'      => strlen( $api_key ),
				'crawl_id'            => $crawl_id,
				'tools_enabled'       => count( $tools ),
				'media_url_enabled'   => $media_url_enabled,
				'media_hosts_count'   => count( $host_list ),
				'media_max_bytes'     => $max_bytes,
				'media_rate_limit'    => $rate_limit,
			],
		] );
	}

	/**
	 * AJAX: validate the stored IATO API key against /workspaces.
	 *
	 * Runs in admin-ajax.php — not subject to the options.php upstream timeout
	 * that was 503'ing the inline validation path. Returns a structured payload
	 * the "Test connection" button uses to flip the validity badge.
	 */
	public static function ajax_test_api_key(): void {
		check_ajax_referer( 'iato_mcp_test_api_key', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'iato-mcp' ) ], 403 );
		}

		// Accept the key from the request (the button sends whatever is in the
		// input, so the user doesn't have to Save Settings before testing).
		// Falls back to the stored option if no key was sent.
		$submitted = isset( $_POST['api_key'] ) ? trim( wp_strip_all_tags( wp_unslash( $_POST['api_key'] ) ) ) : '';

		if ( '' !== $submitted ) {
			update_option( 'iato_mcp_api_key', $submitted );
		}

		$api_key = (string) get_option( 'iato_mcp_api_key', '' );
		if ( '' === $api_key ) {
			wp_send_json_error( [ 'message' => __( 'Enter an API key first.', 'iato-mcp' ) ] );
		}

		$result = IATO_MCP_IATO_Client::list_workspaces();

		if ( is_wp_error( $result ) ) {
			update_option( 'iato_mcp_api_key_valid', false );
			$http_status = (int) ( $result->get_error_data()['status'] ?? 0 );
			wp_send_json_error( [
				'message'     => $result->get_error_message(),
				'http_status' => $http_status,
				'code'        => $result->get_error_code(),
			] );
		}

		$ws_list = $result['data']['workspaces'] ?? $result['workspaces'] ?? [];
		$count   = is_array( $ws_list ) ? count( $ws_list ) : 0;

		update_option( 'iato_mcp_api_key_valid', true );

		// Persist the first workspace_id so the crawl-control bridge tools can
		// scope their requests to the right account. Without this, list_iato_crawls
		// returns empty and start_iato_crawl creates orphan jobs.
		if ( $count > 0 ) {
			$first_id = (string) ( $ws_list[0]['id'] ?? '' );
			if ( $first_id !== '' ) {
				update_option( 'iato_mcp_workspace_id', sanitize_text_field( $first_id ) );
			}
		}

		wp_send_json_success( [
			'workspace_count' => $count,
			/* translators: %d: number of IATO workspaces */
			'message'         => sprintf( _n( 'Connected — %d workspace found.', 'Connected — %d workspaces found.', $count, 'iato-mcp' ), $count ),
		] );
	}

	/**
	 * Check if a tool is enabled in settings.
	 */
	public static function is_tool_enabled( string $tool_name ): bool {
		$enabled = get_option( 'iato_mcp_tools', [] );

		// If option is empty (fresh install), all tools are enabled.
		if ( empty( $enabled ) ) {
			return true;
		}

		return in_array( $tool_name, $enabled, true );
	}

	// ── Admin Menu ───────────────────────────────────────────────────────────────

	public static function add_menu(): void {
		self::$page_hook = (string) add_options_page(
			__( 'IATO MCP', 'iato-mcp' ),
			__( 'IATO MCP', 'iato-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	// ── Register Settings ────────────────────────────────────────────────────────

	public static function register_settings(): void {

		// --- IATO API Key ---
		register_setting( self::OPTION_GROUP, 'iato_mcp_api_key', [
			'type'              => 'string',
			'sanitize_callback' => [ self::class, 'sanitize_api_key' ],
			'default'           => '',
		] );

		// --- Default Crawl ID ---
		register_setting( self::OPTION_GROUP, 'iato_mcp_crawl_id', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		// --- Enabled Tools ---
		register_setting( self::OPTION_GROUP, 'iato_mcp_tools', [
			'type'              => 'array',
			'sanitize_callback' => [ self::class, 'sanitize_tools' ],
			'default'           => [],
		] );

		// --- Media upload settings (v1.6.0) ---
		register_setting( self::OPTION_GROUP, 'iato_mcp_media_url_source_enabled', [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );
		register_setting( self::OPTION_GROUP, 'iato_mcp_media_url_host_allowlist', [
			'type'              => 'array',
			'sanitize_callback' => [ self::class, 'sanitize_host_list' ],
			'default'           => [],
		] );
		register_setting( self::OPTION_GROUP, 'iato_mcp_media_max_upload_size', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 10 * MB_IN_BYTES,
		] );
		register_setting( self::OPTION_GROUP, 'iato_mcp_media_upload_rate_limit', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 20,
		] );
	}

	/**
	 * Sanitize the URL host allowlist (one host per line on the form,
	 * stored as an array). Strips schemes, paths, and anything that
	 * isn't a plain hostname.
	 *
	 * @param mixed $value Raw value (array of strings, newline-separated string, or anything else).
	 * @return array<int,string>
	 */
	public static function sanitize_host_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}
			// Strip scheme + path if user pasted a full URL.
			if ( false !== strpos( $entry, '://' ) ) {
				$entry = (string) wp_parse_url( $entry, PHP_URL_HOST );
			}
			$entry = strtolower( $entry );
			// Hostnames: a-z, 0-9, dot, hyphen. Reject wildcards.
			if ( '' === $entry || ! preg_match( '/^[a-z0-9.\-]+$/', $entry ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return array_values( array_unique( $out ) );
	}

	// ── Sanitize Callbacks ───────────────────────────────────────────────────────

	/**
	 * Sanitize the IATO API key on save.
	 *
	 * The key is stored verbatim — no inline HTTP validation. Many shared hosts
	 * terminate the options.php POST before a network call can complete,
	 * producing a 503 on save. Validation is now an explicit user action via
	 * the "Test connection" button, which runs in admin-ajax (a separate
	 * request that isn't subject to the same upstream timeout).
	 */
	public static function sanitize_api_key( string $value ): string {
		// API keys may contain characters that sanitize_text_field strips (e.g. +, =).
		// Instead, trim whitespace and remove control characters / HTML tags.
		$value = trim( wp_strip_all_tags( $value ) );

		if ( '' === $value ) {
			delete_option( 'iato_mcp_api_key_valid' );
			return '';
		}

		// If the key has changed, clear the stale validity flag — user needs to re-test.
		$previous = (string) get_option( 'iato_mcp_api_key', '' );
		if ( $previous !== $value ) {
			delete_option( 'iato_mcp_api_key_valid' );
		}

		return $value;
	}

	/**
	 * Sanitize the enabled tools array.
	 */
	public static function sanitize_tools( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_intersect( array_map( 'sanitize_text_field', $value ), self::TOOL_NAMES ) );
	}

	// ── Settings Page ────────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switcher; no state mutation occurs from this parameter.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! in_array( $active_tab, [ 'general', 'diagnostics' ], true ) ) {
			$active_tab = 'general';
		}

		$tabs = [
			'general'     => [
				'label' => __( 'General', 'iato-mcp' ),
				'url'   => admin_url( 'options-general.php?page=' . self::PAGE_SLUG ),
			],
			'diagnostics' => [
				'label' => __( 'Diagnostics', 'iato-mcp' ),
				'url'   => admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=diagnostics' ),
			],
		];

		if ( 'diagnostics' === $active_tab ) {
			?>
			<div class="iato-wrap">
				<div class="iato-header">
					<div class="iato-header-top">
						<h1 class="iato-title"><?php echo iato_mcp_logo_svg( 36 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns self-escaped <img> markup (attributes wrapped in esc_attr inside the helper); fallback is a static <span>. ?> <span class="iato-title-mcp">MCP</span></h1>
						<span class="iato-version">v<?php echo esc_html( IATO_MCP_VERSION ); ?></span>
					</div>
					<p class="iato-subtitle"><?php esc_html_e( 'Model Context Protocol server for WordPress', 'iato-mcp' ); ?></p>
				</div>

				<h2 class="nav-tab-wrapper iato-tabs">
					<?php foreach ( $tabs as $slug => $tab ) : ?>
						<a href="<?php echo esc_url( $tab['url'] ); ?>" class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
							<?php echo esc_html( $tab['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</h2>

				<?php settings_errors(); ?>
				<?php IATO_MCP_Diagnostics::render(); ?>
			</div>
			<?php
			return;
		}

		$mcp_key      = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );
		$endpoint     = rest_url( 'iato-mcp/v1/message' );
		$iato_api_key = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$api_valid    = (bool) get_option( 'iato_mcp_api_key_valid', false );
		$crawl_id     = sanitize_text_field( get_option( 'iato_mcp_crawl_id', '' ) );
		$enabled      = get_option( 'iato_mcp_tools', [] );
		$all_on       = empty( $enabled );

		$media_url_enabled = (bool) get_option( 'iato_mcp_media_url_source_enabled', false );
		$media_host_list   = (array) get_option( 'iato_mcp_media_url_host_allowlist', [] );
		$media_max_bytes   = (int) get_option( 'iato_mcp_media_max_upload_size', 10 * MB_IN_BYTES );
		$media_rate_limit  = (int) get_option( 'iato_mcp_media_upload_rate_limit', 20 );
		$media_host_text   = implode( "\n", array_map( 'strval', $media_host_list ) );
		$media_max_mb      = $media_max_bytes > 0 ? round( $media_max_bytes / MB_IN_BYTES, 2 ) : 0;

		$regenerate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=iato_mcp_regenerate_key' ),
			'iato_mcp_regenerate_key'
		);

		$masked_key = '';
		if ( $mcp_key ) {
			$masked_key = substr( $mcp_key, 0, 8 ) . '••••••••' . substr( $mcp_key, -4 );
		}

		$config_json = wp_json_encode( [
			'mcpServers' => [
				iato_mcp_connection_name() => [
					'url'     => $endpoint,
					'headers' => [
						'Authorization' => 'Bearer ' . $mcp_key,
					],
				],
			],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$enabled_count = $all_on ? count( self::TOOL_NAMES ) : count( $enabled );
		$total_count   = count( self::TOOL_NAMES );

		?>
		<div class="iato-wrap">
			<div class="iato-header">
				<div class="iato-header-top">
					<h1 class="iato-title"><?php echo iato_mcp_logo_svg( 36 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns self-escaped <img> markup (attributes wrapped in esc_attr inside the helper); fallback is a static <span>. ?> <span class="iato-title-mcp">MCP</span></h1>
					<span class="iato-version">v<?php echo esc_html( IATO_MCP_VERSION ); ?></span>
				</div>
				<p class="iato-subtitle"><?php esc_html_e( 'Model Context Protocol server for WordPress', 'iato-mcp' ); ?></p>
			</div>

			<h2 class="nav-tab-wrapper iato-tabs">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a href="<?php echo esc_url( $tab['url'] ); ?>" class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<!-- Card 1: MCP Connection -->
				<div class="iato-card iato-card--hero">
					<div class="iato-card-header">
						<div class="iato-card-title">
							<span class="dashicons dashicons-admin-network"></span>
							<h2><?php esc_html_e( 'MCP Connection', 'iato-mcp' ); ?></h2>
						</div>
						<?php if ( $mcp_key ) : ?>
							<span class="iato-badge iato-badge--success"><?php esc_html_e( 'Ready', 'iato-mcp' ); ?></span>
						<?php else : ?>
							<span class="iato-badge iato-badge--warning"><?php esc_html_e( 'Key Missing', 'iato-mcp' ); ?></span>
						<?php endif; ?>
					</div>

					<div class="iato-field-row">
						<label class="iato-label"><?php esc_html_e( 'Endpoint URL', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<div class="iato-code-block">
								<code id="iato-endpoint"><?php echo esc_html( $endpoint ); ?></code>
								<button type="button" class="iato-copy-btn" data-target="iato-endpoint" title="<?php esc_attr_e( 'Copy', 'iato-mcp' ); ?>">
									<span class="dashicons dashicons-clipboard"></span>
								</button>
							</div>
						</div>
					</div>

					<div class="iato-field-row">
						<label class="iato-label"><?php esc_html_e( 'API Key', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<div class="iato-key-row">
								<div class="iato-code-block">
									<code id="iato-mcp-key" class="iato-key-masked" data-full="<?php echo esc_attr( $mcp_key ); ?>"><?php echo esc_html( $masked_key ); ?></code>
									<button type="button" class="iato-copy-btn" data-copy-value="<?php echo esc_attr( $mcp_key ); ?>" title="<?php esc_attr_e( 'Copy key', 'iato-mcp' ); ?>">
										<span class="dashicons dashicons-clipboard"></span>
									</button>
								</div>
								<button type="button" class="iato-reveal-btn" data-target="iato-mcp-key" title="<?php esc_attr_e( 'Show/hide key', 'iato-mcp' ); ?>">
									<span class="dashicons dashicons-visibility"></span>
								</button>
								<a href="<?php echo esc_url( $regenerate_url ); ?>" class="iato-btn iato-btn--danger" onclick="return confirm('<?php echo esc_js( __( 'Regenerate key? Existing clients will need the new key.', 'iato-mcp' ) ); ?>');">
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e( 'Regenerate', 'iato-mcp' ); ?>
								</a>
							</div>
							<p class="iato-hint"><?php esc_html_e( 'Used in the Authorization: Bearer header. Keep this secret.', 'iato-mcp' ); ?></p>
						</div>
					</div>

					<div class="iato-config-section">
						<h3 class="iato-config-title"><?php esc_html_e( 'HTTP MCP clients (MCP Inspector, IDEs, scripts)', 'iato-mcp' ); ?></h3>
						<p class="iato-hint">
							<?php
							printf(
								/* translators: %s: link to setup wizard */
								esc_html__( 'For clients that speak HTTP MCP directly. Claude Desktop, Cursor, Cline, Zed and other stdio-only clients need a different config — see the %s.', 'iato-mcp' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=iato-mcp-setup' ) ) . '">' . esc_html__( 'setup wizard', 'iato-mcp' ) . '</a>'
							);
							?>
						</p>
						<div class="iato-config-block">
							<pre id="iato-config-json"><?php echo esc_html( $config_json ); ?></pre>
							<button type="button" class="iato-copy-btn iato-copy-btn--config" data-target="iato-config-json" title="<?php esc_attr_e( 'Copy config', 'iato-mcp' ); ?>">
								<span class="dashicons dashicons-clipboard"></span>
								<span class="iato-copy-label"><?php esc_html_e( 'Copy', 'iato-mcp' ); ?></span>
							</button>
						</div>
					</div>
				</div>

				<!-- Card 2: IATO Platform -->
				<div class="iato-card">
					<div class="iato-card-header">
						<div class="iato-card-title">
							<span class="dashicons dashicons-cloud"></span>
							<h2><?php esc_html_e( 'IATO Platform', 'iato-mcp' ); ?></h2>
						</div>
						<span id="iato-platform-badge">
							<?php if ( $iato_api_key && $api_valid ) : ?>
								<span class="iato-badge iato-badge--success"><?php esc_html_e( 'Connected', 'iato-mcp' ); ?></span>
							<?php elseif ( $iato_api_key ) : ?>
								<span class="iato-badge iato-badge--neutral"><?php esc_html_e( 'Not validated', 'iato-mcp' ); ?></span>
							<?php else : ?>
								<span class="iato-badge iato-badge--neutral"><?php esc_html_e( 'Not connected', 'iato-mcp' ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<p class="iato-card-desc"><?php esc_html_e( 'Connect your IATO account to enable bridge tools for sitemap analysis, SEO audits, and performance reports.', 'iato-mcp' ); ?></p>

					<div class="iato-field-row">
						<label class="iato-label" for="iato_mcp_api_key"><?php esc_html_e( 'API Key', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<div class="iato-input-group">
								<input type="password" name="iato_mcp_api_key" id="iato_mcp_api_key" value="<?php echo esc_attr( $iato_api_key ); ?>" class="iato-input" autocomplete="off" placeholder="<?php esc_attr_e( 'Enter your IATO API key', 'iato-mcp' ); ?>" />
								<button type="button" class="iato-input-toggle" data-toggle="iato_mcp_api_key" title="<?php esc_attr_e( 'Show/hide', 'iato-mcp' ); ?>">
									<span class="dashicons dashicons-visibility"></span>
								</button>
							</div>
							<div class="iato-test-row">
								<button type="button" class="button" id="iato-test-api-key" <?php echo empty( $iato_api_key ) ? 'disabled' : ''; ?>>
									<?php esc_html_e( 'Test connection', 'iato-mcp' ); ?>
								</button>
								<span id="iato-test-api-key-status" class="iato-test-status">
									<?php if ( '' === $iato_api_key ) : ?>
										<span class="iato-badge iato-badge--neutral"><?php esc_html_e( 'No key', 'iato-mcp' ); ?></span>
									<?php elseif ( $api_valid ) : ?>
										<span class="iato-badge iato-badge--success"><?php esc_html_e( 'Validated', 'iato-mcp' ); ?></span>
									<?php else : ?>
										<span class="iato-badge iato-badge--neutral"><?php esc_html_e( 'Not validated', 'iato-mcp' ); ?></span>
									<?php endif; ?>
								</span>
							</div>
							<p class="iato-hint"><?php esc_html_e( 'Click Test connection to validate the key against IATO. Saving is automatic when you test, or use Save Settings below.', 'iato-mcp' ); ?></p>
						</div>
					</div>

					<div class="iato-field-row">
						<label class="iato-label" for="iato_mcp_crawl_id"><?php esc_html_e( 'Default Crawl ID', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<input type="text" name="iato_mcp_crawl_id" id="iato_mcp_crawl_id" value="<?php echo esc_attr( $crawl_id ); ?>" class="iato-input" placeholder="<?php esc_attr_e( 'e.g. crawl_abc123', 'iato-mcp' ); ?>" />
							<p class="iato-hint">
								<?php
								printf(
									/* translators: %s: link to iato.ai dashboard */
									esc_html__( 'Used by bridge tools when no crawl ID is specified in the request. If you have never crawled this site, start a crawl from your %s first — bridge tools return empty results until a crawl exists.', 'iato-mcp' ),
									'<a href="https://iato.ai/dashboard" target="_blank" rel="noopener">' . esc_html__( 'IATO dashboard', 'iato-mcp' ) . '</a>'
								);
								?>
							</p>
						</div>
					</div>
				</div>

				<!-- Card 3: Tools -->
				<div class="iato-card">
					<div class="iato-card-header">
						<div class="iato-card-title">
							<span class="dashicons dashicons-admin-tools"></span>
							<h2><?php esc_html_e( 'Tools', 'iato-mcp' ); ?></h2>
						</div>
						<span class="iato-tools-count" id="iato-tools-count">
							<?php
							/* translators: %1$d: enabled count, %2$d: total count */
							printf( esc_html__( '%1$d of %2$d enabled', 'iato-mcp' ), (int) $enabled_count, (int) $total_count );
							?>
						</span>
					</div>
					<p class="iato-card-desc"><?php esc_html_e( 'Choose which MCP tools are available to AI clients.', 'iato-mcp' ); ?></p>

					<?php foreach ( self::TOOL_CATEGORIES as $category => $tools ) :
						$is_iato_category = in_array( $category, [ 'IATO Platform', 'Crawl Management' ], true );
						$api_key_present  = ! empty( $iato_api_key );
						// Gate the bridge categories visually when no API key is set — the
						// per-tool toggles for these are placebo without a key (the bridge
						// tool files don't even load — see iato-mcp.php:85). Disable inputs
						// + show a hint so the UI matches the actual registration logic.
						$category_gated = $is_iato_category && ! $api_key_present;
						$category_class = 'iato-tool-category';
						if ( $category_gated ) {
							$category_class .= ' iato-tool-category--gated';
						}
						?>
						<div class="<?php echo esc_attr( $category_class ); ?>">
							<div class="iato-tool-category-header">
								<h3>
									<?php echo esc_html( $category ); ?>
									<?php if ( $is_iato_category ) : ?>
										<span class="iato-category-hint" id="iato-platform-cat-hint">
											<?php
											echo $api_key_present
												? esc_html__( '— requires IATO API key ✓', 'iato-mcp' )
												: esc_html__( '— requires IATO API key', 'iato-mcp' );
											?>
										</span>
									<?php endif; ?>
								</h3>
								<div class="iato-tool-category-actions">
									<button type="button" class="iato-link-btn iato-select-all" <?php echo $category_gated ? 'disabled' : ''; ?>><?php esc_html_e( 'All', 'iato-mcp' ); ?></button>
									<span class="iato-separator">|</span>
									<button type="button" class="iato-link-btn iato-select-none" <?php echo $category_gated ? 'disabled' : ''; ?>><?php esc_html_e( 'None', 'iato-mcp' ); ?></button>
								</div>
							</div>
							<?php if ( $category_gated ) : ?>
								<p class="iato-category-banner">
									<?php esc_html_e( 'These tools require an IATO API key. Add it under "IATO Platform" above to enable them — until then, these toggles have no effect.', 'iato-mcp' ); ?>
								</p>
							<?php endif; ?>
							<div class="iato-tool-grid">
								<?php foreach ( $tools as $tool ) :
									$checked = $all_on || in_array( $tool, $enabled, true );
									$desc    = self::TOOL_DESCRIPTIONS[ $tool ] ?? '';
								?>
									<label class="iato-tool-item<?php echo $category_gated ? ' iato-tool-item--gated' : ''; ?>">
										<div class="iato-toggle">
											<input type="checkbox" name="iato_mcp_tools[]" value="<?php echo esc_attr( $tool ); ?>" <?php checked( $checked ); ?> <?php echo $category_gated ? 'disabled' : ''; ?> />
											<span class="iato-toggle-slider" role="switch" aria-checked="<?php echo $checked ? 'true' : 'false'; ?>"></span>
										</div>
										<div class="iato-tool-info">
											<code class="iato-tool-name"><?php echo esc_html( $tool ); ?></code>
											<?php if ( $desc ) : ?>
												<span class="iato-tool-desc"><?php echo esc_html( $desc ); ?></span>
											<?php endif; ?>
										</div>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Card 4: Media Uploads -->
				<div class="iato-card">
					<div class="iato-card-header">
						<div class="iato-card-title">
							<span class="dashicons dashicons-format-image"></span>
							<h2><?php esc_html_e( 'Media Uploads', 'iato-mcp' ); ?></h2>
						</div>
						<?php if ( $media_url_enabled ) : ?>
							<span class="iato-badge iato-badge--success"><?php esc_html_e( 'URL ingestion on', 'iato-mcp' ); ?></span>
						<?php else : ?>
							<span class="iato-badge iato-badge--neutral"><?php esc_html_e( 'Base64 only', 'iato-mcp' ); ?></span>
						<?php endif; ?>
					</div>
					<p class="iato-card-desc"><?php esc_html_e( 'Controls for the create_media tool: URL ingestion gating, host allowlist, size and rate caps. Base64 uploads are always allowed; URL ingestion is opt-in and restricted to the allowlist below.', 'iato-mcp' ); ?></p>

					<div class="iato-field-row">
						<label class="iato-label" for="iato_mcp_media_url_source_enabled"><?php esc_html_e( 'URL source', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<input type="hidden" name="iato_mcp_media_url_source_enabled" value="0" />
							<label style="display:inline-flex;align-items:center;gap:8px;">
								<input type="checkbox" name="iato_mcp_media_url_source_enabled" id="iato_mcp_media_url_source_enabled" value="1" <?php checked( $media_url_enabled ); ?> />
								<span><?php esc_html_e( 'Allow create_media to fetch images from an https URL', 'iato-mcp' ); ?></span>
							</label>
							<p class="iato-hint"><?php esc_html_e( 'SSRF guards (private / loopback / link-local / cloud-metadata IP rejection) still apply when enabled. Base64 remains the default safe path; turn this on only when an agent needs to ingest images by URL.', 'iato-mcp' ); ?></p>
						</div>
					</div>

					<div class="iato-field-row">
						<label class="iato-label" for="iato_mcp_media_url_host_allowlist"><?php esc_html_e( 'Host allowlist', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<textarea name="iato_mcp_media_url_host_allowlist" id="iato_mcp_media_url_host_allowlist" class="iato-input" rows="4" placeholder="cdn.example.com&#10;images.example.org" style="font-family:JetBrains Mono,monospace;font-size:12px;width:100%;"><?php echo esc_textarea( $media_host_text ); ?></textarea>
							<p class="iato-hint"><?php esc_html_e( 'One hostname per line. Only these hosts are accepted for URL-source uploads. Schemes and paths are stripped on save. Wildcards are not supported.', 'iato-mcp' ); ?></p>
						</div>
					</div>

					<div class="iato-field-row">
						<label class="iato-label" for="iato_mcp_media_max_upload_size"><?php esc_html_e( 'Max upload size', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<input type="number" name="iato_mcp_media_max_upload_size" id="iato_mcp_media_max_upload_size" value="<?php echo esc_attr( (string) $media_max_bytes ); ?>" min="0" step="1024" class="iato-input" style="max-width:240px;" />
							<p class="iato-hint">
								<?php
								printf(
									/* translators: %s: size in megabytes */
									esc_html__( 'Bytes. Currently ≈ %s MB. Decoded base64 or fetched URL payloads exceeding this are rejected with file_too_large. Default 10485760 (10 MB).', 'iato-mcp' ),
									esc_html( (string) $media_max_mb )
								);
								?>
							</p>
						</div>
					</div>

					<div class="iato-field-row">
						<label class="iato-label" for="iato_mcp_media_upload_rate_limit"><?php esc_html_e( 'Rate limit', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<input type="number" name="iato_mcp_media_upload_rate_limit" id="iato_mcp_media_upload_rate_limit" value="<?php echo esc_attr( (string) $media_rate_limit ); ?>" min="0" step="1" class="iato-input" style="max-width:240px;" />
							<p class="iato-hint"><?php esc_html_e( 'Uploads per minute per authenticated user. Set to 0 to disable rate limiting. Default 20.', 'iato-mcp' ); ?></p>
						</div>
					</div>
				</div>

				<div class="iato-submit">
					<?php submit_button( __( 'Save Settings', 'iato-mcp' ), 'primary large', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<?php
	}

	// ── Asset Enqueue ────────────────────────────────────────────────────────────

	/**
	 * Enqueue inline CSS and JS for the settings page only.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( self::$page_hook === '' || $hook !== self::$page_hook ) {
			return;
		}

		wp_enqueue_style( 'iato-mcp-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono&display=swap', [], IATO_MCP_VERSION );

		wp_register_style( 'iato-mcp-admin-settings', false, [], IATO_MCP_VERSION );
		wp_enqueue_style( 'iato-mcp-admin-settings' );
		wp_add_inline_style( 'iato-mcp-admin-settings', self::get_inline_styles() );

		// Diagnostics tab styles — loaded alongside general-tab styles so
		// navigating between tabs doesn't require a reload cycle.
		if ( class_exists( 'IATO_MCP_Diagnostics' ) ) {
			wp_add_inline_style( 'iato-mcp-admin-settings', IATO_MCP_Diagnostics::get_inline_styles() );
		}

		wp_register_script( 'iato-mcp-admin-settings', false, [], IATO_MCP_VERSION, true );
		wp_enqueue_script( 'iato-mcp-admin-settings' );
		wp_add_inline_script( 'iato-mcp-admin-settings', self::get_inline_scripts() );
		wp_localize_script( 'iato-mcp-admin-settings', 'iatoMcpSettings', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'testKeyNonce' => wp_create_nonce( 'iato_mcp_test_api_key' ),
			'saveNonce'    => wp_create_nonce( 'iato_mcp_save_settings' ),
		] );
	}

	// ── Styles ───────────────────────────────────────────────────────────────────

	private static function get_inline_styles(): string {
		return <<<'CSS'
			/* ── Reset & Variables ──────────────────────────────── */
			.iato-wrap {
				--iato-primary: #5a89f4;
				--iato-primary-hover: #3f64b8;
				--iato-primary-light: rgba(90,137,244,0.12);
				--iato-primary-btn: #4b72cc;
				--iato-success: #38d68e;
				--iato-success-bg: rgba(56,214,142,0.12);
				--iato-warning: #eda145;
				--iato-warning-bg: rgba(237,161,69,0.12);
				--iato-danger: #ef4444;
				--iato-danger-bg: rgba(239,68,68,0.12);
				--iato-neutral: #6b7280;
				--iato-neutral-bg: #f3f4f6;
				--iato-bg: #f3f4f6;
				--iato-card-bg: #ffffff;
				--iato-border: #e5e7eb;
				--iato-text: #111827;
				--iato-text-secondary: #6b7280;
				--iato-text-muted: #9ca3af;
				--iato-code-bg: #0b0d17;
				--iato-code-text: #e6e8f0;
				--iato-radius: 12px;
				--iato-radius-sm: 8px;

				max-width: 860px;
				margin: 20px auto 40px;
				padding: 0 20px;
				font-family: 'DM Sans', system-ui, sans-serif;
			}

			/* ── Header ────────────────────────────────────────── */
			.iato-header {
				margin-bottom: 16px;
			}
			.iato-tabs {
				margin: 0 0 20px;
			}
			.iato-header-top {
				display: flex;
				align-items: center;
				gap: 12px;
			}
			.iato-title {
				font-size: 28px;
				font-weight: 400;
				font-family: 'Instrument Serif', Georgia, serif;
				color: var(--iato-primary);
				margin: 0;
				letter-spacing: -0.5px;
			}
			.iato-title-mcp {
				font-weight: 400;
				color: var(--iato-text-secondary);
			}
			.iato-version {
				display: inline-block;
				padding: 2px 10px;
				font-size: 12px;
				font-weight: 600;
				color: var(--iato-primary);
				background: var(--iato-primary-light);
				border-radius: 20px;
			}
			.iato-subtitle {
				margin: 4px 0 0;
				color: var(--iato-text-secondary);
				font-size: 14px;
			}

			/* ── Cards ─────────────────────────────────────────── */
			.iato-card {
				background: var(--iato-card-bg);
				border: 1px solid var(--iato-border);
				border-radius: var(--iato-radius);
				padding: 24px;
				margin-bottom: 20px;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.06);
				transition: background 0.2s, box-shadow 0.2s;
			}
			.iato-card--hero {
				border-left: 4px solid var(--iato-primary);
			}
			.iato-card-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				margin-bottom: 20px;
				padding-bottom: 16px;
				border-bottom: 1px solid var(--iato-border);
			}
			.iato-card-title {
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.iato-card-title .dashicons {
				font-size: 22px;
				width: 22px;
				height: 22px;
				color: var(--iato-primary);
			}
			.iato-card-title h2 {
				margin: 0;
				font-size: 17px;
				font-weight: 600;
				color: var(--iato-text);
			}
			.iato-card-desc {
				color: var(--iato-text-secondary);
				font-size: 13px;
				margin: -12px 0 20px;
			}

			/* ── Badges ────────────────────────────────────────── */
			.iato-badge {
				display: inline-flex;
				align-items: center;
				gap: 5px;
				padding: 4px 12px;
				font-size: 12px;
				font-weight: 600;
				border-radius: 99px;
				line-height: 1;
			}
			.iato-badge--success {
				color: var(--iato-success);
				background: var(--iato-success-bg);
			}
			.iato-badge--success::before {
				content: '';
				display: inline-block;
				width: 7px;
				height: 7px;
				background: var(--iato-success);
				border-radius: 50%;
			}
			.iato-badge--warning {
				color: var(--iato-warning);
				background: var(--iato-warning-bg);
			}
			.iato-badge--warning::before {
				content: '';
				display: inline-block;
				width: 7px;
				height: 7px;
				background: var(--iato-warning);
				border-radius: 50%;
			}
			.iato-badge--neutral {
				color: var(--iato-neutral);
				background: var(--iato-neutral-bg);
			}
			.iato-test-row {
				display: flex;
				align-items: center;
				gap: 10px;
				margin-top: 8px;
				flex-wrap: wrap;
			}
			.iato-test-status {
				display: inline-flex;
				align-items: center;
				gap: 8px;
			}
			.iato-test-status .iato-hint {
				margin: 0;
			}
			.iato-badge--danger {
				color: var(--iato-danger);
				background: var(--iato-danger-bg);
			}
			.iato-badge--danger::before {
				content: '';
				display: inline-block;
				width: 7px;
				height: 7px;
				background: var(--iato-danger);
				border-radius: 50%;
			}

			/* ── Field Rows ────────────────────────────────────── */
			.iato-field-row {
				display: flex;
				gap: 16px;
				margin-bottom: 20px;
			}
			.iato-field-row:last-child {
				margin-bottom: 0;
			}
			.iato-label {
				flex: 0 0 140px;
				font-size: 13px;
				font-weight: 600;
				color: var(--iato-text);
				padding-top: 8px;
			}
			.iato-field-value {
				flex: 1;
				min-width: 0;
			}
			.iato-hint {
				margin: 6px 0 0;
				font-size: 12px;
				color: var(--iato-text-muted);
			}

			/* ── Code Blocks (light) ──────────────────────────── */
			.iato-code-block {
				display: flex;
				align-items: center;
				background: var(--iato-bg);
				border: 1px solid var(--iato-border);
				border-radius: var(--iato-radius-sm);
				padding: 8px 12px;
				gap: 8px;
				min-width: 0;
			}
			.iato-code-block code {
				flex: 1;
				font-size: 13px;
				color: var(--iato-text);
				background: none;
				padding: 0;
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
			}

			/* ── Key Row ──────────────────────────────────────── */
			.iato-key-row {
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.iato-key-row .iato-code-block {
				flex: 1;
			}
			.iato-key-masked {
				font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
				letter-spacing: 0.5px;
			}

			/* ── Copy Button ──────────────────────────────────── */
			.iato-copy-btn {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 4px 8px;
				border: none;
				background: transparent;
				color: var(--iato-text-muted);
				cursor: pointer;
				border-radius: 4px;
				transition: color 0.15s, background 0.15s;
				flex-shrink: 0;
			}
			.iato-copy-btn:hover {
				color: var(--iato-primary);
				background: var(--iato-primary-light);
			}
			.iato-copy-btn .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
			}
			.iato-copy-btn.copied {
				color: var(--iato-success);
			}

			/* ── Reveal Button ────────────────────────────────── */
			.iato-reveal-btn {
				display: inline-flex;
				align-items: center;
				padding: 6px;
				border: 1px solid var(--iato-border);
				background: var(--iato-card-bg);
				color: var(--iato-text-muted);
				cursor: pointer;
				border-radius: var(--iato-radius-sm);
				transition: color 0.15s, border-color 0.15s;
			}
			.iato-reveal-btn:hover {
				color: var(--iato-text-secondary);
				border-color: var(--iato-text-muted);
			}
			.iato-reveal-btn .dashicons {
				font-size: 18px;
				width: 18px;
				height: 18px;
			}

			/* ── Buttons ──────────────────────────────────────── */
			.iato-btn {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 6px 14px;
				font-size: 12px;
				font-weight: 500;
				border-radius: var(--iato-radius-sm);
				text-decoration: none;
				cursor: pointer;
				transition: background 0.15s, color 0.15s;
				border: none;
				white-space: nowrap;
			}
			.iato-btn .dashicons {
				font-size: 14px;
				width: 14px;
				height: 14px;
			}
			.iato-btn--danger {
				color: var(--iato-danger);
				background: var(--iato-danger-bg);
			}
			.iato-btn--danger:hover {
				background: rgba(239,68,68,0.2);
				color: var(--iato-danger);
			}

			/* ── Config Block (dark) ─────────────────────────── */
			.iato-config-section {
				margin-top: 24px;
				padding-top: 20px;
				border-top: 1px solid var(--iato-border);
			}
			.iato-config-title {
				font-size: 14px;
				font-weight: 600;
				color: var(--iato-text);
				margin: 0 0 4px;
			}
			.iato-config-block {
				position: relative;
				background: var(--iato-code-bg);
				border-radius: var(--iato-radius-sm);
				overflow: hidden;
				margin-top: 10px;
			}
			.iato-config-block pre {
				margin: 0;
				padding: 20px;
				padding-right: 80px;
				font-size: 13px;
				line-height: 1.6;
				color: var(--iato-code-text);
				font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
				overflow-x: auto;
				white-space: pre;
				tab-size: 2;
			}
			.iato-copy-btn--config {
				position: absolute;
				top: 10px;
				right: 10px;
				background: rgba(255, 255, 255, 0.1);
				color: rgba(255, 255, 255, 0.7);
				padding: 6px 12px;
				border-radius: 6px;
			}
			.iato-copy-btn--config:hover {
				background: rgba(255, 255, 255, 0.2);
				color: #fff;
			}
			.iato-copy-label {
				font-size: 12px;
				font-weight: 500;
			}

			/* ── Input Fields ────────────────────────────────── */
			.iato-input-group {
				display: flex;
				align-items: center;
				border: 1px solid var(--iato-border);
				border-radius: var(--iato-radius-sm);
				overflow: hidden;
				transition: border-color 0.15s, box-shadow 0.15s;
			}
			.iato-input-group:focus-within {
				border-color: var(--iato-primary);
				box-shadow: 0 0 0 2px rgba(90,137,244,0.1);
			}
			.iato-input {
				flex: 1;
				padding: 8px 12px;
				border: none;
				outline: none;
				font-size: 14px;
				font-family: inherit;
				background: transparent;
				color: var(--iato-text);
			}
			.iato-input-group + .iato-hint,
			.iato-field-value > .iato-input {
				width: 100%;
				max-width: 400px;
			}
			.iato-field-value > .iato-input {
				border: 1px solid var(--iato-border);
				border-radius: var(--iato-radius-sm);
				transition: border-color 0.15s, box-shadow 0.15s;
			}
			.iato-field-value > .iato-input:focus {
				border-color: var(--iato-primary);
				box-shadow: 0 0 0 2px rgba(90,137,244,0.1);
				outline: none;
			}
			.iato-input-toggle {
				display: inline-flex;
				align-items: center;
				padding: 8px 10px;
				border: none;
				border-left: 1px solid var(--iato-border);
				background: var(--iato-bg);
				color: var(--iato-text-muted);
				cursor: pointer;
				transition: color 0.15s;
			}
			.iato-input-toggle:hover {
				color: var(--iato-text-secondary);
			}
			.iato-input-toggle .dashicons {
				font-size: 18px;
				width: 18px;
				height: 18px;
			}

			/* ── Tool Categories ──────────────────────────────── */
			.iato-tool-category {
				margin-top: 20px;
				padding-top: 16px;
				border-top: 1px solid var(--iato-border);
			}
			.iato-tool-category:first-of-type {
				margin-top: 0;
				padding-top: 0;
				border-top: none;
			}
			.iato-tool-category-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				margin-bottom: 12px;
			}
			.iato-tool-category-header h3 {
				margin: 0;
				font-size: 11px;
				font-weight: 600;
				color: var(--iato-text-secondary);
				text-transform: uppercase;
				letter-spacing: 0.06em;
			}
			.iato-category-hint {
				font-size: 10px;
				font-weight: 500;
				color: var(--iato-text-muted);
				text-transform: none;
				letter-spacing: normal;
				margin-left: 6px;
			}
			.iato-tool-category--gated .iato-tool-grid {
				opacity: 0.55;
			}
			.iato-tool-category--gated .iato-tool-item {
				cursor: not-allowed;
			}
			.iato-tool-category--gated input[type=checkbox]:disabled + .iato-toggle-slider {
				cursor: not-allowed;
			}
			.iato-category-banner {
				margin: 0 0 12px;
				padding: 8px 12px;
				background: rgba(245, 158, 11, 0.08);
				border-left: 3px solid #f59e0b;
				border-radius: 4px;
				font-size: 12px;
				color: var(--iato-text-secondary);
				line-height: 1.5;
			}
			.iato-tool-category-actions {
				display: flex;
				align-items: center;
				gap: 4px;
			}
			.iato-link-btn {
				padding: 2px 6px;
				border: none;
				background: none;
				color: var(--iato-primary);
				font-size: 12px;
				cursor: pointer;
				border-radius: 4px;
			}
			.iato-link-btn:hover {
				background: var(--iato-primary-light);
			}
			.iato-separator {
				color: var(--iato-border);
				font-size: 12px;
			}

			/* ── Tool Grid ────────────────────────────────────── */
			.iato-tool-grid {
				display: grid;
				grid-template-columns: repeat(2, 1fr);
				gap: 8px;
			}
			@media (max-width: 782px) {
				.iato-tool-grid {
					grid-template-columns: 1fr;
				}
				.iato-field-row {
					flex-direction: column;
					gap: 4px;
				}
				.iato-label {
					flex: none;
					padding-top: 0;
				}
			}
			.iato-tool-item {
				display: flex;
				align-items: center;
				gap: 12px;
				padding: 10px 12px;
				border-radius: var(--iato-radius-sm);
				cursor: pointer;
				transition: background 0.15s;
			}
			.iato-tool-item:hover {
				background: var(--iato-bg);
			}
			.iato-tool-info {
				display: flex;
				flex-direction: column;
				gap: 2px;
				min-width: 0;
			}
			.iato-tool-name {
				font-size: 12px;
				color: var(--iato-text);
				background: none;
				padding: 0;
				font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
			}
			.iato-tool-desc {
				font-size: 11px;
				color: var(--iato-text-muted);
			}

			/* ── Toggle Switch ────────────────────────────────── */
			.iato-toggle {
				position: relative;
				width: 40px;
				height: 22px;
				flex-shrink: 0;
			}
			.iato-toggle input {
				opacity: 0;
				width: 0;
				height: 0;
				position: absolute;
			}
			.iato-toggle-slider {
				position: absolute;
				inset: 0;
				background: #cbd5e1;
				border-radius: 22px;
				transition: background 0.2s;
				cursor: pointer;
			}
			.iato-toggle-slider::before {
				content: '';
				position: absolute;
				height: 16px;
				width: 16px;
				left: 3px;
				bottom: 3px;
				background: #fff;
				border-radius: 50%;
				transition: transform 0.2s;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
			}
			.iato-toggle input:checked + .iato-toggle-slider {
				background: var(--iato-primary);
			}
			.iato-toggle input:checked + .iato-toggle-slider::before {
				transform: translateX(18px);
			}
			.iato-toggle input:focus-visible + .iato-toggle-slider {
				box-shadow: 0 0 0 2px var(--iato-primary-light);
			}

			/* ── Tools Count ──────────────────────────────────── */
			.iato-tools-count {
				font-size: 12px;
				color: var(--iato-text-muted);
				font-weight: 500;
			}

			/* ── Submit ───────────────────────────────────────── */
			.iato-submit {
				margin-top: 4px;
			}
			.iato-submit .button-primary {
				padding: 8px 24px;
				height: auto;
				font-size: 13.5px;
				font-weight: 600;
				font-family: 'DM Sans', system-ui, sans-serif;
				background: var(--iato-primary-btn);
				border-color: var(--iato-primary-btn);
				border-radius: 8px;
				box-shadow: 0 0 24px rgba(90,137,244,0.18);
				transition: all 0.2s;
			}
			.iato-submit .button-primary:hover {
				background: var(--iato-primary-hover);
				border-color: var(--iato-primary-hover);
				box-shadow: 0 0 36px rgba(90,137,244,0.3);
			}

			/* ── Re-sync button ──────────────────────────────── */
			.iato-btn:hover {
				opacity: 0.85;
			}
			.iato-btn:disabled {
				opacity: 0.6;
				cursor: wait;
			}
			@keyframes spin {
				from { transform: rotate(0deg); }
				to { transform: rotate(360deg); }
			}

			/* ── WordPress overrides ─────────────────────────── */
			.iato-wrap .notice {
				margin-left: 0;
				margin-right: 0;
			}
CSS;
	}

	// ── Scripts ──────────────────────────────────────────────────────────────────

	private static function get_inline_scripts(): string {
		$copied_text = esc_js( __( 'Copied!', 'iato-mcp' ) );

		// Nowdoc (single-quoted heredoc) — no PHP interpolation. The single
		// translated string is swapped in via strtr after the fact.
		$js = <<<'JS'
		(function() {
			var copiedText = '__IATO_COPIED_TEXT__';

			// Copy to clipboard
			document.querySelectorAll('.iato-copy-btn').forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					var text;
					if (btn.dataset.copyValue) {
						text = btn.dataset.copyValue;
					} else if (btn.dataset.target) {
						var el = document.getElementById(btn.dataset.target);
						text = el ? el.textContent : '';
					}
					if (!text) return;
					navigator.clipboard.writeText(text).then(function() {
						btn.classList.add('copied');
						var label = btn.querySelector('.iato-copy-label');
						var icon = btn.querySelector('.dashicons');
						if (label) {
							var orig = label.textContent;
							label.textContent = copiedText;
							setTimeout(function() { label.textContent = orig; btn.classList.remove('copied'); }, 2000);
						} else if (icon) {
							icon.className = 'dashicons dashicons-yes';
							setTimeout(function() { icon.className = 'dashicons dashicons-clipboard'; btn.classList.remove('copied'); }, 2000);
						}
					});
				});
			});

			// Reveal / hide key
			document.querySelectorAll('.iato-reveal-btn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var target = document.getElementById(btn.dataset.target);
					if (!target) return;
					var full = target.dataset.full;
					if (target.textContent === full) {
						target.textContent = full.substring(0, 8) + '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022' + full.substring(full.length - 4);
						btn.querySelector('.dashicons').className = 'dashicons dashicons-visibility';
					} else {
						target.textContent = full;
						btn.querySelector('.dashicons').className = 'dashicons dashicons-hidden';
					}
				});
			});

			// Show/hide password input
			document.querySelectorAll('.iato-input-toggle').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var input = document.getElementById(btn.dataset.toggle);
					if (!input) return;
					var icon = btn.querySelector('.dashicons');
					if (input.type === 'password') {
						input.type = 'text';
						icon.className = 'dashicons dashicons-hidden';
					} else {
						input.type = 'password';
						icon.className = 'dashicons dashicons-visibility';
					}
				});
			});

			// Select All / None per category
			document.querySelectorAll('.iato-tool-category').forEach(function(cat) {
				var checkboxes = cat.querySelectorAll('input[type="checkbox"]');
				var allBtn = cat.querySelector('.iato-select-all');
				var noneBtn = cat.querySelector('.iato-select-none');

				if (allBtn) {
					allBtn.addEventListener('click', function() {
						checkboxes.forEach(function(cb) { cb.checked = true; });
						updateCount();
					});
				}
				if (noneBtn) {
					noneBtn.addEventListener('click', function() {
						checkboxes.forEach(function(cb) { cb.checked = false; });
						updateCount();
					});
				}
			});

			// Update enabled count
			function updateCount() {
				var total = document.querySelectorAll('.iato-tool-grid input[type="checkbox"]').length;
				var checked = document.querySelectorAll('.iato-tool-grid input[type="checkbox"]:checked').length;
				var counter = document.getElementById('iato-tools-count');
				if (counter) {
					counter.textContent = checked + ' of ' + total + ' enabled';
				}
			}

			document.querySelectorAll('.iato-tool-grid input[type="checkbox"]').forEach(function(cb) {
				cb.addEventListener('change', updateCount);
			});

			// Enable / disable Test connection button as the key input changes.
			var keyInput = document.getElementById('iato_mcp_api_key');
			var testBtn = document.getElementById('iato-test-api-key');
			if (keyInput && testBtn) {
				keyInput.addEventListener('input', function() {
					testBtn.disabled = keyInput.value.trim() === '';
				});
			}

			// Test connection — AJAX to /admin-ajax.php?action=iato_mcp_test_api_key
			if (testBtn) {
				testBtn.addEventListener('click', function() {
					var statusEl = document.getElementById('iato-test-api-key-status');
					if (!statusEl) return;

					testBtn.disabled = true;
					statusEl.innerHTML = '<span class="iato-badge iato-badge--neutral">Testing…</span>';

					var form = new FormData();
					form.append('action', 'iato_mcp_test_api_key');
					form.append('nonce', iatoMcpSettings.testKeyNonce);
					if (keyInput && keyInput.value.trim() !== '') {
						form.append('api_key', keyInput.value.trim());
					}

					fetch(iatoMcpSettings.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: form
					}).then(function(r) { return r.json(); }).then(function(resp) {
						testBtn.disabled = false;
						var platformBadge = document.getElementById('iato-platform-badge');
						var catHint = document.getElementById('iato-platform-cat-hint');
						if (resp && resp.success) {
							var msg = (resp.data && resp.data.message) ? resp.data.message : 'Connected.';
							statusEl.innerHTML = '<span class="iato-badge iato-badge--success">Validated</span> <span class="iato-hint">' + escapeHtml(msg) + '</span>';
							if (platformBadge) platformBadge.innerHTML = '<span class="iato-badge iato-badge--success">Connected</span>';
							if (catHint) catHint.textContent = '— requires IATO API key ✓';
						} else {
							var d = (resp && resp.data) || {};
							var parts = [];
							if (d.http_status) parts.push('HTTP ' + d.http_status);
							if (d.message) parts.push(d.message);
							var detail = parts.join(' — ') || 'Unknown error.';
							statusEl.innerHTML = '<span class="iato-badge iato-badge--danger">Failed</span> <span class="iato-hint">' + escapeHtml(detail) + '</span>';
							if (platformBadge) platformBadge.innerHTML = '<span class="iato-badge iato-badge--neutral">Not validated</span>';
						}
					}).catch(function(err) {
						testBtn.disabled = false;
						statusEl.innerHTML = '<span class="iato-badge iato-badge--danger">Network error</span> <span class="iato-hint">' + escapeHtml(String(err)) + '</span>';
					});
				});
			}

			function escapeHtml(s) {
				var d = document.createElement('div');
				d.textContent = String(s);
				return d.innerHTML;
			}

			// Hijack Save Settings to go through admin-ajax instead of options.php.
			// Some hosts 503 on options.php POSTs; admin-ajax isn't subject to the
			// same upstream timeout. The <form action="options.php"> stays intact
			// as a no-JS fallback.
			var settingsForm = document.querySelector('form[action="options.php"]');
			if (settingsForm && typeof iatoMcpSettings !== 'undefined' && iatoMcpSettings.saveNonce) {
				settingsForm.addEventListener('submit', function(e) {
					e.preventDefault();

					var fd = new FormData(settingsForm);
					// Strip the options.php-specific fields; admin-ajax doesn't use them.
					fd.delete('option_page');
					fd.delete('_wpnonce');
					fd.delete('_wp_http_referer');
					fd.append('action', 'iato_mcp_save_settings');
					fd.append('nonce', iatoMcpSettings.saveNonce);

					var submitBtns = settingsForm.querySelectorAll('button[type="submit"], input[type="submit"]');
					submitBtns.forEach(function(b) { b.disabled = true; });

					showSaveNotice('Saving…', 'info');

					fetch(iatoMcpSettings.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: fd
					}).then(function(r) { return r.json(); }).then(function(resp) {
						submitBtns.forEach(function(b) { b.disabled = false; });
						if (resp && resp.success) {
							showSaveNotice((resp.data && resp.data.message) || 'Settings saved.', 'success');
						} else {
							var msg = (resp && resp.data && resp.data.message) || 'Save failed.';
							showSaveNotice(msg, 'error');
						}
					}).catch(function(err) {
						submitBtns.forEach(function(b) { b.disabled = false; });
						showSaveNotice('Network error: ' + String(err), 'error');
					});
				});
			}

			function showSaveNotice(msg, type) {
				var id = 'iato-save-notice';
				var existing = document.getElementById(id);
				if (existing) existing.remove();

				var cls = type === 'success' ? 'notice-success' : (type === 'error' ? 'notice-error' : 'notice-info');
				var html = '<div id="' + id + '" class="notice ' + cls + ' is-dismissible" style="margin:12px 0"><p>' + escapeHtml(msg) + '</p></div>';

				// Insert at top of .iato-wrap, below the header.
				var host = document.querySelector('.iato-wrap');
				if (host) {
					host.insertAdjacentHTML('afterbegin', html);
					if (type === 'success') {
						setTimeout(function() {
							var el = document.getElementById(id);
							if (el) el.remove();
						}, 4000);
					}
				}
			}

		})();
JS;

		return strtr( $js, [ '__IATO_COPIED_TEXT__' => $copied_text ] );
	}

	// ── Setup Wizard Notice ──────────────────────────────────────────────────────

	public static function setup_wizard_notice(): void {
		if ( ! get_option( 'iato_mcp_show_wizard' ) ) {
			return;
		}
		if ( get_option( 'iato_mcp_wizard_dismissed' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key          = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );
		$endpoint     = rest_url( 'iato-mcp/v1/message' );
		$settings_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$wizard_url   = admin_url( 'admin.php?page=iato-mcp-setup' );
		$dismiss_url  = wp_nonce_url( admin_url( 'admin-post.php?action=iato_mcp_dismiss_wizard' ), 'iato_mcp_dismiss_wizard' );

		$config_json = wp_json_encode( [
			'mcpServers' => [
				iato_mcp_connection_name() => [
					'command' => 'npx',
					'args'    => [
						'-y',
						'mcp-remote',
						$endpoint,
						'--header',
						'Authorization: Bearer ${IATO_KEY}',
					],
					'env'     => [
						'IATO_KEY' => $key,
					],
				],
			],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		?>
		<div class="notice" style="border-left-color: #5a89f4; padding: 0; overflow: hidden;">
			<div style="padding: 20px 24px;">
				<h3 style="margin: 0 0 16px; font-size: 16px; color: #5a89f4;"><?php echo iato_mcp_logo_svg( 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Returns self-escaped <img> markup (attributes wrapped in esc_attr inside the helper); fallback is a static <span>. ?><span style="vertical-align: middle; margin-left: 8px;"><?php esc_html_e( 'MCP — Ready to Connect', 'iato-mcp' ); ?></span></h3>

				<p style="margin: 0 0 6px; font-size: 13px; color: #475569;"><strong><?php esc_html_e( 'Your MCP server URL', 'iato-mcp' ); ?></strong></p>
				<div style="background: #f1f5f9; border-radius: 6px; padding: 8px 12px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
					<code id="iato-notice-endpoint" style="flex: 1; font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, monospace; font-size: 13px; color: #0f172a; background: transparent; padding: 0;"><?php echo esc_html( $endpoint ); ?></code>
					<button type="button" style="background: rgba(90,137,244,0.12); border: none; color: #5a89f4; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;" onclick="navigator.clipboard.writeText(document.getElementById('iato-notice-endpoint').textContent).then(function(){var b=event.target.closest('button');b.textContent='Copied!';setTimeout(function(){b.innerHTML='<span class=\'dashicons dashicons-clipboard\' style=\'font-size:13px;width:13px;height:13px;\'></span> Copy';},2000);});">
						<span class="dashicons dashicons-clipboard" style="font-size:13px;width:13px;height:13px;"></span> <?php esc_html_e( 'Copy', 'iato-mcp' ); ?>
					</button>
				</div>

				<p style="margin: 0 0 8px; font-size: 13px; color: #64748b;"><?php esc_html_e( 'Choose ONE connection method:', 'iato-mcp' ); ?></p>

				<div style="border: 1px solid #c7d2fe; border-left: 4px solid #5a89f4; border-radius: 6px; padding: 14px 16px; margin-bottom: 6px; background: rgba(90,137,244,0.03);">
					<div style="margin-bottom: 6px;">
						<strong style="font-size: 14px;"><?php esc_html_e( 'Option A — Claude.ai or Claude Desktop (Connectors UI)', 'iato-mcp' ); ?></strong>
						<span style="display: inline-block; background: #5a89f4; color: #fff; padding: 1px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-left: 6px; vertical-align: middle;"><?php esc_html_e( 'Recommended', 'iato-mcp' ); ?></span>
					</div>
					<p style="margin: 0; color: #475569; font-size: 13px; line-height: 1.5;"><?php esc_html_e( 'In Claude, click Add Custom Connector, paste the URL above, and click Connect. OAuth handles authentication — no credentials needed.', 'iato-mcp' ); ?></p>
				</div>

				<div style="text-align: center; color: #94a3b8; font-size: 11px; margin: 4px 0; letter-spacing: 2px; text-transform: uppercase;"><?php esc_html_e( '— or —', 'iato-mcp' ); ?></div>

				<div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; margin-bottom: 14px;">
					<div style="margin-bottom: 6px;">
						<strong style="font-size: 14px;"><?php esc_html_e( 'Option B — Claude Desktop config file', 'iato-mcp' ); ?></strong>
					</div>
					<p style="margin: 0 0 8px; color: #475569; font-size: 13px; line-height: 1.5;"><?php esc_html_e( "For Claude Desktop's local config file. Paste this snippet under mcpServers:", 'iato-mcp' ); ?></p>
					<div style="background: #0f172a; border-radius: 8px; position: relative; overflow: hidden;">
						<pre id="iato-wizard-config" style="margin: 0; padding: 16px; padding-right: 70px; color: #e2e8f0; font-size: 13px; line-height: 1.6; overflow-x: auto; white-space: pre; font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, monospace;"><?php echo esc_html( $config_json ); ?></pre>
						<button type="button" style="position: absolute; top: 8px; right: 8px; background: rgba(255,255,255,0.1); border: none; color: rgba(255,255,255,0.7); padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;" onclick="navigator.clipboard.writeText(document.getElementById('iato-wizard-config').textContent).then(function(){var b=event.target.closest('button');b.textContent='Copied!';setTimeout(function(){b.innerHTML='<span class=\'dashicons dashicons-clipboard\' style=\'font-size:14px;width:14px;height:14px;\'></span> Copy';},2000);});">
							<span class="dashicons dashicons-clipboard" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e( 'Copy', 'iato-mcp' ); ?>
						</button>
					</div>
				</div>

				<p style="margin: 0 0 6px; color: #64748b; font-size: 13px; line-height: 1.5;">
					<?php
					printf(
						/* translators: %s: link to setup wizard */
						esc_html__( 'Other clients (Cursor, Cline, Zed, MCP Inspector, scripts): see the %s for OAuth, Application Password, and stdio bridge configs.', 'iato-mcp' ),
						'<a href="' . esc_url( $wizard_url ) . '" style="color: #5a89f4; font-weight: 500;">' . esc_html__( 'setup wizard', 'iato-mcp' ) . '</a>'
					);
					?>
				</p>
				<p style="margin: 0 0 14px; color: #64748b; font-size: 13px; line-height: 1.5;">
					<?php
					printf(
						/* translators: %s: link to settings page */
						esc_html__( 'Optional: enter your IATO API key in %s to enable bridge tools (sitemap, SEO audits, performance reports).', 'iato-mcp' ),
						'<a href="' . esc_url( $settings_url ) . '" style="color: #5a89f4; font-weight: 500;">' . esc_html__( 'Settings', 'iato-mcp' ) . '</a>'
					);
					?>
				</p>

				<div style="margin-top: 8px; display: flex; gap: 16px; align-items: center;">
					<?php if ( ! get_option( 'iato_mcp_setup_complete' ) ) : ?>
						<a href="<?php echo esc_url( $wizard_url ); ?>" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; background: #4b72cc; color: #fff; text-decoration: none; border-radius: 8px; box-shadow: 0 0 24px rgba(90,137,244,0.18); font-size: 13px; font-weight: 600;"><?php esc_html_e( 'Run Setup Wizard', 'iato-mcp' ); ?> &rarr;</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( $dismiss_url ); ?>" style="color: #94a3b8; font-size: 13px; text-decoration: none;"><?php esc_html_e( 'Dismiss this notice', 'iato-mcp' ); ?></a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle wizard dismiss action.
	 */
	public static function dismiss_wizard(): void {
		check_admin_referer( 'iato_mcp_dismiss_wizard' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}

		update_option( 'iato_mcp_wizard_dismissed', true );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle MCP key regeneration.
	 */
	public static function handle_regenerate_key(): void {
		check_admin_referer( 'iato_mcp_regenerate_key' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}

		IATO_MCP_Auth::rotate_key();

		add_settings_error(
			'iato_mcp_key',
			'iato_mcp_key_regenerated',
			__( 'MCP API key regenerated. Update your AI client configuration with the new key.', 'iato-mcp' ),
			'updated'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&settings-updated=true' ) );
		exit;
	}

}
