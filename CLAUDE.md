# IATO WordPress MCP Plugin

Free WordPress plugin that exposes an MCP server from any self-hosted WordPress.org install,
enabling the same IATO analyze-and-fix workflow available to WordPress.com users.

MCP server URL once installed: `https://{site}/wp-json/iato-mcp/v1/message`

---

## Architecture

```
Claude Desktop / AI Client
        |  HTTP MCP transport (Streamable HTTP — one POST per request, no SSE for MVP)
        |
WordPress Plugin (iato-mcp)
  ├── wp-json/iato-mcp/v1/message     ← single JSON-RPC endpoint
  ├── class-mcp-server.php            ← MCP protocol handler
  ├── class-auth.php                  ← Application Password validation
  ├── class-iato-client.php           ← IATO REST API HTTP client
  ├── class-seo-adapter.php           ← Yoast / RankMath / SEOPress adapter
  └── tools/
      ├── wp/                         ← native WP REST wrappers (Phase 1)
      └── bridge/                     ← IATO API → WP-slug-enriched output (Phase 2)
              |
              | (user's IATO API key, stored in wp_options encrypted)
              |
IATO Platform (iato.ai/api)
```

Authentication: WordPress Application Passwords (WP 5.6+). No OAuth server needed.
IATO API key: stored via `get_option('iato_mcp_api_key')`, set in Settings > IATO MCP.

---

## MCP Protocol

Implement JSON-RPC 2.0 over HTTP POST. Three methods required:

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

## Coding Rules (enforce every session)

- Use `wp_remote_post` / `wp_remote_get` — never curl directly
- Sanitize all option reads: `sanitize_text_field(get_option(...))`
- All tool handlers must return `WP_Error` on failure, never die/exit
- Nonces not used on MCP endpoint (it uses Application Password auth instead)
- Capability check on every write tool: `current_user_can('edit_posts')` minimum
- Admin-only tools (menus, settings, taxonomy write): `current_user_can('manage_options')`
- Never write to wp_options without `sanitize_*` on the value
- Dry-run mode for destructive write tools: accept `dry_run: true` param, return what *would* change
- All files namespaced under `IATO_MCP_` prefix for constants, `IATO_MCP` for classes
- Plugin slug: `iato-mcp`, text domain: `iato-mcp`

---

## Tool Registry

### Phase 1 — WP Native Tools (10 tools)

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
| `update_menu_item` | tools/wp/tool-menus.php | manage_options |
| `get_terms` | tools/wp/tool-taxonomy.php | read |
| `assign_term` | tools/wp/tool-taxonomy.php | edit_posts |

### Phase 2 — IATO Bridge Tools (9 tools)

All bridge tools require IATO API key in options. Return `WP_Error` if key missing.
Each bridge tool resolves IATO node/page IDs to WordPress post IDs and slugs before returning.

| Tool name | File | IATO tools called |
|---|---|---|
| `get_iato_sitemap` | tools/bridge/tool-sitemap.php | list_sitemaps, get_sitemap |
| `get_iato_nav_audit` | tools/bridge/tool-nav-audit.php | get_menus, get_menu_items, find_orphan_pages |
| `get_iato_orphan_pages` | tools/bridge/tool-orphans.php | find_orphan_pages |
| `get_iato_taxonomy` | tools/bridge/tool-taxonomy.php | get_taxonomy |
| `get_iato_seo_fixes` | tools/bridge/tool-seo-fixes.php | get_seo_issues, run_seo_audit |
| `get_iato_content_gaps` | tools/bridge/tool-content-gaps.php | get_low_performing_pages, get_content_metrics |
| `get_iato_broken_links` | tools/bridge/tool-broken-links.php | get_crawl_analytics |
| `get_iato_suggestions` | tools/bridge/tool-suggestions.php | generate_suggestions |
| `get_iato_perf_report` | tools/bridge/tool-perf.php | get_crawl_analytics, get_low_performing_pages |

### Pass-through (no bridge needed — Claude calls IATO MCP directly)
- `get_page_content`, `search_pages` — IATO MCP
- `get_seo_score`, `get_audit_history` — IATO MCP

---

## SEO Plugin Adapter

`class-seo-adapter.php` must detect which SEO plugin is active and read/write accordingly.

