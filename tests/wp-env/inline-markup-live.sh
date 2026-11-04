#!/bin/zsh
# Live checks for 1.15.0's inline SVG and style opt-in, with real kses, real Elementor
# and every credential: OAuth, bound site key, unbound site key, Application Passwords
# for an administrator, an editor and an author.
#   tests/wp-env/inline-markup-live.sh [dev|tests]
#   tests/wp-env/inline-markup-live.sh [dev|tests] --cleanup
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO"
TARGET="${1:-dev}"
case "$TARGET" in
	dev)   SVC=cli;       BASE=http://localhost:8888 ;;
	tests) SVC=tests-cli; BASE=http://localhost:8889 ;;
	*) echo "usage: $0 [dev|tests] [--cleanup]"; exit 2 ;;
esac
W() { npx @wordpress/env run "$SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
SRC=wp-content/plugins/mcp-wordpress/tests/wp-env
P=$(W wp db prefix)
CLIENT=inline-live-client

if [ "${2:-}" = "--cleanup" ]; then
	set +e
	W wp option delete iato_mcp_inline_markup_enabled >/dev/null
	for id in $(W wp post list --post_type=page --post_status=any --name=iato-inline-fixture --format=ids) $(W wp post list --post_type=post --post_status=any --name=iato-sanitize-fixture-author --format=ids); do W wp post delete "$id" --force >/dev/null; done
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_tokens WHERE client_id='$CLIENT'"
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_grants WHERE client_id='$CLIENT'"
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_clients WHERE client_id='$CLIENT'"
	for u in admin inlineeditor inlineauthor; do
		W wp user application-password list "$u" --fields=uuid,name --format=csv | tail -n +2 > /tmp/iato-ap.csv
		while IFS=, read -r uuid name; do case "$name" in live-inline-*) W wp user application-password delete "$u" "$uuid" >/dev/null;; esac; done < /tmp/iato-ap.csv
	done
	rm -f /tmp/iato-ap.csv
	W wp user delete inlineeditor --yes >/dev/null
	W wp user delete inlineauthor --yes >/dev/null
	echo "cleanup done"
	exit 0
fi

W wp plugin activate elementor >/dev/null
W wp option delete iato_mcp_inline_markup_enabled >/dev/null
stamp=$(date +%s)
export BASE SVC
export KEY=$(W wp option get iato_mcp_key)
export OWNER_BEFORE=$(W wp option get iato_mcp_key_owner)
ADMIN_ID=$(W wp user get admin --field=ID)
export PW_ADMIN=$(W wp user application-password create admin live-inline-$stamp --porcelain)
W wp user get inlineeditor --field=ID >/dev/null 2>&1 || W wp user create inlineeditor inlineeditor@example.test --role=editor --user_pass=inline-pass >/dev/null
W wp user get inlineauthor --field=ID >/dev/null 2>&1 || W wp user create inlineauthor inlineauthor@example.test --role=author --user_pass=inline-pass >/dev/null
export PW_EDITOR=$(W wp user application-password create inlineeditor live-inline-$stamp --porcelain)
export PW_AUTHOR=$(W wp user application-password create inlineauthor live-inline-$stamp --porcelain)
export OAUTH_TOKEN="iato_at_inlinelive$(python3 -c 'import secrets;print(secrets.token_hex(16))')"
GRANT="inlinelive$(python3 -c 'import secrets;print(secrets.token_hex(8))')"
HASH=$(python3 -c "import hashlib,os;print(hashlib.sha256(os.environ['OAUTH_TOKEN'].encode()).hexdigest())")
W wp db query "DELETE FROM ${P}iato_mcp_oauth_tokens WHERE client_id='$CLIENT'"
W wp db query "DELETE FROM ${P}iato_mcp_oauth_grants WHERE client_id='$CLIENT'"
W wp db query "DELETE FROM ${P}iato_mcp_oauth_clients WHERE client_id='$CLIENT'"
W wp db query "INSERT INTO ${P}iato_mcp_oauth_clients (client_id, client_name, redirect_uris, created_at, authorized_at) VALUES ('$CLIENT', 'Inline Live Client', '[\"https://claude.ai/api/mcp/auth_callback\"]', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
W wp db query "INSERT INTO ${P}iato_mcp_oauth_grants (grant_id, client_id, user_id, redirect_uri, granted_at) VALUES ('$GRANT', '$CLIENT', $ADMIN_ID, 'https://claude.ai/api/mcp/auth_callback', UTC_TIMESTAMP())"
W wp db query "INSERT INTO ${P}iato_mcp_oauth_tokens (kind, token_hash, grant_id, client_id, user_id, redirect_uri, granted_at, created_at, expires_at) VALUES ('access', '$HASH', '$GRANT', '$CLIENT', $ADMIN_ID, 'https://claude.ai/api/mcp/auth_callback', UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))"
export FIXTURE=$(W wp eval-file "$SRC/create-sanitize-fixture.php" inline --user=admin | tail -1)
export FIXTURE_AUTHOR=$(W wp eval-file "$SRC/create-sanitize-fixture.php" author --user=inlineauthor | tail -1)
python3 -I "$REPO/tests/wp-env/inline-markup-live.py"
