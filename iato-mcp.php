<?php
/**
 * Plugin Name: IATO MCP
 * Plugin URI:  https://iato.ai/wordpress-mcp
 * Description: Exposes an MCP server from any self-hosted WordPress install, enabling IATO analyze-and-fix workflows via Claude Desktop and other AI clients.
 * Version:     1.21.0
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

define( 'IATO_MCP_VERSION', '1.21.0' );
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
require_once IATO_MCP_DIR . 'includes/class-cache.php';
require_once IATO_MCP_DIR . 'includes/class-element-cache-guard.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-call-log.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';
require_once IATO_MCP_DIR . 'includes/class-receipt-retention.php';
require_once IATO_MCP_DIR . 'includes/class-oauth-store.php';
require_once IATO_MCP_DIR . 'includes/class-schema.php';
require_once IATO_MCP_DIR . 'includes/class-oauth.php';
require_once IATO_MCP_DIR . 'includes/tools-selection.php';
require_once IATO_MCP_DIR . 'includes/class-settings.php';
require_once IATO_MCP_DIR . 'includes/class-setup-wizard.php';
require_once IATO_MCP_DIR . 'includes/class-diagnostics.php';
require_once IATO_MCP_DIR . 'includes/class-mcp-server.php';
require_once IATO_MCP_DIR . 'includes/class-abilities.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-atomic.php';
require_once IATO_MCP_DIR . 'includes/class-sanitize-report.php';
require_once IATO_MCP_DIR . 'includes/class-inline-markup.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-sanitizer.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-adapter.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-atomic-engine.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-atomic-writer.php';
require_once IATO_MCP_DIR . 'includes/class-elementor-router.php';
require_once IATO_MCP_DIR . 'includes/class-theme-builder.php';
require_once IATO_MCP_DIR . 'includes/class-site-image.php';
require_once IATO_MCP_DIR . 'includes/class-post-date.php';
require_once IATO_MCP_DIR . 'includes/class-redirects.php';
require_once IATO_MCP_DIR . 'includes/class-url-move.php';
require_once IATO_MCP_DIR . 'includes/class-permalinks.php';
require_once IATO_MCP_DIR . 'includes/class-custom-css.php';
require_once IATO_MCP_DIR . 'includes/class-page-css.php';
require_once IATO_MCP_DIR . 'includes/class-page-fonts.php';
require_once IATO_MCP_DIR . 'includes/class-preview-link.php';
require_once IATO_MCP_DIR . 'includes/class-page-assets-admin.php';
require_once IATO_MCP_DIR . 'includes/class-page-script.php';
require_once IATO_MCP_DIR . 'includes/class-user-profile.php';
require_once IATO_MCP_DIR . 'includes/class-media-uploader.php';
require_once IATO_MCP_DIR . 'includes/class-media-chunks.php';
require_once IATO_MCP_DIR . 'includes/class-media-phase-log.php';

// Phase 1 — WP native tools
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-site.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-custom-css.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-users.php';
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
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-elementor-templates.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-resolve-url.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-canonical.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-structured-data.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-redirects.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-rollback.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-post-meta.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-settings.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-featured-image.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-media-upload.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-assets.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-script.php';

// Phase 2 — IATO bridge tools. Exposed on the MCP endpoint only when an IATO
// API key is configured (unchanged). When the key is absent but WordPress
// Abilities exposure is enabled, the same files are loaded in catalog-only
// mode: IATO_MCP_Server records their definitions and handlers without adding
// anything to tools/list, so the iato/* abilities can exist and return a clear
// "not connected" error. See IATO_MCP_Abilities.
$iato_mcp_connected = get_option( 'iato_mcp_api_key', '' ) !== '';
if ( $iato_mcp_connected || IATO_MCP_Abilities::is_enabled() ) {
	IATO_MCP_Server::set_catalog_only( ! $iato_mcp_connected );
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
	IATO_MCP_Server::set_catalog_only( false );
}
unset( $iato_mcp_connected );

/**
 * Boot the plugin after all plugins are loaded.
 */
