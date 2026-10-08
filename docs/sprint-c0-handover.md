# Sprint C0 handover: token binding (Part 1) and the "bridge tools saved as off" fix (Part 2)

Date: 2026-10-07. Two branches, nothing committed by me:
- **Part 1** staged on `feat/token-user-binding` (off `feat/abilities-registration` at `7bc4592`).
- **Part 2** staged on `feat/settings-tools-fix`, a separate git worktree at `../mcp-wordpress-part2` based on the Sprint B commit (a branch cannot be taken off an uncommitted tree while that tree stays staged elsewhere). Its handover is `docs/sprint-c0-part2-handover.md` on that branch. Part 2's patch applies cleanly on top of Part 1 (verified: applied for the live checks, 78 tests green, reverted).

WordPress core references are to the wp-env checkout (WordPress 7.1.3); Elementor references to Elementor 4.3.4.

---

# Part 1 — Bind Bearer tokens to a WordPress user

## Audit: how a request authenticates and who it runs as

| Path | Credential | Before C0 | After C0 |
|---|---|---|---|
| Plugin Bearer key | `Authorization: Bearer <iato_mcp_key>`; `hash_equals` in `IATO_MCP_Auth::authenticate()` | user 0; `require_cap()` returned `true` for any cap | runs as the key owner if bound (`manage_options` re-checked every request); unbound keys unchanged (user 0) |
| OAuth (Claude connectors etc.) | access token = the same site key (`class-oauth.php` token endpoint) | user 0 | same as Bearer; consent screen now binds an unbound key to the approving admin and always says who the connection acts as |
| Application Password / Basic | validated by WP core on `determine_current_user`; `authenticate()` requires `edit_posts` | the real user | unchanged |
| Abilities API (`wp-abilities/v1`, MCP Adapter; Sprint B) | whatever core accepts | the real user | unchanged (not through `authenticate()`) |
| stdio / `mcp-remote` | no server-side transport; generated snippets use Bearer (admin notice) or Basic (wizard) | as above | as above |

Routes using `authenticate()`: `POST|GET /iato-mcp/v1/message` and `POST /iato-mcp/v1/rollback`. Cookie + nonce cannot reach them. Rejections in `permission_callback` were never logged; owner rejections now are.

**Token lifecycle (unchanged by C0 except for the owner):** one site-wide key, created on activation (`maybe_generate_key`), stored in `iato_mcp_key`, validated with `hash_equals`, displayed on the Settings "MCP Connection" card and in the setup admin notice, rotated by the Regenerate link (`admin_post_iato_mcp_regenerate_key`, `manage_options` + nonce). OAuth: `oauth/register` is open; `oauth/authorize` requires `manage_options` + nonce (pre-existing, kept); the authorization code and the access token are both the site key, never expire, no refresh token, one global PKCE transient, PKCE skipped when `code_verifier` is empty. None of that changed; it is recorded for a future token-format sprint.

**Write-tool authorisation before C0:** every write tool calls `IATO_MCP_Auth::require_cap(<cap>)`, but under Bearer the check short-circuited to `true`, so it was cosmetic. No per-object caps anywhere. Workarounds for user 0 in `tool-media-upload.php:48-66`, `tool-elementor-templates.php:42-45`, `tool-elementor-bulk.php:98-102, 235-238` were left in place (they also work with a real user). **Gap found, not changed:** the `/iato-mcp/v1/rollback` REST route checks no capability at all (`class-rollback.php:40-107`); an `edit_posts` Application Password user can roll back `redirect`/`menu_item`/`create_term` receipts the MCP tool would refuse. Change receipts record no actor.

## What was built

