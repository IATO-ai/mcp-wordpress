<?php
/**
 * Plugin Name: IATO MCP
 * Plugin URI:  https://iato.ai/wordpress-mcp
 * Description: Exposes an MCP server from any self-hosted WordPress install, enabling IATO analyze-and-fix workflows via Claude Desktop and other AI clients.
 * Version:     1.6.0
 * Author:      IATO
 * Author URI:  https://iato.ai
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: iato-mcp
 * Requires at least: 6.2
 * Requires PHP: 8.0
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

define( 'IATO_MCP_VERSION', '1.6.0' );
define( 'IATO_MCP_FILE', __FILE__ );
define( 'IATO_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'IATO_MCP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Return the IATO logo as an inline <img> tag using a base64 data URI.
 *
 * Some hosts block direct access to PNG files in the plugins directory,
 * so we embed the logo to guarantee it always renders.
 *
 * @param int $height Height attribute in pixels (default 36).
 * @return string <img> markup.
 */
function iato_mcp_logo_svg( int $height = 36 ): string {
	static $data_uri = null;
	if ( null === $data_uri ) {
		$path = IATO_MCP_DIR . 'assets/img/logo.png';
		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data_uri = 'data:image/png;base64,' . base64_encode( file_get_contents( $path ) );
		} else {
			$data_uri = '';
		}
	}
	if ( '' === $data_uri ) {
		return '<span style="font-weight:700;">IATO</span>';
	}
	return '<img src="' . esc_attr( $data_uri ) . '" alt="IATO" height="' . esc_attr( $height ) . '" style="vertical-align:middle;" />';
}

// Core classes
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/class-iato-client.php';
require_once IATO_MCP_DIR . 'includes/class-seo-adapter.php';
require_once IATO_MCP_DIR . 'includes/class-theme-adapter.php';
require_once IATO_MCP_DIR . 'includes/class-meta-policy.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-call-log.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-oauth.php';
require_once IATO_MCP_DIR . 'includes/class-settings.php';
require_once IATO_MCP_DIR . 'includes/class-setup-wizard.php';
require_once IATO_MCP_DIR . 'includes/class-diagnostics.php';
require_once IATO_MCP_DIR . 'includes/class-mcp-server.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-adapter.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-router.php';
require_once IATO_MCP_DIR . 'includes/class-media-uploader.php';

// Phase 1 — WP native tools
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-site.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-posts.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-seo.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-media.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-comments.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-menus.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-taxonomy.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-builder.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-elementor-widgets.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-elementor-bulk.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-elementor-helpers.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-resolve-url.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-canonical.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-structured-data.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-redirects.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-rollback.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-post-meta.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-settings.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-featured-image.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-media-upload.php';

// Phase 2 — IATO bridge tools (loaded only when IATO API key is configured)
if ( get_option( 'iato_mcp_api_key', '' ) !== '' ) {
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-sitemap.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-nav-audit.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-orphans.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-taxonomy.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-seo-fixes.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-content-gaps.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-broken-links.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-suggestions.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-perf.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-start-crawl.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-crawl-status.php';
	require_once IATO_MCP_DIR . 'includes/tools/bridge/tool-list-crawls.php';
}

/**
 * Boot the plugin after all plugins are loaded.
 */
function iato_mcp_init() {
	IATO_MCP_OAuth::init();
	IATO_MCP_Settings::init();
	IATO_MCP_Server::init();
	IATO_MCP_Rollback::init();
	IATO_MCP_Setup_Wizard::init();
	IATO_MCP_Diagnostics::init();
	iato_mcp_maybe_run_migrations();
}
add_action( 'plugins_loaded', 'iato_mcp_init' );

/**
 * Run idempotent one-shot migrations gated by stored db_version.
 *
 * Activation hooks don't fire on plugin update, so any post-install
 * data fix-ups have to live here. Each migration block compares against
 * iato_mcp_db_version and bumps the option on success.
 */
