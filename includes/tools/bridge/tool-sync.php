<?php
/**
 * Bridge Tools: WP → IATO Sync
 *
 * Three tools that push WordPress data into IATO:
 *   - sync_wp_pages_to_iato    — posts/pages → IATO sitemap nodes
 *   - sync_wp_taxonomy_to_iato — categories (hierarchical) + tags → IATO taxonomy
 *   - sync_wp_meta_to_iato     — SEO titles/descriptions → IATO via fix_seo_issue
 *
 * All require sitemap_id and crawl_id. All support dry_run: true.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── Helper: map WP post_type to IATO page_type ──────────────────────────────

/**
 * @param string $post_type WP post type.
 * @return string IATO page_type.
 */
function iato_mcp_map_page_type( string $post_type ): string {
	return match ( $post_type ) {
		'post'    => 'article',
		'page'    => 'landing',
		'product' => 'product',
		default   => $post_type,
	};
}

/**
 * @param string $post_status WP post status.
 * @return string IATO node status.
 */
function iato_mcp_map_status( string $post_status ): string {
	return match ( $post_status ) {
		'publish' => 'published',
		'draft'   => 'draft',
		'pending' => 'needs_review',
		'future'  => 'draft',
		'private' => 'published',
		default   => 'draft',
	};
}

/**
 * Build a URL → node_id lookup map from IATO sitemap nodes.
 *
 * @param array $nodes_response Response from get_sitemap_nodes().
 * @return array<string, int> URL → node_id.
 */
function iato_mcp_build_node_url_map( array $nodes_response ): array {
	$map   = [];
	$nodes = $nodes_response['nodes'] ?? $nodes_response['data'] ?? $nodes_response;
	if ( ! is_array( $nodes ) ) {
		return $map;
	}
	foreach ( $nodes as $node ) {
		$url = $node['url'] ?? '';
		$id  = $node['id'] ?? $node['node_id'] ?? 0;
		if ( $url && $id ) {
			$map[ untrailingslashit( $url ) ] = (int) $id;
		}
	}
	return $map;
}

/**
 * Build a URL → page_id lookup map from IATO crawl pages.
 *
 * @param array $pages_response Response from get_pages().
 * @return array<string, int> URL → page_id.
 */
function iato_mcp_build_page_url_map( array $pages_response ): array {
	$map   = [];
	$pages = $pages_response['pages'] ?? $pages_response['data'] ?? $pages_response;
	if ( ! is_array( $pages ) ) {
		return $map;
	}
	foreach ( $pages as $page ) {
		$url = $page['url'] ?? '';
		$id  = $page['id'] ?? $page['page_id'] ?? 0;
		if ( $url && $id ) {
			$map[ untrailingslashit( $url ) ] = (int) $id;
		}
	}
	return $map;
}

// ═════════════════════════════════════════════════════════════════════════════
// Tool 1: sync_wp_pages_to_iato
// ═════════════════════════════════════════════════════════════════════════════

