<?php
/**
 * Theme adapter — detects which page-level-settings theme is active (Astra,
 * Kadence, GeneratePress) and maps abstract setting names to the concrete
 * post_meta key/value pairs each theme uses.
 *
 * Mirrors IATO_MCP_SEO_Adapter's static-cache detection pattern.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Theme_Adapter {

	private static ?string $active_theme = null;

	/**
	 * Return one of: 'astra', 'kadence', 'generatepress', 'other'.
	 * Cached for the request lifetime.
	 */
	public static function detect(): string {
		if ( null !== self::$active_theme ) {
			return self::$active_theme;
		}
		$template = '';
		if ( function_exists( 'wp_get_theme' ) ) {
			$template = (string) wp_get_theme()->get_template();
		}
		if ( defined( 'ASTRA_THEME_VERSION' ) || 'astra' === $template ) {
			self::$active_theme = 'astra';
		} elseif ( defined( 'KADENCE_VERSION' ) || 'kadence' === $template ) {
			self::$active_theme = 'kadence';
		} elseif ( function_exists( 'generate_get_defaults' ) || 'generatepress' === $template ) {
			self::$active_theme = 'generatepress';
		} else {
			self::$active_theme = 'other';
		}
		return self::$active_theme;
	}

	public static function is_astra(): bool {
		return 'astra' === self::detect();
	}

	public static function is_kadence(): bool {
		return 'kadence' === self::detect();
	}

	public static function is_elementor_active(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Expand the abstract `settings` object accepted by set_page_settings
	 * into concrete (key, value, source) write tuples.
	 *
	 * Keys whose target theme is not active are routed to `skipped` instead
	 * of `writes` so the caller can surface them in the response.
	 *
	 * For `_elementor_page_settings`, the merge is left to the caller —
	 * this method returns a partial array { 'merge' => true, 'patch' => [...] }
	 * as the value, signalling that the existing meta value must be loaded,
	 * merged, and written back.
	 *
	 * @param array<string,mixed> $abstract Abstract settings object.
	 * @return array{writes: list<array{key: string, value: mixed}>, skipped: list<string>}
	 */
	public static function map_page_settings( array $abstract ): array {
		$writes  = [];
		$skipped = [];

		$astra      = self::is_astra();
		$elementor  = self::is_elementor_active();

		// Track partial Elementor patches so multiple abstract keys merge into
		// a single _elementor_page_settings write.
		$elementor_patch = [];

		foreach ( $abstract as $abs_key => $abs_value ) {
			switch ( $abs_key ) {
				case 'hide_title':
					$bool = (bool) $abs_value;
					if ( $astra ) {
						$writes[] = [
							'key'   => 'site-post-title',
							'value' => $bool ? 'disabled' : 'enabled',
						];
					} else {
						$skipped[] = 'hide_title:astra';
					}
					if ( $elementor ) {
						$elementor_patch['hide_title'] = $bool ? 'yes' : '';
					}
					break;

				case 'sidebar_layout':
					if ( $astra ) {
						$writes[] = [
							'key'   => 'site-sidebar-layout',
							'value' => self::normalize_layout_value( $abs_value, [ 'default', 'no-sidebar', 'left-sidebar', 'right-sidebar' ], 'default' ),
						];
					} else {
						$skipped[] = 'sidebar_layout';
					}
					break;

				case 'content_layout':
					if ( $astra ) {
						$writes[] = [
							'key'   => 'site-content-layout',
							'value' => self::normalize_layout_value( $abs_value, [ 'default', 'boxed', 'page-builder', 'plain-container' ], 'default' ),
						];
					} else {
						$skipped[] = 'content_layout';
					}
					break;

				case 'disable_header':
					if ( $astra ) {
						$writes[] = [
							'key'   => 'ast-main-header-display',
							'value' => $abs_value ? 'disabled' : '',
						];
					} else {
						$skipped[] = 'disable_header';
					}
					break;

				case 'disable_footer':
					if ( $astra ) {
						$writes[] = [
							'key'   => 'footer-sml-layout',
							'value' => $abs_value ? 'disabled' : '',
						];
					} else {
						$skipped[] = 'disable_footer';
					}
					break;

				case 'page_template':
					$writes[] = [
						'key'   => '_wp_page_template',
						'value' => sanitize_text_field( (string) $abs_value ),
					];
					break;

				case 'elementor_hide_title':
					if ( $elementor ) {
						$elementor_patch['hide_title'] = $abs_value ? 'yes' : '';
					} else {
						$skipped[] = 'elementor_hide_title';
					}
					break;

				case 'elementor_page_settings':
					if ( $elementor && is_array( $abs_value ) ) {
						foreach ( $abs_value as $inner_key => $inner_value ) {
							$elementor_patch[ sanitize_key( (string) $inner_key ) ] = $inner_value;
						}
					} else {
						$skipped[] = 'elementor_page_settings';
					}
					break;

				default:
					$skipped[] = (string) $abs_key;
					break;
			}
		}

		if ( ! empty( $elementor_patch ) ) {
			$writes[] = [
				'key'    => '_elementor_page_settings',
				'value'  => [ '__merge__' => true, '__patch__' => $elementor_patch ],
			];
		}

		return [ 'writes' => $writes, 'skipped' => $skipped ];
	}

	private static function normalize_layout_value( mixed $raw, array $allowed, string $fallback ): string {
		$val = is_string( $raw ) ? $raw : '';
		return in_array( $val, $allowed, true ) ? $val : $fallback;
	}
}
