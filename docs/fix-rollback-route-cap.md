# Security fix: `/iato-mcp/v1/rollback` enforced no capability

Branch `fix/rollback-route-cap`, based on the released v1.12.0 tree (`af81a5a`), so it can ship as a standalone patch release.

**Who could call it before this fix.** The route's `permission_callback` is `IATO_MCP_Auth::authenticate()` (`includes/class-rollback.php`, `register_route()`), so the caller needs a valid credential: the site's Bearer key (admin-level by design), an OAuth access token (which is that same key), or a WordPress Application Password of any user with `edit_posts` (Contributor and up). Unauthenticated requests get 401. After that, `handle()` performed **no capability check at all**.

**What they need to know.** A `change_id` and the receipt's `before_value`. `change_id` is `wr_` + 16 hex characters from `random_bytes(8)` (`includes/class-change-receipt.php::generate_id()`), i.e. 64 random bits: not guessable, but every write tool returns it (and the `before_value`) in its `change_receipt`, and the `/rollback` body only has to repeat them. So anyone who can see a client's tool output (shared transcript, screenshot, log) and holds an `edit_posts` Application Password had what they needed.

**What it could change.** Everything `dispatch_rollback()` supports: post title/content/excerpt/status/slug, SEO meta, alt text, menu items (`wp_update_nav_menu_item`), redirects (Redirection / Safe Redirect Manager / Yoast / plugin option), term create/update/delete (`wp_delete_term`), post meta, and `create_media` receipts, whose rollback runs `wp_delete_attachment( $id, true )` and deletes the file. The MCP `rollback` tool gates these per receipt type (`edit_theme_options` for menus, `manage_options` for redirects, `manage_categories` for term CRUD, `manage_options` for unknown types); the REST route did not.

**Fix.** `handle()` now runs the same checks as the tool, in the same order: `IATO_MCP_Auth::require_cap('edit_posts')` before any lookup, the `^wr_[a-f0-9]{16}$` format check, then `require_cap( IATO_MCP_Change_Receipt::cap_required_for( $receipt ) )` after the receipt is loaded and before `dispatch_rollback()`. Behaviour for the site Bearer key is unchanged (it passes every capability); Application Password users are now limited exactly as they are through the MCP tool.

**Verified** on wp-env (patch applied on top of the current working tree): with an Editor's Application Password, rolling back a `redirect`-type receipt returns 403 `iato_mcp_forbidden` ("You do not have the required capability: manage_options"); the same request with an administrator Application Password or the site Bearer key passes the capability gate and reaches `dispatch_rollback()`; a malformed `change_id` returns 400 `invalid_change_id`; no credentials returns 401.

## Object-level permissions (second part of this fix)

Receipts are stored server-side and the stored `before_value` is what gets restored; the caller's `before_value` on the REST route is only a confirmation. The remaining gap was that both the route and the MCP tool checked a type-level capability only (`edit_posts` for post-like receipts), so a Contributor could roll back another user's published page, delete another user's upload through an `attachment` receipt, or change terms on a post they cannot edit.

`IATO_MCP_Change_Receipt::object_cap_for( $receipt )` now resolves the WordPress meta capability and the object it applies to, and `IATO_MCP_Rollback::check_permission( $receipt )` enforces it through `IATO_MCP_Auth::require_cap( $cap, $object_id )` (whose signature gained the forwarded object arguments). The REST route and the `rollback` MCP tool both call `check_permission()`, so they cannot drift.

| Receipt (`target_type` / `field`) | Capability checked | Object |
|---|---|---|
| `post`, `page`, `post_meta`, `elementor_widget` | `edit_post` | the post |
| `image` (alt text; `post_id` is the attachment) | `edit_post` | the attachment |
| `attachment` / `create` (rollback deletes it) | `delete_post` | the attachment |
| `taxonomy` / `assign`, `terms` (post-level) | `edit_post` | the post |
| `taxonomy` / `create_term` (rollback deletes the term) | `delete_term` | `after_value.term_id` |
| `taxonomy` / `update_term` | `edit_term` | `before_value.term_id` |
| `taxonomy` / `delete_term` (rollback re-creates the term) | `manage_categories` | none, by design: the object does not exist until the rollback runs |
| `menu_item` | `edit_theme_options` | none: menus are site-wide |
| `redirect` | `manage_options` | none: site-wide |
| unknown type | `manage_options` | none (fail closed, unchanged) |

