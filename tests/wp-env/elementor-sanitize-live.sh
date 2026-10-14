#!/bin/zsh
# Live checks for Part 2 (Elementor data sanitising) on the wp-env dev site.
# Installs the test-only mu-plugin (tests/wp-env/mu-force-elementor-save-fail.php),
# seeds a page with human-authored raw HTML through Elementor's own save as
# admin, creates an admin Application Password, then runs
# tests/wp-env/elementor-sanitize-live.py.
#   tests/wp-env/elementor-sanitize-live.sh [base-url]
#   tests/wp-env/elementor-sanitize-live.sh --cleanup
set -u
cd "$(dirname "$0")/../.."
W() { npx @wordpress/env run cli "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
MU=wp-content/mu-plugins/iato-mcp-test-elementor.php

if [ "${1:-}" = "--cleanup" ]; then
	set +e
	W rm -f "$MU"
	W wp option delete iato_mcp_test_force_save_fail iato_mcp_test_allow_unfiltered
	W wp plugin activate elementor >/dev/null
	for id in $(W wp post list --post_type=page --post_status=any --name=iato-sanitize-fixture --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force; done
	for u in admin author; do
		W wp user application-password list "$u" --fields=uuid,name --format=csv | tail -n +2 > /tmp/iato-ap.csv
		while IFS=, read -r uuid name; do case "$name" in live-sanitize-*) W wp user application-password delete "$u" "$uuid" >/dev/null && echo "deleted $u $name";; esac; done < /tmp/iato-ap.csv
	done
	rm -f /tmp/iato-ap.csv
	for id in $(W wp post list --post_type=post --post_status=any --name=iato-sanitize-fixture-author --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force; done
	W wp option delete iato_mcp_test_cap_during_save iato_mcp_test_cap_at_shutdown
	P=$(W wp db prefix)
	W wp db query "DELETE FROM ${P}iato_change_receipts WHERE field IN ('_elementor_data') AND after_value='sanitize-live-seed'"
	echo "cleanup done"
	exit 0
fi
BASE="${1:-http://localhost:8888}"
W mkdir -p wp-content/mu-plugins
W cp wp-content/plugins/mcp-wordpress/tests/wp-env/mu-force-elementor-save-fail.php "$MU"
W wp option delete iato_mcp_test_force_save_fail iato_mcp_test_allow_unfiltered >/dev/null 2>&1 || true
W wp plugin activate elementor >/dev/null
stamp=$(date +%s)
export BASE
export KEY=$(W wp option get iato_mcp_key)
export PREFIX=$(W wp db prefix)
export PW_ADMIN=$(W wp user application-password create admin live-sanitize-$stamp --porcelain)
W wp user get author --field=ID >/dev/null 2>&1 || W wp user create author author@example.test --role=author --user_pass=author-pass >/dev/null
export PW_AUTHOR=$(W wp user application-password create author live-sanitize-$stamp --porcelain)
export FIXTURE=$(W wp eval-file wp-content/plugins/mcp-wordpress/tests/wp-env/create-sanitize-fixture.php --user=admin | tail -1)
export FIXTURE_AUTHOR=$(W wp eval-file wp-content/plugins/mcp-wordpress/tests/wp-env/create-sanitize-fixture.php author --user=author | tail -1)
echo "fixture: $FIXTURE"
echo "author fixture: $FIXTURE_AUTHOR"
python3 -I tests/wp-env/elementor-sanitize-live.py
