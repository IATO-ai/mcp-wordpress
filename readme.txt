=== IATO MCP ===
Contributors: iatoai
Tags: mcp, ai, claude, elementor, seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.21.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude and other AI clients to your WordPress site to fix SEO and edit Elementor pages and menus, with a rollback for every change.

== Description ==

[IATO MCP](https://iato.ai/wordpress-mcp) turns a self-hosted WordPress site into an MCP server. Connect Claude, or another AI client that supports MCP, and ask it in plain words to edit pages, fix SEO, rebuild menus, restructure URLs or work through a site audit.

The AI client can preview a change with a dry run before making it, and any change it makes can be undone with one call. The plugin understands Elementor, including Theme Builder and the Atomic Editor, and works with the SEO, redirect, theme and cache plugins many sites already use. Add an [IATO](https://iato.ai) account and the same conversation can crawl your site, find what needs fixing, and fix it.

https://www.youtube.com/watch?v=gSX6Vc9Yask

= How it works =

1. Install and activate the plugin.
2. Open the setup wizard and copy your MCP server URL. It looks like `https://example.com/wp-json/iato-mcp/v1/message`.
3. In Claude, add a custom connector with that URL, then approve the connection on your WordPress site. Clients that use a config file connect with a WordPress Application Password instead, and the wizard gives you the config.
4. Optional: add an IATO API key under Settings > IATO MCP for crawl-based audits. IATO's [Free plan](https://iato.ai/pricing) covers 500 pages per crawl.

= What Claude can do =

The plugin has 64 WordPress tools. 57 are on when you install it. The 7 page asset tools stay off until an administrator turns them on. You can switch any tool off under Settings > IATO MCP.

**Posts and pages**

* Read, search, create and edit posts and pages: title, content, slug, excerpt, status, publish date and author
* Schedule posts, and move pages under a parent page
* Change the permalink structure, with 301 redirects from the old URLs
* Read and write post meta. Keys that look like credentials are refused.
* Set per-page theme settings (hide title, sidebar and content layout) on Astra, Kadence, GeneratePress and Elementor
* Read and filter comments

**Elementor**

* Detect which page builder a page uses (Elementor, Gutenberg, Divi, WPBakery, Beaver Builder or the classic editor), so the AI client edits it with the right tools
* Edit one widget, many widgets across many pages, or a whole page, with revision checks so that two edits cannot overwrite each other
* Apply JSON Patch (RFC 6902) edits to a page
* Fix heading levels, text, links and image alt text on Atomic Editor (V4) elements (Elementor 4.3.0 or later), checked with Elementor's own code
* Copy the styling of an existing page in one call with `inherit_settings_from`
* Create Theme Builder templates, such as a site-wide header and footer, and set where they show (Elementor Pro)
* Find which page renders a URL, and which Theme Builder header and footer it gets

**SEO**

* Update SEO titles, meta descriptions and canonical URLs
* Add JSON-LD structured data
* Create redirects
* Update image alt text

**Site, menus and media**

* Set the site title, tagline, home page, site icon and logo
* Read and set the theme's Additional CSS
* Change the public fields of a user's profile, such as the display name
* Create menus, add, edit and remove menu items, and assign menus to theme locations
* Manage categories, tags and other taxonomy terms
* Upload images to the Media Library in checked chunks, and set featured images

**Page assets (off until an administrator turns them on)**

* Give one page its own CSS and Elementor fonts
* Store a script for one page. It runs only after an administrator approves it in wp-admin.
* Make expiring preview links, so the AI client can check a draft before it is published

**Safety**

* Every change returns a receipt. Pass its `change_id` to `rollback` and the old value is restored. Each receipt records the user and the connection that made the change.
* `dry_run` shows what a change would do without saving it.
* Content written through MCP is filtered whatever the user's role, and each response says what was removed (`sanitized_report`).
* Page caches are purged after each change. The response names any cache the plugin cannot reach, such as Cloudflare.
* Each OAuth connection gets its own expiring token, acts as the administrator who approved it, and can be revoked on its own.

**With an [IATO account](https://iato.ai): 12 more tools, for administrators**

* Start a crawl of your site, check its status and list recent crawls
* Get SEO issues (titles, meta descriptions, alt text) mapped to WordPress posts, ready for the tools above to fix
* Find orphan pages that no navigation menu links to, and gaps in your menus
* Map broken links to the posts that contain them
* Find thin content, with specific improvements
* Review categories and tags and get suggested consolidations
* Get AI-prioritized suggestions across SEO, content, links and performance
* Flag slow pages, with what makes them slow

On WordPress 6.9 or later, the IATO read tools are also registered as WordPress Abilities, for MCP servers built on the WordPress MCP Adapter.

= Supported plugins =

* SEO: Yoast SEO, Rank Math and SEOPress. Without one, the plugin reads the WordPress title, and SEO fields cannot be changed.
* Redirects: Redirection or Safe Redirect Manager, or the plugin's own list when neither is active
* Page builders: Elementor, and Elementor Pro for Theme Builder
* Themes with per-page settings: Astra, Kadence and GeneratePress
* Page caches: LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache and SiteGround Optimizer

= Example prompts =

> "Crawl my site and fix all missing meta descriptions"

> "Show me pages that aren't in any navigation menu and add them to the right place"

> "What are the most impactful improvements I can make to my site right now?"

> "Find all broken links and tell me which posts contain them"

> "Move the service pages under a new Services page and redirect the old URLs"

> "Schedule this draft for Monday at 9am and make Sam the author"

> "Change the extra H1 headings on these Elementor pages to H2"

> "Build a site-wide footer with our address and the main menu"

> "Find all button widgets on the site and change their color to #ff0000"

= External services =

This plugin connects to the IATO API ([https://iato.ai](https://iato.ai)) once an administrator enters an IATO API key under Settings > IATO MCP. Nothing is sent to IATO before then. With a key saved, the plugin calls `https://iato.ai/api` when the key is tested and when an AI client uses one of the IATO tools. It sends the API key, and your site's URL when a crawl is started. It receives crawl data about your public pages: SEO audit results, sitemap information, broken links and AI-generated suggestions.

The IATO platform can also read your site. If you give IATO the site's API key from Settings > IATO MCP, for example to use the IATO dashboard's "Sync pages, posts, menus, and taxonomy from WordPress", IATO uses the plugin's read tools to copy posts, pages, menus and taxonomy into your IATO project. It cannot do this without that key, and regenerating the key stops it.

* [IATO Terms of Service](https://iato.ai/terms)
* [IATO Privacy Policy](https://iato.ai/privacy)

Image uploads from a URL are off by default. When an administrator turns them on, `create_media` downloads images only from the hosts the administrator lists.

The plugin also runs an OAuth 2.1 authorization server on your WordPress site, so that MCP clients such as Claude can connect through the standard "Add custom connector" flow. That exchange is between the MCP client and your site. No data is sent to third parties during authentication.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload it to `/wp-content/plugins/iato-mcp/`.
2. Activate it.
3. Open the setup wizard, from the notice that appears or from the link under Settings > IATO MCP. It shows your MCP server URL.
4. In Claude (claude.ai or Claude Desktop), add a custom connector, paste the MCP server URL and click Connect. Claude sends you to your WordPress site: log in as an administrator and approve the connection.
5. For a client that uses a config file instead, create an Application Password under Users > Profile and use the config the wizard gives you.
6. Optional: under Settings > IATO MCP, enter an IATO API key for the IATO tools. Use a separate IATO API key for each client site: a key gives access to every crawl in its IATO account, so a key shared between sites lets one site's administrators see the others' crawl data.

For detailed setup instructions, see the [IATO MCP documentation](https://iato.ai/wordpress-mcp-docs).

== Frequently Asked Questions ==

= Do I need an IATO account? =

No. The plugin's 64 WordPress tools work without one, and 57 of them are on by default. An [IATO account](https://iato.ai) adds 12 tools for administrators: starting, checking and listing crawls, SEO audit, broken links, content gaps, orphan pages, navigation audit, taxonomy analysis, AI suggestions, performance reports and the sitemap. IATO's [Free plan](https://iato.ai/pricing) covers 500 pages per crawl. If you manage several client sites, give each site its own IATO API key: a key can read every crawl in its account.

= Which WordPress version is required? =

WordPress 6.2 or later, with PHP 8.0 or later. A few features need more:

* Keeping inline SVG and style blocks in Elementor HTML widgets: WordPress 6.5
* WordPress Abilities for the IATO tools: WordPress 6.9
* Editing Atomic Editor (V4) elements: Elementor 4.3.0
* Theme Builder templates and their display conditions: Elementor Pro

= Does this work on shared hosting? =

Yes. The plugin uses standard HTTP requests (one per MCP call) rather than long-lived connections, so it works on all hosting environments including shared hosting.

= Which AI clients are supported? =

Any MCP client that can connect to a remote server over Streamable HTTP. In Claude (claude.ai and Claude Desktop), add the site as a custom connector. Claude Code, Cursor, VS Code and other clients connect with the server URL. Clients that only start local servers connect through the `mcp-remote` bridge, with the config the setup wizard gives.

= How does authentication work? =

There are three ways to connect, all to the same endpoint, `/wp-json/iato-mcp/v1/message`:

* **OAuth 2.1 (recommended).** Claude's "Add custom connector" flow uses it. An administrator approves the connection on your site. Each connection gets its own expiring token, acts as the administrator who approved it, and can be revoked on its own under Settings > IATO MCP > Connected OAuth clients.
* **Application Password.** For clients that use a config file or plain HTTP. Requests act as the WordPress user the password belongs to, who needs at least the `edit_posts` capability, and each tool checks that user's permissions. Revoke the password under Users > Profile.
* **Site API key (Bearer token).** The plugin generates it on activation and shows it under Settings > IATO MCP. The IATO platform's integrations use it, and so can HTTP clients you configure yourself. Requests act as the administrator assigned as the key's owner. Regenerate the key to cut off everything that uses it.

= Is my content sent to IATO or Anthropic? =

The plugin itself never sends your posts or pages to IATO. It sends IATO only your API key, and your site's URL when a crawl is started. IATO crawls your public pages the way a search engine does. If you give the IATO platform your site's API key to sync your content, IATO reads posts, pages, menus and taxonomy through the plugin (see External services).

Whatever your AI client reads through the plugin goes to the company that runs that client, such as Anthropic for Claude, and is handled under that company's terms. Turn off any tool you do not want AI clients to use.

= A page lost an embed or script after an IATO edit. How do I repair it? =

Open the page in Elementor, go to History, then Revisions, and apply the last revision saved by a person before the first IATO edit. Then re-apply the IATO changes you want to keep. If revisions are disabled on the site, there is no earlier copy to restore.

= A page edited with IATO shows a missing link or image, or Elementor will not save it =

IATO 1.13.0 and earlier could store a plain value on an Elementor Atomic Editor (V4) element, such as a heading, paragraph, image or button built with the Atomic Editor, where Elementor expects a typed value. Elementor then drops a link or image it cannot read, and saving the page in Elementor fails with "Settings validation failed".

To find the affected elements, read the page with get_elementor_data, get_elementor_widget, list_elementor_widgets or find_elementor_widgets. Since 1.13.1 they flag the element with stored_value_invalid: true, and invalid_keys names the settings to repair; get_elementor_data also lists them under invalid_elements.

To repair an element, send each listed setting again with update_elementor_widget, set_widget_setting or set_heading_level. From 1.14.0 (with Elementor 4.3.0 or later) the plain value works, for example {"tag": "h2"} or {"link": {"destination": "https://example.com/"}}: IATO stores it in Elementor's format and the element is repaired. With 1.13.1, or on a site without Elementor 4.3.0, send it as a typed value, in the shape get_elementor_widget shows under settings for the element, for example {"tag": {"$$type": "string", "value": "h2"}}. When several elements on a page are affected, repair them one at a time; Elementor saves the page normally again once the last one is repaired. A damaged element cannot be copied (update_elementor_patch refuses the copy): repair the source first, then copy it. You can also restore a revision saved before the IATO edit, under History, then Revisions, in Elementor.

= Can IATO edit Elementor Atomic Editor (V4) elements? =

Yes, from 1.14.0, with Elementor 4.3.0 or later and the Atomic Editor turned on. set_heading_level, set_widget_setting, update_elementor_widget, update_elementor_widgets_bulk and update_elementor_patch take plain values for atomic headings, paragraphs, buttons, images and containers, and update_alt_text takes post_id and element_id for an atomic image. IATO converts and validates each value with Elementor's own code and keeps every setting the call does not name as it was stored. Changes go live, like edits to classic widgets, and each one can be rolled back on its own.

The alt text of an image from the Media Library belongs to the attachment, so IATO changes it there, and the change applies everywhere that image is used. Global classes and component instances are not edited by IATO yet. On sites with an older Elementor, IATO accepts only typed values for atomic elements and says what is missing. While the Atomic Editor is off, IATO does not change atomic elements at all, because Elementor cannot check them; the rest of the page can still be edited.

= Why do visitors still see the old page after a change? =

IATO purges the cache plugins it finds after each change and says what happened in the tool's `cache` object. A cache in front of WordPress, such as Cloudflare or a host's CDN, is out of its reach: the response names it in `cache.notes` so the AI client can tell you to purge it from that service's dashboard. On Hostinger, LiteSpeed's full purge also clears hCDN when Hostinger Tools 3.0.79 or later is active. A single-page purge cleared it too on the Hostinger site we tested, but IATO does not control that, so if a page still looks stale, purge hCDN in hPanel.

= Why was a style block or an SVG removed from my HTML widget? =

Content written through MCP is filtered, whatever the role of the user behind the connection, and `<style>` and `<svg>` are not on the list of allowed tags. The response's `sanitized_report` says what was removed from each value. To keep them in Elementor HTML widgets, turn on "Allow inline SVG and style blocks in HTML widgets for administrators" under Settings > IATO MCP. It applies only to a WordPress user who holds both `unfiltered_html` and `manage_options`, and needs WordPress 6.5 or later. Scripts, event attributes, `javascript:` URLs, `foreignObject`, `@import` and off-site `url()` are still removed.

= Can I control which tools are available? =

Yes. Under Settings > IATO MCP, in the Tools section, turn individual tools on or off. The seven page asset tools (page CSS, fonts, scripts and preview links) start switched off. AI clients read the tool list when they connect, so reconnect the client after a change.

= Can AI clients upload arbitrary files to my media library? =

Only images, and only when the calling user has the `upload_files` capability. The `create_media` tool enforces an image-only MIME allowlist (JPEG, PNG, GIF, WebP, AVIF) verified against actual file bytes. The claimed mime_type is never trusted. SVG uploads are not supported in this release. Files exceeding the size cap (default 10MB) or the dimension cap (default 8000×8000) are rejected, as are filenames containing `.php`, `.phtml`, or `.htaccess`. URL-source ingestion is disabled by default; admins who enable it must also configure a host allowlist, and private/loopback/cloud-metadata IPs are rejected even for allowlisted hosts. Each upload counts against a per-user rate limit (default 20/min) and emits a `change_receipt`: rolling back fully deletes the attachment file. All four limits are configurable from Settings > IATO MCP.

Images over about 3 KB are sent in pieces with `media_upload_start`, `media_upload_chunk` and `media_upload_finish`. They go through the same checks, the client sends a SHA-256 digest of the whole file, and the upload is refused if the file that arrives does not match it.

== Screenshots ==

1. MCP connection: the server URL, the site API key, and the administrator every request with that key acts as
2. Tools: turn each tool on or off, or a whole category at once
3. Setup wizard: add the server to Claude as a custom connector, or use a config file
4. OAuth approval screen: approve a client and see which user it will act as
5. Connected OAuth clients: revoke one connection, or all of them
6. Page assets: page CSS and fonts, live preview links and page scripts, with buttons to remove or revoke them

== Changelog ==

= 1.21.2 =
* Fix: a write through the Elementor tools that changed no text on the page (a widget with no text, a class or style change) replaced the page's `post_content` with Elementor's full front-end markup: wrapper divs, data attributes and classes, about four times the size. Elementor's own save had already written its text version; the plugin read the unchanged text as "Elementor did not write" and rendered the page itself. `post_content` now stays Elementor's text version, and when Elementor does not save the page (a key with no user, a save that fails), the plugin writes that same text version, as rollback does. Visitors were never affected: they see Elementor's render. A page that already holds the markup gets its text version back with the next write that changes text, or a rollback.
* Fix: `post_content_length` is the length stored. It was measured before the plugin removed style blocks, so it could be tens of kilobytes too large.
* Changed: `render_error` is replaced by `content_note`, which says when `post_content` could not be brought up to date. A page holding elements Elementor cannot load keeps its `post_content`, with a note, because Elementor's text version would leave those elements out.

= 1.21.1 =
* Fix: `update_elementor_patch`: `add` at a list index now inserts before the item there, as RFC 6902 says and the tool's description promised. Since 1.3.0 it replaced the item, so a widget added at an index deleted the widget that stood there. `copy` to an index overwrote the item there and `move` to an index deleted one; both now insert. An index past the end of a list is refused instead of leaving a gap, and an element cannot be moved into its own children. Use `replace` to overwrite. A page that lost an element this way can be put back by rolling back that write's receipt within 90 days, or from Elementor's revisions.
* Fix: `update_elementor_patch`: `remove` of an item in the middle of a list left a gap in its numbering, so a later op in the same patch could not find the next item, and the list could be stored as a JSON object.
* New: each `update_elementor_patch` op on a list item returns `list` (where it acted, the list's length before and after, and its items' ids afterwards), on dry runs too, so a misplaced insert shows before it is written.
* Fix: `stored_matches_input` no longer reads a different key order as a different value. Elementor's save writes an element's keys in its own order, so a real write said false where its dry run said true.
* Fix: an `add` to `/-`, and an op whose position a later op in the same patch shifted, now get `stored_matches_input`. They used to get nothing, or be compared with whatever stood at the old position.
* New: when the stored value differs, `stored_difference` says how: `markup_normalized` (the same HTML written differently, such as single quotes rewritten as double quotes), `keys_added` or `keys_removed` (with `added_paths` and `removed_paths`; Elementor's save drops `isInner` from a widget), or `changed`. A `sanitized_report` entry for a value WordPress rewrote without removing anything now says so; it used to be empty.
* Fix: `get_page_fonts` and `set_page_fonts` report where each Google font is served from (`served_from`: `local`, `local_after_first_view` or `google`). Elementor serves a local copy it already holds even with "Load Google Fonts Locally" off, so `google_fonts_local` was false on sites that served fonts locally. It now means every Google font on the page comes from this site; `local_google_fonts_setting` gives the setting itself.
* New: Settings > IATO MCP > Tools says that AI clients read the tool list when they connect, and saving a change to the tools says to reconnect the connector. This server cannot tell a connected client that the list changed.

= 1.21.0 =
* New: `set_page_css` and `get_page_css` give one page its own CSS, printed in that page's head only. They follow the same rules as Additional CSS: nothing loaded from another site, at most 200 KB, and anything that would close the style element removed. The CSS is checked again when it is printed. Needs a WordPress user who may edit CSS (administrators and editors) and `edit_post` on the page.
* New: `set_page_fonts` and `get_page_fonts` load Elementor font families on one page only, through Elementor's own font loader, so "Load Google Fonts Locally" is respected. Families Elementor does not know are refused.
* New: `create_preview_link` makes an expiring URL (15 minutes by default, an hour at most) that shows a draft, pending or scheduled post without a login, so an AI client can check its work. Responses to it are not cached and carry noindex and no-referrer. Rolling back its receipt revokes it, and publishing or trashing the post ends it. Needs permission to publish the post.
* New: `set_page_script` and `get_page_script` store inline JavaScript for one page. It runs only after an administrator reads it and approves it in wp-admin with their password, and any change waits for approval again. It needs the new "Allow page scripts" setting, off by default, and an administrator who may post unfiltered HTML. `<script`, `</script`, `<!--` and invisible or direction-changing characters are refused, and the approval screen marks every character outside plain ASCII. Turning the setting or the tool off, or deactivating the plugin, stops every page script and purges those pages, even with cache purging off.
* New: These seven tools ship switched off. An administrator turns them on in Settings > IATO MCP > Tools. A new "Page assets" tab lists every page with page CSS or fonts and every live preview link, with buttons to remove or revoke them. The Plugins screen warns before the plugin is deactivated, since those pages lose their CSS and fonts while it is off. Uninstalling keeps page CSS and fonts.
* New: HTML in Elementor widgets keeps every `aria-*` attribute, `inert`, `disabled` and `readonly` where HTML has them, and `<input>` with ordinary types (not password, file, image, hidden or submit). Nothing that passed before is removed now: a live check runs a fixture set through 1.20.0's filter and this one.
* New: With "Allow inline SVG and style blocks" on, HTML widgets also keep `ellipse`, `text`, `tspan`, `defs`, gradients, `stop`, `marker`, `clipPath`, and `use` pointing at `#id` in the same SVG. A `@property` `syntax` descriptor such as `syntax:'<number>'` is kept in style blocks outside an `<svg>` (WordPress 6.7 or later).
* New: Elementor write responses say for each change whether the stored value is what was sent (`stored_matches_input`). When it is not, they say what was stored. `update_elementor_widget`, `update_elementor_patch` and `update_elementor_data` take `return: "minimal"`, which returns sizes and hashes instead of whole values.
* Fix: `sanitized_report` now names style declarations WordPress removed from `style` attributes. When Elementor's own filter removed more for a user without `unfiltered_html`, that used to be left out of the report.
* Fix: a removed `<style>` block's report names `set_page_css` and where to turn it on.
* Fix: With "Allow inline SVG and style blocks" on, a value with markup inside an SVG `<title>` is no longer kept by the opt-in: a browser reads that markup, while the filter read it as text. Such a value now goes through the ordinary rule.

= 1.20.0 =
* New: `update_post` and `create_post` take `parent`, for pages and other hierarchical types, and `menu_order`. A page's URL becomes its parent's URL plus its slug, so /services/web-development/ can be built. `parent` is refused for posts (`parent_not_supported`). A page cannot go under itself or a page below it (`parent_cycle`). The parent must be a page of the same type that the caller can read. A parent that is not published is a warning.
* New: A new parent moves the page and every page under it. When any of those URLs can be opened now, the call needs `confirm_url_break`. The dry run lists every URL that moves (`url_changes`) and the redirects it would create. By default each published page's old URL gets a 301 to its new one (`redirect_old_urls`), through the same redirect plugin `update_redirect` uses. Creating those redirects needs an administrator; an Editor can pass `redirect_old_urls: false`. One receipt covers the parent, the order and a slug changed in the same call, and its rollback removes the redirects before putting the page back. The whole site's cache is purged.
* New: `update_site_settings` takes `permalink_structure`, `category_base` and `tag_base`, and `get_site_settings` returns the two bases. A change moves every post and the category, tag and author archives, so it needs `confirm_url_break`. The dry run reports `moved_count` and the first 25 URLs. 301 redirects from the old URLs are created by default, up to 1,000; with more, or more than the built-in list or Safe Redirect Manager can hold, none are created and a warning says so. Plain permalinks are refused, and so is a first change away from plain or /index.php/ permalinks, which belongs in Settings > Permalinks. Rollback restores the settings and removes the redirects.
* New: The rewrite rules are rebuilt by the next request after a permalink change, as wp-admin does, not in the request that made it. Rebuilding them there would store rules without the ones other plugins add, such as WooCommerce's account pages, and with category archives at the old URL.
* New: Moving a page back, or changing the permalinks back, with a new call rather than a rollback takes away the redirects the first change left, which would otherwise loop. A redirect someone else set on a URL that a change would make live is refused with `new_url_redirected`.
* New: A permalink change whose new post URL is already a published page's URL is a warning, and so is a post slug that does the same: WordPress shows the page there. A page at the start of the new structure, such as /blog/ for /blog/%postname%/, keeps working, including /blog/page/2/.
* New: `get_post` and `get_posts` return `parent` and `menu_order`.
* Fix: `update_post` refused a page's slug when any post of any type used it, such as a menu item called "services". It now uses WordPress's own rule: a page is compared with pages and attachments under the same parent, a post with posts. A draft is checked as if published, so the slug is not renamed "-2" when it goes live. A slug WordPress reserves, such as `feed`, is refused with the slug WordPress would use instead.
* Changed: Server instructions add a "Restructuring URLs" section (parent pages first, dry run, apply, then update links in content and menus), and on Elementor sites list `slug`, `parent` and `menu_order` among the fields `update_post` may change.

= 1.19.3 =
* Fix: On sites with Elementor, the instructions the plugin gives AI clients said `update_post` could change only `status`, `title` and `excerpt`, so clients were told not to change a post's date or author with it. The list now includes `date`, `date_gmt`, `allow_future` and `author`, and says content edits go through the Elementor tools.
* Tests: a unit test checks that every `update_post` field the instructions name is in its input schema.

= 1.19.2 =
* New: `get_site_info` returns `plugin_version`, the IATO MCP version running on the site, so one call confirms an update is live. Every caller sees it.

= 1.19.1 =
* Changed: The tool descriptions clients read are clearer about publish dates and authors. `create_post`'s description mentions them, `date` and `date_gmt` say they are ISO 8601 and that `date_gmt` is worked out from `date` when omitted, and `allow_future` says what is refused and when a post is scheduled.
* Changed: `create_media`'s description sends files over about 3 KB to `media_upload_start`, `media_upload_chunk` and `media_upload_finish` instead of URL ingestion, and says the response carries `sha256` and `decoded_bytes`.
* Fix: `get_iato_sitemap` reads `crawl_id` but did not list it, so clients could not see it. It is listed now. The WordPress ability already listed it.
* Fix: `create_post` stores no excerpt, but an `excerpt` sent with it could appear in `sanitized_report` as text that was removed. It is now ignored, as the schema says.
* Tests: a unit test checks that every tool's input schema lists exactly the arguments its handler reads, so the two cannot drift apart.

= 1.19.0 =
* New: `update_post` and `create_post` take `date` (the site's local time), `date_gmt` (UTC), `allow_future` and `author`. A published post given a future date is refused with `future_date_on_published` unless `allow_future` is true, when it is scheduled and the response says so. A draft keeps its status and uses the date when published. Rollback restores the date, the UTC date and the status together. Changing an author needs `edit_others_posts`, and the new author must be able to edit that type of post.
* New: `get_posts` returns `date` and `date_gmt`, and `get_post` returns `date_gmt`.
* Changed: `get_post`'s `date` is now the site's local time, so that it can be sent back to `update_post`. It was UTC: read `date_gmt` for that. `modified` is still UTC.
* Changed: Publishing a draft that has a future date is refused with `future_date_on_published` unless `allow_future` is true. WordPress scheduled it without saying so.
* New: `create_media` takes `sha256`, and refuses a file that arrives altered with `checksum_mismatch`. It always returns `sha256` and `decoded_bytes`, so a caller can compare without sending a digest.
* New: `create_media` refuses bytes that are not a whole image of the declared type with `invalid_image`: PNG chunk checksums and end markers are checked, not only the header. Sizes WordPress expected but could not make are a warning rather than an empty list. Its errors are now JSON text with a code and details.
* New: `media_upload_start`, `media_upload_chunk` and `media_upload_finish` upload an image over about 3 KB in pieces, with a digest on the whole file and optionally on each piece. The result is what `create_media` returns. Retrying a chunk or a finish is safe. Unfinished uploads are removed after an hour with no activity.
* New: `get_custom_css` and `update_custom_css` read and set the theme's Additional CSS, stored exactly as sent. CSS that loads anything from another site is refused, and a person can still add that in the Customizer. Administrators only, with a WordPress user behind the credential. Rollback restores the previous CSS for the theme it was written for.
* Changed: The post that holds the theme's Additional CSS can no longer be used as a target of other write tools such as `update_post` and `update_post_meta` (`custom_css_target`). Use `update_custom_css`.
* New: `update_user_profile` changes a user's display name, nickname, first and last name, description and website. Email, password and roles cannot be changed. The display name must be one WordPress offers for that user, or the call must set the nickname to the same value.
* Fix: Changing the site title or tagline through `update_site_settings` saved the value but returned an error and no receipt on a site with Elementor, when the site key had no owner or Elementor's kit was missing or in the trash. It now returns the receipt as usual, and rollback works the same way.
* Fix: With a site key that had no owner, Elementor's Site Settings kept the old title or tagline after such a change, and publishing Site Settings wrote it back. It now follows the change.

= 1.18.0 =
* New: `update_site_settings` sets the site icon: `site_icon` takes a Media Library attachment ID, and 0 clears it without deleting the attachment. The image must be PNG, JPEG, GIF or WebP and square; it is not cropped, and under 512x512 it is set with a warning. The response gives before and after with ID and URL, `icon_url_32` and `icon_url_192`, and `warnings`. It has `dry_run` and rollback, including back to no icon, and purges the whole site.
* New: `update_site_settings` creates the sizes WordPress uses for a site icon, and only those, so `link rel="icon"` points at a file of the right size. Sending the ID that is already set creates any that are missing, which repairs an icon set through Elementor's Site Favicon.
* New: `update_site_settings` sets the theme's logo: `custom_logo` has the same checks, except that it does not have to be square. WordPress keeps a logo per theme, so its rollback is refused after a theme switch.
* New: `get_site_settings` returns `site_icon` and `custom_logo`, each `{id, url}` or null. A null `url` means the image no longer exists.
* New: When Elementor's Site Settings has a saved favicon or logo, a change through `update_site_settings` or its rollback updates it too. Publishing Site Settings otherwise writes the old image back over the new one.
* Fix: Rolling back `create_media` for the image used as the site icon or logo is now a conflict needing `force`, which deletes the image and clears the setting. It left the site pointing at an image that no longer existed.

= 1.17.0 =
* New: `create_elementor_template` creates a template the way Add New Template does: header, footer, single post and page, archive, search results, 404, popup, section, container or page. Theme Builder types need Elementor Pro. It builds a draft, fills it, sets its conditions and publishes last, so a failure on the way leaves nothing showing. A header or footer with conditions cannot be published empty, because it would replace the theme's with nothing. Administrators only.
* New: `set_elementor_template_conditions` sets where a template shows, through Elementor Pro's own conditions manager, which rebuilds the cache that decides what renders. It returns before, after and `verified`, and warns when another published template may show on the same pages. The same conditions again rebuild the cache if it has gone stale. Administrators only.
* New: `create_menu` creates a navigation menu, optionally in a theme location, and `assign_menu_location` puts a menu in a location or clears it. Rollback of `create_menu` deletes the menu and gives the location back; a menu that has items needs `force`, because deleting a menu deletes its items for good, and one given a location afterwards needs that change rolled back first.
* New: `get_menus` lists every location the theme registers, with the menu in it (`locations`).
* New: `resolve_url` says which Theme Builder header and footer a URL gets (`theme_locations`), by Elementor Pro's own rules applied to that URL. A page on Elementor Canvas gets neither, and says so (`hidden_by_page_template`). Another host gives null, and what it cannot judge goes under `not_evaluated`.
* New: `list_elementor_templates` returns `conditions_revision`, for `if_revision`.
* New: Server instructions describe the header and footer workflow, menus, and `elementor_header_footer`.
* Fix: `resolve_url` and `get_post` with `include_shadowing` reported a header, footer, popup or section on Entire Site as what renders every page, so a request to edit a page could end up editing the site header. Only templates that replace a page's content count now.
* Fix: `resolve_url` follows Elementor Pro's rules for the blog, archives, the front page and author archives. A blog archive template was reported as shadowing ordinary pages.
* Fix: `resolve_url` decides what kind of page a URL is from WordPress's own rewrite rules and query, instead of guessing from its path. Later pages of the blog and of archives, pretty search URLs, custom taxonomies and plain-permalink query strings were read as not found or as the front page, and terms and authors that do not exist as archives. `route_type` gains `taxonomy_archive`, `date_archive`, `search_results` and `blog_home` for pages it called `404`.
* Fix: A singular template is no longer reported as shadowing the blog index or a search.
* Fix: `update_post` refuses to publish an empty header or footer that has display conditions, which would remove the theme's from those pages.
* Fix: On a post with Elementor off, `update_post` no longer says a content edit will not reach visitors.
* Fix: The result of Save Settings now appears next to the button. It appeared at the top of a long page, out of sight, and a failed save looked the same as a successful one.

= 1.16.0 =
* Security: Hardened which stored data a post meta write can reach, and how a rollback treats stored data. Update recommended.
* Change: update_post_meta refuses a key the database would read as a different key already stored on the post or, for a key new to the post, as another spelling stored on the site (meta_key_mismatch). A new key with spaces or characters outside ASCII needs force. A key over 255 characters is refused (meta_key_too_long); nothing was stored before, though the response said it was.
* Fix: Changing a page's title, status, slug or excerpt with update_post no longer removes iframes and scripts from content the call did not mention. Rolling back a post field now restores it as it was stored; the same filter used to remove them from the restored value, and rollback still reported success.
* Fix: An Elementor element tree that would crash the page for visitors, such as a JSON object or an element with no elType, is refused with elementor_invalid_tree. It used to be stored and reported as a success.
* Fix: A revision's ID can no longer be used as a write target (revision_target). WordPress sent the change to the parent post, past the checks made on it.
* Fix: Rolling back an Elementor write on a page with no elements yet now restores the content visitors saw, also when Elementor's flag was on.
* Fix: Rolling back "Elementor turned off" after the page was edited is now a conflict, because turning it back on would hide that edit.
* Fix: update_seo_data, and its rollback, no longer damage SEO plugin variables such as %%category%% and %%date%%, or encoded characters in a canonical URL when it is read.
* Fix: A widget that fails while rendering can no longer corrupt the response of the tool call, which lost the change_id of a write that had happened.
* Fix: A write could return a change_id that was never saved, so rollback could not find it. This happened on healthy sites when a meta key was longer than 100 characters, and when a database column was missing. Long keys now save. If an undo record really cannot be saved, the response says so, with no change_id, and Diagnostics shows it.
* Fix: Elementor writes no longer overwrite the content of a page sent "Back to WordPress Editor". They are refused unless update_elementor_data is called with activate_builder: true, and rollback then restores the content, the setting and the old layout. get_page_builder reports these pages with elementor_tree_present.
* Fix: A new page given a layout with update_elementor_data now renders as an Elementor page.
* Fix: Rolling back an Elementor change now also updates post_content, which search and feeds read.
* Fix: A stripped style or script no longer leaves its CSS or code on the page as visible text (WordPress 6.5 or later).
* Change: The inline style block setting checks CSS more strictly. An independent review, with Chrome, found ways past the old check to load files from other sites. A style block is now dropped whole if its text names another site, including in a // comment or an attribute selector, and a url() pointing at this site needs a path.
* New: create_post, update_post, update_seo_data, assign_term, create_term, update_term and delete_term now honour dry_run. They used to ignore it and really write. A tool with no dry run now refuses the flag with dry_run_unsupported instead of running.
* Fix: delete_term no longer reports the default category as deleted when used with a key that has no owner.
* New: Diagnostics shows database table problems and undo records that could not be saved. Table problems are retried automatically.

= Earlier releases =
The changes in 1.15.1 and earlier are in [changelog.txt](https://plugins.trac.wordpress.org/browser/iato-mcp/trunk/changelog.txt).

== Upgrade Notice ==

= 1.21.2 =
Fixes Elementor writes that changed no text storing the full front-end markup in post_content. It now stays Elementor's own text version, and post_content_length reports what is stored.

= 1.21.1 =
Fixes update_elementor_patch: add at an index now inserts, as RFC 6902 says, instead of replacing (deleting) the element there. Use replace to overwrite. Also clearer stored-value and font-source reports.

= 1.21.0 =
Adds page CSS, fonts, approved page scripts and draft preview links (all switched off until you turn them on), keeps ARIA, form attributes and more SVG in Elementor HTML, and says what Elementor stored.

= 1.20.0 =
Adds page parents and permalink settings, with 301 redirects from the old URLs and a one-step rollback, and stops update_post refusing a page slug that a menu item uses.

= 1.19.3 =
On Elementor sites, AI clients are now told they can change a post's date and author with update_post.

= 1.19.2 =
get_site_info now returns plugin_version, so an AI client can confirm which IATO MCP version a site is running in one call.

= 1.19.1 =
Tool descriptions for post dates, authors and image uploads now match what the tools accept, and get_iato_sitemap lists its crawl_id parameter.

= 1.19.0 =
Adds post dates and authors, safe chunked image uploads, site-wide CSS and user display names. get_post's date field is now local time: read date_gmt for UTC.

= 1.18.0 =
Adds site_icon and custom_logo to update_site_settings and get_site_settings, so an AI client can set the favicon and logo from the Media Library, with dry_run and rollback.

= 1.17.0 =
Adds tools to create Theme Builder templates, set their display conditions, create menus and assign them to locations. resolve_url no longer reports a site-wide header as what renders each page, and says which header and footer a URL gets.

= 1.16.0 =
Security update; update recommended. Also fixes undo records that were never saved, title edits removing iframes from content, and Elementor writes overwriting classic pages (now refused unless activate_builder is passed). dry_run works on seven more tools.

= 1.15.1 =
Security update; update recommended. Elementor rebuilds each page once on its next view.

= 1.15.0 =
Changes made through MCP now purge page caches and say so, a new tool sets the site title and home page, and responses say exactly what sanitizing removed. Classic Elementor edits now rebuild only the edited page's CSS.

= 1.14.0 =
Heading, text, link and image alt fixes now work on Elementor Atomic Editor (V4) elements (Elementor 4.3.0 or later), validated with Elementor's own code and with a rollback for each change. Sending a setting again repairs elements damaged by IATO 1.13.0 or earlier.

= 1.13.1 =
Stops plain values from breaking Elementor Atomic Editor (V4) elements. Pages edited with earlier versions may have broken atomic elements (a missing link or image, or a page Elementor will not save); the FAQ explains how to find and repair them.

= 1.13.0 =
Security release. OAuth connections now get their own revocable tokens: remove and re-add the Claude connector once. MCP content is filtered for every role, rollbacks record who made each change, and IATO crawl tools need an administrator.

= 1.12.2 =
Fixes Elementor edits made with an IATO key or OAuth connection removing embeds, scripts and other HTML from other widgets. To repair a page edited before this update, restore an earlier version in Elementor's History panel, under Revisions.

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
