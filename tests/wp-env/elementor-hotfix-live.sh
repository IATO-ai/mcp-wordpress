#!/bin/zsh
# Live checks for 1.12.2 (change-only sanitising, scoped removal of Elementor's
# meta sanitize callbacks) on the wp-env dev site, Elementor 4.3.4.
#   tests/wp-env/elementor-hotfix-live.sh [base-url]
#   tests/wp-env/elementor-hotfix-live.sh --cleanup
set -u
cd "$(dirname "$0")/../.."
W() { npx @wordpress/env run cli "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
MU=wp-content/mu-plugins/iato-mcp-test-hotfix.php
if [ "${1:-}" = "--cleanup" ]; then
	W rm -f "$MU"
	W wp option delete iato_mcp_test_force_save_fail iato_mcp_test_drop_elementor_callback iato_mcp_test_callbacks_at_shutdown
	for id in $(W wp post list --post_type=page --post_status=any --name=iato-sanitize-fixture --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force; done
	W wp user application-password list admin --fields=uuid,name --format=csv | tail -n +2 > /tmp/iato-ap.csv
	while IFS=, read -r uuid name; do case "$name" in live-hotfix-*) W wp user application-password delete admin "$uuid" >/dev/null && echo "deleted admin $name";; esac; done < /tmp/iato-ap.csv
	rm -f /tmp/iato-ap.csv
	echo "cleanup done"
	exit 0
fi
BASE="${1:-http://localhost:8888}"
W mkdir -p wp-content/mu-plugins
W cp wp-content/plugins/mcp-wordpress/tests/wp-env/mu-hotfix-elementor-test.php "$MU"
W wp option delete iato_mcp_test_force_save_fail iato_mcp_test_drop_elementor_callback >/dev/null 2>&1
stamp=$(date +%s)
export BASE
export KEY=$(W wp option get iato_mcp_key)
export PREFIX=$(W wp db prefix)
export PW_ADMIN=$(W wp user application-password create admin live-hotfix-$stamp --porcelain)
export FIXTURE=$(W wp eval-file wp-content/plugins/mcp-wordpress/tests/wp-env/create-sanitize-fixture.php --user=admin | tail -1)
echo "fixture: $FIXTURE"
python3 -I tests/wp-env/elementor-hotfix-live.py
