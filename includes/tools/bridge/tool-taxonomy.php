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
		if ( ! $sitemap_id ) return new WP_Error( 'missing_sitemap_id', 'sitemap_id required' );

		// TODO: implement
		// 1. IATO_MCP_IATO_Client::get_taxonomy($sitemap_id)
		// 2. For each category label: get_term_by('name', $label, 'category')
		//    Attach wp_term_id and wp_slug if found, null if no match
		// 3. For each tag label: get_term_by('name', $label, 'post_tag')
		//    Attach wp_term_id and wp_slug if found, null if no match
		// 4. Return {
		//      categories: [{iato_id, label, color, wp_term_id, wp_slug, matched: bool}],
		//      tags:       [{iato_id, label, color, wp_term_id, wp_slug, matched: bool}],
		//      unmatched_count: int  // labels in IATO with no WP equivalent
		//    }
		return new WP_Error( 'not_implemented', 'get_iato_taxonomy not yet implemented' );
	}
);