function iato_mcp_init() {
	IATO_MCP_OAuth::init();
	IATO_MCP_Settings::init();
	IATO_MCP_Server::init();
	IATO_MCP_Rollback::init();
	IATO_MCP_Receipt_Retention::init();
	IATO_MCP_Media_Chunks::init();
	IATO_MCP_Element_Cache_Guard::init();
	IATO_MCP_Setup_Wizard::init();
	IATO_MCP_Diagnostics::init();
	IATO_MCP_Abilities::init();
	IATO_MCP_Page_CSS::init();
	IATO_MCP_Page_Fonts::init();
	IATO_MCP_Preview_Link::init();
	IATO_MCP_Page_Assets_Admin::init();
	IATO_MCP_Page_Script::init();
	iato_mcp_maybe_run_migrations();
}
add_action( 'plugins_loaded', 'iato_mcp_init' );

// A deleted administrator's OAuth connections die with the account. (A
// demoted administrator is caught lazily on the next request or refresh.)
add_action( 'deleted_user', [ 'IATO_MCP_OAuth', 'on_deleted_user' ], 10, 1 );

// Cron handler for create_media's defer_subsizes path. Generates the
// intermediate sizes asynchronously so the synchronous MCP response can return
// before wp_generate_attachment_metadata runs (which can take many seconds on
// sites with image-optimisation pipelines and exceeds the gateway timeout).
add_action( 'iato_mcp_generate_subsizes', [ 'IATO_MCP_Media_Uploader', 'generate_subsizes_async' ], 10, 3 );

/**
 * Run idempotent one-shot migrations.
 *
 * Activation hooks don't fire on plugin update, so any post-install
 * data fix-ups have to live here. The tool-list backfill is gated by the
 * plugin version (iato_mcp_db_version); table changes and data fix-ups by
 * the schema version (IATO_MCP_Schema), which is independent of it.
 */
