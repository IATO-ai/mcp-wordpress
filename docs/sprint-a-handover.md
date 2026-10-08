# Sprint A handover: read atomic (Elementor V4) pages

Branch `feat/atomic-read` (off `feat/implement-all-tools`), changes staged and uncommitted. Date: 2026-10-07.
Elementor source references below are paths inside Elementor 4.3.4 (`~/Projects/iato-sprint/elementor/`).

## What was built

**Approach chosen: parse `_elementor_data` directly** (brief option 1), no call into Elementor's `get-page-structure` ability. Reasons: it works on Elementor 4.0–4.2, with Elementor deactivated, and on WordPress below 6.9; the ability only exists when the Abilities API and bundled adapter load (`modules/mcp/module.php:124-128`) and only registers when the `elementor_mcp_enabled` option is on, which is off by default (`vendor/elementor/elementor-mcp-composer/src/Admin/McpSettingsController.php:131-139`); abilities check the current user, and Bearer requests run as user 0 (below); and a pure array walk needs no `Throwable` guard and no per-element round trips.

New and changed files:

| Path | Change |
|---|---|
| `includes/class-elementor-atomic.php` | New `IATO_MCP_Elementor_Atomic`: detection, envelope unwrapping, default filling, tag computation, normalised peek view, injectable WP resolver. |
| `includes/data/elementor-atomic-defaults.php` | New. Per-type schema defaults Elementor does not persist (cited to each `define_props_schema()`). |
| `includes/class-elementor-adapter.php` | `peek_fields()` delegates atomic nodes to the new class; `schema: classic\|atomic` added to flat, tree, summary and find_by_filter nodes; `matches_filter()` evaluates atomic nodes against unwrapped settings plus derived keys. |
| `includes/tools/wp/tool-elementor-widgets.php` | `get_elementor_widget` adds `schema`, and for atomic nodes `tag`, `settings_plain`, `defaulted_keys`, `peek` (raw `settings` kept). Descriptions updated. |
| `includes/tools/wp/tool-page-builder.php` | `get_page_builder` adds `elementor_schema: classic\|atomic\|mixed\|empty`. Descriptions updated. |
| `includes/tools/wp/tool-elementor-bulk.php` | Description only (atomic matching documented). |
| `includes/class-mcp-server.php` | `initialize` advertises `capabilities.elementor.atomic_read: true`. |
| `iato-mcp.php` | `require_once` for the new class. |
| `CLAUDE.md` | Architecture section for the reader and the test setup. |
| `tests/`, `composer.json`, `phpunit.xml.dist`, `.wp-env.json`, `.distignore`, `.gitignore` | Test suite, fixtures, wp-env config, dev tooling excluded from the WP.org ZIP. |

No new MCP tools (so no `TOOL_NAMES` / backfill edits), no write-tool changes, no version bump.

### Output contract

Every node from `list_elementor_widgets`, `get_elementor_data` (summary), `find_elementor_widgets` and `get_elementor_widget` now carries `schema`. Atomic nodes additionally carry `tag` (rendered HTML tag) and peek fields that reuse the classic key names:

| Atomic element | Keys |
|---|---|
| `e-heading` | `title`, `header_size` (from `tag`, default `h2`) |
| `e-paragraph` | `editor` (HTML, truncated to 80 chars like classic peek) |
| `e-button` | `text`, `link`, `link_new_tab` |
| `e-image` | `image_url`, `image_id`, `image_alt`, `image_alt_source` (`attachment` for Media Library images, `element` for URL images) |
| any with a link | `link`, `link_new_tab`, `link_post_id` (post-reference links resolve to the permalink) |
| containers (`e-flexbox`, `e-div-block`, `e-grid`, …) | `tag` (follows the link tag when linked, else the `tag` setting, else `div`) |
| unknown `e-*` | `schema: atomic`, `type`, `tag` if a `tag` setting exists, `title`/`text`/`editor` if those keys exist; `get_elementor_widget` returns raw `settings` and `settings_plain` |

Dynamic-tag bound text is reported as `<field>_dynamic: <tag name>` instead of a text value.

### Facts confirmed in Elementor 4.3.4 that the reader relies on

