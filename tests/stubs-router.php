<?php
/**
 * What IATO_MCP_Elementor_Router calls that no other test needs. Every function is guarded.
 */

declare( strict_types=1 );

final class IATO_Test_Router {
	/** @var array<string,int> URL with no query => post ID, for url_to_postid() */
	public static array $url_ids = [];
	/** @var array<string,int> author slug => user ID */
	public static array $authors = [];
	/** @var array<string,string> query var => taxonomy, beyond category and post_tag */
	public static array $taxonomies = [ 'genre' => 'genre' ];
	/** @var string[] post types with an archive */
	public static array $archives = [ 'book' ];
	/** @var array What a real query (a WP_Query given arguments) finds. */
	public static array $found_posts = [ 1 ];
	public static bool $plain_permalinks = false;
	/** @var array<int,array> queries that ran */
	public static array $ran = [];

	public static function reset(): void {
		self::$url_ids          = [];
		self::$authors          = [];
		self::$taxonomies       = [ 'genre' => 'genre' ];
		self::$archives         = [ 'book' ];
		self::$found_posts      = [ 1 ];
		self::$plain_permalinks = false;
		self::$ran              = [];
	}
}

if ( ! function_exists( 'url_to_postid' ) ) {
	// As core's: p=, page_id= and attachment_id= first, then the query is dropped, then a
	// home URL is the static front page whatever its query said.
	function url_to_postid( string $url ): int {
		if ( 1 === preg_match( '#[?&](p|page_id|attachment_id)=(\d+)#', $url, $values ) && (int) $values[2] > 0 ) {
			return (int) $values[2];
		}
		$bare = explode( '?', explode( '#', $url )[0] )[0];
		if ( rtrim( $bare, '/' ) === rtrim( home_url(), '/' ) && 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
			return (int) get_option( 'page_on_front' );
		}
		// The table SeoFixesSkippedTest declares, for when this stub loads first.
		return IATO_Test_Router::$url_ids[ $bare ] ?? ( [ 'https://site.test/about/' => 11, 'https://www.site.test/contact/' => 12, '/team/' => 13 ][ $url ] ?? 0 );
	}
}

