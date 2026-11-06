<?php
/**
 * WP Tools: get_seo_data, update_seo_data
 *
 * Reads and writes SEO title + description via IATO_MCP_SEO_Adapter,
 * which handles Yoast / RankMath / SEOPress / fallback transparently.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_seo_data ──────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_seo_data',
	[
		'description' => 'Get the SEO title and meta description for a post or page.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Post ID (required)' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] ?? 0 );
		if ( ! $post_id ) return new WP_Error( 'missing_id', 'Post ID required' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$read_check = IATO_MCP_Auth::require_read_post( $post_id );
		if ( is_wp_error( $read_check ) ) {
			return $read_check;
		}

		$meta = IATO_MCP_SEO_Adapter::get_meta( $post_id );

		return IATO_MCP_Server::ok( [
			'id'          => $post_id,
			'title'       => $meta['title'],
			'description' => $meta['description'],
			'plugin'      => $meta['plugin'],
		] );
	}
);

// ── update_seo_data ───────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_seo_data',
	[
		'description' => 'Update the SEO title and/or meta description for a post. Works with Yoast, RankMath, SEOPress, or native WP.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'integer', 'description' => 'Post ID (required)' ],
				'title'       => [ 'type' => 'string',  'description' => 'New SEO title' ],
				'description' => [ 'type' => 'string',  'description' => 'New meta description' ],
				'dry_run'     => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		// Accept both schema name (id) and Autopilot name (post_id).
		$post_id = absint( $args['id'] ?? $args['post_id'] ?? 0 );
		if ( ! $post_id ) return new WP_Error( 'missing_id', 'Post ID required' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}

		if ( ! isset( $args['title'] ) && ! isset( $args['seo_title'] ) && ! isset( $args['description'] ) && ! isset( $args['meta_description'] ) ) {
			return new WP_Error( 'missing_fields', 'Provide at least one of title or description to update.' );
		}

		// Capture before values for change receipt.
		$before = IATO_MCP_SEO_Adapter::get_meta( $post_id );

		$updated  = [];
		$receipts = [];

		// Accept both schema names (title/description) and Autopilot names (seo_title/meta_description).
		$new_title = $args['title'] ?? $args['seo_title'] ?? null;
		$new_desc  = $args['description'] ?? $args['meta_description'] ?? null;

		if ( ! empty( $args['dry_run'] ) ) {
			$would = [];
			foreach ( [ 'title' => $new_title, 'description' => $new_desc ] as $field => $value ) {
				if ( null === $value ) {
					continue;
				}
				$refusal = IATO_MCP_SEO_Adapter::write_refusal( $field );
				if ( null !== $refusal ) {
					return $refusal;
				}
				// In the form it would read back as (IATO_MCP_SEO_Adapter::get_meta()).
				$after = IATO_MCP_SEO_Adapter::clean_text( $value );
				if ( $after !== $before[ $field ] ) {
					$would[ $field ] = [ 'before' => $before[ $field ], 'after' => $after ];
				}
			}
			return IATO_MCP_Server::ok( [
				'dry_run'      => true,
				'action'       => [] === $would ? 'no_change' : 'would_update',
				'id'           => $post_id,
				'plugin'       => $before['plugin'],
				'would_change' => (object) $would,
				'not_checked'  => IATO_MCP_Server::DRY_RUN_NOT_CHECKED,
			] );
		}

		if ( null !== $new_title ) {
			$result = IATO_MCP_SEO_Adapter::update_title( $post_id, $new_title );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$updated[]  = 'title';
			// As stored and read back (the adapter sanitises on write).
			$receipts[] = IATO_MCP_Change_Receipt::record( $post_id, 'page', 'title', $before['title'], IATO_MCP_SEO_Adapter::get_meta( $post_id )['title'] );
		}

		if ( null !== $new_desc ) {
			$result = IATO_MCP_SEO_Adapter::update_description( $post_id, $new_desc );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$updated[]  = 'description';
			$receipts[] = IATO_MCP_Change_Receipt::record( $post_id, 'page', 'meta_description', $before['description'], IATO_MCP_SEO_Adapter::get_meta( $post_id )['description'] );
		}

		$meta = IATO_MCP_SEO_Adapter::get_meta( $post_id );

		$data = [
			'id'          => $post_id,
			'updated'     => $updated,
			'title'       => $meta['title'],
			'description' => $meta['description'],
			'plugin'      => $meta['plugin'],
		];

		if ( count( $receipts ) === 1 ) {
			IATO_MCP_Change_Receipt::append( $data, $receipts[0] );
		} elseif ( count( $receipts ) > 1 ) {
			$data['change_receipts'] = $receipts;
		}

		return IATO_MCP_Server::ok( $data );
	}
);
