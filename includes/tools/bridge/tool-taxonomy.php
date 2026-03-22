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
				'sitemap_id' => [ 'type' => 'integer', 'description' => 'IATO sitemap ID (required)' ],
			],
			'required' => [ 'sitemap_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$sitemap_id = absint( $args['sitemap_id'] ?? 0 );
		if ( ! $sitemap_id ) {
			return new WP_Error( 'missing_sitemap_id', 'sitemap_id required' );
		}

		$response = IATO_MCP_IATO_Client::get_taxonomy( $sitemap_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$categories_data = $response['categories'] ?? [];
		$tags_data       = $response['tags'] ?? [];
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
			'sitemap_id'      => $sitemap_id,
			'categories'      => $categories,
			'tags'            => $tags,
			'unmatched_count' => $unmatched,
		] );
	}
);
