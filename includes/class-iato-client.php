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
	 * GET /crawls — list crawl jobs.
	 *
	 * @return array|WP_Error
	 */
	public static function list_crawls(): array|WP_Error {
		return self::get( '/crawls' );
	}

	/**
	 * GET /crawls/{id}/analytics
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	public static function get_crawl_analytics( string $crawl_id ): array|WP_Error {
		return self::get( "/crawls/{$crawl_id}/analytics" );
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
		$query = [ 'limit' => $limit ];
		if ( null !== $severity ) {
			$query['severity'] = $severity;
		}
		return self::get( "/crawls/{$crawl_id}/seo-issues", $query );
	}

	/**
	 * GET /crawls/{id}/seo-score
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	public static function get_seo_score( string $crawl_id ): array|WP_Error {
		return self::get( "/crawls/{$crawl_id}/seo-score" );
	}

	/**
	 * GET /crawls/{id}/pages
	 *
	 * @param string $crawl_id
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public static function get_pages( string $crawl_id, int $limit = 50 ): array|WP_Error {
		return self::get( "/crawls/{$crawl_id}/pages", [ 'limit' => $limit ] );
	}

	/**
	 * GET /crawls/{id}/pages/{page_id}
	 *
	 * @param string $crawl_id
	 * @param int    $page_id
	 * @return array|WP_Error
	 */
	public static function get_page( string $crawl_id, int $page_id ): array|WP_Error {
		return self::get( "/crawls/{$crawl_id}/pages/{$page_id}" );
	}

	/**
	 * GET /crawls/{id}/low-performing
	 *
	 * @param string $crawl_id
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public static function get_low_performing_pages( string $crawl_id, int $limit = 20 ): array|WP_Error {
		return self::get( "/crawls/{$crawl_id}/low-performing", [ 'limit' => $limit ] );
	}

	/**
	 * GET /crawls/{id}/content-metrics
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	public static function get_content_metrics( string $crawl_id ): array|WP_Error {
		return self::get( "/crawls/{$crawl_id}/content-metrics" );
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
		$body = [ 'limit' => $limit ];
		if ( ! empty( $focus_areas ) ) {
			$body['focus_areas'] = $focus_areas;
		}
		return self::post( "/crawls/{$crawl_id}/suggestions", $body );
	}

	// ── Sitemap endpoints ──────────────────────────────────────────────────────

	/**
	 * GET /sitemaps — list sitemaps.
	 *
	 * @param int|null $workspace_id
	 * @return array|WP_Error
	 */
	public static function list_sitemaps( ?int $workspace_id = null ): array|WP_Error {
		$query = [];
		if ( null !== $workspace_id ) {
			$query['workspace_id'] = $workspace_id;
		}
		return self::get( '/sitemaps', $query );
	}

	/**
	 * GET /sitemaps/{id}/nodes — full node tree.
	 *
	 * @param int $sitemap_id
	 * @return array|WP_Error
	 */
	public static function get_sitemap_nodes( int $sitemap_id ): array|WP_Error {
		return self::get( "/sitemaps/{$sitemap_id}/nodes" );
	}

	/**
	 * GET /sitemaps/{id}/menus
	 *
	 * @param int $sitemap_id
	 * @return array|WP_Error
	 */
	public static function get_menus( int $sitemap_id ): array|WP_Error {
		return self::get( "/sitemaps/{$sitemap_id}/menus" );
	}

	/**
	 * GET /sitemaps/{id}/menus/{menu_id}/items
	 *
	 * @param int $sitemap_id
	 * @param int $menu_id
	 * @return array|WP_Error
	 */
	public static function get_menu_items( int $sitemap_id, int $menu_id ): array|WP_Error {
		return self::get( "/sitemaps/{$sitemap_id}/menus/{$menu_id}/items" );
	}

	/**
	 * GET /sitemaps/{id}/orphans
	 *
	 * @param int        $sitemap_id
	 * @param array|null $exclude_types  e.g. ['section','planned']
	 * @return array|WP_Error
	 */
	public static function get_orphan_pages( int $sitemap_id, ?array $exclude_types = null ): array|WP_Error {
		$query = [];
		if ( null !== $exclude_types ) {
			$query['exclude_types'] = implode( ',', $exclude_types );
		}
		return self::get( "/sitemaps/{$sitemap_id}/orphans", $query );
	}

	/**
	 * GET /sitemaps/{id}/taxonomy
	 *
	 * @param int $sitemap_id
	 * @return array|WP_Error
	 */
	public static function get_taxonomy( int $sitemap_id ): array|WP_Error {
		return self::get( "/sitemaps/{$sitemap_id}/taxonomy" );
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
	public static function create_sitemap_node( int $sitemap_id, string $title, ?string $url = null, ?int $parent_node_id = null, string $node_type = 'page', ?string $page_type = null ): array|WP_Error {
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
		return self::post( "/sitemaps/{$sitemap_id}/nodes", $body );
	}

	/**
	 * POST /sitemaps/{id}/nodes/{node_id} — update a sitemap node.
	 *
	 * @param int   $sitemap_id
	 * @param int   $node_id
	 * @param array $fields  Any of: title, status, page_type, url, notes.
	 * @return array|WP_Error
	 */
	public static function update_sitemap_node( int $sitemap_id, int $node_id, array $fields ): array|WP_Error {
		return self::post( "/sitemaps/{$sitemap_id}/nodes/{$node_id}", $fields );
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
	 * POST /crawls/{id}/fix-seo-issue — apply an SEO fix.
	 *
	 * @param string $crawl_id
	 * @param int    $page_id
	 * @param string $fix_type  'title'|'meta_description'
	 * @param string $fix_value
	 * @return array|WP_Error
	 */
	public static function fix_seo_issue( string $crawl_id, int $page_id, string $fix_type, string $fix_value ): array|WP_Error {
		return self::post( "/crawls/{$crawl_id}/fix-seo-issue", [
			'page_id'   => $page_id,
			'fix_type'  => $fix_type,
			'fix_value' => $fix_value,
		] );
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

		$ws_list = $result['workspaces'] ?? $result['data'] ?? $result;
		if ( empty( $ws_list ) || ! isset( $ws_list[0] ) ) {
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

	// ── Governance endpoints ─────────────────────────────────────────────────

	/**
	 * GET /workspaces/{id}/governance-policy
	 *
	 * @param string $workspace_id
	 * @return array|WP_Error
	 */
	public static function get_governance_policy( string $workspace_id ): array|WP_Error {
		return self::get( "/workspaces/{$workspace_id}/governance-policy" );
	}

	/**
	 * POST /workspaces/{id}/governance-policy
	 *
	 * @param string $workspace_id
	 * @param array  $policy
	 * @return array|WP_Error
	 */
	public static function update_governance_policy( string $workspace_id, array $policy ): array|WP_Error {
		return self::post( "/workspaces/{$workspace_id}/governance-policy", $policy );
	}

	// ── Autopilot Queue endpoints ───────────────────────────────────────────

	/**
	 * GET /autopilot/{workspace_id}/queue — list autopilot queue items.
	 *
	 * @param string $workspace_id
	 * @param array  $params       Optional: status, limit, page.
	 * @return array|WP_Error
	 */
	public static function get_queue( string $workspace_id, array $params = [] ): array|WP_Error {
		return self::get( "/autopilot/{$workspace_id}/queue", $params );
	}

	/**
	 * Update a queue item status, trying multiple endpoint patterns.
	 *
	 * Attempts:
	 * 1. POST /autopilot/{workspace_id}/queue/{item_id}/{action}  (action-based)
	 * 2. PUT  /autopilot/{workspace_id}/queue/{item_id}           (status field)
	 *
	 * @param string $workspace_id
	 * @param string $item_id
	 * @param string $status  'approved', 'rejected', or 'manually_fixed'.
	 * @return array|WP_Error
	 */
	public static function update_queue_item( string $workspace_id, string $item_id, string $status ): array|WP_Error {
		// Map status to action verb for POST endpoints.
		$action_map = [
			'approved'       => 'approve',
			'rejected'       => 'reject',
			'manually_fixed' => 'mark-fixed',
		];

		$action = $action_map[ $status ] ?? null;

		// Try action-based POST endpoint first.
		if ( $action ) {
			$result = self::post( "/autopilot/{$workspace_id}/queue/{$item_id}/{$action}" );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Fallback: PUT with status field.
		return self::put( "/autopilot/{$workspace_id}/queue/{$item_id}", [
			'status' => $status,
		] );
	}

	/**
	 * POST /autopilot/batch/{batch_id}/reject — bulk-reject all pending items in a batch.
	 *
	 * @param string $batch_id Batch UUID.
	 * @return array|WP_Error
	 */
	public static function reject_batch( string $batch_id ): array|WP_Error {
		return self::post( "/autopilot/batch/{$batch_id}/reject" );
	}

	/**
	 * Mark a single item as manually fixed.
	 *
	 * @param string $workspace_id
	 * @param string $item_id
	 * @param string $notes Optional notes.
	 * @return array|WP_Error
	 */
	public static function mark_as_fixed( string $workspace_id, string $item_id, string $notes = '' ): array|WP_Error {
		$body = [];
		if ( $notes !== '' ) {
			$body['notes'] = $notes;
		}

		// Try action-based POST endpoint.
		$result = self::post( "/autopilot/{$workspace_id}/queue/{$item_id}/mark-fixed", $body );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// Fallback: PUT with status field.
		$put_body = [ 'status' => 'manually_fixed' ];
		if ( $notes !== '' ) {
			$put_body['notes'] = $notes;
		}
		return self::put( "/autopilot/{$workspace_id}/queue/{$item_id}", $put_body );
	}

	/**
	 * POST /autopilot/batches/{batch_id}/mark-fixed — bulk mark batch as manually fixed.
	 *
	 * @param string $batch_id Batch UUID.
	 * @param string $notes    Optional notes.
	 * @return array|WP_Error
	 */
	public static function mark_batch_as_fixed( string $batch_id, string $notes = '' ): array|WP_Error {
		$body = [];
		if ( $notes !== '' ) {
			$body['notes'] = $notes;
		}
		return self::post( "/autopilot/batches/{$batch_id}/mark-fixed", $body );
	}

	/**
	 * Bulk-reject all pending_review items for a workspace.
	 *
	 * Fetches all pending items page by page, collects unique batch IDs,
	 * then calls reject_batch() for each.
	 *
	 * @param string $workspace_id
	 * @return array{ batches_rejected: int, items_total: int }|WP_Error
	 */
	public static function bulk_reject_all_pending( string $workspace_id ): array|WP_Error {
		$dismissed  = 0;
		$errors     = 0;
		$total      = 0;
		$iteration  = 0;
		$last_error = '';

		do {
			// Always fetch page 1 since rejected items drop off the list.
			$result = self::get_queue( $workspace_id, [
				'status' => 'pending_review',
				'limit'  => 50,
				'page'   => 1,
			] );

			if ( is_wp_error( $result ) ) {
				if ( $dismissed > 0 ) {
					break; // Return partial results.
				}
				return $result;
			}

			$data  = $result['data'] ?? $result;
			$items = $data['items'] ?? [];
			if ( $iteration === 0 ) {
				$total = $data['total'] ?? 0;
			}

			if ( empty( $items ) ) {
				break;
			}

			// Try batch reject first if batch_id is present.
			$batch_ids = [];
			foreach ( $items as $item ) {
				$bid = $item['batch_id'] ?? '';
				if ( $bid !== '' ) {
					$batch_ids[ $bid ] = true;
				}
			}

			if ( ! empty( $batch_ids ) ) {
				foreach ( array_keys( $batch_ids ) as $bid ) {
					$res = self::reject_batch( $bid );
					if ( is_wp_error( $res ) ) {
						$last_error = $res->get_error_message();
						// Batch reject failed — fall through to individual.
						$batch_ids = [];
						break;
					}
				}
			}

			if ( ! empty( $batch_ids ) ) {
				$dismissed += count( $items );
			} else {
				// No batch_id or batch reject failed — reject individually.
				foreach ( $items as $item ) {
					$item_id = $item['id'] ?? '';
					if ( $item_id === '' ) {
						continue;
					}
					$res = self::update_queue_item( $workspace_id, (string) $item_id, 'rejected' );
					if ( ! is_wp_error( $res ) ) {
						$dismissed++;
					} else {
						$errors++;
						$last_error = $res->get_error_message();
						// If every item in this page fails, stop looping.
						if ( $errors >= count( $items ) ) {
							break 2;
						}
					}
				}
			}

			$iteration++;
			if ( $iteration > 200 ) {
				break;
			}
		} while ( true );

		$response = [
			'items_dismissed' => $dismissed,
			'items_total'     => $total,
		];

		if ( $dismissed === 0 && $last_error !== '' ) {
			return new WP_Error( 'reject_failed', 'Could not reject items: ' . $last_error );
		}

		if ( $errors > 0 ) {
			$response['errors'] = $errors;
			$response['last_error'] = $last_error;
		}

		return $response;
	}

	// ── Activity Log endpoints ───────────────────────────────────────────────

	/**
	 * GET /autopilot/{id}/activity
	 *
	 * @param string $workspace_id
	 * @param array  $params       Optional: action, limit, page.
	 * @return array|WP_Error
	 */
	public static function get_activity_log( string $workspace_id, array $params = [] ): array|WP_Error {
		return self::get( "/autopilot/{$workspace_id}/activity", $params );
	}

	// ── Schedule endpoints ───────────────────────────────────────────────────

	/**
	 * POST /schedules — create a crawl schedule.
	 *
	 * @param array $data Schedule configuration.
	 * @return array|WP_Error
	 */
	public static function create_schedule( array $data ): array|WP_Error {
		return self::post( '/schedules', $data );
	}

	/**
	 * POST /schedules/{id}/run — trigger an immediate crawl.
	 *
	 * @param string $schedule_id
	 * @return array|WP_Error
	 */
	public static function run_schedule_now( string $schedule_id ): array|WP_Error {
		return self::post( "/schedules/{$schedule_id}/run" );
	}

	// ── SEO Audit endpoint ───────────────────────────────────────────────────

	/**
	 * POST /crawls/{id}/seo-audit — run an SEO audit.
	 *
	 * @param string $crawl_id
	 * @return array|WP_Error
	 */
	public static function run_seo_audit( string $crawl_id ): array|WP_Error {
		return self::post( "/crawls/{$crawl_id}/seo-audit" );
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
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			],
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
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body' => wp_json_encode( $body ),
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
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body' => wp_json_encode( $body ),
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
			$message = $body['data']['message'] ?? $body['message'] ?? $body['error'] ?? 'IATO API returned an error';
			$err_code = $body['data']['code'] ?? $body['code'] ?? 'iato_api_error';
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
