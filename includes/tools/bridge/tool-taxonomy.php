<?php
/**
 * Bridge Tool: get_iato_taxonomy
 *
 * Calls IATO get_taxonomy and maps IATO category/tag labels to WordPress
 * term IDs so Claude can call assign_term without a manual lookup step.
 *
 * IATO tools used: get_taxonomy
 * WP resolution:   get_term_by('name', ...) per label
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_iato_taxonomy',
	[
		'description' => 'Returns IATO taxonomy (categories and tags) mapped to WordPress term IDs. Use this to audit content classification and bulk-reassign WP categories or tags.',
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

		$response = IATO_MCP_IATO_Client::get_taxonomy( $crawl_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Canonical shape: data.tree is a flat or nested tree; many deployments expose
		// categories/tags as top-level keys under data. Fall back accordingly.
		$tree            = $response['data']['tree'] ?? $response['data'] ?? [];
		$categories_data = is_array( $tree['categories'] ?? null ) ? $tree['categories'] : [];
		$tags_data       = is_array( $tree['tags'] ?? null ) ? $tree['tags'] : [];
		$unmatched       = 0;

		$categories = [];
		foreach ( $categories_data as $cat ) {
			$label   = $cat['label'] ?? $cat['name'] ?? '';
			$wp_term = $label ? get_term_by( 'name', $label, 'category' ) : false;

			$matched = $wp_term && ! is_wp_error( $wp_term );
			if ( ! $matched ) {
				$unmatched++;
			}

			$categories[] = [
				'iato_id'    => $cat['id'] ?? null,
				'label'      => $label,
				'color'      => $cat['color'] ?? null,
				'wp_term_id' => $matched ? $wp_term->term_id : null,
				'wp_slug'    => $matched ? $wp_term->slug : null,
				'matched'    => $matched,
			];
		}

		$tags = [];
		foreach ( $tags_data as $tag ) {
			$label   = $tag['label'] ?? $tag['name'] ?? '';
			$wp_term = $label ? get_term_by( 'name', $label, 'post_tag' ) : false;

			$matched = $wp_term && ! is_wp_error( $wp_term );
			if ( ! $matched ) {
				$unmatched++;
			}

			$tags[] = [
				'iato_id'    => $tag['id'] ?? null,
				'label'      => $label,
				'color'      => $tag['color'] ?? null,
				'wp_term_id' => $matched ? $wp_term->term_id : null,
				'wp_slug'    => $matched ? $wp_term->slug : null,
				'matched'    => $matched,
			];
		}

		return IATO_MCP_Server::ok( [
			'crawl_id'        => $crawl_id,
			'categories'      => $categories,
			'tags'            => $tags,
			'unmatched_count' => $unmatched,
		] );
	}
);
