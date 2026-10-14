# Sprint B handover: IATO crawl tools as WordPress Abilities

Branch `feat/abilities-registration` (off `feat/atomic-read` at `af81a5a`), changes staged and uncommitted. Date: 2026-10-07.
WordPress core references are to the wp-env checkout (WordPress 7.1.3); adapter references to the MCP Adapter 0.6.1 bundled in Elementor 4.3.4 (`vendor/wordpress/mcp-adapter/includes`); the live checks ran against the WP.org MCP Adapter 0.7.0.

## What was built

Eleven read-only bridge tools are registered as `iato/*` abilities through the core Abilities API, executed by the same handler closures the MCP tools use, gated by `manage_options` (filterable), exposed to both the MCP Adapter (`meta.mcp.public`) and the `wp-abilities/v1` REST API (`meta.show_in_rest`), annotated `readonly` / `idempotent` / not `destructive` (except `iato/get-suggestions`, which is only `not destructive` because it can trigger generation), and switchable off with one setting. The IATO MCP endpoint is unchanged.

| Path | Change |
|---|---|
| `includes/class-abilities.php` | New `IATO_MCP_Abilities`: `ABILITIES` table (slug, tool, label, description), `init()`, category + ability registration, `execute()` wrapper. |
| `includes/class-mcp-server.php` | Tool catalog (`$catalog`, `set_catalog_only()`, `get_catalog_entry()`, `is_tool_registered()`, `invoke_tool()`). `register_tool()` fills the catalog first, then `$tools` exactly as before. |
| `iato-mcp.php` | Requires the class; bridge files load when a key is set (as before) or when abilities are enabled (catalog-only, nothing on `tools/list`); `IATO_MCP_Abilities::init()` on `plugins_loaded`. |
| `includes/class-settings.php` | `iato_mcp_abilities_enabled` setting: `register_setting()`, checkbox in the "IATO Platform" card, admin-ajax persistence, `sanitize_bool_flag()`. |
| `uninstall.php` | Deletes the option. |
| `CLAUDE.md` | Architecture section. |
| `tests/AbilitiesRegistrationTest.php`, `tests/stubs-abilities.php`, `tests/bootstrap.php` | Unit tests (production bridge files loaded in catalog-only mode). |
| `tests/wp-env/abilities-smoke.sh`, `tests/wp-env/abilities-settings-save.php` | Live checks (parity across three paths; admin-ajax save path). |

### Abilities, capability, annotations

| Ability | MCP tool | Capability (default, filterable) | Annotations |
|---|---|---|---|
| `iato/get-broken-links` | `get_iato_broken_links` | `manage_options` | readonly, idempotent, not destructive |
| `iato/get-orphan-pages` | `get_iato_orphan_pages` | `manage_options` | same |
| `iato/get-content-gaps` | `get_iato_content_gaps` | `manage_options` | same |
| `iato/get-nav-audit` | `get_iato_nav_audit` | `manage_options` | same |
| `iato/get-seo-fixes` | `get_iato_seo_fixes` | `manage_options` | same |
| `iato/get-suggestions` | `get_iato_suggestions` | `manage_options` | **not destructive only** (no `readonly`, no `idempotent`): the first call on a completed crawl without suggestions triggers generation on IATO once per hour and later calls can return a different list; stated in the description. Consequence: core's `wp-abilities/v1` run route takes POST `{"input": …}` for this one ability (GET for the other ten), and the adapter emits only `destructiveHint: false` for it |
| `iato/get-perf-report` | `get_iato_perf_report` | `manage_options` | same |
| `iato/get-sitemap` | `get_iato_sitemap` | `manage_options` | same; input schema additionally documents `crawl_id`, which the handler already read |
| `iato/get-taxonomy` | `get_iato_taxonomy` | `manage_options` | same |
| `iato/get-crawl-status` | `get_iato_crawl_status` | `manage_options` | same |
| `iato/list-crawls` | `list_iato_crawls` | `manage_options` | same (may cache the workspace id in `iato_mcp_workspace_id`, existing behaviour) |