IATO_MCP_Server::register_tool(
	'sync_wp_pages_to_iato',
	[
		'description' => 'Syncs WordPress posts and pages to IATO as sitemap nodes. Creates nodes for pages not yet in the IATO sitemap, with rich metadata: title, URL, page type, status, author, publish date, and slug.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'sitemap_id'  => [ 'type' => 'integer', 'description' => 'IATO sitemap ID (required)' ],
				'crawl_id'    => [ 'type' => 'string',  'description' => 'IATO crawl ID (required)' ],
				'post_type'   => [ 'type' => 'string',  'description' => 'Comma-separated WP post types (default: post,page)' ],
				'post_status' => [ 'type' => 'string',  'description' => 'WP post status filter (default: publish)' ],
				'limit'       => [ 'type' => 'integer', 'description' => 'Max posts to sync (default: 100)' ],
				'dry_run'     => [ 'type' => 'boolean', 'description' => 'Preview without creating nodes (default: false)' ],
			],
			'required' => [ 'sitemap_id', 'crawl_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$sitemap_id  = absint( $args['sitemap_id'] ?? 0 );
		$crawl_id    = sanitize_text_field( $args['crawl_id'] ?? '' );
		$post_types  = array_map( 'trim', explode( ',', sanitize_text_field( $args['post_type'] ?? 'post,page' ) ) );
		$post_status = sanitize_text_field( $args['post_status'] ?? 'publish' );
		$limit       = absint( $args['limit'] ?? 100 );
		$dry_run     = (bool) ( $args['dry_run'] ?? false );

		if ( ! $sitemap_id || ! $crawl_id ) {
			return new WP_Error( 'missing_params', 'sitemap_id and crawl_id are required.' );
		}

		// Fetch existing IATO nodes to avoid duplicates.
		$nodes_response = IATO_MCP_IATO_Client::get_sitemap_nodes( $sitemap_id );
		if ( is_wp_error( $nodes_response ) ) {
			return $nodes_response;
		}
		$node_url_map = iato_mcp_build_node_url_map( $nodes_response );

		// Query WordPress posts.
		$posts = get_posts( [
			'post_type'      => $post_types,
			'post_status'    => $post_status,
			'numberposts'    => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$created = 0;
		$skipped = 0;
		$pages   = [];

		foreach ( $posts as $post ) {
			$url        = untrailingslashit( get_permalink( $post ) );
			$title      = $post->post_title;
			$page_type  = iato_mcp_map_page_type( $post->post_type );
			$status     = iato_mcp_map_status( $post->post_status );
			$author     = get_the_author_meta( 'display_name', $post->post_author );
			$date       = get_the_date( 'Y-m-d', $post );
			$slug       = $post->post_name;
			$notes      = "Author: {$author} | Published: {$date} | Slug: {$slug}";

			$exists = isset( $node_url_map[ $url ] );

			$page_entry = [
				'url'          => $url,
				'title'        => $title,
				'wp_post_id'   => $post->ID,
				'post_type'    => $post->post_type,
				'page_type'    => $page_type,
				'status'       => $status,
				'author'       => $author,
				'publish_date' => $date,
				'slug'         => $slug,
				'action'       => $exists ? 'skip' : 'create',
			];

			if ( $exists ) {
				++$skipped;
				$pages[] = $page_entry;
				continue;
			}

			if ( ! $dry_run ) {
				$result = IATO_MCP_IATO_Client::create_sitemap_node(
					$sitemap_id,
					$title,
					$url,
					null,
					'page',
					$page_type,
					$post->ID,
					$post->post_type
				);

				if ( is_wp_error( $result ) ) {
					$page_entry['action'] = 'error';
					$page_entry['error']  = $result->get_error_message();
					$pages[] = $page_entry;
					continue;
				}

				// Update the node with status and notes.
				$node_id = $result['id'] ?? $result['node_id'] ?? 0;
				if ( $node_id ) {
					IATO_MCP_IATO_Client::update_sitemap_node( $sitemap_id, $node_id, [
						'status' => $status,
						'notes'  => $notes,
					] );
				}
			}

			++$created;
			$pages[] = $page_entry;
		}

		return IATO_MCP_Server::ok( [
			'sitemap_id'     => $sitemap_id,
			'dry_run'        => $dry_run,
			'total_wp_posts' => count( $posts ),
			'created'        => $created,
			'skipped'        => $skipped,
			'pages'          => $pages,
		] );
	}
);

// ═════════════════════════════════════════════════════════════════════════════
// Tool 2: sync_wp_taxonomy_to_iato
// ═════════════════════════════════════════════════════════════════════════════

IATO_MCP_Server::register_tool(
	'sync_wp_taxonomy_to_iato',
	[
		'description' => 'Syncs WordPress categories (with parent/child hierarchy) and tags to IATO. Categories are created as hierarchical IATO categories preserving the parent structure. Tags are created as flat IATO tags. Both are assigned to matching IATO sitemap nodes.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'sitemap_id' => [ 'type' => 'integer', 'description' => 'IATO sitemap ID (required)' ],
				'crawl_id'   => [ 'type' => 'string',  'description' => 'IATO crawl ID (required)' ],
				'taxonomy'   => [ 'type' => 'string',  'description' => 'Comma-separated WP taxonomies (default: category,post_tag)' ],
				'dry_run'    => [ 'type' => 'boolean', 'description' => 'Preview without syncing (default: false)' ],
			],
			'required' => [ 'sitemap_id', 'crawl_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$sitemap_id = absint( $args['sitemap_id'] ?? 0 );
		$crawl_id   = sanitize_text_field( $args['crawl_id'] ?? '' );
		$taxonomies = array_map( 'trim', explode( ',', sanitize_text_field( $args['taxonomy'] ?? 'category,post_tag' ) ) );
		$dry_run    = (bool) ( $args['dry_run'] ?? false );

		if ( ! $sitemap_id || ! $crawl_id ) {
			return new WP_Error( 'missing_params', 'sitemap_id and crawl_id are required.' );
		}

		// Fetch IATO nodes for URL → node_id mapping.
		$nodes_response = IATO_MCP_IATO_Client::get_sitemap_nodes( $sitemap_id );
		if ( is_wp_error( $nodes_response ) ) {
			return $nodes_response;
		}
		$node_url_map = iato_mcp_build_node_url_map( $nodes_response );

		// Fetch existing IATO taxonomy.
		$iato_taxonomy = IATO_MCP_IATO_Client::get_taxonomy( $sitemap_id );
		if ( is_wp_error( $iato_taxonomy ) ) {
			return $iato_taxonomy;
		}

		// Build existing label → id maps.
		$existing_categories = [];
		$existing_tags       = [];
		foreach ( ( $iato_taxonomy['categories'] ?? [] ) as $cat ) {
			$existing_categories[ $cat['label'] ?? '' ] = $cat['id'] ?? '';
		}
		foreach ( ( $iato_taxonomy['tags'] ?? [] ) as $tag ) {
			$existing_tags[ $tag['label'] ?? '' ] = $tag['id'] ?? '';
		}

		$cat_created    = 0;
		$cat_skipped    = 0;
		$cat_assigned   = 0;
		$tag_created    = 0;
		$tag_skipped    = 0;
		$tag_assigned   = 0;
		$details        = [];

		// ── Categories (hierarchical) ────────────────────────────────────────

		if ( in_array( 'category', $taxonomies, true ) ) {
			$wp_categories = get_terms( [
				'taxonomy'   => 'category',
				'hide_empty' => false,
			] );

			if ( ! is_wp_error( $wp_categories ) && is_array( $wp_categories ) ) {
				// Build parent→children tree for depth-first processing.
				$by_parent = [];
				$term_map  = [];
				foreach ( $wp_categories as $term ) {
					$by_parent[ $term->parent ][] = $term;
					$term_map[ $term->term_id ]   = $term;
				}

				// wp_term_id → iato_category_id map (built during creation).
				$wp_to_iato_cat = [];

				// Process depth-first: roots (parent=0) first, then their children.
				$queue = $by_parent[0] ?? [];
				while ( ! empty( $queue ) ) {
					$term = array_shift( $queue );

					// Add children to the front of the queue.
					if ( isset( $by_parent[ $term->term_id ] ) ) {
						$queue = array_merge( $by_parent[ $term->term_id ], $queue );
					}

					$label  = $term->name;
					$action = 'skip';
					$iato_cat_id = $existing_categories[ $label ] ?? null;

					if ( ! $iato_cat_id ) {
						$action = 'create';
						$parent_iato_id = null;
						if ( $term->parent && isset( $wp_to_iato_cat[ $term->parent ] ) ) {
							$parent_iato_id = $wp_to_iato_cat[ $term->parent ];
						}

						if ( ! $dry_run ) {
							$result = IATO_MCP_IATO_Client::create_category( $sitemap_id, $label, $parent_iato_id );
							if ( ! is_wp_error( $result ) ) {
								$iato_cat_id = $result['id'] ?? $result['category_id'] ?? '';
								$wp_to_iato_cat[ $term->term_id ] = $iato_cat_id;
							}
						} else {
							// In dry_run, simulate an ID for child lookups.
							$wp_to_iato_cat[ $term->term_id ] = 'dry_run_' . $term->term_id;
						}
						++$cat_created;
					} else {
						$wp_to_iato_cat[ $term->term_id ] = $iato_cat_id;
						++$cat_skipped;
					}

					// Assign category to posts.
					$assigned_count = 0;
					if ( $iato_cat_id && ! $dry_run ) {
						$cat_posts = get_posts( [
							'category'    => $term->term_id,
							'numberposts' => 500,
							'post_status' => 'publish',
						] );

						$node_ids = [];
						foreach ( $cat_posts as $cp ) {
							$cp_url  = untrailingslashit( get_permalink( $cp ) );
							$node_id = $node_url_map[ $cp_url ] ?? null;
							if ( $node_id ) {
								$node_ids[] = $node_id;
							}
						}

						if ( ! empty( $node_ids ) ) {
							IATO_MCP_IATO_Client::assign_category( $sitemap_id, $node_ids, (string) $iato_cat_id );
							$assigned_count = count( $node_ids );
							$cat_assigned  += $assigned_count;
						}
					}

					$details[] = [
						'type'              => 'category',
						'name'              => $label,
						'wp_term_id'        => $term->term_id,
						'wp_parent_id'      => $term->parent ?: null,
						'iato_id'           => $iato_cat_id,
						'action'            => $action,
						'assigned_to_nodes' => $assigned_count,
					];
				}
			}
		}

		// ── Tags (flat) ──────────────────────────────────────────────────────

		if ( in_array( 'post_tag', $taxonomies, true ) ) {
			$wp_tags = get_terms( [
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
			] );

			if ( ! is_wp_error( $wp_tags ) && is_array( $wp_tags ) ) {
				$wp_to_iato_tag = [];

				foreach ( $wp_tags as $term ) {
					$label       = $term->name;
					$action      = 'skip';
					$iato_tag_id = $existing_tags[ $label ] ?? null;

					if ( ! $iato_tag_id ) {
						$action = 'create';
						if ( ! $dry_run ) {
							$result = IATO_MCP_IATO_Client::create_tag( $sitemap_id, $label );
							if ( ! is_wp_error( $result ) ) {
								$iato_tag_id = $result['id'] ?? $result['tag_id'] ?? '';
								$wp_to_iato_tag[ $term->term_id ] = $iato_tag_id;
							}
						}
						++$tag_created;
					} else {
						$wp_to_iato_tag[ $term->term_id ] = $iato_tag_id;
						++$tag_skipped;
					}

					// Assign tag to posts.
					$assigned_count = 0;
					if ( $iato_tag_id && ! $dry_run ) {
						$tag_posts = get_posts( [
							'tag_id'      => $term->term_id,
							'numberposts' => 500,
							'post_status' => 'publish',
						] );

						$node_ids = [];
						foreach ( $tag_posts as $tp ) {
							$tp_url  = untrailingslashit( get_permalink( $tp ) );
							$node_id = $node_url_map[ $tp_url ] ?? null;
							if ( $node_id ) {
								$node_ids[] = $node_id;
							}
						}

						if ( ! empty( $node_ids ) && $iato_tag_id ) {
							IATO_MCP_IATO_Client::assign_tags( $sitemap_id, $node_ids, [ (string) $iato_tag_id ] );
							$assigned_count = count( $node_ids );
							$tag_assigned  += $assigned_count;
						}
					}

					$details[] = [
						'type'              => 'tag',
						'name'              => $label,
						'wp_term_id'        => $term->term_id,
						'wp_parent_id'      => null,
						'iato_id'           => $iato_tag_id,
						'action'            => $action,
						'assigned_to_nodes' => $assigned_count,
					];
				}
			}
		}

		return IATO_MCP_Server::ok( [
			'sitemap_id' => $sitemap_id,
			'dry_run'    => $dry_run,
			'categories' => [
				'created'  => $cat_created,
				'skipped'  => $cat_skipped,
				'assigned' => $cat_assigned,
			],
			'tags' => [
				'created'  => $tag_created,
				'skipped'  => $tag_skipped,
				'assigned' => $tag_assigned,
			],
			'details' => $details,
		] );
	}
);

// ═════════════════════════════════════════════════════════════════════════════
// Tool 3: sync_wp_meta_to_iato
// ═════════════════════════════════════════════════════════════════════════════

IATO_MCP_Server::register_tool(
	'sync_wp_meta_to_iato',
	[
		'description' => 'Reads SEO titles and meta descriptions from WordPress (via the active SEO plugin — Yoast, RankMath, or SEOPress) and pushes them to IATO using fix_seo_issue. Keeps IATO in sync with your on-site SEO metadata.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'sitemap_id' => [ 'type' => 'integer', 'description' => 'IATO sitemap ID (required)' ],
				'crawl_id'   => [ 'type' => 'string',  'description' => 'IATO crawl ID (required)' ],
				'post_type'  => [ 'type' => 'string',  'description' => 'Comma-separated WP post types (default: post,page)' ],
				'limit'      => [ 'type' => 'integer', 'description' => 'Max posts to process (default: 100)' ],
				'dry_run'    => [ 'type' => 'boolean', 'description' => 'Preview without pushing to IATO (default: false)' ],
			],
			'required' => [ 'sitemap_id', 'crawl_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$sitemap_id = absint( $args['sitemap_id'] ?? 0 );
		$crawl_id   = sanitize_text_field( $args['crawl_id'] ?? '' );
		$post_types = array_map( 'trim', explode( ',', sanitize_text_field( $args['post_type'] ?? 'post,page' ) ) );
		$limit      = absint( $args['limit'] ?? 100 );
		$dry_run    = (bool) ( $args['dry_run'] ?? false );

		if ( ! $sitemap_id || ! $crawl_id ) {
			return new WP_Error( 'missing_params', 'sitemap_id and crawl_id are required.' );
		}

		// Fetch IATO pages for URL → page_id mapping.
		$pages_response = IATO_MCP_IATO_Client::get_pages( $crawl_id, $limit );
		if ( is_wp_error( $pages_response ) ) {
			return $pages_response;
		}
		$page_url_map = iato_mcp_build_page_url_map( $pages_response );

		// Query WordPress posts.
		$posts = get_posts( [
			'post_type'   => $post_types,
			'post_status' => 'publish',
			'numberposts' => $limit,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		$synced     = 0;
		$skipped    = 0;
		$seo_plugin = null;
		$pages      = [];

		foreach ( $posts as $post ) {
			$meta = IATO_MCP_SEO_Adapter::get_meta( $post->ID );
			$url  = untrailingslashit( get_permalink( $post ) );

			if ( null === $seo_plugin ) {
				$seo_plugin = $meta['plugin'] ?? 'fallback';
			}

			$title       = $meta['title'] ?? '';
			$description = $meta['description'] ?? '';
			$page_id     = $page_url_map[ $url ] ?? null;

			// Skip if no IATO page match or no SEO data to push.
			if ( ! $page_id || ( '' === $title && '' === $description ) ) {
				++$skipped;
				$pages[] = [
					'url'          => $url,
					'wp_post_id'   => $post->ID,
					'iato_page_id' => $page_id,
					'title'        => $title,
					'description'  => $description,
					'seo_plugin'   => $meta['plugin'] ?? 'fallback',
					'action'       => 'skip',
				];
				continue;
			}

			if ( ! $dry_run ) {
				if ( '' !== $title ) {
					$result = IATO_MCP_IATO_Client::fix_seo_issue( $crawl_id, $page_id, 'title', $title );
					if ( is_wp_error( $result ) ) {
						$pages[] = [
							'url'          => $url,
							'wp_post_id'   => $post->ID,
							'iato_page_id' => $page_id,
							'title'        => $title,
							'description'  => $description,
							'seo_plugin'   => $meta['plugin'] ?? 'fallback',
							'action'       => 'error',
							'error'        => $result->get_error_message(),
						];
						continue;
					}
				}

				if ( '' !== $description ) {
					$result = IATO_MCP_IATO_Client::fix_seo_issue( $crawl_id, $page_id, 'meta_description', $description );
					if ( is_wp_error( $result ) ) {
						$pages[] = [
							'url'          => $url,
							'wp_post_id'   => $post->ID,
							'iato_page_id' => $page_id,
							'title'        => $title,
							'description'  => $description,
							'seo_plugin'   => $meta['plugin'] ?? 'fallback',
							'action'       => 'error',
							'error'        => $result->get_error_message(),
						];
						continue;
					}
				}
			}

			++$synced;
			$pages[] = [
				'url'          => $url,
				'wp_post_id'   => $post->ID,
				'iato_page_id' => $page_id,
				'title'        => $title,
				'description'  => $description,
				'seo_plugin'   => $meta['plugin'] ?? 'fallback',
				'action'       => $dry_run ? 'would_sync' : 'synced',
			];
		}

		return IATO_MCP_Server::ok( [
			'crawl_id'    => $crawl_id,
			'dry_run'     => $dry_run,
			'seo_plugin'  => $seo_plugin ?? 'none',
			'total_posts' => count( $posts ),
			'synced'      => $synced,
			'skipped'     => $skipped,
			'pages'       => $pages,
		] );
	}
);

// ── 4. sync_wp_menus_to_iato ─────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'sync_wp_menus_to_iato',
	[
		'description' => 'Syncs WordPress navigation menus and their items to IATO. Creates menus that don\'t exist yet in IATO (matched by name) and populates them with menu items preserving parent-child hierarchy and ordering.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'sitemap_id' => [
					'type'        => 'integer',
					'description' => 'IATO sitemap ID to sync menus into.',
				],
				'crawl_id'   => [
					'type'        => 'string',
					'description' => 'IATO crawl ID (required for consistency with other sync tools).',
				],
				'dry_run'    => [
					'type'        => 'boolean',
					'description' => 'If true, return what would be synced without making changes.',
					'default'     => false,
				],
			],
			'required'   => [ 'sitemap_id', 'crawl_id' ],
		],
	],
	function ( array $args ): array|\WP_Error {

		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$sitemap_id = absint( $args['sitemap_id'] ?? 0 );
		$crawl_id   = sanitize_text_field( $args['crawl_id'] ?? '' );
		$dry_run    = ! empty( $args['dry_run'] );

		if ( ! $sitemap_id || empty( $crawl_id ) ) {
			return new \WP_Error( 'missing_params', 'sitemap_id and crawl_id are required.' );
		}

		// Fetch existing IATO menus for deduplication.
		$iato_menus = IATO_MCP_IATO_Client::get_menus( $sitemap_id );
		if ( is_wp_error( $iato_menus ) ) {
			return $iato_menus;
		}

		$existing_menus = [];
		foreach ( $iato_menus as $im ) {
			$name = $im['name'] ?? '';
			if ( $name !== '' ) {
				$existing_menus[ $name ] = $im['id'] ?? $im['menu_id'] ?? 0;
			}
		}

		// Fetch all WP nav menus.
		$wp_menus = wp_get_nav_menus();
		if ( empty( $wp_menus ) ) {
			return IATO_MCP_Server::ok( [
				'sitemap_id'    => $sitemap_id,
				'dry_run'       => $dry_run,
				'menus_created' => 0,
				'menus_skipped' => 0,
				'items_created' => 0,
				'items_skipped' => 0,
				'message'       => 'No WordPress navigation menus found.',
				'details'       => [],
			] );
		}

		$menus_created = 0;
		$menus_skipped = 0;
		$items_created = 0;
		$items_skipped = 0;
		$details       = [];

		foreach ( $wp_menus as $wp_menu ) {
			$menu_name   = $wp_menu->name;
			$menu_detail = [
				'wp_menu_id'   => $wp_menu->term_id,
				'name'         => $menu_name,
				'action'       => 'skip',
				'iato_menu_id' => null,
				'items'        => [],
			];

			// Create or skip the menu itself.
			if ( isset( $existing_menus[ $menu_name ] ) ) {
				$iato_menu_id = $existing_menus[ $menu_name ];
				$menu_detail['action']       = 'skipped';
				$menu_detail['iato_menu_id'] = $iato_menu_id;
				$menus_skipped++;
			} else {
				if ( $dry_run ) {
					$iato_menu_id = 0;
					$menu_detail['action'] = 'would_create';
				} else {
					$result = IATO_MCP_IATO_Client::create_menu( $sitemap_id, $menu_name );
					if ( is_wp_error( $result ) ) {
						$menu_detail['action'] = 'error';
						$menu_detail['error']  = $result->get_error_message();
						$details[] = $menu_detail;
						continue;
					}
					$iato_menu_id = $result['id'] ?? $result['menu_id'] ?? 0;
					$menu_detail['action']       = 'created';
					$menu_detail['iato_menu_id'] = $iato_menu_id;
				}
				$menus_created++;
			}

			// Fetch WP menu items.
			$wp_items = wp_get_nav_menu_items( $wp_menu->term_id );
			if ( empty( $wp_items ) ) {
				$details[] = $menu_detail;
				continue;
			}

			// Build existing item keys for deduplication (only if menu already existed).
			$existing_item_keys = [];
			if ( isset( $existing_menus[ $menu_name ] ) && $iato_menu_id ) {
				$iato_items = IATO_MCP_IATO_Client::get_menu_items( $sitemap_id, $iato_menu_id );
				if ( ! is_wp_error( $iato_items ) ) {
					foreach ( $iato_items as $ii ) {
						$key = ( $ii['label'] ?? '' ) . '|' . ( $ii['url'] ?? '' );
						$existing_item_keys[ $key ] = true;
					}
				}
			}

			// Group WP items by parent for BFS traversal.
			$by_parent = [];
			foreach ( $wp_items as $item ) {
				$parent = (int) $item->menu_item_parent;
				$by_parent[ $parent ][] = $item;
			}

			// BFS: process roots first, then children — preserves hierarchy.
			$wp_to_iato_item = [];
			$queue           = $by_parent[0] ?? [];

			while ( ! empty( $queue ) ) {
				$item = array_shift( $queue );

				// Enqueue children so they're processed after parent.
				if ( isset( $by_parent[ $item->ID ] ) ) {
					foreach ( $by_parent[ $item->ID ] as $child ) {
						$queue[] = $child;
					}
				}

				$label = $item->title;
				$url   = $item->url;
				$key   = $label . '|' . $url;

				$item_detail = [
					'wp_item_id' => $item->ID,
					'label'      => $label,
					'url'        => $url,
					'position'   => $item->menu_order,
					'parent'     => (int) $item->menu_item_parent ?: null,
					'action'     => 'skip',
				];

				if ( isset( $existing_item_keys[ $key ] ) ) {
					$item_detail['action'] = 'skipped';
					$items_skipped++;
				} else {
					$iato_parent_id = $wp_to_iato_item[ (int) $item->menu_item_parent ] ?? null;

					if ( $dry_run ) {
						$item_detail['action'] = 'would_create';
						$wp_to_iato_item[ $item->ID ] = 'dry_run_' . $item->ID;
					} else {
						$result = IATO_MCP_IATO_Client::create_menu_item( $sitemap_id, $iato_menu_id, [
							'label'          => $label,
							'url'            => $url,
							'parent_item_id' => $iato_parent_id,
							'position'       => $item->menu_order,
						] );

						if ( is_wp_error( $result ) ) {
							$item_detail['action'] = 'error';
							$item_detail['error']  = $result->get_error_message();
						} else {
							$item_detail['action']       = 'created';
							$item_detail['iato_item_id'] = $result['id'] ?? $result['item_id'] ?? 0;
							$wp_to_iato_item[ $item->ID ] = $item_detail['iato_item_id'];
						}
					}
					$items_created++;
				}

				$menu_detail['items'][] = $item_detail;
			}

			$details[] = $menu_detail;
		}

		return IATO_MCP_Server::ok( [
			'sitemap_id'    => $sitemap_id,
			'dry_run'       => $dry_run,
			'menus_created' => $menus_created,
			'menus_skipped' => $menus_skipped,
			'items_created' => $items_created,
			'items_skipped' => $items_skipped,
			'details'       => $details,
		] );
	}
);