- Atomic detection in Elementor is class-based (`modules/atomic-widgets/utils/utils.php:15-18`). Stored atomic nodes carry `version` as whatever was loaded, default `"0.0"` (`elements/base/atomic-widget-base.php:28`, `has-atomic-base.php:247-250`); the fixtures confirm `"version": "0.0"` on every atomic node and no `version` on classic nodes. **The reader uses the `e-` type prefix**, which every registered atomic type has (`modules/atomic-widgets/module.php:391-451`, `modules/components/widgets/component-instance.php:20`), with "any top-level setting is a `$$type` envelope" as a fallback.
- Envelope shape `{"$$type", "value", "disabled"?}` (`prop-types/concerns/has-generate.php:12-22`).
- Schemas: `e-heading` `tag`/`title`/`link` (`elements/atomic-heading/atomic-heading.php:59-78`), `e-paragraph` `paragraph`/`tag` (`atomic-paragraph.php:60-79`), `e-button` `text`/`link`/`tag` (`atomic-button.php:52-72`), `e-image` `image{src{id,url,alt},size}` (`atomic-image.php:58-73`, `prop-types/image-src-prop-type.php:17-24`), `link{destination: url|query, isTargetBlank, tag}` (`prop-types/link-prop-type.php:38-48`). There is no `label` on `link`; a label exists only inside a `query` destination.
- Media Library images take alt from `_wp_attachment_image_alt`; URL images use the stored `src.alt` (`props-resolver/transformers/image-transformer.php:25-46`).
- Unsaved defaults are dropped on save (`parsers/props-parser.php:49-51, 70-72`), so a missing `tag` means the schema default. Confirmed in the fixtures: the heading saved without `tag` has no `tag` key; empty containers have no `settings` key at all.
- Legacy text envelopes (`string`, `html`, `html-v2`, `html-v3`) can still be present because migrations only run on editor load (`migrations/manifest.json:11-40`, `prop-type-migrations/migrations-orchestrator.php:39-41`).
- Rendered tag rules (`elements/base/html-tag-computer.php:15-29`): heading, paragraph and image do not follow their link; button and containers do.
- Elementor accepts a classic widget nested inside an atomic container through `Document::save()` (the mixed fixture has a classic `button` inside an `e-flexbox`).

## What was verified and how

- **Unit tests**: `vendor/bin/phpunit` → 32 tests, 213 assertions, green (PHP 8.5.3, PHPUnit 12). Covers detection, every envelope type, legacy text shapes, `disabled`, dynamic tags, post-reference links, attachment and element alt, missing attachment, unknown types, depth cap, default filling, tag computation, and read-layer snapshots for all four fixtures.
- **Classic output unchanged**: `tests/fixtures/elementor/classic-only.pre-sprint-a.expected.json` was generated with the adapter from `feat/implement-all-tools` before any change; the test asserts current output equals it once the additive `schema` key is removed, and that classic nodes gain no other key.
- **Fixtures are genuine**: captured with `wp post meta get <id> _elementor_data` from a wp-env site (WordPress 7.1.3, Elementor 4.3.4, experiments `e_atomic_elements`, `e_opt_in_v4`, `container` active) after building the pages through `Document::save()` as admin (`tests/wp-env/create-fixture-pages.php`), so the stored JSON went through Elementor's `Props_Parser`.
- **Live endpoint, Bearer auth** (`tests/wp-env/read-tools-smoke.sh`): `initialize`, `get_page_builder` (classic / atomic / mixed), `list_elementor_widgets`, `get_elementor_widget` (atomic image and button), `get_elementor_data` summary, and two `find_elementor_widgets` queries (`title contains` across schemas; `type: e-image` + `image_alt contains`) all returned the expected content: heading text and level, paragraph HTML, library image URL/ID/alt from the attachment, external image alt from the element, button text/URL/new-tab, containers, in document order.
- **Elementor deactivated**: same smoke run with `wp plugin deactivate elementor` produced byte-identical tool output (only `initialize` drops the `elementor` capability block). No errors.
- **No PHP notices**: `WP_DEBUG` + `WP_DEBUG_LOG` were on for every run; no `debug.log` was created.
- `php -l` clean on every changed file.

## What was not verified

