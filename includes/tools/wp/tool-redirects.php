<?php
/**
 * WP Tool: update_redirect
 *
 * Creates or updates redirect rules. Detects popular redirect plugins
 * (Redirection, Safe Redirect Manager, Yoast Premium) and falls back
 * to a custom option-based redirect handler.
 *
 * Includes write-with-rollback support (change receipt).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── Fallback redirect handler (fires only when no redirect plugin is active) ─

add_action( 'template_redirect', function () {
	$redirects = get_option( 'iato_mcp_redirects', [] );
	if ( empty( $redirects ) || ! is_array( $redirects ) ) {
		return;
	}

	$request_path = '/' . ltrim( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? '', '/' );

	foreach ( $redirects as $rule ) {
		$from = '/' . ltrim( $rule['from_url'] ?? '', '/' );
		if ( $from === $request_path ) {
			$type = (int) ( $rule['type'] ?? 301 );
			if ( ! in_array( $type, [ 301, 302, 307, 308 ], true ) ) {
				$type = 301;
			}
			wp_redirect( esc_url_raw( $rule['to_url'] ), $type );
			exit;
		}
	}
} );

// ── Redirect plugin detection ───────────────────────────────────────────────

/**
 * Detect which redirect handler to use.
 *
 * @return string 'redirection'|'safe_redirect_manager'|'yoast_premium'|'fallback'
 */
function iato_mcp_detect_redirect_handler(): string {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( 'redirection/redirection.php' ) && class_exists( 'Red_Item' ) ) {
		return 'redirection';
	}
	if ( is_plugin_active( 'safe-redirect-manager/safe-redirect-manager.php' ) ) {
		return 'safe_redirect_manager';
	}
	if ( class_exists( 'WPSEO_Redirect_Manager' ) ) {
		return 'yoast_premium';
	}
	return 'fallback';
}

/**
 * Get an existing redirect rule for a given from_url.
 *
 * @param string $from_url
 * @param string $handler
 * @return array|null { from_url, to_url, type } or null if not found.
 */
function iato_mcp_get_existing_redirect( string $from_url, string $handler ): ?array {
	$from_path = '/' . ltrim( wp_parse_url( $from_url, PHP_URL_PATH ) ?? $from_url, '/' );

	switch ( $handler ) {
		case 'safe_redirect_manager':
			$posts = get_posts( [
				'post_type'   => 'redirect_rule',
				'post_status' => 'publish',
				'numberposts' => 1,
				'meta_query'  => [
					[
						'key'   => '_redirect_rule_from',
						'value' => $from_path,
					],
				],
			] );
			if ( ! empty( $posts ) ) {
				return [
					'from_url'  => $from_path,
					'to_url'    => get_post_meta( $posts[0]->ID, '_redirect_rule_to', true ),
					'type'      => (int) get_post_meta( $posts[0]->ID, '_redirect_rule_status_code', true ),
					'_post_id'  => $posts[0]->ID,
				];
			}
			return null;

		case 'fallback':
			$redirects = get_option( 'iato_mcp_redirects', [] );
			foreach ( $redirects as $rule ) {
				$rule_from = '/' . ltrim( $rule['from_url'] ?? '', '/' );
				if ( $rule_from === $from_path ) {
					return $rule;
				}
			}
			return null;

		default:
			return null;
	}
}

/**
 * Write a redirect rule using the detected handler.
 *
 * @param string $from_url
 * @param string $to_url
 * @param int    $type
 * @param string $handler
 * @return true|WP_Error
 */
