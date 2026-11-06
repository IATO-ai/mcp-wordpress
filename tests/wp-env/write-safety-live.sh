#!/bin/zsh
# Live checks for what a write may reach beyond what it names (1.16.0): columns a call
# did not set stay as stored; a meta key reaches only the row it names; revisions are
# not targets; trees Elementor cannot render are refused; receipts hold what was
# stored and nothing a post's own data claims; term and SEO previews match the write.
# Installs Yoast SEO for the run when absent, and removes it again.
#   tests/wp-env/write-safety-live.sh [base-url]
#   tests/wp-env/write-safety-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
MARKER=/tmp/iato-write-safety-installed-yoast

cleanup() {
	for id in $(W wp post list --post_type=post,page --post_status=any,trash --s=write-safety-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for t in $(W wp term list category --search=write-safety-live --field=term_id); do W wp term delete category "$t" >/dev/null; done
	W wp user application-password list author --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-write-safety-*) W wp user application-password delete author "$uuid" >/dev/null ;; esac; done
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
W wp user get author --field=ID >/dev/null 2>&1 || W wp user create author author@example.test --role=author --user_pass=author-pass >/dev/null
export PW_AUTHOR=$(W wp user application-password create author live-write-safety-$(date +%s) --porcelain)
export AUTHOR_ID=$(W wp user get author --field=ID)
python3 -I "$REPO/tests/wp-env/write-safety-live.py"
rc=$?
cleanup
exit $rc
