<?php
/**
 * IATO API Client — thin HTTP wrapper around the IATO REST API.
 *
 * Base URL: https://iato.ai/api
 * Auth:     Authorization: Bearer {api_key}
 * Timeout:  30s (crawl endpoints can be slow)
 * Transport: wp_remote_get / wp_remote_post — never curl directly.
 *
 * All public methods return array on success or WP_Error on failure.
 * Callers (bridge tools) should check is_wp_error() before using the result.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_IATO_Client {

	private const BASE_URL = 'https://iato.ai/api';
	private const TIMEOUT  = 30;

	/** @var string|null Cached API key for this request. */
	private static ?string $api_key = null;

	// ── Crawl endpoints ────────────────────────────────────────────────────────

	/**
	 * GET /crawl/jobs — list crawl jobs.
	 *
	 * @return array|WP_Error
	 */
	public static function list_crawls(): array|WP_Error {
		return self::get( '/crawl/jobs' );
	}

	/**
	 * GET /crawl/jobs/{job_id} — get a single crawl job.
	 *
	 * @param string $job_id  String/UUID crawl job ID.
	 * @return array|WP_Error
	 */
	public static function get_crawl_job( string $job_id ): array|WP_Error {
		return self::get( "/crawl/jobs/{$job_id}" );
	}

	/**
	 * GET /crawl/jobs/{job_id}/stats — crawl statistics.
	 *
	 * @param string $crawl_id  Job ID (string/UUID).
	 * @return array|WP_Error
	 */
	public static function get_crawl_analytics( string $crawl_id ): array|WP_Error {
		return self::get( "/crawl/jobs/{$crawl_id}/stats" );
	}

	/**
	 * GET /crawl/jobs/{job_id}/overview — crawl overview/analytics.
	 *
	 * @param string $crawl_id  Job ID (string/UUID).
	 * @return array|WP_Error
	 */
	public static function get_crawl_overview( string $crawl_id ): array|WP_Error {
		return self::get( "/crawl/jobs/{$crawl_id}/overview" );
	}

	/**
	 * GET /crawl/jobs/{job_id}/issues — SEO issues list.
	 *
	 * @param string      $crawl_id  Job ID (string/UUID).
	 * @param string|null $severity  'error'|'warning'|'info'|null
	 * @param int         $limit
	 * @return array|WP_Error
	 */
	public static function get_seo_issues( string $crawl_id, ?string $severity = null, int $limit = 50 ): array|WP_Error {
		$query = [ 'limit' => $limit ];
		if ( null !== $severity ) {
			$query['severity'] = $severity;
		}
		return self::get( "/crawl/jobs/{$crawl_id}/issues", $query );
	}

	/**
	 * GET /crawls/{crawl_id}/seo-score — computed SEO score (bridge endpoint).
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	public static function get_seo_score( string $crawl_id ): array|WP_Error {
		return self::get( "/crawls/{$crawl_id}/seo-score" );
	}

	/**
	 * GET /crawl/jobs/{job_id}/pages — list crawled pages.
	 *
	 * @param string $crawl_id  Job ID (string/UUID).
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public static function get_pages( string $crawl_id, int $limit = 50 ): array|WP_Error {
		return self::get( "/crawl/jobs/{$crawl_id}/pages", [ 'limit' => $limit ] );
	}

	/**
	 * GET /crawl/jobs/{job_id}/pages/{page_id}
	 *
	 * @param string $crawl_id  Job ID (string/UUID).
	 * @param int    $page_id
	 * @return array|WP_Error
	 */
	public static function get_page( string $crawl_id, int $page_id ): array|WP_Error {
		return self::get( "/crawl/jobs/{$crawl_id}/pages/{$page_id}" );
	}

	/**
	 * GET /crawl/jobs/{job_id}/performance — performance metrics.
	 *
	 * @param string $crawl_id  Job ID (string/UUID).
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public static function get_low_performing_pages( string $crawl_id, int $limit = 20 ): array|WP_Error {
		return self::get( "/crawl/jobs/{$crawl_id}/performance", [ 'limit' => $limit ] );
	}

	/**
	 * GET /crawl/jobs/{job_id}/broken-links — broken links.
	 *
	 * @param string $crawl_id  Job ID (string/UUID).
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public static function get_broken_links( string $crawl_id, int $limit = 50 ): array|WP_Error {
		return self::get( "/crawl/jobs/{$crawl_id}/broken-links", [ 'limit' => $limit ] );
	}

	/**
	 * GET /crawl/jobs/{job_id}/links/orphan — orphan pages.
	 *
	 * @param string     $crawl_id      Job ID (string/UUID).
	 * @param array|null $exclude_types  e.g. ['section','planned']
	 * @return array|WP_Error
	 */
	public static function get_orphan_pages( string $crawl_id, ?array $exclude_types = null ): array|WP_Error {
		$query = [];
		if ( null !== $exclude_types ) {
			$query['exclude_types'] = implode( ',', $exclude_types );
		}
		return self::get( "/crawl/jobs/{$crawl_id}/links/orphan", $query );
	}

	/**
	 * GET /crawl/jobs/{job_id}/suggestions — AI suggestions (GET, not POST).
	 *
	 * @param string   $crawl_id    Job ID (string/UUID).
	 * @param string[] $focus_areas  e.g. ['seo','content']
	 * @param int      $limit
	 * @return array|WP_Error
	 */
	public static function generate_suggestions( string $crawl_id, array $focus_areas = [], int $limit = 10 ): array|WP_Error {
		$query = [ 'limit' => $limit ];
		if ( ! empty( $focus_areas ) ) {
			$query['focus_areas'] = implode( ',', $focus_areas );
		}
		return self::get( "/crawl/jobs/{$crawl_id}/suggestions", $query );
	}

	/**
	 * POST /crawl/jobs/{job_id}/suggestions/generate — trigger suggestion generation.
	 *
	 * @param string $crawl_id Job ID (string/UUID).
	 * @return array|WP_Error
	 */
	public static function trigger_suggestions_generate( string $crawl_id ): array|WP_Error {
		return self::post( "/crawl/jobs/{$crawl_id}/suggestions/generate" );
	}

	// ── Sitemap endpoints ──────────────────────────────────────────────────────

	/**
	 * GET /sitemaps?job_id={job_id} — list sitemaps for a crawl job.
	 *
	 * @param string|null $job_id       Crawl job ID (required by API).
	 * @param int|null    $workspace_id Optional workspace filter.
	 * @return array|WP_Error
	 */
	public static function list_sitemaps( ?string $job_id = null, ?int $workspace_id = null ): array|WP_Error {
		$query = [];
		if ( null !== $job_id ) {
			$query['job_id'] = $job_id;
		}
		if ( null !== $workspace_id ) {
			$query['workspace_id'] = $workspace_id;
		}
		return self::get( '/sitemaps', $query );
	}

	/**
	 * GET /sitemaps/{id}/nodes — full node tree (flat list).
	 *
	 * @param int $sitemap_id
	 * @return array|WP_Error
	 */
	public static function get_sitemap_nodes( int $sitemap_id ): array|WP_Error {
		return self::get( "/sitemaps/{$sitemap_id}/nodes" );
	}

	/**
	 * GET /crawl/jobs/{job_id}/navigation/menus — list navigation menus.
	 *
	 * @param string $crawl_id  Job ID (string/UUID).
	 * @return array|WP_Error
	 */
	public static function get_menus( string $crawl_id ): array|WP_Error {
		return self::get( "/crawl/jobs/{$crawl_id}/navigation/menus" );
	}

	/**
	 * GET /crawl/jobs/{job_id}/navigation/items — list menu items.
	 *
	 * @param string $crawl_id  Job ID (string/UUID).
	 * @return array|WP_Error
	 */
	public static function get_menu_items( string $crawl_id ): array|WP_Error {
		return self::get( "/crawl/jobs/{$crawl_id}/navigation/items" );
	}

	/**
	 * GET /crawl/jobs/{job_id}/taxonomy/tree — taxonomy tree.
	 *
	 * @param string $crawl_id  Job ID (string/UUID).
	 * @return array|WP_Error
	 */
	public static function get_taxonomy( string $crawl_id ): array|WP_Error {
		return self::get( "/crawl/jobs/{$crawl_id}/taxonomy/tree" );
	}

	// ── Sitemap write endpoints ───────────────────────────────────────────────

	/**
	 * POST /sitemaps/{id}/nodes — create a sitemap node.
	 *
	 * @param int         $sitemap_id
	 * @param string      $title
	 * @param string|null $url
	 * @param int|null    $parent_node_id
	 * @param string      $node_type     'page'|'section'|'planned'
	 * @param string|null $page_type     'home'|'landing'|'article'|'product'|etc.
	 * @return array|WP_Error
	 */
	public static function create_sitemap_node( int $sitemap_id, string $title, ?string $url = null, ?int $parent_node_id = null, string $node_type = 'page', ?string $page_type = null, ?int $wp_post_id = null, ?string $wp_post_type = null ): array|WP_Error {
		$body = [ 'title' => $title, 'node_type' => $node_type ];
		if ( null !== $url ) {
			$body['url'] = $url;
		}
		if ( null !== $parent_node_id ) {
			$body['parent_node_id'] = $parent_node_id;
		}
		if ( null !== $page_type ) {
			$body['page_type'] = $page_type;
		}
		if ( null !== $wp_post_id ) {
			$body['wp_post_id'] = $wp_post_id;
		}
		if ( null !== $wp_post_type ) {
			$body['wp_post_type'] = $wp_post_type;
		}
		return self::post( "/sitemaps/{$sitemap_id}/nodes", $body );
	}

	/**
	 * PUT /sitemaps/{id}/nodes/{node_id} — update a sitemap node.
	 *
	 * @param int   $sitemap_id
	 * @param int   $node_id
	 * @param array $fields  Any of: title, status, page_type, url, notes.
	 * @return array|WP_Error
	 */
	public static function update_sitemap_node( int $sitemap_id, int $node_id, array $fields ): array|WP_Error {
		return self::put( "/sitemaps/{$sitemap_id}/nodes/{$node_id}", $fields );
	}

	// ── Category endpoints ────────────────────────────────────────────────────

	/**
	 * POST /sitemaps/{id}/categories — create a category.
	 *
	 * @param int         $sitemap_id
	 * @param string      $label
	 * @param string|null $parent_category_id
	 * @return array|WP_Error
	 */
	public static function create_category( int $sitemap_id, string $label, ?string $parent_category_id = null ): array|WP_Error {
		$body = [ 'label' => $label ];
		if ( null !== $parent_category_id ) {
			$body['parent_category_id'] = $parent_category_id;
		}
		return self::post( "/sitemaps/{$sitemap_id}/categories", $body );
	}

	/**
	 * POST /sitemaps/{id}/categories/assign — assign a category to nodes.
	 *
	 * @param int    $sitemap_id
	 * @param array  $node_ids
	 * @param string $category_id
	 * @return array|WP_Error
	 */
	public static function assign_category( int $sitemap_id, array $node_ids, string $category_id ): array|WP_Error {
		return self::post( "/sitemaps/{$sitemap_id}/categories/assign", [
			'node_ids'    => $node_ids,
			'category_id' => $category_id,
		] );
	}

	// ── Tag endpoints ─────────────────────────────────────────────────────────

	/**
	 * POST /sitemaps/{id}/tags — create a tag.
	 *
	 * @param int    $sitemap_id
	 * @param string $label
	 * @param string $color  Hex color code.
	 * @return array|WP_Error
	 */
	public static function create_tag( int $sitemap_id, string $label, string $color = '#6b7280' ): array|WP_Error {
		return self::post( "/sitemaps/{$sitemap_id}/tags", [
			'label' => $label,
			'color' => $color,
		] );
	}

	/**
	 * POST /sitemaps/{id}/tags/assign — assign tags to nodes.
	 *
	 * @param int   $sitemap_id
	 * @param array $node_ids
	 * @param array $tag_ids
	 * @return array|WP_Error
	 */
	public static function assign_tags( int $sitemap_id, array $node_ids, array $tag_ids ): array|WP_Error {
		return self::post( "/sitemaps/{$sitemap_id}/tags/assign", [
			'node_ids' => $node_ids,
			'tag_ids'  => $tag_ids,
		] );
	}

	// ── SEO fix endpoint ──────────────────────────────────────────────────────

	/**
	 * POST /crawl/start — start a new crawl.
	 *
	 * @param string $url       URL to crawl.
	 * @param int    $max_pages Maximum pages to crawl.
	 * @param array  $extra     Additional config fields.
	 * @return array|WP_Error
	 */
	public static function start_crawl( string $url, int $max_pages = 1000, array $extra = [] ): array|WP_Error {
		$body = array_merge( $extra, [
			'url'       => $url,
			'max_pages' => $max_pages,
		] );
		return self::post( '/crawl/start', $body );
	}

	/**
	 * Create a navigation menu in an IATO sitemap.
	 *
	 * @param int    $sitemap_id Sitemap ID.
	 * @param string $name       Menu name.
	 * @return array|WP_Error
	 */
	public static function create_menu( int $sitemap_id, string $name ): array|WP_Error {
		return self::post( "/sitemaps/{$sitemap_id}/menus", [ 'name' => $name ] );
	}

	/**
	 * Create a menu item inside an IATO menu.
	 *
	 * @param int   $sitemap_id Sitemap ID.
	 * @param int   $menu_id    IATO menu ID.
	 * @param array $item_data  Item fields: label, url, parent_item_id, position.
	 * @return array|WP_Error
	 */
	public static function create_menu_item( int $sitemap_id, int $menu_id, array $item_data ): array|WP_Error {
		return self::post( "/sitemaps/{$sitemap_id}/menus/{$menu_id}/items", $item_data );
	}

	// ── Workspace endpoints ──────────────────────────────────────────────────

	/**
	 * GET /workspaces — list workspaces.
	 *
	 * @return array|WP_Error
	 */
	public static function list_workspaces(): array|WP_Error {
		return self::get( '/workspaces' );
	}

	/**
	 * Resolve workspace ID — returns stored option or auto-detects from API.
	 *
	 * @return string Workspace ID or empty string on failure.
	 */
	public static function resolve_workspace_id(): string {
		$workspace_id = sanitize_text_field( get_option( 'iato_mcp_workspace_id', '' ) );
		if ( ! empty( $workspace_id ) ) {
			return $workspace_id;
		}

		$result = self::list_workspaces();
		if ( is_wp_error( $result ) ) {
			return '';
		}

		// Platform normalized /workspaces to data.workspaces; fall back to bare data for
		// one release so users on the transition build don't break. Drop the fallback in v1.1.
		$ws_list = $result['data']['workspaces'] ?? $result['workspaces'] ?? [];
		if ( ! is_array( $ws_list ) || empty( $ws_list ) || ! isset( $ws_list[0] ) ) {
			return '';
		}

		$first = $ws_list[0];
		$id    = $first['id'] ?? '';
		if ( ! empty( $id ) ) {
			update_option( 'iato_mcp_workspace_id', sanitize_text_field( $id ) );
		}

		return (string) $id;
	}

	/**
	 * GET /workspaces/{id}
	 *
	 * @param string $workspace_id
	 * @return array|WP_Error
	 */
	public static function get_workspace( string $workspace_id ): array|WP_Error {
		return self::get( "/workspaces/{$workspace_id}" );
	}

	// ── Internal helpers ───────────────────────────────────────────────────────

	/**
	 * Build the standard request headers for every IATO API call.
	 *
	 * Always includes plugin version + capabilities so the platform can
	 * gate push/callback behavior per client. Unknown headers are ignored
	 * silently by FastAPI today; used by autopilot v2.0 on the platform.
	 *
	 * @param string $key    Bearer token.
	 * @param bool   $send_json  Whether the request has a JSON body.
	 * @return array
	 */
	private static function default_headers( string $key, bool $send_json ): array {
		$headers = [
			'Authorization'              => 'Bearer ' . $key,
			'Accept'                     => 'application/json',
			'X-IATO-Plugin-Version'      => defined( 'IATO_MCP_VERSION' ) ? IATO_MCP_VERSION : 'unknown',
			'X-IATO-Plugin-Capabilities' => 'mcp-read',
		];
		if ( $send_json ) {
			$headers['Content-Type'] = 'application/json';
		}
		return $headers;
	}

	/**
	 * GET request to the IATO API.
	 *
	 * @param string $path  Relative path, e.g. '/crawls/abc123/analytics'.
	 * @param array  $query Query params.
	 * @return array|WP_Error Decoded JSON body or WP_Error.
	 */
	private static function get( string $path, array $query = [] ): array|WP_Error {
		$key = self::api_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$url = self::BASE_URL . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get( $url, [
			'timeout' => self::TIMEOUT,
			'headers' => self::default_headers( $key, false ),
		] );

		return self::parse_response( $response );
	}

	/**
	 * POST request to the IATO API.
	 *
	 * @param string $path Path relative to base URL.
	 * @param array  $body JSON body.
	 * @return array|WP_Error Decoded JSON body or WP_Error.
	 */
	private static function post( string $path, array $body = [] ): array|WP_Error {
		$key = self::api_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$response = wp_remote_post( self::BASE_URL . $path, [
			'timeout' => self::TIMEOUT,
			'headers' => self::default_headers( $key, true ),
			'body'    => wp_json_encode( $body ),
		] );

		return self::parse_response( $response );
	}

	/**
	 * PUT request to the IATO API.
	 *
	 * @param string $path Path relative to base URL.
	 * @param array  $body JSON body.
	 * @return array|WP_Error Decoded JSON body or WP_Error.
	 */
	private static function put( string $path, array $body = [] ): array|WP_Error {
		$key = self::api_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$response = wp_remote_request( self::BASE_URL . $path, [
			'method'  => 'PUT',
			'timeout' => self::TIMEOUT,
			'headers' => self::default_headers( $key, true ),
			'body'    => wp_json_encode( $body ),
		] );

		return self::parse_response( $response );
	}

	/**
	 * Parse an HTTP response from wp_remote_*.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @return array|WP_Error Decoded JSON body or WP_Error.
	 */
	private static function parse_response( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $body['message'] ?? $body['error'] ?? "IATO API returned HTTP {$code}";
			return new WP_Error( 'iato_api_error', $message, [ 'status' => $code ] );
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'iato_api_error', 'Invalid JSON response from IATO API' );
		}

		// IATO sometimes returns HTTP 200 with success:false in the body.
		if ( isset( $body['success'] ) && false === $body['success'] ) {
			$message  = $body['data']['message'] ?? $body['message'] ?? $body['error'] ?? 'IATO API returned an error';
			$err_code = $body['data']['code'] ?? $body['code'] ?? 'iato_api_error';
			// Ensure message is always a string — IATO may return nested objects.
			if ( ! is_string( $message ) ) {
				$message = is_array( $message ) ? wp_json_encode( $message ) : (string) $message;
			}
			if ( ! is_string( $err_code ) ) {
				$err_code = 'iato_api_error';
			}
			return new WP_Error( $err_code, $message, [ 'status' => $code, 'body' => $body ] );
		}

		return $body;
	}

	/**
	 * Get the IATO API key from options.
	 * Returns WP_Error if not configured.
	 *
	 * @return string|WP_Error
	 */
	private static function api_key(): string|WP_Error {
		if ( self::$api_key !== null ) {
			return self::$api_key;
		}
		$key = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		if ( empty( $key ) ) {
			return new WP_Error( 'no_api_key', 'IATO API key not configured. Go to Settings > IATO MCP to add your key.' );
		}
		self::$api_key = $key;
		return $key;
	}
}
