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
  ├── class-oauth.php                 ← OAuth 2.1 server (per-client codes/tokens, PKCE, revocation)
  ├── class-oauth-store.php           ← OAuth tables: registered clients, hashed tokens
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

Authentication: WordPress Application Passwords (WP 5.6+), the plugin's own Bearer key (`iato_mcp_key`), and OAuth 2.1 with PKCE (per-client tokens issued by the plugin since 1.13.0; see "OAuth server" below). See "Token binding" below for which WordPress user each path runs as.
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
- Object-level check on every write that takes an ID, after the existence check: `IATO_MCP_Auth::require_cap( 'edit_post', $post_id )` (or `delete_post`, `edit_term`, `delete_term`); publishing needs `IATO_MCP_Auth::require_publish_cap( $post_type )`. The site key passes all of these; Application Password users are checked against the object. The per-tool inventory is in the 1.12.1 release notes, kept outside the repository.
- Never write to wp_options without `sanitize_*` on the value
- Slash what you hand to WordPress write functions: `wp_insert_post()`, `wp_update_post()`, `wp_insert_attachment()`, `update_post_meta()`, `wp_insert_term()`, `wp_update_term()` and `wp_update_nav_menu_item()` expect slashed input and run `wp_unslash()`, so an unslashed value loses every backslash (`C:\Users` becomes `C:Users`, `\"` becomes `"`). Pass `wp_slash( $value )`, arrays included. `update_option()` does not unslash. Live check: `tests/wp-env/backslash-live.sh` (installs Yoast SEO for its run when absent).
- Dry-run mode for destructive write tools: accept `dry_run: true`, return what *would* change
- All files namespaced under `IATO_MCP_` prefix for constants, `IATO_MCP` for classes
- Plugin slug: `iato-mcp`, text domain: `iato-mcp`
- Every IATO API call sends `X-IATO-Plugin-Version` and `X-IATO-Plugin-Capabilities: mcp-read` headers (set in `class-iato-client.php::default_headers()`)

---

## Schema migrations

`includes/class-schema.php` — `IATO_MCP_Schema`. Table changes and one-shot data fix-ups are keyed by an integer schema version (`iato_mcp_schema_version`, `IATO_MCP_Schema::VERSION`) that is independent of the plugin version. Why: a site that ran a pre-release build already stores that build's `iato_mcp_db_version`, so a `version_compare` gate on the final release would skip every table change made after that build (found during the 1.13.0 staging test). `run()` executes every step above the stored version in order and bumps the option after each one; a step that throws stops the run without advancing past it and is retried on the next request; deleting the option replays everything. Every step must therefore be idempotent (dbDelta, `INSERT IGNORE` / `NOT EXISTS`, option writes that tolerate repeats). Steps so far: 1 base tables (receipts, call log, media phase log), 2 OAuth client/token tables + carry-over of the option-based clients + legacy flags, 3 OAuth grants table + backfill, 4 receipt actor columns (`actor_*`, `rolled_back_by_*`, index on `actor_user_id`) and call-log `credential_kind` / `client_id`, 5 receipts `pruned_at` and index `target_applied (target_type, applied_at)` for the retention job. `run()` is called from `iato_mcp_maybe_run_migrations()` on `plugins_loaded` and from activation. The tool-list backfill (`TOOL_MIGRATION_BACKFILL`) stays keyed by the plugin version, which is only bumped afterwards.

**Adding a step** (whenever a change touches tables): append `N => [ self::class, 'step_name' ]` to `steps()` with `N = VERSION + 1`, bump `VERSION`, make the step idempotent, and add a case to `tests/wp-env/schema-migration-live.sh`. `tests/SchemaMigrationTest.php` checks ordering, no-op reruns, the stop-on-failure rule and that the step map is contiguous.

## Release Checklist (adding or removing tools)

When adding a new MCP tool, three edits must land together — missing any of them produces a tool that exists in code but is invisible on upgraded installs (the bug class that produced the v1.3.0 → v1.3.1, v1.4.0 → v1.4.5, and v1.6.0 → v1.6.1 follow-up patches):

1. Register the handler with `IATO_MCP_Server::register_tool()` in the appropriate `includes/tools/wp/tool-*.php` or `includes/tools/bridge/tool-*.php` file.
2. Append the tool name to the `TOOL_NAMES` constant in `includes/class-settings.php`, plus a matching entry in `TOOL_DESCRIPTIONS` and a category placement in `TOOL_CATEGORIES`.
3. Append the tool name to `IATO_MCP_Settings::TOOL_MIGRATION_BACKFILL` under a gate equal to the release version it ships in. `iato_mcp_maybe_run_migrations()` walks this map once on upgrade and appends any missing names to the saved `iato_mcp_tools` option, so upgraded installs see the new tool the next time they make any request.

Step 3 is the one that historically gets missed. The backfill map lives next to `TOOL_NAMES` in the same file specifically so a reviewer can eyeball the two lists side-by-side and confirm parity. Adding a new tool without a matching backfill entry is the failure mode; the loop walker in `iato-mcp.php` handles every other detail.

Removing a tool is the reverse: take it out of `TOOL_NAMES` and from any category placement; leave `TOOL_MIGRATION_BACKFILL` alone (historical entries are still correct).

---

