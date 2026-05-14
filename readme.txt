=== IATO MCP ===
Contributors: iatoai
Tags: mcp, ai, seo, sitemap, claude
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes an MCP server from any self-hosted WordPress site, enabling AI agents like Claude to audit and fix your site in a single workflow.

== Description ==

WordPress.com has a built-in MCP server. Now self-hosted WordPress does too.

[IATO MCP](https://iato.ai/wordpress-mcp) connects your WordPress site to Claude Desktop and other MCP-enabled AI clients. Once connected, you can ask Claude to audit your site and fix SEO issues, identify orphan pages, clean up broken links, and more — all in a single conversation.

https://www.youtube.com/watch?v=gSX6Vc9Yask

= How it works =

1. Install and activate the plugin
2. Follow the setup wizard — copy the config into Claude Desktop, or use "Add Custom Connector" with your site URL
3. Connect your [IATO account](https://iato.ai) for AI-powered analysis ([free trial](https://iato.ai) up to 500 pages)

= What Claude can do =

**Without an IATO account (45 WordPress tools):**

* Read and edit posts, pages, and media
* Create new posts and pages with excerpt support
* Update SEO titles and meta descriptions (Yoast SEO, RankMath, SEOPress)
* Update canonical URLs
* Update image alt text
* Upload new images to the media library (base64 by default; URL ingestion optional with admin allowlist + full SSRF protection)
* Set and clear the featured image on any post
* Read and write arbitrary post meta with a credential-key denylist and a known-safe theme/builder/SEO allowlist (force=true required outside the allowlist)
* Set per-post theme + builder page settings (hide title, sidebar layout, content layout, etc.) on Astra, Kadence, GeneratePress, and Elementor in one call
* Read and edit navigation menus
* Manage categories, tags, and taxonomy terms
* Manage JSON-LD structured data
* Manage redirect rules
* Read and write Elementor page builder data
* Widget-grained Elementor edits with optimistic concurrency, idempotency, and bulk operations
* Clone the styling of an existing Elementor post in one call via `update_elementor_data(..., inherit_settings_from: <id>)`
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

= Can AI clients upload arbitrary files to my media library? =

Only images, and only when the calling user has the `upload_files` capability. The `create_media` tool enforces an image-only MIME allowlist (JPEG, PNG, GIF, WebP, AVIF) verified against actual file bytes — the claimed mime_type is never trusted. SVG uploads are not supported in this release. Files exceeding the size cap (default 10MB) or the dimension cap (default 8000×8000) are rejected, as are filenames containing `.php`, `.phtml`, or `.htaccess`. URL-source ingestion is disabled by default; admins who enable it must also configure a host allowlist, and private/loopback/cloud-metadata IPs are rejected even for allowlisted hosts. Each upload counts against a per-user rate limit (default 20/min) and emits a `change_receipt` — rolling back fully deletes the attachment file. All four limits are configurable from Settings > IATO MCP.

== Screenshots ==

1. Settings page — MCP connection info with endpoint URL and API key
2. Settings page — IATO Platform configuration and tool toggles
3. Setup wizard — auto-generated Claude Desktop configuration
4. OAuth authorization screen — approve AI client connections

== Changelog ==

= 1.8.1 =
* Fix: `resolve_url` correctly identifies Elementor Theme Builder archive templates as the renderer for Yoast-stripped category URLs (e.g. `/build/` instead of `/category/build/`). Previously such URLs returned `route_type=404` even though a Theme Builder template was rendering them. Root cause was two independent bugs:
  - **Detect_archive_info gap.** The URL classifier only recognised the default `/category/<slug>/`, `/tag/<slug>/`, and `/author/<slug>/` patterns. Sites with Yoast's "Remove the categories prefix" enabled — or with a custom `category_base` / `tag_base` configured in Settings > Permalinks — served archives at URLs that didn't match those patterns, so the classifier returned null and the shadowing check had no archive context to evaluate against. A three-stage cascade now handles all three cases: default patterns first (zero new cost on standard sites), then configured-base patterns, then a bounded reverse lookup through `get_term_link()` that goes through any active `term_link` filter (Yoast, RankMath, etc.) and matches the resulting URL against the input.
  - **Shadowing-dispatch bug.** `detect_theme_builder_shadowing` treated Elementor Pro's `find_via_theme_builder_module` `false` return (Pro loaded but `get_documents_for_location` matched nothing) as authoritative, returning that `false` directly and never reaching the `find_via_conditions_meta` fallback. In REST context — which is 100% of MCP traffic — `get_documents_for_location` is bound to the current `$wp_query` (the REST endpoint, not the URL being asked about) so it consistently returns nothing; the dispatch bug meant the conditions-meta scan that DOES evaluate against our explicit URL context was unreachable on every Pro-installed site. Fix is a one-line change from `if ( null !== $found )` to `if ( is_array( $found ) )` so both `false` and `null` fall through to the meta scan; `array` (a positive match) still returns immediately. Bug has existed since v1.7.x; v1.8.0's archive plumbing landed correctly but the dispatch bug blocked it from firing.
* Bonus: the dispatch fix also restores singular page shadowing detection on Pro-installed sites. `get_post(id, include_shadowing:true)` now correctly surfaces `is_shadowed_by` when an Elementor Theme Builder single template overrides the slug-based render. Same dispatch bug had been blocking this since v1.7.x.
* `rendering_post_id` semantics UNCHANGED from v1.7.x. v1.8.0's additive `resolve_url` contract fully preserved — all new fields (`effective_render_id`, `template{}`, `shadowed_route_type`, etc.) populated correctly by v1.8.0 already; v1.8.1 just makes the shadowing detection that fills them actually fire in REST context.
* Documented limitation: plugins that strip the category base via raw `.htaccess` rewrites without using the `term_link` filter won't be detected by Stage 3 of `detect_archive_info`. Rare; rely on a `term_link`-based stripper (Yoast, RankMath, etc.) until a workaround is needed.

= 1.8.0 =
* Fix: `resolve_url` now resolves Elementor Theme Builder archive routes. Archive URLs served by a Theme Builder template (e.g. a category or CPT archive whose render is provided by an `elementor_library` document) previously returned `route_type=404` because the conditions evaluator only matched `include/singular/...` patterns against `url_to_postid()`'s post ID — which is 0 on archives. The conditions parser now evaluates `include/archive/...` patterns (taxonomies, terms, authors, CPT archives, `in_taxonomy`, `post_archive`) against URL-derived context, so archive shadowing is correctly detected. `find_via_theme_builder_module` also captures the matching location into `template_type`. The condition string that fired is surfaced as `template.condition_matched` for callers who need to see the match logic.
* New: `resolve_url` response gains four additive fields. `rendering_post_id` semantics are UNCHANGED — still the canonical/slug-based post (now normalized to `null` instead of `0` on archives so `=== null` checks work). The new fields are: `rendering_post_type`, `effective_render_id` (single field answering "what actually renders" — template ID when shadowed, canonical post ID otherwise, `null` only on a true 404), `effective_render_post_type`, `shadowed_route_type` (route the URL would have had absent the template), and a structured `template{template_id,template_type,condition_matched,builder}` object present when shadowing applies. `rendering_template_id` continues to work exactly as documented; the new fields are additive only.
* New: `find_elementor_widgets` auto-resolves revision IDs to their parent post. Passing a revision ID via `post_ids` previously returned matches tagged with the revision's own ID, leaking the parent only through the `NNN-revision-vN` slug — the brute-force discovery path that motivated this work. Revision IDs are now mapped to their parent via `wp_is_post_revision()`, deduped, and each match scanned from a revision input carries `resolved_from_revision_id` so callers can see the mapping. Default auto-scan also tightens `post_status` from `any` to `[publish, draft, pending, private]` — trash and auto-draft are no longer scanned by default.
* New: `find_elementor_widgets` filter gains a `contains` operator (case-insensitive substring match against scalar settings), alongside the existing `eq|ne|in|nin|exists`. Useful for finding a widget by its content rather than exact-match on a heading — e.g. `setting.editor.contains="some phrase"`. Scalar-only by design; never recurses into nested settings arrays. The `regex` operator is deferred to a future release where it can get proper backtracking-DoS guards.
* Docs: `get_posts`, `find_elementor_widgets`, and `resolve_url` descriptions now state the current scope honestly. `get_posts` notes that `post_type=any` expands to `[post, page]` and does NOT return `elementor_library`. `find_elementor_widgets` notes that templates are not yet scanned. Both point at the upcoming Layer 2 work.
* Note on disclosure surface: the new fields (`condition_matched`, `template_type`, `effective_render_*`) widen what an authenticated caller can learn about site structure on a per-URL basis. `resolve_url` continues to require only authentication (no capability check), unchanged from v1.7.x — Theme Builder shadowing was already disclosed per-URL since v1.5. The forthcoming template-listing tool (Layer 2) will gate full enumeration behind `edit_posts`.

= 1.7.2 =
* Fix: `create_media` no longer hard-rejects payloads that omit `source.type`. v1.7.1 began requiring an explicit `type` field, breaking callers that worked under v1.6.x where the type was inferred from the presence of `source.data` (base64) or `source.url` (URL ingestion). Restored that inference — supply either field and the right mode is picked automatically. Explicit `type` continues to work and remains the documented preferred form.
* Fix: URL ingestion no longer rejects the site's own host. Plugins installed on `example.com` could not ingest `https://example.com/wp-content/uploads/foo.jpg` without manually adding `example.com` to the URL allowlist — the allowlist defends against fetching arbitrary external hosts, not the site's own media library, and forcing every admin to allowlist their own domain was the most common papercut on this tool. The site's `home_url()` and `site_url()` host(s) are now implicitly trusted. The SSRF IP-resolution guard (`check_host_resolves_publicly`) still runs in both branches, so the bypass only skips the manual allowlist; private/loopback/link-local IPs continue to be rejected.
* Improved: `create_media` tool description rewritten to reflect real-world transport behaviour. Base64 is documented as suitable ONLY for tiny assets (favicons, sprite icons, ~4 KB of decoded image); larger JSON-RPC payloads are truncated by the MCP transport before reaching the plugin, which presents as the call hanging silently. URL ingestion is named as the path for anything bigger, with explicit warnings that share/viewer pages (Google Drive share links, Dropbox `?dl=0`) return HTML rather than the file. The previous "~100 KB" guidance was wrong in practice and trained agents to attempt uploads that would silently hang.
* Improved: `file_too_large` errors on the base64 path now mention URL ingestion as the alternative, mirroring the helpful tone of the existing `url_source_disabled` error. The agent gets a clear next step instead of an opaque size-cap message.

= 1.7.1 =
* Fix: the four Media Uploads settings shipped in 1.7.0 (`iato_mcp_media_url_source_enabled`, `iato_mcp_media_url_host_allowlist`, `iato_mcp_media_max_upload_size`, `iato_mcp_media_upload_rate_limit`) silently failed to persist on Save. The UI rendered correctly and the fields were properly registered with `register_setting()`, but the General-tab form is hijacked through an admin-ajax handler (some hosts 503 on `options.php` POSTs due to upstream WAF/timeout rules) and that handler hardcoded the keys it persisted — anything not explicitly listed fell off the floor. 1.7.1 extends `ajax_save_settings()` to call the matching sanitize-and-update path for each of the four media keys, mirroring the existing `iato_mcp_api_key` / `iato_mcp_crawl_id` / `iato_mcp_tools` lines.
* Docs: CLAUDE.md gains a "Release Checklist (adding a new admin setting)" section calling out the three places a new option must land — `register_setting()`, the rendered `<input name="...">`, and the AJAX persist handler. Same shape as the existing tool-release checklist; closes the structural failure mode that produced this 1.7.0 → 1.7.1 follow-up.

= 1.7.0 =
* New: `Settings > IATO MCP` now includes a Media Uploads card. The four media settings (`iato_mcp_media_url_source_enabled`, `iato_mcp_media_url_host_allowlist`, `iato_mcp_media_max_upload_size`, `iato_mcp_media_upload_rate_limit`) were registered and enforced at runtime in 1.6.0 but never surfaced in admin UI, so the only way to enable URL-source ingestion or configure the host allowlist was via WP-CLI or a direct database edit. The `url_source_disabled` error message returned by `create_media` continues to point admins to "Settings > IATO MCP > Media uploads" — that path now exists.
* New: Diagnostics page gains a "Recent media uploads" panel showing the last 100 `create_media` calls with their full per-phase trace, outcome badge, error code, attachment metadata (MIME, dimensions, size), and total duration. Each row expands inline via a native `<details>` element to show the phase-by-phase timing. Triage that previously required enabling `WP_DEBUG_LOG` mid-session and tailing the host's PHP error log is now one click on the Diagnostics tab. The on-disk `error_log()` mirror is preserved for environments that already aggregate logs centrally.
* New: backing infrastructure — `IATO_MCP_Media_Phase_Log` class and `{prefix}iato_mcp_media_phase_log` table store one ring-buffered row per `create_media` call. The deferred-subsizes cron path threads its `req_id` through `wp_schedule_single_event` and appends an `async-subsizes-done` phase to the parent row on completion, so a single Diagnostics row captures the entire end-to-end timeline of an upload — including the async tick that lands minutes later. DB writes are wrapped in try/catch so an observability hiccup cannot regress `create_media` itself. Table creation is dbDelta-idempotent and runs from both the activation hook and the migration gate, so upgraded installs pick it up without a reactivation.
* Improved: `create_media` tool description now includes practical guidance on when to use base64 vs URL ingestion. Base64 is reliable for small assets (icons, badges, screenshots under ~100 KB); URL ingestion is the recommended path for production-scale photography and requires admin opt-in via the new Media Uploads settings card.
* Fix: `uninstall.php` now drops the `iato_mcp_media_phase_log` table on plugin delete and cleans up `iato_mcp_db_version` plus the four `iato_mcp_media_*` options that the 1.6.0 release introduced without matching uninstall coverage.
* Audit: the other four tools from the 1.6.0 batch (`set_featured_image`, `update_post_meta`, `get_post_meta`, `set_page_settings`) were audited for the same "admin-controlled toggle without UI" pattern that the `create_media` URL-source feature exhibited. None of them read any `iato_mcp_*` options at runtime — the missing-UI gap was isolated to `create_media`. No changes to those four were required.

= 1.6.4 =
* Fix: `create_media` now actually accepts uploads under Bearer-token MCP auth. The v1.6.0 implementation gated the handler on `current_user_can('upload_files')` and `current_user_can('edit_post', $attach_to_post)`, but Bearer-authenticated MCP requests don't establish a logged-in WordPress user — `wp_get_current_user()` returns 0 and meta-cap checks against the empty user object always fail, so every call returned "You do not have permission to upload files." regardless of who initiated it. Switched to `IATO_MCP_Auth::require_cap()`, which honors the documented "plugin key grants full administrative access" auth model — exactly the same fix shape v1.3.1 applied to `update_elementor_widgets_bulk` and `find_elementor_widgets` for the same bug class. Audited the other v1.6.0 tools (`get_post_meta`, `update_post_meta`, `set_page_settings`, `set_featured_image`) and confirmed they all use `require_cap()` correctly — `create_media` was the only regression.

= 1.6.3 =
* New: `create_media` accepts `defer_subsizes: true` to skip the synchronous `wp_generate_attachment_metadata` call and schedule it via WP-Cron instead. The MCP response returns immediately with `attachment_id` and the canonical URL; intermediate sizes are generated on the next cron tick (typically within seconds). Recommended for any caller running through the Anthropic MCP gateway, which times out around 30 seconds — sites with image-optimisation pipelines (ShortPixel, Imagify, Smush, etc.) intercepting the metadata-generation hook routinely exceed that limit and produce silent hangs where the response never arrives but the attachment also never lands. The default remains synchronous so existing callers see no behaviour change.
* New: per-phase diagnostic logging in the `create_media` handler. Every call now writes `[iato-mcp create_media:<req_id>] phase=... elapsed=...s` lines to PHP's error log at each stage (entry, source resolve, MIME check, dimension check, sideload, attachment insert, subsize generation, return), including the byte counts and dimensions seen. When a call hangs or fails, the last line in `wp-content/debug.log` (or the host's PHP log) identifies which phase stalled — turning the previously-silent failure mode into a one-line diagnosis. The async cron handler logs its own line on completion with attachment_id, subsize count, and duration.
* Fix: `update_elementor_data` with `inherit_settings_from` now copies empty-string values from the source post instead of skipping them. WordPress's `get_post_meta` returns `''` for both stored-empty and absent keys, so the prior skip-empty rule turned out to silently drop meaningful Astra layout state on real cloning workflows (Astra stores per-post overrides as empty strings in some configurations, and skipping them caused targets to retain the wrong layout). The contract of `inherit_settings_from` is "make the target match the source"; that now happens uniformly.
* Fix: the default `inherit_keys` list on `update_elementor_data` is widened from 8 keys to 14 to cover the full Astra per-post override family (`site-content-style`, `site-sidebar-style`, `ast-global-header-display`, `ast-banner-title-visibility`, `ast-breadcrumbs-content`, `ast-featured-img`). Cloning a styled post now transfers the complete layout state in a single call, not just the original 4 brief-flagged keys.
* Refactor: `inherited_skipped[]` in the `update_elementor_data` response semantics changed from "source value was empty" to "source value matches target's existing value (no-op write)". Each entry now uses `reason: 'noop'`. The new shape surfaces the case where inherit_settings_from would have written a value but the target already had it — useful diagnostic, no behavioural cost.

= 1.6.2 =
* Refactor: the four hand-written `version_compare` migration blocks that backfill new tool names into the saved `iato_mcp_tools` option are replaced by a single declarative `TOOL_MIGRATION_BACKFILL` map on `IATO_MCP_Settings` plus a one-loop walker in `iato_mcp_maybe_run_migrations()`. Same behavior for every install that was already correctly migrated; the new shape eliminates the "remembered to add a migration block" failure mode that produced the 1.3.0 → 1.3.1 fix, the 1.4.0 → 1.4.5 fix, and the 1.6.0 → 1.6.1 fix. Adding a new tool now requires appending one line to the map alongside the `TOOL_NAMES` edit — colocated, hard to miss at review time.
* Fix: backfills the three crawl-management tools (`start_iato_crawl`, `get_iato_crawl_status`, `list_iato_crawls`) on installs that originally upgraded from 1.1.x to 1.2.x with a saved `iato_mcp_tools` option. v1.2.0 introduced those tools but shipped without the migration to append them, so any user who had saved their per-tool toggles in 1.1.x and configured an IATO API key has had the crawl-management tools invisibly disabled for the entire 1.2 → 1.6 interval. The 1.6.2 backfill catches them automatically on first request after upgrade. No-op for installs that already have the names in the saved option (idempotent), and no-op for fresh installs (the option starts empty and every tool is enabled by default).
* Fix: `update_elementor_data` with `inherit_settings_from` now returns an `inherited_skipped[]` array in the response listing keys that were in the configured inheritance list but absent on the source post (so the assistant can see which clone targets had no source value rather than silently getting fewer receipts than the default list implies). Each entry is `{ key, reason: 'source_empty' }`. The skip-empty behavior itself is unchanged — copying an explicit empty string from a source post that never set a key would stomp the target's existing value.

= 1.6.1 =
* Fix: the five new MCP tools added in 1.6.0 (`get_post_meta`, `update_post_meta`, `set_page_settings`, `set_featured_image`, `create_media`) now register correctly on sites upgrading from a previous version. v1.6.0 added them to the `TOOL_NAMES` constant but forgot the idempotent migration that appends new tool names to the saved `iato_mcp_tools` per-tool toggle option — the same migration shape used for the Elementor v2 tools in 1.3.5 and for `rollback` in 1.4.0/1.4.5. Without it, `is_tool_enabled()` filtered the new names out of the registry on every upgraded install, so the tools never appeared in `tools/list` despite shipping in the plugin. New installs were unaffected (the option is empty on first activation and all tools are enabled by default). Single one-shot migration; no-op for installs that already have the tool names in the saved option.

= 1.6.0 =
* New: `get_post_meta` and `update_post_meta` expose arbitrary post meta over MCP with a centralised security policy. A credential-shaped denylist (`*_token*`, `*_secret*`, `*_api_key*`, `*_password*`, `*_credential*`, `_oauth_*`, `_jwt_*`, `_refresh_token_*`, plus `wp_capabilities` and friends) is hard-rejected on writes and redacted on reads — `force=true` cannot override it. A known-safe allowlist of theme/builder/SEO prefixes (Astra `site-`/`ast-`, Elementor `_elementor_`, Yoast/RankMath/SEOPress, Kadence, GeneratePress, Genesis, plus `_wp_page_template` and `_thumbnail_id`) lets the assistant write the common cases without ceremony; anything outside both lists requires `force=true`. Every write emits a `change_receipt` rollback-able under the new `target_type=post_meta`. Closes the long-standing gap that left the assistant unable to touch per-post theme settings on Astra and similar themes.
* New: `set_page_settings` is a one-call convenience wrapper for the most common page-level settings cluster on Astra + Elementor sites. Pass abstract names like `hide_title: true`, `sidebar_layout: "no-sidebar"`, `content_layout: "page-builder"`, `disable_header`, `disable_footer`, `page_template`, or `elementor_page_settings` and the tool maps each to the right concrete meta key for the active theme. Astra-specific keys are silently skipped on non-Astra themes and surfaced in `skipped[]` so the agent can report them back to the user. Returns one `change_receipt` per concrete meta key written, so the whole settings cluster is reversible.
* New: `set_featured_image` finally closes the "create a post end-to-end" loop — the assistant can now set or clear `_thumbnail_id` directly instead of bouncing the user to wp-admin. Validates that the supplied attachment is an image, captures the previous thumbnail ID in the receipt, and rolls back via the same `post_meta` target_type.
* New: `create_media` uploads new images to the media library. Two source modes: `base64` (default and recommended — the WordPress server never makes an outbound HTTP request on agent input) and `url` (default-disabled; admins must explicitly enable it and add hosts to an allowlist before any fetch is attempted). The URL path runs full SSRF guards: DNS resolution + private/loopback/link-local/cloud-metadata IP rejection, hard timeout, redirect cap, and re-validation of every redirect destination's resolved IP. MIME is verified against actual file bytes (never the claimed mime_type), filenames containing `.php`, `.phtml`, `.phar`, or `.htaccess` are rejected, and SVG is hard-rejected this release regardless of how the upload is presented. Size (default 10MB) and dimension (default 8000×8000) caps are configurable; per-user rate limit (default 20/min) is enforced via a transient. Successful uploads return the attachment ID, public URL, generated intermediate sizes, and a `change_receipt` under the new `target_type=attachment` — rollback fully deletes the attachment file via `wp_delete_attachment(force=true)`.
* New: `update_elementor_data` gains `inherit_settings_from: <post_id>` and optional `inherit_keys` parameters. When set, the tool copies a curated default set of theme + Elementor page-level meta keys (`site-post-title`, `site-sidebar-layout`, `site-content-layout`, `ast-main-header-display`, `footer-sml-layout`, `_wp_page_template`, `_elementor_page_settings`, `_elementor_template_type`) from the source post to the target in the same MCP call, returning one `change_receipt` per inherited key in `change_receipts[]`. Collapses the "clone the styling of an existing post" workflow from four or more tool calls to one — and means the new post no longer renders with the wrong theme title bar above the Elementor content.
* New: four new admin settings under Settings > IATO MCP control upload behaviour — `iato_mcp_media_url_source_enabled` (default off), `iato_mcp_media_url_host_allowlist` (one host per line), `iato_mcp_media_max_upload_size` (bytes; default 10MB), and `iato_mcp_media_upload_rate_limit` (per-user per-minute; default 20). Existing installs upgrade with secure defaults — URL ingestion stays off until explicitly enabled.

= 1.5.0 =
* New: `update_post` accepts a `slug` parameter to rename a post's URL slug via MCP — previously the agent had no way to update a slug and the user had to do it manually in the WP editor. Input is strictly validated (lowercase a-z 0-9 and hyphens only, no leading/trailing/double hyphens, max 200 chars, must survive a `sanitize_title()` round-trip unchanged) and conflicts return a `slug_conflict` error with the colliding post's ID and title rather than silently appending `-2` like WordPress would. Changing the slug of a non-draft post additionally requires `confirm_url_break: true` since it breaks every inbound link — drafts are exempt. Slug changes are rollback-able the same way as title/content/status edits: each change emits a `change_receipt` and can be reversed via the `rollback` tool.
* New: `create_post` and `update_post` responses now include a `notice` field on page-builder-driven sites (Elementor, Divi, WPBakery, Beaver Builder) when the call would produce content that doesn't match the site's existing post format. On Elementor the notice tells the agent to fetch a reference post via `get_post` + `get_elementor_data` and apply its structure via `update_elementor_data`; on Divi/WPBakery/Beaver it tells the agent the layout must be finished in WP admin. On Gutenberg-only sites the field is absent — vanilla installs see no spurious warnings. Closes the gap that left agents creating posts with plain HTML on Elementor sites, producing structurally orphaned drafts that looked nothing like the rest of the site.
* New: the dynamic instructions injected into the MCP `initialize` response (added in 1.4.8) now include a NEW-POST WORKFLOW block whenever a non-Gutenberg builder is active. The block primes the agent to (1) ask the user for a reference post URL before calling `create_post`, (2) fetch the reference's structure, and (3) port that structure onto the new post — so the right path happens on the first call, not after the user notices the formatting problem.

= 1.4.10 =
* Fix: the JSON config snippets emitted by the plugin (setup wizard Method 3, dismissible "Ready to Connect" notice, Settings hero card) now use a unique-per-site inner `mcpServers` key derived from the WordPress site's hostname (e.g. `iato-garennebigby-dev`, `iato-dynomapper-com`) instead of the hardcoded `iato-wordpress`. Agencies managing multiple WordPress installs from a single AI client (Claude Desktop, Claude Code, etc.) can now paste config snippets from many IATO MCP installs into the same client config file without one silently overwriting another (JSON object keys are unique, so two snippets sharing a key was a silent collision). Existing connections that were set up with the old `iato-wordpress` key continue to work — the inner key is a display name only, not part of any HTTP request — so no migration is needed.

= 1.4.9 =
* Docs: added the plugin demo video to the top of the Description section on the WordPress.org plugin page (auto-embedded by WordPress.org's readme renderer when a YouTube URL is on its own line). No code changes; safe to skip if you've already updated to 1.4.8.

= 1.4.8 =
* New: dynamic page-builder-aware server instructions injected into the MCP `initialize` response. The plugin now detects which page-builder plugins are active on the WordPress site (Elementor, Divi, WPBakery, Beaver Builder, Gutenberg) and emits a context-specific instruction string telling the AI agent which write tools are correct for which builder, with a mandatory `get_page_builder` check-first rule before any content edit. Closes a class of silent-failure bug where `update_post` on an Elementor-built post would succeed at the database level but never reach the frontend (because Elementor stores content in `_elementor_data`, not `post_content`). Detected-but-unsupported builders (Divi, WPBakery, Beaver Builder for writes) are explicitly flagged so the agent tells the user to edit in the WP admin instead of attempting a write that won't take effect. Uses the standard MCP `instructions` field added in spec rev 2025-03-26; older clients on 2024-11-05 cleanly ignore the unknown field.
* New: `get_page_builder` now detects Beaver Builder posts (via `_fl_builder_enabled` post meta) and returns `beaver-builder`. Previously these posts fell through to the `gutenberg` or `classic` branch, misleading the agent about how to handle them.

= 1.4.7 =
* Fix: Settings → IATO MCP no longer presents the IATO Platform and Crawl Management tool toggles as functional when no IATO API key is configured. Previously the checkboxes appeared enabled and saveable, but bridge tool registration is gated by a separate condition at `iato-mcp.php:85` (the bridge tool files only `require_once` when the API key is non-empty), so the toggles were placebo — a user could check every box, save, and still get `Unknown tool: get_iato_sitemap` on every call with no UI signal explaining why. The toggle inputs in those two categories are now `disabled` when the API key is empty, the category card grays out (55% opacity), and an inline banner under the heading explains: "These tools require an IATO API key. Add it under 'IATO Platform' above to enable them — until then, these toggles have no effect." When the user pastes an API key and saves, the categories become interactive again.

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

= 1.4.10 =
The JSON config snippets the plugin emits now use a unique-per-site inner `mcpServers` key derived from the site's hostname (e.g. `iato-garennebigby-dev`) instead of the hardcoded `iato-wordpress`. Lets agencies paste config snippets from many WordPress installs into a single Claude Desktop config without silent overwrites. Existing connections keep working unchanged.

= 1.4.9 =
Docs-only release: adds the plugin demo video to the WordPress.org plugin page Description. No code changes. Safe to skip if you've already updated to 1.4.8.

= 1.4.8 =
Adds page-builder-aware safety rails to the MCP `initialize` response: a dynamic instructions string telling the AI agent which write tools to use for which builder, with a mandatory check-first rule before any content edit. Closes a silent-failure class where `update_post` on Elementor-built posts succeeded in the database but never reached the frontend. Also adds Beaver Builder per-post detection.

= 1.4.7 =
Fixes a misleading UX in Settings where IATO Platform and Crawl Management tool toggles appeared enabled even when no IATO API key was configured — making the checkboxes placebo. Toggles are now visually disabled with an inline hint until an API key is set. No backend or auth changes.

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
