<?php
/**
 * WP Tools: update_elementor_widgets_bulk, find_elementor_widgets.
 *
 * Cross-post operations over the v2 Elementor surface. Bulk applies many
 * single-widget patches with partial-success aggregation. Find walks every
 * Elementor post in the workspace and returns widgets matching a filter.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// Hard cap on posts scanned by find_elementor_widgets when post_ids is empty.
const IATO_MCP_FIND_POST_CAP = 500;

// ── update_elementor_widgets_bulk ──────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_elementor_widgets_bulk',
	[
		'description' => 'Apply a batch of widget patches across many posts in one call. Each update is independent — partial success is the expected mode (decode/find/revision errors per update don\'t block siblings). Optional outer idempotency_key covers the entire batch (single 60s window). Requires edit_posts.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'updates'         => [
					'type'        => 'array',
					'description' => 'Array of update objects: { post_id, widget_id, settings_patch, if_revision? }.',
				],
				'dry_run'         => [ 'type' => 'boolean', 'description' => 'Preview every update without writing (default: false).' ],
				'idempotency_key' => [ 'type' => 'string',  'description' => 'Optional outer key covering the whole batch.' ],
			],
			'required' => [ 'updates' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$updates = $args['updates'] ?? null;
		if ( ! is_array( $updates ) ) {
			return new WP_Error( 'missing_updates', 'updates must be a non-empty array.' );
		}
		if ( empty( $updates ) ) {
			return IATO_MCP_Server::ok( [
				'total'     => 0,
				'succeeded' => 0,
				'failed'    => 0,
				'dry_run'   => ! empty( $args['dry_run'] ),
				'results'   => [],
			] );
		}

		$dry_run = ! empty( $args['dry_run'] );
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$key     = isset( $args['idempotency_key'] ) ? (string) $args['idempotency_key'] : null;

		$cached = IATO_MCP_Elementor_Concurrency::idempotency_lookup( $user_id, 'update_elementor_widgets_bulk', $key, $args );
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}
		if ( is_array( $cached ) ) {
			return IATO_MCP_Server::ok( $cached );
		}

		$results   = [];
		$succeeded = 0;
		$failed    = 0;

		foreach ( $updates as $i => $update ) {
			if ( ! is_array( $update ) ) {
				$results[] = [
					'index'   => $i,
					'success' => false,
					'error'   => 'invalid_update',
					'error_data' => [ 'message' => 'Each update must be an object.' ],
				];
				$failed++;
				continue;
			}

			$post_id   = absint( $update['post_id'] ?? $update['id'] ?? 0 );
			$widget_id = isset( $update['widget_id'] ) ? (string) $update['widget_id'] : '';

			if ( ! $post_id || '' === $widget_id ) {
				$results[] = [
					'index'   => $i,
					'success' => false,
					'error'   => 'missing_target',
					'error_data' => [ 'message' => 'post_id and widget_id are required.' ],
				];
				$failed++;
				continue;
			}

			// Per-post capability is gated by the global require_cap('edit_posts')
			// at handler entry. Bearer auth in this plugin grants full admin
			// access (see class-auth.php docblock); current_user_can() against a
			// post would always return false because wp_get_current_user() is 0
			// for bearer-authenticated requests, so it would reject every write.
			$single_args = [
				'id'             => $post_id,
				'widget_id'      => $widget_id,
				'settings_patch' => $update['settings_patch'] ?? null,
				'dry_run'        => $dry_run,
				'if_revision'    => $update['if_revision'] ?? null,
			];
			$result = IATO_MCP_Elementor_Adapter::do_update_widget( $single_args );

			if ( is_wp_error( $result ) ) {
				$err_data = $result->get_error_data();
				$results[] = [
					'index'      => $i,
					'post_id'    => $post_id,
					'widget_id'  => $widget_id,
					'success'    => false,
					'error'      => $result->get_error_code(),
					'error_data' => is_array( $err_data ) ? $err_data : null,
					'message'    => $result->get_error_message(),
				];
				$failed++;
				continue;
			}

			// Drop change_receipt from per-result rows on bulk responses — keeps
			// payloads lean for the heavy h1→h2 sweep (saves ~120B per result).
			// Receipts are still persisted to iato_change_receipts; bulk callers
			// who need them can query the audit table by post_id + applied_at.
			// Singleton update_elementor_widget / update_elementor_patch responses
			// keep the slim receipt for backward-compat and convenience.
			unset( $result['change_receipt'] );

			$result['index']   = $i;
			$result['success'] = true;
			$results[]         = $result;
			$succeeded++;
		}

		$response = [
			'total'     => count( $updates ),
			'succeeded' => $succeeded,
			'failed'    => $failed,
			'dry_run'   => $dry_run,
			'results'   => $results,
		];

		IATO_MCP_Elementor_Concurrency::idempotency_store( $user_id, 'update_elementor_widgets_bulk', $key, $args, $response );
		return IATO_MCP_Server::ok( $response );
	}
);

// ── find_elementor_widgets ─────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'find_elementor_widgets',
	[
		'description' => 'Search every Elementor post for widgets matching a filter. filter: { type?: string, setting?: { key: { eq|ne|in|nin|exists|contains: value } } }. The `contains` operator (added v1.8.0) does a case-insensitive substring match against scalar settings — useful for finding a widget by its content (e.g. setting.editor.contains="<phrase>"). post_ids=[] auto-scans all Elementor-flagged posts (post + page) with status publish/draft/pending/private, capped at 500. Revision IDs passed via post_ids are auto-resolved to their parent post; the matching row carries resolved_from_revision_id so callers can see the input mapped through. NOTE: elementor_library templates are NOT scanned in v1.8.0 — that lands in Layer 2 of the discovery work.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_ids' => [ 'type' => 'array',  'description' => 'Post IDs to scan. Empty = all Elementor posts (capped at 500). Revision IDs are auto-resolved to their parent post.' ],
				'filter'   => [ 'type' => 'object', 'description' => 'Filter spec (required): { type?, setting?: { key: { op: value } } }. Operators: eq, ne, in, nin, exists, contains (case-insensitive substring).' ],
			],
			'required' => [ 'filter' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_ids = is_array( $args['post_ids'] ?? null ) ? $args['post_ids'] : [];
		$filter   = is_array( $args['filter'] ?? null ) ? $args['filter'] : null;

		if ( null === $filter ) {
			return new WP_Error( 'missing_filter', 'filter is required.' );
		}

		// Resolve scan set.
		$truncated            = false;
		$revision_to_parent   = []; // parent_id => first revision_id that resolved to it
		if ( empty( $post_ids ) ) {
			$post_ids = get_posts( [
				'post_type'      => [ 'post', 'page' ],
				// Exclude trash and auto-draft from the default scan. Callers who
				// need those can pass explicit post_ids.
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page' => IATO_MCP_FIND_POST_CAP,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single meta key, indexed in typical setups.
				'meta_query'     => [
					[
						'key'   => '_elementor_edit_mode',
						'value' => 'builder',
					],
				],
			] );
			if ( count( $post_ids ) >= IATO_MCP_FIND_POST_CAP ) {
				$truncated = true;
			}
		} else {
			// Auto-resolve any revision IDs to their parent. wp_is_post_revision
			// returns the parent ID for revisions, false for everything else.
			// Without this, the post_content (= revision body) decode works but
			// every match carries the revision's ID, which leaks the parent only
			// via the NNN-revision-vN slug — the exact "brute-force discovery"
			// pain point that motivated v1.8.0.
			$resolved = [];
			foreach ( $post_ids as $raw ) {
				$pid = absint( $raw );
				if ( $pid <= 0 ) {
					continue;
				}
				$parent = wp_is_post_revision( $pid );
				if ( $parent ) {
					$parent_id = (int) $parent;
					$resolved[] = $parent_id;
					// Keep the first revision ID that mapped to each parent.
					if ( ! isset( $revision_to_parent[ $parent_id ] ) ) {
						$revision_to_parent[ $parent_id ] = $pid;
					}
				} else {
					$resolved[] = $pid;
				}
			}
			$post_ids = array_values( array_unique( $resolved ) );
		}

		// Bearer auth grants full admin access (see class-auth.php), so per-post
		// read_post checks would always fail against wp_get_current_user() = 0
		// and reject every match. Trust the global authentication instead.
		$post_ids_allowed = array_values( array_filter( $post_ids, fn( $pid ) => $pid > 0 ) );

		// Walk each post.
		$matches = [];
		foreach ( $post_ids_allowed as $pid ) {
			$decoded = IATO_MCP_Elementor_Adapter::decode_data( $pid );
			if ( is_wp_error( $decoded ) ) {
				continue;
			}
			[ $elements, ] = $decoded;
			$post_matches  = IATO_MCP_Elementor_Adapter::find_by_filter( $elements, $filter, $pid );
			if ( empty( $post_matches ) ) {
				continue;
			}
			// Tag matches whose scanned post_id came from a revision input, so the
			// caller can see the input → parent mapping.
			if ( isset( $revision_to_parent[ $pid ] ) ) {
				$rev_id = $revision_to_parent[ $pid ];
				foreach ( $post_matches as &$m ) {
					$m['resolved_from_revision_id'] = $rev_id;
				}
				unset( $m );
			}
			$matches = array_merge( $matches, $post_matches );
		}

		$response = [
			'total_matches' => count( $matches ),
			'scanned'       => count( $post_ids_allowed ),
			'matches'       => $matches,
		];
		if ( $truncated ) {
			$response['truncated'] = true;
		}

		return IATO_MCP_Server::ok( $response );
	}
);
