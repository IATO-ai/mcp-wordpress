#!/bin/zsh
# Live checks for 1.14.0 (fixes on Elementor Atomic Editor (V4) elements:
# IATO_MCP_Elementor_Atomic_Writer). Installs the test-only mu-plugin
# (tests/wp-env/mu-force-elementor-save-fail.php), provisions an author user,
# Application Passwords for admin and author and an OAuth access token minted
# straight into the plugin's tables, needs the Sprint A fixture pages
# (tests/wp-env/create-fixture-pages.php) and a site key bound to an
# administrator, then runs tests/wp-env/atomic-fixes-live.py.
#   tests/wp-env/atomic-fixes-live.sh [dev|tests]        dev = :8888, tests = :8889
#   tests/wp-env/atomic-fixes-live.sh [dev|tests] --cleanup
set -u
cd "$(dirname "$0")/../.."
TARGET="${1:-dev}"
case "$TARGET" in
	dev)   SVC=cli;       BASE=http://localhost:8888 ;;
	tests) SVC=tests-cli; BASE=http://localhost:8889 ;;
	*) echo "usage: $0 [dev|tests] [--cleanup]"; exit 2 ;;
esac
W() { npx @wordpress/env run "$SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
MU=wp-content/mu-plugins/iato-mcp-test-elementor.php
P=$(W wp db prefix)
CLIENT=atomic-fixes-live-client
OPTS=(iato_mcp_test_force_save_fail iato_mcp_test_force_save_fail_message iato_mcp_test_allow_unfiltered iato_mcp_test_cap_during_save iato_mcp_test_cap_at_shutdown iato_mcp_test_throw_resolver iato_mcp_test_throw_in_tool iato_mcp_test_record_styles_clear iato_mcp_test_styles_cleared iato_mcp_test_saves)

if [ "${2:-}" = "--cleanup" ]; then
	set +e
	ids=$(W wp post list --post_type=page --post_status=any --s=atomic-fixes-live --field=ID | tr '\n' ' ')
	if [ -n "$ids" ]; then
		W wp db query "DELETE FROM ${P}iato_change_receipts WHERE post_id IN ($(echo $ids | tr ' ' ',' | sed 's/,$//'))"
		W wp db query "DELETE FROM ${P}posts WHERE post_type='revision' AND post_parent IN ($(echo $ids | tr ' ' ',' | sed 's/,$//'))"
		W wp post delete ${=ids} --force >/dev/null
	fi
	for u in admin author; do
		W wp user application-password list "$u" --fields=uuid,name --format=csv | tail -n +2 > /tmp/iato-afl.csv
		while IFS=, read -r uuid name; do case "$name" in atomic-fixes-live-*) W wp user application-password delete "$u" "$uuid" >/dev/null;; esac; done < /tmp/iato-afl.csv
	done
	rm -f /tmp/iato-afl.csv
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_tokens WHERE client_id='$CLIENT'"
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_grants WHERE client_id='$CLIENT'"
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_clients WHERE client_id='$CLIENT'"
	W wp option delete ${OPTS[@]} >/dev/null
	W rm -f "$MU"
	echo "cleanup done ($TARGET)"
	exit 0
fi

W mkdir -p wp-content/mu-plugins
W cp wp-content/plugins/mcp-wordpress/tests/wp-env/mu-force-elementor-save-fail.php "$MU"
W wp option delete ${OPTS[@]} >/dev/null 2>&1 || true
W wp plugin activate elementor >/dev/null
W wp user get author --field=ID >/dev/null 2>&1 || W wp user create author author@example.test --role=author --user_pass=author-pass >/dev/null
stamp=$(date +%s)
export BASE SVC PREFIX="$P"
export KEY=$(W wp option get iato_mcp_key)
export OWNER=$(W wp option get iato_mcp_key_owner)
if [ -z "$OWNER" ] || [ "$OWNER" = "0" ]; then echo "the site key must be bound to an administrator"; exit 2; fi
export ADMIN_ID=$(W wp user get admin --field=ID) AUTHOR_ID=$(W wp user get author --field=ID)
export PW_ADMIN=$(W wp user application-password create admin atomic-fixes-live-$stamp --porcelain)
export PW_AUTHOR=$(W wp user application-password create author atomic-fixes-live-$stamp --porcelain)
export OAUTH_TOKEN="iato_at_atomicfixes$(python3 -c 'import secrets;print(secrets.token_hex(16))')"
GRANT=$(python3 -c 'import secrets;print(secrets.token_hex(16))')
HASH=$(python3 -c "import hashlib,os;print(hashlib.sha256(os.environ['OAUTH_TOKEN'].encode()).hexdigest())")
W wp db query "DELETE FROM ${P}iato_mcp_oauth_clients WHERE client_id='$CLIENT'"
W wp db query "INSERT INTO ${P}iato_mcp_oauth_clients (client_id, client_name, redirect_uris, created_at, authorized_at) VALUES ('$CLIENT', 'Atomic Fixes Live Client', '[\"https://claude.ai/api/mcp/auth_callback\"]', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
W wp db query "INSERT INTO ${P}iato_mcp_oauth_grants (grant_id, client_id, user_id, redirect_uri, granted_at) VALUES ('$GRANT', '$CLIENT', $ADMIN_ID, 'https://claude.ai/api/mcp/auth_callback', UTC_TIMESTAMP())"
W wp db query "INSERT INTO ${P}iato_mcp_oauth_tokens (kind, token_hash, grant_id, client_id, user_id, redirect_uri, granted_at, created_at, expires_at) VALUES ('access', '$HASH', '$GRANT', '$CLIENT', $ADMIN_ID, 'https://claude.ai/api/mcp/auth_callback', UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))"
export ATOMIC_SRC=$(W wp post list --post_type=page --name=iato-fixture-atomic-only --field=ID)
export MIXED_SRC=$(W wp post list --post_type=page --name=iato-fixture-mixed --field=ID)
export CLASSIC_SRC=$(W wp post list --post_type=page --name=iato-fixture-classic-only --field=ID)
if [ -z "$ATOMIC_SRC" ] || [ -z "$MIXED_SRC" ] || [ -z "$CLASSIC_SRC" ]; then echo "run tests/wp-env/create-fixture-pages.php first"; exit 2; fi
echo "target=$TARGET elementor=$(W wp plugin get elementor --field=version) owner=$OWNER admin=$ADMIN_ID author=$AUTHOR_ID"
python3 -I -u tests/wp-env/atomic-fixes-live.py
