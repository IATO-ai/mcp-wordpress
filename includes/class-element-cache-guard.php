<?php
/**
 * Elementor's element cache: internal data that stays on the server.
 *
 * Elementor keeps rendered pages in the `_elementor_element_cache` post meta,
 * with parts of them written as its own `[elementor-element]` shortcode and a
 * site-wide key. Both are Elementor's plumbing, and no client has a use for them.
 * Since 1.15.1 the key is not returned by any tool, writes cannot carry the
 * shortcode, and the key is replaced once on upgrade (IATO_MCP_Schema step 6).
 *
 * The check looks at the whole of a call's arguments before the handler runs and
 * refuses the call: it never edits a value, because removing text from markup
 * can join what is left into something else.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Element_Cache_Guard {

	public const KEY_OPTION = '_elementor_element_cache_unique_id';
	public const META_KEY   = '_elementor_element_cache';
	public const SHORTCODE  = '[elementor-element';
	public const SWEEP_HOOK = 'iato_mcp_element_cache_sweep';

	/** Seconds until the second pass: a render in progress at upgrade can store the old key. */
	public const SWEEP_DELAY = 900;

	/** Shorter option values are not treated as the key, so ordinary text is never replaced. */
	private const MIN_KEY_LENGTH = 16;

	private const REDACTED = '[redacted]';

	/** Deeper than any element tree; anything deeper is refused rather than read part-way. */
	private const MAX_DEPTH = 256;

	/** Layers of URL and entity encoding read before giving up. */
	private const MAX_DECODES = 4;

	public static function init(): void {
		add_action( self::SWEEP_HOOK, [ __CLASS__, 'sweep' ] );
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}
	}

	// ── Tool input ────────────────────────────────────────────────────────

	/**
	 * Do these tool arguments carry Elementor's cache shortcode, at any depth? A
	 * string is read as sent, without its tags, with URL and entity encoding
	 * removed, and as JSON when it holds JSON: update_elementor_data takes a whole
	 * tree as one string, and a JSON escape would otherwise hide the bracket.
	 */
	public static function holds_shortcode( mixed $value, int $depth = 0 ): bool {
		if ( $depth > self::MAX_DEPTH ) {
			return true;
		}
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::holds_shortcode( $item, $depth + 1 ) ) {
					return true;
				}
			}
			return false;
		}
		return is_string( $value ) && self::text_holds_shortcode( $value, $depth );
	}

	private static function text_holds_shortcode( string $text, int $depth ): bool {
		$seen = $text;
		for ( $i = 0; $i < self::MAX_DECODES; $i++ ) {
			if ( false !== stripos( $seen, self::SHORTCODE ) || false !== stripos( strip_tags( $seen ), self::SHORTCODE ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- detection only, wp_strip_all_tags also drops script bodies
				return true;
			}
			$next = rawurldecode( html_entity_decode( $seen, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( $next === $seen ) {
				break;
			}
			$seen = $next;
		}
		$trimmed = ltrim( $text );
		if ( '' !== $trimmed && ( '[' === $trimmed[0] || '{' === $trimmed[0] ) ) {
			$decoded = json_decode( $trimmed, true );
			if ( is_array( $decoded ) ) {
				return self::holds_shortcode( $decoded, $depth + 1 );
			}
		}
		return false;
	}

	/**
	 * Is this tool a read, by its name? A read stores nothing, so it may search for
	 * the shortcode: that is how a site finds one stored earlier. The same naming
	 * rule tests/CacheResponseTest.php holds every tool to.
	 */
	public static function is_read_tool( string $name ): bool {
		return 1 === preg_match( '/^(get|list|find|search|resolve)_/', $name );
	}

	/** The tool result for a refused call: JSON text, so the call log records the code. */
	public static function refusal(): array {
		return [
			'isError' => true,
			'content' => [ [
				'type' => 'text',
				'text' => (string) wp_json_encode( [
					'error'   => 'reserved_shortcode',
					'message' => 'This request contains [elementor-element], a shortcode Elementor reserves for its own cache. It cannot be written through MCP. Nothing was written.',
				] ),
			] ],
		];
	}

	// ── Tool output ───────────────────────────────────────────────────────

	/** Replace the key wherever it appears in a response. */
	public static function scrub( mixed $value ): mixed {
		$key = get_option( self::KEY_OPTION );
		if ( ! is_string( $key ) || strlen( $key ) < self::MIN_KEY_LENGTH ) {
			return $value;
		}
		return self::replace_key( $value, $key );
	}

	private static function replace_key( mixed $value, string $key ): mixed {
		if ( is_string( $value ) ) {
			return str_replace( $key, self::REDACTED, $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $item ) {
				$value[ $k ] = self::replace_key( $item, $key );
			}
		}
		return $value;
	}

	// ── Upgrade ───────────────────────────────────────────────────────────

	/**
	 * Schema step 6: a new key and no cached pages, so Elementor rebuilds each page
	 * once on its next view. update_option rather than deleting: Elementor creates
	 * a missing key without a lock, so two requests could each make their own. A
	 * site without a key has nothing to replace and needs no second pass. A replay
	 * (the schema option deleted by hand) makes one more key and one more rebuild,
	 * with the same end state.
	 */
	public static function refresh(): void {
		$key = get_option( self::KEY_OPTION );
		delete_post_meta_by_key( self::META_KEY );
		if ( ! is_string( $key ) || '' === $key ) {
			return;
		}
		update_option( self::KEY_OPTION, bin2hex( random_bytes( 16 ) ) );
		if ( false === wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_single_event( time() + self::SWEEP_DELAY, self::SWEEP_HOOK );
		}
	}

	/**
	 * The second pass: a render in progress during refresh() can store the old key,
	 * which prints nothing, so its dynamic widgets would stay blank until the cache
	 * expires, and a page cache could keep them. Clear both.
	 */
	public static function sweep(): void {
		delete_post_meta_by_key( self::META_KEY );
		if ( IATO_MCP_Cache::is_enabled() ) {
			IATO_MCP_Cache::purge( 'all' );
		}
	}
}
