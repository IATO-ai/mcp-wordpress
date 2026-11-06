<?php
/**
 * Post meta security policy — shared by every tool that reads or writes
 * arbitrary post_meta keys (get_post_meta, update_post_meta, set_page_settings,
 * set_featured_image, update_elementor_data inherit_settings_from).
 *
 * Two layers:
 *   1. Denylist  — credential / auth / capability keys. Hard-reject even with force=true.
 *   2. Allowlist — known-safe theme/builder/SEO prefixes plus any public (non-underscore) key.
 *                  Anything outside the allowlist requires force=true and surfaces a warning.
 *
 * The denylist matches without case (stripos); what is allowed matches exactly, because
 * another case of an allowed key would be a new row that captures later writes to it.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Meta_Policy {

	/**
	 * Case-insensitive substring patterns. If a key matches ANY of these,
	 * it is rejected on write and redacted on read — force=true does NOT
	 * override this list. These are the keys that, if leaked or rewritten,
	 * could meaningfully widen the blast radius of the connector.
	 */
	private const DENY_PATTERNS = [
		'session_tokens',
		'wp_user_level',
		'wp_capabilities',
		'wp_user_roles',
		'wp_2fa_',
		'_token',
		'_secret',
		'_api_key',
		'_password',
		'_credential',
		'_oauth_',
		'_jwt_',
		'_refresh_token_',
		'_elementor_element_cache', // Elementor's cached pages and their key (IATO_MCP_Element_Cache_Guard)
	];

	/**
	 * Known-safe prefixes. Keys starting with one of these can be written
	 * without force=true and are visible in default get_post_meta reads
	 * even when they begin with an underscore.
	 */
	private const ALLOW_PREFIXES = [
		'site-',           // Astra per-post overrides
		'ast-',            // Astra section-display overrides
		'footer-sml-',     // Astra footer layout
		'_elementor_',     // Elementor builder meta
		'_wp_page_template',
		'_thumbnail_id',
		'_yoast_',
		'_genesis_',
		'_kadence_',
		'_generate_',
		'rank_math_',
		'_seopress_',
	];

	/**
	 * The only `_elementor_` keys the generic meta tools (update_post_meta,
	 * set_page_settings, inherit_settings_from) may write. Every other key
	 * under that prefix holds builder data (the element tree, rendered HTML,
	 * CSS, SVG, caches) and must go through the Elementor tools, which
	 * sanitise what they store. An allowlist rather than a denylist, so a
	 * key added by a future Elementor release or an addon is refused by
	 * default. `_elementor_page_settings` values are sanitised on write.
	 */
	public const ELEMENTOR_WRITABLE = [ '_elementor_page_settings', '_elementor_edit_mode', '_elementor_template_type' ];

	public static function is_elementor_key( string $key ): bool {
		return 0 === stripos( $key, '_elementor_' );
	}

	/**
	 * Refuse `_elementor_` keys outside ELEMENTOR_WRITABLE. Runs after
	 * check_write(); set_page_settings is unaffected (its only Elementor key is
	 * `_elementor_page_settings`).
	 *
	 * @return true|WP_Error WP_Error('elementor_content_key')
	 */
	public static function check_elementor_meta_write( string $key ): bool|WP_Error {
		if ( ! self::is_elementor_key( $key ) || in_array( $key, self::ELEMENTOR_WRITABLE, true ) ) {
			return true;
		}
		return new WP_Error(
			'elementor_content_key',
			sprintf(
				'Meta key %s holds Elementor builder data and cannot be written with this tool. Use update_elementor_data for the element tree, update_elementor_widget / update_elementor_patch / set_widget_setting for widget settings, and set_page_settings for page settings.',
				$key
			),
			[ 'key' => $key, 'writable_elementor_keys' => self::ELEMENTOR_WRITABLE ]
		);
	}

	/**
	 * Sanitise an array/object value written under an `_elementor_` key
	 * (`_elementor_page_settings`): only values that differ from the stored
	 * ones are touched, by shape (IATO_MCP_Elementor_Sanitizer). Scalars are
	 * returned as given (the meta tools already run sanitize_text_field on
	 * strings).
	 *
	 * @return array{value:mixed,changed:string[],report:array}
	 */
	public static function sanitize_elementor_value( string $key, mixed $value, mixed $stored ): array {
		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return [ 'value' => $value, 'changed' => [], 'report' => [] ];
		}
		$value  = json_decode( (string) wp_json_encode( $value ), true );
		$stored = is_array( $stored ) ? $stored : ( is_object( $stored ) ? json_decode( (string) wp_json_encode( $stored ), true ) : null );
		if ( ! is_array( $value ) || ! class_exists( 'IATO_MCP_Elementor_Sanitizer' ) ) {
			return [ 'value' => $value, 'changed' => [], 'report' => [] ];
		}
		$r = IATO_MCP_Elementor_Sanitizer::diff_settings( $value, $stored, $key );
		return [ 'value' => $r['value'], 'changed' => $r['changed'], 'report' => $r['report'] ];
	}

	/**
	 * Keys an administrator alone may write (1.13.0): `_elementor_template_type`
	 * decides how Elementor treats a post (page, header, footer, single,
	 * archive, popup...), which is a site-structure change, not an edit.
	 */
	public const ADMIN_ONLY_KEYS = [ '_elementor_template_type' ];

	/** Capability a write of this key needs beyond edit_post, or null. */
	public static function capability_for_key( string $key ): ?string {
		return in_array( strtolower( $key ), self::ADMIN_ONLY_KEYS, true ) ? 'manage_options' : null;
	}

	/**
	 * Sanitise an array/object value written under a key that is not an
	 * Elementor key (public custom meta, theme and SEO plugin keys) (1.13.0):
	 * strings containing markup that this write changes go through
	 * wp_kses_post, the same rule as for Elementor values; values equal to the
	 * stored ones at the same path are kept verbatim, so markup a person put
	 * there is not altered. Plain string values are not handled here (the meta
	 * tools run sanitize_text_field on them).
	 *
	 * @return array{value:mixed,changed:string[],report:array}
	 */
	public static function sanitize_array_value( string $key, mixed $value, mixed $stored ): array {
		if ( ( ! is_array( $value ) && ! is_object( $value ) ) || ! class_exists( 'IATO_MCP_Elementor_Sanitizer' ) ) {
			return [ 'value' => $value, 'changed' => [], 'report' => [] ];
		}
		$value  = json_decode( (string) wp_json_encode( $value ), true );
		$stored = is_array( $stored ) ? $stored : ( is_object( $stored ) ? json_decode( (string) wp_json_encode( $stored ), true ) : null );
		if ( ! is_array( $value ) ) {
			return [ 'value' => $value, 'changed' => [], 'report' => [] ];
		}
		$changed = [];
		$report  = [];
		$value   = self::filter_changed_markup( $value, is_array( $stored ) ? $stored : null, [ $key ], $changed, $report );
		if ( count( $changed ) > IATO_MCP_Elementor_Sanitizer::MAX_PATHS ) {
			$more    = count( $changed ) - IATO_MCP_Elementor_Sanitizer::MAX_PATHS;
			$changed = array_merge( array_slice( $changed, 0, IATO_MCP_Elementor_Sanitizer::MAX_PATHS ), [ '… and ' . $more . ' more' ] );
		}
		return [ 'value' => $value, 'changed' => $changed, 'report' => IATO_MCP_Sanitize_Report::cap( $report ) ];
	}

	/**
	 * @param string[] $changed
	 * @param array    $report
	 */
	private static function filter_changed_markup( array $new, ?array $stored, array $path, array &$changed, array &$report ): array {
		foreach ( $new as $k => $v ) {
			$old  = ( null !== $stored && array_key_exists( $k, $stored ) ) ? $stored[ $k ] : null;
			$here = array_merge( $path, [ (string) $k ] );
			if ( is_array( $v ) ) {
				$new[ $k ] = self::filter_changed_markup( $v, is_array( $old ) ? $old : null, $here, $changed, $report );
				continue;
			}
			if ( ! is_string( $v ) || $v === $old || ! str_contains( $v, '<' ) ) {
				continue;
			}
			// A neutral path: only the markup rule applies (no URL or CSS rule by key name).
			$clean = (string) IATO_MCP_Elementor_Sanitizer::sanitize_value( $v, [ '_' ] );
			if ( $clean !== $v ) {
				$new[ $k ]  = $clean;
				$changed[] = implode( '/', $here );
				$report[]  = IATO_MCP_Sanitize_Report::entry( implode( '/', $here ), 'html', $v, $clean );
			}
		}
		return $new;
	}

	/**
	 * True if the key matches a denylist pattern. Case-insensitive.
	 */
	public static function is_denied( string $key ): bool {
		foreach ( self::DENY_PATTERNS as $needle ) {
			if ( false !== stripos( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True if the key is either non-underscore-prefixed (public custom meta)
	 * or starts with one of the known-safe builder/theme/SEO prefixes.
	 */
	public static function is_allowed_without_force( string $key ): bool {
		if ( '' === $key ) {
			return false;
		}
		// Public custom meta (does not start with underscore).
		if ( '_' !== $key[0] ) {
			return true;
		}
		foreach ( self::ALLOW_PREFIXES as $prefix ) {
			// Any case of an Elementor key goes on to check_elementor_meta_write(), which
			// gives the reason that helps.
			if ( 0 === strpos( $key, $prefix ) || self::is_elementor_key( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Gate write requests against both lists.
	 *
	 * @return true|WP_Error true on permit; WP_Error('meta_denied') for a denylist hit;
	 *                       WP_Error('meta_requires_force') for an allowlist miss when force=false.
	 */
	public static function check_write( string $key, bool $force ): bool|WP_Error {
		if ( '' === $key || '0' === $key ) { // WordPress stores nothing under either.
			return new WP_Error( 'invalid_meta_key', 'Meta key must not be empty or 0.' );
		}
		$slashes = self::backslash_error( $key );
		if ( null !== $slashes ) {
			return $slashes;
		}
		if ( self::is_denied( $key ) ) {
			return new WP_Error(
				'meta_denied',
				sprintf( 'Meta key %s is in the security denylist and cannot be written via MCP.', $key ),
				[ 'key' => $key ]
			);
		}
		if ( ! $force && ! self::is_allowed_without_force( $key ) ) {
			return new WP_Error(
				'meta_requires_force',
				sprintf( 'Meta key %s is not in the known-safe allowlist. Pass force=true to write it.', $key ),
				[ 'key' => $key ]
			);
		}
		if ( ! $force && preg_match( '/[^\x21-\x7E]/', $key ) ) {
			return new WP_Error(
				'meta_requires_force',
				sprintf( 'Meta key %s holds spaces or characters outside ASCII. Check that it is the key you mean, then pass force=true to write it.', $key ),
				[ 'key' => $key ]
			);
		}
		return true;
	}

	/**
	 * WordPress unslashes a meta key before looking it up, after every check here has
	 * read it, so a backslash makes it a different key, and force does not lift this.
	 */
	private static function backslash_error( string $key ): ?WP_Error {
		if ( false === strpos( $key, '\\' ) ) {
			return null;
		}
		return new WP_Error(
			'invalid_meta_key',
			sprintf( 'Meta key %s holds a backslash. WordPress removes it before looking the key up, so this write would reach another key. Nothing was written.', $key ),
			[ 'key' => $key ]
		);
	}

	/** The meta_key column's length. A longer key stores nothing. */
	public const MAX_KEY_LENGTH = 255;

	/**
	 * The check that needs the database: its collation decides which row a key reaches,
	 * and that can be a row with a different name (case, accents, spaces, look-alike
	 * characters), which these lists never saw (1.16.0). Asks instead of guessing,
	 * because the rules differ between collations.
	 *
	 * A key this post has not got yet would be a new row, which later writes of the real
	 * key would land in, leaving that key reading as empty: another spelling stored
	 * anywhere on the site is enough to refuse it.
	 *
	 * @return true|WP_Error WP_Error('meta_key_too_long'), WP_Error('meta_key_mismatch')
	 */
	public static function check_stored_key( int $post_id, string $key ): bool|WP_Error {
		$slashes = self::backslash_error( $key );
		if ( null !== $slashes ) {
			return $slashes;
		}
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $key ) : strlen( $key ) ) > self::MAX_KEY_LENGTH ) {
			return new WP_Error(
				'meta_key_too_long',
				sprintf( 'A meta key can be at most %d characters. Nothing was written.', self::MAX_KEY_LENGTH ),
				[ 'key' => $key ]
			);
		}
		global $wpdb;
		$stored = (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ) );
		foreach ( $stored as $row ) {
			if ( $row !== $key ) {
				return self::key_mismatch( $key, $row );
			}
		}
		if ( [] === $stored ) {
			$elsewhere = $wpdb->get_var( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_key AS BINARY) <> CAST(%s AS BINARY) LIMIT 1", $key, $key ) );
			if ( is_string( $elsewhere ) && $elsewhere !== $key ) {
				return self::key_mismatch( $key, $elsewhere );
			}
		}
		return true;
	}

	private static function key_mismatch( string $key, string $row ): WP_Error {
		return new WP_Error(
			'meta_key_mismatch',
			sprintf( 'The database treats meta key %1$s as the key %2$s already stored on this site, so this write would reach or take the place of that key. Use the stored key exactly. Nothing was written.', $key, $row ),
			[ 'key' => $key, 'stored_key' => $row ]
		);
	}

	/**
	 * Filter a full meta map for read responses.
	 *
	 * @param array<string,mixed> $meta_map
	 * @param bool                $include_protected If true, includes underscore-prefixed
	 *                                                keys regardless of the allowlist.
	 * @return array{0: array<string,mixed>, 1: string[]} [filtered_meta, redacted_keys]
	 */
	public static function redact_for_read( array $meta_map, bool $include_protected = false ): array {
		$out      = [];
		$redacted = [];
		foreach ( $meta_map as $key => $value ) {
			$key = (string) $key;
			if ( self::is_denied( $key ) ) {
				$redacted[] = $key;
				continue;
			}
			if ( ! $include_protected && '_' === ( $key[0] ?? '' ) && ! self::is_allowed_without_force( $key ) ) {
				// Underscore-prefixed key outside the known-safe allowlist — hide by default.
				continue;
			}
			$out[ $key ] = $value;
		}
		return [ $out, $redacted ];
	}
}
