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
	 * Get SEO meta for a post.
	 *
	 * @param int $post_id
	 * @return array{ title: string, description: string, plugin: string }
	 */
	public static function get_meta( int $post_id ): array {
		// TODO: implement — detect plugin, read appropriate post meta keys.
		// Return ['title' => '', 'description' => '', 'plugin' => 'yoast|rankmath|seopress|fallback']
		return [ 'title' => '', 'description' => '', 'plugin' => 'not_implemented' ];
	}

	/**
	 * Update the SEO title for a post.
	 *
	 * @param int    $post_id
	 * @param string $title
	 * @return true|WP_Error
	 */
	public static function update_title( int $post_id, string $title ): true|WP_Error {
		// TODO: implement — sanitize_text_field, update_post_meta with correct key
		return new WP_Error( 'not_implemented', 'update_title not yet implemented' );
	}

	/**
	 * Update the SEO meta description for a post.
	 *
	 * @param int    $post_id
	 * @param string $description
	 * @return true|WP_Error
	 */
	public static function update_description( int $post_id, string $description ): true|WP_Error {
		// TODO: implement — sanitize_textarea_field, update_post_meta with correct key
		return new WP_Error( 'not_implemented', 'update_description not yet implemented' );
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
		// TODO: implement — is_plugin_active() checks in priority order
		self::$active_plugin = 'fallback';
		return self::$active_plugin;
	}
}
