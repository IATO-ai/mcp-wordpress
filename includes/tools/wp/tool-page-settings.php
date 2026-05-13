<?php
/**
 * WP Tool: set_page_settings
 *
 * Convenience wrapper over post_meta writes for the two most common page-level
 * settings clusters: Astra theme per-post overrides and Elementor's
 * _elementor_page_settings. Routes abstract names ('hide_title', 'sidebar_layout',
 * etc.) through IATO_MCP_Theme_Adapter, then writes each concrete meta key
 * and records one change_receipt per key under target_type=post_meta.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'set_page_settings',
	[
		'description' => 'Set per-post theme + Elementor page settings (hide_title, sidebar_layout, content_layout, disable_header, disable_footer, page_template, elementor_hide_title, elementor_page_settings). Astra-specific keys are silently skipped on non-Astra themes and reported in `skipped`. Returns one change_receipt per concrete meta key written.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'       => [ 'type' => 'integer', 'description' => 'WordPress post/page ID (required).' ],
				'settings' => [ 'type' => 'object',  'description' => 'Abstract setting map. See description for accepted keys.' ],
				'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview without writing (default false).' ],
			],
			'required' => [ 'id', 'settings' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$post_id  = absint( $args['id'] ?? 0 );
		$settings = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : null;
		$dry_run  = ! empty( $args['dry_run'] );

		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		if ( null === $settings ) {
			return new WP_Error( 'missing_settings', 'settings object is required.' );
		}
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}
		if ( function_exists( 'wp_check_post_lock' ) ) {
			$lock_user = wp_check_post_lock( $post_id );
			if ( $lock_user ) {
				return new WP_Error( 'post_locked', 'Post is currently being edited by another user.', [ 'lock_user_id' => $lock_user ] );
			}
		}

		$mapped = IATO_MCP_Theme_Adapter::map_page_settings( $settings );
		$writes = $mapped['writes'];
		$skipped = $mapped['skipped'];

		if ( empty( $writes ) ) {
			return IATO_MCP_Server::ok( [
				'id'              => $post_id,
				'applied'         => [],
				'skipped'         => $skipped,
				'change_receipts' => [],
				'note'            => 'No applicable settings for the active theme/builder.',
			] );
		}

		$planned = [];
		$touched_elementor = false;

		foreach ( $writes as $write ) {
			$key = (string) $write['key'];

			// Defence-in-depth: theme adapter pre-vets, but rerun policy.
			$policy = IATO_MCP_Meta_Policy::check_write( $key, true );
			if ( is_wp_error( $policy ) ) {
				return $policy;
			}

			$raw_value = $write['value'];

			// _elementor_page_settings merge handling.
			if ( '_elementor_page_settings' === $key && is_array( $raw_value ) && ! empty( $raw_value['__merge__'] ) ) {
				$existing = get_post_meta( $post_id, $key, true );
				$existing = is_array( $existing ) ? $existing : ( is_string( $existing ) && '' !== $existing ? (array) maybe_unserialize( $existing ) : [] );
				$after    = array_merge( is_array( $existing ) ? $existing : [], $raw_value['__patch__'] );
				$before   = is_array( $existing ) && ! empty( $existing ) ? $existing : null;
				$touched_elementor = true;
			} else {
				$before = get_post_meta( $post_id, $key, true );
				$before = ( '' === $before ) ? null : $before;
				$after  = is_string( $raw_value ) ? sanitize_text_field( $raw_value ) : $raw_value;
			}

			$planned[] = [
				'key'    => $key,
				'before' => $before,
				'after'  => $after,
			];
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'  => true,
				'id'       => $post_id,
				'planned'  => $planned,
				'skipped'  => $skipped,
			] );
		}

		$applied  = [];
		$receipts = [];

		foreach ( $planned as $step ) {
			$key    = $step['key'];
			$before = $step['before'];
			$after  = $step['after'];

			if ( null === $after || '' === $after ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $after );
			}

			$receipts[] = IATO_MCP_Change_Receipt::record( $post_id, 'post_meta', $key, $before, $after );
			$applied[]  = $key;
		}

		clean_post_cache( $post_id );
		if ( $touched_elementor ) {
			delete_post_meta( $post_id, '_elementor_css' );
			wp_cache_delete( $post_id, 'post_meta' );
			wp_cache_delete( $post_id, 'posts' );
			if ( class_exists( '\Elementor\Plugin' ) ) {
				$plugin = \Elementor\Plugin::$instance;
				if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
					$plugin->files_manager->clear_cache();
				}
			}
		}

		return IATO_MCP_Server::ok( [
			'id'              => $post_id,
			'applied'         => $applied,
			'skipped'         => $skipped,
			'change_receipts' => $receipts,
		] );
	}
);