## Release Checklist (adding a new admin setting)

When adding a new option to the Settings page, three edits must land together — missing any of them produces a setting that renders in the UI but silently discards on save (the bug class that produced the v1.7.0 → v1.7.1 follow-up patch):

1. Register it with `register_setting()` in `IATO_MCP_Settings::register_settings()` using the shared `OPTION_GROUP` constant. The sanitize_callback runs through both `options.php` and admin-ajax persistence paths.
2. Render the input in `IATO_MCP_Settings::render_page()` with a `name="..."` attribute that exactly matches the option key passed to `register_setting()`. The field must live inside the `<form method="post" action="options.php">` opened at the top of the General tab. For checkboxes, emit a hidden `<input type="hidden" value="0">` sibling with the same name so unchecked state still POSTs the key.
3. Wire the corresponding `$_POST` → sanitize → `update_option()` line into `IATO_MCP_Settings::ajax_save_settings()`. This is the step that historically gets missed: the form is hijacked through admin-ajax (some hosts 503 on options.php POSTs due to upstream WAF / timeout rules), so the `register_setting()` allowlist never gets a chance to apply. The AJAX handler hardcodes the keys it persists — anything not explicitly handled there is silently dropped.

Step 3 is the trap. The AJAX handler lives directly above `ajax_test_api_key()` in `class-settings.php`; co-locate any new field's persistence line there alongside its peer registrations so a reviewer can eyeball the two lists side-by-side.

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
| `rollback` | tools/wp/tool-rollback.php | edit_posts + the receipt's object cap; since 1.13.0 also the user who made the change or manage_options |
| `get_post_meta` | tools/wp/tool-post-meta.php | edit_posts |
| `update_post_meta` | tools/wp/tool-post-meta.php | edit_posts |
| `set_page_settings` | tools/wp/tool-page-settings.php | edit_posts |
| `set_featured_image` | tools/wp/tool-featured-image.php | edit_posts |
| `create_media` | tools/wp/tool-media-upload.php | upload_files |

`get_post` accepts an opt-in `include_shadowing: true` parameter that attaches `is_shadowed_by` when an Elementor Theme Builder template overrides the slug-based render. Default is off so the hot path stays fast.

`get_elementor_data` accepts a `format` parameter (`raw` | `compact` | `summary`). All v2 reads include a `revision` hash for use with `if_revision` guards on writes.

`update_post` accepts a `slug` parameter alongside title/content/excerpt/status. Slug input is strictly validated (lowercase a-z/0-9/hyphens, no leading/trailing/double hyphens, max 200 chars, must round-trip through `sanitize_title()` unchanged) and conflicts return `slug_conflict` rather than auto-suffixing. Changing the slug of a non-draft post additionally requires `confirm_url_break: true` since it breaks inbound links. `create_post` and `update_post` responses include a `notice` field on builder-driven sites (Elementor / Divi / WPBakery / Beaver Builder) — on Elementor it tells the agent to fetch a reference post and use the Elementor widget tools; on the others it tells the agent the layout must be finished in WP admin. The `notice` field is absent on Gutenberg-only sites.

`rollback` reverses a prior write by `change_id`. Any write tool that returns a `change_receipt` (or `change_receipts[]`) can be undone in one MCP call — Claude passes the `change_id` back to `rollback`, which validates the stored before_value, dispatches by `target_type`, and marks the receipt rolled-back so it cannot be re-applied. Wraps `IATO_MCP_Rollback::rollback_by_id` (the same dispatch path used by the REST endpoint at `wp-json/iato-mcp/v1/rollback`). Receipt `target_type` values: `post`, `page`, `image`, `menu_item`, `taxonomy`, `redirect`, `elementor_widget`, `post_meta`, `attachment`. `update_post` records one receipt per changed field (`title`, `content`, `slug`, `excerpt`, `status`); `create_post` records `target_type=post, field=create` and rolls back via `wp_trash_post`. `update_post_meta`, `set_page_settings`, and `set_featured_image` all record under `target_type=post_meta` with the meta key in `field` — rollback restores the previous value or deletes the key if `before_value` was null. `create_media` records `target_type=attachment, field=create` and rolls back via `wp_delete_attachment(force=true)` (the underlying file is removed).

