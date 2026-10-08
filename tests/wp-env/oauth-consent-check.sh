#!/bin/zsh
# Render the OAuth consent screen over HTTP as a logged-in administrator in
# every key-owner state, and check the approve rules. wp-env site only.
#   tests/wp-env/oauth-consent-check.sh [base-url]
# Leaves the key bound to user 1.
set -u
B="${1:-http://localhost:8888}"
W() { cd /Users/garennebigby/Projects/mcp-wordpress && npx --yes @wordpress/env@latest run cli "$@" 2>/dev/null | grep -v '^ℹ\|^✔' | sed '/^$/d'; }
cookie_for() { W wp eval "echo 'wordpress_logged_in_' . COOKIEHASH . '=' . wp_generate_auth_cookie($1, time()+3600, 'logged_in');" | tail -1; }
W wp eval '$c = get_option("iato_mcp_oauth_clients", []); $c["c0probe"] = ["client_name"=>"C0 Probe Client","redirect_uris"=>["https://example.com/cb"],"created"=>time()]; update_option("iato_mcp_oauth_clients", $c);' >/dev/null
ADMINC=$(cookie_for 1)
URL="$B/oauth/authorize?response_type=code&client_id=c0probe&redirect_uri=https%3A%2F%2Fexample.com%2Fcb&state=xyz"
OTHER=$(W wp user create c0consent c0consent@example.test --role=administrator --porcelain | tail -1)
EDITOR_ID=$(W wp user get editor --field=ID | tail -1)
actor() { python3 -I -c 'import re,sys,html; h=sys.stdin.read(); m=re.search(r"<div class=\"auth-permissions auth-actor\">(.*?)</div>", h, re.S); print("ACTOR:", html.unescape(re.sub(r"\s+"," ", re.sub(r"<[^>]+>","", m.group(1)))).strip() if m else "NO ACTOR BLOCK")'; }
nonce() { python3 -I -c 'import re,sys; m=re.search(r"name=\"_wpnonce\" value=\"([a-f0-9]+)\"", sys.stdin.read()); print(m.group(1) if m else "")'; }
for st in "unbound:delete" "self:1" "other:$OTHER" "missing:987654"; do
  name=${st%%:*}; val=${st##*:}
  if [[ $val == delete ]]; then W wp option delete iato_mcp_key_owner >/dev/null; else W wp option update iato_mcp_key_owner "$val" >/dev/null; fi
  echo "--- $name ---"; curl -s -b "$ADMINC" "$URL" | actor
done
echo "--- editor (expect 302 to wp-login) ---"
[[ -n "$EDITOR_ID" ]] && curl -s -o /dev/null -w 'HTTP %{http_code} -> %{redirect_url}\n' -b "$(cookie_for "$EDITOR_ID")" "$URL" | cut -c1-90
echo "--- approve on unbound key binds to approver ---"
W wp option delete iato_mcp_key_owner >/dev/null
N=$(curl -s -b "$ADMINC" "$URL" | nonce)
curl -s -o /dev/null -w 'HTTP %{http_code} -> %{redirect_url}\n' -b "$ADMINC" -X POST --data "_wpnonce=$N" "$URL" | sed -E 's/code=[^&]+/code=<key>/'
echo "owner after approve: $(W wp option get iato_mcp_key_owner | tail -1)"
echo "--- approve when bound to someone else keeps the owner ---"
W wp option update iato_mcp_key_owner "$OTHER" >/dev/null
N=$(curl -s -b "$ADMINC" "$URL" | nonce)
curl -s -o /dev/null -w 'HTTP %{http_code}\n' -b "$ADMINC" -X POST --data "_wpnonce=$N" "$URL"
echo "owner still: $(W wp option get iato_mcp_key_owner | tail -1) (expected $OTHER)"
W wp user delete "$OTHER" --yes >/dev/null
W wp option update iato_mcp_key_owner 1 >/dev/null
