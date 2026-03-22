<p align="center">
  <img src="iato-logo.png" alt="IATO" width="280" />
</p>

<h1 align="center">IATO MCP — User's Guide</h1>

<p align="center">
  Connect your self-hosted WordPress site to Claude Desktop and other AI clients.<br>
  Audit SEO, fix broken links, clean up navigation, and manage content — all from a single conversation.
</p>

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Connecting an AI Client](#connecting-an-ai-client)
3. [Settings & Configuration](#settings--configuration)
4. [WordPress Tools](#wordpress-tools)
5. [IATO Bridge Tools](#iato-bridge-tools)
6. [Sync Tools](#sync-tools)
7. [SEO Plugin Support](#seo-plugin-support)
8. [Dry-Run Mode](#dry-run-mode)
9. [Example Prompts](#example-prompts)
10. [Troubleshooting](#troubleshooting)

---

## Getting Started

### Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- Works on all hosting environments including shared hosting

### Installation

1. Upload the plugin files to `/wp-content/plugins/iato-mcp/`, or install via the WordPress plugin directory.
2. Activate the plugin from **Plugins** in your WordPress admin.
3. A setup wizard appears automatically — follow the steps to connect your first AI client.
4. Optionally, go to **Settings → IATO MCP** to enter your IATO API key for the full analysis pipeline.

### What You Get

| | Without IATO Account | With <img src="iato-icon.png" alt="IATO" width="16" align="top" /> IATO Account (free up to 500 pages) |
|---|---|---|
| **Tools** | 17 WordPress tools | 17 WordPress + 12 IATO tools |
| **Read content** | Posts, pages, media, menus, taxonomy, comments | Everything in WordPress tools |
| **Edit content** | Create/update posts, SEO, alt text, menus, taxonomy | Everything in WordPress tools |
| **Site audit** | — | Full SEO audit with auto-fix |
| **Broken links** | — | Detected and mapped to source posts |
| **Orphan pages** | — | Pages missing from navigation |
| **Content gaps** | — | Thin content flagged with recommendations |
| **AI suggestions** | — | Prioritized improvements across all areas |
| **Sync to IATO** | — | Push pages, taxonomy, and SEO metadata |

---

## Connecting an AI Client

IATO MCP works with any MCP-enabled AI client: Claude Desktop, Cursor, VS Code with GitHub Copilot, and others.

### Option A: Paste the JSON Config (Simplest)

1. Go to **Settings → IATO MCP** in your WordPress admin.
2. Copy the JSON config from the **Claude Desktop Configuration** card.
3. Open Claude Desktop → Settings → Developer → Edit Config.
4. Paste the config and save.

The config looks like this:

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://yoursite.com/wp-json/iato-mcp/v1/message",
      "headers": {
        "Authorization": "Bearer your-api-key-here"
      }
    }
  }
}
```

### Option B: Add Custom Connector (OAuth)

1. In Claude Desktop, go to **Settings → Integrations → Add Custom Connector**.
2. Enter your site URL (e.g., `https://yoursite.com`).
3. Claude Desktop discovers the MCP server automatically via OAuth metadata.
4. Log in to your WordPress admin when prompted.
5. Approve the permissions on the authorization screen.

This method uses OAuth 2.0 with PKCE — no manual key copying required.

### MCP Endpoint

Once connected, all communication goes through a single endpoint:

```
POST https://yoursite.com/wp-json/iato-mcp/v1/message
```

Each request is a standard HTTP POST — no long-lived connections or WebSockets needed.

---

## Settings & Configuration

Navigate to **Settings → IATO MCP** in your WordPress admin.

### MCP Connection

- **Endpoint URL** — Your MCP server URL. Share this with AI clients.
- **API Key** — A 32-character key generated on plugin activation. Used in the `Authorization: Bearer` header.
- **Regenerate Key** — Creates a new key (invalidates the old one — you'll need to update your AI client config).

### <img src="iato-icon.png" alt="" width="22" align="top" /> IATO Platform

- **IATO API Key** — Enter your key from the [IATO dashboard](https://iato.ai). This unlocks 12 additional bridge and sync tools.
- **Default Crawl ID** — Optional. Bridge tools use this crawl ID when none is specified in the request.

The API key is validated against the IATO API when you save settings. A green "Connected" badge confirms a valid key.

### Tool Toggles

Every tool can be individually enabled or disabled. Tools are organized by category:

| Category | Tools |
|----------|-------|
| Content | `get_posts`, `get_post`, `create_post`, `update_post`, `search_posts` |
| Site | `get_site_info`, `get_site_settings` |
| SEO | `get_seo_data`, `update_seo_data` |
| Media | `get_media`, `update_alt_text` |
| Navigation | `get_menus`, `get_menu_items`, `update_menu_item` |
| Taxonomy | `get_terms`, `assign_term` |
| Comments | `get_comments` |

Disabled tools will not appear in the AI client's tool list.

---

## WordPress Tools

These 17 tools work without an IATO account. They use native WordPress APIs.

### Content Tools

#### `get_posts`

List posts or pages with optional filters.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `post_type` | string | `post` | `post`, `page`, or `any` |
| `status` | string | `publish` | `publish`, `draft`, or `any` |
| `per_page` | integer | 20 | Results per page (max 100) |
| `page` | integer | 1 | Page number |

#### `get_post`

Get full details for a single post or page.

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Post ID |
| `slug` | string | Post slug (alternative to ID) |

Returns title, content, excerpt, status, URL, author, dates, categories, and tags.

#### `create_post`

Create a new post or page.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `title` | string | Yes | | Post title |
| `content` | string | No | | HTML or plain text content |
| `status` | string | No | `draft` | `draft` or `publish` |
| `post_type` | string | No | `post` | `post` or `page` |

**Capability:** `edit_posts`

#### `update_post`

Update an existing post's title, content, or status.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | Post ID |
| `title` | string | No | New title |
| `content` | string | No | New content |
| `status` | string | No | `draft` or `publish` |

**Capability:** `edit_posts`

#### `search_posts`

Full-text search across posts and pages.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `query` | string | Yes | | Search query |
| `per_page` | integer | No | 20 | Max results |

### Site Tools

#### `get_site_info`

Returns site name, URL, WordPress version, active theme, and plugin count. No parameters required.

#### `get_site_settings`

Returns site title, tagline, admin email, timezone, and permalink structure.

**Capability:** `manage_options` (admin only)

### SEO Tools

#### `get_seo_data`

Get the SEO title and meta description for a post.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | Post ID |

Returns the title, description, and which SEO plugin provided them (`yoast`, `rankmath`, `seopress`, or `fallback`).

#### `update_seo_data`

Update the SEO title and/or meta description for a post.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | Post ID |
| `title` | string | No | New SEO title |
| `description` | string | No | New meta description |

**Capability:** `edit_posts`

### Media Tools

#### `get_media`

List media library items.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | integer | 20 | Results per page (max 100) |
| `missing_alt` | boolean | false | Only return items with missing alt text |

#### `update_alt_text`

Update the alt text for a media attachment.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | Attachment ID |
| `alt` | string | Yes | New alt text |

**Capability:** `edit_posts`

### Navigation Tools

#### `get_menus`

List all registered navigation menus with their item counts and assigned locations. No parameters required.

#### `get_menu_items`

Get all items in a navigation menu.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `menu_id` | integer | Yes | Menu ID |

Returns each item's title, URL, parent ID, menu order, and matched WordPress post ID/slug.

#### `update_menu_item`

Add a page to a navigation menu or update an existing item.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `menu_id` | integer | Yes | | Menu ID |
| `post_id` | integer | Yes | | Post/page ID to add |
| `parent_id` | integer | No | 0 | Parent menu item ID |
| `dry_run` | boolean | No | false | Preview without saving |

**Capability:** `manage_options` (admin only)

### Taxonomy Tools

#### `get_terms`

List categories or tags with their post counts.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `taxonomy` | string | `category` | `category` or `post_tag` |

Returns each term's ID, name, slug, post count, and parent ID (for categories).

#### `assign_term`

Assign a category or tag to a post. Adds to existing terms (does not replace).

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `post_id` | integer | Yes | | Post ID |
| `term_id` | integer | Yes | | Term ID |
| `taxonomy` | string | No | `category` | `category` or `post_tag` |

**Capability:** `edit_posts`

### Comments

#### `get_comments`

List comments with optional filters.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | `approve` | `approve`, `hold`, `spam`, or `trash` |
| `post_id` | integer | | Filter by post ID |
| `per_page` | integer | 20 | Max results |

---

## <img src="iato-icon.png" alt="" width="28" align="top" /> IATO Bridge Tools

These 9 tools require an IATO API key. They call the IATO API, then enrich results with WordPress post IDs and slugs so the AI client can act on them directly.

### `get_iato_sitemap`

Returns the full site hierarchy from IATO with WordPress post IDs and slugs attached.

| Parameter | Type | Description |
|-----------|------|-------------|
| `sitemap_id` | integer | IATO sitemap ID (optional — uses most recent if omitted) |

Use this to understand your site structure before making navigation or link changes.

### `get_iato_seo_fixes`

Returns SEO issues with fix instructions. Auto-fixable issues (title, meta description, alt text) include current and suggested values. Manual issues (missing H1, duplicate H1, canonical) include step-by-step guidance.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `crawl_id` | string | settings default | IATO crawl ID |
| `severity` | string | all | `error`, `warning`, or `info` |
| `limit` | integer | 50 | Max issues |

**Auto-fixable issue types:**

| Issue | Fix Method |
|-------|-----------|
| Missing/bad title | `update_seo_data` |
| Missing/bad meta description | `update_seo_data` |
| Missing alt text | `update_alt_text` |

**Manual issue types:**

| Issue | Guidance Provided |
|-------|-------------------|
| Missing H1 | Add an H1 heading in the block editor |
| Duplicate H1 | Change extra H1s to H2 |
| Canonical issues | Add/correct canonical URL via SEO plugin |

### `get_iato_nav_audit`

Audits site navigation: lists menus with items, identifies orphan pages (pages not in any menu), and returns WordPress slugs for all pages so the AI client can add them to menus directly.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `sitemap_id` | integer | Yes | IATO sitemap ID |

### `get_iato_orphan_pages`

Returns pages not linked from any navigation menu.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `sitemap_id` | integer | Yes | IATO sitemap ID |

### `get_iato_taxonomy`

Returns IATO taxonomy (categories and tags) mapped to WordPress term IDs. Use this to audit content classification and identify mismatches between IATO and WordPress.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `sitemap_id` | integer | Yes | IATO sitemap ID |

### `get_iato_content_gaps`

Returns pages with thin or low-quality content: low word count, missing H1, no images, or insufficient internal links.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `crawl_id` | string | settings default | IATO crawl ID |
| `min_word_count` | integer | 300 | Flag pages below this threshold |
| `limit` | integer | 20 | Max pages |

### `get_iato_broken_links`

Returns broken links found during crawl. Each result includes the broken URL, HTTP status code, anchor text, and the WordPress post that contains the link.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `crawl_id` | string | settings default | IATO crawl ID |
| `limit` | integer | 50 | Max broken links |

### `get_iato_suggestions`

Returns AI-prioritized improvement suggestions across SEO, content, links, and performance. This is the best starting point when you want to know the highest-impact changes to make.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `crawl_id` | string | settings default | IATO crawl ID |
| `focus_areas` | array | all | Filter: `seo`, `content`, `links`, `performance` |
| `limit` | integer | 10 | Max suggestions |

### `get_iato_perf_report`

Returns pages with poor load performance: slow load times, large page sizes, excessive images or scripts.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `crawl_id` | string | settings default | IATO crawl ID |
| `limit` | integer | 20 | Max pages |
| `min_load_time_ms` | integer | 2000 | Threshold in milliseconds |

---

## <img src="iato-icon.png" alt="" width="28" align="top" /> Sync Tools

These 3 tools push WordPress data into IATO, keeping your IATO workspace in sync with your WordPress site. All require an IATO API key and the `edit_posts` capability.

### `sync_wp_pages_to_iato`

Creates IATO sitemap nodes from your WordPress posts and pages. Includes rich metadata: title, URL, page type, status, author, publish date, and slug.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `sitemap_id` | integer | Yes | | IATO sitemap ID |
| `crawl_id` | string | Yes | | IATO crawl ID |
| `post_type` | string | No | `post,page` | Comma-separated post types |
| `post_status` | string | No | `publish` | WordPress post status |
| `limit` | integer | No | 100 | Max posts to sync |
| `dry_run` | boolean | No | false | Preview without creating |

**How mapping works:**

| WordPress | IATO | Mapping |
|-----------|------|---------|
| Post title | Node title | Direct |
| Permalink | Node URL | Direct |
| `post` type | `article` page type | Mapped |
| `page` type | `landing` page type | Mapped |
| `publish` status | `published` | Mapped |
| `draft` status | `draft` | Mapped |
| Author, date, slug | Node notes | Packed as metadata string |

Pages already in the IATO sitemap (matched by URL) are skipped automatically.

### `sync_wp_taxonomy_to_iato`

Syncs WordPress categories and tags to IATO. Categories are created hierarchically — parent/child relationships are preserved. Tags are created as flat IATO tags. Both are then assigned to matching IATO sitemap nodes.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `sitemap_id` | integer | Yes | | IATO sitemap ID |
| `crawl_id` | string | Yes | | IATO crawl ID |
| `taxonomy` | string | No | `category,post_tag` | Comma-separated taxonomies |
| `dry_run` | boolean | No | false | Preview without syncing |

**Category hierarchy:** WordPress categories with parents are created in depth-first order. Child categories are linked to their IATO parent via `parent_category_id`, preserving the full tree structure.

### `sync_wp_meta_to_iato`

Reads SEO titles and meta descriptions from WordPress (via Yoast, RankMath, or SEOPress) and pushes them to IATO. Keeps your IATO crawl data in sync with on-site SEO metadata.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `sitemap_id` | integer | Yes | | IATO sitemap ID |
| `crawl_id` | string | Yes | | IATO crawl ID |
| `post_type` | string | No | `post,page` | Comma-separated post types |
| `limit` | integer | No | 100 | Max posts to process |
| `dry_run` | boolean | No | false | Preview without pushing |

---

## SEO Plugin Support

IATO MCP automatically detects and works with your installed SEO plugin.

| Priority | Plugin | Detection |
|----------|--------|-----------|
| 1 | Yoast SEO | `wordpress-seo/wp-seo.php` |
| 2 | RankMath | `seo-by-rank-math/rank-math.php` |
| 3 | SEOPress | `seopress/seopress.php` |
| 4 | Fallback | Native WordPress title, no description |

Detection happens once per request and is cached. The active SEO plugin is reported in responses from `get_seo_data`, `update_seo_data`, and `sync_wp_meta_to_iato` so you always know which plugin is providing the data.

If no SEO plugin is installed, IATO MCP reads the native WordPress post title. SEO title and description writes require an active SEO plugin — the fallback mode is read-only.

---

## Dry-Run Mode

Write operations that modify external data support `dry_run: true`. When enabled, the tool returns a preview of what *would* change without actually making any modifications.

**Tools supporting dry-run:**

| Tool | What it previews |
|------|-----------------|
| `update_menu_item` | Menu item that would be added |
| `sync_wp_pages_to_iato` | IATO nodes that would be created |
| `sync_wp_taxonomy_to_iato` | Categories/tags that would be created and assigned |
| `sync_wp_meta_to_iato` | SEO metadata that would be pushed |

Dry-run responses always include `"dry_run": true` and show actions like `"would_create"` or `"would_sync"` instead of `"create"` or `"synced"`.

**Tip:** Run every sync tool with `dry_run: true` first to review the changes, then run again with `dry_run: false` to apply them.

---

## Example Prompts

Here are some things you can ask your AI client once connected:

### Content Management

> "Show me all draft posts and publish the ones that are ready."

> "Create a new blog post about our spring sale with a draft status."

> "Search for all posts mentioning 'pricing' and update their titles to be more SEO-friendly."

### SEO

> "Crawl my site and fix all missing meta descriptions."

> "Get the SEO data for my top 10 pages and suggest improvements."

> "Find all images with missing alt text and generate appropriate descriptions."

### Navigation & Structure

> "Show me pages that aren't in any navigation menu and add them to the right place."

> "Audit my main menu — are there any important pages missing?"

> "Find orphan pages and suggest which menu they should be added to."

### Analysis & Fixes

> "What are the most impactful improvements I can make to my site right now?"

> "Find all broken links and tell me which posts contain them."

> "Audit my categories and tags and suggest consolidations."

> "Show me pages that load slowly and explain why."

### Syncing

> "Sync all my published posts and pages to the IATO sitemap."

> "Push my WordPress categories and tags to IATO, preserving the hierarchy."

> "Sync my SEO metadata to IATO so the audit data is up to date."

---

## Troubleshooting

### "IATO API key not configured"

You'll see this error when using bridge or sync tools without an IATO API key. Go to **Settings → IATO MCP** and enter your key. You can get one free at [iato.ai](https://iato.ai).

### "Unauthorized" or 401 errors

- Check that your API key hasn't been regenerated — if it has, update your AI client config.
- Verify the `Authorization: Bearer <key>` header is being sent correctly.
- If using OAuth, try disconnecting and reconnecting through the Custom Connector flow.

### Tools not appearing in AI client

- Confirm the plugin is activated in WordPress.
- Check **Settings → IATO MCP** — disabled tools won't appear in the tool list.
- Bridge/sync tools only load when an IATO API key is configured.

### SEO updates not working

- Verify you have Yoast SEO, RankMath, or SEOPress installed and active.
- The fallback mode (no SEO plugin) is read-only — title and description writes require a plugin.

### "Current user cannot edit_posts"

- The API key authenticates as the WordPress admin who activated the plugin.
- Ensure that admin account has the `edit_posts` capability (standard for administrators).

### Connection works in config but not via Custom Connector

- Your site must be accessible over HTTPS for OAuth to work.
- Check that `/.well-known/oauth-authorization-server` returns a valid JSON response.
- Some security plugins may block the OAuth endpoints — whitelist `/oauth/*` paths.

---

## Data & Privacy

- **WordPress content** is never sent to IATO. IATO crawls your public URLs the same way a search engine would.
- **IATO API calls** only transmit crawl analysis data (URLs, scores, issues) — not your post content.
- The **IATO API** is only contacted when you use bridge or sync tools, and only if you've configured an API key.
- **OAuth tokens** stay between your WordPress site and the AI client — no data is sent to third parties during authentication.

For more information, see the [IATO Terms of Service](https://iato.ai/terms) and [IATO Privacy Policy](https://iato.ai/privacy).

---

<p align="center">
  <img src="iato-icon.png" alt="IATO" width="32" /><br>
  <sub>Built by <a href="https://iato.ai">IATO</a></sub>
</p>
