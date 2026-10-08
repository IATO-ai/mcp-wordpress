# Sprint C0 handover, Part 2: "bridge tools saved as off"

Branch `feat/settings-tools-fix`, staged and uncommitted, based on the Sprint B commit (`7bc4592`) so it can be committed independently of Part 1 (`feat/token-user-binding`). The two parts touch different regions of `includes/class-settings.php`, `iato-mcp.php` and `uninstall.php`; Part 2's patch was applied on top of Part 1's working tree for the live checks and applied and reverted cleanly (78 tests green on the combined tree). Part 1's handover: `docs/sprint-c0-handover.md`.

## Root cause (corrects the Sprint B handover)
The Tools card renders every tool from `TOOL_CATEGORIES`; while no IATO API key is set, the two IATO categories are rendered with **`disabled` checkboxes** (`includes/class-settings.php`, Tools card loop). Browsers never submit disabled inputs, so both save paths (`ajax_save_settings()` and `options.php` through `sanitize_tools()`) stored `iato_mcp_tools` without the twelve bridge tools. The migration backfill is version-gated and could not repair a later save. "Test connection" saves the key by AJAX without re-enabling the checkboxes, so testing and saving in one page view lost them too.

## What was built
| Path | Change |
|---|---|
| `includes/tools-selection.php` | New, pure functions: `iato_mcp_bridge_tool_names()`, `iato_mcp_tool_was_enabled()`, `iato_mcp_merge_gated_tools( $posted, $previous, $bridge_gated )`, `iato_mcp_repair_bridge_tools( $saved )`. |
| `includes/class-settings.php` | Tools card emits a hidden `iato_mcp_tools[]` input for each gated bridge tool that is currently on; `ajax_save_settings()` reads the API key **before** saving anything and merges gated tools with their previous state; `sanitize_tools()` (options.php path) merges using the current key option (`$merge_gated` flag so the AJAX path does not merge twice); duplicate `id="iato-platform-cat-hint"` replaced by a class and the Test-connection JS updates all hints; media URL toggle stored as `'1'`/`'0'` via `sanitize_bool_flag()` (register_setting type string, AJAX line, render read). |
| `iato-mcp.php` | Requires the new file; one-shot repair at the end of `iato_mcp_maybe_run_migrations()` guarded by the flag option `iato_mcp_bridge_tools_repaired`. |
| `uninstall.php` | Deletes the flag. |
| `CLAUDE.md` | Settings section updated. |
| `tests/SettingsToolsTest.php`, `tests/wp-env/tools-settings-save.php` | Unit tests; live admin-ajax driver. |

### Rules
- **Gated save** (no key when the form was rendered): every bridge tool absent from the POST keeps its previous state (on if the option was empty / all-on or listed it; off if it was off). The hidden inputs make the browser post the "on" ones anyway; the merge covers both paths defensively.
- **Ungated save** (key present): the checkboxes are live, so the POST is the truth; an unchecked bridge tool is a deliberate choice and stays off, including across later gated saves.
- **Repair of existing sites**: a non-empty saved list that contains **none** of the twelve bridge tools is treated as the bug and gets all twelve; a list with at least one bridge tool is a deliberate selection and is left alone; an empty list already means all on. Runs once per site (flag), idempotent. The two cases cannot be told apart from the data; a site that deliberately switched off all twelve will see them on again once, after the upgrade, on the Settings page. Mention it in the release notes.

## Verified
- **Unit tests** (`tests/SettingsToolsTest.php`, 12 tests): fresh install = all on; settings saved before key keeps the bridge tools on; key added then saved lets the form govern (one unchecked bridge tool stays off); a deliberate off survives a later gated save; a gated save never resurrects an off tool; de-duplication; posted hidden inputs honoured; repair adds all twelve only when none are present, leaves deliberate and empty lists alone, idempotent. Combined tree: 78 tests, 631 assertions.
- **Live (wp-env, real `ajax_save_settings()` with the exact form field set):**
  - S1 fresh install, key blank, browser posts only the WP tools → 58 tools stored, all twelve bridge tools on.
  - S2 key set, user unchecks `get_iato_seo_fixes` → `tools/list` shows 11 bridge tools, 10 `iato/` abilities.
  - S3 key blanked, gated save → still 11 / 10: the deliberate off survived, the other eleven were preserved.
  - S4 options.php path: `sanitize_tools()` with the key blank keeps the twelve; with the key set and the bridge tools unposted, drops them (form governs).
  - S5 repair: broken list → twelve added, flag set; list with one bridge tool → untouched; empty option → untouched.
  - S6 media toggle: saved `'1'` then `'0'`; row exists with `'0'`; the uploader's `get_option()` check reads it as off.
  - Site restored afterwards (key back, `iato_mcp_tools` cleared = all on, flag set); `tools/list` 12 bridge, 11 abilities.

## Not verified
- The hidden inputs in a real browser (the AJAX driver posts the exact field set a browser would, including the hidden inputs' effect, but no browser was driven).
- The Setup Wizard path (it never touched `iato_mcp_tools` and still does not).

## Paths to review / add (Part 2)
```
CLAUDE.md
docs/sprint-c0-part2-handover.md
iato-mcp.php
includes/class-settings.php
includes/tools-selection.php
tests/SettingsToolsTest.php
tests/wp-env/tools-settings-save.php
uninstall.php
```
