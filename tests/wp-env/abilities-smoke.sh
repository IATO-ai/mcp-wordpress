#!/bin/zsh
# Live check of the iato/* WordPress Abilities against the wp-env site.
#
#   tests/wp-env/abilities-smoke.sh <wp-user> <app-password> <iato-bearer-key> [base-url]
#
# For every ability it runs the same arguments through three paths and
# normalises each result to {"ok": payload} or {"error": "<message>"}:
#   A. core REST   GET  /wp-json/wp-abilities/v1/abilities/iato/<slug>/run?input=...
#   B. MCP Adapter tools/call mcp-adapter-execute-ability on the default server
#   C. IATO MCP    tools/call <tool> on /wp-json/iato-mcp/v1/message (Bearer)
# and reports PARITY when A == B == C. No crawl is ever started.
set -u
WPUSER="$1"; APPPW="$2"; BEARER="$3"; BASE="${4:-http://localhost:8888}"
ABIL="$BASE/wp-json/wp-abilities/v1"
ADAPTER="$BASE/wp-json/mcp/mcp-adapter-default-server"
IATO="$BASE/wp-json/iato-mcp/v1/message"
fail=0
typeset -A TOOL
TOOL=(get-broken-links get_iato_broken_links get-orphan-pages get_iato_orphan_pages get-content-gaps get_iato_content_gaps get-nav-audit get_iato_nav_audit get-seo-fixes get_iato_seo_fixes get-suggestions get_iato_suggestions get-perf-report get_iato_perf_report get-sitemap get_iato_sitemap get-taxonomy get_iato_taxonomy get-crawl-status get_iato_crawl_status list-crawls list_iato_crawls)

# IATO stamps `generated_at` per response (get-suggestions), so three
# back-to-back calls legitimately differ by a second. Strip it before comparing.
STRIP='
def strip(o):
    if isinstance(o,dict): return {k:strip(v) for k,v in o.items() if k!="generated_at"}
    if isinstance(o,list): return [strip(v) for v in o]
    return o
