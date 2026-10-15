#!/bin/zsh
# Live checks for 1.13.1 (Elementor Atomic (V4) write guard, no-fallback rule,
# stored-value flag on reads, repair by sending a typed value).
# Installs the test-only mu-plugin (tests/wp-env/mu-force-elementor-save-fail.php),
# needs the Sprint A fixture pages (tests/wp-env/create-fixture-pages.php) and a
# site key bound to an administrator, then runs tests/wp-env/atomic-guard-live.py.
#   tests/wp-env/atomic-guard-live.sh [dev|tests]        dev = :8888, tests = :8889
#   tests/wp-env/atomic-guard-live.sh [dev|tests] --cleanup
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

if [ "${2:-}" = "--cleanup" ]; then
	set +e
	ids=$(W wp post list --post_type=page --post_status=any --s=atomic-guard-live --field=ID | tr '\n' ' ')
	if [ -n "$ids" ]; then
		P=$(W wp db prefix)
		W wp db query "DELETE FROM ${P}iato_change_receipts WHERE post_id IN ($(echo $ids | tr ' ' ',' | sed 's/,$//'))"
		W wp post delete ${=ids} --force >/dev/null
	fi
	W wp option delete iato_mcp_test_force_save_fail iato_mcp_test_allow_unfiltered iato_mcp_test_cap_during_save iato_mcp_test_cap_at_shutdown iato_mcp_test_record_widget_registration iato_mcp_test_widgets_registered >/dev/null
	W rm -f "$MU"
	W wp plugin activate elementor >/dev/null
	echo "cleanup done ($TARGET)"
	exit 0
fi

W mkdir -p wp-content/mu-plugins
W cp wp-content/plugins/mcp-wordpress/tests/wp-env/mu-force-elementor-save-fail.php "$MU"
W wp option delete iato_mcp_test_force_save_fail iato_mcp_test_allow_unfiltered >/dev/null 2>&1 || true
W wp plugin activate elementor >/dev/null
export BASE SVC
export KEY=$(W wp option get iato_mcp_key)
export OWNER=$(W wp option get iato_mcp_key_owner)
if [ -z "$OWNER" ] || [ "$OWNER" = "0" ]; then echo "the site key must be bound to an administrator"; exit 2; fi
export ATOMIC_SRC=$(W wp post list --post_type=page --name=iato-fixture-atomic-only --field=ID)
export MIXED_SRC=$(W wp post list --post_type=page --name=iato-fixture-mixed --field=ID)
export CLASSIC_SRC=$(W wp post list --post_type=page --name=iato-fixture-classic-only --field=ID)
if [ -z "$ATOMIC_SRC" ] || [ -z "$MIXED_SRC" ] || [ -z "$CLASSIC_SRC" ]; then echo "run tests/wp-env/create-fixture-pages.php first"; exit 2; fi
python3 -I tests/wp-env/atomic-guard-live.py
