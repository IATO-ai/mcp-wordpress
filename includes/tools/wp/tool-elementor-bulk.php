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

			// Object-level: edit_post on each target (the site key passes, an
			// Application Password user is checked against the specific post).
			$object_check = get_post( $post_id )
				? IATO_MCP_Auth::require_cap( 'edit_post', $post_id )
				: new WP_Error( 'not_found', 'Post not found.' );
			if ( is_wp_error( $object_check ) ) {
				$results[] = [
					'index'      => $i,
					'post_id'    => $post_id,
					'widget_id'  => $widget_id,
					'success'    => false,
					'error'      => $object_check->get_error_code(),
					'error_data' => null,
					'message'    => $object_check->get_error_message(),
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
		'description' => 'Search every Elementor post for widgets matching a filter. filter: { type?: string, setting?: { key: { eq|ne|in|nin|exists|contains: value } } }. The `contains` operator (added v1.8.0) does a case-insensitive substring match against scalar settings — useful for finding a widget by its content (e.g. setting.editor.contains="<phrase>"). post_ids=[] auto-scans all Elementor-flagged posts (post + page) with status publish/draft/pending/private, capped at 500. Revision IDs passed via post_ids are auto-resolved to their parent post; the matching row carries resolved_from_revision_id so callers can see the input mapped through. Pass include_templates:true (added v1.11.0) to expand the auto-scan to elementor_library templates as well — default is false to preserve BC for existing callers. For listing templates and their Display Conditions without searching widgets, use list_elementor_templates (also v1.11.0). Atomic (Elementor V4) elements are matched too: type accepts atomic names (e-heading, e-paragraph, e-image, e-button, e-flexbox, ...) and setting clauses run against the unwrapped values plus derived keys header_size, editor, link_url, image_url, image_id, image_alt, so e.g. setting.title.contains or setting.header_size.eq="h1" work on both schemas. Every match carries schema: "classic" | "atomic".',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_ids'          => [ 'type' => 'array',   'description' => 'Post IDs to scan. Empty = all Elementor posts (capped at 500). Revision IDs are auto-resolved to their parent post.' ],
				'filter'            => [ 'type' => 'object',  'description' => 'Filter spec (required): { type?, setting?: { key: { op: value } } }. Operators: eq, ne, in, nin, exists, contains (case-insensitive substring).' ],
				'include_templates' => [ 'type' => 'boolean', 'description' => 'When true, the auto-scan (post_ids=[]) expands to include elementor_library templates alongside post and page. Default false. Has no effect when explicit post_ids are passed.' ],
			],
			'required' => [ 'filter' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_ids          = is_array( $args['post_ids'] ?? null ) ? $args['post_ids'] : [];
		$filter            = is_array( $args['filter'] ?? null ) ? $args['filter'] : null;
		$include_templates = ! empty( $args['include_templates'] );

		if ( null === $filter ) {
			return new WP_Error( 'missing_filter', 'filter is required.' );
		}
		// Theme Builder templates are admin-only to read (same bar as
		// list_elementor_templates); explicit elementor_library IDs are caught
		// per post below.
		if ( $include_templates ) {
			$template_check = IATO_MCP_Auth::require_cap( 'manage_options' );
			if ( is_wp_error( $template_check ) ) {
				return $template_check;
			}
		}

		// Resolve scan set.
		$truncated            = false;
		$revision_to_parent   = []; // parent_id => first revision_id that resolved to it
		$explicit             = ! empty( $post_ids );
		$inputs_by_resolved   = []; // resolved id => every input id (revision or not) that mapped to it
		if ( empty( $post_ids ) ) {
			// include_templates is the only knob that widens the post_type list
			// past [post, page]. Default false preserves v1.8.x BC — silently
			// adding elementor_library to every existing caller's scan would
			// change match counts unpredictably.
			$scan_post_types = $include_templates
				? [ 'post', 'page', 'elementor_library' ]
				: [ 'post', 'page' ];
			$post_ids = get_posts( [
				'post_type'      => $scan_post_types,
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
					$inputs_by_resolved[ $parent_id ][] = $pid;
				} else {
					$resolved[] = $pid;
					$inputs_by_resolved[ $pid ][] = $pid;
				}
			}
			$post_ids = array_values( array_unique( $resolved ) );
		}

		// Scan only what this caller may read: read_post per post (other users'
		// drafts and private posts drop out for an Application Password user),
		// edit_post for password-protected posts, manage_options for templates.
		// The site key passes every check. IDs the caller passed are reported
		// back as given (the input ID, not a resolved revision parent) so they
		// can see why those produced no matches; posts the automatic scan found
		// are only counted, so an unreadable post is not enumerated by ID.
		$post_ids_allowed = [];
		$skipped_ids      = [];
		$skipped_count    = 0;
		foreach ( $post_ids as $pid ) {
			if ( $pid <= 0 ) {
				continue;
			}
			if ( is_wp_error( IATO_MCP_Auth::require_read_post( $pid ) ) ) {
				if ( $explicit ) {
					foreach ( $inputs_by_resolved[ $pid ] ?? [ $pid ] as $input_id ) {
						$skipped_ids[] = $input_id;
					}
				} else {
					$skipped_count++;
				}
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
		if ( ! empty( $skipped_ids ) ) {
			$response['skipped_unreadable'] = $skipped_ids; // explicit post_ids, as passed
		}
		if ( $skipped_count > 0 ) {
			$response['skipped_unreadable_count'] = $skipped_count; // automatic scan
		}

		return IATO_MCP_Server::ok( $response );
	}
);
