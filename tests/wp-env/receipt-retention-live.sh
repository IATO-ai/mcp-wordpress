#!/bin/zsh
# Live checks for the receipt retention job (1.13.0): the daily WP-Cron event
# clears the page copies of Elementor receipts older than the retention
# period, a cleared receipt's rollback is refused, the period follows the
# iato_mcp_receipt_retention_days filter, an overdue job runs one batch at the
# end of an MCP write, and deactivation removes the event.
# Installs a test-only mu-plugin (tests/wp-env/mu-receipt-retention-test.php)
# that sets the filter from an option, and seeds an Elementor page.
#   tests/wp-env/receipt-retention-live.sh [base-url]
#   tests/wp-env/receipt-retention-live.sh --cleanup
set -u
cd "$(dirname "$0")/../.."
W() { npx @wordpress/env run cli "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
MU=wp-content/mu-plugins/iato-mcp-test-retention.php

cleanup() {
	W rm -f "$MU"
	W wp option delete iato_mcp_test_retention_days >/dev/null
	W wp plugin activate mcp-wordpress >/dev/null
	for id in $(W wp post list --post_type=page,post --post_status=any,trash --name=iato-retention-fixture --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for id in $(W wp post list --user=admin --post_type=post --post_status=any,trash --s=retention-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

BASE="${1:-http://localhost:8888}"
W mkdir -p wp-content/mu-plugins
W cp wp-content/plugins/mcp-wordpress/tests/wp-env/mu-receipt-retention-test.php "$MU"
W wp option delete iato_mcp_test_retention_days >/dev/null
export BASE PREFIX=$(W wp db prefix)
export KEY=$(W wp option get iato_mcp_key)
export PLAIN=$(W wp post create --post_type=post --post_status=publish --post_author=1 --post_title="retention-live post" --porcelain)
export FIXTURE=$(W wp eval-file wp-content/plugins/mcp-wordpress/tests/wp-env/create-sanitize-fixture.php retention --user=admin | tail -1)
echo "fixture: $FIXTURE"
python3 -I tests/wp-env/receipt-retention-live.py
rc=$?
cleanup
exit $rc
