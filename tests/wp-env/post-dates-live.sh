#!/bin/zsh
# Live checks for 1.19.0: date, date_gmt, allow_future and author on update_post and
# create_post: status rules, receipts and rollback, every credential, who may do what,
# date-based permalinks and daylight saving, and date, date_gmt and modified on reads.
#   tests/wp-env/post-dates-live.sh [base-url]
#   tests/wp-env/post-dates-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

cleanup() {
	W wp eval '$b=get_option("iato_test_post_dates_backup");if(is_array($b)){update_option("timezone_string",$b["tz"]);update_option("gmt_offset",$b["offset"]);update_option("permalink_structure",$b["permalinks"]);update_option("iato_mcp_key_owner",$b["owner"]);flush_rewrite_rules(false);delete_option("iato_test_post_dates_backup");}' >/dev/null
	for id in $(W wp post list --post_type=post,page --post_status=any,trash --s=post-dates-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for id in $(W wp user list --search='post-dates-live*' --field=ID); do W wp user delete "$id" --yes >/dev/null; done
	for user in admin editor author contributor; do
		W wp user application-password list "$user" --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-dates-*) W wp user application-password delete "$user" "$uuid" >/dev/null ;; esac; done
	done
	W wp eval 'global $wpdb;foreach(["tokens","grants","clients"] as $t){$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}iato_mcp_oauth_{$t} WHERE client_id=%s","live-dates-client"));}' >/dev/null
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
W wp eval 'add_option("iato_test_post_dates_backup",["tz"=>get_option("timezone_string"),"offset"=>get_option("gmt_offset"),"permalinks"=>get_option("permalink_structure"),"owner"=>get_option("iato_mcp_key_owner")],"","no");' >/dev/null
export KEY=$(W wp option get iato_mcp_key)
for user in editor author contributor subscriber; do
	W wp user get "$user" --field=ID >/dev/null 2>&1 || W wp user create "$user" "$user@example.test" --role="$user" --user_pass="$user-pass" >/dev/null
done
STAMP=$(date +%s)
export PW_ADMIN=$(W wp user application-password create admin live-dates-$STAMP --porcelain)
export PW_EDITOR=$(W wp user application-password create editor live-dates-$STAMP --porcelain)
export PW_AUTHOR=$(W wp user application-password create author live-dates-$STAMP --porcelain)
export PW_CONTRIBUTOR=$(W wp user application-password create contributor live-dates-$STAMP --porcelain)
export ADMIN_ID=$(W wp user get admin --field=ID)
export EDITOR_ID=$(W wp user get editor --field=ID)
export AUTHOR_ID=$(W wp user get author --field=ID)
export CONTRIBUTOR_ID=$(W wp user get contributor --field=ID)
export SUBSCRIBER_ID=$(W wp user get subscriber --field=ID)
export OAUTH_TOKEN=$(W wp eval '
global $wpdb;
$now=gmdate("Y-m-d H:i:s");$token="iato_at_".wp_generate_password(40,false);$uid=(int)get_user_by("login","admin")->ID;
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_clients",["client_id"=>"live-dates-client","client_name"=>"post-dates-live","redirect_uris"=>wp_json_encode(["http://localhost/cb"]),"created_at"=>$now,"authorized_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_grants",["grant_id"=>"live-dates-grant","client_id"=>"live-dates-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_tokens",["kind"=>"access","token_hash"=>hash("sha256",$token),"grant_id"=>"live-dates-grant","client_id"=>"live-dates-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now,"created_at"=>$now,"expires_at"=>gmdate("Y-m-d H:i:s",time()+3600)]);
echo $token;')
python3 -I "$REPO/tests/wp-env/post-dates-live.py"
rc=$?
cleanup
exit $rc