function iato_mcp_write_redirect( string $from_url, string $to_url, int $type, string $handler ): true|WP_Error {
	$from_path = '/' . ltrim( wp_parse_url( $from_url, PHP_URL_PATH ) ?? $from_url, '/' );

	switch ( $handler ) {
		case 'redirection':
			if ( ! class_exists( 'Red_Item' ) ) {
				return new WP_Error( 'redirection_unavailable', 'Redirection plugin class not available.' );
			}
			$result = Red_Item::create( [
				'url'         => $from_path,
				'action_data' => [ 'url' => $to_url ],
				'action_type' => 'url',
				'action_code' => $type,
				'match_type'  => 'url',
				'group_id'    => 1,
			] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return true;

		case 'safe_redirect_manager':
			$existing = iato_mcp_get_existing_redirect( $from_url, $handler );
			if ( $existing && ! empty( $existing['_post_id'] ) ) {
				update_post_meta( $existing['_post_id'], '_redirect_rule_to', esc_url_raw( $to_url ) );
				update_post_meta( $existing['_post_id'], '_redirect_rule_status_code', $type );
				return true;
			}
			$post_id = wp_insert_post( [
				'post_type'   => 'redirect_rule',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $from_path . ' → ' . $to_url ),
			] );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			update_post_meta( $post_id, '_redirect_rule_from', $from_path );
			update_post_meta( $post_id, '_redirect_rule_to', esc_url_raw( $to_url ) );
			update_post_meta( $post_id, '_redirect_rule_status_code', $type );
			return true;

		case 'yoast_premium':
			if ( ! class_exists( 'WPSEO_Redirect_Manager' ) ) {
				return new WP_Error( 'yoast_premium_unavailable', 'Yoast Premium redirect manager not available.' );
			}
			$manager  = new WPSEO_Redirect_Manager();
			$redirect = new WPSEO_Redirect( $from_path, $to_url, $type );
			$manager->create_redirect( $redirect );
			return true;

		case 'fallback':
		default:
			$redirects = get_option( 'iato_mcp_redirects', [] );
			if ( ! is_array( $redirects ) ) {
				$redirects = [];
			}

			// Cap at 500 redirects.
			if ( count( $redirects ) >= 500 ) {
				return new WP_Error( 'redirect_limit', 'Maximum of 500 fallback redirects reached. Install a dedicated redirect plugin for more.' );
			}

			// Remove existing rule for this from_url if any.
			$redirects = array_filter( $redirects, function ( $r ) use ( $from_path ) {
				return ( '/' . ltrim( $r['from_url'] ?? '', '/' ) ) !== $from_path;
			} );

			$redirects[] = [
				'from_url' => $from_path,
				'to_url'   => $to_url,
				'type'     => $type,
			];

			update_option( 'iato_mcp_redirects', array_values( $redirects ), false );
			return true;
	}
}

// ── update_redirect ─────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_redirect',
	[
		'description' => 'Create or update a redirect rule. Auto-detects redirect plugins (Redirection, Safe Redirect Manager, Yoast Premium) or uses a built-in fallback. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'from_url' => [ 'type' => 'string',  'description' => 'Source URL path to redirect from (required).' ],
				'to_url'   => [ 'type' => 'string',  'description' => 'Destination URL to redirect to (required).' ],
				'type'     => [ 'type' => 'integer', 'description' => 'HTTP status code: 301, 302, 307, 308 (default: 301).' ],
				'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'from_url', 'to_url' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$from_url = sanitize_text_field( $args['from_url'] ?? '' );
		$to_url   = esc_url_raw( $args['to_url'] ?? '' );
		$type     = absint( $args['type'] ?? 301 );
		$dry_run  = ! empty( $args['dry_run'] );

		if ( empty( $from_url ) ) return new WP_Error( 'missing_from_url', 'from_url is required.' );
		if ( empty( $to_url ) )   return new WP_Error( 'missing_to_url', 'to_url is required.' );

		if ( ! in_array( $type, [ 301, 302, 307, 308 ], true ) ) {
			$type = 301;
		}

		$handler  = iato_mcp_detect_redirect_handler();
		$existing = iato_mcp_get_existing_redirect( $from_url, $handler );

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'       => true,
				'from_url'      => $from_url,
				'to_url'        => $to_url,
				'type'          => $type,
				'handler'       => $handler,
				'existing_rule' => $existing,
			] );
		}

		$before = $existing ? wp_json_encode( $existing ) : null;

		$result = iato_mcp_write_redirect( $from_url, $to_url, $type, $handler );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$after = wp_json_encode( [ 'from_url' => $from_url, 'to_url' => $to_url, 'type' => $type ] );

		$receipt = IATO_MCP_Change_Receipt::record( null, 'redirect', 'rule', $before, $after );

		$data = [
			'from_url' => $from_url,
			'to_url'   => $to_url,
			'type'     => $type,
			'handler'  => $handler,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);
