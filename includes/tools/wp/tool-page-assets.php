<?php
/**
 * WP Tools (1.21.0): get_page_css, set_page_css, get_page_fonts, set_page_fonts,
 * create_preview_link. All five ship switched off in the Tools card
 * (IATO_MCP_Settings::DEFAULT_OFF_TOOLS): an administrator turns them on.
 *
 * See IATO_MCP_Page_CSS, IATO_MCP_Page_Fonts and IATO_MCP_Preview_Link.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

/** A post the caller may edit, or a refusal. */
function iato_mcp_page_asset_post( array $args ): WP_Post|WP_Error {
	$post_id = absint( $args['id'] ?? 0 );
	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}
	$post = get_post( $post_id );
	if ( ! $post || in_array( $post->post_type, [ 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset' ], true ) ) {
		return new WP_Error( 'not_found', 'Post not found.' );
	}
	$edit = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
	return is_wp_error( $edit ) ? $edit : $post;
}

IATO_MCP_Server::register_tool(
	'get_page_css',
	[
		'description' => 'Returns the CSS set_page_css stored for one post (printed in that post\'s head only), with bytes and sha256. Requires edit_post on the post.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Post or page ID (required).' ],
			],
			'required'   => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post = iato_mcp_page_asset_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$css = IATO_MCP_Page_CSS::stored( $post->ID );
		return IATO_MCP_Server::ok( [ 'id' => $post->ID, 'css' => $css, 'bytes' => strlen( $css ), 'sha256' => hash( 'sha256', $css ) ] );
	}
);

IATO_MCP_Server::register_tool(
	'set_page_css',
	[
		'description' => 'Sets CSS for one post or page, printed in its head only (after the theme\'s Additional CSS), on its own URL, on the front page when it is the front page, and on preview links. Use this instead of a <style> block in an HTML widget. This plugin prints it, so the page loses it while the plugin is deactivated (uninstall keeps it); a style block in an HTML widget does not depend on the plugin, so tell the site owner before choosing this. Send css to replace it all (an empty string removes it), or append_css to add to the end. At most 200 KB. CSS that loads anything from another site (@import, or url() to another host) is refused with css_not_allowed and nothing is stored: use set_page_fonts for fonts. Anything that would close the style element is removed and listed in sanitized_report. A @property syntax descriptor such as syntax: \'<number>\' is kept. Accepts dry_run. Returns sizes and hashes, a change_receipt and a cache object (that page is purged). Requires a WordPress user who may edit CSS (an administrator or editor) and edit_post on the post.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'         => [ 'type' => 'integer', 'description' => 'Post or page ID (required).' ],
				'css'        => [ 'type' => 'string', 'description' => 'The full replacement. An empty string removes the page CSS.' ],
				'append_css' => [ 'type' => 'string', 'description' => 'Added to the end of what is there.' ],
				'dry_run'    => [ 'type' => 'boolean', 'description' => 'Report what would change without writing (default: false).' ],
			],
			'required'   => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post = iato_mcp_page_asset_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$may = IATO_MCP_Page_CSS::may_write( $post->ID );
		if ( is_wp_error( $may ) ) {
			return $may;
		}
		$has_css    = array_key_exists( 'css', $args ) && null !== $args['css'];
		$has_append = array_key_exists( 'append_css', $args ) && null !== $args['append_css'];
		if ( $has_css === $has_append ) {
			return IATO_MCP_Server::refusal( new WP_Error( 'invalid_css_input', 'Send css to replace the page CSS, or append_css to add to it: one of them. Nothing was written.' ) );
		}
		$sent = $has_css ? $args['css'] : $args['append_css'];
		if ( ! is_string( $sent ) ) {
			return IATO_MCP_Server::refusal( new WP_Error( 'invalid_css_input', 'The CSS must be text. Nothing was written.' ) );
		}
		$plan = IATO_MCP_Page_CSS::plan( $post->ID, $sent, $has_append, $has_css ? 'css' : 'append_css' );
		if ( is_wp_error( $plan ) ) {
			return IATO_MCP_Server::refusal( $plan );
		}
		$stored = IATO_MCP_Page_CSS::stored( $post->ID );
		$data   = [ 'id' => $post->ID, 'bytes_before' => strlen( $stored ), 'bytes_after' => strlen( $plan['css'] ) ];

		if ( ! empty( $args['dry_run'] ) ) {
			$data = [ 'dry_run' => true, 'action' => $plan['css'] === $stored ? 'no_change' : 'would_update', 'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED ] + $data;
			IATO_MCP_Sanitize_Report::add_to( $data, $plan['report'] );
			return IATO_MCP_Server::ok( $data );
		}
		if ( $plan['css'] !== $stored ) {
			$before = IATO_MCP_Page_CSS::before_value( $post->ID );
			IATO_MCP_Page_CSS::write( $post->ID, $plan['css'] );
			$after   = IATO_MCP_Page_CSS::after_value( $post->ID );
			$receipt = IATO_MCP_Change_Receipt::record( $post->ID, IATO_MCP_Page_CSS::RECEIPT, IATO_MCP_Page_CSS::FIELD, $before, $after );

			$data['bytes_after']    = $after['bytes'];
			$data['sha256_before']  = hash( 'sha256', $stored );
			$data['sha256_after']   = $after['sha256'];
			$data['change_receipt'] = IATO_MCP_Change_Receipt::slim( $receipt );
			if ( hash( 'sha256', $plan['css'] ) !== $after['sha256'] ) {
				$data['warnings'] = [ 'WordPress or another plugin changed this CSS as it was saved. Read it back with get_page_css.' ];
			}
		}
		IATO_MCP_Sanitize_Report::add_to( $data, $plan['report'] );
		return IATO_MCP_Server::ok( $data );
	}
);

