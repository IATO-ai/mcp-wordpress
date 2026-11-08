<?php
/**
 * A page given a new parent (1.20.0): the parent rules, the URLs that move with it, and
 * the receipt that undoes the move and its redirects.
 *
 * Moving a page moves every page under it, because a page's URL is its ancestors'
 * slugs. The new URLs are worked out before anything is written, through WordPress's
 * own get_permalink() with get_page_uri answering for the new place, so other plugins'
 * link filters still apply and the dry run shows what the write will do.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_URL_Move {

	/** Receipt field: target_type post. Parent and menu order, and the slug when it changed in the same call. */
	public const FIELD = 'page_attributes';

	/** Statuses whose URL someone can open: a change to one needs confirm_url_break. */
	public const LIVE_STATUSES = [ 'publish', 'private', 'future' ];

	/** The descendants that move: everything but the trash, auto-drafts and revisions. */
	public const DESCENDANT_STATUSES = [ 'publish', 'private', 'future', 'draft', 'pending' ];

	/** A parent no one may use. */
	private const UNUSABLE_STATUSES = [ 'trash', 'auto-draft', 'inherit' ];

	/**
	 * A post ID from a tool argument: an integer, or a string of digits.
	 */
	public static function int_arg( mixed $raw, int $min = 0 ): ?int {
		if ( is_int( $raw ) ) {
			return $raw >= $min ? $raw : null;
		}
		if ( is_string( $raw ) && preg_match( '/^-?[0-9]{1,10}$/', trim( $raw ) ) ) {
			$value = (int) trim( $raw );
			return $value >= $min ? $value : null;
		}
		return null;
	}

	/**
	 * The parent rules, on facts already gathered. Null when the parent may be used.
	 *
	 * @param int         $post_id         The post moved; 0 for one being created.
	 * @param string      $post_type       Its type.
	 * @param bool        $hierarchical    Whether that type has parents.
	 * @param int         $parent          The parent asked for; 0 clears it.
	 * @param object|null $parent_post     That post, or null when there is none.
	 * @param bool        $readable        Whether the caller may read it.
	 * @param int[]       $ancestors       Its ancestors' IDs.
	 */
	public static function parent_problem( int $post_id, string $post_type, bool $hierarchical, int $parent, ?object $parent_post, bool $readable, array $ancestors ): ?WP_Error {
		if ( ! $hierarchical ) {
			return new WP_Error(
				'parent_not_supported',
				sprintf( 'A %s has no parent: only pages and other hierarchical post types do. Nothing was written.', $post_type ),
				[ 'status' => 400, 'post_type' => $post_type ]
			);
		}
		if ( 0 === $parent ) {
			return null;
		}
		if ( $post_id > 0 && $parent === $post_id ) {
			return self::cycle( $post_id, $parent );
		}
		// One answer for every reason, so the refusal does not say which IDs exist.
		if ( null === $parent_post || ! $readable || (string) ( $parent_post->post_type ?? '' ) !== $post_type
			|| in_array( (string) ( $parent_post->post_status ?? '' ), self::UNUSABLE_STATUSES, true ) ) {
			return new WP_Error(
				'invalid_parent',
				sprintf( 'Post %1$d is not a %2$s that can be the parent. Nothing was written.', $parent, $post_type ),
				[ 'status' => 400, 'parent' => $parent ]
			);
		}
		if ( $post_id > 0 && in_array( $post_id, array_map( 'intval', $ancestors ), true ) ) {
			return self::cycle( $post_id, $parent );
		}
		return null;
	}

	private static function cycle( int $post_id, int $parent ): WP_Error {
		return new WP_Error(
			'parent_cycle',
			sprintf( 'Post %1$d cannot be the parent of post %2$d: it is post %2$d itself or one of the pages under it. Nothing was written.', $parent, $post_id ),
			[ 'status' => 400, 'parent' => $parent ]
		);
	}

	/** The warning for a parent visitors cannot open yet, or null. */
	public static function parent_warning( int $parent, string $status ): ?string {
		if ( 0 === $parent || 'publish' === $status ) {
			return null;
		}
		return sprintf(
			'The parent, post %1$d, is %2$s, so the new URL includes a slug that is not live: visitors who open the parent\'s own URL will not find it until it is published.',
			$parent,
			$status
		);
	}

	/**
	 * Check a parent sent for a post (or, with $post_id 0, for one being created).
	 *
	 * @return array{parent:int,warning:?string}|WP_Error
	 */
	public static function check_parent( int $post_id, string $post_type, mixed $raw ): array|WP_Error {
		$parent = self::int_arg( $raw );
		if ( null === $parent ) {
			return new WP_Error( 'invalid_parent', 'parent must be a post ID, or 0 for no parent. Nothing was written.', [ 'status' => 400 ] );
		}
		$parent_post = $parent > 0 ? get_post( $parent ) : null;
		$readable    = null !== $parent_post && ! is_wp_error( IATO_MCP_Auth::require_read_post( $parent ) );
		$problem     = self::parent_problem(
			$post_id,
			$post_type,
			is_post_type_hierarchical( $post_type ),
			$parent,
			$parent_post,
			$readable,
			$parent_post ? get_post_ancestors( $parent_post ) : []
		);
		if ( null !== $problem ) {
			return $problem;
		}
		return [ 'parent' => $parent, 'warning' => self::parent_warning( $parent, $parent_post ? (string) $parent_post->post_status : 'publish' ) ];
	}

	/**
	 * Check a menu order sent for a post type: WordPress's REST API offers it only for
	 * types that support page attributes.
	 */
	public static function check_menu_order( string $post_type, mixed $raw ): int|WP_Error {
		if ( ! post_type_supports( $post_type, 'page-attributes' ) ) {
			return new WP_Error(
				'menu_order_not_supported',
				sprintf( 'A %s has no order: menu_order is for types with page attributes, such as pages. Nothing was written.', $post_type ),
				[ 'status' => 400, 'post_type' => $post_type ]
			);
		}
		$order = self::int_arg( $raw, -2147483648 );
		if ( null === $order || $order > 2147483647 ) {
			return new WP_Error( 'invalid_menu_order', 'menu_order must be an integer. Nothing was written.', [ 'status' => 400 ] );
		}
		return $order;
	}

	/**
	 * Every page under this one that has a URL of its own. Not get_pages() with
	 * post_status 'any': that is no registered status, and get_pages() returns false.
	 *
	 * @return WP_Post[]
	 */
	public static function descendants( WP_Post $post ): array {
		$pages = get_pages(
			[
				'child_of'    => $post->ID,
				'post_type'   => $post->post_type,
				'post_status' => self::DESCENDANT_STATUSES,
			]
		);
		return is_array( $pages ) ? array_values( $pages ) : [];
	}

	/** @param WP_Post[] $posts @return array<int,string> Each post's URL now. */
	public static function urls( array $posts ): array {
		$urls = [];
		foreach ( $posts as $post ) {
			$urls[ (int) $post->ID ] = (string) get_permalink( $post );
		}
		return $urls;
	}

	/**
	 * The URLs these posts will have once $post sits under $new_parent with $new_slug.
	 * WordPress's get_permalink() builds a page's URL from get_page_uri(), its ancestors'
	 * slugs; answering that filter for the moved page and the pages under it gives the
	 * URLs the write will produce, with every other link filter still applied.
	 *
	 * @param WP_Post[] $descendants
	 * @return array<int,string>
	 */
	public static function predicted_urls( WP_Post $post, array $descendants, int $new_parent, string $new_slug ): array {
		$old_uri = (string) get_page_uri( $post );
		$new_uri = ( $new_parent > 0 ? rtrim( (string) get_page_uri( $new_parent ), '/' ) . '/' : '' ) . $new_slug;
		$moved   = [ (int) $post->ID => true ];
		foreach ( $descendants as $child ) {
			$moved[ (int) $child->ID ] = true;
		}
		$filter = static function ( $uri, $page ) use ( $post, $old_uri, $new_uri, $moved ) {
			$id = is_object( $page ) ? (int) $page->ID : (int) $page;
			if ( $id === (int) $post->ID ) {
				return $new_uri;
			}
			if ( isset( $moved[ $id ] ) && '' !== $old_uri && str_starts_with( (string) $uri, $old_uri . '/' ) ) {
				return $new_uri . substr( (string) $uri, strlen( $old_uri ) );
			}
			return $uri;
		};
		add_filter( 'get_page_uri', $filter, PHP_INT_MAX, 2 );
		try {
			return self::urls( array_merge( [ $post ], $descendants ) );
		} finally {
			remove_filter( 'get_page_uri', $filter, PHP_INT_MAX );
		}
	}

	/**
	 * The posts whose URL differs between the two lists.
	 *
	 * @param WP_Post[]         $posts
	 * @param array<int,string> $before
	 * @param array<int,string> $after
	 * @return list<array{id:int,title:string,status:string,old_url:string,new_url:string}>
	 */
	public static function changes( array $posts, array $before, array $after ): array {
		$changes = [];
		foreach ( $posts as $post ) {
			$id  = (int) $post->ID;
			$old = $before[ $id ] ?? '';
			$new = $after[ $id ] ?? '';
			if ( $old === $new ) {
				continue;
			}
			$changes[] = [
				'id'      => $id,
				'title'   => (string) $post->post_title,
				'status'  => (string) $post->post_status,
				'old_url' => $old,
				'new_url' => $new,
			];
		}
		return $changes;
	}

	/** Does any of these changes move a URL someone can open now? */
	public static function moves_live_url( array $changes ): bool {
		foreach ( $changes as $change ) {
			if ( in_array( $change['status'], self::LIVE_STATUSES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Is any of these changes to a published post? Then redirects are wanted by default. */
	public static function moves_published_url( array $changes ): bool {
		foreach ( $changes as $change ) {
			if ( 'publish' === $change['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is this path, on this site, now the URL of a published page (other than the ones
	 * moving away from it)? A redirect from it would hide that page.
	 *
	 * @param array<int,true> $moving IDs of the posts whose URLs changed.
	 */
	public static function path_is_live_page( string $path, array $moving = [] ): bool {
		$home = (string) parse_url( home_url( '/' ), PHP_URL_PATH );
		$rel  = '/' . ltrim( $path, '/' );
		if ( '' !== $home && '/' !== $home && str_starts_with( $rel, rtrim( $home, '/' ) . '/' ) ) {
			$rel = substr( $rel, strlen( rtrim( $home, '/' ) ) );
		}
		$rel = trim( $rel, '/' );
		if ( '' === $rel ) {
			return true; // the home page
		}
		$types = array_values( get_post_types( [ 'public' => true, 'hierarchical' => true ] ) );
		$page  = get_page_by_path( $rel, OBJECT, [] === $types ? [ 'page' ] : $types );
		return $page instanceof WP_Post && 'publish' === $page->post_status && ! isset( $moving[ (int) $page->ID ] );
	}

	/** A post's page attributes as a receipt holds them. */
	public static function snapshot( object $post, bool $with_slug ): array {
		$snapshot = [ 'parent' => (int) $post->post_parent, 'menu_order' => (int) $post->menu_order ];
		if ( $with_slug ) {
			$snapshot['slug'] = (string) $post->post_name;
		}
		return $snapshot;
	}

	/** The redirects a receipt says its write made. */
	public static function receipt_redirects( mixed $after ): array {
		$after = IATO_MCP_Rollback_Conflict::json_array( $after );
		return is_array( $after['redirects'] ?? null ) ? array_values( array_filter( $after['redirects'], 'is_array' ) ) : [];
	}

	// ── Rollback ──────────────────────────────────────────────────────────

	/** Why the receipt cannot be rolled back at all, or null. Reported before the conflict check. */
	public static function rollback_unavailable( array $receipt ): ?WP_Error {
		$before  = IATO_MCP_Rollback_Conflict::json_array( $receipt['before_value'] ?? null );
		$post_id = (int) ( $receipt['post_id'] ?? 0 );
		if ( ! array_key_exists( 'parent', $before ) ) {
			return new WP_Error( 'rollback_unavailable', 'This receipt holds no parent to restore.', [ 'status' => 409 ] );
		}
		$parent = (int) $before['parent'];
		if ( $parent > 0 ) {
			$parent_post = get_post( $parent );
			if ( ! $parent_post || in_array( (string) $parent_post->post_status, self::UNUSABLE_STATUSES, true ) ) {
				return new WP_Error(
					'rollback_unavailable',
					sprintf( 'The previous parent, post %d, no longer exists or is in the trash, so the page cannot go back under it. Choose a parent with update_post.', $parent ),
					[ 'status' => 409 ]
				);
			}
			if ( $post_id > 0 && in_array( $post_id, array_map( 'intval', get_post_ancestors( $parent_post ) ), true ) ) {
				return new WP_Error(
					'rollback_unavailable',
					sprintf( 'The previous parent, post %1$d, is now under post %2$d, so putting it back would make a loop. Move post %1$d first.', $parent, $post_id ),
					[ 'status' => 409 ]
				);
			}
		}
		return null;
	}

	/**
	 * Conflict check: the page's attributes as the write left them, and the old slug
	 * still free under the old parent (WordPress would otherwise add "-2" to it).
	 */
	public static function conflict( array $receipt ): array {
		$post = get_post( (int) ( $receipt['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return [ 'status' => 'unverifiable', 'reason' => 'the post no longer exists' ];
		}
		$after   = IATO_MCP_Rollback_Conflict::json_array( $receipt['after_value'] ?? null );
		$before  = IATO_MCP_Rollback_Conflict::json_array( $receipt['before_value'] ?? null );
		$current = self::snapshot( $post, array_key_exists( 'slug', $after ) );
		unset( $after['redirects'], $after['removed_redirects'] );
		$check = IATO_MCP_Rollback_Conflict::compare( $current, $after, 'page\'s place in the site (parent, order and slug)' );
		if ( 'conflict' === $check['status'] ) {
			return $check;
		}
		$slug   = (string) ( $before['slug'] ?? $post->post_name );
		$holder = '' === $slug ? null : self::slug_holder( $slug, (int) $post->ID, (string) $post->post_type, (int) ( $before['parent'] ?? 0 ) );
		if ( null !== $holder ) {
			return [
				'status'   => 'conflict',
				'reason'   => sprintf( 'post %1$d now uses the slug "%2$s" under the previous parent, so WordPress would rename this page', $holder, $slug ),
				'current'  => [ 'slug_used_by' => $holder ],
				'expected' => [ 'slug_used_by' => null ],
			];
		}
		return $check;
	}

	/** Undo the move: the redirects first, then the page. */
	public static function rollback( array $receipt ): bool|WP_Error {
		$post_id = (int) ( $receipt['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}
		// A redirect left behind from the old URL would send visitors away from the page
		// once it is back there, to a URL that no longer exists.
		$removal = IATO_MCP_Redirects::remove_all( self::receipt_redirects( $receipt['after_value'] ?? null ) );
		if ( [] !== $removal['failed'] ) {
			return new WP_Error(
				'rollback_failed',
				sprintf( '%d redirect(s) this move created could not be removed, so the page was left where it is. Run the rollback again.', count( $removal['failed'] ) ),
				[ 'status' => 500, 'failed' => array_slice( $removal['failed'], 0, 10 ) ]
			);
		}
		$before = IATO_MCP_Rollback_Conflict::json_array( $receipt['before_value'] ?? null );
		$fields = [
			'ID'          => $post_id,
			'post_parent' => (int) ( $before['parent'] ?? 0 ),
			'menu_order'  => (int) ( $before['menu_order'] ?? 0 ),
		];
		if ( array_key_exists( 'slug', $before ) ) {
			$fields['post_name'] = (string) $before['slug'];
		}
		$result = IATO_MCP_Auth::update_post_fields( $fields, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$note = IATO_MCP_Redirects::removal_note( $removal );
		if ( null !== $note ) {
			IATO_MCP_Rollback::warn( $note );
		}
		// The rules an earlier move left on the new URLs, which this one took away.
		$after   = IATO_MCP_Rollback_Conflict::json_array( $receipt['after_value'] ?? null );
		$restore = IATO_MCP_Redirects::restore_removed( is_array( $after['removed_redirects'] ?? null ) ? $after['removed_redirects'] : [] );
		if ( null !== $restore ) {
			IATO_MCP_Rollback::warn( $restore );
		}
		return true;
	}

	// ── Slugs ─────────────────────────────────────────────────────────────

	/**
	 * Would WordPress store this slug as it is for this post, under this parent? It asks
	 * wp_unique_post_slug() as if publishing: for a draft that function returns any slug
	 * unchanged, and the "-2" would only appear when the post goes live. WordPress
	 * compares a page only with pages and attachments under the same parent, and a post
	 * only with posts of its type, so a menu item called "services" is no conflict.
	 *
	 * @return WP_Error|null slug_conflict, or null when the slug is free.
	 */
	public static function slug_refusal( string $slug, int $post_id, string $post_type, int $parent ): ?WP_Error {
		$would_use = (string) wp_unique_post_slug( $slug, $post_id, 'publish', $post_type, $parent );
		if ( $would_use === $slug ) {
			return null;
		}
		$holder = self::slug_holder( $slug, $post_id, $post_type, $parent );
		if ( null === $holder ) {
			return new WP_Error(
				'slug_conflict',
				sprintf( 'WordPress would not use the slug \'%1$s\' here: it is reserved or clashes with a URL WordPress uses itself, such as a feed or a page number. It would store \'%2$s\' instead.', $slug, $would_use ),
				[
					'conflict_post_id'    => null,
					'reason'              => 'reserved',
					'requested_slug'      => $slug,
					'wordpress_would_use' => $would_use,
				]
			);
		}
		// The holder's title only for a caller who could read it.
		$title = is_wp_error( IATO_MCP_Auth::require_read_post( $holder ) ) ? null : get_the_title( $holder );
		return new WP_Error(
			'slug_conflict',
			sprintf( 'Slug \'%1$s\' is already used by post ID %2$d%3$s.', $slug, $holder, is_post_type_hierarchical( $post_type ) ? ' under the same parent' : '' ),
			[
				'conflict_post_id'    => $holder,
				'conflict_post_title' => $title,
				'reason'              => 'in_use',
				'requested_slug'      => $slug,
				'wordpress_would_use' => $would_use,
			]
		);
	}

	/**
	 * Which post holds this slug, by WordPress's own rule in wp_unique_post_slug(): for a
	 * hierarchical type, a post of the type or an attachment under the same parent; for
	 * an attachment, any post; otherwise a post of the same type.
	 */
	public static function slug_holder( string $slug, int $post_id, string $post_type, int $parent ): ?int {
		global $wpdb;
		if ( 'attachment' === $post_type ) {
			$sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND ID != %d LIMIT 1", $slug, $post_id );
		} elseif ( is_post_type_hierarchical( $post_type ) ) {
			$sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ( %s, 'attachment' ) AND ID != %d AND post_parent = %d LIMIT 1",
				$slug,
				$post_type,
				$post_id,
				$parent
			);
		} else {
			$sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID != %d LIMIT 1", $slug, $post_type, $post_id );
		}
		$id = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return $id > 0 ? $id : null;
	}
}
