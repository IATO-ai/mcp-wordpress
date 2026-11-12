<?php
/**
 * Page CSS (1.21.0): CSS for one post, printed in the head of that post only, for
 * get_page_css, set_page_css, the "Page assets" tab and rollback.
 *
 * The same rules as the theme's Additional CSS (IATO_MCP_Custom_CSS): CSS that
 * loads anything from another site is refused, anything that would close the style
 * element is removed and reported, and what is stored is what was sent. Because this
 * plugin prints it itself, as it is and never through strip_tags(), a @property
 * syntax descriptor may hold `<`.
 *
 * Who may write it: a WordPress user behind the credential holding edit_css outside
 * MCP (Administrators and Editors on a single site), and edit_post on the post.
 *
 * The CSS is checked again when it is printed, against a cache of hashes that passed,
 * so a value that reached the meta another way (another plugin, a database edit, a
 * pre-1.21.0 force write) never prints unchecked. The meta key is also in
 * IATO_MCP_Meta_Policy::DENY_PATTERNS, so no meta tool can write it.
 *
 * It depends on this plugin when the page renders: while the plugin is deactivated the
 * page loses it. Uninstall keeps it, so reinstalling brings it back.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_Page_CSS {

	public const META      = '_iato_mcp_page_css';
	public const RECEIPT   = 'page_css';
	public const FIELD     = 'css';
	public const MAX_BYTES = 204800; // 200 KB, as Additional CSS.

	/** Transient prefix for hashes that passed the check; a failure is cached too. */
	private const VERIFIED = 'iato_mcp_page_css_ok_';

	public static function init(): void {
		add_action( 'wp_head', [ self::class, 'print_css' ], 102 ); // after Additional CSS (101)
	}

	/** May this request write page CSS to this post? */
	public static function may_write( int $post_id ): bool|WP_Error {
		$bound = IATO_MCP_Auth::require_bound_user();
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}
		// The lock denies edit_css to every MCP request; this reads what the user holds outside it.
		$css_cap = IATO_MCP_Auth::write_unlocked( static fn() => IATO_MCP_Auth::require_cap( 'edit_css' ) );
		if ( is_wp_error( $css_cap ) ) {
			return $css_cap;
		}
		return IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
	}

	public static function stored( int $post_id ): string {
		$value = get_post_meta( $post_id, self::META, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * What would be stored for this request, or a refusal. $sent is `css` or
	 * `append_css`; only what is sent is judged, since what is stored passed already.
	 *
	 * @return array{css:string,report:array}|WP_Error
	 */
	public static function plan( int $post_id, string $sent, bool $append, string $path ): array|WP_Error {
		// First: judging a very large block takes a lot of memory.
		if ( strlen( $sent ) > self::MAX_BYTES ) {
			return new WP_Error( 'css_too_large', sprintf( 'This CSS is %1$d bytes, and the most is %2$d. Nothing was written.', strlen( $sent ), self::MAX_BYTES ), [ 'bytes' => strlen( $sent ), 'max_bytes' => self::MAX_BYTES ] );
		}
		$stored  = self::stored( $post_id );
		$clean   = IATO_MCP_Custom_CSS::strip_style_close( $sent );
		$refused = IATO_MCP_Custom_CSS::refused_parts( $clean, true );
		if ( [] !== $refused ) {
			return new WP_Error(
				'css_not_allowed',
				'This CSS cannot be stored, because of what is listed in found: page CSS may not load anything from another site. Host what it needs on this site, or load a font with set_page_fonts. Nothing was written.',
				[ 'found' => array_values( $refused ) ]
			);
		}
		if ( $append && ! IATO_MCP_Inline_Markup::css_is_readable( $stored ) ) {
			return new WP_Error( 'stored_css_unreadable', 'The page CSS already stored ends inside a comment, string or url(), so what is added would be read as part of it. Send the whole CSS with css instead. Nothing was written.' );
		}
		$css = $append && '' !== $stored ? $stored . "\n" . $clean : $clean;
		$css = IATO_MCP_Custom_CSS::strip_style_close( $css ); // in case the join makes one
		if ( strlen( $css ) > self::MAX_BYTES ) {
			return new WP_Error( 'css_too_large', sprintf( 'This would make the page CSS %1$d bytes, and the most is %2$d. Nothing was written.', strlen( $css ), self::MAX_BYTES ), [ 'bytes' => strlen( $css ), 'max_bytes' => self::MAX_BYTES ] );
		}
		$report = $clean !== $sent ? [ IATO_MCP_Sanitize_Report::entry( $path, 'css', $sent, $clean, [ 'removed_css' => [ '</style' ] ] ) ] : [];
		return [ 'css' => $css, 'report' => $report ];
	}

	/** Store it as it is; an empty value removes the meta. */
	public static function write( int $post_id, string $css ): bool {
		if ( '' === $css ) {
			delete_post_meta( $post_id, self::META );
			return true;
		}
		return false !== update_post_meta( $post_id, self::META, wp_slash( $css ) );
	}

	/** A receipt's before value: everything needed to put it back. */
	public static function before_value( int $post_id ): array {
		return [ 'css' => self::stored( $post_id ) ];
	}

	/** A receipt's after value, also what the conflict check reads. */
	public static function after_value( int $post_id ): array {
		$css = self::stored( $post_id );
		return [ 'bytes' => strlen( $css ), 'sha256' => hash( 'sha256', $css ) ];
	}

	/** A rollback's response: what came back, not all of it. */
	public static function restore_view( mixed $before ): array {
		$css = (string) ( IATO_MCP_Rollback_Conflict::json_array( $before )['css'] ?? '' );
		return [ 'bytes' => strlen( $css ), 'sha256' => hash( 'sha256', $css ) ];
	}

	public static function restore( int $post_id, mixed $before ): bool|WP_Error {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'rollback_unavailable', "Post {$post_id} no longer exists.", [ 'status' => 409 ] );
		}
		self::write( $post_id, (string) ( IATO_MCP_Rollback_Conflict::json_array( $before )['css'] ?? '' ) );
		return true;
	}

	/** Why this receipt cannot be rolled back, or null. */
	public static function rollback_unavailable( int $post_id ): ?WP_Error {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'rollback_unavailable', 'The post this CSS belonged to no longer exists.', [ 'status' => 409 ] );
		}
		$may = self::may_write( $post_id );
		return is_wp_error( $may ) ? $may : null;
	}

	/**
	 * Does this stored CSS pass the rules? Cached by hash, failures too, so the check
	 * runs once per version rather than on every page view.
	 */
	public static function passes( string $css ): bool {
		if ( '' === trim( $css ) ) {
			return false;
		}
		$key    = self::VERIFIED . hash( 'sha256', $css );
		$cached = get_transient( $key );
		if ( '1' === $cached || '0' === $cached ) {
			return '1' === $cached;
		}
		$ok = strlen( $css ) <= self::MAX_BYTES
			&& IATO_MCP_Custom_CSS::strip_style_close( $css ) === $css
			&& [] === IATO_MCP_Custom_CSS::refused_parts( $css, true );
		set_transient( $key, $ok ? '1' : '0', MONTH_IN_SECONDS );
		return $ok;
	}

	/** The post the request shows, or 0: the front page, a preview link and the editor preview included. */
	public static function queried_post_id(): int {
		$object = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		return $object instanceof WP_Post ? (int) $object->ID : 0;
	}

	/** wp_head: this post's CSS, after the theme's Additional CSS. */
	public static function print_css(): void {
		$post_id = self::queried_post_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$css = self::stored( $post_id );
		if ( '' === $css || ! self::passes( $css ) ) {
			return;
		}
		// Checked by passes(): nothing that closes the element, nothing off-site.
		echo '<style id="iato-mcp-page-css">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS checked by passes(); escaping would break it.
	}
}
