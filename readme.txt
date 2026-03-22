=== IATO MCP ===
Contributors: iato
Tags: mcp, ai, seo, sitemap, claude
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes an MCP server from any self-hosted WordPress site, enabling AI agents like Claude to audit and fix your site in a single workflow.

== Description ==

WordPress.com has a built-in MCP server. Now self-hosted WordPress does too.

IATO MCP connects your WordPress site to Claude Desktop and other MCP-enabled AI clients. Once connected, you can ask Claude to audit your site and fix SEO issues, identify orphan pages, clean up broken links, and more — all in a single conversation.

= How it works =

1. Install and activate the plugin
2. Follow the setup wizard — copy the config into Claude Desktop, or use "Add Custom Connector" with your site URL
3. Connect your IATO account for AI-powered analysis (free up to 500 pages)

= What Claude can do =

**Without an IATO account (17 WordPress tools):**

* Read and edit posts, pages, and media
* Create new posts and pages
* Update SEO titles and meta descriptions (Yoast SEO, RankMath, SEOPress)
* Update image alt text
* Read and edit navigation menus
* Manage categories, tags, and taxonomy terms
* Search content across the site
* Read site info and settings
* Read and filter comments

**With an IATO account (9 bridge tools — full analyze-and-fix pipeline):**

* Crawl your site and run a full SEO audit
* Fix title, meta description, and alt text issues automatically
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

**IATO API** ([https://iato.ai](https://iato.ai)) — When you enter an IATO API key in the plugin settings, the plugin sends requests to `https://iato.ai/api/v1` to retrieve crawl data, SEO audit results, sitemap information, and AI-generated improvement suggestions. No data is sent to IATO until you configure an API key. Your public page URLs (as crawled by IATO) and crawl analysis results are transmitted.

* [IATO Terms of Service](https://iato.ai/terms)
* [IATO Privacy Policy](https://iato.ai/privacy)

The plugin also implements an OAuth 2.0 authorization server on your WordPress site so that MCP clients like Claude Desktop can authenticate via the standard "Add Custom Connector" flow. This communication stays between the MCP client and your WordPress site — no data is sent to third parties during authentication.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/iato-mcp/` or install via the WordPress plugin directory
2. Activate the plugin via the Plugins menu in WordPress
3. Follow the setup wizard that appears — it provides the JSON config for Claude Desktop
4. In Claude Desktop, either paste the JSON config or use "Add Custom Connector" and enter your site URL
5. Optionally, go to Settings > IATO MCP to enter your IATO API key for the full analysis pipeline

== Frequently Asked Questions ==

= Do I need an IATO account? =

No. The plugin works standalone for reading and editing WordPress content with 17 built-in tools. An IATO account (free for up to 500 pages) unlocks 9 additional bridge tools: SEO audit, broken links, content gaps, orphan pages, navigation audit, taxonomy analysis, AI suggestions, and performance reports.

= Which WordPress version is required? =

WordPress 6.0 or higher with PHP 8.0+. The plugin uses the WordPress REST API and implements OAuth 2.0 for secure authentication with AI clients.

= Does this work on shared hosting? =

Yes. The plugin uses standard HTTP requests (one per MCP call) rather than long-lived connections, so it works on all hosting environments including shared hosting.

= Which AI clients are supported? =

Any MCP-enabled client: Claude Desktop, Cursor, VS Code with GitHub Copilot, and any client that supports the Streamable HTTP MCP transport.

= How does authentication work? =

The plugin generates a secure API key on activation. You can authenticate in two ways: paste the provided Bearer token config into your AI client, or use Claude Desktop's "Add Custom Connector" flow which handles OAuth 2.0 with PKCE automatically.

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

= 1.0.0 =
* 17 WordPress native tools: posts, pages, media, SEO, menus, taxonomy, comments, site info and settings
* 9 IATO bridge tools: sitemap, SEO fixes, broken links, content gaps, orphan pages, navigation audit, AI suggestions, performance reports, taxonomy analysis
* OAuth 2.0 authorization server with PKCE for Claude Desktop connector flow
* Dynamic client registration (RFC 7591)
* SEO adapter supporting Yoast SEO, RankMath, and SEOPress
* Card-based settings UI with per-tool enable/disable toggles
* Setup wizard with auto-generated connection config
* Dry-run mode for destructive write operations
* Plugin-generated API key with Bearer token authentication

== Upgrade Notice ==

= 1.0.0 =
First stable release.
