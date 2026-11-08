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

const IATO_MCP_POST_DATE_HELP     = 'Publish date in the site\'s local time, ISO 8601 with no offset, such as 2026-10-27T09:00:00. date_gmt is worked out from it when omitted.';
const IATO_MCP_POST_DATE_GMT_HELP = 'The publish date in UTC, ISO 8601, such as 2026-10-27T14:00:00Z. Worked out from date when omitted. Send this or date; both together are refused if they are different moments.';
const IATO_MCP_POST_AUTHOR_HELP   = 'The author\'s user ID. The user must be able to edit posts of this type, and giving a post to someone else needs edit_others_posts.';
const IATO_MCP_POST_PARENT_HELP   = 'The parent page\'s ID, or 0 for none: a page of the same type that you can read, and not this page or one under it. Pages and other hierarchical types only; refused for posts (parent_not_supported). The URL becomes the parent\'s URL plus this page\'s slug.';
const IATO_MCP_MENU_ORDER_HELP    = 'The page\'s order among its siblings (page attributes): an integer, lower first. Types with page attributes only, such as pages.';
// The text fields create_post stores: it takes no excerpt, so one sent is not reported as sanitised.
const IATO_MCP_CREATE_POST_TEXT   = [ 'title' => true, 'content' => true ];

// ── get_posts ─────────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_posts',
	[
		'description' => 'List posts or pages with optional filters. Returns ID, title, slug, status, URL, parent (0 for none) and menu_order, date (the site\'s local time), date_gmt (UTC, null for a draft with no date of its own), and modified (UTC). Scope note: post_type accepts post|page|any and `any` expands to [post, page] only — Elementor Theme Builder templates (post_type=elementor_library) are NOT returned by this tool in v1.8.0; a dedicated templates listing tool lands in Layer 2 of the discovery work. If you\'re looking for what renders a specific URL, use resolve_url instead.',
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
				'id'         => $post->ID,
				'title'      => get_the_title( $post ),
				'slug'       => $post->post_name,
				'status'     => $post->post_status,
				'url'        => get_permalink( $post ),
				'parent'     => (int) $post->post_parent,
				'menu_order' => (int) $post->menu_order,
				'date'       => $post->post_date,
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
		'description' => 'Get full details for a single post or page by ID or slug, including parent (0 for none) and menu_order. date is the site\'s local time, date_gmt is UTC (null for a draft with no date of its own), and modified is UTC. Pass include_shadowing=true to detect when an Elementor Theme Builder template overrides the slug-based render (small extra cost; default off to keep the hot path fast).',
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
			'parent'     => (int) $post->post_parent,
			'menu_order' => (int) $post->menu_order,
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
		'description' => 'Create a new post or page, optionally with its publish date (date or date_gmt), author, parent page (parent, for pages: the URL becomes the parent\'s URL plus the new slug) and menu_order. To build nested URLs such as /services/web-development/, create the parent page first. Returns the new post ID and URL. On page-builder sites (Elementor, Divi, etc.), follow the new-post workflow in server instructions BEFORE calling — plain HTML in post_content will not match the site\'s existing post format.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'title'     => [ 'type' => 'string', 'description' => 'Post title (required)' ],
				'content'   => [ 'type' => 'string', 'description' => 'Post content (HTML or plain text)' ],
				'status'    => [ 'type' => 'string', 'description' => 'draft|publish (default: draft)' ],
				'post_type' => [ 'type' => 'string', 'description' => 'post|page (default: post)' ],
				'date'      => [ 'type' => 'string', 'description' => IATO_MCP_POST_DATE_HELP ],
				'date_gmt'  => [ 'type' => 'string', 'description' => IATO_MCP_POST_DATE_GMT_HELP ],
				'allow_future' => [ 'type' => 'boolean', 'description' => 'status publish with a future date is refused with future_date_on_published unless this is true; the post is then scheduled. Default: false.' ],
				'author'    => [ 'type' => 'integer', 'description' => IATO_MCP_POST_AUTHOR_HELP ],
				'parent'    => [ 'type' => 'integer', 'minimum' => 0, 'description' => IATO_MCP_POST_PARENT_HELP ],
				'menu_order' => [ 'type' => 'integer', 'description' => IATO_MCP_MENU_ORDER_HELP ],
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
		// 1.20.0: page attributes. A new post has no URL to move, so no confirmation.
		$warnings = [];
		$parent   = null;
		if ( array_key_exists( 'parent', $args ) ) {
			$checked = IATO_MCP_URL_Move::check_parent( 0, $post_type, $args['parent'] );
			if ( is_wp_error( $checked ) ) {
				return IATO_MCP_Server::refusal( $checked );
			}
			$parent = $checked['parent'];
			if ( null !== $checked['warning'] ) {
				$warnings[] = $checked['warning'];
			}
		}
		$menu_order = null;
		if ( array_key_exists( 'menu_order', $args ) ) {
			$menu_order = IATO_MCP_URL_Move::check_menu_order( $post_type, $args['menu_order'] );
			if ( is_wp_error( $menu_order ) ) {
				return IATO_MCP_Server::refusal( $menu_order );
			}
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
		if ( null !== $parent ) {
			$postarr['post_parent'] = $parent;
		}
		if ( null !== $menu_order ) {
			$postarr['menu_order'] = $menu_order;
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
			if ( null !== $parent ) {
				$data['parent'] = $parent;
			}
			if ( null !== $menu_order ) {
				$data['menu_order'] = $menu_order;
			}
			if ( [] !== $warnings ) {
				$data['warnings'] = $warnings;
			}
			IATO_MCP_Sanitize_Report::add_to( $data, iato_mcp_post_sanitize_report( array_intersect_key( $args, IATO_MCP_CREATE_POST_TEXT ), (object) $postarr ) );
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
			if ( null !== $parent || null !== $menu_order ) {
				$data['parent']     = (int) $created->post_parent;
				$data['menu_order'] = (int) $created->menu_order;
			}
		}
		if ( [] !== $warnings ) {
			$data['warnings'] = $warnings;
		}
		IATO_MCP_Change_Receipt::append( $data, $receipt );
		IATO_MCP_Sanitize_Report::add_to( $data, iato_mcp_post_sanitize_report( array_intersect_key( $args, IATO_MCP_CREATE_POST_TEXT ), $created ) );

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

/**
 * What moving a page to a new parent will do (1.20.0), worked out before anything is
 * written: the URLs that change (its own and every page's under it), whether the call
 * may make that change, and the redirects it would create.
 *
 * @param WP_Post $post       The page, as stored.
 * @param int     $parent_new Its new parent.
 * @param string  $slug       The slug it will have.
 * @param array   $args       The tool arguments.
 * @return array{posts:int[],urls_before:array<int,string>,changes:list<array>,redirect:bool,plan:?array,warnings:string[]}|WP_Error
 */
function iato_mcp_plan_page_move( WP_Post $post, int $parent_new, string $slug, array $args ): array|WP_Error {
	// The slug the page keeps must be free under the new parent, or WordPress adds "-2".
	if ( ! isset( $args['slug'] ) && '' !== $slug ) {
		$clash = IATO_MCP_URL_Move::slug_refusal( $slug, (int) $post->ID, (string) $post->post_type, $parent_new );
		if ( null !== $clash ) {
			return new WP_Error(
				'slug_conflict',
				sprintf( 'This page cannot keep its slug under post %1$d: %2$s Send a different slug with the move. Nothing was written.', $parent_new, $clash->get_error_message() ),
				(array) $clash->get_error_data() + [ 'status' => 409 ]
			);
		}
	}

	$descendants = IATO_MCP_URL_Move::descendants( $post );
	$posts       = array_merge( [ $post ], $descendants );
	$before      = IATO_MCP_URL_Move::urls( $posts );
	$changes     = IATO_MCP_URL_Move::changes( $posts, $before, IATO_MCP_URL_Move::predicted_urls( $post, $descendants, $parent_new, $slug ) );

	if ( IATO_MCP_URL_Move::moves_live_url( $changes ) && empty( $args['confirm_url_break'] ) ) {
		return new WP_Error(
			'slug_change_requires_confirmation',
			sprintf(
				'Moving this page changes %d URL(s), including ones visitors can open now. Pass confirm_url_break=true to proceed. Redirects from the old URLs of published pages are created unless redirect_old_urls is false. Nothing was written.',
				count( $changes )
			),
			[
				'status'           => 400,
				'current_url'      => $before[ (int) $post->ID ] ?? null,
				'url_change_count' => count( $changes ),
			] + iato_mcp_shown_url_changes( $changes )
		);
	}

	// A redirect already on a URL the move makes live would take visitors away from it.
	$host   = (string) parse_url( home_url( '/' ), PHP_URL_HOST );
	$on_new = IATO_MCP_Redirects::rules_on_new_urls( $changes, $host );
	if ( [] !== $on_new['blocking'] ) {
		return IATO_MCP_Redirects::blocking_refusal( $on_new['blocking'] );
	}

	$published = IATO_MCP_URL_Move::moves_published_url( $changes );
	$redirect  = array_key_exists( 'redirect_old_urls', $args ) ? ! empty( $args['redirect_old_urls'] ) : $published;
	$is_admin  = ! is_wp_error( IATO_MCP_Auth::require_cap( 'manage_options' ) );
	if ( $redirect && $published && ! $is_admin ) {
		return new WP_Error(
			'redirects_need_admin',
			'Moving this page moves published URLs, and creating redirects from them needs an administrator (manage_options), as update_redirect does. Ask an administrator, or pass redirect_old_urls=false to move it without redirects: WordPress may still send some old URLs to the new ones itself. Nothing was written.',
			[ 'status' => 403 ]
		);
	}
	if ( [] !== $on_new['stale'] && ! $is_admin ) {
		return new WP_Error(
			'redirects_need_admin',
			'A redirect left by an earlier move would send visitors away from this page\'s new URL, and removing it needs an administrator (manage_options). Ask an administrator to make this move. Nothing was written.',
			[ 'status' => 403, 'redirects_to_remove' => $on_new['stale'] ]
		);
	}

	$ids  = array_map( static fn( WP_Post $p ): int => (int) $p->ID, $posts );
	$plan = $redirect && $published ? iato_mcp_plan_move_redirects( $changes, $ids ) : null;
	return [
		'posts'       => $ids,
		'urls_before' => $before,
		'changes'     => $changes,
		'redirect'    => $redirect && $published,
		'plan'        => $plan,
		'stale'       => $on_new['stale'],
		'warnings'    => null !== $plan && null !== $plan['no_room'] ? [ $plan['no_room'] ] : [],
	];
}

/**
 * The redirects for these URL changes, through the handler update_redirect uses: not
 * from a path that is now another published page, nor one the handler already redirects.
 *
 * @param int[] $ids The posts whose URLs change.
 * @return array{handler:string,planned:list<array{from:string,to:string}>,skipped:list<array>,no_room:?string}
 */
function iato_mcp_plan_move_redirects( array $changes, array $ids ): array {
	$moving  = array_fill_keys( $ids, true );
	$plan    = IATO_MCP_Redirects::plan(
		$changes,
		(string) parse_url( home_url( '/' ), PHP_URL_HOST ),
		static fn( string $path ): bool => IATO_MCP_URL_Move::path_is_live_page( $path, $moving )
	);
	$handler = IATO_MCP_Redirects::store()->handler();
	$kept    = IATO_MCP_Redirects::without_existing( $plan['planned'], $handler );
	return [
		'handler' => $handler,
		'planned' => $kept['planned'],
		'skipped' => array_merge( $plan['skipped'], $kept['skipped'] ),
		'no_room' => IATO_MCP_Redirects::no_room( $handler, count( $kept['planned'] ) ),
	];
}

/**
 * The URL changes a response may list: a page the caller cannot read (another
 * author's private or scheduled page) moves too, but is only counted.
 *
 * @return array{url_changes:list<array>,url_changes_hidden?:int}
 */
function iato_mcp_shown_url_changes( array $changes ): array {
	$shown  = [];
	$hidden = 0;
	foreach ( $changes as $change ) {
		if ( 'publish' === $change['status'] || ! is_wp_error( IATO_MCP_Auth::require_read_post( (int) $change['id'] ) ) ) {
			$shown[] = $change;
		} else {
			$hidden++;
		}
	}
	return [ 'url_changes' => array_slice( $shown, 0, IATO_MCP_Redirects::LIST_LIMIT ) ] + ( $hidden > 0 ? [ 'url_changes_hidden' => $hidden ] : [] );
}

/**
 * A post given a slug whose URL is a published page's URL: WordPress shows the page
 * there and the post cannot be reached. WordPress allows it, because it compares a
 * post's slug only with posts, so this is a warning (1.20.0).
 */
function iato_mcp_slug_url_warning( WP_Post $post, string $slug ): ?string {
	if ( is_post_type_hierarchical( $post->post_type ) || ! is_post_type_viewable( $post->post_type ) ) {
		return null;
	}
	if ( ! function_exists( 'get_sample_permalink' ) ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}
	$sample = get_sample_permalink( $post->ID, null, $slug );
	if ( ! is_array( $sample ) || ! isset( $sample[0], $sample[1] ) ) {
		return null;
	}
	$url  = str_replace( [ '%postname%', '%pagename%' ], (string) $sample[1], (string) $sample[0] );
	$path = IATO_MCP_Redirects::path_of( $url, (string) parse_url( home_url( '/' ), PHP_URL_HOST ) );
	if ( null === $path || ! IATO_MCP_URL_Move::path_is_live_page( $path, [ (int) $post->ID => true ] ) ) {
		return null;
	}
	return sprintf( 'The new URL, %1$s, is already a published page\'s URL. WordPress shows the page there, so visitors could not reach this %2$s at it.', $url, $post->post_type );
}

/** A dry run's report of a move. */
function iato_mcp_page_move_preview( array $move ): array {
	$out  = [ 'url_change_count' => count( $move['changes'] ) ] + iato_mcp_shown_url_changes( $move['changes'] );
	$plan = $move['plan'];
	if ( null !== $plan ) {
		$out['redirect_handler'] = $plan['handler'];
		$out                    += IATO_MCP_Redirects::report( null === $plan['no_room'] ? $plan['planned'] : [], null, 'redirects_planned' );
		if ( [] !== $plan['skipped'] ) {
			$out['redirects_skipped'] = $plan['skipped'];
		}
	}
	if ( [] !== $move['stale'] ) {
		$out['redirects_to_remove'] = $move['stale'];
	}
	return $out;
}

/**
 * After the write: the URLs as WordPress now gives them, the receipt (recorded before
 * any redirect, holding the ones about to be made, so a request cut short still leaves
 * a rollback that finds them), then the redirects.
 *
 * @return array{receipt:?array,report:array,warnings:string[]}
 */
function iato_mcp_finish_page_move( WP_Post $fresh, array $attr_before, ?array $move ): array {
	$out        = [ 'receipt' => null, 'report' => [], 'warnings' => [] ];
	$attr_after = IATO_MCP_URL_Move::snapshot( $fresh, isset( $attr_before['slug'] ) );
	if ( $attr_after === $attr_before ) {
		return $out;
	}
	if ( null === $move ) {
		$out['receipt'] = IATO_MCP_Change_Receipt::record( (int) $fresh->ID, 'post', IATO_MCP_URL_Move::FIELD, $attr_before, $attr_after );
		return $out;
	}

	$posts   = array_values( array_filter( array_map( 'get_post', $move['posts'] ) ) );
	$changes = IATO_MCP_URL_Move::changes( $posts, $move['urls_before'], IATO_MCP_URL_Move::urls( $posts ) );
	$on_new  = IATO_MCP_Redirects::rules_on_new_urls( $changes, (string) parse_url( home_url( '/' ), PHP_URL_HOST ) );
	if ( [] !== $on_new['blocking'] ) {
		$out['warnings'][] = IATO_MCP_Redirects::blocking_refusal( $on_new['blocking'] )->get_error_message();
	}
	$plan    = null;
	$rules   = [];
	if ( $move['redirect'] ) {
		$plan = iato_mcp_plan_move_redirects( $changes, $move['posts'] );
		if ( null === $plan['no_room'] ) {
			$rules = $plan['planned'];
		} else {
			$out['warnings'][] = $plan['no_room'];
		}
	}
	$recorded       = array_map(
		static fn( array $r ): array => [ 'handler' => $plan['handler'], 'from' => $r['from'], 'to' => $r['to'], 'type' => IATO_MCP_Redirects::TYPE ],
		$rules
	);
	$receipt        = IATO_MCP_Change_Receipt::record(
		(int) $fresh->ID,
		'post',
		IATO_MCP_URL_Move::FIELD,
		$attr_before,
		$attr_after + [ 'redirects' => $recorded ] + ( [] !== $on_new['stale'] ? [ 'removed_redirects' => $on_new['stale'] ] : [] )
	);
	$out['receipt'] = $receipt;

	// First the rules an earlier move left on the new URLs, then the new ones.
	$removed = IATO_MCP_Redirects::remove_stale( $on_new['stale'] );
	$created = [];
	if ( [] !== $rules ) {
		$made    = IATO_MCP_Redirects::create_all( $rules, $plan['handler'] );
		$created = $made['created'];
		if ( [] !== $made['failed'] ) {
			$out['warnings'][] = sprintf(
				'%1$d redirect(s) could not be created, so those old URLs do not redirect: %2$s.',
				count( $made['failed'] ),
				implode( '; ', array_map( static fn( array $f ): string => $f['from'] . ' (' . $f['error'] . ')', array_slice( $made['failed'], 0, 5 ) ) )
			);
		}
	}
	$out['report'] = [ 'url_change_count' => count( $changes ) ] + iato_mcp_shown_url_changes( $changes );
	if ( null !== $plan ) {
		$out['report']['redirect_handler'] = $plan['handler'];
		$out['report']                    += IATO_MCP_Redirects::report( $created, $receipt['change_id'] ?? null );
		if ( [] !== $plan['skipped'] ) {
			$out['report']['redirects_skipped'] = $plan['skipped'];
		}
	}
	if ( [] !== $removed ) {
		$out['report']['redirects_removed'] = $removed;
	}
	return $out;
}

// ── update_post ───────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_post',
	[
		'description' => 'Update an existing post title, content, slug, excerpt, status, publish date, author, parent page or menu_order. On page-builder sites (Elementor, Divi, etc.), content edits go through the builder tools — see server instructions. A new parent moves the page and every page under it to new URLs: when any of them can be opened now it needs confirm_url_break=true, and 301 redirects from the old URLs of published pages are created by default (redirect_old_urls; needs an administrator). dry_run lists every URL that moves (url_changes) and the redirects it would create. The change receipt\'s rollback puts the page back and removes those redirects.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'                 => [ 'type' => 'integer', 'description' => 'Post ID to update (required)' ],
				'title'              => [ 'type' => 'string',  'description' => 'New title' ],
				'content'            => [ 'type' => 'string',  'description' => 'New content' ],
				'slug'               => [ 'type' => 'string',  'description' => 'New post slug (lowercase a-z 0-9 and hyphens, no leading/trailing/double hyphens, max 200 chars). Returns slug_conflict error if taken; non-draft posts also require confirm_url_break=true.' ],
				'excerpt'            => [ 'type' => 'string',  'description' => 'New excerpt (manual summary shown on archive/listing pages)' ],
				'status'             => [ 'type' => 'string',  'description' => 'New status: draft|publish' ],
				'confirm_url_break'  => [ 'type' => 'boolean', 'description' => 'Required true when changing the slug of a non-draft post, or a parent that moves a URL visitors can open; acknowledges the URL break.' ],
				'parent'             => [ 'type' => 'integer', 'minimum' => 0, 'description' => IATO_MCP_POST_PARENT_HELP ],
				'menu_order'         => [ 'type' => 'integer', 'description' => IATO_MCP_MENU_ORDER_HELP ],
				'redirect_old_urls'  => [ 'type' => 'boolean', 'description' => 'With a new parent: create a 301 redirect from each published page\'s old URL to its new one. Default: true when a published page moves. Creating redirects needs an administrator (manage_options); others pass false.' ],
				'date'               => [ 'type' => 'string',  'description' => IATO_MCP_POST_DATE_HELP ],
				'date_gmt'           => [ 'type' => 'string',  'description' => IATO_MCP_POST_DATE_GMT_HELP ],
				'allow_future'       => [ 'type' => 'boolean', 'description' => 'A published post given a future date is refused with future_date_on_published unless this is true; it then becomes scheduled and leaves the site until that date. A draft keeps its status and uses the date when published. Default: false.' ],
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

		// 1.20.0: page attributes, read first: the slug check uses the parent the post will have.
		$parent_now = (int) $post->post_parent;
		$parent_new = $parent_now;
		$warnings   = [];
		if ( array_key_exists( 'parent', $args ) ) {
			$checked = IATO_MCP_URL_Move::check_parent( $post_id, $post->post_type, $args['parent'] );
			if ( is_wp_error( $checked ) ) {
				return IATO_MCP_Server::refusal( $checked );
			}
			$parent_new = $checked['parent'];
			if ( null !== $checked['warning'] && $parent_new !== $parent_now ) {
				$warnings[] = $checked['warning'];
			}
		}
		$order_new = (int) $post->menu_order;
		if ( array_key_exists( 'menu_order', $args ) ) {
			$order = IATO_MCP_URL_Move::check_menu_order( $post->post_type, $args['menu_order'] );
			if ( is_wp_error( $order ) ) {
				return IATO_MCP_Server::refusal( $order );
			}
			$order_new = $order;
		}
		$moves = $parent_new !== $parent_now;

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
			$slug_or_error = iato_mcp_validate_post_slug( $args['slug'], $post_id, $parent_new );
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
			if ( $slug_or_error !== $post->post_name ) {
				$shadowed = iato_mcp_slug_url_warning( $post, $slug_or_error );
				if ( null !== $shadowed ) {
					$warnings[] = $shadowed;
				}
			}
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

		// 1.20.0: a new parent moves this page and every page under it.
		$move = null;
		if ( $moves ) {
			$move = iato_mcp_plan_page_move( $post, $parent_new, isset( $postarr['post_name'] ) ? (string) $postarr['post_name'] : (string) $post->post_name, $args );
			if ( is_wp_error( $move ) ) {
				return IATO_MCP_Server::refusal( $move );
			}
		}
		$attr_before = null;
		if ( $moves || $order_new !== (int) $post->menu_order ) {
			if ( $moves ) {
				$postarr['post_parent'] = $parent_new;
			}
			if ( $order_new !== (int) $post->menu_order ) {
				$postarr['menu_order'] = $order_new;
			}
			// A slug changed with the parent goes in the move's receipt, so one rollback
			// restores the URL and removes the redirects from it together.
			$with_slug   = $moves && isset( $before['slug'] );
			$attr_before = IATO_MCP_URL_Move::snapshot( $post, $with_slug );
			if ( $with_slug ) {
				unset( $before['slug'] );
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
			if ( null !== $attr_before ) {
				$would['page_attributes'] = [
					'before' => $attr_before,
					'after'  => [ 'parent' => $parent_new, 'menu_order' => $order_new ] + ( isset( $attr_before['slug'] ) ? [ 'slug' => (string) $postarr['post_name'] ] : [] ),
				];
			}
			$data = [
				'dry_run'      => true,
				'action'       => [] === $would ? 'no_change' : 'would_update',
				'id'           => $post_id,
				'would_change' => (object) $would,
				'not_checked'  => IATO_MCP_Server::DRY_RUN_NOT_CHECKED,
			];
			if ( null !== $move ) {
				$data += iato_mcp_page_move_preview( $move );
				$warnings = array_merge( $warnings, $move['warnings'] );
			}
			if ( [] !== $warnings ) {
				$data['warnings'] = $warnings;
			}
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

		if ( null !== $move ) {
			// From here the write, its receipt and its redirects belong together: a client
			// that stops waiting must not stop the request between them.
			ignore_user_abort( true );
		}

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
		$moved = null;
		if ( null !== $attr_before && $fresh ) {
			$moved = iato_mcp_finish_page_move( $fresh, $attr_before, $move );
			if ( null !== $moved['receipt'] ) {
				$receipts[] = $moved['receipt'];
			}
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
		if ( null !== $moved ) {
			$data['parent']     = (int) $fresh->post_parent;
			$data['menu_order'] = (int) $fresh->menu_order;
			$data              += $moved['report'];
			$warnings           = array_merge( $warnings, $moved['warnings'] );
		}
		if ( [] !== $warnings ) {
			$data['warnings'] = $warnings;
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
