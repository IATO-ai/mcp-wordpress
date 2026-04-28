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

All errors include `error_message` (string) and `error_data` (object). On a 409 `revision_conflict`, `error_data.current_revision` is included so the client can re-sync without an extra read.

### Auth

- **Read tools** (`list_elementor_widgets`, `get_elementor_widget`, `find_elementor_widgets`, `resolve_url`): no capability check beyond the standard MCP authentication. Mirrors `tool-seo.php:24` (`get_seo_data`).
- **Write tools** (`update_elementor_widget`, `update_elementor_patch`, `update_elementor_widgets_bulk`, `set_heading_level`, `set_widget_setting`): `current_user_can('edit_posts')`. Mirrors `tool-seo.php:64` (`update_seo_data`).
- **Capability check is per-post**: the bulk update walks each post and skips ones the current user can't edit, returning per-update `auth_denied` in the response rather than failing the whole call.

### Change receipts

Extend `IATO_MCP_Change_Receipt::record()` ([`includes/class-change-receipt.php`](../includes/class-change-receipt.php)) for widget-level entries. New columns or a JSON `metadata` column (TBD — see [Open Questions](#open-questions)) capture:

- `widget_id` — the targeted Elementor widget
- `path` — JSON Pointer string within the widget settings (e.g. `/settings/header_size`)
- `before` / `after` — the values at that path

The widget-level rollback endpoint (future, not in v2.0–2.2 scope) reverses by applying the inverse patch. For now, the existing `/wp-json/iato-mcp/v1/rollback` continues to operate on whole-document receipts created by v1 writes; widget-level receipts are recorded but only surfaced in the call log, not yet rolled back individually.

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
  "post_content_length": 17892
}
```

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

**Response:** same shape as `update_elementor_widget`, but `applied_patch` reflects the full set of ops applied.

**Errors:** as `update_elementor_widget`, plus `invalid_patch_path` if any op references a path that doesn't exist post-patch.

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

`route_type` is one of: `page`, `category_archive`, `tag_archive`, `cpt_archive`, `theme_builder`, `404`.

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

Equivalent to `update_elementor_widget(id, widget_id, { "header_size": "<level>" }, ...)` but the model can express the intent in one parameter. Also validates that the target widget is actually a heading widget (returns `schema_violation` otherwise).

#### `set_widget_setting`

```
set_widget_setting(id, widget_id, key: string, value: any,
                   dry_run?, if_revision?, idempotency_key?)
```

Equivalent to `update_elementor_widget(id, widget_id, { "<key>": <value> }, ...)`. Slightly more discoverable than the patch form for one-key changes.

These helpers are a **thin layer** — they exist to make the model's intent legible in the call log and to give the client a clean affordance for the 80 % case. They MUST NOT diverge from `update_elementor_widget`'s behavior; they call through it.

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