- **Elementor 3.x and 4.0–4.2 sites** (brief test sites 1 and 2) were not built. Coverage comes from the all-classic fixture and from the fact that no new code path runs unless an `e-` node or a `$$type` envelope is present. An Elementor 3.x install should be checked once before release.
- **Elementor Pro atomic elements** (`e-form`, `e-form-*`, `e-collection-loop`) and `e-component`, `e-tabs`, `e-accordion`, `e-list`, `e-svg`, video elements: handled by the generic path (type, tag, raw and plain settings) but not fixture-tested. Their text lives in child `e-paragraph` / `e-heading` widgets, which the reader does handle.
- **Dynamic tags** are reported, not resolved (no call into Elementor's tag registry).
- **Rendering in the editor** was not visually checked; fixtures were produced by the save path, not by the editor UI.
- **`format=compact`** on `get_elementor_data` passes atomic nodes through unchanged (atomic defaults are never stored, so there is nothing to strip). Not changed, not tested further.
- The Application Password path was exercised only for `get_page_builder`.

## Bearer-authenticated MCP requests run as WordPress user 0 (for Sprint C)

Confirmed in code and on the live site.

- `IATO_MCP_Auth::authenticate()` compares the Bearer value against the site-wide `iato_mcp_key` option and sets only a private static flag (`includes/class-auth.php:91-103`). It never calls `wp_set_current_user()`; the plugin registers no `determine_current_user` or `rest_authentication_errors` filter.
- The OAuth flow hands out that same site-wide key as the authorization code and the access token and stores no user (`includes/class-oauth.php:185-187`, `:486-489`), so OAuth-connected clients (Claude Desktop) are also user 0.
- `IATO_MCP_Auth::require_cap()` returns true for any capability when no user is stored (`includes/class-auth.php:172`), which is how write tools pass under Bearer today. This is documented as Known Issue KI-1 (`class-auth.php:9-12`, `:152-156`).
- Application Password (Basic) requests do run as the real user via WordPress core (`class-auth.php:114-120`).
- Live check: after the smoke run, `IATO_MCP_Call_Log::get_recent()` shows `auth_user_id: 0` for every Bearer call, and `auth_user_id: 1` for a `get_page_builder` call made with an Application Password for `admin`.

Why it matters for Sprint C: Elementor's abilities run `permission_callback` against the current user, and `Document::save()` returns `false` before writing when `is_editable_by_current_user()` fails (`core/base/document.php:812-908`, `:683-690`; `includes/user.php:100-135`), which it does for user 0. So delegating atomic fixes to `elementor/manage-elements` from a Bearer-authenticated request will be refused. Existing workarounds in the codebase that skip `current_user_can` under Bearer (`tool-media-upload.php:48-66`, `tool-elementor-bulk.php:98-102, 235-238`) will not help because the check happens inside Elementor. Options for Sprint C: bind OAuth tokens to the authorising WP user and call `wp_set_current_user()` in the permission callback; or require Application Password auth for atomic writes and return a clear error otherwise. Also note the write pipeline already calls `$document->save()` and ignores its return value (`includes/class-elementor-adapter.php:836-838`), so under Bearer it silently falls back to the direct meta write.

## Open questions

1. `schema` is added to classic nodes too (the brief asks for a marker on each element). If strict byte-for-byte parity for classic pages is required, it could be emitted only on atomic nodes; the golden test would then be exact.
2. `get_page_builder` now decodes `_elementor_data` to classify the document. Cost is one `json_decode` per call; acceptable, but it can be gated behind a parameter if the hot path matters.
3. Should `format=compact` unwrap atomic settings? Left unchanged on purpose: compact is "strip defaults", and raw envelopes are what Sprint C writes will need.
4. For a `disabled: true` text value the reader reports the schema default, which is what Elementor renders (`render-props-resolver.php:90-104`). An audit might prefer to see "disabled" explicitly; `plain_settings` can expose that if wanted.
5. Dynamic-tag resolution (calling Elementor's tag registry) was deliberately left out to keep the reader free of Elementor calls.

## Local test site

`.wp-env.json` (committed) + `.wp-env.override.json` (gitignored, maps `~/Projects/iato-sprint/elementor` as a second plugin). Start with `npx @wordpress/env start`; site at http://localhost:8888 (admin / password). Rebuild fixture pages with `wp eval-file wp-content/plugins/mcp-wordpress/tests/wp-env/create-fixture-pages.php --user=admin` via `npx @wordpress/env run cli`. Fixture page IDs on this instance: classic 6, atomic 8, mixed 10; attachment 5.

## Paths to review / add

```
.distignore
.gitignore
.wp-env.json
CLAUDE.md
composer.json
docs/sprint-a-handover.md
iato-mcp.php
includes/class-elementor-adapter.php
includes/class-elementor-atomic.php
includes/class-mcp-server.php
includes/data/elementor-atomic-defaults.php
includes/tools/wp/tool-elementor-bulk.php
includes/tools/wp/tool-elementor-widgets.php
includes/tools/wp/tool-page-builder.php
phpunit.xml.dist
tests/
```
`composer.lock` is intentionally not added (dev-only; regenerate locally). `vendor/` and `.wp-env.override.json` are gitignored.