| Path | Change |
|---|---|
| `includes/class-auth.php` | `OWNER_OPTION` (`iato_mcp_key_owner`), `OWNER_CAP` (`manage_options`), `key_owner_id()`, `bind_key_owner()` (the single write path; every actual owner change is recorded in the call log as `rpc_method: owner` with `{from, to, by}`), `is_unbound()`, `require_bound_user()`, `filter_map_meta_cap()`; `maybe_generate_key()`/`rotate_key()` bind to the current user; Bearer branch of `authenticate()` resolves the owner, rejects missing/demoted owners (logged once per request as `rpc_method: auth`, `response_status: auth_error`), locks `unfiltered_html`, `wp_set_current_user()`. Header docblock rewritten. |
| `includes/class-oauth.php` | `actor_context()`; consent screen "This connection will act as" block for unbound / self / other owner / missing owner; approve binds only an unbound key. |
| `includes/class-settings.php` | "Token owner" row in the MCP Connection card (badge, admin `<select>`, Assign owner); `handle_assign_key_owner`; `key_owner_notice` + `dismiss_owner_notice`; Regenerate message says it assigns the key to you. |
| `includes/class-diagnostics.php` | "MCP key owner" row. |
| `includes/class-elementor-adapter.php`, `includes/tools/wp/tool-page-builder.php` | `Document::save()` wrapped in `try/catch (Throwable)`, its result reported as `elementor_save` (`true` / `false` / `'error'` + `elementor_save_error`); `meta_persisted` computed by `same_element_tree()` (element-id tree equality) instead of byte length. |
| `iato-mcp.php`, `uninstall.php` | activation comment; option deleted on uninstall. |
| `CLAUDE.md` | "Token binding" section. |
| `tests/AuthBindingTest.php`, `tests/stubs-auth.php`, `tests/bootstrap.php` | 15 unit tests (owner recorded and logged, binding, rejections, legacy, kses lock, App Password path). |
| `tests/ElementorWritePipelineTest.php` | 4 tests with a fake `\Elementor\Plugin`: `Document::save()` throwing, refusing, succeeding (re-encoded meta), and the structural persistence check. |
| `tests/wp-env/tools-snapshot.{sh,py}`, `tests/wp-env/mu-manage-elements-probe.php`, `tests/wp-env/oauth-consent-check.sh` | live checks (below). |

### Required owner capability: `manage_options`
The key is documented and displayed as full administrative access; every tool capability in the plugin is one an administrator holds; every surface that issues or shows the key is `manage_options`-gated. Binding to a lower-capability user would silently switch tools off instead of reflecting a choice, so demotion below administrator revokes the key (403 `iato_mcp_owner_demoted`) until it is reassigned or regenerated. An owner who no longer exists gives 403 `iato_mcp_owner_missing`.

### Existing tokens (migration, as agreed)
Nothing binds automatically on upgrade. A key without an owner keeps today's behaviour exactly (user 0, `require_cap()` passes everything, kses on). Administrators see a dismissible notice ("the site's MCP key is not assigned to a WordPress user…") everywhere, and always on the plugin's Settings page, whose Token owner row shows "Unassigned (legacy mode)". Features that need a user call `IATO_MCP_Auth::require_bound_user()` and get 403 `iato_mcp_token_unbound` ("Assign an owner or regenerate the key under Settings > IATO MCP, then retry."). Fresh activations from wp-admin bind to the activating administrator; WP-CLI activation leaves the key unbound. Rejected alternatives: auto-binding to an arbitrary administrator (silent, misattributes AI writes), forced regeneration (breaks the IATO platform's stored key and every pasted config).

### Behaviour changes, confirmed against core and the plugin