'
norm_rest() { python3 -I -c "$STRIP"'
import json,sys
r=strip(json.load(sys.stdin))
if isinstance(r,dict) and "code" in r and "message" in r and set(r.keys())<= {"code","message","data"}: print(json.dumps({"error": r["message"]}, sort_keys=True))
else: print(json.dumps({"ok": r}, sort_keys=True))'; }
norm_adapter() { python3 -I -c "$STRIP"'
import json,sys
r=json.load(sys.stdin)
if "error" in r: print(json.dumps({"error": r["error"].get("message")}, sort_keys=True)); sys.exit()
res=r["result"]; txt=res["content"][0]["text"]
if res.get("isError"): print(json.dumps({"error": txt}, sort_keys=True)); sys.exit()
d=strip(json.loads(txt))
# execute-ability wraps WP_Error as {success:false,error:msg} and results as {success:true,data:...}
if isinstance(d,dict) and d.get("success") is False and "error" in d: print(json.dumps({"error": d["error"]}, sort_keys=True))
elif isinstance(d,dict) and d.get("success") is True and "data" in d: print(json.dumps({"ok": d["data"]}, sort_keys=True))
else: print(json.dumps({"ok": d}, sort_keys=True))'; }
norm_iato() { python3 -I -c "$STRIP"'
import json,sys
r=json.load(sys.stdin)
if "error" in r: print(json.dumps({"error": r["error"].get("message")}, sort_keys=True)); sys.exit()
res=r["result"]; txt=res["content"][0]["text"]
if res.get("isError"): print(json.dumps({"error": txt}, sort_keys=True))
else: print(json.dumps({"ok": strip(json.loads(txt))}, sort_keys=True))'; }

echo "### adapter session"
SID=$(curl -s -D - -o /dev/null -u "$WPUSER:$APPPW" -H 'Content-Type: application/json' \
  --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"iato-smoke","version":"1"}}}' "$ADAPTER" \
  | awk -F': ' 'tolower($1)=="mcp-session-id"{print $2}' | tr -d '\r')
echo "session: ${SID:0:8}..."
adapter_call() { curl -s -u "$WPUSER:$APPPW" -H 'Content-Type: application/json' -H 'MCP-Protocol-Version: 2025-06-18' -H "Mcp-Session-Id: $SID" --data "$1" "$ADAPTER"; }

echo "### discover-abilities (iato/ entries)"
adapter_call '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}' \
  | python3 -I -c 'import json,sys; r=json.load(sys.stdin); d=json.loads(r["result"]["content"][0]["text"]); names=sorted(a["name"] for a in d["abilities"] if a["name"].startswith("iato/")); print(len(names), names)'

echo "### core REST list ?namespace=iato"
curl -s -u "$WPUSER:$APPPW" "$ABIL/abilities?namespace=iato&per_page=100" | python3 -I -c 'import json,sys; d=json.load(sys.stdin); print(len(d), sorted(a["name"] for a in d)); a=[x for x in d if x["name"]=="iato/get-sitemap"][0]; print("sitemap props:", sorted(a["input_schema"].get("properties",{}).keys()), "meta:", a["meta"])'

for ab in iato/get-seo-fixes iato/get-suggestions; do
  echo "### get-ability-info $ab meta"
  adapter_call "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-get-ability-info\",\"arguments\":{\"ability_name\":\"$ab\"}}}" \
    | python3 -I -c 'import json,sys; r=json.load(sys.stdin); d=json.loads(r["result"]["content"][0]["text"]); print(json.dumps(d.get("meta")))'
done

run_case() { # slug, json-args, label
  local slug="$1" args="$2" label="$3" tool="${TOOL[$1]}"
  # Core's run route reads GET input from the ?input query param as a PHP
  # array (input[key]=value); an empty object is "no input" (schema default).
  local q; q=$(python3 -I -c 'import json,sys,urllib.parse; d=json.loads(sys.argv[1]); print(urllib.parse.urlencode({f"input[{k}]": v for k,v in d.items()}))' "$args")
  local a b c
  if [[ "$slug" == "get-suggestions" ]]; then
    # Not annotated readonly, so core's run route requires POST with {"input": ...}.
    a=$(curl -s -u "$WPUSER:$APPPW" -H 'Content-Type: application/json' --data "{\"input\":$args}" "$ABIL/abilities/iato/$slug/run" | norm_rest)
  else
    a=$(curl -s -u "$WPUSER:$APPPW" "$ABIL/abilities/iato/$slug/run${q:+?$q}" | norm_rest)
  fi
  b=$(adapter_call "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"iato/$slug\",\"parameters\":$args}}}" | norm_adapter)
  c=$(curl -s -H "Authorization: Bearer $BEARER" -H 'Content-Type: application/json' --data "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$tool\",\"arguments\":$args}}" "$IATO" | norm_iato)
  local kind; kind=$(printf '%s' "$c" | python3 -I -c 'import json,sys; d=json.load(sys.stdin); print("error-path" if "error" in d else ("empty-path" if not any(v for v in d["ok"].values() if isinstance(v,(list,dict)) and v) else "real-data"))')
  if [[ "$a" == "$b" && "$b" == "$c" ]]; then
    printf 'PARITY   %-18s %-28s [%s] %s\n' "$slug" "$label" "$kind" "${c:0:110}"
  else
    fail=1
    printf 'MISMATCH %-18s %-28s\n  REST:    %s\n  ADAPTER: %s\n  IATO:    %s\n' "$slug" "$label" "${a:0:300}" "${b:0:300}" "${c:0:300}"
  fi
}

echo "### parity runs"
for slug in ${(k)TOOL}; do
  run_case "$slug" '{}' 'no args'
done
for slug in ${(k)TOOL}; do
  [[ "$slug" == "list-crawls" ]] && continue
  run_case "$slug" '{"crawl_id":"iato-smoke-nonexistent-crawl"}' 'bogus crawl_id'
done
run_case list-crawls '{"limit":5}' 'limit 5'
run_case get-sitemap '{"sitemap_id":999999999}' 'bogus sitemap_id'

# Optional real-data pass: REAL_CRAWL_ID=<id from list-crawls> runs the ten
# crawl-scoped abilities against an existing crawl. Reads only; the only
# remote side effect possible is get-suggestions triggering suggestion
# generation on IATO when none exist yet for that crawl.
if [[ -n "${REAL_CRAWL_ID:-}" ]]; then
  echo "### real-data runs (crawl_id=$REAL_CRAWL_ID)"
  for slug in ${(k)TOOL}; do
    [[ "$slug" == "list-crawls" ]] && continue
    run_case "$slug" "{\"crawl_id\":\"$REAL_CRAWL_ID\"}" 'real crawl_id'
  done
  run_case get-seo-fixes "{\"crawl_id\":\"$REAL_CRAWL_ID\",\"severity\":\"error\",\"limit\":5}" 'real crawl_id + filters'
  run_case get-content-gaps "{\"crawl_id\":\"$REAL_CRAWL_ID\",\"min_word_count\":1000,\"limit\":3}" 'real crawl_id + filters'
fi

echo "### permission checks (default manage_options: only admin passes)"
for u in subscriber editor admin; do
  pw=$(cat "${APPPW_DIR:-/tmp}/apppw-$u.txt" 2>/dev/null | tail -1); [[ "$u" == "admin" ]] && pw="$APPPW"
  [[ -z "$pw" ]] && { echo "no app password file for $u"; continue; }
  code=$(curl -s -o /dev/null -w '%{http_code}' -u "$u:$pw" "$ABIL/abilities/iato/list-crawls/run")
  echo "$u -> HTTP $code on run"
done
exit $fail