**Receipt actors, the rollback rule and the conflict check (1.13.0, schema step 4).** `IATO_MCP_Change_Receipt::record()` stores who made the change: `actor_user_id`, `actor_kind` (`site_key` | `oauth` | `application_password`, or `wordpress` outside an MCP request) and `actor_client_id` (OAuth), from `IATO_MCP_Auth::credential()` via `current_actor()`; every returned receipt carries `actor` (`describe_actor()`: user id, login, kind, client id and name). `mark_rolled_back()` stores `rolled_back_by_*` the same way. Before schema step 4 has run (`has_actor_columns()`), receipts are written without these columns rather than lost. `IATO_MCP_Rollback::check_permission()` (tool and REST route) keeps the 1.12.1 object-level checks and then, for a receipt with an actor, requires the same WordPress user or `manage_options` (`require_cap()`, so the unbound key, a bound key and OAuth tokens, whose users are administrators, always pass); otherwise `rollback_actor_mismatch` (403). Receipts without an actor (recorded before 1.13.0) keep the object rules alone. `IATO_MCP_Rollback::execute()` is the shared core: already-rolled-back (names who), `preflight()` (Elementor receipts that hold only a hash, `update_term` receipts without a term id → `rollback_unavailable`), `IATO_MCP_Rollback_Conflict::check()` (`includes/class-rollback-conflict.php`: one reader per target_type/field returns the current value in the form the receipt recorded; canonical comparison treats '' as null, decodes JSON, ignores key order and scalar types; creates conflict when the object was modified after `applied_at`, deletes when the object is back; redirects of handlers whose rules the plugin cannot read, unknown fields and missing objects are `unverifiable` and proceed), refusal `rollback_conflict` (409, `conflict_check` with `current_value` / `expected_value`) unless `force: true`, `dry_run: true` (reports `would_roll_back`, `would_restore`, the check; writes nothing), dispatch, mark. The `rollback` tool returns refusals that carry details (`rollback_conflict`, `rollback_actor_mismatch`, `rollback_unavailable`, `already_rolled_back`) as an isError result whose text is JSON (`iato_mcp_rollback_error()`), because MCP clients see only the text. For the check to hold, record sites store what was actually written, read back with the reader the check uses: `update_post` / `create_post` (WordPress filters titles and content on save), `update_seo_data`, `update_canonical`, `update_alt_text`, `update_menu_item_details` (`IATO_MCP_Rollback_Conflict::menu_item_value()`); `update_term` snapshots carry `term_id`. Elementor receipts (`elementor_widget`) store the pre-write `_elementor_data` text as `before_value` (one full copy per edit; responses keep the slim receipt) and the stored revision hash as `after_value`; rollback restores the text through the `post_meta` path (`write_elementor_meta()`), reports `restored_revision` instead of the tree, and when the post had no Elementor data before `update_elementor_data` the before value is an envelope (`IATO_MCP_Rollback::no_elementor_envelope()`: a JSON object with the original `post_content` and builder flag; trees are JSON arrays) whose rollback removes `_elementor_data`, restores the flag and writes the original content back verbatim (`restore_post_content()`: `wp_slash()`, kses lock lifted and core's kses content filters removed for that write, both restored in `finally`). Taxonomy `assign` / `terms` rollbacks take the taxonomy from any term in the receipt (`taxonomy_of_ids()`), after IDs included. The REST route accepts `force` and `dry_run` too and still requires the caller's `before_value` to equal the stored one (for Elementor receipts that is the whole tree). Tests: `tests/RollbackActorConflictTest.php` (stubs in `tests/stubs-actor.php`); live: `tests/wp-env/rollback-actor-live.sh` → `.py` (mints an OAuth access token straight into the tables; `--cleanup`) and `tests/wp-env/rollback-plugins-live.sh` → `.py` (installs Yoast SEO, Safe Redirect Manager and Redirection for the run; `--cleanup` uninstalls them). Known limits: a created redirect cannot be rolled back on any handler, and with Redirection no redirect receipt has a before value (the plugin cannot read Redirection's rules), so none can be rolled back.

**Receipt retention (1.13.0, schema step 5).** `includes/class-receipt-retention.php` (`IATO_MCP_Receipt_Retention`): Elementor receipts hold a full copy of the page, so a daily WP-Cron event (`iato_mcp_prune_receipts`, scheduled on `init` when missing, cleared on deactivation and in `uninstall.php`) sets `before_value = NULL` and `pruned_at` on `elementor_widget` receipts older than the retention period, in `UPDATE … LIMIT 200` batches within a 10-second budget. The receipt row stays as an audit record. Period: filter `iato_mcp_receipt_retention_days` (default 90; 0 or less keeps every copy). WP-Cron only runs on visits and can be disabled, so `IATO_MCP_Change_Receipt::record()` calls `maybe_run_overdue()`: when the last run (option `iato_mcp_receipt_prune_last`) is more than two days old, one batch runs on `shutdown` of that request. `IATO_MCP_Rollback::preflight()` refuses a receipt with `pruned_at` (`rollback_unavailable`, pointing at Elementor's revisions) before anything else, so an emptied before value is never read as "the post had no Elementor data". Diagnostics shows the period, the last run and the next one. Tests: `tests/ReceiptRetentionTest.php` (cron stubs in `tests/stubs-cron.php`); live: `tests/wp-env/receipt-retention-live.sh` (test-only mu-plugin `tests/wp-env/mu-receipt-retention-test.php` sets the filter from an option).

The `initialize` response advertises `capabilities.elementor.v2: true` when Elementor is active, plus `capabilities.rollback: true` always — clients can feature-detect without a `tools/list` round-trip.

`create_media` accepts `defer_subsizes: true` (default false) which schedules `wp_generate_attachment_metadata` via WP-Cron instead of running it inline. The response returns immediately with the attachment ID and canonical URL; intermediate sizes appear on the next cron tick. Recommended whenever the call goes through a managed MCP gateway with a request timeout (Anthropic's gateway times out around 30s) — sites with image-optimisation plugins routinely push synchronous metadata generation past that limit. Per-phase timing logs are emitted to PHP's error log under the prefix `[iato-mcp create_media:<req_id>]` for live diagnosis when a call hangs or fails.

`update_elementor_data` goes through the shared write pipeline (see "Elementor write pipeline and sanitising"), records an `elementor_widget` receipt (`elementor:document:replace`) and accepts `inherit_settings_from: <post_id>` (and optional `inherit_keys: string[]`, limited to allowlisted `_elementor_` keys) to clone a curated set of theme/builder per-post overrides from a source post in the same call. The default key list spans Astra (`site-post-title`, `site-sidebar-layout`, `site-content-layout`, `site-content-style`, `site-sidebar-style`, `ast-main-header-display`, `ast-global-header-display`, `ast-banner-title-visibility`, `ast-breadcrumbs-content`, `ast-featured-img`, `footer-sml-layout`), WordPress (`_wp_page_template`), and Elementor (`_elementor_page_settings`, `_elementor_template_type`). Empty source values are copied through (Astra stores meaningful state as empty strings). Keys that already match between source and target are reported in `inherited_skipped[]` with `reason: 'noop'`.

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

## Token binding (who an MCP request runs as)

`includes/class-auth.php` — `IATO_MCP_Auth::authenticate()` is the `permission_callback` for `/iato-mcp/v1/message` and `/iato-mcp/v1/rollback`, and the single place the current user is set for Bearer requests.

| Credential | Runs as | `require_cap()` |
|---|---|---|
| Bearer key bound to an owner (`iato_mcp_key_owner` > 0) | the owner, via `wp_set_current_user()` after re-checking on every request that the user exists and holds `manage_options` (else 403 `iato_mcp_owner_missing` / `iato_mcp_owner_demoted`, logged once per request in the call log with `rpc_method: auth`) | `user_can( $owner, $cap )` — real |
| Bearer key with no owner (issued before binding existed) | user 0, exactly as before binding | `true` for any cap; `require_bound_user()` returns `iato_mcp_token_unbound` for features that need a user |
| OAuth access token (`Bearer iato_at_…`, 1.13.0) | the administrator who approved the connection, via `wp_set_current_user()` after the same exists + `manage_options` re-check (else 401 `iato_mcp_unauthorized`; a missing or demoted user also revokes the grant and is logged with `error_code: iato_mcp_token_user_missing` / `_demoted`). Expired and revoked tokens are 401 with `error="invalid_token"` in `WWW-Authenticate`. OAuth connections made before 1.13.0 hold the site key and arrive on the Bearer-key rows above. | `user_can( $user, $cap )` — real |
| Application Password (Basic) | the real user, set by WP core | `user_can( $user, $cap )` |

- **Owner record.** `iato_mcp_key_owner` is written only through `IATO_MCP_Auth::bind_key_owner()`: `maybe_generate_key()` (activation; WP-CLI activation has no user, so the key stays unbound), `rotate_key()` (Regenerate binds to the regenerating admin), and the Settings "Token owner" control (`admin_post_iato_mcp_assign_key_owner`, admins only). OAuth consent bound an unbound key to the approving admin in 1.12.x; since 1.13.0 consent issues the client its own token and never touches the key. The token format, storage and validation are unchanged.
- **kses stays on for every MCP request (1.13.0).** `authenticate()` attaches a `map_meta_cap` filter that returns `do_not_allow` for `unfiltered_html` and `edit_css` for the rest of the request (before `wp_set_current_user()` for the bound key and OAuth tokens; followed by `kses_init()` on the Application Password path, where core had already decided its filters), so WordPress' content filters stay active and Elementor's `Document::save()` applies `kses_post_deep`. Application Password users keep `unfiltered_html` only when the `iato_mcp_allow_unfiltered_html` filter (`$allow, $user_id, $kind`) returns true and they hold the capability; the decision is taken once in `authenticate()` and read through `IATO_MCP_Auth::unfiltered_html_allowed()`. Create/update_post additionally run `wp_kses_post` themselves. The lock is lifted (`IATO_MCP_Auth::write_unlocked()`, restored in `finally`) around Elementor's `Document::save()` and around the plugin's own `_elementor_` meta writes, so Elementor's blanket `kses_post_deep` and its REST `sanitize_callback` on `_elementor_data` do not strip values the request did not change; the plugin's diff pass has already sanitised the changed leaves. The lift only removes the plugin's filter: a user who does not hold `unfiltered_html` (an Author's Application Password) still lacks it inside the save. For an unbound legacy key (user 0) the lift has nothing to show through, so the plugin's own `_elementor_` meta writes additionally run inside `IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing()` (from 1.12.2): it removes every callback on the `sanitize_post_meta__elementor_data` / `_elementor_page_settings` hooks (and their `_for_<post_type>` variants) for the write and restores them in `finally`, logging each one under `WP_DEBUG`. `IATO_MCP_Elementor_Adapter::write_elementor_meta()` combines the two and is what the pipeline's fallback write, rollback, `update_post_meta`, `set_page_settings` and inherit use for Elementor keys.
- **What changes with a bound owner.** New posts, media, menu items and revisions get the owner as author (was 0). Elementor's `Document::save()` now runs for Bearer requests (it refused user 0), so `post_content` is Elementor's rendered HTML rather than the frontend fallback, and `_elementor_data` is re-encoded by Elementor; the write pipeline reports `elementor_save: true|false|'error'` (guarded with `Throwable`) and checks persistence structurally (`same_element_tree()`) instead of by byte length. The owner's own edit lock no longer blocks writes. `tax_input` on insert is honoured. Bound Bearer behaviour equals today's Application Password admin behaviour except for the kses lock.
- **Legacy keys.** Nothing binds automatically on upgrade. An unbound key shows an admin notice (`key_owner_notice`, `manage_options`, dismissible per user except on the plugin's Settings page) and an "Unassigned (legacy mode)" badge in the Token owner row; Diagnostics shows the owner state.
- **Multisite** is not declared. An owner who is not a member of the site has no capabilities there and is rejected as demoted.
- Tests: `tests/AuthBindingTest.php` (stubs in `tests/stubs-auth.php`); live: `tests/wp-env/tools-snapshot.{sh,py}` (before/after behaviour snapshot over Bearer and App Password), `tests/wp-env/mu-manage-elements-probe.php` (test-only mu-plugin calling `elementor/manage-elements` inside a Bearer request).
- `IATO_MCP_Auth::credential()` reports the credential kind (`site_key` | `oauth` | `application_password`), the user id and, for OAuth, `client_id` / `grant_id`, for receipts and logs.

## OAuth server

`includes/class-oauth.php` (`IATO_MCP_OAuth`) + `includes/class-oauth-store.php` (`IATO_MCP_OAuth_Store`). Follows the MCP authorization specification revision 2026-07-28 (OAuth 2.1, RFC 8414 / 9728 metadata, RFC 8707 `resource`, RFC 9207 `iss`, RFC 7591 registration, RFC 7009 revocation). Routes are matched on `init` priority 1 from `REQUEST_URI` (so they work on any permalink setting): `/.well-known/oauth-protected-resource[/…]`, `/.well-known/oauth-authorization-server`, `/oauth/register`, `/oauth/authorize`, `/oauth/token`, `/oauth/revoke`.

- **Credentials.** Code: 43-char random, single use, 5 min, bound to client, redirect URI, PKCE challenge and approving user. Access token `iato_at_…`, 1 h. Refresh token `iato_rt_…`, 30-day sliding, rotated on every refresh. Absolute grant lifetime 180 days from consent (`iato_mcp_oauth_grant_lifetime` filter, 0 disables), after which refresh returns `invalid_grant`. Everything is stored as SHA-256 in `{prefix}iato_mcp_oauth_tokens`; one row per authorization in `{prefix}iato_mcp_oauth_grants` (`grant_id`, client, user, redirect URI, `granted_at`, `revoked_at`, `revoked_reason`). The site key is never issued or accepted by any OAuth endpoint.
- **Grant row is the authority.** Every revocation path (Revoke, Disconnect all, `/oauth/revoke`, code replay, refresh reuse, approver missing/demoted/deleted, the 180-day limit) sets `grants.revoked_at` (+ reason) through `IATO_MCP_OAuth_Store::revoke_grant()` / `revoke_all_grants()` / `revoke_user_grants()`; `find_token()` joins the grant, and the token endpoint, the refresh path and `authenticate()` refuse any token whose grant is revoked, whenever the token row was created. Codes and refresh tokens are claimed atomically: `mark_used()` is one `UPDATE … WHERE used_at IS NULL` and returns the affected rows; 0 means another request consumed it first (replay / reuse → the grant is revoked, `invalid_grant`). A code or refresh token is spent on first presentation, success or failure. `backfill_grants()` (schema step 3) derives grant rows for sites that ran the first 1.13.0 build.
- **Registration** (`{prefix}iato_mcp_oauth_clients`): at most 10 redirect URIs, each `https`, or `http` to `localhost` / `127.0.0.1` / `[::1]` (matched port-agnostically at authorize time for Claude Code's ephemeral port; same host string, same path and query); `client_name` stored (≤100 chars); public clients only; a failed insert is a 500 `server_error`; limits 10 per IP per 10 min and 100 per site per hour via transients (`iato_mcp_oauth_registration_limits` filter; `REMOTE_ADDR` as the call log uses it, so behind a proxy the IP bucket is effectively site-wide); clients never authorized within 24 h, or authorized but without a live grant for 30 days, are pruned lazily on registration.
- **Authorize.** Not logged in → wp-login and back (`login_return_url()`: site origin + `REQUEST_URI`, so subdirectory installs are not doubled); logged in without `manage_options` → a 403 page (`wp_die`), not a login loop. Unknown client or unregistered redirect URI → JSON 400 (never a redirect); afterwards protocol errors (`unsupported_response_type`, missing/non-S256 PKCE including a missing `code_challenge_method`, bad `resource`) go back to the client by redirect with `error`, `state`, `iss`. The consent form's nonce action is `consent_nonce_action()` (hash of client_id, redirect_uri, code_challenge), so a nonce rendered for one request cannot approve another. `resource` is normalised (scheme/host case, default port, trailing slash) and must equal `rest_url('iato-mcp/v1/message')`; an `https` resource is accepted for an `http` canonical URL (TLS-terminating proxy) and logged as `oauth` / `resource_scheme_upgrade`, never the reverse. Consent shows client name, redirect host (loopback warning), registration time and the approver; Deny is a POST that redirects with `access_denied`. Approval writes the code row, marks the client authorized and logs `oauth` / `grant`. Consent does not touch the site key.
- **Token endpoint** accepts form-encoded and JSON. `authorization_code` → PKCE via `verify_pkce()` against the code row, `resource`, approver re-check, then an access + refresh pair. `refresh_token` → client match, rotation/reuse detection, expiry, absolute lifetime, approver re-check. Refusals are `invalid_grant` (what Claude needs to re-authorize) or `invalid_target`.
- **Revocation.** `POST /oauth/revoke` (`token`, optional `client_id`; either token of a grant revokes the grant; always 200). Settings card "Connected OAuth clients" (`render_oauth_clients_card()`, after the settings `</form>` so its per-row POST forms do not nest; `admin_post_iato_mcp_oauth_revoke` with a per-grant nonce; "Disconnect all" posts `admin_post_iato_mcp_oauth_revoke_all`, revokes every live row including pending codes and reports the number of connections). `deleted_user` revokes that user's grants. Regenerate leaves OAuth grants alone. Every revocation is logged (`oauth` / `revoke`, `request_args.reason`: endpoint, admin, admin_all, code_replay, refresh_reuse, grant_lifetime, user_missing, user_demoted, user_deleted). Routine refreshes are not logged.
- **Migration (1.13.0).** Schema steps 2 and 3 (`IATO_MCP_Schema`, see "Schema migrations"): tables created, clients from the retired `iato_mcp_oauth_clients` option copied over as authorized, the `iato_mcp_oauth_pkce` transient deleted, grant rows backfilled. Sites that had OAuth clients get two flags: `iato_mcp_oauth_legacy_notice` → `IATO_MCP_Settings::oauth_legacy_notice()` (site-wide dismiss hides the notice only) and `iato_mcp_oauth_legacy_key` ("pre-1.13.0 connections may still hold the key"), which drives the Settings card hint and the Diagnostics badge and is cleared only by `IATO_MCP_Auth::rotate_key()` (Regenerate clears both). Pre-1.13.0 connections hold the site key and keep working through the Bearer path; reconnecting the client moves it to its own token; regenerating the key cuts them all.
- **Not built:** Client ID Metadata Documents (`client_id_metadata_document_supported` is deliberately absent, so Claude uses dynamic registration). Subdirectory installs: the spec's path-aware well-known URLs sit at the host root and do not reach WordPress; the `WWW-Authenticate` `resource_metadata` pointer is the working discovery path there.
- Tests: `tests/OAuthFlowTest.php`, `tests/AuthOAuthTokenTest.php`, `tests/OAuthPkceTest.php` (in-memory store and WP stubs in `tests/stubs-oauth.php`); live: `tests/wp-env/oauth-live.sh` → `oauth-live.py` (metadata, registration rules and limits, consent incl. the 403 page and nonce binding, codes, tokens, grant-level revocation, 20 parallel refreshes, refresh, expiry by backdating rows, revocation through the endpoint and the Settings forms, demoted/deleted approver, loopback, pruning, legacy-key flag and Regenerate, site-key sweep).

## WordPress Abilities Bridge

`includes/class-abilities.php` — `IATO_MCP_Abilities`. Registers the eleven read-only bridge tools as `iato/*` abilities through the core Abilities API (WordPress 6.9+) so any MCP server built on the WordPress MCP Adapter, and the `wp-abilities/v1` REST API, can discover and run them. `start_iato_crawl` and every write tool are deliberately not registered.

- **One implementation.** `IATO_MCP_Server` keeps a `$catalog` of every tool passed to `register_tool()` (definition + handler), separate from `$tools` (what `tools/list` exposes). Each ability's `execute_callback` calls `IATO_MCP_Server::invoke_tool()`, which runs the same closure the MCP tool uses and unwraps the `ok()` text envelope. `WP_Error`s pass through with array messages JSON-encoded, exactly as `handle_tools_call()` does, so both surfaces return identical text.
- **Catalog-only loading.** `iato-mcp.php` loads the bridge tool files when an IATO key is set (as before) *or* when abilities exposure is on; in the second case `IATO_MCP_Server::set_catalog_only(true)` wraps the requires so nothing is added to `tools/list`. An unconnected site therefore still registers the abilities, and executing one returns `iato_not_connected` with instructions.
- **Names, labels, descriptions** live in `IATO_MCP_Abilities::ABILITIES` (slug → tool, label, description). Descriptions are written for discovery (≤ 400 chars, crawl source + question answered + when to use). Input schemas are derived from the tool's `inputSchema` (`type: object`, `default: []`; `get-sitemap` additionally documents `crawl_id`, which its handler already reads). `output_schema` is `{type: object}`.
- **Permissions.** `permission_callback` is `current_user_can( IATO_MCP_Abilities::capability_for( $slug ) )`: `manage_options` for all eleven by default, lowerable per ability with the `iato_mcp_ability_capability` filter (`function( string $cap, string $ability, string $tool ): string`). The filter is evaluated on every call, not captured at registration. Abilities run as the REST-authenticated WordPress user; `IATO_MCP_Auth::require_cap()` is not involved. Every IATO call uses the stored API key only.
- **Meta.** `public`, `show_in_rest`, `mcp.public` all `true`; annotations `readonly: true, destructive: false, idempotent: true` (`DEFAULT_ANNOTATIONS`), except `get-suggestions`, which is only `destructive: false` because it can trigger suggestion generation on IATO (a per-entry `annotations` override in the table). Non-readonly abilities run over POST on core's `wp-abilities/v1` route. The MCP Adapter decides exposure from `meta.mcp.public`; core REST from `meta.show_in_rest`.
- **Setting.** `iato_mcp_abilities_enabled` (boolean, default on, "IATO Platform" card, admin-ajax persisted per the settings checklist). Off → no hooks are attached and no `iato/` abilities exist. The per-tool toggles in the Tools card are honoured too: a tool switched off disappears from both surfaces.
- **Hooks.** `IATO_MCP_Abilities::init()` runs on `plugins_loaded` and attaches `wp_abilities_api_categories_init` / `wp_abilities_api_init` (both fire lazily on first registry access after `init`). Every entry point is guarded with `function_exists('wp_register_ability')`, so WordPress below 6.9 is a silent no-op.
- **Per-tool selection and the bridge tools.** While no IATO key is set, the Tools card renders the twelve bridge tools as disabled checkboxes, and browsers never submit disabled inputs. `includes/tools-selection.php` (pure functions) keeps that from switching them off: the card also emits a hidden `iato_mcp_tools[]` input for each gated tool that is currently on, `ajax_save_settings()` merges the posted list with the previous state for gated tools (`iato_mcp_merge_gated_tools()`, using the key as it was before the save), `sanitize_tools()` does the same on the options.php path, and `iato_mcp_maybe_run_migrations()` runs a one-shot repair (`iato_mcp_repair_bridge_tools()`: a non-empty list with none of the twelve gets all twelve; flag option `iato_mcp_bridge_tools_repaired`). With a key present the checkboxes are live and an unchecked bridge tool is a deliberate choice that is honoured, including across later gated saves. Tests: `tests/SettingsToolsTest.php`, `tests/wp-env/tools-settings-save.php`.

Tests: `tests/AbilitiesRegistrationTest.php` (loads the production bridge files in catalog-only mode; stubs in `tests/stubs-abilities.php`), `tests/wp-env/abilities-smoke.sh` (three-way parity: core REST run, adapter `execute-ability`, IATO `tools/call`), `tests/wp-env/abilities-settings-save.php` (admin-ajax save path).

---

## Elementor write pipeline and sanitising

`IATO_MCP_Elementor_Adapter::write_pipeline( $post_id, $elements, $previous_revision, $stored_elements = null )` is the single write path for `update_elementor_widget`, `update_elementor_patch`, `update_elementor_widgets_bulk`, `set_heading_level`, `set_widget_setting` and (since 1.13.0) `update_elementor_data`. Order: (0) unless `IATO_MCP_Auth::unfiltered_html_allowed()`, `IATO_MCP_Elementor_Sanitizer::diff_tree()` compares the new tree with the stored one element by element (matched by id) and sanitises only leaves that differ; (1) cache clears; (2) Elementor's `Document::save()` with the (sanitised) tree, inside `write_unlocked()` and guarded with `Throwable`; (3) only when that returned anything but `true` (refused = user may not edit, e.g. unbound key as user 0; threw = addon controls; Elementor absent) the plugin writes `_elementor_data` itself, so an unsanitised tree is never on disk; (4-6) frontend fallback render, structural persistence check (`same_element_tree()`, ids only), revision. The result carries `elementor_save` (`true|false|'error'|null`), `stored_by` (`elementor|plugin`), and `sanitized: true` + `sanitized_paths` (`<element id>/settings/<path>`, capped at 50) only when a value was altered; `pipeline_report()` copies those onto tool responses and `sanitize_preview()` gives dry runs the same keys.

`includes/class-elementor-sanitizer.php` sanitises by value shape, not widget name, so unknown and addon widgets follow the same rules: URL fields (`url`, `destination`, `href`, `*_url`, and atomic `{$$type: url}` envelopes) → `esc_url_raw` (Elementor's `Url_Prop_Type::sanitize_value()`); `custom_css` (classic, page settings; atomic `styles[].variants[].custom_css.raw`, base64) → `sanitize_textarea_field` (Elementor's `Style_Parser::sanitize_custom_css()`); strings containing `<` → `wp_kses_post` (what Elementor's `Document::save()` applies to every string for users without `unfiltered_html`); everything else verbatim (kses would turn `&` into `&amp;`, double-encoded by widgets that escape on output). Structure keys are never touched. The three functions are injectable (`set_functions()`) for unit tests; `_elementor_page_settings` writes go through `diff_settings()` via `IATO_MCP_Meta_Policy::sanitize_elementor_value()`.

Because the lock is lifted around `Document::save()`, Elementor's own `kses_post_deep` (whole document) does not run for bound-key and OAuth requests; the diff pass is the sanitiser for classic widgets on both paths. Elementor's per-prop atomic sanitising (URL, HTML wrapper tags, `custom_css`) still runs inside its props parser regardless of capability, and its content-sanitizer module still applies to non-`manage_options` users.

**Meta keys.** `IATO_MCP_Meta_Policy::ELEMENTOR_WRITABLE` (`_elementor_page_settings`, `_elementor_edit_mode`, `_elementor_template_type`) are the only `_elementor_` keys `update_post_meta` and `inherit_keys` may write; every other key under that prefix (element tree, element cache, CSS, SVG, caches, conditions, anything an addon adds) is refused with `elementor_content_key`, which names the Elementor tool to use. `set_page_settings` is unaffected. Rollback restores receipts verbatim (the before value is read from the receipts table, never from the request), so old `post_meta` receipts for now-refused keys still roll back. `decode_data()` accepts a tree stored as an array (pre-1.13.0 `update_post_meta` writes).

Tests: `tests/ElementorSanitizerTest.php`, `tests/ElementorWritePipelineTest.php`, `tests/MetaContentKeysTest.php` (stubs in `tests/stubs-meta.php`); live: `tests/wp-env/elementor-sanitize-live.sh` → `.py` with the test-only mu-plugin `tests/wp-env/mu-force-elementor-save-fail.php` (forces `Document::save()` to throw; lets the unfiltered filter return true) and the fixture seeder `tests/wp-env/create-sanitize-fixture.php`.

## Elementor Atomic (Editor V4) Reader

`includes/class-elementor-atomic.php` — `IATO_MCP_Elementor_Atomic`. Pure-PHP normalisation layer for Elementor 4.x atomic elements stored in `_elementor_data` (`e-heading`, `e-paragraph`, `e-image`, `e-button`, `e-flexbox`, `e-div-block`, …). It never calls Elementor code, so reads work on Elementor 3.x, 4.0–4.2, with the Elementor MCP module off, and with Elementor deactivated.

- **Detection**: a node is atomic iff `elType` or `widgetType` starts with `e-`, or any top-level setting is a typed envelope. The stored `version` key is not a signal (Elementor writes `"0.0"`, never `4`).
- **Envelopes**: settings are `{"$$type": key, "value": payload, "disabled"?: true}`. `unwrap()` flattens them (text family `string|html|escaped-html|html-v2|html-v3` → string, `url` → string, `query` → `{post_id,label}`, `attributes` → map, `dynamic` → `{"$dynamic": …}`, unknown types recurse). `plain_settings()` also fills unsaved schema defaults from `includes/data/elementor-atomic-defaults.php` and reports them in `defaulted_keys`.
- **Normalised view** (`normalize()`): `schema: "atomic"`, rendered `tag`, and the same peek keys classic widgets emit (`title`, `header_size`, `editor`, `text`, `link`) plus `link_new_tab`, `link_post_id`, `image_url`, `image_id`, `image_alt`, `image_alt_source` (`attachment` for Media Library images, `element` for URL images).
- **Wiring**: `IATO_MCP_Elementor_Adapter::peek_fields()` delegates to it; every node from `flatten_widgets` / `summary` / `find_by_filter` carries `schema: classic|atomic`; `matches_filter()` evaluates atomic nodes against `match_settings()` (plain values + derived `header_size`, `editor`, `link_url`, `image_*`). `get_elementor_widget` adds `settings_plain`, `defaulted_keys`, `tag`, `peek` for atomic nodes while keeping `settings` raw. `get_page_builder` adds `elementor_schema: classic|atomic|mixed|empty`. `initialize` advertises `capabilities.elementor.atomic_read`.
- **WP calls** (attachment alt/URL, permalink) go through an injectable resolver (`set_resolver()`), which is how the unit tests run without WordPress.
- **Writes to atomic elements are not supported yet** (planned: delegate to Elementor's `manage-elements` ability, which requires a bound token — see "Token binding").

### Tests

`composer install && vendor/bin/phpunit`. Standalone PHPUnit with WP stubs in `tests/bootstrap.php`; fixtures in `tests/fixtures/elementor/` (`classic-only`, `atomic-only`, `mixed` captured from a wp-env site running Elementor 4.3.4 through `tests/wp-env/create-fixture-pages.php`; `legacy-and-edge-cases` hand-written; `*.expected.json` snapshots regenerated with `tests/tools/dump-read-output.php`). `classic-only.pre-sprint-a.expected.json` is the pre-atomic golden output and must keep matching, minus the `schema` key. `.wp-env.json` + `tests/wp-env/read-tools-smoke.sh` drive the live site. None of this ships (see `.distignore`).

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
- Media uploads (v1.6.0): `iato_mcp_media_url_source_enabled` (`'1'`/`'0'` string flag, default off; stored as a string for the same reason as `iato_mcp_abilities_enabled`), `iato_mcp_media_url_host_allowlist` (hostnames, one per line), `iato_mcp_media_max_upload_size` (bytes, default 10MB), `iato_mcp_media_upload_rate_limit` (per-user per-minute, default 20)

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
- Receipt cleanup: retention period, last run, next run (1.13.0)
- IATO API key validation state
- Last 50 MCP JSON-RPC requests from the call log (method, tool, who: user · credential kind · OAuth client (1.13.0 `credential_kind` / `client_id` columns, filled from `IATO_MCP_Auth::credential()` by `IATO_MCP_Call_Log::record()`), status, duration, errors)
- "Clear log" button

No IATO-side probes; the page does not make blocking HTTP calls on render.

---

## File naming

- Classes: `class-{name}.php` in `includes/`
- Tools: `tool-{name}.php` in `includes/tools/wp/` or `includes/tools/bridge/`
- Main plugin file: `iato-mcp.php`
- All class files loaded via `require_once` in the main plugin file, not an autoloader
