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
 * All matching is case-insensitive (stripos).
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
			if ( 0 === stripos( $key, $prefix ) ) {
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
		if ( '' === $key ) {
			return new WP_Error( 'invalid_meta_key', 'Meta key must not be empty.' );
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
		return true;
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
