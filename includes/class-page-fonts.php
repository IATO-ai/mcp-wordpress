<?php
/**
 * Page fonts (1.21.0): font families loaded on one post only, through Elementor's own
 * font loader, for get_page_fonts, set_page_fonts, the "Page assets" tab and rollback.
 *
 * Families are checked against Elementor's list (\Elementor\Fonts::get_font_type(),
 * which includes Elementor Pro's custom and Adobe fonts) when they are set and again
 * when they are printed. Loading goes through Frontend::enqueue_font(), so "Load
 * Google Fonts Locally" is respected: with it on, the visitor's browser asks this
 * site, not Google. Elementor loads every weight of a family (all 18 variants), so
 * there is no weights option. Checked against Elementor 3.35.9, 4.2.2 and 4.3.4–4.3.6.
 *
 * It depends on this plugin when the page renders, like page CSS.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_Page_Fonts {

	public const META      = '_iato_mcp_page_fonts';
	public const RECEIPT   = 'page_fonts';
	public const FIELD     = 'fonts';
	public const MAX_FONTS = 10;

	/** @var callable|null Test seam: fn( string $family ): string|false, Elementor's font type. */
	private static $type_of = null;

	/** @var callable|null Test seam: fn(): bool, are Google fonts on in Elementor? */
	private static $google_on = null;

	/** @var callable|null Test seam: fn(): bool, Elementor's "Load Google Fonts Locally". */
	private static $local_setting = null;

	/** @var callable|null Test seam: fn(): array, the local copies Elementor holds (_elementor_local_google_fonts). */
	private static $local_copies = null;

	/** Elementor's font types that Google_Font serves, from Google or from a local copy. */
	private const GOOGLE_TYPES = [ 'googlefonts', 'earlyaccess' ];

	public static function init(): void {
		// wp_enqueue_scripts runs inside wp_head at priority 1; Elementor prints the
		// queued fonts at priority 7, so they reach the head.
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ], 20 );
	}

	/** Replace Elementor for unit tests; nulls restore it. */
	public static function set_elementor( ?callable $type_of, ?callable $google_on = null ): void {
		self::$type_of   = $type_of;
		self::$google_on = $google_on;
	}

	public static function elementor_ready(): bool {
		return null !== self::$type_of || ( class_exists( '\Elementor\Fonts' ) && class_exists( '\Elementor\Plugin' ) );
	}

	/** Elementor's type for a family (system, googlefonts, earlyaccess, custom, typekit, ...), or null. */
	public static function type_of( string $family ): ?string {
		if ( null !== self::$type_of ) {
			$type = ( self::$type_of )( $family );
		} else {
			$type = class_exists( '\Elementor\Fonts' ) ? \Elementor\Fonts::get_font_type( $family ) : false;
		}
		return is_string( $type ) && '' !== $type ? $type : null;
	}

	public static function google_fonts_on(): bool {
		if ( null !== self::$google_on ) {
			return (bool) ( self::$google_on )();
		}
		return class_exists( '\Elementor\Fonts' ) && method_exists( '\Elementor\Fonts', 'is_google_fonts_enabled' ) ? (bool) \Elementor\Fonts::is_google_fonts_enabled() : true;
	}

	/** Replace where Google fonts come from, for unit tests; nulls restore it. */
	public static function set_font_sources( ?callable $local_setting, ?callable $local_copies ): void {
		self::$local_setting = $local_setting;
		self::$local_copies  = $local_copies;
	}

	/** Elementor's "Load Google Fonts Locally" setting, read as Google_Font::enqueue() reads it. */
	public static function local_google_fonts_setting(): bool {
		if ( null !== self::$local_setting ) {
			return (bool) ( self::$local_setting )();
		}
		return (bool) get_option( 'elementor_local_google_fonts', '0' );
	}

	/**
	 * Where Elementor serves a family from (1.21.1), or null for one that is not a
	 * Google font. Google_Font::enqueue() first serves a local copy it already holds,
	 * whatever the setting says (turning the setting off does not delete them), and
	 * only then reads the setting: on, it downloads a copy on the first page view
	 * and uses Google's when that fails; off, Google's. The same in Elementor 4.2.2
	 * and 4.3.4. Until 1.21.1 the plugin read only the setting.
	 *
	 * @return string|null 'local', 'local_after_first_view' or 'google'.
	 */
	public static function served_from( string $family, ?string $type ): ?string {
		if ( ! in_array( $type, self::GOOGLE_TYPES, true ) ) {
			return null;
		}
		$copies = null !== self::$local_copies ? ( self::$local_copies )() : get_option( '_elementor_local_google_fonts', [] );
		$copy   = is_array( $copies ) ? ( $copies[ sanitize_key( $family ) ] ?? null ) : null;
		if ( is_array( $copy ) && ! empty( $copy['url'] ) ) {
			return 'local';
		}
		return self::local_google_fonts_setting() ? 'local_after_first_view' : 'google';
	}

	/**
	 * The fields get_page_fonts and set_page_fonts report about where fonts come from:
	 * `served_from` for each Google family, `google_fonts_local` (true when every
	 * Google family on the page is served from this site; with none, the setting, as
	 * before 1.21.1) and `local_google_fonts_setting`.
	 *
	 * @param array<string,string|null> $types Family => Elementor's type.
	 */
	public static function source_report( array $types ): array {
		$served = [];
		foreach ( $types as $family => $type ) {
			$from = self::served_from( (string) $family, $type );
			if ( null !== $from ) {
				$served[ (string) $family ] = $from;
			}
		}
		$setting = self::local_google_fonts_setting();
		return [
			'served_from'                => (object) $served,
			'google_fonts_local'         => [] === $served ? $setting : ! in_array( 'google', $served, true ),
			'local_google_fonts_setting' => $setting,
		];
	}

	/** @return string[] */
	public static function stored( int $post_id ): array {
		$value = get_post_meta( $post_id, self::META, true );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : [];
	}

	/**
	 * The list to store, each family with its type, or a refusal.
	 *
	 * @param mixed $fonts The `fonts` argument.
	 * @return array{fonts:string[],types:array<string,string>}|WP_Error
	 */
	public static function plan( mixed $fonts ): array|WP_Error {
		if ( ! is_array( $fonts ) ) {
			return new WP_Error( 'invalid_fonts', 'fonts must be a list of font family names. Nothing was written.' );
		}
		$list = [];
		foreach ( $fonts as $font ) {
			if ( ! is_string( $font ) || '' === trim( $font ) ) {
				return new WP_Error( 'invalid_fonts', 'Each font must be a family name, such as "JetBrains Mono". Nothing was written.' );
			}
			$font = trim( $font );
			if ( ! in_array( $font, $list, true ) ) {
				$list[] = $font;
			}
		}
		if ( count( $list ) > self::MAX_FONTS ) {
			return new WP_Error( 'too_many_fonts', sprintf( 'A page may load at most %d font families. Nothing was written.', self::MAX_FONTS ) );
		}
		$types   = [];
		$unknown = [];
		foreach ( $list as $font ) {
			$type = self::type_of( $font );
			if ( null === $type ) {
				$unknown[] = $font;
				continue;
			}
			$types[ $font ] = $type;
		}
		if ( [] !== $unknown ) {
			if ( ! self::google_fonts_on() ) {
				return new WP_Error(
					'google_fonts_disabled',
					'Elementor does not know these families: ' . implode( ', ', $unknown ) . '. Google Fonts are turned off in Elementor (Elementor > Settings > Advanced), so if they are Google fonts an administrator has to turn them on first. Nothing was written.',
					[ 'unknown' => $unknown ]
				);
			}
			return new WP_Error(
				'unknown_font',
				'Elementor does not know these families: ' . implode( ', ', $unknown ) . '. Use a family exactly as Elementor lists it in a typography control, or add it under Elementor > Custom Fonts (Elementor Pro). Nothing was written.',
				[ 'unknown' => $unknown ]
			);
		}
		return [ 'fonts' => $list, 'types' => $types ];
	}

	public static function write( int $post_id, array $fonts ): bool {
		if ( [] === $fonts ) {
			delete_post_meta( $post_id, self::META );
			return true;
		}
		return false !== update_post_meta( $post_id, self::META, wp_slash( array_values( $fonts ) ) );
	}

	public static function restore( int $post_id, mixed $before ): bool|WP_Error {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'rollback_unavailable', "Post {$post_id} no longer exists.", [ 'status' => 409 ] );
		}
		$fonts = IATO_MCP_Rollback_Conflict::json_array( $before );
		self::write( $post_id, array_values( array_filter( $fonts, 'is_string' ) ) );
		return true;
	}

	/** wp_enqueue_scripts: queue this post's fonts with Elementor, each checked again. */
	public static function enqueue(): void {
		$post_id = IATO_MCP_Page_CSS::queried_post_id();
		if ( $post_id <= 0 || ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		$fonts = self::stored( $post_id );
		if ( [] === $fonts ) {
			return;
		}
		$frontend = \Elementor\Plugin::$instance->frontend ?? null;
		if ( ! is_object( $frontend ) || ! method_exists( $frontend, 'enqueue_font' ) ) {
			return;
		}
		foreach ( array_slice( $fonts, 0, self::MAX_FONTS ) as $font ) {
			if ( null !== self::type_of( $font ) ) {
				$frontend->enqueue_font( $font );
			}
		}
	}
}
