<?php
/**
 * SEO Plugin Adapter — reads and writes SEO meta regardless of which plugin is active.
 *
 * Priority order:
 *   1. Yoast SEO       (wordpress-seo/wp-seo.php)
 *   2. RankMath        (seo-by-rank-math/rank-math.php)
 *   3. SEOPress        (seopress/seopress.php)
 *   4. Fallback        (native WP title, empty description)
 *
 * Detection via is_plugin_active(). Result is cached in a static property
 * for the request lifetime — do not call is_plugin_active() in every read/write.
 *
 * Meta keys:
 *   Yoast:    _yoast_wpseo_title, _yoast_wpseo_metadesc
 *   RankMath: rank_math_title, rank_math_description
 *   SEOPress: _seopress_titles_title, _seopress_titles_desc
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_SEO_Adapter {

	/** @var string|null Detected plugin slug. Set once per request. */
	private static ?string $active_plugin = null;

	/**
	 * sanitize_text_field() without one of its steps (1.16.0): it removes anything shaped
	 * like %xx, which takes the front off SEO plugins' variables and encoded URLs:
	 * %%category%% became %tegory%%, %%date%% became %te%%. Recorded and restored too,
	 * since the reads went through it as well.
	 *
	 * @param mixed $value Non-scalars become '', as in core.
	 */
	public static function clean_text( mixed $value, bool $keep_newlines = false ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$percent = "\xEE\x80\x80"; // A private-use character: core leaves it alone.
		$value   = str_replace( '%', $percent, (string) $value );
		$value   = $keep_newlines ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		return str_replace( $percent, '%', $value );
	}

	/**
	 * Get SEO meta for a post.
	 *
	 * @param int $post_id
	 * @return array{ title: string, description: string, plugin: string }
	 */
	public static function get_meta( int $post_id ): array {
		$plugin    = self::detect_plugin();
		$title_key = self::title_key();
		$desc_key  = self::description_key();

		$title = $title_key
			? self::clean_text( get_post_meta( $post_id, $title_key, true ) )
			: self::clean_text( get_the_title( $post_id ) );

		$description = $desc_key
			? self::clean_text( get_post_meta( $post_id, $desc_key, true ) )
			: '';

		return [
			'title'       => $title,
			'description' => $description,
			'plugin'      => $plugin,
		];
	}

	/**
	 * Update the SEO title for a post.
	 *
	 * @param int    $post_id
	 * @param string $title
	 * @return true|WP_Error
	 */
	public static function update_title( int $post_id, string $title ): bool|WP_Error {
		$key = self::title_key();
		if ( null === $key ) {
			return self::no_plugin_error( 'title' );
		}

		$title = self::clean_text( $title );
		update_post_meta( $post_id, $key, wp_slash( $title ) ); // update_post_meta() unslashes

		return true;
	}

	/**
	 * Update the SEO meta description for a post.
	 *
	 * @param int    $post_id
	 * @param string $description
	 * @return true|WP_Error
	 */
	public static function update_description( int $post_id, string $description ): bool|WP_Error {
		$key = self::description_key();
		if ( null === $key ) {
			return self::no_plugin_error( 'description' );
		}

		$description = self::clean_text( $description, true );
		update_post_meta( $post_id, $key, wp_slash( $description ) );

		return true;
	}

	/**
	 * Why this field cannot be written, or null when it can (1.16.0: dry runs ask).
	 *
	 * @param string $field title or description
	 */
	public static function write_refusal( string $field ): ?WP_Error {
		return null === ( 'title' === $field ? self::title_key() : self::description_key() ) ? self::no_plugin_error( $field ) : null;
	}

	private static function no_plugin_error( string $field ): WP_Error {
		if ( 'title' === $field ) {
			return new WP_Error(
				'iato_mcp_no_seo_plugin',
				__( 'No supported SEO plugin is active. Install Yoast, RankMath, or SEOPress to manage SEO titles.', 'iato-mcp' )
			);
		}
		return new WP_Error(
			'iato_mcp_no_seo_plugin',
			__( 'No supported SEO plugin is active. Install Yoast, RankMath, or SEOPress to manage meta descriptions.', 'iato-mcp' )
		);
	}

	/**
	 * Detect and cache the active SEO plugin.
	 *
	 * @return string 'yoast'|'rankmath'|'seopress'|'fallback'
	 */
	private static function detect_plugin(): string {
		if ( self::$active_plugin !== null ) {
			return self::$active_plugin;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
			self::$active_plugin = 'yoast';
		} elseif ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
			self::$active_plugin = 'rankmath';
		} elseif ( is_plugin_active( 'seopress/seopress.php' ) ) {
			self::$active_plugin = 'seopress';
		} else {
			self::$active_plugin = 'fallback';
		}

		return self::$active_plugin;
	}

	/**
	 * Get the canonical URL for a post.
	 *
	 * @param int $post_id
	 * @return string Canonical URL or empty string if not set.
	 */
	public static function get_canonical( int $post_id ): string {
		$key = self::canonical_key();
		if ( null === $key ) {
			return '';
		}
		return self::clean_text( get_post_meta( $post_id, $key, true ) );
	}

	/**
	 * Update the canonical URL for a post.
	 *
	 * @param int    $post_id
	 * @param string $url
	 * @return true|WP_Error
	 */
	public static function update_canonical( int $post_id, string $url ): bool|WP_Error {
		$key = self::canonical_key();
		if ( null === $key ) {
			return new WP_Error(
				'iato_mcp_no_seo_plugin',
				__( 'No supported SEO plugin is active. Install Yoast, RankMath, or SEOPress to manage canonical URLs.', 'iato-mcp' )
			);
		}

		$url = esc_url_raw( $url );
		update_post_meta( $post_id, $key, wp_slash( $url ) );

		return true;
	}

	/**
	 * Delete the canonical URL meta for a post (used during rollback when before_value is null).
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function delete_canonical( int $post_id ): bool {
		$key = self::canonical_key();
		if ( null === $key ) {
			return false;
		}
		return delete_post_meta( $post_id, $key );
	}

	/**
	 * Get the meta key for canonical URL based on the active plugin.
	 *
	 * @return string|null Null for fallback (no canonical support).
	 */
	private static function canonical_key(): ?string {
		return match ( self::detect_plugin() ) {
			'yoast'    => '_yoast_wpseo_canonical',
			'rankmath' => 'rank_math_canonical_url',
			'seopress' => '_seopress_robots_canonical',
			default    => null,
		};
	}

	/**
	 * Get the meta key for SEO title based on the active plugin.
	 *
	 * @return string|null Null for fallback (uses WP post_title).
	 */
	private static function title_key(): ?string {
		return match ( self::detect_plugin() ) {
			'yoast'    => '_yoast_wpseo_title',
			'rankmath' => 'rank_math_title',
			'seopress' => '_seopress_titles_title',
			default    => null,
		};
	}

	/**
	 * Get the meta key for SEO description based on the active plugin.
	 *
	 * @return string|null Null for fallback (no description available).
	 */
	private static function description_key(): ?string {
		return match ( self::detect_plugin() ) {
			'yoast'    => '_yoast_wpseo_metadesc',
			'rankmath' => 'rank_math_description',
			'seopress' => '_seopress_titles_desc',
			default    => null,
		};
	}
}
