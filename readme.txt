=== IATO MCP ===
Contributors: iatoai
Tags: mcp, ai, seo, sitemap, claude
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes an MCP server from any self-hosted WordPress site, enabling AI agents like Claude to audit and fix your site in a single workflow.

== Description ==

WordPress.com has a built-in MCP server. Now self-hosted WordPress does too.

[IATO MCP](https://iato.ai/wordpress-mcp) connects your WordPress site to Claude Desktop and other MCP-enabled AI clients. Once connected, you can ask Claude to audit your site and fix SEO issues, identify orphan pages, clean up broken links, and more — all in a single conversation.

= How it works =

1. Install and activate the plugin
2. Follow the setup wizard — copy the config into Claude Desktop, or use "Add Custom Connector" with your site URL
3. Connect your [IATO account](https://iato.ai) for AI-powered analysis ([free trial](https://iato.ai) up to 500 pages)

= What Claude can do =

**Without an IATO account (39 WordPress tools):**

* Read and edit posts, pages, and media
* Create new posts and pages with excerpt support
* Update SEO titles and meta descriptions (Yoast SEO, RankMath, SEOPress)
* Update canonical URLs
* Update image alt text
* Read and edit navigation menus
* Manage categories, tags, and taxonomy terms
* Manage JSON-LD structured data
* Manage redirect rules
* Read and write Elementor page builder data
* Widget-grained Elementor edits with optimistic concurrency, idempotency, and bulk operations
* Resolve URLs to their rendering post (Theme Builder shadowing detection)
* Search content across the site
* Read site info and settings
* Read and filter comments

**With an [IATO account](https://iato.ai) (12 bridge tools — full analyze-and-fix pipeline):**

* Start a new crawl of your site directly from Claude (admin only)
* Check crawl status and list recent crawl jobs
* Run a full SEO audit and fix title, meta description, and alt text issues automatically
* Identify orphan pages not linked from any navigation menu
* Audit navigation menus for gaps and missing sections
* Surface thin content with specific improvement recommendations
* Map broken links to source posts for direct editing
* Analyze site taxonomy and suggest consolidations
* Get AI-prioritized suggestions across all areas
* Flag slow pages with contributing performance factors

= Supported SEO plugins =

* Yoast SEO
* RankMath
* SEOPress
* Falls back to native WordPress title if none detected

= Example prompts =

> "Crawl my site and fix all missing meta descriptions"

> "Show me pages that aren't in any navigation menu and add them to the right place"

> "What are the most impactful improvements I can make to my site right now?"

> "Find all broken links and tell me which posts contain them"

> "Audit my categories and tags and suggest consolidations"

= External Services =

This plugin connects to the following external service when configured:

**IATO API** ([https://iato.ai](https://iato.ai)) — When you enter an IATO API key in the plugin settings, the plugin sends requests to `https://iato.ai/api` to retrieve crawl data, SEO audit results, sitemap information, and AI-generated improvement suggestions. No data is sent to IATO until you configure an API key. Your public page URLs (as crawled by IATO) and crawl analysis results are transmitted.

* [IATO Terms of Service](https://iato.ai/terms)
* [IATO Privacy Policy](https://iato.ai/privacy)

The plugin also implements an OAuth 2.0 authorization server on your WordPress site so that MCP clients like Claude Desktop can authenticate via the standard "Add Custom Connector" flow. This communication stays between the MCP client and your WordPress site — no data is sent to third parties during authentication.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/iato-mcp/` or install via the WordPress plugin directory
2. Activate the plugin via the Plugins menu in WordPress
3. Follow the setup wizard that appears — it provides the JSON config for Claude Desktop
4. In Claude Desktop, either paste the JSON config or use "Add Custom Connector" and enter your site URL
5. Optionally, go to Settings > IATO MCP to enter your IATO API key for the full analysis pipeline

For detailed setup instructions, see the [IATO MCP documentation](https://iato.ai/wordpress-mcp-docs).

== Frequently Asked Questions ==

= Do I need an IATO account? =

No. The plugin works standalone for reading and editing WordPress content with 30 built-in tools. An [IATO account](https://iato.ai) ([free trial](https://iato.ai) up to 500 pages) unlocks 12 additional bridge tools: start/list/status crawl management, SEO audit, broken links, content gaps, orphan pages, navigation audit, taxonomy analysis, AI suggestions, and performance reports.

= Which WordPress version is required? =

WordPress 6.2 or higher with PHP 8.0+. The plugin uses the WordPress REST API and implements OAuth 2.0 for secure authentication with AI clients.

= Does this work on shared hosting? =

Yes. The plugin uses standard HTTP requests (one per MCP call) rather than long-lived connections, so it works on all hosting environments including shared hosting.

= Which AI clients are supported? =

Any MCP-enabled client: Claude Desktop, Cursor, VS Code with GitHub Copilot, and any client that supports the Streamable HTTP MCP transport.

= How does authentication work? =

The plugin generates a secure API key on activation. You can authenticate in two ways: paste the provided Bearer token config into your AI client, or use Claude Desktop's "Add Custom Connector" flow which handles OAuth 2.0 with PKCE automatically.

= Why does the plugin support two auth methods? =

AI clients like Claude Desktop authenticate via a WordPress Application Password (or the OAuth 2.0 / PKCE flow), which is the WordPress-native pattern most users will use. The plugin also accepts the plugin-generated Bearer token at the same MCP endpoint — that path is used by the IATO platform's own integrations (for example, the dashboard's "Sync pages, posts, menus, and taxonomy from WordPress" feature, which composes the plugin's read tools to pull content into IATO). Both methods land at `/wp-json/iato-mcp/v1/message` and are validated by `class-auth.php`. You don't have to choose — paste your Bearer token into the IATO platform connection, generate an Application Password for Claude Desktop, and the same plugin handles both.

= Is my content sent to IATO or Anthropic? =

WordPress content (post titles, meta descriptions, etc.) is never sent to IATO. IATO crawls your public URLs the same way a search engine would. Claude processes content within your AI client session only. The IATO API is only called when you use bridge tools, and only crawl analysis data (not your content) is transmitted.

= Can I control which tools are available? =

Yes. Go to Settings > IATO MCP to enable or disable individual tools. You can turn off any tool you don't want AI clients to access.

== Screenshots ==

1. Settings page — MCP connection info with endpoint URL and API key
2. Settings page — IATO Platform configuration and tool toggles
3. Setup wizard — auto-generated Claude Desktop configuration
4. OAuth authorization screen — approve AI client connections

== Changelog ==

= 1.3.0 =
* New: widget-grained Elementor surface (v2). Nine new MCP tools — `list_elementor_widgets`, `get_elementor_widget`, `update_elementor_widget`, `update_elementor_patch`, `update_elementor_widgets_bulk`, `find_elementor_widgets`, `set_heading_level`, `set_widget_setting`, `resolve_url`. Replaces the all-or-nothing `update_elementor_data` for surgical edits while preserving the v1 tool unchanged.
* New: optimistic concurrency on every v2 write via `if_revision` (sha256 of stored Elementor data). Mismatch returns `revision_conflict` with the current revision so clients can re-sync without an extra read.
* New: idempotency keys on every v2 write via `idempotency_key`. Same key + same payload within 60s returns the cached response with `idempotency_replay: true`; same key + different payload returns 409. Scoped per-(user, tool).
* New: structured `applied_patch` diff response on every v2 write — RFC 6902 ops with `previous_value` extension. Identical shape in `dry_run` mode so clients can preview before committing.
* New: `update_elementor_patch` accepts an RFC 6902 JSON Patch over the entire document for surgical array-entry edits (repeater rows, indexed inserts) where v2 widget patch's replace-only array semantics are too coarse.
* New: `find_elementor_widgets` searches every Elementor post in the workspace (capped at 500 in 1.3.0) for widgets matching a filter spec — operators `eq`, `ne`, `in`, `nin`, `exists`.
* New: `resolve_url` walks the WordPress rewrite cascade and reports the rendering post + Theme Builder template shadowing (Elementor Pro). Best-effort across Elementor versions; returns `limited_resolution: true` when the platform's APIs aren't available.
* New: `is_shadowed_by` field on `get_post` (opt-in via `include_shadowing: true`) — surfaces Theme Builder template overrides without requiring a separate `resolve_url` call.
* New: `format` parameter on `get_elementor_data` — `raw` (existing), `compact` (defaults stripped, top-20 widget types), `summary` (skeleton tree of `{widget_id, type, peek_fields}`). All formats include the canonical `revision` hash for use with v2 if_revision guards.
* New: `initialize` response advertises `capabilities.elementor.v2: true` when Elementor is active so clients can feature-detect without a `tools/list` round-trip.
* Existing v1 tools (`get_elementor_data`, `update_elementor_data`) remain functional with unchanged signatures — no breaking changes.

= 1.2.4 =
* Fix: `list_iato_crawls` now returns the UUID `job_id` as `crawl_id` instead of the numeric DB primary key. The numeric `id` had no FK relationship to the other bridge tools (which all key off the UUID via `/crawl/jobs/{uuid}/...`), so handing it back to Claude broke the analyze-and-fix chain at the first hop.
* Fix: `list_iato_crawls` envelope read now falls back from canonical `data.jobs` to bare `jobs` if the platform regresses or a new un-wrapped endpoint slips through. Same dual-key resilience pattern used for `/workspaces` during the v1.1 transition.

= 1.2.3 =
* Fix: `start_iato_crawl` now sends `workspace_id` as a JSON integer, not a JSON string. The platform's POST /crawl/start handler binds the field as `Optional[int]` via Pydantic; depending on strict-mode it can reject `"44"` while accepting `44`. Resolves orphan-crawl creation that persisted from 1.2.0–1.2.2.

= 1.2.2 =
* Fix: Test connection now persists the workspace_id when validation succeeds, so the crawl-control tools can scope requests correctly. Previously the option remained empty even after a successful validation, which made `start_iato_crawl` create orphan jobs and `list_iato_crawls` return an empty list.
* Fix: `start_iato_crawl` and `list_iato_crawls` now use `resolve_workspace_id()` (with built-in lazy-load fallback) instead of reading the option directly. Self-heals existing installs that validated their key before 1.2.2.

= 1.2.1 =
* Fix: `start_iato_crawl` now tags new crawls with the user's workspace_id so they are properly scoped to the connected IATO account
* Fix: `list_iato_crawls` now filters by workspace_id to return crawls owned by the connected account (previously returned an empty list even when crawls existed)
* Fix: replace PHP 8.2-only `: true|WP_Error` literal type with `: bool|WP_Error` across class-auth, class-seo-adapter, class-rollback, and tool-redirects so the plugin parses cleanly on PHP 8.0/8.1 as the header advertises

= 1.2.0 =
* New: `start_iato_crawl` MCP tool — Claude can kick off an IATO crawl of the current site directly from a conversation (admin only; consumes IATO platform quota)
* New: `get_iato_crawl_status` MCP tool — poll a specific crawl job until it completes
* New: `list_iato_crawls` MCP tool — list recent crawl jobs to find the most recent completed crawl_id
* New "Crawl Management" category in Settings > IATO MCP > Tools
* Bridge tool count: 9 → 12; total registered tools: 39 → 42
* New FAQ entry on the dual auth methods (Application Password / OAuth for AI clients vs. Bearer token for the IATO platform's WordPress Sync UI)

= 1.1.12 =
* Added Plugin URI to plugin header
* Added contextual links to iato.ai throughout the plugin description, installation, and FAQ sections
* Added link to documentation page

= 1.1.11 =
* Readme accuracy corrections: updated tool count from 17 to 30, expanded feature list with Elementor, canonical URLs, structured data, redirects, and excerpt support, corrected minimum WordPress version to 6.2

= 1.1.10 =
* 30 WordPress native tools including Elementor read/write and the new `excerpt` parameter on `update_post`
* 9 IATO bridge tools: sitemap, SEO fixes, broken links, content gaps, orphan pages, navigation audit, AI suggestions, performance reports, taxonomy analysis
* OAuth 2.0 authorization server with PKCE for Claude Desktop connector flow
* Dynamic client registration (RFC 7591)
* SEO adapter supporting Yoast SEO, RankMath, and SEOPress
* Single Settings page with General and Diagnostics tabs; 39 per-tool toggles
* AJAX-based Save Settings to sidestep host-level options.php timeouts
* "Test connection" button for explicit IATO API key validation
* Change receipts audit trail for every write operation, with Claude-callable rollback endpoint
* MCP `notifications/*` methods silently accepted per JSON-RPC spec
* Plugin-generated API key with Bearer token authentication

== Upgrade Notice ==

= 1.3.0 =
Adds widget-grained Elementor tools (v2 surface) — patch a single widget without re-uploading the whole document, with optimistic concurrency and idempotency. Existing v1 tools are unchanged. Recommended upgrade for anyone editing Elementor pages from Claude.

= 1.2.4 =
Fixes `list_iato_crawls` returning the wrong identifier (numeric DB id instead of the UUID), which broke the chain into the other bridge tools. Adds dual-key envelope resilience for the same endpoint. Recommended upgrade for anyone on 1.2.0–1.2.3.

= 1.2.3 =
Sends workspace_id as a JSON integer so the platform's Pydantic binding accepts it. Required to make the crawl-control tools fully functional; recommended upgrade for anyone on 1.2.0–1.2.2.

= 1.2.2 =
Completes the workspace_id scoping fix from 1.2.1. After upgrading, click Test connection in Settings > IATO MCP once to populate the workspace_id, then crawl management will work end-to-end.

= 1.2.1 =
Fixes workspace_id scoping for the new crawl-management tools and a PHP 8.2-only return type that broke installs on PHP 8.0/8.1. Recommended upgrade for anyone on 1.2.0.

= 1.2.0 =
Adds three crawl-control MCP tools so Claude can start, check, and list IATO crawls without leaving the conversation. Admin only for `start_iato_crawl`.

= 1.1.12 =
Adds Plugin URI and contextual links to iato.ai throughout the listing. No code changes.

= 1.1.11 =
Readme accuracy pass. No code changes.

= 1.1.10 =
First stable release to the WordPress.org directory.