Priority order:
1. Yoast SEO (`wordpress-seo/wp-seo.php`) — meta keys: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`
2. RankMath (`seo-by-rank-math/rank-math.php`) — meta keys: `rank_math_title`, `rank_math_description`
3. SEOPress (`seopress/seopress.php`) — meta keys: `_seopress_titles_title`, `_seopress_titles_desc`
4. Fallback — native WP title, empty description

Detection via `is_plugin_active()`. Cache result in a static property for the request lifetime.

---

## get_iato_seo_fixes — Extended Issue Types

The bridge tool must handle all five fixable issue types from IATO's `get_seo_issues`:

| Issue type | Can auto-fix | Fix method |
|---|---|---|
| `title` | Yes | SEO adapter `update_title()` |
| `meta_description` | Yes | SEO adapter `update_description()` |
| `h1_missing` | No | Return as `fix_type: manual` |
| `h1_duplicate` | No | Return as `fix_type: manual` |
| `alt_text` | Yes | `update_alt_text` WP tool |
| `canonical` | No | Return as `fix_type: manual` |

Manual fix items must include `manual_instructions` in the response so Claude can present them to the user as actionable guidance.

---

## WP REST Endpoints Used (plugin write side)

```
GET  /wp/v2/posts                    list posts
POST /wp/v2/posts/{id}               update post (title, content, status, meta)
GET  /wp/v2/media                    list media
POST /wp/v2/media/{id}               update alt text
GET  /wp/v2/menus                    list nav menus (requires WP 6.3+)
GET  /wp/v2/menus/{id}/menu-items    list menu items
POST /wp/v2/menu-items               create/update menu item
GET  /wp/v2/categories               list categories
GET  /wp/v2/tags                     list tags
GET  /wp/v2/settings                 site settings
```

---

## IATO API Client

Base URL: `https://iato.ai/api/v1`
Auth: `Authorization: Bearer {iato_api_key}` header
All requests via `wp_remote_*` with 30s timeout.

Key endpoints:
```
GET  /crawls                         list crawls
GET  /crawls/{id}/analytics          crawl analytics
GET  /crawls/{id}/seo-issues         SEO issues
GET  /crawls/{id}/seo-score          SEO score
GET  /crawls/{id}/pages              pages list
GET  /crawls/{id}/pages/{page_id}    page detail
GET  /sitemaps                       list sitemaps
GET  /sitemaps/{id}/nodes            sitemap node tree
GET  /sitemaps/{id}/menus            navigation menus
GET  /sitemaps/{id}/orphans          orphan pages
GET  /sitemaps/{id}/taxonomy         taxonomy
POST /crawls/{id}/suggestions        generate AI suggestions
```

---

## Settings Page

`includes/class-settings.php` — registers `Settings > IATO MCP` admin page.

Fields:
- IATO API Key (password input, stored encrypted in wp_options)
- Default crawl ID (text, used as fallback when bridge tools aren't passed a crawl_id)
- Enable/disable individual tools (checkboxes per tool name)

Validate API key on save by calling `GET /api/v1/workspaces` and checking 200 response.

---

## Setup Wizard (Admin Notice)

On plugin activation, show an admin notice with:
1. Link to WP Admin > Users > Profile > Application Passwords to generate a password
2. The exact JSON snippet to paste into Claude Desktop config (auto-populated with site URL)
3. Link to Settings > IATO MCP to enter API key

Dismiss via `update_option('iato_mcp_wizard_dismissed', true)`.

---

## Phase Boundaries

**Phase 1 (MVP):** Main plugin file, MCP server class, auth, HTTP transport, all WP native tools,
settings page, setup wizard, SEO adapter (Yoast + RankMath). No bridge tools yet.

**Phase 2:** All 9 bridge tools, IATO API client class, SEOPress adapter, menu write tools,
dry-run mode on destructive tools, taxonomy write tools.

**Phase 3:** WP.org readme.txt, assets (banner, icon), submission.

Do not implement Phase 2 tools during Phase 1 sessions unless explicitly asked.

---

## File Naming

- Classes: `class-{name}.php` in `includes/`
- Tools: `tool-{name}.php` in `includes/tools/wp/` or `includes/tools/bridge/`
- Main plugin file: `iato-mcp.php`
- All class files loaded via `require_once` in main plugin file, not autoloader
