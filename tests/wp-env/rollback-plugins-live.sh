#!/bin/zsh
# Live rollback checks that need third-party plugins: SEO receipts (Yoast SEO
# installed for the run) and redirect receipts on the built-in fallback
# handler, Safe Redirect Manager and Redirection. The plugins are installed
# from WordPress.org for the run; --cleanup deactivates and deletes them, so
# the dev site goes back to having no SEO or redirect plugin.
#   tests/wp-env/rollback-plugins-live.sh [base-url]
#   tests/wp-env/rollback-plugins-live.sh --cleanup
set -u
cd "$(dirname "$0")/../.."
# W's exit status is grep's, not wp-cli's: test installed plugins by listing them.
W() { npx @wordpress/env run cli "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
P=$(W wp db prefix)

if [ "${1:-}" = "--cleanup" ]; then
	for id in $(W wp post list --user=admin --post_type=post,page,redirect_rule --post_status=any,trash --s=rollback-plugins --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	# uninstall runs each plugin's uninstall routine (Yoast and Redirection drop their tables).
	for slug in redirection safe-redirect-manager wordpress-seo; do
		W wp plugin list --field=name | grep -qx "$slug" && W wp plugin uninstall "$slug" --deactivate >/dev/null
	done
	W wp option delete iato_mcp_redirects >/dev/null
	W wp user application-password list admin --fields=uuid,name --format=csv | tail -n +2 > /tmp/iato-ap.csv
	while IFS=, read -r uuid name; do case "$name" in live-plugins-*) W wp user application-password delete admin "$uuid" >/dev/null && echo "deleted admin $name";; esac; done < /tmp/iato-ap.csv
	rm -f /tmp/iato-ap.csv
	W wp plugin list --fields=name,status
	echo "cleanup done"
	exit 0
fi

BASE="${1:-http://localhost:8888}"
for slug in redirection safe-redirect-manager wordpress-seo; do
	W wp plugin list --field=name | grep -qx "$slug" && { echo "$slug is already installed; this script would delete it in --cleanup. Aborting."; exit 1; }
done
W wp plugin install wordpress-seo --activate >/dev/null
W wp plugin install safe-redirect-manager >/dev/null
W wp plugin install redirection >/dev/null
W wp plugin list --fields=name,status,version
stamp=$(date +%s)
export BASE PREFIX="$P"
export KEY=$(W wp option get iato_mcp_key)
export PW_ADMIN=$(W wp user application-password create admin live-plugins-$stamp --porcelain)
export SEO_POST=$(W wp post create --post_type=post --post_status=publish --post_author=1 --post_title="rollback-plugins seo post $stamp" --porcelain)
W wp option delete iato_mcp_redirects >/dev/null
python3 -I tests/wp-env/rollback-plugins-live.py