| Area | Before (user 0) | After (bound admin) | Handling |
|---|---|---|---|
| kses | core filters active (`kses_init`, user 0 lacks `unfiltered_html`); every `wp_update_post` re-filters the whole row | `wp_set_current_user` re-runs `kses_init`, which would remove the filters for an admin | `map_meta_cap` lock denies `unfiltered_html`/`edit_css` for Bearer/OAuth requests before the user switch. Verified: a `<script>` in an Elementor patch is stripped under bound Bearer (as under user 0) and kept under an Application Password admin, before and after. `create_post`/`update_post` also run `wp_kses_post` themselves (script and iframe stripped on every path). |
| Authorship | `create_post`, media, menu items, revisions → author 0 | → the owner | Approved change. Verified: `create_post` under legacy → `author: 0`, bound → `author: 1`. |
| Elementor `Document::save()` | returned `false` for user 0; pipeline ignored it and used the frontend fallback (CSS text ends up in `post_content`) | runs: elements validated through their classes, `_elementor_data` re-encoded, `post_content` rendered | Guarded with `Throwable`; `elementor_save` reported; structural `meta_persisted`. Verified: bound Bearer output equals the App Password admin output for every call except the kses difference above. |
| Capability checks | cosmetic | real (`user_can` on the owner) | Verified by the before/after run (no tool refused for the admin owner). |
| Post locks | any lock blocked | the owner's own lock no longer blocks | Accepted; other users' locks still block. |
| `tax_input` on insert | dropped | honoured | No plugin tool uses it. |
| Idempotency / rate-limit keys | shared user 0 | keyed per owner | No change needed. |
| Elementor MCP autosave selection | any user's autosave | the owner's | Sprint C concern. |
| Multisite | not declared | non-member owner has no caps → rejected as demoted; only super admins have `unfiltered_html` | Documented only. |

## What was verified and how