All eleven default to **`manage_options`**, stricter than the `edit_posts` the IATO MCP endpoint requires of Application Password users (`includes/class-auth.php:114-120`). Sites can lower it per ability with the `iato_mcp_ability_capability` filter, `function( string $cap, string $ability, string $tool ): string` (e.g. return `'edit_posts'` for `iato/list-crawls`). The filter runs on every call, so it applies even when added after registration; a non-string or empty result falls back to `manage_options`. `start_iato_crawl` (consumes quota) and all write tools are not registered.

**Crawl scope (reported, not changed):** the ten crawl-scoped tools pass `crawl_id` straight to `/crawl/jobs/{id}/...` (`includes/class-iato-client.php`); nothing in the plugin checks that the crawl belongs to this site's domain or even to the saved workspace. Only `list_crawls` filters by `iato_mcp_workspace_id` (`class-iato-client.php:36-42`), and `list_sitemaps` accepts an optional workspace filter. Scoping is therefore whatever the IATO API enforces for the key's account: any crawl the key can read is accepted, and the per-URL `url_to_postid()` resolution simply yields `post_id: 0` for URLs on another domain. The parity runs confirm it: the wp-env site is `localhost:8888` and the crawls in the key's workspace are of a different domain, yet every crawl-scoped ability returned that crawl's data. Same behaviour as the MCP tools today.

### Setting

`iato_mcp_abilities_enabled`, default **on**, "IATO Platform" card. Stored as the string `'1'` / `'0'`, not a boolean: on a fresh install `update_option( $name, false )` is a silent no-op (WordPress treats `false` as equal to "missing"), which dropped the very first "off" save during testing. The media URL toggle has the same latent behaviour but its default is off, so it is harmless there. Off means `init()` attaches no hooks, so no `iato/` ability exists. The per-tool toggles in the Tools card are honoured as well.

### Design notes

- **One implementation.** `execute_callback` → `IATO_MCP_Abilities::execute()` → `IATO_MCP_Server::invoke_tool()`, which runs the catalogued closure and unwraps the `ok()` text envelope. `WP_Error`s pass through; array messages (IATO error envelopes) are JSON-encoded exactly as `handle_tools_call()` does, so both surfaces return identical text. Verified byte-for-byte below.
- **Not connected.** Abilities register even without an IATO key (bridge files load catalog-only). Executing one returns `iato_not_connected` (HTTP 409 over REST) with the message "This site is not connected to IATO. Add your IATO API key under Settings > IATO MCP (or run the IATO MCP setup wizard), then try again." The error comes from `execute_callback`, not `permission_callback`, because `WP_Ability::execute()` hides permission errors behind a generic message (`wp-includes/abilities-api/class-wp-ability.php:830-838`).
- **Auth.** Abilities run as the REST-authenticated WordPress user (`current_user_can`), never through `IATO_MCP_Auth::require_cap()` (which returns 403 outside an MCP request). Every IATO call uses the stored key only.
- **Guards.** `function_exists('wp_register_ability')` / `wp_register_ability_category` on every entry point; `Throwable` caught in `execute()` (core also catches, `class-wp-ability.php:595`). `output_schema` is `{type: object}` so core's output validation cannot reject a valid payload.
- **Adapter facts that shaped the smoke test.** The default server lists abilities only through `mcp-adapter-discover-abilities` / `get-ability-info` / `execute-ability`, not in `tools/list`; every call after `initialize` needs `Mcp-Session-Id` and `MCP-Protocol-Version`; `execute-ability` wraps results as `{success, data}` and errors as `{success:false, error}` and validates that `error` is a string (the array-message case above would otherwise fail with `ability_invalid_output`). Core's REST run route is GET-only for `readonly` abilities and reads input as `?input[key]=value`.

## What was verified and how

