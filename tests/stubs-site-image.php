<?php
/**
 * IATO_MCP_Site_Image with WordPress's image calls replaced, so its rules can run
 * without files or an image library. The real ones run in tests/wp-env/site-icon-live.
 */

declare( strict_types=1 );

final class FakeSiteImage extends IATO_MCP_Site_Image {

	public const SIZES = [
		'site_icon-270' => [ 'width ' => 270, 'height' => 270, 'crop' => true ],
		'site_icon-192' => [ 'width ' => 192, 'height' => 192, 'crop' => true ],
		'site_icon-180' => [ 'width ' => 180, 'height' => 180, 'crop' => true ],
		'site_icon-64'  => [ 'width ' => 64, 'height' => 64, 'crop' => true ],
		'site_icon-32'  => [ 'width ' => 32, 'height' => 32, 'crop' => true ],
	];

	/** @var array<int,array> attachment id => read_image() */
	public array $images = [];
	/** @var string[] sizes make_sizes() was asked for */
	public array $made = [];
	/** @var string[] sizes it cannot make */
	public array $cannot_make = [];
	public int $logo = 0;
	/** @var array<string,int> Elementor control => saved attachment id */
	public array $kit = [];
	public bool $kit_write_fails = false;
	public int $kit_writes = 0;
	public string $active_theme = 'test-theme';

	public static function image( int $width, int $height, array $overrides = [] ): array {
		return $overrides + [
			'stored_type'  => 'image/png',
			'real_type'    => 'image/png',
			'has_file'     => true,
			'has_metadata' => true,
			'width'        => $width,
			'height'       => $height,
			'sizes'        => [ 'thumbnail' ],
		];
	}

	public function read_image( int $id ): array { return $this->images[ $id ]; }

	public function icon_sizes(): array { return self::SIZES; }

	public function make_sizes( int $id, array $names ): array {
		$this->made = array_merge( $this->made, $names );
		$made       = array_values( array_diff( $names, $this->cannot_make ) );
		if ( isset( $this->images[ $id ] ) ) {
			$this->images[ $id ]['sizes'] = array_merge( $this->images[ $id ]['sizes'], $made );
		}
		return $made;
	}

	public function image_url( int $id ): ?string {
		return isset( $this->images[ $id ] ) && null !== get_post( $id ) ? "https://example.test/{$id}.png" : null;
	}

	public function icon_url( int $size ): ?string {
		$id = (int) get_option( 'site_icon', 0 );
		return $id > 0 && null !== $this->image_url( $id ) ? "https://example.test/{$id}-{$size}.png" : null;
	}

	public function logo_id(): int { return $this->logo; }

	public function set_logo( int $id ): void { $this->logo = $id; }

	public function theme(): string { return $this->active_theme; }

	public function saved_in_kit( string $control ): ?int { return $this->kit[ $control ] ?? null; }

	public function write_kit_media( string $control, int $id ): bool {
		if ( $this->kit_write_fails ) {
			return false;
		}
		$this->kit[ $control ] = $id;
		++$this->kit_writes;
		return true;
	}
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
	function wp_delete_attachment( int $id, bool $force = false ): object|false {
		$post = get_post( $id );
		if ( ! $post ) {
			return false;
		}
		unset( IATO_Test_Roles::$posts[ $id ], IATO_Test_WP::$posts[ $id ] );
		return $post;
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $id ): string {
		$post = get_post( $id );
		return $post ? (string) ( $post->{$field} ?? '' ) : '';
	}
}