- **Unit tests:** `vendor/bin/phpunit` → 71 tests, 616 assertions, green (19 new): owner recorded on generate/rotate/assign (and not on no-op generate, and not for WP-CLI user 0); bound Bearer sets the user once and enforces caps; kses lock denies `unfiltered_html`/`edit_css` only during a bound Bearer request; owner missing → `iato_mcp_owner_missing` (403, logged); owner demoted → `iato_mcp_owner_demoted`; wrong key still 401; unbound legacy → user 0, any cap passes, `is_unbound()`, `require_bound_user()` error; Application Password path untouched.
- **Before/after comparison (`tests/wp-env/tools-snapshot.sh`):** 64 tool invocations (every read tool on the fixture pages; writes on scratch pages: `create_post`, `update_post` ×2, `update_seo_data`, `update_canonical`, `update_structured_data`, `update_post_meta`, `set_page_settings`, `set_featured_image`, `update_alt_text`, `update_elementor_widget` ×3 incl. dry run, `set_heading_level`, `set_widget_setting`, `update_elementor_patch`, `update_elementor_widgets_bulk` dry run, `update_elementor_data`, rollbacks), run over Bearer and over an admin Application Password, on the pre-C0 code ("before") and after binding the key to `admin` ("after"), plus a "legacy" run (owner removed) on the new code. Results normalised (ids, hashes, dates). **Bearer before → after differences, all expected:** `elementor_save` field now present (`true`); `post_content` after Elementor writes is Elementor's rendered HTML instead of the frontend fallback's CSS text; `create_post` author 0 → 1; plus the run label in `update_alt_text` and scratch-id noise. **Application Password before → after:** no behaviour difference (only `elementor_save` present and noise). **After, Bearer vs Application Password:** identical except the `<script>` kept for the App Password admin and stripped for Bearer (the kses lock). Legacy run equals "before".
- **Elementor ability from PHP inside a bound Bearer request:** test-only mu-plugin `tests/wp-env/mu-manage-elements-probe.php` (copied into the wp-env site's `mu-plugins/`, `elementor_mcp_enabled` set) registers `_c0_probe_manage_elements`, which calls `wp_get_ability('elementor/manage-elements')->execute()`. Unbound key: `current_user_id: 0`, `ability_invalid_permissions`. Bound key: `current_user_id: 1`, `status: ok`, and the Sprint A reader re-reads the atomic heading with the new tag (on a draft page; on a published page Elementor stages the change in an autosave, as its description says). A classic widget through the same path returns `elementor_v3_not_supported`, confirming Elementor's own code ran.
- **Owner missing / demoted:** `wp user set-role admin editor` → 403 `iato_mcp_owner_demoted`; bind to a second admin and delete them → 403 `iato_mcp_owner_missing`; both logged with `rpc_method: auth`, `auth_user_id` = the owner id, once per request (core evaluates `permission_callback` again when building the `Allow` header; the duplicate rows were deduplicated).
- **Legacy mode:** owner option deleted → requests succeed, call log `auth_user_id: 0`, probe refused, notice rendered for an administrator and not for an editor, Settings row says "Unassigned (legacy mode)"; `handle_assign_key_owner` rebinds; Diagnostics row shows `admin (ID 1)`.
- **OAuth consent (`tests/wp-env/oauth-consent-check.sh`, real HTTP with a logged-in cookie):** the "This connection will act as" block reads correctly in all four states (unbound: "admin (admin), you. Approving assigns…"; self; other: "c0admin3 (c0admin3), the owner… It will NOT act as you (admin)…"; missing owner); an editor is redirected to wp-login; approving an unbound key binds it to the approver (302 to the client with `code=`); approving a key bound to someone else leaves the owner unchanged.
- **Call log:** `auth_user_id` is the owner for bound requests (verified on both wp-env sites).
- **PHP notices (WP_DEBUG on, dev site):** none from plugin code. Two third-party/core entries showed up during the write snapshots and were isolated with a targeted rerun of `update_elementor_widget`, `update_post` and `set_heading_level` on a copy of the mixed fixture page: under an **unbound key (user 0, i.e. pre-C0 behaviour)** Elementor logs `Undefined array key "widgetType"` from `elementor/modules/content-sanitizer/module.php:45` when classic containers are saved; under a **bound owner** the same writes log nothing. Core kses deprecations (`stripslashes(null)` in `wp-includes/kses.php`) were seen only during the legacy / pre-C0 snapshot runs and were not reproduced in bound mode.
- **WordPress 6.8.3** (tests env): plugin deactivates/activates cleanly, legacy and bound Bearer requests work, Settings page renders the Token owner row, `debug.log` empty.

## Answers to the pre-commit questions

1. **`/rollback` route.** Before the fix it was reachable by anyone holding a valid credential: the site Bearer key, an OAuth token (same key), or an Application Password of any user with `edit_posts`; unauthenticated requests got 401. It then enforced no capability. The caller needs a `change_id` (`wr_` + 16 hex chars from `random_bytes(8)`, not guessable, but returned by every write tool together with the `before_value`) and that `before_value`. It can revert any receipt type, including redirects, menu items, term create/update/delete and `create_media` receipts (whose rollback deletes the attachment file). **Fixed on its own branch off the released v1.12.0 code:** `fix/rollback-route-cap` (worktree `../mcp-wordpress-rollback-fix`), `includes/class-rollback.php` `handle()` now enforces `edit_posts`, the id format, and the receipt type's capability exactly like the MCP tool; note in `docs/fix-rollback-route-cap.md`. Verified live: editor → 403 on a redirect receipt, admin and Bearer pass the gate, malformed id → 400, no credentials → 401.
2. **Elementor `Document::save()` throwing or refusing during a classic-widget edit.** The plugin's own direct `_elementor_data` write happens first and stays; the pipeline then catches the `Throwable` (or the `false` return), reports it as `elementor_save: 'error'` + `elementor_save_error` (or `false`), renders `post_content` through the frontend fallback, and the tool returns success with `meta_persisted: true` and the revision of what is stored. Before C0 an exception would have propagated uncaught (`handle_tools_call()` has no try/catch) and a `false` return was ignored silently. `tests/ElementorWritePipelineTest.php` simulates all three outcomes.
3. **Reactivation with an existing unbound key.** Does not bind: `maybe_generate_key()` returns before touching anything when `iato_mcp_key` exists (`includes/class-auth.php`), and the activation hook calls nothing else that binds. Verified live: owner deleted, plugin deactivated and reactivated as admin → owner still missing, key unchanged. Unit test `test_generate_key_is_a_noop_when_a_key_exists`.
5. **`require_cap()` and user 0.** It never looked at the user ID: it first requires `IATO_MCP_Auth::$authenticated`, which only `authenticate()` sets, and an unauthenticated visitor never reaches a tool handler because `authenticate()` is the `permission_callback` of both routes. The "full access" early return used to mean "authenticated and no `WP_User` stored"; it is now the explicit `$site_key_full_access` flag, set only on the unbound site-key path. A bound key carries its owner as the user and is checked with `user_can()`; an authenticated request with neither flag nor user is refused. Tests: unauthenticated refused, `$authenticated` alone grants nothing, only the unbound path sets the flag. The same change, with the flag set on every site-key request, is on `fix/rollback-route-cap`.
4. **Token owner control.** Only `manage_options` users: `handle_assign_key_owner()` runs `check_admin_referer('iato_mcp_assign_key_owner')` (nonce in the link) and `current_user_can('manage_options')`, and refuses targets that do not exist or lack `manage_options`. Regenerate (`handle_regenerate_key`) and OAuth consent have their own nonce + `manage_options` checks. Every owner change, from every path, is now logged in the call log (`rpc_method: owner`, `request_args: {from, to, by}`, `auth_user_id` = the acting user), because all paths go through `bind_key_owner()`; unchanged re-binds do not log.

## What was not verified
- `create_media` authorship (no upload in the snapshot); the same `wp_insert_post` default applies.
- Multisite.
- The Setup Wizard's generated configs (unchanged).
- A real OAuth client end-to-end (the token endpoint is unchanged; only the authorize screen and its POST were exercised).

## Open questions / follow-ups
1. The `/rollback` REST route capability gap is fixed on `fix/rollback-route-cap` (see the answers above); Part 1 does not include that change so the patch can ship on its own.
2. Change receipts still record no actor (user ID and credential kind). With a bound owner the call log now says who made each tool call, but the receipt itself cannot say who may roll it back; the rollback fix branch adds object-level capability checks, and "same user or an administrator" on top of that needs an `actor_user_id` column (dbDelta on upgrade, as the other tables do). Deferred to a later sprint.
3. The OAuth weaknesses above (code = token, no expiry, global PKCE transient) are unchanged; a per-client token table would also allow per-client owners.
4. Idempotency and rate-limit transients are keyed by owner, so all clients sharing the key share them (as they shared user 0 before).

## Test-site state (dev wp-env, http://localhost:8888)
Key bound to `admin` (ID 1). `elementor_mcp_enabled` = 1. The test-only mu-plugin was removed from the site after the checks (re-copy `tests/wp-env/mu-manage-elements-probe.php` into `mu-plugins/` to repeat them). Page 37 (`iato-c0-probe`, draft) and the registered OAuth client `c0probe` remain. Users `editor`, `subscriber` with Application Passwords. Tests env (8889) is on WordPress 6.8.3 with the key bound to user 1.

## Paths to review / add (Part 1)
```
CLAUDE.md
docs/sprint-c0-handover.md
iato-mcp.php
includes/class-auth.php
includes/class-diagnostics.php
includes/class-elementor-adapter.php
includes/class-oauth.php
includes/class-settings.php
includes/tools/wp/tool-page-builder.php
tests/AuthBindingTest.php
tests/ElementorWritePipelineTest.php
tests/bootstrap.php
tests/stubs-auth.php
tests/wp-env/mu-manage-elements-probe.php
tests/wp-env/oauth-consent-check.sh
tests/wp-env/tools-snapshot.py
tests/wp-env/tools-snapshot.sh
uninstall.php
```
