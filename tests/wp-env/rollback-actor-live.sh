#!/bin/zsh
# Live checks for Part 3 (who made each change; rollback by the same user or
# an administrator; the rollback conflict check) on the wp-env dev site.
# Provisions editor/author users, Application Passwords for admin, editor and
# author, an OAuth access token minted straight into the plugin's tables (for
# the admin, client "Rollback Live Client"), and fixtures: an author draft, an
# admin page, an admin post with two categories and no tags, a category, a
# menu with a custom item, and an Elementor page carrying human-authored raw
# HTML (seeded through Elementor's own save). Then runs rollback-actor-live.py.
#   tests/wp-env/rollback-actor-live.sh [base-url]
#   tests/wp-env/rollback-actor-live.sh --cleanup
set -u
cd "$(dirname "$0")/../.."
W() { npx @wordpress/env run cli "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
P=$(W wp db prefix)
CLIENT=rollback-live-client

if [ "${1:-}" = "--cleanup" ]; then
	for id in $(W wp post list --user=admin --post_type=post,page --post_status=any,trash --s=rollback-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for id in $(W wp post list --post_type=page --post_status=any --name=iato-rollback-fixture --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for t in $(W wp term list category --search=rollback-live --field=term_id); do W wp term delete category "$t" >/dev/null; done
	for t in $(W wp term list post_tag --search=rollback-live --field=term_id); do W wp term delete post_tag "$t" >/dev/null; done
	W wp menu delete "Rollback Live Menu" >/dev/null
	for u in admin editor author; do
		W wp user application-password list "$u" --fields=uuid,name --format=csv | tail -n +2 > /tmp/iato-ap.csv
		while IFS=, read -r uuid name; do case "$name" in live-rollback-*) W wp user application-password delete "$u" "$uuid" >/dev/null && echo "deleted $u $name";; esac; done < /tmp/iato-ap.csv
	done
	rm -f /tmp/iato-ap.csv
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_tokens WHERE client_id='$CLIENT'"
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_grants WHERE client_id='$CLIENT'"
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_clients WHERE client_id='$CLIENT'"
	W wp db query "DELETE FROM ${P}iato_change_receipts WHERE after_value LIKE 'rollback-live-seed%' OR before_value LIKE 'rollback-live-seed%'"
	echo "cleanup done"
	exit 0
fi

BASE="${1:-http://localhost:8888}"
W wp user get author --field=ID >/dev/null 2>&1 || W wp user create author author@example.test --role=author --user_pass=author-pass >/dev/null
W wp user get editor --field=ID >/dev/null 2>&1 || W wp user create editor editor@example.test --role=editor --user_pass=editor-pass >/dev/null
stamp=$(date +%s)
export BASE PREFIX="$P" OAUTH_CLIENT="$CLIENT"
export KEY=$(W wp option get iato_mcp_key)
export KEY_OWNER=$(W wp option get iato_mcp_key_owner)
export ADMIN_ID=$(W wp user get admin --field=ID) EDITOR_ID=$(W wp user get editor --field=ID) AUTHOR_ID=$(W wp user get author --field=ID)
export PW_ADMIN=$(W wp user application-password create admin live-rollback-$stamp --porcelain)
export PW_EDITOR=$(W wp user application-password create editor live-rollback-$stamp --porcelain)
export PW_AUTHOR=$(W wp user application-password create author live-rollback-$stamp --porcelain)

# OAuth access token for the admin, minted into the tables the token path reads.
export OAUTH_TOKEN="iato_at_rollbacklive$(python3 -c 'import secrets;print(secrets.token_hex(16))')"
GRANT=$(python3 -c 'import secrets;print(secrets.token_hex(16))')
HASH=$(python3 -c "import hashlib,os;print(hashlib.sha256(os.environ['OAUTH_TOKEN'].encode()).hexdigest())")
W wp db query "DELETE FROM ${P}iato_mcp_oauth_clients WHERE client_id='$CLIENT'"
W wp db query "INSERT INTO ${P}iato_mcp_oauth_clients (client_id, client_name, redirect_uris, created_at, authorized_at) VALUES ('$CLIENT', 'Rollback Live Client', '[\"https://claude.ai/api/mcp/auth_callback\"]', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
W wp db query "INSERT INTO ${P}iato_mcp_oauth_grants (grant_id, client_id, user_id, redirect_uri, granted_at) VALUES ('$GRANT', '$CLIENT', $ADMIN_ID, 'https://claude.ai/api/mcp/auth_callback', UTC_TIMESTAMP())"
W wp db query "INSERT INTO ${P}iato_mcp_oauth_tokens (kind, token_hash, grant_id, client_id, user_id, redirect_uri, granted_at, created_at, expires_at) VALUES ('access', '$HASH', '$GRANT', '$CLIENT', $ADMIN_ID, 'https://claude.ai/api/mcp/auth_callback', UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))"

export AUTHOR_DRAFT=$(W wp post create --post_type=post --post_status=draft --post_author=$AUTHOR_ID --post_title="rollback-live author draft $stamp" --porcelain)
export ADMIN_PAGE=$(W wp post create --post_type=page --post_status=publish --post_author=$ADMIN_ID --post_title="rollback-live admin page $stamp" --porcelain)
export CAT=$(W wp term create category "rollback-live cat $stamp" --porcelain)
export TAG=$(W wp term create post_tag "rollback-live tag $stamp" --porcelain)
export ADMIN_POST=$(W wp post create --post_type=post --post_status=publish --post_author=$ADMIN_ID --post_category=1,$CAT --post_title="rollback-live admin post $stamp" --porcelain)
W wp menu delete "Rollback Live Menu" >/dev/null 2>&1
export MENU=$(W wp menu create "Rollback Live Menu" --porcelain)
# --user=admin: without a user WP-CLI runs kses and the iframe would never be stored.
export PLAIN_PAGE=$(W wp post create --user=admin --post_type=page --post_status=publish --post_author=$ADMIN_ID --post_title="rollback-live plain page $stamp" --post_content="<p>rollback-live plain content &amp; more</p><iframe src='https://maps.example.com/embed?q=b2l' width='300'></iframe>" --porcelain)
export MENU_ITEM=$(W wp menu item add-custom "$MENU" "Live Item" https://example.com/live --porcelain)
export FIXTURE=$(W wp eval-file wp-content/plugins/mcp-wordpress/tests/wp-env/create-sanitize-fixture.php rollback --user=admin | tail -1)
echo "users: admin=$ADMIN_ID editor=$EDITOR_ID author=$AUTHOR_ID key_owner=$KEY_OWNER"
echo "fixtures: author_draft=$AUTHOR_DRAFT admin_page=$ADMIN_PAGE admin_post=$ADMIN_POST cat=$CAT tag=$TAG menu_item=$MENU_ITEM"
echo "elementor: $FIXTURE"
python3 -I tests/wp-env/rollback-actor-live.py
