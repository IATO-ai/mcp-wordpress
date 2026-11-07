#!/bin/zsh
# Live checks for 1.19.0: get_custom_css and update_custom_css: stored as sent and
# printed on the page, off-site loads refused, the style element closed by nothing,
# who may write it, every credential, a theme switch, conflicts, rollback back to no
# CSS post at all, and the retention job.
#   tests/wp-env/custom-css-live.sh [base-url]
#   tests/wp-env/custom-css-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

cleanup() {
	W wp eval '$b=get_option("iato_test_custom_css_backup");if(is_array($b)){switch_theme($b["theme"]);update_option("iato_mcp_key_owner",$b["owner"]);foreach(get_posts(["post_type"=>"custom_css","post_status"=>"any","numberposts"=>-1]) as $p){if(!isset($b["css"][$p->ID])){wp_delete_post($p->ID,true);}}kses_remove_filters();foreach($b["css"] as $id=>$css){wp_update_post(wp_slash(["ID"=>$id,"post_content"=>$css]));}delete_option("iato_test_custom_css_backup");}$f=WP_CONTENT_DIR."/mu-plugins/iato-custom-css-test.php";if(file_exists($f)){unlink($f);}' --user=admin >/dev/null
	for user in admin editor author contributor; do
		W wp user application-password list "$user" --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-css-*) W wp user application-password delete "$user" "$uuid" >/dev/null ;; esac; done
	done
	W wp eval 'global $wpdb;foreach(["tokens","grants","clients"] as $t){$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}iato_mcp_oauth_{$t} WHERE client_id=%s","live-css-client"));}' >/dev/null
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
W wp eval '$css=[];foreach(get_posts(["post_type"=>"custom_css","post_status"=>"any","numberposts"=>-1]) as $p){$css[$p->ID]=$p->post_content;}add_option("iato_test_custom_css_backup",["theme"=>get_stylesheet(),"owner"=>get_option("iato_mcp_key_owner"),"css"=>$css],"","no");' >/dev/null
export KEY=$(W wp option get iato_mcp_key)
for user in editor author contributor; do
	W wp user get "$user" --field=ID >/dev/null 2>&1 || W wp user create "$user" "$user@example.test" --role="$user" --user_pass="$user-pass" >/dev/null
done
STAMP=$(date +%s)
export PW_ADMIN=$(W wp user application-password create admin live-css-$STAMP --porcelain)
export PW_EDITOR=$(W wp user application-password create editor live-css-$STAMP --porcelain)
export PW_AUTHOR=$(W wp user application-password create author live-css-$STAMP --porcelain)
export PW_CONTRIBUTOR=$(W wp user application-password create contributor live-css-$STAMP --porcelain)
export ADMIN_ID=$(W wp user get admin --field=ID)
export OAUTH_TOKEN=$(W wp eval '
global $wpdb;
$now=gmdate("Y-m-d H:i:s");$token="iato_at_".wp_generate_password(40,false);$uid=(int)get_user_by("login","admin")->ID;
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_clients",["client_id"=>"live-css-client","client_name"=>"custom-css-live","redirect_uris"=>wp_json_encode(["http://localhost/cb"]),"created_at"=>$now,"authorized_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_grants",["grant_id"=>"live-css-grant","client_id"=>"live-css-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_tokens",["kind"=>"access","token_hash"=>hash("sha256",$token),"grant_id"=>"live-css-grant","client_id"=>"live-css-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now,"created_at"=>$now,"expires_at"=>gmdate("Y-m-d H:i:s",time()+3600)]);
echo $token;')
python3 -I "$REPO/tests/wp-env/custom-css-live.py"
rc=$?
cleanup
exit $rc
