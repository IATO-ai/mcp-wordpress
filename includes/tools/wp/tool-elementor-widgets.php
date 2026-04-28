<?php
/**
 * WP Tools: list_elementor_widgets, get_elementor_widget,
 *           update_elementor_widget, update_elementor_patch.
 *
 * Widget-grained Elementor surface (v2). All four tools share the
 * IATO_MCP_Elementor_Adapter for tree walking, patch application, and the
 * write pipeline. Concurrency (if_revision) and idempotency (idempotency_key)
 * are layered on the write tools via IATO_MCP_Elementor_Concurrency.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── list_elementor_widgets ─────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'list_elementor_widgets',
	[
		'description' => 'List every Elementor widget in a post with id, type, and a few peek fields. Use format=tree for a nested view, format=flat (default) for a depth-first list.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'     => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'format' => [ 'type' => 'string',  'enum' => [ 'flat', 'tree' ], 'description' => 'Output shape (default: flat).' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] ?? 0 );
		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		$format = isset( $args['format'] ) ? (string) $args['format'] : 'flat';
		if ( ! in_array( $format, [ 'flat', 'tree' ], true ) ) {
			$format = 'flat';
		}

		$decoded = IATO_MCP_Elementor_Adapter::decode_data( $post_id );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		[ $elements, $raw ] = $decoded;

		$widgets = IATO_MCP_Elementor_Adapter::flatten_widgets( $elements, $format );

		return IATO_MCP_Server::ok( [
			'post_id'      => $post_id,
			'revision'     => IATO_MCP_Elementor_Adapter::compute_revision( $raw ),
			'format'       => $format,
			'widget_count' => 'flat' === $format ? count( $widgets ) : null,
			'widgets'      => $widgets,
		] );
	}
);

// ── get_elementor_widget ───────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_elementor_widget',
	[
		'description' => 'Return full settings + revision for a single Elementor widget. Use list_elementor_widgets first to find the widget_id.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'        => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'widget_id' => [ 'type' => 'string',  'description' => 'Elementor widget ID (required).' ],
			],
			'required' => [ 'id', 'widget_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_id   = absint( $args['id'] ?? 0 );
		$widget_id = isset( $args['widget_id'] ) ? (string) $args['widget_id'] : '';
		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		if ( '' === $widget_id ) {
			return new WP_Error( 'missing_widget_id', 'widget_id is required.' );
		}

		$decoded = IATO_MCP_Elementor_Adapter::decode_data( $post_id );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		[ $elements, $raw ] = $decoded;

		$found = IATO_MCP_Elementor_Adapter::find_widget( $elements, $widget_id );
		if ( null === $found ) {
			return new WP_Error( 'widget_not_found', "Widget {$widget_id} not found in post {$post_id}." );
		}

		$element = $found['element'];
		$type    = (string) ( $element['elType'] ?? 'unknown' );

		return IATO_MCP_Server::ok( [
			'post_id'   => $post_id,
			'revision'  => IATO_MCP_Elementor_Adapter::compute_revision( $raw ),
			'widget_id' => $widget_id,
			'type'      => 'widget' === $type ? (string) ( $element['widgetType'] ?? 'widget' ) : $type,
			'parent_id' => $found['parent_id'],
			'depth'     => $found['depth'],
			'path'      => $found['path'],
			'settings'  => $element['settings'] ?? [],
		] );
	}
);

// ── update_elementor_widget ────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_elementor_widget',
	[
		'description' => 'Patch a single Elementor widget\'s settings. Pass settings_patch as a flat object — null values remove keys, arrays REPLACE existing arrays (use update_elementor_patch for surgical array edits). Supports dry_run, if_revision (optimistic concurrency), and idempotency_key (60s replay window). Requires edit_posts.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'              => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'widget_id'       => [ 'type' => 'string',  'description' => 'Elementor widget ID (required).' ],
				'settings_patch'  => [ 'type' => 'object',  'description' => 'Flat object of settings keys to set/replace/remove (required). Null values remove. Arrays REPLACE.' ],
				'dry_run'         => [ 'type' => 'boolean', 'description' => 'Preview applied_patch without writing (default: false).' ],
				'if_revision'     => [ 'type' => 'string',  'description' => 'Optional. Reject the write with revision_conflict if the stored revision differs.' ],
				'idempotency_key' => [ 'type' => 'string',  'description' => 'Optional. Same key + same payload within 60s returns cached response with idempotency_replay=true; same key + different payload returns 409.' ],
			],
			'required' => [ 'id', 'widget_id', 'settings_patch' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$key     = isset( $args['idempotency_key'] ) ? (string) $args['idempotency_key'] : null;

		$cached = IATO_MCP_Elementor_Concurrency::idempotency_lookup( $user_id, 'update_elementor_widget', $key, $args );
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}
		if ( is_array( $cached ) ) {
			return IATO_MCP_Server::ok( $cached );
		}

		$result = IATO_MCP_Elementor_Adapter::do_update_widget( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		IATO_MCP_Elementor_Concurrency::idempotency_store( $user_id, 'update_elementor_widget', $key, $args, $result );
		return IATO_MCP_Server::ok( $result );
	}
);

// ── update_elementor_patch ─────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_elementor_patch',
	[
		'description' => 'Apply an RFC 6902 JSON Patch to the entire Elementor document. Use this for surgical array entry edits (repeater rows, indexed inserts) where update_elementor_widget\'s replace-only array semantics are too coarse. Each op may include op, path, value, from. Supports dry_run, if_revision, idempotency_key. Requires edit_posts.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'              => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'ops'             => [ 'type' => 'array',   'description' => 'RFC 6902 op array (required). Supports add, remove, replace, move, copy, test.' ],
				'dry_run'         => [ 'type' => 'boolean', 'description' => 'Preview applied_patch without writing (default: false).' ],
				'if_revision'     => [ 'type' => 'string',  'description' => 'Optional revision guard.' ],
				'idempotency_key' => [ 'type' => 'string',  'description' => 'Optional 60s replay key.' ],
			],
			'required' => [ 'id', 'ops' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$post_id = absint( $args['id'] ?? 0 );
		$ops     = is_array( $args['ops'] ?? null ) ? $args['ops'] : null;
		$dry_run = ! empty( $args['dry_run'] );

		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		if ( null === $ops ) {
			return new WP_Error( 'missing_ops', 'ops must be an array of RFC 6902 operations.' );
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$key     = isset( $args['idempotency_key'] ) ? (string) $args['idempotency_key'] : null;

		$cached = IATO_MCP_Elementor_Concurrency::idempotency_lookup( $user_id, 'update_elementor_patch', $key, $args );
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}
		if ( is_array( $cached ) ) {
			return IATO_MCP_Server::ok( $cached );
		}

		$decoded = IATO_MCP_Elementor_Adapter::decode_data( $post_id );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		[ $elements, $raw ] = $decoded;
		$previous_revision  = IATO_MCP_Elementor_Adapter::compute_revision( $raw );

		$if_revision = isset( $args['if_revision'] ) ? (string) $args['if_revision'] : null;
		if ( null !== $if_revision && '' !== $if_revision && $previous_revision !== $if_revision ) {
			return new WP_Error(
				'revision_conflict',
				'Stored revision does not match if_revision.',
				[ 'status' => 409, 'current_revision' => $previous_revision ]
			);
		}

		$applied = IATO_MCP_Elementor_Adapter::apply_rfc6902( $elements, $ops );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'post_id'           => $post_id,
				'dry_run'           => true,
				'previous_revision' => $previous_revision,
				'current_revision'  => null,
				'applied_patch'     => $applied,
			] );
		}

		$pipeline = IATO_MCP_Elementor_Adapter::write_pipeline( $post_id, $elements, $previous_revision );
		if ( is_wp_error( $pipeline ) ) {
			return $pipeline;
		}

		$response = [
			'post_id'             => $post_id,
			'previous_revision'   => $pipeline['previous_revision'],
			'current_revision'    => $pipeline['current_revision'],
			'applied_patch'       => $applied,
			'content_updated'     => $pipeline['content_updated'],
			'post_content_length' => $pipeline['post_content_length'],
		];

		// One change receipt for the whole patch — packed field marks the post as
		// the target since the patch may touch many widgets. Storage rows take
		// the canonical revision before/after; the API response only echoes the
		// receipt id + metadata (full applied_patch is already at the top level).
		$receipt = IATO_MCP_Change_Receipt::record(
			$post_id,
			'elementor_widget',
			'elementor:document:patch',
			$pipeline['previous_revision'],
			$pipeline['current_revision']
		);
		$response['change_receipt'] = [
			'change_id'   => $receipt['change_id'],
			'target_type' => $receipt['target_type'],
			'field'       => $receipt['field'],
			'applied_at'  => $receipt['applied_at'],
		];

		IATO_MCP_Elementor_Concurrency::idempotency_store( $user_id, 'update_elementor_patch', $key, $args, $response );
		return IATO_MCP_Server::ok( $response );
	}
);
