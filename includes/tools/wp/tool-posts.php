<?php
/**
 * WP Tools: get_posts, get_post, create_post, update_post, search_posts
 *
 * All write tools require edit_posts capability.
 * get_posts / get_post / search_posts require read only.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

const IATO_MCP_POST_DATE_HELP     = 'Publish date in the site\'s local time, such as 2026-10-27T09:00:00, with no offset. date_gmt is worked out from it when omitted.';
const IATO_MCP_POST_DATE_GMT_HELP = 'The same date in UTC, such as 2026-10-27T14:00:00. Send this or date; both are refused if they are different moments.';
const IATO_MCP_POST_AUTHOR_HELP   = 'A user ID, who must be able to edit posts of this type. Giving a post to someone else needs edit_others_posts.';

// ── get_posts ─────────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_posts',
	[
		'description' => 'List posts or pages with optional filters. Returns ID, title, slug, status, URL, date (the site\'s local time), date_gmt (UTC, null for a draft with no date of its own), and modified (UTC). Scope note: post_type accepts post|page|any and `any` expands to [post, page] only — Elementor Theme Builder templates (post_type=elementor_library) are NOT returned by this tool in v1.8.0; a dedicated templates listing tool lands in Layer 2 of the discovery work. If you\'re looking for what renders a specific URL, use resolve_url instead.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_type'   => [ 'type' => 'string',  'description' => 'post|page|any (default: post)' ],
				'status'      => [ 'type' => 'string',  'description' => 'publish|draft|any (default: publish)' ],
				'per_page'    => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default: 20)' ],
				'page'        => [ 'type' => 'integer', 'description' => 'Page number (default: 1)' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_type = sanitize_text_field( $args['post_type'] ?? 'post' );
		$status    = sanitize_text_field( $args['status']    ?? 'publish' );
		$per_page  = min( absint( $args['per_page'] ?? 20 ), 100 );
		$page      = max( absint( $args['page']     ?? 1 ), 1 );

		$query_args = [
			'post_type'      => 'any' === $post_type ? [ 'post', 'page' ] : $post_type,
			'post_status'    => 'any' === $status ? [ 'publish', 'draft', 'pending', 'private' ] : $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];

		$query  = new WP_Query( $query_args );
		$posts  = [];
		$hidden = 0;

		foreach ( $query->posts as $post ) {
			// Other users' drafts, private posts and so on are listed only for
			// callers who could read them (the site key reads everything).
			if ( is_wp_error( IATO_MCP_Auth::require_read_post( $post->ID ) ) ) {
				$hidden++;
				continue;
			}
			$posts[] = [
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'slug'     => $post->post_name,
				'status'   => $post->post_status,
				'url'      => get_permalink( $post ),
				'date'     => $post->post_date,
				'date_gmt' => IATO_MCP_Post_Date::gmt_or_null( $post->post_date_gmt ),
				'modified' => $post->post_modified_gmt,
			];
		}

		$result = [
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		];
		if ( $hidden > 0 ) {
			$result['hidden'] = $hidden; // matched the query but not readable by this caller
		}

		return IATO_MCP_Server::ok( $result );
	}
);

// ── get_post ──────────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_post',
	[
		'description' => 'Get full details for a single post or page by ID or slug. date is the site\'s local time, date_gmt is UTC (null for a draft with no date of its own), and modified is UTC. Pass include_shadowing=true to detect when an Elementor Theme Builder template overrides the slug-based render (small extra cost; default off to keep the hot path fast).',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'                => [ 'type' => 'integer', 'description' => 'Post ID' ],
				'slug'              => [ 'type' => 'string',  'description' => 'Post slug (used if id not provided)' ],
				'include_shadowing' => [ 'type' => 'boolean', 'description' => 'If true, attach is_shadowed_by when an Elementor Theme Builder template overrides this post (default: false).' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$post = null;

		if ( ! empty( $args['id'] ) ) {
			$post = get_post( absint( $args['id'] ) );
		} elseif ( ! empty( $args['slug'] ) ) {
			$slug  = sanitize_text_field( $args['slug'] );
			$found = get_posts( [
				'name'           => $slug,
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'any',
				'posts_per_page' => 1,
			] );
			$post = $found[0] ?? null;
		}

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		// Any post type is accepted here; read_post (plus the password and
		// template rules) decides whether this caller may see it.
		$read_check = IATO_MCP_Auth::require_read_post( $post->ID );
		if ( is_wp_error( $read_check ) ) {
			return $read_check;
		}

		$categories = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
		$tags       = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );

		$response = [
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'slug'       => $post->post_name,
			'content'    => $post->post_content,
			'excerpt'    => $post->post_excerpt,
			'status'     => $post->post_status,
			'url'        => get_permalink( $post ),
			'author'     => (int) $post->post_author,
			'date'       => $post->post_date,
			'date_gmt'   => IATO_MCP_Post_Date::gmt_or_null( $post->post_date_gmt ),
			'modified'   => $post->post_modified_gmt,
			'categories' => is_array( $categories ) ? $categories : [],
			'tags'       => is_array( $tags ) ? $tags : [],
		];

		if ( ! empty( $args['include_shadowing'] ) && class_exists( 'IATO_MCP_Elementor_Router' ) ) {
			$shadowing = IATO_MCP_Elementor_Router::get_shadowing_for_post( $post->ID );
			if ( null !== $shadowing ) {
				$response['is_shadowed_by'] = $shadowing;
			}
		}

		return IATO_MCP_Server::ok( $response );
	}
);

// ── create_post ───────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'create_post',
	[
		'description' => 'Create a new post or page. Returns the new post ID and URL. On page-builder sites (Elementor, Divi, etc.), follow the new-post workflow in server instructions BEFORE calling — plain HTML in post_content will not match the site\'s existing post format.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'title'     => [ 'type' => 'string', 'description' => 'Post title (required)' ],
				'content'   => [ 'type' => 'string', 'description' => 'Post content (HTML or plain text)' ],
				'status'    => [ 'type' => 'string', 'description' => 'draft|publish (default: draft)' ],
				'post_type' => [ 'type' => 'string', 'description' => 'post|page (default: post)' ],
				'date'      => [ 'type' => 'string', 'description' => IATO_MCP_POST_DATE_HELP ],
				'date_gmt'  => [ 'type' => 'string', 'description' => IATO_MCP_POST_DATE_GMT_HELP ],
				'allow_future' => [ 'type' => 'boolean', 'description' => 'status publish with a future date schedules the post. Refused (future_date_on_published) unless this is true. Default: false.' ],
				'author'    => [ 'type' => 'integer', 'description' => IATO_MCP_POST_AUTHOR_HELP ],
				'dry_run'   => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'title' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$post_type = sanitize_text_field( $args['post_type'] ?? 'post' );
		if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
			return new WP_Error( 'invalid_post_type', 'post_type must be post or page.' );
		}

		$status = $args['status'] ?? 'draft';
		if ( ! in_array( $status, [ 'draft', 'publish' ], true ) ) {
			$status = 'draft';
		}

		// Object-level: the type's create capability (edit_posts for posts,
		// edit_pages for pages), plus its publish capability when the post goes
		// live, exactly as core's REST posts controller checks them.
		$create_check = IATO_MCP_Auth::require_cap( IATO_MCP_Auth::post_type_cap( $post_type, 'create_posts' ) );
		if ( is_wp_error( $create_check ) ) return $create_check;
		if ( 'publish' === $status ) {
			$publish_check = IATO_MCP_Auth::require_publish_cap( $post_type );
			if ( is_wp_error( $publish_check ) ) return $publish_check;
		}

		$date = IATO_MCP_Post_Date::from_args( $args );
		if ( is_wp_error( $date ) ) {
			return IATO_MCP_Server::refusal( $date );
		}
		$status_then = null === $date ? $status : IATO_MCP_Post_Date::resulting_status( $status, $date['gmt'], $post_type );
		if ( 'future' === $status_then && empty( $args['allow_future'] ) ) {
			return IATO_MCP_Server::refusal( IATO_MCP_Post_Date::future_refusal( $date['local'], $date['gmt'] ) );
		}
		$author = isset( $args['author'] ) ? iato_mcp_check_post_author( $args['author'], $post_type, (int) get_current_user_id() ) : null;
		if ( is_wp_error( $author ) ) {
			return IATO_MCP_Server::refusal( $author );
		}

		$postarr = [
			'post_title'   => sanitize_text_field( $args['title'] ),
			'post_content' => IATO_MCP_Elementor_Sanitizer::clean_markup( (string) ( $args['content'] ?? '' ) ),
			'post_status'  => $status,
			'post_type'    => $post_type,
		];
		if ( null !== $date ) {
			$postarr['post_date']     = $date['local'];
			$postarr['post_date_gmt'] = $date['gmt'];
		}
		if ( null !== $author ) {
			$postarr['post_author'] = $author;
		}

		if ( ! empty( $args['dry_run'] ) ) {
			if ( '' === $postarr['post_title'] && '' === $postarr['post_content'] ) {
				return new WP_Error( 'empty_content', 'Content, title, and excerpt are empty.' );
			}
			$data = [
				'dry_run'     => true,
				'action'      => 'would_create',
				'post_type'   => $post_type,
				'status'      => $status_then,
				'title'       => $postarr['post_title'],
				'content'     => $postarr['post_content'],
				'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED,
			];
			if ( null !== $date ) {
				$data['date']     = $date['local'];
				$data['date_gmt'] = $date['gmt'];
			}
			if ( null !== $author ) {
				$data['author'] = $author;
			}
			IATO_MCP_Sanitize_Report::add_to( $data, iato_mcp_post_sanitize_report( $args, (object) $postarr ) );
			$notice = iato_mcp_page_builder_notice( 'create' );
			if ( null !== $notice ) {
				$data['notice'] = $notice;
			}
			return IATO_MCP_Server::ok( $data );
		}

		// Slashed: wp_insert_post() unslashes, which would strip every backslash.
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// The title as WordPress stored it (kses on save normalises entities),
		// which is what the rollback conflict check reads back.
		$created = get_post( $post_id );
		$receipt = IATO_MCP_Change_Receipt::record(
			$post_id,
			'post',
			'create',
			null,
			[
				'post_type' => $post_type,
				'title'     => $created ? $created->post_title : $postarr['post_title'],
			]
		);

		$data = [
			'id'  => $post_id,
			'url' => get_permalink( $post_id ),
		];
		if ( $created ) {
			$data += iato_mcp_post_date_fields( $created );
			$note  = 'future' === $created->post_status ? IATO_MCP_Post_Date::status_note( 'publish', 'future', $created->post_date ) : null;
			if ( null !== $note ) {
				$data['status_note'] = $note;
			}
		}
		IATO_MCP_Change_Receipt::append( $data, $receipt );
		IATO_MCP_Sanitize_Report::add_to( $data, iato_mcp_post_sanitize_report( $args, $created ) );

		$notice = iato_mcp_page_builder_notice( 'create' );
		if ( null !== $notice ) {
			$data['notice'] = $notice;
		}

		return IATO_MCP_Server::ok( $data );
	}
);

/**
 * What sanitising removed from a post's fields: what was sent against what is
 * stored, read back, because WordPress' content filters run on save as well.
 *
 * @param array        $args The tool arguments.
 * @param WP_Post|null $post The post as stored.
 * @return array Report entries.
 */
