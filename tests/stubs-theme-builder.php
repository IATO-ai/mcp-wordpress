<?php
/**
 * A fake IATO_MCP_Theme_Builder for the tool tests, set through set_instance(): posts
 * and meta go in the shared stores, and conditions in the fake Pro's cache
 * (tests/stubs-pro.php), so the router's theme_locations sees what it would on a site.
 * The registered conditions are what Elementor Pro 4.3.3 reported on the local Pro site.
 */

declare( strict_types=1 );

require_once __DIR__ . '/stubs-pro.php';

final class FakeThemeBuilder {
	public ?string $missing = null;
	public int $next_id     = 700;
	/** @var array<string,array{location:?string,supports_conditions:bool}>|null */
	public ?array $types = [
		'page'           => [ 'location' => null, 'supports_conditions' => false ],
		'section'        => [ 'location' => null, 'supports_conditions' => true ],
		'container'      => [ 'location' => null, 'supports_conditions' => false ],
		'loop-item'      => [ 'location' => null, 'supports_conditions' => false ],
		'single'         => [ 'location' => 'single', 'supports_conditions' => true ],
		'popup'          => [ 'location' => 'popup', 'supports_conditions' => true ],
		'header'         => [ 'location' => 'header', 'supports_conditions' => true ],
		'footer'         => [ 'location' => 'footer', 'supports_conditions' => true ],
		'single-post'    => [ 'location' => 'single', 'supports_conditions' => true ],
		'single-page'    => [ 'location' => 'single', 'supports_conditions' => true ],
		'archive'        => [ 'location' => 'archive', 'supports_conditions' => true ],
		'search-results' => [ 'location' => 'archive', 'supports_conditions' => true ],
		'error-404'      => [ 'location' => 'single', 'supports_conditions' => true ],
	];
	public array $config = [
		'general'               => [ 'sub' => [ 'archive', 'singular' ], 'takes_id' => false ],
		'archive'               => [ 'sub' => [ 'author', 'date', 'search', 'post_archive' ], 'takes_id' => false ],
		'author'                => [ 'sub' => [], 'takes_id' => true ],
		'date'                  => [ 'sub' => [], 'takes_id' => false ],
		'search'                => [ 'sub' => [], 'takes_id' => false ],
		'post_archive'          => [ 'sub' => [ 'category', 'child_of_category', 'any_child_of_category', 'post_tag' ], 'takes_id' => false ],
		'child_of_category'     => [ 'sub' => [], 'takes_id' => true ],
		'any_child_of_category' => [ 'sub' => [], 'takes_id' => true ],
		'category'              => [ 'sub' => [], 'takes_id' => true ],
		'post_tag'              => [ 'sub' => [], 'takes_id' => true ],
		'singular'              => [ 'sub' => [ 'front_page', 'post', 'page', 'child_of', 'not_found404' ], 'takes_id' => false ],
		'front_page'            => [ 'sub' => [], 'takes_id' => false ],
		'post'                  => [ 'sub' => [ 'in_category', 'post_by_author' ], 'takes_id' => true ],
		'page'                  => [ 'sub' => [ 'page_by_author' ], 'takes_id' => true ],
		'in_category'           => [ 'sub' => [], 'takes_id' => true ],
		'post_by_author'        => [ 'sub' => [], 'takes_id' => true ],
		'page_by_author'        => [ 'sub' => [], 'takes_id' => true ],
		'child_of'              => [ 'sub' => [], 'takes_id' => true ],
		'not_found404'          => [ 'sub' => [], 'takes_id' => false ],
	];
	/** @var string[] calls, in order */
	public array $calls = [];
	public ?WP_Error $create_error = null;
	public ?WP_Error $tree_error = null;
	public ?WP_Error $save_error = null;
	public ?WP_Error $regenerate_error = null;
	public array $tree_report = [ 'current_revision' => 'sha256:filled', 'elementor_save' => true, 'stored_by' => 'elementor' ];
	public bool $leave_out_of_cache = false;
	public array $locations = [];
	/** Pro stores the meta, then its cache rebuild fails. */
	public bool $save_then_stale = false;
	public bool $regenerate_throws = false;
	public ?WP_Error $preview_error = null;
	public array $preview_report = [];
	public int $previews = 0;
	/** @var string|null how wp_trash_post() fails: 'throw' or 'false' */
	public static ?string $trash_fails = null;
	public static bool $empty_tree_seed = false;

	public function missing_pro(): ?string { return $this->missing; }
	public function registered_types(): ?array { return $this->types; }
	public function conditions_config(): ?array { return $this->config; }

	public function create_document( string $type, string $title ): int|WP_Error {
		$this->calls[] = "create:{$type}";
		if ( null !== $this->create_error ) {
			return $this->create_error;
		}
		$id = $this->next_id++;
		$this->seed( $id, $type, $title, 'draft' );
		return $id;
	}

