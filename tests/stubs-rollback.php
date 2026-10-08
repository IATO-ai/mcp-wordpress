<?php
/**
 * WordPress stubs for the rollback permission tests: a small role/capability
 * model that mirrors core's map_meta_cap() rules for edit_post, delete_post,
 * edit_term and delete_term closely enough to test object-level checks.
 */

declare( strict_types=1 );

final class IATO_Test_Roles {
	public const CAPS = [
		'subscriber'    => [ 'read' ],
		'contributor'   => [ 'read', 'edit_posts', 'delete_posts' ],
		'author'        => [ 'read', 'edit_posts', 'delete_posts', 'edit_published_posts', 'delete_published_posts', 'publish_posts', 'upload_files' ],
		'editor'        => [ 'read', 'edit_posts', 'delete_posts', 'edit_published_posts', 'delete_published_posts', 'publish_posts', 'upload_files', 'edit_others_posts', 'delete_others_posts', 'edit_private_posts', 'delete_private_posts', 'read_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'read_private_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'publish_pages', 'manage_categories', 'unfiltered_html' ],
		'administrator' => [ 'read', 'edit_posts', 'delete_posts', 'edit_published_posts', 'delete_published_posts', 'publish_posts', 'upload_files', 'edit_others_posts', 'delete_others_posts', 'edit_private_posts', 'delete_private_posts', 'read_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'read_private_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'publish_pages', 'manage_categories', 'unfiltered_html', 'manage_options', 'edit_theme_options' ],
	];
	/** @var array<int,string> user id => role */
	public static array $users = [];
	/** @var array<int,array{post_type:string,post_author:int,post_status:string}> */
	public static array $posts = [];
	/** @var array<int,string> term id => taxonomy */
	public static array $terms = [];

	public static function has( int $user_id, string $cap, array $args ): bool {
		$role = self::$users[ $user_id ] ?? null;
		if ( null === $role ) {
			return false;
		}
		$prims = self::CAPS[ $role ];
		switch ( $cap ) {
			case 'read_post':
				// Core: public status needs `read`, private needs read_private_*,
				// anything else (draft, pending, future) resolves to edit_post.
				$post = self::$posts[ (int) ( $args[0] ?? 0 ) ] ?? null;
				if ( ! $post ) {
					return false;
				}
				$kind = 'page' === $post['post_type'] ? 'pages' : 'posts';
				if ( 'publish' === $post['post_status'] ) {
					return in_array( 'read', $prims, true );
				}
				if ( 'private' === $post['post_status'] ) {
					return in_array( "read_private_{$kind}", $prims, true );
				}
				return self::has( $user_id, 'edit_post', $args );
			case 'edit_post':
			case 'delete_post':
				$post = self::$posts[ (int) ( $args[0] ?? 0 ) ] ?? null;
				if ( ! $post ) {
					return false; // core: do_not_allow for a missing post
				}
				$verb  = 'edit_post' === $cap ? 'edit' : 'delete';
				$kind  = 'page' === $post['post_type'] ? 'pages' : 'posts'; // attachments use the post caps
				$own   = (int) $post['post_author'] === $user_id;
				$need  = [];
				if ( ! $own ) {
					$need[] = "{$verb}_others_{$kind}";
				}
				if ( 'publish' === $post['post_status'] ) {
					$need[] = "{$verb}_published_{$kind}";
				} elseif ( 'private' === $post['post_status'] ) {
					$need[] = "{$verb}_private_{$kind}";
				}
				$need[] = "{$verb}_{$kind}";
				foreach ( $need as $c ) {
					if ( ! in_array( $c, $prims, true ) ) {
						return false;
					}
				}
				return true;
			case 'edit_term':
			case 'delete_term':
				return isset( self::$terms[ (int) ( $args[0] ?? 0 ) ] ) && in_array( 'manage_categories', $prims, true );
			default:
				return in_array( $cap, $prims, true );
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID;
		public function __construct( int $id ) { $this->ID = $id; }
		public function exists(): bool { return isset( IATO_Test_Roles::$users[ $this->ID ] ); }
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( mixed $user, string $cap, mixed ...$args ): bool {
		$id = $user instanceof WP_User ? $user->ID : (int) $user;
		return IATO_Test_Roles::has( $id, $cap, $args );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $id ): ?object {
		return isset( IATO_Test_Roles::$posts[ $id ] ) ? (object) ( [ 'ID' => $id ] + IATO_Test_Roles::$posts[ $id ] ) : null;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( int $id ): ?object {
		return isset( IATO_Test_Roles::$terms[ $id ] ) ? (object) [ 'term_id' => $id, 'taxonomy' => IATO_Test_Roles::$terms[ $id ] ] : null;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { return $text; }
}
