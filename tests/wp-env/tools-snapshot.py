#!/usr/bin/env python3
"""Before/after behaviour snapshot of the IATO MCP tools on the wp-env site.

    tests/wp-env/tools-snapshot.py --label before --bearer KEY --app-user admin --app-pw PW \
        --scratch-post P --scratch-elementor E --out DIR [--base http://localhost:8888]

Runs a fixed list of tool calls twice (Bearer, then Application Password),
each on its own scratch page so writes do not interfere. Every response is
normalised (volatile ids/hashes/dates replaced) and written to
DIR/<label>-<auth>.json so two labels can be diffed. Scratch pages are
created and deleted by the caller (tools-snapshot.sh) with WP-CLI.
"""
import argparse, base64, json, re, sys, urllib.request

VOLATILE_KEYS = {"revision", "previous_revision", "current_revision", "change_id", "change_ids",
                 "applied_at", "date", "date_gmt", "modified", "modified_gmt", "guid", "link",
                 "duration_ms", "req_id", "request_id", "generated_at", "idempotency_replay",
                 "post_content_length", "meta_length", "edit_lock", "_edit_lock", "_edit_last",
                 "timing", "phases", "elapsed_ms", "received_at"}

def call(base, headers, name, args):
    body = json.dumps({"jsonrpc": "2.0", "id": 1, "method": "tools/call",
                       "params": {"name": name, "arguments": args}}).encode()
    req = urllib.request.Request(base + "/wp-json/iato-mcp/v1/message", data=body,
                                 headers={**headers, "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            raw = json.load(r)
    except urllib.error.HTTPError as e:
        return {"http_error": e.code, "body": e.read().decode()[:300]}
    if "error" in raw:
        return {"rpc_error": raw["error"]}
    res = raw["result"]
    text = res["content"][0]["text"]
    if res.get("isError"):
        return {"tool_error": text}
    try:
        return {"ok": json.loads(text)}
    except json.JSONDecodeError:
        return {"ok_text": text}

def normalise(obj, scratch_ids):
    if isinstance(obj, dict):
        out = {}
        for k, v in obj.items():
            if k in VOLATILE_KEYS:
                out[k] = "<volatile>"
            else:
                out[k] = normalise(v, scratch_ids)
        return out
    if isinstance(obj, list):
        return [normalise(v, scratch_ids) for v in obj]
    if isinstance(obj, int) and obj in scratch_ids:
        return "<scratch-id>"
    if isinstance(obj, str):
        s = obj
        for sid in scratch_ids:
            s = re.sub(rf"(?<!\d){sid}(?!\d)", "<scratch-id>", s)
        s = re.sub(r"sha256:[0-9a-f]{64}", "sha256:<hash>", s)
        s = re.sub(r"\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\S*", "<datetime>", s)
        s = re.sub(r"iato-c0-snapshot-[a-z0-9-]+", "iato-c0-snapshot-<slug>", s)
        return s
    return obj

CREATED = []

def run(base, headers, P, E, label):
    receipts = []
    results = []
    def step(name, args, note=""):
        r = call(base, headers, name, args)
        if isinstance(r, dict) and "ok" in r and isinstance(r["ok"], dict):
            rec = r["ok"].get("change_receipt")
            if isinstance(rec, dict) and rec.get("change_id"):
                receipts.append(rec["change_id"])
            for rec in r["ok"].get("change_receipts") or []:
                if isinstance(rec, dict) and rec.get("change_id"):
                    receipts.append(rec["change_id"])
        results.append({"tool": name, "args": args, "note": note, "result": r})
        return r

    # --- reads on the fixture pages (6 classic, 8 atomic, 10 mixed) ---
    step("get_site_info", {})
    step("get_site_settings", {})
    step("get_posts", {"post_type": "page", "per_page": 3, "search": "IATO fixture"})
    step("get_post", {"id": 8})
    step("get_post", {"id": 10, "include_shadowing": True})
    step("search_posts", {"query": "fixture"})
    step("get_page_builder", {"id": 10})
    step("get_elementor_data", {"id": 8, "format": "summary"})
    step("get_elementor_data", {"id": 6, "format": "compact"})
    step("list_elementor_widgets", {"id": 10})
    step("get_elementor_widget", {"id": 8, "widget_id": "a7e4d06"})
    step("find_elementor_widgets", {"post_ids": [6, 8, 10], "filter": {"type": "e-heading"}})
    step("get_media", {"per_page": 3})
    step("get_menus", {})
    step("get_terms", {"taxonomy": "category"})
    step("get_seo_data", {"id": 8})
    step("get_comments", {"post_id": 8})
    step("resolve_url", {"url": "/iato-fixture-mixed/"})
    step("list_elementor_templates", {})
    step("get_post_meta", {"id": 8})
    step("list_iato_crawls", {"limit": 2})

    # --- authorship: a page created by the request itself ---
    created = step("create_post", {"title": "iato-c0-snapshot-created-" + label, "content": "<p>created <em>by</em> the request</p><script>alert(2)</script>", "status": "draft", "post_type": "page"}, "author of a post created by this request")
    if "ok" in created and isinstance(created["ok"].get("id"), int):
        cid = created["ok"]["id"]; CREATED.append(cid)
        step("get_post", {"id": cid}, "created post: author + stored content")

    # --- writes on the scratch page P (plain) ---
    step("update_post", {"id": P, "title": "Snapshot title <b>bold</b>",
                         "content": "<p>Updated <em>content</em></p><script>alert(1)</script><iframe src=\"https://example.com\"></iframe>",
                         "status": "publish"}, "kses evidence: script/iframe")
    step("get_post", {"id": P}, "author + stored content after update")
    step("update_post", {"id": P, "excerpt": "Snapshot excerpt <strong>x</strong>"})
    step("update_seo_data", {"id": P, "title": "SEO title", "description": "SEO description"})
    step("get_seo_data", {"id": P})
    step("update_canonical", {"post_id": P, "canonical_url": "https://example.com/canonical"})
    step("update_structured_data", {"post_id": P, "schema_json": "{\"@context\":\"https://schema.org\",\"@type\":\"Article\",\"name\":\"Snap \\\"quoted\\\"\"}"})
    step("update_post_meta", {"id": P, "key": "iato_snapshot_meta", "value": "v1"})
    step("get_post_meta", {"id": P})
    step("set_page_settings", {"id": P, "settings": {"hide_title": True}})
    step("set_featured_image", {"id": P, "attachment_id": 5})
    step("update_alt_text", {"attachment_id": 5, "alt_text": "Snapshot alt " + label})
    step("get_media", {"per_page": 1, "search": "iato-fixture-image"}, "alt after update")

    # --- writes on the scratch Elementor page E (copy of the mixed fixture) ---
    step("update_elementor_widget", {"id": E, "widget_id": "c1a5501", "settings_patch": {"title": "Snapshot classic heading"}, "dry_run": True})
    step("update_elementor_widget", {"id": E, "widget_id": "c1a5501", "settings_patch": {"title": "Snapshot classic heading"}})
    step("set_heading_level", {"id": E, "widget_id": "c1a5501", "level": "h3"})
    step("set_widget_setting", {"id": E, "widget_id": "c1a5504", "key": "text", "value": "Snapshot button"})
    step("update_elementor_widget", {"id": E, "widget_id": "a7e4d01", "settings_patch": {"title": {"$$type": "escaped-html", "value": "Snapshot atomic heading"}}}, "atomic node via raw envelope")
    step("update_elementor_patch", {"id": E, "widget_id": "c1a5502", "ops": [{"op": "replace", "path": "/0/elements/1/settings/editor", "value": "<p>Snapshot <em>editor</em></p><script>x</script>"}]})
    step("update_elementor_widgets_bulk", {"updates": [{"id": E, "widget_id": "c1a5501", "settings_patch": {"title": "Bulk"}}], "dry_run": True})
    raw = step("get_elementor_data", {"id": E, "format": "raw"}, "raw after widget writes")
    if "ok" in raw and isinstance(raw["ok"].get("elementor_data"), str):
        tweaked = raw["ok"]["elementor_data"].replace("Snapshot button", "Full-doc button")
        step("update_elementor_data", {"id": E, "elementor_data": tweaked})
    step("get_elementor_data", {"id": E, "format": "summary"}, "summary after full-doc write")
    step("list_elementor_widgets", {"id": E})
    step("get_post", {"id": E}, "post_content after Elementor writes")

    # --- rollback every receipt collected, newest first ---
    for cid in reversed(receipts):
        step("rollback", {"change_id": cid})
    step("get_media", {"per_page": 1, "search": "iato-fixture-image"}, "alt after rollback")
    return results

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--label", required=True); ap.add_argument("--bearer", required=True)
    ap.add_argument("--app-user", required=True); ap.add_argument("--app-pw", required=True)
    ap.add_argument("--scratch-post", type=int, nargs=2, required=True, help="P for bearer run, P for app-pw run")
    ap.add_argument("--scratch-elementor", type=int, nargs=2, required=True)
    ap.add_argument("--out", required=True); ap.add_argument("--base", default="http://localhost:8888")
    a = ap.parse_args()
    auths = {
        "bearer": {"Authorization": "Bearer " + a.bearer},
        "apppw": {"Authorization": "Basic " + base64.b64encode(f"{a.app_user}:{a.app_pw}".encode()).decode()},
    }
    for i, (auth, headers) in enumerate(auths.items()):
        P, E = a.scratch_post[i], a.scratch_elementor[i]
        results = run(a.base, headers, P, E, a.label)
        norm = normalise(results, {P, E, *CREATED})
        path = f"{a.out}/{a.label}-{auth}.json"
        with open(path, "w") as f:
            json.dump(norm, f, indent=1, sort_keys=True, ensure_ascii=False)
        errs = sum(1 for r in results if "ok" not in r["result"])
        print(f"{a.label}/{auth}: {len(results)} calls, {errs} non-ok, -> {path}")
    with open(f"{a.out}/{a.label}-created-ids.txt", "w") as f:
        f.write(" ".join(str(i) for i in CREATED))

if __name__ == "__main__":
    main()
