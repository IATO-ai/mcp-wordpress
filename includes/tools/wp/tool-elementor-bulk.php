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
		'description' => 'Apply a batch of widget patches across many posts in one call. Each update is independent — partial success is the expected mode. Per-post capability check; updates the user can\'t do are reported as auth_denied while siblings succeed. Optional outer idempotency_key covers the entire batch (single 60s window). Requires edit_posts (re-checked per post).',
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

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$results[] = [
					'index'     => $i,
					'post_id'   => $post_id,
					'widget_id' => $widget_id,
					'success'   => false,
					'error'     => 'auth_denied',
					'error_data' => [ 'message' => 'Current user cannot edit this post.' ],
				];
				$failed++;
				continue;
			}

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
		'description' => 'Search every Elementor post for widgets matching a filter. filter: { type?: string, setting?: { key: { eq|ne|in|nin|exists: value } } }. post_ids=[] scans all Elementor-flagged posts (capped at 500 in v1.3.0). Permission-filtered against current_user_can(read_post).',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_ids' => [ 'type' => 'array',  'description' => 'Post IDs to scan. Empty = all Elementor posts (capped at 500).' ],
				'filter'   => [ 'type' => 'object', 'description' => 'Filter spec (required): { type?, setting?: { key: { op: value } } }.' ],
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
		$truncated = false;
		if ( empty( $post_ids ) ) {
			$post_ids = get_posts( [
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'any',
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
			$post_ids = array_values( array_unique( array_map( 'absint', $post_ids ) ) );
		}

		// Permission filter.
		$permission_filtered = 0;
		$post_ids_allowed    = [];
		foreach ( $post_ids as $pid ) {
			if ( $pid <= 0 ) {
				continue;
			}
			if ( ! current_user_can( 'read_post', $pid ) ) {
				$permission_filtered++;
				continue;
			}
			$post_ids_allowed[] = $pid;
		}

		// Walk each post.
		$matches = [];
		foreach ( $post_ids_allowed as $pid ) {
			$decoded = IATO_MCP_Elementor_Adapter::decode_data( $pid );
			if ( is_wp_error( $decoded ) ) {
				continue;
			}
			[ $elements, ] = $decoded;
			$post_matches  = IATO_MCP_Elementor_Adapter::find_by_filter( $elements, $filter, $pid );
			if ( ! empty( $post_matches ) ) {
				$matches = array_merge( $matches, $post_matches );
			}
		}

		$response = [
			'total_matches' => count( $matches ),
			'scanned'       => count( $post_ids_allowed ),
			'matches'       => $matches,
		];
		if ( $permission_filtered > 0 ) {
			$response['permission_filtered'] = $permission_filtered;
		}
		if ( $truncated ) {
			$response['truncated'] = true;
		}

		return IATO_MCP_Server::ok( $response );
	}
);
