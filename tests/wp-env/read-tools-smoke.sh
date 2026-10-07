#!/bin/zsh
# Live smoke test of the Elementor read tools over the real MCP endpoint
# (Bearer auth) against the wp-env site built by create-fixture-pages.php.
#
#   tests/wp-env/read-tools-smoke.sh <bearer-key> [base-url]
#
# Prints each JSON-RPC result. Exits non-zero if any call returns an error.
set -u
KEY="$1"; BASE="${2:-http://localhost:8888}"
URL="$BASE/wp-json/iato-mcp/v1/message"
fail=0
call() { # name, params-json
	local body
	body=$(printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2")
	printf '\n### %s %s\n' "$1" "$2"
	local out
	out=$(curl -s -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' --data "$body" "$URL")
	if printf '%s' "$out" | python3 -I -c '
import json,sys
r=json.load(sys.stdin)
if "error" in r: print("RPC ERROR:", r["error"]); sys.exit(1)
res=r["result"]
if res.get("isError"): print("TOOL ERROR:", res["content"][0]["text"]); sys.exit(1)
d=json.loads(res["content"][0]["text"])
print(json.dumps(d, indent=1, ensure_ascii=False)[:4000])
'; then :; else fail=1; fi
}
printf '### initialize\n'
curl -s -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
	--data '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"0"}}}' "$URL" \
	| python3 -I -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["result"]["capabilities"]))'
call get_page_builder '{"id":6}'
call get_page_builder '{"id":8}'
call get_page_builder '{"id":10}'
call list_elementor_widgets '{"id":10}'
call get_elementor_widget '{"id":8,"widget_id":"a7e4d04"}'
call get_elementor_widget '{"id":8,"widget_id":"a7e4d06"}'
call get_elementor_data '{"id":10,"format":"summary"}'
call find_elementor_widgets '{"post_ids":[6,8,10],"filter":{"setting":{"title":{"contains":"heading"}}}}'
call find_elementor_widgets '{"post_ids":[8,10],"filter":{"type":"e-image","setting":{"image_alt":{"contains":"library"}}}}'
exit $fail
