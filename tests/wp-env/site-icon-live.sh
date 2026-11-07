#!/bin/zsh
# Live checks for 1.18.0: site_icon and custom_logo in update_site_settings and
# get_site_settings, on real image files: validation, the sizes WordPress expects,
# the tags on a logged-out page, receipts and rollback, every credential, Elementor's
# saved favicon and logo surviving a publish of Site Settings, and rolling back the
# upload of an image in use.
#   tests/wp-env/site-icon-live.sh [base-url]
#   tests/wp-env/site-icon-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
export THEME_MARKER=/tmp/iato-site-icon-live-theme

cleanup() {
	if [ -f "$THEME_MARKER" ]; then
		W wp theme activate "$(cat "$THEME_MARKER")" >/dev/null
		rm -f "$THEME_MARKER"
	fi
	W wp eval '$b=get_option("iato_test_site_icon_backup");if(is_array($b)){update_option("site_icon",$b["icon"]);if(false===$b["logo"]){remove_theme_mod("custom_logo");}else{set_theme_mod("custom_logo",$b["logo"]);}update_option("iato_mcp_key_owner",$b["owner"]);update_option("blogname",$b["name"]);$k=(int)get_option("elementor_active_kit");if($k>0){if(""===$b["kit"]){delete_post_meta($k,"_elementor_page_settings");}else{update_post_meta($k,"_elementor_page_settings",wp_slash($b["kit"]));}}delete_option("iato_test_site_icon_backup");}' >/dev/null
	for id in $(W wp post list --post_type=attachment,page --post_status=any,trash --s=site-icon-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	# Sizes of the fixtures whose metadata the suite removes: WordPress cannot know them.
	W wp eval '$d=wp_upload_dir()["basedir"];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile()&&0===strpos($f->getFilename(),"site-icon-live")){unlink($f->getPathname());}}' >/dev/null
	for user in admin editor author; do
		W wp user application-password list "$user" --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-icon-*) W wp user application-password delete "$user" "$uuid" >/dev/null ;; esac; done
	done
	W wp eval 'global $wpdb;$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}iato_mcp_oauth_tokens WHERE client_id=%s","live-icon-client"));$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}iato_mcp_oauth_grants WHERE client_id=%s","live-icon-client"));$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}iato_mcp_oauth_clients WHERE client_id=%s","live-icon-client"));' >/dev/null
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
W wp eval '$k=(int)get_option("elementor_active_kit");add_option("iato_test_site_icon_backup",["name"=>get_option("blogname"),"icon"=>get_option("site_icon"),"logo"=>get_theme_mod("custom_logo"),"owner"=>get_option("iato_mcp_key_owner"),"kit"=>$k>0?get_post_meta($k,"_elementor_page_settings",true):""],"","no");' >/dev/null
export KEY=$(W wp option get iato_mcp_key)
for user in editor author; do
	W wp user get "$user" --field=ID >/dev/null 2>&1 || W wp user create "$user" "$user@example.test" --role="$user" --user_pass="$user-pass" >/dev/null
done
STAMP=$(date +%s)
export PW_ADMIN=$(W wp user application-password create admin live-icon-$STAMP --porcelain)
export PW_EDITOR=$(W wp user application-password create editor live-icon-$STAMP --porcelain)
export PW_AUTHOR=$(W wp user application-password create author live-icon-$STAMP --porcelain)
export ADMIN_ID=$(W wp user get admin --field=ID)
export OAUTH_TOKEN=$(W wp eval '
global $wpdb;
$now=gmdate("Y-m-d H:i:s");$token="iato_at_".wp_generate_password(40,false);$uid=(int)get_user_by("login","admin")->ID;
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_clients",["client_id"=>"live-icon-client","client_name"=>"site-icon-live","redirect_uris"=>wp_json_encode(["http://localhost/cb"]),"created_at"=>$now,"authorized_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_grants",["grant_id"=>"live-icon-grant","client_id"=>"live-icon-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_tokens",["kind"=>"access","token_hash"=>hash("sha256",$token),"grant_id"=>"live-icon-grant","client_id"=>"live-icon-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now,"created_at"=>$now,"expires_at"=>gmdate("Y-m-d H:i:s",time()+3600)]);
echo $token;')
python3 -I "$REPO/tests/wp-env/site-icon-live.py"
rc=$?
cleanup
exit $rc
