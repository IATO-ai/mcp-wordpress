> *This file is the architecture reference for the IATO MCP WordPress plugin. It's named CLAUDE.md because it's the project context file consumed by Claude Code (Anthropic's terminal-based coding assistant). Human readers can use it as the technical architecture doc.*

---

# IATO WordPress MCP Plugin

WordPress plugin that exposes an MCP server from any self-hosted WordPress.org install, so AI clients (Claude Desktop, Cursor, etc.) can read and write WordPress content. When the user provides an IATO API key, additional bridge tools expose read-only crawl data (SEO issues, sitemap, orphan pages, broken links, suggestions).

MCP server URL once installed: `https://{site}/wp-json/iato-mcp/v1/message`

---

## Architecture

```
Claude Desktop / AI Client
        |  HTTP MCP transport (Streamable HTTP — one POST per request, no SSE for MVP)
        |
WordPress Plugin (iato-mcp)
  ├── wp-json/iato-mcp/v1/message     ← single JSON-RPC endpoint (tools, incl. `rollback`)
  ├── wp-json/iato-mcp/v1/rollback    ← REST rollback endpoint (same dispatch as the MCP tool)
  ├── class-mcp-server.php            ← MCP protocol handler
  ├── class-auth.php                  ← Application Password validation
  ├── class-oauth.php                 ← OAuth 2.0 server for Claude Desktop
  ├── class-iato-client.php           ← IATO REST API HTTP client
  ├── class-seo-adapter.php           ← Yoast / RankMath / SEOPress adapter
  ├── class-change-receipt.php        ← before/after audit trail for writes
  └── tools/
      ├── wp/        ← WP REST wrappers (posts, SEO, media, menus, taxonomy, Elementor, etc.)
      └── bridge/    ← IATO API → WP-slug-enriched read tools (require IATO API key)
                       |
                       v
                  IATO Platform (iato.ai/api)
```

Authentication: WordPress Application Passwords (WP 5.6+) and OAuth 2.0 with PKCE.
IATO API key: optional, stored in `get_option('iato_mcp_api_key')`, set via Settings > IATO MCP or the Setup Wizard.

**Autopilot / governance / Review Queue are intentionally not part of this release.** They were removed in the v1.0.0 scoping to get a clean MCP server into the WP.org directory. They will return in a future release, designed correctly (async sync, resilient to IATO outages, proper approve→apply→receipt wiring).

---

## MCP Protocol

JSON-RPC 2.0 over HTTP POST. Three methods:

- `initialize` — return server info and capabilities
- `tools/list` — return all registered tool definitions
- `tools/call` — dispatch to the correct tool handler, return result

All tools return an array with a single `content` item of type `text` containing JSON:
```php
return [
    'content' => [[
        'type' => 'text',
        'text' => wp_json_encode($data),
    ]]
];
```

On error, return `isError: true` with a message — never throw exceptions out of tool handlers.

---

## Coding Rules

- Use `wp_remote_post` / `wp_remote_get` — never curl directly
- Sanitize all option reads: `sanitize_text_field(get_option(...))`
- All tool handlers must return `WP_Error` on failure, never die/exit
- Nonces not used on MCP endpoint (it uses Application Password / OAuth auth)
- Capability check on every write tool: `current_user_can('edit_posts')` minimum
- Admin-only tools (menus, settings, taxonomy write): `current_user_can('manage_options')`
- Never write to wp_options without `sanitize_*` on the value
- Dry-run mode for destructive write tools: accept `dry_run: true`, return what *would* change
- All files namespaced under `IATO_MCP_` prefix for constants, `IATO_MCP` for classes
- Plugin slug: `iato-mcp`, text domain: `iato-mcp`
- Every IATO API call sends `X-IATO-Plugin-Version` and `X-IATO-Plugin-Capabilities: mcp-read` headers (set in `class-iato-client.php::default_headers()`)

---

## Release Checklist (adding or removing tools)

When adding a new MCP tool, three edits must land together — missing any of them produces a tool that exists in code but is invisible on upgraded installs (the bug class that produced the v1.3.0 → v1.3.1, v1.4.0 → v1.4.5, and v1.6.0 → v1.6.1 follow-up patches):

1. Register the handler with `IATO_MCP_Server::register_tool()` in the appropriate `includes/tools/wp/tool-*.php` or `includes/tools/bridge/tool-*.php` file.
2. Append the tool name to the `TOOL_NAMES` constant in `includes/class-settings.php`, plus a matching entry in `TOOL_DESCRIPTIONS` and a category placement in `TOOL_CATEGORIES`.
3. Append the tool name to `IATO_MCP_Settings::TOOL_MIGRATION_BACKFILL` under a gate equal to the release version it ships in. `iato_mcp_maybe_run_migrations()` walks this map once on upgrade and appends any missing names to the saved `iato_mcp_tools` option, so upgraded installs see the new tool the next time they make any request.

Step 3 is the one that historically gets missed. The backfill map lives next to `TOOL_NAMES` in the same file specifically so a reviewer can eyeball the two lists side-by-side and confirm parity. Adding a new tool without a matching backfill entry is the failure mode; the loop walker in `iato-mcp.php` handles every other detail.

Removing a tool is the reverse: take it out of `TOOL_NAMES` and from any category placement; leave `TOOL_MIGRATION_BACKFILL` alone (historical entries are still correct).

---

## Tool Registry

### WP Native Tools

| Tool name | File | Capability |
|---|---|---|
| `get_site_info` | tools/wp/tool-site.php | read |
| `get_site_settings` | tools/wp/tool-site.php | manage_options |
| `get_posts` | tools/wp/tool-posts.php | read |
| `get_post` | tools/wp/tool-posts.php | read |
| `create_post` | tools/wp/tool-posts.php | edit_posts |
| `update_post` | tools/wp/tool-posts.php | edit_posts |
| `search_posts` | tools/wp/tool-posts.php | read |
| `get_seo_data` | tools/wp/tool-seo.php | read |
| `update_seo_data` | tools/wp/tool-seo.php | edit_posts |
| `get_media` | tools/wp/tool-media.php | read |
| `update_alt_text` | tools/wp/tool-media.php | edit_posts |
| `get_comments` | tools/wp/tool-comments.php | read |
| `get_menus` | tools/wp/tool-menus.php | read |
| `get_menu_items` | tools/wp/tool-menus.php | read |
| `update_menu_item` / `create_menu_item` / `delete_menu_item` / `update_menu_item_details` | tools/wp/tool-menus.php | manage_options |
| `get_terms` | tools/wp/tool-taxonomy.php | read |
| `assign_term` / `create_term` / `update_term` / `delete_term` / `update_taxonomy` | tools/wp/tool-taxonomy.php | edit_posts |
| `update_canonical` | tools/wp/tool-canonical.php | edit_posts |
| `update_structured_data` | tools/wp/tool-structured-data.php | edit_posts |
| `update_redirect` | tools/wp/tool-redirects.php | manage_options |
| `get_page_builder` / `get_elementor_data` / `update_elementor_data` | tools/wp/tool-page-builder.php | read / edit_posts |
| `list_elementor_widgets` / `get_elementor_widget` | tools/wp/tool-elementor-widgets.php | read |
| `update_elementor_widget` / `update_elementor_patch` | tools/wp/tool-elementor-widgets.php | edit_posts |
| `update_elementor_widgets_bulk` | tools/wp/tool-elementor-bulk.php | edit_posts |
| `find_elementor_widgets` | tools/wp/tool-elementor-bulk.php | read |
| `set_heading_level` / `set_widget_setting` | tools/wp/tool-elementor-helpers.php | edit_posts |
| `resolve_url` | tools/wp/tool-resolve-url.php | read |
| `rollback` | tools/wp/tool-rollback.php | edit_posts (manage_options for menu_item / redirect receipts) |
| `get_post_meta` | tools/wp/tool-post-meta.php | edit_posts |
| `update_post_meta` | tools/wp/tool-post-meta.php | edit_posts |
| `set_page_settings` | tools/wp/tool-page-settings.php | edit_posts |
| `set_featured_image` | tools/wp/tool-featured-image.php | edit_posts |
| `create_media` | tools/wp/tool-media-upload.php | upload_files |

`get_post` accepts an opt-in `include_shadowing: true` parameter that attaches `is_shadowed_by` when an Elementor Theme Builder template overrides the slug-based render. Default is off so the hot path stays fast.

`get_elementor_data` accepts a `format` parameter (`raw` | `compact` | `summary`). All v2 reads include a `revision` hash for use with `if_revision` guards on writes.

`update_post` accepts a `slug` parameter alongside title/content/excerpt/status. Slug input is strictly validated (lowercase a-z/0-9/hyphens, no leading/trailing/double hyphens, max 200 chars, must round-trip through `sanitize_title()` unchanged) and conflicts return `slug_conflict` rather than auto-suffixing. Changing the slug of a non-draft post additionally requires `confirm_url_break: true` since it breaks inbound links. `create_post` and `update_post` responses include a `notice` field on builder-driven sites (Elementor / Divi / WPBakery / Beaver Builder) — on Elementor it tells the agent to fetch a reference post and use the Elementor widget tools; on the others it tells the agent the layout must be finished in WP admin. The `notice` field is absent on Gutenberg-only sites.

`rollback` reverses a prior write by `change_id`. Any write tool that returns a `change_receipt` (or `change_receipts[]`) can be undone in one MCP call — Claude passes the `change_id` back to `rollback`, which validates the stored before_value, dispatches by `target_type`, and marks the receipt rolled-back so it cannot be re-applied. Wraps `IATO_MCP_Rollback::rollback_by_id` (the same dispatch path used by the REST endpoint at `wp-json/iato-mcp/v1/rollback`). Receipt `target_type` values: `post`, `page`, `image`, `menu_item`, `taxonomy`, `redirect`, `elementor_widget`, `post_meta`, `attachment`. `update_post` records one receipt per changed field (`title`, `content`, `slug`, `excerpt`, `status`); `create_post` records `target_type=post, field=create` and rolls back via `wp_trash_post`. `update_post_meta`, `set_page_settings`, and `set_featured_image` all record under `target_type=post_meta` with the meta key in `field` — rollback restores the previous value or deletes the key if `before_value` was null. `create_media` records `target_type=attachment, field=create` and rolls back via `wp_delete_attachment(force=true)` (the underlying file is removed).

The `initialize` response advertises `capabilities.elementor.v2: true` when Elementor is active, plus `capabilities.rollback: true` always — clients can feature-detect without a `tools/list` round-trip.

### IATO Bridge Tools (require IATO API key)

All bridge tools return `WP_Error` if the API key is missing. Each resolves IATO URLs to WordPress post IDs and slugs before returning.

| Tool name | File | IATO endpoint |
|---|---|---|
| `get_iato_sitemap` | tools/bridge/tool-sitemap.php | `/sitemaps`, `/sitemaps/{id}/nodes` |
| `get_iato_nav_audit` | tools/bridge/tool-nav-audit.php | `/crawl/jobs/{id}/navigation/menus`, `/items`, `/links/orphan` |
| `get_iato_orphan_pages` | tools/bridge/tool-orphans.php | `/crawl/jobs/{id}/links/orphan` |
| `get_iato_taxonomy` | tools/bridge/tool-taxonomy.php | `/crawl/jobs/{id}/taxonomy/tree` |
| `get_iato_seo_fixes` | tools/bridge/tool-seo-fixes.php | `/crawl/jobs/{id}/issues` |
| `get_iato_content_gaps` | tools/bridge/tool-content-gaps.php | `/crawl/jobs/{id}/pages` |
| `get_iato_broken_links` | tools/bridge/tool-broken-links.php | `/crawl/jobs/{id}/broken-links` |
| `get_iato_suggestions` | tools/bridge/tool-suggestions.php | `/crawl/jobs/{id}/suggestions` (auto-triggers `/suggestions/generate`) |
| `get_iato_perf_report` | tools/bridge/tool-perf.php | `/crawl/jobs/{id}/performance` |
| `start_iato_crawl` | tools/bridge/tool-start-crawl.php | `POST /crawl/start` (admin only — consumes platform quota) |
| `get_iato_crawl_status` | tools/bridge/tool-crawl-status.php | `/crawl/jobs/{id}` |
| `list_iato_crawls` | tools/bridge/tool-list-crawls.php | `/crawl/jobs` |

---

## IATO API — response envelope

Every response: `{ success, data, error, meta, _actions }`.

List endpoints return the list under an endpoint-specific key inside `data` — not a generic `items` key. Bridge tools must key by the correct canonical name:

| Endpoint | List key |
|---|---|
| `/crawl/jobs` | `data.jobs` |
| `/crawl/jobs/{id}/issues` | `data.issues` |
| `/crawl/jobs/{id}/pages` | `data.pages` |
| `/crawl/jobs/{id}/broken-links` | `data.broken_pages` + `data.broken_resources` + `data.summary` (split) |
| `/crawl/jobs/{id}/links/orphan` | `data.pages` |
| `/crawl/jobs/{id}/performance` | `data.slowest_pages` + `data.largest_pages` + `data.summary` (split) |
| `/crawl/jobs/{id}/navigation/menus` | `data.menus` |
| `/crawl/jobs/{id}/navigation/items` | `data.items` |
| `/crawl/jobs/{id}/taxonomy/tree` | `data.tree` |
| `/crawl/jobs/{id}/suggestions` | `data.suggestions` |
| `POST /crawl/jobs/{id}/suggestions/generate` | `data.suggestions` |
| `/sitemaps` | `data.sitemaps` |
| `/sitemaps/{id}/nodes` | `data.nodes` |
| `/workspaces` | `data.workspaces` (dual-key fallback to bare `workspaces` for one release; drop in v1.1) |

---

## SEO Plugin Adapter

`class-seo-adapter.php` detects which SEO plugin is active and reads/writes accordingly.

Priority order:
1. Yoast SEO (`wordpress-seo/wp-seo.php`) — meta keys: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`
2. RankMath (`seo-by-rank-math/rank-math.php`) — meta keys: `rank_math_title`, `rank_math_description`
3. SEOPress (`seopress/seopress.php`) — meta keys: `_seopress_titles_title`, `_seopress_titles_desc`
4. Fallback — native WP title, empty description

Detection via `is_plugin_active()`. Cache result in a static property for the request lifetime.

---

## `get_iato_seo_fixes` — issue types

The bridge tool handles five fixable issue types from IATO's SEO issues endpoint:

| Issue type | Can auto-fix | Fix method |
|---|---|---|
| `title` | Yes | SEO adapter `update_title()` |
| `meta_description` | Yes | SEO adapter `update_description()` |
| `alt_text` | Yes | `update_alt_text` WP tool |
| `h1_missing` | No | Return as `fix_type: manual` with instructions |
| `h1_duplicate` | No | Return as `fix_type: manual` with instructions |
| `canonical` | No | Return as `fix_type: manual` with instructions |

Manual fix items include `manual_instructions` in the response so Claude can surface them to the user.

---

## WP REST endpoints used on the write side

```
POST /wp/v2/posts/{id}               update post (title, content, excerpt, status, meta)
POST /wp/v2/media/{id}               update alt text
POST /wp/v2/menu-items               create/update menu item
(all others are executed via WP core functions: wp_update_post, update_post_meta, wp_set_object_terms, etc.)
```

---

## Settings Page

`includes/class-settings.php` — registers `Settings > IATO MCP`. Fields:
- IATO API Key (password input, validated on save)
- Default crawl ID (text, used as fallback when bridge tools aren't passed a `crawl_id`)
- Per-tool enable/disable checkboxes
- Media uploads (v1.6.0): `iato_mcp_media_url_source_enabled` (bool, default false), `iato_mcp_media_url_host_allowlist` (hostnames, one per line), `iato_mcp_media_max_upload_size` (bytes, default 10MB), `iato_mcp_media_upload_rate_limit` (per-user per-minute, default 20)

No governance policy UI, no autopilot toggle, no resync.

---

## Post Meta Policy

`includes/class-meta-policy.php` — shared by `get_post_meta`, `update_post_meta`, `set_page_settings`, `set_featured_image`, and `update_elementor_data` (when `inherit_settings_from` is used).

Two layers:
1. **Denylist** (hard-reject even with `force=true`): keys matching credential / auth / capability patterns — `*_token*`, `*_secret*`, `*_api_key*`, `*_password*`, `*_credential*`, `_oauth_*`, `_jwt_*`, `_refresh_token_*`, `wp_capabilities`, `wp_user_level`, `wp_user_roles`, `session_tokens`, `wp_2fa_*`. Case-insensitive substring match.
2. **Allowlist** (writable without `force=true`): public custom meta (any key not starting with `_`) plus known-safe prefixes — `site-`, `ast-`, `footer-sml-`, `_elementor_`, `_wp_page_template`, `_thumbnail_id`, `_yoast_`, `_genesis_`, `_kadence_`, `_generate_`, `rank_math_`, `_seopress_`.

Anything not in the denylist and not in the allowlist requires `force=true` on writes and returns `meta_requires_force`. `get_post_meta` redacts denylist hits unconditionally and filters out underscore-prefixed keys outside the allowlist unless `include_protected=true`.

---

## Theme Adapter

`includes/class-theme-adapter.php` — detects Astra, Kadence, GeneratePress (mirrors the SEO adapter's static-cache pattern). `set_page_settings` uses `map_page_settings()` to expand abstract setting names (`hide_title`, `sidebar_layout`, `content_layout`, `disable_header`, `disable_footer`, `page_template`, `elementor_hide_title`, `elementor_page_settings`) into the concrete `(meta_key, value)` writes each theme + Elementor combination needs. Keys whose target theme isn't active are reported in `skipped[]` rather than silently dropped.

---

## Media Uploader

`includes/class-media-uploader.php` — implements `create_media`. Sources:
- `base64` (default, recommended): bytes decoded in-process; no outbound HTTP.
- `url` (opt-in via setting + host allowlist): SSRF guards resolve the host, reject private/loopback/link-local/cloud-metadata IPs, use `wp_safe_remote_get` with hard timeout + redirect cap, re-validate every redirect destination IP.

Always: MIME verified via `wp_check_filetype_and_ext()` against actual bytes (not the claimed `mime_type`); image-only allowlist (`jpeg`, `png`, `gif`, `webp`, `avif`); SVG hard-rejected this release; size + dimension caps; per-user rate limit transient. Uses `wp_handle_sideload()` + `wp_generate_attachment_metadata()` for the actual filesystem write and intermediate-size generation.

---

## Setup Wizard

`includes/class-setup-wizard.php` — single-screen flow at `admin.php?page=iato-mcp-setup`. Shows:
1. MCP server URL
2. Link to generate an Application Password
3. Claude Desktop JSON config snippet
4. Optional IATO API key field

Dismissed via `update_option('iato_mcp_setup_complete', true)`.

---

## Diagnostics

`includes/class-diagnostics.php` — `Settings > IATO MCP Diagnostics`. Shows:
- MCP endpoint URL and plugin version
- IATO API key validation state
- Last 50 MCP JSON-RPC requests from the call log (method, tool, status, duration, errors)
- "Clear log" button

No IATO-side probes; the page does not make blocking HTTP calls on render.

---

## File naming

- Classes: `class-{name}.php` in `includes/`
- Tools: `tool-{name}.php` in `includes/tools/wp/` or `includes/tools/bridge/`
- Main plugin file: `iato-mcp.php`
- All class files loaded via `require_once` in the main plugin file, not an autoloader
