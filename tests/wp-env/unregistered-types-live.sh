#!/bin/zsh
# Live checks for 1.13.1: a page that holds element types Elementor cannot load
# right now keeps every element when IATO edits it (Elementor's save is skipped
# and the plugin stores the page itself). Installs the test-only addon plugin
# (tests/wp-env/iato-test-addon/) into the site, then runs
# tests/wp-env/unregistered-types-live.py.
#   tests/wp-env/unregistered-types-live.sh [dev|tests|legacy]
#   tests/wp-env/unregistered-types-live.sh [dev|tests|legacy] --cleanup
# dev = :8888, tests = :8889 (this repository's wp-env); legacy = :8890, the
# WordPress 6.8 + Elementor 3.x wp-env in $LEGACY_ENV_DIR (default
# ~/Projects/iato-sprint/wp-env-legacy), whose Atomic Editor is off.
set -u
cd "$(dirname "$0")/../.."
REPO=$PWD
TARGET="${1:-dev}"
case "$TARGET" in
	dev)    ENV_DIR=$REPO; SVC=cli;       BASE=http://localhost:8888 ;;
	tests)  ENV_DIR=$REPO; SVC=tests-cli; BASE=http://localhost:8889 ;;
	legacy) ENV_DIR="${LEGACY_ENV_DIR:-$HOME/Projects/iato-sprint/wp-env-legacy}"; SVC=cli; BASE=http://localhost:8890 ;;
	*) echo "usage: $0 [dev|tests|legacy] [--cleanup]"; exit 2 ;;
esac
W() { (cd "$ENV_DIR" && npx @wordpress/env run "$SVC" "$@" 2>/dev/null </dev/null) | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

if [ "${2:-}" = "--cleanup" ]; then
	set +e
	ids=$(W wp post list --post_type=page --post_status=any --s=unregistered-live --field=ID | tr '\n' ' ')
	if [ -n "$ids" ]; then
		P=$(W wp db prefix)
		W wp db query "DELETE FROM ${P}iato_change_receipts WHERE post_id IN ($(echo $ids | tr ' ' ',' | sed 's/,$//'))"
		W wp post delete ${=ids} --force >/dev/null
	fi
	W wp plugin deactivate iato-test-addon >/dev/null
	W rm -rf wp-content/plugins/iato-test-addon
	echo "cleanup done ($TARGET)"
	exit 0
fi

W cp -r wp-content/plugins/mcp-wordpress/tests/wp-env/iato-test-addon wp-content/plugins/
W wp plugin activate iato-test-addon >/dev/null
export BASE SVC ENV_DIR
export KEY=$(W wp option get iato_mcp_key)
export OWNER=$(W wp option get iato_mcp_key_owner)
if [ -z "$OWNER" ] || [ "$OWNER" = "0" ]; then echo "the site key must be bound to an administrator"; exit 2; fi
python3 -I tests/wp-env/unregistered-types-live.py