function iato_mcp_maybe_run_migrations() {
	$db_version = get_option( 'iato_mcp_db_version', '0' );

	// Tool-list backfill — a single declarative loop replaces what used to be
	// four hand-written `version_compare` blocks (one per release that added
	// new tool names). The map at IATO_MCP_Settings::TOOL_MIGRATION_BACKFILL
	// lists "db_version gate => tool names" pairs; for each gate, if the
	// install's stored db_version is below it, any missing names are appended
	// to iato_mcp_tools. This closes the long-standing class of bug where
	// new tool names added to TOOL_NAMES would be invisible to upgraded
	// installs (is_tool_enabled() returns false for any name not already in
	// the saved array). Fresh installs are unaffected because their option
	// starts empty and is_tool_enabled() defaults to true.
	//
	// To add a new tool: append it to TOOL_NAMES *and* to the backfill map.
	foreach ( IATO_MCP_Settings::TOOL_MIGRATION_BACKFILL as $gate => $tools ) {
		if ( version_compare( $db_version, (string) $gate, '<' ) ) {
			$saved = get_option( 'iato_mcp_tools', null );
			if ( is_array( $saved ) && ! empty( $saved ) ) {
				$missing = array_diff( $tools, $saved );
				if ( ! empty( $missing ) ) {
					update_option( 'iato_mcp_tools', array_values( array_merge( $saved, $missing ) ), false );
				}
			}
		}
	}

	// Schema migrations (tables and one-shot data fix-ups) are keyed by
	// IATO_MCP_Schema::VERSION, an integer independent of the plugin version:
	// a site that ran a pre-release build already stores that build's
	// db_version, which would make a version_compare gate skip every later
	// table change. Each step is idempotent; a failing step stops the run and
	// is retried on the next request. See includes/class-schema.php.
	IATO_MCP_Schema::run();
	// 1.16.0: a site with a table problem retries the repair, at most hourly.
	// Healthy sites have no health option and do no work here.
	IATO_MCP_Schema::maybe_repair();

	if ( version_compare( $db_version, IATO_MCP_VERSION, '<' ) ) {
		update_option( 'iato_mcp_db_version', IATO_MCP_VERSION, false );
	}

	// One-shot repair for sites hit by the "bridge tools saved as off" bug: a
	// non-empty iato_mcp_tools that contains none of the twelve bridge tools is
	// treated as a save made before an IATO key existed and gets them back.
	// Runs once (flag option), is idempotent, and leaves any list that already
	// contains at least one bridge tool alone. See includes/tools-selection.php.
	if ( ! get_option( 'iato_mcp_bridge_tools_repaired' ) && function_exists( 'iato_mcp_repair_bridge_tools' ) ) {
		$saved  = get_option( 'iato_mcp_tools', [] );
		$repair = iato_mcp_repair_bridge_tools( is_array( $saved ) ? $saved : [] );
		if ( $repair['repaired'] ) {
			update_option( 'iato_mcp_tools', $repair['tools'] );
		}
		update_option( 'iato_mcp_bridge_tools_repaired', '1', false );
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

Write tools also return a cache object: the page cache plugins found
(detected), whether they were purged (purged), and notes about caches
that could not be reached, such as a CDN. When purged is not true or
notes name a cache, tell the user visitors may still see the old page.
When a response has sanitized_report, tell the user what was removed.

NAVIGATION MENUS:
To build a menu: create_menu, then create_menu_item for each link (a url for
a custom link, including #anchor links, or a post_id for a page; position and
parent_id set the order and nesting). get_menus lists the theme's locations
under locations; pass one as location to create_menu, or use
assign_menu_location to put a menu in one.

RESTRUCTURING URLS:
A page's parent (update_post or create_post parent) makes its URL the
parent's URL plus its slug, such as /services/web-development/. The
permalink settings (update_site_settings permalink_structure, category_base,
tag_base) move every post and the category, tag and author archives, such
as posts under /blog/ with /blog/%postname%/. Both move live URLs, so they
need confirm_url_break=true, and both create 301 redirects from the old URLs
by default (redirect_old_urls). To restructure:
   1. Create the parent pages first (create_post with post_type page).
   2. Dry run the move (dry_run=true) and show the user url_changes.
   3. Apply it. Keep the change receipt: its rollback puts the URLs back
      and removes the redirects it created.
   4. Update links written into content (Elementor widgets, buttons) and
      custom menu links to the new URLs. Redirects cover visitors, but
      internal links should point at the new URLs.
END_UNIVERSAL;

	// 1.21.0: page assets, only for the tools switched on (they ship off).
	$asset_tool = static fn( string $tool ): bool => class_exists( 'IATO_MCP_Settings' ) && IATO_MCP_Settings::is_tool_enabled( $tool );
	$asset_lines = [];
	if ( $asset_tool( 'set_page_css' ) ) {
		$asset_lines[] = "set_page_css gives one page its own CSS, printed in that page's head\n"
			. "only. Prefer it to a <style> block in an HTML widget, but tell the site owner:\n"
			. "this plugin prints it, so the page loses it if the plugin is deactivated,\n"
			. "while a style block does not depend on the plugin. CSS that loads anything\n"
			. 'from another site is refused.';
	}
	if ( $asset_tool( 'set_page_fonts' ) ) {
		$asset_lines[] = "set_page_fonts loads Elementor font families on one page only. Use it\n"
			. 'instead of @import, which is refused.';
	}
	if ( $asset_tool( 'create_preview_link' ) ) {
		$asset_lines[] = "create_preview_link gives an expiring URL that shows a draft without a\n"
			. "login: fetch it to check your work before telling the user it is done. Do\n"
			. 'not post it anywhere public.';
	}
	if ( $asset_tool( 'set_page_script' ) && class_exists( 'IATO_MCP_Page_Script' ) && IATO_MCP_Page_Script::setting_on() ) {
		$asset_lines[] = "set_page_script stores JavaScript for one page. It does not run until an\n"
			. "administrator reads it and approves it in wp-admin, and any change waits for\n"
			. 'approval again. Tell the user when a script needs their approval.';
	}
	if ( [] !== $asset_lines ) {
		$instructions .= "\n\nPAGE ASSETS:\n" . implode( "\n", $asset_lines )
			. "\nFor a large widget, pass return: \"minimal\" to update_elementor_widget to get\n"
			. 'sizes and hashes back instead of the whole value.';
	}

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
				. "\n      structure via update_elementor_data on the new post ID. That call"
				. "\n      turns Elementor on for the post."
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
  6. update_post is only permitted for: status, title, excerpt, slug, parent, menu_order, date, date_gmt, allow_future and author; content edits go through the Elementor tools.

When get_page_builder returns elementor_tree_present: true, the page holds
Elementor data but Elementor is turned off for it, so visitors see its
post_content. Elementor writes are refused there. Ask the user before calling
update_elementor_data with activate_builder: true, which switches the page to
Elementor and replaces that content. Rollback restores it.

To build a site-wide header or footer (Elementor Pro): create_elementor_template
with template_type header or footer and conditions
[{"type":"include","name":"general"}], then fill it with update_elementor_data,
then publish it with update_post status publish. Or pass elementor_data and
status publish to create_elementor_template, which publishes last. A draft shows
nowhere. Then read theme_locations from resolve_url on the home page. A template
with a built-in header and footer on the page itself, or a page using the
elementor_canvas template, shows no Theme Builder header or footer: switch that
page to elementor_header_footer with set_page_settings (page_template), and take
its own header and footer out of its content.

Rolling back a template you have since filled needs force: true, because it
removes what visitors see on every page it shows on. Ask the user first.

Elementor Pro's Nav Menu widget needs a WordPress menu: create_menu, then
create_menu_item for each link, then set the widget's menu setting to the
menu's slug.

To see which Theme Builder header and footer a URL gets, call resolve_url and
read theme_locations. A template named there renders around the page and is
not what you edit to change the page itself. theme_locations is null when
Elementor Pro's Theme Builder is not active. not_evaluated lists conditions
this plugin could not judge for that URL: check those in the Theme Builder.
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
 * sanitize_title round-trip → uniqueness, by WordPress's own rule (1.20.0).
 *
 * @param mixed    $raw     Slug as supplied by the caller.
 * @param int      $post_id Post being updated, excluded from the uniqueness check.
 * @param int|null $parent  The parent the post will have after this call; null for its current one.
 * @return string|WP_Error Validated slug, or WP_Error with one of:
 *                         invalid_slug_format, slug_conflict.
 */
function iato_mcp_validate_post_slug( mixed $raw, int $post_id, ?int $parent = null ): string|WP_Error {
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

	// 1.20.0: WordPress's own rule (wp_unique_post_slug()), not a lookup across every
	// post type. A menu item called "services" blocked a page of that slug, which
	// WordPress itself would have accepted.
	$post = get_post( $post_id );
	if ( $post ) {
		$conflict = IATO_MCP_URL_Move::slug_refusal( $slug, $post_id, (string) $post->post_type, null === $parent ? (int) $post->post_parent : $parent );
		if ( null !== $conflict ) {
			return $conflict;
		}
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
function iato_mcp_page_builder_notice( string $context = 'create', int $post_id = 0 ): ?string {
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
			// Elementor renders only the posts it is on. Visitors of any other see this content.
			if ( $post_id > 0 && 'active' !== IATO_MCP_Elementor_Adapter::builder_state( $post_id ) ) {
				return "This site uses Elementor, but Elementor is not on for this post, so visitors do see update_post's content. It will not match the layout of posts built with Elementor.";
			}
			return "This site uses Elementor. update_post's content field will NOT change what visitors see on this post — Elementor renders from _elementor_data, not post_content. Use update_elementor_widget for single-widget edits, or update_elementor_data for full-document replacements. If you intended to write fresh content, ask the user for a reference post URL, fetch its structure via get_post + get_elementor_data, and apply it via update_elementor_data.";
		}
		return "This site uses Elementor. The post was created with plain HTML in post_content and will not match your site's existing post format. Recommended: ask the user for a reference post URL, fetch its structure via get_post + get_elementor_data, and call update_elementor_data on this new post to match. That call turns Elementor on for the post.";
	}

	$action_phrase = 'update' === $context ? 'updated with plain HTML' : 'created with plain HTML';
	return "This site uses {$label}. The post was {$action_phrase} and may not match your site's existing post format. {$label} content cannot currently be written via MCP — direct the user to edit this post in WP admin to match site styling.";
}

/**
 * Activation hook — show setup wizard on first run.
 */
function iato_mcp_activate() {
	// Generates the site key on first activation and binds it to the activating
	// administrator (token binding). Keys that already exist are left as they
	// are: an existing key without an owner stays in legacy mode and the Settings
	// page / admin notice ask for an owner. Nothing binds automatically on upgrade.
	IATO_MCP_Auth::maybe_generate_key();
	// Fresh installs run every schema step (which creates the tables);
	// reactivations run only the steps the site has not seen.
	IATO_MCP_Schema::run();
	IATO_MCP_Schema::maybe_repair( true );
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
	delete_transient( 'iato_mcp_dashboard_data' );
	// The daily receipt cleanup; rescheduled on the first request after reactivation.
	IATO_MCP_Receipt_Retention::unschedule();
	IATO_MCP_Media_Chunks::unschedule();
	IATO_MCP_Element_Cache_Guard::unschedule();
	// 1.21.0: page scripts stop with the plugin; cached pages must not keep them.
	IATO_MCP_Page_Script::purge_all();
}
register_deactivation_hook( __FILE__, 'iato_mcp_deactivate' );