// The rewrite rules of a site on /%postname%/, in core's order, and what WP::parse_request() reads.
if ( ! class_exists( 'WP_MatchesMapRegex' ) ) {
	class WP_MatchesMapRegex {
		public static function apply( string $subject, array $matches ): string {
			return (string) preg_replace_callback( '/\$matches\[([1-9][0-9]*)\]/', static fn( $m ) => urlencode( $matches[ (int) $m[1] ] ?? '' ), $subject );
		}
	}
}
if ( ! isset( $GLOBALS['wp_rewrite'] ) ) {
	$GLOBALS['wp_rewrite'] = new class {
		public bool $use_verbose_page_rules = true;
		public string $index = 'index.php';
		public function using_index_permalinks(): bool { return false; }
		public function wp_rewrite_rules(): array {
			return IATO_Test_Router::$plain_permalinks ? [] : [
				'category/(.+?)/page/?([0-9]{1,})/?$' => 'index.php?category_name=$matches[1]&paged=$matches[2]',
				'category/(.+?)/?$'                   => 'index.php?category_name=$matches[1]',
				'tag/([^/]+)/page/?([0-9]{1,})/?$'    => 'index.php?tag=$matches[1]&paged=$matches[2]',
				'tag/([^/]+)/?$'                      => 'index.php?tag=$matches[1]',
				'genre/([^/]+)/?$'                    => 'index.php?genre=$matches[1]',
				'books/?$'                            => 'index.php?post_type=book',
				'feed/?$'                             => 'index.php?&feed=feed',
				'search/(.+)/page/?([0-9]{1,})/?$'    => 'index.php?s=$matches[1]&paged=$matches[2]',
				'search/(.+)/?$'                      => 'index.php?s=$matches[1]',
				'author/([^/]+)/page/?([0-9]{1,})/?$' => 'index.php?author_name=$matches[1]&paged=$matches[2]',
				'author/([^/]+)/?$'                   => 'index.php?author_name=$matches[1]',
				'date/([0-9]{4})/([0-9]{1,2})/?$'     => 'index.php?year=$matches[1]&monthnum=$matches[2]',
				'([0-9]{4})/([0-9]{1,2})/?$'          => 'index.php?year=$matches[1]&monthnum=$matches[2]',
				'page/?([0-9]{1,})/?$'                => 'index.php?&paged=$matches[1]',
				'(.?.+?)/page/?([0-9]{1,})/?$'        => 'index.php?pagename=$matches[1]&paged=$matches[2]',
				'(.?.+?)(?:/([0-9]+))?/?$'            => 'index.php?pagename=$matches[1]&page=$matches[2]',
				'([^/]+)(?:/([0-9]+))?/?$'            => 'index.php?name=$matches[1]&page=$matches[2]',
			];
		}
	};
}
if ( ! isset( $GLOBALS['wp'] ) ) {
	$GLOBALS['wp'] = (object) [ 'public_query_vars' => [ 'm', 'p', 'posts', 'w', 'cat', 'withcomments', 'withoutcomments', 's', 'search', 'exact', 'sentence', 'calendar', 'page', 'paged', 'more', 'tb', 'pb', 'author', 'order', 'orderby', 'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'name', 'category_name', 'tag', 'feed', 'author_name', 'pagename', 'page_id', 'error', 'attachment', 'attachment_id', 'subpost', 'subpost_id', 'preview', 'robots', 'favicon', 'taxonomy', 'term', 'cpage', 'post_type', 'embed', 'genre' ] ];
}
if ( ! function_exists( 'wp_resolve_numeric_slug_conflicts' ) ) {
	function wp_resolve_numeric_slug_conflicts( array $query_vars = [] ): array { return $query_vars; }
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( string $page_path, string $output = 'OBJECT', string $post_type = 'page' ): ?object {
		$id = IATO_Test_Router::$url_ids[ rtrim( home_url(), '/' ) . '/' . trim( urldecode( $page_path ), '/' ) . '/' ] ?? 0;
		return $id > 0 && 'page' === ( IATO_Test_WP::$posts[ $id ]['post_type'] ?? '' ) ? (object) ( [ 'ID' => $id ] + IATO_Test_WP::$posts[ $id ] ) : null;
	}
}
if ( ! class_exists( 'WP_Query' ) ) {
	// The flags of core's WP_Query::parse_query() for the URLs the tests ask about, and
	// get_queried_object(), which is how core tells a missing term from an empty one.
	class WP_Query {
		public $query;
		public array $posts = [];
		public int $found_posts = 0;
		public int $max_num_pages = 0;
		public ?object $queried = null;
		public bool $is_single = false, $is_page = false, $is_attachment = false, $is_singular = false, $is_archive = false, $is_date = false, $is_author = false, $is_category = false, $is_tag = false, $is_tax = false, $is_post_type_archive = false, $is_search = false, $is_feed = false, $is_home = false, $is_posts_page = false, $is_paged = false, $is_embed = false, $is_trackback = false, $is_robots = false, $is_favicon = false, $is_sitemap = false;

		public function __construct( $query = '' ) {
			if ( ! empty( $query ) ) {
				IATO_Test_Router::$ran[] = $query;
				$this->parse_query( $query );
				$this->posts         = IATO_Test_Router::$found_posts;
				$this->found_posts   = count( $this->posts );
				$this->max_num_pages = $this->found_posts ? 1 : 0;
			}
		}

		public function parse_query( $query = '' ): void {
			$this->query = is_array( $query ) ? $query : [];
			$q           = $this->query;
			$pagename    = trim( (string) ( $q['pagename'] ?? '' ) );
			$page_id     = (int) ( $q['page_id'] ?? 0 );
			$front       = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			$posts_page  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;

			if ( ! empty( $q['robots'] ) ) {
				$this->is_robots = true;
			} elseif ( ! empty( $q['favicon'] ) ) {
				$this->is_favicon = true;
			} elseif ( ! empty( $q['attachment_id'] ) ) {
				$this->is_single = $this->is_attachment = true;
			} elseif ( '' !== trim( (string) ( $q['name'] ?? '' ) ) || ! empty( $q['p'] ) ) {
				$this->is_single = true;
			} elseif ( $page_id || '' !== $pagename ) {
				$this->is_page = true;
			} else {
				$this->is_search = isset( $q['s'] );
				$this->is_date   = ! empty( $q['year'] ) || ! empty( $q['monthnum'] ) || ! empty( $q['m'] );
				$this->is_category = ! empty( $q['cat'] ) || ! empty( $q['category_name'] );
				$this->is_tag    = ! empty( $q['tag'] ) || ! empty( $q['tag_id'] );
				foreach ( IATO_Test_Router::$taxonomies as $var => $taxonomy ) {
					$this->is_tax = $this->is_tax || ! empty( $q[ $var ] );
				}
				$this->is_author = ! empty( $q['author'] ) || ! empty( $q['author_name'] );
				$this->is_post_type_archive = in_array( $q['post_type'] ?? '', IATO_Test_Router::$archives, true );
				$this->is_archive = $this->is_date || $this->is_category || $this->is_tag || $this->is_tax || $this->is_author || $this->is_post_type_archive;
			}
			$this->is_feed     = ! empty( $q['feed'] );
			$this->is_embed    = ! empty( $q['embed'] );
			$this->is_paged    = ! empty( $q['paged'] );
			$this->is_singular = $this->is_single || $this->is_page || $this->is_attachment;
			if ( ! ( $this->is_singular || $this->is_archive || $this->is_search || $this->is_feed || $this->is_robots || $this->is_favicon ) ) {
				$this->is_home = true;
			}
			if ( $this->is_home && $front > 0 ) {
				$rest = $q;
				unset( $rest['embed'] );
				if ( isset( $rest['pagename'] ) && '' === $rest['pagename'] ) {
					unset( $rest['pagename'] );
				}
				if ( [] === array_diff( array_keys( $rest ), [ 'preview', 'page', 'paged', 'cpage' ] ) ) {
					$this->is_page = $this->is_singular = true;
					$this->is_home = false;
				}
			}
			if ( $posts_page > 0 && ( $page_id === $posts_page || ( '' !== $pagename && ( get_page_by_path( $pagename )->ID ?? 0 ) === $posts_page ) ) ) {
				$this->is_page = $this->is_singular = false;
				$this->is_home = $this->is_posts_page = true;
			}
		}

		public function get_queried_object(): ?object {
			$q = (array) $this->query;
			if ( $this->is_category || $this->is_tag ) {
				$taxonomy = $this->is_category ? 'category' : 'post_tag';
				$slug     = $this->is_category ? ( $q['category_name'] ?? '' ) : ( $q['tag'] ?? '' );
				$id       = $this->is_category ? (int) ( $q['cat'] ?? 0 ) : (int) ( $q['tag_id'] ?? 0 );
				foreach ( IATO_Test_Actor::$slugs[ $taxonomy ] ?? [] as $known => $term_id ) {
					if ( $known === $slug || $term_id === $id ) {
						return (object) [ 'term_id' => $term_id, 'slug' => $known, 'taxonomy' => $taxonomy ];
					}
				}
				return null;
			}
			if ( $this->is_tax ) {
				foreach ( IATO_Test_Router::$taxonomies as $var => $taxonomy ) {
					$term_id = IATO_Test_Actor::$slugs[ $taxonomy ][ $q[ $var ] ?? '' ] ?? null;
					if ( null !== $term_id ) {
						return (object) [ 'term_id' => $term_id, 'slug' => $q[ $var ], 'taxonomy' => $taxonomy ];
					}
				}
				return null;
			}
			if ( $this->is_post_type_archive ) {
				return (object) [ 'name' => $q['post_type'] ];
			}
			if ( $this->is_author ) {
				$id = (int) ( $q['author'] ?? 0 ) ?: ( IATO_Test_Router::$authors[ $q['author_name'] ?? '' ] ?? 0 );
				return $id > 0 ? (object) [ 'ID' => $id ] : null;
			}
			return null;
		}
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args ): array {
		$ids = [];
		foreach ( IATO_Test_WP::$posts as $id => $post ) {
			if ( ( $post['post_type'] ?? '' ) === ( $args['post_type'] ?? '' ) && ( $post['post_status'] ?? '' ) === ( $args['post_status'] ?? '' )
				&& array_key_exists( '_elementor_conditions', IATO_Test_WP::$meta[ $id ] ?? [] ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( array $args = [], string $output = 'names' ): array { return []; }
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( object $term ): string { return rtrim( home_url(), '/' ) . ( 'post_tag' === $term->taxonomy ? '/tag/' : '/category/' ) . $term->slug . '/'; }
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $id ): string|false { return IATO_Test_WP::$posts[ $id ]['post_status'] ?? false; }
}
