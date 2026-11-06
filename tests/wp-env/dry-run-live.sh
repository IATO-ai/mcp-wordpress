#!/bin/zsh
# Live checks for dry_run (1.16.0): create_post, update_post, update_seo_data,
# assign_term, create_term, update_term and delete_term answer with what would
# happen, write nothing, record no receipt, and refuse what the real call would
# refuse; the preview matches the real write; a tool with no dry run is not run at
# all when given the flag; delete_term cannot report the default category deleted.
# Installs Yoast SEO for the run when absent, and removes it again.
#   tests/wp-env/dry-run-live.sh [base-url]
#   tests/wp-env/dry-run-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
MARKER=/tmp/iato-dry-run-installed-yoast

cleanup() {
	for id in $(W wp post list --post_type=post,page --post_status=any,trash --s=dry-run-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for tax in category post_tag; do
		for t in $(W wp term list "$tax" --search=dry-run-live --field=term_id); do W wp term delete "$tax" "$t" >/dev/null; done
	done
	W wp user application-password list contributor --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-dry-run-*) W wp user application-password delete contributor "$uuid" >/dev/null ;; esac; done
	if [ -f "$MARKER" ]; then
		W wp plugin uninstall wordpress-seo --deactivate >/dev/null
		rm -f "$MARKER"
	fi
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
export KEY=$(W wp option get iato_mcp_key)
W wp user get contributor --field=ID >/dev/null 2>&1 || W wp user create contributor contributor@example.test --role=contributor --user_pass=contributor-pass >/dev/null
export PW_CONTRIBUTOR=$(W wp user application-password create contributor live-dry-run-$(date +%s) --porcelain)
python3 -I "$REPO/tests/wp-env/dry-run-live.py"
rc=$?
cleanup
exit $rc
