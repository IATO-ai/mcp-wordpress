=== IATO MCP ===
Contributors: iato
Tags: mcp, ai, seo, sitemap, claude
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes an MCP server from any self-hosted WordPress site, enabling AI agents like Claude to audit and fix your site in a single workflow.

== Description ==

WordPress.com has a built-in MCP server. Now self-hosted WordPress does too.

IATO MCP connects your WordPress site to Claude Desktop and other MCP-enabled AI clients. Once connected, you can ask Claude to audit your site and fix SEO issues, identify orphan pages, clean up broken links, and more — all in a single conversation.

= How it works =

1. Install and activate the plugin
2. Generate an Application Password in WP Admin > Users > Profile
3. Paste the provided config snippet into Claude Desktop
4. Connect your IATO account for AI-powered analysis (free up to 500 pages)

= What Claude can do =

**Without an IATO account (WP tools only):**
- Read and edit posts, pages, and media
- Update SEO titles and meta descriptions (Yoast, RankMath, SEOPress supported)
- Update image alt text
- Read navigation menus and taxonomy terms
- Search content across the site

**With an IATO account (full analyze-and-fix pipeline):**
- Crawl your site and run a full SEO audit
- Fix title, meta description, and alt text issues automatically
- Identify orphan pages not linked from any navigation menu
- Surface thin content with specific improvement recommendations
- Map broken links to source posts for direct editing
- Get AI-prioritized suggestions across all areas
- Flag slow pages with contributing factors

= Supported SEO plugins =

- Yoast SEO
- RankMath
- SEOPress
- Fallback to native WordPress title if none detected

= Example prompts =

> "Crawl my site and fix all missing meta descriptions"

> "Show me pages that aren't in any navigation menu and add them to the right place"

> "What are the most impactful improvements I can make to my site right now?"

> "Find all broken links and tell me which posts contain them"

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/iato-mcp/`
2. Activate the plugin via the Plugins menu in WordPress
3. Follow the setup wizard that appears after activation
4. Optionally, go to Settings > IATO MCP to enter your IATO API key

== Frequently Asked Questions ==

= Do I need an IATO account? =

No. The plugin works standalone for reading and editing WordPress content. An IATO account (free for up to 500 pages) unlocks the analysis tools: SEO audit, broken links, content gaps, orphan pages, AI suggestions, and performance reports.

= Which WordPress version is required? =

WordPress 5.6 or higher. Application Passwords, which handle authentication, were added in WordPress 5.6.

= Does this work on shared hosting? =

Yes. The plugin uses standard HTTP requests (one per MCP call) rather than long-lived connections, so it works on all hosting environments including shared hosting.

= Which AI clients are supported? =

Any MCP-enabled client: Claude Desktop, Cursor, VS Code with GitHub Copilot, and any client that supports the Streamable HTTP MCP transport.

= Is my content sent to IATO or Anthropic? =

WordPress content (post titles, meta descriptions, etc.) is never sent to IATO or Anthropic. IATO crawls your public URLs the same way a search engine would. Claude processes content within your AI client session only.

== Changelog ==

= 0.1.0 =
* Initial release — Phase 1 MVP

== Upgrade Notice ==

= 0.1.0 =
Initial release.
