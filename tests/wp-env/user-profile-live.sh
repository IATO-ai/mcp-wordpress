#!/bin/zsh
# Live checks for 1.19.0: update_user_profile: who can change whom, the fields it refuses,
# the display name rule, backslashes and HTML, receipts, rollback and conflicts, every
# credential.
#   tests/wp-env/user-profile-live.sh [base-url]
#   tests/wp-env/user-profile-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

cleanup() {
	W wp eval '$b=get_option("iato_test_user_profile_backup");if(is_array($b)){update_option("iato_mcp_key_owner",$b["owner"]);delete_option("iato_test_user_profile_backup");}foreach(get_users(["search"=>"user-profile-live-*","search_columns"=>["user_login"]]) as $u){require_once ABSPATH."wp-admin/includes/user.php";wp_delete_user($u->ID);}' --user=admin >/dev/null
	W wp eval '$b=get_option("iato_test_user_profile_admin");if(is_array($b)){require_once ABSPATH."wp-admin/includes/user.php";wp_update_user(array_merge(["ID"=>$b["ID"]],wp_slash($b["fields"])));delete_option("iato_test_user_profile_admin");}' --user=admin >/dev/null
	W wp post delete $(W wp post list --post_type=post --post_status=any,trash --s=user-profile-live --format=ids) --force >/dev/null 2>&1
	W wp user application-password list subscriber --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-profile-*) W wp user application-password delete subscriber "$uuid" >/dev/null ;; esac; done
	for user in admin editor author contributor; do
		W wp user application-password list "$user" --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-profile-*) W wp user application-password delete "$user" "$uuid" >/dev/null ;; esac; done
	done
	W wp eval 'global $wpdb;foreach(["tokens","grants","clients"] as $t){$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}iato_mcp_oauth_{$t} WHERE client_id=%s","live-profile-client"));}' >/dev/null
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
W wp eval 'add_option("iato_test_user_profile_backup",["owner"=>get_option("iato_mcp_key_owner")],"","no");$u=get_user_by("login","admin");add_option("iato_test_user_profile_admin",["ID"=>$u->ID,"fields"=>["display_name"=>$u->display_name,"nickname"=>$u->nickname,"first_name"=>$u->first_name,"last_name"=>$u->last_name,"description"=>$u->description,"user_url"=>$u->user_url]],"","no");' >/dev/null
export KEY=$(W wp option get iato_mcp_key)
for user in editor author contributor subscriber; do
	W wp user get "$user" --field=ID >/dev/null 2>&1 || W wp user create "$user" "$user@example.test" --role="$user" --user_pass="$user-pass" >/dev/null
done
STAMP=$(date +%s)
export PW_ADMIN=$(W wp user application-password create admin live-profile-$STAMP --porcelain)
export PW_EDITOR=$(W wp user application-password create editor live-profile-$STAMP --porcelain)
export PW_AUTHOR=$(W wp user application-password create author live-profile-$STAMP --porcelain)
export PW_CONTRIBUTOR=$(W wp user application-password create contributor live-profile-$STAMP --porcelain)
export PW_SUBSCRIBER=$(W wp user application-password create subscriber live-profile-$STAMP --porcelain)
export ADMIN_ID=$(W wp user get admin --field=ID)
export EDITOR_ID=$(W wp user get editor --field=ID)
export AUTHOR_ID=$(W wp user get author --field=ID)
export SUBSCRIBER_ID=$(W wp user get subscriber --field=ID)
export OAUTH_TOKEN=$(W wp eval '
global $wpdb;
$now=gmdate("Y-m-d H:i:s");$token="iato_at_".wp_generate_password(40,false);$uid=(int)get_user_by("login","admin")->ID;
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_clients",["client_id"=>"live-profile-client","client_name"=>"user-profile-live","redirect_uris"=>wp_json_encode(["http://localhost/cb"]),"created_at"=>$now,"authorized_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_grants",["grant_id"=>"live-profile-grant","client_id"=>"live-profile-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_tokens",["kind"=>"access","token_hash"=>hash("sha256",$token),"grant_id"=>"live-profile-grant","client_id"=>"live-profile-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now,"created_at"=>$now,"expires_at"=>gmdate("Y-m-d H:i:s",time()+3600)]);
echo $token;')
python3 -I "$REPO/tests/wp-env/user-profile-live.py"
rc=$?
cleanup
exit $rc