function iato_mcp_post_sanitize_report( array $args, $post ): array {
	if ( ! $post ) {
		return [];
	}
	$report = [];
	foreach ( [ 'title' => [ 'post_title', 'text' ], 'content' => [ 'post_content', 'html' ], 'excerpt' => [ 'post_excerpt', 'text' ] ] as $arg => [ $field, $rule ] ) {
		if ( isset( $args[ $arg ] ) ) {
			$entry = IATO_MCP_Sanitize_Report::for_value( $arg, $rule, $args[ $arg ], (string) ( $post->{$field} ?? '' ) );
			if ( null !== $entry ) {
				$report[] = $entry;
			}
		}
	}
	return $report;
}

/**
 * A new author for a post: a user who exists and can edit posts of this type. Giving
 * a post to someone else needs edit_others_posts, as in wp-admin, or an Author could
 * put an administrator's name on their own post. Checked before the user is looked
 * up, so the answer does not tell anyone without it which IDs exist.
 *
 * @param mixed  $raw       The author sent.
 * @param string $post_type The post's type.
 * @param int    $current   Who the author is now, or who would be by default.
 * @return int|WP_Error
 */
function iato_mcp_check_post_author( mixed $raw, string $post_type, int $current ): int|WP_Error {
	$author = is_numeric( $raw ) && (string) (int) $raw === trim( (string) $raw ) ? (int) $raw : 0;
	if ( $author <= 0 ) {
		return new WP_Error( 'invalid_author', 'author must be a user ID. Nothing was written.' );
	}
	if ( $author === $current ) {
		return $author;
	}
	$may_reassign = IATO_MCP_Auth::require_cap( IATO_MCP_Auth::post_type_cap( $post_type, 'edit_others_posts' ) );
	if ( is_wp_error( $may_reassign ) ) {
		return $may_reassign;
	}
	if ( ! get_userdata( $author ) ) {
		return new WP_Error( 'invalid_author', sprintf( 'There is no user %d. Nothing was written.', $author ) );
	}
	$can_edit = IATO_MCP_Auth::post_type_cap( $post_type, 'edit_posts' );
	if ( ! user_can( $author, $can_edit ) ) {
		return new WP_Error( 'invalid_author', sprintf( 'User %1$d cannot edit this type of post (%2$s). Nothing was written.', $author, $can_edit ) );
	}
	return $author;
}

