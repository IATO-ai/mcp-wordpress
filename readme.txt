=== IATO MCP ===
Contributors: iatoai
Tags: mcp, ai, seo, sitemap, claude
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.4.6
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

**Without an IATO account (40 WordPress tools):**

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
* One-call rollback for any tracked write — every change emits a receipt with a stable `change_id`; pass it back to the `rollback` tool and the original value is restored

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

> "Set every H2 heading in these Elementor posts to H1"

> "Find all button widgets on the site and change their color to #ff0000"

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

No. The plugin works standalone for reading and editing WordPress content with 40 built-in tools. An [IATO account](https://iato.ai) ([free trial](https://iato.ai) up to 500 pages) unlocks 12 additional bridge tools: start/list/status crawl management, SEO audit, broken links, content gaps, orphan pages, navigation audit, taxonomy analysis, AI suggestions, and performance reports.

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

= 1.4.6 =
* Fix: `rollback` now appears as a checkbox on the Settings → IATO MCP page (under a new "Safety" category). v1.4.5 added rollback to the `TOOL_NAMES` constant — which fixed the sanitize-strip behavior — but the Settings UI rendering loop iterates a separate constant, `TOOL_CATEGORIES`, which also needed rollback added. Without the category entry, the checkbox was never rendered. Adding `'Safety' => ['rollback']` closes the gap.
* Polish: unified the inner `mcpServers` server key shown in the Settings page hero card config snippet from `wordpress` to `iato-wordpress`, matching the dismissible setup notice. Cosmetic only — the inner key is a user-facing display name they can rename — but eliminates an unnecessary inconsistency between the two snippets.

= 1.4.5 =
* Fix: `rollback` tool now appears in the Settings → IATO MCP per-tool toggle list, and the Settings save no longer silently strips it from `iato_mcp_tools`. When v1.4.0 added the rollback MCP tool, the developer forgot to add it to the `TOOL_NAMES` constant in `class-settings.php`. Consequence: no UI checkbox for it, and `sanitize_tools()` (which `array_intersect`s saved values against TOOL_NAMES) was stripping it from existing installs every time a user clicked Save Settings. Once stripped, `is_tool_enabled('rollback')` returned false and the tool stopped registering. Adding rollback to TOOL_NAMES fixes both the UI and the strip behavior.
* Fix: idempotent migration restores `rollback` to `iato_mcp_tools` for any install where it had been stripped by the previous bug. Runs once on plugin upgrade, no-op for installs that didn't lose it.
* Fix: `capabilities.rollback` in the `initialize` response now reflects actual tool registration instead of being hardcoded `true`. Previously, an install with rollback disabled (manually or via the strip bug above) would advertise `rollback: true` in capabilities, causing clients that feature-detect to attempt rollback calls that returned `tool_not_found`.

= 1.4.4 =
* Fix: clicking Approve on the OAuth consent screen no longer redirects users to /wp-admin instead of back to the OAuth client. The handler at `class-oauth.php:181` was using `wp_safe_redirect()` for the post-approval callback, but `wp_safe_redirect` silently rewrites any URL whose host isn't on WordPress's `allowed_redirect_hosts` allowlist to `admin_url()` — which means every external OAuth callback (claude.ai, cursor.sh, etc.) was being silently rewritten to /wp-admin/, leaving the connector stuck on "Connect" because the client never received an authorization code. Switched to `wp_redirect()`, which is the correct primitive for OAuth callbacks (the protocol requires an external redirect by design).
* Fix: the not-logged-in branch of the authorize handler at `class-oauth.php:132` was passing `$_SERVER['REQUEST_URI']` through `sanitize_text_field()` before building the post-login redirect URL. `sanitize_text_field` strips `%XX` percent-encoded sequences as an HTML-entity defense, which mangled the inner `redirect_uri` parameter (every `:` and `/` removed) and broke the post-login bounce back to /oauth/authorize. Now uses `wp_unslash` only, which is correct for a server-set value used as a redirect target.
* Hardening: `/oauth/authorize` now refuses requests whose `client_id` isn't registered via the dynamic client registration endpoint at `/oauth/register`. Previously the redirect_uri allowlist was opt-in (validated only when the client_id existed in the registered set) — after the wp_redirect change above lets external redirects through, that opt-in shape was an open-redirect surface. Spec-compliant clients (Claude, Cursor, etc.) already register before authorize, so this is a no-op for them.
* Fix: `initialize` now echoes the client's requested `protocolVersion` when it's one we recognize (`2024-11-05`, `2025-03-26`, `2025-06-18`) instead of always returning `2024-11-05`. Falls back to `2025-06-18` for unknown requests. Forward-compat for clients on newer MCP revs.

= 1.4.3 =
* Fix: dismissible "MCP — Ready to Connect" admin notice restructured. The previous "1. Copy / 2. Open Claude Desktop / 3. (Optional) IATO key" framing implied a sequential three-step flow, but Step 1's snippet and Step 2's "Or use Add Custom Connector" sub-line were actually two mutually-exclusive connection methods, and Step 3 was unrelated optional setup. Notice now leads with the endpoint URL (with its own Copy button), then presents Option A (Connectors UI / OAuth, recommended) and Option B (Claude Desktop config file with the mcp-remote stdio snippet) as clearly-labeled alternatives separated by an "— or —" divider, with the IATO API key and "see the setup wizard for other clients" line moved to a non-numbered footer. Same content, structure no longer suggests dependence between the two paths.

= 1.4.2 =
* Fix: `Authorization: Basic <Application Password>` is now an accepted auth path on the MCP endpoint, alongside the existing plugin Bearer token. v1.4.1 documented Application Password support in the setup wizard but `class-auth.php` was hard-rejecting any non-Bearer header — users following wizard Methods 2 or 3 were getting 401s. This release makes the wizard's promise actually work. Trust grant in this version is identical to the Bearer path (full admin once authenticated); per-user capability enforcement under Application Password is tracked separately as a v1.6 hardening item.
* Fix: dismissible setup notice now emits a Claude-Desktop-compatible stdio-bridge config (`mcp-remote` via `npx`, Bearer + `iato_mcp_key` in an `env` entry) instead of the direct-HTTP `{url, headers}` format that Claude Desktop's config file can't consume. Same bug class as the v1.4.1 wizard fix; this catches the second occurrence in the admin notice.
* Fix: relabeled the Settings page hero-card config block from "Claude Desktop Configuration" to "HTTP MCP clients (MCP Inspector, IDEs, scripts)" — the snippet is still the right config for those clients, just no longer mislabels its audience. Adds a one-line pointer to the setup wizard for stdio-only clients.

= 1.4.1 =
* Fix: setup wizard restructured around the three actual connection methods. The previous "1. URL → 2. Application Password → 3. Claude Desktop config" framing presented OAuth-via-Connectors users with a credential step they didn't need, and the JSON snippet referenced `@modelcontextprotocol/server-http` — a package that doesn't exist on npm. The wizard now leads with the endpoint URL, then presents three mutually exclusive method cards: Connectors UI (OAuth, recommended), Direct HTTP (Basic Auth for MCP Inspector / IDEs / scripts), and Manual config (stdio bridge for Claude Desktop config file, Cursor, Cline, Zed).
* Fix: stdio-bridge JSON snippet now uses `mcp-remote` (the real npm package) and passes the credential via an `env` entry referenced as `${IATO_AUTH}` in `args`, working around Claude Desktop's args parser breaking on spaces inside inline header strings.

= 1.4.0 =
* New: `rollback` MCP tool. Reverses any prior write by `change_id`. Wraps the existing `wp-json/iato-mcp/v1/rollback` REST endpoint so Claude can undo a change in one MCP call instead of the user constructing a manual HTTP request. Validates the stored `before_value` to prevent tampering, dispatches by `target_type`, and marks the receipt rolled-back so it cannot be re-applied. Requires `edit_posts` (with elevated `manage_options` for `menu_item` and `redirect` receipts to mirror the original write capability).
* New: change receipts on `update_post` and `create_post`. Previously these two write tools returned no audit trail, so even though every other write tool emitted a receipt, the most common edits — title, content, excerpt, status, and net-new posts — couldn't be rolled back. `update_post` now records one receipt per actually-changed field (skipping no-op resends); `create_post` records `target_type=post, field=create`, and `rollback` reverses it via `wp_trash_post` (recoverable from the WP trash).
* New: `capabilities.rollback: true` in the `initialize` response so MCP clients can feature-detect rollback support without a `tools/list` round-trip — same pattern as the existing `capabilities.elementor.v2`.
* Migration: appends `rollback` to the saved `iato_mcp_tools` per-tool toggle option on first request after upgrade so existing installs see the new tool enabled by default. Same idempotent migration pattern used for the v2 Elementor tools in 1.3.5.

= 1.3.5 =
* Docs: corrected the FAQ entry that still claimed "30 built-in tools" — now reflects the v1.3.0 widget-grained Elementor surface (39 WordPress native + 12 IATO bridge = 51 total).
* Docs: added two example prompts demonstrating widget-grained edits ("Set every H2 heading in these Elementor posts to H1" and "Find all button widgets on the site and change their color to #ff0000") so the v2 capability is concrete for end users who don't know Elementor jargon.
* No code changes.

= 1.3.4 =
* Optimization: `update_elementor_widgets_bulk` no longer echoes `change_receipt` on per-result rows. Receipts are still persisted to the `iato_change_receipts` audit table; bulk callers who need them can query by post_id + applied_at. Saves ~120 bytes per result. Brings the canonical 4-page H1-flip benchmark response under the v2 spec's <2 KB hard target. Singleton `update_elementor_widget` and `update_elementor_patch` responses keep the slim receipt for backward-compat and convenience.

= 1.3.3 =
* Optimization: v2 write tools (`update_elementor_widget`, `update_elementor_patch`, `update_elementor_widgets_bulk`) now elide `previous_revision` from per-result responses unless the caller passed `if_revision`. Rationale: a client that passed `if_revision` already knows the prior hash (echoing back confirms what the server saw on conflict), and a client that didn't pass it doesn't need it on the wire — they get `current_revision` to chain the next write. Saves ~93 bytes per result; brings the canonical 4-page H1-flip benchmark response under the v2 spec's <2 KB hard target on the `op: replace` path.

= 1.3.2 =
* Fix: v2 write tools (`update_elementor_widget`, `update_elementor_patch`, `update_elementor_widgets_bulk`) used to echo a verbose `change_receipt` containing the entire `applied_patch` JSON-stringified into `before_value`. That duplicated the top-level `applied_patch` field on every response and pushed bulk-update payloads over the spec's <2 KB target on a 4-page sweep. The receipt's `before_value` was also semantically wrong (it should be the value being replaced, not the patch). Fixed both: storage rows now record the canonical `previous_revision` → `current_revision` pair, and the API response carries only the receipt id + metadata (`{change_id, target_type, field, applied_at}`). Full audit data still queryable from the `iato_change_receipts` table for rollback. Per-update savings ~0.6–0.8 KB; on a 4-page bulk sweep that's ~3 KB shaved off the wire.

= 1.3.1 =
* Fix: `update_elementor_widgets_bulk` and `find_elementor_widgets` no longer reject every request with `auth_denied`. The handlers were calling `current_user_can( 'edit_post', $post_id )` / `current_user_can( 'read_post', $pid )` per-target, but bearer-authenticated MCP requests don't establish a logged-in WP user — `wp_get_current_user()` returns 0, and meta-cap checks against post objects always fail. v1 tools sidestep this via `IATO_MCP_Auth::require_cap()`, which is a flag check that returns true for any bearer-authenticated request (per the documented "the plugin key grants full administrative access" auth model). The v2 handlers now match v1 semantics.
* Fix: idempotent one-shot migration on plugin update. Existing installs upgrading from 1.2.x to 1.3.x previously saw the nine new Elementor v2 tools auto-disabled because saved `iato_mcp_tools` per-tool toggle arrays didn't include the new names. The migration appends new tool names to the saved option on first request after upgrade. New installs unaffected.

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

= 1.4.6 =
Completes the rollback Settings UI fix from 1.4.5 by adding rollback to the second gating constant (`TOOL_CATEGORIES`) the rendering loop actually iterates — so the checkbox now actually appears under a new "Safety" category. Also unifies the inner server key in the Settings page config snippet from `wordpress` to `iato-wordpress` to match the dismissible notice.

= 1.4.5 =
Fixes the rollback MCP tool being invisible on the Settings page and silently stripped from `iato_mcp_tools` on every Settings save (a bug present since v1.4.0 introduced rollback). One-shot migration auto-restores rollback for affected installs on upgrade. Also makes the `initialize` capability advertisement honest about whether rollback is actually registered.

= 1.4.4 =
Fixes the OAuth flow: clicking Approve on the consent screen now correctly redirects back to the OAuth client (Claude, Cursor, etc.) with an authorization code instead of dumping users on /wp-admin. The connector framework on the client side then transitions to "Connected" as expected. Required for anyone trying to connect via Claude.ai's Add Connector or Claude Desktop's Connectors UI.

= 1.4.3 =
Restructures the dismissible "Ready to Connect" admin notice so its two connection methods (Connectors UI / Claude Desktop config file) are presented as mutually-exclusive options instead of a confusing three-step sequence. No code-path or auth-handler changes — purely a clarity fix in the onboarding notice.

= 1.4.2 =
Makes the Application Password auth path documented in the v1.4.1 setup wizard actually work (the auth handler was hard-rejecting non-Bearer requests, so wizard Methods 2 and 3 were returning 401). Also fixes the dismissible setup notice to emit a Claude-Desktop-compatible stdio-bridge config, and relabels the Settings page hero-card so its audience (HTTP MCP clients) is unambiguous. Recommended upgrade for anyone on 1.4.1.

= 1.4.1 =
Setup wizard restructured around the three real connection paths (Connectors UI / Direct HTTP / stdio bridge), and the stdio JSON snippet now references the actual `mcp-remote` npm package with an env-var credential pattern. Recommended for any new install; existing connections are unaffected.

= 1.4.0 =
Adds a `rollback` MCP tool and change-receipt coverage for `update_post` / `create_post`, closing the gap that previously left the two highest-volume write tools without an audit trail. Claude can now undo any tracked change in a single tool call.

= 1.3.5 =
Docs-only release: corrects a stale FAQ tool count and adds widget-flavored example prompts. No code changes; safe to skip if you've already updated to 1.3.4.

= 1.3.4 =
Drops `change_receipt` from `update_elementor_widgets_bulk` per-result rows (still persisted to the audit table; bulk callers query by post_id). Lands the 4-page H1-flip benchmark under the spec's <2 KB hard target. Singleton update tools unchanged.

= 1.3.3 =
Slims v2 write responses by ~93 bytes per result by eliding the previous_revision echo when the caller didn't pass if_revision. Lands the canonical 4-page bulk benchmark under the spec's <2 KB hard target.

= 1.3.2 =
Slims v2 write-tool responses by ~600 bytes per update by removing redundant change_receipt fields. Brings 4-page bulk sweeps under the 2 KB spec target. No API breakage — the slim receipt still carries the change_id for downstream lookup.

= 1.3.1 =
Fixes the Elementor v2 bulk + find tools rejecting every request with auth_denied (capability-check mismatch with the bearer auth model). Adds an idempotent migration so existing installs see the new v2 tools enabled automatically on upgrade. Required for anyone on 1.3.0.

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