function iato_mcp_maybe_run_migrations() {
	$db_version = get_option( 'iato_mcp_db_version', '0' );

	// 1.3.5: append v2 tool names to iato_mcp_tools so existing installs
	// upgrading from 1.2.x don't see the new Elementor v2 tools auto-disabled
	// (is_tool_enabled() returns false for any name not in the saved array).
	if ( version_compare( $db_version, '1.3.5', '<' ) ) {
		$saved = get_option( 'iato_mcp_tools', null );
		if ( is_array( $saved ) && ! empty( $saved ) ) {
			$new_v2 = [
				'list_elementor_widgets',
				'get_elementor_widget',
				'update_elementor_widget',
				'update_elementor_patch',
				'update_elementor_widgets_bulk',
				'find_elementor_widgets',
				'set_heading_level',
				'set_widget_setting',
				'resolve_url',
			];
			$missing = array_diff( $new_v2, $saved );
			if ( ! empty( $missing ) ) {
				update_option( 'iato_mcp_tools', array_values( array_merge( $saved, $missing ) ), false );
			}
		}
	}

	// 1.4.0: append `rollback` to iato_mcp_tools so existing installs don't see
	// the new tool auto-disabled by the per-tool toggle gate.
	if ( version_compare( $db_version, '1.4.0', '<' ) ) {
		$saved = get_option( 'iato_mcp_tools', null );
		if ( is_array( $saved ) && ! empty( $saved ) && ! in_array( 'rollback', $saved, true ) ) {
			$saved[] = 'rollback';
			update_option( 'iato_mcp_tools', array_values( $saved ), false );
		}
	}

	// 1.4.5: re-restore `rollback` to iato_mcp_tools for installs where it was
	// stripped by sanitize_tools() between 1.4.0 and 1.4.5 (TOOL_NAMES was missing
	// it, so any user-triggered Settings save would array_intersect it out).
	// Idempotent — no-op for installs that didn't lose it.
	if ( version_compare( $db_version, '1.4.5', '<' ) ) {
		$saved = get_option( 'iato_mcp_tools', null );
		if ( is_array( $saved ) && ! empty( $saved ) && ! in_array( 'rollback', $saved, true ) ) {
			$saved[] = 'rollback';
			update_option( 'iato_mcp_tools', array_values( $saved ), false );
		}
	}

	if ( version_compare( $db_version, IATO_MCP_VERSION, '<' ) ) {
		update_option( 'iato_mcp_db_version', IATO_MCP_VERSION, false );
	}
}

/**
 * Build a unique-per-site mcpServers inner key for the JSON config snippets.
 *
 * Agencies often manage many WordPress installs from a single AI client. If
 * every install emitted the same hardcoded "iato-wordpress" key in its config
 * snippet, pasting two of them into the same Claude Desktop config file would
 * silently overwrite (JSON object keys are unique). Deriving the key from the
 * site's hostname makes each install's snippet naturally distinct.
 *
 * @return string e.g. "iato-garennebigby-dev", "iato-dynomapper-com".
 */
function iato_mcp_connection_name(): string {
	$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'wordpress';
	return 'iato-' . sanitize_title( $host );
}

/**
 * Detect which page-builder plugins are active site-wide.
 *
 * Different from get_page_builder (per-post detection): this answers
 * "which plugins are installed and active on this WordPress install?" using
 * canonical PHP signals, so the MCP server's initialize response can build
 * builder-aware safety instructions before any tool is called.
 *
 * @return array<string,bool> map of builder slug => active flag.
 */
function iato_mcp_detect_active_builders(): array {
	return [
		'elementor' => defined( 'ELEMENTOR_VERSION' ),
		'divi'      => function_exists( 'et_setup_theme' ),
		'wpbakery'  => defined( 'WPB_VC_VERSION' ),
		'beaver'    => class_exists( 'FLBuilder' ),
		'gutenberg' => true, // always available in WP 5+
	];
}

/**
 * Build the dynamic instructions string injected into the MCP initialize response.
 *
 * Always includes the universal "check first" rule. Then conditionally appends
 * per-builder guidance: tool routing for supported builders (Elementor,
 * Gutenberg) and "detected but not supported for writes" warnings for the
 * others (Divi, WPBakery, Beaver Builder) so the agent refers the user back
 * to WP admin instead of attempting a write that will silently fail.
 *
 * Not cached — regeneration cost is a few constant/class checks plus string
 * concat, and caching would mask plugin-activation changes mid-session.
 *
 * @return string instructions string.
 */
