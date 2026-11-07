<?php
/**
 * The theme's Additional CSS, for get_custom_css, update_custom_css and rollback (1.19.0).
 *
 * Since 1.13.0 no MCP request holds edit_css, because CSS printed on every page can
 * send data off-site if a client is tricked by injected instructions. So this tool
 * reads the capability the user holds outside MCP, as the inline markup opt-in does,
 * and refuses CSS that loads anything from another site, using the same tokenizer. It
 * stores exactly what was sent or refuses: it never rewrites. People can still put
 * anything in through the Customizer.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_Custom_CSS {

	public const MAX_BYTES   = 204800; // 200 KB.
	public const RECEIPT     = 'custom_css';
	private const CLOSES_TAG = '#</style(?=[\s/>]|$)[^>]*>?#i';

	/** The user's own capability, past the lock on MCP requests. */
	public static function may_write(): bool|WP_Error {
		$bound = IATO_MCP_Auth::require_bound_user();
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}
		$admin = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}
		return IATO_MCP_Auth::write_unlocked( static fn() => IATO_MCP_Auth::require_cap( 'edit_css' ) );
	}

	/**
	 * Take out whatever would close the style element. A browser ends it at </style
	 * followed by a space, / or >, and at the end of the CSS whatever comes next on the
	 * page can finish the tag. Repeated, because removing one can assemble another.
	 */
	public static function strip_style_close( string $css ): string {
		do {
			$before = $css;
			$css    = (string) preg_replace( self::CLOSES_TAG, '', $css );
		} while ( $before !== $css );
		return $css;
	}

	/**
	 * What in this CSS cannot be stored: off-site loads, and what could break out of the
	 * element or run script. Empty when there is nothing wrong, which includes CSS that
	 * is only comments.
	 *
	 * @return string[]
	 */
	public static function refused_parts( string $css ): array {
		return '' === trim( $css ) ? [] : IATO_MCP_Inline_Markup::sanitize_css( $css )['removed'];
	}

	/**
	 * WordPress below 7.1 prints this CSS through strip_tags(), comments included. What
	 * has been judged must be what is printed: taking a tag out of a comment can close the
	 * comment somewhere else and bring an off-site url() to life, and a less-than with no
	 * space after it loses everything up to the next greater-than.
	 */
	public static function printed_differently( string $css ): bool {
		return strip_tags( $css ) !== $css; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- The very function core prints with.
	}

	/** The CSS stored for a stylesheet, unfiltered, and its post. */
	public static function stored( string $stylesheet ): array {
		$post = wp_get_custom_css_post( $stylesheet );
		return [
			'post_id'      => $post ? (int) $post->ID : 0,
			'css'          => $post ? (string) $post->post_content : '',
			'preprocessed' => $post ? (string) $post->post_content_filtered : '', // A preprocessor plugin's source.
		];
	}

	/** A rollback's response says what came back, not all of it: up to 200 KB would swamp the transport. */
	public static function restore_view( mixed $before ): array {
		$value = IATO_MCP_Rollback_Conflict::json_array( $before );
		$css   = (string) ( $value['css'] ?? '' );
		return [
			'stylesheet' => (string) ( $value['stylesheet'] ?? '' ),
			'bytes'      => strlen( $css ),
			'sha256'     => hash( 'sha256', $css ),
		];
	}

	/** A receipt's before value: everything needed to put it back. */
	public static function before_value( string $stylesheet ): array {
		return [ 'stylesheet' => $stylesheet ] + self::stored( $stylesheet );
	}

	/** A receipt's after value, also what the conflict check reads. The CSS is in the before value of the next change. */
	public static function after_value( string $stylesheet ): array {
		$stored = self::stored( $stylesheet );
		return [ 'stylesheet' => $stylesheet, 'bytes' => strlen( $stored['css'] ), 'sha256' => hash( 'sha256', $stored['css'] ) ];
	}

	/**
	 * Save as sent. WordPress's content filter, on for every MCP request, would turn
	 * a > b into a &gt; b: that is why core's route is limited to users it does not
	 * apply to. Removed for this one write, and decided again under the lock afterwards.
	 */
	public static function write( string $stylesheet, string $css, string $preprocessed = '' ): int|WP_Error {
		kses_remove_filters();
		try {
			$saved = IATO_MCP_Auth::write_unlocked( static fn() => wp_update_custom_css_post( $css, [ 'stylesheet' => $stylesheet, 'preprocessed' => $preprocessed ] ) );
		} finally {
			kses_init();
		}
		return is_wp_error( $saved ) ? $saved : (int) $saved->ID;
	}

	/** Back as it was. If this stylesheet had no CSS post, the one the write created goes. */
	public static function restore( mixed $before ): bool|WP_Error {
		$value      = IATO_MCP_Rollback_Conflict::json_array( $before );
		$stylesheet = (string) ( $value['stylesheet'] ?? '' );
		if ( (int) ( $value['post_id'] ?? 0 ) <= 0 ) {
			$current = self::stored( $stylesheet );
			if ( $current['post_id'] > 0 ) {
				wp_delete_post( $current['post_id'], true );
			}
			return true;
		}
		$result = self::write( $stylesheet, (string) ( $value['css'] ?? '' ), (string) ( $value['preprocessed'] ?? '' ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/** Why this receipt cannot be rolled back, or null. */
	public static function rollback_unavailable( mixed $before ): ?WP_Error {
		if ( '' === (string) ( IATO_MCP_Rollback_Conflict::json_array( $before )['stylesheet'] ?? '' ) ) {
			return new WP_Error( 'rollback_unavailable', 'This receipt does not say which theme the CSS belonged to.', [ 'status' => 409 ] );
		}
		$may = self::may_write();
		return is_wp_error( $may ) ? $may : null;
	}
}