- **Unit tests:** `vendor/bin/phpunit` → 53 tests, 533 assertions, green (Sprint A's 32 plus 21 new). Covers: catalog-only mode leaves `$tools` untouched; toggle respected; `invoke_tool` unwrapping, error pass-through, array-message stringification; exactly eleven abilities with the planned names, labels, `edit_posts`, meta and annotations; descriptions ≤ 400 chars and mention the crawl; input schemas equal the MCP tool schemas (+ `default`, + `crawl_id` on sitemap); `init()` no-op when off; not-connected error; handler errors unchanged; Throwable → `iato_ability_exception`; `is_enabled()` on every stored form.
- **Discovery (wp-env, WordPress 7.1.3, MCP Adapter 0.7.0, admin Application Password):** `GET /wp-json/wp-abilities/v1/abilities?namespace=iato` lists all eleven; `mcp-adapter-discover-abilities` lists all eleven `iato/` entries; `get-ability-info` shows `annotations {readonly:true, destructive:false, idempotent:true}`, `public`, `show_in_rest`, `mcp.public` all true; sitemap schema has `crawl_id` and `sitemap_id`.
- **Parity (`tests/wp-env/abilities-smoke.sh`):** 35 of 35 cases identical across core REST run, adapter `execute-ability`, and IATO `tools/call`, including identical error text. No crawl was started. After clearance to read the key's workspace, the ten crawl-scoped abilities were rerun with a real completed crawl taken from `list-crawls` (`6c07e982`, 51 pages, `REAL_CRAWL_ID=... tests/wp-env/abilities-smoke.sh`), in addition to the no-args and bogus-id cases.

  | Ability | Compared on |
  |---|---|
  | `list-crawls` | **real data**: the workspace's job list (no args; `limit: 5`) |
  | `get-crawl-status` | **real data**: status, page count and timestamps of the real crawl; plus error paths (no args → `missing_crawl_id`; bogus id → IATO `NOT_FOUND`) |
  | `get-suggestions` | **real data**: suggestions for the real crawl; plus error paths. Note: the crawl had no suggestions yet, so the first call triggered generation on IATO (the `iato_suggestions_generated_6c07e982` transient went from unset to set) and returned the freshly generated list. This is the shared handler's documented behaviour; no crawl was started. The comparison ignores `generated_at`, which IATO stamps per response and which differed by one second between the three calls (the reason this ability is not annotated `idempotent`) |
  | `get-broken-links`, `get-content-gaps`, `get-nav-audit`, `get-perf-report`, `get-taxonomy`, `get-seo-fixes` | **empty path** on the real crawl (correct envelope with empty lists and zero counts; `get-seo-fixes` also with `severity: error, limit: 5`, `get-content-gaps` also with `min_word_count: 1000, limit: 3`); plus error paths (no args; bogus id) |
  | `get-orphan-pages` | **error path only**: real crawl → IATO `NOT_FOUND` from the orphan endpoint for this crawl (existing handler behaviour, same on all three paths); no args → `missing_crawl_id`; bogus id → `NOT_FOUND` |
  | `get-sitemap` | **error path only**: real crawl → "No sitemaps found in your IATO account." (no sitemap exists for it); no args → IATO `VALIDATION_ERROR` (no default crawl id); bogus `crawl_id` → `IATO API returned HTTP 404`; bogus `sitemap_id` → `NOT_FOUND` |
- **Permissions:** on `/run`, subscriber → HTTP 403, editor → 403, administrator → 200. Unit tests model the default role capability sets and assert Subscriber, Contributor and Editor are refused by default, Administrator admitted, the filter lowers a single ability to `edit_posts` (then Contributor and Editor pass for that one only), bad filter values fall back, and the filter is honoured when added after registration.
- **Setting off/on through the real admin-ajax handler** (`tests/wp-env/abilities-settings-save.php`, hidden `0` + checkbox `1` exactly as the form posts): from a fresh install, off → 0 abilities, on → 11, off → 0; IATO endpoint unaffected throughout.
- **Per-tool toggle:** `iato_mcp_tools` without `get_iato_seo_fixes` → 10 abilities, `iato/get-seo-fixes` returns `rest_ability_not_found`, IATO endpoint shows 11 bridge tools.
- **Not connected:** key blanked temporarily → still 11 listed, run returns `iato_not_connected` with the message above, IATO `tools/list` shows 0 bridge tools (unchanged behaviour); key restored, both surfaces back to normal.
- **No notices:** `WP_DEBUG` + `WP_DEBUG_LOG` on; the only `debug.log` lines from the plugin were the adapter's logged `ability_invalid_output` errors from the smoke run before the array-message fix, and one fatal during a few seconds between writing `iato-mcp.php` and `class-abilities.php` on the live-mounted plugin (not reproducible after).
- **WordPress 6.8.3 (below the Abilities API):** the wp-env tests environment (port 8889) was downgraded in place with `wp core update --version=6.8.3 --force` (run through `docker exec` on the tests-cli container, because wp-env's own CLI swallows `--version`). With `WP_DEBUG` + `WP_DEBUG_LOG` on and `debug.log` cleared first: plugin deactivates and activates cleanly; `function_exists('wp_register_ability')` is false and no `wp_abilities_api_init` hook is attached; the bridge tools are catalogued and (with no key on that site) absent from `tools/list`; the IATO MCP endpoint answers `initialize`, `tools/list` (46 tools) and `tools/call get_site_info`; `/wp-json/wp-abilities/v1/abilities` is `rest_no_route` 404; the Settings page renders with the abilities checkbox and the bold "not available on this WordPress version" note; `debug.log` is empty afterwards.

## What was not verified

- A site that has the MCP Adapter but a different adapter version or a third-party MCP server (e.g. a host's). Only the WP.org MCP Adapter 0.7.0 default server was exercised.
- Non-empty real-data payloads for `get-broken-links`, `get-content-gaps`, `get-nav-audit`, `get-perf-report`, `get-taxonomy` and `get-seo-fixes`: the real crawl simply has no findings in those categories, and `get-orphan-pages` / `get-sitemap` have no data for it. The empty-envelope parity covers the whole execute path; a populated payload differs only by what the shared handler returns.
- The Settings page checkbox in a browser; the admin-ajax handler was driven directly with the exact form field set.
- `wp-abilities/v1` behaviour when another plugin also registers a category named `iato` (name collision would log `_doing_it_wrong` and skip ours).

## Pre-existing issue found (not changed)

Saving the Settings form before an IATO key is set stores `iato_mcp_tools` without the twelve bridge tools (the Tools card only lists loaded tools, and the bridge files are not loaded without a key). After the key is added, all bridge tools then count as toggled off on the IATO MCP endpoint and, now, as abilities. The wp-env site was in exactly this state when testing began (`iato_mcp_tools` had 46 entries, no bridge tools); the option was cleared (empty = all on) for the rest of the checks and left cleared. Suggested fix for a later sprint: have `sanitize_tools()` / the save handler preserve unlisted bridge tools, or add the bridge tools to the migration backfill when a key is first saved.

## Open questions

1. Capability is `manage_options` by default (changed from the originally planned `edit_posts` at review); the settings hint names the filter for sites that want to lower it.
2. `iato/get-suggestions` carries no `readonly` / `idempotent` annotation (decided at review); the brief's "mark every ability readonly" is therefore applied to ten of eleven.
3. Ability names use `get-…` / `list-…` without the `iato` infix (`iato/get-broken-links`), since the namespace already carries it. The adapter's flattened tool name would be `iato-get-broken-links` if a server lists them directly.

## Local test site state

Dev site http://localhost:8888 (WP 7.1.3): Elementor 4.3.4, MCP Adapter 0.7.0, IATO key present, `iato_mcp_tools` cleared, `iato_mcp_abilities_enabled = '1'`, users `subscriber` / `editor` with Application Passwords named `sprint-b`. Tests site http://localhost:8889 downgraded in place to WordPress 6.8.3 (an `npx @wordpress/env start` resets it to latest; the deprecated `env.tests.core` key in `.wp-env.json` is ignored by current wp-env, so it was not committed).

## Paths to review / add

```
CLAUDE.md
docs/sprint-b-handover.md
iato-mcp.php
includes/class-abilities.php
includes/class-mcp-server.php
includes/class-settings.php
tests/AbilitiesRegistrationTest.php
tests/bootstrap.php
tests/stubs-abilities.php
tests/wp-env/abilities-settings-save.php
tests/wp-env/abilities-smoke.sh
uninstall.php
```
