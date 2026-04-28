<?php
/**
 * WP Tools: set_heading_level, set_widget_setting.
 *
 * Thin semantic wrappers over update_elementor_widget. Each handler
 * pre-validates the target widget type and then delegates the actual write
 * to IATO_MCP_Elementor_Adapter::do_update_widget — same revisioning,
 * idempotency, and change-receipt wiring as the underlying tool.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── set_heading_level ──────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'set_heading_level',
	[
		'description' => 'Set the header_size on a heading widget (h1-h6). Wrapper over update_elementor_widget — same dry_run, if_revision, idempotency_key semantics. Errors if the target widget is not a heading.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'              => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'widget_id'       => [ 'type' => 'string',  'description' => 'Heading widget ID (required).' ],
				'level'           => [ 'type' => 'string',  'enum' => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], 'description' => 'Target heading level (required).' ],
				'dry_run'         => [ 'type' => 'boolean' ],
				'if_revision'     => [ 'type' => 'string'  ],
				'idempotency_key' => [ 'type' => 'string'  ],
			],
			'required' => [ 'id', 'widget_id', 'level' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$post_id   = absint( $args['id'] ?? 0 );
		$widget_id = isset( $args['widget_id'] ) ? (string) $args['widget_id'] : '';
		$level     = isset( $args['level'] ) ? strtolower( (string) $args['level'] ) : '';

		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		if ( '' === $widget_id ) {
			return new WP_Error( 'missing_widget_id', 'widget_id is required.' );
		}
		if ( ! in_array( $level, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ) {
			return new WP_Error( 'invalid_level', 'level must be one of h1, h2, h3, h4, h5, h6.' );
		}

		// Pre-flight: confirm widget exists and is a heading.
		$decoded = IATO_MCP_Elementor_Adapter::decode_data( $post_id );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		[ $elements, ] = $decoded;
		$found         = IATO_MCP_Elementor_Adapter::find_widget( $elements, $widget_id );
		if ( null === $found ) {
			return new WP_Error( 'widget_not_found', "Widget {$widget_id} not found in post {$post_id}." );
		}
		$widget_type = (string) ( $found['element']['widgetType'] ?? '' );
		if ( 'heading' !== $widget_type ) {
			return new WP_Error(
				'invalid_widget_type',
				"set_heading_level only operates on heading widgets; got '{$widget_type}'."
			);
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$key     = isset( $args['idempotency_key'] ) ? (string) $args['idempotency_key'] : null;

		$cached = IATO_MCP_Elementor_Concurrency::idempotency_lookup( $user_id, 'set_heading_level', $key, $args );
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}
		if ( is_array( $cached ) ) {
			return IATO_MCP_Server::ok( $cached );
		}

		$delegated_args = [
			'id'             => $post_id,
			'widget_id'      => $widget_id,
			'settings_patch' => [ 'header_size' => $level ],
			'dry_run'        => ! empty( $args['dry_run'] ),
			'if_revision'    => $args['if_revision'] ?? null,
		];

		$result = IATO_MCP_Elementor_Adapter::do_update_widget( $delegated_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		IATO_MCP_Elementor_Concurrency::idempotency_store( $user_id, 'set_heading_level', $key, $args, $result );
		return IATO_MCP_Server::ok( $result );
	}
);

// ── set_widget_setting ─────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'set_widget_setting',
	[
		'description' => 'Set a single key on a widget\'s settings. Convenience wrapper over update_elementor_widget for the common single-field case. Same dry_run, if_revision, idempotency_key semantics.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'              => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'widget_id'       => [ 'type' => 'string',  'description' => 'Widget ID (required).' ],
				'key'             => [ 'type' => 'string',  'description' => 'Settings key (required).' ],
				'value'           => [ 'description' => 'New value. null removes the key.' ],
				'dry_run'         => [ 'type' => 'boolean' ],
				'if_revision'     => [ 'type' => 'string'  ],
				'idempotency_key' => [ 'type' => 'string'  ],
			],
			'required' => [ 'id', 'widget_id', 'key' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$post_id   = absint( $args['id'] ?? 0 );
		$widget_id = isset( $args['widget_id'] ) ? (string) $args['widget_id'] : '';
		$key_name  = isset( $args['key'] ) ? (string) $args['key'] : '';

		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		if ( '' === $widget_id ) {
			return new WP_Error( 'missing_widget_id', 'widget_id is required.' );
		}
		if ( '' === $key_name ) {
			return new WP_Error( 'missing_key', 'key is required.' );
		}

		// 'value' may be legitimately null (= remove); array_key_exists, not isset.
		$value = array_key_exists( 'value', $args ) ? $args['value'] : null;

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$idem    = isset( $args['idempotency_key'] ) ? (string) $args['idempotency_key'] : null;

		$cached = IATO_MCP_Elementor_Concurrency::idempotency_lookup( $user_id, 'set_widget_setting', $idem, $args );
		if ( is_wp_error( $cached ) ) {
			return $cached;
		}
		if ( is_array( $cached ) ) {
			return IATO_MCP_Server::ok( $cached );
		}

		$delegated_args = [
			'id'             => $post_id,
			'widget_id'      => $widget_id,
			'settings_patch' => [ $key_name => $value ],
			'dry_run'        => ! empty( $args['dry_run'] ),
			'if_revision'    => $args['if_revision'] ?? null,
		];

		$result = IATO_MCP_Elementor_Adapter::do_update_widget( $delegated_args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		IATO_MCP_Elementor_Concurrency::idempotency_store( $user_id, 'set_widget_setting', $idem, $args, $result );
		return IATO_MCP_Server::ok( $result );
	}
);
