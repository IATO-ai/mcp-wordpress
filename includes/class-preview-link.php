<?php
/**
 * Preview links (1.21.0): a signed, expiring URL that shows one draft, pending or
 * scheduled post to anyone who has it, so a client can look at its own work. For
 * create_preview_link, the "Page assets" tab and rollback (which revokes the link).
 *
 * The token is 32 random characters; only an HMAC of it, the post and the site's salt is stored, in the post's
 * `_iato_mcp_preview_links` meta (denied to every meta tool), with the expiry. A
 * request carrying a valid token for that post is let through WordPress's status
 * check by asking the main query for the post's own status, and gets no-cache,
 * noindex and no-referrer headers so no page cache keeps it and the token does not
 * leave in a Referer. Anything else about the token, or the post being published or
 * trashed since, and WordPress answers as it would without one: a 404.
 *
 * Known limit: the token is in the URL, so access logs and analytics on the page
 * record it. Lifetimes are short (15 minutes by default, an hour at most) and links
 * can be revoked.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_Preview_Link {

	public const META        = '_iato_mcp_preview_links';
	public const RECEIPT     = 'preview_link';
	public const FIELD       = 'create';
	public const PARAM       = 'iato_preview';
	public const DEFAULT_TTL = 900;
	public const MIN_TTL     = 60;
	public const MAX_TTL     = 3600;
	public const MAX_LINKS   = 10;
	public const STATUSES    = [ 'draft', 'pending', 'future' ];

	/** The post this request is previewing, or 0. */
	private static int $active = 0;

	public static function init(): void {
		add_action( 'pre_get_posts', [ self::class, 'pre_get_posts' ] );
		add_filter( 'posts_results', [ self::class, 'only_that_post' ], 10, 2 );
		add_filter( 'wp_robots', [ self::class, 'robots' ] );
		// A link ends when its post leaves the statuses it was made for.
		add_action( 'transition_post_status', [ self::class, 'status_changed' ], 10, 3 );
	}

	/**
	 * The stored form of a token: an HMAC over the post and the token with the site's
	 * own salt, so meta copied to another post or another site (a duplicate, an import)
	 * opens nothing there.
	 */
	public static function hash( int $post_id, string $token ): string {
		return hash_hmac( 'sha256', $post_id . '|' . $token, function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'iato-mcp' );
	}

	/** @param string $new @param string $old @param WP_Post $post */
	public static function status_changed( $new, $old, $post ): void {
		if ( $post instanceof WP_Post && $new !== $old && ! in_array( $new, self::STATUSES, true ) && [] !== self::links( (int) $post->ID ) ) {
			self::revoke( (int) $post->ID, null );
		}
	}

	/** The live links of a post, expired ones left out. @return array<int,array{link_id:string,hash:string,expires:int,user_id:int,created:int}> */
	public static function links( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META, true );
		$now   = time();
		$out   = [];
		foreach ( is_array( $value ) ? $value : [] as $link ) {
			// A lifetime longer than any this plugin gives is not one it wrote.
			if ( is_array( $link ) && isset( $link['hash'], $link['expires'], $link['link_id'] ) && (int) $link['expires'] > $now
				&& (int) $link['expires'] <= (int) ( $link['created'] ?? 0 ) + self::MAX_TTL ) {
				$out[] = [
					'link_id' => (string) $link['link_id'],
					'hash'    => (string) $link['hash'],
					'expires' => (int) $link['expires'],
					'user_id' => (int) ( $link['user_id'] ?? 0 ),
					'created' => (int) ( $link['created'] ?? 0 ),
				];
			}
		}
		return $out;
	}

	private static function save( int $post_id, array $links ): void {
		if ( [] === $links ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		update_post_meta( $post_id, self::META, wp_slash( array_values( $links ) ) );
	}

	/** May this request make a preview link for this post, and is the post one that can have one? */
	public static function may_create( int $post_id ): bool|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}
		$edit = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
		if ( is_wp_error( $edit ) ) {
			return $edit;
		}
		// A link shows the post to anyone who has it, a kind of publishing.
		$publish = IATO_MCP_Auth::require_publish_cap( (string) $post->post_type );
		if ( is_wp_error( $publish ) ) {
			return $publish;
		}
		if ( (int) get_option( 'page_for_posts' ) === $post_id ) {
			return new WP_Error( 'preview_not_supported', 'This page is the posts page, which lists posts rather than showing its own content, so it has no preview link.', [ 'status' => 409 ] );
		}
		if ( ! in_array( $post->post_status, self::STATUSES, true ) ) {
			return new WP_Error(
				'preview_not_needed',
				sprintf( 'This post is %s. Preview links are for drafts, pending and scheduled posts; a published post can be viewed at its own URL.', $post->post_status ),
				[ 'status' => 409, 'post_status' => $post->post_status ]
			);
		}
		return true;
	}

	/** @return int|WP_Error The lifetime asked for, within bounds. */
	public static function ttl( mixed $raw ): int|WP_Error {
		if ( null === $raw ) {
			return self::DEFAULT_TTL;
		}
		if ( ! is_int( $raw ) && ! ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
			return new WP_Error( 'invalid_ttl', 'ttl_seconds must be a whole number of seconds.' );
		}
		$ttl = (int) $raw;
		if ( $ttl < self::MIN_TTL || $ttl > self::MAX_TTL ) {
			return new WP_Error( 'invalid_ttl', sprintf( 'ttl_seconds must be between %1$d and %2$d.', self::MIN_TTL, self::MAX_TTL ), [ 'min' => self::MIN_TTL, 'max' => self::MAX_TTL ] );
		}
		return $ttl;
	}

	/**
	 * Make a link. The token is returned once, in the URL, and never stored.
	 *
	 * @return array{url:string,link_id:string,expires:int}|WP_Error
	 */
	public static function create( int $post_id, int $ttl ): array|WP_Error {
		$links = self::links( $post_id );
		if ( count( $links ) >= self::MAX_LINKS ) {
			return new WP_Error( 'too_many_preview_links', sprintf( 'This post already has %d live preview links. Revoke one by rolling back its receipt, or wait for one to expire.', self::MAX_LINKS ), [ 'status' => 409 ] );
		}
		$token   = wp_generate_password( 32, false, false );
		$link_id = bin2hex( random_bytes( 6 ) );
		$expires = time() + $ttl;
		$links[] = [
			'link_id' => $link_id,
			'hash'    => self::hash( $post_id, $token ),
			'expires' => $expires,
			'user_id' => (int) get_current_user_id(),
			'created' => time(),
		];
		self::save( $post_id, $links );
		return [ 'url' => self::url( $post_id, $token ), 'link_id' => $link_id, 'expires' => $expires ];
	}

	public static function url( int $post_id, string $token ): string {
		return add_query_arg( self::PARAM, rawurlencode( $token ), (string) get_permalink( $post_id ) );
	}

	/** Revoke one link, or every link of the post with null. True when it is gone (already gone counts). */
	public static function revoke( int $post_id, ?string $link_id ): bool {
		if ( null === $link_id ) {
			self::save( $post_id, [] );
			return true;
		}
		self::save( $post_id, array_values( array_filter( self::links( $post_id ), static fn( array $l ): bool => $l['link_id'] !== $link_id ) ) );
		return true;
	}

	/** Does this token open this post now? */
	public static function valid( int $post_id, string $token ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return false;
		}
		$hash = self::hash( $post_id, $token );
		foreach ( self::links( $post_id ) as $link ) {
			if ( hash_equals( $link['hash'], $hash ) ) {
				return true;
			}
		}
		return false;
	}

	/** Every post with a live link, for the "Page assets" tab. @return array<int,array> */
	public static function all_live(): array {
		$ids = get_posts( [ 'post_type' => 'any', 'post_status' => 'any', 'meta_key' => self::META, 'fields' => 'ids', 'numberposts' => 200, 'suppress_filters' => true ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin screen only, our own key.
		$out = [];
		foreach ( $ids as $id ) {
			foreach ( self::links( (int) $id ) as $link ) {
				unset( $link['hash'] );
				$out[] = [ 'post_id' => (int) $id ] + $link;
			}
		}
		return $out;
	}

	/**
	 * pre_get_posts: a main query for one post carrying a valid token for it asks for
	 * that post's own status, which WordPress's status check then lets through.
	 */
	public static function pre_get_posts( $query ): void {
		if ( ! $query instanceof WP_Query || ! $query->is_main_query() || is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A capability token, checked against its stored hash; no state changes.
		$token = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		if ( '' === $token ) {
			return;
		}
		// One post, shown as itself: never a listing (the posts page ignores its ID), and
		// never a URL that names two posts.
		$page_id = (int) $query->get( 'page_id' );
		$p       = (int) $query->get( 'p' );
		if ( ! $query->is_singular() || $query->is_posts_page || ( $page_id > 0 && $p > 0 && $page_id !== $p ) ) {
			return;
		}
		$post_id = $page_id ?: $p;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || ! in_array( $post->post_status, self::STATUSES, true ) || ! self::valid( $post_id, $token ) ) {
			return;
		}
		$query->set( 'post_status', $post->post_status );
		self::$active = $post_id;
		self::no_cache();
	}

	/**
	 * posts_results: while a preview is active, the main query returns that post and
	 * nothing else, whatever else the URL asked for.
	 *
	 * @param array    $posts
	 * @param WP_Query $query
	 */
	public static function only_that_post( $posts, $query ) {
		if ( self::$active <= 0 || ! $query instanceof WP_Query || ! $query->is_main_query() || ! is_array( $posts ) ) {
			return $posts;
		}
		return array_values( array_filter( $posts, static fn( $p ): bool => $p instanceof WP_Post && (int) $p->ID === self::$active ) );
	}

	/** Keep page caches, search engines and other sites' logs away from a preview. */
	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'IATO MCP preview link' );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/** @param array $robots wp_robots directives. */
	public static function robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : [];
		if ( self::$active > 0 ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/** Is this request a preview? */
	public static function active(): int {
		return self::$active;
	}
}
