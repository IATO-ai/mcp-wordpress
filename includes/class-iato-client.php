<?php
/**
 * IATO API Client — thin HTTP wrapper around the IATO REST API.
 *
 * Base URL: https://iato.ai/api/v1
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

	private const BASE_URL = 'https://iato.ai/api/v1';
	private const TIMEOUT  = 30;

	/** @var string|null Cached API key for this request. */
	private static ?string $api_key = null;

	// ── Crawl endpoints ────────────────────────────────────────────────────────

	/**
	 * GET /crawls — list crawl jobs.
	 *
	 * @return array|WP_Error
	 */
	public static function list_crawls(): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'list_crawls not yet implemented' );
	}

	/**
	 * GET /crawls/{id}/analytics
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	public static function get_crawl_analytics( string $crawl_id ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_crawl_analytics not yet implemented' );
	}

	/**
	 * GET /crawls/{id}/seo-issues
	 *
	 * @param string      $crawl_id
	 * @param string|null $severity  'error'|'warning'|'info'|null
	 * @param int         $limit
	 * @return array|WP_Error
	 */
	public static function get_seo_issues( string $crawl_id, ?string $severity = null, int $limit = 50 ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_seo_issues not yet implemented' );
	}

	/**
	 * GET /crawls/{id}/seo-score
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	public static function get_seo_score( string $crawl_id ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_seo_score not yet implemented' );
	}

	/**
	 * GET /crawls/{id}/pages
	 *
	 * @param string $crawl_id
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public static function get_pages( string $crawl_id, int $limit = 50 ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_pages not yet implemented' );
	}

	/**
	 * GET /crawls/{id}/pages/{page_id}
	 *
	 * @param string $crawl_id
	 * @param int    $page_id
	 * @return array|WP_Error
	 */
	public static function get_page( string $crawl_id, int $page_id ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_page not yet implemented' );
	}

	/**
	 * GET /crawls/{id}/low-performing
	 *
	 * @param string $crawl_id
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public static function get_low_performing_pages( string $crawl_id, int $limit = 20 ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_low_performing_pages not yet implemented' );
	}

	/**
	 * GET /crawls/{id}/content-metrics
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	public static function get_content_metrics( string $crawl_id ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_content_metrics not yet implemented' );
	}

	/**
	 * POST /crawls/{id}/suggestions — generate AI suggestions.
	 *
	 * @param string   $crawl_id
	 * @param string[] $focus_areas  e.g. ['seo','content']
	 * @param int      $limit
	 * @return array|WP_Error
	 */
	public static function generate_suggestions( string $crawl_id, array $focus_areas = [], int $limit = 10 ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'generate_suggestions not yet implemented' );
	}

	// ── Sitemap endpoints ──────────────────────────────────────────────────────

	/**
	 * GET /sitemaps — list sitemaps.
	 *
	 * @param int|null $workspace_id
	 * @return array|WP_Error
	 */
	public static function list_sitemaps( ?int $workspace_id = null ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'list_sitemaps not yet implemented' );
	}

	/**
	 * GET /sitemaps/{id}/nodes — full node tree.
	 *
	 * @param int $sitemap_id
	 * @return array|WP_Error
	 */
	public static function get_sitemap_nodes( int $sitemap_id ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_sitemap_nodes not yet implemented' );
	}

	/**
	 * GET /sitemaps/{id}/menus
	 *
	 * @param int $sitemap_id
	 * @return array|WP_Error
	 */
	public static function get_menus( int $sitemap_id ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_menus not yet implemented' );
	}

	/**
	 * GET /sitemaps/{id}/menus/{menu_id}/items
	 *
	 * @param int $sitemap_id
	 * @param int $menu_id
	 * @return array|WP_Error
	 */
	public static function get_menu_items( int $sitemap_id, int $menu_id ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_menu_items not yet implemented' );
	}

	/**
	 * GET /sitemaps/{id}/orphans
	 *
	 * @param int        $sitemap_id
	 * @param array|null $exclude_types  e.g. ['section','planned']
	 * @return array|WP_Error
	 */
	public static function get_orphan_pages( int $sitemap_id, ?array $exclude_types = null ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_orphan_pages not yet implemented' );
	}

	/**
	 * GET /sitemaps/{id}/taxonomy
	 *
	 * @param int $sitemap_id
	 * @return array|WP_Error
	 */
	public static function get_taxonomy( int $sitemap_id ): array|WP_Error {
		// TODO: implement
		return new WP_Error( 'not_implemented', 'get_taxonomy not yet implemented' );
	}

	// ── Internal helpers ───────────────────────────────────────────────────────

	/**
	 * GET request to the IATO API.
	 *
	 * @param string $path  Relative path, e.g. '/crawls/abc123/analytics'.
	 * @param array  $query Query params.
	 * @return array|WP_Error Decoded JSON body or WP_Error.
	 */
	private static function get( string $path, array $query = [] ): array|WP_Error {
		// TODO: implement — build URL, add auth header, call wp_remote_get, decode response
		return new WP_Error( 'not_implemented', 'HTTP GET not yet implemented' );
	}

	/**
	 * POST request to the IATO API.
	 *
	 * @param string $path Path relative to base URL.
	 * @param array  $body JSON body.
	 * @return array|WP_Error Decoded JSON body or WP_Error.
	 */
	private static function post( string $path, array $body = [] ): array|WP_Error {
		// TODO: implement — build URL, add auth header, call wp_remote_post, decode response
		return new WP_Error( 'not_implemented', 'HTTP POST not yet implemented' );
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
