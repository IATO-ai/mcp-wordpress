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
6. [SEO Plugin Support](#seo-plugin-support)
7. [Dry-Run Mode](#dry-run-mode)
8. [Undoing Changes](#undoing-changes)
9. [Pages Sent Back to the WordPress Editor](#pages-sent-back-to-the-wordpress-editor)
10. [Example Prompts](#example-prompts)
11. [Troubleshooting](#troubleshooting)

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
| **Tools** | WordPress tools only | WordPress tools + 12 IATO bridge tools |
| **Read content** | Posts, pages, media, menus, taxonomy, comments, Elementor | Everything in WordPress tools |
| **Edit content** | Create/update posts, SEO, alt text, menus, taxonomy, canonical, structured data, redirects, Elementor | Everything in WordPress tools |
| **Site audit** | — | SEO issues with WP slugs attached |
| **Broken links** | — | Broken pages + resources mapped to source posts |
| **Orphan pages** | — | Pages missing from navigation |
| **Content gaps** | — | Thin content flagged with recommendations |
| **AI suggestions** | — | Prioritized improvements across SEO, content, links, performance |
| **Performance report** | — | Slowest and largest pages with WP slugs |

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

This method uses OAuth 2.1 with PKCE — no manual key copying required. Each connection gets its own token (not the API key), acts as the administrator who approved it, and can be revoked on its own under **Settings → IATO MCP → Connected OAuth clients**. A connection stays valid for 180 days, then asks for approval again.

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
- **Regenerate Key** — Creates a new key (invalidates the old one — you'll need to update your AI client config). OAuth connections made before version 1.13.0 also stop working; connections listed under Connected OAuth clients are not affected.

### What gets filtered

Content written through MCP is filtered the way WordPress filters a non-administrator's: scripts, event handlers and embeds are removed from post content and from Elementor widgets, whatever the role of the user behind the credential. Elementor edits report `sanitized: true`, the setting paths that were changed, and a `sanitized_report` naming the tags and attributes removed from each value, so the AI client can tell you what was removed; dry runs report the same. Only the values a request changes are filtered; content a person authored in other widgets on the same page stays as it is. If you need raw HTML through MCP, a developer can enable it for Application Password users with the `iato_mcp_allow_unfiltered_html` filter.

`<style>` and `<script>` elements are removed whole, contents included, on WordPress 6.5 or later. `<svg>` is removed like any other disallowed tag. If CSS or a script's text would still stay on the page, for example from an unclosed `<style>` tag, the report warns about it. To keep them in Elementor HTML widgets, turn on **Allow inline SVG and style blocks in HTML widgets for administrators** (see [Caching and HTML widgets](#caching-and-html-widgets)).

The generic `update_post_meta` tool cannot write Elementor's own data (`_elementor_data`, caches, CSS); the Elementor tools are the way to edit pages, and the error says which one to use.

### Connected OAuth clients

Every AI client that connected through OAuth (for example the Claude connector or Claude Code) is listed with the application name, the host it sends you back to, the administrator it acts as, when it connected and when it was last used. **Revoke** disconnects that one client; the others and the API key keep working. **Disconnect all** revokes every OAuth connection at once (the API key is not affected). Deleting or demoting the approving administrator also disconnects their clients. Connections made before version 1.13.0 hold the API key and are not listed; remove and re-add the connector in the AI client to move it to its own token.

### <img src="iato-icon.png" alt="" width="22" align="top" /> IATO Platform

- **IATO API Key** — Enter your key from the [IATO dashboard](https://iato.ai). This unlocks 12 additional bridge and sync tools.
- **Default Crawl ID** — Optional. Bridge tools use this crawl ID when none is specified in the request.

The API key is validated against the IATO API when you save settings. A green "Connected" badge confirms a valid key.

### Caching and HTML widgets

- **Purge page caches after changes** (on by default). After each change made through MCP, IATO purges the cache plugins it finds: LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache and SiteGround Optimizer. See [Page Caches](#page-caches).
- **Allow inline SVG and style blocks in HTML widgets for administrators** (off by default, WordPress 6.5 or later). The `html` setting of an Elementor HTML widget keeps `<style>` and these SVG elements: `svg`, `g`, `path`, `rect`, `circle`, `line`, `polyline`, `polygon`, `title`. It applies only when the connection's WordPress user holds both `unfiltered_html` and `manage_options`, on any kind of connection. It does not grant `unfiltered_html`, and every other value stays filtered. Still removed: `<script>`, event attributes, `javascript:` URLs, `foreignObject`, `use`, `image` and animation elements, and from style blocks `@import` and anything that loads from another site. A style block that can't be read safely, for example one with an unterminated `url()`, a character reference such as `&#105;`, or text that names another site (even in a `//` comment), is dropped whole, and `removed_css` says why.

### Tool Toggles

Every tool can be individually enabled or disabled. Tools are organized by category:

| Category | Tools |
|----------|-------|
| Content | `get_posts`, `get_post`, `create_post`, `update_post`, `search_posts` |
| Site | `get_site_info`, `get_site_settings`, `update_site_settings`, `get_custom_css`, `update_custom_css` |
| SEO | `get_seo_data`, `update_seo_data` |
| Media | `get_media`, `update_alt_text`, `create_media`, `media_upload_start`, `media_upload_chunk`, `media_upload_finish` |
| Navigation | `get_menus`, `get_menu_items`, `update_menu_item` |
| Taxonomy | `get_terms`, `assign_term` |
| Comments | `get_comments` |
| Users | `update_user_profile` |

Disabled tools will not appear in the AI client's tool list.

---

## WordPress Tools

These tools work without an IATO account. They use native WordPress APIs.

### Content Tools

#### `get_posts`

List posts or pages with optional filters.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `post_type` | string | `post` | `post`, `page`, or `any` |
| `status` | string | `publish` | `publish`, `draft`, or `any` |
| `per_page` | integer | 20 | Results per page (max 100) |
| `page` | integer | 1 | Page number |

Returns each post's ID, title, slug, status and URL, with `date` (the site's local time), `date_gmt` (UTC, or null for a draft with no date of its own) and `modified` (UTC).

#### `get_post`

Get full details for a single post or page.

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Post ID |
| `slug` | string | Post slug (alternative to ID) |

Returns title, content, excerpt, status, URL, author, categories, tags, `date` (the site's local time), `date_gmt` (UTC, or null for a draft with no date of its own) and `modified` (UTC).

#### `create_post`

Create a new post or page.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `title` | string | Yes | | Post title |
| `content` | string | No | | HTML or plain text content |
| `status` | string | No | `draft` | `draft` or `publish` |
| `post_type` | string | No | `post` | `post` or `page` |
| `date` | string | No | now | Publish date in the site's local time, such as `2026-10-27T09:00:00` |
| `date_gmt` | string | No | | The same date in UTC. Send this or `date` |
| `allow_future` | boolean | No | `false` | Lets `status: publish` with a future date schedule the post |
| `author` | integer | No | you | A user who can edit this type of post. Someone else needs `edit_others_posts` |

**Capability:** `edit_posts`

#### `update_post`

Update an existing post's title, content, slug, excerpt, status, publish date or author.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | Post ID |
| `title` | string | No | New title |
| `content` | string | No | New content |
| `status` | string | No | `draft` or `publish` |
| `date` | string | No | Publish date in the site's local time, such as `2026-10-27T09:00:00`, with no offset |
| `date_gmt` | string | No | The same date in UTC. Send this or `date`: both are refused with `date_mismatch` if they are different moments |
| `allow_future` | boolean | No | See below. Default `false` |
| `author` | integer | No | A user who can edit this type of post. Someone else needs `edit_others_posts` |

**Capability:** `edit_posts`

**Dates and status.**

| The post | Given | Result |
|---|---|---|
| Published | A past date | Stays published, with the new date |
| Published | A future date | Refused with `future_date_on_published`, unless `allow_future` is true. Then it is scheduled, leaves the site until that date, and the response says so |
| Draft | Any date | Stays a draft, and uses the date when published |
| Draft with a future date | `status: publish` | Refused the same way, unless `allow_future` is true |
| Scheduled | A later date | Stays scheduled |
| Scheduled | A past date | Goes live, and the response says so |

Scheduling or publishing because of a date needs the capability to publish. If the permalink has the date in it, the response gives `previous_url`, and WordPress redirects the old address. Rolling back a date restores the date, the UTC date and the status together. The post's modified time is not restored.

#### `search_posts`

Full-text search across posts and pages.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `query` | string | Yes | | Search query |
| `per_page` | integer | No | 20 | Max results |

### Site Tools

#### `get_site_info`

Returns site name, URL, WordPress version, `plugin_version` (the IATO MCP version running on the site, since 1.19.2), active theme, plugin count, and `cache`: the page cache plugins found (`detected`) and notes about caches IATO cannot purge. No parameters required.

#### `get_site_settings`

Returns site title, tagline, admin email, timezone, permalink structure, what the home page shows (`show_on_front`, `page_on_front`, `page_for_posts`), the site icon (`site_icon`) and the logo (`custom_logo`). Each of the last two is `{id, url}`, or null when none is set. A null `url` means the image no longer exists. From 512 pixels up WordPress uses the full image, so the icon's `url` can be larger than that.

**Capability:** `manage_options` (admin only)

#### `update_site_settings`

Sets the site title, the tagline, what the home page shows, the site icon and the logo. Send only what should change; any other key is refused.

| Parameter | Option | Notes |
|---|---|---|
| `title` | `blogname` | Tags are removed |
| `tagline` | `blogdescription` | Tags are removed |
| `homepage_displays` | `show_on_front` | `posts` or `page` |
| `front_page_id` | `page_on_front` | A published page. Required when `homepage_displays` is `page` |
| `posts_page_id` | `page_for_posts` | A published page other than the front page, or `0` to clear |
| `site_icon` | `site_icon` | The favicon (browser tab, bookmark and app icon): an attachment ID, or `0` to clear it. See below |
| `custom_logo` | The theme's `custom_logo` | The logo: an attachment ID, or `0` to clear it. See below |

Accepts `dry_run`. Returns the before and after value of each option in `changes`, a change receipt per option changed (one `change_receipt`, or `change_receipts` for several), and a `cache` object: the whole site is purged.

**Site icon and logo.** Upload the image with `create_media` first, then send its ID. Clearing never deletes the attachment.

| Refused with | When |
|---|---|
| `attachment_not_found` | No attachment has that ID |
| `not_an_attachment` | The ID is a page or post |
| `attachment_in_trash` | The image is in the trash |
| `unsupported_image_type` | It is not PNG, JPEG, GIF or WebP, by its stored type or its real contents. SVG is refused |
| `attachment_not_ready` | Its sizes are still being generated (`create_media` with `defer_subsizes`). Try again shortly |
| `attachment_unreadable` | It has no metadata and its file is not on this server |
| `image_not_square` | Site icon only. The refusal gives the `width` and `height`. The image is not cropped |

- **Size.** A site icon should be 512x512 or larger. A smaller one is set, with an entry in `warnings`, because devices that ask for a larger icon will scale it up.
- **What it creates.** The sizes WordPress uses for a site icon (`sizes_created`; a dry run lists `would_create_sizes`), and nothing else. Its 512px icon is the full image, as WordPress does it, so an image larger than that stays larger. The icon is still set if the server cannot make a size, with a warning.
- **The same ID again.** Changes no setting and records no receipt, but creates any missing sizes. Use this to repair an icon set through Elementor's Site Favicon, which creates none.
- **The response** also has `icon_url_32` and `icon_url_192`, from WordPress's `get_site_icon_url()`.
- **Elementor.** Site Favicon and Site Logo in Elementor's Site Settings write these same two settings. When Site Settings has a saved image, a change here updates it too (`elementor_settings_synced`), because publishing Site Settings would otherwise put the old image back.
- **Browsers** keep favicons for a long time, so a correct page can still show the old icon in a tab for a while.

**Capability:** `manage_options` (admin only), and `edit_post` on the attachment, because the write changes its metadata.

#### `get_custom_css`

Returns the active theme's Additional CSS and the ID of the post it is stored in, or null when it has none.

**Capability:** `edit_theme_options`

#### `update_custom_css`

Sets the active theme's Additional CSS, which prints on every page. Send `css` to replace it all, or `append_css` to add to the end, with `dry_run` to preview.

- **Stored as sent.** Nothing is rewritten: it stores exactly what you send, or refuses. The limit is 200 KB, counted after appending (`css_too_large`).
- **Off-site loads are refused.** `@import`, or `url()` pointing at another host, is refused with `css_not_allowed`, and `found` says what it saw. CSS on every page can send data off-site if a client is tricked by injected instructions, so a person has to add that in the Customizer. With `append_css`, only what you add is checked.
- **Closing the style element.** Anything that would close it, such as `</style>`, is removed and listed in `sanitized_report`.
- **Text shaped like a tag.** `<a` and the like are refused with `css_not_allowed`, even in a comment, because WordPress below 7.1 removes them when it prints the CSS, which changes what the rest means. A less-than followed by a space is fine.
- **Appending.** If what is stored ends inside a comment, string or `url()`, `append_css` is refused with `stored_css_unreadable`, because what you add would be read as part of it. Send the whole CSS with `css`.
- **Response.** The sizes and hashes, and a short receipt, not the CSS. Rollback restores the previous CSS for the theme it was written for, even after a theme switch. The stored copy goes after 90 days, and WordPress's own revisions remain.

**Capability:** `manage_options`, and `edit_css` held by a real user, so a site key with no owner cannot use it.

---

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

#### `create_media`

Upload an image. Base64 is reliable only up to about 3 KB, because the transport can drop or alter more. Use the chunked tools below for anything larger. URL ingestion is for an image that is already online.

- **`sha256`.** Send the digest of the original file. If the bytes that arrive differ, the call fails with `checksum_mismatch` and nothing is created. The response always gives `sha256` and `decoded_bytes`, so you can compare without sending one.
- **Damaged images.** Bytes that are not a whole image of the declared type fail with `invalid_image`, and `reason` says why. A PNG's checksums and end marker are checked, not only its header. Damaged pixels inside a JPEG can only be caught with a digest.
- **Sizes.** If WordPress could not make the sizes it expected, the response has `warnings` and `missing_sizes`. A small image legitimately has no sizes and gets no warning.

**Capability:** `upload_files`

#### Chunked uploads

For an image over about 3 KB:

1. **`media_upload_start`**: `filename`, `mime_type`, `total_bytes`, `sha256`, and optionally `alt_text`, `title`, `caption` and `attach_to_post`. Returns an `upload_id`. The limit is 15 MB, or less if the site's upload limit or Settings > IATO MCP > Media Uploads says so. That setting is 10 MB by default.
2. **`media_upload_chunk`**: `upload_id`, `index` (from 0) and `data`, the chunk's bytes in base64, up to about 3 KB decoded and 4 KB at most. Every chunk must be the size of the first, except the last. Optionally `chunk_sha256`. Sending a chunk again with the same contents is safe.
3. **`media_upload_finish`**: `upload_id`, and optionally `defer_subsizes`. Checks the total size, the `sha256` and the image, creates the attachment and returns what `create_media` returns, with a receipt. If it times out, call it again: it returns the same result and does not upload twice. If the earlier call stopped part way, the response has `recovered` and a warning, and any missing image sizes are made in the background.

An upload belongs to the user who started it, and each user can have five open. One with no activity for an hour is removed. A `checksum_mismatch` or `invalid_image` at the finish removes the upload, so start again.

**Capability:** `upload_files`

### Navigation Tools

#### `get_menus`

List all registered navigation menus with their item counts, and every menu location the theme registers with the menu in it (`locations`). No parameters required.

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

**Capability:** `edit_theme_options` (admin only)

#### `create_menu`

Create an empty navigation menu, optionally in a theme location. Add its links with `create_menu_item`. Elementor Pro's Nav Menu widget refers to a menu by the `slug` this returns.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `name` | string | Yes | | Menu name, not in use by another menu |
| `location` | string | No | | Theme location slug, from `get_menus`. A menu already there is replaced in that location and kept |
| `dry_run` | boolean | No | false | Preview without saving |

**Capability:** `edit_theme_options` (admin only)

Rollback deletes the menu and gives the location back to the menu that held it. Deleting a menu deletes its items for good, so a menu that has items needs `force: true`. A menu given a location afterwards needs that change rolled back first, which puts the old menu back. WordPress keeps menu locations per theme, so a change that set a location cannot be rolled back after the theme is switched.

#### `assign_menu_location`

Put a menu in a theme location, or clear the location. The menu that was there is kept.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `location` | string | Yes | | Theme location slug, from `get_menus` |
| `menu_id` | integer | Yes | | Menu ID, or 0 to clear the location |
| `dry_run` | boolean | No | false | Preview without saving |

**Capability:** `edit_theme_options` (admin only)

### Theme Builder Tools

Build the site-wide header and footer through your AI client: it can create the template, fill it, say where it shows, and check the result. These need Elementor Pro, except `section`, `container` and `page` templates. An IATO MCP key must be assigned to a user (Settings > IATO MCP) for these tools.

#### `create_elementor_template`

Create a template the way Add New Template does. It is built as a draft, filled, given its conditions and published last, so a failure on the way leaves nothing showing. A header or footer with conditions cannot be published empty, because it would replace the theme's with nothing: create a draft, fill it, then publish.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `title` | string | Yes | | Template title |
| `template_type` | string | Yes | | `header`, `footer`, `single-post`, `single-page`, `archive`, `search-results`, `error-404`, `popup`, `section`, `container` or `page` |
| `status` | string | No | `draft` | `draft` or `publish`. A draft shows nowhere |
| `elementor_data` | string | No | | Full Elementor JSON |
| `conditions` | array | No | | Where it shows; see below |
| `dry_run` | boolean | No | false | Preview without saving |

**Capability:** `manage_options` (admin only)

Rollback moves the template to the trash. If it has been filled or changed since, rollback needs `force: true`, and the refusal says when it is live, because rolling back removes it from every page it shows on. On a site with no trash (`EMPTY_TRASH_DAYS` set to 0) it always needs `force`, because it deletes for good.

#### `set_elementor_template_conditions`

Set where a template shows. Elementor Pro keeps its own record of this, which decides what renders, so this goes through Pro rather than editing the template's data.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `id` | integer | Yes | | Template ID, from `list_elementor_templates` |
| `conditions` | array | Yes | | Replaces what is there. An empty list removes them |
| `if_revision` | string | No | | Refuse if the conditions have changed since you read this `conditions_revision` |
| `dry_run` | boolean | No | false | Preview without saving |

**Capability:** `manage_options` (admin only)

Conditions are rules or strings: `{"type":"include","name":"general"}` or `include/general` for the entire site; `{"type":"exclude","name":"singular","sub_name":"page","sub_id":42}` or `exclude/singular/page/42` to leave out one page. Unknown names are refused with the list Pro registers.

The response says `verified: true` when the template is doing what its conditions say, and otherwise why not, such as `not_published` or `empty_template`. It warns when another published template may show on the same pages, because Elementor Pro shows one per URL: on the pages both cover, the narrower condition wins. A condition that can never show the template, such as an archive condition on a single page template, gets a warning rather than a refusal. If Elementor Pro's record has gone stale, sending the same conditions again rebuilds it (`cache_repaired`).

To see which header and footer a URL gets, use `resolve_url` and read `theme_locations`. A page using the Elementor Canvas template shows neither, and `hidden_by_page_template` says so; switch it to Elementor Full Width (`elementor_header_footer`) with `set_page_settings`.

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

### User Tools

#### `update_user_profile`

Changes the public fields of a profile: `display_name`, `nickname`, `first_name`, `last_name`, `description` and `url`, with `dry_run` to preview. It changes the authenticated user unless `user_id` is given. Any other key, including email, password and role, is refused with `field_not_allowed`.

`display_name` must be one of the names WordPress offers for that user under "Display name publicly as", or the call must set `nickname` to the same value. A refusal lists them in `offered`.

A site key with no owner has no user of its own, so it must send `user_id`.

**Capability:** `edit_user` on that user: yourself, or anyone if you hold `edit_users`.

---

## <img src="iato-icon.png" alt="" width="28" align="top" /> IATO Bridge Tools

These 12 tools require an IATO API key. They call the IATO API, then enrich results with WordPress post IDs and slugs so the AI client can act on them directly.

### `get_iato_sitemap`

Returns the full site hierarchy from IATO with WordPress post IDs and slugs attached.

| Parameter | Type | Description |
|-----------|------|-------------|
| `sitemap_id` | integer | IATO sitemap ID (optional — uses most recent if omitted) |
| `crawl_id` | string | IATO crawl job ID used to pick the sitemap when `sitemap_id` is omitted (optional — falls back to the default crawl ID in Settings) |

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

### `list_iato_crawls`

Lists recent IATO crawl jobs with their status and IDs. Use it to find the latest completed `crawl_id` before reading SEO fixes, the sitemap or suggestions.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | integer | 20 | Max results |

### `get_iato_crawl_status`

Returns the status, progress and metadata of one crawl. Use it after `start_iato_crawl` to poll until the status is `completed`.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `crawl_id` | string | settings default | IATO crawl ID |

### `start_iato_crawl`

Starts a new IATO crawl and returns its `crawl_id`. Administrators only, because it uses your IATO quota.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `url` | string | this site | URL to crawl |
| `max_pages` | integer | 1000 | Maximum pages to crawl |

---

## SEO Plugin Support

IATO MCP automatically detects and works with your installed SEO plugin.

| Priority | Plugin | Detection |
|----------|--------|-----------|
| 1 | Yoast SEO | `wordpress-seo/wp-seo.php` |
| 2 | RankMath | `seo-by-rank-math/rank-math.php` |
| 3 | SEOPress | `seopress/seopress.php` |
| 4 | Fallback | Native WordPress title, no description |

Detection happens once per request and is cached. The active SEO plugin is reported in responses from `get_seo_data` and `update_seo_data` so you always know which plugin is providing the data.

If no SEO plugin is installed, IATO MCP reads the native WordPress post title. SEO title and description writes require an active SEO plugin — the fallback mode is read-only.

---

## Page Caches

After a change, IATO purges the cache plugins it finds and adds a `cache` object to the tool's response:

```json
"cache": {
  "detected": ["litespeed"],
  "purged": true,
  "scope": "post",
  "notes": ["Hostinger server cache or a CDN may still hold the old page; purge it in hPanel or the CDN dashboard if changes don't appear."]
}
```

- **`detected`**: the cache plugins found: `litespeed`, `wp-rocket`, `w3-total-cache`, `wp-super-cache`, `wp-fastest-cache`, `sg-optimizer`.
- **`purged`**: `true` if IATO asked every detected plugin to purge and none failed, `false` if one failed or purging is off, `null` if none was found or nothing was written. It means the purge was requested: LiteSpeed acts on a response header and W3 Total Cache at the end of the request.
- **`scope`**: `post` or `all`, `null` when the call changed nothing. `post_ids` lists the pages when a bulk write touched several.
- **`notes`**: anything worth passing on, such as a CDN IATO cannot reach. Notes never change `purged`.

| Change | What is purged |
|---|---|
| One post or page, its SEO data, meta, Elementor content or featured image | That page, plus the home URL for the front page or posts page |
| The Elementor kit, Theme Builder templates, global widgets, saved templates | The whole site, and all Elementor CSS |
| Menus, terms, redirects, `update_site_settings`, `update_custom_css`, `update_user_profile` | The whole site |
| The date or author of a post that is or was published | The whole site: archives and listings change |
| A slug change, or unpublishing a post | The whole site: the old URL can't be derived from the post any more |
| An image's alt text | The page the image is attached to. Other pages using the image stay cached, and the note says so |

A call that changes nothing, or a dry run, purges nothing. Dry runs and reads have no `cache` object.

Editing a page clears and rebuilds only that page's Elementor CSS, whichever setting is on. A developer can skip a purge with the `iato_mcp_cache_purge` filter (`$purge, $scope, $post_id`) or add their own with the `iato_mcp_after_cache_purge` action (`$scope, $post_id, $results`).

---

## Dry-Run Mode

Write operations that modify external data support `dry_run: true`. When enabled, the tool returns a preview of what *would* change without actually making any modifications.

Every tool that writes accepts it, and a tool that cannot preview refuses the flag with `dry_run_unsupported` instead of running.

Dry-run responses always include `"dry_run": true`, and an `action` such as `"would_update"`, `"would_create"`, `"would_delete"` or `"no_change"`. A preview runs the same checks as the real call, so it refuses what the real call would refuse. Updates list each field's before and after values in `would_change`. `not_checked` names what only a real write can reveal, such as another plugin's filter changing the value on save.

---

## Undoing Changes

Every write tool returns a `change_receipt` with a `change_id`. Pass it to the `rollback` tool to put back the value the change replaced. The receipt also says who made the change: the WordPress user, how they connected (site key, OAuth connection or Application Password) and, for OAuth, which application.

- **Who can undo a change.** The user who made it, or an administrator. An Editor cannot undo an Author's change, even on a post the Editor may edit. Changes recorded before version 1.13.0 keep the earlier rule: anyone allowed to edit the object.
- **Changes that were changed again.** If the value is no longer what the change left behind, because someone edited it afterwards in WordPress or another change came later, `rollback` refuses with `rollback_conflict` and shows the current and expected values. Undo the later change first, or pass `force: true` to overwrite it.
- **If a change cannot be undone.** A response with `"stored": false` and no `change_id` means the change was applied but its undo record could not be saved. Settings > IATO MCP > Diagnostics shows what went wrong and can check the plugin's database tables again.
- **Checking first.** `dry_run: true` reports what would be restored and whether the value has changed since, without writing anything.
- **Site settings.** Each option `update_site_settings` changed has its own receipt. A page that is no longer published cannot be restored as the front page or posts page.
- **Site icon and logo.** Rollback restores the previous attachment, or no icon or logo at all. An image that no longer exists cannot be restored, and a logo cannot be rolled back after a theme switch, because WordPress keeps one per theme. Rolling back `create_media` for the image in use needs `force`, which deletes the image and clears the setting.
- **Post dates and authors.** Rolling back a date restores the date, the UTC date and the status together. A scheduled post that has gone live since is a conflict. A previous author who no longer exists cannot be restored.
- **Custom CSS.** Rollback restores the previous CSS exactly, for the theme it was written for, and removes the CSS post if the change created it. The stored copy goes after 90 days.
- **User profiles.** One receipt covers all the fields in a call. Rollback restores them exactly, even where WordPress's filters would change them, and a user who no longer exists cannot be restored.
- **Elementor pages.** Elementor edits made with 1.13.0 or later can be undone exactly for 90 days, including content a person added elsewhere on the page. Each receipt keeps a copy of the page as it was, and a daily cleanup removes copies older than 90 days to keep the database small; the receipt itself stays. Older edits, and edits made with versions before 1.13.0, are restored in Elementor under History, then Revisions. Settings > IATO MCP Diagnostics shows when the cleanup last ran. A developer can change the period with the `iato_mcp_receipt_retention_days` filter (0 keeps every copy).

---

## Pages Sent Back to the WordPress Editor

Elementor's **Back to WordPress Editor** button turns Elementor off for a page but keeps its old layout, and visitors see the page's normal content. An Elementor edit would replace that content, so Elementor write tools refuse these pages with `elementor_not_active`, and `get_page_builder` reports them with `elementor_tree_present: true`.

To switch such a page to Elementor, call `update_elementor_data` with `activate_builder: true`. Try it with `dry_run: true` first. `rollback` then puts back the content, the setting and the old layout. If someone edited the content after the switch, `rollback` reports a conflict rather than overwriting the edit.

A new page with no layout needs none of this: `update_elementor_data` turns Elementor on for it.

Rollback also restores the content when the page had no elements yet and Elementor's flag was already on, which is what opening the Elementor editor and leaving without saving does. Rolling back a change that turned Elementor off is a conflict if the page was edited since, because turning it back on would hide that edit; `force: true` still does it.

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
- If using OAuth, try disconnecting and reconnecting through the Custom Connector flow. A connection that was revoked, that expired (180 days), or whose approving administrator was deleted or demoted answers 401 until it is approved again.

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

### Visitors still see the old page

Check `cache` in the tool's response. If `purged` is not `true`, or `notes` names a cache, purge that cache by hand. A CDN or host cache is out of IATO's reach. If `detected` is empty and the page is still stale, a cache IATO does not know is in front of the site: use the `iato_mcp_after_cache_purge` action to purge it automatically.

### Connection works in config but not via Custom Connector

- Your site must be accessible over HTTPS for OAuth to work.
- Check that `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server` return valid JSON responses.
- Some security plugins may block the OAuth endpoints — whitelist `/oauth/*` paths (`register`, `authorize`, `token`, `revoke`).
- Claude registers a new OAuth client on every fresh connection. Registration is limited to 10 per source address per 10 minutes and 100 per site per hour; behind a proxy that forwards every request from one address, the first limit applies to the whole site (a developer can raise it with the `iato_mcp_oauth_registration_limits` filter).
- If your site's address in Settings → General is `http://` but visitors reach it over `https://` (a TLS-terminating proxy), the plugin accepts the https resource and records it in Diagnostics.

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
