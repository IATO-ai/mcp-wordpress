#!/bin/zsh
# Live checks for 1.21.1's update_elementor_patch list rules and stored-value report, with
# real WordPress and real Elementor: add, move and remove on a list of widgets, what a dry
# run shows, what is stored and rendered, rollback, and stored_matches_input /
# stored_difference after Elementor's own save.
#   tests/wp-env/patch-ops-live.sh [base-url]
#   tests/wp-env/patch-ops-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

cleanup() {
	W wp eval '$b = get_option( "iato_test_patch_ops_backup" ); if ( is_array( $b ) ) { update_option( "iato_mcp_key_owner", $b["owner"] ); delete_option( "iato_test_patch_ops_backup" ); }' >/dev/null
	for id in $(W wp post list --post_type=page --post_status=any,trash --s=patch-ops-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
W wp plugin activate elementor >/dev/null
W wp eval 'add_option( "iato_test_patch_ops_backup", [ "owner" => get_option( "iato_mcp_key_owner", 0 ) ], "", "no" );' >/dev/null
export KEY=$(W wp option get iato_mcp_key)
# Elementor's own save runs only for a key with a user behind it (some sites keep it
# unbound); cleanup puts the owner back.
W wp option update iato_mcp_key_owner "$(W wp user get admin --field=ID)" >/dev/null
python3 -I "$REPO/tests/wp-env/patch-ops-live.py"
rc=$?
cleanup
exit $rc
