<?php
/**
 * Bridge Tool: get_iato_nav_audit
 *
 * Combines get_menus + get_menu_items + find_orphan_pages from IATO into
 * a single navigation audit output with WP slugs, ready for Claude to
 * chain into update_menu_item calls.
 *
 * IATO tools used: get_menus, get_menu_items, find_orphan_pages
 * WP resolution:   url_to_postid() per orphan URL
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_nav_audit',
	[
		'description' => 'Audits site navigation: lists menus with their items, identifies pages not in any menu (orphans), and returns WordPress slugs for all pages so Claude can add them to menus directly.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'crawl_id' => [ 'type' => 'string', 'description' => 'IATO crawl job ID. Falls back to default crawl ID from settings.' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$crawl_id = sanitize_text_field( $args['crawl_id'] ?? '' );
		if ( ! $crawl_id ) {
			$crawl_id = sanitize_text_field( get_option( 'iato_mcp_crawl_id', '' ) );
		}
		if ( ! $crawl_id ) {
			return new WP_Error( 'missing_crawl_id', 'crawl_id required. Set a default in Settings > IATO MCP or pass it explicitly.' );
		}

		// Fetch menus from IATO.
		$menus_response = IATO_MCP_IATO_Client::get_menus( $crawl_id );
		if ( is_wp_error( $menus_response ) ) {
			return $menus_response;
		}

		$menus_data = $menus_response['data']['menus'] ?? [];
		if ( ! is_array( $menus_data ) ) {
			$menus_data = [];
		}

		// Fetch menu items.
		$menus = [];
		foreach ( $menus_data as $menu ) {
			$menu_id = absint( $menu['id'] ?? 0 );
			if ( ! $menu_id ) {
				continue;
			}

			$items_response = IATO_MCP_IATO_Client::get_menu_items( $crawl_id );
			$items_data     = [];
			if ( ! is_wp_error( $items_response ) ) {
				$items_data = $items_response['data']['items'] ?? [];
				if ( ! is_array( $items_data ) ) {
					$items_data = [];
				}
			}

			$items = [];
			foreach ( $items_data as $item ) {
				$url     = $item['url'] ?? '';
				$wp_id   = $url ? url_to_postid( $url ) : 0;
				$wp_slug = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;

				$items[] = [
					'title'      => $item['title'] ?? '',
					'url'        => $url,
					'parent_id'  => $item['parent_id'] ?? null,
					'wp_post_id' => $wp_id ?: null,
					'wp_slug'    => $wp_slug ?: null,
				];
			}

			$menus[] = [
				'id'         => $menu_id,
				'name'       => $menu['name'] ?? '',
				'item_count' => count( $items ),
				'items'      => $items,
			];
		}

		// Fetch orphan pages.
		$orphans_response = IATO_MCP_IATO_Client::get_orphan_pages( $crawl_id, [ 'section', 'planned' ] );
		$orphans_data     = [];
		if ( ! is_wp_error( $orphans_response ) ) {
			$orphans_data = $orphans_response['data']['pages'] ?? [];
			if ( ! is_array( $orphans_data ) ) {
				$orphans_data = [];
			}
		}

		$orphans = [];
		foreach ( $orphans_data as $orphan ) {
			$url     = $orphan['url'] ?? '';
			$wp_id   = $url ? url_to_postid( $url ) : 0;
			$wp_slug = $wp_id ? get_post_field( 'post_name', $wp_id ) : null;

			$orphans[] = [
				'iato_node_id' => $orphan['id'] ?? null,
				'title'        => $orphan['title'] ?? '',
				'url'          => $url,
				'wp_post_id'   => $wp_id ?: null,
				'wp_slug'      => $wp_slug ?: null,
			];
		}

		return IATO_MCP_Server::ok( [
			'crawl_id'     => $crawl_id,
			'menus'        => $menus,
			'orphan_count' => count( $orphans ),
			'orphans'      => $orphans,
		] );
	}
);