function iato_mcp_build_server_instructions(): string {
	$builders = iato_mcp_detect_active_builders();

	$instructions = <<<END_UNIVERSAL
This MCP server connects to a self-hosted WordPress site.

MANDATORY RULE BEFORE ANY CONTENT EDIT:
Always call get_page_builder on a post before editing its content.
The correct write tool depends entirely on which page builder was
used to create the post. Using the wrong tool will silently fail.
Never assume a page builder. Always check first.

Every write tool returns a change_receipt with a unique rollback ID.
Store these and offer rollback to the user if anything looks wrong.
Use dry_run=true to preview any edit before committing it.
END_UNIVERSAL;

	// New-post workflow: fires whenever any non-Gutenberg builder is active.
	// Plain HTML in post_content renders as an unstyled orphan on builder
	// sites, so the agent must adopt an existing post's structure first.
	$primary_builder = match ( true ) {
		$builders['elementor'] => 'Elementor',
		$builders['divi']      => 'Divi',
		$builders['wpbakery']  => 'WPBakery',
		$builders['beaver']    => 'Beaver Builder',
		default                => null,
	};
	if ( null !== $primary_builder ) {
		$port_step = $builders['elementor']
			? '   3. Create the new post with create_post, then port the reference'
				. "\n      structure via update_elementor_data on the new post ID."
			: '   3. Create the new post with create_post. ' . $primary_builder
				. ' content cannot'
				. "\n      currently be written via MCP — surface this to the user and"
				. "\n      direct them to finish layout in WP admin.";
		$instructions .= "\n\n" . <<<END_NEWPOST
NEW-POST WORKFLOW ON PAGE-BUILDER SITES:
This site uses {$primary_builder}. Plain HTML posted via create_post
will not match the visual style of existing posts. Before calling
create_post, run this workflow:
   1. Ask the user: "I see this site uses {$primary_builder}. Can you
      point me to an existing post that has the layout I should match?"
   2. Once given a URL, call resolve_url then get_post on that reference
      (and get_elementor_data for Elementor) to read its structure.
{$port_step}
Do not create a new post with plain HTML and hope it looks right.
END_NEWPOST;
	}

	if ( $builders['elementor'] ) {
		$instructions .= "\n\n" . <<<END_ELEMENTOR
ELEMENTOR IS ACTIVE ON THIS SITE:
Elementor stores content in _elementor_data, not in post_content.
update_post will NOT update what visitors see on Elementor posts.

When get_page_builder returns 'elementor':
  1. Call list_elementor_widgets to find the correct widget_id
  2. Use update_elementor_widget for single widget edits
  3. Use update_elementor_data for full-document replacements
  4. Always pass if_revision to prevent write conflicts
  5. Always use dry_run=true before committing
  6. update_post is only permitted for: status, title, excerpt
END_ELEMENTOR;
	}

	if ( $builders['gutenberg'] && ! $builders['elementor'] ) {
		$instructions .= "\n\n" . <<<END_GUTENBERG
GUTENBERG IS THE ACTIVE EDITOR ON THIS SITE:
When get_page_builder returns 'gutenberg':
  - Use update_post to edit post content
  - Content is stored as block markup in post_content
  - Standard WordPress CRUD tools apply
END_GUTENBERG;
	}

	if ( $builders['divi'] ) {
		$instructions .= "\n\n" . <<<END_DIVI
DIVI DETECTED BUT NOT FULLY SUPPORTED:
When get_page_builder returns 'divi', inform the user:
  'This post was built with Divi. IATO MCP does not yet have
   dedicated Divi write tools. Please edit this post directly
   in the WordPress admin until Divi support ships.'
Do not attempt to write Divi post content.
Read operations are safe.
END_DIVI;
	}

	if ( $builders['wpbakery'] ) {
		$instructions .= "\n\n" . <<<END_WPBAKERY
WPBAKERY DETECTED BUT NOT FULLY SUPPORTED:
When get_page_builder returns 'wpbakery', inform the user:
  'This post was built with WPBakery. IATO MCP does not yet
   have dedicated WPBakery write tools. Please edit this post
   directly in the WordPress admin until WPBakery support ships.'
Do not attempt to write WPBakery post content.
Read operations are safe.
END_WPBAKERY;
	}

	if ( $builders['beaver'] ) {
		$instructions .= "\n\n" . <<<END_BEAVER
BEAVER BUILDER DETECTED BUT NOT FULLY SUPPORTED:
When get_page_builder returns 'beaver-builder', inform the user:
  'This post was built with Beaver Builder. IATO MCP does not
   yet have dedicated Beaver Builder write tools. Please edit
   this post directly in WordPress admin until support ships.'
Do not attempt to write Beaver Builder post content.
Read operations are safe.
END_BEAVER;
	}

	return trim( $instructions );
}

/**
 * Validate a post slug for use with update_post.
 *
 * Strict input gate: rejects anything that isn't already a valid lowercase
 * URL slug, so callers see a clear error instead of WordPress silently
 * mutating their input (e.g. uppercase folded to lowercase, spaces to
 * hyphens, or wp_unique_post_slug appending "-2" on collision).
 *
 * Order of checks: type → trim/empty → length → character set → defensive
 * sanitize_title round-trip → uniqueness query.
 *
 * @param mixed $raw     Slug as supplied by the caller.
 * @param int   $post_id Post being updated, excluded from the uniqueness check.
 * @return string|WP_Error Validated slug, or WP_Error with one of:
 *                         invalid_slug_format, slug_conflict.
 */