	public function seed( int $id, string $type, string $title = 'A template', string $status = 'publish', array $conditions = [] ): void {
		$post = [ 'post_type' => 'elementor_library', 'post_status' => $status, 'post_title' => $title, 'post_author' => 1, 'post_modified_gmt' => '2026-11-03 09:00:00', 'post_password' => '' ];
		IATO_Test_WP::$posts[ $id ]   = $post;
		IATO_Test_Roles::$posts[ $id ] = $post;
		IATO_Test_WP::$meta[ $id ]    = [ '_elementor_template_type' => $type, '_elementor_edit_mode' => 'builder', '_elementor_version' => '4.3.6' ];
		if ( 'draft' !== $status || [] !== $conditions ) {
			IATO_Test_WP::$meta[ $id ]['_elementor_data'] = '[{"id":"a1","elType":"container","settings":{},"elements":[]}]';
		}
		$this->locations[ $id ]       = $this->types[ $type ]['location'] ?? null;
		if ( [] !== $conditions ) {
			$this->save_conditions( $id, $conditions );
			$this->calls = array_values( array_diff( $this->calls, [ "conditions:{$id}", 'regenerate' ] ) );
		}
	}

	public function preview_tree( array $tree ): array|WP_Error {
		++$this->previews;
		return $this->preview_error ?? $this->preview_report;
	}

	public function store_tree( int $template_id, array $tree ): array|WP_Error {
		$this->calls[] = "tree:{$template_id}";
		if ( null !== $this->tree_error ) {
			return $this->tree_error;
		}
		IATO_Test_WP::$meta[ $template_id ]['_elementor_data'] = (string) json_encode( $tree );
		return $this->tree_report;
	}

	public function location_of( int $template_id ): ?string { return $this->locations[ $template_id ] ?? null; }
	public function location_allows_many( string $location ): bool { return 'popup' === $location; }

	public function save_conditions( int $template_id, array $conditions ): bool|WP_Error {
		$this->calls[] = "conditions:{$template_id}";
		if ( null !== $this->save_error ) {
			return $this->save_error;
		}
		if ( [] === $conditions ) {
			unset( IATO_Test_WP::$meta[ $template_id ]['_elementor_conditions'] );
		} else {
			IATO_Test_WP::$meta[ $template_id ]['_elementor_conditions'] = $conditions;
		}
		if ( $this->save_then_stale ) {
			return new WP_Error( 'cache_not_regenerated', "The conditions were saved, but Elementor Pro's conditions cache could not be rebuilt: it failed." );
		}
		$this->calls[] = 'regenerate';
		$this->rebuild_cache();
		return true;
	}

	/** Pro's cache: every template with conditions and a location, draft or not. */
	private function rebuild_cache(): void {
		$rows = [];
		foreach ( IATO_Test_WP::$meta as $id => $meta ) {
			$location = $this->locations[ $id ] ?? null;
			$status   = IATO_Test_WP::$posts[ $id ]['post_status'] ?? '';
			if ( null !== $location && ! empty( $meta['_elementor_conditions'] ) && in_array( $status, [ 'publish', 'draft' ], true ) && ! ( $this->leave_out_of_cache ) ) {
				$rows[ $location ][ $id ] = $meta['_elementor_conditions'];
			}
		}
		IATO_Test_Pro::$cache = $rows;
	}

	public function cached( string $location ): array { return IATO_Test_Pro::$cache[ $location ] ?? []; }

	public function regenerate(): bool|WP_Error {
		$this->calls[] = 'regenerate';
		if ( $this->regenerate_throws ) {
			throw new RuntimeException( 'an addon failed in the regeneration' );
		}
		if ( null !== $this->regenerate_error ) {
			return $this->regenerate_error;
		}
		$this->rebuild_cache();
		return true;
	}
}

// What the tools call that no other test has needed yet. Guarded, as every stub is.
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string { return 'http://site.test/wp-admin/' . $path; }
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( int $post_id ): object|false {
		if ( 'throw' === FakeThemeBuilder::$trash_fails ) {
			throw new RuntimeException( 'an addon failed in wp_trash_post' );
		}
		if ( 'false' === FakeThemeBuilder::$trash_fails || ! isset( IATO_Test_WP::$posts[ $post_id ] ) ) {
			return false;
		}
		IATO_Test_WP::$posts[ $post_id ]['post_status'] = 'trash';
		if ( isset( IATO_Test_Roles::$posts[ $post_id ] ) ) {
			IATO_Test_Roles::$posts[ $post_id ]['post_status'] = 'trash';
		}
		foreach ( IATO_Test_Pro::$cache as $location => $rows ) {
			unset( IATO_Test_Pro::$cache[ $location ][ $post_id ] ); // Pro's hook on wp_trash_post
		}
		return (object) [ 'ID' => $post_id ];
	}
}
