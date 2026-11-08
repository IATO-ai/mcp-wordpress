<?php
/**
 * The permalink structure and the category and tag bases (1.20.0), through
 * update_site_settings.
 *
 * Changing them moves every post's URL and, because the bases sit under the
 * structure's fixed start (its "front", /blog/ in /blog/%postname%/), the category,
 * tag and author archives too. Everything that moves is worked out before anything is
 * written, on a copy of WordPress's rewrite state, so a dry run shows the real list and
 * a site too large to check fails before the change, not halfway through it.
 *
 * Rewrite rules are not rebuilt in the request that changes them. WP_Rewrite::init(),
 * which each setter calls, empties the rules and endpoints plugins added on init, and
 * taxonomies keep their old base until the next request, so rules stored now would lose
 * WooCommerce's account pages and keep category archives at the old URL. wp-admin
 * avoids this the same way: it saves, redirects, and flushes on the next page load. Here
 * the stored rules are emptied, so the next request builds them, and nothing in this
 * request may store a rebuild.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_Permalinks {

	/** Receipt target type and field. */
	public const RECEIPT = 'permalinks';
	public const FIELD   = 'structure';

	/** The settings, as update_site_settings names them and WordPress stores them. */
	public const KEYS = [ 'permalink_structure', 'category_base', 'tag_base' ];

	/** The other arguments update_site_settings takes for them. */
	public const FLAGS = [ 'confirm_url_break', 'redirect_old_urls' ];

	/** The structure tags WordPress recognises. */
	public const TAGS = [ '%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%', '%post_id%', '%postname%', '%category%', '%author%' ];

	/**
	 * Where WordPress's own URLs start. A category or tag base here would take them
	 * over: those rules come first.
	 */
	public const RESERVED_BASES = [ 'page', 'feed', 'comments', 'comment-page', 'search', 'author', 'type', 'attachment', 'embed', 'trackback', 'rdf', 'rss', 'rss2', 'atom', 'wp-json', 'wp-admin', 'wp-content', 'wp-includes', 'wp-sitemap' ];

	/** Published posts beyond this are too many to check in one request. */
	public const MAX_POSTS = 10000;

	/** Responses list this many URL changes. */
	public const URL_LIST_LIMIT = 25;

	/** Lists of skipped redirects are cut to this many. */
	private const SKIPPED_LIST_LIMIT = 25;

	private static bool $rules_blocked = false;

	// ── Validation ────────────────────────────────────────────────────────

	/** A permalink structure as it will be stored, or why not. */
	public static function check_structure( mixed $raw ): string|WP_Error {
		if ( ! is_string( $raw ) ) {
			return self::invalid( 'permalink_structure must be a string such as /%postname%/ or /blog/%postname%/.' );
		}
		if ( '' === $raw ) {
			return new WP_Error(
				'plain_permalinks_not_supported',
				'An empty permalink_structure is WordPress\'s "Plain" setting (?p=123 URLs), which this tool does not set: every post, page and archive would lose its readable URL. Change it under Settings > Permalinks if that is really wanted. Nothing was written.',
				[ 'status' => 400 ]
			);
		}
		if ( ! str_starts_with( $raw, '/' ) || ! str_ends_with( $raw, '/' ) ) {
			return self::invalid( 'permalink_structure must start and end with "/", such as /blog/%postname%/.' );
		}
		if ( ! str_contains( $raw, '%postname%' ) && ! str_contains( $raw, '%post_id%' ) ) {
			return self::invalid( 'permalink_structure must contain %postname% or %post_id%, or posts would share URLs.' );
		}
		preg_match_all( '/%[^%]*%/', $raw, $found );
		$unknown = array_values( array_diff( $found[0], self::TAGS ) );
		if ( [] !== $unknown ) {
			return self::invalid( sprintf( 'permalink_structure uses %1$s, which WordPress does not recognise. The tags are %2$s.', implode( ', ', $unknown ), implode( ' ', self::TAGS ) ) );
		}
		$fixed = str_replace( self::TAGS, '', $raw );
		if ( ! preg_match( '#^[a-z0-9/-]*$#', $fixed ) || str_contains( $raw, '//' ) ) {
			return self::invalid( 'Outside its tags, permalink_structure may hold only lowercase letters, numbers, hyphens and single slashes.' );
		}
		return $raw;
	}

	/**
	 * A category or tag base as WordPress stores it: '' for the default ("category",
	 * "tag"), otherwise with a leading slash, as Settings > Permalinks saves it.
	 */
	public static function check_base( string $key, mixed $raw ): string|WP_Error {
		if ( ! is_string( $raw ) ) {
			return self::invalid( "{$key} must be a string such as \"topics\", or \"\" for the WordPress default." );
		}
		$base = trim( $raw, '/' );
		if ( '' === $base ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $base ) ) {
			return self::invalid( "{$key} may hold only lowercase letters, numbers, hyphens and single slashes." );
		}
		$first = explode( '/', $base )[0];
		if ( in_array( $first, self::RESERVED_BASES, true ) || ctype_digit( $first ) ) {
			return self::invalid( sprintf( '%1$s cannot start with "%2$s": WordPress uses that for its own URLs (%3$s), which the archives would take over.', $key, $first, ctype_digit( $first ) ? 'date archives' : 'pagination, feeds, search and author archives' ) );
		}
		return '/' . $base;
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'invalid_permalink_setting', $message . ' Nothing was written.', [ 'status' => 400 ] );
	}

	/** The three settings as stored now. */
	public static function current(): array {
		return [
			'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
			'category_base'       => (string) get_option( 'category_base', '' ),
			'tag_base'            => (string) get_option( 'tag_base', '' ),
		];
	}

	/** A base compared as WordPress uses it: the same base with or without its slashes. */
	private static function same_base( string $a, string $b ): bool {
		return trim( $a, '/' ) === trim( $b, '/' );
	}

	/** Do two sets of settings give the same URLs? */
	public static function same( array $a, array $b ): bool {
		return $a['permalink_structure'] === $b['permalink_structure']
			&& self::same_base( $a['category_base'], $b['category_base'] )
			&& self::same_base( $a['tag_base'], $b['tag_base'] );
	}

	/**
	 * The change asked for, or null when the call names none of the three.
	 *
	 * @return array{before:array,after:array,changes:bool}|WP_Error|null
	 */
	public static function plan( array $args ): array|WP_Error|null {
		if ( [] === array_intersect_key( $args, array_flip( self::KEYS ) ) ) {
			return null;
		}
		$before = self::current();
		$after  = $before;
		if ( array_key_exists( 'permalink_structure', $args ) ) {
			$structure = self::check_structure( $args['permalink_structure'] );
			if ( is_wp_error( $structure ) ) {
				return $structure;
			}
			$after['permalink_structure'] = $structure;
		}
		foreach ( [ 'category_base', 'tag_base' ] as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$base = self::check_base( $key, $args[ $key ] );
				if ( is_wp_error( $base ) ) {
					return $base;
				}
				$after[ $key ] = $base;
			}
		}
		if ( '' === $before['permalink_structure'] || str_contains( $before['permalink_structure'], 'index.php' ) ) {
			return new WP_Error(
				'permalinks_not_pretty',
				sprintf(
					'This site uses %s permalinks. Make the first change to readable URLs under Settings > Permalinks, where WordPress checks that the server can serve them; this tool can change them after that. Nothing was written.',
					'' === $before['permalink_structure'] ? 'plain (?p=123)' : '/index.php/'
				),
				[ 'status' => 409 ]
			);
		}
		$category = trim( $after['category_base'], '/' ) ?: 'category';
		$tag      = trim( $after['tag_base'], '/' ) ?: 'tag';
		if ( $category === $tag ) {
			return self::invalid( sprintf( 'category_base and tag_base would both be "%s", so category and tag archives would share URLs.', $category ) );
		}
		$front = trim( self::front( $after['permalink_structure'] ), '/' );
		foreach ( [ 'category_base' => $category, 'tag_base' => $tag ] as $key => $base ) {
			if ( '' !== $front && $base === $front ) {
				return self::invalid( sprintf( '%1$s and the permalink structure would both start with /%2$s/, so archives and posts would share URLs.', $key, $front ) );
			}
		}
		return [ 'before' => $before, 'after' => $after, 'changes' => ! self::same( $before, $after ) ];
	}

	// ── What moves ────────────────────────────────────────────────────────

	/**
	 * Everything whose URL can move: published posts of every public type built on the
	 * structure or its front (pages and attachments are not), the terms with posts of
	 * every public taxonomy built on the front (and categories and tags, whose base may
	 * change), and the authors with published posts.
	 *
	 * @return array{posts:int[],terms:list<array{0:int,1:string}>,authors:int[]}|WP_Error
	 */
	public static function tracked(): array|WP_Error {
		$types = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $name => $type ) {
			if ( 'page' === $name || 'attachment' === $name ) {
				continue;
			}
			if ( 'post' === $name || ( is_array( $type->rewrite ) && ! empty( $type->rewrite['with_front'] ) ) ) {
				$types[] = (string) $name;
			}
		}
		$total = 0;
		foreach ( $types as $type ) {
			$total += (int) ( wp_count_posts( $type )->publish ?? 0 );
		}
		if ( $total > self::MAX_POSTS ) {
			return new WP_Error(
				'too_many_posts',
				sprintf(
					'This site has %1$d published posts whose URLs would move, more than the %2$d this tool can check in one request. Change the permalinks under Settings > Permalinks and add a pattern redirect in a redirect plugin. Nothing was written.',
					$total,
					self::MAX_POSTS
				),
				[ 'status' => 413, 'published_posts' => $total ]
			);
		}
		$posts = [] === $types ? [] : get_posts(
			[
				'post_type'        => $types,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			]
		);

		$taxonomies = [];
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $name => $taxonomy ) {
			if ( 'category' === $name || 'post_tag' === $name || ( is_array( $taxonomy->rewrite ) && ! empty( $taxonomy->rewrite['with_front'] ) ) ) {
				$taxonomies[] = (string) $name;
			}
		}
		$terms = [];
		if ( [] !== $taxonomies ) {
			$found = get_terms( [ 'taxonomy' => $taxonomies, 'hide_empty' => true ] );
			foreach ( is_array( $found ) ? $found : [] as $term ) {
				$terms[] = [ (int) $term->term_id, (string) $term->taxonomy ];
			}
		}

		$authors = [] === $types ? [] : get_users( [ 'has_published_posts' => $types, 'fields' => 'ID' ] );

		return [
			'posts'   => array_map( 'intval', is_array( $posts ) ? $posts : [] ),
			'terms'   => $terms,
			'authors' => array_map( 'intval', is_array( $authors ) ? $authors : [] ),
		];
	}

	/**
	 * Every tracked object's URL as WordPress gives it now.
	 *
	 * @return array<string,string> Keyed post:ID, term:ID, author:ID.
	 */
	public static function links( array $tracked ): array {
		$links = [];
		foreach ( array_chunk( $tracked['posts'], 500 ) as $chunk ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $chunk, true, false );
			}
			foreach ( $chunk as $id ) {
				$links[ 'post:' . $id ] = (string) get_permalink( $id );
			}
		}
		foreach ( $tracked['terms'] as [ $id, $taxonomy ] ) {
			$link = get_term_link( $id, $taxonomy );
			if ( is_string( $link ) ) {
				$links[ 'term:' . $id ] = $link;
			}
		}
		foreach ( $tracked['authors'] as $id ) {
			$links[ 'author:' . $id ] = (string) get_author_posts_url( $id );
		}
		return $links;
	}

	/**
	 * The URLs these settings would give, worked out on a copy of the rewrite state,
	 * which is put back afterwards whatever happens.
	 *
	 * @return array<string,string>
	 */
	public static function simulate( array $before, array $after, array $tracked ): array {
		global $wp_rewrite;
		$saved   = $wp_rewrite;
		$filters = [];
		foreach ( self::KEYS as $key ) {
			$value           = $after[ $key ];
			$filters[ $key ] = static fn() => $value;
			add_filter( 'pre_option_' . $key, $filters[ $key ], PHP_INT_MAX );
		}
		$wp_rewrite = clone $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a copy, put back below.
		try {
			$wp_rewrite->init();
			self::rebase( $wp_rewrite, $before, $after );
			return self::links( $tracked );
		} finally {
			$wp_rewrite = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			foreach ( $filters as $key => $filter ) {
				remove_filter( 'pre_option_' . $key, $filter, PHP_INT_MAX );
			}
		}
	}

	/** The fixed start of a structure, as WP_Rewrite::init() works it out. */
	public static function front( string $structure ): string {
		$tag = strpos( $structure, '%' );
		return false === $tag ? $structure : substr( $structure, 0, $tag );
	}

	/**
	 * Bring the extra permastructs (taxonomies, custom post types) in line with new
	 * settings. Each was built once, when it was registered, as add_permastruct() does:
	 * the front then its own part, or for categories and tags the base. This replaces
	 * that start; a fresh request builds the same from scratch.
	 */
	public static function rebase( object $rewrite, array $before, array $after ): void {
		$old_front = self::front( $before['permalink_structure'] );
		$new_front = self::front( $after['permalink_structure'] );
		foreach ( $rewrite->extra_permastructs as $name => $permastruct ) {
			if ( 'category' === $name || 'post_tag' === $name ) {
				// create_initial_taxonomies(): the base when set, under no front; the default under the front.
				$base       = 'category' === $name ? $after['category_base'] : $after['tag_base'];
				$with_front = '' === trim( $base, '/' );
				$slug       = $with_front ? ( 'category' === $name ? 'category' : 'tag' ) : $base;
				$rewrite->extra_permastructs[ $name ]['struct']     = ( $with_front ? $new_front : '' ) . $slug . '/%' . $name . '%';
				$rewrite->extra_permastructs[ $name ]['with_front'] = $with_front;
				continue;
			}
			$struct = (string) ( $permastruct['struct'] ?? '' );
			if ( ! empty( $permastruct['with_front'] ) && '' !== $old_front && str_starts_with( $struct, $old_front ) ) {
				$rewrite->extra_permastructs[ $name ]['struct'] = $new_front . substr( $struct, strlen( $old_front ) );
			}
		}
	}

	/**
	 * The objects whose URL differs.
	 *
	 * @return list<array{type:string,id:int,status:string,old_url:string,new_url:string}>
	 */
	public static function changes( array $before, array $after ): array {
		$changes = [];
		foreach ( $before as $key => $old ) {
			$new = $after[ $key ] ?? '';
			if ( '' === $new || $old === $new ) {
				continue;
			}
			[ $type, $id ] = explode( ':', $key, 2 );
			$changes[]     = [ 'type' => $type, 'id' => (int) $id, 'status' => 'publish', 'old_url' => $old, 'new_url' => $new ];
		}
		return $changes;
	}

	/**
	 * Posts whose new URL is already a published page's URL. WordPress reads page paths
	 * first, so visitors would get the page and never reach the post. This happens when
	 * posts move under a page (/blog/news/ for a post "news" under a page "blog" that has
	 * a child "news"), or to /%postname%/ where a page has the same slug.
	 *
	 * @return list<array{post_id:int,url:string,page_id:int}>
	 */
	public static function posts_on_page_urls( array $changes ): array {
		$host  = (string) parse_url( home_url( '/' ), PHP_URL_HOST );
		$pages = self::page_paths();
		$found = [];
		foreach ( $changes as $change ) {
			if ( 'post' !== $change['type'] ) {
				continue;
			}
			$path = IATO_MCP_Redirects::path_of( $change['new_url'], $host );
			if ( null !== $path && isset( $pages[ rtrim( $path, '/' ) ] ) ) {
				$found[] = [ 'post_id' => $change['id'], 'url' => $change['new_url'], 'page_id' => $pages[ rtrim( $path, '/' ) ] ];
			}
		}
		return $found;
	}

	/**
	 * Every published page's path, without its trailing slash, to its ID. Pages keep
	 * their URLs through a permalink change. Read once per call, not once per URL.
	 *
	 * @return array<string,int>
	 */
	private static function page_paths(): array {
		$host  = (string) parse_url( home_url( '/' ), PHP_URL_HOST );
		$pages = [];
		foreach ( (array) get_pages( [ 'post_status' => 'publish' ] ) as $page ) {
			$path = IATO_MCP_Redirects::path_of( (string) get_permalink( $page ), $host );
			if ( null !== $path ) {
				$pages[ rtrim( $path, '/' ) ] = (int) $page->ID;
			}
		}
		return $pages;
	}

	/** The warning for posts_on_page_urls(), or null. */
	public static function posts_on_page_urls_warning( array $found ): ?string {
		if ( [] === $found ) {
			return null;
		}
		return sprintf(
			'%1$d post(s) would get the URL of a published page, and WordPress shows the page there, so visitors could not reach the post: %2$s. Change the slug of the post or the page.',
			count( $found ),
			implode( '; ', array_map( static fn( array $f ): string => sprintf( 'post %1$d at %2$s (page %3$d)', $f['post_id'], $f['url'], $f['page_id'] ), array_slice( $found, 0, 10 ) ) )
		);
	}

	// ── Preview and write ─────────────────────────────────────────────────

	/**
	 * Work out the change: what moves and the redirects it needs. Nothing is written.
	 *
	 * @return array|WP_Error
	 */
	public static function prepare( array $before, array $after, bool $redirect ): array|WP_Error {
		$tracked = self::tracked();
		if ( is_wp_error( $tracked ) ) {
			return $tracked;
		}
		$old      = self::links( $tracked );
		$new      = self::simulate( $before, $after, $tracked );
		$changes  = self::changes( $old, $new );
		$warnings = [];
		$clash    = self::posts_on_page_urls_warning( self::posts_on_page_urls( $changes ) );
		if ( null !== $clash ) {
			$warnings[] = $clash;
		}
		// A redirect already on a URL that becomes live would take visitors away from it:
		// one an earlier change left (pointing back here) goes, any other is a refusal.
		$on_new = IATO_MCP_Redirects::rules_on_new_urls( $changes, (string) parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( [] !== $on_new['blocking'] ) {
			return IATO_MCP_Redirects::blocking_refusal( $on_new['blocking'] );
		}
		$plan = $redirect ? self::plan_redirects( $changes, $new ) : null;
		if ( null !== $plan && null !== $plan['no_room'] ) {
			$warnings[] = $plan['no_room'];
		}
		return [
			'stale'    => $on_new['stale'],
			'before'   => $before,
			'after'    => $after,
			'tracked'  => $tracked,
			'old'      => $old,
			'new'      => $new,
			'changes'  => $changes,
			'redirect' => $redirect,
			'plan'     => $plan,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param array<string,string> $new Every tracked object's new URL: no redirect may start at one.
	 * @return array{handler:string,planned:list<array>,skipped:list<array>,no_room:?string}
	 */
	private static function plan_redirects( array $changes, array $new ): array {
		$host      = (string) parse_url( home_url( '/' ), PHP_URL_HOST );
		$new_paths = [];
		foreach ( $new as $url ) {
			$path = IATO_MCP_Redirects::path_of( $url, $host );
			if ( null !== $path ) {
				$new_paths[ rtrim( $path, '/' ) ] = true;
			}
		}
		$pages   = self::page_paths();
		$plan    = IATO_MCP_Redirects::plan(
			$changes,
			$host,
			static fn( string $path ): bool => '' === rtrim( $path, '/' ) || isset( $new_paths[ rtrim( $path, '/' ) ] ) || isset( $pages[ rtrim( $path, '/' ) ] )
		);
		$handler = IATO_MCP_Redirects::store()->handler();
		// Past the limit none are made, so there is no need to look each one up.
		if ( count( $plan['planned'] ) > IATO_MCP_Redirects::MAX_PER_CHANGE ) {
			return [ 'handler' => $handler, 'planned' => [], 'skipped' => $plan['skipped'], 'no_room' => IATO_MCP_Redirects::no_room( $handler, count( $plan['planned'] ) ) ];
		}
		$kept = IATO_MCP_Redirects::without_existing( $plan['planned'], $handler );
		return [
			'handler' => $handler,
			'planned' => $kept['planned'],
			'skipped' => array_merge( $plan['skipped'], $kept['skipped'] ),
			'no_room' => IATO_MCP_Redirects::no_room( $handler, count( $kept['planned'] ) ),
		];
	}

	/** A dry run's report. */
	public static function preview( array $move ): array {
		$out = [
			'moved_count' => count( $move['changes'] ),
			'url_changes' => array_slice( $move['changes'], 0, self::URL_LIST_LIMIT ),
		];
		if ( null !== $move['plan'] ) {
			$out += self::redirect_report( $move['plan'], null === $move['plan']['no_room'] ? $move['plan']['planned'] : [], null, 'redirects_planned' );
		}
		if ( [] !== $move['stale'] ) {
			$out['redirects_to_remove_count'] = count( $move['stale'] );
			$out['redirects_to_remove']       = array_slice( $move['stale'], 0, self::SKIPPED_LIST_LIMIT );
		}
		return $out;
	}

	private static function redirect_report( array $plan, array $rules, ?string $change_id, string $key ): array {
		$out = [ 'redirect_handler' => $plan['handler'] ] + IATO_MCP_Redirects::report( $rules, $change_id, $key );
		if ( [] !== $plan['skipped'] ) {
			$out['redirects_skipped_count'] = count( $plan['skipped'] );
			$out['redirects_skipped']       = array_slice( $plan['skipped'], 0, self::SKIPPED_LIST_LIMIT );
		}
		return $out;
	}

	/**
	 * Make the change, record it at once, then the redirects. The receipt holds the
	 * redirects worked out in prepare(), so a request cut short after the change still
	 * leaves one whose rollback finds whatever was made.
	 *
	 * @return array{receipt:array,report:array,warnings:string[]}
	 */
	public static function commit( array $move ): array {
		// From here the change, its receipt and its redirects belong together: a client
		// that stops waiting must not stop the request between them.
		ignore_user_abort( true );
		$warnings = $move['warnings'];
		$plan     = $move['plan'];
		$rules    = null !== $plan && null === $plan['no_room'] ? $plan['planned'] : [];
		$recorded = array_map(
			static fn( array $r ): array => [ 'handler' => $plan['handler'], 'from' => $r['from'], 'to' => $r['to'], 'type' => IATO_MCP_Redirects::TYPE ],
			$rules
		);

		self::apply( $move['before'], $move['after'] );
		$receipt = IATO_MCP_Change_Receipt::record(
			null,
			self::RECEIPT,
			self::FIELD,
			$move['before'],
			$move['after'] + [ 'redirects' => $recorded ] + ( [] !== $move['stale'] ? [ 'removed_redirects' => $move['stale'] ] : [] )
		);

		// First the rules an earlier change left on the new URLs, then the new ones.
		$removed = IATO_MCP_Redirects::remove_stale( $move['stale'] );
		$created = [];
		if ( [] !== $rules ) {
			$made    = IATO_MCP_Redirects::create_all( $rules, $plan['handler'] );
			$created = $made['created'];
			if ( [] !== $made['failed'] ) {
				$warnings[] = sprintf(
					'%1$d redirect(s) could not be created, so those old URLs do not redirect: %2$s.',
					count( $made['failed'] ),
					implode( '; ', array_map( static fn( array $f ): string => $f['from'] . ' (' . $f['error'] . ')', array_slice( $made['failed'], 0, 5 ) ) )
				);
			}
		}

		// The check that the preview was right comes last: it reads every URL again.
		$live   = self::links( $move['tracked'] );
		$differ = [];
		foreach ( $live as $key => $url ) {
			if ( ( $move['new'][ $key ] ?? null ) !== $url ) {
				$differ[] = $key;
			}
		}
		if ( [] !== $differ ) {
			$warnings[] = sprintf( 'WordPress now gives %1$d URL(s) other than the preview showed (%2$s), and their old URLs were not redirected.', count( $differ ), implode( ', ', array_slice( $differ, 0, 10 ) ) );
		}

		$changes = self::changes( $move['old'], $live );
		$report  = [
			'moved_count' => count( $changes ),
			'url_changes' => array_slice( $changes, 0, self::URL_LIST_LIMIT ),
		];
		if ( null !== $plan ) {
			$report += self::redirect_report( $plan, $created, $receipt['change_id'] ?? null, 'redirects_created' );
		}
		if ( [] !== $removed ) {
			$report['redirects_removed_count'] = count( $removed );
			$report['redirects_removed']       = array_slice( $removed, 0, self::SKIPPED_LIST_LIMIT );
		}
		return [ 'receipt' => $receipt, 'report' => $report, 'warnings' => $warnings ];
	}

	/**
	 * Store the settings and bring this request's rewrite state in line with them,
	 * without storing rules (see the class comment).
	 */
	public static function apply( array $before, array $after ): void {
		global $wp_rewrite;
		$kept = [
			'extra_rules'  => $wp_rewrite->extra_rules,
			'non_wp_rules' => $wp_rewrite->non_wp_rules,
			'endpoints'    => $wp_rewrite->endpoints,
		];
		$wp_rewrite->set_permalink_structure( $after['permalink_structure'] );
		$wp_rewrite->set_category_base( $after['category_base'] );
		$wp_rewrite->set_tag_base( $after['tag_base'] );
		// init() emptied what plugins added on init; this request still serves them.
		foreach ( $kept as $property => $value ) {
			$wp_rewrite->$property = $value;
		}
		self::rebase( $wp_rewrite, $before, $after );
		self::forget_rules();
	}

	/**
	 * Empty the stored rules, so the next request builds them with everything
	 * registered, and keep this request from storing a rebuild of its own.
	 */
	public static function forget_rules(): void {
		update_option( 'rewrite_rules', '' );
		if ( ! self::$rules_blocked ) {
			self::$rules_blocked = true;
			add_filter( 'pre_update_option_rewrite_rules', [ self::class, 'keep_rules_unstored' ], PHP_INT_MAX, 2 );
		}
	}

	/** @internal Filter: the stored value stays as it is. */
	public static function keep_rules_unstored( mixed $value, mixed $old_value ): mixed {
		return $old_value;
	}

	// ── Rollback ──────────────────────────────────────────────────────────

	private static function receipt_settings( mixed $value ): ?array {
		$value = IATO_MCP_Rollback_Conflict::json_array( $value );
		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $value ) || ! is_string( $value[ $key ] ) ) {
				return null;
			}
		}
		return array_intersect_key( $value, array_flip( self::KEYS ) );
	}

	public static function rollback_unavailable( array $receipt ): ?WP_Error {
		$before = self::receipt_settings( $receipt['before_value'] ?? null );
		if ( null === $before || '' === $before['permalink_structure'] ) {
			return new WP_Error( 'rollback_unavailable', 'This receipt holds no permalink settings to restore.', [ 'status' => 409 ] );
		}
		return null;
	}

	/** Conflict check: the settings as the write left them. */
	public static function conflict( array $receipt ): array {
		$after = self::receipt_settings( $receipt['after_value'] ?? null );
		if ( null === $after ) {
			return [ 'status' => 'unverifiable', 'reason' => 'the receipt does not hold the settings it set' ];
		}
		$current = self::current();
		if ( self::same( $current, $after ) ) {
			return [ 'status' => 'match', 'reason' => 'the permalink settings are as the change left them' ];
		}
		return [
			'status'   => 'conflict',
			'reason'   => 'the permalink settings have changed since this change was made',
			'current'  => $current,
			'expected' => $after,
		];
	}

	/** Undo the change: the redirects first, then the settings. */
	public static function rollback( array $receipt ): bool|WP_Error {
		$before = self::receipt_settings( $receipt['before_value'] ?? null );
		if ( null === $before ) {
			return new WP_Error( 'rollback_unavailable', 'This receipt holds no permalink settings to restore.', [ 'status' => 409 ] );
		}
		// A redirect left behind would send visitors from each restored URL to one that
		// no longer exists.
		$removal = IATO_MCP_Redirects::remove_all( IATO_MCP_URL_Move::receipt_redirects( $receipt['after_value'] ?? null ) );
		if ( [] !== $removal['failed'] ) {
			return new WP_Error(
				'rollback_failed',
				sprintf( '%d redirect(s) this change created could not be removed, so the permalink settings were left as they are. Run the rollback again.', count( $removal['failed'] ) ),
				[ 'status' => 500, 'failed' => array_slice( $removal['failed'], 0, 10 ) ]
			);
		}
		self::apply( self::current(), $before );
		$note = IATO_MCP_Redirects::removal_note( $removal );
		if ( null !== $note ) {
			IATO_MCP_Rollback::warn( $note );
		}
		$after   = IATO_MCP_Rollback_Conflict::json_array( $receipt['after_value'] ?? null );
		$restore = IATO_MCP_Redirects::restore_removed( is_array( $after['removed_redirects'] ?? null ) ? $after['removed_redirects'] : [] );
		if ( null !== $restore ) {
			IATO_MCP_Rollback::warn( $restore );
		}
		return true;
	}
}