IATO_MCP_Server::register_tool(
	'get_page_fonts',
	[
		'description' => 'Returns the font families set_page_fonts loads on one post, each with Elementor\'s type for it, and whether Elementor loads Google fonts from this site. Requires edit_post on the post.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Post or page ID (required).' ],
			],
			'required'   => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post = iato_mcp_page_asset_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$fonts = IATO_MCP_Page_Fonts::stored( $post->ID );
		$types = [];
		foreach ( $fonts as $font ) {
			$types[ $font ] = IATO_MCP_Page_Fonts::type_of( $font );
		}
		return IATO_MCP_Server::ok( [ 'id' => $post->ID, 'fonts' => $fonts, 'types' => (object) $types, 'google_fonts_local' => IATO_MCP_Page_Fonts::local_google_fonts() ] );
	}
);

IATO_MCP_Server::register_tool(
	'set_page_fonts',
	[
		'description' => 'Loads font families on one post or page only, through Elementor\'s own font loader, so a page\'s CSS can use a font the site does not load elsewhere. Each family must be one Elementor knows (its Google fonts, system fonts, or Elementor Pro custom and Adobe fonts), spelled as Elementor lists it: unknown_font otherwise. With Elementor\'s "Load Google Fonts Locally" on, visitors fetch them from this site, not Google. Elementor loads every weight of a family, so there is no weights option. fonts replaces the list (at most 10; [] removes it). This plugin loads them, so the page loses them while the plugin is deactivated. Accepts dry_run. Returns the list with each family\'s type, a change_receipt and a cache object (that page is purged). Requires Elementor and edit_post on the post.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer', 'description' => 'Post or page ID (required).' ],
				'fonts'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Font family names, such as ["JetBrains Mono"] (required). [] removes them.' ],
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Report what would change without writing (default: false).' ],
			],
			'required'   => [ 'id', 'fonts' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post = iato_mcp_page_asset_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! IATO_MCP_Page_Fonts::elementor_ready() ) {
			return IATO_MCP_Server::refusal( new WP_Error( 'elementor_required', 'Page fonts load through Elementor\'s font loader, and Elementor is not active. Nothing was written.' ) );
		}
		$plan = IATO_MCP_Page_Fonts::plan( $args['fonts'] ?? null );
		if ( is_wp_error( $plan ) ) {
			return IATO_MCP_Server::refusal( $plan );
		}
		$stored = IATO_MCP_Page_Fonts::stored( $post->ID );
		$data   = [ 'id' => $post->ID, 'fonts' => $plan['fonts'], 'types' => (object) $plan['types'], 'google_fonts_local' => IATO_MCP_Page_Fonts::local_google_fonts() ];
		$notes  = [];
		foreach ( $plan['types'] as $font => $type ) {
			if ( 'system' === $type ) {
				$notes[] = "{$font} is a system font: nothing is loaded for it, browsers use their own.";
			}
		}
		if ( $notes ) {
			$data['notes'] = $notes;
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return IATO_MCP_Server::ok( [ 'dry_run' => true, 'action' => $plan['fonts'] === $stored ? 'no_change' : 'would_update', 'fonts_before' => $stored, 'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED ] + $data );
		}
		if ( $plan['fonts'] !== $stored ) {
			IATO_MCP_Page_Fonts::write( $post->ID, $plan['fonts'] );
			$receipt                = IATO_MCP_Change_Receipt::record( $post->ID, IATO_MCP_Page_Fonts::RECEIPT, IATO_MCP_Page_Fonts::FIELD, $stored, IATO_MCP_Page_Fonts::stored( $post->ID ) );
			$data['fonts_before']   = $stored;
			$data['change_receipt'] = IATO_MCP_Change_Receipt::slim( $receipt );
		}
		return IATO_MCP_Server::ok( $data );
	}
);