/** The date, status and author as a response gives them. */
function iato_mcp_post_date_fields( object $post ): array {
	return [
		'status'   => $post->post_status,
		'date'     => $post->post_date,
		'date_gmt' => IATO_MCP_Post_Date::gmt_or_null( $post->post_date_gmt ),
		'author'   => (int) $post->post_author,
	];
}

// ── update_post ───────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_post',
	[
		'description' => 'Update an existing post title, content, slug, excerpt, status, publish date, or author. On page-builder sites (Elementor, Divi, etc.), content edits go through the builder tools — see server instructions.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'                 => [ 'type' => 'integer', 'description' => 'Post ID to update (required)' ],
				'title'              => [ 'type' => 'string',  'description' => 'New title' ],
				'content'            => [ 'type' => 'string',  'description' => 'New content' ],
				'slug'               => [ 'type' => 'string',  'description' => 'New post slug (lowercase a-z 0-9 and hyphens, no leading/trailing/double hyphens, max 200 chars). Returns slug_conflict error if taken; non-draft posts also require confirm_url_break=true.' ],
				'excerpt'            => [ 'type' => 'string',  'description' => 'New excerpt (manual summary shown on archive/listing pages)' ],
				'status'             => [ 'type' => 'string',  'description' => 'New status: draft|publish' ],
				'confirm_url_break'  => [ 'type' => 'boolean', 'description' => 'Required true when changing the slug of a non-draft post; acknowledges the URL break.' ],
				'date'               => [ 'type' => 'string',  'description' => IATO_MCP_POST_DATE_HELP ],
				'date_gmt'           => [ 'type' => 'string',  'description' => IATO_MCP_POST_DATE_GMT_HELP ],
				'allow_future'       => [ 'type' => 'boolean', 'description' => 'A published post given a future date is scheduled: its status becomes future and it leaves the site until then. Refused (future_date_on_published) unless this is true. A draft keeps its status and uses the date when published. Default: false.' ],
				'author'             => [ 'type' => 'integer', 'description' => IATO_MCP_POST_AUTHOR_HELP ],
				'dry_run'            => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		// Accept both schema name (id) and Autopilot name (post_id).
		$post_id = absint( $args['id'] ?? $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}

		$postarr = [ 'ID' => $post_id ];
		$before  = [];

		if ( isset( $args['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $args['title'] );
			$before['title']       = $post->post_title;
		}
		if ( isset( $args['content'] ) ) {
			$postarr['post_content'] = IATO_MCP_Elementor_Sanitizer::clean_markup( (string) $args['content'] );
			$before['content']       = $post->post_content;
		}
		if ( isset( $args['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $args['excerpt'] );
			$before['excerpt']       = $post->post_excerpt;
		}
		if ( isset( $args['status'] ) && in_array( $args['status'], [ 'draft', 'publish' ], true ) ) {
			// Going live needs the post type's publish capability (publish_posts /
			// publish_pages) on top of edit_post: a Contributor may edit their own
			// draft but not publish it.
			if ( 'publish' === $args['status'] ) {
				$publish_check = IATO_MCP_Auth::require_publish_cap( $post->post_type );
				if ( is_wp_error( $publish_check ) ) return $publish_check;
				if ( 'elementor_library' === $post->post_type && 'publish' !== $post->post_status ) {
					$empty = IATO_MCP_Theme_Builder::empty_publish_refusal( $post_id );
					if ( null !== $empty ) {
						return $empty;
					}
				}
			}
			$postarr['post_status'] = $args['status'];
			$before['status']       = $post->post_status;
		}
		if ( isset( $args['slug'] ) ) {
			$slug_or_error = iato_mcp_validate_post_slug( $args['slug'], $post_id );
			if ( is_wp_error( $slug_or_error ) ) {
				return $slug_or_error;
			}
			// Guard non-draft posts: changing a live slug breaks inbound URLs.
			if ( 'draft' !== $post->post_status && $slug_or_error !== $post->post_name && empty( $args['confirm_url_break'] ) ) {
				return new WP_Error(
					'slug_change_requires_confirmation',
					'Changing the slug of a non-draft post breaks its current URL. Pass confirm_url_break=true to proceed.',
					[
						'current_url'  => get_permalink( $post_id ),
						'current_slug' => $post->post_name,
						'post_status'  => $post->post_status,
					]
				);
			}
			$postarr['post_name'] = $slug_or_error;
			$before['slug']       = $post->post_name;
		}

		$date = IATO_MCP_Post_Date::from_args( $args );
		if ( is_wp_error( $date ) ) {
			return IATO_MCP_Server::refusal( $date );
		}
		$date_changes = null !== $date && ( $date['local'] !== $post->post_date || $date['gmt'] !== $post->post_date_gmt );

		// What the call asks for, and what WordPress will make of that with this date.
		$status_asked = (string) ( $postarr['post_status'] ?? $post->post_status );
		$status_then  = $status_asked;
		if ( $date_changes || isset( $postarr['post_status'] ) ) {
			$status_then = IATO_MCP_Post_Date::resulting_status( $status_asked, $date_changes ? $date['gmt'] : $post->post_date_gmt, $post->post_type );
		}
		if ( 'publish' === $status_asked && 'future' === $status_then && empty( $args['allow_future'] ) ) {
			return IATO_MCP_Server::refusal(
				IATO_MCP_Post_Date::future_refusal( $date_changes ? $date['local'] : $post->post_date, $date_changes ? $date['gmt'] : $post->post_date_gmt )
			);
		}
		// Going on or off the site because of a date is a publishing decision too.
		if ( $status_then !== $post->post_status && in_array( $status_then, [ 'publish', 'future' ], true ) ) {
			$publish_check = IATO_MCP_Auth::require_publish_cap( $post->post_type );
			if ( is_wp_error( $publish_check ) ) {
				return $publish_check;
			}
		}
		if ( 'publish' === $status_then && 'future' === $post->post_status && 'elementor_library' === $post->post_type ) {
			$empty = IATO_MCP_Theme_Builder::empty_publish_refusal( $post_id );
			if ( null !== $empty ) {
				return $empty;
			}
		}

		$date_before = null;
		if ( $date_changes ) {
			$postarr['post_date']     = $date['local'];
			$postarr['post_date_gmt'] = $date['gmt'];
			$postarr['edit_date']     = true; // Without it, WordPress ignores a draft's new date.
			$date_before              = IATO_MCP_Post_Date::snapshot( $post );
			unset( $before['status'] ); // The date's receipt holds the status: one rollback restores both.
		}

		$author_before = null;
		if ( isset( $args['author'] ) ) {
			$author = iato_mcp_check_post_author( $args['author'], $post->post_type, (int) $post->post_author );
			if ( is_wp_error( $author ) ) {
				return IATO_MCP_Server::refusal( $author );
			}
			if ( $author !== (int) $post->post_author ) {
				$postarr['post_author'] = $author;
				$author_before          = (int) $post->post_author;
			}
		}

		$field_to_postkey = [
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
			'slug'    => 'post_name',
		];

		if ( ! empty( $args['dry_run'] ) ) {
			$would = [];
			foreach ( $before as $field => $before_value ) {
				$after_value = $postarr[ $field_to_postkey[ $field ] ];
				if ( $before_value !== $after_value ) {
					$would[ $field ] = [ 'before' => $before_value, 'after' => $after_value ];
				}
			}
			if ( isset( $would['status'] ) ) {
				$would['status']['after'] = $status_then;
			}
			if ( null !== $date_before ) {
				$would['date'] = [ 'before' => $date_before, 'after' => [ 'date' => $date['local'], 'date_gmt' => $date['gmt'], 'status' => $status_then ] ];
			}
			if ( null !== $author_before ) {
				$would['author'] = [ 'before' => $author_before, 'after' => (int) $postarr['post_author'] ];
			}
			$data = [
				'dry_run'      => true,
				'action'       => [] === $would ? 'no_change' : 'would_update',
				'id'           => $post_id,
				'would_change' => (object) $would,
				'not_checked'  => IATO_MCP_Server::DRY_RUN_NOT_CHECKED,
			];
			$note = IATO_MCP_Post_Date::status_note( $post->post_status, $status_then, $date_changes ? $date['local'] : $post->post_date );
			if ( null !== $note ) {
				$data['status_note'] = $note;
			}
			IATO_MCP_Sanitize_Report::add_to( $data, iato_mcp_post_sanitize_report( $args, (object) ( $postarr + [ 'post_title' => $post->post_title, 'post_content' => $post->post_content, 'post_excerpt' => $post->post_excerpt ] ) ) );
			if ( isset( $args['content'] ) ) {
				$notice = iato_mcp_page_builder_notice( 'update', $post_id );
				if ( null !== $notice ) {
					$data['notice'] = $notice;
				}
			}
			return IATO_MCP_Server::ok( $data );
		}

		$url_before = null !== $date_before ? get_permalink( $post_id ) : null;

		// Only what this call names: the other columns stay as they are, filters aside.
		$result = IATO_MCP_Auth::update_post_fields( $postarr );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// After values as WordPress stored them: wp_update_post() runs the
		// content filters (kses normalises entities, for one), and the rollback
		// conflict check compares the stored value with the receipt's.
		$fresh    = get_post( $post_id );
		$receipts = [];
		foreach ( $before as $field => $before_value ) {
			$postkey     = $field_to_postkey[ $field ];
			$after_value = $fresh ? $fresh->{$postkey} : $postarr[ $postkey ];
			if ( $before_value === $after_value ) {
				continue;
			}
			$receipts[] = IATO_MCP_Change_Receipt::record( $post_id, 'post', $field, $before_value, $after_value );
		}
		if ( null !== $date_before && $fresh ) {
			$date_after = IATO_MCP_Post_Date::snapshot( $fresh );
			if ( $date_after !== $date_before ) {
				$receipts[] = IATO_MCP_Change_Receipt::record( $post_id, 'post', 'date', $date_before, $date_after );
			}
		}
		if ( null !== $author_before && $fresh && (int) $fresh->post_author !== $author_before ) {
			$receipts[] = IATO_MCP_Change_Receipt::record( $post_id, 'post', 'author', (string) $author_before, (string) (int) $fresh->post_author );
		}

		$data  = [
			'id'       => $post_id,
			'url'      => get_permalink( $post_id ),
			'slug'     => $fresh->post_name,
			'modified' => $fresh->post_modified_gmt,
		];
		if ( null !== $date || isset( $args['author'] ) ) {
			$data += iato_mcp_post_date_fields( $fresh );
		}
		if ( null !== $url_before && $url_before !== $data['url'] ) {
			$data['previous_url'] = $url_before; // A permalink with the date in it. WordPress redirects the old one.
		}
		$note = IATO_MCP_Post_Date::status_note( $post->post_status, $fresh->post_status, $fresh->post_date );
		if ( null !== $note ) {
			$data['status_note'] = $note;
		}

		if ( count( $receipts ) === 1 ) {
			IATO_MCP_Change_Receipt::append( $data, $receipts[0] );
		} elseif ( count( $receipts ) > 1 ) {
			$data['change_receipts'] = $receipts;
		}
		IATO_MCP_Sanitize_Report::add_to( $data, iato_mcp_post_sanitize_report( $args, $fresh ) );

		// Surface a builder-aware notice if content was rewritten on a builder-driven site.
		if ( isset( $args['content'] ) ) {
			$notice = iato_mcp_page_builder_notice( 'update', $post_id );
			if ( null !== $notice ) {
				$data['notice'] = $notice;
			}
		}

		return IATO_MCP_Server::ok( $data );
	}
);

// ── search_posts ──────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'search_posts',
	[
		'description' => 'Full-text search across posts and pages. Returns matching posts with title, slug, and URL.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'query'    => [ 'type' => 'string',  'description' => 'Search query (required)' ],
				'per_page' => [ 'type' => 'integer', 'description' => 'Max results (default: 20)' ],
			],
			'required' => [ 'query' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$search   = sanitize_text_field( $args['query'] );
		$per_page = min( absint( $args['per_page'] ?? 20 ), 100 );

		$query = new WP_Query( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			's'              => $search,
			'posts_per_page' => $per_page,
			'orderby'        => 'relevance',
		] );

		$posts = [];
		foreach ( $query->posts as $post ) {
			$posts[] = [
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'slug'  => $post->post_name,
				'url'   => get_permalink( $post ),
				'type'  => $post->post_type,
			];
		}

		return IATO_MCP_Server::ok( [
			'posts' => $posts,
			'total' => (int) $query->found_posts,
		] );
	}
);
