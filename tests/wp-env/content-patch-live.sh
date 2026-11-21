#!/bin/zsh
# Live checks for 1.22.0: update_post_content_patch (each operation, exactly-once
# matching, all or nothing, dry runs that predict the stored content, rollback and its
# conflict check, if_revision, idempotency, the Elementor and Beaver Builder refusals,
# markup sanitising would remove, cache and receipts, who may patch what), get_post's
# content_hash, and update_post's return: "minimal".
#   tests/wp-env/content-patch-live.sh [base-url]
#   tests/wp-env/content-patch-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

cleanup() {
	W wp eval '$b=get_option("iato_test_content_patch_backup");if(is_array($b)){update_option("iato_mcp_key_owner",$b["owner"]);delete_option("iato_test_content_patch_backup");}' >/dev/null
	for id in $(W wp post list --post_type=post,page --post_status=any,trash --s=content-patch-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for user in admin editor author contributor; do
		W wp user application-password list "$user" --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-patch-*) W wp user application-password delete "$user" "$uuid" >/dev/null ;; esac; done
	done
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
W wp eval 'add_option("iato_test_content_patch_backup",["owner"=>get_option("iato_mcp_key_owner",0)],"","no");' >/dev/null
export KEY=$(W wp option get iato_mcp_key)
# The key acts as the administrator, so the kses lock applies as it does for clients.
W wp option update iato_mcp_key_owner "$(W wp user get admin --field=ID)" >/dev/null
for user in editor author contributor; do
	W wp user get "$user" --field=ID >/dev/null 2>&1 || W wp user create "$user" "$user@example.test" --role="$user" --user_pass="$user-pass" >/dev/null
done
STAMP=$(date +%s)
export PW_EDITOR=$(W wp user application-password create editor live-patch-$STAMP --porcelain)
export PW_AUTHOR=$(W wp user application-password create author live-patch-$STAMP --porcelain)
export PW_CONTRIBUTOR=$(W wp user application-password create contributor live-patch-$STAMP --porcelain)
export ADMIN_ID=$(W wp user get admin --field=ID)
export CONTRIBUTOR_ID=$(W wp user get contributor --field=ID)
python3 -I "$REPO/tests/wp-env/content-patch-live.py"
rc=$?
cleanup
exit $rc