IATO_MCP_Server::register_tool(
	'create_preview_link',
	[
		'description' => 'Makes a link that shows one draft, pending or scheduled post to anyone who has it, without a WordPress login, so you can look at your own work before it is published. The link expires after ttl_seconds (default 900, at most 3600) and stops working once the post is published or trashed; roll back its receipt to revoke it sooner. Responses to it are not cached and carry noindex. The token is in the URL, so do not post the link anywhere public: access logs and analytics on the page record it. Accepts dry_run. Requires edit_post on the post and permission to publish it.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'integer', 'description' => 'Post or page ID (required).' ],
				'ttl_seconds' => [ 'type' => 'integer', 'description' => 'How long the link works, in seconds: 60 to 3600 (default 900).' ],
				'dry_run'     => [ 'type' => 'boolean', 'description' => 'Check without making a link (default: false).' ],
			],
			'required'   => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		$may = IATO_MCP_Preview_Link::may_create( $post_id );
		if ( is_wp_error( $may ) ) {
			return in_array( $may->get_error_code(), [ 'preview_not_needed', 'preview_not_supported' ], true ) ? IATO_MCP_Server::refusal( $may ) : $may;
		}
		$ttl = IATO_MCP_Preview_Link::ttl( $args['ttl_seconds'] ?? null );
		if ( is_wp_error( $ttl ) ) {
			return IATO_MCP_Server::refusal( $ttl );
		}
		if ( ! empty( $args['dry_run'] ) ) {
			return IATO_MCP_Server::ok( [ 'dry_run' => true, 'action' => 'would_create', 'id' => $post_id, 'ttl_seconds' => $ttl, 'live_links' => count( IATO_MCP_Preview_Link::links( $post_id ) ), 'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED ] );
		}
		$link = IATO_MCP_Preview_Link::create( $post_id, $ttl );
		if ( is_wp_error( $link ) ) {
			return IATO_MCP_Server::refusal( $link );
		}
		// Never the token: the receipt names the link so its rollback can revoke it.
		$receipt = IATO_MCP_Change_Receipt::record( $post_id, IATO_MCP_Preview_Link::RECEIPT, IATO_MCP_Preview_Link::FIELD, null, [ 'link_id' => $link['link_id'], 'expires' => $link['expires'] ] );
		return IATO_MCP_Server::ok(
			[
				'id'             => $post_id,
				'url'            => $link['url'],
				'expires_at'     => gmdate( 'c', $link['expires'] ),
				'ttl_seconds'    => $ttl,
				'post_status'    => get_post_status( $post_id ),
				'note'           => 'Anyone with this URL can see the post until it expires. Roll back change_receipt to revoke it.',
				'change_receipt' => IATO_MCP_Change_Receipt::slim( $receipt ),
			]
		);
	}
);
