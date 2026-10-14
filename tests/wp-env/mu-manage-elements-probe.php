<?php
/**
 * Plugin Name: IATO C0 probe — call elementor/manage-elements inside an MCP request
 * Description: TEST-ONLY must-use plugin. Copy into wp-content/mu-plugins/ on the wp-env site; never ship.
 *
 * Registers an extra MCP tool `_c0_probe_manage_elements` through the plugin's
 * public IATO_MCP_Server::register_tool(). Its handler runs inside a real
 * /iato-mcp/v1/message request (so the plugin's authentication has already
 * run) and calls Elementor's `elementor/manage-elements` ability from PHP,
 * reporting the current user id and the ability result. This demonstrates
 * whether a Bearer-authenticated request is permitted to use Elementor's own
 * abilities (Sprint C0 acceptance criterion).
 */

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'IATO_MCP_Server' ) ) {
		return;
	}
	IATO_MCP_Server::register_tool(
		'_c0_probe_manage_elements',
		[
			'description' => 'TEST ONLY: call elementor/manage-elements from PHP inside this MCP request.',
			'inputSchema' => [
				'type'       => 'object',
				'properties' => [
					'post_id'   => [ 'type' => 'integer' ],
					'widget_id' => [ 'type' => 'string' ],
					'tag'       => [ 'type' => 'string' ],
				],
				'required'   => [ 'post_id', 'widget_id', 'tag' ],
			],
		],
		function ( array $args ) {
			$out = [
				'current_user_id' => get_current_user_id(),
				'unbound'         => method_exists( 'IATO_MCP_Auth', 'is_unbound' ) ? IATO_MCP_Auth::is_unbound() : null,
				'can_edit_posts'  => current_user_can( 'edit_posts' ),
				'ability_found'   => function_exists( 'wp_has_ability' ) && wp_has_ability( 'elementor/manage-elements' ),
			];
			if ( ! $out['ability_found'] ) {
				return IATO_MCP_Server::ok( $out );
			}
			try {
				$ability = wp_get_ability( 'elementor/manage-elements' );
				$result  = $ability->execute( [
					'post_id'    => (int) $args['post_id'],
					'operations' => [
						[ 'action' => 'update', 'element_id' => (string) $args['widget_id'], 'settings' => [ 'tag' => (string) $args['tag'] ] ],
					],
				] );
				$out['result'] = is_wp_error( $result )
					? [ 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ]
					: $result;
			} catch ( Throwable $e ) {
				$out['result'] = [ 'throwable' => get_class( $e ), 'message' => $e->getMessage() ];
			}
			return IATO_MCP_Server::ok( $out );
		}
	);
}, 20 );
