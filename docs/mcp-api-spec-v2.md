<p align="center">
  <img src="iato-logo.png" alt="IATO" width="280" />
</p>

<h1 align="center">IATO MCP — API Spec v2</h1>

<p align="center">
  Widget-grained Elementor endpoints, optimistic concurrency, idempotent writes,<br>
  and structured diff responses. Built to make multi-page edits cheap.
</p>

---

## Table of Contents

1. [Overview & Motivation](#overview--motivation)
2. [Cross-Cutting Design](#cross-cutting-design)
3. [New Endpoints](#new-endpoints)
   - [Tier 1 — Patch & Targeted Access](#tier-1--patch--targeted-access)
   - [Tier 2 — Batch](#tier-2--batch)
   - [Tier 3 — Dry-Run + Diff Response](#tier-3--dry-run--diff-response)
   - [Tier 4 — Routing Awareness](#tier-4--routing-awareness)
   - [Tier 5 — Compact Format on Reads](#tier-5--compact-format-on-reads)
   - [Tier 6 — Semantic Helpers](#tier-6--semantic-helpers)
   - [Atomic (Editor V4) writes (1.14.0)](#atomic-editor-v4-writes-1140)
4. [Backwards Compatibility](#backwards-compatibility)
5. [Phased Rollout](#phased-rollout)
6. [Regression Fixtures](#regression-fixtures)
7. ["Prove It" Benchmark](#prove-it-benchmark)
8. [Implementation References](#implementation-references)
9. [Open Questions](#open-questions)

---

## Overview & Motivation

### The problem

Today's session burned roughly **60 minutes flipping `header_size: h2 → h1` on 13 Elementor pages**. Every page required emitting the entire 20–40 KB Elementor JSON document through the MCP boundary because `update_elementor_data` is **document-grained, not widget-grained**:

```
13 pages × ~30 KB doc × (1 read + 1 write request + 1 read + 1 write response)
≈ 1.5 MB over the wire
~52 conversation turns to keep the model focused
```

The savings opportunity is over the wire and in the client-side context window — not at the database layer. WordPress still does a read-modify-write of `_elementor_data` on every save; that's unavoidable given how Elementor stores its tree. But the AI client should never have to see, parse, or echo back the full document just to flip one setting on one widget.

### What v2 does

A second tier of MCP tools exposes Elementor at the **widget level**:

- Read a flat list of widgets without their settings (~2 KB instead of ~30 KB).
- Patch a single widget's settings (~200 bytes round-trip).
- Patch many widgets across many posts in a single batch call.
- Server-side filtered search (`find_elementor_widgets`) so the client never downloads what it doesn't need.

Plus the cross-cutting features that make multi-step writes safe and cheap: optimistic concurrency, idempotency keys, structured JSON Patch diffs in dry-run, and routing awareness that handles Theme Builder shadowing.

### What v2 is **not**

- **Not a replacement for v1.** Existing `update_elementor_data` / `get_elementor_data` keep working with no behavior change. Soft deprecation only — no hard cut.
- **Not a Gutenberg / WPBakery / Divi solution.** Same shape *could* apply but is out of scope here.
- **Not for the IATO bridge tools.** `get_iato_*` tools have no token-cost issue — they already return enriched, summarized data.
- **Not for the SEO / menu / media / structured-data tools.** Those are already field-level by design.

---

## Cross-Cutting Design

### Concurrency: `if_revision`

Elementor stores its tree as a JSON string in `_elementor_data`. v2 introduces a **revision hash** for optimistic concurrency:

- The hash is `sha256(stored_elementor_data_string)`, computed fresh on every read.
- All v2 read tools include `revision: "sha256:..."` in their response.
- All v2 write tools accept an optional `if_revision` parameter.
- If `if_revision` is supplied and doesn't match the current hash, the server returns `revision_conflict` with the current hash so the client can re-fetch and retry.

```json
// 1. Read with revision
{ "post_id": 42, "widget_id": "abc123", "revision": "sha256:7c4a..." }

// 2. Write with same revision — succeeds
update_elementor_widget(post_id: 42, widget_id: "abc123", settings_patch: {...},
                        if_revision: "sha256:7c4a...")

// 3. Or — meanwhile someone else edited the page in wp-admin — fails
{ "error": "revision_conflict", "current_revision": "sha256:9e2f..." }
```

`if_revision` is **optional**. Omitting it is last-write-wins, matching v1 behavior. Server still computes and returns the new revision in the response.

The hash is computed inside the existing write path that already reads `_elementor_data` to feed `Document::save()` — see `tool-page-builder.php:183-247`. Adding the hash adds one `hash('sha256', $string)` call per write; ~negligible.

### Idempotency: `idempotency_key`

All v2 write tools accept an optional `idempotency_key` parameter. The server caches the response keyed by `(user_id, tool_name, idempotency_key)` for **60 seconds** in a transient.

If the same key arrives within the window:

- **Same payload** → server returns the cached response without touching the DB. The response includes `idempotency_replay: true`.
- **Different payload** → returns `idempotency_replay` error code. The client picked a key that's already in flight with different content.

Storage: WordPress transient `iato_mcp_idem_{sha256(user_id|tool|key)}`, TTL 60s. Cleaned automatically by the WP transients GC.

### Dry-run: structured diff response

The existing `dry_run` flag — already supported by `tool-page-builder`, `tool-seo`, `tool-menus`, `tool-taxonomy`, `tool-structured-data`, `tool-canonical`, and `tool-redirects` — is extended in v2 to return a **structured JSON Patch diff** of what *would* change:

```json
{
  "dry_run": true,
  "post_id": 42,
  "widget_id": "abc123",
  "current_revision": "sha256:7c4a...",
  "applied_patch": [
    { "op": "replace", "path": "/settings/header_size", "value": "h1", "previous_value": "h2" }
  ],
  "warnings": []
}
```

`applied_patch` is RFC 6902 JSON Patch with one extension: each `replace` / `remove` op carries a `previous_value` field for full context without a separate read. Actual writes (non-dry-run) return the **same shape minus the dry_run flag**, so the client gets identical confirmation regardless of mode.

### Errors

| Code | When | Status |
|---|---|---|
| `widget_not_found` | `widget_id` doesn't resolve in this post's tree | 404 |
| `invalid_patch_path` | JSON Patch op references a path that doesn't exist | 400 |
| `revision_conflict` | `if_revision` mismatch | 409 |
| `schema_violation` | Patch produces a tree Elementor would reject | 400 |
| `auth_denied` | Capability check failed | 403 |
| `idempotency_replay` | Same key, different payload, within 60 s | 409 |
| `not_elementor` | Post isn't an Elementor document | 400 |
| `template_locked` | Theme Builder template referenced is read-only on this site | 423 |
| `atomic_plain_value_unsupported` | 1.13.1: a key an atomic element does not have (or, before 1.14.0, any plain value) | 400 |
| `atomic_invalid_settings` | Elementor's validation rejects the new settings of an atomic element | 400 |
| `atomic_write_unavailable` | 1.14.0: a plain value for an atomic element where Elementor's code to convert it is missing (Elementor before 4.3.0, the Atomic Editor off, the type not registered); the message names what is missing | 400 |
| `atomic_prop_not_supported` | 1.14.0: a key or object field the atomic element does not have, or `classes` (global classes) | 400 |
| `atomic_type_not_supported` | 1.14.0: `e-component` instances | 400 |
| `atomic_duplicate_key` | 1.14.0: a setting and one of its aliases sent together | 400 |
| `atomic_value_unresolvable` | 1.14.0: Elementor's resolver cannot convert the value | 400 |
| `atomic_value_dropped` / `atomic_subkeys_dropped` | 1.14.0: Elementor's validation would drop a value sent, or a stored sub-value | 400 |
| `atomic_url_rejected` | 1.14.0: an image URL that is not absolute, or a URL Elementor's sanitising empties (`javascript:`) | 400 |
| `atomic_library_alt_separate` | 1.14.0: the alt of a Media Library image sent with another change | 400 |
| `elementor_editor_unsaved_changes` | 1.14.0: the Elementor editor holds the post lock and reported unsaved changes (Elementor's own rule) | 409 |
| `elementor_cannot_edit` / `elementor_validation_failed` | 1.14.0, atomic path: Elementor refused the save / its save rejected the page | 403 / 400 |
| `iato_mcp_token_unbound` | 1.14.0, atomic path: the site key has no owner (Settings > IATO MCP, Token owner) | 403 |
| `tool_exception` | 1.14.0: a Throwable inside a tool handler (JSON text names the tool and the exception) | — |
| `elementor_not_active` | 1.16.0: the post holds Elementor data but Elementor is turned off for it, so a write would replace the content visitors see. Dry runs too. `content_chars`, `content_is_elementor_text`. `update_elementor_data` accepts `activate_builder: true` | 409 |
| `elementor_empty_tree` | 1.16.0: an empty element list sent to a post with Elementor off | — |
| `elementor_invalid_tree` | 1.16.0: a tree Elementor cannot render: an object with named keys, a node that is not an object, no `elType`, a widget with no `widgetType`, `settings` that are not an object. Dry runs too. `invalid_nodes` lists up to ten. An element with no `id` or an unknown type is allowed | — |
| `revision_target` | 1.16.0: a write named a revision's ID. WordPress would send it to the parent post | 400 |
| `meta_key_mismatch` | 1.16.0: the database reads this key as a different one already stored on the post, or, when the post has no such key yet, as another spelling stored anywhere on the site (`stored_key`). Dry runs too, and `force` does not lift it | — |
| `meta_key_too_long` | 1.16.0: over 255 characters, which the column cannot store | — |
| `invalid_meta_key` | Empty; since 1.16.0 also a key holding a backslash, which WordPress removes before looking the key up | — |
| `dry_run_unsupported` | 1.16.0: `dry_run` passed to a tool that does not declare it. The tool is not run | — |
| `reserved_shortcode` | 1.15.1: the arguments of a tool that writes contain `[elementor-element`, a shortcode Elementor reserves for its own cache. The call is refused before the tool runs. Read tools may search for it | — |

The atomic codes come back as an `isError` result whose text is JSON: `{ error, message, element_id?, ... }`.

All errors include `error_message` (string) and `error_data` (object). On a 409 `revision_conflict`, `error_data.current_revision` is included so the client can re-sync without an extra read.

### Auth

- **Read tools** (`list_elementor_widgets`, `get_elementor_widget`, `get_elementor_data`, `get_page_builder`, `find_elementor_widgets`): since 1.12.1 every read goes through `IATO_MCP_Auth::require_read_post()`: `read_post` on the post (a revision is resolved to its parent first), `edit_post` as well when the post is password-protected, and `manage_options` for `elementor_library` templates. `find_elementor_widgets` scans only readable posts (explicit `post_ids` that were dropped come back in `skipped_unreadable`; posts the automatic scan dropped are only counted in `skipped_unreadable_count`) and needs `manage_options` for `include_templates`. `resolve_url` returns IDs, not content, and has no per-post check. The site key passes every check. Mirrors `get_seo_data` and `get_post`.
- **Write tools** (`update_elementor_widget`, `update_elementor_patch`, `update_elementor_widgets_bulk`, `set_heading_level`, `set_widget_setting`, `update_elementor_data`): `IATO_MCP_Auth::require_cap('edit_posts')` at entry, then `edit_post` on the specific post (since 1.12.1); `update_elementor_data` applies `require_read_post()` to `inherit_settings_from`. Mirrors `update_seo_data`.
- **Capability check is per-post**: the bulk update checks `edit_post` for each target and reports a refused row in `results[]` with `error: iato_mcp_forbidden` (counted in `failed`) rather than failing the whole call.

### Change receipts

Every Elementor write records a receipt through `IATO_MCP_Change_Receipt::record()` ([`includes/class-change-receipt.php`](../includes/class-change-receipt.php)), with the actor (1.13.0), and returns a slim `change_receipt` (`change_id`, `target_type`, `field`, `applied_at`, `actor`). The `rollback` tool and `/wp-json/iato-mcp/v1/rollback` reverse any of them, with the conflict check (`rollback_conflict` unless `force`) and `dry_run`.

| `target_type` | Written by | `field` | `before_value` / `after_value` | Rollback |
|---|---|---|---|---|
| `elementor_widget` | classic `update_elementor_widget`, `set_widget_setting`, `set_heading_level`, bulk rows; `update_elementor_patch`; `update_elementor_data` | `elementor:<widget_id>:<pointer>`, `elementor:document:patch`, `elementor:document:replace`, `elementor:document:activate` (1.16.0: the write replaced the content visitors saw, and the before value holds what a rollback restores) | the stored tree as it was (1.13.0; pruned after the retention period) / the revision hash of what was stored | restores the whole tree |
| `elementor_atomic` (1.14.0) | edits of Atomic Editor (V4) elements through the writer | `elementor:<element_id>:settings` | `{element_id, type, path, keys: {key: stored value, or null when absent}}` / the same keys as read back after the write | restores those keys of that element only, validated by Elementor; refused (`rollback_unavailable`) when the element is gone or Elementor's code is missing |
| `image` (`alt_text`) | `update_alt_text`, including the alt of a Media Library image routed from an atomic element | `alt_text` | the attachment's alt | restores the attachment's alt |

Bulk results drop `change_receipt` from classic rows (lean payloads), unless it carries a `warning`; atomic rows keep it, one receipt per element (two rows on one element share it).

Since 1.16.0 an ID is returned only if the receipt was saved. If it was not: `change_id: null`, `stored: false`, and a `warning` saying rollback cannot undo the change, which was still applied. If a database column for the actor is missing, the receipt is saved with a `warning` that who made the change was not recorded. `rollback` adds a `warning` if it could not mark the receipt.

An `update_elementor_data` write on a post with Elementor off stores an envelope as its before value: a JSON object with the content, the flag and any old tree, which rollback restores (`restored: "content_and_elementor_off"` or `"no_elementor_data"`). Rollback of a stored tree also brings `post_content` in line: `content_refreshed`, dry run `would_refresh_content`, on failure `content_note`.

---

## New Endpoints

For each endpoint: input schema, output shape, error codes, auth, full request/response example, brief implementation notes where non-obvious.

### Tier 1 — Patch & Targeted Access

#### `list_elementor_widgets`

Returns a flat list of all widgets in a post's Elementor tree, **without their settings**. Target payload <2 KB for typical pages.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | Post ID |
| `format` | enum | No | `flat` (default) or `tree` |

**Response:**

```json
{
  "post_id": 42,
  "revision": "sha256:7c4a...",
  "widget_count": 14,
  "widgets": [
    { "widget_id": "abc123", "type": "heading", "parent_id": null, "depth": 0, "title": "About Us", "header_size": "h2" },
    { "widget_id": "def456", "type": "text-editor", "parent_id": "abc123", "depth": 1 },
    ...
  ]
}
```

`title` and `header_size` are included only for widgets where they're meaningful (headings, text-editors with a `title` setting). Adding more peek fields on demand is fine — keep the goal of <2 KB total payload.

**Errors:** `not_elementor`.

#### `get_elementor_widget`

Returns a single widget's full settings + element metadata.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | Post ID |
| `widget_id` | string | Yes | Elementor widget ID |

**Response:** `{ post_id, revision, widget_id, type, parent_id, depth, settings: { ... } }`

**Errors:** `not_elementor`, `widget_not_found`.

#### `update_elementor_widget`

Partial-merges a settings patch into a single widget.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | Post ID |
| `widget_id` | string | Yes | Target widget |
| `settings_patch` | object | Yes | Object merged into widget's `settings` |
| `dry_run` | boolean | No | Preview only |
| `if_revision` | string | No | Optimistic concurrency hash |
| `idempotency_key` | string | No | Deduplication key |

**Implementation note:** server walks the JSON tree once to locate `widget_id`, deep-merges `settings_patch` into the matching widget's `settings` object (existing keys overwritten, new keys added, `null` removes), then re-runs the existing `Document::save()` flow from `tool-page-builder.php:194-247`. Same CSS regeneration and `post_content` reflow path — the only thing that changes is *what* gets serialized back.

**Response (success):**

```json
{
  "post_id": 42,
  "widget_id": "abc123",
  "previous_revision": "sha256:7c4a...",
  "current_revision": "sha256:9e2f...",
  "applied_patch": [
    { "op": "replace", "path": "/settings/header_size", "value": "h1", "previous_value": "h2" }
  ],
  "content_updated": true,
  "post_content_length": 17892,
  "applied_via": "iato-native",
  "schema": "classic",
  "state": "live",
  "elementor_save": true,
  "stored_by": "elementor",
  "change_receipt": { "change_id": "wr_…", "target_type": "elementor_widget", "field": "elementor:abc123:/0/elements/1/settings", "applied_at": "…", "actor": { "user_id": 1, "user_login": "admin", "kind": "site_key" } }
}
```

Fields since 1.13.0: `elementor_save` (`true` = Elementor's `Document::save()` stored the tree; `false` = Elementor refused; `"error"` = it threw, with `elementor_save_error`; `"skipped"` = not called, with `skipped_reason`), `stored_by` (`elementor` | `plugin`), `sanitized: true` and `sanitized_paths` (`<element id>/settings/<path>`, only when a value was altered), `change_receipt`. Since 1.13.1: `invalid_elements`, `skipped_reason: "unregistered_element_types"` with `unregistered_element_types`, `cache_cleared`, `verification`. Since 1.14.0: `applied_via`, `schema`, `state`; when the plugin stored a classic page because Elementor's save failed, `cache_cleared`, `css_regenerated: false` and `css_note`. Since 1.15.0: `sanitized_report` and `cache`, see [Reports on every write](#reports-on-every-write-1150). Atomic targets: see [Atomic (Editor V4) writes](#atomic-editor-v4-writes-1140).

**Errors:** `not_elementor`, `widget_not_found`, `revision_conflict`, `schema_violation`, `auth_denied`, `idempotency_replay`.

#### `update_elementor_patch`

Power-user escape hatch — apply RFC 6902 JSON Patch ops to the full Elementor document. Use when multiple widgets need atomic changes, or when the change is structural (e.g. moving a widget between containers).

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | Post ID |
| `ops` | array | Yes | RFC 6902 ops array |
| `dry_run` | boolean | No | |
| `if_revision` | string | No | |
| `idempotency_key` | string | No | |
| `return` | string | No | `full` (default) or `minimal` (1.21.0): values become their bytes and sha256 |

**Lists (1.21.1).** A list is a JSON array: in PHP, an array keyed 0..n-1, or empty.
- `add` at a list index inserts before the item there. An index equal to the length appends, as does `-`. An index past the end, or one with a leading zero, is `invalid_patch_path`. Until 1.21.1 `add` replaced the item at the index.
- `copy` and `move` insert the same way. `move` is evaluated as RFC 6902 says: the removal first, then the insert at `path`. `move` into the moved element's own children is `invalid_patch`.
- `remove` re-indexes the list. Until 1.21.1, removing a middle item left a gap, so a later op could not find the next item, and the list could be stored as a JSON object.
- An object's member is set by `add` (replacing an existing one, as RFC 6902 says). A stored page whose elements have gaps in their numbering is not a list, and keeps the old behaviour.

Each `add`, `copy`, `move` and `remove` on a list item carries `list`: `{path, index, count_before, count_after, ids_after}`. `ids_after` holds each item's `id` (elements) or `_id` (repeater rows), null for an item with neither, at most 100, with `ids_truncated` past that. A `move` between two lists also carries `from_list`. Dry runs carry the same, and `return: "minimal"` keeps it.

**What was stored (1.21.0, 1.21.1).** Each `add` and `replace` op carries `stored_matches_input`, read back from the stored tree after the write (on a dry run, from the tree the sanitising pass would store; Elementor's own save is not simulated).
- **How it is found:** the op's element, by its id, and the path inside it. A `/-` path, or a position a later op in the same patch shifted, is read where the value went. Without an element on its path, the op's path is read. An op whose value cannot be found carries no `stored_matches_input`.
- **What counts as the same:** objects whatever their key order, lists in order, strings byte for byte.
- **When it is false:** `stored_bytes`, `stored_sha256`, `stored_value` (not with `minimal`) and `stored_difference`, a list of:
  - `markup_normalized`: a string holds the same HTML written differently. Same tags, same attributes with the same decoded values, same decoded text; quotes, character references, attribute order or case differ. Needs WordPress 6.5.
  - `keys_added` / `keys_removed`: the save added or dropped keys (`added_paths` / `removed_paths`, at most 20 each).
  - `changed`: anything else.
- **`sanitized_report`:** an entry for a value WordPress rewrote without removing a tag or attribute carries a note saying so.

**Response:** same shape as `update_elementor_widget`, but `applied_patch` reflects the full set of ops applied.

**Errors:** as `update_elementor_widget`, plus `invalid_patch_path` if any op references a path that doesn't exist post-patch, or an index past the end of a list.

### Tier 2 — Batch

#### `update_elementor_widgets_bulk`

Single round-trip across posts. Each update is independent; failures are reported per-update without aborting the batch.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `updates` | array | Yes | Array of `{ post_id, widget_id, settings_patch, if_revision? }` |
| `dry_run` | boolean | No | Applies to all updates |
| `idempotency_key` | string | No | Single key covers the whole batch |

**Response:**

```json
{
  "total": 13,
  "succeeded": 13,
  "failed": 0,
  "dry_run": false,
  "results": [
    {
      "post_id": 42,
      "widget_id": "abc123",
      "success": true,
      "previous_revision": "sha256:7c4a...",
      "current_revision": "sha256:9e2f...",
      "applied_patch": [...]
    },
    {
      "post_id": 56,
      "widget_id": "xyz789",
      "success": false,
      "error": "revision_conflict",
      "error_data": { "current_revision": "sha256:..." }
    }
  ]
}
```

**Implementation note:** the bulk handler iterates with the same per-post locking discipline as v1. No global transaction — partial success is the expected mode. Performance budget: 50 ms × N updates is the rough internal target for a 13-post batch (~650 ms), dominated by `Document::save()` per post.

#### `find_elementor_widgets`

Server-side widget search across many posts. Filter pushdown so the client never downloads what it doesn't need.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `post_ids` | array | Yes | Posts to search; or `[]` to search all Elementor posts |
| `filter` | object | Yes | Match criteria — see below |

**Filter shape:**

```json
{
  "type": "heading",
  "setting": {
    "header_size": { "ne": "h1" }
  }
}
```

Supported filter operators in `setting.{key}`:

| Operator | Meaning |
|---|---|
| direct value | Equality (`"header_size": "h1"`) |
| `eq` / `ne` | Equal / not equal |
| `in` / `nin` | Value in / not in array |
| `exists` | Setting key is present (boolean) |

**Response:**

```json
{
  "total_matches": 13,
  "matches": [
    { "post_id": 42, "widget_id": "abc123", "type": "heading", "header_size": "h2" },
    ...
  ]
}
```

**Implementation note:** worst-case O(P × W) where P = post count and W = avg widgets per post. For garennebigby.dev (~50 posts, ~40 widgets each) that's 2 000 widget evaluations — still <100 ms. If site size grows, server can iterate in a chunked WP_Query and stream matches; not needed for v2.0.

### Tier 3 — Dry-Run + Diff Response

All v2 writes return `applied_patch` (the RFC 6902 ops that actually applied or would apply) regardless of `dry_run` mode. This replaces v1's `{success, regenerated, content_updated, *_length}` shape with semantic confirmation:

```json
"applied_patch": [
  { "op": "replace", "path": "/settings/header_size", "value": "h1", "previous_value": "h2" }
]
```

Dry-run vs. real-write differ only in side effects; the response shape is identical. This means a client can build a UI that previews the diff with `dry_run: true`, then re-issues the same call without `dry_run` to commit — and the response is parsed by the same code path.

Existing v1 tools are NOT extended with `applied_patch` — they keep their existing response shape for backwards compatibility.

### Tier 4 — Routing Awareness

#### `resolve_url`

Walks the WordPress rewrite cascade and Elementor Theme Builder conditions to answer "what actually renders at this URL?"

| Parameter | Type | Required | Description |
|---|---|---|---|
| `url` | string | Yes | URL or path on the site |

**Response:**

```json
{
  "url": "https://example.com/build/",
  "rendering_post_id": 309,
  "rendering_template_id": 309,
  "shadowed_post_id": 204,
  "route_type": "theme_builder"
}
```

**`theme_locations` (1.17.0).** Which Elementor Pro Theme Builder header and footer the URL gets, on every response:

```json
"theme_locations": {
  "header": { "template_id": 412, "condition_matched": "include/general" },
  "footer": null,
  "not_evaluated": [ { "location": "footer", "template_id": 415, "condition": "include/singular/child_of/5" } ]
}
```

| Value | Meaning |
|---|---|
| `theme_locations: null` | Elementor Pro's Theme Builder is not active, reading it failed, the URL is on another host, or the caller may not read the post at this URL |
| `header` / `footer: null` | No published template in Pro's conditions cache applies to this URL |
| `hidden_by_page_template` | Only on a page using Elementor Canvas, its own or the kit's default: `header` and `footer` are null because the page template prints neither, whatever the conditions say |
| `not_evaluated` | Only when not empty: conditions that depend on something the plugin does not look at (child of, by author, in a term), and every condition but Entire Site on a URL it cannot judge (a feed, WooCommerce's shop page and product archives). The answer above may be incomplete. Without `manage_options`, entries carry `location` alone |

A header, footer, popup or section renders around or over a page, so since 1.17.0 none of them counts as shadowing it: `rendering_template_id`, `effective_render_id`, `template` and `get_post`'s `is_shadowed_by` report only templates that replace the content (`single`, `single-post`, `single-page`, `error-404`, `archive`, `search-results`, `product`, `product-post`, `product-archive`).

`route_type` is one of: `page`, `category_archive`, `tag_archive`, `author_archive`, `cpt_archive`, `taxonomy_archive`, `date_archive`, `search_results`, `blog_home`, `theme_builder`, `404`, or a post type. The four before `theme_builder` are new in 1.17.0, for pages that used to come back as `404`. What a URL is comes from WordPress's own rewrite rules and `WP_Query::parse_query()`: a term or author that does not exist is `404`, and so is a date or later page with no posts. `rendering_post_id` is null where `url_to_postid()` answers with the front page for a query string that shows something else, such as `/?cat=7`.

The `shadowed_post_id` field is populated when a higher-priority rule overrides what the slug would normally resolve to. In today's session this is the `/build/` case: the page with slug `build` (post 204) was shadowed by a Theme Builder template (post 309) that registered an "Include: All Pages" condition. The model spent ~10 minutes editing post 204 before realizing post 309 was the actual rendering surface.

**Implementation note:** Theme Builder conditions are stored as post meta on the template post (`_elementor_conditions`). Walk all `elementor_library` posts of type `single` / `archive` / `loop_item`, evaluate each condition against the resolved query, and pick the highest-priority match. This is non-trivial — see [Open Questions](#open-questions).

#### `get_post` extension

Optional `is_shadowed_by` field added to existing `get_post` response when the post's slug is overridden by a higher-priority route:

```json
{
  "id": 204,
  "title": "Build",
  "slug": "build",
  "is_shadowed_by": {
    "type": "theme_builder",
    "shadowing_id": 309,
    "reason": "Theme Builder template 'Build Page Layout' registered for 'All Pages'"
  },
  ...
}
```

When present, this is a hint to the client: "you're editing this post, but at runtime users see something else." This is a pure addition — clients that don't care about it can ignore it.

### Tier 5 — Compact Format on Reads

`get_elementor_data(id, format?: "raw" | "compact" | "summary")`:

- **`raw`** (default, today's behavior) — full JSON tree, every default value included.
- **`compact`** — strip fields equal to Elementor's documented widget defaults; ~3-5× shrink, no semantic loss. Server keeps a defaults table per widget type generated at install.
- **`summary`** — a flat or nested skeleton: `{ widget_id, type, title?, header_size? }` only. Roughly equivalent to `list_elementor_widgets(format: "tree")` plus identity fields.

A v2-aware client reading a page to introspect (rather than mutate) should default to `compact` or `summary`.

### Tier 6 — Semantic Helpers

Thin wrappers over `update_elementor_widget` for common operations. Same input contract as the underlying tool plus a single semantic parameter.

#### `set_heading_level`

```
set_heading_level(id, widget_id, level: "h1" | "h2" | "h3" | "h4" | "h5" | "h6",
                  dry_run?, if_revision?, idempotency_key?)
```

Equivalent to `update_elementor_widget(id, widget_id, { "header_size": "<level>" }, ...)` but the model can express the intent in one parameter. Also validates that the target widget is actually a heading widget (returns `invalid_widget_type` otherwise). Since 1.14.0 an Atomic Editor `e-heading` is accepted too: its level is `{ "tag": "<level>" }`, written through the atomic writer.

#### `set_widget_setting`

```
set_widget_setting(id, widget_id, key: string, value: any,
                   dry_run?, if_revision?, idempotency_key?)
```

Equivalent to `update_elementor_widget(id, widget_id, { "<key>": <value> }, ...)`. Slightly more discoverable than the patch form for one-key changes.

These helpers are a **thin layer** — they exist to make the model's intent legible in the call log and to give the client a clean affordance for the 80 % case. They MUST NOT diverge from `update_elementor_widget`'s behavior; they call through it.

### Atomic (Editor V4) writes (1.14.0)

Elementor Atomic Editor elements (`e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-flexbox`, `e-div-block`, …) store every setting as a typed value, `{"$$type": …, "value": …}`. Since 1.14.0 the fix tools take plain values for them and `IATO_MCP_Elementor_Atomic_Writer` ([`includes/class-elementor-atomic-writer.php`](../includes/class-elementor-atomic-writer.php)) converts and validates each value with Elementor's own code (`IATO_MCP_Elementor_Atomic_Engine`: the settings plain-values resolver, Elementor 4.3.0+, and `Props_Parser`). The plugin never builds a typed value itself. An element is atomic when Elementor's registered class for its type has a props schema; when Elementor cannot say (type not registered), when its type starts with `e-` and its settings hold typed values (the 1.13.1 rule).

**Routing.**

| Tool | Atomic target |
|---|---|
| `set_heading_level` | `e-heading` → `{tag: level}`; other atomic types → `invalid_widget_type` |
| `set_widget_setting`, `update_elementor_widget` | each key through the writer, plain or typed. A plain object (`link`, `image`) changes only the fields it names; the other stored fields keep their bytes (unlike classic, where arrays replace). `null` removes a key. Setting names Elementor accepts as aliases work (`text` for an `e-heading` title) and are reported under `aliases`. |
| `update_elementor_widgets_bulk` | a page's atomic rows run as one read-modify-write after that page's classic rows; at most 50 per page per call (`batch_size_exceeded`); a row that fails validation fails alone; `if_revision` per row is compared with the page revision at the start of the group; each row keeps its element's `change_receipt` |
| `update_elementor_patch`, `update_elementor_data` | ops as before; plain values in the atomic elements the change touches are converted (`converted_elements`), then the same page check; the whole-document receipt stays |
| `update_alt_text` | `post_id` + `element_id`: an `e-image` with an external URL gets `image.src.alt`; one with a Media Library image changes the attachment's alt (`routed_to: "attachment_alt"`, `scope: "all uses of attachment N"`) |

**Rules.** Only the element's schema keys (`atomic_prop_not_supported` lists them, with a hint for classic names such as `header_size`); `classes` and `e-component` are not edited. Image URLs must be absolute; setting a URL on a Media Library image clears its attachment id (Elementor requires one or the other). The alt of a Media Library image is changed in a call of its own. Values Elementor's validation rejects or drops are refused; nothing is written.

**Sequence.** Bound user (`iato_mcp_token_unbound`), Elementor's code available (`atomic_write_unavailable`), the element's type registered (`atomic_write_unavailable`: with the Atomic Editor off nothing can validate or show the element, so typed values are refused too), the editor not holding unsaved changes (`elementor_editor_unsaved_changes`, 409), `if_revision`; Elementor's load migrations, captured in memory and stored together with the change in the one write (`migrated_by_elementor: true`; `"would_migrate"` when nothing is written: dry run, no-op, refusal); build and validate; the page check: Elementor's per-element save transform over every atomic element and, for users without `unfiltered_html`, the kses pass Elementor's save applies, over every element including the untouched settings of the edited one. When the save would reject or alter an element the call does not change, the plugin stores the page itself so that element keeps its bytes (`elementor_save: "skipped"`, `skipped_reason: "invalid_elements_on_page"` with `invalid_elements`, or `"elementor_would_alter_untouched"` with `skipped_for_elements`). Otherwise Elementor's `Document::save()`: `false` → `elementor_cannot_edit`, a "Settings validation failed" throw → `elementor_validation_failed` (nothing written), any other throw → the plugin's write of the validated tree. Receipts right after the write; this post's caches only (Post CSS, element cache, atomic styles; no global flush); a re-read verifies the result.

**Result fields (atomic).** `applied_via: "iato-native"`, `schema: "atomic"`, `state: "live"`, `applied: [{key, from, to}]` (plain values, `to` as read back), `applied_patch` (ops on `…/settings/<key>` with the stored typed value and, for `replace` / `remove`, `previous_value`), `render_error` (an addon threw while the page rendered after the write; the change and its receipt stand), `verified` (and `verification_diff`), `elementor_save`, `stored_by`, `skipped_reason`, `sanitized` / `sanitized_paths` (only when Elementor's sanitising changed a value beyond entity encoding), `migrated_by_elementor`, `editor_autosave_pending` and `editor_autosave` (`{user, user_id, modified, note}`: an autosave by any user newer than the page), `css: {scope: "post", post_css, element_cache, atomic_styles}`, `save_check`, `change_receipt` (`elementor_atomic`). Dry runs: `would_apply`, `would_store`, `save_check`; nothing written, no caches touched.

---

## Menus and theme locations (1.17.0)

Both need `edit_theme_options` and honour `dry_run`. Refusals are JSON error text, `{error, message, ...}`.

**`create_menu`**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Must not be in use by another menu |
| `location` | string | No | Theme location slug from `get_menus`. A menu already there is replaced in that location and kept |
| `dry_run` | boolean | No | `{dry_run: true, action: "would_create", name, slug, location, would_replace_menu_id?, not_checked}` |

Returns `{menu_id, name, slug, location, replaced_menu_id?, change_receipt, cache}`, with the name and slug as stored. Elementor Pro's Nav Menu widget refers to a menu by this `slug`. Errors: `missing_name`, `unknown_menu_location` (with `locations`), `menu_exists` (with `menu_id`).

**`assign_menu_location`**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `location` | string | Yes | Theme location slug from `get_menus` |
| `menu_id` | integer | Yes | The menu, or 0 to clear the location. The menu that was there is kept |
| `dry_run` | boolean | No | `{dry_run: true, action: "would_assign"\|"would_clear"\|"no_change", location, before, after, not_checked}` |

Returns `{location, before: {menu_id}, after: {menu_id}, change_receipt, cache}`, with `menu_id` null for an empty location. The same menu again is `{action: "no_change", ...}` with no receipt. Errors: `unknown_menu_location`, `invalid_menu_id`, `not_found`.

**`get_menus`** adds `locations`: `[{slug, label, menu_id|null}]`, every location the theme registers.

**Rollback.**

| `target_type` | `field` | `before_value` / `after_value` | Rollback | Refused with |
|---|---|---|---|---|
| `menu` | `create` | `{location, menu_id, theme}`, or null with no location / `{menu_id, name, slug, location, theme}` | Deletes the menu. Gives the location back to the previous menu if this one still holds it | `rollback_conflict` if the menu has items, which deleting it deletes permanently, was renamed, or holds a location it was given later, which that change's rollback must put back first; `force` overrides. `rollback_unavailable` after a theme switch, if the change set a location |
| `menu_location` | the location slug | `{location, menu_id, theme}` | Restores the previous menu, or empties the location | `rollback_conflict` if the location changed since; `force` overrides. `rollback_unavailable` after a theme switch, or if the previous menu no longer exists |

`force` does not get past `rollback_unavailable`. WordPress keeps menu locations per theme.

---

## Theme Builder templates (1.17.0)

Both need `manage_options` and a credential tied to a WordPress user. Refusals are JSON error text, `{error, message, ...}`; a key with no owner gets `iato_mcp_token_unbound` as plain text. Checked against Elementor 4.3.6 and Elementor Pro 4.3.3.

**Conditions** are accepted in either form, and returned as strings: a rule, `{"type":"include","name":"general"}` or `{"type":"exclude","name":"singular","sub_name":"page","sub_id":42}`, or the stored string, `include/general` or `exclude/singular/page/42`. `unknown_condition` returns `registered` (each top-level name with the sub-names under it) and `takes_id`, read from what Pro registers on that site.

**`create_elementor_template`**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `title` | string | Yes | |
| `template_type` | string | Yes | `header`, `footer`, `single-post`, `single-page`, `archive`, `search-results`, `error-404`, `popup` (these need Elementor Pro), `section`, `container`, `page` |
| `status` | string | No | `draft` (default) or `publish`. A draft shows nowhere |
| `elementor_data` | string | No | Full Elementor JSON, stored through the same path as `update_elementor_data` |
| `conditions` | array | No | Not for `section`, `container` or `page` |
| `dry_run` | boolean | No | `{dry_run: true, action: "would_create", title, template_type, status, conditions, overlap_warnings?, warnings?, not_checked}` |

It is built as a draft, filled, given its conditions and published last. A failure on the way, including another plugin's hook throwing during the publish, trashes the template and returns `trashed_template_id` with the error. If it cannot be trashed the error says so: `template_id`, with `still_live: true` when it is published. A `header` or `footer` with an include condition and `status: publish` needs `elementor_data` (`empty_template`), because it would replace the theme's with nothing. `elementor_data` goes through the same atomic checks in a dry run and before the post exists.

Returns `{id, title, edit_url, template_type, status, conditions, conditions_revision, verified?, verified_reason?, winning_template_id?, may_win?, overlap_warnings?, overlap_note?, warnings?, change_receipt, cache}`, plus what `update_elementor_data` reports about the write (`current_revision`, `elementor_save`, `stored_by`, `sanitized_report`, ...) when `elementor_data` was given. Errors: `elementor_required`, `elementor_pro_required`, `unknown_template_type` (with `allowed`), `missing_title`, `invalid_status`, `empty_template`, `invalid_json`, `elementor_invalid_tree`, `unknown_condition`, `conditions_not_supported`, `template_create_failed`, `template_not_stored`, `conditions_not_saved`.

**`set_elementor_template_conditions`**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | An `elementor_library` post |
| `conditions` | array | Yes | They replace what is there. An empty list removes them |
| `if_revision` | string | No | The `conditions_revision` you read, from `list_elementor_templates` or an earlier write |
| `dry_run` | boolean | No | `{dry_run: true, action: "would_update"\|"no_change"\|"would_repair_cache", would_repair_cache?, id, location, before, after, conditions_revision, overlap_warnings?, warnings?, not_checked}` |

Returns `{id, location, before, after, conditions_revision, verified, verified_reason?, winning_template_id?, may_win?, overlap_warnings?, overlap_note?, warnings?, change_receipt, cache}`. The same conditions again are `{action: "no_change", ...}` with no receipt, unless Pro's cache no longer matches them: then they rebuild it, `{action: "cache_repaired", cache_repaired: true, ...}`, with no receipt and page caches purged. If Pro saves the conditions and its cache rebuild throws, the call still succeeds, with a receipt, `verified: false` and a warning. Order counts: Pro lets the last matching include set a template's priority. Errors: `elementor_pro_required`, `not_a_template`, `template_in_trash`, `conditions_not_supported`, `missing_conditions`, `unknown_condition`, `revision_conflict` (with `conditions_revision`), `conditions_not_saved`.

**`verified`** is false with a reason when the template is not doing what its conditions say:

| `verified_reason` | Meaning |
|---|---|
| `no_include_condition` | No include, so it shows nowhere |
| `not_published` | A draft shows nowhere, though Pro's cache holds it |
| `not_in_conditions_cache` | Pro's cache does not hold these conditions, so it will not render |
| `another_template_wins` | On Entire Site, another header or footer takes the home page: `winning_template_id` |
| `condition_cannot_render` | An `archive` condition on a template in the `single` location, or a `singular` one in the `archive` location: stored, with a warning, because other Pro versions may differ |
| `empty_template` | A published header or footer with no elements: stored, with a warning, because it replaces the theme's with nothing |
| `cache_not_regenerated` | The conditions were saved and Pro's cache rebuild failed. Send them again |
| `home_page_template_hides_it` | On Entire Site, and the home page uses Elementor Canvas |
| `another_template_may_win` | On Entire Site, and another template's condition that the plugin cannot judge may beat it on the home page: `may_win` |
| `not_on_home_page` | On Entire Site, and the home page gets no template from this location |

**`overlap_warnings`:** `[{template_id, title, condition, your_condition, relation: "same"|"wider"|"narrower"|"may_overlap"}]`, published templates in the same location that may show on some of the same pages. `wider`: theirs covers yours. `narrower`: yours covers theirs. Entire Site overlaps everything, and within a scope a condition overlaps its parent and any sibling that is not mutually exclusive: `singular/post` and `singular/in_category/5` may, `singular/page` and `singular/post` do not. Pro shows one per URL: on the pages both cover, the narrower wins, and on a tie the first in its cache, which can change. Popups do not compete.

**`warnings`:** strings, for what the caller should know that is no reason to refuse: a condition that can never render, an empty published header or footer, a published popup with no triggers, a cache rebuild that failed.

`list_elementor_templates` adds `conditions_revision` next to `conditions`.

**Rollback.** `target_type` `elementor_template`, `manage_options`.

| `field` | `before_value` / `after_value` | Rollback | Refused with |
|---|---|---|---|
| `create` | null / `{template_type, title, status, location, conditions, data_sha256, page_settings_sha256}` | Trashes the template and rebuilds Pro's cache | `rollback_conflict` if the template's post was modified, its `_elementor_data` or `_elementor_page_settings` differ from the recorded hashes, or its conditions changed since it was created, and always on a site with no trash (`EMPTY_TRASH_DAYS` 0), where it deletes for good. The message says when it is live and what rolling back removes. `force` overrides |
| `conditions` | the condition lists | Restores them through Pro | `rollback_conflict` if they changed since; `force` overrides. `rollback_unavailable` without Pro or with the template gone |

---

## Reports on every write (1.15.0)

**`cache`.** On every write tool's response; never on a dry run or a read. `{detected: string[], purged: true|false|null, scope: "post"|"all"|null, notes: string[], post_ids?: int[]}`. `detected` lists cache plugins found (`litespeed`, `wp-rocket`, `w3-total-cache`, `wp-super-cache`, `wp-fastest-cache`, `sg-optimizer`). `purged` is `true` when every detected plugin was asked to purge without an error, `false` when one failed or purging is off or filtered, `null` when none was found or nothing was written. `notes` names caches the plugin cannot reach; they never change `purged`. A call that wrote nothing (a no-op, an idempotency replay) has `purged: null, scope: null`. A tool error has no `cache`, but what was written before it is still purged. Elementor CSS: a write to one page clears and rebuilds that page's file (no global clear); a write to `elementor_library` content clears everything; when the per-page clear cannot be confirmed the whole site is cleared and `scope` is `all`.

**`sanitized_report`.** Next to `sanitized` and `sanitized_paths`, on real writes and dry runs:

```json
"sanitized": true,
"sanitized_paths": ["ab00069/settings/html"],
"sanitized_report": [
  {
    "path": "ab00069/settings/html",
    "rule": "html",
    "removed_tags": ["style", "svg", "path", "rect"],
    "removed_attributes": [],
    "inline_markup": "stripped_setting_off",
    "note": "The contents of removed <style> and <script> tags stay on the page as visible text; ..."
  }
]
```

| Field | Meaning |
|---|---|
| `path` | Where the value lives: `<element id>/settings/<path>`, a meta key path, or `title`, `content`, `excerpt`, `description` |
| `rule` | `html` (`wp_kses_post`), `url` (`esc_url_raw`), `css` (`sanitize_textarea_field`), `text` (`sanitize_text_field`). A `url` or `css` entry can have empty lists: the value changed without losing a tag |
| `removed_tags`, `removed_attributes` | Compared from input and output, lowercased. Attributes of a removed tag are not listed |
| `source` | `elementor` when Elementor's own save removed it, for a user without `unfiltered_html` |
| `inline_markup` | Only for `<style>` or `<svg>` in the `html` setting of a classic `html` widget. `kept`, `stripped_setting_off`, `stripped_no_capability`, `stripped_wp_version`, `stripped_unsafe` |
| `kept_tags`, `removed_css` | With `kept`: the tags kept, and what left the CSS (`@import`, off-site `url()`, or the whole block) |
| `note` | Plain language to pass on |

Capped at 50 entries, then `… and N more`. `sanitized_report` can come without `sanitized` when markup was kept and the value did not change. Kept markup can still report `sanitized: true` with no removals, because kses normalises it (`viewBox` to `viewbox`, which browsers read correctly). `inherited_sanitized_report` covers `inherit_settings_from`.

---

## Backwards Compatibility

- **v1 tools remain functional.** `update_elementor_data` and `get_elementor_data` keep their existing signatures and response shapes. No deprecation warnings on the wire (we'll surface them in the docs and call log only).
- **Capability discovery.** The MCP `initialize` response — currently in [`class-mcp-server.php:230-249`](../includes/class-mcp-server.php) — gains a `capabilities.elementor.v2: true` flag. Clients can use this to feature-detect.
- **Client migration guide.** Three-line examples for the typical use cases:

```
// v1: H1 flip on one page
get_elementor_data(id: 42)                       // ~30 KB read
update_elementor_data(id: 42, elementor_data: <30 KB modified JSON>)   // ~30 KB write

// v2: same H1 flip, one round trip
update_elementor_widget(id: 42, widget_id: "abc123",
                        settings_patch: { header_size: "h1" })          // ~200 B
```

```
// v1: H1 sweep across 13 pages
// 26 round trips, ~1.5 MB total payload, multi-turn

// v2: same sweep, one batch
update_elementor_widgets_bulk(updates: [
  { post_id: 42, widget_id: "abc123", settings_patch: { header_size: "h1" } },
  { post_id: 56, widget_id: "xyz789", settings_patch: { header_size: "h1" } },
  ... 11 more
])                                                                       // ~2 KB
```

```
// v1: surgical edit — find which widgets need fixing, then edit
// Step 1: download all 13 docs (~390 KB) just to inspect.

// v2: filter on the server, edit the matches
find_elementor_widgets(post_ids: [], filter: { type: "heading", setting: { header_size: { ne: "h1" } } })
// → 13 matches. Then bulk update.
```

---

## Phased Rollout

### v2.0 (M1, ~3 dev-days)

- Tier 1 endpoints: `list_elementor_widgets`, `get_elementor_widget`, `update_elementor_widget`.
- No optimistic concurrency yet — last-write-wins, same as v1.
- No idempotency — replay is the client's responsibility.
- This alone delivers ~80 % of the token-cost win.

**Done definition:** the H1-flip benchmark in [§7](#prove-it-benchmark) passes for a single page (1 round trip, <1 KB total wire).

### v2.1 (M2, ~5 dev-days)

- Tier 2 batch: `update_elementor_widgets_bulk`, `find_elementor_widgets`.
- Tier 3 diff response: `applied_patch` field on all v2 writes.
- Concurrency: `if_revision` on all v2 writes.
- Idempotency: `idempotency_key` on all v2 writes.

**Done definition:** the 13-page H1-flip benchmark passes (1 round trip, <2 KB request, <2 KB response, <5 s wall time).

### v2.2 (M3, ~5 dev-days)

- Tier 4 routing: `resolve_url`, `get_post.is_shadowed_by`.
- Tier 5 compact: `format: "compact" | "summary"` on `get_elementor_data`.
- Tier 6 helpers: `set_heading_level`, `set_widget_setting`.
- `update_elementor_patch` (full RFC 6902 escape hatch).

**Done definition:** the routing test (`/build/` resolves to template 309, not page 204) passes, and the compact-format response on a 30 KB doc is <8 KB.

---

## Regression Fixtures

Capture today's 13 Elementor docs as golden inputs in `tests/fixtures/elementor/`. Required cases:

1. **`/build/` URL shadowing** — `resolve_url("/build/")` returns Theme Builder template 309 with `route_type: "theme_builder"` and `shadowed_post_id: 204`. Verifies the rewrite-cascade walker honors Theme Builder conditions ahead of slug match.

2. **Gradient-stop preservation** — `update_elementor_widget` patching `eael_vto_writing_gradient_color_repeater[0].color` does not corrupt sibling repeater entries. (The post-19 incident from this session: the v1 round-trip silently re-ordered repeater IDs because the model regenerated the array index by index.) The v2 patch must address-by-index without touching siblings; verify the JSON Patch produces exactly one `replace` op.

3. **Stale-revision rejection** — read post 42, get `revision: "sha256:A"`. Manually change `_elementor_data` post-meta to update the hash to `sha256:B`. Issue `update_elementor_widget(..., if_revision: "sha256:A")` → expect `revision_conflict` with `current_revision: "sha256:B"`.

4. **Idempotency replay** — call `update_elementor_widget` with `idempotency_key: "k1"`. Within 60 s, call again with the same key and same payload → expect cached response with `idempotency_replay: true`. Then call with same key but different `settings_patch` → expect `idempotency_replay` error code.

5. **Widget-not-found** — `update_elementor_widget(id: 42, widget_id: "bogus")` returns `widget_not_found`, no partial write to the document. Confirm by reading post 42's `revision` before and after; should be unchanged.

6. **Auth-denied per-update in bulk** — submit `update_elementor_widgets_bulk` with one update targeting a post the current user can't edit. Expect that update reports `auth_denied` while siblings succeed.

7. **Bulk dry-run** — `update_elementor_widgets_bulk(dry_run: true)` returns `applied_patch` for all 13 updates without writing any of them. Verify by checking each post's `revision` post-call.

Tests run against a stock Elementor install on the regression CI; fixtures are committed JSON files exported from the live garennebigby.dev site at session capture.

---

## "Prove It" Benchmark

The 13-page H1 sweep that motivated this spec. Reproducing it with v2 must hit:

| Metric | v1 baseline | v2 target |
|---|---|---|
| Total wire payload | ~500 KB (request + response) | < 2 KB request, < 2 KB response |
| Wall-clock time | ~60 min (52 turns × 70 s/turn average) | < 5 s |
| MCP round trips | 52 (13 × 4: read, modify, write, verify) | 1 (`update_elementor_widgets_bulk`) |
| Conversation turns | ~52 | 1 |

The benchmark lives in `tests/bench/elementor-h1-sweep.php` (executed via wp-cli + the MCP endpoint) and runs as part of the CI gating for v2.1+ releases.

If v2.1 ships without hitting these numbers, the release is a no-go.

---

## Implementation References

Cited from the existing codebase at `/Users/garennebigby/Projects/mcp-wordpress`:

- **Adapter pattern model:** [`includes/class-seo-adapter.php`](../includes/class-seo-adapter.php) — design template for an `IATO_MCP_Elementor_Adapter` abstraction. The adapter encapsulates the JSON-tree walking, widget lookup, settings merge, and `Document::save()` call so each new Elementor tool stays small. Lives in `includes/class-elementor-adapter.php` (new file).

- **Change receipts:** [`includes/class-change-receipt.php`](../includes/class-change-receipt.php) — extend `record()` to accept a `metadata` array (or add `widget_id`, `path` columns; see [Open Questions](#open-questions)). Existing schema uses `target_type`, `field`, `before_value`, `after_value`; add `widget_id` as a new column.

- **Existing dry-run plumbing:** [`tool-page-builder.php:167-173`](../includes/tools/wp/tool-page-builder.php) — the v1 `dry_run` short-circuit. v2 tools follow the same shape.

- **Tool registration:** [`class-mcp-server.php:99-116`](../includes/class-mcp-server.php) — `register_tool()` API. The `is_tool_enabled()` gate lets users disable v2 tools individually from Settings > IATO MCP > Tools (new "Elementor v2" category in `class-settings.php` `TOOL_CATEGORIES`).

- **Document save / CSS regenerate flow:** [`tool-page-builder.php:183-247`](../includes/tools/wp/tool-page-builder.php) — reuse this verbatim. v2 tools build the modified Elementor JSON, then call into the same `update_post_meta('_elementor_data', ...)` → `Document::save(['elements' => $decoded])` → cache-bust pipeline. Don't re-implement.

- **Existing tool style to mirror:** [`tool-seo.php:15-126`](../includes/tools/wp/tool-seo.php) — clean field-level inputs, adapter delegation, change-receipt recording, dual-name aliases. v2 tools should look like this, not like the 200-line `update_elementor_data` from v1.

---

## Open Questions

These are flagged for resolution during M1 implementation; they do **not** block spec approval.

1. **Change receipt schema migration.** Add `widget_id`/`path` columns to `iato_change_receipts`, or use a flexible `metadata` JSON column? Pros for columns: queryable, future rollback can JOIN on `widget_id`. Pros for JSON: no migration cost. **Recommendation:** new columns, with a `dbDelta` migration in the activation hook.

2. **Theme Builder condition evaluation.** Conditions can be deeply nested (`Include: All Pages → Exclude: Single Page Y → Include: Pages with category Z`). Re-implementing Elementor's evaluator is brittle and will rot. **Recommendation:** call Elementor's internal `Conditions_Manager::get_documents_for_location()` if it's stable enough; otherwise document the limitation that we only handle the common cases (All Pages, Specific Pages, By Type/Tag/Category).

3. **Idempotency key namespace.** Should `idempotency_key` be scoped per-tool or global? **Recommendation:** per-(user, tool) — different tools can reuse the same key without collision, but two calls to the same tool with the same key must match exactly.

4. **Compact-format defaults table.** Should the per-widget defaults table be hand-curated, exported from Elementor's source, or generated at install time by introspecting Elementor's PHP classes? **Recommendation:** hand-curate the top ~20 widget types for v2.2 launch; expand as users report mismatches.

5. **Settings merge semantics for arrays.** When `settings_patch` includes an array key, should that replace the existing array, or merge? Elementor settings include both repeaters (where merge makes sense) and lists (where replace makes sense). **Recommendation:** v2.0 ships with replace-only semantics; introduce explicit merge ops via JSON Patch (`add` to a path) in v2.2 if user pain demands.

6. **Capability for `find_elementor_widgets` over many posts.** Reading all Elementor docs across the site could surface posts the current user can't see. **Recommendation:** filter post_ids through `current_user_can('read_post')` before walking trees; return a `permission_filtered: N` field in the response when results were trimmed.
