#!/bin/zsh
# Live OAuth checks (1.13.0) against the wp-env dev site: metadata, dynamic
# registration rules and limits, consent screen, single-use codes, per-client
# tokens, refresh rotation, revocation (endpoint and Settings), expiry, user
# checks, client pruning. Provisions a second administrator and an editor,
# then runs tests/wp-env/oauth-live.py, which drives WP-CLI itself for the
# role changes and backdating it needs.
#
#   tests/wp-env/oauth-live.sh [base-url]
#   tests/wp-env/oauth-live.sh --cleanup
set -eu
cd "$(dirname "$0")/../.."
W() { npx @wordpress/env run cli "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

if [ "${1:-}" = "--cleanup" ]; then
	set +e
	for u in oauth2admin oauthed; do W wp user get "$u" --field=ID >/dev/null 2>&1 && W wp user delete "$u" --yes --reassign=1; done
	for id in $(W wp post list --user=admin --post_type=post --post_status=any --s=oauth-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force; done
	P=$(W wp db prefix)
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_tokens WHERE client_id IN (SELECT client_id FROM ${P}iato_mcp_oauth_clients WHERE client_name LIKE 'oauth-live%')"
	W wp db query "DELETE FROM ${P}iato_mcp_oauth_clients WHERE client_name LIKE 'oauth-live%'"
	W wp db query "DELETE FROM ${P}options WHERE option_name LIKE '_transient_iato_mcp_oauth_reg_%' OR option_name LIKE '_transient_timeout_iato_mcp_oauth_reg_%'"
	echo "cleanup done"
	exit 0
fi
BASE="${1:-http://localhost:8888}"
W wp user get oauth2admin --field=ID >/dev/null 2>&1 || W wp user create oauth2admin oauth2admin@example.test --role=administrator --user_pass=oauth2-pass >/dev/null
W wp user get oauthed --field=ID >/dev/null 2>&1 || W wp user create oauthed oauthed@example.test --role=editor --user_pass=oauthed-pass >/dev/null
export BASE
export KEY=$(W wp option get iato_mcp_key)
export PREFIX=$(W wp db prefix)
python3 -I tests/wp-env/oauth-live.py