**When the object no longer exists** (deleted post or attachment, deleted term on a `create_term` / `update_term` receipt): nothing object-specific is left to verify and the rollback will mostly be a no-op or fail on its own, so the check falls back to the type-level capability from `cap_required_for()`: `edit_posts` for post-like receipts (a Contributor passes; the rollback then fails with `not_found` / is a no-op for a missing attachment), `manage_categories` for term receipts. `delete_term` is type-level by design, as above. Unknown types stay at `manage_options`.

**The site Bearer key keeps full access.** On this branch Bearer requests run as user 0 with no `WP_User`; `require_cap()` returns `true` for every capability on that path before any `user_can()` call, so the object arguments never matter. `RollbackPermissionTest::test_bearer_key_without_a_user_can_roll_back_everything` covers all sixteen receipt shapes, including missing objects. With token binding (Sprint C0) the key runs as an administrator owner and passes `user_can()` instead; verified live on that tree too.

**Tests** (`tests/RollbackPermissionTest.php`, with a role/meta-cap model in `tests/stubs-rollback.php` that mirrors core's `map_meta_cap` rules for `edit_post` / `delete_post` / `edit_term` / `delete_term`): the mapping table above; Bearer passes everything; unauthenticated is refused; a Contributor is refused on another user's published page (four receipt types), on an attachment delete, and on a taxonomy change to a post they cannot edit, and on term CRUD; a Contributor can roll back their own draft; an Author succeeds on their own published post and is refused on others' content; an Editor passes object-level and fallback checks but not site-wide ones (menus, redirects, unknown types); missing objects fall back as documented; an Administrator succeeds on all. 43 tests green on this branch.

**Verified live** (patch applied on top of the C0 tree, two new users with Application Passwords): MCP tool and REST route both refuse a Contributor on the admin's published page (`edit_post`), on a scratch attachment's `create` receipt (`delete_post`), and on a taxonomy assignment to that page; an Author is refused on that page and succeeds on their own published post; an administrator succeeds on the page (route, 200) and on the attachment delete (tool; the scratch attachment is gone afterwards); the site Bearer key succeeds, both bound (C0) and unbound (user 0).

## `require_cap()` and user 0

A site-key request and an unauthenticated visitor are both WordPress user 0. `require_cap()` never looked at the user ID: it first requires `IATO_MCP_Auth::$authenticated`, a private static that only `authenticate()` sets, and an unauthenticated visitor never reaches a tool handler at all because `authenticate()` is the `permission_callback` of both routes. The "full access" early return used to be "authenticated and no `WP_User` stored"; it is now the explicit `$site_key_full_access` flag, set only in the Bearer branch of `authenticate()`. An authenticated request with neither that flag nor a `WP_User` is refused (defensive). `tests/AuthRequireCapTest.php` covers: unauthenticated refused, authenticated-without-flag-or-user refused, site-key flag grants everything including object-level caps, Application Password users are checked with object arguments.

Every `require_cap()` caller is inside an MCP tool handler (run only by `handle_tools_call()`, after the route's `permission_callback`) or inside the `/rollback` route handler / `IATO_MCP_Rollback::check_permission()` (same `permission_callback`). Handlers run outside an authenticated request (for example through `IATO_MCP_Server::invoke_tool()` from a WordPress Ability, on branches that have it) see `$authenticated === false` and are refused.

## Follow-up (deferred, later sprint)
Receipts record no actor. Adding `actor_user_id` (and the credential kind) to the receipts table would allow "same user or an administrator" on top of the object-level check and complete the audit trail. It changes the table schema (dbDelta on upgrade), so it was left out of this patch release.

Paths: `includes/class-auth.php` (require_cap object args), `includes/class-change-receipt.php` (`object_cap_for`), `includes/class-rollback.php` (`check_permission`, route checks), `includes/tools/wp/tool-rollback.php`, `tests/RollbackPermissionTest.php`, `tests/stubs-rollback.php`, `docs/fix-rollback-route-cap.md`.