function iato_mcp_validate_post_slug( mixed $raw, int $post_id ): string|WP_Error {
	if ( ! is_string( $raw ) ) {
		return new WP_Error( 'invalid_slug_format', 'slug must be a string.' );
	}

	$slug = trim( $raw );
	if ( '' === $slug ) {
		return new WP_Error( 'invalid_slug_format', 'slug must not be empty.' );
	}
	if ( strlen( $slug ) > 200 ) {
		return new WP_Error( 'invalid_slug_format', 'slug exceeds the 200-character limit.' );
	}
	// Lowercase a-z, 0-9, hyphens; no leading/trailing/double hyphens.
	if ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) ) {
		return new WP_Error(
			'invalid_slug_format',
			'slug must be lowercase a-z, 0-9, and hyphens, with no leading, trailing, or consecutive hyphens.'
		);
	}
	// Defense in depth: WP's own sanitizer must not change the value.
	if ( sanitize_title( $slug ) !== $slug ) {
		return new WP_Error( 'invalid_slug_format', 'slug contains characters that WordPress would normalize. Pass a pre-normalized slug.' );
	}

	global $wpdb;
	$conflict_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND ID != %d AND post_status != 'trash' LIMIT 1",
		$slug,
		$post_id
	) );
	if ( $conflict_id > 0 ) {
		return new WP_Error(
			'slug_conflict',
			"Slug '{$slug}' is already used by post ID {$conflict_id}.",
			[
				'conflict_post_id'    => $conflict_id,
				'conflict_post_title' => get_the_title( $conflict_id ),
				'requested_slug'      => $slug,
			]
		);
	}

	return $slug;
}

/**
 * Build a page-builder formatting notice for create_post / update_post responses.
 *
 * Returns null on Gutenberg-only sites so vanilla installs see no warnings.
 * For builder-driven sites the message tells the AI to fetch a reference
 * post and adapt its structure — the friction this whole feature targets.
 *
 * @param string $context 'create' or 'update'. Influences phrasing only.
 * @return string|null Notice text, or null when no notice should be surfaced.
 */
function iato_mcp_page_builder_notice( string $context = 'create' ): ?string {
	$builders = iato_mcp_detect_active_builders();
	// Gutenberg-only is the normal case — no notice.
	$active = array_filter( [
		'elementor' => $builders['elementor'],
		'divi'      => $builders['divi'],
		'wpbakery'  => $builders['wpbakery'],
		'beaver'    => $builders['beaver'],
	] );
	if ( empty( $active ) ) {
		return null;
	}

	$builder = array_key_first( $active );
	$label   = match ( $builder ) {
		'elementor' => 'Elementor',
		'divi'      => 'Divi',
		'wpbakery'  => 'WPBakery',
		'beaver'    => 'Beaver Builder',
	};

	if ( 'elementor' === $builder ) {
		if ( 'update' === $context ) {
			return "This site uses Elementor. update_post's content field will NOT change what visitors see on this post — Elementor renders from _elementor_data, not post_content. Use update_elementor_widget for single-widget edits, or update_elementor_data for full-document replacements. If you intended to write fresh content, ask the user for a reference post URL, fetch its structure via get_post + get_elementor_data, and apply it via update_elementor_data.";
		}
		return "This site uses Elementor. The post was created with plain HTML in post_content and will not match your site's existing post format. Recommended: ask the user for a reference post URL, fetch its structure via get_post + get_elementor_data, and call update_elementor_data on this new post to match.";
	}

	$action_phrase = 'update' === $context ? 'updated with plain HTML' : 'created with plain HTML';
	return "This site uses {$label}. The post was {$action_phrase} and may not match your site's existing post format. {$label} content cannot currently be written via MCP — direct the user to edit this post in WP admin to match site styling.";
}

/**
 * Activation hook — show setup wizard on first run.
 */
function iato_mcp_activate() {
	IATO_MCP_Auth::maybe_generate_key();
	IATO_MCP_Change_Receipt::create_table();
	IATO_MCP_Call_Log::create_table();
	update_option( 'iato_mcp_show_wizard', true );

	// Clear stale suggestion generation transients so a fresh install/update
	// always starts clean and can re-trigger generation.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation-only bulk delete of our own transient keys; no cache to invalidate, and delete_transient() cannot do pattern matching.
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_iato_suggestions_generated_%' OR option_name LIKE '_transient_timeout_iato_suggestions_generated_%'"
	);
}
register_activation_hook( __FILE__, 'iato_mcp_activate' );

/**
 * Deactivation hook — clean up transients.
 * Options are preserved for reactivation; full cleanup is in uninstall.php.
 */
function iato_mcp_deactivate() {
	delete_transient( 'iato_mcp_oauth_pkce' );
	delete_transient( 'iato_mcp_dashboard_data' );
}
register_deactivation_hook( __FILE__, 'iato_mcp_deactivate' );
